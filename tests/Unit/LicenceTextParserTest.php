<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\LicenceTextParser as P;
use PHPUnit\Framework\TestCase;

final class LicenceTextParserTest extends TestCase
{
    private const LICENCE_FR = <<<TXT
        RÉPUBLIQUE DÉMOCRATIQUE DU CONGO
        LICENCE D'IMPORTATION N° LI-2026/04512
        Date d'émission : 12/10/2026
        Date d'expiration : 11 avril 2027
        Importateur : ETHAN SARL
        Fournisseur : Sasol Chemicals (Pty) Ltd
        Pays d'origine : Afrique du Sud
        Banque domiciliataire : Rawbank
        Proforma N° : PO-2026-000014
        Valeur FOB : 450 000,00 USD

        1. Acide sulfurique 98%  2807.00  1 000 T  450,00  450 000,00
        TXT;

    public function testNativeFrenchLicence(): void
    {
        $r = P::parse(self::LICENCE_FR);
        $f = $r['fields'];
        self::assertSame(['LI-2026/04512', 'extracted'], [$f['number']['value'], $f['number']['status']]);
        self::assertSame('2026-10-12', $f['issued_at']['value']);
        self::assertSame('2027-04-11', $f['expires_at']['value']);
        self::assertSame('Sasol Chemicals (Pty) Ltd', $f['supplier_name']['value']);
        self::assertSame('ETHAN SARL', $f['importer_name']['value']);
        self::assertSame('Afrique du Sud', $f['origin_country']['value']);
        self::assertSame('Rawbank', $f['bank']['value']);
        self::assertSame('PO-2026-000014', $f['po_reference']['value']);
        self::assertSame([450000.0, 'USD'], [$f['amount']['value'], $f['currency']['value']]);
        self::assertSame('extracted', $f['amount']['status']);
        self::assertCount(1, $r['lines']);
        self::assertSame(['Acide sulfurique 98%', '2807.00', 1000.0, 'T', 450.0, 450000.0, 'extracted'],
            array_values(array_intersect_key($r['lines'][0], array_flip(['description', 'hs_code', 'quantity', 'unit', 'unit_price', 'amount', 'status']))));
    }

    public function testNothingIsInventedWhenFieldsAreAbsent(): void
    {
        $r = P::parse("Document quelconque\nSans aucune information utile");
        foreach ($r['fields'] as $name => $field) {
            self::assertSame('missing', $field['status'], $name);
            self::assertNull($field['value'], $name);
        }
        self::assertSame([], $r['lines']);
    }

    public function testInvalidDateIsUncertainNotGuessed(): void
    {
        $r = P::parse("Licence N° LIC-77\nDate d'émission : 31/02/2026");
        self::assertSame(['uncertain', null], [$r['fields']['issued_at']['status'], $r['fields']['issued_at']['value']]);
        self::assertContains('invalid_date_issued_at', $r['warnings']);
    }

    public function testDatesWithoutLabelsAreOnlyUncertain(): void
    {
        $r = P::parse("License No: LX-90\nKinshasa le 01/03/2026 ... jusqu'à 2026-09-01");
        self::assertSame(['2026-03-01', 'uncertain'], [$r['fields']['issued_at']['value'], $r['fields']['issued_at']['status']]);
        self::assertSame(['2026-09-01', 'uncertain'], [$r['fields']['expires_at']['value'], $r['fields']['expires_at']['status']]);
    }

    public function testConflictingValuesAreFlaggedUncertain(): void
    {
        $r = P::parse("Fournisseur : Alpha Ltd\nFournisseur : Beta Ltd");
        self::assertSame(['Alpha Ltd', 'uncertain'], [$r['fields']['supplier_name']['value'], $r['fields']['supplier_name']['status']]);
    }

    public function testLineWithWrongTotalIsUncertain(): void
    {
        $lines = P::parseLines(['Soufre granulé 500 T 200,00 99 000,00']);
        self::assertSame('uncertain', $lines[0]['status']);
    }

