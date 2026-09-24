<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Application\Recommendation\GenerateRecommendations;
use App\Application\Recommendation\RecommendationExperiment;
use App\Controller\AbTestResultsController;
use App\Controller\MetricsController;
use App\Controller\RecommendationController;
use App\Domain\Event\EventHistoryRepositoryInterface;
use App\Domain\Recommendation\Service\AbTestAssigner;
use App\Domain\Recommendation\ValueObject\RecommendationSettings;
use App\Shared\Container\Container;
use App\Shared\Http\SessionContext;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\InMemoryAlgorithmMetricsRepository;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Story 8.2: A/B test end to end through public/index.php -- recommendations
 * served by both arms feed GET /api/ab-tests/results and the /metrics panel,
 * which read the same RecommendationExperiment::results().
 */
final class AbTestResultsHttpEndpointTest extends TestCase
{
    private const SECRET = 'phpunit-only-session-cookie-secret-32';

    private Environment $twig;

    /** @var array<class-string, object> */
    private array $controllers = [];

    #[RunInSeparateProcess]
    public function testRecommendationsFromBothArmsFeedTheExportAndThePanel(): void
    {
        $metrics = new InMemoryAlgorithmMetricsRepository();
        $experiment = new RecommendationExperiment(
            RecommendationSettings::fromArray(['ab_test' => 'knn,collaborative']),
            fn (string $algorithm): GenerateRecommendations => $this->useCase($algorithm),
            new AbTestAssigner(),
            $metrics,
            new NullLogger(),
        );
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/views'), ['strict_variables' => true]);
        $history = $this->createStub(EventHistoryRepositoryInterface::class);
        $history->method('getBySession')->willReturn([]);
        $this->twig = $twig;
        $this->controllers = [
            RecommendationController::class => new RecommendationController(
                $experiment->useCaseFor('knn'),
                new NullLogger(),
                null,
                $experiment
            ),
            AbTestResultsController::class => new AbTestResultsController($experiment),
            MetricsController::class => new MetricsController(
                $history,
                $twig,
                null,
                null,
                fn (): array => $experiment->results()
            ),
        ];

        $assigner = new AbTestAssigner();
        $sessions = [];
        for ($i = 0; count(array_unique(array_values($sessions))) < 2 || count($sessions) < 4; ++$i) {
            $sessionId = str_pad(dechex($i), 64, '0', STR_PAD_LEFT);
            $sessions[$sessionId] = $assigner->variantFor($sessionId);
        }

        $served = 0;
        foreach ($sessions as $sessionId => $variant) {
            $body = $this->dispatch('/api/recommendations', ['product_id' => '1'], $sessionId);
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            ++$served;

            self::assertSame($variant, $decoded['meta']['ab_variant']);
            self::assertSame($variant === 'A' ? 'knn' : 'collaborative', $decoded['meta']['algorithm']);
        }

        $export = json_decode($this->dispatch('/api/ab-tests/results', [], array_key_first($sessions)), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, http_response_code());
        self::assertTrue($export['data']['enabled']);
        self::assertSame(['A' => 'knn', 'B' => 'collaborative'], $export['data']['variants']);
        self::assertSame(['knn', 'collaborative'], array_column($export['data']['algorithms'], 'algorithm'));
        self::assertSame($served, array_sum(array_column($export['data']['algorithms'], 'requests')));
        foreach ($export['data']['algorithms'] as $row) {
            self::assertGreaterThan(0, $row['requests']);
            self::assertSame($row['requests'], $row['unique_subjects']);
            self::assertSame(100.0, (float) $row['ml_item_rate']);
            self::assertSame(80.0, (float) $row['avg_score']);
        }
        self::assertArrayHasKey('generated_at', $export['meta']);

        $html = $this->dispatch('/metrics', [], array_key_first($sessions));

        self::assertStringContainsString('Comparação de algoritmos (A/B)', $html);
        self::assertStringContainsString('<th scope="row">knn</th>', $html);
        self::assertStringContainsString('<th scope="row">collaborative</th>', $html);
        self::assertStringContainsString('Teste A/B: ativo · A = knn · B = collaborative', $html);
        self::assertStringContainsString('<a href="/api/ab-tests/results">Exportar resultados (JSON)</a>', $html);
    }

    #[RunInSeparateProcess]
    public function testExportFailureReachesTheErrorHandler(): void
    {
        $experiment = new RecommendationExperiment(
            RecommendationSettings::fromArray([]),
            fn (string $algorithm): GenerateRecommendations => $this->useCase($algorithm),
            new AbTestAssigner(),
            new InMemoryAlgorithmMetricsRepository(failOnGet: true),
            new NullLogger(),
        );
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/views'), ['strict_variables' => true]);
        $this->twig = $twig;
        $this->controllers = [AbTestResultsController::class => new AbTestResultsController($experiment)];

        $body = $this->dispatch('/api/ab-tests/results', [], str_repeat('a', 64));

        self::assertSame(500, http_response_code());
        self::assertSame(500, json_decode($body, true, 512, JSON_THROW_ON_ERROR)['code']);
    }

    private function useCase(string $algorithm): GenerateRecommendations
    {
        $useCase = $this->createStub(GenerateRecommendations::class);
        $useCase->method('getAlgorithmName')->willReturn($algorithm);
        $useCase->method('execute')->willReturn([
            ['product_id' => 2, 'name' => 'Mouse', 'score' => 80.0, 'source' => 'ml'],
        ]);

        return $useCase;
    }

    /** @param array<string, string> $query */
    private function dispatch(string $uri, array $query, string $sessionId): string
    {
        // A fresh container per request: SessionContext memoizes the session id.
        $entries = [
            Environment::class => fn () => $this->twig,
            SessionContext::class => fn () => new SessionContext(self::SECRET),
        ];
        foreach ($this->controllers as $class => $controller) {
            $entries[$class] = fn () => $controller;
        }
        $GLOBALS['EC_HUB_TEST_CONTAINER'] = new Container($entries);
        $_COOKIE[SessionContext::COOKIE_NAME] = $sessionId;
        $_COOKIE[SessionContext::SIGNATURE_COOKIE_NAME] = hash_hmac('sha256', $sessionId, self::SECRET);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $uri . ($query === [] ? '' : '?' . http_build_query($query));
        $_GET = $query;
        header_remove();
        http_response_code(200);

        ob_start();
        require dirname(__DIR__, 3) . '/public/index.php';

        return (string) ob_get_clean();
    }
}
