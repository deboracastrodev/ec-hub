<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Monitoring\HealthCheck;

final readonly class HealthCheckController
{
    public function __construct(private HealthCheck $healthCheck)
    {
    }

    /** @return array{status: string, services: array{mysql: array{status: string}, redis: array{status: string}}} */
    public function index(): array
    {
        return $this->healthCheck->check()->toArray();
    }
}
