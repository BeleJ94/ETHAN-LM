<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Security\SiteScope;

/** Référentiel des agences en douane / transitaires. */
final class CustomsAgencyRepository
{
    private const COLS = ['code', 'name', 'approval_number', 'nif', 'rccm', 'address', 'city', 'country', 'contact_name', 'phone', 'email', 'customs_posts', 'notes', 'active'];

    /** Expéditions confiées à l'agence (désignée sur l'expédition, ou par défaut sur son PO). */
    private const ASSIGNED = 'COALESCE(d.forwarder_id, p.forwarder_id) = a.id';

    /** @return list<array<string,mixed>> Agences avec volumes : expéditions (toutes / en cours / sans n° de dossier) et comptes portail */
    public function all(bool $activeOnly = false): array
    {
        return Database::pdo()->query("SELECT a.*,
                (SELECT COUNT(*) FROM deliveries d JOIN purchase_orders p ON p.id = d.po_id WHERE " . self::ASSIGNED . " AND d.status <> 'cancelled') AS delivery_count,
                (SELECT COUNT(*) FROM deliveries d JOIN purchase_orders p ON p.id = d.po_id WHERE " . self::ASSIGNED . " AND d.status IN ('planned','in_transit')) AS open_count,
                (SELECT COUNT(*) FROM deliveries d JOIN purchase_orders p ON p.id = d.po_id WHERE " . self::ASSIGNED . " AND d.status IN ('planned','in_transit') AND d.forwarder_ref IS NULL) AS missing_ref,
                (SELECT COUNT(*) FROM users u WHERE u.forwarder_id = a.id AND u.active = 1) AS user_count
            FROM customs_agencies a" . ($activeOnly ? ' WHERE a.active = 1' : '') . ' ORDER BY a.name')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        foreach ($this->all() as $a) {
            if ((int) $a['id'] === $id) {
                return $a;
            }
        }
        return null;
    }

    public function exists(string $column, string $value, ?int $exceptId = null): bool
    {
        if (!in_array($column, ['code', 'name'], true)) {
            throw new \InvalidArgumentException($column);
        }
        $stmt = Database::pdo()->prepare("SELECT 1 FROM customs_agencies WHERE $column = ? AND id <> ?");
        $stmt->execute([$value, $exceptId ?? 0]);
        return (bool) $stmt->fetchColumn();
    }

    public function isActive(int $id): bool
    {
        $stmt = Database::pdo()->prepare('SELECT 1 FROM customs_agencies WHERE id = ? AND active = 1');
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
    }

    /** @return list<array<string,mixed>> Expéditions de l'agence (périmètre de sites de l'utilisateur) */
    public function deliveries(int $agencyId): array
    {
        [$scope, $params] = SiteScope::clause('o.site_id');
        $stmt = Database::pdo()->prepare("SELECT d.id, d.reference, d.status, d.shipped_at, d.delivered_at, d.forwarder_ref, d.vehicle_plate,
                p.reference AS po_reference, o.customer_po_number, o.fulfillment_mode, c.name AS customer_name
            FROM deliveries d JOIN purchase_orders p ON p.id = d.po_id JOIN customer_orders o ON o.id = d.order_id JOIN customers c ON c.id = o.customer_id
            WHERE COALESCE(d.forwarder_id, p.forwarder_id) = ?" . $scope . ' ORDER BY d.shipped_at DESC, d.id DESC');
        $stmt->execute([$agencyId, ...$params]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> Comptes du portail rattachés à l'agence */
    public function users(int $agencyId): array
    {
        $stmt = Database::pdo()->prepare('SELECT id, name, email, active, last_login_at FROM users WHERE forwarder_id = ? ORDER BY name');
        $stmt->execute([$agencyId]);
        return $stmt->fetchAll();
    }

    public function save(array $data, ?int $id = null): int
    {
        $pdo = Database::pdo();
        $values = array_map(fn ($c) => $data[$c] ?? null, self::COLS);
        if ($id === null) {
            $pdo->prepare('INSERT INTO customs_agencies (' . implode(',', self::COLS) . ') VALUES (' . implode(',', array_fill(0, count(self::COLS), '?')) . ')')->execute($values);
            return (int) $pdo->lastInsertId();
        }
        $pdo->prepare('UPDATE customs_agencies SET ' . implode(' = ?, ', self::COLS) . ' = ? WHERE id = ?')->execute([...$values, $id]);
        return $id;
    }
}
