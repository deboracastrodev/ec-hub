<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Application\Product\ManageProducts;
use App\Application\Recommendation\GenerateRecommendations;
use App\Application\Recommendation\TrainedModelCacheInterface;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Recommendation\Service\CollaborativeFilteringService;
use App\Domain\Recommendation\Service\KNNService;
use App\Domain\Recommendation\Service\NeighborFinderInterface;
use App\Domain\Recommendation\Service\RecommendationStrategy;
use App\Infrastructure\ML\CachedNeighborFinder;
use App\Infrastructure\Redis\RedisTrainedModelCache;
use App\Shared\Container\Container;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tests\Support\InMemoryProductRepository;

/**
 * Story 8.1: config/bootstrap.php resolves RecommendationStrategy from
 * RECOMMENDATION_ALGORITHM. The product repository is pre-seeded in memory
 * (its real factory opens PDO); the Predis client behind the event store is
 * lazy and never connects here.
 */
final class RecommendationAlgorithmBootstrapTest extends TestCase
{
    private string|false $previous;

    protected function setUp(): void
    {
        $this->previous = getenv('RECOMMENDATION_ALGORITHM');
    }

    protected function tearDown(): void
    {
        putenv($this->previous === false
            ? 'RECOMMENDATION_ALGORITHM'
            : "RECOMMENDATION_ALGORITHM={$this->previous}");
    }

    public function testKnnIsTheDefaultStrategy(): void
    {
        putenv('RECOMMENDATION_ALGORITHM');

        $container = $this->container();
        $strategy = $container->get(RecommendationStrategy::class);

        self::assertInstanceOf(KNNService::class, $strategy);
        self::assertSame('knn', $strategy->getName());
        self::assertSame('knn', $container->get(GenerateRecommendations::class)->getAlgorithmName());
    }

    public function testCollaborativeIsSelectedFromTheEnvironment(): void
    {
        putenv('RECOMMENDATION_ALGORITHM= Collaborative ');

        $strategy = $this->container()->get(RecommendationStrategy::class);

        self::assertInstanceOf(CollaborativeFilteringService::class, $strategy);
        self::assertSame('collaborative', $strategy->getName());
    }

    public function testUseCaseIsWiredToTheSelectedStrategy(): void
    {
        putenv('RECOMMENDATION_ALGORITHM=collaborative');

        $container = $this->container();

        self::assertSame('collaborative', $container->get(GenerateRecommendations::class)->getAlgorithmName());
    }

    /** Story 10.1: the KNN index is served through the Redis model cache. */
    public function testNeighborFinderIsTheRedisCachedDecorator(): void
    {
        $container = $this->container();

        self::assertInstanceOf(CachedNeighborFinder::class, $container->get(NeighborFinderInterface::class));
        self::assertInstanceOf(RedisTrainedModelCache::class, $container->get(TrainedModelCacheInterface::class));
    }

    /** Story 10.1: the admin CRUD gets the same model cache, so its writes invalidate it. */
    public function testManageProductsIsWiredToTheModelCache(): void
    {
        $container = $this->container();

        self::assertSame(
            $container->get(TrainedModelCacheInterface::class),
            (new ReflectionProperty(ManageProducts::class, 'modelCache'))->getValue($container->get(ManageProducts::class))
        );
    }

    /** Story 10.1: an unreachable Redis must cost ~0.25 s, not Predis' default 5 s. */
    public function testModelCacheClientHasShortTimeouts(): void
    {
        $cache = $this->container()->get(TrainedModelCacheInterface::class);
        $client = (new ReflectionProperty(RedisTrainedModelCache::class, 'client'))->getValue($cache);
        $parameters = $client->getConnection()->getParameters();

        self::assertSame(0.25, (float) $parameters->timeout);
        self::assertSame(0.25, (float) $parameters->read_write_timeout);
    }

    /** Story 10.1: ManageProducts gets the container's logger for failed invalidations. */
    public function testManageProductsReceivesTheContainerLogger(): void
    {
        $container = $this->container();
        $logger = (new ReflectionProperty(ManageProducts::class, 'logger'))->getValue($container->get(ManageProducts::class));

        self::assertSame($container->get(\Psr\Log\LoggerInterface::class), $logger);
    }

    public function testUnknownAlgorithmFailsFast(): void
    {
        putenv('RECOMMENDATION_ALGORITHM=svd');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('knn, collaborative');

        $this->container()->get(RecommendationStrategy::class);
    }

    private function container(): Container
    {
        /** @var Container $container */
        $container = require dirname(__DIR__, 3) . '/config/bootstrap.php';
        (new ReflectionProperty(Container::class, 'instances'))->setValue($container, [
            ProductRepositoryInterface::class => new InMemoryProductRepository(),
        ]);

        return $container;
    }
}
