<?php
declare(strict_types=1);

namespace Tests\Unit\Domain\SEO\Service;

use App\Domain\SEO\Service\MetaTagsService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MetaTagsService
 * Story: 2-6-seo-accessibility-features
 */
class MetaTagsServiceTest extends TestCase
{
    private MetaTagsService $service;

    protected function setUp(): void
    {
        $this->service = new MetaTagsService();
    }

    public function test_generate_for_product_listing_page(): void
    {
        $data = [
            'category' => 'Eletrônicos',
            'category_slug' => 'eletronicos',
            'product_count' => 42,
        ];

        $meta = $this->service->generateForPage('product.listing', $data);

        $this->assertIsArray($meta);
        $this->assertArrayHasKey('title', $meta);
        $this->assertArrayHasKey('description', $meta);
        $this->assertArrayHasKey('keywords', $meta);
        $this->assertArrayHasKey('canonical', $meta);
        $this->assertArrayHasKey('og', $meta);
        $this->assertArrayHasKey('twitter', $meta);
        $this->assertArrayHasKey('structured_data', $meta);

        // Verify title contains category
        $this->assertStringContainsString('Eletrônicos', $meta['title']);
        $this->assertStringContainsString('Produtos', $meta['title']);

        // Verify canonical URL
        $this->assertStringContainsString('products?category=eletronicos', $meta['canonical']);
    }

    public function test_generate_for_product_listing_without_category(): void
    {
        $data = [
            'product_count' => 100,
        ];

        $meta = $this->service->generateForPage('product.listing', $data);

        $this->assertStringContainsString('Catálogo de Produtos', $meta['title']);
        $this->assertStringEndsWith('/products', $meta['canonical']);
    }

    public function test_generate_for_product_detail_page(): void
    {
        $data = [
            'product_id' => '123',
            'product_name' => 'Smartphone XYZ',
            'product_slug' => 'smartphone-xyz',
            'description' => 'Smartphone de última geração',
            'category' => 'Eletrônicos',
            'price' => '1.999,00',
            'image_url' => 'https://example.com/image.jpg',
        ];

        $meta = $this->service->generateForPage('product.detail', $data);

        $this->assertStringContainsString('Smartphone XYZ', $meta['title']);
        $this->assertStringContainsString('Eletrônicos', $meta['description']);
        $this->assertStringContainsString('R$ 1.999,00', $meta['description']);
        $this->assertStringEndsWith('/products/smartphone-xyz', $meta['canonical']);
    }

    public function test_generate_for_home_page(): void
    {
        $meta = $this->service->generateForPage('home', []);

        $this->assertStringContainsString('ec-hub', $meta['title']);
        $this->assertStringContainsString('E-commerce com ML', $meta['title']);
        $this->assertStringEndsWith('/', $meta['canonical']);
    }

    public function test_open_graph_tags_are_complete(): void
    {
        $data = [
            'product_name' => 'Test Product',
            'image_url' => 'https://example.com/test.jpg',
        ];

        $meta = $this->service->generateForPage('product.detail', $data);
        $og = $meta['og'];

        $this->assertArrayHasKey('og:type', $og);
        $this->assertArrayHasKey('og:site_name', $og);
        $this->assertArrayHasKey('og:title', $og);
        $this->assertArrayHasKey('og:description', $og);
        $this->assertArrayHasKey('og:image', $og);
        $this->assertArrayHasKey('og:url', $og);
        $this->assertArrayHasKey('og:locale', $og);

        $this->assertEquals('website', $og['og:type']);
        $this->assertEquals('ec-hub', $og['og:site_name']);
        $this->assertEquals('pt_BR', $og['og:locale']);
    }

    public function test_twitter_card_tags_are_complete(): void
    {
        $data = [
            'product_name' => 'Test Product',
            'image_url' => 'https://example.com/test.jpg',
        ];

        $meta = $this->service->generateForPage('product.detail', $data);
        $twitter = $meta['twitter'];

        $this->assertArrayHasKey('twitter:card', $twitter);
        $this->assertArrayHasKey('twitter:site', $twitter);
        $this->assertArrayHasKey('twitter:title', $twitter);
        $this->assertArrayHasKey('twitter:description', $twitter);
        $this->assertArrayHasKey('twitter:image', $twitter);

        $this->assertEquals('summary_large_image', $twitter['twitter:card']);
        $this->assertEquals('@echub', $twitter['twitter:site']);
    }

    public function test_structured_data_for_product_detail(): void
    {
        $data = [
            'product_name' => 'Test Product',
            'description' => 'Test description',
            'category' => 'Test Category',
            'price' => '99.99',
            'image_url' => 'https://example.com/test.jpg',
        ];

        $meta = $this->service->generateForPage('product.detail', $data);
        $structuredData = $meta['structured_data'];

        $this->assertIsString($structuredData);
        $this->assertStringContainsString('"@type":"Product"', $structuredData);
        $this->assertStringContainsString('"name":"Test Product"', $structuredData);
        $this->assertStringContainsString('"@type":"Offer"', $structuredData);
        $this->assertStringContainsString('"priceCurrency":"BRL"', $structuredData);
    }

    public function test_keywords_are_generated(): void
    {
        $data = [
            'category' => 'Eletrônicos',
        ];

        $meta = $this->service->generateForPage('product.listing', $data);

        $this->assertStringContainsString('eletrônicos', strtolower($meta['keywords']));
        $this->assertStringContainsString('e-commerce', $meta['keywords']);
        $this->assertStringContainsString('machine learning', $meta['keywords']);
    }
}
