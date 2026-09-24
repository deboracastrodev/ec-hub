<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Mail;

use App\Domain\Order\Model\Order;
use App\Domain\Order\Model\OrderItem;
use App\Infrastructure\Mail\FileOrderConfirmationMailer;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\AdminTestTwig;

final class FileOrderConfirmationMailerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ec-hub-mail-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/nested/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        if (is_dir($this->directory)) {
            chmod($this->directory, 0775);
            rmdir($this->directory);
        }
    }

    public function testWritesThePlainTextEmailCreatingTheDirectory(): void
    {
        $directory = $this->directory . '/nested';
        (new FileOrderConfirmationMailer($directory, AdminTestTwig::create()))->sendConfirmation($this->order());

        $path = $directory . '/EC-0123456789.eml';
        self::assertFileExists($path);
        $message = (string) file_get_contents($path);
        [$head, $body] = explode("\r\n\r\n", $message, 2);

        $headers = explode("\r\n", $head);
        self::assertContains('To: <ana@example.com>', $headers);
        self::assertContains('Subject: Pedido EC-0123456789 confirmado - ec-hub', $headers);
        self::assertContains('Date: Thu, 24 Sep 2026 10:30:00 -0300', $headers);
        self::assertContains('Content-Type: text/plain; charset=UTF-8', $headers);
        self::assertContains('X-Simulated: true', $headers);

        self::assertStringContainsString('Olá, <b>Ana</b> & Cia!', $body);
        self::assertStringContainsString('EC-0123456789', $body);
        self::assertStringContainsString('- Caneca "Dev" × 3: R$ 59,70', $body);
        self::assertStringContainsString('- Adesivo × 1: R$ 0,10', $body);
        self::assertStringContainsString('Total: R$ 59,80', $body);
        self::assertStringContainsString("Rua das Flores, 123\r\nSão Paulo", $body);
        self::assertStringNotContainsString('&lt;', $body);
        self::assertStringNotContainsString('&amp;', $body);
        self::assertStringNotContainsString('&quot;', $body);
        self::assertStringNotContainsString("\r\r\n", $body);
    }

    public function testFailsWhenTheFileCannotBeWritten(): void
    {
        mkdir($this->directory, 0555);
        if (is_writable($this->directory)) {
            self::markTestSkipped('Running as a user that ignores directory permissions.');
        }

        $this->expectException(RuntimeException::class);
        (new FileOrderConfirmationMailer($this->directory, AdminTestTwig::create()))->sendConfirmation($this->order());
    }

    public function testFailsWhenTheDirectoryCannotBeCreated(): void
    {
        touch($this->directory);

        try {
            $this->expectException(RuntimeException::class);
            (new FileOrderConfirmationMailer($this->directory . '/sub', AdminTestTwig::create()))->sendConfirmation($this->order());
        } finally {
            unlink($this->directory);
        }
    }

    private function order(): Order
    {
        return new Order(
            12,
            'EC-0123456789',
            '<b>Ana</b> & Cia',
            'ana@example.com',
            "Rua das Flores, 123\nSão Paulo",
            Order::STATUS_COMPLETED,
            [new OrderItem(2, 'Caneca "Dev"', 1990, 3), new OrderItem(3, 'Adesivo', 10, 1)],
            new DateTimeImmutable('2026-09-24 10:30:00', new \DateTimeZone('America/Sao_Paulo'))
        );
    }
}
