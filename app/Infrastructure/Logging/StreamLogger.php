<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use InvalidArgumentException;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException as InvalidLogLevel;
use Psr\Log\LogLevel;
use Stringable;

/**
 * Story 10.4: the application's PSR-3 logger. Writes one JSON object per
 * record -- {"ts","level","message","context"} -- to a stream (stderr by
 * default, which is what `php -S` and `docker compose logs` show).
 *
 * Records below the minimum level are dropped; the level 'none' turns the
 * logger off. An unknown record level throws Psr\Log\InvalidArgumentException,
 * as PSR-3 requires. Apart from that, writing never throws: a closed stream
 * or an unserializable context degrades to a partial line (or no line),
 * never to an exception that could change a response. "context" is always
 * a JSON object, even for an empty or list-shaped context.
 */
final class StreamLogger extends AbstractLogger
{
    public const NONE = 'none';

    /** PSR-3 levels by severity (lower = more severe). */
    private const SEVERITY = [
        LogLevel::EMERGENCY => 0,
        LogLevel::ALERT => 1,
        LogLevel::CRITICAL => 2,
        LogLevel::ERROR => 3,
        LogLevel::WARNING => 4,
        LogLevel::NOTICE => 5,
        LogLevel::INFO => 6,
        LogLevel::DEBUG => 7,
    ];

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;

    /** Records with a severity above this are dropped; null = logger off. */
    private readonly ?int $threshold;

    /** @var resource|null */
    private $handle = null;

    /**
     * @param string $minLevel a PSR-3 level (case-insensitive) or 'none'
     * @param resource|string $stream an open stream, or a path/URI opened lazily in append mode
     *
     * @throws InvalidArgumentException on an unknown level or a stream that is neither
     */
    public function __construct(string $minLevel = LogLevel::INFO, private readonly mixed $stream = 'php://stderr')
    {
        $level = strtolower(trim($minLevel));
        if ($level !== self::NONE && ! isset(self::SEVERITY[$level])) {
            throw new InvalidArgumentException(sprintf(
                'Nível de log inválido: "%s". Use um nível PSR-3 (%s) ou "none".',
                $minLevel,
                implode(', ', array_keys(self::SEVERITY))
            ));
        }
        if (! is_resource($stream) && ! is_string($stream)) {
            throw new InvalidArgumentException('O destino do log precisa ser um stream aberto ou um caminho.');
        }

        $this->threshold = $level === self::NONE ? null : self::SEVERITY[$level];
    }

    /**
     * @param mixed $level
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $name = is_string($level) ? strtolower($level) : '';
        if (! isset(self::SEVERITY[$name])) {
            throw new InvalidLogLevel(sprintf('Nível de log desconhecido: %s', get_debug_type($level)));
        }

        if ($this->threshold === null || self::SEVERITY[$name] > $this->threshold) {
            return;
        }

        try {
            $record = [
                'ts' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.uP'),
                'level' => $name,
                'message' => (string) $message,
                'context' => (object) self::normalize($context),
            ];

            $line = json_encode($record, self::JSON_FLAGS);
            if ($line === false) {
                // Ex.: profundidade excedida. Sem o contexto, a linha ainda sai.
                $record['context'] = ['log_error' => 'contexto não serializável'];
                $line = json_encode($record, self::JSON_FLAGS);
            }
            if ($line === false) {
                return;
            }

            $handle = $this->handle();
            if ($handle !== null) {
                @fwrite($handle, $line . "\n");
            }
        } catch (\Throwable) {
            // Gravar log nunca derruba quem logou.
        }
    }

    /** @return resource|null */
    private function handle()
    {
        if (! is_string($this->stream)) {
            // Stream injetado: se foi fechado, a linha se perde (nunca vira caminho de arquivo).
            return is_resource($this->stream) ? $this->stream : null;
        }

        if ($this->handle === null) {
            $handle = @fopen($this->stream, 'ab');
            $this->handle = $handle === false ? null : $handle;
        }

        return $this->handle;
    }

    /** Throwables and objects become JSON-friendly values; the rest is left to json_encode. */
    private static function normalize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 16) {
            return '[profundidade máxima]';
        }
        if ($value instanceof \Throwable) {
            return [
                'class' => $value::class,
                'message' => $value->getMessage(),
                'code' => $value->getCode(),
                'file' => $value->getFile() . ':' . $value->getLine(),
            ];
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if ($value instanceof \JsonSerializable) {
            return $value;
        }
        if ($value instanceof Stringable) {
            return (string) $value;
        }
        if (is_object($value)) {
            return '[' . $value::class . ']';
        }
        if (is_resource($value)) {
            return '[resource ' . get_resource_type($value) . ']';
        }
        if (is_float($value) && ! is_finite($value)) {
            return is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF');
        }
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = self::normalize($item, $depth + 1);
            }

            return $normalized;
        }

        return $value;
    }
}
