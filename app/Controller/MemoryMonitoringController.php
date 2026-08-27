<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Monitoring\MemoryMonitor;

final readonly class MemoryMonitoringController
{
    public function __construct(private MemoryMonitor $monitor)
    {
    }

    /** @return array{current_usage_bytes: int, peak_usage_bytes: int, growth_percent: float, alert: bool} */
    public function index(): array
    {
        return $this->monitor->snapshot()->toArray();
    }
}
