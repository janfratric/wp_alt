<?php declare(strict_types=1);

namespace App\Core;

class Router
{
    /** @var array<int, array{method: string, pattern: string, handler: callable|array}> */
    private array $routes = [];
    private string $groupPrefix = '';

    public function get(string $pattern, callable|array $handler): void
    {
        $this->addRoute('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable|array $handler): void
    {
        $this->addRoute('POST', $pattern, $handler);
    }

    public function put(string $pattern, callable|array $handler): void
    {
        $this->addRoute('PUT', $pattern, $handler);
    }

    public function delete(string $pattern, callable|array $handler): void
    {
        $this->addRoute('DELETE', $pattern, $handler);
    }

    public function group(string $prefix, callable $callback): void
    {
        $previousPrefix = $this->groupPrefix;
        $this->groupPrefix .= $prefix;
        $callback($this);
        $this->groupPrefix = $previousPrefix;
    }

    public function dispatch(string $method, string $uri): ?array
    {
        $method = strtoupper($method);

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $regex = $this->patternToRegex($route['pattern']);

            if (preg_match($regex, $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                return ['handler' => $route['handler'], 'params' => $params];
            }
        }

        return null;
    }

    private function addRoute(string $method, string $pattern, callable|array $handler): void
    {
        $fullPattern = $this->groupPrefix . $pattern;

        // Normalize: ensure leading slash
        if (!str_starts_with($fullPattern, '/')) {
            $fullPattern = '/' . $fullPattern;
        }

        // Trim trailing slash (except if pattern is just '/')
        if ($fullPattern !== '/' && str_ends_with($fullPattern, '/')) {
            $fullPattern = rtrim($fullPattern, '/');
        }

        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $fullPattern,
            'handler' => $handler,
        ];
    }

    private function patternToRegex(string $pattern): string
    {
        $regex = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $pattern);
        return '#^' . $regex . '$#';
    }
}
