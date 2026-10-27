<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use App\Domain\DomainException;
use App\Security\Auth;

/**
 * Identité de la société utilisatrice (Paramètres → Société) : coordonnées, identifiants légaux, logo.
 * Valeurs en base (company_settings, ligne unique) ; un champ vide retombe sur la variable COMPANY_* du .env.
 */
final class CompanySettings
{
    public const FIELDS = ['name', 'legal_form', 'address', 'city', 'country', 'phone', 'email', 'website',
        'rccm', 'id_nat', 'nif', 'vat_number', 'importer_number', 'share_capital'];
    public const LOGO_MAX_BYTES = 1024 * 1024;
    public const LOGO_MIME = ['image/png', 'image/jpeg', 'image/webp'];

    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    /** @return array<string,string|bool> champs + has_logo */
    public static function get(): array
    {
        if (self::$cache === null) {
            $row = [];
            try {
                $row = Database::pdo()->query('SELECT ' . implode(',', self::FIELDS) . ', logo IS NOT NULL AS has_logo FROM company_settings WHERE id = 1')->fetch() ?: [];
            } catch (\PDOException) {
                // migration 0015 pas encore jouée : repli sur le .env
            }
            $out = [];
            foreach (self::FIELDS as $f) {
                $out[$f] = (string) (($row[$f] ?? '') !== '' ? $row[$f] : Env::get('COMPANY_' . strtoupper($f), ''));
            }
            $out['name'] = $out['name'] ?: 'ETHAN LM';
            $out['has_logo'] = (bool) ($row['has_logo'] ?? false);
            self::$cache = $out;
        }
        return self::$cache;
    }

    /** Valeurs brutes en base (formulaire). @return array<string,?string> */
    public static function stored(): array
    {
        $row = Database::pdo()->query('SELECT ' . implode(',', self::FIELDS) . ' FROM company_settings WHERE id = 1')->fetch() ?: [];
        return array_merge(array_fill_keys(self::FIELDS, null), $row);
    }

    /** @param array<string,?string> $data champs de FIELDS (null = vide) */
    public static function save(array $data): void
    {
        $data = array_intersect_key($data, array_flip(self::FIELDS));
        if (!$data) {
            return;
        }
        $cols = array_keys($data);
        $sql = 'INSERT INTO company_settings (id, ' . implode(',', $cols) . ', updated_by, updated_at) VALUES (1, '
            . implode(',', array_fill(0, count($cols), '?')) . ', ?, NOW()) ON DUPLICATE KEY UPDATE '
            . implode(', ', array_map(fn ($c) => "$c = VALUES($c)", $cols)) . ', updated_by = VALUES(updated_by), updated_at = NOW()';
        Database::pdo()->prepare($sql)->execute([...array_values($data), Auth::user()?->id]);
        self::$cache = null;
    }

    /** @param array<string,mixed> $file entrée $_FILES */
    public static function saveLogo(array $file): void
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || (int) ($file['size'] ?? 0) > self::LOGO_MAX_BYTES) {
            throw new DomainException('company.error.logo_size', ['max' => self::LOGO_MAX_BYTES / 1024]);
        }
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new DomainException('company.error.logo_upload');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
        if (!in_array($mime, self::LOGO_MIME, true) || @getimagesize($file['tmp_name']) === false) {
            throw new DomainException('company.error.logo_type');
        }
        Database::pdo()->prepare('INSERT INTO company_settings (id, logo, logo_mime, updated_by, updated_at) VALUES (1, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE logo = VALUES(logo), logo_mime = VALUES(logo_mime), updated_by = VALUES(updated_by), updated_at = NOW()')
            ->execute([file_get_contents($file['tmp_name']), $mime, Auth::user()?->id]);
        self::$cache = null;
    }

    public static function deleteLogo(): void
    {
        Database::pdo()->prepare('UPDATE company_settings SET logo = NULL, logo_mime = NULL, updated_by = ?, updated_at = NOW() WHERE id = 1')
            ->execute([Auth::user()?->id]);
        self::$cache = null;
    }

    /** @return array{0:string,1:string,2:string}|null [mime, contenu, empreinte] */
    public static function logo(): ?array
    {
        $row = Database::pdo()->query('SELECT logo, logo_mime, updated_at FROM company_settings WHERE id = 1 AND logo IS NOT NULL')->fetch();
        return $row ? [$row['logo_mime'], $row['logo'], md5((string) $row['updated_at'] . strlen($row['logo']))] : null;
    }

    /** Logo en data URI pour les documents imprimés (aucune requête supplémentaire). */
    public static function logoDataUri(): ?string
    {
        $logo = self::get()['has_logo'] ? self::logo() : null;
        return $logo ? 'data:' . $logo[0] . ';base64,' . base64_encode($logo[1]) : null;
    }
}
