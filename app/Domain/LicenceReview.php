<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Contrôles de cohérence d'une licence avant création (import de document ET saisie manuelle).
 * Niveau « error » : bloque la confirmation ; « warning » : à vérifier, n'empêche pas la création.
 */
final class LicenceReview
{
    public const REQUIRED = ['po_id', 'number', 'issued_at', 'expires_at', 'currency'];

    /**
     * @param array<string,mixed> $data champs de la fiche (po_id, number, issued_at, expires_at, currency, amount…)
     * @param list<array{description:?string,quantity:?float,unit_price:?float,amount:?float}> $lines
     * @param array{today:string,number_taken:bool,po_has_licence:bool,po_amount:?float,po_currency:?string,same_file_licence:?string,model?:?string} $ctx
     * @return list<array{level:string,code:string,field:?string,params:array<string,string|int|float>}>
     */
    public static function issues(array $data, array $lines, array $ctx): array
    {
        $out = [];
        $add = function (string $level, string $code, ?string $field = null, array $params = []) use (&$out) {
            $out[] = ['level' => $level, 'code' => $code, 'field' => $field, 'params' => $params];
        };

        foreach (self::REQUIRED as $f) {
            if (($data[$f] ?? '') === '' || $data[$f] === null) {
                $add('error', 'required', $f);
            }
        }
        // Licence IB : le n° de licence est le n° de validation bancaire (DEC…), pas le n° COD
        if (($ctx['model'] ?? null) === 'ib' && LicenceTextParser::normalizeCod((string) ($data['number'] ?? '')) !== null) {
            $add('warning', 'ib_number_is_cod', 'number');
        }
        $issued = self::date($data['issued_at'] ?? null);
        $expires = self::date($data['expires_at'] ?? null);
        if (($data['issued_at'] ?? '') !== '' && $issued === null) {
            $add('error', 'invalid_date', 'issued_at');
        }
        if (($data['expires_at'] ?? '') !== '' && $expires === null) {
            $add('error', 'invalid_date', 'expires_at');
        }
        if ($issued && $expires && $expires < $issued) {
            $add('error', 'expiry_before_issue', 'expires_at');
        }
        if ($issued && $issued > $ctx['today']) {
            $add('warning', 'issued_in_future', 'issued_at');
        }
        if ($expires && $expires < $ctx['today']) {
            $add('warning', 'already_expired', 'expires_at');
        }
        if ($ctx['number_taken']) {
            $add('error', 'duplicate_number', 'number', ['number' => (string) ($data['number'] ?? '')]);
        }
        if ($ctx['po_has_licence']) {
            $add('error', 'po_has_licence', 'po_id');
        }
        if ($ctx['same_file_licence'] !== null) {
            $add('warning', 'same_file', null, ['number' => $ctx['same_file_licence']]);
        }

        $amount = is_numeric($data['amount'] ?? null) ? (float) $data['amount'] : null;
        if ($amount !== null && $amount < 0) {
            $add('error', 'negative_amount', 'amount');
        }
        // Lignes : quantité × prix = montant ; somme des lignes = valeur de la licence
        $sum = 0.0;
        $hasAmounts = false;
        foreach (array_values($lines) as $i => $l) {
            if (trim((string) ($l['description'] ?? '')) === '') {
                $add('error', 'line_description', 'lines', ['n' => $i + 1]);
            }
            $q = $l['quantity'] ?? null;
            $p = $l['unit_price'] ?? null;
            $a = $l['amount'] ?? null;
            if ($q !== null && $q < 0 || $p !== null && $p < 0 || $a !== null && $a < 0) {
                $add('error', 'line_negative', 'lines', ['n' => $i + 1]);
            }
            if ($q !== null && $p !== null && $a !== null && abs($q * $p - $a) > max(0.01, $a * 0.005)) {
                $add('warning', 'line_total', 'lines', ['n' => $i + 1, 'expected' => round($q * $p, 2)]);
            }
            if ($a !== null) {
                $hasAmounts = true;
                $sum += $a;
            }
        }
        if ($hasAmounts && $amount !== null && abs($sum - $amount) > max(0.01, $amount * 0.005)) {
            $add('warning', 'lines_sum', 'amount', ['sum' => round($sum, 2), 'amount' => $amount]);
        }
        // Rapprochement avec le PO fournisseur couvert
        if ($ctx['po_currency'] !== null && ($data['currency'] ?? '') !== '' && $data['currency'] !== $ctx['po_currency']) {
            $add('warning', 'currency_differs', 'currency', ['po' => $ctx['po_currency']]);
        }
        if ($ctx['po_amount'] !== null && $amount !== null && $ctx['po_amount'] > 0 && abs($amount - $ctx['po_amount']) > $ctx['po_amount'] * 0.01) {
            $add('warning', 'amount_differs', 'amount', ['po' => round($ctx['po_amount'], 2)]);
        }
        return $out;
    }

    public static function hasErrors(array $issues): bool
    {
        return (bool) array_filter($issues, fn ($i) => $i['level'] === 'error');
    }

    private static function date(mixed $v): ?string
    {
        $v = (string) $v;
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v ? $v : null;
    }
}
