<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Http;

use App\Shared\Http\SessionContext;
use PHPUnit\Framework\TestCase;

final class SessionContextTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_COOKIE[SessionContext::COOKIE_NAME]);
    }

    public function testReusesValidCookieValue(): void
    {
        $sessionId = str_repeat('c', 64);
        $_COOKIE[SessionContext::COOKIE_NAME] = $sessionId;

        $context = new SessionContext();

        self::assertSame($sessionId, $context->id());
        self::assertSame($sessionId, $context->id());
    }
}
