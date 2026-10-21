<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Numérotation des inspections / AV / ARA à partir du n° COD de la licence :
 * COD 2026 0012 → 1re inspection « COD 2026 0012 / 0001 », puis « / 0002 »… (compteur propre à chaque licence).
 */
final class InspectionRef
{
    public const SEPARATOR = ' / ';

    /** @param list<string> $existing références déjà attribuées aux inspections de la licence */
    public static function next(string $cod, array $existing): string
    {
        $max = 0;
        $prefix = $cod . self::SEPARATOR;
        foreach ($existing as $ref) {
            if (str_starts_with($ref, $prefix) && ctype_digit($n = substr($ref, strlen($prefix)))) {
                $max = max($max, (int) $n);
            }
        }
        return $prefix . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}
