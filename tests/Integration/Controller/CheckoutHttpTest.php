<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Application\Cart\CartSummary;
use App\Application\Cart\ManageCart;
use App\Application\Event\TrackProductInteraction;
use App\Application\Order\PlaceOrder;
use App\Application\Product\GetProductDetail;
use App\Application\Product\GetProductList;
use App\Controller\CartController;
use App\Controller\CheckoutController;
use App\Controller\ProductController;
use App\Controller\ProductInteractionController;
use App\Domain\Cart\Model\Cart;
use App\Domain\Event\EventHistoryRepositoryInterface;
use App\Domain\Event\EventPublisherInterface;
use App\Domain\Product\Service\CategoryService;
use App\Infrastructure\Mail\FileOrderConfirmationMailer;
use App\Shared\Container\Container;
use App\Shared\Http\SessionContext;
use App\Shared\Http\SessionCsrf;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\AdminTestTwig;
use Tests\Support\InMemoryEventStore;
use Tests\Support\InMemoryOrderRepository;
use Tests\Support\InMemoryProductRepository;
use Tests\Support\InMemorySessionRepository;
use Twig\Environment;

/** Story 8.7: the simulated checkout journey through public/index.php (routing + dispatch). */
final class CheckoutHttpTest extends TestCase
{
    private const SECRET = 'phpunit-only-session-cookie-secret-32';

    private const VALID_FORM = [
        'name' => 'Ana Souza',
        'email' => 'ana@example.com',
        'address' => "Rua das Flores, 123\r\nSão Paulo - SP",
    ];

    private InMemoryProductRepository $products;

    private InMemorySessionRepository $sessions;

    private InMemoryOrderRepository $orders;

    private string $mailDirectory;

    /** @var array<string, string> cookies issued by the "server" in the whole journey */
    private array $issuedCookies = [];

    /** @var array<string, string> cookies issued by the last request */
    private array $lastCookies = [];

