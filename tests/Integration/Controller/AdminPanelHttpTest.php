<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Application\Product\GetProductDetail;
use App\Application\Product\GetProductList;
use App\Application\Product\ManageProducts;
use App\Controller\Admin\AdminAuthController;
use App\Controller\Admin\AdminProductController;
use App\Controller\ProductController;
use App\Domain\Product\Service\CategoryService;
use App\Domain\Recommendation\Service\KNNService;
use App\Infrastructure\ML\RubixNeighborFinder;
use App\Shared\Container\Container;
use App\Shared\Http\AdminAuth;
use App\Shared\Http\AdminCredentials;
use App\Shared\Http\SessionContext;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestTwig;
use Tests\Support\InMemoryProductRepository;
use Twig\Environment;

/** Story 8.3: the admin journey through public/index.php (routing + dispatch). */
final class AdminPanelHttpTest extends TestCase
{
    private const SECRET = 'phpunit-only-session-cookie-secret-32';
    private const SESSION_ID = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    private InMemoryProductRepository $repository;

    private string $passwordHash;

    /** @var array<string, string> */
    private array $issuedCookies = [];

    #[RunInSeparateProcess]
    public function testFullAdminJourney(): void
    {
        $this->repository = new InMemoryProductRepository([
            ['id' => 1, 'name' => 'Mouse Gamer RGB', 'slug' => 'mouse-gamer-rgb', 'description' => 'Mouse.', 'price' => 149.90, 'category' => 'Periféricos', 'image_url' => null],
            ['id' => 2, 'name' => 'Teclado Mecânico', 'slug' => 'teclado-mecanico', 'description' => 'Teclado.', 'price' => 299.90, 'category' => 'Periféricos', 'image_url' => null],
            ['id' => 3, 'name' => 'Mousepad XL', 'slug' => 'mousepad-xl', 'description' => 'Mousepad.', 'price' => 89.90, 'category' => 'Periféricos', 'image_url' => null],
        ]);
        $this->passwordHash = password_hash('s3nha-forte', PASSWORD_DEFAULT);
        ini_set('error_log', '/dev/null'); // ProductController logs the expected 404

        // Without a session: every product route goes to the login.
        self::assertSame(303, $this->request('GET', '/admin/products')['status']);
        self::assertSame(303, $this->request('POST', '/admin/products/1/delete')['status']);
        self::assertNotNull($this->repository->findById(1));

        // Login form, then a login with the wrong password and one with a forged CSRF.
        $form = $this->request('GET', '/admin/login');
        self::assertSame(200, $form['status']);
        self::assertSame(1, preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $form['body'], $match));
        $loginCsrf = $match[1];

        $wrong = $this->request('POST', '/admin/login', ['_csrf' => $loginCsrf, 'username' => 'admin', 'password' => 'x']);
        self::assertSame(401, $wrong['status']);
        self::assertStringContainsString('Usuário ou senha inválidos.', $wrong['body']);
        self::assertSame(403, $this->request('POST', '/admin/login', ['_csrf' => 'forjado', 'username' => 'admin', 'password' => 's3nha-forte'])['status']);
        self::assertArrayNotHasKey(AdminAuth::COOKIE_NAME, $this->issuedCookies);

        $login = $this->request('POST', '/admin/login', ['_csrf' => $loginCsrf, 'username' => 'admin', 'password' => 's3nha-forte']);
        self::assertSame(303, $login['status']);
        self::assertArrayHasKey(AdminAuth::COOKIE_NAME, $this->issuedCookies);

