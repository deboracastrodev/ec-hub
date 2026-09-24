<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Application\Cart\CartSummary;
use App\Application\Cart\ManageCart;
use App\Application\Event\TrackProductInteraction;
use App\Application\Product\GetProductDetail;
use App\Application\Product\GetProductList;
use App\Controller\CartController;
use App\Controller\ProductController;
use App\Controller\ProductInteractionController;
use App\Domain\Cart\Model\Cart;
use App\Domain\Event\EventHistoryRepositoryInterface;
use App\Domain\Event\EventPublisherInterface;
use App\Domain\Product\Service\CategoryService;
use App\Shared\Container\Container;
use App\Shared\Http\SessionContext;
use App\Shared\Http\SessionCsrf;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\AdminTestTwig;
use Tests\Support\InMemoryEventStore;
use Tests\Support\InMemoryProductRepository;
use Tests\Support\InMemorySessionRepository;
use Twig\Environment;

/** Story 8.6: the shopping cart journey through public/index.php (routing + dispatch). */
final class CartHttpTest extends TestCase
{
    private const SECRET = 'phpunit-only-session-cookie-secret-32';

    private InMemoryProductRepository $products;

    private InMemorySessionRepository $sessions;

    /** @var list<array{string, array<string, mixed>}> */
    private array $published = [];

    /** @var array<string, string> cookies issued by the "server" in the whole journey */
    private array $issuedCookies = [];

    /** @var array<string, string> cookies issued by the last request */
    private array $lastCookies = [];

