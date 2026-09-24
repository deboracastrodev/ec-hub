<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Application\Monitoring\HttpMetricsRepositoryInterface;
use App\Application\Recommendation\TrainedModelCacheInterface;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Recommendation\Repository\AlgorithmMetricsRepositoryInterface;
use App\Domain\Session\Repository\SessionRepositoryInterface;
use App\Infrastructure\Logging\StreamLogger;
use App\Shared\Container\Container;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Tests\Support\InMemoryAlgorithmMetricsRepository;
use Tests\Support\InMemoryHttpMetricsRepository;
use Tests\Support\InMemoryProductRepository;
use Tests\Support\InMemorySessionRepository;
use Tests\Support\InMemoryTrainedModelCache;

/**
 * Story 10.4: GET /api/recommendations through public/index.php and the real
 * config/bootstrap.php wiring writes one 'Recommendations served' JSON line
 * naming the strategy of every item. With LOG_LEVEL absent the container's
 * logger is a StreamLogger at 'info' (on stderr); here the same logger class,
 * at the same level, writes to a temporary file so the line can be read back.
 * Only the catalog (standing in for MySQL) and the Redis-backed stores are
 * swapped for in-memory ones.
 */
final class RecommendationServedLogHttpTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'ec-hub-log-');
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);
    }

    #[RunInSeparateProcess]
    public function testDefaultContainerLoggerIsAStreamLoggerAtInfo(): void
    {
        putenv('LOG_LEVEL');

        /** @var Container $container */
        $container = require dirname(__DIR__, 3) . '/config/bootstrap.php';
        $logger = $container->get(LoggerInterface::class);

        self::assertInstanceOf(StreamLogger::class, $logger);
        self::assertSame(6, (new ReflectionProperty(StreamLogger::class, 'threshold'))->getValue($logger));
        // stderr, nunca php://output: o log não pode se misturar ao corpo das respostas da API.
        self::assertSame('php://stderr', (new ReflectionProperty(StreamLogger::class, 'stream'))->getValue($logger));
    }

    #[RunInSeparateProcess]
    public function testLogLevelIsNormalizedAndAnInvalidOneFallsBackToInfo(): void
    {
        putenv('LOG_LEVEL= WARNING ');
        /** @var Container $container */
        $container = require dirname(__DIR__, 3) . '/config/bootstrap.php';
        self::assertSame(4, (new ReflectionProperty(StreamLogger::class, 'threshold'))->getValue(
            $container->get(LoggerInterface::class)
        ));

        // O aviso vai para o stderr do processo. Fechar o STDERR libera o fd 2, que o
        // próximo fopen reutiliza: assim php://stderr passa a gravar no arquivo temporário
        // (e a saída do processo isolado continua limpa).
        fclose(STDERR);
        $stderr = fopen($this->logFile, 'wb');
        self::assertIsResource($stderr);

        putenv('LOG_LEVEL=verbose');
        /** @var Container $container */
        $container = require dirname(__DIR__, 3) . '/config/bootstrap.php';
        $logger = $container->get(LoggerInterface::class);
        fflush($stderr);

        self::assertInstanceOf(StreamLogger::class, $logger);
        self::assertSame(6, (new ReflectionProperty(StreamLogger::class, 'threshold'))->getValue($logger));
        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(1, $lines);
        $record = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('warning', $record['level']);
        self::assertSame('LOG_LEVEL inválido; usando info.', $record['message']);
        self::assertSame('verbose', $record['context']['log_level']);
    }

    #[RunInSeparateProcess]
    public function testHttpRequestWritesTheServedLineWithTheStrategyOfEachItem(): void
    {
        putenv('LOG_LEVEL=info');
        putenv('RECOMMENDATION_ALGORITHM');
        putenv('RECOMMENDATION_AB_TEST');

        $response = $this->request();

        $served = [];
        foreach (file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if ($record['message'] === 'Recommendations served') {
                $served[] = $record;
            }
        }

        self::assertCount(1, $served);
        self::assertSame('info', $served[0]['level']);
        $context = $served[0]['context'];
        self::assertSame(1, $context['target_product_id']);
        self::assertSame('knn', $context['algorithm']);
        self::assertIsBool($context['fallback_activated']);
        self::assertNotEmpty($context['recommendations']);
        self::assertSame(array_column($response['data'], 'product_id'), array_column($context['recommendations'], 'product_id'));
        foreach ($context['recommendations'] as $item) {
            self::assertArrayHasKey('strategy', $item);
            if ($item['source'] === 'ml') {
                self::assertSame('knn', $item['strategy']);
            } else {
                self::assertContains($item['strategy'], ['category', 'popularity', 'rule_based']);
            }
        }
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
            LoggerInterface::class => new StreamLogger((string) getenv('LOG_LEVEL'), $this->logFile),
            HttpMetricsRepositoryInterface::class => new InMemoryHttpMetricsRepository(),
            TrainedModelCacheInterface::class => new InMemoryTrainedModelCache(),
            SessionRepositoryInterface::class => new InMemorySessionRepository(),
            AlgorithmMetricsRepositoryInterface::class => new InMemoryAlgorithmMetricsRepository(),
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
        for ($id = 1; $id <= 30; ++$id) {
            $rows[] = ['id' => $id, 'name' => 'Produto ' . $id, 'slug' => 'produto-' . $id, 'description' => '',
                'price' => 10.0 * $id, 'category' => $categories[$id % 5], 'image_url' => '',
                'created_at' => '2026-01-01 10:00:00'];
        }

        return $rows;
    }
}
