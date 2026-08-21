<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Event\TrackProductInteraction;
use App\Controller\Exceptions\InvalidRequestException;
use App\Shared\Http\SessionContext;

final class ProductInteractionController
{
    public function __construct(private readonly TrackProductInteraction $tracker, private readonly SessionContext $session)
    {
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function event(array $payload): array
    {
        $interaction = $payload['interaction'] ?? 'click';
        if ($interaction !== 'click') {
            throw new InvalidRequestException('interaction must be click');
        }

        return ['data' => $this->tracker->track($interaction, $this->session->id(), $this->productId($payload), $this->userId($payload))];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function addCartItem(array $payload): array
    {
        $quantity = $payload['quantity'] ?? null;
        if ((! is_int($quantity) && ! (is_string($quantity) && ctype_digit($quantity))) || (int) $quantity < 1) {
            throw new InvalidRequestException('quantity must be a positive integer');
        }

        return ['data' => $this->tracker->track('cart', $this->session->id(), $this->productId($payload), $this->userId($payload), (int) $quantity)];
    }

    /** @param array<string, mixed> $payload */
    private function productId(array $payload): int
    {
        $id = $payload['product_id'] ?? null;
        if ((! is_int($id) && ! (is_string($id) && ctype_digit($id))) || (int) $id < 1) {
            throw new InvalidRequestException('product_id must be a positive integer');
        }

        return (int) $id;
    }

    /** @param array<string, mixed> $payload */
    private function userId(array $payload): ?string
    {
        if (! isset($payload['user_id'])) {
            return null;
        }
        if (! is_string($payload['user_id']) || trim($payload['user_id']) === '') {
            throw new InvalidRequestException('user_id must be a non-empty string');
        }

        return trim($payload['user_id']);
    }
}
