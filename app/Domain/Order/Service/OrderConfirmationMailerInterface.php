<?php

declare(strict_types=1);

namespace App\Domain\Order\Service;

use App\Domain\Order\Model\Order;

/** Sends (or, in this POC, simulates) the order confirmation email. */
interface OrderConfirmationMailerInterface
{
    /** @throws \RuntimeException when the confirmation cannot be produced */
    public function sendConfirmation(Order $order): void;
}
