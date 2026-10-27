<?php

declare(strict_types=1);

namespace App\Services\MasterData;

use App\Domain\DomainException;

/**
 * Lecteur .xlsx minimal (ZipArchive + SimpleXML, sans dépendance) : valeurs brutes des cellules, onglet par onglet.
 * Les nombres sont rendus tels que stockés (« 1.08 », numéro de série pour les dates) ; les formules, par leur dernière valeur calculée.
 */
final class XlsxReader
{
    /** @var array<string,string> nom d'onglet → chemin de la feuille dans l'archive */
    private array $sheets = [];
    /** @var list<string> */
    private array $strings = [];

    public function __construct(private readonly string $path)
    {
        $zip = $this->open();
        try {
            $shared = $zip->getFromName('xl/sharedStrings.xml');
            if ($shared !== false) {
                foreach ($this->xml($shared)->si as $si) {
                    $this->strings[] = self::text($si);
                }
            }
            $rels = [];
            foreach ($this->xml((string) $zip->getFromName('xl/_rels/workbook.xml.rels'))->Relationship as $rel) {
                $target = ltrim((string) $rel['Target'], '/');
                $rels[(string) $rel['Id']] = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
            }
            foreach ($this->xml((string) $zip->getFromName('xl/workbook.xml'))->sheets->sheet as $sheet) {
                $rid = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                if (isset($rels[$rid])) {
                    $this->sheets[trim((string) $sheet['name'])] = $rels[$rid];
                }
            }
        } finally {
            $zip->close();
        }
    }

    /** @return list<string> */
    public function sheetNames(): array
    {
        return array_keys($this->sheets);
    }

    /**
     * Lignes d'un onglet : n° de ligne → [lettre de colonne → valeur texte (trim)].
     * @return array<int,array<string,string>>
     */
    public function rows(string $sheet): array
    {
        $zip = $this->open();
        try {
            $xml = $zip->getFromName($this->sheets[$sheet] ?? '');
        } finally {
            $zip->close();
        }
        if ($xml === false) {
            return [];
        }
        $out = [];
        foreach ($this->xml($xml)->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $col = preg_replace('/\d+/', '', (string) $c['r']);
                $value = match ((string) $c['t']) {
                    's' => $this->strings[(int) $c->v] ?? '',
                    'inlineStr' => self::text($c->is),
                    'b' => (string) $c->v === '1' ? 'Oui' : 'Non',
                    default => (string) $c->v,
                };
                $value = trim(str_replace("\u{00A0}", ' ', $value));
                if ($value !== '') {
                    $cells[$col] = $value;
                }
            }
            $out[(int) $row['r']] = $cells;
        }
        return $out;
    }

    private function open(): \ZipArchive
    {
        $zip = new \ZipArchive();
        if ($zip->open($this->path, \ZipArchive::RDONLY) !== true || $zip->locateName('xl/workbook.xml') === false) {
            throw new DomainException('masterdata.error.not_xlsx');
        }
        return $zip;
    }

    private function xml(string $content): \SimpleXMLElement
    {
        $xml = simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false) {
            throw new DomainException('masterdata.error.not_xlsx');
        }
        return $xml;
    }

    /** Texte d'une chaîne partagée ou en ligne (y compris texte enrichi en plusieurs « runs »). */
    private static function text(\SimpleXMLElement $node): string
    {
        if (isset($node->t)) {
            return (string) $node->t;
        }
        $s = '';
        foreach ($node->r as $run) {
            $s .= (string) $run->t;
        }
        return $s;
    }
}
