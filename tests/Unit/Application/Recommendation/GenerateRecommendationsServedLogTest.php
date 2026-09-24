<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Recommendation;

use App\Application\Recommendation\GenerateRecommendations;
use App\Domain\Event\EventHistoryRepositoryInterface;
use App\Domain\Product\Model\Product;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Recommendation\Model\RecommendationResult;
use App\Domain\Recommendation\Service\RecommendationStrategy;
use App\Domain\Recommendation\Service\RuleBasedFallback;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Tests\Support\InMemoryProductRepository;

/**
 * Story 10.4: every response of GenerateRecommendations logs exactly one
 * 'Recommendations served' entry naming the strategy behind each item, in
 * the final order.
 */
final class GenerateRecommendationsServedLogTest extends TestCase
{
    private InMemoryProductRepository $repository;
    private ServedLogSpy $logger;

    protected function setUp(): void
    {
        $this->repository = new InMemoryProductRepository(self::catalog());
        $this->logger = new ServedLogSpy();
    }

    public function test_ml_response_logs_every_item_as_knn(): void
    {
        $useCase = $this->useCase($this->strategy([2, 6, 5]));

        $result = $useCase->execute(1, 3);

        $served = $this->singleServedLog();
        self::assertSame(1, $served['target_product_id']);
        self::assertSame('knn', $served['algorithm']);
        self::assertFalse($served['fallback_activated']);
        self::assertSame(
            [
                ['product_id' => 2, 'source' => 'ml', 'strategy' => 'knn'],
                ['product_id' => 6, 'source' => 'ml', 'strategy' => 'knn'],
                ['product_id' => 5, 'source' => 'ml', 'strategy' => 'knn'],
            ],
            $served['recommendations']
        );
        self::assertSame(array_column($result, 'product_id'), array_column($served['recommendations'], 'product_id'));
    }

    public function test_partial_ml_completed_by_fallback_logs_both_strategies(): void
    {
        // Só 1 item de ML para limite 4: o fallback híbrido completa com a mesma categoria (Eletrônicos).
        $useCase = $this->useCase($this->strategy([2]));

        $result = $useCase->execute(1, 4);

        $served = $this->singleServedLog();
        self::assertTrue($served['fallback_activated']);
        self::assertSame(
            [
                ['product_id' => 2, 'source' => 'ml', 'strategy' => 'knn'],
                ['product_id' => 6, 'source' => 'rules', 'strategy' => 'category'],
            ],
            $served['recommendations']
        );
        self::assertSame(array_column($result, 'product_id'), array_column($served['recommendations'], 'product_id'));
        // Os logs existentes continuam.
        self::assertContains('Recommendation algorithm used', $this->logger->messages());
    }

    public function test_forced_fallback_logs_category_and_popularity_items(): void
    {
        $useCase = $this->useCase($this->strategy([4]));

        $result = $useCase->execute(3, 4, true);

        $served = $this->singleServedLog();
        self::assertSame(3, $served['target_product_id']);
        self::assertTrue($served['fallback_activated']);
        self::assertNotSame([], $served['recommendations']);
        foreach ($served['recommendations'] as $item) {
            // 4 é o único outro produto de Esportes; o resto vem da popularidade.
            $expected = $item['product_id'] === 4
                ? ['source' => 'rules', 'strategy' => 'category']
                : ['source' => 'popular', 'strategy' => 'popularity'];
            self::assertSame($expected, ['source' => $item['source'], 'strategy' => $item['strategy']]);
        }
        self::assertSame(4, $served['recommendations'][0]['product_id']);
        self::assertSame(array_column($result, 'product_id'), array_column($served['recommendations'], 'product_id'));
        self::assertContains('Fallback activated: insufficient_session_data', $this->logger->messages());
    }

