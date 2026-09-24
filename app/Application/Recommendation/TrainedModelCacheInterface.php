<?php

declare(strict_types=1);

namespace App\Application\Recommendation;

/**
 * Story 10.1: single-slot store for the serialized, trained recommendation
 * model (R4.4).
 *
 * The payload is opaque here: what is serialized, and how it is validated on
 * the way back, belongs to the Infrastructure that owns the model. Callers
 * such as ManageProducts only need to invalidate the slot, never to read it.
 */
interface TrainedModelCacheInterface
{
    /** The stored payload, or null when the slot is empty. */
    public function load(): ?string;

    public function store(string $payload): void;

    public function invalidate(): void;
}
