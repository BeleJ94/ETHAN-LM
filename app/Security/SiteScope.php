<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Session;

/**
 * Périmètre de données par site, appliqué dans toutes les requêtes des repositories.
 * Combine les sites autorisés de l'utilisateur et le « site de travail » choisi dans la sidebar.
 */
final class SiteScope
{
    /** @return list<int>|null null = aucun filtre (admin sans site de travail) */
    public static function siteIds(): ?array
    {
        $user = Auth::require();
        $working = Session::get('working_site_id');
        $allowed = $user->role->isGlobal() ? null : $user->siteIds;

        if (is_int($working) && ($allowed === null || in_array($working, $allowed, true))) {
            return [$working];
        }
        return $allowed;
    }

    /**
     * Fragment SQL « AND col IN (...) » avec paramètres positionnels.
     * @return array{0:string,1:list<int>}
     */
    public static function clause(string $column): array
    {
        $ids = self::siteIds();
        if ($ids === null) {
            return ['', []];
        }
        if ($ids === []) {
            return [' AND 1 = 0', []];
        }
        return [' AND ' . $column . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
    }

    /** Vérifie l'accès à un site précis (droits, pas site de travail). */
    public static function allows(int $siteId): bool
    {
        $user = Auth::require();
        return $user->role->isGlobal() || in_array($siteId, $user->siteIds, true);
    }
}
