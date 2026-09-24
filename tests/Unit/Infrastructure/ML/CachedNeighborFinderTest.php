<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\ML;

use App\Domain\Product\Model\Product;
use App\Infrastructure\ML\CachedNeighborFinder;
use App\Infrastructure\ML\RubixNeighborFinder;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryTrainedModelCache;
use Tests\Support\RecordingLogger;

/**
 * Story 10.1 (R4.4): the trained KNN index is hydrated from the cache when
 * the catalog is unchanged and refitted otherwise. Every CachedNeighborFinder
 * built here stands for a new PHP request: only the cache is shared.
 */
final class CachedNeighborFinderTest extends TestCase
{
    private InMemoryTrainedModelCache $cache;
    private RecordingLogger $logger;
    private int $fits = 0;

    protected function setUp(): void
    {
        $this->cache = new InMemoryTrainedModelCache();
        $this->logger = new RecordingLogger();
        $this->fits = 0;
    }

    public function testColdCacheTrainsOnceAndStoresTheEnvelope(): void
    {
        $products = self::catalog();

        $finder = $this->finder();
        $finder->train($products);

        self::assertSame(1, $this->fits);
        self::assertTrue($finder->isTrained());
        self::assertSame(1, $this->cache->stores);
        self::assertSame(['Modelo KNN treinado'], $this->logger->messages('info'));
        self::assertSame(
            ['products_count' => 6, 'fingerprint' => substr(CachedNeighborFinder::fingerprint($products), 0, 12)],
            $this->logger->records[0][2]
        );

        $envelope = unserialize((string) $this->cache->payload, ['allowed_classes' => true]);
        self::assertSame(CachedNeighborFinder::SCHEMA, $envelope['schema']);
        self::assertSame(CachedNeighborFinder::fingerprint($products), $envelope['fingerprint']);
        self::assertIsString($envelope['rubix']);
        self::assertIsInt($envelope['trained_at']);
        self::assertInstanceOf(RubixNeighborFinder::class, $envelope['finder']);
    }

    public function testWarmCacheHydratesWithoutTrainingAndAnswersTheSame(): void
    {
        $first = $this->finder();
        $first->train(self::catalog());
        $expected = self::ids($first->nearest(self::catalog()[0], 4));

        $second = $this->finder();
        $second->train(self::catalog()); // fresh objects, same content

        self::assertSame(1, $this->fits, 'the second "request" must not refit');
        self::assertSame(1, $this->cache->stores);
        self::assertTrue($second->isTrained());
        self::assertSame($expected, self::ids($second->nearest(self::catalog()[0], 4)));
        self::assertSame(['Modelo KNN treinado', 'Modelo KNN carregado do cache'], $this->logger->messages('info'));
        self::assertSame($this->logger->records[0][2], $this->logger->records[1][2]);
        self::assertSame([], $this->logger->messages('warning'));
    }

    /**
     * @param callable(array<int, array<string, mixed>>): array<int, array<string, mixed>> $change
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('catalogChanges')]
    public function testChangedCatalogRetrainsAndOverwrites(callable $change): void
    {
        $this->finder()->train(self::catalog());
        $firstPayload = $this->cache->payload;

        $changed = array_map(Product::fromArray(...), $change(self::rows()));
        $finder = $this->finder();
        $finder->train($changed);

        self::assertSame(2, $this->fits);
        self::assertNotSame($firstPayload, $this->cache->payload);
        self::assertSame(['Modelo KNN treinado', 'Modelo KNN treinado'], $this->logger->messages('info'));

        // The overwritten slot now serves the new catalog.
        $this->finder()->train($changed);
        self::assertSame(2, $this->fits);
    }

    /** @return array<string, array{0: callable}> */
    public static function catalogChanges(): array
    {
        return [
            'product created' => [static fn (array $rows): array => [...$rows, self::row(7, 'Cadeira', 'Casa', 700.0)]],
            'product removed' => [static fn (array $rows): array => array_slice($rows, 1)],
            'price edited' => [static function (array $rows): array {
                $rows[0]['price'] = 999.0;

                return $rows;
            }],
            'name edited' => [static function (array $rows): array {
                $rows[0]['name'] = 'Fone Novo';

                return $rows;
            }],
            'category edited' => [static function (array $rows): array {
                $rows[0]['category'] = 'Casa';

                return $rows;
            }],
        ];
    }

