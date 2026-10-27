<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Services\Licence\LicenceImportService;

/**
 * Suppression définitive d'un client et de tout son dossier (réservée à l'administrateur) :
 * PO clients → devis, PO fournisseurs, licences, inspections, livraisons, documents d'expédition, factures et paiements,
 * pièces jointes (fichiers compris), chronologie ; puis lieux de livraison et fiche client.
 *
 * - impact() : inventaire de ce qui sera supprimé, affiché avant confirmation ;
 * - delete() : suppression dans une transaction, dans l'ordre des clés étrangères (sans les désactiver),
 *   avec une trace au journal d'activité contenant la fiche et l'inventaire ; les fichiers ne sont effacés qu'après validation.
 * Le stock n'est pas concerné (livraisons directes, aucun mouvement lié à un PO client).
 */
final class CustomerDeletionService
{
    /**
     * @return array{counts:array<string,int>,orders:list<array<string,mixed>>,purchase_orders:list<string>,invoices:list<string>,sites:list<string>}
     */
    public function impact(int $customerId): array
    {
        $ids = $this->collect($customerId);
        $orders = $ids['orders'] ? $this->rows(
            'SELECT reference, customer_po_number, po_date, status FROM customer_orders WHERE id IN (' . $this->in($ids['orders']) . ') ORDER BY id',
            $ids['orders']
        ) : [];
        return [
            'counts' => array_map('count', $ids) + [
                'order_lines' => $this->count('customer_order_lines', 'order_id', $ids['orders']),
                'order_events' => $this->count('order_events', 'order_id', $ids['orders']),
            ],
            'orders' => $orders,
            'purchase_orders' => $this->column('purchase_orders', 'reference', $ids['purchase_orders']),
            'invoices' => $this->column('invoices', 'reference', $ids['invoices']),
            'sites' => $this->column('delivery_sites', 'name', $ids['sites']),
        ];
    }

