<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Security\SiteScope;

/** Licences d'importation (répertoire) et inspections BIVAC/OCC, dans le périmètre de sites. */
final class ImportRepository
{
    private const LICENCE_SELECT = "SELECT l.*, p.reference AS po_reference, p.status AS po_status, p.currency AS po_currency,
            s.name AS supplier_name, o.reference AS order_reference, o.customer_po_number, o.status AS order_status,
            c.name AS customer_name, u.name AS created_by_name, a.original_name AS file_name, a.mime AS file_mime,
            (SELECT COALESCE(SUM(pl.quantity * pl.unit_price), 0) FROM purchase_order_lines pl WHERE pl.po_id = p.id) AS po_amount,
            (SELECT COUNT(*) FROM inspections i WHERE i.licence_id = l.id) AS inspection_count,
            (SELECT COUNT(*) FROM inspections i WHERE i.licence_id = l.id AND i.decision = 'av') AS av_count,
            (SELECT COUNT(*) FROM inspections i WHERE i.licence_id = l.id AND i.decision = 'ara'
                AND NOT EXISTS (SELECT 1 FROM inspections r WHERE r.regularizes_id = i.id AND r.decision = 'av')) AS open_ara
        FROM import_licences l
        JOIN purchase_orders p ON p.id = l.po_id
        JOIN suppliers s ON s.id = p.supplier_id
        JOIN customer_orders o ON o.id = l.order_id
        JOIN customers c ON c.id = o.customer_id
        JOIN users u ON u.id = l.created_by
        LEFT JOIN attachments a ON a.id = l.attachment_id";

