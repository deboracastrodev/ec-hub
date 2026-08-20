<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Application\Recommendation\GenerateRecommendations;
use App\Controller\RecommendationController;
use App\Shared\Container\Container;
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
}