    protected function tearDown(): void
    {
        if (isset($this->mailDirectory)) {
            foreach (glob($this->mailDirectory . '/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($this->mailDirectory)) {
                rmdir($this->mailDirectory);
            }
        }
    }

    #[RunInSeparateProcess]
    public function testFullCheckoutJourney(): void
    {
        $this->setUpCatalog();
        $csrf = $this->openSessionAndAdd(['1' => '2']);

        // The cart links to the checkout.
        $cart = $this->request('GET', '/cart');
        self::assertStringContainsString('<a class="cart-button cart-button--primary" href="/checkout">Ir para o checkout</a>', $cart['body']);

        // The form, with the order summary.
        $form = $this->request('GET', '/checkout');
        self::assertSame(200, $form['status']);
        self::assertStringContainsString('<title>Checkout - ec-hub</title>', $form['body']);
        self::assertStringContainsString('<meta name="robots" content="noindex">', $form['body']);
        self::assertStringContainsString('<h1 class="checkout__title" id="checkout-title">Checkout</h1>', $form['body']);
        self::assertStringContainsString('<form class="checkout-form" method="post" action="/checkout" novalidate>', $form['body']);
        self::assertStringContainsString('name="_csrf" value="' . $csrf . '"', $form['body']);
        self::assertStringContainsString('name="name" autocomplete="name"', $form['body']);
        self::assertStringContainsString('type="email" id="checkout-email" name="email" autocomplete="email"', $form['body']);
        self::assertStringContainsString('name="address" autocomplete="street-address"', $form['body']);
        self::assertStringContainsString('maxlength="120"', $form['body']);
        self::assertStringContainsString('maxlength="254"', $form['body']);
        self::assertStringContainsString('maxlength="500"', $form['body']);
        self::assertStringContainsString('for="checkout-name"', $form['body']);
        self::assertStringContainsString('Mouse Gamer RGB × 2', $form['body']);
        self::assertStringContainsString('Total: <strong>R$ 299,80</strong>', $form['body']);
        self::assertStringContainsString('Pagamento simulado: nenhuma cobrança é feita.', $form['body']);
        self::assertStringContainsString('Confirmar pedido', $form['body']);
        self::assertStringContainsString('href="/cart">Voltar ao carrinho</a>', $form['body']);
        self::assertStringNotContainsString('aria-invalid', $form['body']);

        // Invalid input: 422, errors per field, values kept, cart intact.
        $invalid = $this->request('POST', '/checkout', ['_csrf' => $csrf, 'name' => '', 'email' => 'ana@', 'address' => 'Rua <b>']);
        self::assertSame(422, $invalid['status']);
        self::assertSame(3, substr_count($invalid['body'], 'aria-invalid="true"'));
        self::assertStringContainsString('aria-describedby="checkout-name-error"', $invalid['body']);
        self::assertStringContainsString('id="checkout-email-error"', $invalid['body']);
        self::assertStringContainsString('Informe seu nome.', $invalid['body']);
        self::assertStringContainsString('value="ana@"', $invalid['body']);
        self::assertStringContainsString('>Rua &lt;b&gt;</textarea>', $invalid['body']);
        self::assertStringContainsString('Total: <strong>R$ 299,80</strong>', $invalid['body']);
        self::assertSame([], $this->orders->all());
        self::assertSame(['1' => 2], $this->sessions->get($this->sessionId(), Cart::SESSION_FIELD));

        // Header injection through the email is refused.
        $injected = $this->request('POST', '/checkout', ['_csrf' => $csrf, 'email' => "ana@example.com\r\nBcc: x@example.com"] + self::VALID_FORM);
        self::assertSame(422, $injected['status']);
        self::assertSame([], $this->orders->all());

        // Success: 303, the order saved, the email written, the cart emptied.
        $placed = $this->request('POST', '/checkout', ['_csrf' => $csrf, 'name' => '<script>alert(1)</script>'] + self::VALID_FORM);
        self::assertSame(303, $placed['status']);
        self::assertCount(1, $this->orders->all());
        $order = $this->orders->all()[0];
        self::assertSame('completed', $order->status());
        self::assertSame(29980, $order->totalCents());
        self::assertCount(1, $order->items());
        self::assertSame(1, preg_match('/^EC-[0-9A-F]{10}$/', $order->orderNumber()));
        self::assertSame([], $this->sessions->get($this->sessionId(), Cart::SESSION_FIELD));
        $eml = $this->mailDirectory . '/' . $order->orderNumber() . '.eml';
        self::assertFileExists($eml);
        self::assertStringContainsString('Olá, <script>alert(1)</script>!', (string) file_get_contents($eml));

        // The confirmation page.
        $confirmation = $this->request('GET', '/checkout/confirmation');
        self::assertSame(200, $confirmation['status']);
        self::assertStringContainsString('<title>Pedido confirmado - ec-hub</title>', $confirmation['body']);
        self::assertStringContainsString('<meta name="robots" content="noindex">', $confirmation['body']);
        self::assertStringContainsString('Pedido confirmado</h1>', $confirmation['body']);
        self::assertStringContainsString('<strong data-order-number>' . $order->orderNumber() . '</strong>', $confirmation['body']);
        self::assertStringContainsString('Status: Concluído', $confirmation['body']);
        self::assertStringContainsString('Mouse Gamer RGB × 2', $confirmation['body']);
        self::assertStringContainsString('Total: <strong>R$ 299,80</strong>', $confirmation['body']);
        self::assertStringContainsString('Rua das Flores, 123<br />' . "\n" . 'São Paulo - SP', $confirmation['body']);
        self::assertStringContainsString('Enviamos a confirmação para ana@example.com (email simulado: nenhum email real é enviado).', $confirmation['body']);
        self::assertStringNotContainsString('<script>alert(1)</script>', $confirmation['body']);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $confirmation['body']);
        self::assertStringContainsString('href="/products">Continuar comprando</a>', $confirmation['body']);
        self::assertStringContainsString('Carrinho: 0 itens', $confirmation['body']);

        // Double submit: the cart is already empty, one order only.
        self::assertSame(303, $this->request('POST', '/checkout', ['_csrf' => $csrf] + self::VALID_FORM)['status']);
        self::assertCount(1, $this->orders->all());

        // GET /checkout with an empty cart goes back to /cart.
        $emptyForm = $this->request('GET', '/checkout');
        self::assertSame(303, $emptyForm['status']);
        self::assertSame('', $emptyForm['body']);
    }

    #[RunInSeparateProcess]
    public function testCheckoutWithCentsAndADeletedProduct(): void
    {
        $this->setUpCatalog();
        $csrf = $this->openSessionAndAdd(['5' => '3', '6' => '1', '3' => '1']);
        $this->products->delete(3);

        self::assertSame(303, $this->request('POST', '/checkout', ['_csrf' => $csrf] + self::VALID_FORM)['status']);
        $order = $this->orders->all()[0];
        self::assertSame(5980, $order->totalCents());
        self::assertSame([5, 6], array_map(static fn ($item) => $item->productId(), $order->items()));
        self::assertStringContainsString('Total: <strong>R$ 59,80</strong>', $this->request('GET', '/checkout/confirmation')['body']);
    }

