<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Security\Auth;

final class SiteRepository
{
    /** @return list<array<string,mixed>> Sites accessibles à l'utilisateur courant (droits, pas site de travail) */
    public function allowed(bool $activeOnly = true): array
    {
        $user = Auth::require();
        $sql = 'SELECT s.*, (SELECT COUNT(*) FROM warehouses w WHERE w.site_id = s.id) AS warehouse_count FROM sites s WHERE 1=1';
        $params = [];
        if ($activeOnly) {
            $sql .= ' AND s.active = 1';
        }
        if (!$user->role->isGlobal()) {
            if ($user->siteIds === []) {
                return [];
            }
            $sql .= ' AND s.id IN (' . implode(',', array_fill(0, count($user->siteIds), '?')) . ')';
            $params = $user->siteIds;
        }
        $stmt = Database::pdo()->prepare($sql . ' ORDER BY s.name');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM sites WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        $stmt = Database::pdo()->prepare('SELECT 1 FROM sites WHERE code = ? AND id <> ?');
        $stmt->execute([$code, $exceptId ?? 0]);
        return (bool) $stmt->fetchColumn();
    }

    /** @param array{code:string,name:string,city:?string,active:int} $data */
    public function save(array $data, ?int $id = null): int
    {
        $pdo = Database::pdo();
        if ($id === null) {
            $pdo->prepare('INSERT INTO sites (code, name, city, active) VALUES (?,?,?,?)')
                ->execute([$data['code'], $data['name'], $data['city'], $data['active']]);
            return (int) $pdo->lastInsertId();
        }
        $pdo->prepare('UPDATE sites SET code = ?, name = ?, city = ?, active = ? WHERE id = ?')
            ->execute([$data['code'], $data['name'], $data['city'], $data['active'], $id]);
        return $id;
    }
}
