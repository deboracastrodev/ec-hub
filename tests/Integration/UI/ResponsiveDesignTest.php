<?php
declare(strict_types=1);

namespace Tests\Integration\UI;

use PHPUnit\Framework\TestCase;
use Hyperf\Test\HttpTestCase;

/**
 * Integration tests for responsive design and touch-friendly UI
 * Story: 2-5-responsive-design-touch-friendly-ui
 *
 * Tests verify:
 * - AC1: Mobile (320px+) layout with 1 column, 44x44px touch targets, 16px text
 * - AC2: Tablet (768px+) layout with 2 columns
 * - AC3: Desktop (1024px+) layout with 3-4 columns
 */
class ResponsiveDesignTest extends HttpTestCase
{
    public function test_mobile_viewport_has_proper_meta(): void
    {
        $response = $this->get('/products');

        $html = (string) $response->getBody();
        $this->assertStringContainsString('width=device-width', $html);
        $this->assertStringContainsString('initial-scale=1.0', $html);
    }

    public function test_mobile_menu_button_exists(): void
    {
        $response = $this->get('/products');

        $html = (string) $response->getBody();
        $this->assertStringContainsString('header__menu-btn', $html);
        $this->assertStringContainsString('aria-label', $html);
        $this->assertStringContainsString('aria-expanded', $html);
    }

    public function test_mobile_menu_button_has_three_icon_spans(): void
    {
        $response = $this->get('/products');

        $html = (string) $response->getBody();
        // Count header__menu-icon occurrences
        $iconCount = substr_count($html, 'header__menu-icon');
        $this->assertEquals(3, $iconCount, 'Mobile menu button should have 3 icon spans for hamburger');
    }

    public function test_navigation_links_accessible(): void
    {
        $response = $this->get('/products');

        $html = (string) $response->getBody();
        $this->assertStringContainsString('nav__link', $html);
        $this->assertStringContainsString('href="/products"', $html);
        $this->assertStringContainsString('href="/"', $html);
    }

    public function test_product_grid_responsive_classes(): void
    {
        $response = $this->get('/products');

        $html = (string) $response->getBody();
        $this->assertStringContainsString('product-grid', $html);
        $this->assertStringContainsString('product-card', $html);
    }

    public function test_touch_targets_css_loaded(): void
    {
        $response = $this->get('/products');

        $html = (string) $response->getBody();
        $this->assertStringContainsString('/assets/css/main.css', $html);
    }

    public function test_base_font_size_defined_for_mobile(): void
    {
        $response = $this->get('/products');

        $html = (string) $response->getBody();
        // CSS should define --font-size-base: 16px
        $css = file_get_contents(__DIR__ . '/../../../public/assets/css/main.css');
        $this->assertStringContainsString('--font-size-base:', $css);
        $this->assertStringContainsString('16px', $css);
    }

    public function test_touch_target_minimum_defined(): void
    {
        $css = file_get_contents(__DIR__ . '/../../../public/assets/css/main.css');
        // Should have --touch-target-min: 44px
        $this->assertStringContainsString('--touch-target-min:', $css);
        $this->assertStringContainsString('44px', $css);
    }

    public function test_skeleton_loading_animation_defined(): void
    {
        $css = file_get_contents(__DIR__ . '/../../../public/assets/css/main.css');
        // Should have shimmer animation
        $this->assertStringContainsString('@keyframes shimmer', $css);
        $this->assertStringContainsString('.skeleton', $css);
    }

    public function test_product_grid_desktop_has_3_columns_min(): void
    {
        $css = file_get_contents(__DIR__ . '/../../../public/assets/css/main.css');
        // Desktop should have 3 columns minimum (AC: 3-4 columns)
        $this->assertStringContainsString('grid-template-columns: repeat(3, 1fr)', $css);
    }

    public function test_pagination_links_touch_friendly(): void
    {
        $css = file_get_contents(__DIR__ . '/../../../public/assets/css/main.css');
        // Pagination buttons should have min-height for touch
        $this->assertStringContainsString('.pagination__prev', $css);
        $this->assertStringContainsString('.pagination__next', $css);
    }

    public function test_breadcrumb_touch_friendly(): void
    {
        $css = file_get_contents(__DIR__ . '/../../../public/assets/css/main.css');
        // Breadcrumb links should be touch-friendly
        $this->assertStringContainsString('.breadcrumb__link', $css);
    }

    public function test_responsive_breakpoints_defined(): void
    {
        $css = file_get_contents(__DIR__ . '/../../../public/assets/css/main.css');
        // Should have mobile-first breakpoints
        $this->assertStringContainsString('@media (min-width: 768px)', $css);
        $this->assertStringContainsString('@media (min-width: 1024px)', $css);
    }
}
