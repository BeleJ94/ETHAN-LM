<?php

declare(strict_types=1);

namespace App\Services\Licence;

use App\Core\Env;
use App\Domain\DomainException;
use Smalot\PdfParser\Parser;

/**
 * Lecture du texte d'un PDF, par ordre de préférence :
 *  1. texte natif (pdftotext si configuré — meilleure mise en page —, sinon bibliothèque PHP smalot/pdfparser) ;
 *  2. OCR (pdftoppm + tesseract) si le document est numérisé ET si les outils sont configurés ;
 *  3. sinon : erreur explicite (document scanné, protégé, illisible) → l'utilisateur passe en saisie manuelle.
 */
final class PdfTextReader
{
    /** En dessous de ce nombre de caractères utiles, le PDF est considéré comme une image (scan). */
    public const MIN_TEXT = 40;

    /** @return array{text:string,method:string,pages:int} */
    public function read(string $path): array
    {
        $head = (string) file_get_contents($path, false, null, 0, 1024);
        if (!str_starts_with(ltrim($head), '%PDF-')) {
            throw new DomainException('licence_import.error.not_pdf');
        }
        $raw = (string) file_get_contents($path);
        // PDF « protégé » : souvent de simples restrictions (impression, copie) avec mot de passe d'ouverture vide.
        // Poppler sait le lire ; sans Poppler, ou s'il faut réellement un mot de passe → erreur explicite.
        $encrypted = (bool) preg_match('/\/Encrypt\s/', $raw);
        $pages = preg_match_all('/\/Type\s*\/Page(?![s\w])/', $raw);
        $text = '';
        $method = 'native';
        $pdftotext = self::tool('PDFTOTEXT_PATH');
        if ($pdftotext !== null) {
            $text = $this->run([$pdftotext, '-layout', '-enc', 'UTF-8', $path, '-']);
            $method = 'pdftotext';
        }
        if ($encrypted && self::useful($text) < self::MIN_TEXT) {
            // Scan protégé (sans texte) mais ouvrable : l'OCR reste possible via Poppler
            if ($pdftotext === null || !self::opensWithoutPassword($pdftotext, $path)) {
                throw new DomainException('licence_import.error.encrypted');
            }
        }
        if (!$encrypted && self::useful($text) < self::MIN_TEXT) {
            try {
                $doc = (new Parser())->parseFile($path);
                $pages = count($doc->getPages()) ?: $pages;
                $text = $doc->getText();
                $method = 'native';
            } catch (\Throwable $e) {
                throw new DomainException('licence_import.error.unreadable');
            }
        }
        if (self::useful($text) >= self::MIN_TEXT) {
            return ['text' => $text, 'method' => $method, 'pages' => $pages];
        }

        // Document numérisé : OCR seulement si les outils sont disponibles
        $tesseract = self::tool('TESSERACT_PATH');
        $pdftoppm = self::tool('PDFTOPPM_PATH');
        if ($tesseract === null || $pdftoppm === null) {
            throw new DomainException('licence_import.error.scanned');
        }
        $dir = sys_get_temp_dir() . '/elm-ocr-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        try {
            $this->run([$pdftoppm, '-r', '300', '-png', '-l', '5', $path, $dir . '/p']);
            $ocr = '';
            foreach (glob($dir . '/p*.png') ?: [] as $img) {
                $ocr .= $this->run([$tesseract, $img, 'stdout', '-l', Env::get('TESSERACT_LANG', 'fra+eng')]) . "\n";
            }
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
        if (self::useful($ocr) < self::MIN_TEXT) {
            throw new DomainException('licence_import.error.unreadable');
        }
        return ['text' => $ocr, 'method' => 'ocr', 'pages' => $pages];
    }

    /** L'OCR est-il disponible sur ce serveur ? (affiché à l'utilisateur) */
    public static function ocrAvailable(): bool
    {
        return self::tool('TESSERACT_PATH') !== null && self::tool('PDFTOPPM_PATH') !== null;
    }

    /** pdftotext renvoie un code de sortie 1 et « Incorrect password » quand l'ouverture exige un mot de passe. */
    private static function opensWithoutPassword(string $pdftotext, string $path): bool
    {
        $proc = proc_open([$pdftotext, '-l', '1', $path, '-'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return false;
        }
        stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return proc_close($proc) === 0 && !str_contains(strtolower($err), 'password');
    }

    private static function useful(string $text): int
    {
        return mb_strlen(preg_replace('/[^\p{L}\p{N}]/u', '', $text) ?? '');
    }

    private static function tool(string $env): ?string
    {
        $p = Env::get($env);
        return $p && is_file($p) && is_executable($p) ? $p : null;
    }

    /** Exécution sans shell (arguments passés en tableau) ; sortie standard renvoyée. */
    private function run(array $cmd): string
    {
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return '';
        }
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return $out;
    }
}
