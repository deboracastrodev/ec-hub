<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Shared\Http\AdminAuth;
use App\Shared\Http\Request;
use App\Shared\Http\Response;
use App\Shared\Http\SessionContext;
use Twig\Environment;

/** Admin login/logout (Story 8.3). */
final class AdminAuthController
{
    public const DISABLED_MESSAGE = 'Painel admin desabilitado: configure ADMIN_USERNAME e ADMIN_PASSWORD_HASH';
    public const INVALID_CREDENTIALS_MESSAGE = 'Usuário ou senha inválidos.';

    public function __construct(
        private readonly AdminAuth $auth,
        private readonly SessionContext $session,
        private readonly Environment $twig
    ) {
    }

    /** GET /admin/login */
    public function loginForm(Request $request): Response
    {
        return $this->renderLogin(200, $this->auth->isConfigured() ? null : self::DISABLED_MESSAGE, '');
    }

    /** POST /admin/login */
    public function login(Request $request): Response
    {
        if (! $this->auth->isValidLoginCsrfToken($request->input('_csrf'), $this->session->id())) {
            return $this->forbidden();
        }

        $username = $request->input('username');
        $typedUsername = is_string($username) ? $username : '';

        if (! $this->auth->isConfigured()) {
            return $this->renderLogin(401, self::DISABLED_MESSAGE, $typedUsername);
        }

        if (! $this->auth->attempt($username, $request->input('password'))) {
            return $this->renderLogin(401, self::INVALID_CREDENTIALS_MESSAGE, $typedUsername);
        }

        return Response::redirect('/admin/products');
    }

    /** POST /admin/logout */
    public function logout(Request $request): Response
    {
        if (! $this->auth->isAuthenticated()) {
            return Response::redirect('/admin/login');
        }

        if (! $this->auth->isValidCsrfToken($request->input('_csrf'))) {
            return $this->forbidden();
        }

        $this->auth->logout();

        return Response::redirect('/admin/login');
    }

    private function renderLogin(int $status, ?string $error, string $username): Response
    {
        return Response::html($this->twig->render('admin/login.html.twig', [
            'error' => $error,
            'username' => $username,
            'csrf_token' => $this->auth->loginCsrfToken($this->session->id()),
        ]), $status);
    }

    private function forbidden(): Response
    {
        return Response::html($this->twig->render('error/403.html.twig', [
            'message' => 'Requisição recusada: o formulário expirou ou é inválido. Recarregue a página e tente de novo.',
        ]), 403);
    }
}
