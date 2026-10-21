<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\ImportGate;
use PHPUnit\Framework\TestCase;

final class ImportGateTest extends TestCase
{
    private const TODAY = '2026-10-19';
    private array $licence = ['number' => 'LIC-1', 'status' => 'active', 'expires_at' => '2027-01-31'];

    public function testLicenceValidity(): void
    {
        self::assertTrue(ImportGate::licenceValid($this->licence, self::TODAY));
        self::assertFalse(ImportGate::licenceValid(['expires_at' => '2026-10-18'] + $this->licence, self::TODAY));
        self::assertFalse(ImportGate::licenceValid(['status' => 'cancelled'] + $this->licence, self::TODAY));
        self::assertFalse(ImportGate::licenceValid(null, self::TODAY));
    }

    public function testPartialAvClearsOnlyConformQuantities(): void
    {
        // Ligne 7 : 1 000 t commandées, 2 inspections partielles AV (400 + 250 conformes), 300 déjà expédiées
        $shippable = ImportGate::shippable([7 => 650.0], [7 => 300.0]);
        self::assertSame([7 => 350.0], $shippable);
        self::assertNull(ImportGate::checkShipment($this->licence, 0, [7 => 350.0], $shippable, self::TODAY));
        self::assertSame('imports.error.over_cleared', ImportGate::checkShipment($this->licence, 0, [7 => 351.0], $shippable, self::TODAY)[0]);
    }

    public function testBlockingRules(): void
    {
        $ok = [7 => 100.0];
        self::assertSame('imports.error.no_licence', ImportGate::checkShipment(null, 0, $ok, $ok, self::TODAY)[0]);
        self::assertSame('imports.error.licence_invalid', ImportGate::checkShipment(['expires_at' => '2026-01-01'] + $this->licence, 0, $ok, $ok, self::TODAY)[0]);
        self::assertSame('imports.error.ara_open', ImportGate::checkShipment($this->licence, 1, $ok, $ok, self::TODAY)[0]);
        // Ligne jamais inspectée : rien d'expédiable
        self::assertSame('imports.error.over_cleared', ImportGate::checkShipment($this->licence, 0, [9 => 1.0], [], self::TODAY)[0]);
    }

    public function testOrderStage(): void
    {
        self::assertNull(ImportGate::orderStage([]));
        self::assertSame('awaiting_licence', ImportGate::orderStage([['licence' => false, 'cleared' => 0.0, 'open_ara' => 0]]));
        self::assertSame('inspection', ImportGate::orderStage([['licence' => true, 'cleared' => 0.0, 'open_ara' => 0]]));
        self::assertNull(ImportGate::orderStage([['licence' => true, 'cleared' => 400.0, 'open_ara' => 0]]));
        // Un ARA ouvert prime sur tout
        self::assertSame('blocked', ImportGate::orderStage([
            ['licence' => true, 'cleared' => 400.0, 'open_ara' => 0],
            ['licence' => true, 'cleared' => 0.0, 'open_ara' => 1],
        ]));
        // Deux PO fournisseurs : l'un sans licence → tout le dossier attend la licence
        self::assertSame('awaiting_licence', ImportGate::orderStage([
            ['licence' => true, 'cleared' => 400.0, 'open_ara' => 0],
            ['licence' => false, 'cleared' => 0.0, 'open_ara' => 0],
        ]));
    }
}
