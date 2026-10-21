<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Coût moyen unitaire pondéré (CMUP), recalculé à chaque entrée valorisée.
 *   nouveau CMUP = (Qstock × CMUP + Qentrée × Coût entrée) / (Qstock + Qentrée)
 * Un stock négatif ou nul avant l'entrée ne doit pas fausser la moyenne : on repart du coût d'entrée.
 */
final class Cmup
{
    public static function afterReceipt(float $stockQty, float $avgCost, float $inQty, float $inUnitCost): float
    {
        if ($inQty <= 0) {
            throw new \InvalidArgumentException('La quantité entrée doit être positive.');
        }
        if ($stockQty <= 0) {
            return round($inUnitCost, 6);
        }
        return round(($stockQty * $avgCost + $inQty * $inUnitCost) / ($stockQty + $inQty), 6);
    }

    /** Conversion vers la devise de base : 1 unité de devise = $rate unités de base. */
    public static function toBase(float $amount, float $rate): float
    {
        if ($rate <= 0) {
            throw new \InvalidArgumentException('Le taux de change doit être positif.');
        }
        return round($amount * $rate, 6);
    }
}
