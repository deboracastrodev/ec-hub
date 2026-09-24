<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cart;

use App\Domain\Cart\Model\Cart;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CartTest extends TestCase
{
    public function testConstantsMatchTheSessionFormat(): void
    {
        self::assertSame('cart.items', Cart::SESSION_FIELD);
        self::assertSame(99, Cart::MAX_QUANTITY);
    }

    public function testFromSessionKeepsValidEntriesInInsertionOrder(): void
    {
        $cart = Cart::fromSession(['7' => 2, 3 => 1, '12' => '4', '5' => 3.0]);

        self::assertSame([7 => 2, 3 => 1, 12 => 4, 5 => 3], $cart->quantities());
        self::assertSame(10, $cart->itemCount());
        self::assertSame('{"7":2,"3":1,"12":4,"5":3}', json_encode($cart->toSession()));
    }

    /** @return iterable<string, array{mixed}> */
    public static function nonArrayValues(): iterable
    {
        yield 'null' => [null];
        yield 'string' => ['{"7":2}'];
        yield 'int' => [7];
        yield 'bool' => [true];
        yield 'float' => [1.5];
    }

    #[DataProvider('nonArrayValues')]
    public function testFromSessionTreatsNonArrayAsEmpty(mixed $raw): void
    {
        $cart = Cart::fromSession($raw);

        self::assertTrue($cart->isEmpty());
        self::assertSame(0, $cart->itemCount());
        self::assertSame([], $cart->toSession());
    }

    public function testFromSessionDropsInvalidKeysAndQuantities(): void
    {
        $cart = Cart::fromSession([
            'x' => 'y',          // non-numeric key
            '3' => -1,           // negative quantity
            '0' => 2,            // id 0
            '-4' => 2,           // negative id
            '1.5' => 2,          // non-integer id
            '8' => 0,            // zero quantity
            '9' => 1.5,          // fractional quantity
            '10' => 'abc',       // non-numeric quantity
            '11' => true,        // bool quantity
            '13' => [1],         // array quantity
            '14' => null,        // null quantity
            '15' => '2.5',       // fractional string quantity
            '16' => INF,         // non-finite quantity
            '99999999999999999999999' => 1, // id overflow
            '20' => 2,           // valid
        ]);

        self::assertSame([20 => 2], $cart->quantities());
    }

    public function testFromSessionClampsQuantitiesAndMergesEquivalentKeys(): void
    {
        $cart = Cart::fromSession(['1' => 150, '2' => 1e12, '3' => '500', '007' => 60, 7 => 50]);

        self::assertSame([1 => 99, 2 => 99, 3 => 99, 7 => 99], $cart->quantities());
    }

    public function testCorruptedSessionFromTheMatrixIsEmpty(): void
    {
        self::assertTrue(Cart::fromSession(json_decode('{"x":"y","3":-1}', true))->isEmpty());
    }

    public function testAddSumsAppendsAndClamps(): void
    {
        $cart = Cart::fromSession([])->add(1, 1)->add(2, 3)->add(1, 1);
        self::assertSame([1 => 2, 2 => 3], $cart->quantities());

        self::assertSame(99, Cart::fromSession(['5' => 98])->add(5, 5)->quantities()[5]);
        self::assertSame(99, Cart::fromSession([])->add(5, PHP_INT_MAX)->quantities()[5]);
    }

    public function testAddIsImmutable(): void
    {
        $original = Cart::fromSession(['1' => 1]);
        $original->add(1, 1);

        self::assertSame([1 => 1], $original->quantities());
    }

    public function testAddRejectsNonPositiveQuantityAndId(): void
    {
        foreach ([[1, 0], [1, -1], [0, 1], [-3, 1]] as [$id, $quantity]) {
            try {
                Cart::fromSession([])->add($id, $quantity);
                self::fail("add({$id}, {$quantity}) should throw");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testWithQuantitySetsRemovesAndValidates(): void
    {
        $cart = Cart::fromSession(['1' => 2, '2' => 1]);

        self::assertSame([1 => 5, 2 => 1], $cart->withQuantity(1, 5)->quantities());
        self::assertSame([2 => 1], $cart->withQuantity(1, 0)->quantities());
        self::assertSame(99, $cart->withQuantity(2, 99)->quantities()[2]);

        foreach ([-1, 100] as $invalid) {
            try {
                $cart->withQuantity(1, $invalid);
                self::fail("withQuantity(1, {$invalid}) should throw");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testWithoutAndHas(): void
    {
        $cart = Cart::fromSession(['1' => 2]);

        self::assertTrue($cart->has(1));
        self::assertFalse($cart->has(2));
        self::assertFalse($cart->without(1)->has(1));
        self::assertTrue($cart->without(1)->isEmpty());
        self::assertSame([1 => 2], $cart->without(42)->quantities());
    }
}
