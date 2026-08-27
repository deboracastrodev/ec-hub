<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Controller\MetricsController;
use App\Domain\Event\EventBusStatus;
use App\Domain\Event\EventBusStatusInterface;
use App\Domain\Event\EventHistoryRepositoryInterface;
use App\Domain\Session\Repository\SessionRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class MetricsControllerTest extends TestCase
{
    public function testItRendersReverseChronologicalHistoryWithStableTiesAndTotal(): void
    {
        $history = new MetricsInMemoryHistoryRepository([
            ['event' => 'product.viewed', 'product_id' => 7, 'timestamp' => '2026-08-21T10:00:00+00:00'],
            ['event' => 'product.clicked', 'product_id' => 8, 'timestamp' => '2026-08-21T11:00:00+00:00'],
            ['event' => 'cart.item_added', 'product_id' => 9, 'timestamp' => '2026-08-21T11:00:00+00:00'],
        ]);
        $controller = $this->controller($history);

        $html = $controller->index([], [], 'current-session');

        self::assertSame('current-session', $history->queriedSessionId);
        self::assertStringContainsString('ec-hub - System Metrics Dashboard', $html);
        self::assertStringContainsString('class="dashboard__panel dashboard__panel--history"', $html);
        self::assertStringContainsString('Total de eventos: 3', $html);
        self::assertLessThan(
            strpos($html, 'cart.item_added'),
            strpos($html, 'product.clicked')
        );
        self::assertLessThan(
            strpos($html, 'product.viewed'),
            strpos($html, 'product.clicked')
        );
    }

    public function testItOmitsProductWhenItIsNotPresent(): void
    {
        $controller = $this->controller(new MetricsInMemoryHistoryRepository([
            ['event' => 'session.started', 'timestamp' => '2026-08-21T10:00:00+00:00'],
        ]));

        $html = $controller->index([], [], 'current-session');

        self::assertStringContainsString('session.started', $html);
        self::assertStringNotContainsString('Produto:', $html);
    }

    public function testItRendersAnEmptyHistory(): void
    {
        $history = new MetricsInMemoryHistoryRepository([]);
        $controller = $this->controller($history);

        $html = $controller->index([], [], 'empty-session');

        self::assertSame('empty-session', $history->queriedSessionId);
        self::assertStringContainsString('class="dashboard"', $html);
        self::assertStringContainsString('Total de eventos: 0', $html);
        self::assertStringContainsString('Nenhum evento foi registrado nesta sessão.', $html);
        self::assertStringContainsString('ML: indisponível', $html);
        self::assertStringContainsString('Latência: —', $html);
        self::assertStringContainsString('Confiança média: —', $html);
        self::assertStringContainsString('Recomendações: 0', $html);
    }

    public function testItRendersArchitectureVisibilityWithPubSubConnectedAndFiveSignalsNotAvailableYet(): void
    {
        $controller = $this->controller(
            new MetricsInMemoryHistoryRepository([]),
            null,
            new MetricsFakeEventBusStatus(new EventBusStatus(connected: true, publishedCount: 7))
        );

        $html = $controller->index([], [], 'current-session');

        self::assertStringContainsString('Architecture Visibility', $html);
        self::assertStringContainsString('Redis Pub/Sub: connected · 7 eventos publicados', $html);
        self::assertStringContainsString('Not available yet (Epic 10 / Story 10.1)', $html);
        self::assertStringContainsString('Not available yet (Story 5.6)', $html);
        self::assertStringContainsString('Not available yet (Epic 10)', $html);
        self::assertStringContainsString('Not available yet (Story 10.5)', $html);
    }

    public function testItRendersPubSubAsDisconnectedWhenTheRealPingFails(): void
    {
        $controller = $this->controller(
            new MetricsInMemoryHistoryRepository([]),
            null,
            new MetricsFakeEventBusStatus(new EventBusStatus(connected: false, publishedCount: 0))
        );

        $html = $controller->index([], [], 'current-session');

        self::assertStringContainsString('Redis Pub/Sub: disconnected', $html);
        self::assertStringNotContainsString('eventos publicados', $html);
    }

    public function testItRendersPubSubAsNotAvailableWhenTheStatusSourceThrows(): void
    {
        $controller = $this->controller(
            new MetricsInMemoryHistoryRepository([]),
            null,
            new MetricsFakeEventBusStatus(null, throws: true)
        );

        $html = $controller->index([], [], 'current-session');

        self::assertStringContainsString('Redis Pub/Sub: Not available', $html);
    }

    public function testItRendersPubSubAsNotAvailableWhenNoStatusSourceIsWired(): void
    {
        $controller = $this->controller(new MetricsInMemoryHistoryRepository([]));

        $html = $controller->index([], [], 'current-session');

        self::assertStringContainsString('Redis Pub/Sub: Not available', $html);
    }

    public function testItNeverReusesTheSessionAverageConfidenceAsTheLevelThreeConfidenceSignal(): void
    {
        $sessions = new MetricsInMemorySessionRepository([
            'recommendation.snapshot' => [
                'source' => 'ml',
                'latency_ms' => 12.34,
                'avg_confidence' => 87.5,
                'count' => 2,
                'generated_at' => '2026-08-24T12:00:00+00:00',
            ],
        ]);
        $controller = $this->controller(new MetricsInMemoryHistoryRepository([]), $sessions);

        $html = $controller->index([], [], 'current-session');

        self::assertStringContainsString('87,50%', $html);
        self::assertStringContainsString('Not available yet (Epic 10)', $html);
    }

    public function testItRendersRecordedRecommendationSnapshot(): void
    {
        $sessions = new MetricsInMemorySessionRepository([
            'recommendation.snapshot' => [
                'source' => 'ml',
                'latency_ms' => 12.34,
                'avg_confidence' => 87.5,
                'count' => 2,
                'generated_at' => '2026-08-24T12:00:00+00:00',
            ],
        ]);
        $controller = $this->controller(new MetricsInMemoryHistoryRepository([
            ['event' => 'product.viewed', 'timestamp' => '2026-08-24T11:00:00+00:00'],
        ]), $sessions);

        $html = $controller->index([], [], 'current-session');

        self::assertSame('current-session', $sessions->queriedSessionId);
        self::assertStringContainsString('ML: ativo', $html);
        self::assertStringContainsString('12,34 ms', $html);
        self::assertStringContainsString('87,50%', $html);
        self::assertStringContainsString('Total de eventos: 1', $html);
        self::assertStringContainsString('Recomendações: 2', $html);
    }

    public function testItRendersViewedProductsAndChangedRecommendationEvidence(): void
    {
        $sessions = new MetricsInMemorySessionRepository(['recommendation.snapshot' => [
            'current' => ['source' => 'ml', 'latency_ms' => 10.0, 'avg_confidence' => 80.0, 'count' => 2, 'generated_at' => '2026-08-24T12:01:00+00:00', 'product_ids' => [2, 3]],
            'previous' => ['source' => 'ml', 'latency_ms' => 9.0, 'avg_confidence' => 80.0, 'count' => 2, 'generated_at' => '2026-08-24T12:00:00+00:00', 'product_ids' => [2, 4]],
        ]]);
        $html = $this->controller(new MetricsInMemoryHistoryRepository([
            ['event' => 'product.viewed', 'product_id' => 7, 'timestamp' => '2026-08-24T11:00:00+00:00'],
            ['event' => 'product.clicked', 'product_id' => 99, 'timestamp' => '2026-08-24T11:01:00+00:00'],
        ]), $sessions)->index([], [], 'current-session');

        self::assertStringContainsString('Produto: 7', $html);
        self::assertStringNotContainsString('<li>Produto: 99</li>', $html);
        self::assertStringContainsString('A recomendação mudou nesta sessão.', $html);
        self::assertStringContainsString('Anterior: 2, 4. Atual: 2, 3.', $html);
    }

    public function testItRendersUnchangedAndUnavailableComparisonStates(): void
    {
        $unchanged = new MetricsInMemorySessionRepository(['recommendation.snapshot' => [
            'current' => ['source' => 'ml', 'latency_ms' => 10.0, 'avg_confidence' => 80.0, 'count' => 2, 'generated_at' => '2026-08-24T12:01:00+00:00', 'product_ids' => [2, 3]],
            'previous' => ['source' => 'rules', 'latency_ms' => 9.0, 'avg_confidence' => 10.0, 'count' => 2, 'generated_at' => '2026-08-24T12:00:00+00:00', 'product_ids' => [2, 3]],
        ]]);
        $html = $this->controller(new MetricsInMemoryHistoryRepository([]), $unchanged)->index([], [], 'current-session');
        self::assertStringContainsString('A recomendação não mudou nesta sessão.', $html);
        self::assertStringContainsString('Recomendação atual: 2, 3.', $html);
        self::assertStringContainsString('Recomendação anterior: 2, 3.', $html);

        $unavailable = new MetricsInMemorySessionRepository(['recommendation.snapshot' => [
            'current' => ['source' => 'ml', 'latency_ms' => 10.0, 'avg_confidence' => 80.0, 'count' => 2, 'generated_at' => '2026-08-24T12:01:00+00:00'],
        ]]);
        $html = $this->controller(new MetricsInMemoryHistoryRepository([]), $unavailable)->index([], [], 'current-session');
        self::assertStringContainsString('Ainda sem comparação nesta sessão.', $html);
    }

    public function testItTreatsUnreadableSnapshotAsUnavailable(): void
    {
        $controller = $this->controller(
            new MetricsInMemoryHistoryRepository([]),
            new MetricsInMemorySessionRepository([], true)
        );

        $html = $controller->index([], [], 'current-session');

        self::assertStringContainsString('ML: indisponível', $html);
        self::assertStringContainsString('Recomendações: 0', $html);
    }

    public function testItTreatsInvalidSnapshotFieldsAsUnavailable(): void
    {
        $controller = $this->controller(
            new MetricsInMemoryHistoryRepository([]),
            new MetricsInMemorySessionRepository([
                'recommendation.snapshot' => [
                    'source' => 'unknown',
                    'latency_ms' => -1,
                    'avg_confidence' => 101,
                    'count' => 1,
                    'generated_at' => '2026-08-24T12:00:00+00:00',
                ],
            ])
        );

        $html = $controller->index([], [], 'current-session');

        self::assertStringContainsString('ML: indisponível', $html);
        self::assertStringContainsString('Latência: —', $html);
    }

    private function controller(
        EventHistoryRepositoryInterface $history,
        ?SessionRepositoryInterface $sessions = null,
        ?EventBusStatusInterface $eventBusStatus = null,
    ): MetricsController {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/views'), [
            'strict_variables' => true,
        ]);

        return new MetricsController($history, $twig, $sessions, $eventBusStatus);
    }
}

