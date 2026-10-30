<?php

declare(strict_types=1);

namespace App\Domain;

/** Montant en toutes lettres pour les factures (« Arrêtée la présente facture à la somme de… »), en français ou en anglais. */
final class AmountInWords
{
    private const FR_UNITS = ['zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix',
        'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf'];
    private const FR_TENS = [2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante', 6 => 'soixante', 8 => 'quatre-vingt'];
    private const EN_UNITS = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
        'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
    private const EN_TENS = [2 => 'twenty', 3 => 'thirty', 4 => 'forty', 5 => 'fifty', 6 => 'sixty', 7 => 'seventy', 8 => 'eighty', 9 => 'ninety'];

    /** Ex. 1250.5, USD, fr → « mille deux cent cinquante dollars américains et cinquante cents ». */
    public static function format(float $amount, string $currency, string $locale = 'fr'): string
    {
        $amount = round(abs($amount), 2);
        $whole = (int) floor($amount);
        $cents = (int) round(($amount - $whole) * 100);
        $fr = $locale !== 'en';
        [$major, $minor] = self::currencyNames($currency, $fr);
        $words = ($fr ? self::fr($whole) : self::en($whole)) . ' ' . $major;
        if ($cents > 0) {
            $words .= ($fr ? ' et ' : ' and ') . ($fr ? self::fr($cents) : self::en($cents)) . ' ' . $minor;
        }
        return $words;
    }

    public static function fr(int $n): string
    {
        if ($n < 0) {
            return 'moins ' . self::fr(-$n);
        }
        if ($n < 1000) {
            return self::frBelow1000($n, true);
        }
        $parts = [];
        foreach ([1_000_000_000 => ['milliard', 'milliards'], 1_000_000 => ['million', 'millions']] as $size => [$one, $many]) {
            if ($n >= $size) {
                $q = intdiv($n, $size);
                $parts[] = self::fr($q) . ' ' . ($q > 1 ? $many : $one);
                $n %= $size;
            }
        }
        if ($n >= 1000) {
            $q = intdiv($n, 1000);
            // « mille » est invariable et ne prend pas « un » ; « cent » ne prend pas de « s » devant « mille »
            $parts[] = ($q === 1 ? '' : self::frBelow1000($q, false) . ' ') . 'mille';
            $n %= 1000;
        }
        if ($n > 0) {
            $parts[] = self::frBelow1000($n, true);
        }
        return implode(' ', $parts);
    }

    private static function frBelow1000(int $n, bool $final): string
    {
        $h = intdiv($n, 100);
        $r = $n % 100;
        $out = [];
        if ($h > 0) {
            $out[] = ($h > 1 ? self::FR_UNITS[$h] . ' ' : '') . 'cent' . ($h > 1 && $r === 0 && $final ? 's' : '');
        }
        if ($r > 0 || $h === 0) {
            $out[] = self::frBelow100($r, $final);
        }
        return implode(' ', $out);
    }

    private static function frBelow100(int $n, bool $final): string
    {
        if ($n < 20) {
            return self::FR_UNITS[$n];
        }
        $t = intdiv($n, 10);
        $u = $n % 10;
        if ($t === 7 || $t === 9) {
            // soixante-dix… / quatre-vingt-dix…
            return self::FR_TENS[$t - 1] . ($t === 7 && $u === 1 ? ' et ' : '-') . self::FR_UNITS[10 + $u];
        }
        if ($u === 0) {
            return self::FR_TENS[$t] . ($t === 8 && $final ? 's' : '');
        }
        return self::FR_TENS[$t] . ($u === 1 && $t !== 8 ? ' et ' : '-') . self::FR_UNITS[$u];
    }

    public static function en(int $n): string
    {
        if ($n < 0) {
            return 'minus ' . self::en(-$n);
        }
        if ($n < 20) {
            return self::EN_UNITS[$n];
        }
        if ($n < 100) {
            return self::EN_TENS[intdiv($n, 10)] . ($n % 10 ? '-' . self::EN_UNITS[$n % 10] : '');
        }
        if ($n < 1000) {
            return self::EN_UNITS[intdiv($n, 100)] . ' hundred' . ($n % 100 ? ' and ' . self::en($n % 100) : '');
        }
        foreach ([1_000_000_000 => 'billion', 1_000_000 => 'million', 1000 => 'thousand'] as $size => $name) {
            if ($n >= $size) {
                $r = $n % $size;
                return self::en(intdiv($n, $size)) . ' ' . $name . ($r ? ($r < 100 ? ' and ' : ' ') . self::en($r) : '');
            }
        }
        return '';
    }

    /** @return array{0:string,1:string} nom de l'unité et de la subdivision */
    private static function currencyNames(string $currency, bool $fr): array
    {
        return match (strtoupper($currency)) {
            'USD' => $fr ? ['dollars américains', 'cents'] : ['US dollars', 'cents'],
            'EUR' => $fr ? ['euros', 'centimes'] : ['euros', 'cents'],
            'CDF' => $fr ? ['francs congolais', 'centimes'] : ['Congolese francs', 'centimes'],
            'ZAR' => $fr ? ['rands', 'cents'] : ['rand', 'cents'],
            'XAF', 'XOF' => $fr ? ['francs CFA', 'centimes'] : ['CFA francs', 'centimes'],
            default => [strtoupper($currency), $fr ? 'centièmes' : 'hundredths'],
        };
    }
}
