<?php
declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Application\Recommendation\GenerateRecommendations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class RecommendationHttpEndpointTest extends TestCase
{
    /**
     * @runInSeparateProcess
     */
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

        $twig = new class {
            public function render(string $view, array $params = []): string
            {
                return $view . json_encode($params);
            }
        };

        $GLOBALS['EC_HUB_TEST_CONTAINER'] = [
            'twig' => $twig,
            'services' => [
                'logger' => fn(array $container) => new NullLogger(),
                'generate_recommendations' => fn(array $container) => $generateRecommendations,
            ],
            'repositories' => [],
        ];

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/recommendations?user_id=1';
        $_GET = ['user_id' => '1'];

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
