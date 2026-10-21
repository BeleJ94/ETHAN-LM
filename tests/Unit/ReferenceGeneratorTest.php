<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ReferenceGenerator;
use PHPUnit\Framework\TestCase;

final class ReferenceGeneratorTest extends TestCase
{
    public function testNormalizeUppercasesAndStripsSpaces(): void
    {
        self::assertSame('KCC-PO01', ReferenceGenerator::normalize('  kcc-po 01 '));
        self::assertSame('', ReferenceGenerator::normalize(null));
    }

    public function testAllowedFormat(): void
    {
        foreach (['CMD-2026-000001', 'REA-0001', 'LUB/01', 'A.1_B'] as $ok) {
            self::assertMatchesRegularExpression(ReferenceGenerator::PATTERN, $ok);
        }
        foreach (['-ABC', 'AB C', 'ÉTÉ', 'A<B'] as $bad) {
            self::assertDoesNotMatchRegularExpression(ReferenceGenerator::PATTERN, $bad);
        }
    }

    public function testEveryTypeTargetsAWhitelistedTableAndFitsItsColumn(): void
    {
        $columns = ['reference' => 30, 'sku' => 40, 'code' => 20];
        foreach (ReferenceGenerator::TYPES as $type => [$table, $column, , , $digits, $max]) {
            self::assertArrayHasKey($column, $columns, $type);
            self::assertLessThanOrEqual($columns[$column], $max, $type);
            self::assertGreaterThan(0, $digits, $type);
            self::assertMatchesRegularExpression('/^[a-z_]+$/', $table, $type);
        }
    }
}
