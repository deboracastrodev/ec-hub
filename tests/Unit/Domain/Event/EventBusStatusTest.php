<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Event;

use App\Domain\Event\EventBusStatus;
use PHPUnit\Framework\TestCase;

final class EventBusStatusTest extends TestCase
{
    public function testConnectedStatus(): void
    {
        $status = new EventBusStatus(true, 12);

        $this->assertTrue($status->connected);
        $this->assertSame(12, $status->publishedCount);
    }

    public function testDisconnectedStatus(): void
    {
        $status = new EventBusStatus(false, 0);

        $this->assertFalse($status->connected);
        $this->assertSame(0, $status->publishedCount);
    }

    public function testConnectedAndCountAreIndependent(): void
    {
        $status = new EventBusStatus(false, 45);

        $this->assertFalse($status->connected);
        $this->assertSame(45, $status->publishedCount);
    }

    public function testPropertiesAreReadonlyPublic(): void
    {
        $status = new EventBusStatus(true, 3);

        // Both properties must be accessible as public readonly.
        $this->assertSame(true, $status->connected);
        $this->assertSame(3, $status->publishedCount);
    }
}
