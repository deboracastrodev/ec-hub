<?php
declare(strict_types=1);

namespace Tests\Integration\Controller;

use PHPUnit\Framework\TestCase;

class RecommendationApiLiveHttpTest extends TestCase
{
    public function test_live_http_endpoint_returns_required_headers(): void
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 3,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents('http://127.0.0.1:9501/api/recommendations?user_id=1', false, $context);
        if ($body === false) {
            $this->markTestSkipped('Servidor HTTP local indisponível em 127.0.0.1:9501.');
        }

        /** @var array<int, string> $responseHeaders */
        $responseHeaders = $http_response_header ?? [];
        $this->assertNotEmpty($responseHeaders);
        $this->assertStringContainsString('200', $responseHeaders[0]);

        $headers = $this->headersToMap($responseHeaders);

        $this->assertArrayHasKey('content-type', $headers);
        $this->assertStringContainsString('application/json', $headers['content-type']);
        $this->assertArrayHasKey('x-recommendation-source', $headers);
        $this->assertContains($headers['x-recommendation-source'], ['ml', 'rules', 'popular']);
        $this->assertArrayHasKey('x-response-time', $headers);
        $this->assertRegExp('/^\d+(\.\d+)?ms$/', $headers['x-response-time']);

        $decoded = json_decode($body, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('meta', $decoded);
        $this->assertArrayHasKey('response_time_ms', $decoded['meta']);
        $this->assertLessThan(200.0, (float) $decoded['meta']['response_time_ms']);
    }

    /**
     * @param array<int, string> $headers
     * @return array<string, string>
     */
    private function headersToMap(array $headers): array
    {
        $result = [];
        foreach ($headers as $index => $line) {
            if ($index === 0 || strpos($line, ':') === false) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $result[strtolower(trim($name))] = trim($value);
        }

        return $result;
    }
}
