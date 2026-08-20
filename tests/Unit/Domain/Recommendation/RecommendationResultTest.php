<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Recommendation;

use App\Domain\Recommendation\Model\RecommendationResult;
use PHPUnit\Framework\TestCase;

final class RecommendationResultTest extends TestCase
{
    public function testToArrayReturnsExpectedShape(): void
    {
        $result = new RecommendationResult(
            42,
            'Smartphone Galaxy X',
            'Eletrônicos',
            2999.0,
            95.5,
            1,
            'Recomendado porque...'
        );

        $data = $result->toArray();

        $this->assertSame(42, $data['product_id']);
        $this->assertSame('Smartphone Galaxy X', $data['product_name']);
        $this->assertSame('Eletrônicos', $data['category']);
        $this->assertSame(2999.0, $data['price']);
        $this->assertSame(95.5, $data['score']);
        $this->assertSame(1, $data['rank']);
        $this->assertSame('Recomendado porque...', $data['explanation']);
    }

    public function testConfidenceLevelAndScoreLabelAreDerivedFromScoreWhenOmitted(): void
    {
        // Story 3.5 (AC6): existing call sites that don't pass confidence
        // metadata explicitly still get it computed from the score.
        $high = new RecommendationResult(1, 'A', 'Cat', 10.0, 85.0, 1, '');
        $medium = new RecommendationResult(2, 'B', 'Cat', 10.0, 65.0, 1, '');
        $low = new RecommendationResult(3, 'C', 'Cat', 10.0, 20.0, 1, '');

        $this->assertSame('high', $high->getConfidenceLevel());
        $this->assertSame('Alta similaridade', $high->getScoreLabel());

        $this->assertSame('medium', $medium->getConfidenceLevel());
        $this->assertSame('Média similaridade', $medium->getScoreLabel());

        $this->assertSame('low', $low->getConfidenceLevel());
        $this->assertSame('Baixa similaridade', $low->getScoreLabel());
    }

    public function testExplicitConfidenceLevelAndScoreLabelAreRespected(): void
    {
        $result = new RecommendationResult(1, 'A', 'Cat', 10.0, 10.0, 1, '', 'high', 'Alta similaridade');

        $this->assertSame('high', $result->getConfidenceLevel());
        $this->assertSame('Alta similaridade', $result->getScoreLabel());
    }

    public function testReasonsDefaultToEmptyArray(): void
    {
        $result = new RecommendationResult(1, 'A', 'Cat', 10.0, 90.0, 1, '');

        $this->assertSame([], $result->getReasons());
    }

    public function testReasonsPassedToConstructorAreReturnedAsIs(): void
    {
        $reasons = [
            ['type' => 'similarity', 'description' => '90% similar'],
            ['type' => 'category', 'description' => 'Mesma categoria: Cat'],
        ];
        $result = new RecommendationResult(1, 'A', 'Cat', 10.0, 90.0, 1, '', null, null, $reasons);

        $this->assertSame($reasons, $result->getReasons());
    }

    public function testToArrayIncludesConfidenceMetadata(): void
    {
        $result = new RecommendationResult(
            1,
            'A',
            'Cat',
            10.0,
            90.0,
            1,
            'explanation',
            null,
            null,
            [['type' => 'similarity', 'description' => '90% similar']]
        );

        $data = $result->toArray();

        $this->assertSame('high', $data['confidence_level']);
        $this->assertSame('Alta similaridade', $data['score_label']);
        $this->assertSame([['type' => 'similarity', 'description' => '90% similar']], $data['reasons']);
    }
}
