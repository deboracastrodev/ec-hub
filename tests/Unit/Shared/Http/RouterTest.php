<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Http;

use App\Shared\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        return new Router(
            [
                'GET /products' => ['controller' => \stdClass::class, 'action' => 'index'],
                'GET /api/x' => ['controller' => \stdClass::class, 'action' => 'x', 'api' => true],
                'GET /admin/products' => ['controller' => \stdClass::class, 'action' => 'index', 'admin' => true],
            ],
            [
                '/products/([A-Za-z0-9-]+)' => ['method' => 'GET', 'controller' => \stdClass::class, 'action' => 'show'],
                '/admin/products/(\d+)' => ['method' => 'POST', 'controller' => \stdClass::class, 'action' => 'update', 'admin' => true],
                '/admin/products/(\d+)/delete' => ['method' => 'POST', 'controller' => \stdClass::class, 'action' => 'delete', 'admin' => true],
                '/admin/products/(\d+)/edit' => ['method' => 'GET', 'controller' => \stdClass::class, 'action' => 'edit', 'admin' => true],
            ]
        );
    }

    public function testAdminFlagOnExactRoutes(): void
    {
        $admin = $this->router()->match('GET', '/admin/products');
        self::assertNotNull($admin);
        self::assertTrue($admin->isAdmin);
        self::assertFalse($admin->isApi);

        $public = $this->router()->match('GET', '/products');
        self::assertNotNull($public);
        self::assertFalse($public->isAdmin);

        $api = $this->router()->match('GET', '/api/x');
        self::assertNotNull($api);
        self::assertTrue($api->isApi);
        self::assertFalse($api->isAdmin);
    }

    public function testAdminFlagOnPatternRoutes(): void
    {
        $update = $this->router()->match('POST', '/admin/products/42');
        self::assertNotNull($update);
        self::assertTrue($update->isAdmin);
        self::assertSame('update', $update->action);
        self::assertSame(['42'], $update->params);

        $delete = $this->router()->match('POST', '/admin/products/42/delete');
        self::assertNotNull($delete);
        self::assertSame('delete', $delete->action);

        $show = $this->router()->match('GET', '/products/mouse');
        self::assertNotNull($show);
        self::assertFalse($show->isAdmin);
    }

    public function testMethodAndPatternMustMatch(): void
    {
        self::assertNull($this->router()->match('GET', '/admin/products/42'));
        self::assertNull($this->router()->match('POST', '/admin/products/abc'));
    }

    public function testRouteLabelIsTheExactPathOrThePatternWithParams(): void
    {
        self::assertSame('/products', $this->router()->match('GET', '/products')?->route);
        self::assertSame('/api/x', $this->router()->match('GET', '/api/x')?->route);
        self::assertSame('/products/{param}', $this->router()->match('GET', '/products/mouse-gamer')?->route);
        self::assertSame('/admin/products/{param}', $this->router()->match('POST', '/admin/products/42')?->route);
        self::assertSame('/admin/products/{param}/edit', $this->router()->match('GET', '/admin/products/42/edit')?->route);
        self::assertSame('/admin/products/{param}/delete', $this->router()->match('POST', '/admin/products/7/delete')?->route);
    }

    public function testRouteLabelHandlesNestedNonCapturingAndEscapedParts(): void
    {
        $router = new Router([], [
            '/files/((\\d+))\\.json' => ['method' => 'GET', 'controller' => self::class, 'action' => 'a'],
            '/docs/(?:en|pt)/(\\w+)' => ['method' => 'GET', 'controller' => self::class, 'action' => 'b'],
            '/lit/\\(x\\)/(\\d+)' => ['method' => 'GET', 'controller' => self::class, 'action' => 'c'],
        ]);

        self::assertSame('/files/{param}.json', $router->match('GET', '/files/12.json')?->route);
        self::assertSame('/docs/{param}/{param}', $router->match('GET', '/docs/en/intro')?->route);
        self::assertSame('/lit/(x)/{param}', $router->match('GET', '/lit/(x)/3')?->route);
    }
}
