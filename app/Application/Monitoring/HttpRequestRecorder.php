<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Story 8.4: records one routed request, called by public/index.php at the
 * end of the request. A metrics failure never breaks the response.
 */
final readonly class HttpRequestRecorder
{
    public function __construct(
        private HttpMetricsRepositoryInterface $repository,
        private LoggerInterface $logger,
    ) {
    }

    public function record(string $method, string $route, int $status, float $durationSeconds): void
    {
        try {
            $this->repository->record(new HttpRequestSample($method, $route, $status, $durationSeconds));
        } catch (Throwable $exception) {
            $this->logger->warning('Não foi possível registrar métricas HTTP.', [
                'method' => $method,
                'route' => $route,
                'status' => $status,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
