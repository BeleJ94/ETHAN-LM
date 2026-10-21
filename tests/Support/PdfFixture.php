<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Générateur minimal de PDF pour les tests (aucune dépendance) :
 * - text()    : PDF à texte natif (Helvetica, encodage WinAnsi, accents compris) ;
 * - drawing() : PDF sans aucun texte (équivalent d'un scan) ;
 */
final class PdfFixture
{
    /** @param list<string> $lines lignes de texte (UTF-8) */
    public static function text(array $lines): string
    {
        $content = "BT /F1 10 Tf 40 800 Td 14 TL\n";
        foreach ($lines as $line) {
            $latin = iconv('UTF-8', 'Windows-1252//TRANSLIT', $line) ?: $line;
            $content .= '(' . strtr($latin, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ") Tj T*\n";
        }
        $content .= "ET\n";
        return self::build($content, true);
    }

    public static function drawing(): string
    {
        return self::build("0.2 0.2 0.2 rg 60 600 300 120 re f 0 0 1 RG 4 w 60 560 m 360 560 l S\n", false);
    }

    private static function build(string $content, bool $font): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R'
                . ($font ? ' /Resources << /Font << /F1 5 0 R >> >>' : ' /Resources << >>') . ' >>',
            '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream",
        ];
        if ($font) {
            $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        }
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $obj . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $pdf .= sprintf("%010d 00000 n \n", $o);
        }
        return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }
}
