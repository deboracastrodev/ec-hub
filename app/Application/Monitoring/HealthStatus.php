<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use InvalidArgumentException;

final readonly class HealthStatus
{
    private const UP = 'up';
    private const DOWN = 'down';

    public function __construct(
        public string $mysql,
        public string $redis,
    ) {
        foreach ([$mysql, $redis] as $serviceStatus) {
            if (! in_array($serviceStatus, [self::UP, self::DOWN], true)) {
                throw new InvalidArgumentException('O status do serviço deve ser up ou down.');
            }
        }
    }

    /** @return array{status: string, services: array{mysql: array{status: string}, redis: array{status: string}}} */
    public function toArray(): array
    {
        return [
            'status' => $this->mysql === self::UP && $this->redis === self::UP ? 'healthy' : 'unhealthy',
            'services' => [
                'mysql' => ['status' => $this->mysql],
                'redis' => ['status' => $this->redis],
            ],
        ];
    }
}
