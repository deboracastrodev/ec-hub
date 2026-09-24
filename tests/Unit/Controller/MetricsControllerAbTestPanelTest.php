<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Controller\MetricsController;
use App\Domain\Event\EventHistoryRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Story 8.2: A/B comparison panel on /metrics.
 */
final class MetricsControllerAbTestPanelTest extends TestCase
{
    public function testItRendersTheComparisonTableWithOneRowPerAlgorithm(): void
    {
        $html = $this->controller(static fn (): array => [
            'enabled' => true,
            'variants' => ['A' => 'knn', 'B' => 'collaborative'],
            'algorithms' => [
                ['algorithm' => 'knn', 'variant' => 'A', 'requests' => 12, 'unique_subjects' => 5, 'total_items' => 60, 'ml_items' => 45, 'ml_item_rate' => 75.0, 'avg_response_time_ms' => 18.25, 'avg_score' => 71.5],
                ['algorithm' => 'collaborative', 'variant' => 'B', 'requests' => 9, 'unique_subjects' => 4, 'total_items' => 40, 'ml_items' => 10, 'ml_item_rate' => 25.0, 'avg_response_time_ms' => 30.0, 'avg_score' => 55.12],
            ],
        ])->index([], [], 'current-session');

        self::assertStringContainsString('Comparação de algoritmos (A/B)', $html);
        self::assertStringContainsString('<caption class="dashboard__ab-caption">Métricas por algoritmo</caption>', $html);
        self::assertStringContainsString('<th scope="col">Sujeitos únicos</th>', $html);
        self::assertStringContainsString('<th scope="row">knn</th>', $html);
        self::assertStringContainsString('<th scope="row">collaborative</th>', $html);
        self::assertStringContainsString('Teste A/B: ativo · A = knn · B = collaborative', $html);
        self::assertStringContainsString('75,00%', $html);
        self::assertStringContainsString('18,25 ms', $html);
        self::assertStringContainsString('71,50', $html);
        self::assertStringContainsString('55,12', $html);
        self::assertStringContainsString('<a href="/api/ab-tests/results">Exportar resultados (JSON)</a>', $html);
        self::assertStringNotContainsString('Comparação indisponível', $html);
    }

    public function testItRendersDisabledStatusAndDashesForEmptyAlgorithms(): void
    {
        $html = $this->controller(static fn (): array => [
            'enabled' => false,
            'variants' => null,
            'algorithms' => [
                ['algorithm' => 'knn', 'variant' => null, 'requests' => 0, 'unique_subjects' => 0, 'total_items' => 0, 'ml_items' => 0, 'ml_item_rate' => null, 'avg_response_time_ms' => null, 'avg_score' => null],
                ['algorithm' => 'collaborative', 'variant' => null, 'requests' => 0, 'unique_subjects' => 0, 'total_items' => 0, 'ml_items' => 0, 'ml_item_rate' => null, 'avg_response_time_ms' => null, 'avg_score' => null],
            ],
        ])->index([], [], 'current-session');

        self::assertStringContainsString('Teste A/B: desligado', $html);
        self::assertStringContainsString('<th scope="row">collaborative</th>', $html);
        self::assertStringContainsString('<td>—</td>', $html);
    }

    public function testItShowsUnavailableWhenResultsThrow(): void
    {
        $html = $this->controller(static function (): array {
            throw new \InvalidArgumentException('RECOMMENDATION_AB_TEST inválido');
        })->index([], [], 'current-session');

        self::assertStringContainsString('Comparação indisponível', $html);
        self::assertStringNotContainsString('<table class="dashboard__ab-table">', $html);
        self::assertStringNotContainsString('Exportar resultados (JSON)', $html);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function malformedResults(): array
    {
        $row = ['algorithm' => 'knn', 'variant' => 'A', 'requests' => 1, 'unique_subjects' => 1, 'total_items' => 1, 'ml_items' => 1, 'ml_item_rate' => 100.0, 'avg_response_time_ms' => 1.0, 'avg_score' => 80.0];
        $valid = ['enabled' => true, 'variants' => ['A' => 'knn', 'B' => 'collaborative'], 'algorithms' => [$row]];
        $missingKey = $row;
        unset($missingKey['avg_score']);

        return [
            'enabled true without variants' => [['variants' => null] + $valid],
            'enabled not bool' => [['enabled' => 'yes'] + $valid],
            'enabled missing' => [array_diff_key($valid, ['enabled' => true])],
            'variants without B' => [['variants' => ['A' => 'knn']] + $valid],
            'variants not array' => [['variants' => 'knn,collaborative'] + $valid],
            'algorithms missing' => [array_diff_key($valid, ['algorithms' => true])],
            'algorithms not a list' => [['algorithms' => ['knn' => $row]] + $valid],
            'row not an array' => [['algorithms' => ['knn']] + $valid],
            'row missing a key' => [['algorithms' => [$missingKey]] + $valid],
        ];
    }

    /** @param array<string, mixed> $results */
    #[DataProvider('malformedResults')]
    public function testItShowsUnavailableForMalformedResults(array $results): void
    {
        $html = $this->controller(static fn (): array => $results)->index([], [], 'current-session');

        self::assertStringContainsString('Comparação indisponível', $html);
        self::assertStringNotContainsString('<table class="dashboard__ab-table">', $html);
        self::assertStringNotContainsString('Exportar resultados (JSON)', $html);
    }

    public function testItShowsUnavailableWhenNoResultsSourceIsWired(): void
    {
        $html = $this->controller(null)->index([], [], 'current-session');

        self::assertStringContainsString('Comparação indisponível', $html);
    }

    private function controller(?\Closure $results): MetricsController
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/views'), [
            'strict_variables' => true,
        ]);

        $history = new class () implements EventHistoryRepositoryInterface {
            public function append(string $sessionId, ?string $userId, array $event): void
            {
            }

            public function getBySession(string $sessionId): array
            {
                return [];
            }

            public function getByUserId(string $userId): array
            {
                return [];
            }
        };

        return new MetricsController($history, $twig, null, null, $results);
    }
}