    /** @param array<string,mixed> $customer fiche avant suppression (pour le journal) */
    public function delete(array $customer): void
    {
        $customerId = (int) $customer['id'];
        $files = [];
        Database::transaction(function () use ($customer, $customerId, &$files) {
            $pdo = Database::pdo();
            $pdo->prepare('SELECT id FROM customers WHERE id = ? FOR UPDATE')->execute([$customerId]);
            $impact = $this->impact($customerId);
            $ids = $this->collect($customerId);

            $files = array_merge(
                array_map(fn ($n) => AttachmentService::dir() . '/' . $n, $this->column('attachments', 'stored_name', $ids['attachments'])),
                array_map(fn ($n) => LicenceImportService::tmpDir() . '/' . $n, $this->column('licence_imports', 'stored_name', $ids['licence_imports'])),
            );

            // Références croisées remises à NULL avant suppression (cycle licence ↔ brouillon d'import, pièces jointes, régularisations).
            $this->exec('UPDATE import_licences SET import_id = NULL, attachment_id = NULL WHERE id IN (%s)', $ids['licences']);
            $this->exec('UPDATE inspections SET attachment_id = NULL, regularizes_id = NULL WHERE id IN (%s)', $ids['inspections']);
            $this->exec('UPDATE inspections SET regularizes_id = NULL WHERE regularizes_id IN (%s)', $ids['inspections']);
            $this->exec('UPDATE purchase_orders SET delivery_site_id = NULL WHERE delivery_site_id IN (%s)', $ids['sites']);

            $this->exec('DELETE FROM shipment_documents WHERE order_id IN (%s)', $ids['orders']);
            $this->exec('DELETE FROM payments WHERE invoice_id IN (%s)', $ids['invoices']);
            $this->exec('DELETE FROM invoices WHERE id IN (%s)', $ids['invoices']);
            $this->exec('DELETE FROM deliveries WHERE id IN (%s)', $ids['deliveries']);          // lignes et AV en cascade
            $this->exec('DELETE FROM inspections WHERE id IN (%s)', $ids['inspections']);        // lignes en cascade
            $this->exec('DELETE FROM licence_imports WHERE id IN (%s)', $ids['licence_imports']);
            $this->exec('DELETE FROM import_licences WHERE id IN (%s)', $ids['licences']);       // lignes en cascade
            $this->exec('DELETE FROM purchase_orders WHERE id IN (%s)', $ids['purchase_orders']); // lignes en cascade
            $this->exec('DELETE FROM supplier_quotes WHERE id IN (%s)', $ids['quotes']);          // lignes en cascade
            $this->exec('DELETE FROM attachments WHERE id IN (%s)', $ids['attachments']);
            $this->exec('DELETE FROM order_events WHERE order_id IN (%s)', $ids['orders']);
            $this->exec('DELETE FROM customer_orders WHERE id IN (%s)', $ids['orders']);         // lignes en cascade
            $this->exec('DELETE FROM delivery_sites WHERE id IN (%s)', $ids['sites']);
            $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);

            ActivityLogger::log('customer', $customerId, 'delete_cascade', $customer + [
                'deleted' => array_filter($impact['counts']),
                'orders' => array_column($impact['orders'], 'reference'),
                'purchase_orders' => $impact['purchase_orders'],
                'invoices' => $impact['invoices'],
            ], null);
        });
        foreach ($files as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    /** Identifiants de tout ce qui dépend du client. @return array<string,list<int>> */
    private function collect(int $customerId): array
    {
        $orders = $this->ids('SELECT id FROM customer_orders WHERE customer_id = ?', [$customerId]);
        $pos = $this->idsIn('SELECT id FROM purchase_orders WHERE order_id IN (%s)', $orders);
        $licences = array_values(array_unique(array_merge(
            $this->idsIn('SELECT id FROM import_licences WHERE order_id IN (%s)', $orders),
            $this->idsIn('SELECT id FROM import_licences WHERE po_id IN (%s)', $pos),
        )));
        $invoices = array_values(array_unique(array_merge(
            $this->ids('SELECT id FROM invoices WHERE customer_id = ?', [$customerId]),
            $this->idsIn('SELECT id FROM invoices WHERE order_id IN (%s)', $orders),
            $this->idsIn('SELECT id FROM invoices WHERE po_id IN (%s)', $pos),
        )));
        return [
            'orders' => $orders,
            'quotes' => $this->idsIn('SELECT id FROM supplier_quotes WHERE order_id IN (%s)', $orders),
            'purchase_orders' => $pos,
            'licences' => $licences,
            'licence_imports' => $this->idsIn('SELECT id FROM licence_imports WHERE licence_id IN (%s)', $licences),
            'inspections' => $this->idsIn('SELECT id FROM inspections WHERE po_id IN (%s)', $pos),
            'deliveries' => $this->idsIn('SELECT id FROM deliveries WHERE order_id IN (%s)', $orders),
            'documents' => $this->idsIn('SELECT id FROM shipment_documents WHERE order_id IN (%s)', $orders),
            'invoices' => $invoices,
            'payments' => $this->idsIn('SELECT id FROM payments WHERE invoice_id IN (%s)', $invoices),
            'attachments' => $this->idsIn('SELECT id FROM attachments WHERE order_id IN (%s)', $orders),
            'sites' => $this->ids('SELECT id FROM delivery_sites WHERE customer_id = ?', [$customerId]),
        ];
    }

    private function in(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }

    /** @return list<int> */
    private function ids(string $sql, array $params): array
    {
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Requête « … IN (%s) » sur une liste d'identifiants (vide = aucun résultat). @return list<int> */
    private function idsIn(string $sql, array $ids): array
    {
        return $ids ? $this->ids(sprintf($sql, $this->in($ids)), $ids) : [];
    }

    private function exec(string $sql, array $ids): void
    {
        if ($ids) {
            Database::pdo()->prepare(sprintf($sql, $this->in($ids)))->execute($ids);
        }
    }

    private function count(string $table, string $column, array $ids): int
    {
        if (!$ids) {
            return 0;
        }
        $stmt = Database::pdo()->prepare("SELECT COUNT(*) FROM $table WHERE $column IN (" . $this->in($ids) . ')');
        $stmt->execute($ids);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<string> */
    private function column(string $table, string $column, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $stmt = Database::pdo()->prepare("SELECT $column FROM $table WHERE id IN (" . $this->in($ids) . ') ORDER BY id');
        $stmt->execute($ids);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql, array $params): array
    {
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
