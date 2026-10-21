<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\DomainException;
use App\Domain\OrderProgress;
use App\Security\Auth;

/** Factures clients (à encaisser) et fournisseurs (à payer), et leurs règlements partiels. */
final class InvoiceService
{
    /** @param array<string,mixed> $data direction, po_id?, external_number, invoice_date, due_date, amount, notes */
    public function create(int $orderId, array $data): int
    {
        return Database::transaction(function () use ($orderId, $data) {
            $order = OrderService::lockOpen($orderId);
            $pdo = Database::pdo();
            if ($data['direction'] === 'payable') {
                $po = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = ? AND order_id = ? AND status IN ('sent','partial','delivered')");
                $po->execute([$data['po_id'], $orderId]);
                $po = $po->fetch() ?: throw new DomainException('invoice.error.po_required');
                // La facture fournisseur est dans la devise du PO fournisseur, la facture client dans celle du PO client.
                [$currency, $customerId, $supplierId, $refType] = [$po['currency'], null, (int) $po['supplier_id'], 'invoice_payable'];
            } else {
                // Facturation client : documents bloquants « avant facturation » des expéditions réceptionnées
                $missing = ChecklistService::orderBlockers($orderId, 'invoicing');
                if ($missing) {
                    throw new DomainException('documents.error.blocked', ['stage' => t('documents.stage.invoicing'), 'docs' => ChecklistService::names($missing)]);
                }
                [$currency, $customerId, $supplierId, $refType] = [$order['currency'], (int) $order['customer_id'], null, 'invoice_receivable'];
                $data['po_id'] = null;
            }
            if ($data['amount'] <= 0) {
                throw new DomainException('invoice.error.amount');
            }
            $ref = ReferenceGenerator::resolve($refType, $data['reference'] ?? '', $data['reference_suggested'] ?? null);
            $pdo->prepare('INSERT INTO invoices (reference, direction, order_id, po_id, customer_id, supplier_id, external_number, invoice_date,
                    due_date, currency, amount, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$ref, $data['direction'], $orderId, $data['po_id'], $customerId, $supplierId, $data['external_number'],
                    $data['invoice_date'], $data['due_date'], $currency, $data['amount'], $data['notes'], Auth::require()->id]);
            $id = (int) $pdo->lastInsertId();
            ActivityLogger::log('invoice', $id, 'create', null, $data + ['reference' => $ref, 'currency' => $currency]);
            OrderService::event($orderId, $data['direction'] === 'payable' ? 'supplier_invoice' : 'customer_invoice',
                $ref . ' · ' . fmt_money($data['amount'], $currency));
            return $id;
        });
    }

    /** @param array<string,mixed> $data paid_at, amount, method, bank_ref, notes */
    public function pay(int $invoiceId, array $data): void
    {
        Database::transaction(function () use ($invoiceId, $data) {
            $inv = $this->lock($invoiceId);
            OrderService::lockOpen((int) $inv['order_id']);
            if ($inv['status'] !== 'open') {
                throw new DomainException('invoice.error.not_open');
            }
            $pdo = Database::pdo();
            $paid = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = ?');
            $paid->execute([$invoiceId]);
            $already = (float) $paid->fetchColumn();
            $balance = round((float) $inv['amount'] - $already, 2);
            if ($data['amount'] <= 0 || $data['amount'] > $balance + 0.005) {
                throw new DomainException('invoice.error.over_pay', ['balance' => fmt_money($balance, $inv['currency'])]);
            }
            $pdo->prepare('INSERT INTO payments (invoice_id, paid_at, amount, method, bank_ref, notes, created_by) VALUES (?,?,?,?,?,?,?)')
                ->execute([$invoiceId, $data['paid_at'], $data['amount'], $data['method'], $data['bank_ref'], $data['notes'], Auth::require()->id]);
            $status = OrderProgress::invoiceStatus('open', (float) $inv['amount'], $already + $data['amount']);
            if ($status !== 'open') {
                $pdo->prepare('UPDATE invoices SET status = ? WHERE id = ?')->execute([$status, $invoiceId]);
            }
            ActivityLogger::log('payment', (int) $pdo->lastInsertId(), 'create', null, $data + ['invoice' => $inv['reference']]);
            OrderService::event((int) $inv['order_id'], $inv['direction'] === 'payable' ? 'supplier_paid' : 'customer_paid',
                $inv['reference'] . ' · ' . fmt_money($data['amount'], $inv['currency']) . ($status === 'paid' ? ' · ' . t('invoice.status.paid') : ''));
        });
    }

    public function cancel(int $invoiceId, string $note): void
    {
        Database::transaction(function () use ($invoiceId, $note) {
            $inv = $this->lock($invoiceId);
            OrderService::lockOpen((int) $inv['order_id']);
            $paid = Database::pdo()->prepare('SELECT COUNT(*) FROM payments WHERE invoice_id = ?');
            $paid->execute([$invoiceId]);
            if ($inv['status'] !== 'open' || (int) $paid->fetchColumn() > 0) {
                throw new DomainException('invoice.error.cannot_cancel');
            }
            Database::pdo()->prepare("UPDATE invoices SET status = 'cancelled', notes = ? WHERE id = ?")
                ->execute([trim(($inv['notes'] ?? '') . ' [' . t('common.cancelled') . '] ' . $note), $invoiceId]);
            ActivityLogger::log('invoice', $invoiceId, 'cancel', ['status' => 'open'], ['status' => 'cancelled', 'note' => $note]);
            OrderService::event((int) $inv['order_id'], 'invoice_cancelled', $inv['reference'] . ' — ' . $note);
        });
    }

    /** @return array<string,mixed> */
    private function lock(int $id): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM invoices WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: throw new DomainException('invoice.error.not_found');
    }
}
