<?php

declare(strict_types=1);

namespace Tests\Performance;

use App\Shared\Container\Container;
use App\Shared\Http\SessionContext;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Tests\Support\RequiresTestDatabase;

/**
 * FR58 / NFR-PERF-07: 1.000 requisições pelo mesmo public/index.php com o
 * container de produção reaproveitado (simula um worker persistente) não
 * podem crescer a memória em 10% ou mais. O php -S isola cada requisição,
 * por isso a medida é in-process — ver Design Notes da spec 6-5.
 */
#[Group('performance')]
#[Group('db')]
#[Group('redis')]
final class MemoryGrowthTest extends TestCase
{
    use RequiresTestDatabase;

    private const REDIS_DATABASE = 15;
    private const WARMUP_REQUESTS = 100;
    private const MEASURED_REQUESTS = 1000;
    private const MAX_GROWTH_PERCENT = 10.0;
    private const SESSION_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

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

        $config = require dirname(__DIR__, 2) . '/config/redis.php';
        $this->redis = new Client(['scheme' => 'tcp', ...$config, 'database' => self::REDIS_DATABASE]);

        try {
            $this->redis->flushdb();
        } catch (\Throwable $exception) {
            unset($this->redis);
            $this->markTestSkipped('Redis indisponível: ' . $exception->getMessage());
        }

        $productionContainer = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $factories = (new \ReflectionProperty(Container::class, 'factories'))->getValue($productionContainer);
        $factories[Client::class] = fn (): Client => $this->redis;
        $this->container = new Container($factories);
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
    public function test_memory_grows_less_than_10_percent_over_1000_requests(): void
    {
        /** @var list<array{id: int, slug: string}> $products */
        $products = $this->database->query('SELECT id, slug FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        self::assertNotEmpty($products, 'ec_hub_test deveria estar semeado');

        $routes = [];
        foreach ($products as $product) {
            $routes[] = '/api/recommendations?product_id=' . $product['id'] . '&limit=5';
            $routes[] = '/products/' . $product['slug'];
            $routes[] = '/metrics';
            $routes[] = '/health';
        }

        $this->dispatchMany($routes, self::WARMUP_REQUESTS);
        $baseline = $this->measureMemory();

        $this->dispatchMany($routes, self::MEASURED_REQUESTS);
        $final = $this->measureMemory();

        $growthPercent = ($final - $baseline) / $baseline * 100;
        self::assertLessThan(
            self::MAX_GROWTH_PERCENT,
            $growthPercent,
            sprintf(
                'Memória cresceu %.2f%% em %d requisições (baseline %d bytes, final %d bytes)',
                $growthPercent,
                self::MEASURED_REQUESTS,
                $baseline,
                $final
            )
        );
    }

    /** @param list<string> $routes */
    private function dispatchMany(array $routes, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $uri = $routes[$i % count($routes)];
            $status = $this->dispatch($uri);
            self::assertSame(200, $status, "GET {$uri} (requisição {$i}) não respondeu 200");
        }
    }

    private function measureMemory(): int
    {
        gc_collect_cycles();

        return memory_get_usage();
    }

    private function dispatch(string $uri): int
    {
        header_remove();
        http_response_code(200);
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri];
        $_GET = [];
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $_GET);
        $_COOKIE = [
            SessionContext::COOKIE_NAME => self::SESSION_ID,
            SessionContext::SIGNATURE_COOKIE_NAME => hash_hmac('sha256', self::SESSION_ID, (string) getenv('SESSION_COOKIE_SECRET')),
        ];
        $GLOBALS['EC_HUB_TEST_CONTAINER'] = $this->container;

        ob_start();

        try {
            require dirname(__DIR__, 2) . '/public/index.php';
        } finally {
            ob_end_clean();
        }

        return (int) http_response_code();
    }
}
