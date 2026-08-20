<?php

declare(strict_types=1);

namespace App\Domain\Recommendation\Model;

/**
 * Value Object representing a recommendation outcome.
 */
class RecommendationResult
{
    private int $productId;
    private string $productName;
    private string $category;
    private float $price;
    private float $score;
    private int $rank;
    private string $explanation;

    public function __construct(
        int $productId,
        string $productName,
        string $category,
        float $price,
        float $score,
        int $rank,
        string $explanation
    ) {
        $this->productId = $productId;
        $this->productName = $productName;
        $this->category = $category;
        $this->price = $price;
        $this->score = $score;
        $this->rank = $rank;
        $this->explanation = $explanation;
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getProductName(): string
    {
        return $this->productName;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    /**
     * Decimal price (e.g. 149.9). Formatting for display happens once, at
     * the view layer (Twig |BRL filter) -- not here (R3.3).
     */
    public function getPrice(): float
    {
        return $this->price;
    }

    public function getScore(): float
    {
        return $this->score;
    }

    public function getRank(): int
    {
        return $this->rank;
    }

    public function getExplanation(): string
    {
        return $this->explanation;
    }

    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'product_name' => $this->productName,
            'category' => $this->category,
            'price' => $this->price,
            'score' => $this->score,
            'rank' => $this->rank,
            'explanation' => $this->explanation,
        ];
    }
}
