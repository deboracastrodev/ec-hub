<?php

declare(strict_types=1);

namespace App\Domain\Recommendation\Evaluation;

use App\Domain\Product\Model\Product;
use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Story 10.2: deterministic train/holdout split of a product catalog.
 *
 * The catalog is sorted by id, shuffled with Mt19937 seeded by $seed, and
 * the first max(1, round(n * ratio)) products become the holdout. Same
 * catalog + same seed = same split, on any machine (Mt19937 is portable).
 */
final class HoldoutSplit
{
    public const MIN_PRODUCTS = 3;
    public const MIN_TRAIN = 2;

    /**
     * @param list<Product> $train
     * @param list<Product> $holdout
     */
    private function __construct(
        private readonly array $train,
        private readonly array $holdout,
        private readonly int $seed,
        private readonly float $holdoutRatio,
    ) {
    }

    /**
     * @param list<Product> $products
     */
    public static function fromProducts(array $products, int $seed, float $holdoutRatio = 0.2): self
    {
        if (! ($holdoutRatio > 0.0 && $holdoutRatio < 1.0)) {
            throw new InvalidArgumentException(sprintf(
                'A proporção de holdout precisa estar entre 0 e 1 (exclusivo); recebido %s.',
                $holdoutRatio
            ));
        }

        $count = count($products);
        if ($count < self::MIN_PRODUCTS) {
            throw new InvalidArgumentException(sprintf(
                'O catálogo precisa de pelo menos %d produtos para o split; recebido %d.',
                self::MIN_PRODUCTS,
                $count
            ));
        }

        $seenIds = [];
        foreach ($products as $product) {
            $id = $product->getId();
            if ($id === null) {
                throw new InvalidArgumentException('Todo produto do catálogo precisa de id.');
            }
            if (isset($seenIds[$id])) {
                throw new InvalidArgumentException(sprintf('Id de produto duplicado no catálogo: %d.', $id));
            }
            $seenIds[$id] = true;
        }

        $holdoutSize = max(1, (int) round($count * $holdoutRatio));
        if ($count - $holdoutSize < self::MIN_TRAIN) {
            throw new InvalidArgumentException(sprintf(
                'O treino precisa de pelo menos %d produtos; com %d produtos e proporção %s sobram %d.',
                self::MIN_TRAIN,
                $count,
                $holdoutRatio,
                $count - $holdoutSize
            ));
        }

        $sorted = $products;
        usort($sorted, static fn (Product $a, Product $b): int => $a->getId() <=> $b->getId());

        $shuffled = (new Randomizer(new Mt19937($seed)))->shuffleArray($sorted);

        return new self(
            array_values(array_slice($shuffled, $holdoutSize)),
            array_values(array_slice($shuffled, 0, $holdoutSize)),
            $seed,
            $holdoutRatio
        );
    }

    /** @return list<Product> */
    public function train(): array
    {
        return $this->train;
    }

    /** @return list<Product> */
    public function holdout(): array
    {
        return $this->holdout;
    }

    public function seed(): int
    {
        return $this->seed;
    }

    public function holdoutRatio(): float
    {
        return $this->holdoutRatio;
    }
}
