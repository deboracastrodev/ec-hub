<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Controller\Exceptions\InvalidRequestException;
use App\Domain\Event\EventHistoryRepositoryInterface;
use App\Domain\Event\EventPublisherInterface;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Session\Repository\SessionRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;

final class TrackProductInteraction
{
    private const EVENTS = ['view' => 'product.viewed', 'click' => 'product.clicked', 'cart' => 'cart.item_added'];

    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly SessionRepositoryInterface $sessions,
        private readonly EventHistoryRepositoryInterface $history,
        private readonly EventPublisherInterface $publisher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array<string, mixed> */
    public function track(string $interaction, string $sessionId, int $productId, ?string $userId = null, ?int $quantity = null): array
    {
        if (! isset(self::EVENTS[$interaction])) {
            throw new InvalidRequestException('interaction must be view, click or cart');
        }
        if ($productId < 1 || $this->products->findById($productId) === null) {
            throw new InvalidRequestException('product_id must reference an existing product', 404);
        }
        if ($interaction === 'cart' && ($quantity === null || $quantity < 1)) {
            throw new InvalidRequestException('quantity must be a positive integer');
        }

        $event = [
            'event' => self::EVENTS[$interaction],
            'session_id' => $sessionId,
            'product_id' => $productId,
            'timestamp' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
        ];
        if ($userId !== null && trim($userId) !== '') {
            $event['user_id'] = trim($userId);
        }
        if ($quantity !== null) {
            $event['quantity'] = $quantity;
        }

        try {
            if (isset($event['user_id'])) {
                $this->sessions->save($sessionId, 'user.id', $event['user_id']);
            }
            $this->history->append($sessionId, $event['user_id'] ?? null, $event);
            if ($interaction === 'cart') {
                $items = $this->sessions->get($sessionId, 'cart.items');
                $items = is_array($items) ? $items : [];
                $items[(string) $productId] = ((int) ($items[(string) $productId] ?? 0)) + $quantity;
                $this->sessions->save($sessionId, 'cart.items', $items);
            }
        } catch (\Throwable $exception) {
            $this->logger->error('Não foi possível persistir interação de produto.', ['event' => $event['event'], 'error' => $exception->getMessage()]);
        }

        try {
            $this->publisher->publish($event['event'], $event);
        } catch (\Throwable $exception) {
            $this->logger->error('Não foi possível publicar interação de produto.', ['event' => $event['event'], 'error' => $exception->getMessage()]);
        }

        return $event;
    }
}
