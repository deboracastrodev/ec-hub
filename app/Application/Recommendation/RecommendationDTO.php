<?php
declare(strict_types=1);

namespace App\Application\Recommendation;

use App\Domain\Recommendation\Model\RecommendationResult;

/**
 * Recommendation Data Transfer Object for Application layer responses.
 */
class RecommendationDTO
{
    private int $productId;
    private string $name;
    private string $category;
    private string $price;
    private float $score;
    private string $explanation;

    public function __construct(
        int $productId,
        string $name,
        string $category,
        string $price,
        float $score,
        string $explanation
    ) {
        $this->productId = $productId;
        $this->name = $name;
        $this->category = $category;
        $this->price = $price;
        $this->score = $score;
        $this->explanation = $explanation;
    }

    public static function fromRecommendationResult(RecommendationResult $result): self
    {
        return new self(
            $result->getProductId(),
            $result->getProductName(),
            $result->getCategory(),
            $result->getPriceFormatted(),
            $result->getScore(),
            $result->getExplanation()
        );
    }

    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'name' => $this->name,
            'price' => $this->price,
            'category' => $this->category,
            'score' => $this->score,
            'explanation' => $this->explanation,
        ];
    }
}
