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
    private string $priceFormatted;
    private float $score;
    private int $rank;
    private string $explanation;

    public function __construct(
        int $productId,
        string $productName,
        string $category,
        string $priceFormatted,
        float $score,
        int $rank,
        string $explanation
    ) {
        $this->productId = $productId;
        $this->productName = $productName;
        $this->category = $category;
        $this->priceFormatted = $priceFormatted;
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

    public function getPriceFormatted(): string
    {
        return $this->priceFormatted;
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
            'price' => $this->priceFormatted,
            'score' => $this->score,
            'rank' => $this->rank,
            'explanation' => $this->explanation,
        ];
    }
}
