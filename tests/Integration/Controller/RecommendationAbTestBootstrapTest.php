<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Application\Recommendation\GenerateRecommendations;
use App\Application\Recommendation\RecommendationExperiment;
use App\Controller\AbTestResultsController;
use App\Controller\MetricsController;
use App\Controller\RecommendationController;
use App\Domain\Event\EventBusStatus;
use App\Domain\Event\EventBusStatusInterface;
use App\Domain\Event\EventHistoryRepositoryInterface;
use App\Domain\Event\EventStoreInterface;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Recommendation\Repository\AlgorithmMetricsRepositoryInterface;
use App\Domain\Recommendation\Service\AbTestAssigner;
use App\Domain\Recommendation\Service\RecommendationStrategy;
use App\Domain\Session\Repository\SessionRepositoryInterface;
use App\Shared\Container\Container;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tests\Support\InMemoryAlgorithmMetricsRepository;
use Tests\Support\InMemoryEventStore;
use Tests\Support\InMemoryProductRepository;

/**
 * Story 8.2: config/bootstrap.php wires the A/B experiment from
 * RECOMMENDATION_AB_TEST. The real container is used; only entries that
 * would do I/O (MySQL, Redis) are pre-seeded with in-memory fakes.
 *
 * Env vars are set to an empty value rather than unset: the immutable
 * Dotenv in bootstrap.php would repopulate an unset variable from a local
 * .env, while an empty one is kept as-is.
 */
final class RecommendationAbTestBootstrapTest extends TestCase
{
    private const ENV_VARS = ['RECOMMENDATION_ALGORITHM', 'RECOMMENDATION_AB_TEST', 'APP_DEBUG'];

    /** @var array<string, string|false> */
    private array $previous = [];

    private InMemoryAlgorithmMetricsRepository $metrics;