    public function testNumberFormats(): void
    {
        self::assertSame(1234567.89, P::parseNumber('1 234 567,89'));
        self::assertSame(1234567.89, P::parseNumber('1,234,567.89'));
        self::assertSame(1234567.89, P::parseNumber('1.234.567,89'));
        self::assertSame(450000.0, P::parseNumber('450.000'));
        self::assertSame(12.5, P::parseNumber('12,5'));
        self::assertNull(P::parseNumber('abc'));
    }

    public function testDateFormats(): void
    {
        self::assertSame('2026-10-12', P::parseDate('12/10/2026'));
        self::assertSame('2026-10-12', P::parseDate('2026-10-12'));
        self::assertSame('2026-10-12', P::parseDate('12 octobre 2026'));
        self::assertSame('2026-10-12', P::parseDate('12 Oct 2026'));
        self::assertSame('2026-08-01', P::parseDate('1er août 2026'));
        self::assertNull(P::parseDate('31/02/2026'));
    }

    public function testEnglishLabels(): void
    {
        $r = P::parse("IMPORT LICENSE NO. IL-55120\nIssue date: 2026-01-05\nExpiry date: 2026-07-05\nSupplier: Jinan Chemical Co.\nCountry of origin: China\nTotal value: EUR 125,000.00");
        $f = $r['fields'];
        self::assertSame('IL-55120', $f['number']['value']);
        self::assertSame(['2026-01-05', '2026-07-05'], [$f['issued_at']['value'], $f['expires_at']['value']]);
        self::assertSame(['China', 'Jinan Chemical Co.'], [$f['origin_country']['value'], $f['supplier_name']['value']]);
        self::assertSame([125000.0, 'EUR'], [$f['amount']['value'], $f['currency']['value']]);
    }

    /** Modèle IB réel (RDC, validé Rawbank) : texte tel que restitué par le PDF natif. */
    public function testIbModel(): void
    {
        $r = P::parse((string) file_get_contents(__DIR__ . '/../Fixtures/licence-ib.txt'));
        $f = $r['fields'];
        self::assertSame('ib', $r['model']);
        // N° de licence IB = N° Validation ; le n° COD est une information complémentaire
        self::assertSame(['DEC1391544-BCEEBC8C2898-IB', 'extracted'], [$f['number']['value'], $f['number']['status']]);
        self::assertSame('COD 2024 214576', $r['extras']['cod']);
        self::assertSame(['2024-06-14', '2025-06-09'], [$f['issued_at']['value'], $f['expires_at']['value']]);
        self::assertSame(['China', 'Rawbank', 'USD', 147555.56], [$f['origin_country']['value'], $f['bank']['value'], $f['currency']['value'], $f['amount']['value']]);
        self::assertSame('CHINA RAILWAY NO.8 ENGINEERING GROUP CO.,LTD', $f['supplier_name']['value']);
        self::assertSame('COLINGO RDC SARL', $f['importer_name']['value']);
        self::assertSame('missing', $f['po_reference']['status']); // rien d'inventé
        self::assertCount(2, $r['lines']);
        [$a, $b] = $r['lines'];
        self::assertSame(['8704230001', 1.0, 'PCE', 52111.1111, 52111.11], [$a['hs_code'], $a['quantity'], $a['unit'], $a['unit_price'], $a['amount']]);
        self::assertStringStartsWith('Autres véhicules automobiles pour le transport', $a['description']);
        self::assertSame('Bouteurs (bulldozers) et bouteurs biais (angledozers) autopropulsés à chenilles', $b['description']);
        self::assertContains('line_amount_computed', $r['warnings']);
        self::assertSame(['164625.56', 'LICENCE PREFINANCEE', '2024-06-05'], [$r['extras']['cif'], $r['extras']['category'], $r['extras']['invoice_date']]);
    }

    /** Le mot « LICENCE » seul n'est jamais pris pour un numéro. */
    public function testWordLicenceIsNotANumber(): void
    {
        $r = P::parse("CATEGORIE : LICENCE PREFINANCEE\nAutre texte sans numéro");
        self::assertSame('missing', $r['fields']['number']['status']);
        self::assertNull($r['model']);
    }

