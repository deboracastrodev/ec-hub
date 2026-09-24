<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Application\Monitoring\HttpMetricsRepositoryInterface;
use App\Application\Recommendation\TrainedModelCacheInterface;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Infrastructure\Redis\RedisTrainedModelCache;
use App\Shared\Container\Container;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Tests\Support\InMemoryHttpMetricsRepository;
use Tests\Support\InMemoryProductRepository;
use Tests\Support\RecordingLogger;

/**
 * Story 10.1 (R4.4, FR126, FR10): the real config/bootstrap.php wiring against
 * a real Redis. Two consecutive GET /api/recommendations, each through a
 * freshly required bootstrap (a new container, as in a stateless PHP
 * request), over a 100-product catalog. Only the catalog (standing in for
 * MySQL), the logger and the model key (random, so ec-hub:model:knn is never
 * touched) are swapped.
 */
#[Group('redis')]
final class RecommendationModelCacheRedisHttpTest extends TestCase
{
    private Client $client;
    private string $key;
    private RecordingLogger $logger;

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
        $this->key = 'test-model-knn-http-' . bin2hex(random_bytes(6));
        $this->logger = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        try {
            $this->client->del([$this->key]);
        } catch (\Throwable) {
            // Preserva a falha principal da integração Redis.
        }
    }

    #[RunInSeparateProcess]
    public function testSecondRequestThroughTheRealWiringLoadsTheModelFromRedis(): void
    {
        putenv('RECOMMENDATION_ALGORITHM');
        putenv('RECOMMENDATION_AB_TEST');

        $first = $this->request();
        $second = $this->request();

        self::assertSame(
            ['Modelo KNN treinado', 'Modelo KNN carregado do cache'],
            array_values(array_filter(
                $this->logger->messages('info'),
                static fn (string $message): bool => str_starts_with($message, 'Modelo KNN')
            ))
        );
        self::assertSame(1, (int) $this->client->exists($this->key));
        self::assertSame('ml', $second['meta']['source']);
        self::assertNotEmpty($second['data']);
        self::assertSame($first['data'], $second['data']);
        self::assertLessThan(200, $second['meta']['response_time_ms']);
    }

    /** @return array<string, mixed> */
    private function request(): array
    {
        header_remove();
        http_response_code(200);

        /** @var Container $container */
        $container = require dirname(__DIR__, 3) . '/config/bootstrap.php';
        (new ReflectionProperty(Container::class, 'instances'))->setValue($container, [
            ProductRepositoryInterface::class => new InMemoryProductRepository(self::rows()),
            LoggerInterface::class => $this->logger,
            HttpMetricsRepositoryInterface::class => new InMemoryHttpMetricsRepository(),
            TrainedModelCacheInterface::class => new RedisTrainedModelCache($this->client, $this->key),
        ]);
        $GLOBALS['EC_HUB_TEST_CONTAINER'] = $container;

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/recommendations?product_id=1&limit=5';
        $_GET = ['product_id' => '1', 'limit' => '5'];
        $_COOKIE = [];

        try {
            ob_start();
            require dirname(__DIR__, 3) . '/public/index.php';
            $decoded = json_decode((string) ob_get_clean(), true);
        } finally {
            unset($GLOBALS['EC_HUB_TEST_CONTAINER']);
        }

        self::assertSame(200, http_response_code());
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(): array
    {
        $categories = ['Eletrônicos', 'Esportes', 'Casa', 'Livros', 'Moda'];
        $rows = [];
        for ($id = 1; $id <= 100; ++$id) {
            $rows[] = ['id' => $id, 'name' => 'Produto ' . $id, 'slug' => 'produto-' . $id, 'description' => '',
                'price' => 10.0 * $id, 'category' => $categories[$id % 5], 'image_url' => '',
                'created_at' => '2026-01-01 10:00:00'];
        }

        return $rows;
    }
}
