<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\NameMatcher;
use PHPUnit\Framework\TestCase;

final class NameMatcherTest extends TestCase
{
    private array $refs = [
        ['id' => 1, 'name' => 'Sasol Chemicals (Pty) Ltd'],
        ['id' => 2, 'name' => 'Engen DRC'],
        ['id' => 3, 'name' => 'Jinan Chemical Co.'],
        ['id' => 4, 'name' => 'Jinan Chemical Trading'],
    ];

    public function testExactMatchIgnoringLegalForm(): void
    {
        $m = NameMatcher::match('SASOL CHEMICALS LTD', $this->refs);
        self::assertSame(['matched', 1], [$m['status'], $m['best']]);
    }

    public function testAmbiguityRequiresUserChoice(): void
    {
        $m = NameMatcher::match('Jinan Chemical', $this->refs);
        self::assertSame('ambiguous', $m['status']);
        self::assertNull($m['best']);
        self::assertSame([3, 4], array_slice(array_column($m['candidates'], 'id'), 0, 2));
    }

    public function testNoMatchNeverInventsAPartner(): void
    {
        self::assertSame(['none', null, []], array_values(NameMatcher::match('Totally Unknown Mining', $this->refs)));
        self::assertSame('none', NameMatcher::match(null, $this->refs)['status']);
    }

    /** Formes juridiques à points (S.A., S.A.R.L.) ignorées comme leurs équivalents sans points. */
    public function testDottedLegalForms(): void
    {
        self::assertSame(NameMatcher::normalize("Rawbank"), NameMatcher::normalize("RAWBANK S.A."));
        self::assertSame(NameMatcher::normalize("Zeta Kimia"), NameMatcher::normalize("ZETA KIMIA S.A.R.L."));
        $m = NameMatcher::match("tst banque alpha s.a.", [["id" => 1, "name" => "TST Banque Alpha"], ["id" => 2, "name" => "TST Banque Beta"]]);
        self::assertSame(["matched", 1], [$m["status"], $m["best"]]);
    }
}
