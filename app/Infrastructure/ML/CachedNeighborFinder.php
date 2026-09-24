<?php

declare(strict_types=1);

namespace App\Infrastructure\ML;

use App\Application\Recommendation\TrainedModelCacheInterface;
use App\Domain\Product\Model\Product;
use App\Domain\Recommendation\Service\NeighborFinderInterface;
use App\Domain\Shared\ValueObject\Money;
use Closure;
use Composer\InstalledVersions;
use Psr\Log\LoggerInterface;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Graph\Nodes\Ball;
use Rubix\ML\Graph\Nodes\Clique;
use Rubix\ML\Graph\Trees\BallTree;
use Rubix\ML\Kernels\Distance\Euclidean;
use Rubix\ML\Transformers\MinMaxNormalizer;
use Rubix\ML\Transformers\OneHotEncoder;

/**
 * Story 10.1 (R4.4): serves the trained KNN index from a cache instead of
 * refitting it on every request.
 *
 * PHP is stateless per request, so the in-process memoization of
 * GenerateRecommendations/RubixNeighborFinder dies with each request. This
 * decorator stores the trained RubixNeighborFinder, serialized, in a single
 * cache slot together with a fingerprint of the catalog it was trained on.
 * train() becomes "hydrate from the cache or fit": when the fingerprint of
 * the products handed in matches the cached one, the index is unserialized
 * instead of refitted; any difference (a product created, edited or removed,
 * by the admin or straight in the database) is a miss and a refit.
 *
 * The cache is an optimization, never a dependency: an unreachable Redis, a
 * corrupted payload, another envelope schema or another rubix/ml version all
 * degrade to training in memory, as before.
 */
final class CachedNeighborFinder implements NeighborFinderInterface
{
    public const SCHEMA = 1;

    /**
     * Every class a trained RubixNeighborFinder serializes. unserialize()
     * never instantiates anything outside this list.
     */
    private const ALLOWED_CLASSES = [
        RubixNeighborFinder::class,
        BallTree::class,
        Euclidean::class,
        Ball::class,
        Clique::class,
        Labeled::class,
        OneHotEncoder::class,
        MinMaxNormalizer::class,
        Product::class,
        Money::class,
        \DateTimeImmutable::class,
    ];

    private Closure $trainer;

    private ?NeighborFinderInterface $finder = null;

    /**
     * @param (Closure(array<Product>): RubixNeighborFinder)|null $trainer the real fit;
     *        defaults to a fresh RubixNeighborFinder trained on the products
     */
    public function __construct(
        private readonly TrainedModelCacheInterface $cache,
        private readonly LoggerInterface $logger,
        ?Closure $trainer = null,
    ) {
        $this->trainer = $trainer ?? static function (array $products): RubixNeighborFinder {
            $finder = new RubixNeighborFinder();
            $finder->train($products);

            return $finder;
        };
    }

    /**
     * Fingerprint of everything the model serves: any change to a product's
     * name, price, category (or any other field) yields a different hash.
     *
     * @param Product[] $products
     */
    public static function fingerprint(array $products): string
    {
        // Sorted by id: the repository orders by name, and ties between equal
        // names may come back in any order -- same catalog, same hash.
        $products = array_values($products);
        usort($products, static fn (Product $left, Product $right): int => $left->getId() <=> $right->getId());

        return hash('sha256', serialize($products));
    }

    public function train(array $products): void
    {
        $fingerprint = self::fingerprint($products);
        $context = [
            'products_count' => count($products),
            'fingerprint' => substr($fingerprint, 0, 12),
        ];

        $cached = $this->hydrate($fingerprint);
        if ($cached !== null) {
            $this->finder = $cached;
            $this->logger->info('Modelo KNN carregado do cache', $context);

            return;
        }

        $finder = ($this->trainer)($products);
        $this->finder = $finder;
        $this->logger->info('Modelo KNN treinado', $context);

        $this->persist($finder, $fingerprint);
    }

    public function isTrained(): bool
    {
        return $this->finder !== null && $this->finder->isTrained();
    }

    public function nearest(Product $target, int $k): array
    {
        if ($this->finder === null) {
            throw new \RuntimeException('Índice de vizinhos ainda não foi treinado.');
        }

        return $this->finder->nearest($target, $k);
    }

    private function hydrate(string $fingerprint): ?RubixNeighborFinder
    {
        try {
            $payload = $this->cache->load();
        } catch (\Throwable $exception) {
            $this->logger->warning('Falha ao ler o cache do modelo KNN', ['error' => $exception->getMessage()]);

            return null;
        }

        if ($payload === null) {
            return null;
        }

        $envelope = $this->unserialize($payload);

        // Another schema or library version is checked before the shape: an
        // envelope written by other code is a plain miss, not a corruption.
        if (is_array($envelope)
            && isset($envelope['schema'], $envelope['rubix'])
            && ($envelope['schema'] !== self::SCHEMA || $envelope['rubix'] !== self::rubixVersion())
        ) {
            return null;
        }

        if (! is_array($envelope)
            || ! isset($envelope['schema'], $envelope['rubix'], $envelope['fingerprint'], $envelope['trained_at'], $envelope['finder'])
            || ! $envelope['finder'] instanceof RubixNeighborFinder
            || ! $envelope['finder']->isTrained()
        ) {
            $this->logger->warning('Cache do modelo KNN inválido');

            return null;
        }

        // Another catalog: a plain miss, the refit overwrites the slot.
        if ($envelope['fingerprint'] !== $fingerprint) {
            return null;
        }

        return $envelope['finder'];
    }

    /**
     * unserialize() with a closed class list; notices/warnings from a
     * corrupted payload and errors thrown while restoring typed properties
     * are swallowed and reported as "not an envelope" (false).
     */
    private function unserialize(string $payload): mixed
    {
        set_error_handler(static fn (): bool => true);

        try {
            return unserialize($payload, ['allowed_classes' => self::ALLOWED_CLASSES]);
        } catch (\Throwable) {
            return false;
        } finally {
            restore_error_handler();
        }
    }

    private function persist(RubixNeighborFinder $finder, string $fingerprint): void
    {
        try {
            $this->cache->store(serialize([
                'schema' => self::SCHEMA,
                'rubix' => self::rubixVersion(),
                'fingerprint' => $fingerprint,
                'trained_at' => time(),
                'finder' => $finder,
            ]));
        } catch (\Throwable $exception) {
            $this->logger->warning('Falha ao gravar o cache do modelo KNN', ['error' => $exception->getMessage()]);
        }
    }

    private static function rubixVersion(): string
    {
        try {
            return InstalledVersions::getVersion('rubix/ml') ?? 'unknown';
        } catch (\OutOfBoundsException) {
            return 'unknown';
        }
    }
}
