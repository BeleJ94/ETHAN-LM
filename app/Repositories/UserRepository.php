<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class UserRepository
{
    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return Database::pdo()->query('SELECT u.id, u.name, u.email, u.role, u.invoice_approver, u.active, u.last_login_at,
                GROUP_CONCAT(s.code ORDER BY s.code SEPARATOR \', \') AS sites
            FROM users u LEFT JOIN user_sites us ON us.user_id = u.id LEFT JOIN sites s ON s.id = us.site_id
            GROUP BY u.id ORDER BY u.name')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT id, name, email, role, forwarder_id, invoice_approver, locale, active FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) {
            return null;
        }
        $sites = Database::pdo()->prepare('SELECT site_id FROM user_sites WHERE user_id = ?');
        $sites->execute([$id]);
        $user['site_ids'] = array_map('intval', $sites->fetchAll(\PDO::FETCH_COLUMN));
        return $user;
    }

    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        $stmt = Database::pdo()->prepare('SELECT 1 FROM users WHERE email = ? AND id <> ?');
        $stmt->execute([$email, $exceptId ?? 0]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param array{name:string,email:string,role:string,active:int,password?:?string} $data
     * @param list<int> $siteIds
     */
    public function save(array $data, array $siteIds, ?int $id = null): int
    {
        $pdo = Database::pdo();
        if ($id === null) {
            $pdo->prepare('INSERT INTO users (name, email, role, forwarder_id, invoice_approver, active, password_hash) VALUES (?,?,?,?,?,?,?)')
                ->execute([$data['name'], $data['email'], $data['role'], $data['forwarder_id'] ?? null, $data['invoice_approver'] ?? 0, $data['active'], password_hash((string) $data['password'], PASSWORD_DEFAULT)]);
            $id = (int) $pdo->lastInsertId();
        } else {
            $pdo->prepare('UPDATE users SET name = ?, email = ?, role = ?, forwarder_id = ?, invoice_approver = ?, active = ? WHERE id = ?')
                ->execute([$data['name'], $data['email'], $data['role'], $data['forwarder_id'] ?? null, $data['invoice_approver'] ?? 0, $data['active'], $id]);
            if (!empty($data['password'])) {
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($data['password'], PASSWORD_DEFAULT), $id]);
            }
        }
        $pdo->prepare('DELETE FROM user_sites WHERE user_id = ?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO user_sites (user_id, site_id) VALUES (?, ?)');
        foreach (array_unique($siteIds) as $siteId) {
            $ins->execute([$id, $siteId]);
        }
        return $id;
    }
}
