<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Cmup;
use App\Domain\MovementType;
use PHPUnit\Framework\TestCase;

final class CmupTest extends TestCase
{
    public function testWeightedAverageAfterReceipt(): void
    {
        // 100 u à 10 + 50 u à 16 → (1000 + 800) / 150 = 12
        self::assertSame(12.0, Cmup::afterReceipt(100, 10, 50, 16));
    }

    public function testEmptyOrNegativeStockRestartsFromReceiptCost(): void
    {
        self::assertSame(7.5, Cmup::afterReceipt(0, 99, 10, 7.5));
        self::assertSame(7.5, Cmup::afterReceipt(-3, 99, 10, 7.5));
    }

    public function testRejectsNonPositiveQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Cmup::afterReceipt(10, 5, 0, 5);
    }

    public function testConversionToBaseCurrency(): void
    {
        self::assertSame(25.92, Cmup::toBase(24, 1.08));
        $this->expectException(\InvalidArgumentException::class);
        Cmup::toBase(10, 0);
    }

    public function testOnlyValuedReceiptsRevalueCost(): void
    {
        foreach (MovementType::cases() as $type) {
            self::assertSame($type === MovementType::In, $type->revaluesCost(), $type->value);
        }
        self::assertSame(1, MovementType::TransferIn->sign());
        self::assertSame(-1, MovementType::AdjustOut->sign());
    }
}
