<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Product\Model;

use App\Domain\Product\Model\Product;
use App\Domain\Shared\ValueObject\Money;
use PHPUnit\Framework\TestCase;

final class ProductTest extends TestCase
{
    private Product $product;

    protected function setUp(): void
    {
        $this->product = new Product(
            'Smartphone Galaxy X',
            'Smartphone premium com tela AMOLED',
            Money::fromDecimal(2999.00),
            'Eletrônicos',
            'https://example.com/phone.jpg'
        );
    }

    public function testGettersReturnConstructorValues(): void
    {
        $this->assertSame('Smartphone Galaxy X', $this->product->getName());
        $this->assertSame('Smartphone premium com tela AMOLED', $this->product->getDescription());
        $this->assertSame('Eletrônicos', $this->product->getCategory());
        $this->assertSame('https://example.com/phone.jpg', $this->product->getImageUrl());
    }

    public function testIdIsNullUntilSet(): void
    {
        $this->assertNull($this->product->getId());
    }

    public function testSetIdAssignsId(): void
    {
        $this->product->setId(42);

        $this->assertSame(42, $this->product->getId());
    }

    public function testPriceIsStoredAsMoney(): void
    {
        $price = $this->product->getPrice();

        $this->assertInstanceOf(Money::class, $price);
        $this->assertSame(299900, $price->getAmount());
    }

    public function testDefaultsForImageUrlAndSlug(): void
    {
        $product = new Product('A', 'B', Money::fromCents(100), 'Cat');

        $this->assertSame('', $product->getImageUrl());
        $this->assertSame('', $product->getSlug());
    }

    public function testCreatedAtIsSetOnConstruction(): void
    {
        $this->assertInstanceOf(\DateTimeImmutable::class, $this->product->getCreatedAt());
    }

    public function testSettersMutateFields(): void
    {
        $this->product->setDescription('nova descrição');
        $this->product->setPrice(Money::fromDecimal(10.0));
        $this->product->setCategory('Roupas');
        $this->product->setImageUrl('https://example.com/new.jpg');
        $this->product->setSlug('novo-slug');

        $this->assertSame('nova descrição', $this->product->getDescription());
        $this->assertSame(1000, $this->product->getPrice()->getAmount());
        $this->assertSame('Roupas', $this->product->getCategory());
        $this->assertSame('https://example.com/new.jpg', $this->product->getImageUrl());
        $this->assertSame('novo-slug', $this->product->getSlug());
    }

    public function testToArrayReturnsExpectedShape(): void
    {
        $this->product->setId(7);

        $data = $this->product->toArray();

        $this->assertSame(7, $data['id']);
        $this->assertSame('Smartphone Galaxy X', $data['name']);
        $this->assertSame('Smartphone premium com tela AMOLED', $data['description']);
        $this->assertSame(2999.0, $data['price']);
        $this->assertSame('2.999,00', $data['price_formatted']);
        $this->assertSame('Eletrônicos', $data['category']);
        $this->assertSame('https://example.com/phone.jpg', $data['image_url']);
        $this->assertSame('', $data['slug']);
        $this->assertStringMatchesFormat('%d-%d-%d %d:%d:%d', $data['created_at']);
    }

    public function testFromArrayCreatesProductWithAllFields(): void
    {
        $product = Product::fromArray([
            'id' => 3,
            'name' => 'Mouse Gamer',
            'description' => 'RGB mouse',
            'price' => '150.00',
            'category' => 'Eletrônicos',
            'image_url' => 'https://example.com/mouse.jpg',
            'slug' => 'mouse-gamer',
            'created_at' => '2024-01-02 03:04:05',
        ]);

        $this->assertSame(3, $product->getId());
        $this->assertSame('Mouse Gamer', $product->getName());
        $this->assertSame('RGB mouse', $product->getDescription());
        $this->assertSame(15000, $product->getPrice()->getAmount());
        $this->assertSame('Eletrônicos', $product->getCategory());
        $this->assertSame('https://example.com/mouse.jpg', $product->getImageUrl());
        $this->assertSame('mouse-gamer', $product->getSlug());
        $this->assertSame('2024-01-02 03:04:05', $product->getCreatedAt()->format('Y-m-d H:i:s'));
    }

    public function testFromArrayFillsMissingOptionalFields(): void
    {
        $product = Product::fromArray([
            'name' => 'Sem Imagem',
            'description' => 'Sem imagem nem slug',
            'price' => '10.00',
            'category' => 'Outros',
        ]);

        $this->assertSame('', $product->getImageUrl());
        $this->assertSame('', $product->getSlug());
        $this->assertNull($product->getId());
    }
}
