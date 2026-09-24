<?php

declare(strict_types=1);

namespace App\Shared\Http;

final class MatchedRoute
{
    /**
     * @param class-string $controller
     * @param list<string> $params Captured path segments, in order
     * @param string $route Bounded-cardinality route label (Story 8.4): the
     *        exact path, or the pattern with each capture group as {param}
     */
    public function __construct(
        public readonly string $controller,
        public readonly string $action,
        public readonly array $params,
        public readonly bool $isApi,
        public readonly bool $isAdmin = false,
        public readonly string $route = ''
    ) {
    }
}
