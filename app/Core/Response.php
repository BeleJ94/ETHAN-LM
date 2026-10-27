<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly string $body = '',
        public readonly int $status = 200,
        public array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            // Un octet non UTF-8 (saisie, fichier importé) est remplacé au lieu de faire échouer toute la réponse.
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    public static function redirect(string $path): self
    {
        return new self('', 302, ['Location' => url($path)]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        $security = [
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; frame-src 'self' blob:; font-src 'self'; frame-ancestors 'self'",
            // Même origine uniquement : l'aperçu en modale (iframe) fonctionne, l'intégration par un site tiers reste bloquée.
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
        ];
        foreach ($security + $this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
