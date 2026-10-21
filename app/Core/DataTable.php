<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Paramètres d'une requête DataTables côté serveur, le tri étant limité à une liste blanche.
 * Les colonnes sont envoyées par nom (columns[i][data]) : clé de la liste blanche → expression SQL.
 */
final class DataTable
{
    public const EXPORT_CAP = 5000;

    public readonly int $draw;
    public readonly int $start;
    public readonly int $length;
    public readonly string $search;
    public readonly string $orderBy;

    /** @param array<string,string> $sortable nom de colonne → expression SQL */
    public function __construct(Request $request, array $sortable, string $defaultOrder)
    {
        $q = $request->allQuery();
        $this->draw = (int) ($q['draw'] ?? 0);
        $this->start = max(0, (int) ($q['start'] ?? 0));
        $length = (int) ($q['length'] ?? 15);
        // length = -1 : export de tous les enregistrements filtrés (plafonné)
        $this->length = $length === -1 ? self::EXPORT_CAP : min(max($length, 1), 100);
        $this->search = trim((string) ($q['search']['value'] ?? ''));

        $order = $defaultOrder;
        $idx = $q['order'][0]['column'] ?? null;
        if ($idx !== null) {
            $name = (string) ($q['columns'][(int) $idx]['data'] ?? '');
            $dir = strtolower((string) ($q['order'][0]['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
            if (isset($sortable[$name])) {
                $order = $sortable[$name] . ' ' . $dir;
            }
        }
        $this->orderBy = $order;
    }

    public function limitSql(): string
    {
        return ' LIMIT ' . $this->length . ' OFFSET ' . $this->start;
    }

    /** @param list<array<string,mixed>> $rows */
    public function response(int $total, int $filtered, array $rows): Response
    {
        return Response::json([
            'draw' => $this->draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $rows,
            'truncated' => $filtered > $this->start + $this->length && $this->length === self::EXPORT_CAP,
        ]);
    }
}
