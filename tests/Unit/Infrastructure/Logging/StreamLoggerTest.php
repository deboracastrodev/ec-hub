<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Logging;

use App\Infrastructure\Logging\StreamLogger;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Story 10.4: the application's JSON-lines PSR-3 logger. */
final class StreamLoggerTest extends TestCase
{
    /** @var resource */
    private $stream;

    protected function setUp(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $this->stream = $stream;
    }

    protected function tearDown(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function test_writes_one_json_line_per_record(): void
    {
        $logger = new StreamLogger('info', $this->stream);

        $logger->info('Recommendations served', [
            'target_product_id' => 7,
            'recommendations' => [['product_id' => 2, 'source' => 'ml', 'strategy' => 'knn']],
            'path' => 'a/b',
            'nome' => 'Eletrônicos',
        ]);
        $logger->warning('segundo');

        $lines = $this->lines();
        self::assertCount(2, $lines);
        $record = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['ts', 'level', 'message', 'context'], array_keys($record));
        self::assertSame('info', $record['level']);
        self::assertSame('Recommendations served', $record['message']);
        self::assertSame(7, $record['context']['target_product_id']);
        self::assertSame('knn', $record['context']['recommendations'][0]['strategy']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $record['ts']);
        // Sem escapes de unicode nem de barra.
        self::assertStringContainsString('Eletrônicos', $lines[0]);
        self::assertStringContainsString('a/b', $lines[0]);
        self::assertSame('warning', json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR)['level']);
    }

    public function test_context_is_always_a_json_object(): void
    {
        $logger = new StreamLogger('info', $this->stream);

        $logger->info('lista', [1, 2]);
        $logger->info('vazio');

        [$list, $empty] = $this->lines();
        self::assertStringContainsString('"context":{"0":1,"1":2}', $list);
        self::assertStringContainsString('"context":{}', $empty);
    }

    public function test_drops_records_below_the_minimum_level(): void
    {
        $logger = new StreamLogger('WARNING', $this->stream);

        $logger->debug('d');
        $logger->info('i');
        $logger->notice('n');
        $logger->warning('w');
        $logger->error('e');

        self::assertSame(['w', 'e'], array_map(
            static fn (string $line): string => json_decode($line, true, flags: JSON_THROW_ON_ERROR)['message'],
            $this->lines()
        ));
    }

    public function test_none_writes_nothing(): void
    {
        $logger = new StreamLogger('none', $this->stream);

        $logger->emergency('x');
        $logger->info('y');

        self::assertSame([], $this->lines());
    }

    public function test_invalid_minimum_level_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Nível de log inválido');

        new StreamLogger('verbose', $this->stream);
    }

    public function test_unknown_record_level_throws_even_when_logging_is_off(): void
    {
        foreach (['debug', 'none'] as $minimum) {
            $logger = new StreamLogger($minimum, $this->stream);
            foreach (['bogus', 5] as $level) {
                try {
                    $logger->log($level, 'x');
                    self::fail('Nível desconhecido deveria lançar exceção.');
                } catch (\Psr\Log\InvalidArgumentException) {
                    self::assertTrue(true);
                }
            }
        }
        self::assertSame([], $this->lines());
    }

    public function test_unserializable_context_does_not_throw(): void
    {
        $logger = new StreamLogger('debug', $this->stream);
        $recursive = [];
        $recursive['self'] = &$recursive;

        $logger->error('falhou', [
            'exception' => new \RuntimeException('boom'),
            'resource' => $this->stream,
            'nan' => NAN,
            'bytes' => "\xB1\x31",
            'object' => new \stdClass(),
            'recursive' => $recursive,
        ]);

        $lines = $this->lines();
        self::assertCount(1, $lines);
        $record = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('falhou', $record['message']);
        self::assertSame('boom', $record['context']['exception']['message']);
    }

    public function test_a_closed_stream_does_not_throw(): void
    {
        $logger = new StreamLogger('info', $this->stream);
        fclose($this->stream);

        $before = scandir((string) getcwd());
        $logger->info('perdido');

        // O stream fechado não vira caminho de arquivo (ex.: "Resource id #12" no diretório atual).
        $this->assertSame($before, scandir((string) getcwd()));
    }

    /** @return list<string> */
    private function lines(): array
    {
        rewind($this->stream);
        $contents = (string) stream_get_contents($this->stream);

        return $contents === '' ? [] : explode("\n", rtrim($contents, "\n"));
    }
}
