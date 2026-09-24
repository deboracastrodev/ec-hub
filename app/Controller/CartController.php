<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Cart\ManageCart;
use App\Application\Event\TrackProductInteraction;
use App\Controller\Exceptions\InvalidRequestException;
use App\Domain\Cart\Model\Cart;
use App\Shared\Http\Request;
use App\Shared\Http\Response;
use App\Shared\Http\SessionContext;
use App\Shared\Http\SessionCsrf;
use Twig\Environment;

/**
 * Cart page (Story 8.6). Server-rendered forms with a stateless CSRF token
 * bound to the visitor session; every successful POST answers 303 to
 * /cart?status=... (post/redirect/get).
 *
 * Adding goes through TrackProductInteraction, the only place that publishes
 * cart.item_added. GET /cart and the update/remove actions never create a
 * session: without one there is no cart to show or change.
 */
final class CartController
{
    public const STATUS_MESSAGES = [
        'added' => 'Produto adicionado ao carrinho.',
        'updated' => 'Quantidade atualizada.',
        'removed' => 'Produto removido do carrinho.',
    ];

    /** Statuses that report a failure: rendered as an error alert (role=alert). */
    public const ERROR_STATUS_MESSAGES = [
        'add_failed' => 'Não foi possível atualizar o carrinho agora. Tente de novo em instantes.',
    ];

    public const LOAD_FAILED_MESSAGE = 'Não foi possível carregar o carrinho agora. Tente de novo em instantes.';

    public const INVALID_QUANTITY_MESSAGE = 'Informe uma quantidade inteira entre 0 e 99.';

    public function __construct(
        private readonly ManageCart $cart,
        private readonly TrackProductInteraction $tracker,
        private readonly SessionContext $session,
        private readonly SessionCsrf $csrf,
        private readonly Environment $twig
    ) {
    }

    /** GET /cart */
    public function index(Request $request): Response
    {
        $status = $request->query('status');
        $status = is_string($status) ? $status : '';

        return $this->renderCart(
            $this->session->currentId(),
            self::STATUS_MESSAGES[$status] ?? null,
            self::ERROR_STATUS_MESSAGES[$status] ?? null,
            200,
            503
        );
    }

    /** POST /cart/items (the product page form, without JavaScript) */
    public function add(Request $request): Response
    {
        $sessionId = $this->session->id();
        if (! $this->csrf->isValid($request->input('_csrf'), $sessionId)) {
            return $this->forbidden();
        }

        $productId = filter_var($request->input('product_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $rawQuantity = $request->input('quantity');
        $quantity = $rawQuantity === null
            ? 1
            : filter_var($rawQuantity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => Cart::MAX_QUANTITY]]);
        $userId = $request->input('user_id');
        if ($productId === false || $quantity === false || ($userId !== null && (! is_string($userId) || trim($userId) === ''))) {
            return Response::html($this->twig->render('error/400.html.twig', [
                'message' => 'Requisição inválida: produto ou quantidade fora do esperado.',
            ]), 400);
        }

        try {
            $result = $this->tracker->track('cart', $sessionId, $productId, $userId === null ? null : trim($userId), $quantity);
        } catch (InvalidRequestException $exception) {
            if ($exception->getHttpCode() === 404) {
                return $this->notFound('Produto não encontrado');
            }

            throw $exception;
        }

        return Response::redirect(
            ($result['cart_item_count'] ?? null) === null ? '/cart?status=add_failed' : '/cart?status=added'
        );
    }

    /** POST /cart/items/{id} */
    public function update(Request $request): Response
    {
        $sessionId = $this->session->currentId();
        if ($sessionId === null || ! $this->csrf->isValid($request->input('_csrf'), $sessionId)) {
            return $this->forbidden();
        }

        $productId = $this->productId($request);
        if ($productId === null) {
            return $this->notFound();
        }

        $quantity = filter_var($request->input('quantity'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => Cart::MAX_QUANTITY],
        ]);
        if ($quantity === false) {
            return $this->renderCart($sessionId, null, self::INVALID_QUANTITY_MESSAGE, 422);
        }

        try {
            $updated = $this->cart->updateQuantity($sessionId, $productId, $quantity);
        } catch (\Throwable) {
            return Response::redirect('/cart?status=add_failed');
        }
        if (! $updated) {
            return $this->notFound();
        }

        return Response::redirect($quantity === 0 ? '/cart?status=removed' : '/cart?status=updated');
    }

    /** POST /cart/items/{id}/delete -- idempotent */
    public function remove(Request $request): Response
    {
        $sessionId = $this->session->currentId();
        if ($sessionId === null || ! $this->csrf->isValid($request->input('_csrf'), $sessionId)) {
            return $this->forbidden();
        }

        $productId = $this->productId($request);
        if ($productId === null) {
            return $this->notFound();
        }

        try {
            $this->cart->remove($sessionId, $productId);
        } catch (\Throwable) {
            return Response::redirect('/cart?status=add_failed');
        }

        return Response::redirect('/cart?status=removed');
    }

    /**
     * @param int|null $loadFailedStatus status when the cart cannot be read
     *                                   (null keeps $status)
     */
    private function renderCart(
        ?string $sessionId,
        ?string $statusMessage,
        ?string $errorMessage,
        int $status,
        ?int $loadFailedStatus = null
    ): Response {
        $view = ['lines' => [], 'item_count' => 0, 'total' => 0.0];
        $loadFailed = false;
        if ($sessionId !== null) {
            try {
                $view = $this->cart->view($sessionId);
            } catch (\Throwable) {
                // Session store down: the page still renders, with the failure said.
                $statusMessage = null;
                $errorMessage = self::LOAD_FAILED_MESSAGE;
                $status = $loadFailedStatus ?? $status;
                $loadFailed = true;
            }
        }

        return Response::html($this->twig->render('cart/index.html.twig', [
            'lines' => $view['lines'],
            'item_count' => $view['item_count'],
            'total' => $view['total'],
            'status_message' => $statusMessage,
            'error_message' => $errorMessage,
            'load_failed' => $loadFailed,
            'csrf_token' => $sessionId === null ? '' : $this->csrf->token($sessionId),
        ]), $status);
    }

    /** The {id} path segment as a positive int; null when out of range. */
    private function productId(Request $request): ?int
    {
        $id = filter_var($request->param(0), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }

    private function notFound(string $message = 'Produto não encontrado no carrinho'): Response
    {
        return Response::html($this->twig->render('error/404.html.twig', [
            'message' => $message,
            'noindex' => true,
        ]), 404);
    }

    private function forbidden(): Response
    {
        return Response::html($this->twig->render('error/403.html.twig', [
            'message' => 'Requisição recusada: o formulário expirou ou é inválido. Recarregue a página e tente de novo.',
            'back_url' => '/cart',
            'back_label' => 'Voltar ao carrinho',
        ]), 403);
    }
}
