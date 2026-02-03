<?php

declare(strict_types=1);

namespace App\Domain\User\Repository;

/**
 * User Repository Interface
 *
 * Defines contract for User data access following DDD Repository pattern
 */
interface UserRepositoryInterface
{
    /**
     * Find user by ID
     *
     * @param int $id User ID
     * @return array|null User data or null if not found
     */
    public function findById(int $id): ?array;

    /**
     * Find user by email
     *
     * @param string $email User email
     * @return array|null User data or null if not found
     */
    public function findByEmail(string $email): ?array;

    /**
     * Create new user
     *
     * @param array $data User data
     * @return int Created user ID
     */
    public function create(array $data): int;

    /**
     * Update user
     *
     * @param int $id User ID
     * @param array $data User data
     * @return bool Success status
     */
    public function update(int $id, array $data): bool;
}
