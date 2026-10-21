<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\DomainException;
use App\Domain\LicenceTextParser;
use App\Services\Licence\PdfTextReader;
use PHPUnit\Framework\TestCase;
use Tests\Support\PdfFixture;

/** Lecture de vrais fichiers PDF (générés), sans base de données. */
final class PdfTextReaderTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->files, 'is_file'));
    }

    private function file(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'elmpdf');
        file_put_contents($path, $content);
        return $this->files[] = $path;
    }

    public function testNativePdfIsReadAndParsedEndToEnd(): void
    {
        $path = $this->file(PdfFixture::text([
            "LICENCE D'IMPORTATION N° LI-2026/04512",
            "Date d'émission : 12/10/2026",
            "Date d'expiration : 11/04/2027",
            'Fournisseur : Sasol Chemicals (Pty) Ltd',
            "Pays d'origine : Afrique du Sud",
            'Valeur FOB : 450 000,00 USD',
            'Acide sulfurique 98%  2807.00  1 000 T  450,00  450 000,00',
        ]));
        $read = (new PdfTextReader())->read($path);
        self::assertContains($read['method'], ['native', 'pdftotext']);
        self::assertSame(1, $read['pages']);
        $r = LicenceTextParser::parse($read['text']);
        self::assertSame('LI-2026/04512', $r['fields']['number']['value']);
        self::assertSame(['2026-10-12', '2027-04-11'], [$r['fields']['issued_at']['value'], $r['fields']['expires_at']['value']]);
        self::assertSame("Afrique du Sud", $r['fields']['origin_country']['value']);
        self::assertSame([450000.0, 'USD'], [$r['fields']['amount']['value'], $r['fields']['currency']['value']]);
        self::assertCount(1, $r['lines']);
        self::assertSame(1000.0, $r['lines'][0]['quantity']);
    }

    public function testScannedPdfWithoutOcrGivesClearError(): void
    {
        if (PdfTextReader::ocrAvailable()) {
            self::markTestSkipped('OCR configuré sur ce poste');
        }
        $this->expectExceptionObject(new DomainException('licence_import.error.scanned'));
        (new PdfTextReader())->read($this->file(PdfFixture::drawing()));
    }

    public function testNonPdfAndCorruptedFiles(): void
    {
        try {
            (new PdfTextReader())->read($this->file("Ceci n'est pas un PDF"));
            self::fail('attendu : not_pdf');
        } catch (DomainException $e) {
            self::assertSame('licence_import.error.not_pdf', $e->getMessage());
        }
        try {
            (new PdfTextReader())->read($this->file("%PDF-1.4\n" . random_bytes(300)));
            self::fail('attendu : unreadable');
        } catch (DomainException $e) {
            self::assertContains($e->getMessage(), ['licence_import.error.unreadable', 'licence_import.error.scanned']);
        }
    }

    /** PDF « protégé » (restrictions, mot de passe d'ouverture vide) : lu via Poppler, modèle IB analysé. */
    public function testPermissionProtectedPdfIsReadWithPoppler(): void
    {
        $path = __DIR__ . '/../Fixtures/licence-ib-protected.pdf';
        $env = \App\Core\Env::get('PDFTOTEXT_PATH');
        if (!$env || !is_file($env)) {
            $this->expectException(\App\Domain\DomainException::class);
            $this->expectExceptionMessage('licence_import.error.encrypted');
            (new PdfTextReader())->read($path);
            return;
        }
        $read = (new PdfTextReader())->read($path);
        self::assertSame('pdftotext', $read['method']);
        self::assertSame(1, $read['pages']);
        $r = LicenceTextParser::parse($read['text']);
        self::assertSame('ib', $r['model']);
        self::assertSame(['DEC1711498-40CD-IB', 'extracted'], [$r['fields']['number']['value'], $r['fields']['number']['status']]);
        self::assertSame(3055500.0, $r['fields']['amount']['value']);
        self::assertCount(1, $r['lines']);
    }
}
