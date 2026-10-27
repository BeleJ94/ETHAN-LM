<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Security\Auth;

/** Journal qui / quoi / quand / avant-après — à appeler dans la transaction de la modification. */
final class ActivityLogger
{
    public static function log(string $entity, ?int $entityId, string $action, ?array $before = null, ?array $after = null): void
    {
        [$before, $after] = self::diff($before, $after);
        Database::pdo()->prepare(
            'INSERT INTO activity_log (user_id, entity, entity_id, action, before_json, after_json, ip) VALUES (?,?,?,?,?,?,?)'
        )->execute([
            Auth::user()?->id,
            $entity,
            $entityId,
            $action,
            $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    /** En modification, ne conserve que les champs réellement changés. */
    private static function diff(?array $before, ?array $after): array
    {
        if ($before === null || $after === null) {
            return [$before, $after];
        }
        $b = $a = [];
        // Valeurs composées (tableaux) comparées sous forme JSON : un cast (string) émettrait un avertissement.
        $str = fn (mixed $v): string => is_scalar($v) || $v === null ? (string) $v : (string) json_encode($v);
        foreach ($after as $key => $value) {
            if (array_key_exists($key, $before) && $str($before[$key]) !== $str($value)) {
                $b[$key] = $before[$key];
                $a[$key] = $value;
            }
        }
        return [$b, $a];
    }
}