    /** @return list<array<string,mixed>> Répertoire des licences */
    public function licences(): array
    {
        [$scope, $params] = SiteScope::clause('o.site_id');
        $stmt = Database::pdo()->prepare(self::LICENCE_SELECT . ' WHERE 1=1' . $scope . ' ORDER BY l.expires_at < CURDATE(), l.expires_at, l.id DESC');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function licence(int $id): ?array
    {
        [$scope, $params] = SiteScope::clause('o.site_id');
        $stmt = Database::pdo()->prepare(self::LICENCE_SELECT . ' WHERE l.id = ?' . $scope);
        $stmt->execute([$id, ...$params]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<string,mixed>|null Licence d'un PO fournisseur (1 pour 1) */
    public function licenceForPo(int $poId): ?array
    {
        $stmt = Database::pdo()->prepare(self::LICENCE_SELECT . ' WHERE l.po_id = ? AND l.status = \'active\'');
        $stmt->execute([$poId]);
        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> Marchandises portées par la licence (import depuis document) */
    public function licenceLines(int $licenceId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM import_licence_lines WHERE licence_id = ? ORDER BY line_no');
        $stmt->execute([$licenceId]);
        return $stmt->fetchAll();
    }

    /**
     * Soldes des AV d'un PO, par AV et par ligne de PO (plus ancienne AV d'abord) :
     * conforme − prélevé par des expéditions non annulées = reste.
     * @return list<array{inspection_id:int,reference:string,decision_number:?string,decision_date:?string,po_line_id:int,conform:float,allocated:float,remaining:float}>
     */
    public function avBalances(int $poId, bool $lock = false): array
    {
        $stmt = Database::pdo()->prepare("SELECT i.id AS inspection_id, i.reference, i.decision_number, i.decision_date, il.po_line_id, il.quantity_conform AS conform,
                COALESCE((SELECT SUM(a.quantity) FROM delivery_line_avs a JOIN delivery_lines dl ON dl.id = a.delivery_line_id
                    JOIN deliveries d ON d.id = dl.delivery_id
                    WHERE a.inspection_id = i.id AND dl.po_line_id = il.po_line_id AND d.status <> 'cancelled'), 0) AS allocated
            FROM inspections i JOIN inspection_lines il ON il.inspection_id = i.id
            WHERE i.po_id = ? AND i.decision = 'av' AND il.quantity_conform > 0
            ORDER BY COALESCE(i.decision_date, i.inspection_date), i.id" . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$poId]);
        return array_map(fn ($r) => [
            'inspection_id' => (int) $r['inspection_id'], 'reference' => $r['reference'], 'decision_number' => $r['decision_number'],
            'decision_date' => $r['decision_date'], 'po_line_id' => (int) $r['po_line_id'], 'conform' => (float) $r['conform'],
            'allocated' => (float) $r['allocated'], 'remaining' => max(0.0, round((float) $r['conform'] - (float) $r['allocated'], 3)),
        ], $stmt->fetchAll());
    }

    /**
     * AV qui couvrent les lignes d'expéditions données.
     * @param list<int> $deliveryIds
     * @return array<int,array<int,list<array{inspection_id:int,reference:string,decision_number:?string,quantity:float}>>> delivery_id → po_line_id → AV
     */
    public function allocationsByDelivery(array $deliveryIds): array
    {
        if (!$deliveryIds) {
            return [];
        }
        $in = implode(',', array_map('intval', $deliveryIds));
        $out = [];
        foreach (Database::pdo()->query("SELECT dl.delivery_id, dl.po_line_id, a.inspection_id, a.quantity, i.reference, i.decision_number
                FROM delivery_line_avs a JOIN delivery_lines dl ON dl.id = a.delivery_line_id JOIN inspections i ON i.id = a.inspection_id
                WHERE dl.delivery_id IN ($in) ORDER BY COALESCE(i.decision_date, i.inspection_date), i.id") as $r) {
            $out[(int) $r['delivery_id']][(int) $r['po_line_id']][] = ['inspection_id' => (int) $r['inspection_id'], 'reference' => $r['reference'],
                'decision_number' => $r['decision_number'], 'quantity' => (float) $r['quantity']];
        }
        return $out;
    }

    /**
     * Expéditions (non annulées) qui ont prélevé sur chaque AV d'un PO.
     * @return array<int,list<array{delivery_id:int,reference:string,status:string,quantity:float}>> inspection_id → expéditions
     */
    public function allocationsByInspection(int $poId): array
    {
        $stmt = Database::pdo()->prepare("SELECT a.inspection_id, d.id AS delivery_id, d.reference, d.status, SUM(a.quantity) AS quantity
            FROM delivery_line_avs a JOIN delivery_lines dl ON dl.id = a.delivery_line_id JOIN deliveries d ON d.id = dl.delivery_id
            JOIN inspections i ON i.id = a.inspection_id
            WHERE i.po_id = ? AND d.status <> 'cancelled' GROUP BY a.inspection_id, d.id, d.reference, d.status ORDER BY d.shipped_at, d.id");
        $stmt->execute([$poId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['inspection_id']][] = ['delivery_id' => (int) $r['delivery_id'], 'reference' => $r['reference'], 'status' => $r['status'], 'quantity' => (float) $r['quantity']];
        }
        return $out;
    }

    public function codExists(string $cod, ?int $exceptId = null): bool
    {
        $stmt = Database::pdo()->prepare('SELECT 1 FROM import_licences WHERE cod_number = ? AND id <> ?');
        $stmt->execute([$cod, $exceptId ?? 0]);
        return (bool) $stmt->fetchColumn();
    }

    /** Prochain n° d'inspection d'une licence « COD … / 0001 » (null : licence sans COD). */
    public function nextInspectionRef(array $licence, bool $lock = false): ?string
    {
        if (empty($licence['cod_number'])) {
            return null;
        }
        $stmt = Database::pdo()->prepare('SELECT reference FROM inspections WHERE licence_id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([(int) $licence['id']]);
        return \App\Domain\InspectionRef::next($licence['cod_number'], $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function numberExists(string $number, ?int $exceptId = null): bool
    {
        $stmt = Database::pdo()->prepare('SELECT 1 FROM import_licences WHERE number = ? AND id <> ?');
        $stmt->execute([$number, $exceptId ?? 0]);
        return (bool) $stmt->fetchColumn();
    }

    /** @return list<array<string,mixed>> PO fournisseurs de dossiers « importation » sans licence active (pour en créer une) */
    public function posWithoutLicence(): array
    {
        [$scope, $params] = SiteScope::clause('o.site_id');
        $stmt = Database::pdo()->prepare("SELECT p.id, p.reference, p.currency, p.supplier_id, s.name AS supplier_name, o.reference AS order_reference,
                o.customer_po_number, c.name AS customer_name,
                (SELECT COALESCE(SUM(pl.quantity * pl.unit_price), 0) FROM purchase_order_lines pl WHERE pl.po_id = p.id) AS amount
            FROM purchase_orders p JOIN suppliers s ON s.id = p.supplier_id
            JOIN customer_orders o ON o.id = p.order_id JOIN customers c ON c.id = o.customer_id
            WHERE o.fulfillment_mode = 'import' AND o.status NOT IN ('closed','cancelled') AND p.status <> 'cancelled'
              AND NOT EXISTS (SELECT 1 FROM import_licences l WHERE l.po_id = p.id AND l.status = 'active')" . $scope . '
            ORDER BY p.id DESC');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> Inspections d'un PO fournisseur, avec leurs lignes et leur état de régularisation */
    public function inspections(int $poId): array
    {
        $stmt = Database::pdo()->prepare("SELECT i.*, u.name AS created_by_name, a.original_name AS file_name, a.mime AS file_mime,
                r.reference AS regularizes_reference,
                (SELECT x.reference FROM inspections x WHERE x.regularizes_id = i.id AND x.decision = 'av' LIMIT 1) AS regularized_by
            FROM inspections i JOIN users u ON u.id = i.created_by
            LEFT JOIN attachments a ON a.id = i.attachment_id
            LEFT JOIN inspections r ON r.id = i.regularizes_id
            WHERE i.po_id = ? ORDER BY i.inspection_date DESC, i.id DESC");
        $stmt->execute([$poId]);
        $rows = $stmt->fetchAll();
        $lines = Database::pdo()->prepare('SELECT il.*, it.sku, u.code AS unit FROM inspection_lines il
            JOIN purchase_order_lines pl ON pl.id = il.po_line_id JOIN items it ON it.id = pl.item_id JOIN units u ON u.id = it.unit_id
            WHERE il.inspection_id = ?');
        foreach ($rows as &$r) {
            $lines->execute([$r['id']]);
            $r['lines'] = $lines->fetchAll();
        }
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function inspection(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM inspections WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int,float> po_line_id → quantité conforme cumulée des AV */
    public function cleared(int $poId): array
    {
        $stmt = Database::pdo()->prepare("SELECT pl.id, COALESCE(SUM(CASE WHEN i.decision = 'av' THEN il.quantity_conform END), 0) AS q
            FROM purchase_order_lines pl
            LEFT JOIN inspection_lines il ON il.po_line_id = pl.id
            LEFT JOIN inspections i ON i.id = il.inspection_id
            WHERE pl.po_id = ? GROUP BY pl.id");
        $stmt->execute([$poId]);
        return array_map('floatval', array_column($stmt->fetchAll(), 'q', 'id'));
    }

    /** @return array<int,float> po_line_id → quantité déjà engagée en expéditions (hors annulées) */
    public function committed(int $poId): array
    {
        $stmt = Database::pdo()->prepare("SELECT pl.id, COALESCE(SUM(dl.quantity_shipped), 0) AS q
            FROM purchase_order_lines pl
            LEFT JOIN delivery_lines dl ON dl.po_line_id = pl.id
            LEFT JOIN deliveries d ON d.id = dl.delivery_id AND d.status <> 'cancelled'
            WHERE pl.po_id = ? AND (dl.id IS NULL OR d.id IS NOT NULL) GROUP BY pl.id");
        $stmt->execute([$poId]);
        return array_map('floatval', array_column($stmt->fetchAll(), 'q', 'id'));
    }

    /** Nombre d'ARA non régularisés par une AV pour ce PO fournisseur. */
    public function openAra(int $poId): int
    {
        $stmt = Database::pdo()->prepare("SELECT COUNT(*) FROM inspections i WHERE i.po_id = ? AND i.decision = 'ara'
            AND NOT EXISTS (SELECT 1 FROM inspections r WHERE r.regularizes_id = i.id AND r.decision = 'av')");
        $stmt->execute([$poId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Synthèse import d'un PO fournisseur (fiche PO, dossier, statut du PO client).
     * @return array{licence:?array,cleared:array<int,float>,committed:array<int,float>,shippable:array<int,float>,open_ara:int,cleared_total:float}
     */
    public function summary(int $poId): array
    {
        $cleared = $this->cleared($poId);
        $committed = $this->committed($poId);
        return [
            'licence' => $this->licenceForPo($poId),
            'cleared' => $cleared,
            'committed' => $committed,
            'shippable' => \App\Domain\ImportGate::shippable($cleared, $committed),
            'open_ara' => $this->openAra($poId),
            'cleared_total' => array_sum($cleared),
        ];
    }
}
