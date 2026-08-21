<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Http;

use App\Shared\Http\SessionContext;
use PHPUnit\Framework\TestCase;

final class SessionContextTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_COOKIE[SessionContext::COOKIE_NAME], $_SERVER['HTTPS']);
    }

    public function testGeneratesOpaqueCookieWithRequiredAttributes(): void
    {
        $emitted = [];
        $context = new SessionContext(static function (string $name, string $value, array $options) use (&$emitted): bool {
            $emitted = compact('name', 'value', 'options');

            return true;
        });

        $id = $context->id();

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $id);
        self::assertSame(SessionContext::COOKIE_NAME, $emitted['name']);
        self::assertSame($id, $emitted['value']);
        self::assertTrue($emitted['options']['httponly']);
        self::assertSame('Lax', $emitted['options']['samesite']);
        self::assertSame('/', $emitted['options']['path']);
    }

    public function testReusesOnlyAValidServerCookie(): void
    {
        $id = str_repeat('c', 64);
        $_COOKIE[SessionContext::COOKIE_NAME] = $id;
        $emissions = 0;
        $context = new SessionContext(static function () use (&$emissions): bool {
            ++$emissions;

            return true;
        });

        self::assertSame($id, $context->id());
        self::assertSame(0, $emissions);
    }
}
