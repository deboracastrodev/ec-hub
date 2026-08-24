<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Application\Event\TrackProductInteraction;
use App\Controller\Exceptions\InvalidRequestException;
use App\Controller\ProductInteractionController;
use App\Domain\Event\EventHistoryRepositoryInterface;
use App\Domain\Event\EventPublisherInterface;
use App\Domain\Product\Model\Product;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Session\Repository\SessionRepositoryInterface;
use App\Shared\Http\SessionContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ProductInteractionControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_COOKIE[SessionContext::COOKIE_NAME], $_COOKIE[SessionContext::SIGNATURE_COOKIE_NAME]);
    }

    public function testResponsesAreAllowlistedAndNeverExposeSessionId(): void
    {
        $_COOKIE[SessionContext::COOKIE_NAME] = str_repeat('a', 64);
        $_COOKIE[SessionContext::SIGNATURE_COOKIE_NAME] = hash_hmac(
            'sha256',
            $_COOKIE[SessionContext::COOKIE_NAME],
            'phpunit-only-session-cookie-secret-32'
        );
        $controller = new ProductInteractionController($this->tracker(), new SessionContext('phpunit-only-session-cookie-secret-32'));

        $click = $controller->event(['product_id' => 7, 'interaction' => 'click']);
        $cart = $controller->addCartItem(['product_id' => 7, 'quantity' => 2, 'user_id' => 'user-1']);

        self::assertSame('product.clicked', $click['data']['event']);
        self::assertSame(2, $cart['data']['quantity']);
        self::assertArrayNotHasKey('session_id', $click['data']);
        self::assertArrayNotHasKey('session_id', $cart['data']);
    }

    public function testRejectsInvalidPayloadWithoutPublishing(): void
    {
        $publisher = $this->createMock(EventPublisherInterface::class);
        $publisher->expects(self::never())->method('publish');
        $controller = new ProductInteractionController($this->tracker($publisher), new SessionContext('phpunit-only-session-cookie-secret-32'));

        $this->expectException(InvalidRequestException::class);
        $controller->addCartItem(['product_id' => 7, 'quantity' => 0]);
    }

    private function tracker(?EventPublisherInterface $publisher = null): TrackProductInteraction
    {
        $products = $this->createMock(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($this->createMock(Product::class));
        $sessions = new class () implements SessionRepositoryInterface {
            public function save(string $sessionId, string $field, mixed $value): void
            {
            }

            public function get(string $sessionId, string $field): mixed
            {
                return null;
            }
        };
        $history = new class () implements EventHistoryRepositoryInterface {
            public function append(string $sessionId, ?string $userId, array $event): void
            {
            }

            public function getBySession(string $sessionId): array
            {
                return [];
            }

            public function getByUserId(string $userId): array
            {
                return [];
            }
        };

        return new TrackProductInteraction(
            $products,
            $sessions,
            $history,
            $publisher ?? $this->createMock(EventPublisherInterface::class),
            new NullLogger()
        );
    }
}
