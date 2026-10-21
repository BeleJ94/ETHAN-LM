<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\DataTable;
use App\Security\SiteScope;

final class MovementRepository
{
    public const SORTABLE = [
        'moved_at' => 'm.moved_at',
        'reference' => 'm.reference',
        'type' => 'm.type',
        'item' => 'i.sku',
        'warehouse' => 'w.code',
        'quantity' => 'm.quantity',
        'unit_cost_base' => 'm.unit_cost_base',
        'user' => 'us.name',
    ];

    /** @param array<string,string> $filters */
    public function datatable(DataTable $dt, array $filters): array
    {
        [$scope, $params] = SiteScope::clause('w.site_id');
        $base = 'FROM stock_movements m
            JOIN items i ON i.id = m.item_id
            JOIN units u ON u.id = i.unit_id
            JOIN warehouses w ON w.id = m.warehouse_id
            JOIN users us ON us.id = m.user_id
            WHERE 1=1' . $scope;

        $pdo = Database::pdo();
        $total = $pdo->prepare('SELECT COUNT(*) ' . $base);
        $total->execute($params);

        $where = '';
        $map = ['warehouse_id' => 'm.warehouse_id', 'item_id' => 'm.item_id'];
        foreach ($map as $key => $col) {
            if (($filters[$key] ?? '') !== '') {
                $where .= " AND $col = ?";
                $params[] = (int) $filters[$key];
            }
        }
        $type = $filters['type'] ?? '';
        $groups = ['in' => ['in'], 'out' => ['out'], 'transfer' => ['transfer_out', 'transfer_in'], 'adjust' => ['adjust_in', 'adjust_out']];
        if (isset($groups[$type])) {
            $where .= ' AND m.type IN (' . implode(',', array_fill(0, count($groups[$type]), '?')) . ')';
            array_push($params, ...$groups[$type]);
        }
        $period = $filters['period'] ?? '';
        if ($period === 'today') {
            $where .= ' AND m.moved_at >= CURDATE()';
        } elseif ($period === '7d') {
            $where .= ' AND m.moved_at >= CURDATE() - INTERVAL 7 DAY';
        } elseif ($period === '30d') {
            $where .= ' AND m.moved_at >= CURDATE() - INTERVAL 30 DAY';
        }
        if ($dt->search !== '') {
            $where .= ' AND (m.reference LIKE ? OR i.sku LIKE ? OR i.name LIKE ? OR m.note LIKE ?)';
            $like = '%' . $dt->search . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $filtered = $pdo->prepare('SELECT COUNT(*) ' . $base . $where);
        $filtered->execute($params);

        $rows = $pdo->prepare('SELECT m.id, m.reference, m.type, m.quantity, m.unit_cost, m.currency_code, m.unit_cost_base,
                m.balance_after, m.note, m.moved_at, m.item_id, i.sku AS item_sku, i.name AS item_name, u.code AS unit,
                w.code AS warehouse_code, w.name AS warehouse_name, us.name AS user_name '
            . $base . $where . ' ORDER BY ' . $dt->orderBy . ', m.id DESC' . $dt->limitSql());
        $rows->execute($params);

        return [(int) $total->fetchColumn(), (int) $filtered->fetchColumn(), $rows->fetchAll()];
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 8, ?int $itemId = null): array
    {
        [$scope, $params] = SiteScope::clause('w.site_id');
        $sql = 'SELECT m.*, i.sku AS item_sku, i.name AS item_name, u.code AS unit, w.code AS warehouse_code, us.name AS user_name
            FROM stock_movements m JOIN items i ON i.id = m.item_id JOIN units u ON u.id = i.unit_id
            JOIN warehouses w ON w.id = m.warehouse_id JOIN users us ON us.id = m.user_id
            WHERE 1=1' . $scope;
        if ($itemId !== null) {
            $sql .= ' AND m.item_id = ?';
            $params[] = $itemId;
        }
        $stmt = Database::pdo()->prepare($sql . ' ORDER BY m.moved_at DESC, m.id DESC LIMIT ' . $limit);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
