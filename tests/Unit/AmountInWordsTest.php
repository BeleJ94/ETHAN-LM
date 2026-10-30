<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\AmountInWords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AmountInWordsTest extends TestCase
{
    public static function french(): array
    {
        return [
            [0, 'zéro'], [1, 'un'], [17, 'dix-sept'], [21, 'vingt et un'], [71, 'soixante et onze'], [72, 'soixante-douze'],
            [80, 'quatre-vingts'], [81, 'quatre-vingt-un'], [91, 'quatre-vingt-onze'], [99, 'quatre-vingt-dix-neuf'],
            [100, 'cent'], [200, 'deux cents'], [201, 'deux cent un'], [1000, 'mille'], [2000, 'deux mille'],
            [80000, 'quatre-vingt mille'], [200000, 'deux cent mille'], [1_000_000, 'un million'], [2_500_000, 'deux millions cinq cent mille'],
            [135150, 'cent trente-cinq mille cent cinquante'],
        ];
    }

    #[DataProvider('french')]
    public function testFrench(int $n, string $expected): void
    {
        self::assertSame($expected, AmountInWords::fr($n));
    }

    public function testEnglish(): void
    {
        self::assertSame('one hundred and thirty-five thousand one hundred and fifty', AmountInWords::en(135150));
        self::assertSame('two million and five', AmountInWords::en(2_000_005));
    }

    public function testWithCurrencyAndCents(): void
    {
        self::assertSame('mille deux cent cinquante dollars américains et cinquante cents', AmountInWords::format(1250.5, 'USD'));
        self::assertSame('one thousand US dollars', AmountInWords::format(1000, 'USD', 'en'));
    }
}