final class MetricsFakeEventBusStatus implements EventBusStatusInterface
{
    public function __construct(
        private readonly ?EventBusStatus $status,
        private readonly bool $throws = false,
    ) {
    }

    public function status(): EventBusStatus
    {
        if ($this->throws || $this->status === null) {
            throw new \RuntimeException('Fonte de status indisponível.');
        }

        return $this->status;
    }
}

final class MetricsInMemorySessionRepository implements SessionRepositoryInterface
{
    public ?string $queriedSessionId = null;

    /** @param array<string, mixed> $data */
    public function __construct(private array $data, private readonly bool $throwsOnGet = false)
    {
    }

    public function save(string $sessionId, string $field, mixed $value): void
    {
        $this->data[$field] = $value;
    }

    public function compareAndSwap(string $sessionId, string $field, mixed $expected, mixed $value): bool
    {
        if (($this->data[$field] ?? null) !== $expected) {
            return false;
        }
        $this->data[$field] = $value;

        return true;
    }

    public function get(string $sessionId, string $field): mixed
    {
        $this->queriedSessionId = $sessionId;
        if ($this->throwsOnGet) {
            throw new \UnexpectedValueException('Snapshot inválido.');
        }

        return $this->data[$field] ?? null;
    }
}

final class MetricsInMemoryHistoryRepository implements EventHistoryRepositoryInterface
{
    public ?string $queriedSessionId = null;

    /** @param list<array<string, mixed>> $events */
    public function __construct(private readonly array $events)
    {
    }

    public function append(string $sessionId, ?string $userId, array $event): void
    {
    }

    public function getBySession(string $sessionId): array
    {
        $this->queriedSessionId = $sessionId;

        return $this->events;
    }

    public function getByUserId(string $userId): array
    {
        return [];
    }
}