    #[RunInSeparateProcess]
    public function testFullCartJourney(): void
    {
        $this->setUpCatalog();

        // No cookie: an empty cart, and no session is opened.
        $empty = $this->request('GET', '/cart');
        self::assertSame(200, $empty['status']);
        self::assertStringContainsString('Seu carrinho está vazio.', $empty['body']);
        self::assertStringContainsString('<title>Carrinho - ec-hub</title>', $empty['body']);
        self::assertStringContainsString('<meta name="robots" content="noindex">', $empty['body']);
        self::assertStringContainsString('href="/products"', $empty['body']);
        self::assertStringContainsString('Carrinho: 0 itens', $empty['body']);
        self::assertSame([], $this->lastCookies);

        // Product page: opens the session and renders the no-JS form.
        $detail = $this->request('GET', '/products/mouse-gamer-rgb');
        self::assertSame(200, $detail['status']);
        self::assertArrayHasKey(SessionContext::COOKIE_NAME, $this->issuedCookies);
        self::assertStringContainsString('data-add-cart-form', $detail['body']);
        self::assertStringContainsString('data-add-cart="1"', $detail['body']);
        self::assertStringContainsString('Adicionar ao carrinho', $detail['body']);
        self::assertSame(1, preg_match('/data-add-cart-form>.*?name="_csrf" value="([a-f0-9]{64})"/s', $detail['body'], $match));
        $csrf = $match[1];
        $this->published = [];

        // Add (form, without JS): 303, one cart.item_added, header counter.
        $added = $this->request('POST', '/cart/items', ['_csrf' => $csrf, 'product_id' => '1', 'quantity' => '1']);
        self::assertSame(303, $added['status']);
        self::assertSame(['cart.item_added'], array_column($this->published, 0));
        $cart = $this->request('GET', '/cart', [], ['status' => 'added']);
        self::assertSame(200, $cart['status']);
        self::assertStringContainsString('Produto adicionado ao carrinho.', $cart['body']);
        self::assertStringContainsString('href="/products/mouse-gamer-rgb"', $cart['body']);
        self::assertStringContainsString('Preço unitário: R$ 149,90', $cart['body']);
        self::assertStringContainsString('Subtotal: <strong>R$ 149,90</strong>', $cart['body']);
        self::assertStringContainsString('Total: <strong>R$ 149,90</strong>', $cart['body']);
        self::assertStringContainsString('Carrinho: 1 item<', $cart['body']);
        self::assertStringContainsString('min="0" max="99"', $cart['body']);
        self::assertSame(1, preg_match('/action="\/cart\/items\/1".*?name="_csrf" value="([a-f0-9]{64})"/s', $cart['body'], $match));
        self::assertSame($csrf, $match[1]);

        // Unknown status values are ignored.
        self::assertStringNotContainsString('role="status"', $this->request('GET', '/cart', [], ['status' => '<b>x</b>'])['body']);

        // Add again through the JSON API (the JS path): same event, count in the response.
        $this->published = [];
        $api = $this->request('POST', '/api/cart/items', [], [], '{"product_id":1,"quantity":1}');
        self::assertSame(200, $api['status']);
        $decoded = json_decode($api['body'], true);
        self::assertSame(2, $decoded['data']['cart_item_count']);
        self::assertArrayNotHasKey('session_id', $decoded['data']);
        self::assertSame(['cart.item_added'], array_column($this->published, 0));
        self::assertArrayNotHasKey('cart_item_count', $this->published[0][1]);
        self::assertStringContainsString('Carrinho: 2 itens', $this->request('GET', '/products')['body']);
        self::assertSame(['1' => 2], $this->sessions->get($this->sessionId(), Cart::SESSION_FIELD));

        // CSRF on every cart POST: nothing changes, nothing is published.
        $this->published = [];
        self::assertSame(403, $this->request('POST', '/cart/items', ['product_id' => '1'])['status']);
        $forbidden = $this->request('POST', '/cart/items/1', ['_csrf' => 'forjado', 'quantity' => '5']);
        self::assertSame(403, $forbidden['status']);
        self::assertStringContainsString('href="/cart"', $forbidden['body']);
        self::assertStringContainsString('Voltar ao carrinho', $forbidden['body']);
        self::assertSame(403, $this->request('POST', '/cart/items/1/delete', ['_csrf' => 'forjado'])['status']);
        self::assertSame([], $this->published);
        self::assertSame(['1' => 2], $this->sessions->get($this->sessionId(), Cart::SESSION_FIELD));

        // Invalid add input and unknown product.
        self::assertSame(400, $this->request('POST', '/cart/items', ['_csrf' => $csrf, 'product_id' => 'abc'])['status']);
        self::assertSame(400, $this->request('POST', '/cart/items', ['_csrf' => $csrf, 'product_id' => '1', 'quantity' => '100'])['status']);
        self::assertSame(400, $this->request('POST', '/cart/items', ['_csrf' => $csrf, 'product_id' => '1', 'quantity' => '0'])['status']);
        self::assertSame(404, $this->request('POST', '/cart/items', ['_csrf' => $csrf, 'product_id' => '999'])['status']);
        self::assertSame([], $this->published);
        self::assertSame(['1' => 2], $this->sessions->get($this->sessionId(), Cart::SESSION_FIELD));

        // Update the quantity.
        self::assertSame(303, $this->request('POST', '/cart/items/1', ['_csrf' => $csrf, 'quantity' => '3'])['status']);
        $updated = $this->request('GET', '/cart', [], ['status' => 'updated']);
        self::assertStringContainsString('Quantidade atualizada.', $updated['body']);
        self::assertStringContainsString('Subtotal: <strong>R$ 449,70</strong>', $updated['body']);
        self::assertStringContainsString('Carrinho: 3 itens', $updated['body']);

        // Invalid quantities: 422 with the message, the cart unchanged.
        foreach (['abc', '-1', '100', '1.5', ''] as $invalid) {
            $response = $this->request('POST', '/cart/items/1', ['_csrf' => $csrf, 'quantity' => $invalid]);
            self::assertSame(422, $response['status'], "quantity '{$invalid}'");
            self::assertStringContainsString(CartController::INVALID_QUANTITY_MESSAGE, $response['body']);
            self::assertStringContainsString('Seu carrinho', $response['body']);
        }
        self::assertSame(['1' => 3], $this->sessions->get($this->sessionId(), Cart::SESSION_FIELD));

        // Updating a product that is not in the cart.
        self::assertSame(404, $this->request('POST', '/cart/items/42', ['_csrf' => $csrf, 'quantity' => '1'])['status']);
        self::assertSame(404, $this->request('POST', '/cart/items/2', ['_csrf' => $csrf, 'quantity' => '1'])['status']);

        // Quantity 0 removes the line.
        self::assertSame(303, $this->request('POST', '/cart/items', ['_csrf' => $csrf, 'product_id' => '2'])['status']);
        self::assertSame(303, $this->request('POST', '/cart/items/2', ['_csrf' => $csrf, 'quantity' => '0'])['status']);
        self::assertSame(['1' => 3], $this->sessions->get($this->sessionId(), Cart::SESSION_FIELD));

        // A soft-deleted product leaves the page and the session; the total follows.
        self::assertSame(303, $this->request('POST', '/cart/items', ['_csrf' => $csrf, 'product_id' => '3', 'quantity' => '2'])['status']);
        $this->products->delete(3);
        $pruned = $this->request('GET', '/cart');
        self::assertStringNotContainsString('Mousepad XL', $pruned['body']);
        self::assertStringContainsString('Total: <strong>R$ 449,70</strong>', $pruned['body']);
        self::assertSame(['1' => 3], $this->sessions->get($this->sessionId(), Cart::SESSION_FIELD));

        // Product names are escaped.
        self::assertSame(303, $this->request('POST', '/cart/items', ['_csrf' => $csrf, 'product_id' => '4'])['status']);
        $xss = $this->request('GET', '/cart');
        self::assertStringNotContainsString('<script>alert(1)</script>', $xss['body']);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $xss['body']);

