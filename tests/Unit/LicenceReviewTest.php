<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\LicenceReview as R;
use PHPUnit\Framework\TestCase;

final class LicenceReviewTest extends TestCase
{
    private array $ok = ['po_id' => 6, 'number' => 'LI-1', 'issued_at' => '2026-10-12', 'expires_at' => '2027-04-11', 'currency' => 'USD', 'amount' => 450000];
    private array $ctx = ['today' => '2026-10-19', 'number_taken' => false, 'po_has_licence' => false, 'po_amount' => 450000.0, 'po_currency' => 'USD', 'same_file_licence' => null];

    private static function codes(array $issues, string $level): array
    {
        return array_column(array_values(array_filter($issues, fn ($i) => $i['level'] === $level)), 'code');
    }

    public function testCleanLicenceHasNoIssue(): void
    {
        self::assertSame([], R::issues($this->ok, [['description' => 'Acide', 'quantity' => 1000.0, 'unit_price' => 450.0, 'amount' => 450000.0]], $this->ctx));
    }

    public function testBlockingErrors(): void
    {
        $issues = R::issues(['po_id' => '', 'number' => '', 'issued_at' => '2026-13-01', 'expires_at' => '2026-01-01', 'currency' => 'USD'], [], ['number_taken' => true, 'po_has_licence' => true] + $this->ctx);
        $errors = self::codes($issues, 'error');
        self::assertContains('required', $errors);
        self::assertContains('invalid_date', $errors);
        self::assertContains('duplicate_number', $errors);
        self::assertContains('po_has_licence', $errors);
        self::assertTrue(R::hasErrors($issues));
        // Expiration avant émission
        self::assertContains('expiry_before_issue', self::codes(R::issues(['expires_at' => '2026-01-01'] + $this->ok, [], $this->ctx), 'error'));
    }

    public function testWarningsDoNotBlock(): void
    {
        $issues = R::issues(['amount' => 400000, 'currency' => 'EUR', 'expires_at' => '2026-10-01', 'issued_at' => '2026-01-01'] + $this->ok,
            [['description' => 'Acide', 'quantity' => 1000.0, 'unit_price' => 450.0, 'amount' => 440000.0]],
            ['same_file_licence' => 'LI-OLD'] + $this->ctx);
        $w = self::codes($issues, 'warning');
        foreach (['already_expired', 'line_total', 'lines_sum', 'currency_differs', 'amount_differs', 'same_file'] as $code) {
            self::assertContains($code, $w, $code);
        }
        self::assertFalse(R::hasErrors($issues));
    }

    /** Licence IB : un n° COD saisi comme n° de licence est signalé (le n° attendu est le N° Validation). */
    public function testIbNumberIsValidationNotCod(): void
    {
        $data = ['po_id' => 1, 'number' => 'DEC1711498-40CD-IB', 'issued_at' => '2026-03-19', 'expires_at' => '2027-03-14', 'currency' => 'USD'];
        $codes = fn (array $d, ?string $model) => array_column(\App\Domain\LicenceReview::issues($d, [], ['today' => '2026-10-19', 'number_taken' => false, 'po_has_licence' => false, 'po_amount' => null, 'po_currency' => null, 'same_file_licence' => null, 'model' => $model]), 'code');
        self::assertNotContains('ib_number_is_cod', $codes($data, 'ib'));
        self::assertContains('ib_number_is_cod', $codes(['number' => 'COD 2026 214576'] + $data, 'ib'));
        self::assertNotContains('ib_number_is_cod', $codes(['number' => 'COD 2026 214576'] + $data, null));
    }
}
