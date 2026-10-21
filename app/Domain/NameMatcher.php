<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Rapprochement d'un nom lu sur un document avec un référentiel (fournisseurs…).
 * Ne crée jamais d'entrée : renvoie des candidats classés ; un seul candidat « sûr » est proposé d'office,
 * sinon l'utilisateur choisit (ambiguïté) ou sélectionne lui-même (aucune correspondance).
 */
final class NameMatcher
{
    /** Mots sans valeur distinctive (formes juridiques, articles). */
    private const STOP = ['sarl', 'sa', 'sas', 'sprl', 'ltd', 'limited', 'pty', 'llc', 'inc', 'co', 'company', 'corp', 'gmbh', 'bv', 'srl', 'spa',
        'plc', 'the', 'le', 'la', 'les', 'de', 'du', 'des', 'et', 'and', 'group', 'groupe', 'international', 'intl'];

    public static function normalize(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c']);
        // Sigles à points (« s.a. », « s.a.r.l. ») : lettres réunies pour reconnaître la forme juridique
        $s = preg_replace_callback('/\b(?:[a-z]\.){2,}/', fn ($m) => str_replace('.', '', $m[0]), $s) ?? $s;
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '';
        $words = array_filter(explode(' ', $s), fn ($w) => $w !== '' && !in_array($w, self::STOP, true));
        return implode(' ', $words);
    }

    /**
     * @param list<array{id:int,name:string}> $refs
     * @return array{status:string,best:?int,candidates:list<array{id:int,name:string,score:int}>}
     *         status : matched (un seul candidat sûr) | ambiguous (plusieurs proches) | none
     */
    public static function match(?string $name, array $refs): array
    {
        $needle = self::normalize((string) $name);
        if ($needle === '') {
            return ['status' => 'none', 'best' => null, 'candidates' => []];
        }
        $scored = [];
        foreach ($refs as $r) {
            $hay = self::normalize($r['name']);
            if ($hay === '') {
                continue;
            }
            if ($hay === $needle) {
                $score = 100;
            } elseif (str_contains($hay, $needle) || str_contains($needle, $hay)) {
                $score = 85;
            } else {
                similar_text($needle, $hay, $pct);
                $common = count(array_intersect(explode(' ', $needle), explode(' ', $hay)));
                $score = (int) round(max($pct, $common > 0 ? 50 + 15 * $common : 0));
            }
            if ($score >= 60) {
                $scored[] = ['id' => (int) $r['id'], 'name' => $r['name'], 'score' => min(100, $score)];
            }
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        $scored = array_slice($scored, 0, 5);
        if (!$scored) {
            return ['status' => 'none', 'best' => null, 'candidates' => []];
        }
        // Sûr : score élevé et nettement devant le suivant (un homonyme proche, même moins bien noté, impose un choix)
        $clear = $scored[0]['score'] >= 85 && (!isset($scored[1]) || $scored[0]['score'] - $scored[1]['score'] >= 20);
        return ['status' => $clear ? 'matched' : 'ambiguous', 'best' => $clear ? $scored[0]['id'] : null, 'candidates' => $scored];
    }
}
