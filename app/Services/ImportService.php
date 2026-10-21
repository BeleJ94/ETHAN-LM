<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\DomainException;
use App\Domain\ImportGate;
use App\Repositories\ImportRepository;
use App\Security\Auth;

/**
 * Processus « importation » : licence (1 par PO fournisseur), inspections BIVAC pour l'OCC, décisions AV / ARA.
 * Chaque écriture recalcule le statut du PO client (en attente de licence, en inspection, bloqué ARA…).
 */
final class ImportService
{
    public const DEFAULT_INSPECTOR = 'BIVAC (pour le compte de l\'OCC)';

    /* ---------- Licences ---------- */

    /**
     * @param array{number:string,issued_at:string,expires_at:string,origin_country:?string,currency:string,amount:?float,bank:?string,notes:?string} $data
     * @param array<string,mixed>|null $file
     * @param array{attachment_id?:?int,lines?:list<array<string,mixed>>,source?:string,import_id?:?int} $options
     *        import de document : pièce jointe déjà enregistrée, lignes lues sur le document, origine tracée
     */
    public function createLicence(int $poId, array $data, ?array $file, array $options = []): int
    {
        if ($data['expires_at'] < $data['issued_at']) {
            throw new DomainException('imports.error.expiry_before_issue');
        }
        $po = $this->lockImportPo($poId);
        if ((new ImportRepository())->licenceForPo($poId) !== null) {
            throw new DomainException('imports.error.po_has_licence');
        }
        $attachmentId = $options['attachment_id'] ?? $this->storeFile((int) $po['order_id'], $poId, $file, t('imports.licence') . ' ' . $data['number']);
        $source = ($options['source'] ?? 'manual') === 'document' ? 'document' : 'manual';
        return Database::transaction(function () use ($po, $poId, $data, $attachmentId, $options, $source) {
            $pdo = Database::pdo();
            if ((new ImportRepository())->numberExists($data['number'])) {
                throw new DomainException('imports.error.number_taken', ['number' => $data['number']]);
            }
            $pdo->prepare('INSERT INTO import_licences (number, cod_number, po_id, order_id, issued_at, expires_at, origin_country, currency, amount, freight, insurance, other_costs, bank, bank_id, branch_id, notes, source, import_id, attachment_id, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$data['number'], $data['cod_number'] ?? null, $poId, $po['order_id'], $data['issued_at'], $data['expires_at'], $data['origin_country'], $data['currency'],
                    $data['amount'], $data['freight'] ?? null, $data['insurance'] ?? null, $data['other_costs'] ?? null, $data['bank'], $data['bank_id'] ?? null, $data['branch_id'] ?? null, $data['notes'], $source, $options['import_id'] ?? null, $attachmentId, Auth::require()->id]);
            $id = (int) $pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO import_licence_lines (licence_id, line_no, description, hs_code, quantity, unit, unit_price, amount, po_line_id) VALUES (?,?,?,?,?,?,?,?,?)');
            foreach (array_values($options['lines'] ?? []) as $i => $l) {
                $ins->execute([$id, $i + 1, $l['description'], $l['hs_code'] ?? null, $l['quantity'] ?? null, $l['unit'] ?? null, $l['unit_price'] ?? null, $l['amount'] ?? null, $l['po_line_id'] ?? null]);
            }
            ActivityLogger::log('import_licence', $id, 'create', null, $data + ['po' => $po['reference'], 'source' => $source, 'import_id' => $options['import_id'] ?? null, 'lines' => count($options['lines'] ?? [])]);
            OrderService::event((int) $po['order_id'], 'licence_added', $data['number'] . ' · ' . $po['reference']);
            $this->syncChecklist((int) $po['order_id'], $data['number'], $data['issued_at'], $data['expires_at'], $attachmentId);
            OrderService::refresh((int) $po['order_id']);
            return $id;
        });
    }

    /** @param array<string,mixed> $data mêmes champs que createLicence */
    public function updateLicence(int $licenceId, array $data, ?array $file): void
    {
        if ($data['expires_at'] < $data['issued_at']) {
            throw new DomainException('imports.error.expiry_before_issue');
        }
        $lic = (new ImportRepository())->licence($licenceId) ?? throw new DomainException('imports.error.not_found');
        OrderService::lockOpen((int) $lic['order_id']);
        if (!empty($lic['cod_number']) && ($data['cod_number'] ?? null) !== $lic['cod_number']) {
            $used = Database::pdo()->prepare('SELECT COUNT(*) FROM inspections WHERE licence_id = ? AND reference LIKE ?');
            $used->execute([$lic['id'], $lic['cod_number'] . ' / %']);
            if (($n = (int) $used->fetchColumn()) > 0) {
                throw new DomainException('imports.error.cod_locked', ['cod' => $lic['cod_number'], 'n' => $n]);
            }
        }
        $attachmentId = $this->storeFile((int) $lic['order_id'], (int) $lic['po_id'], $file, t('imports.licence') . ' ' . $data['number']) ?? $lic['attachment_id'];
        Database::transaction(function () use ($lic, $data, $attachmentId) {
            Database::pdo()->prepare('UPDATE import_licences SET number = ?, cod_number = ?, issued_at = ?, expires_at = ?, origin_country = ?, currency = ?, amount = ?,
                    freight = ?, insurance = ?, other_costs = ?, bank = ?, bank_id = ?, branch_id = ?, notes = ?, attachment_id = ? WHERE id = ?')
                ->execute([$data['number'], $data['cod_number'] ?? null, $data['issued_at'], $data['expires_at'], $data['origin_country'], $data['currency'], $data['amount'],
                    $data['freight'] ?? null, $data['insurance'] ?? null, $data['other_costs'] ?? null, $data['bank'], $data['bank_id'] ?? null, $data['branch_id'] ?? null, $data['notes'], $attachmentId, $lic['id']]);
            ActivityLogger::log('import_licence', (int) $lic['id'], 'update', array_intersect_key($lic, $data), $data);
            OrderService::event((int) $lic['order_id'], 'licence_updated', $data['number']);
            OrderService::refresh((int) $lic['order_id']);
        });
    }

    /* ---------- Inspections ---------- */

    /**
     * Inspection (partielle possible) d'un PO fournisseur couvert par une licence valide.
     * @param array<string,mixed> $data inspector, inspection_date, location, report_number, report_date, decision, decision_number, decision_date, reason, regularizes_id, notes
     * @param array<int,array{inspected:float,conform:float}> $lines po_line_id → quantités
     */
    public function createInspection(int $poId, array $data, array $lines, ?array $file): int
    {
        $po = $this->lockImportPo($poId);
        $repo = new ImportRepository();
        $licence = $repo->licenceForPo($poId) ?? throw new DomainException('imports.error.no_licence');
        if (!ImportGate::licenceValid($licence, date('Y-m-d'))) {
            throw new DomainException('imports.error.licence_invalid', ['number' => $licence['number']]);
        }
        $lines = $this->checkLines($poId, $lines);
        $this->checkDecision($data);
        if ($data['regularizes_id']) {
            $ara = $repo->inspection((int) $data['regularizes_id']);
            if (!$ara || (int) $ara['po_id'] !== $poId || $ara['decision'] !== 'ara') {
                throw new DomainException('imports.error.bad_regularization');
            }
        }
        $attachmentId = $this->storeFile((int) $po['order_id'], $poId, $file, t('imports.report') . ' ' . ($data['report_number'] ?: ''));
        return Database::transaction(function () use ($po, $poId, $licence, $data, $lines, $attachmentId) {
            $pdo = Database::pdo();
            $ref = self::inspectionRef($licence, (string) ($data['reference'] ?? ''), (string) ($data['reference_suggested'] ?? ''));
            $data['report_number'] = ($data['report_number'] ?? '') !== '' ? $data['report_number'] : $ref;
            $pdo->prepare('INSERT INTO inspections (reference, po_id, licence_id, inspector, inspection_date, location, report_number, report_date,
                    decision, decision_number, decision_date, reason, regularizes_id, attachment_id, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$ref, $poId, $licence['id'], $data['inspector'], $data['inspection_date'], $data['location'], $data['report_number'], $data['report_date'],
                    $data['decision'], $data['decision_number'], $data['decision_date'], $data['reason'], $data['regularizes_id'] ?: null, $attachmentId,
                    $data['notes'], Auth::require()->id]);
            $id = (int) $pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO inspection_lines (inspection_id, po_line_id, quantity_inspected, quantity_conform) VALUES (?,?,?,?)');
            foreach ($lines as $lineId => $l) {
                $ins->execute([$id, $lineId, $l['inspected'], $l['conform']]);
            }
            ActivityLogger::log('inspection', $id, 'create', null, $data + ['reference' => $ref, 'lines' => count($lines)]);
            OrderService::event((int) $po['order_id'], 'inspection', $ref . ' · ' . $po['reference']);
            if ($data['decision'] !== 'pending') {
                $this->afterDecision((int) $po['order_id'], $ref, $data, $attachmentId);
            }
            OrderService::refresh((int) $po['order_id']);
            return $id;
        });
    }

    /**
     * Référence d'une inspection (dans la transaction d'écriture).
     * Licence avec COD : proposition « COD … / 0001 » laissée telle quelle → n° suivant attribué maintenant (verrou, pas de doublon) ;
     * référence modifiée par l'utilisateur → conservée après contrôle (format, unicité).
     * Licence sans COD : générateur standard (INSP-AAAA-NNNNNN), lui aussi modifiable.
     */
    private static function inspectionRef(array $licence, string $typed, string $suggested): string
    {
        $next = (new ImportRepository())->nextInspectionRef($licence, true);
        if ($next === null) {
            return ReferenceGenerator::resolve('inspection', $typed, $suggested);
        }
        $typed = mb_strtoupper(trim((string) preg_replace('/\s+/', ' ', $typed)));
        if ($typed === '' || $typed === $suggested) {
            return $next;
        }
        if (mb_strlen($typed) > 30 || !preg_match('/^[A-Z0-9][A-Z0-9 \-_\/.]*$/', $typed)) {
            throw new DomainException('imports.error.inspection_ref_format');
        }
        $taken = Database::pdo()->prepare('SELECT 1 FROM inspections WHERE reference = ?');
        $taken->execute([$typed]);
        if ($taken->fetchColumn()) {
            throw new DomainException('imports.error.inspection_ref_taken', ['ref' => $typed]);
        }
        return $typed;
    }

    /** Rapport d'inspection joint ou remplacé après l'enregistrement (l'ancien fichier reste dans les pièces jointes du dossier). */
    public function attachReport(int $inspectionId, ?array $file): void
    {
        $insp = (new ImportRepository())->inspection($inspectionId) ?? throw new DomainException('imports.error.not_found');
        $po = $this->lockImportPo((int) $insp['po_id']);
        OrderService::lockOpen((int) $po['order_id']);
        $attachmentId = $this->storeFile((int) $po['order_id'], (int) $po['id'], $file, t('imports.report') . ' ' . ($insp['report_number'] ?: $insp['reference']))
            ?? throw new DomainException('imports.error.report_file');
        Database::transaction(function () use ($insp, $po, $attachmentId) {
            Database::pdo()->prepare('UPDATE inspections SET attachment_id = ? WHERE id = ?')->execute([$attachmentId, $insp['id']]);
            ActivityLogger::log('inspection', (int) $insp['id'], 'report', ['attachment_id' => $insp['attachment_id']], ['attachment_id' => $attachmentId]);
            OrderService::event((int) $po['order_id'], 'inspection', $insp['reference'] . ' · ' . t('imports.report_attached_event'));
            foreach ((new ImportRepository())->allocationsByInspection((int) $po['id'])[(int) $insp['id']] ?? [] as $u) {
                ChecklistService::syncInspectionDocs($u['delivery_id']);
            }
        });
    }

    /** Décision reçue après coup (rapport en attente) : AV ou ARA. */
    public function recordDecision(int $inspectionId, array $data, ?array $file): void
    {
        $repo = new ImportRepository();
        $insp = $repo->inspection($inspectionId) ?? throw new DomainException('imports.error.not_found');
        $po = $this->lockImportPo((int) $insp['po_id']);
        if ($insp['decision'] !== 'pending') {
            throw new DomainException('imports.error.already_decided');
        }
        $this->checkDecision($data);
        $attachmentId = $this->storeFile((int) $po['order_id'], (int) $po['id'], $file, t('imports.report') . ' ' . ($data['report_number'] ?: $insp['reference'])) ?? $insp['attachment_id'];
        Database::transaction(function () use ($insp, $po, $data, $attachmentId) {
            Database::pdo()->prepare('UPDATE inspections SET report_number = COALESCE(?, report_number, reference), report_date = COALESCE(?, report_date),
                    decision = ?, decision_number = ?, decision_date = ?, reason = ?, attachment_id = ? WHERE id = ?')
                ->execute([$data['report_number'], $data['report_date'], $data['decision'], $data['decision_number'], $data['decision_date'],
                    $data['reason'], $attachmentId, $insp['id']]);
            ActivityLogger::log('inspection', (int) $insp['id'], 'decision', ['decision' => 'pending'], $data);
            $this->afterDecision((int) $po['order_id'], $insp['reference'], $data + ['regularizes_id' => $insp['regularizes_id']], $attachmentId);
            OrderService::refresh((int) $po['order_id']);
        });
    }

    /** Événements de chronologie + check-list (l'AV alimente le certificat de vérification si la règle existe). */
    private function afterDecision(int $orderId, string $ref, array $data, ?int $attachmentId): void
    {
        if ($data['decision'] === 'ara') {
            OrderService::event($orderId, 'ara', $ref . ' · ' . ($data['decision_number'] ?? '') . ' — ' . ($data['reason'] ?? ''));
            return;
        }
        OrderService::event($orderId, $data['regularizes_id'] ? 'ara_regularized' : 'av', $ref . ' · ' . ($data['decision_number'] ?? ''));
    }

    private function checkDecision(array $data): void
    {
        if (!in_array($data['decision'], ['pending', 'av', 'ara'], true)) {
            throw new DomainException('validation.in');
        }
        if ($data['decision'] !== 'pending' && (empty($data['decision_number']) || empty($data['decision_date']))) {
            throw new DomainException('imports.error.decision_details');
        }
        if ($data['decision'] === 'ara' && trim((string) $data['reason']) === '') {
            throw new DomainException('imports.error.ara_reason');
        }
    }

    /**
     * Quantités inspectées ≤ quantité de la ligne de PO, conformes ≤ inspectées ; au moins une ligne.
     * @return array<int,array{inspected:float,conform:float}>
     */
    private function checkLines(int $poId, array $lines): array
    {
        $stmt = Database::pdo()->prepare('SELECT id, quantity FROM purchase_order_lines WHERE po_id = ?');
        $stmt->execute([$poId]);
        $poLines = array_map('floatval', array_column($stmt->fetchAll(), 'quantity', 'id'));
        $out = [];
        foreach ($lines as $lineId => $l) {
            if ($l['inspected'] <= 0) {
                continue;
            }
            if (!isset($poLines[$lineId])) {
                throw new DomainException('po.error.bad_line');
            }
            if ($l['inspected'] > $poLines[$lineId] + 1e-9 || $l['conform'] > $l['inspected'] + 1e-9 || $l['conform'] < 0) {
                throw new DomainException('imports.error.bad_quantities');
            }
            $out[$lineId] = $l;
        }
        if ($out === []) {
            throw new DomainException('imports.error.no_lines');
        }
        return $out;
    }

    /** PO fournisseur d'un dossier « importation » ouvert. @return array<string,mixed> */
    private function lockImportPo(int $poId): array
    {
        $stmt = Database::pdo()->prepare("SELECT p.*, o.fulfillment_mode FROM purchase_orders p JOIN customer_orders o ON o.id = p.order_id WHERE p.id = ?");
        $stmt->execute([$poId]);
        $po = $stmt->fetch() ?: throw new DomainException('po.error.not_found');
        if ($po['fulfillment_mode'] !== 'import') {
            throw new DomainException('imports.error.not_import');
        }
        if ($po['status'] === 'cancelled') {
            throw new DomainException('po.error.cannot_cancel');
        }
        OrderService::lockOpen((int) $po['order_id']);
        return $po;
    }

    private function storeFile(int $orderId, int $poId, ?array $file, string $label): ?int
    {
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return (new AttachmentService())->store($orderId, 'purchase_order', $poId, $file, $label);
    }

    /**
     * La licence enregistrée alimente la ligne « Licence d'importation » de la check-list du dossier :
     * déposée (n°, dates, fichier) et en attente de validation (contrôle à deux niveaux conservé).
     */
    private function syncChecklist(int $orderId, string $number, string $issued, string $expires, ?int $attachmentId): void
    {
        ChecklistService::generateForOrder($orderId);
        Database::pdo()->prepare("UPDATE shipment_documents sd JOIN document_types t ON t.id = sd.document_type_id
                SET sd.status = 'submitted', sd.number = ?, sd.issued_at = ?, sd.expires_at = ?, sd.attachment_id = COALESCE(?, sd.attachment_id),
                    sd.submitted_by = ?, sd.submitted_at = NOW(), sd.decided_by = NULL, sd.decided_at = NULL, sd.decision_note = NULL
            WHERE sd.order_id = ? AND sd.delivery_id IS NULL AND t.code = 'LIC' AND sd.status IN ('pending','rejected')")
            ->execute([$number, $issued, $expires, $attachmentId, Auth::require()->id, $orderId]);
    }
}
