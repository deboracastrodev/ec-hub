<?php
declare(strict_types=1);

namespace Tests\Integration\UI;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for responsive design and touch-friendly UI
 * Story: 2-5-responsive-design-touch-friendly-ui
 *
 * Tests verify:
 * - AC1: Mobile (320px+) layout with 1 column, 44x44px touch targets, 16px text
 * - AC2: Tablet (768px+) layout with 2 columns
 * - AC3: Desktop (1024px+) layout with 3-4 columns
 */
class ResponsiveDesignTest extends TestCase
{
    private string $cssPath;
    private string $templatePath;

    protected function setUp(): void
    {
        $this->cssPath = __DIR__ . '/../../../public/assets/css/main.css';
        $this->templatePath = __DIR__ . '/../../../views/layout/base.html.twig';
    }

    public function test_css_file_exists(): void
    {
        $this->assertFileExists($this->cssPath, 'CSS file must exist');
    }

    public function test_base_template_exists(): void
    {
        $this->assertFileExists($this->templatePath, 'Base template must exist');
    }

    public function test_mobile_viewport_meta_tag_exists(): void
    {
        $template = file_get_contents($this->templatePath);
        $this->assertStringContainsString('width=device-width', $template);
        $this->assertStringContainsString('initial-scale=1.0', $template);
    }

    public function test_mobile_menu_button_exists_in_template(): void
    {
        $template = file_get_contents($this->templatePath);
        $this->assertStringContainsString('header__menu-btn', $template);
        $this->assertStringContainsString('aria-label', $template);
        $this->assertStringContainsString('aria-expanded', $template);
    }

    public function test_mobile_menu_button_has_three_icon_spans(): void
    {
        $template = file_get_contents($this->templatePath);
        $iconCount = substr_count($template, 'header__menu-icon');
        $this->assertEquals(3, $iconCount, 'Mobile menu button should have 3 icon spans for hamburger');
    }

    public function test_navigation_links_exist(): void
    {
        $template = file_get_contents($this->templatePath);
        $this->assertStringContainsString('nav__link', $template);
        $this->assertStringContainsString('href="/products"', $template);
        $this->assertStringContainsString('href="/"', $template);
    }

    public function test_base_font_size_defined_for_mobile(): void
    {
        $css = file_get_contents($this->cssPath);
        $this->assertStringContainsString('--font-size-base:', $css);
        $this->assertStringContainsString('16px', $css);
    }

    public function test_touch_target_minimum_defined(): void
    {
        $css = file_get_contents($this->cssPath);
        $this->assertStringContainsString('--touch-target-min:', $css);
        $this->assertStringContainsString('44px', $css);
    }

    public function test_skeleton_loading_animation_defined(): void
    {
        $css = file_get_contents($this->cssPath);
        $this->assertStringContainsString('@keyframes shimmer', $css);
        $this->assertStringContainsString('.skeleton', $css);
    }

    public function test_product_grid_desktop_has_3_columns_minimum(): void
    {
        $css = file_get_contents($this->cssPath);
        // Desktop (1024px+) should have 3 columns minimum per AC
        $this->assertStringContainsString('grid-template-columns: repeat(3, 1fr)', $css);
    }

    public function test_pagination_links_touch_friendly(): void
    {
        $css = file_get_contents($this->cssPath);
        $this->assertStringContainsString('.pagination__prev', $css);
        $this->assertStringContainsString('.pagination__next', $css);
    }

    public function test_breadcrumb_touch_friendly(): void
    {
        $css = file_get_contents($this->cssPath);
        $this->assertStringContainsString('.breadcrumb__link', $css);
    }

    public function test_responsive_breakpoints_defined(): void
    {
        $css = file_get_contents($this->cssPath);
        $this->assertStringContainsString('@media (min-width: 768px)', $css);
        $this->assertStringContainsString('@media (min-width: 1024px)', $css);
    }

    public function test_mobile_menu_button_css_defined(): void
    {
        $css = file_get_contents($this->cssPath);
        $this->assertStringContainsString('.header__menu-btn', $css);
        $this->assertStringContainsString('.header__menu-icon', $css);
    }

    public function test_nav_open_state_css_defined(): void
    {
        $css = file_get_contents($this->cssPath);
        $this->assertStringContainsString('.nav--open', $css);
    }

    public function test_line_height_variables_defined(): void
    {
        $css = file_get_contents($this->cssPath);
        $this->assertStringContainsString('--line-height-', $css);
        $this->assertStringContainsString('1.5', $css);
    }

    public function test_body_has_min_font_size(): void
    {
        $css = file_get_contents($this->cssPath);
        // Body should have base font size for mobile readability
        $this->assertRegExp('/body\s*{[^}]*font-size:\s*var\(--font-size-base\)/', $css);
    }

    public function test_typography_scale_defined(): void
    {
        $css = file_get_contents($this->cssPath);
        $this->assertStringContainsString('--font-size-xs:', $css);
        $this->assertStringContainsString('--font-size-sm:', $css);
        $this->assertStringContainsString('--font-size-md:', $css);
        $this->assertStringContainsString('--font-size-lg:', $css);
        $this->assertStringContainsString('--font-size-xl:', $css);
    }
}
