<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Application\Event\TrackProductInteraction;
use App\Application\Monitoring\HttpMetricsRepositoryInterface;
use App\Domain\Event\EventStoreInterface;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Shared\Container\Container;
use App\Shared\Http\SessionContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Tests\Support\InMemoryEventStore;
use Tests\Support\InMemoryHttpMetricsRepository;
use Tests\Support\InMemoryProductRepository;
use Tests\Support\RecordingLogger;

/**
 * DW-32: the real config/bootstrap.php wiring against a real Redis. An event
 * is written through TrackProductInteraction and read back by GET /metrics
 * with the same signed session, each through a freshly required bootstrap (a
 * new container, as in a stateless PHP request). Only the catalog (standing in
 * for MySQL), the logger, the HTTP metrics and the global event store are
 * swapped.
 */
#[Group('redis')]
final class MetricsEventHistoryRedisHttpTest extends TestCase
{
    private const SESSION_TTL = 120;
    private const PUBSUB_COUNTER_KEY = 'metrics:pubsub:published_count';

    private Client $client;
    private string $sessionId;
    private string $userId;
    /** @var list<string> */
    private array $keys;
    private ?int $counterBefore = null;
    private bool $tracked = false;

    protected function setUp(): void
    {
        /** @var array{host: string, port: int} $config */
        $config = require dirname(__DIR__, 3) . '/config/redis.php';
        $this->client = new Client(['scheme' => 'tcp', ...$config]);

        try {
            $this->client->ping();
        } catch (\Throwable $exception) {
            self::markTestSkipped('Redis indisponível: ' . $exception->getMessage());
        }

        $this->sessionId = bin2hex(random_bytes(32));
        $this->userId = 'test-user-' . bin2hex(random_bytes(6));
        $this->keys = [
            'ec-hub:event-history:session:' . $this->sessionId,
            'ec-hub:event-history:user:' . $this->userId,
            // TrackProductInteraction also binds user.id to the session hash.
            'ec-hub:session:' . $this->sessionId,
        ];
        $counter = $this->client->get(self::PUBSUB_COUNTER_KEY);
        $this->counterBefore = $counter === null ? null : (int) $counter;
    }

    protected function tearDown(): void
    {
        try {
            $this->client->del($this->keys);
            $this->restorePublishCounter();
        } catch (\Throwable) {
            // Preserva a falha principal da integração Redis.
        }
    }

    /**
     * The real RedisEventBus increments the shared publish counter. Nothing
     * to undo unless track() ran. Then remove the key when this test created
     * it; otherwise decrement only if the counter really went up since setUp().
     */
    private function restorePublishCounter(): void
    {
        if (! $this->tracked) {
            return;
        }
        if ($this->counterBefore === null) {
            $this->client->del([self::PUBSUB_COUNTER_KEY]);

            return;
        }
        $current = $this->client->get(self::PUBSUB_COUNTER_KEY);
        if ($current !== null && (int) $current > $this->counterBefore) {
            $this->client->decr(self::PUBSUB_COUNTER_KEY);
        }
    }

    #[RunInSeparateProcess]
    public function testEventTrackedThroughTheRealWiringIsShownByMetrics(): void
    {
        putenv('SESSION_TTL=' . self::SESSION_TTL);
        putenv('RECOMMENDATION_ALGORITHM');
        putenv('RECOMMENDATION_AB_TEST');

        $this->container()->get(TrackProductInteraction::class)
            ->track('view', $this->sessionId, 1, $this->userId);
        $this->tracked = true;

        [$sessionKey, $userKey] = $this->keys;
        foreach ([$sessionKey, $userKey] as $key) {
            self::assertSame(1, (int) $this->client->exists($key), $key);
            $ttl = (int) $this->client->ttl($key);
            self::assertGreaterThan(0, $ttl, $key);
            self::assertLessThanOrEqual(self::SESSION_TTL, $ttl, $key);
            self::assertSame(1, (int) $this->client->llen($key), $key);
        }
        self::assertSame($this->client->lrange($sessionKey, 0, -1), $this->client->lrange($userKey, 0, -1));

        $html = $this->requestMetrics();

        self::assertStringContainsString('Total de eventos: 1', $html);
        self::assertStringContainsString('product.viewed', $html);
        self::assertStringContainsString('Produto: 1', $html);
        self::assertStringNotContainsString('Histórico de eventos indisponível no momento.', $html);
    }

    private function container(): Container
    {
        /** @var Container $container */
        $container = require dirname(__DIR__, 3) . '/config/bootstrap.php';
        (new ReflectionProperty(Container::class, 'instances'))->setValue($container, [
            ProductRepositoryInterface::class => new InMemoryProductRepository([
                ['id' => 1, 'name' => 'Produto 1', 'slug' => 'produto-1', 'description' => '', 'price' => 10.0,
                    'category' => 'Livros', 'image_url' => '', 'created_at' => '2026-01-01 10:00:00'],
            ]),
            LoggerInterface::class => new RecordingLogger(),
            HttpMetricsRepositoryInterface::class => new InMemoryHttpMetricsRepository(),
            EventStoreInterface::class => new InMemoryEventStore(),
        ]);

        return $container;
    }

    private function requestMetrics(): string
    {
        header_remove();
        http_response_code(200);

        $GLOBALS['EC_HUB_TEST_CONTAINER'] = $this->container();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/metrics';
        $_GET = [];
        $_COOKIE = [
            SessionContext::COOKIE_NAME => $this->sessionId,
            SessionContext::SIGNATURE_COOKIE_NAME => hash_hmac(
                'sha256',
                $this->sessionId,
                'phpunit-only-session-cookie-secret-32'
            ),
        ];

        try {
            ob_start();
            require dirname(__DIR__, 3) . '/public/index.php';
            $html = (string) ob_get_clean();
        } finally {
            unset($GLOBALS['EC_HUB_TEST_CONTAINER']);
        }

        self::assertSame(200, http_response_code());

        return $html;
    }
}
