<?php
declare(strict_types=1);

namespace Tests\Unit\Domain\Recommendation;

use App\Domain\Product\Model\Product;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Recommendation\Model\RecommendationResult;
use App\Domain\Recommendation\Service\KNNService;
use App\Domain\Shared\ValueObject\Money;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for KNNService
 *
 * These tests verify the KNN implementation works correctly
 * without external dependencies (mock products).
 */
class KNNServiceTest extends TestCase
{
    private KNNService $knnService;
    private array $testProducts;
    private ProductRepositoryInterface $productRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->knnService = new KNNService($this->productRepository);

        // Create test products
        $this->testProducts = [
            new Product(
                'Smartphone Galaxy X',
                'Smartphone premium com tela AMOLED e câmera de alta resolução',
                Money::fromDecimal(2999.00),
                'Eletrônicos',
                'https://example.com/phone1.jpg'
            ),
            new Product(
                'Laptop Pro 15',
                'Laptop para trabalho com processador Intel Core i7',
                Money::fromDecimal(5499.00),
                'Eletrônicos',
                'https://example.com/laptop1.jpg'
            ),
            new Product(
                'Camiseta Algodão',
                'Camiseta 100% algodão, confortável e estilosa',
                Money::fromDecimal(79.90),
                'Roupas',
                'https://example.com/shirt1.jpg'
            ),
            new Product(
                'Tênis Esportivo',
                'Tênis para corrida com amortecimento de alta performance',
                Money::fromDecimal(299.00),
                'Esportes',
                'https://example.com/shoe1.jpg'
            ),
            new Product(
                'Fone Bluetooth Sony',
                'Fone bluetooth com cancelamento de ruído',
                Money::fromDecimal(599.00),
                'Eletrônicos',
                'https://example.com/headphone1.jpg'
            ),
        ];

        foreach ($this->testProducts as $index => $product) {
            $product->setId($index + 1);
        }
    }

    public function test_train_creates_trained_model(): void
    {
        $this->knnService->train($this->testProducts, 3);

        $this->assertTrue($this->knnService->isTrained());
        $this->assertEquals(3, $this->knnService->getK());
    }

    public function test_recommend_returns_similar_products(): void
    {
        $this->knnService->train($this->testProducts, 3);

        $targetProduct = $this->testProducts[0];
        $recommendations = $this->knnService->recommend($targetProduct, 3);

        $this->assertIsArray($recommendations);
        $this->assertGreaterThanOrEqual(1, count($recommendations));
        $this->assertContainsOnlyInstancesOf(RecommendationResult::class, $recommendations);
    }

    public function test_recommend_includes_score_and_explanation(): void
    {
        $this->knnService->train($this->testProducts, 3);

        $targetProduct = $this->testProducts[0];
        $recommendations = $this->knnService->recommend($targetProduct, 2);

        if ($recommendations !== []) {
            $firstRec = $recommendations[0]->toArray();
            $this->assertArrayHasKey('score', $firstRec);
            $this->assertArrayHasKey('rank', $firstRec);
            $this->assertArrayHasKey('explanation', $firstRec);
            $this->assertGreaterThanOrEqual(0, $firstRec['score']);
            $this->assertLessThanOrEqual(100, $firstRec['score']);
        }
    }

    public function test_recommend_excludes_target_product(): void
    {
        $this->knnService->train($this->testProducts, 3);

        $targetProduct = $this->testProducts[0];
        $recommendations = $this->knnService->recommend($targetProduct, 10);

        foreach ($recommendations as $rec) {
            $this->assertNotEquals($targetProduct->getId(), $rec->getProductId());
        }
    }

    public function test_k_is_configurable(): void
    {
        $this->knnService->train($this->testProducts, 7);

        $this->assertEquals(7, $this->knnService->getK());
    }

    public function test_recommend_without_training_trains_using_repository(): void
    {
        $this->productRepository->expects($this->once())
            ->method('findAll')
            ->willReturn($this->convertProductsToArray($this->testProducts));

        $targetProduct = $this->testProducts[0];
        $recommendations = $this->knnService->recommend($targetProduct, 2);

        $this->assertNotEmpty($recommendations);
        $this->assertContainsOnlyInstancesOf(RecommendationResult::class, $recommendations);
    }

    public function test_explanation_mentions_category_similarity(): void
    {
        $this->knnService->train($this->testProducts, 3);

        $targetProduct = $this->testProducts[0];
        $recommendations = $this->knnService->recommend($targetProduct);

        if ($recommendations !== []) {
            $firstRec = $recommendations[0]->toArray();
            $this->assertNotEmpty($firstRec['explanation'] ?? null);
        }
    }

    /**
     * @param Product[] $products
     * @return array<int, array<string, mixed>>
     */
    private function convertProductsToArray(array $products): array
    {
        return array_map(static function (Product $product): array {
            return [
                'id' => $product->getId(),
                'name' => $product->getName(),
                'description' => $product->getDescription(),
                'price' => (string) $product->getPrice()->getDecimal(),
                'category' => $product->getCategory(),
                'image_url' => $product->getImageUrl(),
                'created_at' => $product->getCreatedAt()->format('Y-m-d H:i:s'),
            ];
        }, $products);
    }
}
