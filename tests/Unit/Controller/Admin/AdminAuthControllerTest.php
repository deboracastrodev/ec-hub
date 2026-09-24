<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Admin;

use App\Controller\Admin\AdminAuthController;
use App\Shared\Http\AdminAuth;
use App\Shared\Http\Request;

final class AdminAuthControllerTest extends AdminControllerTestCase
{
    private function loginCsrf(): string
    {
        return $this->auth()->loginCsrfToken(self::SESSION_ID);
    }

    public function testLoginFormRendersWithCsrfAndNoindex(): void
    {
        $response = $this->authController()->loginForm(new Request('GET'));

        self::assertSame(200, $response->status);
        self::assertSame('no-store', $response->headers['Cache-Control']);
        self::assertSame('DENY', $response->headers['X-Frame-Options']);
        self::assertSame("frame-ancestors 'none'", $response->headers['Content-Security-Policy']);
        self::assertStringContainsString('<meta name="robots" content="noindex">', $response->body);
        self::assertStringContainsString('name="_csrf" value="' . $this->loginCsrf() . '"', $response->body);
        self::assertStringContainsString('<label class="admin-form__label" for="admin-username">', $response->body);
        self::assertStringNotContainsString(AdminAuthController::DISABLED_MESSAGE, $response->body);
    }

    public function testSuccessfulLoginRedirectsToProductsAndIssuesCookie(): void
    {
        $response = $this->authController()->login(new Request('POST', [], [], [
            '_csrf' => $this->loginCsrf(), 'username' => 'admin', 'password' => self::PASSWORD,
        ]));

        self::assertSame(303, $response->status);
        self::assertSame('/admin/products', $response->headers['Location']);
        self::assertSame('DENY', $response->headers['X-Frame-Options']);
        self::assertSame(AdminAuth::COOKIE_NAME, $this->emittedCookies[0]['name']);
    }

    public function testWrongPasswordIsA401WithAGenericMessage(): void
    {
        $response = $this->authController()->login(new Request('POST', [], [], [
            '_csrf' => $this->loginCsrf(), 'username' => '<admin>', 'password' => 'errada',
        ]));

        self::assertSame(401, $response->status);
        self::assertStringContainsString('Usuário ou senha inválidos.', $response->body);
        self::assertStringContainsString('value="&lt;admin&gt;"', $response->body);
        self::assertStringNotContainsString('errada', $response->body);
        self::assertSame([], $this->emittedCookies);
    }

    public function testDisabledAdminShowsTheConfigurationMessage(): void
    {
        $form = $this->authController(false)->loginForm(new Request('GET'));
        self::assertStringContainsString(AdminAuthController::DISABLED_MESSAGE, $form->body);

        $response = $this->authController(false)->login(new Request('POST', [], [], [
            '_csrf' => $this->loginCsrf(), 'username' => 'admin', 'password' => '',
        ]));

        self::assertSame(401, $response->status);
        self::assertStringContainsString(AdminAuthController::DISABLED_MESSAGE, $response->body);
        self::assertSame([], $this->emittedCookies);
    }

    public function testLoginWithoutValidCsrfIsForbidden(): void
    {
        foreach ([[], ['_csrf' => 'forjado'], ['_csrf' => ['x']]] as $csrf) {
            $response = $this->authController()->login(new Request('POST', [], [], $csrf + [
                'username' => 'admin', 'password' => self::PASSWORD,
            ]));

            self::assertSame(403, $response->status);
            self::assertStringContainsString('403', $response->body);
            self::assertSame("frame-ancestors 'none'", $response->headers['Content-Security-Policy']);
        }
        self::assertSame([], $this->emittedCookies);
    }

    public function testLogoutRequiresCsrfAndRedirectsToLogin(): void
    {
        $this->logIn();

        $forbidden = $this->authController()->logout(new Request('POST', [], [], ['_csrf' => 'forjado']));
        self::assertSame(403, $forbidden->status);
        self::assertSame([], $this->emittedCookies);

        $response = $this->authController()->logout(new Request('POST', [], [], ['_csrf' => $this->csrf()]));
        self::assertSame(303, $response->status);
        self::assertSame('/admin/login', $response->headers['Location']);
        self::assertSame('', $this->emittedCookies[0]['value']);
    }

    public function testLogoutWithoutSessionJustRedirects(): void
    {
        $response = $this->authController()->logout(new Request('POST'));

        self::assertSame(303, $response->status);
        self::assertSame('/admin/login', $response->headers['Location']);
    }
}
