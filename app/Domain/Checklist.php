<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Règles de la check-list documentaire (pures, sans base de données).
 *
 * - une règle s'applique si chacun de ses critères est vide ou correspond au contexte (les règles se cumulent) ;
 * - plusieurs règles sur le même type de document fusionnent : obligatoire si l'une l'est, étape bloquante la plus précoce ;
 * - un document est « satisfait » s'il est validé (contrôle à deux niveaux), dérogé ou non applicable, et non expiré ;
 * - une étape est bloquée par tout document obligatoire non satisfait dont l'étape bloquante est atteinte.
 */
final class Checklist
{
    /** Ordre des étapes du flux d'une expédition. */
    public const STAGES = ['departure', 'reception', 'invoicing', 'closure'];
    public const MODES = ['local_purchase', 'import', 'stock'];
    public const TRANSPORTS = ['road', 'rail', 'sea', 'air', 'multimodal'];
    public const SATISFIED = ['validated', 'waived', 'not_applicable'];

    /**
     * @param array{fulfillment_mode:?string,family_id:?int,transport_mode:?string} $rule
     * @param array{mode:string,family_ids:list<int>,transport_modes:list<string>} $ctx
     */
    public static function matches(array $rule, array $ctx): bool
    {
        return ($rule['fulfillment_mode'] === null || $rule['fulfillment_mode'] === $ctx['mode'])
            && ($rule['family_id'] === null || in_array((int) $rule['family_id'], $ctx['family_ids'], true))
            && ($rule['transport_mode'] === null || in_array($rule['transport_mode'], $ctx['transport_modes'], true));
    }

    /**
     * Check-list attendue : type de document → exigence fusionnée.
     * @param list<array<string,mixed>> $rules règles actives (avec document_type_id, required, blocking_stage)
     * @return array<int,array{required:bool,blocking_stage:?string}>
     */
    public static function expected(array $rules, array $ctx): array
    {
        $out = [];
        foreach ($rules as $rule) {
            if (!self::matches($rule, $ctx)) {
                continue;
            }
            $type = (int) $rule['document_type_id'];
            $stage = $rule['blocking_stage'] ?: null;
            $prev = $out[$type] ?? ['required' => false, 'blocking_stage' => null];
            $out[$type] = [
                'required' => $prev['required'] || (bool) $rule['required'],
                'blocking_stage' => self::earliest($prev['blocking_stage'], $stage),
            ];
        }
        return $out;
    }

    /**
     * Check-list expliquée (simulateur) : exigence fusionnée + règles qui l'ont produite.
     * @param list<array<string,mixed>> $rules règles actives (avec id)
     * @return array<int,array{required:bool,blocking_stage:?string,rule_ids:list<int>}>
     */
    public static function explain(array $rules, array $ctx): array
    {
        $out = self::expected($rules, $ctx);
        foreach ($out as $type => $req) {
            $out[$type]['rule_ids'] = [];
        }
        foreach ($rules as $rule) {
            if (self::matches($rule, $ctx)) {
                $out[(int) $rule['document_type_id']]['rule_ids'][] = (int) $rule['id'];
            }
        }
        return $out;
    }

    /** Exigence d'une règle, en un seul niveau : optional | required | blocking. */
    public static function requirement(array $rule): string
    {
        if (!$rule['required']) {
            return 'optional';
        }
        return $rule['blocking_stage'] ? 'blocking' : 'required';
    }

    /**
     * Autres règles actives sur le même document pouvant s'appliquer en même temps que $rule
     * (chaque critère : identique, ou « Tous » d'un côté). « duplicate » = critères strictement identiques.
     * @param list<array<string,mixed>> $rules
     * @return list<array{id:int,kind:string}>
     */
    public static function overlaps(array $rule, array $rules): array
    {
        $out = [];
        $crit = ['fulfillment_mode', 'family_id', 'transport_mode'];
        foreach ($rules as $other) {
            if ((int) $other['document_type_id'] !== (int) $rule['document_type_id'] || empty($other['active'])
                || (isset($rule['id']) && (int) $other['id'] === (int) $rule['id'])) {
                continue;
            }
            $same = true;
            $compatible = true;
            foreach ($crit as $c) {
                $a = ($rule[$c] ?? null) === '' ? null : ($rule[$c] ?? null);
                $b = ($other[$c] ?? null) === '' ? null : ($other[$c] ?? null);
                if ((string) $a !== (string) $b) {
                    $same = false;
                    if ($a !== null && $b !== null) {
                        $compatible = false;
                    }
                }
            }
            if ($compatible) {
                $out[] = ['id' => (int) $other['id'], 'kind' => $same ? 'duplicate' : 'overlap'];
            }
        }
        return $out;
    }

    /**
     * Un document fourni par le transitaire ne se dépose qu'une fois l'agence en douane désignée
     * (dérogation et « non applicable » restent possibles : ce sont des décisions, pas des dépôts).
     * @param array{provider:string,agency_ok:mixed} $doc
     */
    public static function needsAgency(array $doc): bool
    {
        return $doc['provider'] === 'forwarder' && !(bool) $doc['agency_ok'];
    }

    public static function earliest(?string $a, ?string $b): ?string
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }
        return array_search($a, self::STAGES, true) <= array_search($b, self::STAGES, true) ? $a : $b;
    }

    /** @param array{status:string,expires_at:?string} $doc */
    public static function isExpired(array $doc, string $today): bool
    {
        return !empty($doc['expires_at']) && $doc['expires_at'] < $today;
    }

    /** @param array{status:string,expires_at:?string} $doc */
    public static function isSatisfied(array $doc, string $today): bool
    {
        return in_array($doc['status'], self::SATISFIED, true)
            && !($doc['status'] === 'validated' && self::isExpired($doc, $today));
    }

    /**
     * Documents qui bloquent l'étape $stage (toute étape bloquante antérieure ou égale compte).
     * @param list<array<string,mixed>> $docs
     * @return list<array<string,mixed>>
     */
    public static function blockers(array $docs, string $stage, string $today): array
    {
        $limit = array_search($stage, self::STAGES, true);
        return array_values(array_filter($docs, function (array $d) use ($limit, $today) {
            if (!$d['required'] || $d['blocking_stage'] === null || self::isSatisfied($d, $today)) {
                return false;
            }
            return array_search($d['blocking_stage'], self::STAGES, true) <= $limit;
        }));
    }

    /**
     * Avancement : documents obligatoires satisfaits / obligatoires.
     * @param list<array<string,mixed>> $docs
     * @return array{done:int,total:int}
     */
    public static function progress(array $docs, string $today): array
    {
        $required = array_filter($docs, fn ($d) => (bool) $d['required']);
        return [
            'done' => count(array_filter($required, fn ($d) => self::isSatisfied($d, $today))),
            'total' => count($required),
        ];
    }
}
