<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Application\Recommendation\GenerateRecommendations;
use App\Controller\MetricsController;
use App\Controller\RecommendationController;
use App\Domain\Event\EventBusStatus;
use App\Domain\Event\EventBusStatusInterface;
use App\Domain\Event\EventHistoryRepositoryInterface;
use App\Domain\Session\Repository\SessionRepositoryInterface;
use App\Shared\Container\Container;
use App\Shared\Http\SessionContext;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
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
    public function testMetricsRouteRendersArchitectureVisibilityWithRealPubSubStatusAndRemainingSignalsAsNotAvailableYet(): void
    {
        $sessionId = str_repeat('e', 64);
        $history = new HttpMetricsHistoryRepository([$sessionId => []]);
        $this->installContainer($history, null, new HttpMetricsEventBusStatus(new EventBusStatus(connected: true, publishedCount: 3)));
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
        self::assertStringContainsString('Architecture Visibility', $html);
        self::assertStringContainsString('Redis Pub/Sub: connected · 3 eventos publicados', $html);
        self::assertStringContainsString('Not available yet (Story 5.6)', $html);
        self::assertStringContainsString('Not available yet (Epic 10)', $html);
        self::assertStringContainsString('Not available yet (Story 10.5)', $html);
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

    #[RunInSeparateProcess]
    public function testPublicRecommendationJourneyFeedsAllMetricsComparisonStatesWithoutRecalculationOrSessionLeakage(): void
    {
        $changedSession = str_repeat('1', 64);
        $unchangedSession = str_repeat('2', 64);
        $unavailableSession = str_repeat('3', 64);
        $sessions = new HttpMetricsSessionRepository([]);
        $history = new HttpMetricsHistoryRepository([
            $changedSession => [
                ['event' => 'product.viewed', 'product_id' => 'produto-visto-' . str_repeat('x', 80), 'timestamp' => '2026-08-26T10:00:00+00:00'],
            ],
            $unchangedSession => [
                ['event' => 'product.viewed', 'product_id' => 202, 'timestamp' => '2026-08-26T10:01:00+00:00'],
            ],
            $unavailableSession => [
                ['event' => 'product.viewed', 'product_id' => 303, 'timestamp' => '2026-08-26T10:02:00+00:00'],
            ],
        ]);
        $recommendations = $this->createMock(GenerateRecommendations::class);
        $recommendations->expects($this->exactly(5))
            ->method('execute')
            ->willReturnOnConsecutiveCalls(
                [['product_id' => 11, 'score' => 70.0], ['product_id' => 12, 'score' => 50.0]],
                [['product_id' => 21, 'score' => 95.0], ['product_id' => 22, 'score' => 85.0]],
                [['product_id' => 31, 'score' => 80.0], ['product_id' => 32, 'score' => 60.0]],
                [['product_id' => 31, 'score' => 80.0], ['product_id' => 32, 'score' => 60.0]],
                [['product_id' => 41, 'score' => 55.0]],
            );
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/views'), ['strict_variables' => true]);
        $recommendationController = new RecommendationController($recommendations, new NullLogger(), $sessions);
        $metricsController = new MetricsController($history, $twig, $sessions);

        $this->assertSuccessfulRecommendationResponse($this->dispatch('GET', '/api/recommendations?product_id=1', ['product_id' => '1'], $changedSession, $twig, $recommendationController, $metricsController));
        $this->assertSuccessfulRecommendationResponse($this->dispatch('GET', '/api/recommendations?product_id=2', ['product_id' => '2'], $changedSession, $twig, $recommendationController, $metricsController));
        $changedResponse = $this->dispatch('GET', '/metrics', [], $changedSession, $twig, $recommendationController, $metricsController);
        self::assertSame(200, $changedResponse['status']);
        $changedHtml = $changedResponse['body'];

        self::assertStringContainsString('A recomendação mudou nesta sessão.', $changedHtml);
        self::assertStringContainsString('Anterior: 11, 12. Atual: 21, 22.', $changedHtml);
        self::assertStringContainsString('Confiança média', $changedHtml);
        self::assertStringContainsString('90,00%', $changedHtml);
        self::assertStringContainsString('Recomendações: 2', $changedHtml);
        self::assertStringContainsString('produto-visto-' . str_repeat('x', 80), $changedHtml);
        self::assertStringNotContainsString('Produto: 202', $changedHtml);

        $this->assertSuccessfulRecommendationResponse($this->dispatch('GET', '/api/recommendations?product_id=3', ['product_id' => '3'], $unchangedSession, $twig, $recommendationController, $metricsController));
        $this->assertSuccessfulRecommendationResponse($this->dispatch('GET', '/api/recommendations?product_id=4', ['product_id' => '4'], $unchangedSession, $twig, $recommendationController, $metricsController));
        $unchangedResponse = $this->dispatch('GET', '/metrics', [], $unchangedSession, $twig, $recommendationController, $metricsController);
        self::assertSame(200, $unchangedResponse['status']);
        $unchangedHtml = $unchangedResponse['body'];

        self::assertStringContainsString('A recomendação não mudou nesta sessão.', $unchangedHtml);
        self::assertStringContainsString('Recomendação atual: 31, 32. Recomendação anterior: 31, 32.', $unchangedHtml);
        self::assertStringContainsString('Produto: 202', $unchangedHtml);
        self::assertStringNotContainsString('Produto: 303', $unchangedHtml);

        $this->assertSuccessfulRecommendationResponse($this->dispatch('GET', '/api/recommendations?product_id=5', ['product_id' => '5'], $unavailableSession, $twig, $recommendationController, $metricsController));
        $unavailableResponse = $this->dispatch('GET', '/metrics', [], $unavailableSession, $twig, $recommendationController, $metricsController);
        self::assertSame(200, $unavailableResponse['status']);
        $unavailableHtml = $unavailableResponse['body'];

        self::assertStringContainsString('Ainda sem comparação nesta sessão.', $unavailableHtml);
        self::assertStringContainsString('Produto: 303', $unavailableHtml);
        self::assertStringNotContainsString('A recomendação mudou nesta sessão.', $unavailableHtml);
        self::assertStringNotContainsString('A recomendação não mudou nesta sessão.', $unavailableHtml);
    }

    /** @param array{status: int, body: string} $response */
    private function assertSuccessfulRecommendationResponse(array $response): void
    {
        self::assertSame(200, $response['status']);
        $decoded = json_decode($response['body'], true);
        self::assertSame(JSON_ERROR_NONE, json_last_error());
        self::assertIsArray($decoded);
        self::assertArrayHasKey('data', $decoded);
        self::assertArrayHasKey('meta', $decoded);
    }

    /**
     * @param array<string, string> $query
     * @return array{status: int, body: string}
     */
    private function dispatch(
        string $method,
        string $uri,
        array $query,
        string $sessionId,
        Environment $twig,
        RecommendationController $recommendationController,
        MetricsController $metricsController,
    ): array {
        $hadContainer = array_key_exists('EC_HUB_TEST_CONTAINER', $GLOBALS);
        $previousContainer = $GLOBALS['EC_HUB_TEST_CONTAINER'] ?? null;
        $hadMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
        $previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $hadUri = array_key_exists('REQUEST_URI', $_SERVER);
        $previousUri = $_SERVER['REQUEST_URI'] ?? null;
        $previousQuery = $_GET;
        $hadSessionCookie = array_key_exists(SessionContext::COOKIE_NAME, $_COOKIE);
        $previousSessionCookie = $_COOKIE[SessionContext::COOKIE_NAME] ?? null;
        $hadSignatureCookie = array_key_exists(SessionContext::SIGNATURE_COOKIE_NAME, $_COOKIE);
        $previousSignatureCookie = $_COOKIE[SessionContext::SIGNATURE_COOKIE_NAME] ?? null;
        $previousStatus = http_response_code();
        $bufferLevel = ob_get_level();

        $GLOBALS['EC_HUB_TEST_CONTAINER'] = new Container([
            Environment::class => fn () => $twig,
            SessionContext::class => fn () => new SessionContext('phpunit-only-session-cookie-secret-32'),
            RecommendationController::class => fn () => $recommendationController,
            MetricsController::class => fn () => $metricsController,
        ]);
        $_COOKIE[SessionContext::COOKIE_NAME] = $sessionId;
        $_COOKIE[SessionContext::SIGNATURE_COOKIE_NAME] = hash_hmac('sha256', $sessionId, 'phpunit-only-session-cookie-secret-32');
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        $_GET = $query;
        header_remove();
        http_response_code(200);

        try {
            ob_start();
            require dirname(__DIR__, 3) . '/public/index.php';

            return [
                'status' => http_response_code(),
                'body' => (string) ob_get_contents(),
            ];
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            if ($hadContainer) {
                $GLOBALS['EC_HUB_TEST_CONTAINER'] = $previousContainer;
            } else {
                unset($GLOBALS['EC_HUB_TEST_CONTAINER']);
            }
            if ($hadMethod) {
                $_SERVER['REQUEST_METHOD'] = $previousMethod;
            } else {
                unset($_SERVER['REQUEST_METHOD']);
            }
            if ($hadUri) {
                $_SERVER['REQUEST_URI'] = $previousUri;
            } else {
                unset($_SERVER['REQUEST_URI']);
            }
            $_GET = $previousQuery;
            if ($hadSessionCookie) {
                $_COOKIE[SessionContext::COOKIE_NAME] = $previousSessionCookie;
            } else {
                unset($_COOKIE[SessionContext::COOKIE_NAME]);
            }
            if ($hadSignatureCookie) {
                $_COOKIE[SessionContext::SIGNATURE_COOKIE_NAME] = $previousSignatureCookie;
            } else {
                unset($_COOKIE[SessionContext::SIGNATURE_COOKIE_NAME]);
            }
            header_remove();
            if ($previousStatus !== false) {
                http_response_code($previousStatus);
            }
        }
    }

    private function installContainer(
        EventHistoryRepositoryInterface $history,
        ?SessionRepositoryInterface $sessions = null,
        ?EventBusStatusInterface $eventBusStatus = null,
    ): void {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/views'), [
            'strict_variables' => true,
        ]);
        $GLOBALS['EC_HUB_TEST_CONTAINER'] = new Container([
            Environment::class => fn () => $twig,
            SessionContext::class => fn () => new SessionContext('phpunit-only-session-cookie-secret-32'),
            MetricsController::class => fn () => new MetricsController($history, $twig, $sessions, $eventBusStatus),
        ]);
    }

    public function testHttpMetricsSessionRepositoryCompareAndSwapsNonNullValue(): void
    {
        $sessionId = 'session-a';
        $expected = ['current' => ['product_ids' => [1]]];
        $replacement = ['current' => ['product_ids' => [2]], 'previous' => ['product_ids' => [1]]];
        $sessions = new HttpMetricsSessionRepository([$sessionId => ['recommendation.snapshot' => $expected]]);

        self::assertTrue($sessions->compareAndSwap($sessionId, 'recommendation.snapshot', $expected, $replacement));
        self::assertSame($replacement, $sessions->get($sessionId, 'recommendation.snapshot'));
    }
}

final class HttpMetricsEventBusStatus implements EventBusStatusInterface
{
    public function __construct(private readonly EventBusStatus $status)
    {
    }

    public function status(): EventBusStatus
    {
        return $this->status;
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

    public function compareAndSwap(string $sessionId, string $field, mixed $expected, mixed $value): bool
    {
        if (($this->sessions[$sessionId][$field] ?? null) !== $expected) {
            return false;
        }
        $this->sessions[$sessionId][$field] = $value;

        return true;
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
