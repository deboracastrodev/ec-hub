<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Cart;

use App\Application\Cart\CartSummary;
use App\Domain\Cart\Model\Cart;
use App\Domain\Session\Repository\SessionRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemorySessionRepository;

final class CartSummaryTest extends TestCase
{
    public function testCountsTheQuantitiesOfTheCurrentSession(): void
    {
        $sessions = new InMemorySessionRepository();
        $sessions->save('s1', Cart::SESSION_FIELD, ['1' => 2, '5' => 3, 'x' => 'y']);

        self::assertSame(5, (new CartSummary(static fn (): string => 's1', static fn () => $sessions))->itemCount());
    }

    public function testWithoutSessionIsZeroAndNeverBuildsTheRepository(): void
    {
        $built = false;
        $summary = new CartSummary(static fn (): ?string => null, static function () use (&$built): SessionRepositoryInterface {
            $built = true;

            return new InMemorySessionRepository();
        });

        self::assertSame(0, $summary->itemCount());
        self::assertFalse($built);
    }

    public function testAnyFailureYieldsNull(): void
    {
        $sessions = new InMemorySessionRepository();
        $sessions->failWith = new \RuntimeException('Redis fora');

        self::assertNull((new CartSummary(static fn (): string => 's1', static fn () => $sessions))->itemCount());
        self::assertNull((new CartSummary(
            static fn (): string => throw new \InvalidArgumentException('SESSION_COOKIE_SECRET inválido'),
            static fn () => new InMemorySessionRepository()
        ))->itemCount());
    }

    public function testIsMemoizedPerInstance(): void
    {
        $calls = 0;
        $sessions = new InMemorySessionRepository();
        $summary = new CartSummary(static function () use (&$calls): string {
            $calls++;

            return 's1';
        }, static fn () => $sessions);

        self::assertSame(0, $summary->itemCount());
        $sessions->save('s1', Cart::SESSION_FIELD, ['1' => 2]);
        self::assertSame(0, $summary->itemCount());
        self::assertSame(1, $calls);

        $failing = 0;
        $broken = new CartSummary(static function () use (&$failing): string {
            $failing++;

            throw new \RuntimeException('x');
        }, static fn () => $sessions);
        self::assertNull($broken->itemCount());
        self::assertNull($broken->itemCount());
        self::assertSame(1, $failing);
    }
}
