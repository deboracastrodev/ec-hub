<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Admin;

use App\Shared\Http\Request;
use App\Shared\Http\Response;

final class AdminProductControllerTest extends AdminControllerTestCase
{
    /** @return array<string, string> */
    private function validForm(): array
    {
        return [
            '_csrf' => $this->csrf(),
            'name' => 'Headset USB',
            'description' => 'Headset com microfone.',
            'price' => '1234,50',
            'category' => 'Áudio',
            'image_url' => 'https://cdn.example.com/headset.jpg',
        ];
    }

    private static function assertRedirect(string $location, Response $response): void
    {
        self::assertSame(303, $response->status);
        self::assertSame($location, $response->headers['Location']);
        self::assertAdminSecurityHeaders($response);
    }

    private static function assertAdminSecurityHeaders(Response $response): void
    {
        self::assertSame('no-store', $response->headers['Cache-Control']);
        self::assertSame('DENY', $response->headers['X-Frame-Options']);
        self::assertSame("frame-ancestors 'none'", $response->headers['Content-Security-Policy']);
    }

    public function testEveryActionRedirectsToLoginWithoutSession(): void
    {
        $controller = $this->productController();

        foreach (['index', 'newForm', 'create', 'edit', 'update', 'delete'] as $action) {
            self::assertRedirect('/admin/login', $controller->$action(new Request('GET', ['1'])));
        }
        self::assertNotNull($this->repository->findById(1));
    }

    public function testForgedAdminCookieIsTreatedAsLoggedOut(): void
    {
        $_COOKIE['ec_hub_admin'] = (time() + 600) . '.' . str_repeat('a', 64);

        self::assertRedirect('/admin/login', $this->productController()->index(new Request('GET')));
    }

    public function testIndexListsActiveProductsWithActions(): void
    {
        $this->logIn();
        $this->repository->delete(2);

        $response = $this->productController()->index(new Request('GET'));

        self::assertSame(200, $response->status);
        self::assertAdminSecurityHeaders($response);
        $html = $response->body;
        self::assertStringContainsString('<meta name="robots" content="noindex">', $html);
        self::assertStringContainsString('<caption', $html);
        self::assertStringContainsString('<th scope="col">Preço</th>', $html);
        self::assertStringContainsString('Mouse Gamer RGB', $html);
        self::assertStringContainsString('R$ 149,90', $html);
        self::assertStringContainsString('href="/admin/products/1/edit"', $html);
        self::assertStringContainsString('action="/admin/products/1/delete"', $html);
        self::assertStringContainsString('name="_csrf" value="' . $this->csrf() . '"', $html);
        self::assertStringContainsString('href="/admin/products/new"', $html);
        self::assertStringContainsString('Novo produto', $html);
        self::assertStringContainsString('action="/admin/logout"', $html);
        self::assertStringNotContainsString('Teclado Mecânico', $html);
        self::assertStringContainsString("onsubmit=\"return confirm('Excluir o produto Mouse\\u0020Gamer\\u0020RGB?')\"", $html);
    }

    public function testDeleteConfirmationIsEscapedForTheJsAttributeContext(): void
    {
        $this->logIn();
        $this->repository = new \Tests\Support\InMemoryProductRepository([
            ['id' => 1, 'name' => "O'Brien \"<x>\" & co", 'slug' => 'obrien', 'description' => '', 'price' => 10.0, 'category' => 'Geral', 'image_url' => null],
        ]);

        $html = $this->productController()->index(new Request('GET'))->body;

        self::assertSame(1, preg_match('/onsubmit="([^"]*)"/', $html, $match));
        self::assertStringNotContainsString("'Brien", $match[1]);
        self::assertStringNotContainsString('<', $match[1]);
        self::assertStringNotContainsString('&', $match[1]);
        self::assertStringContainsString('\\u0027', $match[1]);
    }

    public function testIndexPaginatesTwentyPerPageWithPrevAndNextLinks(): void
    {
        $this->logIn();
        $products = [];
        for ($i = 1; $i <= 45; ++$i) {
            $products[] = ['id' => $i, 'name' => sprintf('Item %02d', $i), 'slug' => "item-{$i}",
                'description' => '', 'price' => 10.0, 'category' => 'Geral', 'image_url' => null];
        }
        $this->repository = new \Tests\Support\InMemoryProductRepository($products);

        $html = $this->productController()->index(new Request('GET', [], ['page' => '2']))->body;

        self::assertStringContainsString('Item 21', $html);
        self::assertStringContainsString('Item 40', $html);
        self::assertStringNotContainsString('Item 20', $html);
        self::assertStringNotContainsString('Item 01', $html);
        self::assertStringNotContainsString('Item 41', $html);
        self::assertStringContainsString('href="/admin/products?page=1" rel="prev"', $html);
        self::assertStringContainsString('href="/admin/products?page=3" rel="next"', $html);
        self::assertStringContainsString('Página 2 de 3', $html);

        $last = $this->productController()->index(new Request('GET', [], ['page' => '3']))->body;
        self::assertStringContainsString('rel="prev"', $last);
        self::assertStringNotContainsString('rel="next"', $last);
    }

    public function testIndexShowsOnlyKnownStatusMessages(): void
    {
        $this->logIn();
        $controller = $this->productController();

        self::assertStringContainsString('Produto criado com sucesso.', $controller->index(new Request('GET', [], ['status' => 'created']))->body);
        self::assertStringContainsString('Produto atualizado com sucesso.', $controller->index(new Request('GET', [], ['status' => 'updated']))->body);
        self::assertStringContainsString('Produto excluído com sucesso.', $controller->index(new Request('GET', [], ['status' => 'deleted']))->body);

        $unknown = $controller->index(new Request('GET', [], ['status' => '<script>x</script>']))->body;
        self::assertStringNotContainsString('<script>x</script>', $unknown);
        self::assertStringNotContainsString('admin__alert--success', $unknown);
    }

