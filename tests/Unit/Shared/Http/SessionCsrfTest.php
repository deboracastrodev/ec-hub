<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Http;

use App\Shared\Http\SessionCsrf;
use PHPUnit\Framework\TestCase;

final class SessionCsrfTest extends TestCase
{
    private const SECRET = 'phpunit-only-session-cookie-secret-32';

    public function testTokenIsAnHmacOfTheSessionId(): void
    {
        $csrf = new SessionCsrf(self::SECRET);
        $session = str_repeat('a', 64);

        self::assertSame(hash_hmac('sha256', 'cart-csrf|' . $session, self::SECRET), $csrf->token($session));
        self::assertNotSame($csrf->token($session), $csrf->token(str_repeat('b', 64)));
        self::assertNotSame($csrf->token($session), (new SessionCsrf(self::SECRET . 'x'))->token($session));
    }

    public function testValidatesOnlyTheTokenOfTheSameSession(): void
    {
        $csrf = new SessionCsrf(self::SECRET);
        $session = str_repeat('a', 64);
        $token = $csrf->token($session);

        self::assertTrue($csrf->isValid($token, $session));
        self::assertFalse($csrf->isValid($token, str_repeat('b', 64)));
        self::assertFalse($csrf->isValid($token, null));
        self::assertFalse($csrf->isValid('forjado', $session));
        self::assertFalse($csrf->isValid('', $session));
        self::assertFalse($csrf->isValid(null, $session));
        self::assertFalse($csrf->isValid([$token], $session));
    }
}