    #[RunInSeparateProcess]
    public function testOnlyDeletedProductsMeansAnEmptyCart(): void
    {
        $this->setUpCatalog();
        $csrf = $this->openSessionAndAdd(['3' => '1']);
        $this->products->delete(3);

        self::assertSame(303, $this->request('GET', '/checkout')['status']);
        self::assertSame(303, $this->request('POST', '/checkout', ['_csrf' => $csrf] + self::VALID_FORM)['status']);
        self::assertSame([], $this->orders->all());
    }

    #[RunInSeparateProcess]
    public function testWithoutSessionNothingHappensAndNoCookieIsIssued(): void
    {
        $this->setUpCatalog();
        $token = (new SessionCsrf(self::SECRET))->token(str_repeat('e', 64));

        self::assertSame(303, $this->request('GET', '/checkout')['status']);
        self::assertSame(403, $this->request('POST', '/checkout', ['_csrf' => $token] + self::VALID_FORM)['status']);
        self::assertSame(404, $this->request('GET', '/checkout/confirmation')['status']);
        self::assertSame([], $this->issuedCookies);
        self::assertSame([], $this->orders->all());
    }

    #[RunInSeparateProcess]
    public function testCsrfIsRequired(): void
    {
        $this->setUpCatalog();
        $this->openSessionAndAdd(['1' => '1']);

        foreach ([[], ['_csrf' => 'forjado'], ['_csrf' => str_repeat('a', 64)]] as $csrf) {
            $forbidden = $this->request('POST', '/checkout', $csrf + self::VALID_FORM);
            self::assertSame(403, $forbidden['status']);
            self::assertStringContainsString('href="/cart"', $forbidden['body']);
            self::assertStringContainsString('Voltar ao carrinho', $forbidden['body']);
        }
        self::assertSame([], $this->orders->all());
        self::assertSame(['1' => 1], $this->sessions->get($this->sessionId(), Cart::SESSION_FIELD));
    }

    #[RunInSeparateProcess]
    public function testConfirmationWithoutAnOrderIs404(): void
    {
        $this->setUpCatalog();
        $this->openSessionAndAdd(['1' => '1']);

        self::assertSame(404, $this->request('GET', '/checkout/confirmation')['status']);

        // A number this session did not place (or that is not saved) opens nothing.
        $this->sessions->save($this->sessionId(), PlaceOrder::LAST_ORDER_FIELD, ['order_number' => 'EC-0123456789', 'email_sent' => true]);
        self::assertSame(404, $this->request('GET', '/checkout/confirmation')['status']);
        $this->sessions->save($this->sessionId(), PlaceOrder::LAST_ORDER_FIELD, ['order_number' => '../../etc', 'email_sent' => true]);
        self::assertSame(404, $this->request('GET', '/checkout/confirmation')['status']);
    }

    #[RunInSeparateProcess]
    public function testASaveFailureKeepsTheCartAndAnswers503(): void
    {
        $this->setUpCatalog();
        $csrf = $this->openSessionAndAdd(['1' => '2']);
        $this->orders->failWith = new \PDOException('MySQL fora');

        $failed = $this->request('POST', '/checkout', ['_csrf' => $csrf] + self::VALID_FORM);
        self::assertSame(503, $failed['status']);
        self::assertStringContainsString('role="alert">' . CheckoutController::PLACE_FAILED_MESSAGE, $failed['body']);
        self::assertStringContainsString('value="Ana Souza"', $failed['body']);
        self::assertStringContainsString('Total: <strong>R$ 299,80</strong>', $failed['body']);
        self::assertSame(['1' => 2], $this->sessions->get($this->sessionId(), Cart::SESSION_FIELD));
        self::assertSame(404, $this->request('GET', '/checkout/confirmation')['status']);

        // Once MySQL is back, the same cart goes through.
        $this->orders->failWith = null;
        self::assertSame(303, $this->request('POST', '/checkout', ['_csrf' => $csrf] + self::VALID_FORM)['status']);
        self::assertCount(1, $this->orders->all());
    }

