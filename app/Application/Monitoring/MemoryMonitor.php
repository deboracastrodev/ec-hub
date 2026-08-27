<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use InvalidArgumentException;

final readonly class MemoryMonitor
{
    private const ALERT_THRESHOLD_PERCENT = 10.0;

    public function __construct(private int $baselineUsageBytes)
    {
        if ($baselineUsageBytes <= 0) {
            throw new InvalidArgumentException('A baseline de memória deve ser maior que zero.');
        }
    }

    public function snapshot(): MemorySnapshot
    {
        return $this->sample(memory_get_usage(), memory_get_peak_usage());
    }

    public function sample(int $currentUsageBytes, int $peakUsageBytes): MemorySnapshot
    {
        $growthPercent = (($currentUsageBytes - $this->baselineUsageBytes) / $this->baselineUsageBytes) * 100;

        return new MemorySnapshot(
            $currentUsageBytes,
            $peakUsageBytes,
            $growthPercent,
            $growthPercent > self::ALERT_THRESHOLD_PERCENT,
        );
    }
}
