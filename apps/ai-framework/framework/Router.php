<?php
declare(strict_types=1);

namespace AiFramework;

/**
 * High-speed hybrid router: O(1) static map + compiled regex for parameterized routes, with 405 Method Not Allowed detection.
 */
final class Router
{
    /** @var array<string, array<string, callable|array{class-string, string}>> */
    private array $staticRoutes = [];

    /** @var array<string, list<array{regex:string, params:list<string>, handler:callable|array{class-string, string}}>> */
    private array $dynamicRoutes = [];

    public function get(string $pattern, callable|array $handler): void
    {
        $this->add('GET', $pattern, $handler);
        $this->add('HEAD', $pattern, $handler);
    }

    public function post(string $pattern, callable|array $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function patch(string $pattern, callable|array $handler): void
    {
        $this->add('PATCH', $pattern, $handler);
    }

    public function delete(string $pattern, callable|array $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable|array $handler): void
    {
        if (!str_contains($pattern, '{')) {
            $this->staticRoutes[$method][$pattern] = $handler;
            return;
        }

        $params = [];
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}/', static function (array $m) use (&$params): string {
            $params[] = $m[1];
            $constraint = $m[2] ?? '[^/]+';
            return '(' . $constraint . ')';
        }, $pattern);

        $this->dynamicRoutes[$method][] = [
            'regex' => '#^' . $regex . '$#',
            'params' => $params,
            'handler' => $handler,
        ];
    }

    /**
     * @return array{status:200, handler:callable|array{class-string, string}, params:array<string, string>}|array{status:405, allowed:list<string>}|array{status:404}
     */
    public function dispatch(string $method, string $path): array
    {
        if (isset($this->staticRoutes[$method][$path])) {
            return ['status' => 200, 'handler' => $this->staticRoutes[$method][$path], 'params' => []];
        }

        foreach ($this->dynamicRoutes[$method] ?? [] as $route) {
            if (preg_match($route['regex'], $path, $matches) === 1) {
                $params = [];
                foreach ($route['params'] as $i => $name) {
                    $params[$name] = $matches[$i + 1];
                }
                return ['status' => 200, 'handler' => $route['handler'], 'params' => $params];
            }
        }

        // Check if path exists under any other HTTP method -> 405 Method Not Allowed
        $allowed = [];
        foreach ($this->staticRoutes as $m => $map) {
            if (isset($map[$path])) {
                $allowed[$m] = true;
            }
        }
        foreach ($this->dynamicRoutes as $m => $routes) {
            if (isset($allowed[$m])) {
                continue;
            }
            foreach ($routes as $route) {
                if (preg_match($route['regex'], $path) === 1) {
                    $allowed[$m] = true;
                    break;
                }
            }
        }

        if ($allowed !== []) {
            return ['status' => 405, 'allowed' => array_keys($allowed)];
        }

        return ['status' => 404];
    }
}
