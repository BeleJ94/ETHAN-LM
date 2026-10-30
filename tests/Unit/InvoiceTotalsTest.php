<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\DomainException;
use App\Services\InvoiceService;
use PHPUnit\Framework\TestCase;

final class InvoiceTotalsTest extends TestCase
{
    public function testWithoutVat(): void
    {
        self::assertSame(['subtotal' => 135150.0, 'vat_rate' => null, 'vat_amount' => 0.0, 'amount' => 135150.0],
            InvoiceService::totals([['amount' => 135000.0], ['amount' => 150.0]], null));
    }

    public function testWithVat(): void
    {
        $t = InvoiceService::totals([['amount' => 100.0], ['amount' => 33.33]], 16.0);
        self::assertSame(133.33, $t['subtotal']);
        self::assertSame(21.33, $t['vat_amount']);
        self::assertSame(154.66, $t['amount']);
    }

    public function testInvalidRateIsRefused(): void
    {
        $this->expectException(DomainException::class);
        InvoiceService::totals([['amount' => 100.0]], -1.0);
    }
}
