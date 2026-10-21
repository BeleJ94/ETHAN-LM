<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Fil du dossier : PO client → PO fournisseur → licence → inspection → expédition.
 * Affiché à l'identique en haut de chaque page du dossier : où suis-je, d'où je viens, que reste-t-il.
 * Étape : key, icon, label (nature), value (référence), href, state (done|current|todo), add (lien « + créer »).
 */
final class DossierTrail
{
    /**
     * @param string $kind order|po|licence|inspection|delivery (page affichée)
     * @param int $orderId PO client du dossier
     * @param int|null $poId PO fournisseur concerné (null = page de la commande)
     * @param int|null $deliveryId expédition affichée
     * @return array{order_id:int,steps:list<array<string,mixed>>}|null
     */
    public static function build(string $kind, int $orderId, ?int $poId = null, ?int $deliveryId = null): ?array
    {
        $pdo = Database::pdo();
        if ($orderId === 0 && $poId !== null) {
            $q = $pdo->prepare('SELECT order_id FROM purchase_orders WHERE id = ?');
            $q->execute([$poId]);
            $orderId = (int) $q->fetchColumn();
        }
        $q = $pdo->prepare('SELECT o.id, o.reference, o.customer_po_number, o.fulfillment_mode, c.name AS customer_name
            FROM customer_orders o JOIN customers c ON c.id = o.customer_id WHERE o.id = ?');
        $q->execute([$orderId]);
        $order = $q->fetch();
        if (!$order) {
            return null;
        }
        $q = $pdo->prepare("SELECT p.id, p.reference, s.name AS supplier_name FROM purchase_orders p JOIN suppliers s ON s.id = p.supplier_id
            WHERE p.order_id = ? AND p.status <> 'cancelled' ORDER BY p.id");
        $q->execute([$orderId]);
        $pos = $q->fetchAll();
        if ($poId === null && count($pos) === 1) {
            $poId = (int) $pos[0]['id'];
        }
        $po = null;
        foreach ($pos as $p) {
            if ((int) $p['id'] === $poId) {
                $po = $p;
            }
        }
        if ($po === null && $poId !== null) {
            $q = $pdo->prepare('SELECT p.id, p.reference, s.name AS supplier_name FROM purchase_orders p JOIN suppliers s ON s.id = p.supplier_id WHERE p.id = ?');
            $q->execute([$poId]);
            $po = $q->fetch() ?: null;
        }
        $import = $order['fulfillment_mode'] === 'import';
        $oid = (int) $order['id'];
        $state = fn (string $k, bool $done) => $k === $kind ? 'current' : ($done ? 'done' : 'todo');

        $steps = [[
            'key' => 'order', 'icon' => 'clipboard-list', 'label' => t('dossier.step.order'),
            'value' => $order['customer_po_number'] . ' · ' . $order['customer_name'], 'href' => url("/orders/$oid"),
            'state' => $state('order', true), 'add' => null,
        ]];

        if ($po === null) {
            $steps[] = [
                'key' => 'po', 'icon' => 'shopping-cart', 'label' => t('dossier.step.po'),
                'value' => $pos ? t('dossier.po_count', ['count' => count($pos)]) : null,
                'href' => $pos ? url("/orders/$oid") . '#purchasing' : null, 'state' => $pos ? 'done' : 'todo',
                'add' => $pos ? null : url("/orders/$oid") . '#purchasing',
            ];
            return ['order_id' => $oid, 'steps' => $steps];
        }

        $pid = (int) $po['id'];
        $base = url("/purchase-orders/$pid");
        $steps[] = [
            'key' => 'po', 'icon' => 'shopping-cart', 'label' => t('dossier.step.po') . (count($pos) > 1 ? ' (' . count($pos) . ')' : ''),
            'value' => $po['reference'] . ' · ' . $po['supplier_name'], 'href' => $base, 'state' => $state('po', true), 'add' => null,
        ];

        $licence = null;
        if ($import) {
            $q = $pdo->prepare('SELECT id, number, cod_number FROM import_licences WHERE po_id = ? ORDER BY id DESC LIMIT 1');
            $q->execute([$pid]);
            $licence = $q->fetch() ?: null;
            $steps[] = [
                'key' => 'licence', 'icon' => 'stamp', 'label' => t('dossier.step.licence'),
                'value' => $licence ? ($licence['cod_number'] ?: $licence['number']) : null,
                'href' => $licence ? url('/licences/' . (int) $licence['id']) : null, 'state' => $state('licence', $licence !== null),
                'add' => $licence ? null : url('/licences/new', ['po_id' => $pid]),
            ];
            $q = $pdo->prepare("SELECT COUNT(*) AS n, SUM(decision = 'av') AS av FROM inspections WHERE po_id = ?");
            $q->execute([$pid]);
            $insp = $q->fetch();
            $n = (int) $insp['n'];
            $steps[] = [
                'key' => 'inspection', 'icon' => 'search-check', 'label' => t('dossier.step.inspection'),
                'value' => $n ? t('dossier.inspection_count', ['count' => $n, 'av' => (int) $insp['av']]) : null,
                'href' => $n ? $base . '#import' : null, 'state' => $state('inspection', $n > 0),
                'add' => !$n && $licence ? url("/purchase-orders/$pid/inspections/new") : null,
            ];
        }

        $q = $pdo->prepare("SELECT id, reference FROM deliveries WHERE po_id = ? AND status <> 'cancelled' ORDER BY id DESC");
        $q->execute([$pid]);
        $dels = $q->fetchAll();
        $current = null;
        foreach ($dels as $d) {
            if ((int) $d['id'] === $deliveryId) {
                $current = $d;
            }
        }
        $steps[] = [
            'key' => 'delivery', 'icon' => 'truck', 'label' => t('dossier.step.delivery'),
            'value' => $current ? $current['reference'] . (count($dels) > 1 ? ' (' . count($dels) . ')' : '')
                : ($dels ? (count($dels) === 1 ? $dels[0]['reference'] : t('dossier.delivery_count', ['count' => count($dels)])) : null),
            'href' => $current ? url('/deliveries/' . (int) $current['id'])
                : ($dels ? (count($dels) === 1 ? url('/deliveries/' . (int) $dels[0]['id']) : $base . '#deliveries') : null),
            'state' => $state('delivery', $dels !== []),
            'add' => !$dels && (!$import || $licence) ? url("/purchase-orders/$pid/deliveries/new") : null,
        ];
        return ['order_id' => $oid, 'steps' => $steps];
    }
}
