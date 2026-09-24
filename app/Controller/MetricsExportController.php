<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Monitoring\ExportMetrics;
use App\Application\Monitoring\PrometheusFormatter;
use App\Controller\Exceptions\InvalidRequestException;
use App\Shared\Http\Response;

/**
 * Story 8.4: GET /api/metrics?format=json|prometheus (FR107/FR108).
 *
 * Public, like the other diagnostic endpoints (/metrics, /debug/memory,
 * /api/ab-tests/results): restrict it at the reverse proxy if needed.
 */
final class MetricsExportController
{
    public const JSON_CONTENT_TYPE = 'application/json';
    public const PROMETHEUS_CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    public function __construct(
        private readonly ExportMetrics $metrics,
        private readonly PrometheusFormatter $formatter,
    ) {
    }

    /**
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed> $headers
     */
    public function export(array $queryParams, array $headers = [], ?string $sessionId = null): Response
    {
        $format = $this->format($queryParams['format'] ?? null);
        $collected = $this->metrics->collect();

        if ($format === 'prometheus') {
            return new Response(200, $this->formatter->format($collected), [
                'Content-Type' => self::PROMETHEUS_CONTENT_TYPE,
                'Cache-Control' => 'no-store',
            ]);
        }

        $body = json_encode(
            ['data' => $collected['data'], 'meta' => $collected['meta']],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        return new Response(200, $body, [
            'Content-Type' => self::JSON_CONTENT_TYPE,
            'Cache-Control' => 'no-store',
        ]);
    }

    private function format(mixed $format): string
    {
        if ($format === null) {
            return 'json';
        }
        if (! is_string($format)) {
            throw new InvalidRequestException('format must be "json" or "prometheus"');
        }

        $format = strtolower(trim($format));
        if ($format === '') {
            return 'json';
        }
        if (! in_array($format, ['json', 'prometheus'], true)) {
            throw new InvalidRequestException('format must be "json" or "prometheus"');
        }

        return $format;
    }
}
