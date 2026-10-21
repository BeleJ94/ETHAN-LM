<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Analyse du texte d'une licence d'importation (pure, sans base de données ni fichier).
 *
 * Règle d'or : ne rien inventer. Chaque champ a un statut :
 *  - extracted : trouvé sans ambiguïté après un libellé reconnu ;
 *  - uncertain : déduit (sans libellé), ambigu (plusieurs valeurs différentes) ou mal formé (date invalide…) ;
 *  - missing   : absent du document.
 * Le « source » conserve l'extrait de texte d'où provient la valeur, pour que l'utilisateur vérifie.
 */
final class LicenceTextParser
{
    public const FIELDS = ['number', 'issued_at', 'expires_at', 'origin_country', 'currency', 'amount', 'bank', 'supplier_name', 'importer_name', 'po_reference'];

    private const MONTHS = [
        'janvier' => 1, 'janv' => 1, 'january' => 1, 'jan' => 1, 'février' => 2, 'fevrier' => 2, 'févr' => 2, 'fevr' => 2, 'february' => 2, 'feb' => 2,
        'mars' => 3, 'march' => 3, 'mar' => 3, 'avril' => 4, 'april' => 4, 'avr' => 4, 'apr' => 4, 'mai' => 5, 'may' => 5,
        'juin' => 6, 'june' => 6, 'jun' => 6, 'juillet' => 7, 'juil' => 7, 'july' => 7, 'jul' => 7, 'août' => 8, 'aout' => 8, 'august' => 8, 'aug' => 8,
        'septembre' => 9, 'september' => 9, 'sept' => 9, 'sep' => 9, 'octobre' => 10, 'october' => 10, 'oct' => 10,
        'novembre' => 11, 'november' => 11, 'nov' => 11, 'décembre' => 12, 'decembre' => 12, 'december' => 12, 'déc' => 12, 'dec' => 12,
    ];

    /** Libellés reconnus (FR / EN) par champ ; la valeur suit le libellé sur la même ligne. */
    private const LABELS = [
        'number' => '(?:licence|license)\s*(?:d[\'’]\s*importation|of\s+import|import)?\s*(?:n[°ºo]\.?|no\.?|num[ée]ro|number|#)\s*[:\-]?',
        'issued_at' => '(?:date\s+d[\'’]\s*[ée]mission|date\s+de\s+d[ée]livrance|d[ée]livr[ée]e?\s+le|[ée]mise?\s+le|date\s+of\s+issue|issue\s+date|issued\s+on)\s*[:\-]?',
        'expires_at' => '(?:date\s+d[\'’]\s*expiration|date\s+de\s+validit[ée]|valable\s+jusqu[\'’]\s*au|expire\s+le|expiry\s+date|expiration\s+date|valid\s+until)\s*[:\-]?',
        'origin_country' => '(?:pays\s+d[\'’]\s*origine|pays\s+de\s+provenance|provenance|country\s+of\s+origin|origin)\s*[:\-]',
        'bank' => '(?:banque\s+domiciliataire|banque\s+agr[ée][ée]e|banque|domiciliation|bank)\s*[:\-]',
        'supplier_name' => '(?:fournisseur|exportateur|vendeur|supplier|exporter|seller|shipper)\s*[:\-]',
        'importer_name' => '(?:importateur|importer|consignee|destinataire)\s*[:\-]',
        'amount' => '(?:valeur\s+(?:totale\s+)?(?:fob|cif|cfr|autoris[ée]e|totale|globale)|montant\s+(?:total|autoris[ée])|valeur|total\s+value|fob\s+value|cif\s+value|amount)\s*(?:\(([A-Z]{3})\))?\s*[:\-]?',
        'po_reference' => '(?:bon\s+de\s+commande|commande|proforma|facture\s+proforma|purchase\s+order|p\.?\s*o\.?)\s*(?:n[°ºo]\.?|no\.?|num[ée]ro|ref\.?|#)?\s*[:\-]',
    ];

