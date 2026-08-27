<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Application\Monitoring\MemoryMonitor;
use App\Controller\MemoryMonitoringController;
use App\Shared\Container\Container;
use App\Shared\Http\SessionContext;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class MemoryMonitoringHttpEndpointTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testMemoryEndpointReturnsTheCurrentRequestSampleWithoutSessionData(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/debug/memory';
        $_GET = [];
        $_COOKIE = ['unrelated_session_value' => 'must-not-leak'];
        header_remove();
        http_response_code(200);

        ob_start();
        require dirname(__DIR__, 3) . '/public/index.php';
        $body = (string) ob_get_clean();

        self::assertSame(200, http_response_code());
        $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertSame([
            'current_usage_bytes',
            'peak_usage_bytes',
            'growth_percent',
            'alert',
        ], array_keys($payload));
        self::assertIsInt($payload['current_usage_bytes']);
        self::assertIsInt($payload['peak_usage_bytes']);
        self::assertIsNumeric($payload['growth_percent']);
        self::assertIsBool($payload['alert']);
        self::assertArrayHasKey('EC_HUB_MEMORY_BASELINE', $GLOBALS);
        $baseline = $GLOBALS['EC_HUB_MEMORY_BASELINE'];
        self::assertIsInt($baseline);
        self::assertGreaterThan(0, $baseline);
        self::assertEqualsWithDelta(
            (($payload['current_usage_bytes'] - $baseline) / $baseline) * 100,
            $payload['growth_percent'],
            0.000_001,
        );
        self::assertSame($payload['growth_percent'] > 10.0, $payload['alert']);
        self::assertStringNotContainsString('must-not-leak', $body);
        self::assertStringNotContainsString('session', strtolower($body));
    }

    #[RunInSeparateProcess]
    public function testMemoryEndpointDoesNotResolveSessionContextOrEmitSessionCookies(): void
    {
        $sessionResolutions = 0;
        $emittedCookies = [];
        $sessionContext = new SessionContext(
            'phpunit-only-session-cookie-secret-32',
            static function (string $name, string $value, array $options) use (&$emittedCookies): bool {
                $emittedCookies[] = compact('name', 'value', 'options');

                return true;
            },
        );
        $GLOBALS['EC_HUB_TEST_CONTAINER'] = new Container([
            Environment::class => fn () => new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/views')),
            MemoryMonitoringController::class => fn () => new MemoryMonitoringController(new MemoryMonitor(1)),
            SessionContext::class => function () use (&$sessionResolutions, $sessionContext): SessionContext {
                ++$sessionResolutions;

                return $sessionContext;
            },
        ]);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/debug/memory';
        $_GET = [];
        $_COOKIE = [];
        header_remove();
        http_response_code(200);

        ob_start();
        require dirname(__DIR__, 3) . '/public/index.php';
        $body = (string) ob_get_clean();

        self::assertSame(200, http_response_code());
        self::assertSame(0, $sessionResolutions);
        self::assertSame([], $emittedCookies);
        self::assertStringNotContainsString(SessionContext::COOKIE_NAME, $body);
        self::assertStringNotContainsString(SessionContext::SIGNATURE_COOKIE_NAME, $body);
    }
}
