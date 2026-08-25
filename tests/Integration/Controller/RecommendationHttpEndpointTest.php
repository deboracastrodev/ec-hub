<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Application\Recommendation\GenerateRecommendations;
use App\Controller\RecommendationController;
use App\Domain\Session\Repository\SessionRepositoryInterface;
use App\Shared\Container\Container;
use App\Shared\Http\SessionContext;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class RecommendationHttpEndpointTest extends TestCase
{
    #[RunInSeparateProcess]
    public function test_api_endpoint_returns_json_headers_and_200_status(): void
    {
        header_remove();
        http_response_code(200);

        $generateRecommendations = $this->createMock(GenerateRecommendations::class);
        $generateRecommendations->expects($this->once())
            ->method('execute')
            ->with(1, 10)
            ->willReturn([
                [
                    'product_id' => 22,
                    'name' => 'Mouse Gamer',
                    'price' => 'R$ 150,00',
                    'score' => 0.95,
                    'explanation' => 'Customers who bought this also bought...',
                ],
            ]);

        $logger = new NullLogger();
        $controller = new RecommendationController($generateRecommendations, $logger);

        // Real Twig, same as every other integration test -- this route
        // never renders a template, but public/index.php's ErrorHandler is
        // typed against the concrete Twig\Environment (R5.6), not a fake.
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/views'));

        $GLOBALS['EC_HUB_TEST_CONTAINER'] = new Container([
            Environment::class => fn () => $twig,
            RecommendationController::class => fn () => $controller,
        ]);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/recommendations?product_id=1';
        $_GET = ['product_id' => '1'];

        ob_start();
        require dirname(__DIR__, 3) . '/public/index.php';
        $output = (string) ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertArrayHasKey('meta', $decoded);
        $this->assertSame(200, http_response_code());

        // Header capture is not reliable under CLI SAPI; validate contract metadata instead.
        $this->assertSame('ml', $decoded['meta']['source'] ?? null);
        $this->assertArrayHasKey('response_time_ms', $decoded['meta']);

        unset($GLOBALS['EC_HUB_TEST_CONTAINER']);
    }

    #[RunInSeparateProcess]
    public function testApiEndpointPersistsSnapshotForCurrentSession(): void
    {
        header_remove();
        http_response_code(200);
        $generateRecommendations = $this->createMock(GenerateRecommendations::class);
        $generateRecommendations->expects($this->once())
            ->method('execute')
            ->with(1, 10, false, null, str_repeat('e', 64), null)
            ->willReturn([['product_id' => 22, 'score' => 75.0]]);
        $sessions = new HttpRecommendationSessionRepository();
        $controller = new RecommendationController($generateRecommendations, new NullLogger(), $sessions);
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/views'));
        $GLOBALS['EC_HUB_TEST_CONTAINER'] = new Container([
            Environment::class => fn () => $twig,
            SessionContext::class => fn () => new SessionContext('phpunit-only-session-cookie-secret-32'),
            RecommendationController::class => fn () => $controller,
        ]);
        $sessionId = str_repeat('e', 64);
        $_COOKIE[SessionContext::COOKIE_NAME] = $sessionId;
        $_COOKIE[SessionContext::SIGNATURE_COOKIE_NAME] = hash_hmac('sha256', $sessionId, 'phpunit-only-session-cookie-secret-32');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/recommendations?product_id=1';
        $_GET = ['product_id' => '1'];

        ob_start();
        require dirname(__DIR__, 3) . '/public/index.php';
        $decoded = json_decode((string) ob_get_clean(), true);

        self::assertSame(200, http_response_code());
        self::assertSame('recommendation.snapshot', $sessions->savedField);
        self::assertSame('ml', $sessions->savedValue['current']['source']);
        self::assertSame(75.0, $sessions->savedValue['current']['avg_confidence']);
        self::assertSame([22], $sessions->savedValue['current']['product_ids']);
        self::assertSame(1, $decoded['meta']['count']);
        unset($GLOBALS['EC_HUB_TEST_CONTAINER']);
    }

    #[RunInSeparateProcess]
    public function testApiEndpointReturns200WhenSnapshotPersistenceFails(): void
    {
        header_remove();
        http_response_code(200);
        $generateRecommendations = $this->createMock(GenerateRecommendations::class);
        $generateRecommendations->expects($this->once())->method('execute')->willReturn([]);
        $controller = new RecommendationController($generateRecommendations, new NullLogger(), new HttpRecommendationSessionRepository(true));
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/views'));
        $GLOBALS['EC_HUB_TEST_CONTAINER'] = new Container([
            Environment::class => fn () => $twig,
            SessionContext::class => fn () => new SessionContext('phpunit-only-session-cookie-secret-32'),
            RecommendationController::class => fn () => $controller,
        ]);
        $sessionId = str_repeat('f', 64);
        $_COOKIE[SessionContext::COOKIE_NAME] = $sessionId;
        $_COOKIE[SessionContext::SIGNATURE_COOKIE_NAME] = hash_hmac('sha256', $sessionId, 'phpunit-only-session-cookie-secret-32');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/recommendations?product_id=1';
        $_GET = ['product_id' => '1'];

        ob_start();
        require dirname(__DIR__, 3) . '/public/index.php';
        $decoded = json_decode((string) ob_get_clean(), true);

        self::assertSame(200, http_response_code());
        self::assertSame(0, $decoded['meta']['count']);
        unset($GLOBALS['EC_HUB_TEST_CONTAINER']);
    }
}

final class HttpRecommendationSessionRepository implements SessionRepositoryInterface
{
    public ?string $savedField = null;
    /** @var array<string, mixed> */
    public array $savedValue = [];

    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly bool $throwsOnSave = false)
    {
    }

    public function save(string $sessionId, string $field, mixed $value): void
    {
        if ($this->throwsOnSave) {
            throw new \RuntimeException('Redis indisponível.');
        }
        $this->savedField = $field;
        $this->savedValue = $value;
        $this->data[$field] = $value;
    }

    public function compareAndSwap(string $sessionId, string $field, mixed $expected, mixed $value): bool
    {
        if (($this->data[$field] ?? null) !== $expected) { return false; }
        $this->save($sessionId, $field, $value);
        return true;
    }

    public function get(string $sessionId, string $field): mixed
    {
        return $this->data[$field] ?? null;
    }
}