    /**
     * @param list<string> $currencies codes ISO connus (référentiel)
     * @return array{fields:array<string,array{value:mixed,status:string,source:?string}>,lines:list<array<string,mixed>>,warnings:list<string>}
     */
    public static function parse(string $text, array $currencies = ['USD', 'EUR', 'CDF', 'ZAR', 'CNY', 'AED']): array
    {
        $text = self::normalize($text);
        $lines = preg_split('/\n/', $text) ?: [];
        $fields = array_fill_keys(self::FIELDS, ['value' => null, 'status' => 'missing', 'source' => null]);
        $warnings = [];

        // Champs texte simples (valeur jusqu'à la fin de la ligne)
        foreach (['number', 'origin_country', 'bank', 'supplier_name', 'importer_name', 'po_reference'] as $f) {
            $found = self::afterLabel($lines, self::LABELS[$f]);
            if ($found) {
                $values = array_values(array_unique(array_map(fn ($x) => self::clean($f, $x[0]), $found)));
                $values = array_values(array_filter($values, fn ($v) => $v !== ''));
                if ($values) {
                    $fields[$f] = ['value' => $values[0], 'status' => count($values) === 1 ? 'extracted' : 'uncertain', 'source' => $found[0][1]];
                }
            }
        }
        // N° de licence sans libellé complet : motif typique « LIC-… » / « LI… » → incertain
        if ($fields['number']['status'] === 'missing' && preg_match('/\b(LIC[\-\/ ]?(?=[A-Z0-9\-\/\.]*\d)[A-Z0-9][A-Z0-9\-\/\.]{3,})\b/i', $text, $m)) {
            $fields['number'] = ['value' => strtoupper(str_replace(' ', '-', $m[1])), 'status' => 'uncertain', 'source' => $m[0]];
        }
        // Référence de PO au format ETHAN (PO-AAAA-NNNNNN) n'importe où dans le document
        if ($fields['po_reference']['status'] === 'missing' && preg_match('/\b(PO[\-\s]?\d{4}[\-\s]?\d{3,6})\b/i', $text, $m)) {
            $fields['po_reference'] = ['value' => strtoupper(preg_replace('/\s+/', '-', $m[1])), 'status' => 'uncertain', 'source' => $m[0]];
        }

        // Dates (avec libellé) puis repli sans libellé
        foreach (['issued_at', 'expires_at'] as $f) {
            foreach (self::afterLabel($lines, self::LABELS[$f]) as [$raw, $src]) {
                $date = self::parseDate($raw);
                if ($date === null) {
                    $fields[$f] = ['value' => null, 'status' => 'uncertain', 'source' => $src];
                    $warnings[] = 'invalid_date_' . $f;
                    continue;
                }
                $fields[$f] = $fields[$f]['status'] === 'extracted' && $fields[$f]['value'] !== $date
                    ? ['value' => $fields[$f]['value'], 'status' => 'uncertain', 'source' => $fields[$f]['source']]
                    : ['value' => $date, 'status' => 'extracted', 'source' => $src];
                break;
            }
        }
        if ($fields['issued_at']['status'] === 'missing' || $fields['expires_at']['status'] === 'missing') {
            $all = self::allDates($text);
            if (count($all) === 2) {
                sort($all);
                foreach (['issued_at' => $all[0], 'expires_at' => $all[1]] as $f => $d) {
                    if ($fields[$f]['status'] === 'missing') {
                        $fields[$f] = ['value' => $d, 'status' => 'uncertain', 'source' => null];
                    }
                }
            }
        }

        // Montant et devise
        foreach (self::afterLabel($lines, self::LABELS['amount'], true) as [$raw, $src, $labelCur]) {
            [$amount, $cur] = self::parseAmount($raw, $currencies);
            if ($amount === null) {
                continue;
            }
            $fields['amount'] = ['value' => $amount, 'status' => 'extracted', 'source' => $src];
            $cur = $cur ?? ($labelCur && in_array(strtoupper($labelCur), $currencies, true) ? strtoupper($labelCur) : null);
            if ($cur !== null) {
                $fields['currency'] = ['value' => $cur, 'status' => 'extracted', 'source' => $src];
            }
            break;
        }
        if ($fields['currency']['status'] === 'missing') {
            $label = self::afterLabel($lines, '(?:devise|monnaie|currency)\s*[:\-]');
            if ($label && in_array(strtoupper(substr(trim($label[0][0]), 0, 3)), $currencies, true)) {
                $fields['currency'] = ['value' => strtoupper(substr(trim($label[0][0]), 0, 3)), 'status' => 'extracted', 'source' => $label[0][1]];
            } else {
                $codes = [];
                foreach ($currencies as $c) {
                    if (preg_match('/\b' . $c . '\b/', $text)) {
                        $codes[] = $c;
                    }
                }
                if (count($codes) === 1) {
                    $fields['currency'] = ['value' => $codes[0], 'status' => 'uncertain', 'source' => null];
                } elseif (count($codes) > 1) {
                    $warnings[] = 'several_currencies';
                }
            }
        }

        $items = self::parseLines($lines);
        $model = null;
        $extras = [];
        // Modèle reconnu : ses règles dédiées priment sur la lecture générique
        if (self::isIb($text)) {
            $model = 'ib';
            [$ibFields, $items, $extras, $ibWarnings] = self::parseIb($lines, $currencies);
            $fields = array_merge($fields, $ibFields);
            $warnings = array_merge($warnings, $ibWarnings);
        }
        return ['fields' => $fields, 'lines' => $items, 'warnings' => array_values(array_unique($warnings)), 'model' => $model, 'extras' => $extras];
    }

