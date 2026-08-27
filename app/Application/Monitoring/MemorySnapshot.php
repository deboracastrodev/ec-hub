<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use InvalidArgumentException;

final readonly class MemorySnapshot
{
    public function __construct(
        public int $currentUsageBytes,
        public int $peakUsageBytes,
        public float $growthPercent,
        public bool $alert,
    ) {
        if ($currentUsageBytes < 0 || $peakUsageBytes < $currentUsageBytes) {
            throw new InvalidArgumentException('A amostra de memória é inválida.');
        }
    }

    /** @return array{current_usage_bytes: int, peak_usage_bytes: int, growth_percent: float, alert: bool} */
    public function toArray(): array
    {
        return [
            'current_usage_bytes' => $this->currentUsageBytes,
            'peak_usage_bytes' => $this->peakUsageBytes,
            'growth_percent' => $this->growthPercent,
            'alert' => $this->alert,
        ];
    }
}
