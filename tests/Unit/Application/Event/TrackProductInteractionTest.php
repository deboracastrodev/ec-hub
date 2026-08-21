<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Event;

use App\Application\Event\TrackProductInteraction;
use App\Domain\Event\EventHistoryRepositoryInterface;
use App\Domain\Event\EventPublisherInterface;
use App\Domain\Product\Model\Product;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Session\Repository\SessionRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TrackProductInteractionTest extends TestCase
{
    public function testTracksCartInteractionAndKeepsTheCartInSession(): void
    {
        $products = $this->createMock(ProductRepositoryInterface::class);
        $products->method('findById')->with(7)->willReturn($this->createMock(Product::class));
        $publisher = $this->createMock(EventPublisherInterface::class);
        $publisher->expects($this->once())->method('publish')->with('cart.item_added', $this->callback(
            static fn (array $event): bool => $event['session_id'] === 'session' && $event['product_id'] === 7 && $event['quantity'] === 2 && isset($event['timestamp'])
        ));
        $session = new InMemorySessionRepository();
        $history = new InMemoryEventHistoryRepository();
        $tracker = new TrackProductInteraction($products, $session, $history, $publisher, new NullLogger());

        $event = $tracker->track('cart', 'session', 7, 'user-1', 2);

        self::assertSame('cart.item_added', $event['event']);
        self::assertSame(['7' => 2], $session->get('session', 'cart.items'));
        self::assertCount(1, $history->getBySession('session'));
        self::assertSame('user-1', $session->get('session', 'user.id'));
    }

    public function testPublicationFailureDoesNotPreventTracking(): void
    {
        $products = $this->createMock(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($this->createMock(Product::class));
        $publisher = $this->createMock(EventPublisherInterface::class);
        $publisher->method('publish')->willThrowException(new \RuntimeException('Redis indisponível'));
        $session = new InMemorySessionRepository();

        $history = new InMemoryEventHistoryRepository();
        (new TrackProductInteraction($products, $session, $history, $publisher, new NullLogger()))->track('view', 'session', 1);

        self::assertCount(1, $history->getBySession('session'));
    }

    public function testKeepsExactlyTheLastFiftyInteractions(): void
    {
        $products = $this->createMock(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($this->createMock(Product::class));
        $history = new InMemoryEventHistoryRepository();
        $tracker = new TrackProductInteraction($products, new InMemorySessionRepository(), $history, $this->createMock(EventPublisherInterface::class), new NullLogger());

        for ($productId = 1; $productId <= 51; $productId++) {
            $tracker->track('view', 'session', $productId);
        }

        self::assertCount(50, $history->getBySession('session'));
        self::assertSame(2, $history->getBySession('session')[0]['product_id']);
        self::assertSame(51, $history->getBySession('session')[49]['product_id']);
    }
}

final class InMemorySessionRepository implements SessionRepositoryInterface
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function save(string $sessionId, string $field, mixed $value): void
    {
        $this->values[$sessionId . ':' . $field] = $value;
    }

    public function get(string $sessionId, string $field): mixed
    {
        return $this->values[$sessionId . ':' . $field] ?? null;
    }
}

final class InMemoryEventHistoryRepository implements EventHistoryRepositoryInterface
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $sessions = [];
    /** @var array<string, list<array<string, mixed>>> */
    private array $users = [];

    public function append(string $sessionId, ?string $userId, array $event): void
    {
        $this->sessions[$sessionId][] = $event;
        $this->sessions[$sessionId] = array_slice($this->sessions[$sessionId], -50);
        if ($userId !== null) {
            $this->users[$userId][] = $event;
            $this->users[$userId] = array_slice($this->users[$userId], -50);
        }
    }

    public function getBySession(string $sessionId): array
    {
        return $this->sessions[$sessionId] ?? [];
    }

    public function getByUserId(string $userId): array
    {
        return $this->users[$userId] ?? [];
    }
}
