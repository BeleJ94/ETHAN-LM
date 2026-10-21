<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\InspectionRef;
use App\Domain\LicenceTextParser;
use PHPUnit\Framework\TestCase;

final class InspectionRefTest extends TestCase
{
    public function testFirstInspectionOfTheLicence(): void
    {
        self::assertSame('COD 2026 0012 / 0001', InspectionRef::next('COD 2026 0012', []));
    }

    public function testCounterFollowsTheHighestNumberOfThisLicenceOnly(): void
    {
        $existing = ['COD 2026 0012 / 0001', 'COD 2026 0012 / 0003', 'COD 2026 0099 / 0007', 'INSP-2026-000004', 'COD 2026 0012 / abc'];
        self::assertSame('COD 2026 0012 / 0004', InspectionRef::next('COD 2026 0012', $existing));
    }

    public function testCodFormatAcceptedOnTheLicence(): void
    {
        self::assertSame('COD 2026 0012', LicenceTextParser::normalizeCod('cod 2026 0012'));
        self::assertSame('COD 2026 0012', LicenceTextParser::normalizeCod('COD-2026-0012'));
        self::assertSame('COD 2024 214576', LicenceTextParser::normalizeCod('COD2024214576'));
        self::assertNull(LicenceTextParser::normalizeCod('2026 0012'));
    }
}
