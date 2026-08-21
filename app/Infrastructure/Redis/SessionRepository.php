<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use App\Domain\Session\Repository\SessionRepositoryInterface;
use InvalidArgumentException;
use JsonException;
use Predis\Client;
use Predis\Transaction\MultiExec;

/**
 * Armazena cada sessão em um hash Redis com retenção deslizante.
 */
final class SessionRepository implements SessionRepositoryInterface
{
    private const KEY_PREFIX = 'ec-hub:session:';

    public function __construct(
        private readonly Client $client,
        private readonly int $ttl,
    ) {
        if ($ttl < 1) {
            throw new InvalidArgumentException('SESSION_TTL must be a positive integer.');
        }
    }

    public function put(string $sessionId, string $field, mixed $value): void
    {
        $key = $this->keyFor($sessionId);
        $this->assertField($field);

        try {
            $serializedValue = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Session value must be JSON serializable.', 0, $exception);
        }

        $this->client->transaction(function (MultiExec $transaction) use ($key, $field, $serializedValue): void {
            $transaction->hset($key, $field, $serializedValue);
            $transaction->expire($key, $this->ttl);
        });
    }

    public function get(string $sessionId, string $field): mixed
    {
        $key = $this->keyFor($sessionId);
        $this->assertField($field);
        $serializedValue = $this->client->hget($key, $field);

        if ($serializedValue === null) {
            return null;
        }

        try {
            return json_decode($serializedValue, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Stored session value is not valid JSON.', 0, $exception);
        }
    }

    private function keyFor(string $sessionId): string
    {
        if (trim($sessionId) === '' || strlen($sessionId) > 255 || preg_match('/[\x00-\x1F\x7F]/', $sessionId) === 1) {
            throw new InvalidArgumentException('Session identifier must be a non-empty printable string up to 255 bytes.');
        }

        return self::KEY_PREFIX . $sessionId;
    }

    private function assertField(string $field): void
    {
        if (trim($field) === '' || strlen($field) > 255 || preg_match('/[\x00-\x1F\x7F]/', $field) === 1) {
            throw new InvalidArgumentException('Session field must be a non-empty printable string up to 255 bytes.');
        }
    }
}
