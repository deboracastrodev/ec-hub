<?php

declare(strict_types=1);

namespace App\Domain\Recommendation\Service;

use App\Domain\Event\EventStoreInterface;
use App\Domain\Product\Model\Product;
use App\Domain\Recommendation\Model\RecommendationResult;

/**
 * Item-based collaborative filtering (Story 8.1, FR103).
 *
 * "Who interacted with A also interacted with B": each product is the set
 * of sessions that viewed, clicked or added it to the cart (read from the
 * EventStoreInterface), and two products are as similar as the cosine of
 * those sets:
 *
 *     sim(a, b) = |S_a ∩ S_b| / sqrt(|S_a| * |S_b|)
 *
 * Plain co-occurrence algebra -- no ML library, pure Domain. Identity is the
 * session_id (always present), never user_id, so identities are not mixed.
 * A session counts once per product regardless of how many events it sent.
 *
 * Limite de custo (DW-13): cada sessão contribui com no máximo
 * $maxProductsPerSession produtos distintos (padrão 50), então a
 * co-ocorrência de uma sessão fica em no máximo 50² pares. Ao atingir o
 * teto, novos produtos daquela sessão são ignorados (a sessão é truncada,
 * não descartada).
 */
final class CollaborativeFilteringService implements RecommendationStrategy
{
    public const NAME = 'collaborative';

    /** Interaction events that count as "interest" in a product. */
    public const INTERACTION_EVENTS = ['product.viewed', 'product.clicked', 'cart.item_added'];

    /** Teto padrão de produtos distintos contados por sessão no train(). */
    public const DEFAULT_MAX_PRODUCTS_PER_SESSION = 50;

    /** @var array<int, Product> */
    private array $productsById = [];

    /** @var array<int, array<string, true>> product_id => set of session ids */
    private array $sessionsByProduct = [];

    /** @var array<int, array<int, int>> product_id => (product_id => shared sessions) */
    private array $coOccurrence = [];

