<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Toute clé utilisée dans le code existe en FR et en EN ; les deux catalogues ont les mêmes clés. */
final class TranslationsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testCataloguesHaveSameKeys(): void
    {
        $fr = require self::ROOT . '/lang/fr.php';
        $en = require self::ROOT . '/lang/en.php';
        self::assertSame([], array_values(array_diff(array_keys($fr), array_keys($en))), 'Manquant en EN');
        self::assertSame([], array_values(array_diff(array_keys($en), array_keys($fr))), 'Manquant en FR');
    }

    public function testEveryLiteralKeyUsedExists(): void
    {
        $fr = require self::ROOT . '/lang/fr.php';
        $files = [];
        foreach (['app', 'views'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/' . $dir));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $files[] = $f->getPathname();
                }
            }
        }
        $files[] = self::ROOT . '/public/assets/js/elm-tables.js';
        $files[] = self::ROOT . '/public/assets/js/app.js';

        $missing = [];
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            // t('a.b'), 'label' => 'nav.x', 'section' => 'nav.y', DomainException('stock.error.z')
            preg_match_all("/(?:\\bt\\(|'(?:label|section)' => |DomainException\\()'([a-z_]+(?:\\.[a-z0-9_]+)+)'/", $src, $m);
            foreach ($m[1] as $key) {
                if (!isset($fr[$key])) {
                    $missing[] = basename($file) . ': ' . $key;
                }
            }
        }
        self::assertSame([], array_values(array_unique($missing)));
    }
}
