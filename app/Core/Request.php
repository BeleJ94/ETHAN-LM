<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @param array<string,string> $params Paramètres de route */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $server,
        public array $params = [],
    ) {
    }

    public static function fromGlobals(string $basePath): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        $path = '/' . trim($path, '/');
        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path, $_GET, $_POST, $_SERVER);
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $v = $this->body[$key] ?? $default;
        return is_string($v) ? trim($v) : $v;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return array_map(fn ($v) => is_string($v) ? trim($v) : $v, $this->body);
    }

    /** @return array<string,mixed> */
    public function allQuery(): array
    {
        return $this->query;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $this->server[$key] ?? null;
    }

    public function param(string $name): string
    {
        return $this->params[$name] ?? '';
    }

    public function intParam(string $name): int
    {
        return (int) ($this->params[$name] ?? 0);
    }

    public function isAjax(): bool
    {
        return $this->header('X-Requested-With') === 'XMLHttpRequest';
    }
}
