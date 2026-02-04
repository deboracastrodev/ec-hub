<?php
declare(strict_types=1);

namespace App\Domain\SEO\Service;

class MetaTagsService
{
    private string $siteName;
    private string $siteUrl;
    private string $defaultDescription;
    private string $defaultImage;

    public function __construct()
    {
        $this->siteName = 'ec-hub';
        $this->siteUrl = 'https://ec-hub.example.com';
        $this->defaultDescription = 'Catálogo de produtos do ec-hub - E-commerce com Machine Learning em PHP 7.4';
        $this->defaultImage = $this->siteUrl . '/assets/images/og-default.jpg';
    }

    /**
     * Generate meta tags for a page
     *
     * @param string $page Page identifier (e.g., 'product.listing', 'product.detail')
     * @param array $data Page-specific data
     * @return array Meta tags configuration
     */
    public function generateForPage(string $page, array $data = []): array
    {
        $metaTags = [
            'title' => $this->generateTitle($page, $data),
            'description' => $this->generateDescription($page, $data),
            'keywords' => $this->generateKeywords($page, $data),
            'canonical' => $this->generateCanonical($page, $data),
            'og' => $this->generateOpenGraph($page, $data),
            'twitter' => $this->generateTwitterCard($page, $data),
            'structured_data' => $this->generateStructuredData($page, $data),
        ];

        return $metaTags;
    }

    private function generateTitle(string $page, array $data): string
    {
        switch ($page) {
            case 'product.listing':
                $category = $data['category'] ?? null;
                if ($category) {
                    return "{$category} - Produtos - {$this->siteName}";
                }
                return "Catálogo de Produtos - {$this->siteName}";

            case 'product.detail':
                $productName = $data['product_name'] ?? 'Produto';
                return "{$productName} - {$this->siteName}";

            case 'home':
            default:
                return "{$this->siteName} - E-commerce com ML em PHP 7.4";
        }
    }

    private function generateDescription(string $page, array $data): string
    {
        switch ($page) {
            case 'product.listing':
                $category = $data['category'] ?? 'todos';
                $count = $data['product_count'] ?? 0;
                return "Explore {$count} produtos na categoria {$category}. Encontre as melhores ofertas com recomendações personalizadas via ML.";

            case 'product.detail':
                $productName = $data['product_name'] ?? 'Produto';
                $category = $data['category'] ?? '';
                $price = $data['price'] ?? '';
                return $productName . ' - ' . $category . ' por R$ ' . $price . '. Compre agora com as melhores recomendações personalizadas.';

            default:
                return $this->defaultDescription;
        }
    }

    private function generateKeywords(string $page, array $data): string
    {
        switch ($page) {
            case 'product.listing':
                $category = $data['category'] ?? 'produtos';
                return strtolower("{$category}, e-commerce, compras, {$this->siteName}, machine learning, recomendações");

            case 'product.detail':
                $productName = $data['product_name'] ?? '';
                $category = $data['category'] ?? '';
                return strtolower("{$productName}, {$category}, comprar, preço, {$this->siteName}");

            default:
                return 'e-commerce, produtos, compras, machine learning, php 7.4, recomendações';
        }
    }

    private function generateCanonical(string $page, array $data): string
    {
        switch ($page) {
            case 'product.listing':
                $category = $data['category_slug'] ?? null;
                if ($category) {
                    return $this->siteUrl . "/products?category={$category}";
                }
                return $this->siteUrl . "/products";

            case 'product.detail':
                $slug = $data['product_slug'] ?? '';
                if ($slug) {
                    return $this->siteUrl . "/products/{$slug}";
                }
                $productId = $data['product_id'] ?? '';
                return $this->siteUrl . "/products/{$productId}";

            default:
                return $this->siteUrl . '/';
        }
    }

    private function generateOpenGraph(string $page, array $data): array
    {
        $title = $this->generateTitle($page, $data);
        $description = $this->generateDescription($page, $data);
        $image = $data['image_url'] ?? $this->defaultImage;
        $url = $this->generateCanonical($page, $data);

        return [
            'og:type' => 'website',
            'og:site_name' => $this->siteName,
            'og:title' => $title,
            'og:description' => $description,
            'og:image' => $image,
            'og:url' => $url,
            'og:locale' => 'pt_BR',
        ];
    }

    private function generateTwitterCard(string $page, array $data): array
    {
        $title = $this->generateTitle($page, $data);
        $description = $this->generateDescription($page, $data);
        $image = $data['image_url'] ?? $this->defaultImage;

        return [
            'twitter:card' => 'summary_large_image',
            'twitter:site' => '@echub',
            'twitter:title' => $title,
            'twitter:description' => $description,
            'twitter:image' => $image,
        ];
    }

    private function generateStructuredData(string $page, array $data): ?string
    {
        switch ($page) {
            case 'product.detail':
                return $this->generateProductSchema($data);

            case 'product.listing':
                return $this->generateItemListSchema($data);

            default:
                return $this->generateOrganizationSchema();
        }
    }

    private function generateProductSchema(array $data): string
    {
        $product = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $data['product_name'] ?? '',
            'description' => $data['description'] ?? '',
            'image' => $data['image_url'] ?? '',
            'category' => $data['category'] ?? '',
            'offers' => [
                '@type' => 'Offer',
                'price' => $data['price'] ?? '',
                'priceCurrency' => 'BRL',
                'availability' => 'https://schema.org/InStock',
            ],
        ];

        return json_encode($product, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function generateItemListSchema(array $data): string
    {
        $itemList = [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'itemListElement' => [],
        ];

        if (isset($data['products']) && is_array($data['products'])) {
            foreach ($data['products'] as $index => $product) {
                $itemList['itemListElement'][] = [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'item' => [
                        '@type' => 'Product',
                        'name' => $product['name'] ?? '',
                        'url' => $this->siteUrl . '/products/' . ($product['id'] ?? ''),
                    ],
                ];
            }
        }

        return json_encode($itemList, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function generateOrganizationSchema(): string
    {
        $org = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $this->siteName,
            'url' => $this->siteUrl,
            'description' => 'E-commerce com Machine Learning em PHP 7.4',
        ];

        return json_encode($org, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
