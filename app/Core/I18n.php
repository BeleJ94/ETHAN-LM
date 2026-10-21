<?php

declare(strict_types=1);

namespace App\Core;

final class I18n
{
    public const LOCALES = ['fr', 'en'];

    private static string $locale = 'fr';
    /** @var array<string,string> */
    private static array $messages = [];

    public static function setLocale(string $locale): void
    {
        self::$locale = in_array($locale, self::LOCALES, true) ? $locale : 'fr';
        self::$messages = require dirname(__DIR__, 2) . '/lang/' . self::$locale . '.php';
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    /** @param array<string,string|int|float> $params */
    public static function t(string $key, array $params = []): string
    {
        $text = self::$messages[$key] ?? $key;
        foreach ($params as $name => $value) {
            $text = str_replace(':' . $name, (string) $value, $text);
        }
        return $text;
    }

    public static function has(string $key): bool
    {
        return isset(self::$messages[$key]);
    }

    /**
     * Catalogue filtré envoyé au JS (seules les clés à préfixes autorisés).
     * @param list<string> $prefixes
     * @return array<string,string>
     */
    public static function catalog(array $prefixes): array
    {
        $out = [];
        foreach (self::$messages as $key => $text) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    $out[$key] = $text;
                    break;
                }
            }
        }
        return $out;
    }
}
