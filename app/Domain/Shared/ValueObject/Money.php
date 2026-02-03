<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObject;

/**
 * Money Value Object
 *
 * Represents monetary values in cents (integers) to avoid floating point precision issues.
 * Currency defaults to BRL (Brazilian Real).
 */
class Money
{
    private int $amount; // Em centavos (ex: 9990 = R$ 99,90)
    private string $currency; // BRL

    public function __construct(int $amount, string $currency = 'BRL')
    {
        $this->amount = $amount;
        $this->currency = $currency;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * Get formatted price for display (e.g., "99.90" for R$ 99,90)
     */
    public function getFormatted(): string
    {
        return number_format($this->amount / 100, 2, ',', '.');
    }

    /**
     * Get decimal value for database storage (e.g., 99.90)
     */
    public function getDecimal(): float
    {
        return $this->amount / 100;
    }

    /**
     * Create Money from decimal value (e.g., 99.90 -> 9990 cents)
     */
    public static function fromDecimal(float $amount, string $currency = 'BRL'): self
    {
        return new self((int) round($amount * 100), $currency);
    }

    /**
     * Create Money from integer cents (e.g., 9990 -> R$ 99,90)
     */
    public static function fromCents(int $cents, string $currency = 'BRL'): self
    {
        return new self($cents, $currency);
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'formatted' => $this->getFormatted(),
            'decimal' => $this->getDecimal(),
        ];
    }
}
