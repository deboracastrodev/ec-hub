<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

/**
 * Story 8.4: global HTTP request metrics (count per method/route/status and
 * a duration histogram), shared by every PHP process.
 */
interface HttpMetricsRepositoryInterface
{
    public function record(HttpRequestSample $sample): void;

    /** @return list<HttpRouteMetrics> ordered by route, then method */
    public function routes(): array;
}
