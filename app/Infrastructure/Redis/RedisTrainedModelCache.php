<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use App\Application\Recommendation\TrainedModelCacheInterface;
use Predis\Client;

/**
 * Story 10.1: the trained KNN model in a single Redis string.
 *
 * One key, so no KEYS/SCAN is ever needed. The TTL is hygiene only: the
 * reader validates the payload against the current catalog fingerprint, so
 * an expired or stale key is simply a cache miss.
 */
final class RedisTrainedModelCache implements TrainedModelCacheInterface
{
    public const KEY = 'ec-hub:model:knn';

    public const TTL_SECONDS = 86400;

    public function __construct(
        private readonly Client $client,
        private readonly string $key = self::KEY,
        private readonly int $ttlSeconds = self::TTL_SECONDS,
    ) {
    }

    public function load(): ?string
    {
        $payload = $this->client->get($this->key);

        return is_string($payload) ? $payload : null;
    }

    public function store(string $payload): void
    {
        $this->client->set($this->key, $payload, 'EX', $this->ttlSeconds);
    }

    public function invalidate(): void
    {
        $this->client->del([$this->key]);
    }
}
