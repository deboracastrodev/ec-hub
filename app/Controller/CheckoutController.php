<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Cart\ManageCart;
use App\Application\Order\CheckoutInput;
use App\Application\Order\EmptyCartException;
use App\Application\Order\PlaceOrder;
use App\Domain\Order\Model\Order;
use App\Domain\Order\Repository\OrderRepositoryInterface;
use App\Shared\Http\Request;
use App\Shared\Http\Response;
use App\Shared\Http\SessionContext;
use App\Shared\Http\SessionCsrf;
use Twig\Environment;

/**
 * Simulated checkout (Story 8.7): form, order placement and confirmation.
 *
 * No route here ever opens a session (currentId() only): without a session
 * there is no cart to check out. The confirmation page only shows the order
 * this session placed last -- an order number in a URL opens nothing.
 */
final class CheckoutController
{
    public const LOAD_FAILED_MESSAGE = 'Não foi possível carregar o checkout agora. Tente de novo em instantes.';

    public const PLACE_FAILED_MESSAGE = 'Não foi possível concluir o pedido agora. Seu carrinho foi mantido.';

    public const CONFIRMATION_FAILED_MESSAGE = 'Não foi possível carregar a confirmação do pedido agora. Tente de novo em instantes.';

    private const EMPTY_VALUES = ['name' => '', 'email' => '', 'address' => ''];

    public function __construct(
        private readonly PlaceOrder $placeOrder,
        private readonly ManageCart $cart,
        private readonly OrderRepositoryInterface $orders,
        private readonly SessionContext $session,
        private readonly SessionCsrf $csrf,
        private readonly Environment $twig
    ) {
    }

    /** GET /checkout */
    public function form(Request $request): Response
    {
        $sessionId = $this->session->currentId();
        if ($sessionId === null) {
            return Response::redirect('/cart');
        }

        return $this->renderForm($sessionId, self::EMPTY_VALUES, [], null, 200, 503) ?? Response::redirect('/cart');
    }

    /** POST /checkout */
    public function place(Request $request): Response
    {
        $sessionId = $this->session->currentId();
        if ($sessionId === null || ! $this->csrf->isValid($request->input('_csrf'), $sessionId)) {
            return $this->forbidden();
        }

        $input = CheckoutInput::fromForm($request->body);
        if (! $input->isValid()) {
            return $this->renderForm($sessionId, $input->values(), $input->errors(), null, 422, 503)
                ?? Response::redirect('/cart');
        }

        try {
            $this->placeOrder->place($sessionId, $input);
        } catch (EmptyCartException) {
            return Response::redirect('/cart');
        } catch (\Throwable) {
            return $this->renderForm($sessionId, $input->values(), [], self::PLACE_FAILED_MESSAGE, 503, 503, true)
                ?? Response::redirect('/cart');
        }

        return Response::redirect('/checkout/confirmation');
    }

    /** GET /checkout/confirmation */
    public function confirmation(Request $request): Response
    {
        $sessionId = $this->session->currentId();
        if ($sessionId === null) {
            return $this->notFound();
        }

        try {
            $last = $this->placeOrder->lastOrder($sessionId);
            $order = $last === null ? null : $this->orders->findByNumber($last['order_number']);
        } catch (\Throwable) {
            return Response::html($this->twig->render('checkout/confirmation.html.twig', [
                'order' => null,
                'lines' => [],
                'total' => 0.0,
                'email_sent' => false,
                'error_message' => self::CONFIRMATION_FAILED_MESSAGE,
            ]), 503);
        }
        if ($last === null || $order === null) {
            return $this->notFound();
        }

        $lines = [];
        foreach ($order->items() as $item) {
            $lines[] = [
                'name' => $item->productName(),
                'quantity' => $item->quantity(),
                'unit_price' => $item->unitPriceCents() / 100,
                'subtotal' => $item->subtotalCents() / 100,
            ];
        }

        return Response::html($this->twig->render('checkout/confirmation.html.twig', [
            'order' => $order,
            'status_label' => $order->status() === Order::STATUS_COMPLETED ? 'Concluído' : $order->status(),
            'lines' => $lines,
            'total' => $order->totalCents() / 100,
            'email_sent' => $last['email_sent'],
            'error_message' => null,
        ]));
    }

    /**
     * Renders the checkout form with the cart summary. Null when the cart is
     * empty (the caller redirects to /cart), unless $keepWhenEmpty.
     *
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function renderForm(
        string $sessionId,
        array $values,
        array $errors,
        ?string $errorMessage,
        int $status,
        int $loadFailedStatus,
        bool $keepWhenEmpty = false
    ): ?Response {
        $view = ['lines' => [], 'item_count' => 0, 'total' => 0.0];
        $loadFailed = false;

        try {
            $view = $this->cart->view($sessionId);
        } catch (\Throwable) {
            // Session store down: the page still renders, with the failure said.
            $errorMessage ??= self::LOAD_FAILED_MESSAGE;
            $status = $loadFailedStatus;
            $loadFailed = true;
        }
        if (! $loadFailed && ! $keepWhenEmpty && $view['lines'] === []) {
            return null;
        }

        return Response::html($this->twig->render('checkout/form.html.twig', [
            'lines' => $view['lines'],
            'item_count' => $view['item_count'],
            'total' => $view['total'],
            'values' => $values + self::EMPTY_VALUES,
            'errors' => $errors,
            'error_message' => $errorMessage,
            'load_failed' => $loadFailed,
            'limits' => [
                'name' => CheckoutInput::NAME_MAX,
                'email' => CheckoutInput::EMAIL_MAX,
                'address' => CheckoutInput::ADDRESS_MAX,
            ],
            'csrf_token' => $this->csrf->token($sessionId),
        ]), $status);
    }

    private function notFound(): Response
    {
        return Response::html($this->twig->render('error/404.html.twig', [
            'message' => 'Pedido não encontrado',
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
