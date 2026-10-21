<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Clients et fournisseurs partagent la même structure (table choisie dans une liste blanche). */
final class PartyRepository
{
    public const FIELDS = ['code', 'name', 'rccm', 'id_nat', 'nif', 'address', 'city', 'country', 'contact_name', 'phone', 'email', 'payment_terms_days', 'currency', 'notes', 'active'];

    private string $table;

    /** @return list<string> Colonnes du type (les fournisseurs peuvent être marqués « transitaire ») */
    public function fields(): array
    {
        return $this->type === 'supplier' ? [...self::FIELDS, 'is_forwarder'] : self::FIELDS;
    }

    public function __construct(public readonly string $type)
    {
        $this->table = match ($type) {
            'customer' => 'customers',
            'supplier' => 'suppliers',
            default => throw new \InvalidArgumentException($type),
        };
    }

    /** @return list<array<string,mixed>> */
    public function all(bool $activeOnly = false): array
    {
        $fk = $this->type === 'customer' ? 'customer_id' : 'supplier_id';
        $orders = $this->type === 'customer'
            ? '(SELECT COUNT(*) FROM customer_orders o WHERE o.customer_id = p.id AND o.status NOT IN (\'closed\',\'cancelled\'))'
            : '(SELECT COUNT(*) FROM purchase_orders o WHERE o.supplier_id = p.id AND o.status IN (\'sent\',\'partial\'))';
        $sql = "SELECT p.*, $orders AS open_orders,
                (SELECT COALESCE(SUM(i.amount - COALESCE((SELECT SUM(py.amount) FROM payments py WHERE py.invoice_id = i.id), 0)), 0)
                   FROM invoices i WHERE i.$fk = p.id AND i.status = 'open') AS balance
            FROM {$this->table} p" . ($activeOnly ? ' WHERE p.active = 1' : '') . ' ORDER BY p.name';
        return Database::pdo()->query($sql)->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare("SELECT * FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        $stmt = Database::pdo()->prepare("SELECT 1 FROM {$this->table} WHERE code = ? AND id <> ?");
        $stmt->execute([$code, $exceptId ?? 0]);
        return (bool) $stmt->fetchColumn();
    }

    /** @param array<string,mixed> $data */
    public function save(array $data, ?int $id = null): int
    {
        $pdo = Database::pdo();
        $fields = $this->fields();
        $values = array_map(fn ($f) => $data[$f] ?? null, $fields);
        if ($id === null) {
            $pdo->prepare("INSERT INTO {$this->table} (" . implode(',', $fields) . ') VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')')
                ->execute($values);
            return (int) $pdo->lastInsertId();
        }
        $pdo->prepare("UPDATE {$this->table} SET " . implode(' = ?, ', $fields) . ' = ? WHERE id = ?')->execute([...$values, $id]);
        return $id;
    }

    /* ---------- Lieux de livraison (clients) ---------- */

    /** @return list<array<string,mixed>> */
    public function sites(int $customerId, bool $activeOnly = false): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM delivery_sites WHERE customer_id = ?' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY name');
        $stmt->execute([$customerId]);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function findSite(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM delivery_sites WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** @param array<string,mixed> $data */
    public function saveSite(array $data, ?int $id = null): int
    {
        $cols = ['customer_id', 'name', 'address', 'city', 'contact_name', 'phone', 'gps', 'instructions', 'active'];
        $values = array_map(fn ($c) => $data[$c] ?? null, $cols);
        $pdo = Database::pdo();
        if ($id === null) {
            $pdo->prepare('INSERT INTO delivery_sites (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute($values);
            return (int) $pdo->lastInsertId();
        }
        $pdo->prepare('UPDATE delivery_sites SET ' . implode(' = ?, ', $cols) . ' = ? WHERE id = ?')->execute([...$values, $id]);
        return $id;
    }

    /** Adresse de livraison complète, figée sur le PO fournisseur. */
    public static function formatSite(array $site): string
    {
        return implode("\n", array_filter([
            $site['name'],
            trim(($site['address'] ?? '') . ' ' . ($site['city'] ?? '')),
            $site['gps'] ? 'GPS : ' . $site['gps'] : null,
            $site['instructions'] ?? null,
        ]));
    }
}
