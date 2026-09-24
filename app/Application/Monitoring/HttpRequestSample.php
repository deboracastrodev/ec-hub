<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

/**
 * Story 8.4: one routed HTTP request, normalized so the metrics labels stay
 * bounded (known methods only, valid status codes, non-negative duration).
 */
final readonly class HttpRequestSample
{
    public const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
    public const OTHER_METHOD = 'OTHER';

    public string $method;
    public string $route;
    public int $status;
    public float $durationSeconds;

    public function __construct(string $method, string $route, int $status, float $durationSeconds)
    {
        $method = strtoupper($method);
        $this->method = in_array($method, self::METHODS, true) ? $method : self::OTHER_METHOD;
        $this->route = $route;
        $this->status = $status >= 100 && $status <= 599 ? $status : 500;
        $this->durationSeconds = is_finite($durationSeconds) && $durationSeconds > 0 ? $durationSeconds : 0.0;
    }
}
