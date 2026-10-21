<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Référentiel des familles d'articles (réactifs, carburants…). */
final class FamilyRepository
{
    public const COLORS = ['blue', 'green', 'orange', 'red', 'purple', 'teal', 'gray'];

    /** @return list<array<string,mixed>> Avec nombre d'articles (actifs / total) */
    public function all(bool $activeOnly = false): array
    {
        return Database::pdo()->query('SELECT f.*,
                (SELECT COUNT(*) FROM items i WHERE i.family_id = f.id) AS item_count,
                (SELECT COUNT(*) FROM items i WHERE i.family_id = f.id AND i.active = 1) AS active_item_count
            FROM item_families f' . ($activeOnly ? ' WHERE f.active = 1' : '') . ' ORDER BY f.sort_order, f.name')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT f.*, (SELECT COUNT(*) FROM items i WHERE i.family_id = f.id) AS item_count
            FROM item_families f WHERE f.id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function isActive(int $id): bool
    {
        $stmt = Database::pdo()->prepare('SELECT 1 FROM item_families WHERE id = ? AND active = 1');
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
    }

    public function exists(string $column, string $value, ?int $exceptId = null): bool
    {
        if (!in_array($column, ['code', 'name'], true)) {
            throw new \InvalidArgumentException($column);
        }
        $stmt = Database::pdo()->prepare("SELECT 1 FROM item_families WHERE $column = ? AND id <> ?");
        $stmt->execute([$value, $exceptId ?? 0]);
        return (bool) $stmt->fetchColumn();
    }

    /** @param array{code:string,name:string,description:?string,color:string,sort_order:int,active:int} $data */
    public function save(array $data, ?int $id = null): int
    {
        $pdo = Database::pdo();
        $cols = ['code', 'name', 'description', 'color', 'sort_order', 'active'];
        $values = array_map(fn ($c) => $data[$c], $cols);
        if ($id === null) {
            $pdo->prepare('INSERT INTO item_families (' . implode(',', $cols) . ') VALUES (?,?,?,?,?,?)')->execute($values);
            return (int) $pdo->lastInsertId();
        }
        $pdo->prepare('UPDATE item_families SET ' . implode(' = ?, ', $cols) . ' = ? WHERE id = ?')->execute([...$values, $id]);
        return $id;
    }

    public function delete(int $id): void
    {
        Database::pdo()->prepare('DELETE FROM item_families WHERE id = ?')->execute([$id]);
    }
}
