<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Product\Service;

use App\Domain\Product\Model\Product;
use App\Domain\Product\Model\SearchQuery;
use App\Domain\Product\Service\ProductSearchRanker;
use PHPUnit\Framework\TestCase;

/** Story 8.5 (FR109): relevance is a pure Domain rule. */
final class ProductSearchRankerTest extends TestCase
{
    private static function product(int $id, string $name, string $description = ''): Product
    {
        return Product::fromArray(['id' => $id, 'name' => $name, 'description' => $description, 'price' => 10, 'category' => 'X']);
    }

    /** @param list<Product> $products @return list<string> */
    private static function names(array $products): array
    {
        return array_map(static fn (Product $p): string => $p->getName(), $products);
    }

    public function testNameWeighsThreeAndDescriptionOne(): void
    {
        $ranked = (new ProductSearchRanker())->rank([
            self::product(1, 'Mouse', 'Sem fio.'),
            self::product(2, 'Caixa Bluetooth', 'Som.'),                 // 3
            self::product(3, 'Fone de Ouvido', 'Com bluetooth.'),        // 3 + 1 = 4
            self::product(4, 'Fone Bluetooth X', 'Fone bluetooth leve.'), // 3+1+3+1 = 8
            self::product(5, 'Cabo', 'Para fone.'),                       // 1
        ], SearchQuery::fromRaw('fone bluetooth'));

        self::assertSame(['Fone Bluetooth X', 'Fone de Ouvido', 'Caixa Bluetooth', 'Cabo'], self::names($ranked));
    }

    public function testScoreZeroIsDropped(): void
    {
        $ranked = (new ProductSearchRanker())->rank([self::product(1, 'Mouse', 'Gamer')], SearchQuery::fromRaw('fone'));

        self::assertSame([], $ranked);
    }

    public function testCaseAndAccentInsensitive(): void
    {
        $ranked = (new ProductSearchRanker())->rank([self::product(1, 'Teclado MECÂNICO')], SearchQuery::fromRaw('mecanico'));

        self::assertSame(['Teclado MECÂNICO'], self::names($ranked));
    }

    public function testTiesBreakByNormalizedNameThenId(): void
    {
        $ranked = (new ProductSearchRanker())->rank([
            self::product(9, 'Fone B'),
            self::product(7, 'fone a'),
            self::product(3, 'Fone B'),
            self::product(5, 'Fône A'),
        ], SearchQuery::fromRaw('fone'));

        self::assertSame([5, 7, 3, 9], array_map(static fn (Product $p): ?int => $p->getId(), $ranked));
    }

    public function testNoTermsRanksNothing(): void
    {
        self::assertSame([], (new ProductSearchRanker())->rank([self::product(1, 'Fone')], SearchQuery::fromRaw('a b')));
    }
}
