<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Compteurs de numérotation (table sequences), sans doublon grâce au verrou de ligne.
 * next() et bumpTo() s'appellent dans une transaction ; peek() est en lecture seule.
 */
final class Sequence
{
    /** Ancienne API : PREFIXE-AAAA-000001 (conservée pour compatibilité). */
    public static function next(string $prefix): string
    {
        $name = $prefix . '-' . date('Y');
        return sprintf('%s-%06d', $name, self::nextValue($name));
    }

    public static function nextValue(string $name): int
    {
        $pdo = Database::pdo();
        $pdo->prepare('INSERT INTO sequences (name, value) VALUES (?, 0) ON DUPLICATE KEY UPDATE name = name')->execute([$name]);
        $stmt = $pdo->prepare('SELECT value FROM sequences WHERE name = ? FOR UPDATE');
        $stmt->execute([$name]);
        $value = (int) $stmt->fetchColumn() + 1;
        $pdo->prepare('UPDATE sequences SET value = ? WHERE name = ?')->execute([$value, $name]);
        return $value;
    }

    /** Valeur actuelle sans réserver (proposition affichée dans un formulaire). */
    public static function peek(string $name): int
    {
        $stmt = Database::pdo()->prepare('SELECT value FROM sequences WHERE name = ?');
        $stmt->execute([$name]);
        return (int) $stmt->fetchColumn();
    }

    /** Une référence saisie à la main au format du compteur fait avancer celui-ci (pas de collision future). */
    public static function bumpTo(string $name, int $value): void
    {
        Database::pdo()->prepare('INSERT INTO sequences (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = GREATEST(value, VALUES(value))')
            ->execute([$name, $value]);
    }
}
