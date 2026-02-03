<?php

declare(strict_types=1);

namespace App\Domain\Recommendation\Repository;

/**
 * Recommendation Repository Interface
 *
 * Defines contract for Recommendation data access following DDD Repository pattern
 */
interface RecommendationRepositoryInterface
{
    /**
     * Get recommendations for user
     *
     * @param int $userId User ID
     * @param int $limit Number of recommendations
     * @return array List of recommended product IDs with scores
     */
    public function getForUser(int $userId, int $limit = 10): array;

    /**
     * Store recommendation result
     *
     * @param int $userId User ID
     * @param array $productIds Recommended product IDs
     * @param string $algorithm Algorithm used
     * @return bool Success status
     */
    public function store(int $userId, array $productIds, string $algorithm): bool;

    /**
     * Get cached recommendations for user
     *
     * @param int $userId User ID
     * @param int $ttl Cache TTL in seconds
     * @return array|null Cached recommendations or null if not found/expired
     */
    public function getCached(int $userId, int $ttl = 3600): ?array;
}
