<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Http;

use App\Shared\Http\SessionContext;
use PHPUnit\Framework\TestCase;

final class SessionContextTest extends TestCase
{
    private const COOKIE_SECRET = 'test-session-cookie-secret-with-32-chars';

    protected function tearDown(): void
    {
        unset($_COOKIE[SessionContext::COOKIE_NAME], $_COOKIE[SessionContext::SIGNATURE_COOKIE_NAME], $_SERVER['HTTPS']);
    }

    public function testGeneratesOpaqueCookieWithRequiredAttributes(): void
    {
        $emitted = [];
        $context = new SessionContext(self::COOKIE_SECRET, static function (string $name, string $value, array $options) use (&$emitted): bool {
            $emitted[] = compact('name', 'value', 'options');

            return true;
        });

        $id = $context->id();

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $id);
        self::assertCount(2, $emitted);
        self::assertSame(SessionContext::COOKIE_NAME, $emitted[0]['name']);
        self::assertSame($id, $emitted[0]['value']);
        self::assertSame(SessionContext::SIGNATURE_COOKIE_NAME, $emitted[1]['name']);
        self::assertSame(hash_hmac('sha256', $id, self::COOKIE_SECRET), $emitted[1]['value']);

        foreach ($emitted as $cookie) {
            self::assertTrue($cookie['options']['httponly']);
            self::assertSame('Lax', $cookie['options']['samesite']);
            self::assertSame('/', $cookie['options']['path']);
        }
    }

    public function testReusesOnlyAValidSignedServerCookie(): void
    {
        $id = str_repeat('c', 64);
        $_COOKIE[SessionContext::COOKIE_NAME] = $id;
        $_COOKIE[SessionContext::SIGNATURE_COOKIE_NAME] = hash_hmac('sha256', $id, self::COOKIE_SECRET);
        $emissions = 0;
        $context = new SessionContext(self::COOKIE_SECRET, static function () use (&$emissions): bool {
            ++$emissions;

            return true;
        });

        self::assertSame($id, $context->id());
        self::assertSame(0, $emissions);
    }

    public function testRotatesAnUnsignedOrInvalidlySignedCookie(): void
    {
        $fixedId = str_repeat('d', 64);
        $_COOKIE[SessionContext::COOKIE_NAME] = $fixedId;
        $_COOKIE[SessionContext::SIGNATURE_COOKIE_NAME] = str_repeat('a', 64);
        $emitted = [];
        $context = new SessionContext(self::COOKIE_SECRET, static function (string $name, string $value) use (&$emitted): bool {
            $emitted[$name] = $value;

            return true;
        });

        $id = $context->id();

        self::assertNotSame($fixedId, $id);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $id);
        self::assertSame($id, $emitted[SessionContext::COOKIE_NAME]);
        self::assertSame(hash_hmac('sha256', $id, self::COOKIE_SECRET), $emitted[SessionContext::SIGNATURE_COOKIE_NAME]);
    }

    public function testRotatesMalformedCookieValues(): void
    {
        $_COOKIE[SessionContext::COOKIE_NAME] = 'not-a-session-id';
        $_COOKIE[SessionContext::SIGNATURE_COOKIE_NAME] = 'not-a-signature';
        $emissions = 0;
        $context = new SessionContext(self::COOKIE_SECRET, static function () use (&$emissions): bool {
            ++$emissions;

            return true;
        });

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $context->id());
        self::assertSame(2, $emissions);
    }

    public function testRejectsAnInvalidSecretBeforeEmittingCookies(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SESSION_COOKIE_SECRET must contain at least 32 characters.');

        new SessionContext('short-secret');
    }
}
