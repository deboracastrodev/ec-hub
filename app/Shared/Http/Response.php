<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * HTTP response returned by the admin controllers (Story 8.3) and emitted by
 * public/index.php. Admin pages are private and must not be framed
 * (clickjacking): every factory sets SECURITY_HEADERS.
 */
final class Response
{
    public const SECURITY_HEADERS = [
        'Cache-Control' => 'no-store',
        'X-Frame-Options' => 'DENY',
        'Content-Security-Policy' => "frame-ancestors 'none'",
    ];

    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly array $headers = []
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/html; charset=utf-8'] + self::SECURITY_HEADERS);
    }

    /** 303 See Other: the follow-up request is always a GET. */
    public static function redirect(string $location): self
    {
        return new self(303, '', ['Location' => $location] + self::SECURITY_HEADERS);
    }
}