    private bool $trained = false;

    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly ExplanationGenerator $explanationGenerator,
        private readonly int $maxProductsPerSession = self::DEFAULT_MAX_PRODUCTS_PER_SESSION,
    ) {
        if ($maxProductsPerSession < 1) {
            throw new \InvalidArgumentException('Max products per session must be at least 1.');
        }
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function isTrained(): bool
    {
        return $this->trained;
    }

    /**
     * Aggregate interaction sessions per catalog product and compute the
     * pairwise co-occurrence counts. Events for products outside $products
     * and malformed envelopes are ignored. A failing event store propagates
     * (the use case turns it into the ml_error fallback) and leaves the
     * model untrained.
     *
     * Cada sessão conta em no máximo $maxProductsPerSession produtos
     * distintos, na ordem de leitura (product.viewed, depois product.clicked,
     * depois cart.item_added, cada um na ordem gravada). Um produto além do
     * teto não conta a sessão nem em |S_p| nem em nenhum par.
     *
     * @param Product[] $products
     */
    public function train(array $products): void
    {
        $this->trained = false;

        $productsById = [];
        foreach ($products as $product) {
            $id = $product->getId();
            if ($id !== null) {
                $productsById[$id] = $product;
            }
        }

        $sessionsByProduct = [];
        /** @var array<string, int> session id => produtos distintos já contados */
        $productCountBySession = [];
        foreach (self::INTERACTION_EVENTS as $eventName) {
            foreach ($this->eventStore->getByEvent($eventName) as $envelope) {
                $interaction = $this->parseInteraction($envelope['data']);
                if ($interaction === null || ! isset($productsById[$interaction['product_id']])) {
                    continue;
                }
                $productId = $interaction['product_id'];
                $sessionId = $interaction['session_id'];
                if (isset($sessionsByProduct[$productId][$sessionId])) {
                    continue;
                }
                if (($productCountBySession[$sessionId] ?? 0) >= $this->maxProductsPerSession) {
                    continue;
                }
                $productCountBySession[$sessionId] = ($productCountBySession[$sessionId] ?? 0) + 1;
                $sessionsByProduct[$productId][$sessionId] = true;
            }
        }

        $productsBySession = [];
        foreach ($sessionsByProduct as $productId => $sessions) {
            foreach (array_keys($sessions) as $sessionId) {
                $productsBySession[(string) $sessionId][] = $productId;
            }
        }

        $coOccurrence = [];
        foreach ($productsBySession as $sessionProducts) {
            foreach ($sessionProducts as $a) {
                foreach ($sessionProducts as $b) {
                    if ($a !== $b) {
                        $coOccurrence[$a][$b] = ($coOccurrence[$a][$b] ?? 0) + 1;
                    }
                }
            }
        }

        $this->productsById = $productsById;
        $this->sessionsByProduct = $sessionsByProduct;
        $this->coOccurrence = $coOccurrence;
        $this->trained = true;
    }

    /**
     * Products co-interacted with $target, by cosine similarity desc, ties
     * by product_id asc. score = sim * 100, in (0, 100]. Returns [] when no
     * session interacted with $target and anything else.
     *
     * @return RecommendationResult[]
     * @throws \RuntimeException When called before train()
     */
    public function recommend(Product $target, int $limit): array
    {
        if (! $this->trained) {
            throw new \RuntimeException('Collaborative filtering model is not trained');
        }

        $targetId = $target->getId();
        if ($targetId === null || $limit < 1 || ! isset($this->coOccurrence[$targetId])) {
            return [];
        }

        $targetSessions = count($this->sessionsByProduct[$targetId] ?? []);
        $candidates = [];
        foreach ($this->coOccurrence[$targetId] as $productId => $shared) {
            if ($productId === $targetId || ! isset($this->productsById[$productId])) {
                continue;
            }
            $candidates[] = [
                'product_id' => $productId,
                'shared' => $shared,
                'similarity' => $shared / sqrt($targetSessions * count($this->sessionsByProduct[$productId])),
            ];
        }

        usort(
            $candidates,
            // Rounded before comparing so equal ratios built from different
            // integers (e.g. 1/sqrt(2) vs 2/sqrt(8)) tie and fall to the id.
            static fn (array $left, array $right): int =>
                (round($right['similarity'], 10) <=> round($left['similarity'], 10))
                    ?: ($left['product_id'] <=> $right['product_id'])
        );

        $results = [];
        foreach (array_slice($candidates, 0, $limit) as $index => $candidate) {
            $product = $this->productsById[$candidate['product_id']];
            $score = max(0.0, min(100.0, $candidate['similarity'] * 100));

            $base = new RecommendationResult(
                $candidate['product_id'],
                $product->getName(),
                $product->getCategory(),
                $product->getPrice()->getDecimal(),
                $score,
                $index + 1,
                ''
            );

            $results[] = new RecommendationResult(
                $base->getProductId(),
                $base->getProductName(),
                $base->getCategory(),
                $base->getPrice(),
                $base->getScore(),
                $base->getRank(),
                $this->explanationGenerator->generateForCollaborative($base, $target),
                null,
                null,
                $this->explanationGenerator->buildCollaborativeReasons($base, $target, $candidate['shared'])
            );
        }

        return $results;
    }

    /**
     * @return array{session_id: string, product_id: int}|null
     */
    private function parseInteraction(mixed $data): ?array
    {
        if (! is_array($data)) {
            return null;
        }

        $sessionId = $data['session_id'] ?? null;
        if (! is_string($sessionId) || trim($sessionId) === '') {
            return null;
        }

        $productId = $data['product_id'] ?? null;
        if (is_string($productId) && ctype_digit($productId)) {
            $productId = (int) $productId;
        }
        if (! is_int($productId) || $productId < 1) {
            return null;
        }

        return ['session_id' => $sessionId, 'product_id' => $productId];
    }
}
