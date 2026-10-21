<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Référentiels simples : unités, catégories, devises, taux. */
final class LookupRepository
{
    /** @return list<array<string,mixed>> */
    public function units(): array
    {
        return Database::pdo()->query('SELECT id, code, label FROM units ORDER BY label')->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function categories(): array
    {
        return Database::pdo()->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
    }

    public function createCategory(string $name): int
    {
        $pdo = Database::pdo();
        $pdo->prepare('INSERT INTO categories (name) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)')->execute([$name]);
        return (int) $pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function currencies(): array
    {
        return Database::pdo()->query('SELECT code, name FROM currencies ORDER BY code')->fetchAll();
    }

    public function currencyExists(string $code): bool
    {
        $stmt = Database::pdo()->prepare('SELECT 1 FROM currencies WHERE code = ?');
        $stmt->execute([$code]);
        return (bool) $stmt->fetchColumn();
    }

    /** Dernier taux connu à la date donnée (null si aucun). */
    public function rateAt(string $currency, string $date): ?float
    {
        if ($currency === base_currency()) {
            return 1.0;
        }
        $stmt = Database::pdo()->prepare(
            'SELECT rate FROM exchange_rates WHERE currency_code = ? AND valid_from <= ? ORDER BY valid_from DESC LIMIT 1'
        );
        $stmt->execute([$currency, $date]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (float) $v;
    }

    /** @return array<string,float> devise → dernier taux connu */
    public function latestRates(): array
    {
        $rows = Database::pdo()->query(
            'SELECT r.currency_code, r.rate FROM exchange_rates r
             JOIN (SELECT currency_code, MAX(valid_from) d FROM exchange_rates GROUP BY currency_code) m
               ON m.currency_code = r.currency_code AND m.d = r.valid_from'
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['currency_code']] = (float) $r['rate'];
        }
        $out[base_currency()] = 1.0;
        return $out;
    }
}
