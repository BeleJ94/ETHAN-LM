<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Routeur minimal : motifs avec jetons {id} (entiers) ou {slug}.
 * Chaque route déclare la permission requise (null = public, 'auth' = connecté).
 */
final class Router
{
    /** @var list<array{method:string,regex:string,handler:array{0:class-string,1:string},permission:?string}> */
    private array $routes = [];

    public function get(string $pattern, array $handler, ?string $permission = 'auth'): void
    {
        $this->add('GET', $pattern, $handler, $permission);
    }

    public function post(string $pattern, array $handler, ?string $permission = 'auth'): void
    {
        $this->add('POST', $pattern, $handler, $permission);
    }

    private function add(string $method, string $pattern, array $handler, ?string $permission): void
    {
        $regex = preg_replace_callback(
            '#\{(\w+)\}#',
            fn ($m) => $m[1] === 'id' || str_ends_with($m[1], '_id') ? '(?P<' . $m[1] . '>\d+)' : '(?P<' . $m[1] . '>[\w-]+)',
            $pattern
        );
        $this->routes[] = ['method' => $method, 'regex' => '#^' . $regex . '$#', 'handler' => $handler, 'permission' => $permission];
    }

    /** @return array{handler:array{0:class-string,1:string},permission:?string,params:array<string,string>} */
    public function match(string $method, string $path): array
    {
        $allowed = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed = true;
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return ['handler' => $route['handler'], 'permission' => $route['permission'], 'params' => $params];
        }
        throw new HttpException($allowed ? 405 : 404);
    }
}
