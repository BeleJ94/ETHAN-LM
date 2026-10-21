<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\CockpitRepository;
use App\Security\CurrentUser;

/**
 * Pastilles du menu : une par rubrique, calculée à partir des mêmes règles que « À faire aujourd'hui » du cockpit
 * (le menu et le cockpit disent toujours la même chose).
 * Chiffre = nombre d'objets à traiter dans la rubrique ; couleur = action la plus urgente (bad > warn > info).
 * Résultat mémorisé pour la requête HTTP ; une erreur de calcul n'empêche jamais l'affichage de la page.
 */
final class NavBadges
{
    /** @var array<string,array{count:int,level:string,title:string,lines:list<string>}|null> */
    private static array $cache = [];
    /** @var array<string,list<array<string,mixed>>>|null actions par rubrique */
    private static ?array $sections = null;

    /** @return array{count:int,level:string,title:string,lines:list<string>}|null */
    public static function get(string $key, CurrentUser $user): ?array
    {
        if (!array_key_exists($key, self::$cache)) {
            try {
                self::$cache[$key] = $key === 'portal' ? self::portal($user) : self::section($key, $user);
            } catch (\Throwable $e) {
                error_log('NavBadges ' . $key . ': ' . $e->getMessage());
                self::$cache[$key] = null;
            }
        }
        return self::$cache[$key];
    }

    /** Actions de la rubrique (pour l'infobulle et la modale de détail). @return list<array<string,mixed>> */
    public static function actions(string $key, CurrentUser $user): array
    {
        if (self::$sections === null) {
            self::$sections = [];
            foreach ((new CockpitRepository())->todo($user) as $a) {
                self::$sections[$a['section']][] = $a;
            }
        }
        return self::$sections[$key] ?? [];
    }

    /** @return array{count:int,level:string,title:string,lines:list<string>}|null */
    private static function section(string $key, CurrentUser $user): ?array
    {
        $actions = self::actions($key, $user);
        if (!$actions) {
            return null;
        }
        $rank = ['bad' => 0, 'warn' => 1, 'info' => 2];
        $level = 'info';
        $count = 0;
        $lines = [];
        foreach ($actions as $a) {
            $count += (int) $a['n'];
            if ($rank[$a['level']] < $rank[$level]) {
                $level = $a['level'];
            }
        }
        // Infobulle : les actions les plus urgentes (déjà triées), 6 au plus
        $dot = ['bad' => '🔴', 'warn' => '🟠', 'info' => '🔵'];
        foreach (array_slice($actions, 0, 6) as $a) {
            $lines[] = $dot[$a['level']] . ' ' . $a['text'] . ($a['ref'] !== '' ? ' — ' . $a['ref'] : '');
        }
        if (count($actions) > 6) {
            $lines[] = t('nav.badge.more', ['n' => count($actions) - 6]);
        }
        return ['count' => $count, 'level' => $level, 'title' => t('nav.badge.actions', ['n' => $count]), 'lines' => $lines];
    }

    /** Portail du transitaire : documents à fournir sur ses expéditions. */
    private static function portal(CurrentUser $user): ?array
    {
        if ($user->forwarderId === null) {
            return null;
        }
        $ids = (new \App\Repositories\ShipmentDocumentRepository())->forwarderDeliveryIds($user->forwarderId);
        if (!$ids) {
            return null;
        }
        $stmt = Database::pdo()->prepare("SELECT COUNT(*) FROM shipment_documents sd JOIN deliveries d ON d.order_id = sd.order_id AND (sd.delivery_id = d.id OR sd.delivery_id IS NULL)
            WHERE d.id IN (" . implode(',', array_map('intval', $ids)) . ") AND sd.required = 1 AND sd.status IN ('pending','rejected')");
        $stmt->execute();
        $todo = (int) $stmt->fetchColumn();
        $title = t('nav.badge.portal_todo', ['n' => $todo]);
        return $todo > 0 ? ['count' => $todo, 'level' => 'warn', 'title' => $title, 'lines' => [$title]] : null;
    }
}
