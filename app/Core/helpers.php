<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Env;
use App\Core\I18n;
use App\Core\Session;

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @param array<string,string|int|float> $params */
function t(string $key, array $params = []): string
{
    return I18n::t($key, $params);
}

function url(string $path = '/', array $query = []): string
{
    $base = rtrim((string) Env::get('APP_BASE_PATH', ''), '/');
    $qs = $query ? '?' . http_build_query($query) : '';
    return $base . '/' . ltrim($path, '/') . $qs;
}

function asset(string $path): string
{
    $file = dirname(__DIR__, 2) . '/public/assets/' . ltrim($path, '/');
    $v = is_file($file) ? '?v=' . filemtime($file) : '';
    return url('assets/' . ltrim($path, '/')) . $v;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

/** Valeur ressaisie après une erreur de validation, sinon valeur par défaut. */
function old(string $field, mixed $default = ''): string
{
    $old = Session::getFlash('_old', []);
    return (string) ($old[$field] ?? $default ?? '');
}

function field_error(string $field): ?string
{
    return Session::getFlash('_errors', [])[$field] ?? null;
}

/** Attributs d'erreur d'un contrôle + message (à placer dans .field). */
function error_attr(string $field): string
{
    return field_error($field) !== null ? ' aria-invalid="true"' : '';
}

function error_msg(string $field): string
{
    $msg = field_error($field);
    return $msg !== null ? '<p class="error">' . e($msg) . '</p>' : '';
}

function icon(string $name, string $class = ''): string
{
    return '<i data-lucide="' . e($name) . '"' . ($class ? ' class="' . e($class) . '"' : '') . ' aria-hidden="true"></i>';
}

function base_currency(): string
{
    return (string) Env::get('APP_CURRENCY', 'USD');
}

function fmt_number(float|int|string|null $value, int $decimals = 2): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $sep = I18n::locale() === 'fr' ? [',', "\u{202F}"] : ['.', ','];
    return number_format((float) $value, $decimals, $sep[0], $sep[1]);
}

function fmt_qty(float|int|string|null $value): string
{
    $v = (float) $value;
    return fmt_number($v, floor($v) === $v ? 0 : 3);
}

function fmt_money(float|int|string|null $value, ?string $currency = null): string
{
    return fmt_number($value) . ' ' . ($currency ?? base_currency());
}

function fmt_date(?string $value, bool $withTime = false): string
{
    if (!$value) {
        return '—';
    }
    $ts = strtotime($value);
    return $ts === false ? '—' : date($withTime ? 'd/m/Y H:i' : 'd/m/Y', $ts);
}

/**
 * Attributs d'aperçu en modale d'un lien vers un fichier ou un document imprimable.
 * $type : pdf | image | html (déduit du type MIME si fourni). Le href reste le lien de secours sans JavaScript.
 */
function preview_attrs(string $url, string $title, ?string $mime = null, string $type = ''): string
{
    $type = $type ?: match (true) {
        $mime === 'application/pdf' => 'pdf',
        $mime !== null && str_starts_with($mime, 'image/') => 'image',
        default => 'file',
    };
    $sep = str_contains($url, '?') ? '&' : '?';
    return ' href="' . e($url) . '" target="_blank" rel="noopener" data-preview data-preview-type="' . e($type) . '" data-preview-title="' . e($title) . '"'
        . ($type === 'html' ? '' : ' data-preview-download="' . e($url . $sep . 'download=1') . '"');
}

/** Encode des données pour un bloc <script type="application/json"> (sans risque de fermeture de balise). */
function json_script(string $id, mixed $data): string
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return '<script type="application/json" id="' . e($id) . '">' . $json . '</script>';
}
