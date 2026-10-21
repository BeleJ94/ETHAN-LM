<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\AvAllocation;
use PHPUnit\Framework\TestCase;

final class AvAllocationTest extends TestCase
{
    public function testFifoTakesTheOldestAvFirstAndSpillsOver(): void
    {
        $avs = [['id' => 1, 'remaining' => 1000.0], ['id' => 2, 'remaining' => 2000.0]];
        self::assertSame([1 => 1000.0, 2 => 500.0], AvAllocation::fifo(1500, $avs));
        self::assertSame([1 => 800.0], AvAllocation::fifo(800, $avs));
    }

    public function testFifoSkipsExhaustedAvsAndRefusesUncoveredQuantities(): void
    {
        $avs = [['id' => 1, 'remaining' => 0.0], ['id' => 2, 'remaining' => 300.0]];
        self::assertSame([2 => 300.0], AvAllocation::fifo(300, $avs));
        self::assertNull(AvAllocation::fifo(300.5, $avs));
        self::assertNull(AvAllocation::fifo(10, []));
    }

    public function testCheckManualAllocation(): void
    {
        $remaining = [1 => 1000.0, 2 => 2000.0];
        self::assertNull(AvAllocation::check(1500, [1 => 200, 2 => 1300], $remaining));
        self::assertSame('delivery.av.error.sum', AvAllocation::check(1500, [1 => 200, 2 => 1000], $remaining)[0]);
        self::assertSame('delivery.av.error.over', AvAllocation::check(1500, [1 => 1500], $remaining)[0]);
        self::assertSame('delivery.av.error.unknown', AvAllocation::check(10, [9 => 10], $remaining)[0]);
        self::assertSame('delivery.av.error.negative', AvAllocation::check(10, [1 => -5, 2 => 15], $remaining)[0]);
    }
}
