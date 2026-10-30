<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Database;
use App\Core\Session;

final class Auth
{
    private static ?CurrentUser $user = null;
    private static bool $loaded = false;

    public static function attempt(string $email, string $password): bool
    {
        $stmt = Database::pdo()->prepare('SELECT id, password_hash FROM users WHERE email = ? AND active = 1');
        $stmt->execute([mb_strtolower($email)]);
        $row = $stmt->fetch();
        // Vérification exécutée même sans utilisateur pour ne pas révéler son existence par le temps de réponse.
        $hash = $row['password_hash'] ?? password_hash('dummy-password', PASSWORD_DEFAULT);
        if (!password_verify($password, $hash) || !$row) {
            return false;
        }
        Session::regenerate();
        Session::set('user_id', (int) $row['id']);
        Database::pdo()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$row['id']]);
        self::$loaded = false;
        return true;
    }

    public static function logout(): void
    {
        Session::destroy();
        self::$user = null;
    }

    public static function user(): ?CurrentUser
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;
        $id = Session::get('user_id');
        if (!is_int($id)) {
            return null;
        }
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id, name, email, role, forwarder_id, invoice_approver FROM users WHERE id = ? AND active = 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            Session::forget('user_id');
            return null;
        }
        $sites = $pdo->prepare('SELECT site_id FROM user_sites WHERE user_id = ?');
        $sites->execute([$id]);
        return self::$user = new CurrentUser(
            (int) $row['id'],
            $row['name'],
            $row['email'],
            Role::from($row['role']),
            array_map('intval', $sites->fetchAll(\PDO::FETCH_COLUMN)),
            $row['forwarder_id'] !== null ? (int) $row['forwarder_id'] : null,
            (bool) $row['invoice_approver'],
        );
    }

    public static function require(): CurrentUser
    {
        $user = self::user();
        if ($user === null) {
            throw new \App\Core\HttpException(401);
        }
        return $user;
    }
}
