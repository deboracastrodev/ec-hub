<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use App\Domain\Product\Model\Product;
use App\Infrastructure\Persistence\MySQL\ProductRepository;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\RequiresTestDatabase;

/** Story 8.5 (FR109): ProductRepository::searchCandidates() against a real MySQL (ec_hub_test). */
#[Group('db')]
final class ProductRepositorySearchTest extends TestCase
{
    use RequiresTestDatabase;

    private PDO $pdo;

    private ProductRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = $this->connectToTestDatabaseOrSkip();
        $insert = $this->pdo->prepare(
            'INSERT INTO products (name, description, price, category, slug, image_url)
             VALUES (:name, :description, 10, :category, :slug, NULL)'
        );
        foreach ([
            ['Fone Bluetooth X', 'Sem fio.', 'Áudio', 'fone-bluetooth-x'],
            ['Caixa Bluetooth', 'Caixa de som.', 'Áudio', 'caixa-bluetooth'],
            ['Cabo USB', 'Ideal para FONE de ouvido.', 'Informatica', 'cabo-usb'],
            ['Teclado Mecânico RGB', 'Switch azul.', 'Informatica', 'teclado-mecanico-rgb'],
            ['Camiseta 100% algodão', 'Leve.', 'Moda', 'camiseta'],
            ['Cabo fone_bt', 'Adaptador.', 'Informatica', 'cabo-fone-bt'],
        ] as [$name, $description, $category, $slug]) {
            $insert->execute(['name' => $name, 'description' => $description, 'category' => $category, 'slug' => $slug]);
        }
        $this->repository = new ProductRepository($this->pdo);
    }

    /** @param list<Product> $products @return list<string> */
    private static function names(array $products): array
    {
        return array_map(static fn (Product $p): string => $p->getName(), $products);
    }

    public function testSearchCandidatesOrsTermsOverNameAndDescription(): void
    {
        $names = self::names($this->repository->searchCandidates(['fone', 'bluetooth']));

        self::assertEqualsCanonicalizing(['Fone Bluetooth X', 'Caixa Bluetooth', 'Cabo USB', 'Cabo fone_bt'], $names);
    }

    public function testSearchCandidatesIsCaseAndAccentInsensitive(): void
    {
        // 'Teclado Mecanico' comes from the RequiresTestDatabase seed.
        $expected = ['Teclado Mecanico', 'Teclado Mecânico RGB'];
        self::assertSame($expected, self::names($this->repository->searchCandidates(['mecanico'])));
        self::assertSame($expected, self::names($this->repository->searchCandidates(['MECÂNICO'])));
        self::assertSame(['Cabo USB'], self::names($this->repository->searchCandidates(['ouvido'])));
    }

    public function testSearchCandidatesEscapesLikeWildcards(): void
    {
        self::assertSame(['Camiseta 100% algodão'], self::names($this->repository->searchCandidates(['100%'])));
        self::assertSame(['Camiseta 100% algodão'], self::names($this->repository->searchCandidates(['%'])));
        // Unescaped, '_' would also match 'Fone Bluetooth' ("e B").
        self::assertSame(['Cabo fone_bt'], self::names($this->repository->searchCandidates(['e_b'])));
        self::assertSame([], $this->repository->searchCandidates(['\\']));
        // The escape char itself is escaped (and ESCAPE '!' works whatever the sql_mode).
        self::assertSame([], $this->repository->searchCandidates(['!']));
        self::assertSame([], $this->repository->searchCandidates(['!%']));
    }

    public function testSearchCandidatesSkipsSoftDeletedProducts(): void
    {
        $id = (int) $this->pdo->query("SELECT id FROM products WHERE slug = 'caixa-bluetooth'")->fetchColumn();
        self::assertTrue($this->repository->delete($id));

        self::assertSame(['Fone Bluetooth X'], self::names((new ProductRepository($this->pdo))->searchCandidates(['bluetooth'])));
    }

    public function testSearchCandidatesWithinACategory(): void
    {
        self::assertSame(['Fone Bluetooth X'], self::names($this->repository->searchCandidates(['fone'], 'Áudio')));
        self::assertSame(['Cabo USB', 'Cabo fone_bt'], self::names($this->repository->searchCandidates(['fone'], 'Informatica')));
    }

    public function testSearchCandidatesWithoutTermsOrWithLimit(): void
    {
        self::assertSame([], $this->repository->searchCandidates([]));
        self::assertCount(1, $this->repository->searchCandidates(['fone', 'bluetooth'], null, 1));
    }
}
