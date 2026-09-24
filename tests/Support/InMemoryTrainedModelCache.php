<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Recommendation\TrainedModelCacheInterface;

/**
 * Story 10.1: single-slot fake of the trained-model cache. Keeps the real
 * serialized string, so hydrating goes through the same unserialize() path
 * as with Redis. Each operation can be told to fail like an unreachable Redis.
 */
final class InMemoryTrainedModelCache implements TrainedModelCacheInterface
{
    public ?string $payload = null;

    public int $stores = 0;

    public int $invalidations = 0;

    public bool $failOnLoad = false;

    public bool $failOnStore = false;

    public bool $failOnInvalidate = false;

    public function load(): ?string
    {
        if ($this->failOnLoad) {
            throw new \RuntimeException('Redis indisponível (load).');
        }

        return $this->payload;
    }

    public function store(string $payload): void
    {
        if ($this->failOnStore) {
            throw new \RuntimeException('Redis indisponível (store).');
        }
        ++$this->stores;
        $this->payload = $payload;
    }

    public function invalidate(): void
    {
        if ($this->failOnInvalidate) {
            throw new \RuntimeException('Redis indisponível (invalidate).');
        }
        ++$this->invalidations;
        $this->payload = null;
    }
}
