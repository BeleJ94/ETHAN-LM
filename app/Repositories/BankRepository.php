<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Référentiel des banques et de leurs agences. */
final class BankRepository
{
    private const BANK_COLS = ['code', 'name', 'short_name', 'swift', 'country', 'phone', 'email', 'website', 'notes', 'active'];
    private const BRANCH_COLS = ['bank_id', 'code', 'name', 'city', 'address', 'phone', 'email', 'contact_name', 'active'];

    /** Utilisation (licences) : une banque ou une agence utilisée ne se supprime pas, elle se désactive. */
    private const BANK_USAGE = '(SELECT COUNT(*) FROM import_licences l WHERE l.bank_id = b.id)';
    private const BRANCH_USAGE = '(SELECT COUNT(*) FROM import_licences l WHERE l.branch_id = br.id)';

    /** @return list<array<string,mixed>> Banques avec nombre d'agences (actives / total) et d'utilisations */
    public function all(bool $activeOnly = false): array
    {
        return Database::pdo()->query('SELECT b.*,
                (SELECT COUNT(*) FROM bank_branches br WHERE br.bank_id = b.id) AS branch_count,
                (SELECT COUNT(*) FROM bank_branches br WHERE br.bank_id = b.id AND br.active = 1) AS active_branch_count,
                ' . self::BANK_USAGE . ' AS usage_count
            FROM banks b' . ($activeOnly ? ' WHERE b.active = 1' : '') . ' ORDER BY b.name')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT b.*, (SELECT COUNT(*) FROM bank_branches br WHERE br.bank_id = b.id) AS branch_count,
                ' . self::BANK_USAGE . ' AS usage_count FROM banks b WHERE b.id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function branches(int $bankId, bool $activeOnly = false): array
    {
        $stmt = Database::pdo()->prepare('SELECT br.*, ' . self::BRANCH_USAGE . ' AS usage_count FROM bank_branches br
            WHERE br.bank_id = ?' . ($activeOnly ? ' AND br.active = 1' : '') . ' ORDER BY br.city, br.name');
        $stmt->execute([$bankId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> Toutes les agences actives (listes de choix dépendantes de la banque) */
    public function allBranches(bool $activeOnly = true): array
    {
        return Database::pdo()->query('SELECT br.id, br.bank_id, br.code, br.name, br.city, br.active FROM bank_branches br'
            . ($activeOnly ? ' WHERE br.active = 1' : '') . ' ORDER BY br.city, br.name')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function findBranch(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT br.*, b.name AS bank_name, ' . self::BRANCH_USAGE . ' AS usage_count
            FROM bank_branches br JOIN banks b ON b.id = br.bank_id WHERE br.id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Unicité : code et nom de banque ; code et nom d'agence au sein d'une même banque. */
    public function bankExists(string $column, string $value, ?int $exceptId = null): bool
    {
        if (!in_array($column, ['code', 'name'], true)) {
            throw new \InvalidArgumentException($column);
        }
        $stmt = Database::pdo()->prepare("SELECT 1 FROM banks WHERE $column = ? AND id <> ?");
        $stmt->execute([$value, $exceptId ?? 0]);
        return (bool) $stmt->fetchColumn();
    }

    public function branchExists(int $bankId, string $column, string $value, ?int $exceptId = null): bool
    {
        if (!in_array($column, ['code', 'name'], true)) {
            throw new \InvalidArgumentException($column);
        }
        $stmt = Database::pdo()->prepare("SELECT 1 FROM bank_branches WHERE bank_id = ? AND $column = ? AND id <> ?");
        $stmt->execute([$bankId, $value, $exceptId ?? 0]);
        return (bool) $stmt->fetchColumn();
    }

    public function saveBank(array $data, ?int $id = null): int
    {
        return $this->save('banks', self::BANK_COLS, $data, $id);
    }

    public function saveBranch(array $data, ?int $id = null): int
    {
        return $this->save('bank_branches', self::BRANCH_COLS, $data, $id);
    }

    public function deleteBank(int $id): void
    {
        Database::pdo()->prepare('DELETE FROM banks WHERE id = ?')->execute([$id]);
    }

    public function deleteBranch(int $id): void
    {
        Database::pdo()->prepare('DELETE FROM bank_branches WHERE id = ?')->execute([$id]);
    }

    /** @param list<string> $cols */
    private function save(string $table, array $cols, array $data, ?int $id): int
    {
        $pdo = Database::pdo();
        $values = array_map(fn ($c) => $data[$c] ?? null, $cols);
        if ($id === null) {
            $pdo->prepare("INSERT INTO $table (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute($values);
            return (int) $pdo->lastInsertId();
        }
        $pdo->prepare("UPDATE $table SET " . implode(' = ?, ', $cols) . ' = ? WHERE id = ?')->execute([...$values, $id]);
        return $id;
    }
}
