<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Contrôles avant clôture d'un PO client.
 * Bloquants : aucun camion en route, tout le livré facturé, factures clients et fournisseurs soldées.
 * Non bloquant mais justifié : quantités non livrées (clôture « soldée » avec motif obligatoire).
 */
final class ClosureCheck
{
    /**
     * @param array{undelivered_lines:int,in_transit:int,delivered_value:float,invoiced_value:float,
     *              receivable_open:float,pos_without_invoice:int,payable_open:float,documents_missing?:int} $facts
     * @return list<array{key:string,ok:bool,blocking:bool}>
     */
    public static function evaluate(array $facts): array
    {
        return [
            ['key' => 'delivered', 'ok' => $facts['undelivered_lines'] === 0, 'blocking' => false],
            ['key' => 'no_transit', 'ok' => $facts['in_transit'] === 0, 'blocking' => true],
            ['key' => 'customer_invoiced', 'ok' => $facts['invoiced_value'] + 0.01 >= $facts['delivered_value'], 'blocking' => true],
            ['key' => 'customer_paid', 'ok' => $facts['receivable_open'] < 0.01, 'blocking' => true],
            ['key' => 'supplier_invoiced', 'ok' => $facts['pos_without_invoice'] === 0, 'blocking' => true],
            ['key' => 'supplier_paid', 'ok' => $facts['payable_open'] < 0.01, 'blocking' => true],
            ['key' => 'documents', 'ok' => ($facts['documents_missing'] ?? 0) === 0, 'blocking' => true],
        ];
    }

    /** @param list<array{key:string,ok:bool,blocking:bool}> $checks */
    public static function canClose(array $checks, string $note): bool
    {
        foreach ($checks as $c) {
            if (!$c['ok'] && ($c['blocking'] || trim($note) === '')) {
                return false;
            }
        }
        return true;
    }
}
