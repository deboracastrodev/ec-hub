<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * Route matching, extracted out of public/index.php (R5.6).
 *
 * Two route shapes, matched in order:
 * - exact: "METHOD /path" -> no captured params
 * - pattern: a regex fragment matched against the path only, method checked
 *   separately -- captures become $params, in order
 */
final class Router
{
    /**
     * @param array<string, array{controller: class-string, action: string, api?: bool}> $exactRoutes
     * @param array<string, array{method: string, controller: class-string, action: string}> $patternRoutes
     */
    public function __construct(
        private readonly array $exactRoutes,
        private readonly array $patternRoutes
    ) {
    }

    public function match(string $method, string $uri): ?MatchedRoute
    {
        $route = $this->exactRoutes["{$method} {$uri}"] ?? null;
        if ($route !== null) {
            return new MatchedRoute($route['controller'], $route['action'], [], $route['api'] ?? false);
        }

        foreach ($this->patternRoutes as $pattern => $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match('#^' . $pattern . '$#', $uri, $matches)) {
                return new MatchedRoute($route['controller'], $route['action'], array_slice($matches, 1), false);
            }
        }

        return null;
    }
}