    /** Modèle IB en colonnes (pdftotext -layout), sans n° COD imprimé, produit sur une ligne + suite de désignation. */
    public function testIbModelColumnLayout(): void
    {
        $r = P::parse((string) file_get_contents(__DIR__ . '/../Fixtures/licence-ib-sicodex.txt'));
        $f = $r['fields'];
        self::assertSame('ib', $r['model']);
        // N° de licence = N° Validation (la case COD est vide sur ce document, sans incidence)
        self::assertSame(['DEC1711498-40CD-IB', 'extracted'], [$f['number']['value'], $f['number']['status']]);
        self::assertArrayNotHasKey('cod', $r['extras']);
        self::assertSame(['2026-03-19', '2027-03-14'], [$f['issued_at']['value'], $f['expires_at']['value']]);
        self::assertSame(['China', 'Rawbank', 'USD', 3055500.0], [$f['origin_country']['value'], $f['bank']['value'], $f['currency']['value'], $f['amount']['value']]);
        self::assertSame('AUXIN HOLDINGS(HONG KONG)LIMITED', $f['supplier_name']['value']);
        self::assertSame("SINO-CONGOLAISE D'EXPLOITATION S.A (SICODEX, SA)", $f['importer_name']['value']);
        self::assertCount(1, $r['lines']);
        $l = $r['lines'][0];
        self::assertSame(["Nitrate d'ammonium, même en solution aqueuse", '3102300000', 4500.0, 'KG', 679.0, 3055500.0],
            [$l['description'], $l['hs_code'], $l['quantity'], $l['unit'], $l['unit_price'], $l['amount']]);
        self::assertSame(['SICODEX-KLW-202604', '2026-03-03', '4-Mise à la consommation définitive', '4068000.00'],
            [$r['extras']['invoice'], $r['extras']['invoice_date'], $r['extras']['regime'], $r['extras']['cif']]);
    }

    /** N° COD (information complémentaire) reconnu quelle que soit sa graphie ; le n° de licence reste le N° Validation. */
    public function testCodNumberVariants(): void
    {
        foreach ([
            "MODELE IB\nCOD 2026 214576" => 'COD 2026 214576',
            "MODELE IB\nLICENCE PREFINANCEECOD 2026 214576" => 'COD 2026 214576',  // collé au texte voisin
            "MODELE IB\nN° COD-2026-214576" => 'COD 2026 214576',
            "MODELE IB\ncod2026214576" => 'COD 2026 214576',
            "MODELE IB\nC0D 2026 214576" => 'COD 2026 214576',                     // O lu comme 0 par l'OCR
        ] as $text => $expected) {
            self::assertSame($expected, P::parse($text)['extras']['cod'] ?? null, $text);
        }
        self::assertArrayNotHasKey('cod', P::parse("MODELE IB\nCode type déclaration IB 2026")['extras']);
        // N° Validation : libellé + valeur, valeur collée, ou motif DEC…-IB seul (alors « à vérifier »)
        self::assertSame(['DEC1711498-40CD-IB', 'extracted'], array_values(array_intersect_key(P::parse("MODELE IB\nN° Validation      DEC1711498-40CD-IB")['fields']['number'], ['value' => 1, 'status' => 1])));
        self::assertSame(['DEC1711498-40CD-IB', 'uncertain'], array_values(array_intersect_key(P::parse("MODELE IB\nRéf. DEC1711498-40CD-IB")['fields']['number'], ['value' => 1, 'status' => 1])));
        $two = P::parse("MODELE IB\nN° Validation DEC1-AA-IB\nN° Validation DEC2-BB-IB");
        self::assertSame('uncertain', $two['fields']['number']['status']);
        self::assertContains('validation_several', $two['warnings']);
    }

    public function testNormalizeCod(): void
    {
        self::assertSame('COD 2026 123456', P::normalizeCod('cod2026-123456'));
        self::assertSame('COD 2026 123456', P::normalizeCod(' COD 2026 123456 '));
        self::assertNull(P::normalizeCod('DEC1711498-40CD-IB'));
        self::assertNull(P::normalizeCod('LI-2026/04512'));
    }
}
