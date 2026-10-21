<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\DomainException;
use App\Repositories\OrderRepository;
use App\Security\Auth;

/** Sourcing (devis fournisseurs) et PO fournisseurs rattachés à un PO client. */
final class PurchasingService
{
    /**
     * @param array<int,float> $prices order_line_id → prix unitaire
     * @param array<int,float> $quantities order_line_id → quantité proposée (absente = quantité commandée)
     */
    public function addQuote(int $orderId, array $data, array $prices, array $quantities = []): int
    {
        return Database::transaction(function () use ($orderId, $data, $prices, $quantities) {
            OrderService::lockOpen($orderId);
            $lineIds = array_map(fn ($l) => (int) $l['id'], (new OrderRepository())->lines($orderId));
            $prices = array_intersect_key($prices, array_flip($lineIds));
            if ($prices === []) {
                throw new DomainException('sourcing.error.no_price');
            }
            $pdo = Database::pdo();
            $pdo->prepare('INSERT INTO supplier_quotes (order_id, supplier_id, quote_ref, quote_date, currency, lead_time_days, valid_until, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$orderId, $data['supplier_id'], $data['quote_ref'], $data['quote_date'], $data['currency'],
                    $data['lead_time_days'], $data['valid_until'], $data['notes'], Auth::require()->id]);
            $id = (int) $pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO supplier_quote_lines (quote_id, order_line_id, quantity, unit_price) VALUES (?,?,?,?)');
            foreach ($prices as $lineId => $price) {
                $ins->execute([$id, $lineId, $quantities[$lineId] ?? null, $price]);
            }
            ActivityLogger::log('supplier_quote', $id, 'create', null, $data);
            OrderService::event($orderId, 'quote_added', $data['supplier_name'] ?? null);
            OrderService::refresh($orderId);
            return $id;
        });
    }

    public function rejectQuote(int $orderId, int $quoteId): void
    {
        Database::transaction(function () use ($orderId, $quoteId) {
            OrderService::lockOpen($orderId);
            $stmt = Database::pdo()->prepare("UPDATE supplier_quotes SET status = 'rejected' WHERE id = ? AND order_id = ? AND status = 'pending'");
            $stmt->execute([$quoteId, $orderId]);
            if ($stmt->rowCount() === 1) {
                ActivityLogger::log('supplier_quote', $quoteId, 'reject');
                OrderService::event($orderId, 'quote_rejected');
            }
        });
    }

    /**
     * Crée un PO fournisseur (brouillon). Une ligne ne peut pas dépasser le reste à commander du PO client.
     * @param list<array{order_line_id:int,quantity:float,unit_price:float}> $lines
     */
    public function createPo(int $orderId, array $data, array $lines): int
    {
        return Database::transaction(function () use ($orderId, $data, $lines) {
            OrderService::lockOpen($orderId);
            $lines = $this->checkLines($orderId, $lines, null);
            $pdo = Database::pdo();
            $ref = ReferenceGenerator::resolve('po', $data['reference'] ?? '', $data['reference_suggested'] ?? null);
            $pdo->prepare('INSERT INTO purchase_orders (reference, order_id, supplier_id, quote_id, currency, order_date, expected_date,
                    delivery_site_id, delivery_address, delivery_contact, transport_mode, forwarder_id, payment_terms_days, notes, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$ref, $orderId, $data['supplier_id'], $data['quote_id'], $data['currency'], $data['order_date'], $data['expected_date'],
                    $data['delivery_site_id'], $data['delivery_address'], $data['delivery_contact'], $data['transport_mode'] ?? 'road',
                    $data['forwarder_id'] ?? null, $data['payment_terms_days'], $data['notes'], Auth::require()->id]);
            $id = (int) $pdo->lastInsertId();
            $this->writeLines($id, $lines);
            if ($data['quote_id']) {
                $pdo->prepare("UPDATE supplier_quotes SET status = 'selected' WHERE id = ? AND order_id = ?")->execute([$data['quote_id'], $orderId]);
            }
            ActivityLogger::log('purchase_order', $id, 'create', null, $data + ['reference' => $ref]);
            OrderService::event($orderId, 'po_created', $ref);
            return $id;
        });
    }

    /** Modification d'un brouillon uniquement. */
    public function updatePo(int $poId, array $data, array $lines): void
    {
        Database::transaction(function () use ($poId, $data, $lines) {
            $po = $this->lockPo($poId);
            OrderService::lockOpen((int) $po['order_id']);
            if ($po['status'] !== 'draft') {
                throw new DomainException('po.error.not_draft');
            }
            $lines = $this->checkLines((int) $po['order_id'], $lines, $poId);
            $pdo = Database::pdo();
            $ref = ReferenceGenerator::resolve('po', ($data['reference'] ?? '') !== '' ? $data['reference'] : $po['reference'], $data['reference_suggested'] ?? null, [], $poId);
            $pdo->prepare('UPDATE purchase_orders SET reference = ?, supplier_id = ?, currency = ?, order_date = ?, expected_date = ?, delivery_site_id = ?,
                    delivery_address = ?, delivery_contact = ?, transport_mode = ?, forwarder_id = ?, payment_terms_days = ?, notes = ? WHERE id = ?')
                ->execute([$ref, $data['supplier_id'], $data['currency'], $data['order_date'], $data['expected_date'], $data['delivery_site_id'],
                    $data['delivery_address'], $data['delivery_contact'], $data['transport_mode'] ?? 'road', $data['forwarder_id'] ?? null,
                    $data['payment_terms_days'], $data['notes'], $poId]);
            $pdo->prepare('DELETE FROM purchase_order_lines WHERE po_id = ?')->execute([$poId]);
            $this->writeLines($poId, $lines);
            ActivityLogger::log('purchase_order', $poId, 'update', array_intersect_key($po, $data), $data);
            OrderService::event((int) $po['order_id'], 'po_updated', $ref);
        });
    }

    /** Émission : le PO devient opposable et ne se modifie plus. */
    public function sendPo(int $poId): void
    {
        Database::transaction(function () use ($poId) {
            $po = $this->lockPo($poId);
            OrderService::lockOpen((int) $po['order_id']);
            if ($po['status'] !== 'draft') {
                throw new DomainException('po.error.not_draft');
            }
            Database::pdo()->prepare("UPDATE purchase_orders SET status = 'sent', sent_at = NOW() WHERE id = ?")->execute([$poId]);
            ActivityLogger::log('purchase_order', $poId, 'send', ['status' => 'draft'], ['status' => 'sent']);
            OrderService::event((int) $po['order_id'], 'po_sent', $po['reference']);
            ChecklistService::generateForOrder((int) $po['order_id']);
            OrderService::refresh((int) $po['order_id']);
        });
    }

    public function cancelPo(int $poId, string $note): void
    {
        Database::transaction(function () use ($poId, $note) {
            $po = $this->lockPo($poId);
            OrderService::lockOpen((int) $po['order_id']);
            $busy = Database::pdo()->prepare("SELECT (SELECT COUNT(*) FROM deliveries WHERE po_id = ? AND status <> 'cancelled')
                + (SELECT COUNT(*) FROM invoices WHERE po_id = ? AND status <> 'cancelled')");
            $busy->execute([$poId, $poId]);
            if ($po['status'] === 'cancelled' || (int) $busy->fetchColumn() > 0) {
                throw new DomainException('po.error.cannot_cancel');
            }
            Database::pdo()->prepare("UPDATE purchase_orders SET status = 'cancelled', notes = CONCAT(COALESCE(notes, ''), ?) WHERE id = ?")
                ->execute(["\n[" . t('common.cancelled') . '] ' . $note, $poId]);
            if ($po['quote_id']) {
                Database::pdo()->prepare("UPDATE supplier_quotes SET status = 'pending' WHERE id = ?")->execute([$po['quote_id']]);
            }
            ActivityLogger::log('purchase_order', $poId, 'cancel', ['status' => $po['status']], ['status' => 'cancelled', 'note' => $note]);
            OrderService::event((int) $po['order_id'], 'po_cancelled', $po['reference'] . ' — ' . $note);
            OrderService::refresh((int) $po['order_id']);
        });
    }

    /** @return array<string,mixed> */
    private function lockPo(int $poId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM purchase_orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$poId]);
        $po = $stmt->fetch();
        if (!$po) {
            throw new DomainException('po.error.not_found');
        }
        return $po;
    }

    /**
     * Garde-fou : quantité ≤ reste à commander (le PO édité est exclu du calcul).
     * @return list<array{order_line_id:int,item_id:int,quantity:float,unit_price:float}>
     */
    private function checkLines(int $orderId, array $lines, ?int $exceptPoId): array
    {
        $remaining = [];
        $stmt = Database::pdo()->prepare("SELECT l.id, l.item_id, l.quantity - COALESCE((SELECT SUM(pl.quantity) FROM purchase_order_lines pl
                JOIN purchase_orders p ON p.id = pl.po_id WHERE pl.order_line_id = l.id AND p.status <> 'cancelled' AND p.id <> ?), 0) AS remaining
            FROM customer_order_lines l WHERE l.order_id = ?");
        $stmt->execute([$exceptPoId ?? 0, $orderId]);
        foreach ($stmt->fetchAll() as $r) {
            $remaining[(int) $r['id']] = ['item_id' => (int) $r['item_id'], 'remaining' => (float) $r['remaining']];
        }
        $out = [];
        foreach ($lines as $l) {
            if ($l['quantity'] <= 0) {
                continue;
            }
            $ref = $remaining[$l['order_line_id']] ?? null;
            if ($ref === null) {
                throw new DomainException('po.error.bad_line');
            }
            if ($l['quantity'] > $ref['remaining'] + 1e-9) {
                throw new DomainException('po.error.over_order', ['remaining' => fmt_qty($ref['remaining'])]);
            }
            $out[] = $l + ['item_id' => $ref['item_id']];
        }
        if ($out === []) {
            throw new DomainException('po.error.no_lines');
        }
        return $out;
    }

    private function writeLines(int $poId, array $lines): void
    {
        $ins = Database::pdo()->prepare('INSERT INTO purchase_order_lines (po_id, order_line_id, item_id, quantity, unit_price) VALUES (?,?,?,?,?)');
        foreach ($lines as $l) {
            $ins->execute([$poId, $l['order_line_id'], $l['item_id'], $l['quantity'], $l['unit_price']]);
        }
    }
}
