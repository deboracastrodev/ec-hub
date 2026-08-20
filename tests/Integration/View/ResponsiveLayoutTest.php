<?php

declare(strict_types=1);

namespace Tests\Integration\View;

use App\Application\Product\GetProductDetail;
use App\Application\Product\GetProductList;
use App\Controller\ProductController;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Product\Service\CategoryService;
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
}
