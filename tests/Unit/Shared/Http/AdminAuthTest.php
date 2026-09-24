<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Http;

use App\Shared\Http\AdminAuth;
use App\Shared\Http\AdminCredentials;
use PHPUnit\Framework\TestCase;

final class AdminAuthTest extends TestCase
{
    private const SECRET = 'test-session-cookie-secret-with-32-chars';
    private const NOW = 1_800_000_000;

    private string $hash;

    /** @var list<array{name: string, value: string, options: array<string, mixed>}> */
    private array $emitted = [];

    private int $now = self::NOW;

    protected function setUp(): void
    {
        $this->hash = password_hash('s3nha-forte', PASSWORD_DEFAULT);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[AdminAuth::COOKIE_NAME], $_SERVER['HTTPS']);
    }

    private function auth(?string $hash = null, string $username = 'admin'): AdminAuth
    {
        return new AdminAuth(
            AdminCredentials::fromArray(['username' => $username, 'password_hash' => $hash ?? $this->hash]),
            self::SECRET,
            function (string $name, string $value, array $options): bool {
                $this->emitted[] = compact('name', 'value', 'options');

                return true;
            },
            fn (): int => $this->now
        );
    }

    public function testRejectsAShortSecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AdminAuth(AdminCredentials::fromArray([]), 'short');
    }

    public function testSuccessfulAttemptIssuesASignedStrictCookieScopedToAdmin(): void
    {
        self::assertTrue($this->auth()->attempt('admin', 's3nha-forte'));

        self::assertCount(1, $this->emitted);
        $cookie = $this->emitted[0];
        $expiresAt = self::NOW + AdminAuth::TTL_SECONDS;
        $expectedSignature = hash_hmac('sha256', "admin-session|admin|{$this->hash}|{$expiresAt}", self::SECRET);

        self::assertSame(AdminAuth::COOKIE_NAME, $cookie['name']);
        self::assertSame("{$expiresAt}.{$expectedSignature}", $cookie['value']);
        self::assertSame('/admin', $cookie['options']['path']);
        self::assertTrue($cookie['options']['httponly']);
        self::assertSame('Strict', $cookie['options']['samesite']);
        self::assertFalse($cookie['options']['secure']);
        self::assertSame($expiresAt, $cookie['options']['expires']);
    }

    public function testCookieIsSecureOverHttps(): void
    {
        $_SERVER['HTTPS'] = 'on';

        $this->auth()->attempt('admin', 's3nha-forte');

        self::assertTrue($this->emitted[0]['options']['secure']);
    }

    private function authWithFailingEmitter(): AdminAuth
    {
        return new AdminAuth(
            AdminCredentials::fromArray(['username' => 'admin', 'password_hash' => $this->hash]),
            self::SECRET,
            static fn (): bool => false,
            fn (): int => $this->now
        );
    }

    public function testAttemptThrowsWhenTheCookieCannotBeEmitted(): void
    {
        $auth = $this->authWithFailingEmitter();
        self::assertFalse($auth->attempt('admin', 'errada'), 'wrong credentials never reach the emitter');

        try {
            $auth->attempt('admin', 's3nha-forte');
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException) {
            self::assertArrayNotHasKey(AdminAuth::COOKIE_NAME, $_COOKIE);
        }
    }

    public function testLogoutThrowsWhenTheCookieCannotBeEmitted(): void
    {
        $_COOKIE[AdminAuth::COOKIE_NAME] = $this->validToken(self::NOW + 60);

        $this->expectException(\RuntimeException::class);

        $this->authWithFailingEmitter()->logout();
    }

    public function testWrongCredentialsIssueNothing(): void
    {
        $auth = $this->auth();

        self::assertFalse($auth->attempt('admin', 'errada'));
        self::assertFalse($auth->attempt('outro', 's3nha-forte'));
        self::assertFalse($auth->attempt(['admin'], 's3nha-forte'));
        self::assertFalse($auth->attempt('admin', null));
        self::assertSame([], $this->emitted);
    }

    public function testUnconfiguredAdminNeverAuthenticates(): void
    {
        $auth = $this->auth('não-é-hash');

        self::assertFalse($auth->isConfigured());
        self::assertFalse($auth->attempt('admin', 'não-é-hash'));
        $_COOKIE[AdminAuth::COOKIE_NAME] = $this->validToken(self::NOW + 60, 'não-é-hash');
        self::assertFalse($auth->isAuthenticated());
    }

    public function testValidCookieAuthenticates(): void
    {
        $_COOKIE[AdminAuth::COOKIE_NAME] = $this->validToken(self::NOW + 60);

        self::assertTrue($this->auth()->isAuthenticated());
    }

    public function testTamperedMalformedOrExpiredCookiesAreRejected(): void
    {
        $auth = $this->auth();
        $valid = $this->validToken(self::NOW + 60);
        [$expiresAt, $signature] = explode('.', $valid);

        $candidates = [
            'tampered signature' => $expiresAt . '.' . str_repeat('0', 64),
            'extended expiry' => (self::NOW + 99_999) . '.' . $signature,
            'expired' => $this->validToken(self::NOW - 1),
            'expires now' => $this->validToken(self::NOW),
            'uppercase hex' => $expiresAt . '.' . strtoupper($signature),
            'trailing newline' => $valid . "\n",
            'no dot' => $expiresAt . $signature,
            'empty' => '',
        ];

        foreach ($candidates as $label => $cookie) {
            $_COOKIE[AdminAuth::COOKIE_NAME] = $cookie;
            self::assertFalse($auth->isAuthenticated(), $label);
        }

        $_COOKIE[AdminAuth::COOKIE_NAME] = ['array'];
        self::assertFalse($auth->isAuthenticated(), 'array cookie');
    }

    public function testCookieExpiresAfterTtl(): void
    {
        $this->auth()->attempt('admin', 's3nha-forte');
        $_COOKIE[AdminAuth::COOKIE_NAME] = $this->emitted[0]['value'];

        $this->now = self::NOW + AdminAuth::TTL_SECONDS - 1;
        self::assertTrue($this->auth()->isAuthenticated());

        $this->now = self::NOW + AdminAuth::TTL_SECONDS;
        self::assertFalse($this->auth()->isAuthenticated());
    }

    public function testChangingPasswordOrUsernameInvalidatesIssuedSessions(): void
    {
        $_COOKIE[AdminAuth::COOKIE_NAME] = $this->validToken(self::NOW + 60);

        self::assertFalse($this->auth(password_hash('nova-senha', PASSWORD_DEFAULT))->isAuthenticated());
        self::assertFalse($this->auth(null, 'outro-admin')->isAuthenticated());
        self::assertTrue($this->auth()->isAuthenticated());
    }

    public function testLogoutEmitsAnExpiredEmptyCookie(): void
    {
        $_COOKIE[AdminAuth::COOKIE_NAME] = $this->validToken(self::NOW + 60);
        $auth = $this->auth();

        $auth->logout();

        self::assertSame('', $this->emitted[0]['value']);
        self::assertLessThan(self::NOW, $this->emitted[0]['options']['expires']);
        self::assertSame('/admin', $this->emitted[0]['options']['path']);
        self::assertFalse($auth->isAuthenticated());
    }

    public function testAuthenticatedCsrfTokenIsBoundToTheCookie(): void
    {
        $cookie = $this->validToken(self::NOW + 60);
        $_COOKIE[AdminAuth::COOKIE_NAME] = $cookie;
        $auth = $this->auth();

        $token = $auth->csrfToken();

        self::assertSame(hash_hmac('sha256', 'admin-csrf|' . $cookie, self::SECRET), $token);
        self::assertTrue($auth->isValidCsrfToken($token));
        self::assertFalse($auth->isValidCsrfToken(str_repeat('a', 64)));
        self::assertFalse($auth->isValidCsrfToken(null));
        self::assertFalse($auth->isValidCsrfToken([$token]));

        $_COOKIE[AdminAuth::COOKIE_NAME] = $this->validToken(self::NOW + 120);
        self::assertFalse($auth->isValidCsrfToken($token), 'token from another session');

        unset($_COOKIE[AdminAuth::COOKIE_NAME]);
        self::assertSame('', $auth->csrfToken());
        self::assertFalse($auth->isValidCsrfToken(''));
    }

    public function testLoginCsrfTokenIsBoundToTheVisitorSession(): void
    {
        $auth = $this->auth();
        $sessionId = str_repeat('a', 64);

        $token = $auth->loginCsrfToken($sessionId);

        self::assertSame(hash_hmac('sha256', 'admin-login-csrf|' . $sessionId, self::SECRET), $token);
        self::assertTrue($auth->isValidLoginCsrfToken($token, $sessionId));
        self::assertFalse($auth->isValidLoginCsrfToken($token, str_repeat('b', 64)));
        self::assertFalse($auth->isValidLoginCsrfToken(null, $sessionId));
        self::assertFalse($auth->isValidLoginCsrfToken(['x'], $sessionId));
    }

    private function validToken(int $expiresAt, ?string $hash = null): string
    {
        $hash ??= $this->hash;

        return $expiresAt . '.' . hash_hmac('sha256', "admin-session|admin|{$hash}|{$expiresAt}", self::SECRET);
    }
}
