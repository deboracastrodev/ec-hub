<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Event;

use App\Application\Event\TrackProductInteraction;
use App\Controller\Exceptions\InvalidRequestException;
use App\Domain\Event\EventHistoryRepositoryInterface;
use App\Domain\Event\EventPublisherInterface;
use App\Domain\Product\Model\Product;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Session\Repository\SessionRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TrackProductInteractionTest extends TestCase
{
    public function testTracksCartAndKeepsMutationWhenHistoryAndPublicationFail(): void
    {
        $products = $this->createMock(ProductRepositoryInterface::class);
        $products->method('findById')->with(7)->willReturn($this->createMock(Product::class));
        $publisher = $this->createMock(EventPublisherInterface::class);
        $publisher->method('publish')->willThrowException(new \RuntimeException('Redis indisponível'));
        $history = $this->createMock(EventHistoryRepositoryInterface::class);
        $history->method('append')->willThrowException(new \RuntimeException('Redis indisponível'));
        $session = new InMemorySessionRepository();

        $event = (new TrackProductInteraction($products, $session, $history, $publisher, new NullLogger()))
            ->track('cart', 'session', 7, 'user-1', 2);

        self::assertSame('cart.item_added', $event['event']);
        self::assertSame(['7' => 2], $session->get('session', 'cart.items'));
    }

    public function testTracksViewAndClickWithRequiredEnvelopeData(): void
    {
        $products = $this->createMock(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($this->createMock(Product::class));
        $publisher = $this->createMock(EventPublisherInterface::class);
        $published = [];
        $publisher->expects(self::exactly(2))->method('publish')
            ->willReturnCallback(static function (string $name, array $event) use (&$published): void {
                $published[] = [$name, $event];
            });
        $history = new InMemoryEventHistoryRepository();
        $tracker = new TrackProductInteraction(
            $products,
            new InMemorySessionRepository(),
            $history,
            $publisher,
            new NullLogger()
        );

        $tracker->track('view', 'session', 7);
        $tracker->track('click', 'session', 7, 'user-1');

        self::assertCount(2, $history->getBySession('session'));
        self::assertCount(1, $history->getByUserId('user-1'));
        self::assertSame('product.viewed', $published[0][0]);
        self::assertSame('session', $published[0][1]['session_id']);
        self::assertSame(7, $published[0][1]['product_id']);
        self::assertArrayHasKey('timestamp', $published[0][1]);
        self::assertSame('product.clicked', $published[1][0]);
        self::assertSame('user-1', $published[1][1]['user_id']);
    }

    public function testRejectsUnknownProductBeforePublishing(): void
    {
        $products = $this->createMock(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn(null);
        $publisher = $this->createMock(EventPublisherInterface::class);
        $publisher->expects(self::never())->method('publish');
        $tracker = new TrackProductInteraction(
            $products,
            new InMemorySessionRepository(),
            new InMemoryEventHistoryRepository(),
            $publisher,
            new NullLogger()
        );

        try {
            $tracker->track('click', 'session', 999);
            self::fail('Produto inexistente deveria ser rejeitado.');
        } catch (InvalidRequestException $exception) {
            self::assertSame(404, $exception->getHttpCode());
        }
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
