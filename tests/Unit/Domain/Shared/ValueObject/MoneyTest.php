<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\ValueObject;

use App\Domain\Shared\ValueObject\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testConstructorStoresCentsAndCurrency(): void
    {
        $money = new Money(9990, 'BRL');

        $this->assertSame(9990, $money->getAmount());
        $this->assertSame('BRL', $money->getCurrency());
    }

    public function testCurrencyDefaultsToBRL(): void
    {
        $money = new Money(100);

        $this->assertSame('BRL', $money->getCurrency());
    }

    public function testFromDecimalConvertsToCents(): void
    {
        $money = Money::fromDecimal(99.90);

        $this->assertSame(9990, $money->getAmount());
        $this->assertSame('BRL', $money->getCurrency());
    }

    public function testFromDecimalSupportsCustomCurrency(): void
    {
        $money = Money::fromDecimal(10.50, 'USD');

        $this->assertSame(1050, $money->getAmount());
        $this->assertSame('USD', $money->getCurrency());
    }

    public function testFromCentsKeepsAmountAsIs(): void
    {
        $money = Money::fromCents(12345);

        $this->assertSame(12345, $money->getAmount());
    }

    public function testGetFormattedUsesBrazilianSeparators(): void
    {
        $money = Money::fromDecimal(1234.56);

        $this->assertSame('1.234,56', $money->getFormatted());
    }

    public function testGetFormattedForCentsValue(): void
    {
        $money = Money::fromCents(9990);

        $this->assertSame('99,90', $money->getFormatted());
    }

    public function testGetDecimalReturnsFloatValue(): void
    {
        $money = Money::fromCents(9990);

        $this->assertSame(99.90, $money->getDecimal());
    }

    public function testZeroAmountIsValid(): void
    {
        $money = new Money(0);

        $this->assertSame(0, $money->getAmount());
        $this->assertSame(0.0, $money->getDecimal());
        $this->assertSame('0,00', $money->getFormatted());
    }

    public function testNegativeAmountBehavesPerImplementationContract(): void
    {
        // Money stores cents as a plain int; negative amounts are accepted and
        // propagated through formatting/decimal without throwing (per the
        // implementation contract -- there is no validation in the VO).
        $money = new Money(-500, 'BRL');

        $this->assertSame(-500, $money->getAmount());
        $this->assertSame(-5.00, $money->getDecimal());
        $this->assertSame('-5,00', $money->getFormatted());
    }

    public function testFromDecimalRoundsToNearestCent(): void
    {
        $money = Money::fromDecimal(10.005);

        $this->assertSame(1001, $money->getAmount());
    }

    public function testToArrayExposesAmountCurrencyFormattedAndDecimal(): void
    {
        $money = Money::fromCents(1990, 'USD');

        $data = $money->toArray();

        $this->assertSame(1990, $data['amount']);
        $this->assertSame('USD', $data['currency']);
        $this->assertSame('19,90', $data['formatted']);
        $this->assertSame(19.90, $data['decimal']);
    }
}
