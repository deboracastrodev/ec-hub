<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Recommendation\Utility;

use App\Domain\Recommendation\Utility\ConfidenceCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfidenceCalculatorTest extends TestCase
{
    private ConfidenceCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ConfidenceCalculator();
    }

    #[DataProvider('confidenceLevelProvider')]
    public function testCalculateConfidenceLevel(float $score, string $expected): void
    {
        $this->assertSame($expected, $this->calculator->calculateConfidenceLevel($score));
    }

    /**
     * @return iterable<string, array{0: float, 1: string}>
     */
    public static function confidenceLevelProvider(): iterable
    {
        yield 'well above high threshold' => [95.0, 'high'];
        yield 'exactly at high threshold (AC6 boundary)' => [80.0, 'high'];
        yield 'just below high threshold' => [79.9, 'medium'];
        yield 'mid range' => [65.0, 'medium'];
        yield 'exactly at medium threshold (AC6 boundary)' => [50.0, 'medium'];
        yield 'just below medium threshold' => [49.9, 'low'];
        yield 'very low' => [10.0, 'low'];
        yield 'zero' => [0.0, 'low'];
    }

    #[DataProvider('scoreLabelProvider')]
    public function testCalculateScoreLabel(float $score, string $expected): void
    {
        $this->assertSame($expected, $this->calculator->calculateScoreLabel($score));
    }

    /**
     * @return iterable<string, array{0: float, 1: string}>
     */
    public static function scoreLabelProvider(): iterable
    {
        yield 'high' => [87.5, 'Alta similaridade'];
        yield 'medium' => [65.0, 'Média similaridade'];
        yield 'low' => [20.0, 'Baixa similaridade'];
    }

    public function testCustomThresholdsOverrideDefaults(): void
    {
        $calculator = new ConfidenceCalculator(90.0, 60.0);

        $this->assertSame('medium', $calculator->calculateConfidenceLevel(85.0));
        $this->assertSame('high', $calculator->calculateConfidenceLevel(90.0));
        $this->assertSame('low', $calculator->calculateConfidenceLevel(59.9));
    }

    public function testNormalizeScoreScalesZeroToOneRangeIntoPercentage(): void
    {
        $this->assertSame(0.0, $this->calculator->normalizeScore(0.0));
        $this->assertSame(50.0, $this->calculator->normalizeScore(0.5));
        $this->assertSame(100.0, $this->calculator->normalizeScore(1.0));
    }

    public function testNormalizeScoreClampsOutOfRangeInput(): void
    {
        $this->assertSame(0.0, $this->calculator->normalizeScore(-1.0));
        $this->assertSame(100.0, $this->calculator->normalizeScore(2.0));
    }

    public function testNormalizeScoreSupportsCustomRange(): void
    {
        $this->assertSame(50.0, $this->calculator->normalizeScore(5.0, 0.0, 10.0));
    }
}
