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
    private float $price;
    private float $score;
    private string $explanation;
    private string $scoreLabel;
    private string $confidenceLevel;

    /** @var list<array{type: string, description: string}> */
    private array $reasons;

    /**
     * @param list<array{type: string, description: string}> $reasons
     */
    public function __construct(
        int $productId,
        string $name,
        string $category,
        float $price,
        float $score,
        string $explanation,
        string $scoreLabel = '',
        string $confidenceLevel = '',
        array $reasons = []
    ) {
        $this->productId = $productId;
        $this->name = $name;
        $this->category = $category;
        $this->price = $price;
        $this->score = $score;
        $this->explanation = $explanation;
        $this->scoreLabel = $scoreLabel;
        $this->confidenceLevel = $confidenceLevel;
        $this->reasons = $reasons;
    }

    public static function fromRecommendationResult(RecommendationResult $result): self
    {
        return new self(
            $result->getProductId(),
            $result->getProductName(),
            $result->getCategory(),
            $result->getPrice(),
            $result->getScore(),
            $result->getExplanation(),
            $result->getScoreLabel(),
            $result->getConfidenceLevel(),
            $result->getReasons()
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
            'score_label' => $this->scoreLabel,
            'explanation' => $this->explanation,
            'reasons' => $this->reasons,
            'confidence_level' => $this->confidenceLevel,
        ];
    }
}
