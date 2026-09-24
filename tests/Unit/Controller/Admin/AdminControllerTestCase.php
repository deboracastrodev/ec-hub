<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Admin;

use App\Application\Product\ManageProducts;
use App\Controller\Admin\AdminAuthController;
use App\Controller\Admin\AdminProductController;
use App\Shared\Http\AdminAuth;
use App\Shared\Http\AdminCredentials;
use App\Shared\Http\SessionContext;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestTwig;
use Tests\Support\InMemoryProductRepository;

abstract class AdminControllerTestCase extends TestCase
{
    protected const SECRET = 'test-session-cookie-secret-with-32-chars';
    protected const PASSWORD = 's3nha-forte';
    protected const SESSION_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected InMemoryProductRepository $repository;

    /** @var list<array{name: string, value: string, options: array<string, mixed>}> */
    protected array $emittedCookies = [];

    private static ?string $hash = null;

    protected function setUp(): void
    {
        $this->repository = new InMemoryProductRepository();
        $_COOKIE[SessionContext::COOKIE_NAME] = self::SESSION_ID;
        $_COOKIE[SessionContext::SIGNATURE_COOKIE_NAME] = hash_hmac('sha256', self::SESSION_ID, self::SECRET);
    }

    protected function tearDown(): void
    {
        unset(
            $_COOKIE[AdminAuth::COOKIE_NAME],
            $_COOKIE[SessionContext::COOKIE_NAME],
            $_COOKIE[SessionContext::SIGNATURE_COOKIE_NAME]
        );
    }

    protected static function passwordHash(): string
    {
        return self::$hash ??= password_hash(self::PASSWORD, PASSWORD_DEFAULT);
    }

    protected function auth(bool $configured = true): AdminAuth
    {
        return new AdminAuth(
            AdminCredentials::fromArray([
                'username' => 'admin',
                'password_hash' => $configured ? self::passwordHash() : '',
            ]),
            self::SECRET,
            function (string $name, string $value, array $options): bool {
                $this->emittedCookies[] = compact('name', 'value', 'options');

                return true;
            }
        );
    }

    protected function logIn(): void
    {
        $expiresAt = time() + 600;
        $_COOKIE[AdminAuth::COOKIE_NAME] = $expiresAt . '.'
            . hash_hmac('sha256', 'admin-session|admin|' . self::passwordHash() . '|' . $expiresAt, self::SECRET);
    }

    protected function csrf(): string
    {
        return $this->auth()->csrfToken();
    }

    protected function authController(bool $configured = true): AdminAuthController
    {
        return new AdminAuthController(
            $this->auth($configured),
            new SessionContext(self::SECRET, static fn (): bool => true),
            AdminTestTwig::create()
        );
    }

    protected function productController(): AdminProductController
    {
        return new AdminProductController(new ManageProducts($this->repository), $this->auth(), AdminTestTwig::create());
    }
}
