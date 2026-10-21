<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Security\SiteScope;

final class WarehouseRepository
{
    /** @return list<array<string,mixed>> Entrepôts du périmètre courant, avec valeur de stock */
    public function scoped(bool $activeOnly = false): array
    {
        [$scope, $params] = SiteScope::clause('w.site_id');
        $sql = 'SELECT w.*, s.name AS site_name, s.code AS site_code,
                       COUNT(CASE WHEN sl.quantity > 0 THEN 1 END) AS item_count,
                       COALESCE(SUM(sl.quantity * i.avg_cost), 0) AS stock_value
                FROM warehouses w
                JOIN sites s ON s.id = w.site_id
                LEFT JOIN stock_levels sl ON sl.warehouse_id = w.id
                LEFT JOIN items i ON i.id = sl.item_id
                WHERE 1=1' . $scope . ($activeOnly ? ' AND w.active = 1 AND s.active = 1' : '') . '
                GROUP BY w.id ORDER BY s.name, w.name';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Entrepôt du périmètre, sinon null (→ 404). @return array<string,mixed>|null */
    public function findScoped(int $id): ?array
    {
        [$scope, $params] = SiteScope::clause('w.site_id');
        $stmt = Database::pdo()->prepare('SELECT w.*, s.name AS site_name FROM warehouses w JOIN sites s ON s.id = w.site_id WHERE w.id = ?' . $scope);
        $stmt->execute([$id, ...$params]);
        return $stmt->fetch() ?: null;
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        $stmt = Database::pdo()->prepare('SELECT 1 FROM warehouses WHERE code = ? AND id <> ?');
        $stmt->execute([$code, $exceptId ?? 0]);
        return (bool) $stmt->fetchColumn();
    }

    /** @param array{site_id:int,code:string,name:string,active:int} $data */
    public function save(array $data, ?int $id = null): int
    {
        $pdo = Database::pdo();
        if ($id === null) {
            $pdo->prepare('INSERT INTO warehouses (site_id, code, name, active) VALUES (?,?,?,?)')
                ->execute([$data['site_id'], $data['code'], $data['name'], $data['active']]);
            return (int) $pdo->lastInsertId();
        }
        $pdo->prepare('UPDATE warehouses SET site_id = ?, code = ?, name = ?, active = ? WHERE id = ?')
            ->execute([$data['site_id'], $data['code'], $data['name'], $data['active'], $id]);
        return $id;
    }
}
