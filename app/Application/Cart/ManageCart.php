<?php

declare(strict_types=1);

namespace App\Application\Cart;

use App\Domain\Cart\Model\Cart;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Session\Repository\SessionRepositoryInterface;
use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Cart page use case (Story 8.6): view, change a quantity, remove a line.
 *
 * Adding stays in TrackProductInteraction, where the canonical
 * cart.item_added event is born. Every cart write -- the add there (DW-16)
 * and update, remove, pruning here -- is read -> Cart rule -> compareAndSwap
 * against the value read, retried up to 5 times, so concurrent adds, updates
 * and removes never silently lose an update: a write either lands or reports
 * failure (update/remove throw; the add degrades to a null item count).
 */
final class ManageCart
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly SessionRepositoryInterface $sessions,
        private readonly ProductRepositoryInterface $products,
    ) {
    }

    /**
     * Products that no longer exist (including soft-deleted ones) leave the
     * view and the session. Money is summed in cents, converted at the end.
     *
     * @return array{
     *     lines: list<array{product_id: int, name: string, slug: string, image_url: string, unit_price: float, quantity: int, subtotal: float}>,
     *     item_count: int,
     *     total: float
     * }
     */
    public function view(string $sessionId): array
    {
        $cart = Cart::fromSession($this->sessions->get($sessionId, Cart::SESSION_FIELD));

        $lines = [];
        $missing = [];
        $itemCount = 0;
        $totalCents = 0;
        foreach ($cart->quantities() as $productId => $quantity) {
            $product = $this->products->findById($productId);
            if ($product === null) {
                $missing[] = $productId;

                continue;
            }

            $unitCents = $product->getPrice()->getAmount();
            $subtotalCents = $unitCents * $quantity;
            $itemCount += $quantity;
            $totalCents += $subtotalCents;
            $lines[] = [
                'product_id' => $productId,
                'name' => $product->getName(),
                'slug' => $product->getSlug(),
                'image_url' => $product->getImageUrl(),
                'unit_price' => self::decimal($unitCents),
                'quantity' => $quantity,
                'subtotal' => self::decimal($subtotalCents),
            ];
        }

        if ($missing !== []) {
            try {
                $this->mutate($sessionId, static function (Cart $current) use ($missing): ?Cart {
                    $next = $current;
                    foreach ($missing as $productId) {
                        $next = $next->without($productId);
                    }

                    return $next->quantities() === $current->quantities() ? null : $next;
                });
            } catch (\Throwable) {
                // Best effort (conflicts, or a store error such as a Predis
                // connection failure, which is not a RuntimeException): the view
                // is already right and the next read prunes again.
            }
        }

        return ['lines' => $lines, 'item_count' => $itemCount, 'total' => self::decimal($totalCents)];
    }

    /**
     * Sets the quantity of a product already in the cart; 0 removes it.
     *
     * @return bool false when the product is not in the cart
     * @throws InvalidArgumentException when $quantity is outside 0..Cart::MAX_QUANTITY
     * @throws RuntimeException when the cart keeps changing concurrently
     */
    public function updateQuantity(string $sessionId, int $productId, int $quantity): bool
    {
        if ($quantity < 0 || $quantity > Cart::MAX_QUANTITY) {
            throw new InvalidArgumentException(sprintf('Cart quantity must be between 0 and %d.', Cart::MAX_QUANTITY));
        }

        $found = false;
        $this->mutate($sessionId, static function (Cart $cart) use ($productId, $quantity, &$found): ?Cart {
            $found = $cart->has($productId);

            return $found ? $cart->withQuantity($productId, $quantity) : null;
        });

        return $found;
    }

    /**
     * Removes a product from the cart; removing an absent product is a no-op.
     *
     * @throws RuntimeException when the cart keeps changing concurrently
     */
    public function remove(string $sessionId, int $productId): void
    {
        $this->mutate($sessionId, static fn (Cart $cart): ?Cart =>
            $cart->has($productId) ? $cart->without($productId) : null);
    }

    /** Cents to a float decimal, only at the edge (an exact division would give an int). */
    private static function decimal(int $cents): float
    {
        return $cents / 100;
    }

    /**
     * @param Closure(Cart): ?Cart $change returns null when nothing changes
     *                                      (nothing is written then)
     */
    private function mutate(string $sessionId, Closure $change): void
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $raw = $this->sessions->get($sessionId, Cart::SESSION_FIELD);
            $next = $change(Cart::fromSession($raw));
            if ($next === null || ($raw === null && $next->isEmpty())) {
                return;
            }
            if ($this->sessions->compareAndSwap($sessionId, Cart::SESSION_FIELD, $raw, $next->toSession())) {
                return;
            }
        }

        throw new RuntimeException('Carrinho alterado concorrentemente.');
    }
}
