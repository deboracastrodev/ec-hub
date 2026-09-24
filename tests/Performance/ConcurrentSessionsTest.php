<?php

declare(strict_types=1);

namespace Tests\Performance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Performance\Support\LiveHttpClient;

/**
 * FR35: 10 sessões simultâneas sem degradação nem vazamento de estado entre
 * sessões. O php -S atende em série, então a latência medida no cliente
 * inclui a fila das requisições concorrentes.
 */
#[Group('performance')]
final class ConcurrentSessionsTest extends TestCase
{
    private const SESSIONS = 10;
    private const ROUNDS = 3;

    public function test_ten_simultaneous_sessions_stay_isolated_and_fast(): void
    {
        $products = (new LiveHttpClient())->discoverProducts(self::SESSIONS);
        self::assertCount(self::SESSIONS, array_unique(array_column($products, 'id')), 'Produtos descobertos deveriam ser distintos');

        /** @var list<LiveHttpClient> $sessions */
        $sessions = [];
        for ($i = 0; $i < self::SESSIONS; $i++) {
            $sessions[] = new LiveHttpClient();
        }

        $opened = LiveHttpClient::concurrent(array_map(
            static fn (LiveHttpClient $session, array $product): array => [$session, '/products/' . rawurlencode($product['identifier'])],
            $sessions,
            $products
        ));
        foreach ($opened as $i => $response) {
            self::assertSame(200, $response['status'], "Sessão {$i}: GET /products/{$products[$i]['identifier']} não respondeu 200");
        }

        $sessionIds = array_map(static fn (LiveHttpClient $session): ?string => $session->sessionId(), $sessions);
        foreach ($sessionIds as $i => $sessionId) {
            self::assertNotNull($sessionId, "Sessão {$i}: servidor não emitiu cookie de sessão");
        }
        self::assertCount(self::SESSIONS, array_unique($sessionIds), 'As 10 sessões deveriam ter IDs distintos');

        $samples = [];
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $responses = LiveHttpClient::concurrent(array_map(
                static fn (LiveHttpClient $session, array $product): array => [$session, '/api/recommendations?product_id=' . $product['id'] . '&limit=5'],
                $sessions,
                $products
            ));
            foreach ($responses as $i => $response) {
                self::assertSame(200, $response['status'], "Sessão {$i}, rodada {$round}: GET /api/recommendations não respondeu 200");
                $samples[] = $response['seconds'] * 1000;
            }
        }

        self::assertLessThan(
            200.0,
            LiveHttpClient::percentile($samples, 95),
            'p95 das recomendações concorrentes acima de 200 ms: ' . LiveHttpClient::summary($samples)
        );

        $metrics = LiveHttpClient::concurrent(array_map(
            static fn (LiveHttpClient $session): array => [$session, '/metrics'],
            $sessions
        ));
        foreach ($metrics as $i => $response) {
            self::assertSame(200, $response['status'], "Sessão {$i}: GET /metrics não respondeu 200");
            foreach ($products as $j => $product) {
                $pattern = '/\bProduto: ' . $product['id'] . '\b/';
                if ($i === $j) {
                    self::assertMatchesRegularExpression($pattern, $response['body'], "Sessão {$i}: /metrics sem o próprio produto {$product['id']}");
                } else {
                    self::assertDoesNotMatchRegularExpression($pattern, $response['body'], "Sessão {$i}: /metrics vazou o produto {$product['id']} da sessão {$j}");
                }
            }
        }
    }
}
