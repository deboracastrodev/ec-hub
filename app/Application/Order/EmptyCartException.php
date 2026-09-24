<?php

declare(strict_types=1);

namespace App\Application\Order;

/** The cart has nothing that can be ordered (empty, or only removed products). */
final class EmptyCartException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('O carrinho está vazio.');
    }
}
