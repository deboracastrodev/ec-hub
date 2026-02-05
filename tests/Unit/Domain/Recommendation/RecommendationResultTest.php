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
            'R$ 2.999,00',
            95.5,
            1,
            'Recomendado porque...'
        );

        $data = $result->toArray();

        $this->assertSame(42, $data['product_id']);
        $this->assertSame('Smartphone Galaxy X', $data['product_name']);
        $this->assertSame('Eletrônicos', $data['category']);
        $this->assertSame('R$ 2.999,00', $data['price']);
        $this->assertSame(95.5, $data['score']);
        $this->assertSame(1, $data['rank']);
        $this->assertSame('Recomendado porque...', $data['explanation']);
    }
}
