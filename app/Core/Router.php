<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<int,array{method:string,pattern:string,regex:string,handler:array,middleware:array}> */
    private array $routes = [];

    public function get(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    public function post(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    private function add(string $method, string $pattern, array $handler, array $middleware): void
    {
        $regex = '#^' . preg_replace('#\{[a-zA-Z_]+\}#', '([^/]+)', $pattern) . '$#';
        $this->routes[] = compact('method', 'pattern', 'regex', 'handler', 'middleware');
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = rtrim($uri, '/');
        if ($path === '') {
            $path = '/';
        }
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            array_shift($m);
            foreach ($route['middleware'] as $mw) {
                Middleware::handle($mw);
            }
            [$class, $action] = $route['handler'];
            $controller = new $class();
            $controller->$action(...$m);
            return;
        }
        http_response_code(404);
        if (str_starts_with($path, '/api/')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'not_found', 'message' => 'Endpoint tidak ditemukan.']);
            return;
        }
        View::render('errors/404', ['title' => 'Halaman tidak ditemukan'], 'guest');
    }
}
