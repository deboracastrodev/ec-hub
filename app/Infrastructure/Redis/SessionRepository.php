<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use App\Domain\Session\Repository\SessionRepositoryInterface;
use InvalidArgumentException;
use JsonException;
use Predis\Client;
use Predis\Transaction\MultiExec;
use UnexpectedValueException;

/**
 * Repositório Redis de estado efêmero de sessão.
 */
final class SessionRepository implements SessionRepositoryInterface
{
    private const KEY_PREFIX = 'ec-hub:session:';
    private const JSON_MAX_DEPTH = 100;
    private const MAX_TTL = 2147483647;

    public function __construct(
        private readonly Client $client,
        private readonly int $ttl,
    ) {
        if ($ttl < 1 || $ttl > self::MAX_TTL) {
            throw new InvalidArgumentException('SESSION_TTL must be an integer between 1 and 2147483647.');
        }
    }

    public function save(string $sessionId, string $field, mixed $value): void
    {
        $key = $this->key($sessionId);
        $this->assertField($field);
        $encodedValue = $this->encode($value);

        // MULTI/EXEC garante que HSET e EXPIRE sejam aplicados juntos: a chave
        // jamais é deixada persistente se a operação for interrompida.
        $this->client->transaction(function (MultiExec $transaction) use ($key, $field, $encodedValue): void {
            $transaction->hset($key, $field, $encodedValue);
            $transaction->expire($key, $this->ttl);
        });
    }

    public function get(string $sessionId, string $field): mixed
    {
        $key = $this->key($sessionId);
        $this->assertField($field);
        $encodedValue = $this->client->hget($key, $field);

        if ($encodedValue === null) {
            return null;
        }

        try {
            $value = json_decode($encodedValue, true, self::JSON_MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('Stored session value is not valid JSON.', 0, $exception);
        }

        if ($value === null) {
            throw new UnexpectedValueException('Stored session value must not be null.');
        }

        try {
            $this->assertJsonValue($value);
        } catch (InvalidArgumentException $exception) {
            throw new UnexpectedValueException('Stored session value violates the repository value contract.', 0, $exception);
        }

        return $value;
    }

    private function key(string $sessionId): string
    {
        if (trim($sessionId) === '') {
            throw new InvalidArgumentException('Session identifier must not be empty.');
        }

        return self::KEY_PREFIX . $sessionId;
    }

    private function assertField(string $field): void
    {
        if (trim($field) === '') {
            throw new InvalidArgumentException('Session field must not be empty.');
        }
    }

    private function encode(mixed $value): string
    {
        $this->assertJsonValue($value);

        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, self::JSON_MAX_DEPTH);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Session value cannot be encoded as JSON.', 0, $exception);
        }
    }

    private function assertJsonValue(mixed $value, int $depth = 0): void
    {
        if ($depth >= self::JSON_MAX_DEPTH) {
            throw new InvalidArgumentException('Session value exceeds the maximum JSON nesting depth.');
        }

        if ($value === null) {
            throw new InvalidArgumentException('Session value must not be null.');
        }

        if (is_string($value) || is_int($value) || is_bool($value)) {
            return;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Session float value must be finite.');
            }

            return;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('Session value must be a native JSON value.');
        }

        foreach ($value as $item) {
            $this->assertJsonValue($item, $depth + 1);
        }
    }
}