    /** N° COD dans un texte : « COD 2024 214576 », « COD-2024-214576 », « COD2024214576 », « C0D 2024 214576 ». */
    private const COD_RE = '/C[O0]D[\s\-_.\/]{0,3}((?:19|20)\d{2})[\s\-_.\/]{0,3}(\d{4,8})(?!\d)/i';

    /** Format officiel du n° de licence IB. */
    public const COD_FORMAT = '/^COD \d{4} \d{4,8}$/';

    /** Saisie libre → « COD AAAA NNNNNN » si c'est un n° COD (sinon null : valeur laissée telle quelle). */
    public static function normalizeCod(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        return preg_match('/^' . trim(self::COD_RE, '/i') . '$/i', $raw, $m) ? 'COD ' . $m[1] . ' ' . $m[2] : null;
    }

    /** Modèle IB « Déclaration d'Importation des Biens » (licence RDC validée par la banque agréée). */
    public static function isIb(string $text): bool
    {
        return (bool) preg_match('/MODELE\s+IB\b|D[ée]claration\s+d[\'’]\s*Importation\s+des\s+Biens/iu', $text);
    }

    /**
     * Lecture dédiée du modèle IB : rubriques numérotées sans « : », valeurs parfois collées au libellé,
     * produits sur plusieurs lignes. Les nombres y sont au format point décimal (« 1.000 » = 1).
     * @param list<string> $lines
     * @return array{0:array<string,array{value:mixed,status:string,source:?string}>,1:list<array<string,mixed>>,2:array<string,string>,3:list<string>}
     */
    private static function parseIb(array $lines, array $currencies): array
    {
        $fields = [];
        $extras = [];
        $warnings = [];
        $text = implode("\n", $lines);
        $set = function (string $f, mixed $v, ?string $src, string $status = 'extracted') use (&$fields) {
            if ($v !== null && $v !== '') {
                $fields[$f] = ['value' => $v, 'status' => $status, 'source' => $src !== null ? trim($src) : null];
            }
        };
        $find = function (string $re) use ($lines): ?array {
            foreach ($lines as $l) {
                if (preg_match($re, $l, $m)) {
                    return [$m, $l];
                }
            }
            return null;
        };
        $dot = fn (string $n) => is_numeric($n = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', ''], trim($n))) ? (float) $n : null;

        // N° COD (bandeau, parfois vide ou collé au texte voisin) : information complémentaire, au format « COD AAAA NNNNNN »
        if (preg_match_all(self::COD_RE, $text, $m, PREG_SET_ORDER)) {
            $extras['cod'] = implode(' / ', array_values(array_unique(array_map(fn ($x) => 'COD ' . $x[1] . ' ' . $x[2], $m))));
        }
        if ($r = $find('/^\s*Banque\s+(?!agr)([^\n]+?)\s*$/iu')) {
            $set('bank', trim($r[0][1]), $r[1]);
        }
        if ($r = $find('/^\s*Agence\s+Banque\s+(.+)$/iu')) {
            $extras['agency'] = trim($r[0][1]);
        }
        // N° de licence IB = « N° Validation » attribué par la banque agréée (ex. DEC1711498-40CD-IB)
        $vals = [];
        foreach ($lines as $l) {
            if (preg_match('/N[°º]\s*Validation\s*[:\-]?\s*(DEC[A-Z0-9]+(?:-[A-Z0-9]+)*|[A-Z0-9][A-Z0-9\-]{5,})/iu', $l, $m)) {
                $vals[strtoupper($m[1])] = $l;
            }
        }
        if (!$vals && preg_match_all('/\b(DEC\d{5,}(?:-[A-Z0-9]+)*-IB)\b/i', $text, $m)) {
            // Libellé non retrouvé (mise en page éclatée) : motif DEC…-IB seul → à vérifier
            foreach (array_unique($m[1]) as $v) {
                $vals[strtoupper($v)] = null;
            }
            $set('number', array_key_first($vals), null, count($vals) === 1 ? 'uncertain' : 'uncertain');
        } elseif ($vals) {
            $set('number', array_key_first($vals), reset($vals), count($vals) === 1 ? 'extracted' : 'uncertain');
        }
        if (count($vals) > 1) {
            $warnings[] = 'validation_several';
        }
        if ($r = $find('/Je\s+soussign[ée]\s+(.+?)\s*(?:\(([A-Z]{2,4}\d+)\))?\s*$/iu')) {
            $set('importer_name', trim($r[0][1]), $r[1]);
        }
        if ($r = $find('/Poste\s+douanier\s+d[\'’]\s*Entr[ée]e\s+(.+)$/iu')) {
            $extras['customs_post'] = trim($r[0][1]);
        }
        if ($r = $find('/Pays\s+de\s+provenance\s+(.+)$/iu')) {
            $set('origin_country', trim($r[0][1]), $r[1]);
        }
        if ($r = $find('/Nom\s+du\s+fournisseur\s+Etranger\s+(.+)$/iu')) {
            $set('supplier_name', trim($r[0][1], " .\t"), $r[1]);
        }
        if ($r = $find('/Monnaie\s+de\s+la\s+transaction\s+([A-Z]{3})\b/u')) {
            $set('currency', $r[0][1], $r[1], in_array($r[0][1], $currencies, true) ? 'extracted' : 'uncertain');
        }
        // « 5) Montant FOB total à payer / CIF total à payer 147555.56 / 164625.56 » : valeur de la licence = FOB
        if ($r = $find('/Montant\s+FOB.*?([\d][\d .,]*\d)\s*\/\s*([\d][\d .,]*\d)\s*$/iu')) {
            $set('amount', self::parseNumber($r[0][1]), $r[1]);
            $extras['cif'] = $r[0][2];
        }
        foreach (['freight' => 'Fret', 'insurance' => 'Assurances', 'other_costs' => 'Autres\s+frais'] as $k => $label) {
            if ($r = $find('/' . $label . '\s+([\d][\d .,]*)\s*([A-Z]{3})?/iu')) {
                $extras[$k] = trim($r[0][1]) . (isset($r[0][2]) ? ' ' . $r[0][2] : '');
            }
        }
        if ($r = $find('/Modalit[ée].*?paiement\s+(.+)$/iu')) {
            $extras['payment'] = trim($r[0][1]);
        }
        // Dates : validation (émission) et date extrême de validité (expiration), libellé parfois collé à la valeur
        foreach (['issued_at' => 'Date\s+de\s+validation', 'expires_at' => 'Date\s+extr[êe]me\s+de\s+validit[ée]'] as $f => $label) {
            if ($r = $find('/' . $label . '\s*(\d{1,2}(?:er)?\s+[A-Za-zéû]+\.?\s+\d{4}|\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{4})/iu')) {
                $d = self::parseDate($r[0][1]);
                $d !== null ? $set($f, $d, $r[1]) : $warnings[] = 'invalid_date_' . $f;
            }
        }
        // Facture : valeur sur la même ligne (mise en colonnes) ou n° puis date sous les deux libellés (flux simple)
        if ($r = $find('/N[°º]\s*Facture\s+(\S[^\n]*?)\s*$/iu')) {
            $extras['invoice'] = $r[0][1];
        }
        if (($r = $find('/Date\s+Facture\s+(\S[^\n]*?)\s*$/iu')) && ($d = self::parseDate($r[0][1])) !== null) {
            $extras['invoice_date'] = $d;
        }
        foreach ($lines as $i => $l) {
            if (preg_match('/^\s*N[°º]\s*Facture\s*$/iu', $l) && preg_match('/^\s*Date\s+Facture\s*$/iu', $lines[$i + 1] ?? '')) {
                $extras['invoice'] = trim($lines[$i + 2] ?? '');
                $d = self::parseDate($lines[$i + 3] ?? '');
                if ($d !== null) {
                    $extras['invoice_date'] = $d;
                }
            }
        }
        if ($r = $find('/CATEGORIE\s*:\s*(.+?)(?=COD\s?\d{4}|$)/iu')) {
            $extras['category'] = trim($r[0][1]);
        }
        // Régime : « 4-Mise à la consommation » + « définitive », placés autour du libellé selon la mise en page
        foreach ($lines as $i => $l) {
            if (preg_match('/(?:^|\s)(\d{1,2}-(?:Mise|Admission|Entrep|Transit|R[ée]export|R[ée]import|Perfectionnement|Exportation)[^\n]*?)(?:\s{2,}|$)/iu', $l, $m)) {
                $val = trim($m[1]);
                for ($k = $i + 1; $k <= $i + 3 && isset($lines[$k]); $k++) {
                    $chunk = trim((string) preg_split('/\s{2,}/', trim($lines[$k]))[0]);
                    if (preg_match('/^(?:Date\s+de\s+validation|10\.4)/iu', $chunk)) {
                        $chunk = trim((string) (preg_split('/\s{2,}/', trim($lines[$k]))[1] ?? ''));
                        if (!preg_match('/^[a-zé]/u', $chunk)) {
                            continue;
                        }
                    }
                    if (preg_match('/^[a-zé][a-zé ]*$/u', $chunk)) {
                        $val .= ' ' . $chunk;
                        break;
                    }
                }
                $extras['regime'] = preg_replace('/\s+/', ' ', $val) ?? $val;
                break;
            }
        }


        // Produits : « code tarifaire + désignation » sur plusieurs lignes, puis « pays quantité », puis « unité prix devise »
        $items = [];
        $start = null;
        foreach ($lines as $i => $l) {
            if (preg_match('/Liste\s+Des\s+Produits|^\s*Produits?\s*$|^\s*Tarif\s+Douanier\s+D[ée]signation/iu', $l)) {
                $start = $i + 1;
                break;
            }
        }
        if ($start !== null) {
            $cur = null;
            $flush = function () use (&$cur, &$items, &$warnings) {
                if ($cur === null) {
                    return;
                }
                $ok = $cur['quantity'] !== null && $cur['unit_price'] !== null;
                if ($ok) {
                    // Montant absent du modèle : calculé (quantité × prix), signalé à l'utilisateur
                    $cur['amount'] = round($cur['quantity'] * $cur['unit_price'], 2);
                    $warnings[] = 'line_amount_computed';
                }
                $cur['description'] = trim(preg_replace('/\s+/', ' ', $cur['description']) ?? '');
                $cur['status'] = $ok ? 'extracted' : 'uncertain';
                unset($cur['currency'], $cur['open']);
                $items[] = $cur;
                $cur = null;
            };
            for ($i = $start; $i < count($lines); $i++) {
                $l = trim($lines[$i]);
                if ($l === '' || preg_match('/^Tarif\s+Douanier/iu', $l)) {
                    continue;
                }
                if (preg_match('/^(?:Tir[ée]\s+le|CATEGORIE)/iu', $l)) {
                    break;
                }
                // Mise en colonnes : toute la ligne produit d'un coup, la désignation continue sur les lignes suivantes
                if (preg_match('/^(\d{8,10})\s{2,}(.+?)\s{2,}([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ \-\']*?)\s{2,}(\d[\d.,]*)\s+(\S.*?)\s{2,}(\d[\d.,]*)\s+([A-Z]{3})$/u', $l, $m)) {
                    $flush();
                    $cur = ['description' => $m[2], 'hs_code' => $m[1], 'quantity' => $dot($m[4]), 'unit' => self::ibUnit($m[5]),
                        'unit_price' => $dot($m[6]), 'amount' => null, 'source' => $l, 'currency' => $m[7], 'open' => true];
                    continue;
                }
                if ($cur !== null && !empty($cur['open'])) {
                    $cur['description'] .= ' ' . trim((string) preg_split('/\s{2,}/', $l)[0]);
                    continue;
                }
                if (preg_match('/^(\d{8,10})\s+(.*)$/u', $l, $m)) {
                    $flush();
                    $cur = ['description' => $m[2], 'hs_code' => $m[1], 'quantity' => null, 'unit' => null, 'unit_price' => null, 'amount' => null, 'source' => $l];
                    continue;
                }
                if ($cur === null) {
                    continue;
                }
                if ($cur['quantity'] === null && preg_match('/^([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ \-\']*?)\s+(\d[\d.,]*)\s*$/u', $l, $m)) {
                    $cur['quantity'] = $dot($m[2]);
                    $cur['origin'] = trim($m[1]);
                    $cur['source'] .= ' … ' . $l;
                    continue;
                }
                if ($cur['quantity'] !== null && preg_match('/^(\S.*?)\s+(\d[\d.,]*)\s*([A-Z]{3})\s*$/u', $l, $m)) {
                    $cur['unit'] = self::ibUnit($m[1]);
                    $cur['unit_price'] = $dot($m[2]);
                    $cur['currency'] = $m[3];
                    $cur['source'] .= ' … ' . $l;
                    $flush();
                    continue;
                }
                $cur['description'] .= ' ' . $l;
            }
            $flush();
        }
        foreach ($items as &$it) {
            unset($it['origin']);
        }
        unset($it);
        return [$fields, $items, $extras, $warnings];
    }

    /** « Unite(Piece) » → PCE, « Kilogramme » → KG, etc. ; sinon libellé d'origine (20 car.). */
    private static function ibUnit(string $raw): string
    {
        $u = mb_strtolower($raw);
        return match (true) {
            str_contains($u, 'piece') || str_contains($u, 'pièce') => 'PCE',
            str_contains($u, 'kilo') || $u === 'kg' => 'KG',
            str_contains($u, 'tonne') || $u === 't' => 'T',
            str_contains($u, 'litre') || $u === 'l' => 'L',
            default => mb_substr(trim($raw), 0, 20),
        };
    }

    /**
     * Lignes de marchandises : « désignation [code SH] quantité unité [prix unitaire] [montant] ».
     * Statut « uncertain » si quantité × prix ≠ montant.
     * @param list<string> $lines
     * @return list<array<string,mixed>>
     */
    public static function parseLines(array $lines): array
    {
        // Nombre : un seul espace (milliers) suivi d'un chiffre ; deux espaces ou plus = changement de colonne
        $num = '\d(?:[\d.,]|[ \x{202F}\x{00A0}](?=\d))*';
        $unit = '(?:T|TM|TN|TONNES?|TONS?|KG|KGS|KILOS?|L|LT|LITRES?|LITERS?|M3|M³|PCE|PCS|PI[ÈE]CES?|UNIT[ÉE]S?|SACS?|CTN|CARTONS?|FUTS?|DRUMS?|BAGS?)';
        $re = '/^\s*(?:\d{1,3}[\.\)]\s+)?(?<desc>[A-Za-zÀ-ÿ][^\n]*?)\s+(?:(?<hs>\d{4}\.\d{2}(?:\.\d{2,4})?|\d{8,10})\s+)?(?<qty>' . $num . ')\s*(?<unit>' . $unit . ')\b\s*(?:(?<price>' . $num . ')\s+(?<amount>' . $num . '))?\s*$/iu';
        $out = [];
        foreach ($lines as $line) {
            if (!preg_match($re, $line, $m)) {
                continue;
            }
            $qty = self::parseNumber($m['qty']);
            if ($qty === null || $qty <= 0) {
                continue;
            }
            $price = isset($m['price']) && $m['price'] !== '' ? self::parseNumber($m['price']) : null;
            $amount = isset($m['amount']) && $m['amount'] !== '' ? self::parseNumber($m['amount']) : null;
            $consistent = $price === null || $amount === null || abs($qty * $price - $amount) <= max(0.01, $amount * 0.005);
            $out[] = [
                'description' => trim(preg_replace('/\s+/', ' ', $m['desc']) ?? ''),
                'hs_code' => ($m['hs'] ?? '') !== '' ? $m['hs'] : null,
                'quantity' => $qty,
                'unit' => self::normalizeUnit($m['unit']),
                'unit_price' => $price,
                'amount' => $amount,
                'status' => $consistent ? 'extracted' : 'uncertain',
                'source' => trim($line),
            ];
        }
        return $out;
    }

    /** « 12/10/2026 », « 2026-10-12 », « 12.10.2026 », « 12 octobre 2026 », « 12 Oct 2026 » → AAAA-MM-JJ (null si invalide). */
    public static function parseDate(string $raw): ?string
    {
        $raw = trim(mb_strtolower($raw));
        if (preg_match('/\b(\d{4})[\-\/\.](\d{1,2})[\-\/\.](\d{1,2})\b/', $raw, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/\b(\d{1,2})[\-\/\.](\d{1,2})[\-\/\.](\d{4}|\d{2})\b/', $raw, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            $y = $y < 100 ? 2000 + $y : $y;
        } elseif (preg_match('/\b(\d{1,2})(?:er)?\s+([a-zéûô]+)\.?\s+(\d{4})\b/u', $raw, $m) && isset(self::MONTHS[$m[2]])) {
            [$d, $mo, $y] = [(int) $m[1], self::MONTHS[$m[2]], (int) $m[3]];
        } else {
            return null;
        }
        return checkdate($mo, $d, $y) && $y >= 1990 && $y <= 2100 ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /** « 1 234 567,89 », « 1,234,567.89 », « 1.234.567,89 », « 450000 » → float (null si non numérique). */
    public static function parseNumber(string $raw): ?float
    {
        $s = preg_replace('/[\s\x{202F}\x{00A0}]/u', '', trim($raw)) ?? '';
        if ($s === '' || !preg_match('/^\d[\d.,]*$/', $s)) {
            return null;
        }
        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');
        $decimal = null;
        if ($lastComma !== false && $lastDot !== false) {
            $decimal = $lastComma > $lastDot ? ',' : '.';
        } elseif ($lastComma !== false || $lastDot !== false) {
            $sep = $lastComma !== false ? ',' : '.';
            $pos = (int) ($lastComma !== false ? $lastComma : $lastDot);
            $after = strlen($s) - $pos - 1;
            // Un seul séparateur suivi de 3 chiffres = séparateur de milliers (« 450.000 », « 1,500 »)
            $decimal = ($after === 3 && substr_count($s, $sep) >= 1 && $after !== 0) ? null : $sep;
            if (substr_count($s, $sep) > 1) {
                $decimal = null;
            }
        }
        if ($decimal === null) {
            $s = str_replace([',', '.'], '', $s);
        } else {
            $thousands = $decimal === ',' ? '.' : ',';
            $s = str_replace([$thousands], '', $s);
            $s = str_replace($decimal, '.', $s);
        }
        return is_numeric($s) ? (float) $s : null;
    }

    /** @param list<string> $currencies @return array{0:?float,1:?string} */
    public static function parseAmount(string $raw, array $currencies): array
    {
        $cur = null;
        foreach ($currencies as $c) {
            if (preg_match('/\b' . $c . '\b/i', $raw)) {
                $cur = $c;
                break;
            }
        }
        if ($cur === null && str_contains($raw, '$')) {
            $cur = 'USD';
        } elseif ($cur === null && str_contains($raw, '€')) {
            $cur = 'EUR';
        }
        if (!preg_match('/\d[\d \x{202F}\x{00A0}.,]*\d|\d/u', $raw, $m)) {
            return [null, $cur];
        }
        return [self::parseNumber($m[0]), $cur];
    }

    /** @return list<array{0:string,1:string,2:?string}> valeurs trouvées après le libellé : [valeur brute, ligne source, groupe du libellé] */
    private static function afterLabel(array $lines, string $label, bool $withGroup = false): array
    {
        $out = [];
        foreach ($lines as $i => $line) {
            if (!preg_match('/' . $label . '\s*(.*)$/iu', $line, $m)) {
                continue;
            }
            $value = trim(end($m));
            $group = $withGroup && count($m) > 2 ? ($m[1] ?? null) : null;
            // Valeur sur la ligne suivante quand le libellé est seul (mise en page en colonnes)
            if ($value === '' && isset($lines[$i + 1])) {
                $value = trim($lines[$i + 1]);
            }
            if ($value !== '') {
                $out[] = [$value, trim($line), $group ?: null];
            }
        }
        return $out;
    }

    private static function clean(string $field, string $value): string
    {
        $value = trim(preg_replace('/\s{2,}.*$/u', '', $value) ?? $value); // colonne suivante éventuelle
        if ($field === 'number' || $field === 'po_reference') {
            $value = trim($value, " \t:;-–—.,");
            if (!preg_match('/^([A-Z0-9][A-Z0-9\-\/\. ]*[A-Z0-9])/i', $value, $m)) {
                return '';
            }
            return strtoupper(preg_replace('/\s+/', '-', trim($m[1])) ?? '');
        }
        return mb_substr(trim($value, " 	:;-–—,"), 0, 160);
    }

    /** @return list<string> */
    private static function allDates(string $text): array
    {
        preg_match_all('/\b\d{1,2}[\-\/\.]\d{1,2}[\-\/\.]\d{4}\b|\b\d{4}-\d{2}-\d{2}\b|\b\d{1,2}\s+[A-Za-zéû]+\.?\s+\d{4}\b/u', $text, $m);
        $dates = array_values(array_unique(array_filter(array_map([self::class, 'parseDate'], $m[0]))));
        return $dates;
    }

    private static function normalizeUnit(string $unit): string
    {
        $u = mb_strtoupper($unit);
        return match (true) {
            in_array($u, ['T', 'TM', 'TN', 'TONNE', 'TONNES', 'TON', 'TONS'], true) => 'T',
            in_array($u, ['KG', 'KGS', 'KILO', 'KILOS'], true) => 'KG',
            in_array($u, ['L', 'LT', 'LITRE', 'LITRES', 'LITER', 'LITERS'], true) => 'L',
            in_array($u, ['M3', 'M³'], true) => 'M3',
            default => $u,
        };
    }

    private static function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\t", "\f"], ["\n", "\n", ' ', "\n"], $text);
        return preg_replace('/[ ]{3,}/', '   ', $text) ?? $text;
    }
}
