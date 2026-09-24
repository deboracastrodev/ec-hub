<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Product\Model;

use App\Domain\Product\Model\SearchQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Story 8.5 (FR109): the single text rule of the product search. */
final class SearchQueryTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function termCases(): iterable
    {
        yield 'two terms' => ['fone bluetooth', ['fone', 'bluetooth']];
        yield 'uppercase' => ['FONE', ['fone']];
        yield 'accents removed' => ['Mecânico ÁUDIO ação', ['mecanico', 'audio', 'acao']];
        yield 'punctuation is a separator' => ['fone,bluetooth;usb-c', ['fone', 'bluetooth', 'usb']];
        yield 'short terms dropped' => ['a b fone c', ['fone']];
        yield 'only short terms' => ['a b', []];
        yield 'duplicates keep first order' => ['fone Fone FÔNE caixa fone', ['fone', 'caixa']];
        yield 'wildcard is a separator' => ['100%', ['100']];
        yield 'underscore is a separator' => ['fone_bt', ['fone', 'bt']];
        yield 'markup is split' => ['<script>alert(1)</script>', ['script', 'alert']];
        yield 'surrounding spaces' => ['   fone   ', ['fone']];
        yield 'digits kept' => ['4k 55', ['4k', '55']];
    }

    /** @param list<string> $expected */
    #[DataProvider('termCases')]
    public function testTerms(string $raw, array $expected): void
    {
        $query = SearchQuery::fromRaw($raw);

        self::assertSame($expected, $query->terms());
        self::assertSame($expected !== [], $query->hasTerms());
    }

    public function testRawIsTrimmedAndKeepsTheUserText(): void
    {
        self::assertSame('Fone <b>Bluetooth</b>', SearchQuery::fromRaw("  Fone <b>Bluetooth</b> \n")->raw());
    }

    public function testRawIsCutAt100Characters(): void
    {
        $raw = str_repeat('ã', 150);
        $query = SearchQuery::fromRaw($raw);

        self::assertSame(100, mb_strlen($query->raw(), 'UTF-8'));
        self::assertSame([str_repeat('a', 100)], $query->terms());
    }

    public function testAtMostEightTerms(): void
    {
        $query = SearchQuery::fromRaw('t1 t2 t3 t4 t5 t6 t7 t8 t9 t10');

        self::assertSame(['t1', 't2', 't3', 't4', 't5', 't6', 't7', 't8'], $query->terms());
    }

    public function testInvalidUtf8YieldsZeroTermsWithoutThrowing(): void
    {
        $query = SearchQuery::fromRaw("fone \xC3\x28 bluetooth");

        self::assertSame([], $query->terms());
        self::assertFalse($query->hasTerms());
        self::assertTrue(mb_check_encoding($query->raw(), 'UTF-8'));
    }

    public function testNormalize(): void
    {
        self::assertSame('teclado mecanico - acai', SearchQuery::normalize('Teclado MECÂNICO - Açaí'));
        self::assertSame('', SearchQuery::normalize("\xFF"));
    }

    public function testNormalizeStripsAccentsBeyondThePtBrMapWithIntl(): void
    {
        if (! class_exists(\Normalizer::class)) {
            self::markTestSkipped('ext-intl ausente: só o mapa pt-BR se aplica.');
        }

        self::assertSame('skoda y', SearchQuery::normalize('Škoda Ý'));
    }
}
