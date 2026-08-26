<?php

declare(strict_types=1);

namespace Tests\Integration\View;

use App\Application\Product\GetProductDetail;
use App\Application\Product\GetProductList;
use App\Controller\MetricsController;
use App\Controller\ProductController;
use App\Domain\Event\EventHistoryRepositoryInterface;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Product\Service\CategoryService;
use App\Domain\Session\Repository\SessionRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryProductRepository;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Responsive Layout Test
 *
 * Tests that the product listing page has proper responsive CSS
 * per Task 4 requirements (AC #1).
 *
 * Exercises real templates/controller but a fake in-memory repository:
 * this is a view-structure test, not a database test (R2.5).
 */
class ResponsiveLayoutTest extends TestCase
{
    private ProductController $controller;
    private ProductRepositoryInterface $repository;
    private CategoryService $categoryService;
    private GetProductList $getProductList;
    private GetProductDetail $getProductDetail;
    private Environment $twig;

    protected function setUp(): void
    {
        $this->repository = new InMemoryProductRepository();
        $this->categoryService = new CategoryService($this->repository);
        $this->getProductList = new GetProductList($this->repository, $this->categoryService);
        $this->getProductDetail = new GetProductDetail($this->repository);

        // Setup Twig
        $loader = new FilesystemLoader(__DIR__ . '/../../../views');
        $this->twig = new Environment($loader, [
            'cache' => false,
            'debug' => true,
        ]);

        $this->controller = new ProductController($this->getProductList, $this->getProductDetail, $this->twig);
    }

    public function test_base_template_exists_and_is_semantic_html5()
    {
        // Arrange & Act
        $output = $this->controller->index(['page' => 1, 'limit' => 20]);

        // Assert - Semantic HTML5 elements (AC #1 requirement)
        $this->assertStringContainsString('<!DOCTYPE html>', $output);
        $this->assertStringContainsString('<html', $output);
        $this->assertStringContainsString('<head>', $output);
        $this->assertStringContainsString('<body>', $output);
        $this->assertStringContainsString('<header', $output, 'Should have semantic header');
        $this->assertStringContainsString('<nav', $output, 'Should have semantic nav');
        $this->assertStringContainsString('<main', $output, 'Should have semantic main');
        $this->assertStringContainsString('<footer', $output, 'Should have semantic footer');
    }

    public function test_responsive_viewport_meta_tag_exists()
    {
        // Arrange & Act
        $output = $this->controller->index(['page' => 1, 'limit' => 20]);

        // Assert - Mobile-first viewport meta tag (Task 4.1)
        $this->assertStringContainsString('<meta name="viewport"', $output);
        $this->assertStringContainsString('width=device-width', $output);
        $this->assertStringContainsString('initial-scale=1.0', $output);
    }

    public function test_css_file_is_linked()
    {
        // Arrange & Act
        $output = $this->controller->index(['page' => 1, 'limit' => 20]);

        // Assert
        $this->assertStringContainsString('<link rel="stylesheet"', $output);
        $this->assertStringContainsString('/assets/css/main.css', $output);
    }

    public function test_bem_css_classes_are_used()
    {
        // Arrange & Act
        $output = $this->controller->index(['page' => 1, 'limit' => 20]);

        // Assert - BEM methodology (Task 2.5)
        $this->assertStringContainsString('product-listing', $output);
        $this->assertStringContainsString('product-listing__header', $output, 'BEM: Block__Element pattern');
        $this->assertStringContainsString('product-listing__title', $output);
        $this->assertStringContainsString('product-grid', $output);
        $this->assertStringContainsString('product-card', $output);
        $this->assertStringContainsString('product-card__link', $output, 'BEM: Block__Element pattern');
        $this->assertStringContainsString('product-card__image', $output);
        $this->assertStringContainsString('product-card__content', $output);
        $this->assertStringContainsString('product-card__name', $output);
        $this->assertStringContainsString('product-card__category', $output);
        $this->assertStringContainsString('product-card__price', $output);
    }

    public function test_product_listing_page_structure_is_complete()
    {
        // Arrange & Act
        $output = $this->controller->index(['page' => 1, 'limit' => 20]);

        // Assert - Complete structure per Task 2
        $this->assertStringContainsString('class="product-listing"', $output);
        $this->assertStringContainsString('class="product-listing__header"', $output);
        $this->assertStringContainsString('class="product-listing__title"', $output);
        $this->assertStringContainsString('class="product-listing__count"', $output);
        $this->assertStringContainsString('class="product-grid"', $output);
    }

    public function test_metrics_dashboard_is_semantic_and_uses_responsive_grid(): void
    {
        $history = new ResponsiveMetricsHistoryRepository([
            ['event' => 'product.viewed', 'product_id' => 7, 'timestamp' => '2026-08-21T10:00:00+00:00'],
        ]);
        $controller = new MetricsController($history, $this->twig);

        $output = $controller->index([], [], 'current-session');
        $stylesheet = (string) file_get_contents(__DIR__ . '/../../../public/assets/css/main.css');

        $this->assertStringContainsString('<section class="dashboard" aria-labelledby="metrics-dashboard-title">', $output);
        $this->assertStringContainsString('<header class="dashboard__header">', $output);
        $this->assertStringContainsString('ec-hub - System Metrics Dashboard', $output);
        $this->assertStringContainsString('<h2 class="dashboard__panel-title" id="event-history-title">Histórico de eventos</h2>', $output);
        $this->assertStringContainsString('<section class="dashboard__panel dashboard__panel--impact" aria-labelledby="impact-panel-title">', $output);
        $this->assertStringContainsString('<dl class="dashboard__metrics" aria-label="Indicadores da recomendação">', $output);
        $this->assertStringContainsString('ML: indisponível', $output);
        $this->assertStringContainsString('class="dashboard__event-list"', $output);
        $this->assertStringContainsString('<details class="dashboard__disclosure">', $output);
        $this->assertStringContainsString('Current Session', $output);
        $this->assertStringContainsString('.dashboard__disclosure-control {', $stylesheet);
        $this->assertStringContainsString('.dashboard__grid {', $stylesheet);
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr);', $stylesheet);
        $this->assertStringContainsString("@media (min-width: 768px) {\n    .dashboard {", $stylesheet);
        $this->assertStringContainsString('grid-template-columns: repeat(2, minmax(0, 1fr));', $stylesheet);
        $this->assertStringContainsString("@media (min-width: 1024px) {\n    .dashboard__grid {", $stylesheet);
        $this->assertStringContainsString('grid-template-columns: repeat(3, minmax(0, 1fr));', $stylesheet);
        $this->assertStringContainsString('.dashboard__metrics {', $stylesheet);
        $this->assertStringContainsString('grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));', $stylesheet);
    }

    #[DataProvider('metricsTargetWidths')]
    public function test_metrics_session_disclosure_keeps_long_content_safe_and_native_at_target_widths(
        int $width,
        string $layoutContractPattern,
    ): void {
        $longProductId = str_repeat('W', 240);
        $history = new ResponsiveMetricsHistoryRepository([
            ['event' => 'product.viewed', 'product_id' => $longProductId, 'timestamp' => '2026-08-26T10:00:00+00:00'],
        ]);
        $sessions = new class ($longProductId) implements SessionRepositoryInterface {
            public function __construct(private readonly string $longProductId)
            {
            }

            public function save(string $sessionId, string $field, mixed $value): void
            {
            }

            public function compareAndSwap(string $sessionId, string $field, mixed $expected, mixed $value): bool
            {
                return false;
            }

            public function get(string $sessionId, string $field): mixed
            {
                $side = [
                    'source' => 'ml',
                    'latency_ms' => 10.0,
                    'avg_confidence' => 80.0,
                    'count' => 1,
                    'generated_at' => '2026-08-26T10:00:00+00:00',
                    'product_ids' => [$this->longProductId],
                ];

                return ['current' => $side, 'previous' => $side];
            }
        };
        $output = (new MetricsController($history, $this->twig, $sessions))->index([], [], 'current-session');
        $stylesheet = (string) file_get_contents(__DIR__ . '/../../../public/assets/css/main.css');

        self::assertContains($width, [320, 768, 1024]);
        self::assertMatchesRegularExpression($layoutContractPattern, $stylesheet);
        self::assertStringContainsString('<details class="dashboard__disclosure">', $output);
        self::assertStringContainsString('<summary class="dashboard__disclosure-control">', $output);
        self::assertStringContainsString($longProductId, $output);
        self::assertMatchesRegularExpression(
            '~\.dashboard__product-list\s*\{[^}]*overflow-wrap:\s*anywhere;[^}]*\}~s',
            $stylesheet,
        );
        self::assertMatchesRegularExpression(
            '~\.dashboard__disclosure\s*\{[^}]*min-width:\s*0;[^}]*\}~s',
            $stylesheet,
        );
        self::assertMatchesRegularExpression(
            '~\.dashboard__disclosure-control\s*\{[^}]*min-height:\s*44px;[^}]*\}~s',
            $stylesheet,
        );
        self::assertStringContainsString('.dashboard__disclosure-control:focus-visible {', $stylesheet);
    }

    /** @return array<string, array{int, string}> */
    public static function metricsTargetWidths(): array
    {
        return [
            'mobile 320 px' => [
                320,
                '~\.dashboard__grid\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\);[^}]*\}~s',
            ],
            'tablet 768 px' => [
                768,
                '~@media\s*\(min-width:\s*768px\)\s*\{(?:(?!@media).)*?\.dashboard__grid\s*\{[^}]*grid-template-columns:\s*repeat\(2,\s*minmax\(0,\s*1fr\)\);[^}]*\}~s',
            ],
            'desktop 1024 px' => [
                1024,
                '~@media\s*\(min-width:\s*1024px\)\s*\{(?:(?!@media).)*?\.dashboard__grid\s*\{[^}]*grid-template-columns:\s*repeat\(3,\s*minmax\(0,\s*1fr\)\);[^}]*\}~s',
            ],
        ];
    }
}

final class ResponsiveMetricsHistoryRepository implements EventHistoryRepositoryInterface
{
    /** @param list<array<string, mixed>> $events */
    public function __construct(private readonly array $events)
    {
    }

    public function append(string $sessionId, ?string $userId, array $event): void
    {
    }

    public function getBySession(string $sessionId): array
    {
        return $this->events;
    }

    public function getByUserId(string $userId): array
    {
        return [];
    }
}
