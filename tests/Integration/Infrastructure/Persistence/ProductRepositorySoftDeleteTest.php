<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use App\Infrastructure\Persistence\MySQL\ProductRepository;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\RequiresTestDatabase;

/** Story 8.3 (FR106): soft delete against a real MySQL (ec_hub_test). */
#[Group('db')]
final class ProductRepositorySoftDeleteTest extends TestCase
{
    use RequiresTestDatabase;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->connectToTestDatabaseOrSkip();
    }

    private function idOf(string $slug): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM products WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);

        return (int) $stmt->fetchColumn();
    }

    public function testDeletedProductDisappearsFromReadsButStaysInTheTable(): void
    {
        $repository = new ProductRepository($this->pdo);
        $id = $this->idOf('webcam-full-hd');
        self::assertSame(5, $repository->count());

        self::assertTrue($repository->delete($id));

        $fresh = new ProductRepository($this->pdo);
        self::assertNull($fresh->findById($id));
        self::assertNull($fresh->findBySlug('webcam-full-hd'));
        self::assertSame(4, $fresh->count());
        self::assertSame(3, $fresh->countByCategory('Informatica'));
        self::assertNotContains($id, array_map(static fn ($p) => $p->getId(), $fresh->findAll(50, 0)));
        self::assertNotContains($id, array_map(static fn ($p) => $p->getId(), $fresh->findByCategory('Informatica')));

        $row = $this->pdo->query("SELECT deleted_at FROM products WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertNotNull($row['deleted_at']);

        self::assertFalse($fresh->delete($id), 'already deleted');
        self::assertFalse($fresh->update($id, ['name' => 'Ressuscitado']), 'deleted rows are not updated');
    }

    public function testCategoryOfOnlyDeletedProductsDisappears(): void
    {
        $repository = new ProductRepository($this->pdo);
        $repository->delete($this->idOf('camera-compacta'));

        self::assertNotContains('Fotografia', (new ProductRepository($this->pdo))->findCategories());
    }

    public function testDeletedSlugStaysReserved(): void
    {
        $repository = new ProductRepository($this->pdo);
        $repository->delete($this->idOf('notebook-pro'));

        $id = $repository->create(['name' => 'Notebook Pro', 'description' => '', 'price' => '10.00', 'category' => 'Informatica']);

        self::assertSame('notebook-pro-1', $repository->findById($id)?->getSlug());
    }

    public function testUpdateClearsOptionalFieldsAndKeepsTheSlug(): void
    {
        $repository = new ProductRepository($this->pdo);
        $id = $this->idOf('monitor-ultrawide');

        self::assertTrue($repository->update($id, [
            'name' => 'Monitor Curvo', 'description' => '', 'price' => '1499.90', 'category' => 'Informatica', 'image_url' => null,
        ]));

        $row = $this->pdo->query("SELECT name, slug, description, image_url, price FROM products WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['name' => 'Monitor Curvo', 'slug' => 'monitor-ultrawide', 'description' => '', 'image_url' => null, 'price' => '1499.90'], $row);
    }
}