        // Listing with the cookie.
        $list = $this->request('GET', '/admin/products');
        self::assertSame(200, $list['status']);
        self::assertStringContainsString('Mouse Gamer RGB', $list['body']);
        self::assertStringContainsString('Novo produto', $list['body']);
        self::assertSame(1, preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $list['body'], $match));
        $csrf = $match[1];

        // CSRF is enforced on every admin POST.
        self::assertSame(403, $this->request('POST', '/admin/products', ['name' => 'X', 'price' => '1', 'category' => 'Y'])['status']);
        self::assertSame(403, $this->request('POST', '/admin/products/1/delete', ['_csrf' => 'forjado'])['status']);
        self::assertSame(3, $this->repository->count());

        // Create (invalid, then valid) -> visible in the public listing.
        $invalid = $this->request('POST', '/admin/products', ['_csrf' => $csrf, 'name' => '', 'price' => 'abc', 'category' => 'Áudio']);
        self::assertSame(422, $invalid['status']);
        $created = $this->request('POST', '/admin/products', [
            '_csrf' => $csrf, 'name' => 'Headset USB', 'description' => 'Com microfone.',
            'price' => '1234,50', 'category' => 'Periféricos', 'image_url' => '/assets/images/headset.jpg',
        ]);
        self::assertSame(303, $created['status']);
        self::assertStringContainsString('Produto criado com sucesso.', $this->request('GET', '/admin/products', [], ['status' => 'created'])['body']);
        self::assertStringContainsString('Headset USB', $this->request('GET', '/products')['body']);

        // Edit -> the public detail page shows the change, the slug is kept.
        self::assertSame(200, $this->request('GET', '/admin/products/1/edit')['status']);
        $updated = $this->request('POST', '/admin/products/1', [
            '_csrf' => $csrf, 'name' => 'Mouse Gamer Pro', 'description' => 'Novo sensor.',
            'price' => '199.90', 'category' => 'Periféricos', 'image_url' => '',
        ]);
        self::assertSame(303, $updated['status']);
        $detail = $this->request('GET', '/products/mouse-gamer-rgb');
        self::assertSame(200, $detail['status']);
        self::assertStringContainsString('Mouse Gamer Pro', $detail['body']);
        self::assertStringContainsString('Novo sensor.', $detail['body']);

        // Delete -> gone from the public listing, detail (404) and recommendations.
        $deleted = $this->request('POST', '/admin/products/3/delete', ['_csrf' => $csrf]);
        self::assertSame(303, $deleted['status']);
        self::assertNotNull($this->repository->rawRow(3)['deleted_at']);
        self::assertStringNotContainsString('Mousepad XL', $this->request('GET', '/products')['body']);
        self::assertSame(404, $this->request('GET', '/products/mousepad-xl')['status']);
        $knn = new KNNService($this->repository, new RubixNeighborFinder());
        $mouse = $this->repository->findById(1);
        self::assertNotNull($mouse);
        $recommendedIds = array_map(static fn ($r): int => $r->getProductId(), $knn->recommend($mouse, 10));
        self::assertNotContains(3, $recommendedIds);
        self::assertContains(2, $recommendedIds);

        // Deleted or unknown ids are 404.
        self::assertSame(404, $this->request('GET', '/admin/products/3/edit')['status']);
        self::assertSame(404, $this->request('POST', '/admin/products/3/delete', ['_csrf' => $csrf])['status']);
        self::assertSame(404, $this->request('POST', '/admin/products/999', ['_csrf' => $csrf, 'name' => 'X', 'price' => '1', 'category' => 'Y'])['status']);

        // Logout clears the cookie; the panel is closed again.
        self::assertSame(303, $this->request('POST', '/admin/logout', ['_csrf' => $csrf])['status']);
        self::assertSame('', $this->issuedCookies[AdminAuth::COOKIE_NAME]);
        self::assertSame(303, $this->request('GET', '/admin/products')['status']);
    }

    #[RunInSeparateProcess]
    public function testDisabledAdminLeavesTheRestOfTheSiteUp(): void
    {
        $this->repository = new InMemoryProductRepository();
        $this->passwordHash = '';

        $form = $this->request('GET', '/admin/login');
        self::assertStringContainsString(AdminAuthController::DISABLED_MESSAGE, $form['body']);
        self::assertSame(1, preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $form['body'], $match));

        $login = $this->request('POST', '/admin/login', ['_csrf' => $match[1], 'username' => 'admin', 'password' => '']);
        self::assertSame(401, $login['status']);
        self::assertStringContainsString(AdminAuthController::DISABLED_MESSAGE, $login['body']);
        self::assertSame(200, $this->request('GET', '/products')['status']);
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $query
     * @return array{status: int, body: string}
     */
    private function request(string $method, string $uri, array $post = [], array $query = []): array
    {
        $twig = AdminTestTwig::create();
        $repository = $this->repository;
        $auth = new AdminAuth(
            AdminCredentials::fromArray(['username' => 'admin', 'password_hash' => $this->passwordHash]),
            self::SECRET,
            function (string $name, string $value): bool {
                $this->issuedCookies[$name] = $value;

                return true;
            }
        );
        $session = new SessionContext(self::SECRET, static fn (): bool => true);
        $GLOBALS['EC_HUB_TEST_CONTAINER'] = new Container([
            Environment::class => fn () => $twig,
            SessionContext::class => fn () => $session,
            AdminAuthController::class => fn () => new AdminAuthController($auth, $session, $twig),
            AdminProductController::class => fn () => new AdminProductController(new ManageProducts($repository), $auth, $twig),
            ProductController::class => fn () => new ProductController(
                new GetProductList($repository, new CategoryService($repository)),
                new GetProductDetail($repository),
                $twig
            ),
        ]);

        $_COOKIE = [
            SessionContext::COOKIE_NAME => self::SESSION_ID,
            SessionContext::SIGNATURE_COOKIE_NAME => hash_hmac('sha256', self::SESSION_ID, self::SECRET),
        ];
        if (($this->issuedCookies[AdminAuth::COOKIE_NAME] ?? '') !== '') {
            $_COOKIE[AdminAuth::COOKIE_NAME] = $this->issuedCookies[AdminAuth::COOKIE_NAME];
        }
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri . ($query === [] ? '' : '?' . http_build_query($query));
        $_GET = $query;
        $_POST = $post;
        http_response_code(200);

        ob_start();
        require dirname(__DIR__, 3) . '/public/index.php';
        $body = (string) ob_get_clean();

        return ['status' => (int) http_response_code(), 'body' => $body];
    }
}
