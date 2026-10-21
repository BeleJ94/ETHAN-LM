<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\DomainException;

/**
 * Références automatiques et modifiables pour tous les champs « référence / code ».
 *
 * - suggest()  : proposition affichée dans le formulaire (ne consomme pas le compteur) ;
 * - resolve()  : à l'enregistrement, dans la transaction :
 *     vide                                → génération ;
 *     = proposition mais déjà prise       → génération du numéro suivant (formulaire ouvert en parallèle) ;
 *     saisie libre                        → contrôle format + unicité, et le compteur avance si le format correspond.
 */
final class ReferenceGenerator
{
    /** type => [table, colonne, préfixe (null = dépend du contexte), annuel, chiffres, longueur max] */
    public const TYPES = [
        'order' => ['customer_orders', 'reference', 'CMD', true, 6, 30],
        'po' => ['purchase_orders', 'reference', 'PO', true, 6, 30],
        'delivery' => ['deliveries', 'reference', 'LIV', true, 6, 30],
        'inspection' => ['inspections', 'reference', 'INSP', true, 6, 30],
        'invoice_receivable' => ['invoices', 'reference', 'FAC', true, 6, 30],
        'invoice_payable' => ['invoices', 'reference', 'FF', true, 6, 30],
        'movement_in' => ['stock_movements', 'reference', 'ENT', true, 6, 30],
        'movement_out' => ['stock_movements', 'reference', 'SOR', true, 6, 30],
        'movement_transfer' => ['stock_movements', 'reference', 'TRF', true, 6, 30],
        'movement_adjust' => ['stock_movements', 'reference', 'INV', true, 6, 30],
        'item' => ['items', 'sku', null, false, 4, 40],
        'customer' => ['customers', 'code', 'CLI', false, 4, 20],
        'supplier' => ['suppliers', 'code', 'FRS', false, 4, 20],
        'site' => ['sites', 'code', 'SITE', false, 2, 20],
        'warehouse' => ['warehouses', 'code', null, false, 2, 20],
        'family' => ['item_families', 'code', 'FAM', false, 2, 20],
        'bank' => ['banks', 'code', 'BQ', false, 3, 20],
        'agency' => ['customs_agencies', 'code', 'AGD', false, 3, 20],
    ];

    /** Caractères admis : majuscules, chiffres et séparateurs usuels. */
    public const PATTERN = '/^[A-Z0-9][A-Z0-9\-_\/.]*$/';

    /** @param array<string,mixed> $ctx family_id (article), site_id (entrepôt) */
    public static function suggest(string $type, array $ctx = []): string
    {
        $stem = self::stem($type, $ctx);
        $n = Sequence::peek($stem);
        do {
            $candidate = self::format($type, $stem, ++$n);
        } while (self::exists($type, $candidate));
        return $candidate;
    }

    /** @param array<string,mixed> $ctx */
    public static function generate(string $type, array $ctx = []): string
    {
        $stem = self::stem($type, $ctx);
        do {
            $candidate = self::format($type, $stem, Sequence::nextValue($stem));
        } while (self::exists($type, $candidate));
        return $candidate;
    }

    /**
     * Référence définitive (à appeler dans la transaction d'écriture).
     * @param array<string,mixed> $ctx
     * @param int|null $exceptId enregistrement modifié (sa propre référence n'est pas un doublon)
     */
    public static function resolve(string $type, ?string $value, ?string $suggested = null, array $ctx = [], ?int $exceptId = null): string
    {
        $value = self::normalize($value);
        if ($value === '') {
            return self::generate($type, $ctx);
        }
        $max = self::TYPES[$type][5];
        if (mb_strlen($value) > $max || !preg_match(self::PATTERN, $value)) {
            throw new DomainException('reference.invalid', ['max' => $max]);
        }
        if (self::exists($type, $value, $exceptId)) {
            if ($value === self::normalize($suggested)) {
                return self::generate($type, $ctx);
            }
            throw new DomainException('reference.taken', ['ref' => $value]);
        }
        $stem = self::stem($type, $ctx);
        if (preg_match('/^' . preg_quote($stem, '/') . '-(\d+)$/', $value, $m)) {
            Sequence::bumpTo($stem, (int) $m[1]);
        }
        return $value;
    }

    public static function normalize(?string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/', '', trim((string) $value)) ?? '');
    }

    public static function exists(string $type, string $value, ?int $exceptId = null): bool
    {
        [$table, $column] = self::TYPES[$type] ?? throw new \InvalidArgumentException($type);
        $stmt = Database::pdo()->prepare("SELECT 1 FROM $table WHERE $column = ? AND id <> ? LIMIT 1");
        $stmt->execute([$value, $exceptId ?? 0]);
        return (bool) $stmt->fetchColumn();
    }

    /** Racine du compteur : préfixe (+ année pour les documents). */
    private static function stem(string $type, array $ctx): string
    {
        [, , $prefix, $yearly] = self::TYPES[$type] ?? throw new \InvalidArgumentException($type);
        $prefix ??= match ($type) {
            'item' => self::lookup('SELECT code FROM item_families WHERE id = ?', $ctx['family_id'] ?? null) ?? 'ART',
            'warehouse' => self::lookup('SELECT code FROM sites WHERE id = ?', $ctx['site_id'] ?? null) ?? 'ENT',
        };
        return $yearly ? $prefix . '-' . date('Y') : $prefix;
    }

    private static function format(string $type, string $stem, int $n): string
    {
        return $stem . '-' . str_pad((string) $n, self::TYPES[$type][4], '0', STR_PAD_LEFT);
    }

    private static function lookup(string $sql, mixed $id): ?string
    {
        if (!$id) {
            return null;
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([(int) $id]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }
}
