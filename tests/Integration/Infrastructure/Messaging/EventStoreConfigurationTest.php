<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Messaging;

use App\Domain\Event\EventStoreInterface;
use App\Infrastructure\Messaging\RedisEventStore;
use App\Shared\Container\Container;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class EventStoreConfigurationTest extends TestCase
{
    private const ENV = 'EVENT_STORE_MAX_EVENTS_PER_LIST';

    private string|false $previous;

    protected function setUp(): void
    {
        $this->previous = getenv(self::ENV);
    }

    protected function tearDown(): void
    {
        putenv($this->previous === false ? self::ENV : self::ENV . '=' . $this->previous);
    }

    public function test_missing_or_empty_retention_uses_the_default(): void
    {
        putenv(self::ENV);
        self::assertSame(5000, $this->load()['max_events_per_list']);

        putenv(self::ENV . '=');
        self::assertSame(5000, $this->load()['max_events_per_list']);
    }

    public function test_it_reads_a_valid_retention(): void
    {
        putenv(self::ENV . '=100');

        self::assertSame(100, $this->load()['max_events_per_list']);
    }

    public function test_it_rejects_a_non_positive_or_non_integer_retention(): void
    {
        foreach (['0', '-1', '1.5', 'x', '2147483648'] as $invalid) {
            putenv(self::ENV . '=' . $invalid);

            try {
                $this->load();
                self::fail(self::ENV . "={$invalid} deveria ser rejeitada.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_bootstrap_injects_the_configured_retention_into_the_event_store(): void
    {
        putenv(self::ENV . '=123');

        /** @var Container $container */
        $container = require dirname(__DIR__, 4) . '/config/bootstrap.php';
        $store = $container->get(EventStoreInterface::class);

        self::assertInstanceOf(RedisEventStore::class, $store);
        self::assertSame(123, (new ReflectionProperty(RedisEventStore::class, 'maxEventsPerList'))->getValue($store));
    }

    /** @return array{max_events_per_list: int} */
    private function load(): array
    {
        return require dirname(__DIR__, 4) . '/config/event_store.php';
    }
}