    public function test_unknown_product_logs_popular_items(): void
    {
        $useCase = $this->useCase($this->strategy([2]));

        $result = $useCase->execute(999, 3);

        $served = $this->singleServedLog();
        self::assertSame(999, $served['target_product_id']);
        self::assertTrue($served['fallback_activated']);
        self::assertCount(3, $served['recommendations']);
        foreach ($served['recommendations'] as $item) {
            self::assertSame('popular', $item['source']);
            self::assertSame('popularity', $item['strategy']);
        }
        self::assertSame(array_column($result, 'product_id'), array_column($served['recommendations'], 'product_id'));
        self::assertContains('Fallback activated: cold_start_unknown_product', $this->logger->messages());
    }

    public function test_ml_error_logs_the_fallback_response_once(): void
    {
        $useCase = $this->useCase($this->strategy([], true));

        $result = $useCase->execute(3, 3);

        $served = $this->singleServedLog();
        self::assertTrue($served['fallback_activated']);
        self::assertNotSame([], $result);
        self::assertNotContains('ml', array_column($served['recommendations'], 'source'));
        self::assertSame(array_column($result, 'product_id'), array_column($served['recommendations'], 'product_id'));
    }

    public function test_small_catalog_logs_the_catalog_fallback(): void
    {
        // 3 produtos < min_products_for_ml (5): o caso de uso nem chama a estratégia.
        $this->repository = new InMemoryProductRepository(array_slice(self::catalog(), 0, 3));
        $useCase = $this->useCase($this->strategy([2]));

        $result = $useCase->execute(1, 2);

        $served = $this->singleServedLog();
        self::assertTrue($served['fallback_activated']);
        self::assertNotSame([], $served['recommendations']);
        self::assertNotContains('ml', array_column($served['recommendations'], 'source'));
        self::assertSame(array_column($result, 'product_id'), array_column($served['recommendations'], 'product_id'));
        self::assertContains('Fallback activated: insufficient_catalog_data', $this->logger->messages());
    }

    public function test_ml_error_without_product_logs_an_empty_list(): void
    {
        // O produto existe na primeira leitura e some antes da leitura do catch.
        $this->repository = new class (self::catalog()) extends InMemoryProductRepository {
            private int $reads = 0;

            public function findById(int $id): ?Product
            {
                return ++$this->reads === 1 ? parent::findById($id) : null;
            }
        };
        $useCase = $this->useCase($this->strategy([], true));

        $result = $useCase->execute(1, 3);

        $served = $this->singleServedLog();
        self::assertSame([], $result);
        self::assertSame([], $served['recommendations']);
        self::assertFalse($served['fallback_activated']);
        self::assertContains('ML failed, using fallback', $this->logger->messages());
    }

    public function test_logged_order_is_the_personalized_order(): void
    {
        // Histórico da sessão com o produto 5 (Casa): a personalização o sobe para o topo.
        $history = new class () implements EventHistoryRepositoryInterface {
            public function append(string $sessionId, ?string $userId, array $event): void
            {
            }

            public function getBySession(string $sessionId): array
            {
                return $sessionId === 'session-1' ? [['event' => 'product.viewed', 'product_id' => 5]] : [];
            }

            public function getByUserId(string $userId): array
            {
                return [];
            }
        };
        $useCase = new GenerateRecommendations(
            $this->repository,
            $this->strategy([2, 6, 5]),
            new RuleBasedFallback($this->repository, $this->logger),
            $this->logger,
            null,
            null,
            $history
        );

        $result = $useCase->execute(1, 3, false, null, 'session-1');

        $served = $this->singleServedLog();
        self::assertSame([5, 2, 6], array_column($result, 'product_id'));
        self::assertSame([5, 2, 6], array_column($served['recommendations'], 'product_id'));
        self::assertNotSame([2, 6, 5], array_column($served['recommendations'], 'product_id'));
        self::assertSame(['knn', 'knn', 'knn'], array_column($served['recommendations'], 'strategy'));
    }

