<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\DataTable;
use App\Security\SiteScope;

/**
 * Les articles sont un référentiel commun à tous les sites ;
 * les quantités affichées sont limitées aux entrepôts du périmètre.
 */
final class ItemRepository
{
    /** Liste blanche de tri (nom de colonne JS → expression SQL). */
    public const SORTABLE = [
        'sku' => 'i.sku',
        'name' => 'i.name',
        'category' => 'c.name',
        'quantity' => 'quantity',
        'min_stock' => 'i.min_stock',
        'avg_cost' => 'i.avg_cost',
        'value' => 'value',
    ];

    /** @param array<string,string> $filters */
    public function datatable(DataTable $dt, array $filters): array
    {
        [$scope, $scopeParams] = SiteScope::clause('w.site_id');
        $whParams = $scopeParams;
        $whFilter = '';
        if (($filters['warehouse_id'] ?? '') !== '') {
            $whFilter = ' AND w.id = ?';
            $whParams[] = (int) $filters['warehouse_id'];
        }

        $base = 'FROM items i
            JOIN units u ON u.id = i.unit_id
            LEFT JOIN categories c ON c.id = i.category_id
            LEFT JOIN (
                SELECT sl.item_id, SUM(sl.quantity) AS qty
                FROM stock_levels sl JOIN warehouses w ON w.id = sl.warehouse_id
                WHERE 1=1' . $scope . $whFilter . '
                GROUP BY sl.item_id
            ) q ON q.item_id = i.id
            WHERE 1=1';
        $params = $whParams;

        $where = '';
        if (($filters['family_id'] ?? '') !== '') {
            $where .= ' AND i.family_id = ?';
            $params[] = (int) $filters['family_id'];
        }
        if (($filters['category_id'] ?? '') !== '') {
            $where .= ' AND i.category_id = ?';
            $params[] = (int) $filters['category_id'];
        }
        $status = $filters['status'] ?? '';
        if ($status === 'inactive') {
            $where .= ' AND i.active = 0';
        } else {
            $where .= ' AND i.active = 1';
        }
        if ($status === 'low') {
            $where .= ' AND COALESCE(q.qty, 0) > 0 AND COALESCE(q.qty, 0) <= i.min_stock';
        } elseif ($status === 'out') {
            $where .= ' AND COALESCE(q.qty, 0) <= 0';
        } elseif ($status === 'action') {
            $where .= ' AND COALESCE(q.qty, 0) <= i.min_stock AND i.min_stock > 0';
        }

        $pdo = Database::pdo();
        $total = $pdo->prepare('SELECT COUNT(*) ' . $base . ' AND i.active = 1');
        $total->execute($whParams);

        if ($dt->search !== '') {
            $where .= ' AND (i.sku LIKE ? OR i.name LIKE ? OR i.hs_code LIKE ?)';
            $like = '%' . $dt->search . '%';
            array_push($params, $like, $like, $like);
        }
        $filtered = $pdo->prepare('SELECT COUNT(*) ' . $base . $where);
        $filtered->execute($params);

        $rows = $pdo->prepare('SELECT i.id, i.sku, i.name, i.hs_code, i.min_stock, i.avg_cost, i.active,
                (SELECT f.name FROM item_families f WHERE f.id = i.family_id) AS family_name,
                (SELECT f.color FROM item_families f WHERE f.id = i.family_id) AS family_color,
                u.code AS unit, c.name AS category,
                COALESCE(q.qty, 0) AS quantity, COALESCE(q.qty, 0) * i.avg_cost AS value '
            . $base . $where . ' ORDER BY ' . $dt->orderBy . ', i.id' . $dt->limitSql());
        $rows->execute($params);

        return [(int) $total->fetchColumn(), (int) $filtered->fetchColumn(), $rows->fetchAll()];
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT i.*, u.code AS unit, u.label AS unit_label, c.name AS category,
                f.name AS family_name, f.color AS family_color, f.code AS family_code
            FROM items i JOIN units u ON u.id = i.unit_id LEFT JOIN categories c ON c.id = i.category_id
            JOIN item_families f ON f.id = i.family_id WHERE i.id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> Stock par entrepôt du périmètre */
    public function levels(int $itemId): array
    {
        [$scope, $params] = SiteScope::clause('w.site_id');
        $stmt = Database::pdo()->prepare('SELECT w.id, w.code, w.name, s.name AS site_name, COALESCE(sl.quantity, 0) AS quantity
            FROM warehouses w JOIN sites s ON s.id = w.site_id
            LEFT JOIN stock_levels sl ON sl.warehouse_id = w.id AND sl.item_id = ?
            WHERE w.active = 1' . $scope . ' ORDER BY s.name, w.name');
        $stmt->execute([$itemId, ...$params]);
        return $stmt->fetchAll();
    }

    public function skuExists(string $sku, ?int $exceptId = null): bool
    {
        $stmt = Database::pdo()->prepare('SELECT 1 FROM items WHERE sku = ? AND id <> ?');
        $stmt->execute([$sku, $exceptId ?? 0]);
        return (bool) $stmt->fetchColumn();
    }

    /** @return list<array<string,mixed>> Recherche pour le sélecteur d'article */
    public function search(string $term, int $limit = 20): array
    {
        $like = '%' . $term . '%';
        $stmt = Database::pdo()->prepare('SELECT i.id, i.sku, i.name, u.code AS unit FROM items i JOIN units u ON u.id = i.unit_id
            WHERE i.active = 1 AND (i.sku LIKE ? OR i.name LIKE ?) ORDER BY i.sku LIMIT ' . $limit);
        $stmt->execute([$like, $like]);
        return $stmt->fetchAll();
    }

    /** @param array<string,mixed> $data */
    public function save(array $data, ?int $id = null): int
    {
        $pdo = Database::pdo();
        $cols = ['sku', 'name', 'description', 'category_id', 'family_id', 'unit_id', 'hs_code', 'min_stock', 'active'];
        $values = array_map(fn ($c) => $data[$c], $cols);
        if ($id === null) {
            $pdo->prepare('INSERT INTO items (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
                ->execute($values);
            return (int) $pdo->lastInsertId();
        }
        $pdo->prepare('UPDATE items SET ' . implode(' = ?, ', $cols) . ' = ? WHERE id = ?')->execute([...$values, $id]);
        return $id;
    }
}
