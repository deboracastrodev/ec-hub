<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Redis;

use App\Domain\Session\Repository\SessionRepositoryInterface;
use App\Infrastructure\Redis\SessionRepository;
use App\Shared\Container\Container;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Predis\Client;

final class SessionRepositoryConfigurationTest extends TestCase
{
    public function test_session_ttl_defaults_without_connecting(): void
    {
        $previousTtl = getenv('SESSION_TTL');
        putenv('SESSION_TTL');

        try {
            self::assertSame(['ttl' => 1800], require dirname(__DIR__, 4) . '/config/session.php');
        } finally {
            putenv($previousTtl === false ? 'SESSION_TTL' : "SESSION_TTL={$previousTtl}");
        }
    }

    public function test_session_ttl_rejects_invalid_values(): void
    {
        $previousTtl = getenv('SESSION_TTL');

        foreach (['0', '-1', 'not-a-number'] as $invalidTtl) {
            putenv("SESSION_TTL={$invalidTtl}");

            try {
                require dirname(__DIR__, 4) . '/config/session.php';
                self::fail("SESSION_TTL={$invalidTtl} deveria ser rejeitada.");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            } finally {
                putenv($previousTtl === false ? 'SESSION_TTL' : "SESSION_TTL={$previousTtl}");
            }
        }
    }

    public function test_bootstrap_defers_predis_construction_until_session_repository_is_requested(): void
    {
        /** @var Container $container */
        $container = require dirname(__DIR__, 4) . '/config/bootstrap.php';

        self::assertTrue($container->has(Client::class));
        self::assertTrue($container->has(SessionRepositoryInterface::class));
        self::assertFalse($this->containerHasInstance($container, Client::class));

        $repository = $container->get(SessionRepositoryInterface::class);

        self::assertInstanceOf(SessionRepository::class, $repository);
        self::assertTrue($this->containerHasInstance($container, Client::class));
    }

    public function test_bootstrap_injects_configured_session_ttl_without_connecting(): void
    {
        $previousTtl = getenv('SESSION_TTL');
        putenv('SESSION_TTL=47');

        try {
            /** @var Container $container */
            $container = require dirname(__DIR__, 4) . '/config/bootstrap.php';
            $repository = $container->get(SessionRepositoryInterface::class);

            self::assertInstanceOf(SessionRepository::class, $repository);
            self::assertSame(47, $this->repositoryTtl($repository));
        } finally {
            putenv($previousTtl === false ? 'SESSION_TTL' : "SESSION_TTL={$previousTtl}");
        }
    }

    public function test_repository_rejects_invalid_ttl_identifier_field_and_value_before_persisting(): void
    {
        $client = new Client(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 1]);

        $this->expectException(InvalidArgumentException::class);
        new SessionRepository($client, 0);
    }

    public function test_repository_rejects_invalid_session_identifier_before_connecting(): void
    {
        $repository = new SessionRepository(new Client(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 1]), 60);

        $this->expectException(InvalidArgumentException::class);
        $repository->put('', 'cart.items', []);
    }

    public function test_repository_rejects_invalid_field_before_connecting(): void
    {
        $repository = new SessionRepository(new Client(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 1]), 60);

        $this->expectException(InvalidArgumentException::class);
        $repository->put('session-id', '', []);
    }

    public function test_repository_rejects_non_json_serializable_value_before_connecting(): void
    {
        $repository = new SessionRepository(new Client(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 1]), 60);
        $resource = fopen('php://memory', 'r');

        try {
            $this->expectException(InvalidArgumentException::class);
            $repository->put('session-id', 'cart.items', $resource);
        } finally {
            fclose($resource);
        }
    }

    private function containerHasInstance(Container $container, string $id): bool
    {
        $instances = (function (): array {
            return $this->instances;
        })->bindTo($container, Container::class)();

        return array_key_exists($id, $instances);
    }

    private function repositoryTtl(SessionRepository $repository): int
    {
        return (function (): int {
            return $this->ttl;
        })->bindTo($repository, SessionRepository::class)();
    }
}
