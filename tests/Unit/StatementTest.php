<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Statement;
use PHPUnit\Framework\TestCase;

final class StatementTest extends TestCase
{
    private const INVOICES = [
        ['id' => 1, 'reference' => 'FAC-1', 'date' => '2026-08-10', 'due_date' => '2026-09-09', 'amount' => 1000.0],
        ['id' => 2, 'reference' => 'FAC-2', 'date' => '2026-09-15', 'due_date' => '2026-10-15', 'amount' => 500.0],
        ['id' => 3, 'reference' => 'FAC-3', 'date' => '2026-10-20', 'due_date' => '2026-11-19', 'amount' => 300.0],
    ];
    private const PAYMENTS = [
        ['invoice_id' => 1, 'date' => '2026-08-30', 'amount' => 400.0, 'reference' => 'VIR-1'],
        ['invoice_id' => 1, 'date' => '2026-09-20', 'amount' => 600.0, 'reference' => 'VIR-2'],
        ['invoice_id' => 2, 'date' => '2026-10-25', 'amount' => 200.0, 'reference' => 'VIR-3'],
    ];

    public function testLedgerOpeningMovesAndClosing(): void
    {
        $l = Statement::ledger(self::INVOICES, self::PAYMENTS, '2026-09-01', '2026-10-31');
        self::assertSame(600.0, $l['opening']); // FAC-1 1000 − VIR-1 400 avant la période
        self::assertSame(['FAC-2', 'VIR-2', 'FAC-3', 'VIR-3'], array_column($l['lines'], 'reference'));
        self::assertSame([1100.0, 500.0, 800.0, 600.0], array_column($l['lines'], 'balance'));
        self::assertSame(800.0, $l['invoiced']);
        self::assertSame(800.0, $l['paid']);
        self::assertSame(600.0, $l['closing']);
    }

    public function testLedgerIgnoresMovesAfterPeriod(): void
    {
        $l = Statement::ledger(self::INVOICES, self::PAYMENTS, '2026-09-01', '2026-09-30');
        self::assertSame(['FAC-2', 'VIR-2'], array_column($l['lines'], 'reference'));
        self::assertSame(500.0, $l['closing']);
    }

    public function testAgingAtCutoffDate(): void
    {
        $a = Statement::aging(self::INVOICES, self::PAYMENTS, '2026-10-31');
        self::assertSame(['FAC-2', 'FAC-3'], array_column($a['rows'], 'reference')); // FAC-1 soldée
        self::assertSame(300.0, $a['buckets']['d30']);     // FAC-2 : 500 − 200, 16 j de retard
        self::assertSame(300.0, $a['buckets']['current']); // FAC-3 non échue
        self::assertSame(600.0, $a['total']);
        self::assertSame(300.0, $a['overdue']);
    }

    public function testAgingIgnoresLaterPaymentsAndCanListSettled(): void
    {
        // Au 30/09, FAC-2 n'a encore rien reçu ; FAC-1 est soldée (listée sur demande)
        $a = Statement::aging(self::INVOICES, self::PAYMENTS, '2026-09-30', true);
        self::assertSame(['FAC-1', 'FAC-2'], array_column($a['rows'], 'reference'));
        self::assertSame(0.0, $a['rows'][0]['open']);
        self::assertSame(500.0, $a['buckets']['current']);
    }

    public function testBuckets(): void
    {
        self::assertSame('current', Statement::bucket(-5));
        self::assertSame('current', Statement::bucket(0));
        self::assertSame('d30', Statement::bucket(30));
        self::assertSame('d60', Statement::bucket(31));
        self::assertSame('d90', Statement::bucket(90));
        self::assertSame('over90', Statement::bucket(91));
    }
}