    public function testIndexToleratesNonNumericPage(): void
    {
        $this->logIn();

        foreach (['abc', '-1', ['2'], '0'] as $page) {
            self::assertSame(200, $this->productController()->index(new Request('GET', [], ['page' => $page]))->status);
        }
    }

    public function testNewFormRendersEmptyAccessibleForm(): void
    {
        $this->logIn();

        $response = $this->productController()->newForm(new Request('GET'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('action="/admin/products"', $response->body);
        self::assertStringContainsString('<label class="admin-form__label" for="product-price">', $response->body);
        self::assertStringNotContainsString('aria-invalid', $response->body);
    }

    public function testCreateSavesAndRedirects(): void
    {
        $this->logIn();

        $response = $this->productController()->create(new Request('POST', [], [], $this->validForm()));

        self::assertRedirect('/admin/products?status=created', $response);
        $created = $this->repository->findBySlug('headset-usb');
        self::assertNotNull($created);
        self::assertSame(1234.5, $created->getPrice()->getDecimal());
        self::assertSame('https://cdn.example.com/headset.jpg', $created->getImageUrl());
    }

    public function testInvalidCreateIs422WithErrorsAndEscapedValues(): void
    {
        $this->logIn();

        $response = $this->productController()->create(new Request('POST', [], [], [
            'name' => '',
            'price' => 'abc',
            'image_url' => 'javascript:alert(1)',
            'category' => '"><script>alert(1)</script>',
        ] + $this->validForm()));

        self::assertSame(422, $response->status);
        $html = $response->body;
        self::assertStringContainsString('id="product-name-error"', $html);
        self::assertStringContainsString('id="product-price-error"', $html);
        self::assertStringContainsString('id="product-image_url-error"', $html);
        self::assertStringContainsString('aria-invalid="true" aria-describedby="product-price-error"', $html);
        self::assertStringContainsString('value="abc"', $html);
        self::assertStringContainsString('value="javascript:alert(1)"', $html);
        self::assertStringContainsString('value="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertSame(2, $this->repository->count());
    }

    public function testPostsWithoutValidCsrfAreForbiddenAndChangeNothing(): void
    {
        $this->logIn();
        $controller = $this->productController();

        foreach ([[], ['_csrf' => 'forjado']] as $csrf) {
            $form = $csrf + array_diff_key($this->validForm(), ['_csrf' => true]);
            $forbidden = $controller->create(new Request('POST', [], [], $form));
            self::assertSame(403, $forbidden->status);
            self::assertAdminSecurityHeaders($forbidden);
            // Story 8.6: the 403 page keeps its default back link for the admin.
            self::assertStringContainsString('href="/admin/products"', $forbidden->body);
            self::assertStringContainsString('Voltar ao painel', $forbidden->body);
            self::assertSame(403, $controller->update(new Request('POST', ['1'], [], $form))->status);
            self::assertSame(403, $controller->delete(new Request('POST', ['1'], [], $form))->status);
        }

        self::assertSame(2, $this->repository->count());
        self::assertSame('Mouse Gamer RGB', $this->repository->findById(1)?->getName());
    }

    public function testEditPrefillsTheForm(): void
    {
        $this->logIn();

        $response = $this->productController()->edit(new Request('GET', ['1']));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('action="/admin/products/1"', $response->body);
        self::assertStringContainsString('value="Mouse Gamer RGB"', $response->body);
        self::assertStringContainsString('value="149,90"', $response->body);
    }

    public function testUpdateSavesKeepsSlugAndRedirects(): void
    {
        $this->logIn();

        $response = $this->productController()->update(new Request('POST', ['1'], [], [
            'name' => 'Mouse Renomeado', 'image_url' => '',
        ] + $this->validForm()));

        self::assertRedirect('/admin/products?status=updated', $response);
        $product = $this->repository->findById(1);
        self::assertSame('Mouse Renomeado', $product?->getName());
        self::assertSame('mouse-gamer-rgb', $product?->getSlug());
        self::assertSame('', $product?->getImageUrl());
    }

    public function testInvalidUpdateIs422AndChangesNothing(): void
    {
        $this->logIn();

        $response = $this->productController()->update(new Request('POST', ['1'], [], ['price' => '1.234,50'] + $this->validForm()));

        self::assertSame(422, $response->status);
        self::assertStringContainsString('value="1.234,50"', $response->body);
        self::assertSame(149.9, $this->repository->findById(1)?->getPrice()->getDecimal());
    }

    public function testDeleteIsSoftAndRedirects(): void
    {
        $this->logIn();

        $response = $this->productController()->delete(new Request('POST', ['1'], [], ['_csrf' => $this->csrf()]));

        self::assertRedirect('/admin/products?status=deleted', $response);
        self::assertNull($this->repository->findById(1));
        self::assertNotNull($this->repository->rawRow(1)['deleted_at']);
    }

    public function testMissingOrDeletedIdsAre404(): void
    {
        $this->logIn();
        $this->repository->delete(2);
        $controller = $this->productController();

        foreach (['2', '999', '99999999999999999999999'] as $id) {
            self::assertSame(404, $controller->edit(new Request('GET', [$id]))->status, "edit {$id}");
            self::assertSame(404, $controller->update(new Request('POST', [$id], [], $this->validForm()))->status, "update {$id}");
            $delete = $controller->delete(new Request('POST', [$id], [], ['_csrf' => $this->csrf()]));
            self::assertSame(404, $delete->status, "delete {$id}");
            self::assertAdminSecurityHeaders($delete);
            self::assertStringContainsString('<meta name="robots" content="noindex">', $delete->body);
        }
    }
}
