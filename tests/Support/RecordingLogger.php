<?php

declare(strict_types=1);

namespace Tests\Support;

use Psr\Log\AbstractLogger;

/** Keeps every log record as [level, message, context] for assertions. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{0: mixed, 1: string, 2: array<mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [$level, (string) $message, $context];
    }

    /** @return list<string> messages logged at $level, in order */
    public function messages(string $level): array
    {
        return array_values(array_map(
            static fn (array $record): string => $record[1],
            array_filter($this->records, static fn (array $record): bool => $record[0] === $level)
        ));
    }
}
