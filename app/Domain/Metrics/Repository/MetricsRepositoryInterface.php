<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Repository;

/**
 * Metrics Repository Interface
 *
 * Defines contract for Metrics data access following DDD Repository pattern
 */
interface MetricsRepositoryInterface
{
    /**
     * Record page view event
     *
     * @param string $sessionId Session identifier
     * @param string $page Page identifier
     * @param array $context Additional context data
     * @return bool Success status
     */
    public function recordPageView(string $sessionId, string $page, array $context = []): bool;

    /**
     * Record product interaction event
     *
     * @param string $sessionId Session identifier
     * @param int $productId Product ID
     * @param string $action Action type (view, click, add_to_cart, etc.)
     * @param array $context Additional context data
     * @return bool Success status
     */
    public function recordProductInteraction(string $sessionId, int $productId, string $action, array $context = []): bool;

    /**
     * Get metrics for session
     *
     * @param string $sessionId Session identifier
     * @return array Session metrics
     */
    public function getForSession(string $sessionId): array;

    /**
     * Get aggregate metrics
     *
     * @param string $period Time period (hour, day, week)
     * @return array Aggregate metrics
     */
    public function getAggregate(string $period = 'day'): array;

    /**
     * Get system health metrics
     *
     * @return array Health metrics (memory, cpu, etc.)
     */
    public function getSystemHealth(): array;
}
