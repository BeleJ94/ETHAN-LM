<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Relevé d'un tiers (règles pures, une devise à la fois) :
 *  - relevé de compte : solde d'ouverture, mouvements datés (facture = montant dû, règlement = montant réglé), solde cumulé, solde de clôture ;
 *  - échéancier / ancienneté : reste dû de chaque facture à la date d'arrêté, réparti par tranche de retard.
 * Le sens (client / fournisseur) ne change pas le calcul : le solde est toujours « ce qui reste dû ».
 */
final class Statement
{
    /** Tranches d'ancienneté (jours de retard après l'échéance) : clé => borne haute incluse. */
    public const BUCKETS = ['current' => 0, 'd30' => 30, 'd60' => 60, 'd90' => 90, 'over90' => PHP_INT_MAX];

    /**
     * @param list<array{id:int,reference:string,date:string,due_date:string,amount:float,label?:string}> $invoices factures (hors brouillons / annulées)
     * @param list<array{invoice_id:int,date:string,amount:float,reference?:string,label?:string}> $payments règlements de ces factures
     * @return array{opening:float,lines:list<array<string,mixed>>,invoiced:float,paid:float,closing:float}
     */
    public static function ledger(array $invoices, array $payments, string $from, string $to): array
    {
        $opening = 0.0;
        $moves = [];
        foreach ($invoices as $i) {
            if ($i['date'] > $to) {
                continue;
            }
            if ($i['date'] < $from) {
                $opening += $i['amount'];
                continue;
            }
            $moves[] = ['kind' => 'invoice', 'date' => $i['date'], 'reference' => $i['reference'], 'label' => $i['label'] ?? '',
                'due_date' => $i['due_date'], 'debit' => $i['amount'], 'credit' => 0.0, 'order' => 0, 'id' => $i['id']];
        }
        foreach ($payments as $p) {
            if ($p['date'] > $to) {
                continue;
            }
            if ($p['date'] < $from) {
                $opening -= $p['amount'];
                continue;
            }
            $moves[] = ['kind' => 'payment', 'date' => $p['date'], 'reference' => $p['reference'] ?? '', 'label' => $p['label'] ?? '',
                'due_date' => null, 'debit' => 0.0, 'credit' => $p['amount'], 'order' => 1, 'id' => $p['invoice_id']];
        }
        // Ordre chronologique ; le même jour, la facture avant son règlement
        usort($moves, fn ($a, $b) => [$a['date'], $a['order'], $a['reference']] <=> [$b['date'], $b['order'], $b['reference']]);
        $balance = round($opening, 2);
        $invoiced = $paid = 0.0;
        foreach ($moves as &$m) {
            $balance = round($balance + $m['debit'] - $m['credit'], 2);
            $m['balance'] = $balance;
            $invoiced += $m['debit'];
            $paid += $m['credit'];
            unset($m['order']);
        }
        unset($m);
        return ['opening' => round($opening, 2), 'lines' => $moves, 'invoiced' => round($invoiced, 2), 'paid' => round($paid, 2), 'closing' => $balance];
    }

    /**
     * Échéancier à la date d'arrêté : reste dû de chaque facture émise au plus tard à cette date (règlements jusqu'à cette date).
     * @param list<array{id:int,reference:string,date:string,due_date:string,amount:float}> $invoices
     * @param list<array{invoice_id:int,date:string,amount:float}> $payments
     * @param bool $withSettled inclure les factures soldées (reste dû nul) dans la liste
     * @return array{rows:list<array<string,mixed>>,buckets:array<string,float>,total:float,overdue:float}
     */
    public static function aging(array $invoices, array $payments, string $asOf, bool $withSettled = false): array
    {
        $paidBy = [];
        foreach ($payments as $p) {
            if ($p['date'] <= $asOf) {
                $paidBy[$p['invoice_id']] = ($paidBy[$p['invoice_id']] ?? 0) + $p['amount'];
            }
        }
        $buckets = array_fill_keys(array_keys(self::BUCKETS), 0.0);
        $rows = [];
        foreach ($invoices as $i) {
            if ($i['date'] > $asOf) {
                continue;
            }
            $paid = round($paidBy[$i['id']] ?? 0, 2);
            $open = round($i['amount'] - $paid, 2);
            if ($open <= 0.004 && !$withSettled) {
                continue;
            }
            $late = (int) ((strtotime($asOf) - strtotime($i['due_date'])) / 86400);
            $bucket = self::bucket($late);
            if ($open > 0.004) {
                $buckets[$bucket] += $open;
            }
            $rows[] = $i + ['paid' => $paid, 'open' => max(0.0, $open), 'days_late' => max(0, $late), 'bucket' => $open > 0.004 ? $bucket : null];
        }
        usort($rows, fn ($a, $b) => [$a['due_date'], $a['reference']] <=> [$b['due_date'], $b['reference']]);
        $buckets = array_map(fn ($v) => round($v, 2), $buckets);
        $total = round(array_sum($buckets), 2);
        return ['rows' => $rows, 'buckets' => $buckets, 'total' => $total, 'overdue' => round($total - $buckets['current'], 2)];
    }

    public static function bucket(int $daysLate): string
    {
        foreach (self::BUCKETS as $key => $max) {
            if ($daysLate <= $max) {
                return $key;
            }
        }
        return 'over90';
    }
}
