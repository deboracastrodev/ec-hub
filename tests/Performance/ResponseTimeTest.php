<?php

declare(strict_types=1);

namespace Tests\Performance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Performance\Support\LiveHttpClient;

/**
 * FR57 / NFR de latência: tempo de resposta medido no cliente (curl), p95
 * de 50 amostras após 5 aquecimentos descartados, contra o app no ar.
 */
#[Group('performance')]
final class ResponseTimeTest extends TestCase
{
    private const WARMUP = 5;
    private const SAMPLES = 50;

    public function test_recommendations_p95_is_below_200ms(): void
    {
        $session = new LiveHttpClient();
        $product = $session->discoverProducts(1)[0];
        $path = '/api/recommendations?product_id=' . $product['id'] . '&limit=5';

        $samples = $this->measure($session, $path, function (array $response) use ($path): void {
            $payload = json_decode($response['body'], true);
            self::assertIsArray($payload, "{$path} deveria devolver JSON");
            self::assertIsArray($payload['data'] ?? null, "{$path} sem chave data");
            self::assertNotEmpty($payload['data'], "{$path} devolveu data vazio");
        });

        self::assertLessThan(
            200.0,
            LiveHttpClient::percentile($samples, 95),
            "p95 de GET {$path} acima de 200 ms: " . LiveHttpClient::summary($samples)
        );
    }

    public function test_metrics_p95_is_below_500ms(): void
    {
        $session = new LiveHttpClient();
        // discoverProducts visita a página de detalhe de cada produto nesta
        // sessão, então o histórico medido tem 3 visualizações.
        $viewedIds = array_column($session->discoverProducts(3), 'id');

        $samples = $this->measure($session, '/metrics', function (array $response) use ($viewedIds): void {
            self::assertStringContainsString('Histórico de eventos', $response['body']);
            foreach ($viewedIds as $id) {
                self::assertMatchesRegularExpression("/\\bProduto: {$id}\\b/", $response['body'], "/metrics sem o produto visto {$id}");
            }
        });

        self::assertLessThan(
            500.0,
            LiveHttpClient::percentile($samples, 95),
            'p95 de GET /metrics acima de 500 ms: ' . LiveHttpClient::summary($samples)
        );
    }

    /**
     * @param callable(array{status: int, body: string, seconds: float}): void $check
     * @return list<float> amostras em ms
     */
    private function measure(LiveHttpClient $session, string $path, callable $check): array
    {
        for ($i = 0; $i < self::WARMUP; $i++) {
            self::assertSame(200, $session->get($path)['status'], "Aquecimento de GET {$path} falhou");
        }

        $samples = [];
        for ($i = 0; $i < self::SAMPLES; $i++) {
            $response = $session->get($path);
            self::assertSame(200, $response['status'], "GET {$path} (amostra {$i}) não respondeu 200");
            $check($response);
            $samples[] = $response['seconds'] * 1000;
        }

        return $samples;
    }
}
