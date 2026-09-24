<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Product;

use App\Application\Product\GetProductList;
use App\Domain\Product\Service\CategoryService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryProductRepository;

/** Story 8.5 (FR109): search mode of the listing, against the in-memory fake. */
final class GetProductListSearchTest extends TestCase
{
    private InMemoryProductRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryProductRepository(self::catalog());
    }

    /** @return list<array<string, mixed>> */
    private static function catalog(): array
    {
        $row = static fn (int $id, string $name, string $description, string $category): array => [
            'id' => $id, 'name' => $name, 'slug' => "p-{$id}", 'description' => $description,
            'price' => 10.0 * $id, 'category' => $category, 'image_url' => null,
        ];

        return [
            $row(1, 'Mouse', 'Mouse óptico.', 'Periféricos'),
            $row(2, 'Caixa Bluetooth', 'Caixa de som portátil.', 'Áudio'),
            $row(3, 'Fone de Ouvido', 'Fone com fio.', 'Áudio'),
            $row(4, 'Fone Bluetooth X', 'Sem fio.', 'Eletrônicos'),
            $row(5, 'Teclado Mecânico', 'Switch azul.', 'Periféricos'),
            $row(6, 'Cabo USB', 'Ideal para fone de ouvido.', 'Periféricos'),
            $row(7, 'Produto 100% algodão', 'Camiseta.', 'Moda'),
        ];
    }

    /** @param array<string, mixed> $query @return array<string, mixed> */
    private function list(array $query): array
    {
        return (new GetProductList($this->repository, new CategoryService($this->repository)))->execute($query);
    }

    /** @param array<string, mixed> $result @return list<string> */
    private static function names(array $result): array
    {
        return array_map(static fn (array $p): string => $p['name'], $result['products']);
    }

    public function testTermsAreOredAndRankedByRelevance(): void
    {
        $result = $this->list(['q' => 'fone bluetooth']);

        self::assertTrue($result['isSearch']);
        self::assertSame('fone bluetooth', $result['searchQuery']);
        self::assertSame(['Fone Bluetooth X', 'Fone de Ouvido', 'Caixa Bluetooth', 'Cabo USB'], self::names($result));
        self::assertSame(4, $result['totalProducts']);
        self::assertStringContainsString('q=fone+bluetooth', $result['paginationBaseQuery']);
        self::assertNotContains('Mouse', self::names($result));
    }

    public function testCaseInsensitive(): void
    {
        self::assertSame(self::names($this->list(['q' => 'fone'])), self::names($this->list(['q' => 'FONE'])));
    }

    public function testAccentInsensitive(): void
    {
        self::assertSame(['Teclado Mecânico'], self::names($this->list(['q' => 'mecanico'])));
    }

    public function testDescriptionOnlyMatchComesAfterNameMatches(): void
    {
        $names = self::names($this->list(['q' => 'fone']));

        self::assertSame('Cabo USB', end($names));
    }

    public function testCategoryRestrictsCandidates(): void
    {
        $result = $this->list(['q' => 'fone bluetooth', 'category' => 'áudio']);

        self::assertSame(['Fone de Ouvido', 'Caixa Bluetooth'], self::names($result));
        self::assertSame('Áudio', $result['currentCategory']);
        self::assertStringContainsString('q=fone+bluetooth', $result['paginationBaseQuery']);
    }

    public function testPaginationSlicesTheRankedResult(): void
    {
        $rows = [];
        for ($i = 1; $i <= 25; ++$i) {
            $rows[] = ['id' => $i, 'name' => sprintf('Fone %02d', $i), 'slug' => "fone-{$i}", 'description' => '', 'price' => 1.0, 'category' => 'Áudio', 'image_url' => null];
        }
        $rows[] = ['id' => 26, 'name' => 'Mouse', 'slug' => 'mouse', 'description' => '', 'price' => 1.0, 'category' => 'Áudio', 'image_url' => null];
        $this->repository = new InMemoryProductRepository($rows);

        $result = $this->list(['q' => 'fone', 'limit' => 20, 'page' => 2]);

        self::assertSame(['Fone 21', 'Fone 22', 'Fone 23', 'Fone 24', 'Fone 25'], self::names($result));
        self::assertSame(25, $result['totalProducts']);
        self::assertSame(2, $result['totalPages']);
        self::assertSame(2, $result['currentPage']);
        parse_str($result['paginationBaseQuery'], $base);
        self::assertSame(['q' => 'fone', 'limit' => '20'], $base);
    }

    public function testSingleResult(): void
    {
        $result = $this->list(['q' => 'teclado']);

        self::assertSame(1, $result['totalProducts']);
        self::assertTrue($result['isSearch']);
    }

    public function testNoResult(): void
    {
        $result = $this->list(['q' => 'xyzzy']);

        self::assertTrue($result['isSearch']);
        self::assertSame(0, $result['totalProducts']);
        self::assertSame([], $result['products']);
        self::assertTrue($result['hasNoProducts']);
    }

    public function testOnlyShortTermsIsSearchModeWithoutQueryingTheRepository(): void
    {
        $spy = new class (self::catalog()) extends InMemoryProductRepository {
            public int $searchCalls = 0;

            public function searchCandidates(array $terms, ?string $category = null, int $limit = 500): array
            {
                ++$this->searchCalls;

                return parent::searchCandidates($terms, $category, $limit);
            }
        };
        $this->repository = $spy;

        $result = $this->list(['q' => 'a b']);

        self::assertTrue($result['isSearch']);
        self::assertSame('a b', $result['searchQuery']);
        self::assertSame(0, $result['totalProducts']);
        self::assertSame(0, $spy->searchCalls);
    }

    /** '%' never reaches the LIKE: SearchQuery treats it as a separator, so '100%' is the term '100'. */
    public function testPercentSignIsASeparatorNotAWildcard(): void
    {
        self::assertSame(['Produto 100% algodão'], self::names($this->list(['q' => '100%'])));
    }

    public function testSoftDeletedProductIsNotFound(): void
    {
        $this->repository->delete(4);

        self::assertNotContains('Fone Bluetooth X', self::names($this->list(['q' => 'fone bluetooth'])));
    }

    public function testRawIsKeptForDisplay(): void
    {
        $result = $this->list(['q' => '  <script>alert(1)</script> ']);

        self::assertSame('<script>alert(1)</script>', $result['searchQuery']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function nonSearchQueries(): iterable
    {
        yield 'q absent' => [[]];
        yield 'q empty' => [['q' => '']];
        yield 'q only spaces' => [['q' => '   ']];
        yield 'q not a string' => [['q' => ['fone']]];
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('nonSearchQueries')]
    public function testWithoutAUsableQTheListingIsUnchanged(array $query): void
    {
        $baseline = $this->list(['limit' => 5]);
        $result = $this->list($query + ['limit' => 5]);

        self::assertFalse($result['isSearch']);
        self::assertNull($result['searchQuery']);
        foreach (['products', 'totalProducts', 'totalPages', 'totalAllProducts', 'categories', 'currentCategory', 'hasNoProducts'] as $key) {
            self::assertSame($baseline[$key], $result[$key], $key);
        }
    }

    public function testCategoryFilterWithoutQIsUnchanged(): void
    {
        $result = $this->list(['category' => 'Periféricos']);

        self::assertFalse($result['isSearch']);
        self::assertSame(['Mouse', 'Teclado Mecânico', 'Cabo USB'], self::names($result));
        self::assertSame(3, $result['totalProducts']);
        self::assertSame('category=Perif%C3%A9ricos&limit=20', $result['paginationBaseQuery']);
    }
}
