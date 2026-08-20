<?php

declare(strict_types=1);

namespace App\Shared\Http;

final class MatchedRoute
{
    /**
     * @param class-string $controller
     * @param list<string> $params Captured path segments, in order
     */
    public function __construct(
        public readonly string $controller,
        public readonly string $action,
        public readonly array $params,
        public readonly bool $isApi
    ) {
    }
}
