<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

use App\Domain\Order\Model\Order;
use App\Domain\Order\Service\OrderConfirmationMailerInterface;
use RuntimeException;
use Twig\Environment;

/**
 * Simulated confirmation email (Story 8.7): nothing is sent. The message is
 * written as {directory}/{order_number}.eml, a plain-text RFC 5322 file that
 * any mail client opens, so the "sent" email can be inspected.
 */
final class FileOrderConfirmationMailer implements OrderConfirmationMailerInterface
{
    public const TEMPLATE = 'email/order-confirmation.txt.twig';

    public function __construct(private readonly string $directory, private readonly Environment $twig)
    {
    }

    public function sendConfirmation(Order $order): void
    {
        try {
            $body = $this->twig->render(self::TEMPLATE, [
                'order' => $order,
                'items' => array_map(static fn ($item): array => [
                    'name' => $item->productName(),
                    'quantity' => $item->quantity(),
                    'subtotal' => $item->subtotalCents() / 100,
                ], $order->items()),
                'total' => $order->totalCents() / 100,
            ]);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Não foi possível montar o email de confirmação.', 0, $exception);
        }

        $headers = [
            'To' => '<' . self::headerValue($order->customerEmail()) . '>',
            'Subject' => sprintf('Pedido %s confirmado - ec-hub', $order->orderNumber()),
            'Date' => $order->createdAt()->format(DATE_RFC2822),
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
            'X-Simulated' => 'true',
        ];
        $message = '';
        foreach ($headers as $name => $value) {
            $message .= $name . ': ' . $value . "\r\n";
        }
        $message .= "\r\n" . str_replace("\n", "\r\n", str_replace("\r\n", "\n", $body));

        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0775, true) && ! is_dir($this->directory)) {
            throw new RuntimeException(sprintf('Não foi possível criar o diretório de emails "%s".', $this->directory));
        }

        $path = rtrim($this->directory, '/') . '/' . $order->orderNumber() . '.eml';
        if (@file_put_contents($path, $message, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Não foi possível gravar o email simulado em "%s".', $path));
        }
    }

    /** Header values never carry line breaks (header injection). */
    private static function headerValue(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }
}
