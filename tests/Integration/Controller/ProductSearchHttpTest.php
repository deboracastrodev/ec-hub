<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Application\Product\GetProductDetail;
use App\Application\Product\GetProductList;
use App\Controller\ProductController;
use App\Domain\Product\Service\CategoryService;
use App\Shared\Container\Container;
use App\Shared\Http\SessionContext;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestTwig;
use Tests\Support\InMemoryProductRepository;
use Twig\Environment;

/** Story 8.5 (FR109): the product search through public/index.php. */
final class ProductSearchHttpTest extends TestCase
{
    private const SECRET = 'phpunit-only-session-cookie-secret-32';
    private const SESSION_ID = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

    private InMemoryProductRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryProductRepository([
            ['id' => 1, 'name' => 'Mouse', 'slug' => 'mouse', 'description' => 'Mouse óptico.', 'price' => 49.90, 'category' => 'Periféricos', 'image_url' => null],
            ['id' => 2, 'name' => 'Caixa Bluetooth', 'slug' => 'caixa-bluetooth', 'description' => 'Caixa de som.', 'price' => 199.90, 'category' => 'Áudio', 'image_url' => null],
            ['id' => 3, 'name' => 'Fone de Ouvido', 'slug' => 'fone-de-ouvido', 'description' => 'Com fio.', 'price' => 89.90, 'category' => 'Áudio', 'image_url' => null],
            ['id' => 4, 'name' => 'Fone Bluetooth X', 'slug' => 'fone-bluetooth-x', 'description' => 'Sem fio.', 'price' => 299.90, 'category' => 'Áudio', 'image_url' => null],
        ]);
    }

    #[RunInSeparateProcess]
    public function testSearchListsOredTermsByRelevance(): void
    {
        $page = $this->request('/products', ['q' => 'fone bluetooth']);

        self::assertSame(200, $page['status']);
        $body = $page['body'];
        self::assertStringContainsString('<meta name="robots" content="noindex">', $body);
        self::assertStringContainsString('<title>Busca: fone bluetooth - ec-hub</title>', $body);
        self::assertStringContainsString('Resultados para “fone bluetooth”', $body);
        self::assertMatchesRegularExpression('#<p class="product-listing__count" role="status">\s*3 resultados encontrados\s*</p>#', $body);
        self::assertStringContainsString('value="fone bluetooth"', $body);
        self::assertStringNotContainsString('>Mouse<', $body);

        $first = strpos($body, 'Fone Bluetooth X</h3>');
        $second = strpos($body, 'Caixa Bluetooth</h3>');
        $third = strpos($body, 'Fone de Ouvido</h3>');
        self::assertNotFalse($first);
        self::assertNotFalse($second);
        self::assertNotFalse($third);
        self::assertLessThan($second, $first);
        self::assertLessThan($third, $first);
        self::assertLessThan($third, $second); // 3-point tie broken by name: Caixa < Fone

        // Category links and "Todos os produtos" keep the query.
        self::assertStringContainsString('href="/products?q=fone%20bluetooth"', $body);
        self::assertStringContainsString('href="?category=%C3%81udio&amp;q=fone%20bluetooth"', $body);
    }

    #[RunInSeparateProcess]
    public function testSingularAndEmptyStates(): void
    {
        self::assertMatchesRegularExpression('#1 resultado encontrado\s*</p>#', $this->request('/products', ['q' => 'mouse'])['body']);

        $none = $this->request('/products', ['q' => 'xyzzy']);
        self::assertSame(200, $none['status']);
        self::assertStringContainsString('0 resultados encontrados', $none['body']);
        self::assertStringContainsString('Nenhum produto encontrado para “xyzzy”.', $none['body']);
        self::assertStringContainsString('href="/products" class="product-listing__empty-link"', $none['body']);
    }

    #[RunInSeparateProcess]
    public function testQueryIsEscapedEverywhere(): void
    {
        $body = $this->request('/products', ['q' => '<script>alert(1)</script>'])['body'];

        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
        self::assertStringContainsString('Resultados para “&lt;script&gt;alert(1)&lt;/script&gt;”', $body);
        self::assertStringContainsString('<title>Busca: &lt;script&gt;alert(1)&lt;/script&gt; - ec-hub</title>', $body);
        self::assertStringContainsString('value="&lt;script&gt;alert(1)&lt;/script&gt;"', $body);
    }

    #[RunInSeparateProcess]
    public function testListingWithoutQHasTheHeaderFormAndNoNoindex(): void
    {
        $page = $this->request('/products');

        self::assertSame(200, $page['status']);
        $body = $page['body'];
        self::assertStringNotContainsString('noindex', $body);
        self::assertStringNotContainsString('Resultados para', $body);
        self::assertStringContainsString('<form class="search-form" role="search" action="/products" method="get">', $body);
        self::assertStringContainsString('<label for="search-q" class="visually-hidden">Buscar produtos</label>', $body);
        self::assertMatchesRegularExpression('#<input type="search" id="search-q" name="q" class="search-form__input" maxlength="100" value=""#', $body);
        self::assertStringContainsString('4 produtos encontrados', $body);
    }

    #[RunInSeparateProcess]
    public function testBlankOrArrayQIsTheNormalListing(): void
    {
        foreach ([['q' => '   '], ['q' => ['fone']]] as $query) {
            $body = $this->request('/products', $query)['body'];
            self::assertStringNotContainsString('noindex', $body);
            self::assertStringContainsString('4 produtos encontrados', $body);
        }
    }

    /**
     * @param array<string, mixed> $query
     * @return array{status: int, body: string}
     */
    private function request(string $uri, array $query = []): array
    {
        $twig = AdminTestTwig::create();
        $repository = $this->repository;
        $session = new SessionContext(self::SECRET, static fn (): bool => true);
        $GLOBALS['EC_HUB_TEST_CONTAINER'] = new Container([
            Environment::class => fn () => $twig,
            SessionContext::class => fn () => $session,
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
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $uri . ($query === [] ? '' : '?' . http_build_query($query));
        $_GET = $query;
        $_POST = [];
        http_response_code(200);

        ob_start();
        require dirname(__DIR__, 3) . '/public/index.php';
        $body = (string) ob_get_clean();

        return ['status' => (int) http_response_code(), 'body' => $body];
    }
}
