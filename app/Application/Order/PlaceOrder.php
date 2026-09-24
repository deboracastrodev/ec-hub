<?php

declare(strict_types=1);

namespace App\Application\Order;

use App\Domain\Cart\Model\Cart;
use App\Domain\Order\Model\Order;
use App\Domain\Order\Model\OrderItem;
use App\Domain\Order\Repository\OrderRepositoryInterface;
use App\Domain\Order\Service\OrderConfirmationMailerInterface;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Session\Repository\SessionRepositoryInterface;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Simulated checkout (Story 8.7): turns the session cart into a completed
 * order.
 *
 * The cart is first "claimed" by a compareAndSwap to [] against the value
 * read, and only then is the order saved: a double submit reads the cart
 * already empty and gets EmptyCartException, so one cart never becomes two
 * orders. MySQL and Redis share no transaction, so if the save fails the
 * cart is put back (best effort) -- a lost cart, never a phantom order.
 */
final class PlaceOrder
{
    public const LAST_ORDER_FIELD = 'checkout.last_order';

    private const MAX_ATTEMPTS = 5;

    /** @var Closure(): string */
    private readonly Closure $numberGenerator;

    /** @param null|Closure(): string $numberGenerator */
    public function __construct(
        private readonly SessionRepositoryInterface $sessions,
        private readonly ProductRepositoryInterface $products,
        private readonly OrderRepositoryInterface $orders,
        private readonly OrderConfirmationMailerInterface $mailer,
        private readonly LoggerInterface $logger,
        ?Closure $numberGenerator = null
    ) {
        $this->numberGenerator = $numberGenerator ?? self::generateNumber(...);
    }

    public static function generateNumber(): string
    {
        return 'EC-' . strtoupper(bin2hex(random_bytes(5)));
    }

    /**
     * @throws InvalidArgumentException when the input is not valid
     * @throws EmptyCartException when the cart has nothing orderable
     * @throws RuntimeException when the cart keeps changing concurrently
     * @throws \Throwable when the session store or the order repository fails
     *                    (the cart is restored, best effort, on a save failure)
     */
    public function place(string $sessionId, CheckoutInput $input): Order
    {
        if (! $input->isValid()) {
            throw new InvalidArgumentException('Dados do checkout inválidos.');
        }

        // Everything that can fail before the save (number generation, Order
        // validation) runs BEFORE the claim: once the cart is claimed, the
        // only failure path left is the save, which restores the cart.
        $orderNumber = ($this->numberGenerator)();

        $order = null;
        $raw = null;
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $raw = $this->sessions->get($sessionId, Cart::SESSION_FIELD);
            $items = $this->pricedItems(Cart::fromSession($raw));
            if ($items === []) {
                throw new EmptyCartException();
            }
            $candidate = new Order(
                null,
                $orderNumber,
                $input->name(),
                $input->email(),
                $input->address(),
                Order::STATUS_COMPLETED,
                $items,
                new DateTimeImmutable()
            );
            if ($this->sessions->compareAndSwap($sessionId, Cart::SESSION_FIELD, $raw, [])) {
                $order = $candidate;

                break;
            }
        }
        if ($order === null || $raw === null) {
            throw new RuntimeException('Carrinho alterado concorrentemente.');
        }

        try {
            $order = $this->orders->save($order);
        } catch (\Throwable $exception) {
            $this->restoreCart($sessionId, $raw);

            throw $exception;
        }

        $emailSent = true;

        try {
            $this->mailer->sendConfirmation($order);
        } catch (\Throwable $exception) {
            $emailSent = false;
            $this->logger->error('checkout_confirmation_email_failed', [
                'order_number' => $order->orderNumber(),
                'error' => $exception->getMessage(),
            ]);
        }

        try {
            $this->sessions->save($sessionId, self::LAST_ORDER_FIELD, [
                'order_number' => $order->orderNumber(),
                'email_sent' => $emailSent,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error('checkout_last_order_not_saved', [
                'order_number' => $order->orderNumber(),
                'error' => $exception->getMessage(),
            ]);
        }

        return $order;
    }

    /**
     * The last order placed by this session (the confirmation page's only
     * key: an order number alone never opens an order). Null when absent
     * or malformed.
     *
     * @return array{order_number: string, email_sent: bool}|null
     * @throws \Throwable when the session store fails
     */
    public function lastOrder(string $sessionId): ?array
    {
        $raw = $this->sessions->get($sessionId, self::LAST_ORDER_FIELD);
        if (! is_array($raw) || ! is_string($raw['order_number'] ?? null) || ! Order::isValidNumber($raw['order_number'])) {
            return null;
        }

        return ['order_number' => $raw['order_number'], 'email_sent' => ($raw['email_sent'] ?? false) === true];
    }

    /**
     * Cart lines whose product still exists, priced in cents now.
     *
     * @return list<OrderItem>
     */
    private function pricedItems(Cart $cart): array
    {
        $items = [];
        foreach ($cart->quantities() as $productId => $quantity) {
            $product = $this->products->findById($productId);
            if ($product === null) {
                continue;
            }
            $items[] = new OrderItem($productId, $product->getName(), $product->getPrice()->getAmount(), $quantity);
        }

        return $items;
    }

    /** @param string|int|float|bool|array<string|int, mixed> $raw the cart value that was claimed */
    private function restoreCart(string $sessionId, mixed $raw): void
    {
        try {
            if (! $this->sessions->compareAndSwap($sessionId, Cart::SESSION_FIELD, [], $raw)) {
                $this->logger->error('checkout_cart_not_restored', ['reason' => 'cart changed after the claim']);
            }
        } catch (\Throwable $exception) {
            $this->logger->error('checkout_cart_not_restored', ['error' => $exception->getMessage()]);
        }
    }
}
