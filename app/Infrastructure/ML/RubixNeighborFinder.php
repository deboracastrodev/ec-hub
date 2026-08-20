<?php

declare(strict_types=1);

namespace App\Infrastructure\ML;

use App\Domain\Product\Model\Product;
use App\Domain\Recommendation\Service\NeighborFinderInterface;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Datasets\Unlabeled;
use Rubix\ML\Graph\Trees\BallTree;
use Rubix\ML\Kernels\Distance\Euclidean;
use Rubix\ML\Transformers\MinMaxNormalizer;
use Rubix\ML\Transformers\OneHotEncoder;

/**
 * Nearest-neighbor search over the product catalog using Rubix ML.
 *
 * Only class in the app that imports Rubix\* (R4.3) -- the Domain depends
 * on NeighborFinderInterface, not on this implementation.
 *
 * Pipeline: category + price -> OneHotEncoder (categorical -> binary
 * columns) -> MinMaxNormalizer (scale everything to [0, 1]) -> BallTree
 * (spatial index for k-NN search). Both transformers are Stateful: they fit
 * once from the training data and are reused, unfitted-again, on every
 * query sample so query and training features share the same scale.
 *
 * BallTree::nearest() is the primitive every Rubix k-NN estimator uses
 * internally; it is marked @internal upstream, which is why it stays
 * wrapped behind NeighborFinderInterface instead of used directly by the
 * Domain -- a break in that method is a one-file blast radius here.
 */
class RubixNeighborFinder implements NeighborFinderInterface
{
    private BallTree $tree;
    private OneHotEncoder $categoryEncoder;
    private MinMaxNormalizer $normalizer;
    /** @var array<int, Product> */
    private array $productsById = [];
    private bool $trained = false;

    public function __construct(?BallTree $tree = null)
    {
        $this->tree = $tree ?? new BallTree(30, new Euclidean());
        $this->categoryEncoder = new OneHotEncoder();
        $this->normalizer = new MinMaxNormalizer();
    }

    public function train(array $products): void
    {
        if ($products === []) {
            throw new \RuntimeException('Nenhum produto disponível para treinar o índice de vizinhos.');
        }

        $this->productsById = [];
        $samples = [];
        $labels = [];

        foreach ($products as $product) {
            $id = $product->getId();
            if ($id === null) {
                continue;
            }

            $this->productsById[$id] = $product;
            $samples[] = $this->featuresFor($product);
            $labels[] = $id;
        }

        // Fresh, unfitted transformers every training run -- reusing already-
        // fitted ones here would silently keep scaling new data against an
        // old catalog's min/max and category set.
        $this->categoryEncoder = new OneHotEncoder();
        $this->normalizer = new MinMaxNormalizer();

        $dataset = new Labeled($samples, $labels);
        $dataset->apply($this->categoryEncoder)
            ->apply($this->normalizer);

        $this->tree->grow($dataset);
        $this->trained = true;
    }

    public function isTrained(): bool
    {
        return $this->trained;
    }

    public function nearest(Product $target, int $k): array
    {
        if (! $this->trained) {
            throw new \RuntimeException('Índice de vizinhos ainda não foi treinado.');
        }

        $query = new Unlabeled([$this->featuresFor($target)]);
        $query->apply($this->categoryEncoder)
            ->apply($this->normalizer);

        [, $labels, $distances] = $this->tree->nearest($query->samples()[0], $k);

        $results = [];
        foreach ($labels as $index => $productId) {
            if (! isset($this->productsById[$productId])) {
                continue;
            }

            $results[] = [
                'product' => $this->productsById[$productId],
                'distance' => (float) $distances[$index],
            ];
        }

        return $results;
    }

    /**
     * @return array{0: string, 1: float}
     */
    private function featuresFor(Product $product): array
    {
        return [$product->getCategory(), $product->getPrice()->getDecimal()];
    }
}
