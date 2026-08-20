<?php

declare(strict_types=1);

namespace App\Shared\Container;

use Psr\Container\ContainerInterface;

/**
 * Minimal PSR-11 container (R5.7).
 *
 * Replaces the plain nested array config/bootstrap.php used to return:
 * lookups were by magic string key ($container['services']['knn']($container)),
 * with no error until the wrong key was actually hit at runtime, and one
 * registered factory (the 'category' service) had no caller at all.
 *
 * Entries are keyed by FQCN. Each factory receives the container itself, so
 * a factory can pull its own dependencies from it. Resolution is lazy and
 * memoized: a factory only runs the first time its id is requested, and the
 * result is reused after that -- this is what keeps a PDO connection from
 * opening for a request that never needs one (R2.4).
 */
final class Container implements ContainerInterface
{
    /** @var array<string, callable(ContainerInterface): mixed> */
    private array $factories;

    /** @var array<string, mixed> */
    private array $instances = [];

    /**
     * @param array<string, callable(ContainerInterface): mixed> $factories
     */
    public function __construct(array $factories = [])
    {
        $this->factories = $factories;
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (! isset($this->factories[$id])) {
            throw new NotFoundException("No entry found for '{$id}'.");
        }

        return $this->instances[$id] = ($this->factories[$id])($this);
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }
}
