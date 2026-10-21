<?php

declare(strict_types=1);

namespace App\Services\Licence;

/** Implémentation par défaut tant que le moteur d'extraction n'est pas branché : saisie manuelle. */
final class NullLicenceExtractor implements LicenceExtractor
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function extract(string $path, string $mime): array
    {
        return [];
    }
}
