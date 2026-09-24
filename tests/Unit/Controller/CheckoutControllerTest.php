<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Application\Cart\ManageCart;
use App\Application\Order\PlaceOrder;
use App\Controller\CheckoutController;
use App\Domain\Cart\Model\Cart;
use App\Domain\Order\Model\Order;
use App\Domain\Order\Service\OrderConfirmationMailerInterface;
use App\Shared\Http\Request;
use App\Shared\Http\SessionContext;
use App\Shared\Http\SessionCsrf;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\AdminTestTwig;
use Tests\Support\InMemoryOrderRepository;
use Tests\Support\InMemoryProductRepository;
use Tests\Support\InMemorySessionRepository;

/** Story 8.7: redirect targets (not observable through public/index.php in the CLI). */
final class CheckoutControllerTest extends TestCase
{
    private const SECRET = 'phpunit-only-session-cookie-secret-32';

    private const FORM = ['name' => 'Ana Souza', 'email' => 'ana@example.com', 'address' => 'Rua das Flores, 123'];

    private string $sessionId;

    private InMemorySessionRepository $sessions;

    private InMemoryOrderRepository $orders;

    private CheckoutController $controller;

    protected function setUp(): void
    {
        $this->sessionId = str_repeat('a', 64);
        $_COOKIE[SessionContext::COOKIE_NAME] = $this->sessionId;
        $_COOKIE[SessionContext::SIGNATURE_COOKIE_NAME] = hash_hmac('sha256', $this->sessionId, self::SECRET);

        $this->sessions = new InMemorySessionRepository();
        $this->orders = new InMemoryOrderRepository();
        $products = new InMemoryProductRepository();
        $mailer = new class () implements OrderConfirmationMailerInterface {
            public function sendConfirmation(Order $order): void
            {
            }
        };
        $this->controller = new CheckoutController(
            new PlaceOrder($this->sessions, $products, $this->orders, $mailer, new NullLogger()),
            new ManageCart($this->sessions, $products),
            $this->orders,
            new SessionContext(self::SECRET, static fn (): bool => throw new \LogicException('No cookie may be issued.')),
            new SessionCsrf(self::SECRET),
            AdminTestTwig::create()
        );
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[SessionContext::COOKIE_NAME], $_COOKIE[SessionContext::SIGNATURE_COOKIE_NAME]);
    }

    public function testRedirects(): void
    {
        $csrf = (new SessionCsrf(self::SECRET))->token($this->sessionId);

        $empty = $this->controller->form(new Request('GET'));
        self::assertSame(303, $empty->status);
        self::assertSame('/cart', $empty->headers['Location']);

        $emptyPost = $this->controller->place(new Request('POST', [], [], ['_csrf' => $csrf] + self::FORM));
        self::assertSame('/cart', $emptyPost->headers['Location']);

        $this->sessions->save($this->sessionId, Cart::SESSION_FIELD, [1 => 1]);
        self::assertSame(200, $this->controller->form(new Request('GET'))->status);

        $placed = $this->controller->place(new Request('POST', [], [], ['_csrf' => $csrf] + self::FORM));
        self::assertSame(303, $placed->status);
        self::assertSame('/checkout/confirmation', $placed->headers['Location']);
        self::assertSame('no-store', $placed->headers['Cache-Control']);

        $confirmation = $this->controller->confirmation(new Request('GET'));
        self::assertSame(200, $confirmation->status);
        self::assertStringContainsString($this->orders->all()[0]->orderNumber(), $confirmation->body);
    }

    public function testWithoutSessionGetRedirectsToTheCart(): void
    {
        unset($_COOKIE[SessionContext::COOKIE_NAME], $_COOKIE[SessionContext::SIGNATURE_COOKIE_NAME]);

        self::assertSame('/cart', $this->controller->form(new Request('GET'))->headers['Location']);
        self::assertSame(403, $this->controller->place(new Request('POST', [], [], self::FORM))->status);
        self::assertSame(404, $this->controller->confirmation(new Request('GET'))->status);
    }
}
