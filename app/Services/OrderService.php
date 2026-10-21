<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\ClosureCheck;
use App\Domain\DomainException;
use App\Domain\OrderProgress;
use App\Repositories\OrderRepository;
use App\Security\Auth;

/** Cycle de vie du PO client. Les autres services appellent refresh() après chaque changement. */
final class OrderService
{
    /**
     * @param array<string,mixed> $header
     * @param list<array{item_id:int,specification:?string,quantity:float,unit_price:float}> $lines
     */
    public function create(array $header, array $lines): int
    {
        return Database::transaction(function () use ($header, $lines) {
            $pdo = Database::pdo();
            $ref = ReferenceGenerator::resolve('order', $header['reference'] ?? '', $header['reference_suggested'] ?? null);
            $pdo->prepare('INSERT INTO customer_orders (reference, customer_id, customer_po_number, po_date, site_id, fulfillment_mode, currency,
                    delivery_site_id, required_date, payment_terms_days, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$ref, $header['customer_id'], $header['customer_po_number'], $header['po_date'], $header['site_id'],
                    $header['fulfillment_mode'], $header['currency'], $header['delivery_site_id'], $header['required_date'],
                    $header['payment_terms_days'], $header['notes'], Auth::require()->id]);
            $id = (int) $pdo->lastInsertId();
            $this->writeLines($id, $lines);
            ActivityLogger::log('customer_order', $id, 'create', null, $header + ['reference' => $ref, 'lines' => count($lines)]);
            self::event($id, 'received', $header['customer_po_number']);
            return $id;
        });
    }

    /** Modification possible tant qu'aucun PO fournisseur n'est actif (lignes encore libres). */
    public function update(int $id, array $header, array $lines): void
    {
        Database::transaction(function () use ($id, $header, $lines) {
            $order = $this->lock($id);
            if (!in_array($order['status'], ['received', 'sourcing'], true) || $this->hasActivePo($id)) {
                throw new DomainException('orders.error.locked');
            }
            $pdo = Database::pdo();
            $ref = ReferenceGenerator::resolve('order', ($header['reference'] ?? '') !== '' ? $header['reference'] : $order['reference'], $header['reference_suggested'] ?? null, [], $id);
            $pdo->prepare('UPDATE customer_orders SET reference = ?, customer_id = ?, customer_po_number = ?, po_date = ?, site_id = ?, fulfillment_mode = ?,
                    currency = ?, delivery_site_id = ?, required_date = ?, payment_terms_days = ?, notes = ? WHERE id = ?')
                ->execute([$ref, $header['customer_id'], $header['customer_po_number'], $header['po_date'], $header['site_id'], $header['fulfillment_mode'],
                    $header['currency'], $header['delivery_site_id'], $header['required_date'], $header['payment_terms_days'], $header['notes'], $id]);
            // Les lignes sont réécrites : les prix de devis rattachés suivent (cascade) — un devis doit être ressaisi si les lignes changent.
            $pdo->prepare('DELETE FROM customer_order_lines WHERE order_id = ?')->execute([$id]);
            $this->writeLines($id, $lines);
            ActivityLogger::log('customer_order', $id, 'update', array_intersect_key($order, $header), $header);
            self::event($id, 'updated');
        });
    }

    public function cancel(int $id, string $note): void
    {
        Database::transaction(function () use ($id, $note) {
            $order = $this->lock($id);
            if (in_array($order['status'], OrderProgress::CLOSED_STATES, true)) {
                throw new DomainException('orders.error.already_closed');
            }
            $pdo = Database::pdo();
            $busy = $pdo->prepare("SELECT (SELECT COUNT(*) FROM deliveries WHERE order_id = ? AND status <> 'cancelled')
                + (SELECT COUNT(*) FROM invoices WHERE order_id = ? AND status <> 'cancelled')");
            $busy->execute([$id, $id]);
            if ((int) $busy->fetchColumn() > 0) {
                throw new DomainException('orders.error.cancel_has_activity');
            }
            $pdo->prepare("UPDATE purchase_orders SET status = 'cancelled' WHERE order_id = ? AND status <> 'cancelled'")->execute([$id]);
            $pdo->prepare("UPDATE customer_orders SET status = 'cancelled', closing_note = ?, closed_at = NOW(), closed_by = ? WHERE id = ?")
                ->execute([$note, Auth::require()->id, $id]);
            ActivityLogger::log('customer_order', $id, 'cancel', ['status' => $order['status']], ['status' => 'cancelled', 'note' => $note]);
            self::event($id, 'cancelled', $note);
        });
    }

    public function close(int $id, string $note): void
    {
        Database::transaction(function () use ($id, $note) {
            $order = $this->lock($id);
            if (in_array($order['status'], OrderProgress::CLOSED_STATES, true)) {
                throw new DomainException('orders.error.already_closed');
            }
            $repo = new OrderRepository();
            $facts = OrderRepository::closureFacts($repo->lines($id), $repo->purchaseOrders($id), $repo->deliveries($id), $repo->invoices($id));
            $facts['documents_missing'] = count(ChecklistService::orderBlockers($id, 'closure'));
            $checks = ClosureCheck::evaluate($facts);
            if (!ClosureCheck::canClose($checks, $note)) {
                throw new DomainException('orders.error.cannot_close');
            }
            Database::pdo()->prepare("UPDATE customer_orders SET status = 'closed', closing_note = ?, closed_at = NOW(), closed_by = ? WHERE id = ?")
                ->execute([$note ?: null, Auth::require()->id, $id]);
            ActivityLogger::log('customer_order', $id, 'close', ['status' => $order['status']], ['status' => 'closed', 'note' => $note]);
            self::event($id, 'closed', $note);
        });
    }

    /** Recalcule les statuts des PO fournisseurs puis du PO client (à appeler dans la transaction appelante). */
    public static function refresh(int $orderId): void
    {
        $pdo = Database::pdo();
        $poRepo = new \App\Repositories\PurchaseOrderRepository();
        $pos = $pdo->prepare('SELECT id, status FROM purchase_orders WHERE order_id = ?');
        $pos->execute([$orderId]);
        foreach ($pos->fetchAll() as $po) {
            $lines = array_map(fn ($l) => ['quantity' => (float) $l['quantity'], 'shipped' => (float) $l['shipped']], $poRepo->lines((int) $po['id']));
            $status = OrderProgress::poStatus($po['status'], $lines);
            if ($status !== $po['status']) {
                $pdo->prepare('UPDATE purchase_orders SET status = ? WHERE id = ?')->execute([$status, $po['id']]);
            }
        }

        $order = $pdo->prepare('SELECT status, fulfillment_mode FROM customer_orders WHERE id = ?');
        $order->execute([$orderId]);
        $orderRow = $order->fetch();
        $current = (string) $orderRow['status'];
        $facts = $pdo->prepare("SELECT
                (SELECT COUNT(*) FROM supplier_quotes WHERE order_id = ?) AS quotes,
                (SELECT COUNT(*) FROM purchase_orders WHERE order_id = ? AND status IN ('sent','partial','delivered')) AS pos,
                (SELECT COUNT(*) FROM deliveries WHERE order_id = ? AND status <> 'cancelled') AS deliveries");
        $facts->execute([$orderId, $orderId, $orderId]);
        $f = $facts->fetch();
        $lines = (new OrderRepository())->lines($orderId);
        $allDelivered = $lines !== [] && array_reduce($lines, fn ($ok, $l) => $ok && (float) $l['delivered'] + 1e-9 >= (float) $l['quantity'], true);

        // Les étapes d'import ne sont pas des états terminaux : on repart du statut « métier » puis on applique l'étape d'import.
        $base = in_array($current, ['awaiting_licence', 'inspection', 'blocked'], true) ? 'ordered' : $current;
        $status = OrderProgress::orderStatus($base, (int) $f['quotes'] > 0, (int) $f['pos'] > 0, (int) $f['deliveries'] > 0, $allDelivered);
        if ($orderRow['fulfillment_mode'] === 'import' && !in_array($status, ['delivered', 'closed', 'cancelled'], true)) {
            $stage = self::importStage($orderId);
            // ARA : bloque à tout moment ; licence / inspection : tant qu'aucune livraison n'a commencé.
            if ($stage === 'blocked' || ($stage !== null && (int) $f['deliveries'] === 0)) {
                $status = $stage;
            }
        }
        if ($status !== $current) {
            $pdo->prepare('UPDATE customer_orders SET status = ? WHERE id = ?')->execute([$status, $orderId]);
            self::event($orderId, 'status', $status);
        }
    }

    /** Étape d'import du dossier, d'après ses PO fournisseurs non annulés (licence, quantités AV, ARA ouverts). */
    private static function importStage(int $orderId): ?string
    {
        $stmt = Database::pdo()->prepare("SELECT id FROM purchase_orders WHERE order_id = ? AND status <> 'cancelled'");
        $stmt->execute([$orderId]);
        $repo = new \App\Repositories\ImportRepository();
        $today = date('Y-m-d');
        $pos = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $poId) {
            $lic = $repo->licenceForPo((int) $poId);
            $pos[] = [
                'licence' => \App\Domain\ImportGate::licenceValid($lic, $today),
                'cleared' => array_sum($repo->cleared((int) $poId)),
                'open_ara' => $repo->openAra((int) $poId),
            ];
        }
        return \App\Domain\ImportGate::orderStage($pos);
    }

    public static function event(int $orderId, string $event, ?string $detail = null): void
    {
        Database::pdo()->prepare('INSERT INTO order_events (order_id, event, detail, user_id) VALUES (?,?,?,?)')
            ->execute([$orderId, $event, $detail !== null ? mb_substr($detail, 0, 500) : null, Auth::user()?->id]);
    }

    /** Verrouille le PO client et refuse toute écriture sur un dossier clos. @return array<string,mixed> */
    public static function lockOpen(int $orderId): array
    {
        $order = (new self())->lock($orderId);
        if (in_array($order['status'], OrderProgress::CLOSED_STATES, true)) {
            throw new DomainException('orders.error.already_closed');
        }
        return $order;
    }

    /** @return array<string,mixed> */
    private function lock(int $id): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM customer_orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $order = $stmt->fetch();
        if (!$order) {
            throw new DomainException('orders.error.not_found');
        }
        return $order;
    }

    private function hasActivePo(int $orderId): bool
    {
        // Même annulé, un PO référence les lignes (clé étrangère) : elles ne peuvent plus être réécrites.
        $stmt = Database::pdo()->prepare('SELECT 1 FROM purchase_orders WHERE order_id = ? LIMIT 1');
        $stmt->execute([$orderId]);
        return (bool) $stmt->fetchColumn();
    }

    private function writeLines(int $orderId, array $lines): void
    {
        if ($lines === []) {
            throw new DomainException('orders.error.no_lines');
        }
        $ins = Database::pdo()->prepare('INSERT INTO customer_order_lines (order_id, line_no, item_id, specification, quantity, unit_price) VALUES (?,?,?,?,?,?)');
        foreach (array_values($lines) as $i => $l) {
            $ins->execute([$orderId, $i + 1, $l['item_id'], $l['specification'], $l['quantity'], $l['unit_price']]);
        }
    }
}
