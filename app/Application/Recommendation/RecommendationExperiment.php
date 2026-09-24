<?php

declare(strict_types=1);

namespace App\Application\Recommendation;

use App\Domain\Recommendation\Model\AlgorithmRequestSample;
use App\Domain\Recommendation\Repository\AlgorithmMetricsRepositoryInterface;
use App\Domain\Recommendation\Service\AbTestAssigner;
use App\Domain\Recommendation\ValueObject\RecommendationSettings;
use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Story 8.2: A/B testing between recommendation algorithms.
 *
 * Assigns each subject (user_id, else session_id) to a variant, routes the
 * request to that variant's use case, records a per-algorithm sample for
 * every response (A/B on or off), and exposes the aggregated results --
 * the single read path for both the /metrics panel and the JSON export.
 */
final class RecommendationExperiment
{
    /** @var array<string, GenerateRecommendations> */
    private array $useCases = [];

    /**
     * @param Closure(string): GenerateRecommendations $useCaseFactory Lazy: only
     *        the assigned arm's use case is built (and trained) per request
     */
    public function __construct(
        private readonly RecommendationSettings $settings,
        private readonly Closure $useCaseFactory,
        private readonly AbTestAssigner $assigner,
        private readonly AlgorithmMetricsRepositoryInterface $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function assign(?string $sessionId, ?string $userId): AlgorithmAssignment
    {
        $subject = trim($userId ?? '');
        if ($subject === '') {
            $subject = trim($sessionId ?? '');
        }
        $subjectId = $subject === '' ? null : $subject;

        $variants = $this->settings->getAbTestVariants();
        if ($variants === null || $subjectId === null) {
            return new AlgorithmAssignment($this->settings->getAlgorithm(), null, $subjectId);
        }

        $variant = $this->assigner->variantFor($subjectId);

        return new AlgorithmAssignment($variants[$variant], $variant, $subjectId);
    }

    public function useCaseFor(string $algorithm): GenerateRecommendations
    {
        return $this->useCases[$algorithm] ??= ($this->useCaseFactory)($algorithm);
    }

    /**
     * Record the served response under the algorithm that served it. A
     * metrics failure never breaks the recommendation response.
     *
     * @param array<string, mixed> $response Formatted response ({data, meta})
     */
    public function record(AlgorithmAssignment $assignment, array $response): void
    {
        try {
            $this->metrics->record(
                $assignment->algorithm,
                AlgorithmRequestSample::fromResponse($response, $assignment->subjectId)
            );
        } catch (Throwable $exception) {
            $this->logger->warning('Não foi possível registrar métricas do algoritmo.', [
                'algorithm' => $assignment->algorithm,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array{
     *     enabled: bool,
     *     variants: array{A: string, B: string}|null,
     *     algorithms: list<array{
     *         algorithm: string,
     *         variant: 'A'|'B'|null,
     *         requests: int,
     *         unique_subjects: int,
     *         total_items: int,
     *         ml_items: int,
     *         ml_item_rate: float|null,
     *         avg_response_time_ms: float|null,
     *         avg_score: float|null
     *     }>
     * }
     */
    public function results(): array
    {
        $variants = $this->settings->getAbTestVariants();
        $variantByAlgorithm = $variants === null ? [] : array_flip($variants);

        $algorithms = [];
        foreach (RecommendationSettings::ALGORITHMS as $algorithm) {
            $metrics = $this->metrics->get($algorithm);
            /** @var 'A'|'B'|null $variant */
            $variant = $variantByAlgorithm[$algorithm] ?? null;
            $algorithms[] = [
                'algorithm' => $algorithm,
                'variant' => $variant,
                'requests' => $metrics->requests,
                'unique_subjects' => $metrics->uniqueSubjects,
                'total_items' => $metrics->totalItems,
                'ml_items' => $metrics->mlItems,
                'ml_item_rate' => $metrics->mlItemRate(),
                'avg_response_time_ms' => $metrics->avgResponseTimeMs(),
                'avg_score' => $metrics->avgScore(),
            ];
        }

        return [
            'enabled' => $variants !== null,
            'variants' => $variants,
            'algorithms' => $algorithms,
        ];
    }
}
