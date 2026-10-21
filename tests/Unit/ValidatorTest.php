<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\I18n;
use App\Core\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        I18n::setLocale('fr');
    }

    public function testRulesAndOptionalFields(): void
    {
        $v = new Validator(
            ['sku' => '', 'qty' => '0', 'email' => 'x', 'date' => '2026-13-40', 'note' => ''],
            ['sku' => 'required', 'qty' => 'required|numeric|gt:0', 'email' => 'email', 'date' => 'date', 'note' => 'max:5']
        );
        self::assertTrue($v->fails());
        self::assertSame(['sku', 'qty', 'email', 'date'], array_keys($v->errors()));
    }

    public function testValidInput(): void
    {
        $v = new Validator(['qty' => '2.5', 'type' => 'in', 'd' => '2026-10-19'], ['qty' => 'numeric|min:0', 'type' => 'in:in,out', 'd' => 'date']);
        self::assertFalse($v->fails());
    }
}
