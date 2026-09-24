<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Product;

use App\Application\Product\ManageProducts;
use App\Application\Product\ProductInput;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryProductRepository;

final class ManageProductsTest extends TestCase
{
    private function input(array $overrides = []): ProductInput
    {
        return ProductInput::fromForm($overrides + [
            'name' => 'Headset USB',
            'description' => 'Headset com microfone.',
            'price' => '1234,50',
            'category' => 'Áudio',
            'image_url' => '/assets/images/headset.jpg',
        ]);
    }

    /** @return list<array<string, mixed>> */
    private static function manyProducts(int $count): array
    {
        $products = [];
        for ($i = 1; $i <= $count; ++$i) {
            $products[] = ['id' => $i, 'name' => sprintf('Produto %02d', $i), 'slug' => "produto-{$i}",
                'description' => '', 'price' => 10.0, 'category' => 'Geral', 'image_url' => null];
        }

        return $products;
    }

    public function testListPagePaginates20PerPage(): void
    {
        $manage = new ManageProducts(new InMemoryProductRepository(self::manyProducts(45)));

        $first = $manage->listPage(1);
        self::assertCount(20, $first['products']);
        self::assertSame(1, $first['page']);
        self::assertSame(3, $first['total_pages']);
        self::assertSame(45, $first['total']);

        $last = $manage->listPage(3);
        self::assertCount(5, $last['products']);
        self::assertSame(41, $last['products'][0]->getId());
    }

    public function testListPageNormalizesOutOfRangePages(): void
    {
        $manage = new ManageProducts(new InMemoryProductRepository(self::manyProducts(45)));

        self::assertSame(1, $manage->listPage(0)['page']);
        self::assertSame(1, $manage->listPage(-3)['page']);
        self::assertSame(3, $manage->listPage(PHP_INT_MAX)['page']);
    }

    public function testCreateStoresNormalizedData(): void
    {
        $repository = new InMemoryProductRepository();
        $manage = new ManageProducts($repository);

        $id = $manage->create($this->input());
        $product = $manage->find($id);

        self::assertNotNull($product);
        self::assertSame('Headset USB', $product->getName());
        self::assertSame(1234.5, $product->getPrice()->getDecimal());
        self::assertSame('headset-usb', $product->getSlug());
        self::assertSame(3, $manage->listPage(1)['total']);
    }

    public function testUpdateKeepsSlugAndClearsOptionalFields(): void
    {
        $repository = new InMemoryProductRepository();
        $manage = new ManageProducts($repository);

        self::assertTrue($manage->update(1, $this->input(['name' => 'Mouse Renomeado', 'image_url' => '', 'description' => ''])));

        $product = $manage->find(1);
        self::assertNotNull($product);
        self::assertSame('Mouse Renomeado', $product->getName());
        self::assertSame('mouse-gamer-rgb', $product->getSlug());
        self::assertSame('', $product->getImageUrl());
        self::assertSame('', $product->getDescription());
        self::assertNull($repository->rawRow(1)['image_url']);
    }

    public function testUpdateIsTrueEvenWithoutChanges(): void
    {
        $repository = new InMemoryProductRepository();
        $manage = new ManageProducts($repository);
        $manage->update(1, $this->input());

        self::assertTrue($manage->update(1, $this->input()));
    }

    public function testUpdateIsTrueWhenRepositoryReportsNoChangedRowForAnExistingProduct(): void
    {
        $repository = new class () extends InMemoryProductRepository {
            public int $updateCalls = 0;

            public function update(int $id, array $data): bool
            {
                ++$this->updateCalls;

                return false;
            }
        };
        $manage = new ManageProducts($repository);

        self::assertTrue($manage->update(1, $this->input()));
        self::assertSame(1, $repository->updateCalls);
    }

    public function testUpdateIsFalseWhenProductIsDeletedBetweenReadAndWrite(): void
    {
        $repository = new class () extends InMemoryProductRepository {
            public function update(int $id, array $data): bool
            {
                $this->delete($id); // a concurrent admin deletes it first

                return false;
            }
        };
        $manage = new ManageProducts($repository);

        self::assertFalse($manage->update(1, $this->input()));
    }

    public function testFakeUpdateReportsWhetherAValueChanged(): void
    {
        $repository = new InMemoryProductRepository();

        self::assertFalse($repository->update(1, ['name' => 'Mouse Gamer RGB', 'price' => '149.90']));
        self::assertTrue($repository->update(1, ['name' => 'Mouse Novo']));
    }

    public function testUpdateOfMissingOrDeletedProductIsFalse(): void
    {
        $manage = new ManageProducts(new InMemoryProductRepository());

        self::assertFalse($manage->update(999, $this->input()));
        self::assertTrue($manage->delete(2));
        self::assertFalse($manage->update(2, $this->input()));
    }

    public function testDeleteIsSoft(): void
    {
        $repository = new InMemoryProductRepository();
        $manage = new ManageProducts($repository);

        self::assertTrue($manage->delete(1));

        self::assertNull($manage->find(1));
        self::assertNull($repository->findBySlug('mouse-gamer-rgb'));
        self::assertSame(1, $repository->count());
        self::assertNotNull($repository->rawRow(1)['deleted_at']);
        self::assertFalse($manage->delete(1), 'already deleted');
        self::assertFalse($manage->delete(999));
    }

    public function testDeletedSlugStaysReserved(): void
    {
        $repository = new InMemoryProductRepository();
        $manage = new ManageProducts($repository);
        $manage->delete(1);

        $id = $manage->create($this->input(['name' => 'Mouse Gamer RGB']));

        self::assertSame('mouse-gamer-rgb-1', $manage->find($id)?->getSlug());
    }
}