    public function test_failing_logger_never_changes_the_ml_response(): void
    {
        $expected = $this->useCase($this->strategy([2, 6, 5]))->execute(1, 3);

        $throwing = new class () extends AbstractLogger {
            public int $calls = 0;

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                ++$this->calls;

                throw new \RuntimeException('disco cheio');
            }
        };
        $useCase = new GenerateRecommendations(
            $this->repository,
            $this->strategy([2, 6, 5]),
            new RuleBasedFallback($this->repository, new ServedLogSpy()),
            $throwing
        );

        $result = $useCase->execute(1, 3);

        self::assertSame($expected, $result);
        self::assertSame(['ml', 'ml', 'ml'], array_column($result, 'source'));
        self::assertGreaterThan(0, $throwing->calls);
    }

    /** @return array<string, mixed> */
    private function singleServedLog(): array
    {
        $served = array_values(array_filter(
            $this->logger->records,
            static fn (array $record): bool => $record[1] === 'Recommendations served'
        ));
        self::assertCount(1, $served, 'esperado exatamente um log "Recommendations served" por chamada');
        self::assertSame('info', $served[0][0]);

        return $served[0][2];
    }

    /** @param list<int> $ids */
    private function strategy(array $ids, bool $fail = false): ServedLogStrategy
    {
        return new ServedLogStrategy($ids, $fail, $this->repository);
    }

    private function useCase(RecommendationStrategy $strategy): GenerateRecommendations
    {
        return new GenerateRecommendations(
            $this->repository,
            $strategy,
            new RuleBasedFallback($this->repository, $this->logger),
            $this->logger
        );
    }

    /** @return list<array<string, mixed>> */
    private static function catalog(): array
    {
        $rows = [
            [1, 'Fone', 'Eletrônicos', 200.0],
            [2, 'Monitor', 'Eletrônicos', 1200.0],
            [3, 'Bola', 'Esportes', 100.0],
            [4, 'Tênis', 'Esportes', 400.0],
            [5, 'Luminária', 'Casa', 250.0],
            [6, 'Notebook', 'Eletrônicos', 4000.0],
        ];

        return array_map(static fn (array $row): array => [
            'id' => $row[0],
            'name' => $row[1],
            'slug' => strtolower($row[1]),
            'description' => '',
            'price' => $row[3],
            'category' => $row[2],
            'image_url' => '',
            'created_at' => '2026-01-01 10:00:00',
        ], $rows);
    }
}

/**
 * Estratégia 'knn' falsa: devolve os ids dados, com nome, categoria e preço
 * do próprio catálogo, ou falha se $fail.
 */
final class ServedLogStrategy implements RecommendationStrategy
{
    private bool $trained = false;

    /** @param list<int> $ids */
    public function __construct(
        private readonly array $ids,
        private readonly bool $fail = false,
        private readonly ?ProductRepositoryInterface $catalog = null,
    ) {
    }

    public function getName(): string
    {
        return 'knn';
    }

    public function isTrained(): bool
    {
        return $this->trained;
    }

    public function train(array $products): void
    {
        $this->trained = true;
    }

    public function recommend(Product $target, int $limit): array
    {
        if ($this->fail) {
            throw new \RuntimeException('índice indisponível');
        }

        $results = [];
        foreach (array_slice($this->ids, 0, $limit) as $rank => $id) {
            $product = $this->catalog?->findById($id);
            $results[] = new RecommendationResult(
                $id,
                $product?->getName() ?? 'Produto ' . $id,
                $product?->getCategory() ?? 'Eletrônicos',
                $product?->getPrice()->getDecimal() ?? 10.0,
                90.0 - $rank,
                $rank + 1,
                'x'
            );
        }

        return $results;
    }
}

final class ServedLogSpy extends AbstractLogger
{
    /** @var list<array{0: mixed, 1: string, 2: array<mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [$level, (string) $message, $context];
    }

    /** @return list<string> */
    public function messages(): array
    {
        return array_column($this->records, 1);
    }
}
