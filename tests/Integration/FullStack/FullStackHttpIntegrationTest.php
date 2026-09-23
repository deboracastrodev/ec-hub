<?php

declare(strict_types=1);

namespace Tests\Integration\FullStack;

use App\Domain\Event\EventStoreInterface;
use App\Shared\Container\Container;
use App\Shared\Http\SessionContext;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Tests\Support\RequiresTestDatabase;

#[Group('db')]
#[Group('redis')]
final class FullStackHttpIntegrationTest extends TestCase
{
    use RequiresTestDatabase;

    private const REDIS_DATABASE = 15;
    private const SESSION_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private Client $redis;
    private Container $container;
    private PDO $database;
    private string $errorLog;

    protected function setUp(): void
    {
        $this->errorLog = (string) ini_get('error_log');
        ini_set('error_log', '/dev/null');
        $this->database = $this->connectToTestDatabaseOrSkip();
        putenv('DB_DATABASE=ec_hub_test');

        $config = require dirname(__DIR__, 3) . '/config/redis.php';
        $this->redis = new Client(['scheme' => 'tcp', ...$config, 'database' => self::REDIS_DATABASE]);
        try {
            $this->redis->flushdb();
        } catch (\Throwable $exception) {
            $this->markTestSkipped('Redis indisponível: ' . $exception->getMessage());
        }

        $this->container = $this->bootContainerWithTestRedis();
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->exec('TRUNCATE TABLE products');
        }
        if (isset($this->redis)) {
            $this->redis->flushdb();
        }
        ini_set('error_log', $this->errorLog);
        unset($GLOBALS['EC_HUB_TEST_CONTAINER'], $GLOBALS['EC_HUB_TEST_JSON_BODY']);
    }

    #[RunInSeparateProcess]
    public function test_product_list_returns_seeded_products_through_http_bootstrap(): void
    {
        $response = $this->dispatch('GET', '/products');

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('Camera Compacta', $response['body']);
        self::assertStringContainsString('Notebook Pro', $response['body']);
    }

    #[RunInSeparateProcess]
    public function test_product_detail_returns_seeded_product_and_missing_product_returns_404(): void
    {
        $this->expectOutputRegex('/\[ProductController\] Produto não encontrado: does-not-exist/');

        $detail = $this->dispatch('GET', '/products/camera-compacta');
        self::assertSame(200, $detail['status']);
        self::assertStringContainsString('Camera Compacta', $detail['body']);

        $missing = $this->dispatch('GET', '/products/does-not-exist');
        self::assertSame(404, $missing['status']);
        self::assertStringContainsString('Produto não encontrado', $missing['body']);
    }

    #[RunInSeparateProcess]
    public function test_recommendations_endpoint_returns_json_from_real_dependencies(): void
    {
        $response = $this->dispatch('GET', '/api/recommendations?product_id=3');
        $payload = json_decode($response['body'], true);

        self::assertSame(200, $response['status']);
        self::assertIsArray($payload);
        self::assertArrayHasKey('data', $payload);
        self::assertArrayHasKey('meta', $payload);
        self::assertSame('ml', $payload['meta']['source']);
    }

    #[RunInSeparateProcess]
    public function test_event_endpoint_publishes_and_persists_event(): void
    {
        $response = $this->dispatch('POST', '/api/events', ['product_id' => 1, 'interaction' => 'click']);
        $payload = json_decode($response['body'], true);

        self::assertSame(200, $response['status']);
        self::assertSame('product.clicked', $payload['data']['event']);
        self::assertSame('1', (string) $this->redis->get('metrics:pubsub:published_count'));

        $events = $this->container->get(EventStoreInterface::class)->getByEvent('product.clicked');
        self::assertCount(1, $events);
        self::assertSame(1, $events[0]['data']['product_id']);
    }

    #[RunInSeparateProcess]
    public function test_cart_endpoint_persists_items_in_redis_session(): void
    {
        $response = $this->dispatch('POST', '/api/cart/items', ['product_id' => 2, 'quantity' => 3]);
        $payload = json_decode($response['body'], true);

        self::assertSame(200, $response['status']);
        self::assertSame(3, $payload['data']['quantity']);
        self::assertSame(['2' => 3], json_decode((string) $this->redis->hget('ec-hub:session:' . self::SESSION_ID, 'cart.items'), true));
    }

    #[RunInSeparateProcess]
    public function test_personalized_recommendations_promote_previously_clicked_product(): void
    {
        $event = $this->dispatch('POST', '/api/events', ['product_id' => 1, 'interaction' => 'click', 'user_id' => 'full-stack-user']);
        self::assertSame(200, $event['status']);

        $response = $this->dispatch('GET', '/api/recommendations?product_id=3&user_id=full-stack-user');
        $payload = json_decode($response['body'], true);

        self::assertSame(200, $response['status']);
        self::assertSame(1, $payload['data'][0]['product_id']);
    }

    private function bootContainerWithTestRedis(): Container
    {
        $productionContainer = require dirname(__DIR__, 3) . '/config/bootstrap.php';
        $factories = (new \ReflectionProperty(Container::class, 'factories'))->getValue($productionContainer);
        $factories[Client::class] = fn (): Client => $this->redis;

        return new Container($factories);
    }

    /** @param array<string, mixed>|null $body @return array{status: int, body: string} */
    private function dispatch(string $method, string $uri, ?array $body = null): array
    {
        header_remove();
        http_response_code(200);
        $_SERVER = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri];
        $_GET = [];
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $_GET);
        $_COOKIE = [
            SessionContext::COOKIE_NAME => self::SESSION_ID,
            SessionContext::SIGNATURE_COOKIE_NAME => hash_hmac('sha256', self::SESSION_ID, 'phpunit-only-session-cookie-secret-32'),
        ];
        $GLOBALS['EC_HUB_TEST_CONTAINER'] = $this->container;
        if ($body !== null) {
            $GLOBALS['EC_HUB_TEST_JSON_BODY'] = json_encode($body, JSON_THROW_ON_ERROR);
        } else {
            unset($GLOBALS['EC_HUB_TEST_JSON_BODY']);
        }

        ob_start();
        require dirname(__DIR__, 3) . '/public/index.php';

        return ['status' => http_response_code(), 'body' => (string) ob_get_clean()];
    }
}