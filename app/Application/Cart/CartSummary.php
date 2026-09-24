<?php

declare(strict_types=1);

namespace App\Application\Cart;

use App\Domain\Cart\Model\Cart;
use App\Domain\Session\Repository\SessionRepositoryInterface;
use Closure;

/**
 * Item count for the header link (Story 8.6), shared by every page.
 *
 * Both collaborators are lazy: a page without a session never touches the
 * repository. Any failure (Redis down, invalid cookie secret) yields null,
 * so the header degrades to a plain "Carrinho" link instead of breaking.
 */
final class CartSummary
{
    private bool $resolved = false;

    private ?int $itemCount = null;

    /**
     * @param Closure(): ?string $sessionId current session id, without creating one
     * @param Closure(): SessionRepositoryInterface $sessions
     */
    public function __construct(
        private readonly Closure $sessionId,
        private readonly Closure $sessions,
    ) {
    }

    /** Sum of the quantities in the cart; 0 without a session; null when unknown. */
    public function itemCount(): ?int
    {
        if (! $this->resolved) {
            $this->itemCount = $this->load();
            $this->resolved = true;
        }

        return $this->itemCount;
    }

    private function load(): ?int
    {
        try {
            $sessionId = ($this->sessionId)();
            if ($sessionId === null) {
                return 0;
            }

            /** @var SessionRepositoryInterface $sessions */
            $sessions = ($this->sessions)();

            return Cart::fromSession($sessions->get($sessionId, Cart::SESSION_FIELD))->itemCount();
        } catch (\Throwable) {
            return null;
        }
    }
}
