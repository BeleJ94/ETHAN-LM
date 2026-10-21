<?php

declare(strict_types=1);

/**
 * Reprise : rattache aux AV les lignes d'expédition d'import créées avant la traçabilité par AV.
 * Règle automatique (AV la plus ancienne d'abord), expéditions dans l'ordre chronologique.
 * Idempotent : seules les lignes sans répartition sont traitées. Usage : php bin/backfill-av-allocations.php
 */
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\Env;
use App\Domain\AvAllocation;
use App\Repositories\ImportRepository;

Env::load(__DIR__ . '/../.env');
$pdo = Database::pdo();
$repo = new ImportRepository();
$lines = $pdo->query("SELECT dl.id, dl.po_line_id, dl.quantity_shipped, d.id AS delivery_id, d.reference, d.po_id
    FROM delivery_lines dl JOIN deliveries d ON d.id = dl.delivery_id JOIN customer_orders o ON o.id = d.order_id
    WHERE o.fulfillment_mode = 'import' AND d.status <> 'cancelled'
      AND NOT EXISTS (SELECT 1 FROM delivery_line_avs a WHERE a.delivery_line_id = dl.id)
    ORDER BY d.shipped_at, d.id, dl.id")->fetchAll();
$done = $skipped = 0;
foreach ($lines as $l) {
    Database::transaction(function () use ($pdo, $repo, $l, &$done, &$skipped) {
        $avs = array_values(array_map(fn ($b) => ['id' => $b['inspection_id'], 'remaining' => $b['remaining'], 'label' => $b['decision_number'] ?: $b['reference']],
            array_filter($repo->avBalances((int) $l['po_id'], true), fn ($b) => $b['po_line_id'] === (int) $l['po_line_id'])));
        $alloc = AvAllocation::fifo((float) $l['quantity_shipped'], $avs);
        if ($alloc === null) {
            echo "  ⚠ {$l['reference']} : {$l['quantity_shipped']} non couverts par les AV — à répartir manuellement\n";
            $skipped++;
            return;
        }
        $ins = $pdo->prepare('INSERT INTO delivery_line_avs (delivery_line_id, inspection_id, quantity) VALUES (?,?,?)');
        $labels = array_column($avs, 'label', 'id');
        foreach ($alloc as $iid => $q) {
            $ins->execute([$l['id'], $iid, $q]);
            echo "  {$l['reference']} : " . rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.') . " → {$labels[$iid]}\n";
        }
        \App\Services\ActivityLogger::log('delivery', (int) $l['delivery_id'], 'av_allocation', null, ['backfill' => true, 'line' => (int) $l['id'], 'allocations' => $alloc]);
        $done++;
    });
}
echo "Lignes rattachées : $done · non couvertes : $skipped\n";
// Documents AV / RI des expéditions couvertes par des AV
$docs = 0;
foreach ($pdo->query("SELECT DISTINCT dl.delivery_id FROM delivery_line_avs a JOIN delivery_lines dl ON dl.id = a.delivery_line_id
        JOIN deliveries d ON d.id = dl.delivery_id WHERE d.status <> 'cancelled'")->fetchAll(PDO::FETCH_COLUMN) as $deliveryId) {
    $docs += Database::transaction(fn () => \App\Services\ChecklistService::syncInspectionDocs((int) $deliveryId));
}
echo "Documents AV / RI mis à jour : $docs\n";
