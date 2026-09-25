<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Event;

use App\Application\Event\TrackProductInteraction;
use App\Controller\Exceptions\InvalidRequestException;
use App\Domain\Event\EventHistoryRepositoryInterface;
use App\Domain\Event\EventPublisherInterface;
use App\Domain\Event\EventStoreInterface;
use App\Domain\Product\Model\Product;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Session\Repository\SessionRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\InMemorySessionRepository as CasSessionRepository;

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

        $event = (new TrackProductInteraction($products, $session, $history, $this->createStub(EventStoreInterface::class), $publisher, new NullLogger()))
            ->track('cart', 'session', 7, 'user-1', 2);

        self::assertSame('cart.item_added', $event['event']);
        self::assertSame(['7' => 2], $session->get('session', 'cart.items'));
    }

    public function testCartReturnsItemCountClampsAndKeepsItOutOfThePublishedEvent(): void
    {
        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($this->createStub(Product::class));
        $published = [];
        $publisher = $this->createMock(EventPublisherInterface::class);
        $publisher->expects(self::exactly(2))->method('publish')
            ->willReturnCallback(static function (string $name, array $event) use (&$published): void {
                $published[] = $event;
            });
        $stored = [];
        $eventStore = $this->createStub(EventStoreInterface::class);
        $eventStore->method('append')->willReturnCallback(static function (array $envelope) use (&$stored): void {
            $stored[] = $envelope;
        });
        $history = new InMemoryEventHistoryRepository();
        $session = new InMemorySessionRepository();
        $session->save('session', 'cart.items', ['3' => 1, '7' => 98, 'x' => 'y']);
        $tracker = new TrackProductInteraction($products, $session, $history, $eventStore, $publisher, new NullLogger());

        $first = $tracker->track('cart', 'session', 7, null, 5);
        $second = $tracker->track('cart', 'session', 4, null, 2);

        self::assertSame(100, $first['cart_item_count']);
        self::assertSame(102, $second['cart_item_count']);
        self::assertSame(['3' => 1, '7' => 99, '4' => 2], $session->get('session', 'cart.items'));
        foreach ($published as $event) {
            self::assertArrayNotHasKey('cart_item_count', $event);
        }
        foreach ($stored as $envelope) {
            self::assertArrayNotHasKey('cart_item_count', $envelope['data']);
        }
        foreach ($history->getBySession('session') as $event) {
            self::assertArrayNotHasKey('cart_item_count', $event);
        }
        self::assertSame(5, $published[0]['quantity']);
    }

    public function testViewAndClickDoNotCarryCartItemCount(): void
    {
        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($this->createStub(Product::class));
        $tracker = new TrackProductInteraction(
            $products,
            new InMemorySessionRepository(),
            new InMemoryEventHistoryRepository(),
            $this->createStub(EventStoreInterface::class),
            $this->createStub(EventPublisherInterface::class),
            new NullLogger()
        );

        self::assertArrayNotHasKey('cart_item_count', $tracker->track('view', 'session', 7));
        self::assertArrayNotHasKey('cart_item_count', $tracker->track('click', 'session', 7));
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
        $eventStore = $this->createMock(EventStoreInterface::class);
        $stored = [];
        $eventStore->expects(self::exactly(2))->method('append')
            ->willReturnCallback(static function (array $envelope) use (&$stored): void {
                $stored[] = $envelope;
            });
        $tracker = new TrackProductInteraction(
            $products,
            new InMemorySessionRepository(),
            $history,
            $eventStore,
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
        self::assertSame('product.viewed', $stored[0]['event']);
        self::assertSame($published[0][1], $stored[0]['data']);
    }

    public function testPersistsEventBeforePublishingAndKeepsPublishingWhenStoreFails(): void
    {
        $products = $this->createMock(ProductRepositoryInterface::class);
        $products->method('findById')->with(7)->willReturn($this->createMock(Product::class));
        $calls = [];
        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects(self::once())->method('append')
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'store';

                throw new \RuntimeException('Event store indisponível');
            });
        $publisher = $this->createMock(EventPublisherInterface::class);
        $publisher->expects(self::once())->method('publish')
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'publish';
            });
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $event = (new TrackProductInteraction(
            $products,
            new InMemorySessionRepository(),
            new InMemoryEventHistoryRepository(),
            $eventStore,
            $publisher,
            $logger
        ))->track('click', 'session', 7);

        self::assertSame('product.clicked', $event['event']);
        self::assertSame(['store', 'publish'], $calls);
    }

    public function testKeepsTrackingAndResponseWhenCartPersistenceFails(): void
    {
        $products = $this->createMock(ProductRepositoryInterface::class);
        $products->method('findById')->with(7)->willReturn($this->createMock(Product::class));
        $sessions = $this->createMock(SessionRepositoryInterface::class);
        $sessions->method('get')->willThrowException(new \RuntimeException('Redis indisponível'));
        $sessions->method('compareAndSwap')->willThrowException(new \RuntimeException('Redis indisponível'));
        $sessions->method('save')->willThrowException(new \RuntimeException('Redis indisponível'));
        $history = $this->createMock(EventHistoryRepositoryInterface::class);
        $history->expects(self::once())->method('append');
        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects(self::once())->method('append');
        $publisher = $this->createMock(EventPublisherInterface::class);
        $publisher->expects(self::once())->method('publish')->with('cart.item_added', self::isArray());
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects(self::atLeastOnce())->method('error');

        $event = (new TrackProductInteraction($products, $sessions, $history, $eventStore, $publisher, $logger))
            ->track('cart', 'session', 7, 'user-1', 2);

        self::assertSame('cart.item_added', $event['event']);
        self::assertSame(2, $event['quantity']);
        self::assertArrayHasKey('cart_item_count', $event);
        self::assertNull($event['cart_item_count']);
    }

    public function testCartAddOnEmptySessionWritesThroughCompareAndSwap(): void
    {
        $sessions = $this->createMock(SessionRepositoryInterface::class);
        $sessions->method('get')->willReturn(null);
        $sessions->expects(self::once())->method('compareAndSwap')
            ->with('session', 'cart.items', null, ['7' => 2])
            ->willReturn(true);
        $sessions->expects(self::never())->method('save');

        $event = $this->tracker($sessions)->track('cart', 'session', 7, null, 2);

        self::assertSame(2, $event['cart_item_count']);
    }

    public function testCartAddRetriesALostCompareAndSwap(): void
    {
        $sessions = new CasSessionRepository();
        $sessions->save('session', 'cart.items', ['3' => 1]);
        $sessions->casConflicts = 1;

        $event = $this->tracker($sessions)->track('cart', 'session', 7, null, 2);

        self::assertSame(2, $sessions->casCalls);
        self::assertSame(['3' => 1, '7' => 2], $sessions->get('session', 'cart.items'));
        self::assertSame(3, $event['cart_item_count']);
    }

    public function testCartAddSucceedsOnTheLastAllowedAttempt(): void
    {
        $sessions = new CasSessionRepository();
        $sessions->save('session', 'cart.items', ['3' => 1]);
        $sessions->casConflicts = 4;

        $event = $this->tracker($sessions)->track('cart', 'session', 7, null, 2);

        self::assertSame(5, $sessions->casCalls);
        self::assertSame(['3' => 1, '7' => 2], $sessions->get('session', 'cart.items'));
        self::assertSame(3, $event['cart_item_count']);
    }

    public function testCartAddKeepsAWriteMadeBetweenReadAndCompareAndSwap(): void
    {
        $inner = new CasSessionRepository();
        $inner->save('session', 'cart.items', ['3' => 1]);
        $sessions = new class ($inner) implements SessionRepositoryInterface {
            private bool $raced = false;

            public function __construct(private readonly CasSessionRepository $inner)
            {
            }

            public function save(string $sessionId, string $field, mixed $value): void
            {
                $this->inner->save($sessionId, $field, $value);
            }

            public function get(string $sessionId, string $field): mixed
            {
                return $this->inner->get($sessionId, $field);
            }

            public function compareAndSwap(string $sessionId, string $field, mixed $expected, mixed $value): bool
            {
                if (! $this->raced) {
                    // Another writer (e.g. ManageCart update) lands after our read.
                    $this->raced = true;
                    $this->inner->save($sessionId, $field, ['3' => 5]);
                }

                return $this->inner->compareAndSwap($sessionId, $field, $expected, $value);
            }
        };

        $event = $this->tracker($sessions)->track('cart', 'session', 7, null, 2);

        self::assertSame(['3' => 5, '7' => 2], $inner->get('session', 'cart.items'));
        self::assertSame(7, $event['cart_item_count']);
        self::assertSame(2, $inner->casCalls);
    }

    public function testCartAddDegradesWhenCompareAndSwapKeepsLosing(): void
    {
        $sessions = new CasSessionRepository();
        $sessions->save('session', 'cart.items', ['3' => 1]);
        $sessions->casConflicts = 5;
        $publisher = $this->createMock(EventPublisherInterface::class);
        $publisher->expects(self::once())->method('publish')->with('cart.item_added', self::isArray());
        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects(self::once())->method('append');
        $history = new InMemoryEventHistoryRepository();
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with('Não foi possível persistir o carrinho da sessão.', self::callback(
                static fn (array $context): bool => $context['event'] === 'cart.item_added'
                    && $context['error'] === 'Carrinho alterado concorrentemente.'
            ));

        $event = $this->tracker($sessions, $publisher, $eventStore, $history, $logger)
            ->track('cart', 'session', 7, null, 2);

        self::assertNull($event['cart_item_count']);
        self::assertSame(5, $sessions->casCalls);
        self::assertSame(['3' => 1], $sessions->get('session', 'cart.items'));
        self::assertCount(1, $history->getBySession('session'));
    }

    public function testCartAddDegradesWhenRedisIsDown(): void
    {
        $sessions = new CasSessionRepository();
        $sessions->failWith = new \RuntimeException('Redis indisponível');
        $publisher = $this->createMock(EventPublisherInterface::class);
        $publisher->expects(self::once())->method('publish')->with('cart.item_added', self::isArray());
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with('Não foi possível persistir o carrinho da sessão.', self::anything());

        $event = $this->tracker($sessions, $publisher, null, null, $logger)->track('cart', 'session', 7, null, 2);

        self::assertNull($event['cart_item_count']);
    }

    public function testCartAddDegradesWhenOnlyCompareAndSwapFails(): void
    {
        $sessions = new CasSessionRepository();
        $sessions->casFailWith = new \RuntimeException('Redis indisponível');
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $event = $this->tracker($sessions, null, null, null, $logger)->track('cart', 'session', 7, null, 2);

        self::assertNull($event['cart_item_count']);
        self::assertNull($sessions->get('session', 'cart.items'));
        self::assertSame(0, $sessions->writes);
    }

    private function tracker(
        SessionRepositoryInterface $sessions,
        ?EventPublisherInterface $publisher = null,
        ?EventStoreInterface $eventStore = null,
        ?EventHistoryRepositoryInterface $history = null,
        ?\Psr\Log\LoggerInterface $logger = null
    ): TrackProductInteraction {
        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($this->createStub(Product::class));

        return new TrackProductInteraction(
            $products,
            $sessions,
            $history ?? new InMemoryEventHistoryRepository(),
            $eventStore ?? $this->createStub(EventStoreInterface::class),
            $publisher ?? $this->createStub(EventPublisherInterface::class),
            $logger ?? new NullLogger()
        );
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
            $this->createStub(EventStoreInterface::class),
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

    public function compareAndSwap(string $sessionId, string $field, mixed $expected, mixed $value): bool
    {
        $key = $sessionId . ':' . $field;
        if (($this->values[$key] ?? null) !== $expected) {
            return false;
        }

        $this->values[$key] = $value;

        return true;
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
