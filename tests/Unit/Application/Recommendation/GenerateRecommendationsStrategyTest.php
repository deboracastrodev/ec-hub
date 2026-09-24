<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Recommendation;

use App\Application\Recommendation\GenerateRecommendations;
use App\Domain\Product\Model\Product;
use App\Domain\Recommendation\Model\RecommendationResult;
use App\Domain\Recommendation\Service\CollaborativeFilteringService;
use App\Domain\Recommendation\Service\ExplanationGenerator;
use App\Domain\Recommendation\Service\RecommendationStrategy;
use App\Domain\Recommendation\Service\RuleBasedFallback;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Tests\Support\InMemoryEventStore;
use Tests\Support\InMemoryProductRepository;

/**
 * Story 8.1: GenerateRecommendations is algorithm-agnostic -- it talks to a
 * RecommendationStrategy, logs which one answered and keeps every fallback
 * path unchanged.
 */
final class GenerateRecommendationsStrategyTest extends TestCase
{
    private InMemoryProductRepository $repository;
    private StrategyRecordingLogger $logger;

    protected function setUp(): void
    {
        $this->repository = new InMemoryProductRepository(self::catalog());
        $this->logger = new StrategyRecordingLogger();
    }

    public function testLogsTrainingAndTheAlgorithmThatAnswered(): void
    {
        $store = $this->storeWithInteractions();
        $useCase = $this->useCase(new CollaborativeFilteringService($store, new ExplanationGenerator()));

        $useCase->execute(1, 2);

        self::assertContains(
            ['info', 'Recommendation model trained', ['algorithm' => 'collaborative']],
            $this->logger->records
        );
        self::assertContains(
            ['info', 'Recommendation algorithm used', [
                'algorithm' => 'collaborative',
                'target_product_id' => 1,
                'count' => 2,
            ]],
            $this->logger->records
        );
        self::assertSame('collaborative', $useCase->getAlgorithmName());
    }

    public function testCollaborativeExplanationAndReasonsArePreserved(): void
    {
        $useCase = $this->useCase(
            new CollaborativeFilteringService($this->storeWithInteractions(), new ExplanationGenerator())
        );

        $result = $useCase->execute(1, 2);

        self::assertSame([3, 2], array_column($result, 'product_id'));
        self::assertSame(['ml', 'ml'], array_column($result, 'source'));
        self::assertStringStartsWith('Quem se interessou por Fone', $result[0]['explanation']);
        self::assertSame('co_interaction', $result[0]['reasons'][0]['type']);
    }

    public function testEmptyCollaborativeResultIsCompletedByFallback(): void
    {
        $useCase = $this->useCase(
            new CollaborativeFilteringService(new InMemoryEventStore(), new ExplanationGenerator())
        );

        $result = $useCase->execute(1, 3);

        self::assertNotEmpty($result);
        foreach ($result as $item) {
            self::assertContains($item['source'], ['rules', 'popular']);
            self::assertNotSame(1, (int) $item['product_id']);
        }
        self::assertContains(
            ['info', 'Recommendation algorithm used', [
                'algorithm' => 'collaborative',
                'target_product_id' => 1,
                'count' => 0,
            ]],
            $this->logger->records
        );
        self::assertSame('collaborative', $useCase->getAlgorithmName());
    }

    public function testUnavailableEventStoreFallsBackWithMlErrorAndLogsTheAlgorithm(): void
    {
        $useCase = $this->useCase(
            new CollaborativeFilteringService(new InMemoryEventStore(true), new ExplanationGenerator())
        );

        $result = $useCase->execute(1, 3);

        self::assertNotEmpty($result);
        self::assertContains(
            ['error', 'ML failed, using fallback', ['error' => 'Event store indisponível.', 'algorithm' => 'collaborative']],
            $this->logger->records
        );
        self::assertContains('Fallback activated: ml_error', array_column($this->logger->records, 1));
    }

    public function testResultsWithoutReasonsStillGetTheMlExplanation(): void
    {
        $strategy = new FixedStrategy([
            new RecommendationResult(2, 'Monitor', 'Eletrônicos', 1200.0, 90.0, 1, 'texto da estratégia'),
        ]);
        $useCase = $this->useCase($strategy);

        $result = $useCase->execute(1, 1);

        self::assertSame(
            'Recomendado com base em Fone que você visualizou (90% de similaridade)',
            $result[0]['explanation']
        );
        self::assertSame('similarity', $result[0]['reasons'][0]['type']);
        self::assertSame('fixed', $useCase->getAlgorithmName());
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

    private function storeWithInteractions(): InMemoryEventStore
    {
        $store = new InMemoryEventStore();
        foreach (['s1' => [1, 2, 3], 's2' => [1, 2], 's3' => [1, 3], 's4' => [2]] as $session => $products) {
            foreach ($products as $productId) {
                $store->interaction('product.viewed', $session, $productId);
            }
        }

        return $store;
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

final class FixedStrategy implements RecommendationStrategy
{
    private bool $trained = false;

    /** @param RecommendationResult[] $results */
    public function __construct(private readonly array $results)
    {
    }

    public function getName(): string
    {
        return 'fixed';
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
        return array_slice($this->results, 0, $limit);
    }
}

final class StrategyRecordingLogger extends AbstractLogger
{
    /** @var list<array{0: mixed, 1: string, 2: array<mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [$level, (string) $message, $context];
    }
}
