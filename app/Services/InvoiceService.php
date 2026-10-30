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
            $lines = [];
            if ($data['direction'] === 'payable') {
                $po = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = ? AND order_id = ? AND status IN ('sent','partial','delivered')");
                $po->execute([$data['po_id'], $orderId]);
                $po = $po->fetch() ?: throw new DomainException('invoice.error.po_required');
                // La facture fournisseur est dans la devise du PO fournisseur, la facture client dans celle du PO client.
                [$currency, $customerId, $supplierId, $refType] = [$po['currency'], null, (int) $po['supplier_id'], 'invoice_payable'];
            } else {
                // Facturation client par expédition : une facture par expédition livrée (réceptionnée sur le site client)
                $dl = $pdo->prepare('SELECT * FROM deliveries WHERE id = ? AND order_id = ? FOR UPDATE');
                $dl->execute([$data['delivery_id'] ?? 0, $orderId]);
                $dl = $dl->fetch() ?: throw new DomainException('invoice.error.delivery_required');
                if ($dl['status'] !== 'delivered') {
                    throw new DomainException('invoice.error.delivery_not_delivered', ['ref' => $dl['reference']]);
                }
                $dup = $pdo->prepare("SELECT reference FROM invoices WHERE delivery_id = ? AND direction = 'receivable' AND status <> 'cancelled'");
                $dup->execute([$dl['id']]);
                if ($existing = $dup->fetchColumn()) {
                    throw new DomainException('invoice.error.delivery_invoiced', ['ref' => $dl['reference'], 'invoice' => $existing]);
                }
                // Documents bloquants « avant facturation » : ceux du dossier et ceux de cette expédition
                $missing = ChecklistService::deliveryBlockers((int) $dl['id'], $orderId, 'invoicing');
                if ($missing) {
                    throw new DomainException('documents.error.blocked', ['stage' => t('documents.stage.invoicing'), 'docs' => ChecklistService::names($missing)]);
                }
                [$currency, $customerId, $supplierId, $refType] = [$order['currency'], (int) $order['customer_id'], null, 'invoice_receivable'];
                $data['po_id'] = null;
                $data['delivery_id'] = (int) $dl['id'];
                $lines = self::receivableLines((int) $dl['id'], $data['lines'] ?? []);
                // TVA facultative : HT = somme des lignes, TTC = HT + TVA
                $totals = self::totals($lines, $data['vat_rate'] ?? null);
                $data = $totals + $data;
                $data['amount'] = $totals['amount'];
            }
            if ($data['amount'] <= 0) {
                throw new DomainException('invoice.error.amount');
            }
            $ref = ReferenceGenerator::resolve($refType, $data['reference'] ?? '', $data['reference_suggested'] ?? null);
            // Facture client : émise « en attente d'approbation », modifiable jusqu'à son approbation
            $status = $data['direction'] === 'receivable' ? 'draft' : 'open';
            $pdo->prepare('INSERT INTO invoices (reference, direction, order_id, po_id, delivery_id, customer_id, supplier_id, external_number, invoice_date,
                    due_date, currency, subtotal, vat_rate, vat_amount, amount, status, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$ref, $data['direction'], $orderId, $data['po_id'], $data['delivery_id'] ?? null, $customerId, $supplierId, $data['external_number'],
                    $data['invoice_date'], $data['due_date'], $currency, $data['subtotal'] ?? null, $data['vat_rate'] ?? null, $data['vat_amount'] ?? 0,
                    $data['amount'], $status, $data['notes'], Auth::require()->id]);
            $id = (int) $pdo->lastInsertId();
            self::saveLines($id, $lines);
            ActivityLogger::log('invoice', $id, 'create', null, $data + ['reference' => $ref, 'currency' => $currency, 'status' => $status]);
            OrderService::event($orderId, $data['direction'] === 'payable' ? 'supplier_invoice' : 'customer_invoice',
                $ref . ' · ' . fmt_money($data['amount'], $currency) . (isset($dl['reference']) ? ' · ' . $dl['reference'] : ''));
            return $id;
        });
    }

    /**
     * Modification d'une facture client en attente d'approbation : dates, lignes, TVA, observations.
     * @param array{invoice_date:string,due_date:string,lines:list<array>,vat_rate:?float,notes:?string} $data
     */
    public function update(int $invoiceId, array $data): void
    {
        Database::transaction(function () use ($invoiceId, $data) {
            $inv = $this->lock($invoiceId);
            OrderService::lockOpen((int) $inv['order_id']);
            if ($inv['direction'] !== 'receivable' || $inv['status'] !== 'draft') {
                throw new DomainException('invoice.error.not_draft');
            }
            $lines = self::receivableLines((int) $inv['delivery_id'], $data['lines']);
            $totals = self::totals($lines, $data['vat_rate']);
            if ($totals['amount'] <= 0) {
                throw new DomainException('invoice.error.amount');
            }
            Database::pdo()->prepare('UPDATE invoices SET invoice_date = ?, due_date = ?, subtotal = ?, vat_rate = ?, vat_amount = ?, amount = ?,
                    notes = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$data['invoice_date'], $data['due_date'], $totals['subtotal'], $totals['vat_rate'], $totals['vat_amount'], $totals['amount'],
                    $data['notes'], $invoiceId]);
            Database::pdo()->prepare('DELETE FROM invoice_lines WHERE invoice_id = ?')->execute([$invoiceId]);
            self::saveLines($invoiceId, $lines);
            ActivityLogger::log('invoice', $invoiceId, 'update',
                array_intersect_key($inv, array_flip(['invoice_date', 'due_date', 'subtotal', 'vat_rate', 'amount', 'notes'])),
                $totals + ['invoice_date' => $data['invoice_date'], 'due_date' => $data['due_date'], 'notes' => $data['notes'], 'lines' => count($lines)]);
            OrderService::event((int) $inv['order_id'], 'invoice_updated', $inv['reference'] . ' · ' . fmt_money($totals['amount'], $inv['currency']));
        });
    }

    /** Approbation : la facture devient « à régler » et peut recevoir des encaissements. */
    public function approve(int $invoiceId): void
    {
        Database::transaction(function () use ($invoiceId) {
            $inv = $this->lock($invoiceId);
            OrderService::lockOpen((int) $inv['order_id']);
            if ($inv['status'] !== 'draft') {
                throw new DomainException('invoice.error.not_draft');
            }
            // Contrôle à 2 niveaux : approbateur habilité (Paramètres → Utilisateurs) et différent de l'émetteur
            $user = Auth::require();
            if (!$user->can('finance.approve')) {
                throw new DomainException('invoice.error.not_approver');
            }
            if (!$user->canApproveInvoiceOf((int) $inv['created_by'])) {
                throw new DomainException('invoice.error.self_approval');
            }
            Database::pdo()->prepare("UPDATE invoices SET status = 'open', approved_by = ?, approved_at = NOW() WHERE id = ?")
                ->execute([$user->id, $invoiceId]);
            ActivityLogger::log('invoice', $invoiceId, 'approve', ['status' => 'draft'], ['status' => 'open']);
            OrderService::event((int) $inv['order_id'], 'invoice_approved', $inv['reference'] . ' · ' . fmt_money($inv['amount'], $inv['currency']));
        });
    }

    /** @param list<array<string,mixed>> $lines */
    private static function saveLines(int $invoiceId, array $lines): void
    {
        $ins = Database::pdo()->prepare('INSERT INTO invoice_lines (invoice_id, line_no, delivery_line_id, item_id, description, unit, quantity, unit_price, amount)
            VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($lines as $n => $l) {
            $ins->execute([$invoiceId, $n + 1, $l['delivery_line_id'], $l['item_id'], $l['description'], $l['unit'], $l['quantity'], $l['unit_price'], $l['amount']]);
        }
    }

    /**
     * Lignes d'une facture client d'expédition. Une ligne reprise de l'expédition ne peut dépasser la quantité réceptionnée ;
     * une ligne libre (delivery_line_id vide) porte un libellé (frais, remise négative non admise).
     * @param list<array{delivery_line_id:?int,description:string,quantity:float,unit_price:float}> $input
     * @return list<array<string,mixed>>
     */
    public static function receivableLines(int $deliveryId, array $input): array
    {
        $stmt = Database::pdo()->prepare('SELECT dl.id, dl.quantity_received, pl.item_id, u.code AS unit FROM delivery_lines dl
            JOIN purchase_order_lines pl ON pl.id = dl.po_line_id JOIN items i ON i.id = pl.item_id JOIN units u ON u.id = i.unit_id
            WHERE dl.delivery_id = ?');
        $stmt->execute([$deliveryId]);
        $known = [];
        foreach ($stmt->fetchAll() as $r) {
            $known[(int) $r['id']] = $r;
        }
        $lines = [];
        $used = [];
        foreach ($input as $l) {
            $dlId = $l['delivery_line_id'];
            $src = null;
            if ($dlId !== null) {
                $src = $known[$dlId] ?? throw new DomainException('invoice.error.line_unknown');
                $used[$dlId] = ($used[$dlId] ?? 0) + $l['quantity'];
                if ($used[$dlId] > (float) $src['quantity_received'] + 1e-6) {
                    throw new DomainException('invoice.error.line_over', ['line' => $l['description'], 'qty' => fmt_qty($src['quantity_received'])]);
                }
            }
            if ($l['description'] === '' || $l['quantity'] <= 0 || $l['unit_price'] < 0) {
                throw new DomainException('invoice.error.line_invalid');
            }
            $lines[] = [
                'delivery_line_id' => $dlId, 'item_id' => $src ? (int) $src['item_id'] : null, 'unit' => $src['unit'] ?? null,
                'description' => mb_substr($l['description'], 0, 255), 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'],
                'amount' => round($l['quantity'] * $l['unit_price'], 2),
            ];
        }
        if (!$lines) {
            throw new DomainException('invoice.error.no_lines');
        }
        return $lines;
    }

    /**
     * Totaux d'une facture client : HT (somme des lignes), TVA facultative (taux null = sans TVA), TTC.
     * @param list<array{amount:float}> $lines
     * @return array{subtotal:float,vat_rate:?float,vat_amount:float,amount:float}
     */
    public static function totals(array $lines, ?float $vatRate): array
    {
        if ($vatRate !== null && ($vatRate <= 0 || $vatRate > 100)) {
            throw new DomainException('invoice.error.vat_rate');
        }
        $subtotal = round(array_sum(array_column($lines, 'amount')), 2);
        $vat = $vatRate === null ? 0.0 : round($subtotal * $vatRate / 100, 2);
        return ['subtotal' => $subtotal, 'vat_rate' => $vatRate, 'vat_amount' => $vat, 'amount' => round($subtotal + $vat, 2)];
    }

    /** Valeur facturable d'une expédition : quantités réceptionnées × prix de vente du PO client. */
    public static function deliveryValue(int $deliveryId): float
    {
        $stmt = Database::pdo()->prepare('SELECT COALESCE(SUM(COALESCE(dl.quantity_received, 0) * col.unit_price), 0) FROM delivery_lines dl
            JOIN purchase_order_lines pl ON pl.id = dl.po_line_id JOIN customer_order_lines col ON col.id = pl.order_line_id
            WHERE dl.delivery_id = ?');
        $stmt->execute([$deliveryId]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /** @param array<string,mixed> $data paid_at, amount, method, bank_ref, notes */
    public function pay(int $invoiceId, array $data): void
    {
        Database::transaction(function () use ($invoiceId, $data) {
            $inv = $this->lock($invoiceId);
            OrderService::lockOpen((int) $inv['order_id']);
            if ($inv['status'] === 'draft') {
                throw new DomainException('invoice.error.not_approved');
            }
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
            if (!in_array($inv['status'], ['draft', 'open'], true) || (int) $paid->fetchColumn() > 0) {
                throw new DomainException('invoice.error.cannot_cancel');
            }
            Database::pdo()->prepare("UPDATE invoices SET status = 'cancelled', notes = ? WHERE id = ?")
                ->execute([trim(($inv['notes'] ?? '') . ' [' . t('common.cancelled') . '] ' . $note), $invoiceId]);
            ActivityLogger::log('invoice', $invoiceId, 'cancel', ['status' => $inv['status']], ['status' => 'cancelled', 'note' => $note]);
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
