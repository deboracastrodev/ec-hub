<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Application\Product\ManageProducts;
use App\Application\Product\ProductInput;
use App\Domain\Product\Model\Product;
use App\Shared\Http\AdminAuth;
use App\Shared\Http\Request;
use App\Shared\Http\Response;
use Twig\Environment;

/**
 * Admin product CRUD (Story 8.3). Every action checks, in this order:
 * authentication (303 to the login), CSRF on POST (403), the product id
 * (404), then the form (422).
 */
final class AdminProductController
{
    public const STATUS_MESSAGES = [
        'created' => 'Produto criado com sucesso.',
        'updated' => 'Produto atualizado com sucesso.',
        'deleted' => 'Produto excluído com sucesso.',
    ];

    public function __construct(
        private readonly ManageProducts $products,
        private readonly AdminAuth $auth,
        private readonly Environment $twig
    ) {
    }

    /** GET /admin/products */
    public function index(Request $request): Response
    {
        if (! $this->auth->isAuthenticated()) {
            return Response::redirect('/admin/login');
        }

        $status = $request->query('status');
        $page = filter_var($request->query('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $listing = $this->products->listPage($page === false ? 1 : $page);

        return Response::html($this->twig->render('admin/products/index.html.twig', [
            'products' => $listing['products'],
            'page' => $listing['page'],
            'total_pages' => $listing['total_pages'],
            'total' => $listing['total'],
            'status_message' => is_string($status) ? (self::STATUS_MESSAGES[$status] ?? null) : null,
            'csrf_token' => $this->auth->csrfToken(),
        ]));
    }

    /** GET /admin/products/new */
    public function newForm(Request $request): Response
    {
        if (! $this->auth->isAuthenticated()) {
            return Response::redirect('/admin/login');
        }

        return $this->renderForm(null, array_fill_keys(ProductInput::FIELDS, ''), [], 200);
    }

    /** POST /admin/products */
    public function create(Request $request): Response
    {
        if (! $this->auth->isAuthenticated()) {
            return Response::redirect('/admin/login');
        }
        if (! $this->auth->isValidCsrfToken($request->input('_csrf'))) {
            return $this->forbidden();
        }

        $input = ProductInput::fromForm($request->body);
        if (! $input->isValid()) {
            return $this->renderForm(null, $input->values(), $input->errors(), 422);
        }

        $this->products->create($input);

        return Response::redirect('/admin/products?status=created');
    }

    /** GET /admin/products/{id}/edit */
    public function edit(Request $request): Response
    {
        if (! $this->auth->isAuthenticated()) {
            return Response::redirect('/admin/login');
        }

        $product = $this->findProduct($request);
        if ($product === null) {
            return $this->notFound();
        }

        return $this->renderForm($product, [
            'name' => $product->getName(),
            'description' => $product->getDescription(),
            'price' => number_format($product->getPrice()->getDecimal(), 2, ',', ''),
            'category' => $product->getCategory(),
            'image_url' => $product->getImageUrl(),
        ], [], 200);
    }

    /** POST /admin/products/{id} */
    public function update(Request $request): Response
    {
        if (! $this->auth->isAuthenticated()) {
            return Response::redirect('/admin/login');
        }
        if (! $this->auth->isValidCsrfToken($request->input('_csrf'))) {
            return $this->forbidden();
        }

        $product = $this->findProduct($request);
        if ($product === null) {
            return $this->notFound();
        }

        $input = ProductInput::fromForm($request->body);
        if (! $input->isValid()) {
            return $this->renderForm($product, $input->values(), $input->errors(), 422);
        }

        if (! $this->products->update((int) $product->getId(), $input)) {
            return $this->notFound();
        }

        return Response::redirect('/admin/products?status=updated');
    }

    /** POST /admin/products/{id}/delete */
    public function delete(Request $request): Response
    {
        if (! $this->auth->isAuthenticated()) {
            return Response::redirect('/admin/login');
        }
        if (! $this->auth->isValidCsrfToken($request->input('_csrf'))) {
            return $this->forbidden();
        }

        $id = $this->productId($request);
        if ($id === null || ! $this->products->delete($id)) {
            return $this->notFound();
        }

        return Response::redirect('/admin/products?status=deleted');
    }

    private function findProduct(Request $request): ?Product
    {
        $id = $this->productId($request);

        return $id === null ? null : $this->products->find($id);
    }

    /** The {id} path segment as a positive int; null when out of range. */
    private function productId(Request $request): ?int
    {
        $id = filter_var($request->param(0), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function renderForm(?Product $product, array $values, array $errors, int $status): Response
    {
        return Response::html($this->twig->render('admin/products/form.html.twig', [
            'product' => $product,
            'action' => $product === null ? '/admin/products' : '/admin/products/' . $product->getId(),
            'values' => $values,
            'errors' => $errors,
            'csrf_token' => $this->auth->csrfToken(),
        ]), $status);
    }

    private function notFound(): Response
    {
        return Response::html($this->twig->render('error/404.html.twig', [
            'message' => 'Produto não encontrado',
            'noindex' => true,
        ]), 404);
    }

    private function forbidden(): Response
    {
        return Response::html($this->twig->render('error/403.html.twig', [
            'message' => 'Requisição recusada: o formulário expirou ou é inválido. Recarregue a página e tente de novo.',
        ]), 403);
    }
}