    #[RunInSeparateProcess]
    public function testAMailFailureKeepsTheOrderAndWarns(): void
    {
        $this->setUpCatalog();
        $csrf = $this->openSessionAndAdd(['1' => '1']);
        // A file where the mail directory should be: the mailer cannot write.
        touch($this->mailDirectory);

        try {
            self::assertSame(303, $this->request('POST', '/checkout', ['_csrf' => $csrf] + self::VALID_FORM)['status']);
            self::assertCount(1, $this->orders->all());

            $confirmation = $this->request('GET', '/checkout/confirmation');
            self::assertSame(200, $confirmation['status']);
            self::assertStringContainsString('role="alert">Não foi possível gerar o email de confirmação simulado.', $confirmation['body']);
            self::assertStringNotContainsString('Enviamos a confirmação', $confirmation['body']);
        } finally {
            unlink($this->mailDirectory);
        }
    }

    #[RunInSeparateProcess]
    public function testTheSessionStoreFailingDegradesTo503(): void
    {
        $this->setUpCatalog();
        $csrf = $this->openSessionAndAdd(['1' => '1']);
        $this->sessions->failWith = new \RuntimeException('Redis fora');

        $form = $this->request('GET', '/checkout');
        self::assertSame(503, $form['status']);
        self::assertStringContainsString(CheckoutController::LOAD_FAILED_MESSAGE, $form['body']);
        self::assertStringNotContainsString('Confirmar pedido', $form['body']);

        $placed = $this->request('POST', '/checkout', ['_csrf' => $csrf] + self::VALID_FORM);
        self::assertSame(503, $placed['status']);
        self::assertStringContainsString(CheckoutController::PLACE_FAILED_MESSAGE, $placed['body']);

        $confirmation = $this->request('GET', '/checkout/confirmation');
        self::assertSame(503, $confirmation['status']);
        self::assertStringContainsString(CheckoutController::CONFIRMATION_FAILED_MESSAGE, $confirmation['body']);
        self::assertStringContainsString('<h1 class="order-confirmation__title" id="order-confirmation-title">Confirmação do pedido</h1>', $confirmation['body']);
        self::assertStringNotContainsString('Pedido confirmado', $confirmation['body']);
        self::assertSame([], $this->orders->all());
    }

    #[RunInSeparateProcess]
    public function testTheOrderLookupFailingDegradesTheConfirmationTo503(): void
    {
        $this->setUpCatalog();
        $csrf = $this->openSessionAndAdd(['1' => '1']);
        self::assertSame(303, $this->request('POST', '/checkout', ['_csrf' => $csrf] + self::VALID_FORM)['status']);
        self::assertCount(1, $this->orders->all());
        $this->orders->findFailWith = new \PDOException('MySQL fora');

        $confirmation = $this->request('GET', '/checkout/confirmation');
        self::assertSame(503, $confirmation['status']);
        self::assertStringContainsString('role="alert">' . CheckoutController::CONFIRMATION_FAILED_MESSAGE, $confirmation['body']);
        self::assertStringContainsString('<title>Confirmação do pedido - ec-hub</title>', $confirmation['body']);
        self::assertStringContainsString('<h1 class="order-confirmation__title" id="order-confirmation-title">Confirmação do pedido</h1>', $confirmation['body']);
        self::assertStringNotContainsString('Pedido confirmado', $confirmation['body']);
        self::assertStringNotContainsString('data-order-number', $confirmation['body']);

        // Once MySQL is back, the same session sees its order.
        $this->orders->findFailWith = null;
        $recovered = $this->request('GET', '/checkout/confirmation');
        self::assertSame(200, $recovered['status']);
        self::assertStringContainsString('Pedido confirmado</h1>', $recovered['body']);
    }

    #[RunInSeparateProcess]
    public function testCartRoutesKeepTheirContract(): void
    {
        $this->setUpCatalog();
        $this->request('GET', '/products/mouse-gamer-rgb');

        $api = $this->request('POST', '/api/cart/items', [], '{"product_id":1,"quantity":2}');
        self::assertSame(200, $api['status']);
        self::assertSame(2, json_decode($api['body'], true)['data']['cart_item_count']);
        self::assertSame(200, $this->request('GET', '/cart')['status']);
    }

