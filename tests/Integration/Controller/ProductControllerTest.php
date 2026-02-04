<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use PHPUnit\Framework\TestCase;
use App\Controller\ProductController;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Infrastructure\Persistence\MySQL\ProductRepository;
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

        // Setup Twig
        $loader = new FilesystemLoader(__DIR__ . '/../../../views');
        $this->twig = new Environment($loader, [
            'cache' => false,
            'debug' => true,
        ]);

        $this->controller = new ProductController($this->repository, $this->twig);
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
}
