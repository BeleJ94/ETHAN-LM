<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Règles du processus « importation » (pures, sans base de données).
 *
 * Chaîne : PO client → PO fournisseur → licence (1 pour 1) → inspections → AV / ARA → livraisons.
 * - sans licence valide (active, non expirée) : ni inspection ni livraison ;
 * - un ARA non régularisé bloque toute nouvelle livraison du PO fournisseur ;
 * - on ne peut expédier que les quantités conformes couvertes par des AV (inspections partielles cumulées).
 */
final class ImportGate
{
    /** @param array{status:string,expires_at:string}|null $licence */
    public static function licenceValid(?array $licence, string $today): bool
    {
        return $licence !== null && $licence['status'] === 'active' && $licence['expires_at'] >= $today;
    }

    /**
     * Quantité encore expédiable par ligne de PO = conforme (AV) − déjà chargé (livré + en route / en préparation).
     * @param array<int,float> $cleared po_line_id → quantité conforme cumulée des AV
     * @param array<int,float> $committed po_line_id → quantité déjà engagée en expéditions
     * @return array<int,float>
     */
    public static function shippable(array $cleared, array $committed): array
    {
        $out = [];
        foreach ($cleared as $lineId => $qty) {
            $out[$lineId] = max(0.0, round($qty - ($committed[$lineId] ?? 0.0), 3));
        }
        return $out;
    }

    /**
     * Contrôle d'une expédition demandée. Renvoie la clé d'erreur (et ses paramètres) ou null si autorisée.
     * @param array<int,float> $requested po_line_id → quantité à charger
     * @param array<int,float> $shippable sortie de shippable()
     * @return array{0:string,1:array<string,string|int|float>}|null
     */
    public static function checkShipment(?array $licence, int $openAra, array $requested, array $shippable, string $today): ?array
    {
        if ($licence === null) {
            return ['imports.error.no_licence', []];
        }
        if (!self::licenceValid($licence, $today)) {
            return ['imports.error.licence_invalid', ['number' => $licence['number'] ?? '']];
        }
        if ($openAra > 0) {
            return ['imports.error.ara_open', []];
        }
        foreach ($requested as $lineId => $qty) {
            if ($qty > ($shippable[$lineId] ?? 0.0) + 1e-9) {
                return ['imports.error.over_cleared', ['line' => $lineId, 'cleared' => $shippable[$lineId] ?? 0.0]];
            }
        }
        return null;
    }

    /**
     * Étape d'import du PO client, d'après ses PO fournisseurs actifs (émis ou en cours).
     * @param list<array{licence:bool,cleared:float,open_ara:int}> $pos
     * @return string|null awaiting_licence | blocked | inspection | null (prêt à livrer)
     */
    public static function orderStage(array $pos): ?string
    {
        if ($pos === []) {
            return null;
        }
        foreach ($pos as $p) {
            if ($p['open_ara'] > 0) {
                return 'blocked';
            }
        }
        foreach ($pos as $p) {
            if (!$p['licence']) {
                return 'awaiting_licence';
            }
        }
        foreach ($pos as $p) {
            if ($p['cleared'] <= 0) {
                return 'inspection';
            }
        }
        return null;
    }
}
