<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Répartition d'une quantité chargée sur les AV (attestations de vérification) d'un produit.
 * Chaque AV a un reste = quantité conforme − quantité déjà prélevée par des expéditions non annulées.
 */
final class AvAllocation
{
    private const EPS = 1e-9;

    /**
     * Répartition automatique : AV la plus ancienne d'abord.
     * @param list<array{id:int,remaining:float}> $avs dans l'ordre de priorité (plus ancienne d'abord)
     * @return array<int,float>|null inspection_id → quantité ; null si les AV ne couvrent pas la quantité
     */
    public static function fifo(float $qty, array $avs): ?array
    {
        $out = [];
        $left = round($qty, 3);
        foreach ($avs as $av) {
            if ($left <= self::EPS) {
                break;
            }
            $take = round(min($left, max(0.0, (float) $av['remaining'])), 3);
            if ($take > self::EPS) {
                $out[(int) $av['id']] = $take;
                $left = round($left - $take, 3);
            }
        }
        return $left > self::EPS ? null : $out;
    }

    /**
     * Contrôle d'une répartition saisie : AV connues, quantités positives, reste de chaque AV respecté, total = quantité chargée.
     * @param array<int,float> $alloc inspection_id → quantité
     * @param array<int,float> $remaining inspection_id → reste disponible
     * @return array{0:string,1:array<string,mixed>}|null [clé d'erreur, paramètres] ou null si valide
     */
    public static function check(float $qty, array $alloc, array $remaining): ?array
    {
        $sum = 0.0;
        foreach ($alloc as $id => $q) {
            if (!array_key_exists($id, $remaining)) {
                return ['delivery.av.error.unknown', []];
            }
            if ($q < 0) {
                return ['delivery.av.error.negative', []];
            }
            if ($q > $remaining[$id] + self::EPS) {
                return ['delivery.av.error.over', ['id' => $id, 'remaining' => $remaining[$id]]];
            }
            $sum += $q;
        }
        if (abs($sum - $qty) > 0.0005) {
            return ['delivery.av.error.sum', ['sum' => round($sum, 3), 'qty' => $qty]];
        }
        return null;
    }
}