        // Remove (idempotent) until the cart is empty.
        self::assertSame(303, $this->request('POST', '/cart/items/4/delete', ['_csrf' => $csrf])['status']);
        self::assertSame(303, $this->request('POST', '/cart/items/1/delete', ['_csrf' => $csrf])['status']);
        self::assertSame(303, $this->request('POST', '/cart/items/1/delete', ['_csrf' => $csrf])['status']);
        $final = $this->request('GET', '/cart', [], ['status' => 'removed']);
        self::assertStringContainsString('Produto removido do carrinho.', $final['body']);
        self::assertStringContainsString('Seu carrinho está vazio.', $final['body']);
        self::assertStringContainsString('Carrinho: 0 itens', $final['body']);
    }

    #[RunInSeparateProcess]
    public function testWithoutSessionTheChangesAreForbiddenAndNoCookieIsIssued(): void
    {
        $this->setUpCatalog();
        $token = (new SessionCsrf(self::SECRET))->token(str_repeat('e', 64));

        self::assertSame(403, $this->request('POST', '/cart/items/1', ['_csrf' => $token, 'quantity' => '1'])['status']);
        self::assertSame(403, $this->request('POST', '/cart/items/1/delete', ['_csrf' => $token])['status']);
        self::assertSame([], $this->issuedCookies);

        // Adding opens a session, so a token from no session never matches.
        self::assertSame(403, $this->request('POST', '/cart/items', ['_csrf' => $token, 'product_id' => '1'])['status']);
        self::assertSame([], $this->published);
    }

    #[RunInSeparateProcess]
    public function testHeaderDegradesToAPlainLinkWhenRedisIsDown(): void
    {
        $this->setUpCatalog();
        $this->request('GET', '/products/mouse-gamer-rgb');
        $this->sessions->failWith = new \RuntimeException('Redis fora');

        foreach (['/products', '/products/mouse-gamer-rgb'] as $uri) {
            $page = $this->request('GET', $uri);
            self::assertSame(200, $page['status'], $uri);
            self::assertSame(1, preg_match('/<a href="\/cart" class="header__cart" data-cart-link>Carrinho<\/a>/', $page['body']), $uri);
        }
    }

    #[RunInSeparateProcess]
    public function testCartPageAndChangesDegradeWhenTheSessionStoreFails(): void
    {
        $this->setUpCatalog();
        $detail = $this->request('GET', '/products/mouse-gamer-rgb');
        self::assertSame(1, preg_match('/data-add-cart-form>.*?name="_csrf" value="([a-f0-9]{64})"/s', $detail['body'], $match));
        $csrf = $match[1];
        self::assertSame(303, $this->request('POST', '/cart/items', ['_csrf' => $csrf, 'product_id' => '1'])['status']);
        $this->sessions->failWith = new \Exception('Connection refused');

        $page = $this->request('GET', '/cart');
        self::assertSame(503, $page['status']);
        self::assertStringContainsString(CartController::LOAD_FAILED_MESSAGE, $page['body']);
        self::assertStringContainsString('<a href="/cart" class="header__cart" data-cart-link>Carrinho</a>', $page['body']);

        self::assertSame(303, $this->request('POST', '/cart/items/1', ['_csrf' => $csrf, 'quantity' => '2'])['status']);
        self::assertSame(303, $this->request('POST', '/cart/items/1/delete', ['_csrf' => $csrf])['status']);

        // The add itself degrades to add_failed (the event is still published).
        self::assertSame(303, $this->request('POST', '/cart/items', ['_csrf' => $csrf, 'product_id' => '1'])['status']);

        $this->sessions->failWith = null;
        $failed = $this->request('GET', '/cart', [], ['status' => 'add_failed']);
        self::assertSame(200, $failed['status']);
        self::assertStringContainsString('role="alert">' . CartController::ERROR_STATUS_MESSAGES['add_failed'], $failed['body']);
        self::assertSame(['1' => 1], $this->sessions->get($this->sessionId(), Cart::SESSION_FIELD));
    }

    private function setUpCatalog(): void
    {
        ini_set('error_log', '/dev/null');
        $this->products = new InMemoryProductRepository([
            ['id' => 1, 'name' => 'Mouse Gamer RGB', 'slug' => 'mouse-gamer-rgb', 'description' => 'Mouse.', 'price' => 149.90, 'category' => 'Periféricos', 'image_url' => '/assets/images/mouse.jpg'],
            ['id' => 2, 'name' => 'Teclado Mecânico', 'slug' => 'teclado-mecanico', 'description' => 'Teclado.', 'price' => 299.90, 'category' => 'Periféricos', 'image_url' => null],
            ['id' => 3, 'name' => 'Mousepad XL', 'slug' => 'mousepad-xl', 'description' => 'Mousepad.', 'price' => 89.90, 'category' => 'Periféricos', 'image_url' => null],
            ['id' => 4, 'name' => '<script>alert(1)</script>', 'slug' => 'xss', 'description' => 'x', 'price' => 1.00, 'category' => 'Periféricos', 'image_url' => null],
        ]);
        $this->sessions = new InMemorySessionRepository();
    }

    private function sessionId(): string
    {
        return $this->issuedCookies[SessionContext::COOKIE_NAME];
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $query
     * @return array{status: int, body: string}
     */
    private function request(string $method, string $uri, array $post = [], array $query = [], ?string $json = null): array
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
        $publisher = new class (function (string $event, mixed $data): void {
            $this->published[] = [$event, (array) $data];
        }) implements EventPublisherInterface {

            public function __construct(private readonly \Closure $record)
            {
            }

            public function publish(string $event, mixed $data): void
            {
                ($this->record)($event, $data);
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
        $tracker = new TrackProductInteraction($this->products, $sessions, $history, new InMemoryEventStore(), $publisher, new NullLogger());
        $csrf = new SessionCsrf(self::SECRET);
        $products = $this->products;
        $GLOBALS['EC_HUB_TEST_CONTAINER'] = new Container([
            Environment::class => fn () => $twig,
            SessionContext::class => fn () => $session,
            CartController::class => fn () => new CartController(new ManageCart($sessions, $products), $tracker, $session, $csrf, $twig),
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
        $_SERVER['REQUEST_URI'] = $uri . ($query === [] ? '' : '?' . http_build_query($query));
        $_GET = $query;
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
