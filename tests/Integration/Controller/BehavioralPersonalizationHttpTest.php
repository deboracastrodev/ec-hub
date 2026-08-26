<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Application\Event\TrackProductInteraction;
use App\Application\Recommendation\GenerateRecommendations;
use App\Controller\ProductInteractionController;
use App\Controller\RecommendationController;
use App\Domain\Event\EventHistoryRepositoryInterface;
use App\Domain\Event\EventPublisherInterface;
use App\Domain\Product\Model\Product;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Recommendation\Service\KNNService;
use App\Domain\Recommendation\Service\RuleBasedFallback;
use App\Domain\Recommendation\ValueObject\RecommendationSettings;
use App\Shared\Container\Container;
use App\Shared\Http\SessionContext;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Retro do Epic 4, item 5: prova pela borda HTTP real que uma interação
 * registrada via POST /api/events altera a resposta de
 * GET /api/recommendations?user_id=... — baseline antes, reordenação depois.
 */
final class BehavioralPersonalizationHttpTest extends TestCase
{
    private const SECRET = 'phpunit-only-session-cookie-secret-32';
    private const SESSION_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    #[RunInSeparateProcess]
    public function testInteractionPostedViaHttpChangesRecommendationResponseForUserId(): void
    {
        $history = new HttpJourneyEventHistoryRepository();
        $this->installContainer($history);
        $_COOKIE[SessionContext::COOKIE_NAME] = self::SESSION_ID;
        $_COOKIE[SessionContext::SIGNATURE_COOKIE_NAME] = hash_hmac('sha256', self::SESSION_ID, self::SECRET);

        $baseline = $this->dispatchRecommendations('1');
        self::assertSame(200, http_response_code());
        self::assertSame([2, 3], array_column($baseline['data'], 'product_id'));

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/events';
        $_GET = [];
        $GLOBALS['EC_HUB_TEST_JSON_BODY'] = '{"product_id":9,"interaction":"click","user_id":"journey-user"}';
        $interaction = $this->dispatch();
        self::assertSame(200, http_response_code());
        self::assertSame('product.clicked', $interaction['data']['event'] ?? null);

        $after = $this->dispatchRecommendations('1');
        self::assertSame(200, http_response_code());
        self::assertSame([3, 2], array_column($after['data'], 'product_id'));
    }

    /** @return array<string, mixed> */
    private function dispatchRecommendations(string $productId): array
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/recommendations?product_id=' . $productId . '&user_id=journey-user';
        $_GET = ['product_id' => $productId, 'user_id' => 'journey-user'];
        unset($GLOBALS['EC_HUB_TEST_JSON_BODY']);

        return $this->dispatch();
    }

    /** @return array<string, mixed> */
    private function dispatch(): array
    {
        header_remove();
        http_response_code(200);

        ob_start();
        require dirname(__DIR__, 3) . '/public/index.php';

        return (array) json_decode((string) ob_get_clean(), true);
    }

    private function installContainer(EventHistoryRepositoryInterface $history): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/views'));
        $sessionContext = new SessionContext(self::SECRET);

        $GLOBALS['EC_HUB_TEST_CONTAINER'] = new Container([
            Environment::class => fn () => $twig,
            SessionContext::class => fn () => $sessionContext,
            RecommendationController::class => fn () => new RecommendationController(
                $this->recommendationPipeline($history),
                new NullLogger()
            ),
            ProductInteractionController::class => fn () => new ProductInteractionController(
                new TrackProductInteraction(
                    $this->products(),
                    new HttpJourneySessionRepository(),
                    $history,
                    $this->createStub(EventPublisherInterface::class),
                    new NullLogger()
                ),
                $sessionContext
            ),
        ]);
    }

    private function recommendationPipeline(EventHistoryRepositoryInterface $history): GenerateRecommendations
    {
        $fallback = $this->createMock(RuleBasedFallback::class);
        $fallback->method('getRecommendations')->willReturn([
            ['product_id' => 2, 'product_name' => 'Fora do interesse', 'category' => 'Outra', 'score' => 1],
            ['product_id' => 3, 'product_name' => 'Do interesse', 'category' => 'Interesse', 'score' => 1],
        ]);

        return new GenerateRecommendations(
            $this->products(),
            $this->createMock(KNNService::class),
            $fallback,
            new NullLogger(),
            RecommendationSettings::fromArray(['min_products_for_ml' => 5]),
            null,
            $history
        );
    }

    private function products(): ProductRepositoryInterface
    {
        $byId = [
            1 => Product::fromArray(['id' => 1, 'name' => 'Alvo', 'category' => 'Base', 'price' => 10]),
            9 => Product::fromArray(['id' => 9, 'name' => 'Interagido', 'category' => 'Interesse', 'price' => 10]),
        ];

        $products = $this->createMock(ProductRepositoryInterface::class);
        $products->method('findAll')->willReturn([$byId[1]]);
        $products->method('findById')->willReturnCallback(static fn (int $id): ?Product => $byId[$id] ?? null);

        return $products;
    }
}

final class HttpJourneySessionRepository implements \App\Domain\Session\Repository\SessionRepositoryInterface
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function save(string $sessionId, string $field, mixed $value): void
    {
        $this->values[$sessionId . ':' . $field] = $value;
    }

    public function compareAndSwap(string $sessionId, string $field, mixed $expected, mixed $value): bool
    {
        $key = $sessionId . ':' . $field;
        if (($this->values[$key] ?? null) !== $expected) {
            return false;
        }

        $this->values[$key] = $value;

        return true;
    }

    public function get(string $sessionId, string $field): mixed
    {
        return $this->values[$sessionId . ':' . $field] ?? null;
    }
}

final class HttpJourneyEventHistoryRepository implements EventHistoryRepositoryInterface
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $sessions = [];
    /** @var array<string, list<array<string, mixed>>> */
    private array $users = [];

    public function append(string $sessionId, ?string $userId, array $event): void
    {
        $this->sessions[$sessionId][] = $event;
        $this->sessions[$sessionId] = array_slice($this->sessions[$sessionId], -50);
        if ($userId !== null && trim($userId) !== '') {
            $this->users[$userId][] = $event;
            $this->users[$userId] = array_slice($this->users[$userId], -50);
        }
    }

    public function getBySession(string $sessionId): array
    {
        return $this->sessions[$sessionId] ?? [];
    }

    public function getByUserId(string $userId): array
    {
        return $this->users[$userId] ?? [];
    }
}
