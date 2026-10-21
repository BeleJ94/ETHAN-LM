<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\Checklist;
use App\Domain\DomainException;
use App\Repositories\DocumentRepository;
use App\Repositories\ShipmentDocumentRepository;
use App\Security\Auth;

/**
 * Check-list documentaire : génération figée par expédition / dossier, cycle de vie des documents
 * (déposé → validé par une autre personne, rejet, dérogation tracée) et calcul des blocages.
 * Toutes les méthodes d'écriture s'exécutent dans la transaction appelante ou leur propre transaction.
 */
final class ChecklistService
{
    /**
     * Génère (ou complète) la check-list d'une expédition et les documents de niveau dossier correspondants.
     * $prune : retire les documents encore « à fournir » qui ne correspondent plus (bouton Recalculer).
     */
    public static function generateForDelivery(int $deliveryId, bool $prune = false): void
    {
        $pdo = Database::pdo();
        $d = $pdo->prepare('SELECT d.id, d.order_id, d.transport_mode, o.fulfillment_mode FROM deliveries d
            JOIN customer_orders o ON o.id = d.order_id WHERE d.id = ?');
        $d->execute([$deliveryId]);
        $delivery = $d->fetch();
        if (!$delivery) {
            return;
        }
        $fam = $pdo->prepare('SELECT DISTINCT i.family_id FROM delivery_lines dl JOIN purchase_order_lines pl ON pl.id = dl.po_line_id
            JOIN items i ON i.id = pl.item_id WHERE dl.delivery_id = ?');
        $fam->execute([$deliveryId]);
        $ctx = [
            'mode' => $delivery['fulfillment_mode'],
            'family_ids' => array_map('intval', $fam->fetchAll(\PDO::FETCH_COLUMN)),
            'transport_modes' => [$delivery['transport_mode']],
        ];
        $rules = (new DocumentRepository())->activeRules();
        $levels = array_column($rules, 'level', 'document_type_id');
        $expected = Checklist::expected($rules, $ctx);

        $orderId = (int) $delivery['order_id'];
        foreach ($expected as $typeId => $req) {
            $scopeDelivery = ($levels[$typeId] ?? 'delivery') === 'delivery' ? $deliveryId : null;
            self::upsert($orderId, $scopeDelivery, $typeId, $req);
        }
        if ($prune) {
            $keepDelivery = array_values(array_filter(array_keys($expected), fn ($t) => ($levels[$t] ?? 'delivery') === 'delivery'));
            $sql = 'DELETE FROM shipment_documents WHERE delivery_id = ? AND status = \'pending\' AND attachment_id IS NULL';
            $params = [$deliveryId];
            if ($keepDelivery) {
                $sql .= ' AND document_type_id NOT IN (' . implode(',', array_fill(0, count($keepDelivery), '?')) . ')';
                array_push($params, ...$keepDelivery);
            }
            $pdo->prepare($sql)->execute($params);
        }
    }

    /**
     * Documents de niveau dossier (licence, assurance, FERI…) dès l'émission d'un PO fournisseur,
     * pour pouvoir les déposer avant le premier camion. Contexte : familles du PO client, modes de transport des PO.
     */
    public static function generateForOrder(int $orderId): void
    {
        $pdo = Database::pdo();
        $o = $pdo->prepare('SELECT fulfillment_mode FROM customer_orders WHERE id = ?');
        $o->execute([$orderId]);
        $mode = $o->fetchColumn();
        if ($mode === false) {
            return;
        }
        $fam = $pdo->prepare('SELECT DISTINCT i.family_id FROM customer_order_lines l JOIN items i ON i.id = l.item_id WHERE l.order_id = ?');
        $fam->execute([$orderId]);
        $tr = $pdo->prepare("SELECT DISTINCT transport_mode FROM purchase_orders WHERE order_id = ? AND status <> 'cancelled'");
        $tr->execute([$orderId]);
        $ctx = [
            'mode' => $mode,
            'family_ids' => array_map('intval', $fam->fetchAll(\PDO::FETCH_COLUMN)),
            'transport_modes' => $tr->fetchAll(\PDO::FETCH_COLUMN) ?: ['road'],
        ];
        $rules = array_values(array_filter((new DocumentRepository())->activeRules(), fn ($r) => $r['level'] === 'order'));
        foreach (Checklist::expected($rules, $ctx) as $typeId => $req) {
            self::upsert($orderId, null, $typeId, $req);
        }
    }

    /** Ajoute la ligne, ou durcit une ligne existante (obligatoire, étape bloquante plus précoce). */
    private static function upsert(int $orderId, ?int $deliveryId, int $typeId, array $req): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id, required, blocking_stage FROM shipment_documents WHERE order_id = ? AND delivery_key = ? AND document_type_id = ?');
        $stmt->execute([$orderId, $deliveryId ?? 0, $typeId]);
        $row = $stmt->fetch();
        if (!$row) {
            $pdo->prepare('INSERT INTO shipment_documents (order_id, delivery_id, document_type_id, required, blocking_stage) VALUES (?,?,?,?,?)')
                ->execute([$orderId, $deliveryId, $typeId, (int) $req['required'], $req['blocking_stage']]);
            return;
        }
        $required = (int) ($row['required'] || $req['required']);
        $stage = Checklist::earliest($row['blocking_stage'], $req['blocking_stage']);
        if ($required !== (int) $row['required'] || $stage !== $row['blocking_stage']) {
            $pdo->prepare('UPDATE shipment_documents SET required = ?, blocking_stage = ? WHERE id = ?')->execute([$required, $stage, $row['id']]);
        }
    }

    /** @return list<array<string,mixed>> Documents bloquant l'étape pour une expédition (dossier compris) */
    public static function deliveryBlockers(int $deliveryId, int $orderId, string $stage): array
    {
        return Checklist::blockers((new ShipmentDocumentRepository())->forDelivery($deliveryId, $orderId), $stage, date('Y-m-d'));
    }

    /**
     * Documents bloquant une étape au niveau du dossier (facturation : expéditions réceptionnées ; clôture : toutes).
     * @return list<array<string,mixed>>
     */
    public static function orderBlockers(int $orderId, string $stage): array
    {
        $docs = (new ShipmentDocumentRepository())->forOrder($orderId);
        if ($stage === 'invoicing') {
            $delivered = Database::pdo()->prepare("SELECT id FROM deliveries WHERE order_id = ? AND status = 'delivered'");
            $delivered->execute([$orderId]);
            $ids = array_map('intval', $delivered->fetchAll(\PDO::FETCH_COLUMN));
            $docs = array_values(array_filter($docs, fn ($d) => $d['delivery_id'] === null || in_array((int) $d['delivery_id'], $ids, true)));
        }
        return Checklist::blockers($docs, $stage, date('Y-m-d'));
    }

    /** Liste lisible pour les messages de blocage. */
    public static function names(array $docs): string
    {
        return implode(', ', array_unique(array_map(fn ($d) => $d['code'], $docs)));
    }

    /* ---------- Cycle de vie d'un document ---------- */

    /**
     * Dépôt (équipe interne ou transitaire). Un nouveau dépôt remplace le précédent et repasse en attente de validation.
     * @param array{number:?string,issued_at:?string,expires_at:?string} $data
     * @param array<string,mixed>|null $file entrée $_FILES
     */
    public function submit(int $docId, array $data, ?array $file): void
    {
        $doc = $this->load($docId);
        if (in_array($doc['status'], ['validated', 'waived', 'not_applicable'], true)) {
            throw new DomainException('documents.error.locked');
        }
        if (Checklist::needsAgency($doc)) {
            throw new DomainException($doc['delivery_id'] !== null ? 'documents.error.agency_required' : 'documents.error.agency_required_order');
        }
        if ($doc['requires_number'] && trim((string) $data['number']) === '') {
            throw new DomainException('documents.error.number_required');
        }
        if ($doc['requires_issue_date'] && empty($data['issued_at'])) {
            throw new DomainException('documents.error.issue_required');
        }
        if ($doc['has_expiry'] && empty($data['expires_at'])) {
            throw new DomainException('documents.error.expiry_required');
        }
        // Cohérence des dates saisies (exigées ou non)
        if (!empty($data['issued_at']) && $data['issued_at'] > date('Y-m-d')) {
            throw new DomainException('documents.error.issue_future');
        }
        if (!empty($data['issued_at']) && !empty($data['expires_at']) && $data['expires_at'] < $data['issued_at']) {
            throw new DomainException('documents.error.expiry_before_issue');
        }
        $hasFile = $file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($doc['requires_file'] && !$hasFile && $doc['attachment_id'] === null) {
            throw new DomainException('documents.error.file_required');
        }
        $attachmentId = $doc['attachment_id'];
        if ($hasFile) {
            $entity = $doc['delivery_id'] !== null ? 'delivery' : 'customer_order';
            $entityId = (int) ($doc['delivery_id'] ?? $doc['order_id']);
            $attachmentId = (new AttachmentService())->store((int) $doc['order_id'], $entity, $entityId, $file, $doc['name'] . ($data['number'] ? ' ' . $data['number'] : ''));
        }
        Database::transaction(function () use ($doc, $data, $attachmentId) {
            Database::pdo()->prepare("UPDATE shipment_documents SET status = 'submitted', number = ?, issued_at = ?, expires_at = ?, attachment_id = ?,
                    submitted_by = ?, submitted_at = NOW(), decided_by = NULL, decided_at = NULL, decision_note = NULL WHERE id = ?")
                ->execute([$data['number'] ?: null, $data['issued_at'] ?: null, $data['expires_at'] ?: null, $attachmentId, Auth::require()->id, $doc['id']]);
            $this->trace($doc, 'submit', ['number' => $data['number']]);
            OrderService::event((int) $doc['order_id'], 'doc_submitted', $this->label($doc));
        });
    }

    /**
     * Second niveau de contrôle : la validation doit être faite par une autre personne que le déposant.
     * Exception : l'administrateur peut valider son propre dépôt (opération de bout en bout seul) — tracé comme auto-validation.
     */
    public function validate(int $docId): void
    {
        Database::transaction(function () use ($docId) {
            $doc = $this->load($docId, true);
            if ($doc['status'] !== 'submitted') {
                throw new DomainException('documents.error.not_submitted');
            }
            $user = Auth::require();
            $self = (int) $doc['submitted_by'] === $user->id;
            if ($self && !self::canSelfValidate($user)) {
                throw new DomainException('documents.error.same_user');
            }
            $this->decide($doc, 'validated', $self ? t('documents.self_validated_note') : null);
            OrderService::event((int) $doc['order_id'], 'doc_validated', $this->label($doc) . ($self ? ' · ' . t('documents.self_validated') : ''));
        });
    }

    /** Seul l'administrateur peut s'auto-valider ; les autres rôles restent soumis au contrôle à deux niveaux. */
    public static function canSelfValidate(\App\Security\CurrentUser $user): bool
    {
        return $user->role->isGlobal();
    }

    public function reject(int $docId, string $note): void
    {
        Database::transaction(function () use ($docId, $note) {
            $doc = $this->load($docId, true);
            if (!in_array($doc['status'], ['submitted', 'validated'], true)) {
                throw new DomainException('documents.error.not_submitted');
            }
            $this->decide($doc, 'rejected', $note);
            OrderService::event((int) $doc['order_id'], 'doc_rejected', $this->label($doc) . ' — ' . $note);
        });
    }

    /** Dérogation (document manquant accepté) ou non applicable — réservé aux responsables, motif obligatoire. */
    public function waive(int $docId, string $kind, string $note): void
    {
        if (!in_array($kind, ['waived', 'not_applicable'], true) || trim($note) === '') {
            throw new DomainException('orders.error.note_required');
        }
        Database::transaction(function () use ($docId, $kind, $note) {
            $doc = $this->load($docId, true);
            if ($doc['status'] === 'validated') {
                throw new DomainException('documents.error.locked');
            }
            $this->decide($doc, $kind, $note);
            OrderService::event((int) $doc['order_id'], $kind === 'waived' ? 'doc_waived' : 'doc_not_applicable', $this->label($doc) . ' — ' . $note);
        });
    }

    /** Annule une décision (validation, dérogation…) : le document redevient à fournir / à valider. */
    public function reset(int $docId, string $note): void
    {
        Database::transaction(function () use ($docId, $note) {
            $doc = $this->load($docId, true);
            $status = $doc['submitted_at'] !== null ? 'submitted' : 'pending';
            Database::pdo()->prepare('UPDATE shipment_documents SET status = ?, decided_by = NULL, decided_at = NULL, decision_note = ? WHERE id = ?')
                ->execute([$status, $note ?: null, $doc['id']]);
            $this->trace($doc, 'reset', ['status' => $status, 'note' => $note]);
            OrderService::event((int) $doc['order_id'], 'doc_reset', $this->label($doc));
        });
    }

    private function decide(array $doc, string $status, ?string $note): void
    {
        Database::pdo()->prepare('UPDATE shipment_documents SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ?')
            ->execute([$status, Auth::require()->id, $note, $doc['id']]);
        $this->trace($doc, $status, ['note' => $note]);
    }

    /** @return array<string,mixed> */
    private function load(int $docId, bool $lock = false): array
    {
        if ($lock) {
            Database::pdo()->prepare('SELECT id FROM shipment_documents WHERE id = ? FOR UPDATE')->execute([$docId]);
        }
        $doc = (new ShipmentDocumentRepository())->find($docId) ?? throw new DomainException('documents.error.not_found');
        $order = Database::pdo()->prepare('SELECT status FROM customer_orders WHERE id = ?');
        $order->execute([$doc['order_id']]);
        if (in_array($order->fetchColumn(), ['closed', 'cancelled'], true)) {
            throw new DomainException('orders.error.already_closed');
        }
        return $doc;
    }

    /** Types de documents d'expédition alimentés automatiquement par les AV liées à l'expédition. */
    public const AV_CODES = ['AV'];
    public const REPORT_CODES = ['RI'];

    /**
     * Importation : documents « Attestation de Vérification » (AV) et « Rapport d'inspection » (RI) de l'expédition
     * renseignés à partir des AV qui couvrent ses lignes : n° (plusieurs AV : « A + B »), date, fichier du rapport.
     * Avec un fichier, le document passe « déposé » : la validation reste à faire (contrôle à deux niveaux).
     * Un document validé, dérogé, non applicable ou rejeté n'est jamais modifié.
     * @return int nombre de documents mis à jour
     */
    public static function syncInspectionDocs(int $deliveryId): int
    {
        $pdo = Database::pdo();
        $allocs = (new \App\Repositories\ImportRepository())->allocationsByDelivery([$deliveryId])[$deliveryId] ?? [];
        $ids = [];
        foreach ($allocs as $lineAllocs) {
            foreach ($lineAllocs as $a) {
                $ids[$a['inspection_id']] = true;
            }
        }
        if (!$ids) {
            return 0;
        }
        $insp = $pdo->query('SELECT id, reference, decision_number, decision_date, report_number, report_date, inspection_date, attachment_id
            FROM inspections WHERE id IN (' . implode(',', array_map('intval', array_keys($ids))) . ') ORDER BY COALESCE(decision_date, inspection_date), id')->fetchAll();
        $codes = array_merge(self::AV_CODES, self::REPORT_CODES);
        $docs = $pdo->prepare('SELECT sd.*, t.code FROM shipment_documents sd JOIN document_types t ON t.id = sd.document_type_id
            WHERE sd.delivery_id = ? AND t.code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')');
        $docs->execute([$deliveryId, ...$codes]);
        $creator = $pdo->prepare('SELECT created_by FROM deliveries WHERE id = ?');
        $creator->execute([$deliveryId]);
        $by = Auth::user()?->id ?? (int) $creator->fetchColumn();
        $changed = 0;
        foreach ($docs->fetchAll() as $doc) {
            if (!in_array($doc['status'], ['pending', 'submitted'], true)) {
                continue;
            }
            $isAv = in_array($doc['code'], self::AV_CODES, true);
            $numbers = array_values(array_unique(array_map(fn ($i) => $isAv ? ($i['decision_number'] ?: $i['reference']) : ($i['report_number'] ?: $i['reference']), $insp)));
            $dates = array_filter(array_map(fn ($i) => $isAv ? $i['decision_date'] : ($i['report_date'] ?: $i['inspection_date']), $insp));
            $files = array_values(array_filter(array_column($insp, 'attachment_id')));
            $after = [
                'number' => mb_substr(implode(' + ', $numbers), 0, 80),
                'issued_at' => $dates ? max($dates) : null,
                'attachment_id' => $files[0] ?? $doc['attachment_id'],
                'status' => ($files || $doc['attachment_id']) ? 'submitted' : $doc['status'],
            ];
            if ($after['number'] === $doc['number'] && $after['issued_at'] === $doc['issued_at']
                && (int) $after['attachment_id'] === (int) $doc['attachment_id'] && $after['status'] === $doc['status']) {
                continue;
            }
            $pdo->prepare("UPDATE shipment_documents SET number = ?, issued_at = ?, attachment_id = ?, status = ?,
                    submitted_by = IF(? = 'submitted', COALESCE(submitted_by, ?), submitted_by), submitted_at = IF(? = 'submitted', COALESCE(submitted_at, NOW()), submitted_at)
                WHERE id = ?")
                ->execute([$after['number'], $after['issued_at'], $after['attachment_id'], $after['status'], $after['status'], $by, $after['status'], $doc['id']]);
            ActivityLogger::log('shipment_document', (int) $doc['id'], 'auto_inspection', array_intersect_key($doc, $after), $after + ['document' => $doc['code']]);
            $changed++;
        }
        return $changed;
    }

    private function trace(array $doc, string $action, array $after): void
    {
        ActivityLogger::log('shipment_document', (int) $doc['id'], $action, ['status' => $doc['status']], $after + ['document' => $doc['code']]);
    }

    private function label(array $doc): string
    {
        return $doc['code'] . ($doc['delivery_reference'] ? ' · ' . $doc['delivery_reference'] : ' · ' . t('documents.level.order'));
    }
}
