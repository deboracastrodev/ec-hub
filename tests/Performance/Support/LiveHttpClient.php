<?php

declare(strict_types=1);

namespace Tests\Performance\Support;

use App\Shared\Http\SessionContext;
use CurlHandle;
use CurlMultiHandle;
use PHPUnit\Framework\Assert;

/**
 * Cliente HTTP da suíte de performance: uma instância = uma sessão (cookie
 * jar próprio, em memória, no handle curl persistente). Os tempos vêm de
 * CURLINFO_TOTAL_TIME, medidos no cliente, nunca do meta.response_time_ms
 * auto-reportado pelo servidor.
 */
final class LiveHttpClient
{
    private const TIMEOUT_SECONDS = 10;

    private readonly string $baseUrl;

    private CurlHandle $handle;

    public function __construct(?string $baseUrl = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? self::baseUrl(), '/');
        $this->handle = curl_init();
        curl_setopt_array($this->handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            // String vazia liga o motor de cookies em memória deste handle:
            // cada instância guarda só os cookies da própria sessão.
            CURLOPT_COOKIEFILE => '',
        ]);
    }

    public static function baseUrl(): string
    {
        return getenv('PERF_BASE_URL') ?: 'http://127.0.0.1:9501';
    }

    /** @return array{status: int, body: string, seconds: float} */
    public function get(string $path): array
    {
        $this->prepare($path);
        $body = curl_exec($this->handle);

        return $this->result($path, is_string($body) ? $body : null);
    }

    /**
     * Dispara as requisições juntas via curl_multi (uma por sessão).
     *
     * @param list<array{0: self, 1: string}> $requests pares [sessão, caminho]
     * @return list<array{status: int, body: string, seconds: float}>
     */
    public static function concurrent(array $requests): array
    {
        $multi = curl_multi_init();
        $attached = [];

        try {
            foreach ($requests as [$client, $path]) {
                $client->prepare($path);
                curl_multi_add_handle($multi, $client->handle);
                $attached[] = $client->handle;
            }

            $transferErrors = self::runMulti($multi);

            $results = [];
            foreach ($requests as [$client, $path]) {
                $error = $transferErrors[spl_object_id($client->handle)] ?? CURLE_OK;
                $body = $error === CURLE_OK ? curl_multi_getcontent($client->handle) : null;
                $results[] = $client->result($path, $body, $error === CURLE_OK ? null : curl_strerror($error));
            }

            return $results;
        } finally {
            foreach ($attached as $handle) {
                curl_multi_remove_handle($multi, $handle);
            }
            curl_multi_close($multi);
        }
    }

    /** ID da sessão emitido pelo servidor via Set-Cookie, se já houver. */
    public function sessionId(): ?string
    {
        foreach ((array) curl_getinfo($this->handle, CURLINFO_COOKIELIST) as $line) {
            $fields = explode("\t", (string) $line);
            if (count($fields) >= 7 && $fields[5] === SessionContext::COOKIE_NAME) {
                return $fields[6];
            }
        }

        return null;
    }

    /**
     * Descobre produtos pela superfície: links a.product-card__link de
     * GET /products e o data-add-cart da página de detalhe (o id numérico).
     *
     * @return list<array{identifier: string, id: int}>
     */
    public function discoverProducts(int $count): array
    {
        $listing = $this->get('/products');
        Assert::assertSame(200, $listing['status'], 'GET /products deveria responder 200');

        preg_match_all('~href="/products/([^"]+)"\s+class="product-card__link"~', $listing['body'], $matches);
        $identifiers = array_values(array_unique($matches[1]));
        Assert::assertGreaterThanOrEqual(
            $count,
            count($identifiers),
            sprintf('GET /products deveria listar ao menos %d produtos (banco semeado?)', $count)
        );

        $products = [];
        foreach (array_slice($identifiers, 0, $count) as $identifier) {
            $detail = $this->get('/products/' . $identifier);
            Assert::assertSame(200, $detail['status'], "GET /products/{$identifier} deveria responder 200");
            Assert::assertSame(
                1,
                preg_match('~data-add-cart="(\d+)"~', $detail['body'], $id),
                "Página /products/{$identifier} sem data-add-cart"
            );
            $products[] = ['identifier' => rawurldecode($identifier), 'id' => (int) $id[1]];
        }

        return $products;
    }

    /** @param list<float> $samples */
    public static function percentile(array $samples, float $percentile): float
    {
        Assert::assertNotEmpty($samples, 'Nenhuma amostra para calcular percentil');
        sort($samples);
        $rank = (int) ceil($percentile / 100 * count($samples));

        return $samples[max(0, $rank - 1)];
    }

    /** @param list<float> $samplesMs */
    public static function summary(array $samplesMs): string
    {
        return sprintf(
            'n=%d p50=%.2fms p95=%.2fms max=%.2fms',
            count($samplesMs),
            self::percentile($samplesMs, 50),
            self::percentile($samplesMs, 95),
            max($samplesMs)
        );
    }

    private function prepare(string $path): void
    {
        curl_setopt($this->handle, CURLOPT_URL, $this->baseUrl . $path);
        curl_setopt($this->handle, CURLOPT_HTTPGET, true);
    }

    /** @return array{status: int, body: string, seconds: float} */
    private function result(string $path, ?string $body, ?string $transferError = null): array
    {
        $status = (int) curl_getinfo($this->handle, CURLINFO_RESPONSE_CODE);
        if ($body === null || $status === 0) {
            Assert::fail(sprintf(
                'Servidor inacessível em %s%s (%s). Suba o stack (make up && make setup) ou ajuste PERF_BASE_URL.',
                $this->baseUrl,
                $path,
                $transferError ?? (curl_error($this->handle) ?: 'sem resposta')
            ));
        }

        return [
            'status' => $status,
            'body' => $body,
            'seconds' => (float) curl_getinfo($this->handle, CURLINFO_TOTAL_TIME),
        ];
    }

    /**
     * Executa as transferências e devolve o código de erro curl de cada
     * handle (chave spl_object_id), lido via curl_multi_info_read.
     *
     * @return array<int, int>
     */
    private static function runMulti(CurlMultiHandle $multi): array
    {
        $errors = [];
        do {
            $status = curl_multi_exec($multi, $running);
            if ($status !== CURLM_OK) {
                Assert::fail('curl_multi_exec falhou: ' . curl_multi_strerror($status));
            }
            while (($info = curl_multi_info_read($multi)) !== false) {
                $errors[spl_object_id($info['handle'])] = $info['result'];
            }
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0);

        return $errors;
    }
}
