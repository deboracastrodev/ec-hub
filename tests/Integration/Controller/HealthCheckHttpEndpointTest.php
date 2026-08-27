<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Application\Monitoring\HealthCheck;
use App\Controller\HealthCheckController;
use App\Shared\Container\Container;
use App\Shared\Http\SessionContext;
use PDO;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Predis\Client;

final class HealthCheckHttpEndpointTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testHealthEndpointReturnsTheHealthyContractWithoutSessionOrCookies(): void
    {
        $sessionResolutions = 0;
        $emittedCookies = [];
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn($this->createMock(\PDOStatement::class));
        $redis = new class () extends Client {
            public function __construct()
            {
            }

            public function ping(): string
            {
                return 'PONG';
            }
        };
        $GLOBALS['EC_HUB_TEST_CONTAINER'] = new Container([
            HealthCheckController::class => fn () => new HealthCheckController(new HealthCheck(
                fn (): PDO => $pdo,
                fn (): Client => $redis,
            )),
            SessionContext::class => function () use (&$sessionResolutions, &$emittedCookies): SessionContext {
                ++$sessionResolutions;

                return new SessionContext('phpunit-only-session-cookie-secret-32', static function (string $name, string $value, array $options) use (&$emittedCookies): bool {
                    $emittedCookies[] = compact('name', 'value', 'options');

                    return true;
                });
            },
        ]);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/health';
        $_GET = [];
        $_COOKIE = ['unrelated_session_value' => 'must-not-leak'];
        header_remove();
        http_response_code(200);

        $startedAt = hrtime(true);
        ob_start();
        require dirname(__DIR__, 3) . '/public/index.php';
        $body = (string) ob_get_clean();
        $elapsedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

        self::assertSame(200, http_response_code());
        self::assertLessThan(100, $elapsedMilliseconds);
        self::assertSame([
            'status' => 'healthy',
            'services' => [
                'mysql' => ['status' => 'up'],
                'redis' => ['status' => 'up'],
            ],
        ], json_decode($body, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(0, $sessionResolutions);
        self::assertSame([], $emittedCookies);
        self::assertStringNotContainsString('must-not-leak', $body);
    }
}
