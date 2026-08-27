<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use Closure;
use PDO;
use Predis\Client;
use Throwable;

final readonly class HealthCheck
{
    /**
     * @param Closure(): PDO $pdo
     * @param Closure(): Client $redis
     */
    public function __construct(
        private Closure $pdo,
        private Closure $redis,
    ) {
    }

    public function check(): HealthStatus
    {
        return new HealthStatus(
            mysql: $this->mysqlStatus(),
            redis: $this->redisStatus(),
        );
    }

    private function mysqlStatus(): string
    {
        try {
            return ($this->pdo)()->query('SELECT 1') === false ? 'down' : 'up';
        } catch (Throwable) {
            return 'down';
        }
    }

    private function redisStatus(): string
    {
        try {
            return ($this->redis)()->ping() === 'PONG' ? 'up' : 'down';
        } catch (Throwable) {
            return 'down';
        }
    }
}