    public function testRemovedProductIsNeverServedAfterTheCatalogChanges(): void
    {
        $this->finder()->train(self::catalog());

        $withoutMonitor = array_values(array_filter(self::catalog(), static fn (Product $p): bool => $p->getId() !== 2));
        $finder = $this->finder();
        $finder->train($withoutMonitor);

        self::assertNotContains(2, self::ids($finder->nearest(self::catalog()[0], 10)));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidPayloads(): array
    {
        $unexpectedClass = serialize([
            'schema' => CachedNeighborFinder::SCHEMA,
            'rubix' => \Composer\InstalledVersions::getVersion('rubix/ml') ?? 'unknown',
            'fingerprint' => 'x',
            'trained_at' => 1,
            'finder' => new \ArrayObject([1]),
        ]);

        return [
            'not serialized' => ['definitely not a serialized payload'],
            'truncated' => [substr(serialize(['schema' => 1, 'finder' => str_repeat('a', 50)]), 0, 30)],
            'not an array' => [serialize('just a string')],
            'envelope without fields' => [serialize(['schema' => CachedNeighborFinder::SCHEMA])],
            'finder of another class' => [$unexpectedClass],
            'forbidden class nested in the finder' => [(string) preg_replace(
                '/O:\d+:"Rubix\\\\ML\\\\Kernels\\\\Distance\\\\Euclidean"/',
                'O:8:"stdClass"',
                self::validPayload()
            )],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPayloads')]
    public function testCorruptedPayloadIsAMissAndRetrains(string $payload): void
    {
        $this->cache->payload = $payload;

        $finder = $this->finder();
        $finder->train(self::catalog()); // PHPUnit fails the test if a notice/warning leaks

        self::assertSame(1, $this->fits);
        self::assertTrue($finder->isTrained());
        self::assertSame(['Cache do modelo KNN inválido'], $this->logger->messages('warning'));
        self::assertSame(['Modelo KNN treinado'], $this->logger->messages('info'));
        self::assertNotSame($payload, $this->cache->payload, 'the bad payload is overwritten');
    }

    public function testEnvelopeFromAnotherSchemaOrLibraryVersionIsAQuietMiss(): void
    {
        foreach (['schema' => 999, 'rubix' => '0.0.1'] as $field => $value) {
            $envelope = unserialize(self::validPayload(), ['allowed_classes' => true]);
            $envelope[$field] = $value;
            $this->cache->payload = serialize($envelope);
            $this->fits = 0;

            $this->finder()->train(self::catalog());

            self::assertSame(1, $this->fits, $field);
        }
        self::assertSame([], $this->logger->messages('warning'));
    }

    public function testEnvelopeOfAnotherSchemaWithOtherFieldsIsAQuietMiss(): void
    {
        $this->cache->payload = serialize(['schema' => 2, 'rubix' => 'x', 'model' => 'renamed field']);

        $this->finder()->train(self::catalog());

        self::assertSame(1, $this->fits);
        self::assertSame([], $this->logger->messages('warning'));
    }

    public function testFingerprintIgnoresTheOrderTheRepositoryReturns(): void
    {
        self::assertSame(
            CachedNeighborFinder::fingerprint(self::catalog()),
            CachedNeighborFinder::fingerprint(array_reverse(self::catalog()))
        );

        $this->finder()->train(self::catalog());
        $this->finder()->train(array_reverse(self::catalog()));

        self::assertSame(1, $this->fits, 'same catalog in another order is a cache hit');
    }

    public function testRedisDownOnLoadTrainsInMemory(): void
    {
        $this->cache->failOnLoad = true;

        $finder = $this->finder();
        $finder->train(self::catalog());

        self::assertSame(1, $this->fits);
        self::assertNotEmpty($finder->nearest(self::catalog()[0], 3));
        self::assertSame(['Falha ao ler o cache do modelo KNN'], $this->logger->messages('warning'));
    }

    public function testRedisDownOnStoreStillAnswers(): void
    {
        $this->cache->failOnStore = true;

        $finder = $this->finder();
        $finder->train(self::catalog());

        self::assertNotEmpty($finder->nearest(self::catalog()[0], 3));
        self::assertNull($this->cache->payload);
        self::assertSame(['Falha ao gravar o cache do modelo KNN'], $this->logger->messages('warning'));
    }

    public function testUntrainedFinderRefusesQueries(): void
    {
        $finder = $this->finder();

        self::assertFalse($finder->isTrained());
        $this->expectException(\RuntimeException::class);
        $finder->nearest(self::catalog()[0], 3);
    }

    public function testDefaultTrainerFitsARealRubixIndex(): void
    {
        $finder = new CachedNeighborFinder($this->cache, $this->logger);
        $finder->train(self::catalog());

        self::assertSame(self::ids((function () {
            $direct = new RubixNeighborFinder();
            $direct->train(self::catalog());

            return $direct->nearest(self::catalog()[0], 4);
        })()), self::ids($finder->nearest(self::catalog()[0], 4)));
    }

    private function finder(): CachedNeighborFinder
    {
        return new CachedNeighborFinder($this->cache, $this->logger, function (array $products): RubixNeighborFinder {
            ++$this->fits;
            $finder = new RubixNeighborFinder();
            $finder->train($products);

            return $finder;
        });
    }

    private static function validPayload(): string
    {
        $cache = new InMemoryTrainedModelCache();
        (new CachedNeighborFinder($cache, new RecordingLogger()))->train(self::catalog());

        return (string) $cache->payload;
    }

    /** @return list<Product> */
    private static function catalog(): array
    {
        return array_map(Product::fromArray(...), self::rows());
    }

    /** @return list<array<string, mixed>> */
    private static function rows(): array
    {
        return [
            self::row(1, 'Fone', 'Eletrônicos', 200.0),
            self::row(2, 'Monitor', 'Eletrônicos', 1200.0),
            self::row(3, 'Bola', 'Esportes', 100.0),
            self::row(4, 'Tênis', 'Esportes', 400.0),
            self::row(5, 'Luminária', 'Casa', 250.0),
            self::row(6, 'Mouse', 'Eletrônicos', 150.0),
        ];
    }

    /** @return array<string, mixed> */
    private static function row(int $id, string $name, string $category, float $price): array
    {
        return ['id' => $id, 'name' => $name, 'slug' => 'p-' . $id, 'description' => '', 'price' => $price,
            'category' => $category, 'image_url' => '', 'created_at' => '2026-01-01 10:00:00'];
    }

    /**
     * @param list<array{product: Product, distance: float}> $neighbors
     * @return list<int|null>
     */
    private static function ids(array $neighbors): array
    {
        return array_map(static fn (array $n): ?int => $n['product']->getId(), $neighbors);
    }
}