    private function setUpCatalog(): void
    {
        ini_set('error_log', '/dev/null');
        $this->products = new InMemoryProductRepository([
            ['id' => 1, 'name' => 'Mouse Gamer RGB', 'slug' => 'mouse-gamer-rgb', 'description' => 'Mouse.', 'price' => 149.90, 'category' => 'Periféricos', 'image_url' => '/assets/images/mouse.jpg'],
            ['id' => 3, 'name' => 'Mousepad XL', 'slug' => 'mousepad-xl', 'description' => 'Mousepad.', 'price' => 89.90, 'category' => 'Periféricos', 'image_url' => null],
            ['id' => 5, 'name' => 'Caneca', 'slug' => 'caneca', 'description' => 'Caneca.', 'price' => 19.90, 'category' => 'Casa', 'image_url' => null],
            ['id' => 6, 'name' => 'Adesivo', 'slug' => 'adesivo', 'description' => 'Adesivo.', 'price' => 0.10, 'category' => 'Casa', 'image_url' => null],
        ]);
        $this->sessions = new InMemorySessionRepository();
        $this->orders = new InMemoryOrderRepository();
        $this->mailDirectory = sys_get_temp_dir() . '/ec-hub-checkout-mail-' . bin2hex(random_bytes(6));
    }

    /**
     * Opens a session on the product page and adds through the no-JS form.
     *
     * @param array<string, string> $quantities product id => quantity
     * @return string the session CSRF token
     */
    private function openSessionAndAdd(array $quantities): string
    {
        $detail = $this->request('GET', '/products/mouse-gamer-rgb');
        self::assertSame(1, preg_match('/data-add-cart-form>.*?name="_csrf" value="([a-f0-9]{64})"/s', $detail['body'], $match));
        foreach ($quantities as $productId => $quantity) {
            self::assertSame(303, $this->request('POST', '/cart/items', ['_csrf' => $match[1], 'product_id' => (string) $productId, 'quantity' => $quantity])['status']);
        }

        return $match[1];
    }

    private function sessionId(): string
    {
        return $this->issuedCookies[SessionContext::COOKIE_NAME];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{status: int, body: string}
     */
    private function request(string $method, string $uri, array $post = [], ?string $json = null): array
    {
        $this->lastCookies = [];
        $session = new SessionContext(self::SECRET, function (string $name, string $value): bool {
            $this->issuedCookies[$name] = $value;
            $this->lastCookies[$name] = $value;

            return true;
        });
        $sessions = $this->sessions;
        $twig = AdminTestTwig::create();
        $twig->addGlobal('cart_summary', new CartSummary(
            static fn (): ?string => $session->currentId(),
            static fn () => $sessions
        ));
        $publisher = new class () implements EventPublisherInterface {
            public function publish(string $event, mixed $data): void
            {
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
        $products = $this->products;
        $orders = $this->orders;
        $tracker = new TrackProductInteraction($products, $sessions, $history, new InMemoryEventStore(), $publisher, new NullLogger());
        $csrf = new SessionCsrf(self::SECRET);
        $manageCart = new ManageCart($sessions, $products);
        $mailer = new FileOrderConfirmationMailer($this->mailDirectory, $twig);
        $GLOBALS['EC_HUB_TEST_CONTAINER'] = new Container([
            Environment::class => fn () => $twig,
            SessionContext::class => fn () => $session,
            CartController::class => fn () => new CartController($manageCart, $tracker, $session, $csrf, $twig),
            CheckoutController::class => fn () => new CheckoutController(
                new PlaceOrder($sessions, $products, $orders, $mailer, new NullLogger()),
                $manageCart,
                $orders,
                $session,
                $csrf,
                $twig
            ),
            ProductInteractionController::class => fn () => new ProductInteractionController($tracker, $session),
            ProductController::class => fn () => new ProductController(
                new GetProductList($products, new CategoryService($products)),
                new GetProductDetail($products),
                $twig,
                null,
                $tracker,
                $session,
                $csrf
            ),
        ]);

        $_COOKIE = [];
        if (isset($this->issuedCookies[SessionContext::COOKIE_NAME])) {
            $_COOKIE[SessionContext::COOKIE_NAME] = $this->issuedCookies[SessionContext::COOKIE_NAME];
            $_COOKIE[SessionContext::SIGNATURE_COOKIE_NAME] = $this->issuedCookies[SessionContext::SIGNATURE_COOKIE_NAME];
        }
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        $_GET = [];
        $_POST = $post;
        unset($GLOBALS['EC_HUB_TEST_JSON_BODY']);
        if ($json !== null) {
            $GLOBALS['EC_HUB_TEST_JSON_BODY'] = $json;
        }
        http_response_code(200);

        ob_start();
        require dirname(__DIR__, 3) . '/public/index.php';
        $body = (string) ob_get_clean();

        return ['status' => (int) http_response_code(), 'body' => $body];
    }
}
