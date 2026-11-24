<?php
declare(strict_types=1);

namespace AiFramework;

final class Request
{
    private ?array $parsedJson = null;

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param array<string, mixed> $files
     * @param array<string, mixed> $server
     * @param array<string, string> $routeParams
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $headers,
        public readonly array $cookies,
        public readonly array $files,
        public readonly array $server,
        public readonly string $rawBody,
        private array $routeParams = [],
        private array $attributes = [],
    ) {}

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[strtolower(str_replace('_', '-', $key))] = $value;
            }
        }

        $rawBody = '';
        if ($method !== 'GET' && $method !== 'HEAD') {
            $rawBody = file_get_contents('php://input') ?: '';
        }

        return new self(
            method: $method,
            path: $path,
            query: $_GET,
            post: $_POST,
            headers: $headers,
            cookies: $_COOKIE,
            files: $_FILES,
            server: $_SERVER,
            rawBody: $rawBody,
        );
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function queryString(string $key, string $default = ''): string
    {
        if (!array_key_exists($key, $this->query)) {
            return $default;
        }
        $val = $this->query[$key];
        if (!is_string($val)) {
            throw new HttpException(400, "パラメータ {$key} の形式が不正です");
        }
        return mb_scrub($val, 'UTF-8');
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return isset($this->cookies[$name]) && is_string($this->cookies[$name]) ? $this->cookies[$name] : $default;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->parsedJson !== null) {
            return $this->parsedJson;
        }
        if ($this->rawBody === '') {
            return $this->parsedJson = $this->post;
        }
        $decoded = json_decode($this->rawBody, true);
        return $this->parsedJson = is_array($decoded) ? $decoded : [];
    }

    public function withRouteParams(array $params): self
    {
        $clone = clone $this;
        $clone->routeParams = $params;
        return $clone;
    }

    public function param(string $key, ?string $default = null): ?string
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$key] = $value;
        return $clone;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function expectsJson(): bool
    {
        if (str_starts_with($this->path, '/api/')) {
            return true;
        }
        $accept = $this->header('accept', '') ?? '';
        return str_contains($accept, 'application/json');
    }

    public function isHttps(): bool
    {
        return !empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off';
    }

    public function baseUrl(): string
    {
        $configured = getenv('APP_URL');
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }
        $scheme = $this->isHttps() ? 'https' : 'http';
        $port = (string)($this->server['SERVER_PORT'] ?? '8081');
        return "{$scheme}://localhost" . ($port !== '80' && $port !== '443' ? ":{$port}" : '');
    }
}
