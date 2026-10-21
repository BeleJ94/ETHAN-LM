<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Security\SiteScope;

final class DashboardRepository
{
    /** @return array{items:int,low:int,out:int,value:float,moves_today:int} */
    public function kpis(): array
    {
        [$scope, $params] = SiteScope::clause('w.site_id');
        $sub = '(SELECT sl.item_id, SUM(sl.quantity) qty FROM stock_levels sl JOIN warehouses w ON w.id = sl.warehouse_id WHERE 1=1' . $scope . ' GROUP BY sl.item_id)';
        $stmt = Database::pdo()->prepare('SELECT
                COUNT(*) AS items,
                SUM(COALESCE(q.qty,0) > 0 AND COALESCE(q.qty,0) <= i.min_stock) AS low,
                SUM(COALESCE(q.qty,0) <= 0) AS `out`,
                COALESCE(SUM(COALESCE(q.qty,0) * i.avg_cost), 0) AS value
            FROM items i LEFT JOIN ' . $sub . ' q ON q.item_id = i.id WHERE i.active = 1');
        $stmt->execute($params);
        $row = $stmt->fetch();

        $moves = Database::pdo()->prepare('SELECT COUNT(*) FROM stock_movements m JOIN warehouses w ON w.id = m.warehouse_id
            WHERE m.moved_at >= CURDATE()' . $scope);
        $moves->execute($params);

        return [
            'items' => (int) $row['items'],
            'low' => (int) $row['low'],
            'out' => (int) $row['out'],
            'value' => (float) $row['value'],
            'moves_today' => (int) $moves->fetchColumn(),
        ];
    }

    /** @return list<array{day:string,in:float,out:float}> Valeur des entrées / sorties sur 30 jours */
    public function flows(int $days = 30): array
    {
        [$scope, $params] = SiteScope::clause('w.site_id');
        $stmt = Database::pdo()->prepare('SELECT DATE(m.moved_at) AS day,
                SUM(CASE WHEN m.type IN (\'in\',\'adjust_in\') THEN m.quantity * m.unit_cost_base ELSE 0 END) AS `in`,
                SUM(CASE WHEN m.type IN (\'out\',\'adjust_out\') THEN m.quantity * m.unit_cost_base ELSE 0 END) AS `out`
            FROM stock_movements m JOIN warehouses w ON w.id = m.warehouse_id
            WHERE m.moved_at >= CURDATE() - INTERVAL ' . $days . ' DAY' . $scope . '
            GROUP BY DATE(m.moved_at) ORDER BY day');
        $stmt->execute($params);
        $byDay = [];
        foreach ($stmt->fetchAll() as $r) {
            $byDay[$r['day']] = $r;
        }
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i day"));
            $out[] = ['day' => $d, 'in' => round((float) ($byDay[$d]['in'] ?? 0), 2), 'out' => round((float) ($byDay[$d]['out'] ?? 0), 2)];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> Articles sous le seuil, plus urgents d'abord */
    public function lowStock(int $limit = 8): array
    {
        [$scope, $params] = SiteScope::clause('w.site_id');
        $stmt = Database::pdo()->prepare('SELECT i.id, i.sku, i.name, i.min_stock, u.code AS unit, COALESCE(q.qty, 0) AS quantity
            FROM items i JOIN units u ON u.id = i.unit_id
            LEFT JOIN (SELECT sl.item_id, SUM(sl.quantity) qty FROM stock_levels sl JOIN warehouses w ON w.id = sl.warehouse_id WHERE 1=1' . $scope . ' GROUP BY sl.item_id) q ON q.item_id = i.id
            WHERE i.active = 1 AND i.min_stock > 0 AND COALESCE(q.qty, 0) <= i.min_stock
            ORDER BY COALESCE(q.qty, 0) / i.min_stock, i.sku LIMIT ' . $limit);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
