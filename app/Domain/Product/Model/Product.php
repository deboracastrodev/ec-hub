<?php

declare(strict_types=1);

namespace App\Domain\Product\Model;

use App\Domain\Shared\ValueObject\Money;

/**
 * Product Domain Model
 *
 * Represents a product in the catalog following DDD principles.
 * This is a domain entity that is independent of infrastructure concerns.
 */
class Product
{
    private ?int $id = null;
    private string $name;
    private string $description;
    private Money $price;
    private string $category;
    private string $imageUrl;
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $name,
        string $description,
        Money $price,
        string $category,
        string $imageUrl = ''
    ) {
        $this->name = $name;
        $this->description = $description;
        $this->price = $price;
        $this->category = $category;
        $this->imageUrl = $imageUrl;
        $this->createdAt = new \DateTimeImmutable();
    }

    // Getters
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getPrice(): Money
    {
        return $this->price;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getImageUrl(): string
    {
        return $this->imageUrl;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    // Setters
    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function setPrice(Money $price): void
    {
        $this->price = $price;
    }

    public function setCategory(string $category): void
    {
        $this->category = $category;
    }

    public function setImageUrl(string $imageUrl): void
    {
        $this->imageUrl = $imageUrl;
    }

    /**
     * Convert to array format (useful for JSON responses)
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price->getDecimal(),
            'price_formatted' => $this->price->getFormatted(),
            'category' => $this->category,
            'image_url' => $this->imageUrl,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Create Product from database row
     */
    public static function fromArray(array $data): self
    {
        $product = new self(
            $data['name'],
            $data['description'] ?? '',
            Money::fromDecimal((float) $data['price']),
            $data['category'],
            $data['image_url'] ?? ''
        );

        if (isset($data['id'])) {
            $product->setId((int) $data['id']);
        }

        if (isset($data['created_at'])) {
            $product->createdAt = new \DateTimeImmutable($data['created_at']);
        }

        return $product;
    }
}
