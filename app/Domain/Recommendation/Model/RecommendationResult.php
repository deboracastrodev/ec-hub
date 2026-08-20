<?php

declare(strict_types=1);

namespace App\Domain\Recommendation\Model;

use App\Domain\Recommendation\Utility\ConfidenceCalculator;

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
    private string $confidenceLevel;
    private string $scoreLabel;

    /** @var list<array{type: string, description: string}> */
    private array $reasons;

    /**
     * @param list<array{type: string, description: string}> $reasons Story 3.5 (AC7): multi-factor
     *        explanation reasons, most relevant first. Capped at 3 by the caller (ExplanationGenerator).
     * @param string|null $confidenceLevel Story 3.5 (AC6): 'high'|'medium'|'low'. When omitted it's
     *        derived from $score via ConfidenceCalculator, so existing call sites keep working unchanged.
     * @param string|null $scoreLabel Story 3.5 (AC5): Portuguese label ("Alta similaridade" etc), also
     *        derived from $score when omitted.
     */
    public function __construct(
        int $productId,
        string $productName,
        string $category,
        float $price,
        float $score,
        int $rank,
        string $explanation,
        ?string $confidenceLevel = null,
        ?string $scoreLabel = null,
        array $reasons = []
    ) {
        $this->productId = $productId;
        $this->productName = $productName;
        $this->category = $category;
        $this->price = $price;
        $this->score = $score;
        $this->rank = $rank;
        $this->explanation = $explanation;
        $this->confidenceLevel = $confidenceLevel ?? (new ConfidenceCalculator())->calculateConfidenceLevel($score);
        $this->scoreLabel = $scoreLabel ?? (new ConfidenceCalculator())->calculateScoreLabel($score);
        $this->reasons = $reasons;
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

    public function getConfidenceLevel(): string
    {
        return $this->confidenceLevel;
    }

    public function getScoreLabel(): string
    {
        return $this->scoreLabel;
    }

    /**
     * @return list<array{type: string, description: string}>
     */
    public function getReasons(): array
    {
        return $this->reasons;
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
            'confidence_level' => $this->confidenceLevel,
            'score_label' => $this->scoreLabel,
            'reasons' => $this->reasons,
        ];
    }
}
