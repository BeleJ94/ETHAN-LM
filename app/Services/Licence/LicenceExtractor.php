<?php

declare(strict_types=1);

namespace App\Services\Licence;

/**
 * Point d'extension : extraction automatique des données d'une licence depuis le fichier déposé (PDF / image).
 * Le moteur développé dans un autre projet sera branché ici ; il renvoie les champs reconnus, que l'utilisateur vérifie.
 * Clés attendues : number, issued_at (AAAA-MM-JJ), expires_at, origin_country, currency, amount, bank.
 */
interface LicenceExtractor
{
    public function isAvailable(): bool;

    /** @return array<string,string|float|null> */
    public function extract(string $path, string $mime): array;
}
