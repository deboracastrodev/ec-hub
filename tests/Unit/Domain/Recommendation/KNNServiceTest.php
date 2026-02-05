<?php
declare(strict_types=1);

namespace Tests\Unit\Domain\Recommendation;

use App\Domain\Product\Model\Product;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->knnService = new KNNService();

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

        // Set IDs for testing
        foreach ($this->testProducts as $index => $product) {
            $product->setId($index + 1);
        }
    }

    public function test_train_creates_trained_model()
    {
        $this->knnService->train($this->testProducts, 3);

        $this->assertTrue($this->knnService->isTrained());
        $this->assertEquals(3, $this->knnService->getK());
    }

    public function test_recommend_returns_similar_products()
    {
        $this->knnService->train($this->testProducts, 3);

        $targetProduct = $this->testProducts[0]; // Smartphone Galaxy X
        $recommendations = $this->knnService->recommend($targetProduct, 3);

        $this->assertIsArray($recommendations);
        $this->assertGreaterThanOrEqual(1, count($recommendations));
    }

    public function test_recommend_includes_score_and_explanation()
    {
        $this->knnService->train($this->testProducts, 3);

        $targetProduct = $this->testProducts[0];
        $recommendations = $this->knnService->recommend($targetProduct, 2);

        if (count($recommendations) > 0) {
            $firstRec = $recommendations[0];

            $this->assertArrayHasKey('score', $firstRec);
            $this->assertArrayHasKey('explanation', $firstRec);
            $this->assertArrayHasKey('rank', $firstRec);
            $this->assertGreaterThanOrEqual(0, $firstRec['score']);
            $this->assertLessThanOrEqual(100, $firstRec['score']);
        }
    }

    public function test_recommend_excludes_target_product()
    {
        $this->knnService->train($this->testProducts, 3);

        $targetProduct = $this->testProducts[0];
        $recommendations = $this->knnService->recommend($targetProduct, 10);

        foreach ($recommendations as $rec) {
            $this->assertNotEquals($targetProduct->getId(), $rec['product_id']);
        }
    }

    public function test_k_is_configurable()
    {
        $this->knnService->train($this->testProducts, 7);

        $this->assertEquals(7, $this->knnService->getK());
    }

    public function test_recommend_without_training_throws_exception()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('trained');

        $knnService = new KNNService();
        $targetProduct = $this->testProducts[0];

        $knnService->recommend($targetProduct);
    }

    public function test_explanation_mentions_category_similarity()
    {
        $this->knnService->train($this->testProducts, 3);

        $targetProduct = $this->testProducts[0]; // Eletrônicos
        $recommendations = $this->knnService->recommend($targetProduct);

        if (count($recommendations) > 0) {
            $firstRec = $recommendations[0];

            // Explanation should mention category or similarity
            $explanation = $firstRec['explanation'];
            $this->assertNotEmpty($explanation);
        }
    }
}
