<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Controller\MetricsController;
use App\Domain\Event\EventHistoryRepositoryInterface;
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
    }

    private function controller(EventHistoryRepositoryInterface $history): MetricsController
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/views'), [
            'strict_variables' => true,
        ]);

        return new MetricsController($history, $twig);
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
