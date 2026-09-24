<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Cart;

use App\Application\Cart\ManageCart;
use App\Domain\Cart\Model\Cart;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryProductRepository;
use Tests\Support\InMemorySessionRepository;

final class ManageCartTest extends TestCase
{
    private const SESSION = 'session-1';

    private InMemorySessionRepository $sessions;

    private InMemoryProductRepository $products;

    protected function setUp(): void
    {
        $this->sessions = new InMemorySessionRepository();
        $this->products = new InMemoryProductRepository([
            ['id' => 1, 'name' => 'Mouse Gamer RGB', 'slug' => 'mouse-gamer-rgb', 'description' => '', 'price' => 149.90, 'category' => 'Periféricos', 'image_url' => '/assets/images/mouse.jpg'],
            ['id' => 2, 'name' => 'Cabo USB', 'slug' => 'cabo-usb', 'description' => '', 'price' => 19.90, 'category' => 'Acessórios', 'image_url' => ''],
            ['id' => 3, 'name' => 'Adesivo', 'slug' => 'adesivo', 'description' => '', 'price' => 0.10, 'category' => 'Acessórios', 'image_url' => ''],
        ]);
    }

    private function cart(): ManageCart
    {
        return new ManageCart($this->sessions, $this->products);
    }

    /** @param array<string, int> $items */
    private function seed(array $items): void
    {
        $this->sessions->save(self::SESSION, Cart::SESSION_FIELD, $items);
        $this->sessions->writes = 0;
    }

    public function testViewBuildsLinesWithCentPreciseTotals(): void
    {
        $this->seed(['2' => 3, '3' => 1]);

        $view = $this->cart()->view(self::SESSION);

        self::assertSame(4, $view['item_count']);
        self::assertSame(59.8, $view['total']);
        self::assertSame([
            'product_id' => 2,
            'name' => 'Cabo USB',
            'slug' => 'cabo-usb',
            'image_url' => '',
            'unit_price' => 19.9,
            'quantity' => 3,
            'subtotal' => 59.7,
        ], $view['lines'][0]);
        self::assertSame(0.1, $view['lines'][1]['subtotal']);
        self::assertSame(0, $this->sessions->writes);
    }

    public function testViewOfEmptyOrAbsentCartWritesNothing(): void
    {
        $view = $this->cart()->view(self::SESSION);

        self::assertSame(['lines' => [], 'item_count' => 0, 'total' => 0.0], $view);
        self::assertNull($this->sessions->raw(self::SESSION, Cart::SESSION_FIELD));
    }

    public function testViewPrunesDeletedAndUnknownProductsFromTheSession(): void
    {
        $this->seed(['1' => 1, '2' => 2, '42' => 1]);
        $this->products->delete(1); // soft delete

        $view = $this->cart()->view(self::SESSION);

        self::assertSame([2], array_column($view['lines'], 'product_id'));
        self::assertSame(2, $view['item_count']);
        self::assertSame(39.8, $view['total']);
        self::assertSame('{"2":2}', $this->sessions->raw(self::SESSION, Cart::SESSION_FIELD));
    }

    public function testViewSurvivesAPruneThatKeepsConflicting(): void
    {
        $this->seed(['1' => 1, '42' => 1]);
        $this->sessions->casConflicts = 100;

        $view = $this->cart()->view(self::SESSION);

        self::assertSame([1], array_column($view['lines'], 'product_id'));
    }

    public function testViewSurvivesAPruneWriteFailingWithANonRuntimeException(): void
    {
        // Predis connection errors extend \Exception, not \RuntimeException.
        $this->seed(['1' => 1, '42' => 1]);
        $this->sessions->casFailWith = new \Exception('Connection refused');

        $view = $this->cart()->view(self::SESSION);

        self::assertSame([1], array_column($view['lines'], 'product_id'));
        self::assertSame(1, $this->sessions->casCalls);
    }

    public function testUpdateQuantitySetsZeroRemovesAndReportsAbsentItems(): void
    {
        $this->seed(['1' => 1, '2' => 1]);
        $cart = $this->cart();

        self::assertTrue($cart->updateQuantity(self::SESSION, 1, 3));
        self::assertSame('{"1":3,"2":1}', $this->sessions->raw(self::SESSION, Cart::SESSION_FIELD));

        self::assertTrue($cart->updateQuantity(self::SESSION, 2, 0));
        self::assertSame('{"1":3}', $this->sessions->raw(self::SESSION, Cart::SESSION_FIELD));

        $writes = $this->sessions->writes;
        self::assertFalse($cart->updateQuantity(self::SESSION, 42, 1));
        self::assertSame($writes, $this->sessions->writes);
    }

    public function testUpdateQuantityRejectsOutOfRangeWithoutWriting(): void
    {
        $this->seed(['1' => 1]);

        foreach ([-1, 100] as $invalid) {
            try {
                $this->cart()->updateQuantity(self::SESSION, 1, $invalid);
                self::fail("quantity {$invalid} should be rejected");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(0, $this->sessions->writes);
    }

    public function testUpdateQuantityWithoutCartFieldWritesNothing(): void
    {
        self::assertFalse($this->cart()->updateQuantity(self::SESSION, 1, 2));
        self::assertNull($this->sessions->raw(self::SESSION, Cart::SESSION_FIELD));
    }

    public function testRemoveIsIdempotent(): void
    {
        $this->seed(['1' => 1]);
        $cart = $this->cart();

        $cart->remove(self::SESSION, 1);
        self::assertSame('[]', $this->sessions->raw(self::SESSION, Cart::SESSION_FIELD));

        $writes = $this->sessions->writes;
        $cart->remove(self::SESSION, 1);
        $cart->remove(self::SESSION, 42);
        self::assertSame($writes, $this->sessions->writes);
    }

    public function testRetriesCompareAndSwapOnConflict(): void
    {
        $this->seed(['1' => 1]);
        $this->sessions->casConflicts = 4;

        self::assertTrue($this->cart()->updateQuantity(self::SESSION, 1, 7));
        self::assertSame(5, $this->sessions->casCalls);
        self::assertSame('{"1":7}', $this->sessions->raw(self::SESSION, Cart::SESSION_FIELD));
    }

    public function testGivesUpAfterFiveConflictingAttempts(): void
    {
        $this->seed(['1' => 1]);
        $this->sessions->casConflicts = 5;

        try {
            $this->cart()->remove(self::SESSION, 1);
            self::fail('Expected a RuntimeException after 5 conflicts');
        } catch (\RuntimeException $exception) {
            self::assertSame('Carrinho alterado concorrentemente.', $exception->getMessage());
        }
        self::assertSame(5, $this->sessions->casCalls);
        self::assertSame('{"1":1}', $this->sessions->raw(self::SESSION, Cart::SESSION_FIELD));
    }

    public function testWritesUseTheValueReadAsExpected(): void
    {
        // A corrupted value is sanitized on write; CAS still matches the raw value read.
        $this->sessions->putRaw(self::SESSION, Cart::SESSION_FIELD, '{"x":"y","1":2}');

        self::assertTrue($this->cart()->updateQuantity(self::SESSION, 1, 4));
        self::assertSame('{"1":4}', $this->sessions->raw(self::SESSION, Cart::SESSION_FIELD));
    }
}
