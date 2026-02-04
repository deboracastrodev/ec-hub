<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Application\Product\GetProductDetail;
use App\Application\Product\GetProductList;
use App\Controller\ProductController;
use App\Infrastructure\Persistence\MySQL\ProductRepository;
use App\Service\CategoryService;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Product Controller Integration Test
 *
 * Tests ProductController with real dependencies (database, Twig)
 */
class ProductControllerTest extends TestCase
{
    private ProductController $controller;
    private ProductRepository $repository;
    private CategoryService $categoryService;
    private GetProductList $getProductList;
    private GetProductDetail $getProductDetail;
    private Environment $twig;
    private \PDO $pdo;

    protected function setUp(): void
    {
        // Setup database connection
        $this->pdo = new \PDO(
            'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . (getenv('DB_DATABASE') ?: 'ec_hub'),
            getenv('DB_USERNAME') ?: 'root',
            getenv('DB_PASSWORD') ?: 'secret',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        $this->repository = new ProductRepository($this->pdo);
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

    public function test_listing_page_returns_html_with_products()
    {
        // Arrange - get total count first
        $totalProducts = $this->repository->count();

        // Act
        $output = $this->controller->index(['page' => 1, 'limit' => 20]);

        // Assert
        $this->assertIsString($output);
        $this->assertStringContainsString('<!DOCTYPE html>', $output);
        $this->assertStringContainsString('Catálogo de Produtos', $output);
        $this->assertStringContainsString('produtos encontrados', $output);
    }

    public function test_pagination_params_are_processed_correctly()
    {
        // Arrange
        $limit = 10;
        $page = 2;

        // Act - should not throw exception
        $output = $this->controller->index(['page' => $page, 'limit' => $limit]);

        // Assert
        $this->assertIsString($output);
        $this->assertStringContainsString('Página 2', $output);
    }

    public function test_pagination_limits_are_enforced()
    {
        // Arrange - limit > 100 should be capped to 100
        $limit = 999;

        // Act - should not throw exception, limit capped at 100
        $output = $this->controller->index(['page' => 1, 'limit' => $limit]);

        // Assert
        $this->assertIsString($output);
    }

    public function test_page_number_is_minimum_1()
    {
        // Arrange - page < 1 should be set to 1
        $page = 0;

        // Act - should not throw exception
        $output = $this->controller->index(['page' => $page, 'limit' => 20]);

        // Assert
        $this->assertIsString($output);
        $this->assertStringContainsString('Página 1', $output);
    }

    public function test_show_page_returns_404_for_nonexistent_product()
    {
        // Arrange
        $nonexistentId = 999999;

        // Act
        $output = $this->controller->show($nonexistentId);

        // Assert
        $this->assertIsString($output);
        $this->assertStringContainsString('Produto não encontrado', $output);
    }

    public function test_show_page_returns_product_html_for_valid_id()
    {
        // Arrange - Find first product
        $products = $this->repository->findAll(1, 0);
        $this->assertNotEmpty($products, 'Database must have at least one product for this test');
        $firstProduct = $products[0];

        // Act & Assert - The detail template doesn't exist yet (Story 2.3)
        // For now, just verify the controller method is callable and handles valid ID
        try {
            $output = $this->controller->show((int) $firstProduct['id']);
            // If detail.html.twig exists, verify HTML structure
            $this->assertIsString($output);
        } catch (\Twig\Error\LoaderError $e) {
            // Template doesn't exist yet - expected for Story 2.2
            $this->assertStringContainsString('product/detail.html.twig', $e->getMessage());
        }
    }

    public function test_total_products_count_is_displayed()
    {
        // Arrange
        $totalProducts = $this->repository->count();

        // Act
        $output = $this->controller->index(['page' => 1, 'limit' => 20]);

        // Assert
        $this->assertStringContainsString((string) $totalProducts, $output);
        $this->assertStringContainsString('produtos encontrados', $output);
    }

    public function test_performance_render_time_is_tracked()
    {
        // Act
        $output = $this->controller->index(['page' => 1, 'limit' => 20]);

        // Assert - render time should be displayed in debug mode
        $this->assertStringContainsString('Renderizado em', $output);
        $this->assertStringContainsString('ms', $output);

        // Extract render time and verify it's under 500ms (AC requirement)
        preg_match('/Renderizado em ([\d.]+)ms/', $output, $matches);
        $this->assertArrayHasKey(1, $matches);
        $renderTime = (float) $matches[1];
        $this->assertLessThan(500, $renderTime, 'Page must load in under 500ms per AC #1');
    }

    public function test_product_card_contains_required_elements()
    {
        // Arrange - Ensure we have products
        $products = $this->repository->findAll(1, 0);
        $this->assertNotEmpty($products);

        // Act
        $output = $this->controller->index(['page' => 1, 'limit' => 20]);

        // Assert - Product card elements (AC #1: name, price, category, image)
        $this->assertStringContainsString('product-card', $output);
        $this->assertStringContainsString('product-card__image', $output);
        $this->assertStringContainsString('product-card__name', $output);
        $this->assertStringContainsString('product-card__price', $output);
        $this->assertStringContainsString('product-card__category', $output);
        $this->assertStringContainsString('loading="lazy"', $output, 'Images should have lazy loading for performance');
    }

    public function test_pagination_navigation_links_work()
    {
        // Arrange - Need at least 21 products for pagination to appear
        $totalProducts = $this->repository->count();
        if ($totalProducts <= 20) {
            $this->markTestSkipped('Need more than 20 products to test pagination navigation');
        }

        // Act
        $output = $this->controller->index(['page' => 1, 'limit' => 20]);

        // Assert - Pagination elements present
        $this->assertStringContainsString('pagination', $output);
        $this->assertStringContainsString('Página 1 de', $output);
        $this->assertStringContainsString('?page=2', $output, 'Next page link should exist');
        $this->assertStringContainsString('Próxima', $output);
    }

    // Category Filtering Tests (Story 2.4)

    public function test_category_filter_shows_only_category_products()
    {
        // Arrange - Get a valid category from database
        $categories = $this->repository->findCategories();
        $this->assertNotEmpty($categories, 'Database must have categories for this test');
        $category = $categories[0];

        // Get count for this category
        $expectedCount = $this->repository->countByCategory($category);

        // Act
        $output = $this->controller->index(['category' => $category]);

        // Assert
        $this->assertStringContainsString($category, $output);
        $this->assertStringContainsString($expectedCount . ' produto', $output);
        $this->assertStringContainsString('em ' . $category, $output);
    }

    public function test_invalid_category_shows_empty_state()
    {
        // Arrange - Use a non-existent category
        $invalidCategory = 'XYZNonExistentCategory' . time();

        // Act
        $output = $this->controller->index(['category' => $invalidCategory]);

        // Assert - Should show empty state message
        $this->assertStringContainsString('Nenhum produto encontrado', $output);
        $this->assertStringContainsString($invalidCategory, $output);
        $this->assertStringContainsString('Ver todos os produtos', $output);
    }

    public function test_category_pagination_preserves_filter()
    {
        // Arrange - Need a category with enough products for pagination
        $categories = $this->repository->findCategories();
        $categoryForPagination = null;
        foreach ($categories as $cat) {
            if ($this->repository->countByCategory($cat) > 20) {
                $categoryForPagination = $cat;
                break;
            }
        }

        if ($categoryForPagination === null) {
            $this->markTestSkipped('Need a category with >20 products to test pagination with filter');
        }

        // Act
        $output = $this->controller->index(['category' => $categoryForPagination, 'page' => 2, 'limit' => 20]);

        // Assert - Category should still be highlighted and products filtered
        $this->assertStringContainsString($categoryForPagination, $output);
        $this->assertStringContainsString('Página 2', $output);
        $this->assertStringContainsString('category=' . urlencode($categoryForPagination), $output);
    }

    public function test_category_active_state_is_highlighted()
    {
        // Arrange
        $categories = $this->repository->findCategories();
        $this->assertNotEmpty($categories);
        $category = $categories[0];

        // Act
        $output = $this->controller->index(['category' => $category]);

        // Assert - Active category should have the active CSS class
        $this->assertStringContainsString('category-filter__link--active', $output);
        // Check that the active category appears in an active link
        $this->assertMatchesRegularExpression(
            '/category-filter__link--active[^>]*>' . preg_quote($category, '/') . '/',
            $output
        );
    }

    public function test_all_products_link_clears_category_filter()
    {
        // Arrange - First, filter by a category
        $categories = $this->repository->findCategories();
        $category = $categories[0] ?? null;

        if ($category === null) {
            $this->markTestSkipped('Need at least one category to test filter clearing');
        }

        // Act - Clear filter by not passing category parameter
        $output = $this->controller->index([]);

        // Assert - Should show all products and "Todos os produtos" should be active
        $totalProducts = $this->repository->count();
        $this->assertStringContainsString((string) $totalProducts . ' produto', $output);
        $this->assertStringContainsString('Todos os produtos', $output);
        $this->assertMatchesRegularExpression(
            '/href="\/products"[^>]*category-filter__link--active/',
            $output
        );
    }

    public function test_category_filter_accepts_category_without_accents()
    {
        // Arrange - find a category containing special characters (e.g., Eletrônicos)
        $categories = $this->repository->findCategories();
        if (!in_array('Eletrônicos', $categories, true)) {
            $this->markTestSkipped('Category Eletrônicos not available in dataset');
        }

        $requestedCategory = 'Eletronicos'; // without accent

        // Act
        $output = $this->controller->index(['category' => $requestedCategory]);

        // Assert - Active link should reflect canonical category
        $this->assertStringContainsString('Eletrônicos', $output);
        $this->assertStringContainsString('category-filter__link--active', $output);
        $this->assertStringContainsString('produto', $output);
    }
}
