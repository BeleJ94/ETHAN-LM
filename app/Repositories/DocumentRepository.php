<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Référentiel documentaire : types de documents et règles de check-list. */
final class DocumentRepository
{
    public const PROVIDERS = ['supplier', 'forwarder', 'customs', 'customer', 'internal'];
    public const LEVELS = ['order', 'delivery'];
    private const TYPE_COLS = ['code', 'name', 'description', 'provider', 'level', 'requires_number', 'requires_file', 'requires_issue_date', 'has_expiry', 'sort_order', 'active'];
    private const RULE_COLS = ['document_type_id', 'fulfillment_mode', 'family_id', 'transport_mode', 'required', 'blocking_stage', 'notes', 'active'];

    /** @return list<array<string,mixed>> */
    public function types(bool $activeOnly = false): array
    {
        return Database::pdo()->query('SELECT t.*,
                (SELECT COUNT(*) FROM document_rules r WHERE r.document_type_id = t.id AND r.active = 1) AS rule_count,
                (SELECT COUNT(*) FROM shipment_documents s WHERE s.document_type_id = t.id) AS usage_count
            FROM document_types t' . ($activeOnly ? ' WHERE t.active = 1' : '') . ' ORDER BY t.sort_order, t.name')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function findType(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT t.*, (SELECT COUNT(*) FROM shipment_documents s WHERE s.document_type_id = t.id) AS usage_count
            FROM document_types t WHERE t.id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function typeCodeExists(string $code, ?int $exceptId = null): bool
    {
        $stmt = Database::pdo()->prepare('SELECT 1 FROM document_types WHERE code = ? AND id <> ?');
        $stmt->execute([$code, $exceptId ?? 0]);
        return (bool) $stmt->fetchColumn();
    }

    /** @param array<string,mixed> $data */
    public function saveType(array $data, ?int $id = null): int
    {
        return $this->save('document_types', self::TYPE_COLS, $data, $id);
    }

    /** @return list<array<string,mixed>> Règles avec libellés (pour l'écran de paramétrage) */
    public function rules(): array
    {
        return Database::pdo()->query('SELECT r.*, t.code AS type_code, t.name AS type_name, t.level, f.name AS family_name
            FROM document_rules r JOIN document_types t ON t.id = r.document_type_id
            LEFT JOIN item_families f ON f.id = r.family_id
            ORDER BY r.active DESC, COALESCE(r.fulfillment_mode, \'\'), t.sort_order, r.id')->fetchAll();
    }

    /** @return list<array<string,mixed>> Règles actives sur types actifs (moteur de check-list) */
    public function activeRules(): array
    {
        return Database::pdo()->query('SELECT r.*, t.level FROM document_rules r JOIN document_types t ON t.id = r.document_type_id
            WHERE r.active = 1 AND t.active = 1')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function findRule(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM document_rules WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** @param array<string,mixed> $data */
    public function saveRule(array $data, ?int $id = null): int
    {
        return $this->save('document_rules', self::RULE_COLS, $data, $id);
    }

    public function deleteRule(int $id): void
    {
        Database::pdo()->prepare('DELETE FROM document_rules WHERE id = ?')->execute([$id]);
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
