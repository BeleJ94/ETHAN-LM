<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Security\SiteScope;

/**
 * Données des relevés de tiers : factures approuvées (hors brouillons et annulées) et leurs règlements,
 * groupées par tiers puis par devise (jamais de conversion implicite). Toujours limité au périmètre de sites.
 */
final class StatementRepository
{
    /**
     * @param string $direction receivable|payable
     * @param array{site_id?:string,currency?:string,order_id?:string} $filters
     * @return array<int,array{party:array<string,mixed>,currencies:array<string,array{invoices:list<array>,payments:list<array>}>}> tiers id → données
     */
    public function load(string $direction, ?int $partyId, array $filters): array
    {
        [$scope, $params] = SiteScope::clause('o.site_id');
        $fk = $direction === 'receivable' ? 'customer_id' : 'supplier_id';
        $where = "i.direction = ? AND i.status IN ('open','paid')" . $scope;
        $params = [$direction, ...$params];
        if ($partyId !== null) {
            $where .= " AND i.$fk = ?";
            $params[] = $partyId;
        }
        if (($filters['site_id'] ?? '') !== '') {
            $where .= ' AND o.site_id = ?';
            $params[] = (int) $filters['site_id'];
        }
        if (($filters['currency'] ?? '') !== '') {
            $where .= ' AND i.currency = ?';
            $params[] = $filters['currency'];
        }
        if (($filters['order_id'] ?? '') !== '') {
            $where .= ' AND i.order_id = ?';
            $params[] = (int) $filters['order_id'];
        }
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("SELECT i.id, i.$fk AS party_id, i.reference, i.external_number, i.invoice_date AS date, i.due_date, i.currency, i.amount,
                COALESCE(i.subtotal, i.amount) AS subtotal, i.vat_amount, o.reference AS order_ref, o.customer_po_number,
                dv.reference AS delivery_ref, p.reference AS po_ref
            FROM invoices i JOIN customer_orders o ON o.id = i.order_id
            LEFT JOIN deliveries dv ON dv.id = i.delivery_id LEFT JOIN purchase_orders p ON p.id = i.po_id
            WHERE $where ORDER BY i.invoice_date, i.id");
        $stmt->execute($params);
        $out = [];
        $ids = [];
        foreach ($stmt->fetchAll() as $r) {
            $label = implode(' · ', array_filter([$r['external_number'] ? 'N° ' . $r['external_number'] : null, $r['customer_po_number'], $r['delivery_ref'] ?? $r['po_ref']]));
            $inv = ['id' => (int) $r['id'], 'reference' => $r['reference'], 'date' => $r['date'], 'due_date' => $r['due_date'],
                'amount' => (float) $r['amount'], 'subtotal' => (float) $r['subtotal'], 'vat_amount' => (float) $r['vat_amount'], 'label' => $label,
                'order_ref' => $r['order_ref'], 'external_number' => $r['external_number']];
            $out[(int) $r['party_id']]['currencies'][$r['currency']]['invoices'][] = $inv;
            $out[(int) $r['party_id']]['currencies'][$r['currency']]['payments'] ??= [];
            $ids[(int) $r['id']] = [(int) $r['party_id'], $r['currency'], $r['reference']];
        }
        if ($ids) {
            $pay = $pdo->query('SELECT invoice_id, paid_at, amount, method, bank_ref FROM payments WHERE invoice_id IN (' . implode(',', array_keys($ids)) . ') ORDER BY paid_at, id');
            foreach ($pay->fetchAll() as $p) {
                [$party, $cur, $ref] = $ids[(int) $p['invoice_id']];
                $out[$party]['currencies'][$cur]['payments'][] = [
                    'invoice_id' => (int) $p['invoice_id'], 'date' => $p['paid_at'], 'amount' => (float) $p['amount'],
                    'reference' => $p['bank_ref'] ?: t('payment.method.' . $p['method']), 'label' => t('statement.payment_of', ['ref' => $ref]) . ' · ' . t('payment.method.' . $p['method']),
                ];
            }
        }
        // Coordonnées des tiers
        if ($out) {
            $table = $direction === 'receivable' ? 'customers' : 'suppliers';
            $rows = $pdo->query("SELECT * FROM $table WHERE id IN (" . implode(',', array_keys($out)) . ')')->fetchAll();
            foreach ($rows as $r) {
                $out[(int) $r['id']]['party'] = $r;
            }
            uasort($out, fn ($a, $b) => strcasecmp($a['party']['name'] ?? '', $b['party']['name'] ?? ''));
        }
        return $out;
    }

    /** Dossiers du tiers (filtre « Dossier »). @return list<array{id:int,reference:string,customer_po_number:string}> */
    public function orders(string $direction, int $partyId): array
    {
        [$scope, $params] = SiteScope::clause('o.site_id');
        $fk = $direction === 'receivable' ? 'customer_id' : 'supplier_id';
        $stmt = Database::pdo()->prepare("SELECT DISTINCT o.id, o.reference, o.customer_po_number FROM invoices i JOIN customer_orders o ON o.id = i.order_id
            WHERE i.$fk = ? AND i.direction = ?" . $scope . ' ORDER BY o.reference DESC');
        $stmt->execute([$partyId, $direction, ...$params]);
        return $stmt->fetchAll();
    }
}
