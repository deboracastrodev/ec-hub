<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Controller\MetricsController;
use App\Domain\Event\EventHistoryRepositoryInterface;
use App\Domain\Session\Repository\SessionRepositoryInterface;
use App\Shared\Container\Container;
use App\Shared\Http\SessionContext;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class MetricsHttpEndpointTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testMetricsRouteShowsOnlyCurrentSessionEventsInReverseChronologicalOrder(): void
    {
        $sessionOne = str_repeat('a', 64);
        $sessionTwo = str_repeat('b', 64);
        $history = new HttpMetricsHistoryRepository([
            $sessionOne => [
                ['event' => 'product.viewed', 'product_id' => 7, 'timestamp' => '2026-08-21T10:00:00+00:00'],
                ['event' => 'product.clicked', 'product_id' => 8, 'timestamp' => '2026-08-21T11:00:00+00:00'],
                ['event' => 'cart.item_added', 'timestamp' => '2026-08-21T12:00:00+00:00'],
            ],
            $sessionTwo => [
                ['event' => 'product.viewed', 'product_id' => 999, 'timestamp' => '2026-08-21T13:00:00+00:00'],
            ],
        ]);
        $this->installContainer($history);
        $_COOKIE[SessionContext::COOKIE_NAME] = $sessionOne;
        $_COOKIE[SessionContext::SIGNATURE_COOKIE_NAME] = hash_hmac('sha256', $sessionOne, 'phpunit-only-session-cookie-secret-32');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/metrics';
        $_GET = [];
        header_remove();
        http_response_code(200);

        ob_start();
        require dirname(__DIR__, 3) . '/public/index.php';
        $html = (string) ob_get_clean();

        self::assertSame(200, http_response_code());
        self::assertSame($sessionOne, $history->queriedSessionId);
        self::assertStringContainsString('<title>ec-hub - System Metrics Dashboard</title>', $html);
        self::assertStringContainsString('<h1 class="dashboard__title" id="metrics-dashboard-title">ec-hub - System Metrics Dashboard</h1>', $html);
        self::assertStringContainsString('<section class="dashboard__panel dashboard__panel--history"', $html);
        self::assertStringContainsString('Total de eventos: 3', $html);
        self::assertStringContainsString('cart.item_added', $html);
        self::assertStringContainsString('product.clicked', $html);
        self::assertStringContainsString('Produto: 8', $html);
        self::assertStringNotContainsString('Produto: 999', $html);
        self::assertStringNotContainsString('2026-08-21T13:00:00+00:00', $html);
        self::assertLessThan(strpos($html, 'product.clicked'), strpos($html, 'cart.item_added'));
    }

    #[RunInSeparateProcess]
    public function testMetricsRouteRendersEmptyStateForCurrentSession(): void
    {
        $sessionId = str_repeat('c', 64);
        $history = new HttpMetricsHistoryRepository([$sessionId => []]);
        $this->installContainer($history);
        $_COOKIE[SessionContext::COOKIE_NAME] = $sessionId;
        $_COOKIE[SessionContext::SIGNATURE_COOKIE_NAME] = hash_hmac('sha256', $sessionId, 'phpunit-only-session-cookie-secret-32');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/metrics';
        $_GET = [];
        header_remove();
        http_response_code(200);

        ob_start();
        require dirname(__DIR__, 3) . '/public/index.php';
        $html = (string) ob_get_clean();

        self::assertSame(200, http_response_code());
        self::assertSame($sessionId, $history->queriedSessionId);
        self::assertStringContainsString('ec-hub - System Metrics Dashboard', $html);
        self::assertStringContainsString('Total de eventos: 0', $html);
        self::assertStringContainsString('<p class="dashboard__empty" role="status">Nenhum evento foi registrado nesta sessão.</p>', $html);
        self::assertStringContainsString('ML: indisponível', $html);
        self::assertStringContainsString('Latência: —', $html);
        self::assertStringContainsString('Confiança média: —', $html);
        self::assertStringContainsString('Recomendações: 0', $html);
    }

    #[RunInSeparateProcess]
    public function testMetricsRouteRendersCurrentSessionRecommendationSnapshot(): void
    {
        $sessionId = str_repeat('d', 64);
        $sessions = new HttpMetricsSessionRepository([
            $sessionId => [
                'recommendation.snapshot' => [
                    'source' => 'rules',
                    'latency_ms' => 14.5,
                    'avg_confidence' => 64.0,
                    'count' => 3,
                    'generated_at' => '2026-08-24T12:00:00+00:00',
                ],
            ],
        ]);
        $this->installContainer(new HttpMetricsHistoryRepository([$sessionId => []]), $sessions);
        $_COOKIE[SessionContext::COOKIE_NAME] = $sessionId;
        $_COOKIE[SessionContext::SIGNATURE_COOKIE_NAME] = hash_hmac('sha256', $sessionId, 'phpunit-only-session-cookie-secret-32');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/metrics';
        $_GET = [];
        header_remove();
        http_response_code(200);

        ob_start();
        require dirname(__DIR__, 3) . '/public/index.php';
        $html = (string) ob_get_clean();

        self::assertSame($sessionId, $sessions->queriedSessionId);
        self::assertStringContainsString('ML: inativo (fallback)', $html);
        self::assertStringContainsString('14,50 ms', $html);
        self::assertStringContainsString('64,00%', $html);
        self::assertStringContainsString('Recomendações: 3', $html);
    }

    private function installContainer(EventHistoryRepositoryInterface $history, ?SessionRepositoryInterface $sessions = null): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/views'), [
            'strict_variables' => true,
        ]);
        $GLOBALS['EC_HUB_TEST_CONTAINER'] = new Container([
            Environment::class => fn () => $twig,
            SessionContext::class => fn () => new SessionContext('phpunit-only-session-cookie-secret-32'),
            MetricsController::class => fn () => new MetricsController($history, $twig, $sessions),
        ]);
    }
}

final class HttpMetricsSessionRepository implements SessionRepositoryInterface
{
    public ?string $queriedSessionId = null;

    /** @param array<string, array<string, mixed>> $sessions */
    public function __construct(private array $sessions)
    {
    }

    public function save(string $sessionId, string $field, mixed $value): void
    {
        $this->sessions[$sessionId][$field] = $value;
    }

    public function get(string $sessionId, string $field): mixed
    {
        $this->queriedSessionId = $sessionId;

        return $this->sessions[$sessionId][$field] ?? null;
    }
}

final class HttpMetricsHistoryRepository implements EventHistoryRepositoryInterface
{
    public ?string $queriedSessionId = null;

    /** @param array<string, list<array<string, mixed>>> $eventsBySession */
    public function __construct(private readonly array $eventsBySession)
    {
    }

    public function append(string $sessionId, ?string $userId, array $event): void
    {
    }

    public function getBySession(string $sessionId): array
    {
        $this->queriedSessionId = $sessionId;

        return $this->eventsBySession[$sessionId] ?? [];
    }

    public function getByUserId(string $userId): array
    {
        return [];
    }
}
