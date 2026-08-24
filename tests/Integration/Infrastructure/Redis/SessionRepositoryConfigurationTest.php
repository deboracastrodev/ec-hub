<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Redis;

use App\Domain\Session\Repository\SessionRepositoryInterface;
use App\Infrastructure\Redis\SessionRepository;
use App\Shared\Container\Container;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use ReflectionProperty;

final class SessionRepositoryConfigurationTest extends TestCase
{
    public function test_session_configuration_uses_default_ttl_without_connecting(): void
    {
        $previousTtl = getenv('SESSION_TTL');
        putenv('SESSION_TTL');

        try {
            $config = require dirname(__DIR__, 4) . '/config/session.php';

            self::assertSame(1800, $config['ttl']);
            self::assertSame('phpunit-only-session-cookie-secret-32', $config['cookie_secret']);
        } finally {
            putenv($previousTtl === false ? 'SESSION_TTL' : "SESSION_TTL={$previousTtl}");
        }
    }

    public function test_session_configuration_rejects_non_positive_or_non_integer_ttl(): void
    {
        $previousTtl = getenv('SESSION_TTL');

        try {
            foreach (['', '0', '-1', '1.5', '2147483648', 'not-a-ttl'] as $invalidTtl) {
                putenv("SESSION_TTL={$invalidTtl}");

                try {
                    require dirname(__DIR__, 4) . '/config/session.php';
                    self::fail("SESSION_TTL={$invalidTtl} deveria ser rejeitada.");
                } catch (\InvalidArgumentException) {
                    self::addToAssertionCount(1);
                }
            }
        } finally {
            putenv($previousTtl === false ? 'SESSION_TTL' : "SESSION_TTL={$previousTtl}");
        }
    }

    public function test_session_configuration_rejects_a_missing_or_short_cookie_secret(): void
    {
        $previousSecret = getenv('SESSION_COOKIE_SECRET');

        try {
            foreach (['', 'short-secret'] as $invalidSecret) {
                putenv("SESSION_COOKIE_SECRET={$invalidSecret}");

                try {
                    require dirname(__DIR__, 4) . '/config/session.php';
                    self::fail('SESSION_COOKIE_SECRET inválido deveria ser rejeitado.');
                } catch (\InvalidArgumentException) {
                    self::addToAssertionCount(1);
                }
            }
        } finally {
            putenv($previousSecret === false ? 'SESSION_COOKIE_SECRET' : "SESSION_COOKIE_SECRET={$previousSecret}");
        }
    }

    public function test_repository_rejects_a_ttl_outside_the_redis_expiration_range(): void
    {
        $client = new Client(['host' => '127.0.0.1', 'port' => 1]);

        foreach ([0, 2147483648] as $invalidTtl) {
            try {
                new SessionRepository($client, $invalidTtl);
                self::fail("TTL {$invalidTtl} deveria ser rejeitado antes de acessar Redis.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_repository_rejects_invalid_identifiers_before_connecting_to_redis(): void
    {
        $repository = new SessionRepository(new Client(['host' => '127.0.0.1', 'port' => 1]), 30);

        foreach ([['   ', 'user.id'], ['valid-session', ''], ['valid-session', '   ']] as [$sessionId, $field]) {
            try {
                $repository->save($sessionId, $field, 7);
                self::fail('Identificador de sessão e campo inválidos devem falhar antes de acessar Redis.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_bootstrap_defers_predis_creation_until_session_repository_is_requested(): void
    {
        $previousHost = getenv('REDIS_HOST');
        $previousPort = getenv('REDIS_PORT');
        $previousTtl = getenv('SESSION_TTL');
        putenv('REDIS_HOST=redis.test');
        putenv('REDIS_PORT=6381');
        putenv('SESSION_TTL=60');

        try {
            /** @var Container $container */
            $container = require dirname(__DIR__, 4) . '/config/bootstrap.php';
            self::assertTrue($container->has(Client::class));
            self::assertTrue($container->has(SessionRepositoryInterface::class));
            self::assertSame([], $this->instancesOf($container));

            $repository = $container->get(SessionRepositoryInterface::class);

            self::assertInstanceOf(SessionRepository::class, $repository);
            self::assertInstanceOf(Client::class, $container->get(Client::class));
            self::assertSame('redis.test', $container->get(Client::class)->getConnection()->getParameters()->host);
            self::assertSame(6381, $container->get(Client::class)->getConnection()->getParameters()->port);
        } finally {
            putenv($previousHost === false ? 'REDIS_HOST' : "REDIS_HOST={$previousHost}");
            putenv($previousPort === false ? 'REDIS_PORT' : "REDIS_PORT={$previousPort}");
            putenv($previousTtl === false ? 'SESSION_TTL' : "SESSION_TTL={$previousTtl}");
        }
    }

    /** @return array<string, mixed> */
    private function instancesOf(Container $container): array
    {
        $property = new ReflectionProperty(Container::class, 'instances');

        return $property->getValue($container);
    }
}
