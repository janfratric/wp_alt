<?php declare(strict_types=1);

namespace App\Core;

class Request
{
    private string $method;
    private string $uri;
    private array $get;
    private array $post;
    private array $server;
    private array $cookies;

    public function __construct()
    {
        $this->server = $_SERVER;
        $this->cookies = $_COOKIE;
        $this->get = $this->trimStrings($_GET);
        $this->post = $this->trimStrings($_POST);

        // Determine HTTP method with _method override support
        $method = strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'POST' && isset($this->post['_method'])) {
            $override = strtoupper($this->post['_method']);
            if (in_array($override, ['PUT', 'DELETE'], true)) {
                $method = $override;
            }
        }
        $this->method = $method;

        // Parse URI: strip query string and handle subdirectory installs
        $requestUri = $this->server['REQUEST_URI'] ?? '/';
        $uri = strtok($requestUri, '?');
        if ($uri === false) {
            $uri = '/';
        }

        // Handle subdirectory installs: strip the script directory prefix
        $scriptName = $this->server['SCRIPT_NAME'] ?? '';
        $scriptDir = dirname($scriptName);
        if ($scriptDir !== '/' && $scriptDir !== '\\' && str_starts_with($uri, $scriptDir)) {
            $uri = substr($uri, strlen($scriptDir));
            if ($uri === '' || $uri === false) {
                $uri = '/';
            }
        }

        // Trim trailing slash (keep root /)
        if ($uri !== '/' && str_ends_with($uri, '/')) {
            $uri = rtrim($uri, '/');
        }

        $this->uri = $uri;
    }

    public function method(): string
    {
        return strtoupper($this->method);
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->get[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->get, $this->post);
    }

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    public function isMethod(string $method): bool
    {
        return $this->method() === strtoupper($method);
    }

    public function isAjax(): bool
    {
        return ($this->server('HTTP_X_REQUESTED_WITH') ?? '') === 'XMLHttpRequest';
    }

    /**
     * Recursively trim all string values in an array.
     */
    private function trimStrings(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $result[$key] = trim($value);
            } elseif (is_array($value)) {
                $result[$key] = $this->trimStrings($value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
