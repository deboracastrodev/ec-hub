<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Product;

use App\Application\Product\GetProductDetail;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryProductRepository;

final class GetProductDetailTest extends TestCase
{
    public function testExecuteByIdentifierResolvesSlug(): void
    {
        $repository = new InMemoryProductRepository([
            [
                'id' => 1,
                'slug' => 'fone-bluetooth-sony',
                'name' => 'Fone Bluetooth Sony',
                'description' => '',
                'price' => 199.90,
                'category' => 'Áudio',
                'image_url' => '',
            ],
        ]);

        $useCase = new GetProductDetail($repository);

        $product = $useCase->executeByIdentifier('fone-bluetooth-sony');

        $this->assertNotNull($product);
        $this->assertSame('fone-bluetooth-sony', $product['slug']);
    }

    public function testExecuteByIdentifierFallsBackToNumericId(): void
    {
        $repository = new InMemoryProductRepository([
            [
                'id' => 42,
                'slug' => 'notebook-gamer',
                'name' => 'Notebook Gamer',
                'description' => '',
                'price' => 4500.00,
                'category' => 'Informática',
                'image_url' => '',
            ],
        ]);

        $useCase = new GetProductDetail($repository);

        $product = $useCase->executeByIdentifier('42');

        $this->assertNotNull($product);
        $this->assertSame('Notebook Gamer', $product['name']);
    }
}
