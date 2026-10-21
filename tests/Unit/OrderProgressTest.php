<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\ClosureCheck;
use App\Domain\OrderProgress;
use PHPUnit\Framework\TestCase;

final class OrderProgressTest extends TestCase
{
    public function testOrderStatusFollowsTheFlow(): void
    {
        self::assertSame('received', OrderProgress::orderStatus('received', false, false, false, false));
        self::assertSame('sourcing', OrderProgress::orderStatus('received', true, false, false, false));
        self::assertSame('ordered', OrderProgress::orderStatus('sourcing', true, true, false, false));
        self::assertSame('in_delivery', OrderProgress::orderStatus('ordered', true, true, true, false));
        self::assertSame('delivered', OrderProgress::orderStatus('in_delivery', true, true, true, true));
        self::assertSame('closed', OrderProgress::orderStatus('closed', true, true, true, false));
    }

    public function testPoStatusWithPartialDeliveries(): void
    {
        $lines = [['quantity' => 30000.0, 'shipped' => 12000.0], ['quantity' => 10.0, 'shipped' => 0.0]];
        self::assertSame('partial', OrderProgress::poStatus('sent', $lines));
        $lines[0]['shipped'] = 30000.0;
        $lines[1]['shipped'] = 10.0;
        self::assertSame('delivered', OrderProgress::poStatus('partial', $lines));
        self::assertSame('sent', OrderProgress::poStatus('sent', [['quantity' => 5.0, 'shipped' => 0.0]]));
        self::assertSame('draft', OrderProgress::poStatus('draft', $lines));
    }

    public function testInvoiceAndDueDate(): void
    {
        self::assertSame('open', OrderProgress::invoiceStatus('open', 1000, 999.99 - 0.01));
        self::assertSame('paid', OrderProgress::invoiceStatus('open', 1000, 1000));
        self::assertSame('2026-11-18', OrderProgress::dueDate('2026-10-19', 30));
        self::assertSame(40, OrderProgress::percent(12000, 30000));
        self::assertSame(0, OrderProgress::percent(1, 0));
    }

    public function testClosureRules(): void
    {
        $facts = ['undelivered_lines' => 0, 'in_transit' => 0, 'delivered_value' => 5000.0, 'invoiced_value' => 5000.0,
            'receivable_open' => 0.0, 'pos_without_invoice' => 0, 'payable_open' => 0.0];
        self::assertTrue(ClosureCheck::canClose(ClosureCheck::evaluate($facts), ''));

        // Livraison incomplète : possible uniquement avec motif
        $short = ['undelivered_lines' => 1] + $facts;
        self::assertFalse(ClosureCheck::canClose(ClosureCheck::evaluate($short), ''));
        self::assertTrue(ClosureCheck::canClose(ClosureCheck::evaluate($short), 'Client a réduit sa commande'));

        // Facture client impayée : bloquant même avec motif
        $unpaid = ['receivable_open' => 120.0] + $facts;
        self::assertFalse(ClosureCheck::canClose(ClosureCheck::evaluate($unpaid), 'motif'));
    }
}