    protected function setUp(): void
    {
        foreach (self::ENV_VARS as $name) {
            $this->previous[$name] = getenv($name);
        }
        putenv('RECOMMENDATION_ALGORITHM=');
        putenv('RECOMMENDATION_AB_TEST=');
        // No Twig cache: renders the current template, never a stale compiled one.
        putenv('APP_DEBUG=true');
        $this->metrics = new InMemoryAlgorithmMetricsRepository();
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    public function testAbOnAssignsCoherentlyAndBuildsTheAssignedArm(): void
    {
        putenv('RECOMMENDATION_AB_TEST=knn,collaborative');
        $container = $this->container();
        $experiment = $container->get(RecommendationExperiment::class);
        $assigner = new AbTestAssigner();

        foreach (['s', 'session-2', 'session-3', 'session-4'] as $sessionId) {
            $assignment = $experiment->assign($sessionId, null);

            self::assertSame($assigner->variantFor($sessionId), $assignment->variant);
            self::assertSame($assignment->variant === 'A' ? 'knn' : 'collaborative', $assignment->algorithm);
            self::assertSame($assignment->algorithm, $experiment->useCaseFor($assignment->algorithm)->getAlgorithmName());
        }

        // The .env default arm reuses the container's GenerateRecommendations.
        self::assertSame($container->get(GenerateRecommendations::class), $experiment->useCaseFor('knn'));
        self::assertNotSame($experiment->useCaseFor('knn'), $experiment->useCaseFor('collaborative'));
        self::assertSame('collaborative', $experiment->useCaseFor('collaborative')->getAlgorithmName());
    }

    public function testWiredRecommendationControllerServesTheAssignedArmAndRecordsASample(): void
    {
        putenv('RECOMMENDATION_AB_TEST=knn,collaborative');
        $container = $this->container();
        $sessionId = $this->subjectAssignedTo('B');

        $response = $container->get(RecommendationController::class)
            ->getRecommendations(['product_id' => '1'], null, $sessionId);

        self::assertSame('collaborative', $response['meta']['algorithm']);
        self::assertSame('B', $response['meta']['ab_variant']);
        self::assertCount(1, $this->metrics->recorded);
        self::assertSame('collaborative', $this->metrics->recorded[0]['algorithm']);
        self::assertSame($sessionId, $this->metrics->recorded[0]['sample']->subjectId);
        self::assertSame(count($response['data']), $this->metrics->recorded[0]['sample']->totalItems);
    }

    public function testWiredMetricsControllerRendersTheComparisonPanel(): void
    {
        putenv('RECOMMENDATION_AB_TEST=knn,collaborative');
        $container = $this->container();

        $html = $container->get(MetricsController::class)->index([], [], 'current-session');

        self::assertStringContainsString('<th scope="row">knn</th>', $html);
        self::assertStringContainsString('<th scope="row">collaborative</th>', $html);
        self::assertStringContainsString('Teste A/B: ativo · A = knn · B = collaborative', $html);
        self::assertStringNotContainsString('Comparação indisponível', $html);
    }

    public function testAbOffKeepsKnnAsTheDefault(): void
    {
        putenv('RECOMMENDATION_AB_TEST=');
        $container = $this->container();
        $experiment = $container->get(RecommendationExperiment::class);

        $assignment = $experiment->assign('session-1', null);

        self::assertSame('knn', $assignment->algorithm);
        self::assertNull($assignment->variant);
        self::assertSame('knn', $container->get(RecommendationStrategy::class)->getName());
        self::assertInstanceOf(AbTestResultsController::class, $container->get(AbTestResultsController::class));

        $response = $container->get(RecommendationController::class)
            ->getRecommendations(['product_id' => '1'], null, 'session-1');

        self::assertSame('knn', $response['meta']['algorithm']);
        self::assertNull($response['meta']['ab_variant']);
        self::assertSame('knn', $this->metrics->recorded[0]['algorithm']);
    }

    public function testInvalidAbTestFailsFast(): void
    {
        putenv('RECOMMENDATION_AB_TEST=knn,svd');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('RECOMMENDATION_AB_TEST');

        $this->container()->get(RecommendationController::class);
    }

    private function subjectAssignedTo(string $variant): string
    {
        $assigner = new AbTestAssigner();
        for ($i = 0; ; ++$i) {
            $sessionId = 'session-' . $i;
            if ($assigner->variantFor($sessionId) === $variant) {
                return $sessionId;
            }
        }
    }

    private function container(): Container
    {
        /** @var Container $container */
        $container = require dirname(__DIR__, 3) . '/config/bootstrap.php';
        (new ReflectionProperty(Container::class, 'instances'))->setValue($container, [
            ProductRepositoryInterface::class => new InMemoryProductRepository(),
            AlgorithmMetricsRepositoryInterface::class => $this->metrics,
            EventStoreInterface::class => new InMemoryEventStore(),
            EventHistoryRepositoryInterface::class => new class () implements EventHistoryRepositoryInterface {
                public function append(string $sessionId, ?string $userId, array $event): void
                {
                }

                public function getBySession(string $sessionId): array
                {
                    return [];
                }

                public function getByUserId(string $userId): array
                {
                    return [];
                }
            },
            SessionRepositoryInterface::class => new class () implements SessionRepositoryInterface {
                /** @var array<string, array<string, mixed>> */
                private array $data = [];

                public function save(string $sessionId, string $field, mixed $value): void
                {
                    $this->data[$sessionId][$field] = $value;
                }

                public function get(string $sessionId, string $field): mixed
                {
                    return $this->data[$sessionId][$field] ?? null;
                }

                public function compareAndSwap(string $sessionId, string $field, mixed $expected, mixed $value): bool
                {
                    if (($this->data[$sessionId][$field] ?? null) !== $expected) {
                        return false;
                    }
                    $this->data[$sessionId][$field] = $value;

                    return true;
                }
            },
            EventBusStatusInterface::class => new class () implements EventBusStatusInterface {
                public function status(): EventBusStatus
                {
                    return new EventBusStatus(connected: true, publishedCount: 0);
                }
            },
        ]);

        return $container;
    }
}
