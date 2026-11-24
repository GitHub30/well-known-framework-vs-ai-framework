<?php
declare(strict_types=1);

/**
 * CloudDrive â€” Single-File Standalone PHP 8.3 Edition (AI Custom Framework)
 *
 * Zero external dependencies, zero Composer, zero vendor/.
 * Upload this single file as `index.php` to any PHP 8.3 shared hosting / rental server
 * (Xserver, Sakura, Lolipop, ConoHa WING, MixHost, Nginx, Apache, LiteSpeed, or `php -S`).
 *
 * First-request auto-provisioning:
 *   - Creates `.clouddrive_data/` (0700) protected by deny-all `.htaccess` + dotfile 403 guard
 *   - Generates a random 256-bit CSRF HMAC secret in `.clouddrive_data/.app_secret` (0600)
 *   - Initializes SQLite WAL database & seeds 71 sample items (set CLOUDDRIVE_SKIP_SEED=1 for empty)
 *   - On Apache/LiteSpeed, auto-creates rental-server-safe `.htaccess` if none exists
 *   - Serves all CSS, JS, Service Worker, PWA icons, and screenshots directly from this file
 *
 * Optional Environment Variables:
 *   APP_SECRET            Override CSRF HMAC secret (>= 32 bytes)
 *   APP_URL               Fixed canonical origin URL (e.g. https://drive.example.com)
 *   CLOUDDRIVE_DATA_DIR   Custom writable data directory outside document root
 *   CLOUDDRIVE_SKIP_SEED  Set to "1" to initialize an empty Drive instead of 71 demo items
 *   CLOUDDRIVE_NO_HTACCESS Set to "1" to disable automatic Apache/LiteSpeed `.htaccess` creation
 */

namespace AiFramework {
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
            throw new HttpException(400, "ãƒ‘ãƒ©ãƒ¡ãƒ¼ã‚¿ {$key} ã®å½¢å¼ãŒä¸æ­£ã§ã™");
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
        if (!empty($this->server['HTTPS']) && strtolower((string)$this->server['HTTPS']) !== 'off') {
            return true;
        }
        if ((string)($this->server['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        return strtolower((string)($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public function baseUrl(): string
    {
        $configured = getenv('APP_URL');
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }
        $scheme = $this->isHttps() ? 'https' : 'http';
        $host = (string)($this->server['HTTP_HOST'] ?? $this->server['SERVER_NAME'] ?? 'localhost');
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*(?::\d{1,5})?$/i', $host)) {
            $host = 'localhost';
        }
        return "{$scheme}://{$host}";
    }
}
}

namespace AiFramework {
/**
 * Lightweight lazy singleton Dependency Injection container.
 */
final class Container
{
    /** @var array<string, callable(self): mixed> */
    private array $definitions = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @param callable(self): mixed $factory */
    public function singleton(string $id, callable $factory): void
    {
        $this->definitions[$id] = $factory;
    }

    public function set(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (isset($this->definitions[$id])) {
            return $this->instances[$id] = ($this->definitions[$id])($this);
        }
        throw new \RuntimeException("Service not registered in container: {$id}");
    }
}
}

namespace AiFramework {
use PDO;
use PDOStatement;

final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly string $sqlitePath) {}

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO('sqlite:' . $this->sqlitePath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000; PRAGMA synchronous = NORMAL;');
        }
        return $this->pdo;
    }

    /** @param array<int|string, mixed> $params @return list<array<string, mixed>> */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param array<int|string, mixed> $params @return array<string, mixed>|null */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return is_array($row) ? $row : null;
    }

    /** @param array<int|string, mixed> $params */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        return $this->run($sql, $params)->fetchColumn();
    }

    /** @param array<int|string, mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int)$this->pdo()->lastInsertId();
    }

    /** @template T @param callable(self): T $fn @return T */
    public function transaction(callable $fn, bool $immediate = false): mixed
    {
        $pdo = $this->pdo();
        if ($immediate) {
            $pdo->exec('BEGIN IMMEDIATE');
        } else {
            $pdo->beginTransaction();
        }
        try {
            $result = $fn($this);
            if ($immediate) {
                $pdo->exec('COMMIT');
            } else {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            try {
                if ($immediate) {
                    $pdo->exec('ROLLBACK');
                } elseif ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    /** @param array<int|string, mixed> $params */
    private function run(string $sql, array $params): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}
}

namespace AiFramework {
final class HttpException extends \RuntimeException
{
    public function __construct(public readonly int $statusCode, string $message = '')
    {
        parent::__construct($message, $statusCode);
    }
}
}

namespace AiFramework {
interface MiddlewareInterface
{
    /** @param callable(Request): Response $next */
    public function handle(Request $request, callable $next): Response;
}
}

namespace AiFramework {
final class Response
{
    /** @var list<array{name:string,value:string,options:array<string,mixed>}> */
    private array $cookies = [];
    private ?string $filePath = null;

    /** @param array<string, string> $headers */
    public function __construct(
        private string $body = '',
        private int $status = 200,
        private array $headers = [],
    ) {}

    public static function html(string $html, int $status = 200, array $headers = []): self
    {
        return new self($html, $status, array_merge(['Content-Type' => 'text/html; charset=utf-8'], $headers));
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $encoded = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
        return new self($encoded, $status, array_merge(['Content-Type' => 'application/json; charset=utf-8'], $headers));
    }

    public static function text(string $text, int $status = 200, string $contentType = 'text/plain; charset=utf-8', array $headers = []): self
    {
        return new self($text, $status, array_merge(['Content-Type' => $contentType], $headers));
    }

    public static function file(string $absolutePath, string $contentType, ?string $downloadName = null, array $headers = []): self
    {
        $res = new self('', 200, array_merge([
            'Content-Type' => $contentType,
            'Content-Length' => (string)filesize($absolutePath),
        ], $headers));
        if ($downloadName !== null) {
            $safeAscii = preg_replace('/[^a-zA-Z0-9._-]/', '_', $downloadName) ?: 'download';
            $utf8 = rawurlencode($downloadName);
            $res = $res->withHeader('Content-Disposition', "attachment; filename=\"{$safeAscii}\"; filename*=UTF-8''{$utf8}");
        }
        $res->filePath = $absolutePath;
        return $res;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function hasHeader(string $name): bool
    {
        $target = strtolower($name);
        foreach ($this->headers as $k => $_) {
            if (strtolower($k) === $target) {
                return true;
            }
        }
        return false;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    /** @param array<string, mixed> $options */
    public function withCookie(string $name, string $value, array $options = []): self
    {
        $clone = clone $this;
        $clone->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options];
        return $clone;
    }

    public function send(bool $isHead = false): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        foreach ($this->cookies as $c) {
            setcookie($c['name'], $c['value'], $c['options']);
        }
        if ($isHead) {
            return;
        }
        if ($this->filePath !== null) {
            readfile($this->filePath);
            return;
        }
        echo $this->body;
    }
}
}

namespace AiFramework {
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
}

namespace AiFramework {
/**
 * Cryptographic CSRF protection, Unicode-safe filename sanitization, strict ID parsing, and path-jail utilities.
 */
final class Security
{
    public const CSRF_COOKIE = 'clouddrive_csrf';
    public const CSRF_TTL = 43200; // 12 hours

    private const PUBLIC_CACHEABLE_PATHS = [
        '/manifest.webmanifest' => true,
        '/robots.txt' => true,
        '/sitemap.xml' => true,
        '/llms.txt' => true,
        '/llms-full.txt' => true,
        '/openapi.json' => true,
        '/api/health' => true,
    ];

    public function __construct(private readonly string $secretKey)
    {
        if (strlen($secretKey) < 32) {
            throw new \RuntimeException('APP_SECRET must be configured with at least 32 bytes of entropy.');
        }
    }

    public static function isPublicCacheablePath(string $path): bool
    {
        return isset(self::PUBLIC_CACHEABLE_PATHS[$path]);
    }

    public function issueCsrfToken(): string
    {
        $ts = (string)time();
        $nonce = bin2hex(random_bytes(16));
        $payload = $ts . '.' . $nonce;
        $sig = hash_hmac('sha256', $payload, $this->secretKey);
        return $payload . '.' . $sig;
    }

    public function verifyCsrfToken(?string $token): bool
    {
        if (!is_string($token) || substr_count($token, '.') !== 2) {
            return false;
        }
        [$ts, $nonce, $sig] = explode('.', $token, 3);
        if (!ctype_digit($ts) || !ctype_xdigit($nonce) || strlen($nonce) !== 32 || strlen($sig) !== 64) {
            return false;
        }
        $age = time() - (int)$ts;
        if ($age < -60 || $age > self::CSRF_TTL) {
            return false;
        }
        $expected = hash_hmac('sha256', $ts . '.' . $nonce, $this->secretKey);
        return hash_equals($expected, $sig);
    }

    public function verifyDoubleSubmit(?string $cookieToken, ?string $headerToken): bool
    {
        if (!$this->verifyCsrfToken($cookieToken) || !$this->verifyCsrfToken($headerToken)) {
            return false;
        }
        return hash_equals((string)$cookieToken, (string)$headerToken);
    }

    public static function sanitizeItemName(string $name): string
    {
        $clean = mb_scrub($name, 'UTF-8');
        // Strip ASCII control chars, slashes, and Unicode Bidi/RTLO spoofing controls
        $clean = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', ' ', $clean) ?? '';
        $clean = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $clean) ?? '';
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '');
        $clean = trim(mb_substr($clean, 0, 200, 'UTF-8'));
        if ($clean === '' || $clean === '.' || $clean === '..') {
            return '';
        }
        return $clean;
    }

    public static function parseOptionalPositiveInt(mixed $value, string $fieldName = 'id', int $errorStatus = 422): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value) || is_array($value) || is_object($value)) {
            throw new HttpException($errorStatus, "{$fieldName} ã®å½¢å¼ãŒä¸æ­£ã§ã™");
        }
        if (is_int($value)) {
            if ($value < 1) {
                throw new HttpException($errorStatus, "{$fieldName} ã¯æ­£ã®æ•´æ•°ã§æŒ‡å®šã—ã¦ãã ã•ã„");
            }
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            $intVal = (int)$value;
            if ($intVal >= 1) {
                return $intVal;
            }
        }
        throw new HttpException($errorStatus, "{$fieldName} ã¯æ­£ã®æ•´æ•°ã§æŒ‡å®šã—ã¦ãã ã•ã„");
    }

    public static function requireString(mixed $value, string $fieldName, bool $allowNull = false): ?string
    {
        if ($value === null && $allowNull) {
            return null;
        }
        if (!is_string($value)) {
            throw new HttpException(422, "{$fieldName} ã¯æ–‡å­—åˆ—ã§æŒ‡å®šã—ã¦ãã ã•ã„");
        }
        return mb_scrub($value, 'UTF-8');
    }

    public static function resolveJailedPath(string $baseDir, string $storageName): ?string
    {
        $base = realpath($baseDir);
        if ($base === false) {
            return null;
        }
        $safeName = basename($storageName);
        if ($safeName === '' || $safeName !== $storageName || str_contains($safeName, '..')) {
            return null;
        }
        $full = realpath($base . DIRECTORY_SEPARATOR . $safeName);
        if ($full === false || !str_starts_with($full, $base . DIRECTORY_SEPARATOR) || !is_file($full)) {
            return null;
        }
        return $full;
    }
}
}

namespace AiFramework {
final class Application
{
    /** @var list<MiddlewareInterface> */
    private array $middlewares = [];

    public function __construct(
        public readonly Container $container,
        public readonly Router $router,
    ) {}

    public function addMiddleware(MiddlewareInterface $middleware): void
    {
        $this->middlewares[] = $middleware;
    }

    public function handle(Request $request): Response
    {
        // Route matching executes before inner middleware (matching Slim & Laravel routing-before-CSRF order)
        $match = $this->router->dispatch($request->method, $request->path);

        $core = function (Request $req) use ($match): Response {
            try {
                if ($match['status'] === 404) {
                    throw new HttpException(404, 'Not Found');
                }
                if ($match['status'] === 405) {
                    $res = $this->renderError($req, 405, 'Method Not Allowed');
                    return $res->withHeader('Allow', implode(', ', $match['allowed']));
                }
                $req = $req->withRouteParams($match['params']);
                $handler = $match['handler'];
                if (is_array($handler)) {
                    [$class, $method] = $handler;
                    $controller = $this->container->get($class);
                    return $controller->$method($req);
                }
                return $handler($req);
            } catch (HttpException $e) {
                return $this->renderError($req, $e->statusCode, $e->getMessage());
            } catch (\Throwable $e) {
                error_log('[ai-framework] ' . $e->getMessage());
                return $this->renderError($req, 500, 'Internal Server Error');
            }
        };

        // Run SecurityHeaders outermost, then routing check (404/405), then CSRF, then controller
        $request = $request->withAttribute('route_status', $match['status']);

        $pipeline = array_reduce(
            array_reverse($this->middlewares),
            static fn(callable $next, MiddlewareInterface $mw): callable => static fn(Request $req): Response => $mw->handle($req, $next),
            $core
        );

        try {
            return $pipeline($request);
        } catch (HttpException $e) {
            return $this->renderError($request, $e->statusCode, $e->getMessage());
        } catch (\Throwable $e) {
            error_log('[ai-framework] ' . $e->getMessage());
            return $this->renderError($request, 500, 'Internal Server Error');
        }
    }

    private function renderError(Request $request, int $status, string $message): Response
    {
        if ($request->expectsJson()) {
            return Response::json(['error' => $message ?: 'Error', 'status' => $status], $status);
        }
        $safeMsg = htmlspecialchars($message ?: 'ã‚¨ãƒ©ãƒ¼ãŒç™ºç”Ÿã—ã¾ã—ãŸ', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = "<!DOCTYPE html><html lang=\"ja\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>{$status} - CloudDrive</title><link rel=\"stylesheet\" href=\"/assets/app.css?v=1\"></head><body><main class=\"content-page\"><h1>{$status}</h1><p>{$safeMsg}</p><p><a href=\"/\">ãƒžã‚¤ãƒ‰ãƒ©ã‚¤ãƒ–ã¸æˆ»ã‚‹</a></p></main></body></html>";
        return Response::html($html, $status);
    }
}
}

namespace AiFramework {
use App\Services\UiHelper;

final class EmbeddedTemplates
{
    /** @param array<string, mixed> $__data */
    public static function render_layout(array $__data, callable $e, callable $jsonSafe): string
    {
        extract($__data, EXTR_SKIP);
        ob_start();
        try {
            ?><?php
$usedBytes = (int)($storage['used_bytes'] ?? 0);
$quotaBytes = (int)($storage['quota_bytes'] ?? 16106127360);
$pct = min(100, max($usedBytes > 0 ? 1 : 0, ($usedBytes / max(1, $quotaBytes)) * 100));
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $e($meta['title']) ?></title>
  <meta name="description" content="<?= $e($meta['description']) ?>">
  <meta name="theme-color" content="#f8fafd">
  <meta name="csrf-token" content="<?= $e($csrfToken) ?>">
  <meta name="robots" content="<?= $e($meta['robots'] ?? 'index, follow') ?>">
  <link rel="canonical" href="<?= $e($meta['canonical']) ?>">
  <link rel="alternate" hreflang="ja" href="<?= $e($meta['canonical']) ?>">
  <link rel="alternate" hreflang="x-default" href="<?= $e($meta['canonical']) ?>">
  <link rel="alternate" type="text/markdown" href="/llms.txt" title="LLMs Documentation Index">
  <link rel="manifest" href="/manifest.webmanifest">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link rel="apple-touch-icon" href="/assets/icon-192.png">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="CloudDrive">
  <meta property="og:locale" content="ja_JP">
  <meta property="og:title" content="<?= $e($meta['title']) ?>">
  <meta property="og:description" content="<?= $e($meta['description']) ?>">
  <meta property="og:url" content="<?= $e($meta['canonical']) ?>">
  <meta property="og:image" content="<?= $e($meta['ogImage']) ?>">
  <meta property="og:image:alt" content="<?= $e($meta['ogImageAlt'] ?? 'CloudDrive') ?>">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= $e($meta['title']) ?>">
  <meta name="twitter:description" content="<?= $e($meta['description']) ?>">
  <meta name="twitter:image" content="<?= $e($meta['ogImage']) ?>">
  <meta name="twitter:image:alt" content="<?= $e($meta['ogImageAlt'] ?? 'CloudDrive') ?>">
  <style><?= UiHelper::inlineCss() ?></style>
  <script type="application/ld+json"><?= $jsonSafe($meta['jsonLd']) ?></script>
</head>
<body>
  <a class="skip-link" href="#main-content">ãƒ¡ã‚¤ãƒ³ã‚³ãƒ³ãƒ†ãƒ³ãƒ„ã¸ã‚¹ã‚­ãƒƒãƒ—</a>

  <div class="app-shell">
    <header class="app-header">
      <div class="brand-area">
        <button type="button" id="menu-toggle-btn" class="menu-toggle-btn" aria-label="ãƒŠãƒ“ã‚²ãƒ¼ã‚·ãƒ§ãƒ³ãƒ¡ãƒ‹ãƒ¥ãƒ¼ã‚’é–‹ã" aria-controls="app-sidebar" aria-expanded="false">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg>
        </button>
        <a class="brand-link" href="/" aria-label="Drive ãƒ›ãƒ¼ãƒ ã¸ç§»å‹•">
          <svg width="34" height="34" viewBox="0 0 64 64" aria-hidden="true">
            <path d="M23.5 11h17l15.5 27-8.5 14.5-15.5-27L23.5 11z" fill="#fbbc04"/>
            <path d="M8 38l8.5 14.5h31L56 38H24.5L8 38z" fill="#1a73e8"/>
            <path d="M23.5 11L8 38l8.5 14.5L32 25.5 23.5 11z" fill="#34a853"/>
            <path d="M24.5 38l-8 14.5h15.5L24.5 38z" fill="#1967d2"/>
          </svg>
          <span class="brand-name">Drive</span>
        </a>
      </div>

      <form id="search-form" class="search-form" action="/drive/search" method="get" role="search">
        <span class="search-icon" aria-hidden="true">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
        </span>
        <label class="sr-only" for="search-input">ãƒ‰ãƒ©ã‚¤ãƒ–ã‚’æ¤œç´¢</label>
        <input id="search-input" class="search-input" type="search" name="q" value="<?= $e($q ?? '') ?>" placeholder="ãƒ‰ãƒ©ã‚¤ãƒ–ã‚’æ¤œç´¢" autocomplete="off">
        <button type="button" id="search-clear-btn" class="search-clear-btn" aria-label="æ¤œç´¢èªžå¥ã‚’ã‚¯ãƒªã‚¢">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        </button>
      </form>

      <div class="header-actions">
        <span class="fw-badge" title="ç¨¼åƒä¸­ã®PHPãƒ•ãƒ¬ãƒ¼ãƒ ãƒ¯ãƒ¼ã‚¯"><span class="fw-dot" aria-hidden="true"></span><?= $e($frameworkLabel) ?></span>
        <a class="icon-btn hide-mobile" href="/about" aria-label="CloudDriveã®ä»•æ§˜ã¨ãƒ˜ãƒ«ãƒ—">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M11 18h2v-2h-2v2zm1-16C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm0-14c-2.21 0-4 1.79-4 4h2c0-1.1.9-2 2-2s2 .9 2 2c0 2-3 1.75-3 5h2c0-2.25 3-2.5 3-5 0-2.21-1.79-4-4-4z"/></svg>
        </a>
        <button type="button" id="details-toggle-btn" class="icon-btn" aria-label="è©³ç´°ãƒ‘ãƒãƒ«ã‚’åˆ‡ã‚Šæ›¿ãˆ" aria-pressed="false">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M11 7h2v2h-2zm0 4h2v6h-2zm1-9C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z"/></svg>
        </button>
        <div class="user-avatar" role="img" title="å±±ç”° å¤ªéƒŽ" aria-label="ãƒ¦ãƒ¼ã‚¶ãƒ¼ å±±ç”° å¤ªéƒŽ">å±±</div>
      </div>
    </header>

    <div id="drawer-scrim" class="drawer-scrim" aria-hidden="true"></div>

    <aside id="app-sidebar" class="app-sidebar" aria-label="ãƒ‰ãƒ©ã‚¤ãƒ–ãƒŠãƒ“ã‚²ãƒ¼ã‚·ãƒ§ãƒ³">
      <div class="new-btn-wrap">
        <button type="button" id="new-btn" class="new-btn" aria-haspopup="menu" aria-expanded="false" aria-controls="new-menu">
          <svg width="24" height="24" viewBox="0 0 36 36" aria-hidden="true">
            <path fill="#34A853" d="M16 16v14h4V20z"/>
            <path fill="#4285F4" d="M30 16H20l-4 4h14z"/>
            <path fill="#FBBC05" d="M6 16v4h10l4-4z"/>
            <path fill="#EA4335" d="M20 16V6h-4v14z"/>
          </svg>
          <span>æ–°è¦</span>
        </button>
      </div>

      <nav aria-label="ãƒ¡ã‚¤ãƒ³ãƒŠãƒ“ã‚²ãƒ¼ã‚·ãƒ§ãƒ³">
        <ul class="nav-list">
          <li>
            <a class="nav-link" href="/"<?= ($activeView ?? '') === 'my-drive' ? ' aria-current="page"' : '' ?>>
              <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19 2H5c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 18H5V4h14v16zM8 14h8v2H8zm0-4h8v2H8zm0-4h5v2H8z"/></svg>
              <span>ãƒžã‚¤ãƒ‰ãƒ©ã‚¤ãƒ–</span>
            </a>
          </li>
          <li>
            <a class="nav-link" href="/drive/shared"<?= ($activeView ?? '') === 'shared' ? ' aria-current="page"' : '' ?>>
              <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
              <span>å…±æœ‰ã‚¢ã‚¤ãƒ†ãƒ </span>
            </a>
          </li>
          <li>
            <a class="nav-link" href="/drive/recent"<?= ($activeView ?? '') === 'recent' ? ' aria-current="page"' : '' ?>>
              <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>
              <span>æœ€è¿‘ä½¿ç”¨ã—ãŸã‚¢ã‚¤ãƒ†ãƒ </span>
            </a>
          </li>
          <li>
            <a class="nav-link" href="/drive/starred"<?= ($activeView ?? '') === 'starred' ? ' aria-current="page"' : '' ?>>
              <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>
              <span>ã‚¹ã‚¿ãƒ¼ä»˜ã</span>
            </a>
          </li>
          <li>
            <a class="nav-link" href="/drive/trash"<?= ($activeView ?? '') === 'trash' ? ' aria-current="page"' : '' ?>>
              <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
              <span>ã‚´ãƒŸç®±</span>
            </a>
          </li>
        </ul>
      </nav>

      <hr class="sidebar-divider">

      <div class="storage-box">
        <div class="storage-header">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19.35 10.04A7.49 7.49 0 0 0 12 4C9.11 4 6.6 5.64 5.35 8.04A5.994 5.994 0 0 0 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96z"/></svg>
          <span>ä¿å­˜å®¹é‡</span>
        </div>
        <div class="storage-bar-track" role="progressbar" aria-label="ä¿å­˜å®¹é‡ã®ä½¿ç”¨çŽ‡" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)round($pct) ?>">
          <div id="storage-bar-fill" class="storage-bar-fill" style="width:<?= number_format($pct, 1) ?>%"></div>
        </div>
        <div id="storage-text" class="storage-text"><?= $e(UiHelper::formatBytes($usedBytes, false)) ?> / 15 GB ã‚’ä½¿ç”¨ä¸­</div>
        <a class="storage-cta" href="/about">ä»•æ§˜ãƒ»FAQã‚’è¦‹ã‚‹</a>
      </div>
    </aside>

    <div class="workspace-container">
      <main id="main-content" class="workspace-main">
        <?= $content ?>
      </main>

      <aside id="details-panel" class="details-panel" aria-label="ã‚¢ã‚¤ãƒ†ãƒ ã®è©³ç´°ã¨ã‚¢ã‚¯ãƒ†ã‚£ãƒ“ãƒ†ã‚£">
        <div class="details-header">
          <h2>è©³ç´°</h2>
          <button type="button" id="details-close-btn" class="icon-btn" aria-label="è©³ç´°ãƒ‘ãƒãƒ«ã‚’é–‰ã˜ã‚‹">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
          </button>
        </div>
        <div id="details-content">
          <p>ãƒ•ã‚¡ã‚¤ãƒ«ã¾ãŸã¯ãƒ•ã‚©ãƒ«ãƒ€ã‚’é¸æŠžã™ã‚‹ã¨ã€è©³ç´°æƒ…å ±ã¨ã‚¢ã‚¯ãƒ†ã‚£ãƒ“ãƒ†ã‚£å±¥æ­´ãŒè¡¨ç¤ºã•ã‚Œã¾ã™ã€‚</p>
        </div>
      </aside>
    </div>
  </div>

  <nav class="mobile-bottom-nav" aria-label="ãƒ¢ãƒã‚¤ãƒ«ãƒŠãƒ“ã‚²ãƒ¼ã‚·ãƒ§ãƒ³">
    <a class="mobile-nav-item" href="/"<?= ($activeView ?? '') === 'my-drive' ? ' aria-current="page"' : '' ?>>
      <span class="mobile-nav-pill"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg></span>
      <span>ãƒžã‚¤ãƒ‰ãƒ©ã‚¤ãƒ–</span>
    </a>
    <a class="mobile-nav-item" href="/drive/starred"<?= ($activeView ?? '') === 'starred' ? ' aria-current="page"' : '' ?>>
      <span class="mobile-nav-pill"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg></span>
      <span>ã‚¹ã‚¿ãƒ¼ä»˜ã</span>
    </a>
    <a class="mobile-nav-item" href="/drive/shared"<?= ($activeView ?? '') === 'shared' ? ' aria-current="page"' : '' ?>>
      <span class="mobile-nav-pill"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg></span>
      <span>å…±æœ‰</span>
    </a>
    <a class="mobile-nav-item" href="/drive/recent"<?= ($activeView ?? '') === 'recent' ? ' aria-current="page"' : '' ?>>
      <span class="mobile-nav-pill"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg></span>
      <span>æœ€è¿‘</span>
    </a>
  </nav>

  <button type="button" id="mobile-fab" class="mobile-fab" aria-label="æ–°è¦ä½œæˆã¾ãŸã¯ã‚¢ãƒƒãƒ—ãƒ­ãƒ¼ãƒ‰">
    <svg width="26" height="26" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
  </button>

  <div id="new-menu" class="menu-popover" role="menu" aria-label="æ–°è¦ä½œæˆãƒ¡ãƒ‹ãƒ¥ãƒ¼">
    <button type="button" id="menu-new-folder" class="menu-item" role="menuitem">æ–°ã—ã„ãƒ•ã‚©ãƒ«ãƒ€</button>
    <button type="button" id="menu-upload-file" class="menu-item" role="menuitem">ãƒ•ã‚¡ã‚¤ãƒ«ã®ã‚¢ãƒƒãƒ—ãƒ­ãƒ¼ãƒ‰</button>
  </div>
  <div id="item-menu" class="menu-popover" role="menu" aria-label="ã‚¢ã‚¤ãƒ†ãƒ æ“ä½œãƒ¡ãƒ‹ãƒ¥ãƒ¼"></div>
  <input id="file-upload-input" class="sr-only" type="file" multiple aria-label="ã‚¢ãƒƒãƒ—ãƒ­ãƒ¼ãƒ‰ã™ã‚‹ãƒ•ã‚¡ã‚¤ãƒ«ã‚’é¸æŠž">

  <div id="modal-backdrop" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <div class="modal-dialog">
      <h2 id="modal-title">ãƒ€ã‚¤ã‚¢ãƒ­ã‚°</h2>
      <div id="modal-body"></div>
      <div id="modal-footer" class="modal-footer"></div>
    </div>
  </div>

  <div id="preview-backdrop" class="preview-backdrop" role="dialog" aria-modal="true" aria-labelledby="preview-title">
    <div class="preview-topbar">
      <div class="preview-title-wrap">
        <button type="button" id="preview-close-btn" class="icon-btn" aria-label="ãƒ—ãƒ¬ãƒ“ãƒ¥ãƒ¼ã‚’é–‰ã˜ã‚‹">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
        </button>
        <h2 id="preview-title" class="preview-title">ãƒ•ã‚¡ã‚¤ãƒ«ãƒ—ãƒ¬ãƒ“ãƒ¥ãƒ¼</h2>
      </div>
      <div>
        <a id="preview-download-link" class="icon-btn" href="#" aria-label="ãƒ•ã‚¡ã‚¤ãƒ«ã‚’ãƒ€ã‚¦ãƒ³ãƒ­ãƒ¼ãƒ‰">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
        </a>
      </div>
    </div>
    <div id="preview-body" class="preview-body"></div>
  </div>

  <div id="toast-container" class="toast-container" aria-live="polite"></div>

  <?php if (!empty($driveState)): ?>
  <script id="drive-state" type="application/json"><?= $jsonSafe($driveState) ?></script>
  <?php endif; ?>
  <script src="/assets/app.js?v=2" defer></script>
</body>
</html><?php
            return (string)ob_get_clean();
        } catch (\Throwable $ex) {
            ob_end_clean();
            throw $ex;
        }
    }
    /** @param array<string, mixed> $__data */
    public static function render_drive(array $__data, callable $e, callable $jsonSafe): string
    {
        extract($__data, EXTR_SKIP);
        ob_start();
        try {
            ?><?php
$isTrash = ($activeView ?? '') === 'trash';
$typeFilters = [
    'all' => 'ã™ã¹ã¦',
    'folder' => 'ãƒ•ã‚©ãƒ«ãƒ€',
    'document' => 'ãƒ‰ã‚­ãƒ¥ãƒ¡ãƒ³ãƒˆ',
    'spreadsheet' => 'ã‚¹ãƒ—ãƒ¬ãƒƒãƒ‰ã‚·ãƒ¼ãƒˆ',
    'pdf' => 'PDF',
    'image' => 'ç”»åƒ',
];
?>
<div class="workspace-toolbar">
  <nav class="breadcrumb-nav" aria-label="ãƒ‘ãƒ³ããšãƒªã‚¹ãƒˆ">
    <h1 class="breadcrumb-title">
      <?php if (!empty($breadcrumbs)): ?>
        <a class="breadcrumb-link" href="/">ãƒžã‚¤ãƒ‰ãƒ©ã‚¤ãƒ–</a>
        <?php foreach ($breadcrumbs as $idx => $crumb): ?>
          <span class="breadcrumb-sep" aria-hidden="true">â€º</span>
          <?php if ($idx === count($breadcrumbs) - 1): ?>
            <span class="breadcrumb-current"><?= $e($crumb['name']) ?></span>
          <?php else: ?>
            <a class="breadcrumb-link" href="/drive/folders/<?= (int)$crumb['id'] ?>"><?= $e($crumb['name']) ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
      <?php else: ?>
        <span class="breadcrumb-current"><?= $e($headingTitle) ?></span>
      <?php endif; ?>
    </h1>
  </nav>

  <div class="toolbar-right">
    <div class="view-toggle" role="group" aria-label="è¡¨ç¤ºå½¢å¼ã®åˆ‡ã‚Šæ›¿ãˆ">
      <button type="button" id="view-list-btn" class="view-toggle-btn" aria-pressed="true" aria-label="ãƒªã‚¹ãƒˆè¡¨ç¤º">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/></svg>
        <span>ãƒªã‚¹ãƒˆ</span>
      </button>
      <button type="button" id="view-grid-btn" class="view-toggle-btn" aria-pressed="false" aria-label="ã‚°ãƒªãƒƒãƒ‰è¡¨ç¤º">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M4 11h5V5H4v6zm0 7h5v-6H4v6zm6 0h5v-6h-5v6zm6 0h5v-6h-5v6zm-6-7h5V5h-5v6zm6-6v6h5V5h-5z"/></svg>
        <span>ã‚°ãƒªãƒƒãƒ‰</span>
      </button>
    </div>
  </div>
</div>

<div class="filter-bar">
  <div class="filter-chips" role="group" aria-label="ãƒ•ã‚¡ã‚¤ãƒ«ç¨®åˆ¥ãƒ•ã‚£ãƒ«ã‚¿">
    <?php foreach ($typeFilters as $key => $label): ?>
      <button type="button" class="chip-btn" data-filter-type="<?= $e($key) ?>" aria-pressed="<?= ($type ?? 'all') === $key ? 'true' : 'false' ?>"><?= $e($label) ?></button>
    <?php endforeach; ?>
  </div>
  <div class="sort-controls">
    <label class="sr-only" for="sort-select">ä¸¦ã³æ›¿ãˆåŸºæº–</label>
    <select id="sort-select" class="sort-select">
      <option value="name"<?= ($sort ?? 'name') === 'name' ? ' selected' : '' ?>>åå‰é †</option>
      <option value="updated_at"<?= ($sort ?? '') === 'updated_at' ? ' selected' : '' ?>>æœ€çµ‚æ›´æ–°æ—¥</option>
      <option value="size"<?= ($sort ?? '') === 'size' ? ' selected' : '' ?>>ãƒ•ã‚¡ã‚¤ãƒ«ã‚µã‚¤ã‚º</option>
    </select>
    <button type="button" id="sort-dir-btn" class="chip-btn" title="ä¸¦ã³æ›¿ãˆé †åºã‚’åˆ‡ã‚Šæ›¿ãˆ"><?= ($dir ?? 'asc') === 'desc' ? 'é™é † â†“' : 'æ˜‡é † â†‘' ?></button>
  </div>
</div>

<?php if ($isTrash): ?>
<div class="trash-banner">
  <span>ã‚´ãƒŸç®±å†…ã®ã‚¢ã‚¤ãƒ†ãƒ ã¯å¾©å…ƒã¾ãŸã¯å®Œå…¨ã«å‰Šé™¤ã§ãã¾ã™ã€‚</span>
  <button type="button" id="empty-trash-btn" class="btn-secondary">ã‚´ãƒŸç®±ã‚’ç©ºã«ã™ã‚‹</button>
</div>
<?php endif; ?>

<div id="selection-bar" class="selection-bar" role="toolbar" aria-label="é¸æŠžã‚¢ã‚¤ãƒ†ãƒ ã®æ“ä½œ">
  <span id="selection-label" class="selection-label">é¸æŠžä¸­</span>
  <button type="button" id="sel-download-btn" class="action-pill-btn">ãƒ€ã‚¦ãƒ³ãƒ­ãƒ¼ãƒ‰</button>
  <button type="button" id="sel-star-btn" class="action-pill-btn">â˜† ã‚¹ã‚¿ãƒ¼ã‚’ä»˜ã‘ã‚‹</button>
  <button type="button" id="sel-share-btn" class="action-pill-btn">å…±æœ‰</button>
  <button type="button" id="sel-rename-btn" class="action-pill-btn">åå‰ã‚’å¤‰æ›´</button>
  <button type="button" id="sel-move-btn" class="action-pill-btn">ç§»å‹•</button>
  <button type="button" id="sel-restore-btn" class="action-pill-btn hidden">å¾©å…ƒã™ã‚‹</button>
  <button type="button" id="sel-trash-btn" class="action-pill-btn danger">ã‚´ãƒŸç®±ã«ç§»å‹•</button>
  <button type="button" id="sel-clear-btn" class="action-pill-btn">é¸æŠžè§£é™¤</button>
</div>

<div class="files-area">
  <div id="files-list-wrap" class="<?= empty($items) ? 'hidden' : '' ?>">
    <table class="drive-table">
      <thead>
        <tr>
          <th scope="col" class="col-name">åå‰</th>
          <th scope="col" class="col-owner">ã‚ªãƒ¼ãƒŠãƒ¼</th>
          <th scope="col" class="col-updated">æœ€çµ‚æ›´æ–°</th>
          <th scope="col" class="col-size">ãƒ•ã‚¡ã‚¤ãƒ«ã‚µã‚¤ã‚º</th>
          <th scope="col" class="col-actions"><span class="sr-only">æ“ä½œ</span></th>
        </tr>
      </thead>
      <tbody id="files-tbody">
        <?php foreach ($items as $item):
          $isFolder = (int)$item['is_folder'] === 1;
          $isTrashed = (int)$item['is_trashed'] === 1;
          $href = $isTrashed ? '#trash-' . (int)$item['id'] : ($isFolder ? '/drive/folders/' . (int)$item['id'] : '#preview-' . (int)$item['id']);
          $subText = UiHelper::formatDate($item['updated_at']) . ' ãƒ» ' . ($isFolder ? 'ãƒ•ã‚©ãƒ«ãƒ€' : UiHelper::formatBytes((int)$item['size_bytes'], false));
        ?>
        <tr class="drive-row" data-item-id="<?= (int)$item['id'] ?>" data-is-folder="<?= $isFolder ? 1 : 0 ?>" data-is-trashed="<?= $isTrashed ? 1 : 0 ?>">
          <td class="col-name">
            <div class="item-name-cell">
              <?= UiHelper::itemIconSvg($item) ?>
              <div class="item-text-stack">
                <a class="item-link" href="<?= $e($href) ?>" data-action="open" data-item-id="<?= (int)$item['id'] ?>" data-is-trashed="<?= $isTrashed ? 1 : 0 ?>">
                  <?= $e($item['name']) ?>
                  <?php if ((int)$item['is_starred'] === 1): ?>
                    <span class="badge-inline badge-star" title="ã‚¹ã‚¿ãƒ¼ä»˜ã" aria-label="ã‚¹ã‚¿ãƒ¼ä»˜ã">â˜…</span>
                  <?php endif; ?>
                  <?php if ((int)$item['is_shared'] === 1): ?>
                    <span class="badge-inline" title="å…±æœ‰ä¸­" aria-label="å…±æœ‰ä¸­"><svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg></span>
                  <?php endif; ?>
                </a>
                <span class="item-sub-mobile"><?= $e($subText) ?></span>
              </div>
            </div>
          </td>
          <td class="col-owner"><?= $e($item['owner_name']) ?></td>
          <td class="col-updated"><?= $e(UiHelper::formatDate($item['updated_at'])) ?></td>
          <td class="col-size"><?= $e(UiHelper::formatBytes((int)$item['size_bytes'], $isFolder)) ?></td>
          <td class="col-actions row-actions-cell">
            <button type="button" class="kebab-btn" data-action="menu" data-item-id="<?= (int)$item['id'] ?>" aria-label="<?= $e($item['name']) ?> ã®æ“ä½œãƒ¡ãƒ‹ãƒ¥ãƒ¼">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div id="files-grid-wrap" class="hidden"></div>

  <div id="empty-state" class="empty-state<?= empty($items) ? '' : ' hidden' ?>">
    <svg width="64" height="64" viewBox="0 0 24 24" fill="#c4c7c5" aria-hidden="true"><path d="M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>
    <h2>è¡¨ç¤ºã™ã‚‹ã‚¢ã‚¤ãƒ†ãƒ ãŒã‚ã‚Šã¾ã›ã‚“</h2>
    <p>ã€Œ+ æ–°è¦ã€ãƒœã‚¿ãƒ³ã¾ãŸã¯ãƒ‰ãƒ©ãƒƒã‚°ï¼†ãƒ‰ãƒ­ãƒƒãƒ—ã§ãƒ•ã‚¡ã‚¤ãƒ«ã‚’è¿½åŠ ã§ãã¾ã™ã€‚</p>
  </div>
</div>

<div id="drop-overlay" class="drop-overlay" aria-hidden="true">
  <svg width="48" height="48" viewBox="0 0 24 24" fill="#0b57d0"><path d="M19.35 10.04A7.49 7.49 0 0 0 12 4C9.11 4 6.6 5.64 5.35 8.04A5.994 5.994 0 0 0 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
  <p>ã“ã“ã«ãƒ‰ãƒ­ãƒƒãƒ—ã—ã¦ã‚¢ãƒƒãƒ—ãƒ­ãƒ¼ãƒ‰</p>
</div>

<footer class="semantic-footer">
  <span>CloudDrive â€” Google Driveæº–æ‹ ã®é«˜é€ŸPHP 8.3ã‚¯ãƒ©ã‚¦ãƒ‰ã‚¹ãƒˆãƒ¬ãƒ¼ã‚¸ï¼ˆç¨¼åƒä¸­: <?= $e($frameworkLabel) ?>ï¼‰</span>
  <div class="semantic-links">
    <a href="/about">ä»•æ§˜ãƒ»FAQ</a>
    <a href="/llms.txt">llms.txt</a>
    <a href="/llms-full.txt">llms-full.txt</a>
    <a href="/openapi.json">OpenAPI 3.1</a>
    <a href="/sitemap.xml">Sitemap</a>
  </div>
</footer><?php
            return (string)ob_get_clean();
        } catch (\Throwable $ex) {
            ob_end_clean();
            throw $ex;
        }
    }
    /** @param array<string, mixed> $__data */
    public static function render_about(array $__data, callable $e, callable $jsonSafe): string
    {
        extract($__data, EXTR_SKIP);
        ob_start();
        try {
            ?><article class="content-page">
  <h1>CloudDrive â€” ã‚µãƒ¼ãƒ“ã‚¹æ¦‚è¦ãƒ»æŠ€è¡“ä»•æ§˜ãƒ»FAQ</h1>

  <section class="tldr-box" aria-label="æ¦‚è¦ã‚µãƒžãƒªãƒ¼">
    <strong>è¦ç´„ï¼ˆGEO / AEO ã‚µãƒžãƒªãƒ¼ï¼‰:</strong>
    CloudDriveã¯ã€Google Driveã¨åŒä¸€ã®Material Design 3 UIãƒ»æ“ä½œæ€§ã‚’å‚™ãˆãŸé«˜é€ŸPHP 8.3ã‚¯ãƒ©ã‚¦ãƒ‰ã‚¹ãƒˆãƒ¬ãƒ¼ã‚¸ã‚¢ãƒ—ãƒªã‚±ãƒ¼ã‚·ãƒ§ãƒ³ã§ã™ã€‚ä¾å­˜ã‚¼ãƒ­ã®AIè‡ªä½œãƒ•ãƒ¬ãƒ¼ãƒ ãƒ¯ãƒ¼ã‚¯ã€Laravelã€Phalcon 5ï¼ˆCæ‹¡å¼µï¼‰ã€Slim 4ã®4ç’°å¢ƒã§å®Œå…¨åŒä¸€ä»•æ§˜ã‚’å®Ÿè£…ã—ã€PWAãƒ»SEOãƒ»AEOãƒ»GEOãƒ»AIOãƒ»LLMOãŠã‚ˆã³Lighthouseå…¨é …ç›®100ç‚¹ã‚’é”æˆã—ã¦ã„ã¾ã™ã€‚
  </section>

  <h2>æŠ€è¡“ä»•æ§˜ã¨å“è³ªåŸºæº–</h2>
  <table class="spec-table">
    <thead>
      <tr>
        <th scope="col">é …ç›®</th>
        <th scope="col">ä»•æ§˜ãƒ»é”æˆåŸºæº–</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>æ¯”è¼ƒãƒ•ãƒ¬ãƒ¼ãƒ ãƒ¯ãƒ¼ã‚¯</td>
        <td>AIè‡ªä½œãƒ•ãƒ¬ãƒ¼ãƒ ãƒ¯ãƒ¼ã‚¯ï¼ˆå¤–éƒ¨ãƒ©ã‚¤ãƒ–ãƒ©ãƒªä¸ä½¿ç”¨ï¼‰ã€Laravel 13ã€Phalcon 5.23ï¼ˆCæ‹¡å¼µï¼‰ã€Slim 4.15</td>
      </tr>
      <tr>
        <td>å®Ÿè¡Œç’°å¢ƒ</td>
        <td>PHP 8.3 (OPcacheæœ‰åŠ¹) + Nginx + ç‹¬ç«‹PHP-FPMãƒ—ãƒ¼ãƒ« + SQLite (WAL)</td>
      </tr>
      <tr>
        <td>ä¿å­˜å®¹é‡ãƒ»ã‚¢ãƒƒãƒ—ãƒ­ãƒ¼ãƒ‰</td>
        <td>15 GB ã‚¯ã‚©ãƒ¼ã‚¿ã€1ãƒ•ã‚¡ã‚¤ãƒ«æœ€å¤§ 20 MBã€ãƒ‰ãƒ©ãƒƒã‚°ï¼†ãƒ‰ãƒ­ãƒƒãƒ—ãƒ»ãƒžãƒ«ãƒã‚¢ãƒƒãƒ—ãƒ­ãƒ¼ãƒ‰å¯¾å¿œ</td>
      </tr>
      <tr>
        <td>PWAãƒ»ãƒ¢ãƒã‚¤ãƒ«å¯¾å¿œ</td>
        <td>Web App Manifestã€Service Worker ã‚ªãƒ•ãƒ©ã‚¤ãƒ³ã‚­ãƒ£ãƒƒã‚·ãƒ¥ã€ãƒ¢ãƒã‚¤ãƒ«ãƒœãƒˆãƒ ãƒŠãƒ“ãƒ»ãƒ‰ãƒ­ãƒ¯ãƒ¼ãƒ»FAB</td>
      </tr>
      <tr>
        <td>SEO / AEO / GEO / AIO / LLMO</td>
        <td>SSR HTMLã€JSON-LDï¼ˆWebApplication / SearchAction / BreadcrumbList / FAQPageï¼‰ã€robots.txtã€å‹•çš„sitemap.xmlã€llms.txtã€llms-full.txtã€OpenAPI 3.1</td>
      </tr>
      <tr>
        <td>ã‚»ã‚­ãƒ¥ãƒªãƒ†ã‚£</td>
        <td>ãƒ—ãƒªãƒšã‚¢ãƒ‰ã‚¹ãƒ†ãƒ¼ãƒˆãƒ¡ãƒ³ãƒˆã€HMAC-SHA256 CSRFä¿è­·ã€realpathæ¤œè¨¼ã€CSPã€å¾ªç’°ãƒ•ã‚©ãƒ«ãƒ€ç§»å‹•é˜²æ­¢</td>
      </tr>
    </tbody>
  </table>

  <h2>ã‚ˆãã‚ã‚‹è³ªå•ï¼ˆAEO / FAQï¼‰</h2>
  <?php foreach ($faqs as $faq): ?>
    <details class="faq-item" open>
      <summary><?= $e($faq['q']) ?></summary>
      <p><?= $e($faq['a']) ?></p>
    </details>
  <?php endforeach; ?>

  <h2>æ©Ÿæ¢°å¯èª­ãƒ»AIæ¤œç´¢å‘ã‘ã‚¨ãƒ³ãƒ‰ãƒã‚¤ãƒ³ãƒˆï¼ˆAIO / LLMOï¼‰</h2>
  <ul>
    <li><a href="/llms.txt">/llms.txt â€” LLMå‘ã‘Markdownã‚¤ãƒ³ãƒ‡ãƒƒã‚¯ã‚¹</a></li>
    <li><a href="/llms-full.txt">/llms-full.txt â€” LLMå‘ã‘å®Œå…¨æŠ€è¡“ãƒªãƒ•ã‚¡ãƒ¬ãƒ³ã‚¹</a></li>
    <li><a href="/openapi.json">/openapi.json â€” OpenAPI 3.1 REST APIå®šç¾©</a></li>
    <li><a href="/sitemap.xml">/sitemap.xml â€” å‹•çš„XMLã‚µã‚¤ãƒˆãƒžãƒƒãƒ—</a></li>
    <li><a href="/robots.txt">/robots.txt â€” æ¤œç´¢ãƒ»AIã‚¯ãƒ­ãƒ¼ãƒ©åˆ¶å¾¡ãƒ‡ã‚£ãƒ¬ã‚¯ãƒ†ã‚£ãƒ–</a></li>
    <li><a href="/manifest.webmanifest">/manifest.webmanifest â€” PWA Web App Manifest</a></li>
  </ul>
</article><?php
            return (string)ob_get_clean();
        } catch (\Throwable $ex) {
            ob_end_clean();
            throw $ex;
        }
    }
    /** @param array<string, mixed> $__data */
    public static function render_share(array $__data, callable $e, callable $jsonSafe): string
    {
        extract($__data, EXTR_SKIP);
        ob_start();
        try {
            ?><?php
$isFolder = (int)$item['is_folder'] === 1;
$isImg = !$isFolder && in_array(strtolower((string)$item['mime_type']), ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true);
?>
<article class="content-page">
  <h1><?= $e($item['name']) ?></h1>
  <p><?= $e($item['description'] !== '' ? $item['description'] : 'CloudDriveã§å…¬é–‹å…±æœ‰ã•ã‚Œã¦ã„ã‚‹ã‚¢ã‚¤ãƒ†ãƒ ã§ã™ã€‚') ?></p>

  <dl class="details-dl">
    <dt>ç¨®é¡ž</dt><dd><?= $isFolder ? 'å…±æœ‰ãƒ•ã‚©ãƒ«ãƒ€' : $e($item['mime_type']) ?></dd>
    <dt>ã‚µã‚¤ã‚º</dt><dd><?= $e(UiHelper::formatBytes((int)$item['size_bytes'], $isFolder)) ?></dd>
    <dt>ã‚ªãƒ¼ãƒŠãƒ¼</dt><dd><?= $e($item['owner_name']) ?></dd>
    <dt>æœ€çµ‚æ›´æ–°</dt><dd><?= $e(UiHelper::formatDate($item['updated_at'])) ?></dd>
  </dl>

  <?php if (!$isFolder): ?>
    <?php if ($isImg): ?>
      <p style="margin-top:16px"><img src="/api/files/<?= (int)$item['id'] ?>/preview" alt="<?= $e($item['name']) ?>" width="640" height="360" style="max-width:100%;height:auto;border-radius:8px;border:1px solid #dde3ea"></p>
    <?php endif; ?>
    <p style="margin-top:20px;display:flex;flex-wrap:wrap;gap:10px">
      <a class="btn-primary" style="display:inline-flex;align-items:center" href="/api/files/<?= (int)$item['id'] ?>/download">ã“ã®ãƒ•ã‚¡ã‚¤ãƒ«ã‚’ãƒ€ã‚¦ãƒ³ãƒ­ãƒ¼ãƒ‰</a>
      <a class="btn-secondary" style="display:inline-flex;align-items:center" href="/">ãƒžã‚¤ãƒ‰ãƒ©ã‚¤ãƒ–ã¸æˆ»ã‚‹</a>
    </p>
  <?php else: ?>
    <h2>ãƒ•ã‚©ãƒ«ãƒ€å†…ã®ã‚¢ã‚¤ãƒ†ãƒ </h2>
    <?php if (empty($children)): ?>
      <p>ã“ã®å…±æœ‰ãƒ•ã‚©ãƒ«ãƒ€ã¯ç©ºã§ã™ã€‚</p>
    <?php else: ?>
      <table class="spec-table">
        <thead>
          <tr><th scope="col">åå‰</th><th scope="col">æ›´æ–°æ—¥</th><th scope="col">ã‚µã‚¤ã‚º</th><th scope="col">æ“ä½œ</th></tr>
        </thead>
        <tbody>
          <?php foreach ($children as $ch):
            $chFolder = (int)$ch['is_folder'] === 1;
          ?>
            <tr>
              <td><?= $e($ch['name']) ?></td>
              <td><?= $e(UiHelper::formatDate($ch['updated_at'])) ?></td>
              <td><?= $e(UiHelper::formatBytes((int)$ch['size_bytes'], $chFolder)) ?></td>
              <td>
                <?php if (!$chFolder): ?>
                  <a href="/api/files/<?= (int)$ch['id'] ?>/download">ãƒ€ã‚¦ãƒ³ãƒ­ãƒ¼ãƒ‰</a>
                <?php else: ?>
                  ãƒ•ã‚©ãƒ«ãƒ€
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <p style="margin-top:16px"><a class="btn-secondary" style="display:inline-flex;align-items:center" href="/">ãƒžã‚¤ãƒ‰ãƒ©ã‚¤ãƒ–ã¸æˆ»ã‚‹</a></p>
  <?php endif; ?>
</article><?php
            return (string)ob_get_clean();
        } catch (\Throwable $ex) {
            ob_end_clean();
            throw $ex;
        }
    }
    /** @param array<string, mixed> $__data */
    public static function render_offline(array $__data, callable $e, callable $jsonSafe): string
    {
        extract($__data, EXTR_SKIP);
        ob_start();
        try {
            ?><article class="content-page">
  <h1>ã‚ªãƒ•ãƒ©ã‚¤ãƒ³çŠ¶æ…‹ã§ã™</h1>
  <p>ã‚¤ãƒ³ã‚¿ãƒ¼ãƒãƒƒãƒˆæŽ¥ç¶šãŒä¸€æ™‚çš„ã«åˆ‡æ–­ã•ã‚Œã¦ã„ã¾ã™ã€‚æŽ¥ç¶šãŒå›žå¾©ã—ãŸã‚‰å†èª­ã¿è¾¼ã¿ã—ã¦æœ€æ–°ã®CloudDriveãƒ•ã‚¡ã‚¤ãƒ«ã«ã‚¢ã‚¯ã‚»ã‚¹ã—ã¦ãã ã•ã„ã€‚</p>
  <p><a class="btn-primary" style="display:inline-flex;align-items:center" href="/">ãƒžã‚¤ãƒ‰ãƒ©ã‚¤ãƒ–ã‚’å†èª­ã¿è¾¼ã¿</a></p>
</article><?php
            return (string)ob_get_clean();
        } catch (\Throwable $ex) {
            ob_end_clean();
            throw $ex;
        }
    }
}

final class View
{
    public function __construct(private readonly string $templateDir = '') {}

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        $content = $this->renderTemplate($template, $data);
        if ($layout === null) {
            return $content;
        }
        return $this->renderTemplate($layout, array_merge($data, ['content' => $content]));
    }

    /** @param array<string, mixed> $data */
    private function renderTemplate(string $template, array $data): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $template);
        $method = 'render_' . $safe;
        if (!method_exists(EmbeddedTemplates::class, $method)) {
            throw new \RuntimeException("Template not found: {$safe}");
        }
        $e = static fn(mixed $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $jsonSafe = static fn(mixed $v): string => json_encode(
            $v,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
        return EmbeddedTemplates::$method($data, $e, $jsonSafe);
    }
}
}

namespace App\Services {
use AiFramework\Database;
use AiFramework\HttpException;
use AiFramework\Security;

final class DriveService
{
    public const QUOTA_BYTES = 16106127360; // 15 GB
    public const MAX_UPLOAD_BYTES = 20971520; // 20 MB
    public const MAX_DEPTH = 32;

    private const ALLOWED_SORT = [
        'name' => 'name COLLATE NOCASE',
        'updated_at' => 'updated_at',
        'size' => 'size_bytes',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly string $uploadsDir,
    ) {}

    /**
     * @return array{items:list<array<string,mixed>>, breadcrumbs:list<array{id:int,name:string}>, folder:?array<string,mixed>, storage:array<string,mixed>}
     */
    public function listItems(
        string $view = 'my-drive',
        ?int $folderId = null,
        string $q = '',
        string $type = 'all',
        string $sort = 'name',
        string $dir = 'asc',
    ): array {
        $folder = null;
        $breadcrumbs = [];

        if ($folderId !== null) {
            $folder = $this->db->fetchOne('SELECT * FROM items WHERE id = ? AND is_folder = 1 AND is_trashed = 0', [$folderId]);
            if ($folder === null) {
                throw new HttpException(404, 'ãƒ•ã‚©ãƒ«ãƒ€ãŒè¦‹ã¤ã‹ã‚Šã¾ã›ã‚“');
            }
            $breadcrumbs = $this->getBreadcrumbs($folderId);
        }

        $where = [];
        $params = [];

        $q = trim(mb_scrub($q, 'UTF-8'));
        if ($q !== '') {
            $where[] = $view === 'trash' ? 'is_trashed = 1' : 'is_trashed = 0';
            if ($view === 'starred') {
                $where[] = 'is_starred = 1';
            } elseif ($view === 'shared') {
                $where[] = 'is_shared = 1';
            } elseif ($view === 'recent') {
                $where[] = 'is_folder = 0';
            }
            $where[] = "(name LIKE ? ESCAPE '\\' OR description LIKE ? ESCAPE '\\')";
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
            $like = '%' . $escaped . '%';
            $params[] = $like;
            $params[] = $like;
            if ($folderId !== null) {
                $where[] = 'parent_id = ?';
                $params[] = $folderId;
            }
        } else {
            switch ($view) {
                case 'folders':
                    $where[] = 'is_trashed = 0';
                    $where[] = 'is_folder = 1';
                    break;
                case 'recent':
                    $where[] = 'is_trashed = 0';
                    $where[] = 'is_folder = 0';
                    break;
                case 'starred':
                    $where[] = 'is_trashed = 0';
                    $where[] = 'is_starred = 1';
                    break;
                case 'shared':
                    $where[] = 'is_trashed = 0';
                    $where[] = 'is_shared = 1';
                    break;
                case 'trash':
                    $where[] = 'is_trashed = 1';
                    break;
                case 'my-drive':
                default:
                    $where[] = 'is_trashed = 0';
                    if ($folderId === null) {
                        $where[] = 'parent_id IS NULL';
                    } else {
                        $where[] = 'parent_id = ?';
                        $params[] = $folderId;
                    }
                    break;
            }
        }

        switch ($type) {
            case 'folder':
                $where[] = 'is_folder = 1';
                break;
            case 'document':
                $where[] = "is_folder = 0 AND extension IN ('md','txt','doc','docx','json')";
                break;
            case 'spreadsheet':
                $where[] = "is_folder = 0 AND extension IN ('csv','xls','xlsx')";
                break;
            case 'pdf':
                $where[] = "is_folder = 0 AND (extension = 'pdf' OR mime_type = 'application/pdf')";
                break;
            case 'image':
                $where[] = "is_folder = 0 AND (mime_type LIKE 'image/%' OR extension IN ('png','jpg','jpeg','webp','gif'))";
                break;
            default:
                break;
        }

        $sortCol = self::ALLOWED_SORT[$sort] ?? self::ALLOWED_SORT['name'];
        $sortDir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        $orderBy = ($view === 'recent' && !isset(self::ALLOWED_SORT[$sort]))
            ? "last_opened_at {$sortDir}, updated_at {$sortDir}"
            : ($view === 'recent' && $sort === 'updated_at'
                ? "last_opened_at {$sortDir}, updated_at {$sortDir}"
                : "is_folder DESC, {$sortCol} {$sortDir}, id ASC");

        $sql = 'SELECT id, parent_id, name, is_folder, mime_type, extension, size_bytes, owner_name, owner_email, description, is_starred, is_shared, share_token, is_trashed, trashed_at, last_opened_at, created_at, updated_at FROM items WHERE '
            . implode(' AND ', $where)
            . " ORDER BY {$orderBy} LIMIT 200";

        $items = $this->db->fetchAll($sql, $params);

        return [
            'items' => array_map([$this, 'normalizeItem'], $items),
            'breadcrumbs' => $breadcrumbs,
            'folder' => $folder ? $this->normalizeItem($folder) : null,
            'storage' => $this->getStorageSummary(),
        ];
    }

    /** @return list<array{id:int,name:string}> */
    public function getBreadcrumbs(int $folderId): array
    {
        $rows = $this->db->fetchAll(
            'WITH RECURSIVE anc(id, parent_id, name, depth) AS (
                SELECT id, parent_id, name, 0 FROM items WHERE id = ? AND is_folder = 1
                UNION
                SELECT i.id, i.parent_id, i.name, a.depth + 1
                FROM items i JOIN anc a ON i.id = a.parent_id
                WHERE a.depth < 32
            )
            SELECT id, name FROM anc ORDER BY depth DESC',
            [$folderId]
        );
        return array_map(static fn(array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name']], $rows);
    }

    /** @return array{item:array<string,mixed>, activities:list<array<string,mixed>>, breadcrumbs:list<array{id:int,name:string}>} */
    public function getItemDetail(int $id): array
    {
        $item = $this->db->fetchOne('SELECT * FROM items WHERE id = ?', [$id]);
        if ($item === null) {
            throw new HttpException(404, 'ã‚¢ã‚¤ãƒ†ãƒ ãŒè¦‹ã¤ã‹ã‚Šã¾ã›ã‚“');
        }
        $activities = $this->db->fetchAll(
            'SELECT id, item_id, action, actor_name, detail, created_at FROM activities WHERE item_id = ? ORDER BY id DESC LIMIT 20',
            [$id]
        );
        $breadcrumbs = $item['parent_id'] !== null ? $this->getBreadcrumbs((int)$item['parent_id']) : [];

        return [
            'item' => $this->normalizeItem($item),
            'activities' => $activities,
            'breadcrumbs' => $breadcrumbs,
        ];
    }

    /** @return array<string, mixed>|null */
    public function getSharedByToken(string $token): ?array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{4,64}$/', $token)) {
            return null;
        }
        $item = $this->db->fetchOne(
            'SELECT * FROM items WHERE share_token = ? AND is_shared = 1 AND is_trashed = 0',
            [$token]
        );
        return $item ? $this->normalizeItem($item) : null;
    }

    /** @return list<array<string, mixed>> */
    public function getSharedChildren(int $folderId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM items WHERE parent_id = ? AND is_trashed = 0 ORDER BY is_folder DESC, name COLLATE NOCASE ASC LIMIT 100',
            [$folderId]
        );
        return array_map([$this, 'normalizeItem'], $rows);
    }

    public function latestUpdatedDate(): string
    {
        $val = (string)($this->db->fetchValue('SELECT MAX(updated_at) FROM items WHERE is_trashed = 0') ?? '');
        return strlen($val) >= 10 ? substr($val, 0, 10) : gmdate('Y-m-d');
    }

    /** @return array<string, mixed> */
    public function createFolder(string $rawName, ?int $parentId): array
    {
        $name = Security::sanitizeItemName($rawName);
        if ($name === '') {
            throw new HttpException(422, 'æœ‰åŠ¹ãªãƒ•ã‚©ãƒ«ãƒ€åã‚’å…¥åŠ›ã—ã¦ãã ã•ã„');
        }
        if ($parentId !== null) {
            $parent = $this->db->fetchOne('SELECT id FROM items WHERE id = ? AND is_folder = 1 AND is_trashed = 0', [$parentId]);
            if ($parent === null) {
                throw new HttpException(404, 'è¦ªãƒ•ã‚©ãƒ«ãƒ€ãŒè¦‹ã¤ã‹ã‚Šã¾ã›ã‚“');
            }
            if ($this->ancestorDepth($parentId) + 1 >= self::MAX_DEPTH) {
                throw new HttpException(422, 'ãƒ•ã‚©ãƒ«ãƒ€ã®éšŽå±¤ä¸Šé™ï¼ˆ32éšŽå±¤ï¼‰ã‚’è¶…ãˆã¦ã„ã¾ã™');
            }
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return $this->db->transaction(function (Database $db) use ($name, $parentId, $now): array {
            $db->execute(
                'INSERT INTO items (parent_id, name, is_folder, mime_type, extension, size_bytes, storage_name, owner_name, owner_email, description, is_starred, is_shared, share_token, is_trashed, trashed_at, last_opened_at, created_at, updated_at)
                 VALUES (?, ?, 1, ?, ?, 0, NULL, ?, ?, ?, 0, 0, NULL, 0, NULL, ?, ?, ?)',
                [$parentId, $name, 'application/vnd.clouddrive.folder', '', 'å±±ç”° å¤ªéƒŽ', 'taro.yamada@clouddrive.example', '', $now, $now, $now]
            );
            $id = $db->lastInsertId();
            $db->execute(
                'INSERT INTO activities (item_id, action, actor_name, detail, created_at) VALUES (?, ?, ?, ?, ?)',
                [$id, 'created', 'å±±ç”° å¤ªéƒŽ', "ãƒ•ã‚©ãƒ«ãƒ€ã€Œ{$name}ã€ã‚’ä½œæˆã—ã¾ã—ãŸ", $now]
            );
            $row = $db->fetchOne('SELECT * FROM items WHERE id = ?', [$id]);
            return $this->normalizeItem($row ?? []);
        });
    }

    /** @param array<string, mixed> $file @return array<string, mixed> */
    public function uploadFile(array $file, ?int $parentId, string $description = ''): array
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            throw new HttpException(422, 'å˜ä¸€ãƒ•ã‚¡ã‚¤ãƒ«ã¨ã—ã¦ã‚¢ãƒƒãƒ—ãƒ­ãƒ¼ãƒ‰ã—ã¦ãã ã•ã„');
        }
        $error = (int)$file['error'];
        if ($error !== UPLOAD_ERR_OK) {
            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                throw new HttpException(413, 'ãƒ•ã‚¡ã‚¤ãƒ«ã‚µã‚¤ã‚ºãŒä¸Šé™ï¼ˆ20MBï¼‰ã‚’è¶…ãˆã¦ã„ã¾ã™');
            }
            throw new HttpException(422, 'ãƒ•ã‚¡ã‚¤ãƒ«ã®ã‚¢ãƒƒãƒ—ãƒ­ãƒ¼ãƒ‰ã«å¤±æ•—ã—ã¾ã—ãŸ');
        }

        $tmpPath = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new HttpException(422, 'æœ‰åŠ¹ãªã‚¢ãƒƒãƒ—ãƒ­ãƒ¼ãƒ‰ãƒ•ã‚¡ã‚¤ãƒ«ãŒè¦‹ã¤ã‹ã‚Šã¾ã›ã‚“');
        }

        $size = (int)filesize($tmpPath);
        if ($size > self::MAX_UPLOAD_BYTES) {
            throw new HttpException(413, 'ãƒ•ã‚¡ã‚¤ãƒ«ã‚µã‚¤ã‚ºãŒä¸Šé™ï¼ˆ20MBï¼‰ã‚’è¶…ãˆã¦ã„ã¾ã™');
        }

        if ($parentId !== null) {
            $parent = $this->db->fetchOne('SELECT id FROM items WHERE id = ? AND is_folder = 1 AND is_trashed = 0', [$parentId]);
            if ($parent === null) {
                throw new HttpException(404, 'ã‚¢ãƒƒãƒ—ãƒ­ãƒ¼ãƒ‰å…ˆãƒ•ã‚©ãƒ«ãƒ€ãŒè¦‹ã¤ã‹ã‚Šã¾ã›ã‚“');
            }
        }

        $origName = Security::sanitizeItemName(is_string($file['name'] ?? null) ? $file['name'] : 'untitled');
        if ($origName === '') {
            $origName = 'untitled';
        }
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($origName, PATHINFO_EXTENSION))) ?? '';

        $detectedMime = class_exists(\finfo::class) ? (string)((new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath) ?: 'application/octet-stream') : 'application/octet-stream';
        if ($detectedMime === 'application/x-empty' || $detectedMime === 'text/plain') {
            $detectedMime = match ($ext) {
                'md' => 'text/markdown',
                'csv' => 'text/csv',
                'json' => 'application/json',
                'txt' => 'text/plain',
                default => $detectedMime === 'application/x-empty' ? 'text/plain' : $detectedMime,
            };
        } elseif ($ext === 'csv' && $detectedMime === 'application/csv') {
            $detectedMime = 'text/csv';
        }

        // Always use inert .blob extension on disk (defense-in-depth against executable uploads)
        $storageName = 'up-' . bin2hex(random_bytes(16)) . '.blob';
        @mkdir($this->uploadsDir, 0775, true);
        $destPath = $this->uploadsDir . DIRECTORY_SEPARATOR . $storageName;

        if (!move_uploaded_file($tmpPath, $destPath)) {
            throw new HttpException(500, 'ãƒ•ã‚¡ã‚¤ãƒ«ã®ä¿å­˜ã«å¤±æ•—ã—ã¾ã—ãŸ');
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $cleanDesc = mb_substr(trim(mb_scrub($description, 'UTF-8')), 0, 1000, 'UTF-8');

        try {
            return $this->db->transaction(function (Database $db) use ($parentId, $origName, $detectedMime, $ext, $size, $storageName, $cleanDesc, $now): array {
                $used = (int)($db->fetchValue('SELECT COALESCE(SUM(CASE WHEN is_folder = 0 THEN size_bytes ELSE 0 END), 0) FROM items') ?? 0);
                if (($used + $size) > self::QUOTA_BYTES) {
                    throw new HttpException(413, 'ä¿å­˜å®¹é‡ã®ä¸Šé™ï¼ˆ15GBï¼‰ã‚’è¶…ãˆã¦ã„ã¾ã™');
                }
                $db->execute(
                    'INSERT INTO items (parent_id, name, is_folder, mime_type, extension, size_bytes, storage_name, owner_name, owner_email, description, is_starred, is_shared, share_token, is_trashed, trashed_at, last_opened_at, created_at, updated_at)
                     VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?, ?, 0, 0, NULL, 0, NULL, ?, ?, ?)',
                    [$parentId, $origName, $detectedMime, $ext, $size, $storageName, 'å±±ç”° å¤ªéƒŽ', 'taro.yamada@clouddrive.example', $cleanDesc, $now, $now, $now]
                );
                $id = $db->lastInsertId();
                $db->execute(
                    'INSERT INTO activities (item_id, action, actor_name, detail, created_at) VALUES (?, ?, ?, ?, ?)',
                    [$id, 'uploaded', 'å±±ç”° å¤ªéƒŽ', "ãƒ•ã‚¡ã‚¤ãƒ«ã€Œ{$origName}ã€ã‚’ã‚¢ãƒƒãƒ—ãƒ­ãƒ¼ãƒ‰ã—ã¾ã—ãŸ", $now]
                );
                $row = $db->fetchOne('SELECT * FROM items WHERE id = ?', [$id]);
                return $this->normalizeItem($row ?? []);
            }, true);
        } catch (\Throwable $e) {
            @unlink($destPath);
            throw $e;
        }
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function updateItem(int $id, array $payload): array
    {
        $item = $this->db->fetchOne('SELECT * FROM items WHERE id = ?', [$id]);
        if ($item === null) {
            throw new HttpException(404, 'ã‚¢ã‚¤ãƒ†ãƒ ãŒè¦‹ã¤ã‹ã‚Šã¾ã›ã‚“');
        }
        if ((int)$item['is_trashed'] === 1) {
            throw new HttpException(422, 'ã‚´ãƒŸç®±å†…ã®ã‚¢ã‚¤ãƒ†ãƒ ã¯ç·¨é›†ã§ãã¾ã›ã‚“ã€‚å…ˆã«å¾©å…ƒã—ã¦ãã ã•ã„');
        }

        $updates = [];
        $params = [];
        $activityLogs = [];

        if (array_key_exists('name', $payload)) {
            $rawName = Security::requireString($payload['name'], 'name');
            $newName = Security::sanitizeItemName((string)$rawName);
            if ($newName === '') {
                throw new HttpException(422, 'æœ‰åŠ¹ãªåå‰ã‚’å…¥åŠ›ã—ã¦ãã ã•ã„');
            }
            $updates[] = 'name = ?';
            $params[] = $newName;
            if ((int)$item['is_folder'] === 0) {
                $newExt = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($newName, PATHINFO_EXTENSION))) ?? '';
                $updates[] = 'extension = ?';
                $params[] = $newExt;
            }
            $activityLogs[] = ['renamed', "åå‰ã‚’ã€Œ{$newName}ã€ã«å¤‰æ›´ã—ã¾ã—ãŸ"];
        }

        if (array_key_exists('parent_id', $payload)) {
            $newParent = Security::parseOptionalPositiveInt($payload['parent_id'], 'parent_id', 422);
            if ($newParent !== null) {
                if ($newParent === $id) {
                    throw new HttpException(422, 'è‡ªåˆ†è‡ªèº«ã®é…ä¸‹ã«ã¯ç§»å‹•ã§ãã¾ã›ã‚“');
                }
                $target = $this->db->fetchOne('SELECT id FROM items WHERE id = ? AND is_folder = 1 AND is_trashed = 0', [$newParent]);
                if ($target === null) {
                    throw new HttpException(404, 'ç§»å‹•å…ˆãƒ•ã‚©ãƒ«ãƒ€ãŒè¦‹ã¤ã‹ã‚Šã¾ã›ã‚“');
                }
                if ((int)$item['is_folder'] === 1) {
                    if ($this->isDescendant($newParent, $id)) {
                        throw new HttpException(422, 'å­å­«ãƒ•ã‚©ãƒ«ãƒ€ã¸å¾ªç’°ç§»å‹•ã™ã‚‹ã“ã¨ã¯ã§ãã¾ã›ã‚“');
                    }
                    $newTotalDepth = $this->ancestorDepth($newParent) + 1 + $this->subtreeHeight($id);
                    if ($newTotalDepth >= self::MAX_DEPTH) {
                        throw new HttpException(422, 'ç§»å‹•å…ˆã®ãƒ•ã‚©ãƒ«ãƒ€éšŽå±¤ãŒä¸Šé™ï¼ˆ32éšŽå±¤ï¼‰ã‚’è¶…ãˆã¦ã„ã¾ã™');
                    }
                }
            }
            $updates[] = 'parent_id = ?';
            $params[] = $newParent;
            $activityLogs[] = ['moved', 'ä¿å­˜å ´æ‰€ã‚’ç§»å‹•ã—ã¾ã—ãŸ'];
        }

        if (array_key_exists('is_starred', $payload)) {
            $starred = filter_var($payload['is_starred'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            $updates[] = 'is_starred = ?';
            $params[] = $starred;
            $activityLogs[] = ['starred', $starred ? 'ã‚¹ã‚¿ãƒ¼ã‚’ä»˜ã‘ã¾ã—ãŸ' : 'ã‚¹ã‚¿ãƒ¼ã‚’å¤–ã—ã¾ã—ãŸ'];
        }

        if (array_key_exists('is_shared', $payload)) {
            $shared = filter_var($payload['is_shared'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            $updates[] = 'is_shared = ?';
            $params[] = $shared;
            if ($shared === 1) {
                $updates[] = 'share_token = ?';
                $params[] = !empty($item['share_token']) ? (string)$item['share_token'] : bin2hex(random_bytes(12));
            } else {
                // Rotate/revoke share token on unshare (SEC-10)
                $updates[] = 'share_token = NULL';
            }
            $activityLogs[] = ['shared', $shared ? 'å…±æœ‰ãƒªãƒ³ã‚¯ã‚’æœ‰åŠ¹ã«ã—ã¾ã—ãŸ' : 'å…±æœ‰ã‚’åœæ­¢ã—ã¾ã—ãŸ'];
        }

        if (array_key_exists('description', $payload)) {
            $rawDesc = Security::requireString($payload['description'], 'description');
            $desc = mb_substr(trim((string)$rawDesc), 0, 1000, 'UTF-8');
            $updates[] = 'description = ?';
            $params[] = $desc;
            $activityLogs[] = ['edited', 'èª¬æ˜Žã‚’æ›´æ–°ã—ã¾ã—ãŸ'];
        }

        if ($updates === []) {
            return $this->normalizeItem($item);
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $updates[] = 'updated_at = ?';
        $params[] = $now;
        $params[] = $id;

        return $this->db->transaction(function (Database $db) use ($updates, $params, $activityLogs, $id, $now): array {
            $db->execute('UPDATE items SET ' . implode(', ', $updates) . ' WHERE id = ?', $params);
            foreach ($activityLogs as [$action, $detail]) {
                $db->execute(
                    'INSERT INTO activities (item_id, action, actor_name, detail, created_at) VALUES (?, ?, ?, ?, ?)',
                    [$id, $action, 'å±±ç”° å¤ªéƒŽ', $detail, $now]
                );
            }
            $row = $db->fetchOne('SELECT * FROM items WHERE id = ?', [$id]);
            return $this->normalizeItem($row ?? []);
        });
    }

    /** @return array<string, mixed> */
    public function deleteItem(int $id, bool $permanent = false): array
    {
        $item = $this->db->fetchOne('SELECT * FROM items WHERE id = ?', [$id]);
        if ($item === null) {
            throw new HttpException(404, 'ã‚¢ã‚¤ãƒ†ãƒ ãŒè¦‹ã¤ã‹ã‚Šã¾ã›ã‚“');
        }

        if (!$permanent && (int)$item['is_trashed'] === 0) {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $this->db->transaction(function (Database $db) use ($id, $now): void {
                // Recursively soft-delete item and all active descendants (SEC-07)
                $db->execute(
                    'WITH RECURSIVE tree(id) AS (
                        SELECT id FROM items WHERE id = ?
                        UNION
                        SELECT i.id FROM items i JOIN tree t ON i.parent_id = t.id
                    )
                    UPDATE items SET is_trashed = 1, trashed_at = ?, updated_at = ?
                    WHERE id IN (SELECT id FROM tree) AND is_trashed = 0',
                    [$id, $now, $now]
                );
                $db->execute(
                    'INSERT INTO activities (item_id, action, actor_name, detail, created_at) VALUES (?, ?, ?, ?, ?)',
                    [$id, 'trashed', 'å±±ç”° å¤ªéƒŽ', 'ã‚´ãƒŸç®±ã¸ç§»å‹•ã—ã¾ã—ãŸ', $now]
                );
            });
            return ['deleted' => true, 'permanent' => false, 'id' => $id];
        }

        // Atomic permanent delete with cycle-safe UNION CTE (SEC-02, SEC-13)
        $storageFiles = $this->db->transaction(function (Database $db) use ($id): array {
            $files = $db->fetchAll(
                'WITH RECURSIVE tree(id, storage_name) AS (
                    SELECT id, storage_name FROM items WHERE id = ?
                    UNION
                    SELECT i.id, i.storage_name FROM items i JOIN tree t ON i.parent_id = t.id
                )
                SELECT storage_name FROM tree WHERE storage_name IS NOT NULL',
                [$id]
            );
            $db->execute('DELETE FROM items WHERE id = ?', [$id]);
            return $files;
        }, true);

        foreach ($storageFiles as $f) {
            $path = Security::resolveJailedPath($this->uploadsDir, (string)$f['storage_name']);
            if ($path !== null) {
                @unlink($path);
            }
        }
        return ['deleted' => true, 'permanent' => true, 'id' => $id];
    }

    /** @return array<string, mixed> */
    public function restoreItem(int $id): array
    {
        $item = $this->db->fetchOne('SELECT * FROM items WHERE id = ? AND is_trashed = 1', [$id]);
        if ($item === null) {
            throw new HttpException(404, 'ã‚´ãƒŸç®±å†…ã«ã‚¢ã‚¤ãƒ†ãƒ ãŒè¦‹ã¤ã‹ã‚Šã¾ã›ã‚“');
        }

        $parentId = $item['parent_id'] !== null ? (int)$item['parent_id'] : null;
        if ($parentId !== null && !$this->isAncestorChainActive($parentId)) {
            $parentId = null;
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $trashedAt = $item['trashed_at'];

        return $this->db->transaction(function (Database $db) use ($id, $parentId, $now, $trashedAt): array {
            $db->execute(
                'UPDATE items SET is_trashed = 0, trashed_at = NULL, parent_id = ?, updated_at = ? WHERE id = ?',
                [$parentId, $now, $id]
            );
            // Also restore descendants trashed together with this folder
            if ($trashedAt !== null) {
                $db->execute(
                    'WITH RECURSIVE tree(id) AS (
                        SELECT id FROM items WHERE parent_id = ?
                        UNION
                        SELECT i.id FROM items i JOIN tree t ON i.parent_id = t.id
                    )
                    UPDATE items SET is_trashed = 0, trashed_at = NULL, updated_at = ?
                    WHERE id IN (SELECT id FROM tree) AND is_trashed = 1 AND trashed_at = ?',
                    [$id, $now, $trashedAt]
                );
            }
            $db->execute(
                'INSERT INTO activities (item_id, action, actor_name, detail, created_at) VALUES (?, ?, ?, ?, ?)',
                [$id, 'restored', 'å±±ç”° å¤ªéƒŽ', 'ã‚´ãƒŸç®±ã‹ã‚‰å¾©å…ƒã—ã¾ã—ãŸ', $now]
            );
            $row = $db->fetchOne('SELECT * FROM items WHERE id = ?', [$id]);
            return $this->normalizeItem($row ?? []);
        });
    }

    /** @return array{emptied:int} */
    public function emptyTrash(): array
    {
        // Single atomic transaction avoids cascading 404 crashes (SEC-07)
        $result = $this->db->transaction(function (Database $db): array {
            $count = (int)($db->fetchValue('SELECT COUNT(*) FROM items WHERE is_trashed = 1') ?? 0);
            $files = $db->fetchAll(
                'WITH RECURSIVE tree(id, storage_name) AS (
                    SELECT id, storage_name FROM items WHERE is_trashed = 1
                    UNION
                    SELECT i.id, i.storage_name FROM items i JOIN tree t ON i.parent_id = t.id
                )
                SELECT storage_name FROM tree WHERE storage_name IS NOT NULL'
            );
            $db->execute('DELETE FROM items WHERE is_trashed = 1');
            return ['count' => $count, 'files' => $files];
        }, true);

        foreach ($result['files'] as $f) {
            $path = Security::resolveJailedPath($this->uploadsDir, (string)$f['storage_name']);
            if ($path !== null) {
                @unlink($path);
            }
        }
        return ['emptied' => $result['count']];
    }

    /** @return array{item:array<string,mixed>, path:string} */
    public function resolveFileForTransfer(int $id, bool $touchOpened = true): array
    {
        $item = $this->db->fetchOne('SELECT * FROM items WHERE id = ? AND is_folder = 0 AND is_trashed = 0', [$id]);
        if ($item === null || empty($item['storage_name'])) {
            throw new HttpException(404, 'ãƒ•ã‚¡ã‚¤ãƒ«ãŒè¦‹ã¤ã‹ã‚Šã¾ã›ã‚“');
        }
        $path = Security::resolveJailedPath($this->uploadsDir, (string)$item['storage_name']);
        if ($path === null) {
            throw new HttpException(404, 'ã‚¹ãƒˆãƒ¬ãƒ¼ã‚¸ä¸Šã«ãƒ•ã‚¡ã‚¤ãƒ«ãŒå­˜åœ¨ã—ã¾ã›ã‚“');
        }
        if ($touchOpened) {
            $this->db->execute('UPDATE items SET last_opened_at = ? WHERE id = ?', [gmdate('Y-m-d\TH:i:s\Z'), $id]);
        }
        return ['item' => $this->normalizeItem($item), 'path' => $path];
    }

    /** @return array<string, mixed> */
    public function getStorageSummary(): array
    {
        $row = $this->db->fetchOne(
            "SELECT
                COALESCE(SUM(CASE WHEN is_folder = 0 THEN size_bytes ELSE 0 END), 0) AS used_bytes,
                COALESCE(SUM(CASE WHEN is_folder = 0 AND is_trashed = 1 THEN size_bytes ELSE 0 END), 0) AS trash_bytes,
                COALESCE(SUM(CASE WHEN is_folder = 0 AND is_trashed = 0 AND mime_type LIKE 'image/%' THEN size_bytes ELSE 0 END), 0) AS image_bytes,
                COALESCE(SUM(CASE WHEN is_folder = 0 AND is_trashed = 0 AND extension IN ('md','txt','doc','docx','json') THEN size_bytes ELSE 0 END), 0) AS doc_bytes,
                COALESCE(SUM(CASE WHEN is_folder = 0 AND is_trashed = 0 AND (extension = 'pdf' OR mime_type = 'application/pdf') THEN size_bytes ELSE 0 END), 0) AS pdf_bytes,
                COUNT(CASE WHEN is_trashed = 0 THEN 1 END) AS active_count,
                COUNT(CASE WHEN is_trashed = 1 THEN 1 END) AS trash_count
             FROM items"
        ) ?? [];

        $used = (int)($row['used_bytes'] ?? 0);
        return [
            'used_bytes' => $used,
            'quota_bytes' => self::QUOTA_BYTES,
            'percent' => round(($used / self::QUOTA_BYTES) * 100, 4),
            'active_count' => (int)($row['active_count'] ?? 0),
            'trash_count' => (int)($row['trash_count'] ?? 0),
            'breakdown' => [
                'images' => (int)($row['image_bytes'] ?? 0),
                'documents' => (int)($row['doc_bytes'] ?? 0),
                'pdf' => (int)($row['pdf_bytes'] ?? 0),
                'trash' => (int)($row['trash_bytes'] ?? 0),
            ],
        ];
    }

    public function totalItemCount(): int
    {
        return (int)$this->db->fetchValue('SELECT COUNT(*) FROM items');
    }

    private function isDescendant(int $candidateParentId, int $movingFolderId): bool
    {
        $found = $this->db->fetchOne(
            'WITH RECURSIVE anc(id, parent_id) AS (
                SELECT id, parent_id FROM items WHERE id = ?
                UNION
                SELECT i.id, i.parent_id FROM items i JOIN anc a ON i.id = a.parent_id
            )
            SELECT id FROM anc WHERE id = ? LIMIT 1',
            [$candidateParentId, $movingFolderId]
        );
        return $found !== null;
    }

    private function ancestorDepth(int $folderId): int
    {
        return (int)($this->db->fetchValue(
            'WITH RECURSIVE anc(id, parent_id, depth) AS (
                SELECT id, parent_id, 0 FROM items WHERE id = ?
                UNION
                SELECT i.id, i.parent_id, a.depth + 1 FROM items i JOIN anc a ON i.id = a.parent_id
                WHERE a.depth < 64
            )
            SELECT COALESCE(MAX(depth), 0) FROM anc',
            [$folderId]
        ) ?? 0);
    }

    private function subtreeHeight(int $folderId): int
    {
        return (int)($this->db->fetchValue(
            'WITH RECURSIVE sub(id, depth) AS (
                SELECT id, 0 FROM items WHERE id = ?
                UNION
                SELECT i.id, s.depth + 1 FROM items i JOIN sub s ON i.parent_id = s.id
                WHERE i.is_folder = 1 AND s.depth < 64
            )
            SELECT COALESCE(MAX(depth), 0) FROM sub',
            [$folderId]
        ) ?? 0);
    }

    private function isAncestorChainActive(int $folderId): bool
    {
        $rows = $this->db->fetchAll(
            'WITH RECURSIVE anc(id, parent_id, is_folder, is_trashed, depth) AS (
                SELECT id, parent_id, is_folder, is_trashed, 0 FROM items WHERE id = ?
                UNION
                SELECT i.id, i.parent_id, i.is_folder, i.is_trashed, a.depth + 1
                FROM items i JOIN anc a ON i.id = a.parent_id
                WHERE a.depth < 64
            )
            SELECT is_folder, is_trashed FROM anc',
            [$folderId]
        );
        if ($rows === []) {
            return false;
        }
        foreach ($rows as $r) {
            if ((int)$r['is_folder'] !== 1 || (int)$r['is_trashed'] !== 0) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizeItem(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'parent_id' => $row['parent_id'] !== null ? (int)$row['parent_id'] : null,
            'name' => (string)$row['name'],
            'is_folder' => (int)$row['is_folder'],
            'mime_type' => (string)$row['mime_type'],
            'extension' => (string)$row['extension'],
            'size_bytes' => (int)$row['size_bytes'],
            'owner_name' => (string)$row['owner_name'],
            'owner_email' => (string)$row['owner_email'],
            'description' => (string)$row['description'],
            'is_starred' => (int)$row['is_starred'],
            'is_shared' => (int)$row['is_shared'],
            'share_token' => $row['share_token'] !== null ? (string)$row['share_token'] : null,
            'is_trashed' => (int)$row['is_trashed'],
            'trashed_at' => $row['trashed_at'] !== null ? (string)$row['trashed_at'] : null,
            'last_opened_at' => $row['last_opened_at'] !== null ? (string)$row['last_opened_at'] : null,
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)$row['updated_at'],
        ];
    }
}
}

namespace App\Services {
/**
 * Generates SEO, AEO, GEO, AIO, LLMO metadata, structured JSON-LD, RFC-9309 robots.txt,
 * DB-backed sitemap.xml, llms.txt, llms-full.txt, PWA manifest, and valid OpenAPI 3.1.0.
 */
final class SeoService
{
    public const FRAMEWORK_ID = 'ai-framework';
    public const FRAMEWORK_LABEL = 'AIè‡ªä½œFW (ä¾å­˜ã‚¼ãƒ­)';

    /**
     * @param list<array{id?:int,name:string,url?:string}> $breadcrumbs
     * @param array<string, mixed>|null $extraSchema
     * @return array<string, mixed>
     */
    public function pageMeta(
        string $baseUrl,
        string $path,
        string $title,
        string $description,
        array $breadcrumbs = [],
        bool $includeFaq = false,
        string $robots = 'index, follow, max-image-preview:large, max-snippet:-1',
        ?array $extraSchema = null,
    ): array {
        $canonical = rtrim($baseUrl, '/') . ($path === '/' ? '/' : $path);

        $graph = [
            [
                '@type' => 'Organization',
                '@id' => $baseUrl . '/#organization',
                'name' => 'CloudDrive Benchmark Project',
                'url' => $baseUrl . '/',
                'logo' => $baseUrl . '/assets/icon-512.png',
            ],
            [
                '@type' => 'WebApplication',
                '@id' => $baseUrl . '/#webapp',
                'name' => 'CloudDrive',
                'alternateName' => 'ã‚¯ãƒ©ã‚¦ãƒ‰ãƒ‰ãƒ©ã‚¤ãƒ– PHPãƒ™ãƒ³ãƒãƒžãƒ¼ã‚¯ç‰ˆ',
                'url' => $baseUrl . '/',
                'applicationCategory' => 'UtilitiesApplication',
                'operatingSystem' => 'Web, iOS, Android, Windows, macOS, Linux',
                'browserRequirements' => 'Requires HTML5 and JavaScript enabled',
                'inLanguage' => 'ja',
                'publisher' => ['@id' => $baseUrl . '/#organization'],
                'description' => 'Google Driveæº–æ‹ ã®UIã¨PWAãƒ»SEOãƒ»AEOãƒ»GEOãƒ»AIOãƒ»LLMOæœ€é©åŒ–ã‚’å‚™ãˆãŸé«˜é€ŸPHPã‚¯ãƒ©ã‚¦ãƒ‰ã‚¹ãƒˆãƒ¬ãƒ¼ã‚¸ã‚¢ãƒ—ãƒªã€‚',
                'offers' => [
                    '@type' => 'Offer',
                    'price' => '0',
                    'priceCurrency' => 'JPY',
                ],
                'featureList' => [
                    'éšŽå±¤ãƒ•ã‚©ãƒ«ãƒ€ç®¡ç†ã¨ãƒ‘ãƒ³ããšãƒŠãƒ“ã‚²ãƒ¼ã‚·ãƒ§ãƒ³',
                    'ãƒ‰ãƒ©ãƒƒã‚°ï¼†ãƒ‰ãƒ­ãƒƒãƒ—å¯¾å¿œãƒžãƒ«ãƒãƒ•ã‚¡ã‚¤ãƒ«ã‚¢ãƒƒãƒ—ãƒ­ãƒ¼ãƒ‰',
                    'é«˜é€Ÿãƒ•ã‚¡ã‚¤ãƒ«æ¤œç´¢ãƒ»ç¨®åˆ¥ãƒ•ã‚£ãƒ«ã‚¿ãƒ»ä¸¦ã³æ›¿ãˆ',
                    'ç”»åƒãƒ»PDFãƒ»Markdownãƒ»CSVã®å®‰å…¨ãªã‚¤ãƒ³ãƒ©ã‚¤ãƒ³ãƒ—ãƒ¬ãƒ“ãƒ¥ãƒ¼',
                    'ãƒ•ã‚©ãƒ«ãƒ€ç§»å‹•ãƒ»å…¬é–‹å…±æœ‰ãƒªãƒ³ã‚¯ç™ºè¡Œãƒ»ã‚¹ã‚¿ãƒ¼ãƒ»ã‚´ãƒŸç®±å¾©å…ƒ',
                    'ã‚ªãƒ•ãƒ©ã‚¤ãƒ³å¯¾å¿œPWAãŠã‚ˆã³ãƒ¬ã‚¹ãƒãƒ³ã‚·ãƒ–ãƒ¢ãƒã‚¤ãƒ«UI',
                ],
                'softwareVersion' => '1.0.0',
            ],
            [
                '@type' => 'WebSite',
                '@id' => $baseUrl . '/#website',
                'url' => $baseUrl . '/',
                'name' => 'CloudDrive',
                'inLanguage' => 'ja',
                'publisher' => ['@id' => $baseUrl . '/#organization'],
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => $baseUrl . '/drive/search?q={search_term_string}',
                    'query-input' => 'required name=search_term_string',
                ],
            ],
        ];

        $crumbItems = [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'ãƒžã‚¤ãƒ‰ãƒ©ã‚¤ãƒ–', 'item' => $baseUrl . '/'],
        ];
        $pos = 2;
        foreach ($breadcrumbs as $b) {
            $itemUrl = isset($b['url'])
                ? $baseUrl . $b['url']
                : (isset($b['id']) ? $baseUrl . '/drive/folders/' . $b['id'] : $canonical);
            $crumbItems[] = [
                '@type' => 'ListItem',
                'position' => $pos++,
                'name' => $b['name'],
                'item' => $itemUrl,
            ];
        }
        $graph[] = [
            '@type' => 'BreadcrumbList',
            'itemListElement' => $crumbItems,
        ];

        if ($includeFaq) {
            $graph[] = [
                '@type' => 'AboutPage',
                '@id' => $baseUrl . '/about#page',
                'url' => $baseUrl . '/about',
                'name' => $title,
                'description' => $description,
                'inLanguage' => 'ja',
                'dateModified' => '2026-11-24',
                'mainEntity' => ['@id' => $baseUrl . '/#webapp'],
            ];
            $graph[] = [
                '@type' => 'FAQPage',
                'mainEntity' => array_map(static fn(array $faq): array => [
                    '@type' => 'Question',
                    'name' => $faq['q'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $faq['a'],
                    ],
                ], self::faqList()),
            ];
        }

        if ($extraSchema !== null) {
            $graph[] = $extraSchema;
        }

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'robots' => $robots,
            'ogImage' => $baseUrl . '/assets/og-image.png',
            'ogImageAlt' => 'CloudDrive â€” PHP 8.3 ãƒ•ãƒ¬ãƒ¼ãƒ ãƒ¯ãƒ¼ã‚¯æ€§èƒ½æ¯”è¼ƒã‚¯ãƒ©ã‚¦ãƒ‰ã‚¹ãƒˆãƒ¬ãƒ¼ã‚¸',
            'jsonLd' => [
                '@context' => 'https://schema.org',
                '@graph' => $graph,
            ],
        ];
    }

    /** @return list<array{q:string,a:string}> */
    public static function faqList(): array
    {
        return [
            [
                'q' => 'CloudDriveã¨ã¯ä½•ã§ã™ã‹ï¼Ÿ',
                'a' => 'CloudDriveã¯Google Driveã¨åŒä¸€ã®æ“ä½œæ€§ãƒ»Material Design 3 UIã‚’å‚™ãˆã€AIè‡ªä½œãƒ•ãƒ¬ãƒ¼ãƒ ãƒ¯ãƒ¼ã‚¯ãƒ»Laravel 13ãƒ»Phalcon 5ãƒ»Slim 4ã®4ç¨®é¡žã®PHP 8.3ãƒ•ãƒ¬ãƒ¼ãƒ ãƒ¯ãƒ¼ã‚¯ã§åŒä¸€ä»•æ§˜å®Ÿè£…ã—ãŸé«˜é€Ÿã‚¯ãƒ©ã‚¦ãƒ‰ãƒ•ã‚¡ã‚¤ãƒ«ç®¡ç†Webã‚¢ãƒ—ãƒªã§ã™ã€‚',
            ],
            [
                'q' => 'ã‚ªãƒ•ãƒ©ã‚¤ãƒ³ç’°å¢ƒã‚„ã‚¹ãƒžãƒ¼ãƒˆãƒ•ã‚©ãƒ³ã§ã‚‚åˆ©ç”¨ã§ãã¾ã™ã‹ï¼Ÿ',
                'a' => 'ã¯ã„ã€‚CloudDriveã¯Web App Manifestã¨Service Workerã‚’å®Ÿè£…ã—ãŸPWAï¼ˆProgressive Web Appï¼‰ã§ã‚ã‚Šã€ãƒ¢ãƒã‚¤ãƒ«å‘ã‘ãƒœãƒˆãƒ ãƒŠãƒ“ã‚²ãƒ¼ã‚·ãƒ§ãƒ³ãƒ»ãƒ‰ãƒ­ãƒ¯ãƒ¼ãƒ»FABã«å¯¾å¿œã—ã€ã‚ªãƒ•ãƒ©ã‚¤ãƒ³æ™‚ã«ã‚‚ãƒ•ã‚©ãƒ¼ãƒ«ãƒãƒƒã‚¯ç”»é¢ã¨ã‚­ãƒ£ãƒƒã‚·ãƒ¥æ¸ˆã¿ã‚¢ã‚»ãƒƒãƒˆã‚’åˆ©ç”¨ã§ãã¾ã™ã€‚',
            ],
            [
                'q' => 'ã©ã®ãƒ•ã‚¡ã‚¤ãƒ«å½¢å¼ã®ãƒ—ãƒ¬ãƒ“ãƒ¥ãƒ¼ã¨ã‚¢ãƒƒãƒ—ãƒ­ãƒ¼ãƒ‰ã«å¯¾å¿œã—ã¦ã„ã¾ã™ã‹ï¼Ÿ',
                'a' => 'æœ€å¤§20MBï¼ˆåˆè¨ˆ15GBã‚¯ã‚©ãƒ¼ã‚¿ï¼‰ã®ãƒ•ã‚¡ã‚¤ãƒ«ã‚¢ãƒƒãƒ—ãƒ­ãƒ¼ãƒ‰ã«å¯¾å¿œã—ã€PNGãƒ»JPEGãƒ»WebPãƒ»GIFç”»åƒã€PDFæ–‡æ›¸ã€Markdownãƒ»CSVãƒ»JSONãƒ»ãƒ†ã‚­ã‚¹ãƒˆãƒ•ã‚¡ã‚¤ãƒ«ã®å®‰å…¨ãªã‚¤ãƒ³ãƒ©ã‚¤ãƒ³ãƒ—ãƒ¬ãƒ“ãƒ¥ãƒ¼ãŠã‚ˆã³ãƒ¯ãƒ³ã‚¯ãƒªãƒƒã‚¯ãƒ€ã‚¦ãƒ³ãƒ­ãƒ¼ãƒ‰ãŒå¯èƒ½ã§ã™ã€‚',
            ],
            [
                'q' => 'SEOãƒ»AEOãƒ»GEOãƒ»AIOãƒ»LLMOã«ã¯ã©ã®ã‚ˆã†ã«å¯¾å¿œã—ã¦ã„ã¾ã™ã‹ï¼Ÿ',
                'a' => 'ã‚»ãƒžãƒ³ãƒ†ã‚£ãƒƒã‚¯SSR HTMLã€JSON-LDæ§‹é€ åŒ–ãƒ‡ãƒ¼ã‚¿ï¼ˆOrganizationãƒ»WebApplicationãƒ»SearchActionãƒ»BreadcrumbListãƒ»FAQPageãƒ»AboutPageãƒ»DigitalDocumentï¼‰ã€DBé€£å‹•sitemap.xmlã€RFC 9309æº–æ‹ ã®AIã‚¯ãƒ­ãƒ¼ãƒ©å¯¾å¿œrobots.txtã€llms.txtãŠã‚ˆã³llms-full.txtã€ã•ã‚‰ã«å®Œå…¨ãªOpenAPI 3.1.0ä»•æ§˜ï¼ˆ/openapi.jsonï¼‰ã‚’å…¨ãƒ•ãƒ¬ãƒ¼ãƒ ãƒ¯ãƒ¼ã‚¯ã§æ¨™æº–æä¾›ã—ã¦ã„ã¾ã™ã€‚',
            ],
            [
                'q' => 'ã‚»ã‚­ãƒ¥ãƒªãƒ†ã‚£å¯¾ç­–ã¯ã©ã®ã‚ˆã†ã«å®Ÿè£…ã•ã‚Œã¦ã„ã¾ã™ã‹ï¼Ÿ',
                'a' => 'å…¨SQLã‚¯ã‚¨ãƒªã§ã®ãƒ—ãƒªãƒšã‚¢ãƒ‰ã‚¹ãƒ†ãƒ¼ãƒˆãƒ¡ãƒ³ãƒˆå¾¹åº•ã€256bitéµã«ã‚ˆã‚‹HMAC-SHA256ç½²åä»˜ããƒ€ãƒ–ãƒ«ã‚µãƒ–ãƒŸãƒƒãƒˆCSRFä¿è­·ã€ä¸æ´»æ€§.blobä¿å­˜ï¼‹realpathæ¤œè¨¼ã«ã‚ˆã‚‹ãƒ‘ã‚¹ãƒˆãƒ©ãƒãƒ¼ã‚µãƒ«é˜²æ­¢ã€åŽ³æ ¼ãªContent-Security-Policyã€SVGç­‰ã®å±é™ºMIMEã®JSONãƒ†ã‚­ã‚¹ãƒˆåŒ–ã€32éšŽå±¤ä¸Šé™ã¨å¾ªç’°ãƒ•ã‚©ãƒ«ãƒ€ç§»å‹•æ¤œè¨¼ã‚’å‚™ãˆã¦ã„ã¾ã™ã€‚',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function manifestJson(string $baseUrl): array
    {
        $icon192 = [['src' => '/assets/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png']];
        return [
            'id' => '/',
            'name' => 'CloudDrive â€” é«˜é€ŸPHPã‚¯ãƒ©ã‚¦ãƒ‰ã‚¹ãƒˆãƒ¬ãƒ¼ã‚¸',
            'short_name' => 'CloudDrive',
            'description' => 'Google Driveæº–æ‹ UIã¨PWAãƒ»SEOãƒ»AEOãƒ»GEOãƒ»AIOãƒ»LLMOã‚’å‚™ãˆãŸPHPã‚¯ãƒ©ã‚¦ãƒ‰ãƒ‰ãƒ©ã‚¤ãƒ–ã€‚',
            'lang' => 'ja',
            'dir' => 'ltr',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#f8fafd',
            'theme_color' => '#f8fafd',
            'categories' => ['productivity', 'utilities'],
            'icons' => [
                ['src' => '/assets/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/assets/icon-maskable-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => '/assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/assets/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'screenshots' => [
                [
                    'src' => '/assets/screenshot-desktop.png',
                    'sizes' => '1440x900',
                    'type' => 'image/png',
                    'form_factor' => 'wide',
                    'label' => 'CloudDrive ãƒ‡ã‚¹ã‚¯ãƒˆãƒƒãƒ— ãƒžã‚¤ãƒ‰ãƒ©ã‚¤ãƒ–ç”»é¢',
                ],
                [
                    'src' => '/assets/screenshot-mobile.png',
                    'sizes' => '780x1688',
                    'type' => 'image/png',
                    'form_factor' => 'narrow',
                    'label' => 'CloudDrive ãƒ¢ãƒã‚¤ãƒ« PWA ç”»é¢',
                ],
            ],
            'shortcuts' => [
                ['name' => 'ãƒžã‚¤ãƒ‰ãƒ©ã‚¤ãƒ–', 'short_name' => 'ãƒžã‚¤ãƒ‰ãƒ©ã‚¤ãƒ–', 'description' => 'ãƒ«ãƒ¼ãƒˆãƒ•ã‚©ãƒ«ãƒ€ã®ãƒ•ã‚¡ã‚¤ãƒ«ä¸€è¦§ã‚’é–‹ã', 'url' => '/', 'icons' => $icon192],
                ['name' => 'æœ€è¿‘ä½¿ç”¨ã—ãŸã‚¢ã‚¤ãƒ†ãƒ ', 'short_name' => 'æœ€è¿‘', 'description' => 'æœ€è¿‘é–‹ã„ãŸãƒ•ã‚¡ã‚¤ãƒ«ã‚’è¡¨ç¤º', 'url' => '/drive/recent', 'icons' => $icon192],
                ['name' => 'ã‚¹ã‚¿ãƒ¼ä»˜ã', 'short_name' => 'ã‚¹ã‚¿ãƒ¼', 'description' => 'ã‚¹ã‚¿ãƒ¼ä»˜ããƒ•ã‚¡ã‚¤ãƒ«ã‚’è¡¨ç¤º', 'url' => '/drive/starred', 'icons' => $icon192],
                ['name' => 'å…±æœ‰ã‚¢ã‚¤ãƒ†ãƒ ', 'short_name' => 'å…±æœ‰', 'description' => 'å…±æœ‰ä¸­ã®ã‚¢ã‚¤ãƒ†ãƒ ã‚’è¡¨ç¤º', 'url' => '/drive/shared', 'icons' => $icon192],
            ],
        ];
    }

    public function robotsTxt(string $baseUrl): string
    {
        // RFC 9309 compliant: stack all explicitly welcomed search & AI crawlers onto shared Allow/Disallow rules
        return <<<TXT
# CloudDrive robots.txt â€” RFC 9309 Compliant (SEO / AEO / GEO / AIO / LLMO)
User-agent: *
User-agent: Googlebot
User-agent: Bingbot
User-agent: OAI-SearchBot
User-agent: GPTBot
User-agent: ClaudeBot
User-agent: PerplexityBot
User-agent: Google-Extended
Allow: /
Allow: /about
Allow: /llms.txt
Allow: /llms-full.txt
Allow: /openapi.json
Disallow: /api/
Disallow: /offline
Disallow: /drive/trash
Disallow: /drive/search

Sitemap: {$baseUrl}/sitemap.xml
# LLM-friendly index: {$baseUrl}/llms.txt
TXT;
    }

    public function sitemapXml(string $baseUrl, string $latestDate): string
    {
        // Index public navigable pages only (never leak private/unlisted user share_tokens in sitemap.xml â€” SEC-10)
        $staticUrls = [
            ['loc' => $baseUrl . '/', 'priority' => '1.0', 'freq' => 'daily', 'lastmod' => $latestDate],
            ['loc' => $baseUrl . '/about', 'priority' => '0.9', 'freq' => 'weekly', 'lastmod' => $latestDate],
            ['loc' => $baseUrl . '/drive/shared', 'priority' => '0.8', 'freq' => 'daily', 'lastmod' => $latestDate],
            ['loc' => $baseUrl . '/drive/recent', 'priority' => '0.7', 'freq' => 'daily', 'lastmod' => $latestDate],
            ['loc' => $baseUrl . '/drive/starred', 'priority' => '0.7', 'freq' => 'daily', 'lastmod' => $latestDate],
        ];

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($staticUrls as $u) {
            $loc = htmlspecialchars($u['loc'], ENT_XML1, 'UTF-8');
            $xml .= "  <url>\n    <loc>{$loc}</loc>\n    <lastmod>{$u['lastmod']}</lastmod>\n    <changefreq>{$u['freq']}</changefreq>\n    <priority>{$u['priority']}</priority>\n  </url>\n";
        }
        $xml .= "</urlset>\n";
        return $xml;
    }

    public function llmsTxt(string $baseUrl): string
    {
        return <<<MD
# CloudDrive

> Google Driveæº–æ‹ ã®Material Design 3 UIãƒ»PWAãƒ»SEOãƒ»AEOãƒ»GEOãƒ»AIOãƒ»LLMOå®Œå…¨å¯¾å¿œã®PHP 8.3ã‚¯ãƒ©ã‚¦ãƒ‰ãƒ•ã‚¡ã‚¤ãƒ«ç®¡ç†ã‚¢ãƒ—ãƒªã‚±ãƒ¼ã‚·ãƒ§ãƒ³ã€‚AIè‡ªä½œãƒ•ãƒ¬ãƒ¼ãƒ ãƒ¯ãƒ¼ã‚¯ãƒ»Laravel 13ãƒ»Phalcon 5ãƒ»Slim 4ã®4å®Ÿè£…ã§å®Œå…¨åŒä¸€ä»•æ§˜ãƒ»å…¬å¹³ãƒ™ãƒ³ãƒãƒžãƒ¼ã‚¯ã‚’æä¾›ã€‚

## ä¸»è¦ãƒšãƒ¼ã‚¸
- [ãƒžã‚¤ãƒ‰ãƒ©ã‚¤ãƒ–]({$baseUrl}/): ãƒ«ãƒ¼ãƒˆãƒ•ã‚©ãƒ«ãƒ€ã®ãƒ•ã‚¡ã‚¤ãƒ«ãƒ»ãƒ•ã‚©ãƒ«ãƒ€ä¸€è¦§ï¼ˆSSRå¯¾å¿œï¼‰
- [å…±æœ‰ã‚¢ã‚¤ãƒ†ãƒ ]({$baseUrl}/drive/shared): å…±æœ‰ãƒªãƒ³ã‚¯ä»˜ããƒ•ã‚¡ã‚¤ãƒ«ãƒ»ãƒ•ã‚©ãƒ«ãƒ€ä¸€è¦§
- [æœ€è¿‘ä½¿ç”¨ã—ãŸã‚¢ã‚¤ãƒ†ãƒ ]({$baseUrl}/drive/recent): æœ€è¿‘é–²è¦§ãƒ»æ›´æ–°ã•ã‚ŒãŸãƒ•ã‚¡ã‚¤ãƒ«ä¸€è¦§
- [ã‚¹ã‚¿ãƒ¼ä»˜ã]({$baseUrl}/drive/starred): é‡è¦ãƒ•ã‚¡ã‚¤ãƒ«ã®ãŠæ°—ã«å…¥ã‚Šä¸€è¦§
- [ã‚µãƒ¼ãƒ“ã‚¹æ¦‚è¦ãƒ»FAQ]({$baseUrl}/about): æŠ€è¡“ä»•æ§˜ãƒ»æ¯”è¼ƒè¡¨ãƒ»AEO/GEOæœ€é©åŒ–FAQ

## æ©Ÿæ¢°å¯èª­ãƒªã‚½ãƒ¼ã‚¹ï¼ˆAIO / LLMOï¼‰
- [è©³ç´°ãƒ‰ã‚­ãƒ¥ãƒ¡ãƒ³ãƒˆ (llms-full.txt)]({$baseUrl}/llms-full.txt): å…¨æ©Ÿèƒ½ãƒ»APIãƒ»CSRFèªè¨¼ä»•æ§˜ãƒ»ã‚»ã‚­ãƒ¥ãƒªãƒ†ã‚£è¨­è¨ˆã®å®Œå…¨ãƒªãƒ•ã‚¡ãƒ¬ãƒ³ã‚¹
- [OpenAPI 3.1.0 ã‚¹ã‚­ãƒ¼ãƒž]({$baseUrl}/openapi.json): å…¨12ã‚¨ãƒ³ãƒ‰ãƒã‚¤ãƒ³ãƒˆãƒ»ãƒ‘ãƒ©ãƒ¡ãƒ¼ã‚¿ãƒ»ã‚¹ã‚­ãƒ¼ãƒžå®šç¾©
- [XMLã‚µã‚¤ãƒˆãƒžãƒƒãƒ—]({$baseUrl}/sitemap.xml): æ¤œç´¢ã‚¨ãƒ³ã‚¸ãƒ³ãƒ»AIæ¤œç´¢ã‚¯ãƒ­ãƒ¼ãƒ©ç”¨URLä¸€è¦§
- [ã‚¯ãƒ­ãƒ¼ãƒ©åˆ¶å¾¡ (robots.txt)]({$baseUrl}/robots.txt): RFC 9309æº–æ‹ ã®æ¤œç´¢ãƒ»AIã‚¯ãƒ­ãƒ¼ãƒ©ãƒãƒªã‚·ãƒ¼
- [PWAãƒžãƒ‹ãƒ•ã‚§ã‚¹ãƒˆ]({$baseUrl}/manifest.webmanifest): ã‚¤ãƒ³ã‚¹ãƒˆãƒ¼ãƒ«å¯èƒ½Webã‚¢ãƒ—ãƒªå®šç¾©
MD;
    }

    public function llmsFullTxt(string $baseUrl): string
    {
        $faqMd = '';
        foreach (self::faqList() as $f) {
            $faqMd .= "### {$f['q']}\n{$f['a']}\n\n";
        }
        return <<<MD
# CloudDrive â€” å®Œå…¨æŠ€è¡“ä»•æ§˜ï¼ˆLLMO / GEO / AIO Referenceï¼‰

> ã“ã®ãƒ‰ã‚­ãƒ¥ãƒ¡ãƒ³ãƒˆã¯å¤§è¦æ¨¡è¨€èªžãƒ¢ãƒ‡ãƒ«ï¼ˆLLMï¼‰ã€AIæ¤œç´¢ã‚¨ãƒ³ã‚¸ãƒ³ã€ãŠã‚ˆã³è‡ªå‹•åŒ–ã‚¨ãƒ¼ã‚¸ã‚§ãƒ³ãƒˆãŒCloudDriveã®æ©Ÿèƒ½ãƒ»ä»•æ§˜ãƒ»APIã‚’æ­£ç¢ºã«ç†è§£ãƒ»å¼•ç”¨ã™ã‚‹ãŸã‚ã®ä¸€æ¬¡æƒ…å ±æºã§ã™ã€‚

## 1. è£½å“ã‚µãƒžãƒªãƒ¼
- **ã‚µãƒ¼ãƒ“ã‚¹å**: CloudDrive
- **ã‚«ãƒ†ã‚´ãƒª**: ã‚¯ãƒ©ã‚¦ãƒ‰ã‚¹ãƒˆãƒ¬ãƒ¼ã‚¸ / ãƒ•ã‚¡ã‚¤ãƒ«ç®¡ç†Webã‚¢ãƒ—ãƒªã‚±ãƒ¼ã‚·ãƒ§ãƒ³ï¼ˆGoogle Driveã‚¯ãƒ­ãƒ¼ãƒ³ï¼‰
- **ãƒ©ãƒ³ã‚¿ã‚¤ãƒ **: PHP 8.3 (OPcacheæœ‰åŠ¹) + SQLite (WAL, `synchronous=NORMAL`, `busy_timeout=5000`) + Nginx + ç‹¬ç«‹PHP-FPMãƒ—ãƒ¼ãƒ«
- **æ¯”è¼ƒå¯¾è±¡ãƒ•ãƒ¬ãƒ¼ãƒ ãƒ¯ãƒ¼ã‚¯**: AIè‡ªä½œãƒ•ãƒ¬ãƒ¼ãƒ ãƒ¯ãƒ¼ã‚¯ï¼ˆå¤–éƒ¨ãƒ©ã‚¤ãƒ–ãƒ©ãƒªä¸ä½¿ç”¨ï¼‰ã€Laravel 13ã€Phalcon 5.23ï¼ˆCæ‹¡å¼µï¼‰ã€Slim 4.15
- **ä¿å­˜å®¹é‡ä¸Šé™**: 15 GBï¼ˆ1ãƒ•ã‚¡ã‚¤ãƒ«æœ€å¤§ã‚¢ãƒƒãƒ—ãƒ­ãƒ¼ãƒ‰ 20 MBï¼‰
- **UI/UX**: Google Driveæº–æ‹  Material Design 3ï¼ˆãƒ‡ã‚¹ã‚¯ãƒˆãƒƒãƒ—3ãƒšã‚¤ãƒ³ + ãƒ¢ãƒã‚¤ãƒ«ãƒœãƒˆãƒ ãƒŠãƒ“ãƒ»ãƒ‰ãƒ­ãƒ¯ãƒ¼ãƒ»FABãƒ»å³ã‚¯ãƒªãƒƒã‚¯ãƒ¡ãƒ‹ãƒ¥ãƒ¼ãƒ»ãƒ‰ãƒ©ãƒƒã‚°ï¼†ãƒ‰ãƒ­ãƒƒãƒ—ãƒ»ãƒ•ã‚©ãƒ«ãƒ€ç§»å‹•ãƒ¢ãƒ¼ãƒ€ãƒ«ï¼‰
- **å“è³ªç›®æ¨™**: Lighthouseï¼ˆPerformance / Accessibility / Best Practices / SEOï¼‰å…¨é …ç›®100ç‚¹

## 2. èªè¨¼ãƒ»CSRFä»•æ§˜ï¼ˆå…¨æ›¸ãè¾¼ã¿ç³»ã‚¨ãƒ³ãƒ‰ãƒã‚¤ãƒ³ãƒˆå…±é€šï¼‰
- `GET /` ã¾ãŸã¯ `GET /api/storage` ã‚’å‘¼ã³å‡ºã™ã¨ã€256-bit HMAC-SHA256 ç½²åä»˜ããƒˆãƒ¼ã‚¯ãƒ³ãŒ `Set-Cookie: clouddrive_csrf=...`ï¼ˆHttpOnly, SameSite=Laxï¼‰ãŠã‚ˆã³ãƒ¬ã‚¹ãƒãƒ³ã‚¹ãƒ˜ãƒƒãƒ€ `X-CSRF-Token`ãƒ»HTML `<meta name="csrf-token">` ã§ç™ºè¡Œã•ã‚Œã¾ã™ã€‚
- ã™ã¹ã¦ã®çŠ¶æ…‹å¤‰æ›´ãƒªã‚¯ã‚¨ã‚¹ãƒˆï¼ˆ`POST`, `PATCH`, `DELETE`ï¼‰ã¯ã€`Cookie: clouddrive_csrf=<token>` ã¨ `X-CSRF-Token: <token>` ã®ä¸¡æ–¹ã‚’ä¸€è‡´ã•ã›ã¦é€ä¿¡ã™ã‚‹å¿…è¦ãŒã‚ã‚Šã¾ã™ï¼ˆä¸ä¸€è‡´æ™‚ã¯ `403 Forbidden`ï¼‰ã€‚

## 3. REST API å…¨12ã‚¨ãƒ³ãƒ‰ãƒã‚¤ãƒ³ãƒˆæ¦‚è¦ï¼ˆè©³ç´°ã¯ {$baseUrl}/openapi.jsonï¼‰
- `GET {$baseUrl}/api/health`: ãƒ•ãƒ¬ãƒ¼ãƒ ãƒ¯ãƒ¼ã‚¯ç¨®åˆ¥ãƒ»PHPãƒãƒ¼ã‚¸ãƒ§ãƒ³ãƒ»ç·ã‚¢ã‚¤ãƒ†ãƒ æ•°
- `GET {$baseUrl}/api/items`: ã‚¢ã‚¤ãƒ†ãƒ ä¸€è¦§ï¼ˆ`view`, `folder_id`, `q`, `type`, `sort`, `dir`ï¼‰
- `GET {$baseUrl}/api/items/{id}`: ã‚¢ã‚¤ãƒ†ãƒ è©³ç´°ãƒ»ã‚¢ã‚¯ãƒ†ã‚£ãƒ“ãƒ†ã‚£å±¥æ­´ãƒ»ãƒ‘ãƒ³ããš
- `POST {$baseUrl}/api/folders`: æ–°è¦ãƒ•ã‚©ãƒ«ãƒ€ä½œæˆï¼ˆJSON: `name`, `parent_id`ï¼‰
- `POST {$baseUrl}/api/files/upload`: ãƒ•ã‚¡ã‚¤ãƒ«ã‚¢ãƒƒãƒ—ãƒ­ãƒ¼ãƒ‰ï¼ˆ`multipart/form-data`: `file`, `parent_id`, `description`ï¼‰
- `PATCH {$baseUrl}/api/items/{id}`: åå‰å¤‰æ›´ãƒ»ãƒ•ã‚©ãƒ«ãƒ€ç§»å‹•ï¼ˆå¾ªç’°ï¼‹32éšŽå±¤æ¤œè¨¼ï¼‰ãƒ»ã‚¹ã‚¿ãƒ¼ãƒ»å…±æœ‰ãƒˆã‚°ãƒ«ï¼ˆåœæ­¢æ™‚ãƒˆãƒ¼ã‚¯ãƒ³å¤±åŠ¹ï¼‰ãƒ»èª¬æ˜Žæ›´æ–°
- `DELETE {$baseUrl}/api/items/{id}`: ã‚´ãƒŸç®±ç§»å‹•ï¼ˆå­å­«å†å¸°ã‚´ãƒŸç®±åŒ–ï¼‰ã¾ãŸã¯å®Œå…¨å‰Šé™¤ï¼ˆ`?permanent=1`ï¼‰
- `POST {$baseUrl}/api/items/{id}/restore`: ã‚´ãƒŸç®±ã‹ã‚‰å¾©å…ƒï¼ˆè¦ªãƒ•ã‚©ãƒ«ãƒ€ç¢ºèªãƒ»å­å­«åŒæœŸå¾©å…ƒï¼‰
- `POST {$baseUrl}/api/trash/empty`: ã‚´ãƒŸç®±ã‚’ç©ºã«ã™ã‚‹ï¼ˆåŽŸå­çš„å®Œå…¨å‰Šé™¤ï¼‹ã‚¹ãƒˆãƒ¬ãƒ¼ã‚¸å›žåŽï¼‰
- `GET {$baseUrl}/api/files/{id}/download`: å®‰å…¨ãªãƒã‚¤ãƒŠãƒªãƒ€ã‚¦ãƒ³ãƒ­ãƒ¼ãƒ‰ï¼ˆ`Content-Disposition: attachment`ï¼‰
- `GET {$baseUrl}/api/files/{id}/preview`: å®‰å…¨ãªãƒ—ãƒ¬ãƒ“ãƒ¥ãƒ¼ï¼ˆãƒ©ã‚¹ã‚¿ç”»åƒãƒ»PDFãƒ»UTF-8ãƒ†ã‚­ã‚¹ãƒˆJSONã€SVGéžå®Ÿè¡Œï¼‰
- `GET {$baseUrl}/api/storage`: å®¹é‡ä½¿ç”¨çŠ¶æ³ãŠã‚ˆã³ã‚«ãƒ†ã‚´ãƒªåˆ¥å†…è¨³

## 4. ã‚ˆãã‚ã‚‹è³ªå•ï¼ˆFAQï¼‰
{$faqMd}
MD;
    }

    /** @return array<string, mixed> */
    public function openApiSpec(string $baseUrl): array
    {
        $idParam = [
            'name' => 'id',
            'in' => 'path',
            'required' => true,
            'description' => 'ã‚¢ã‚¤ãƒ†ãƒ IDï¼ˆæ­£ã®æ•´æ•°ï¼‰',
            'schema' => ['type' => 'integer', 'minimum' => 1],
        ];
        $csrfSec = [['csrfHeader' => [], 'csrfCookie' => []]];
        $errRef = ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]]];

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'CloudDrive API',
                'version' => '1.0.0',
                'description' => 'Google Driveæº–æ‹ ã‚¯ãƒ©ã‚¦ãƒ‰ã‚¹ãƒˆãƒ¬ãƒ¼ã‚¸APIï¼ˆAIè‡ªä½œFWãƒ»Laravel 13ãƒ»Phalcon 5ãƒ»Slim 4å…±é€šä»•æ§˜ï¼‰',
            ],
            'servers' => [['url' => $baseUrl]],
            'paths' => [
                '/api/health' => [
                    'get' => [
                        'operationId' => 'getHealth',
                        'summary' => 'ãƒ˜ãƒ«ã‚¹ãƒã‚§ãƒƒã‚¯',
                        'responses' => ['200' => ['description' => 'ç¨¼åƒæ­£å¸¸']],
                    ],
                ],
                '/api/items' => [
                    'get' => [
                        'operationId' => 'listItems',
                        'summary' => 'ãƒ•ã‚¡ã‚¤ãƒ«ãƒ»ãƒ•ã‚©ãƒ«ãƒ€ä¸€è¦§å–å¾—',
                        'parameters' => [
                            ['name' => 'view', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['my-drive', 'recent', 'starred', 'shared', 'trash', 'folders', 'search'], 'default' => 'my-drive']],
                            ['name' => 'folder_id', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                            ['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string']],
                            ['name' => 'type', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['all', 'folder', 'document', 'spreadsheet', 'pdf', 'image'], 'default' => 'all']],
                            ['name' => 'sort', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['name', 'updated_at', 'size'], 'default' => 'name']],
                            ['name' => 'dir', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'asc']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'ã‚¢ã‚¤ãƒ†ãƒ ä¸€è¦§JSON'],
                            '400' => array_merge(['description' => 'ä¸æ­£ãƒ‘ãƒ©ãƒ¡ãƒ¼ã‚¿'], $errRef),
                            '404' => array_merge(['description' => 'ãƒ•ã‚©ãƒ«ãƒ€æœªæ¤œå‡º'], $errRef),
                        ],
                    ],
                ],
                '/api/items/{id}' => [
                    'parameters' => [$idParam],
                    'get' => [
                        'operationId' => 'getItem',
                        'summary' => 'ã‚¢ã‚¤ãƒ†ãƒ è©³ç´°ãƒ»ã‚¢ã‚¯ãƒ†ã‚£ãƒ“ãƒ†ã‚£å–å¾—',
                        'responses' => [
                            '200' => ['description' => 'è©³ç´°JSON'],
                            '404' => array_merge(['description' => 'æœªæ¤œå‡º'], $errRef),
                        ],
                    ],
                    'patch' => [
                        'operationId' => 'updateItem',
                        'summary' => 'ã‚¢ã‚¤ãƒ†ãƒ æ›´æ–°ï¼ˆåå‰ãƒ»ç§»å‹•ãƒ»ã‚¹ã‚¿ãƒ¼ãƒ»å…±æœ‰ãƒ»èª¬æ˜Žï¼‰',
                        'security' => $csrfSec,
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'name' => ['type' => 'string', 'maxLength' => 200],
                                            'parent_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
                                            'is_starred' => ['type' => 'boolean'],
                                            'is_shared' => ['type' => 'boolean'],
                                            'description' => ['type' => 'string', 'maxLength' => 1000],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => ['description' => 'æ›´æ–°æˆåŠŸ'],
                            '403' => array_merge(['description' => 'CSRFæ¤œè¨¼ã‚¨ãƒ©ãƒ¼'], $errRef),
                            '404' => array_merge(['description' => 'æœªæ¤œå‡º'], $errRef),
                            '422' => array_merge(['description' => 'ãƒãƒªãƒ‡ãƒ¼ã‚·ãƒ§ãƒ³ã¾ãŸã¯å¾ªç’°ç§»å‹•ã‚¨ãƒ©ãƒ¼'], $errRef),
                        ],
                    ],
                    'delete' => [
                        'operationId' => 'deleteItem',
                        'summary' => 'ã‚´ãƒŸç®±ç§»å‹•ã¾ãŸã¯å®Œå…¨å‰Šé™¤',
                        'security' => $csrfSec,
                        'parameters' => [
                            ['name' => 'permanent', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['0', '1'], 'default' => '0']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'å‰Šé™¤æˆåŠŸ'],
                            '403' => array_merge(['description' => 'CSRFæ¤œè¨¼ã‚¨ãƒ©ãƒ¼'], $errRef),
                            '404' => array_merge(['description' => 'æœªæ¤œå‡º'], $errRef),
                        ],
                    ],
                ],
                '/api/items/{id}/restore' => [
                    'parameters' => [$idParam],
                    'post' => [
                        'operationId' => 'restoreItem',
                        'summary' => 'ã‚´ãƒŸç®±ã‹ã‚‰ã‚¢ã‚¤ãƒ†ãƒ ã‚’å¾©å…ƒ',
                        'security' => $csrfSec,
                        'responses' => [
                            '200' => ['description' => 'å¾©å…ƒæˆåŠŸ'],
                            '403' => array_merge(['description' => 'CSRFæ¤œè¨¼ã‚¨ãƒ©ãƒ¼'], $errRef),
                            '404' => array_merge(['description' => 'ã‚´ãƒŸç®±å†…ã«æœªæ¤œå‡º'], $errRef),
                        ],
                    ],
                ],
                '/api/trash/empty' => [
                    'post' => [
                        'operationId' => 'emptyTrash',
                        'summary' => 'ã‚´ãƒŸç®±ã‚’ç©ºã«ã™ã‚‹',
                        'security' => $csrfSec,
                        'responses' => [
                            '200' => ['description' => 'å®Œå…¨å‰Šé™¤å®Œäº†'],
                            '403' => array_merge(['description' => 'CSRFæ¤œè¨¼ã‚¨ãƒ©ãƒ¼'], $errRef),
                        ],
                    ],
                ],
                '/api/folders' => [
                    'post' => [
                        'operationId' => 'createFolder',
                        'summary' => 'æ–°è¦ãƒ•ã‚©ãƒ«ãƒ€ä½œæˆ',
                        'security' => $csrfSec,
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['name'],
                                        'properties' => [
                                            'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                                            'parent_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '201' => ['description' => 'ä½œæˆæˆåŠŸ'],
                            '403' => array_merge(['description' => 'CSRFæ¤œè¨¼ã‚¨ãƒ©ãƒ¼'], $errRef),
                            '404' => array_merge(['description' => 'è¦ªãƒ•ã‚©ãƒ«ãƒ€æœªæ¤œå‡º'], $errRef),
                            '422' => array_merge(['description' => 'ãƒãƒªãƒ‡ãƒ¼ã‚·ãƒ§ãƒ³ã¾ãŸã¯éšŽå±¤ä¸Šé™ã‚¨ãƒ©ãƒ¼'], $errRef),
                        ],
                    ],
                ],
                '/api/files/upload' => [
                    'post' => [
                        'operationId' => 'uploadFile',
                        'summary' => 'ãƒ•ã‚¡ã‚¤ãƒ«ã‚¢ãƒƒãƒ—ãƒ­ãƒ¼ãƒ‰ï¼ˆæœ€å¤§20MBï¼‰',
                        'security' => $csrfSec,
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'multipart/form-data' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['file'],
                                        'properties' => [
                                            'file' => ['type' => 'string', 'format' => 'binary'],
                                            'parent_id' => ['type' => 'integer', 'minimum' => 1],
                                            'description' => ['type' => 'string', 'maxLength' => 1000],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '201' => ['description' => 'ã‚¢ãƒƒãƒ—ãƒ­ãƒ¼ãƒ‰æˆåŠŸ'],
                            '403' => array_merge(['description' => 'CSRFæ¤œè¨¼ã‚¨ãƒ©ãƒ¼'], $errRef),
                            '413' => array_merge(['description' => 'ã‚µã‚¤ã‚ºä¸Šé™ã¾ãŸã¯ã‚¯ã‚©ãƒ¼ã‚¿è¶…éŽ'], $errRef),
                            '422' => array_merge(['description' => 'ä¸æ­£ãƒ•ã‚¡ã‚¤ãƒ«'], $errRef),
                        ],
                    ],
                ],
                '/api/files/{id}/download' => [
                    'parameters' => [$idParam],
                    'get' => [
                        'operationId' => 'downloadFile',
                        'summary' => 'ãƒ•ã‚¡ã‚¤ãƒ«ãƒ€ã‚¦ãƒ³ãƒ­ãƒ¼ãƒ‰',
                        'responses' => [
                            '200' => ['description' => 'ãƒã‚¤ãƒŠãƒªã‚¹ãƒˆãƒªãƒ¼ãƒ ï¼ˆattachmentï¼‰'],
                            '404' => array_merge(['description' => 'æœªæ¤œå‡º'], $errRef),
                        ],
                    ],
                ],
                '/api/files/{id}/preview' => [
                    'parameters' => [$idParam],
                    'get' => [
                        'operationId' => 'previewFile',
                        'summary' => 'å®‰å…¨ãªã‚¤ãƒ³ãƒ©ã‚¤ãƒ³ãƒ—ãƒ¬ãƒ“ãƒ¥ãƒ¼',
                        'responses' => [
                            '200' => ['description' => 'ç”»åƒ/PDFãƒã‚¤ãƒŠãƒªã¾ãŸã¯ãƒ†ã‚­ã‚¹ãƒˆJSON'],
                            '404' => array_merge(['description' => 'æœªæ¤œå‡º'], $errRef),
                            '415' => array_merge(['description' => 'ãƒ—ãƒ¬ãƒ“ãƒ¥ãƒ¼éžå¯¾å¿œå½¢å¼'], $errRef),
                        ],
                    ],
                ],
                '/api/storage' => [
                    'get' => [
                        'operationId' => 'getStorage',
                        'summary' => 'ä¿å­˜å®¹é‡ã‚µãƒžãƒªãƒ¼å–å¾—',
                        'responses' => [
                            '200' => ['description' => 'å®¹é‡çµ±è¨ˆJSON', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/StorageSummary']]]],
                        ],
                    ],
                ],
            ],
            'components' => [
                'securitySchemes' => [
                    'csrfHeader' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-CSRF-Token'],
                    'csrfCookie' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'clouddrive_csrf'],
                ],
                'schemas' => [
                    'DriveItem' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'parent_id' => ['type' => ['integer', 'null']],
                            'name' => ['type' => 'string'],
                            'is_folder' => ['type' => 'integer', 'enum' => [0, 1]],
                            'mime_type' => ['type' => 'string'],
                            'extension' => ['type' => 'string'],
                            'size_bytes' => ['type' => 'integer'],
                            'owner_name' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'is_starred' => ['type' => 'integer', 'enum' => [0, 1]],
                            'is_shared' => ['type' => 'integer', 'enum' => [0, 1]],
                            'share_token' => ['type' => ['string', 'null']],
                            'is_trashed' => ['type' => 'integer', 'enum' => [0, 1]],
                            'created_at' => ['type' => 'string', 'format' => 'date-time'],
                            'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                        ],
                    ],
                    'StorageSummary' => [
                        'type' => 'object',
                        'properties' => [
                            'used_bytes' => ['type' => 'integer'],
                            'quota_bytes' => ['type' => 'integer'],
                            'percent' => ['type' => 'number'],
                            'active_count' => ['type' => 'integer'],
                            'trash_count' => ['type' => 'integer'],
                        ],
                    ],
                    'ErrorResponse' => [
                        'type' => 'object',
                        'required' => ['error', 'status'],
                        'properties' => [
                            'error' => ['type' => 'string'],
                            'status' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
}

namespace App\Services {
final class UiHelper
{
    private static ?string $cachedCss = null;

    public static function inlineCss(): string
    {
        return \EmbeddedAssets::INLINE_CSS;
    }

    public static function formatBytes(int $bytes, bool $isFolder = false): string
    {
        if ($isFolder) {
            return 'â€”';
        }
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $val = (float)$bytes;
        while ($val >= 1024 && $i < count($units) - 1) {
            $val /= 1024;
            $i++;
        }
        return ($i === 0 ? number_format($val, 0) : number_format($val, 1)) . ' ' . $units[$i];
    }

    public static function formatDate(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return 'â€”';
        }
        $ts = strtotime($iso);
        return $ts === false ? $iso : gmdate('Y/m/d', $ts);
    }

    /** @param array<string, mixed> $item */
    public static function itemIconSvg(array $item): string
    {
        if ((int)($item['is_folder'] ?? 0) === 1) {
            return '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" fill="#444746"><path d="M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>';
        }
        $ext = strtolower((string)($item['extension'] ?? ''));
        $mime = strtolower((string)($item['mime_type'] ?? ''));
        if (str_starts_with($mime, 'image/') || in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
            return '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" fill="#d93025"><path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg>';
        }
        if ($ext === 'pdf' || $mime === 'application/pdf') {
            return '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" fill="#ea4335"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm1 7V3.5L19.5 9H15zM8 14h8v2H8v-2zm0 4h5v2H8v-2z"/></svg>';
        }
        if (in_array($ext, ['csv', 'xls', 'xlsx'], true)) {
            return '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" fill="#0f9d58"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-8 14H5v-4h6v4zm0-6H5V7h6v4zm8 6h-6v-4h6v4zm0-6h-6V7h6v4z"/></svg>';
        }
        if (in_array($ext, ['md', 'txt', 'doc', 'docx'], true)) {
            return '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" fill="#1a73e8"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>';
        }
        return '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" fill="#5f6368"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13z"/></svg>';
    }
}
}

namespace App\Middleware {
use AiFramework\MiddlewareInterface;
use AiFramework\Request;
use AiFramework\Response;
use AiFramework\Security;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        $isPreview = str_starts_with($request->path, '/api/files/') && str_ends_with($request->path, '/preview');
        $frameAncestors = $isPreview ? "'self'" : "'none'";
        $xFrame = $isPreview ? 'SAMEORIGIN' : 'DENY';

        if (!$response->hasHeader('Content-Security-Policy')) {
            $csp = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors {$frameAncestors}; frame-src 'self'";
            $response = $response->withHeader('Content-Security-Policy', $csp);
        }

        if (!$response->hasHeader('Cache-Control') && !Security::isPublicCacheablePath($request->path)) {
            $response = $response->withHeader('Cache-Control', 'no-store, private');
        }

        return $response
            ->withHeader('X-Framework', 'ai-framework')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', $xFrame)
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin');
    }
}
}

namespace App\Middleware {
use AiFramework\MiddlewareInterface;
use AiFramework\Request;
use AiFramework\Response;
use AiFramework\Security;

final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Security $security) {}

    public function handle(Request $request, callable $next): Response
    {
        // Let 404/405 route mismatches return 404/405 before CSRF (consistent with Slim & Laravel)
        $routeStatus = (int)$request->attribute('route_status', 200);
        if ($routeStatus !== 200) {
            return $next($request);
        }

        // Never issue Set-Cookie on public cacheable metadata/health endpoints (prevents CDN token leakage)
        if (Security::isPublicCacheablePath($request->path) && in_array($request->method, ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        $cookieToken = $request->cookie(Security::CSRF_COOKIE);
        $validCookie = $this->security->verifyCsrfToken($cookieToken);

        if (in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $headerToken = $request->header('x-csrf-token');
            if (!$this->security->verifyDoubleSubmit($cookieToken, $headerToken)) {
                return Response::json(['error' => 'CSRF token mismatch or missing', 'status' => 403], 403);
            }
            return $next($request->withAttribute('csrf_token', (string)$cookieToken));
        }

        $token = $validCookie ? (string)$cookieToken : $this->security->issueCsrfToken();
        $response = $next($request->withAttribute('csrf_token', $token));

        if ($response->status() < 400) {
            $response = $response->withHeader('X-CSRF-Token', $token);
            if (!$validCookie) {
                $response = $response->withCookie(Security::CSRF_COOKIE, $token, [
                    'expires' => time() + Security::CSRF_TTL,
                    'path' => '/',
                    'secure' => $request->isHttps(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
        }

        return $response;
    }
}
}

namespace App\Controllers {
use AiFramework\HttpException;
use AiFramework\Request;
use AiFramework\Response;
use AiFramework\View;
use App\Services\DriveService;
use App\Services\SeoService;

final class PageController
{
    public function __construct(
        private readonly DriveService $drive,
        private readonly SeoService $seo,
        private readonly View $view,
    ) {}

    public function home(Request $request): Response
    {
        return $this->renderDriveView($request, 'my-drive', null, 'ãƒžã‚¤ãƒ‰ãƒ©ã‚¤ãƒ– - CloudDrive', 'CloudDriveã®ãƒžã‚¤ãƒ‰ãƒ©ã‚¤ãƒ–ã€‚Google Driveæº–æ‹ UIã§ãƒ•ã‚¡ã‚¤ãƒ«ãƒ»ãƒ•ã‚©ãƒ«ãƒ€ã‚’é«˜é€Ÿç®¡ç†ã€ãƒ‰ãƒ©ãƒƒã‚°ï¼†ãƒ‰ãƒ­ãƒƒãƒ—ã‚¢ãƒƒãƒ—ãƒ­ãƒ¼ãƒ‰ã€æ¤œç´¢ã€å…±æœ‰ã«å¯¾å¿œã€‚');
    }

    public function folder(Request $request): Response
    {
        $id = (int)$request->param('id', '0');
        if ($id <= 0) {
            throw new HttpException(404, 'ãƒ•ã‚©ãƒ«ãƒ€ãŒè¦‹ã¤ã‹ã‚Šã¾ã›ã‚“');
        }
        return $this->renderDriveView($request, 'my-drive', $id, null, null);
    }

    public function recent(Request $request): Response
    {
        return $this->renderDriveView($request, 'recent', null, 'æœ€è¿‘ä½¿ç”¨ã—ãŸã‚¢ã‚¤ãƒ†ãƒ  - CloudDrive', 'CloudDriveã§æœ€è¿‘é–‹ã„ãŸãƒ»æ›´æ–°ã—ãŸãƒ•ã‚¡ã‚¤ãƒ«ä¸€è¦§ã‚’æ™‚ç³»åˆ—ã§ç´ æ—©ãç¢ºèªã§ãã¾ã™ã€‚');
    }

    public function starred(Request $request): Response
    {
        return $this->renderDriveView($request, 'starred', null, 'ã‚¹ã‚¿ãƒ¼ä»˜ã - CloudDrive', 'é‡è¦ãªãƒ•ã‚¡ã‚¤ãƒ«ã‚„ãƒ•ã‚©ãƒ«ãƒ€ã«ã‚¹ã‚¿ãƒ¼ã‚’ä»˜ã‘ã¦ç´ æ—©ãã‚¢ã‚¯ã‚»ã‚¹ã§ãã‚‹CloudDriveã®ãŠæ°—ã«å…¥ã‚Šä¸€è¦§ã€‚');
    }

    public function shared(Request $request): Response
    {
        return $this->renderDriveView($request, 'shared', null, 'å…±æœ‰ã‚¢ã‚¤ãƒ†ãƒ  - CloudDrive', 'ãƒãƒ¼ãƒ ã‚„å¤–éƒ¨ãƒªãƒ³ã‚¯ã§å…±æœ‰ã•ã‚Œã¦ã„ã‚‹ãƒ•ã‚¡ã‚¤ãƒ«ãƒ»ãƒ•ã‚©ãƒ«ãƒ€ä¸€è¦§ã‚’ç®¡ç†ã§ãã¾ã™ã€‚');
    }

    public function trash(Request $request): Response
    {
        return $this->renderDriveView($request, 'trash', null, 'ã‚´ãƒŸç®± - CloudDrive', 'å‰Šé™¤äºˆå®šã®ãƒ•ã‚¡ã‚¤ãƒ«ãƒ»ãƒ•ã‚©ãƒ«ãƒ€ã‚’ç¢ºèªã—ã€ãƒ¯ãƒ³ã‚¯ãƒªãƒƒã‚¯å¾©å…ƒã¾ãŸã¯å®Œå…¨å‰Šé™¤ã‚’å®Ÿè¡Œã§ãã¾ã™ã€‚', 'noindex, follow');
    }

    public function search(Request $request): Response
    {
        $q = trim($request->queryString('q', ''));
        $title = $q !== '' ? "æ¤œç´¢ã€Œ{$q}ã€ - CloudDrive" : 'æ¤œç´¢ - CloudDrive';
        return $this->renderDriveView($request, 'search', null, $title, 'CloudDriveå†…ã®ãƒ•ã‚¡ã‚¤ãƒ«åãƒ»èª¬æ˜Žæ–‡ã‹ã‚‰ç›®çš„ã®ãƒ‰ã‚­ãƒ¥ãƒ¡ãƒ³ãƒˆã‚„ç”»åƒã‚’é«˜é€Ÿæ¤œç´¢ã—ã¾ã™ã€‚', 'noindex, follow');
    }

    public function share(Request $request): Response
    {
        $token = (string)$request->param('token', '');
        $item = $this->drive->getSharedByToken($token);
        if ($item === null) {
            throw new HttpException(404, 'å…±æœ‰ã‚¢ã‚¤ãƒ†ãƒ ãŒè¦‹ã¤ã‹ã‚‰ãªã„ã‹ã€å…¬é–‹ãŒåœæ­¢ã•ã‚Œã¦ã„ã¾ã™');
        }
        $isFolder = (int)$item['is_folder'] === 1;
        $children = $isFolder ? $this->drive->getSharedChildren((int)$item['id']) : [];
        $storage = $this->drive->getStorageSummary();
        $baseUrl = $request->baseUrl();
        $kindLabel = $isFolder ? 'å…±æœ‰ãƒ•ã‚©ãƒ«ãƒ€' : 'å…±æœ‰ãƒ•ã‚¡ã‚¤ãƒ«';

        $extraSchema = [
            '@type' => $isFolder ? 'CollectionPage' : 'DigitalDocument',
            'name' => $item['name'],
            'description' => $item['description'] !== '' ? $item['description'] : "CloudDriveã§å…¬é–‹å…±æœ‰ã•ã‚Œã¦ã„ã‚‹{$kindLabel}ã€‚",
            'dateModified' => $item['updated_at'],
            'encodingFormat' => $item['mime_type'],
            'url' => $baseUrl . '/share/' . $token,
        ];

        $meta = $this->seo->pageMeta(
            $baseUrl,
            '/share/' . $token,
            $item['name'] . "ï¼ˆ{$kindLabel}ï¼‰ - CloudDrive",
            $item['description'] !== '' ? $item['description'] : "CloudDriveã§å…±æœ‰ã•ã‚Œã¦ã„ã‚‹{$item['name']}ã®é–²è¦§ãƒ»ãƒ€ã‚¦ãƒ³ãƒ­ãƒ¼ãƒ‰ãƒšãƒ¼ã‚¸ã€‚",
            [['name' => $item['name'], 'url' => '/share/' . $token]],
            false,
            'noindex, follow',
            $extraSchema
        );

        $html = $this->view->render('share', [
            'meta' => $meta,
            'csrfToken' => (string)$request->attribute('csrf_token', ''),
            'activeView' => 'shared',
            'storage' => $storage,
            'item' => $item,
            'children' => $children,
            'q' => '',
            'frameworkLabel' => SeoService::FRAMEWORK_LABEL,
            'frameworkId' => SeoService::FRAMEWORK_ID,
            'driveState' => null,
        ]);

        return Response::html($html);
    }

    public function about(Request $request): Response
    {
        $storage = $this->drive->getStorageSummary();
        $baseUrl = $request->baseUrl();
        $meta = $this->seo->pageMeta(
            $baseUrl,
            '/about',
            'CloudDriveã«ã¤ã„ã¦ãƒ»æŠ€è¡“ä»•æ§˜ãƒ»FAQ - CloudDrive',
            'Google Driveæº–æ‹ ã®PHP 8.3ã‚¯ãƒ©ã‚¦ãƒ‰ã‚¹ãƒˆãƒ¬ãƒ¼ã‚¸ã€ŒCloudDriveã€ã®è¨­è¨ˆä»•æ§˜ã€4ãƒ•ãƒ¬ãƒ¼ãƒ ãƒ¯ãƒ¼ã‚¯æ€§èƒ½æ¯”è¼ƒã€PWAãƒ»SEOãƒ»AEOãƒ»GEOãƒ»AIOãƒ»LLMOæœ€é©åŒ–ã‚¬ã‚¤ãƒ‰ãŠã‚ˆã³FAQã€‚',
            [['name' => 'ã‚µãƒ¼ãƒ“ã‚¹æ¦‚è¦ãƒ»FAQ', 'url' => '/about']],
            true
        );

        $html = $this->view->render('about', [
            'meta' => $meta,
            'csrfToken' => (string)$request->attribute('csrf_token', ''),
            'activeView' => 'about',
            'storage' => $storage,
            'faqs' => SeoService::faqList(),
            'q' => '',
            'frameworkLabel' => SeoService::FRAMEWORK_LABEL,
            'frameworkId' => SeoService::FRAMEWORK_ID,
            'driveState' => null,
        ]);

        return Response::html($html);
    }

    public function offline(Request $request): Response
    {
        $baseUrl = $request->baseUrl();
        $meta = $this->seo->pageMeta(
            $baseUrl,
            '/offline',
            'ã‚ªãƒ•ãƒ©ã‚¤ãƒ³ - CloudDrive',
            'ç¾åœ¨ã‚ªãƒ•ãƒ©ã‚¤ãƒ³ã§ã™ã€‚ãƒãƒƒãƒˆãƒ¯ãƒ¼ã‚¯æŽ¥ç¶šãŒå›žå¾©ã™ã‚‹ã¨CloudDriveã®æœ€æ–°ãƒ•ã‚¡ã‚¤ãƒ«ã«å†æŽ¥ç¶šã§ãã¾ã™ã€‚',
            [['name' => 'ã‚ªãƒ•ãƒ©ã‚¤ãƒ³', 'url' => '/offline']],
            false,
            'noindex, nofollow'
        );

        $html = $this->view->render('offline', [
            'meta' => $meta,
            'csrfToken' => (string)$request->attribute('csrf_token', ''),
            'activeView' => '',
            'storage' => ['used_bytes' => 0, 'quota_bytes' => DriveService::QUOTA_BYTES, 'percent' => 0],
            'q' => '',
            'frameworkLabel' => SeoService::FRAMEWORK_LABEL,
            'frameworkId' => SeoService::FRAMEWORK_ID,
            'driveState' => null,
        ]);

        return Response::html($html);
    }

    private function renderDriveView(
        Request $request,
        string $view,
        ?int $folderId,
        ?string $title,
        ?string $description,
        string $robots = 'index, follow, max-image-preview:large, max-snippet:-1',
    ): Response {
        $q = trim($request->queryString('q', ''));
        $type = $request->queryString('type', 'all');
        $sort = $request->queryString('sort', $view === 'recent' ? 'updated_at' : 'name');
        $dir = $request->queryString('dir', $view === 'recent' ? 'desc' : 'asc');

        $data = $this->drive->listItems($view, $folderId, $q, $type, $sort, $dir);

        if ($folderId !== null && $data['folder'] !== null) {
            $title = $data['folder']['name'] . ' - ãƒžã‚¤ãƒ‰ãƒ©ã‚¤ãƒ– - CloudDrive';
            $description = $data['folder']['description'] !== ''
                ? $data['folder']['description']
                : "CloudDriveã®ãƒ•ã‚©ãƒ«ãƒ€ã€Œ{$data['folder']['name']}ã€ã«å«ã¾ã‚Œã‚‹ãƒ•ã‚¡ã‚¤ãƒ«ã¨ã‚µãƒ–ãƒ•ã‚©ãƒ«ãƒ€ä¸€è¦§ã€‚";
        }

        $viewTitles = [
            'my-drive' => 'ãƒžã‚¤ãƒ‰ãƒ©ã‚¤ãƒ–',
            'recent' => 'æœ€è¿‘ä½¿ç”¨ã—ãŸã‚¢ã‚¤ãƒ†ãƒ ',
            'starred' => 'ã‚¹ã‚¿ãƒ¼ä»˜ã',
            'shared' => 'å…±æœ‰ã‚¢ã‚¤ãƒ†ãƒ ',
            'trash' => 'ã‚´ãƒŸç®±',
            'search' => $q !== '' ? "ã€Œ{$q}ã€ã®æ¤œç´¢çµæžœ" : 'ãƒ‰ãƒ©ã‚¤ãƒ–ã‚’æ¤œç´¢',
        ];

        $metaBreadcrumbs = $data['breadcrumbs'];
        if ($folderId === null && $view !== 'my-drive') {
            $metaBreadcrumbs = [['name' => $viewTitles[$view] ?? 'ãƒžã‚¤ãƒ‰ãƒ©ã‚¤ãƒ–', 'url' => $request->path]];
        }

        $baseUrl = $request->baseUrl();
        $meta = $this->seo->pageMeta(
            $baseUrl,
            $request->path,
            $title ?? 'CloudDrive',
            $description ?? 'Google Driveæº–æ‹ ã®é«˜é€ŸPHPã‚¯ãƒ©ã‚¦ãƒ‰ã‚¹ãƒˆãƒ¬ãƒ¼ã‚¸ã€‚',
            $metaBreadcrumbs,
            false,
            $robots
        );

        $driveState = [
            'view' => $view,
            'folderId' => $folderId,
            'items' => $data['items'],
            'breadcrumbs' => $data['breadcrumbs'],
            'storage' => $data['storage'],
            'q' => $q,
            'type' => $type,
            'sort' => $sort,
            'dir' => $dir,
            'framework' => SeoService::FRAMEWORK_ID,
        ];

        $html = $this->view->render('drive', [
            'meta' => $meta,
            'csrfToken' => (string)$request->attribute('csrf_token', ''),
            'activeView' => $view,
            'headingTitle' => $viewTitles[$view] ?? 'ãƒžã‚¤ãƒ‰ãƒ©ã‚¤ãƒ–',
            'folder' => $data['folder'],
            'breadcrumbs' => $data['breadcrumbs'],
            'items' => $data['items'],
            'storage' => $data['storage'],
            'q' => $q,
            'type' => $type,
            'sort' => $sort,
            'dir' => $dir,
            'frameworkLabel' => SeoService::FRAMEWORK_LABEL,
            'frameworkId' => SeoService::FRAMEWORK_ID,
            'driveState' => $driveState,
        ]);

        return Response::html($html);
    }
}
}

namespace App\Controllers {
use AiFramework\HttpException;
use AiFramework\Request;
use AiFramework\Response;
use AiFramework\Security;
use App\Services\DriveService;
use App\Services\SeoService;

final class ApiController
{
    public function __construct(private readonly DriveService $drive) {}

    public function health(Request $request): Response
    {
        return Response::json([
            'status' => 'ok',
            'framework' => SeoService::FRAMEWORK_ID,
            'php_version' => PHP_VERSION,
            'items_total' => $this->drive->totalItemCount(),
        ]);
    }

    public function listItems(Request $request): Response
    {
        $view = $request->queryString('view', 'my-drive');
        $folderId = Security::parseOptionalPositiveInt($request->query('folder_id'), 'folder_id', 400);
        $q = $request->queryString('q', '');
        $type = $request->queryString('type', 'all');
        $sort = $request->queryString('sort', $view === 'recent' ? 'updated_at' : 'name');
        $dir = $request->queryString('dir', $view === 'recent' ? 'desc' : 'asc');

        $data = $this->drive->listItems($view, $folderId, $q, $type, $sort, $dir);
        return Response::json($data);
    }

    public function getItem(Request $request): Response
    {
        $id = (int)$request->param('id', '0');
        return Response::json($this->drive->getItemDetail($id));
    }

    public function createFolder(Request $request): Response
    {
        $body = $request->json();
        $name = (string)Security::requireString($body['name'] ?? null, 'name');
        $parentId = Security::parseOptionalPositiveInt($body['parent_id'] ?? null, 'parent_id', 422);

        $item = $this->drive->createFolder($name, $parentId);
        return Response::json(['item' => $item], 201);
    }

    public function uploadFile(Request $request): Response
    {
        // Detect post_max_size overflow -> return 413 consistently (SEC-13)
        $contentLength = (int)($request->header('content-length') ?? '0');
        if ($contentLength > DriveService::MAX_UPLOAD_BYTES && empty($request->files)) {
            throw new HttpException(413, 'ãƒªã‚¯ã‚¨ã‚¹ãƒˆã‚µã‚¤ã‚ºãŒä¸Šé™ï¼ˆ20MBï¼‰ã‚’è¶…ãˆã¦ã„ã¾ã™');
        }
        if (!isset($request->files['file']) || !is_array($request->files['file'])) {
            throw new HttpException(422, 'ã‚¢ãƒƒãƒ—ãƒ­ãƒ¼ãƒ‰ã™ã‚‹ãƒ•ã‚¡ã‚¤ãƒ«ã‚’é¸æŠžã—ã¦ãã ã•ã„');
        }
        $parentId = Security::parseOptionalPositiveInt($request->post['parent_id'] ?? null, 'parent_id', 422);
        $descRaw = $request->post['description'] ?? '';
        $desc = (string)Security::requireString($descRaw, 'description');

        $item = $this->drive->uploadFile($request->files['file'], $parentId, $desc);
        return Response::json(['item' => $item], 201);
    }

    public function updateItem(Request $request): Response
    {
        $id = (int)$request->param('id', '0');
        $item = $this->drive->updateItem($id, $request->json());
        return Response::json(['item' => $item]);
    }

    public function deleteItem(Request $request): Response
    {
        $id = (int)$request->param('id', '0');
        $permanent = $request->queryString('permanent', '0') === '1';
        return Response::json($this->drive->deleteItem($id, $permanent));
    }

    public function restoreItem(Request $request): Response
    {
        $id = (int)$request->param('id', '0');
        $item = $this->drive->restoreItem($id);
        return Response::json(['item' => $item]);
    }

    public function emptyTrash(Request $request): Response
    {
        return Response::json($this->drive->emptyTrash());
    }

    public function downloadFile(Request $request): Response
    {
        $id = (int)$request->param('id', '0');
        $resolved = $this->drive->resolveFileForTransfer($id);
        return Response::file($resolved['path'], 'application/octet-stream', (string)$resolved['item']['name'], [
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function previewFile(Request $request): Response
    {
        $id = (int)$request->param('id', '0');
        $resolved = $this->drive->resolveFileForTransfer($id);
        $item = $resolved['item'];
        $path = $resolved['path'];
        $mime = strtolower((string)$item['mime_type']);
        $ext = strtolower((string)$item['extension']);

        if (in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
            return Response::file($path, $mime, null, [
                'Cache-Control' => 'private, max-age=3600',
                'Content-Security-Policy' => "default-src 'none'; sandbox; frame-ancestors 'self'",
            ]);
        }

        if ($mime === 'application/pdf') {
            return Response::file($path, 'application/pdf', null, [
                'Content-Disposition' => 'inline',
                'Cache-Control' => 'private, max-age=3600',
                'Content-Security-Policy' => "default-src 'none'; object-src 'self'; script-src 'none'; form-action 'none'; frame-ancestors 'self'",
            ]);
        }

        $isText = str_starts_with($mime, 'text/')
            || in_array($mime, ['application/json', 'application/xml', 'image/svg+xml'], true)
            || in_array($ext, ['md', 'txt', 'csv', 'json', 'xml', 'log', 'php', 'js', 'css', 'html', 'svg'], true);

        if ($isText) {
            $maxBytes = 262144;
            $raw = file_get_contents($path, false, null, 0, $maxBytes + 4);
            $truncated = filesize($path) > $maxBytes;
            $safeText = $raw === false ? '' : mb_scrub(mb_strcut($raw, 0, $maxBytes, 'UTF-8'), 'UTF-8');
            return Response::json([
                'id' => $item['id'],
                'name' => $item['name'],
                'mime_type' => $item['mime_type'],
                'truncated' => $truncated,
                'content' => $safeText,
            ]);
        }

        throw new HttpException(415, 'ã“ã®ãƒ•ã‚¡ã‚¤ãƒ«å½¢å¼ã®ã‚¤ãƒ³ãƒ©ã‚¤ãƒ³ãƒ—ãƒ¬ãƒ“ãƒ¥ãƒ¼ã¯ã‚µãƒãƒ¼ãƒˆã•ã‚Œã¦ã„ã¾ã›ã‚“');
    }

    public function storage(Request $request): Response
    {
        return Response::json($this->drive->getStorageSummary());
    }
}
}

namespace App\Controllers {
use AiFramework\Request;
use AiFramework\Response;
use App\Services\DriveService;
use App\Services\SeoService;

final class MetaController
{
    public function __construct(
        private readonly DriveService $drive,
        private readonly SeoService $seo,
    ) {}

    public function manifest(Request $request): Response
    {
        $json = json_encode($this->seo->manifestJson($request->baseUrl()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        return Response::text((string)$json, 200, 'application/manifest+json; charset=utf-8', [
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function robots(Request $request): Response
    {
        return Response::text($this->seo->robotsTxt($request->baseUrl()), 200, 'text/plain; charset=utf-8', [
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function sitemap(Request $request): Response
    {
        $latestDate = $this->drive->latestUpdatedDate();
        return Response::text($this->seo->sitemapXml($request->baseUrl(), $latestDate), 200, 'application/xml; charset=utf-8', [
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function llms(Request $request): Response
    {
        return Response::text($this->seo->llmsTxt($request->baseUrl()), 200, 'text/markdown; charset=utf-8', [
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function llmsFull(Request $request): Response
    {
        return Response::text($this->seo->llmsFullTxt($request->baseUrl()), 200, 'text/markdown; charset=utf-8', [
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function openapi(Request $request): Response
    {
        return Response::json($this->seo->openApiSpec($request->baseUrl()), 200, [
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
}

namespace {

use AiFramework\Application;
use AiFramework\Container;
use AiFramework\Database;
use AiFramework\Request;
use AiFramework\Response;
use AiFramework\Router;
use AiFramework\Security;
use AiFramework\View;
use App\Controllers\ApiController;
use App\Controllers\MetaController;
use App\Controllers\PageController;
use App\Middleware\CsrfMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Services\DriveService;
use App\Services\SeoService;

final class EmbeddedAssets
{
    public const INLINE_CSS = ':root{--shell-bg:#f8fafd;--surface:#ffffff;--surface-hover:#f0f4f9;--surface-variant:#e9eef6;--surface-variant-hover:#dfe6f1;--active-bg:#c2e7ff;--active-fg:#001d35;--selected-bg:#d3e3fd;--primary:#0b57d0;--primary-hover:#0842a0;--danger:#b3261e;--danger-bg:#fce8e6;--ink:#1f1f1f;--ink-secondary:#444746;--border:#dde3ea;--border-strong:#c4c7c5;--shadow-sm:0 1px 2px 0 rgba(60,64,67,0.25),0 1px 3px 1px rgba(60,64,67,0.14);--shadow-md:0 2px 6px 2px rgba(60,64,67,0.15),0 1px 2px 0 rgba(60,64,67,0.28);--shadow-lg:0 6px 20px 4px rgba(0,0,0,0.16),0 2px 6px rgba(0,0,0,0.12);--radius-sm:8px;--radius-md:12px;--radius-lg:16px;--radius-pill:9999px;--header-h:64px;--sidebar-w:256px;--details-w:320px;--font-sans:-apple-system,BlinkMacSystemFont,"Segoe UI","Hiragino Sans","Hiragino Kaku Gothic ProN","Noto Sans JP",Roboto,"Yu Gothic",sans-serif;--font-mono:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}*,*::before,*::after{box-sizing:border-box}html,body{margin:0;padding:0;height:100%;background:var(--shell-bg);color:var(--ink);font-family:var(--font-sans);font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased;overflow-x:hidden}a{color:var(--primary);text-decoration:none}a:hover{text-decoration:underline}button,input,select,textarea{font:inherit;color:inherit}button{cursor:pointer}:focus-visible{outline:2px solid var(--primary);outline-offset:2px}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}.skip-link{position:fixed;top:-100px;left:16px;z-index:1000;background:var(--primary);color:#ffffff;padding:10px 16px;border-radius:var(--radius-sm);font-weight:600}.skip-link:focus{top:12px;text-decoration:none}.app-shell{display:grid;grid-template-rows:var(--header-h) 1fr;grid-template-columns:var(--sidebar-w) minmax(0,1fr);grid-template-areas:"header header" "sidebar workspace";min-height:100vh;width:100%;background:var(--shell-bg)}.app-header{grid-area:header;display:flex;align-items:center;gap:12px;padding:8px 16px;height:var(--header-h);width:100%;min-width:0;background:var(--shell-bg);position:sticky;top:0;z-index:40}.brand-area{display:flex;align-items:center;gap:10px;min-width:calc(var(--sidebar-w) - 16px);flex-shrink:0}.menu-toggle-btn{display:none;align-items:center;justify-content:center;width:44px;height:44px;border:none;border-radius:50%;background:transparent;color:var(--ink-secondary)}.menu-toggle-btn:hover{background:var(--surface-variant)}.brand-link{display:inline-flex;align-items:center;gap:10px;color:var(--ink-secondary);text-decoration:none;padding:4px 8px;border-radius:var(--radius-sm)}.brand-link:hover{text-decoration:none;background:var(--surface-variant)}.brand-name{font-size:21px;font-weight:500;letter-spacing:-0.01em;color:var(--ink-secondary)}.search-form{flex:1 1 0%;min-width:0;max-width:720px;display:flex;align-items:center;gap:8px;background:var(--surface-variant);border-radius:var(--radius-pill);padding:0 10px 0 14px;height:48px;border:1px solid transparent;transition:background-color 0.15s ease,box-shadow 0.15s ease}.search-form:focus-within{background:var(--surface);box-shadow:var(--shadow-sm);border-color:var(--border)}.search-icon{color:var(--ink-secondary);flex-shrink:0;display:inline-flex}.search-input{flex:1;min-width:0;border:none;background:transparent;font-size:15px;color:var(--ink);outline:none;height:100%}.search-input::placeholder{color:var(--ink-secondary);opacity:1}.search-clear-btn{display:none;align-items:center;justify-content:center;width:36px;height:36px;border:none;border-radius:50%;background:transparent;color:var(--ink-secondary)}.search-clear-btn.visible{display:inline-flex}.search-clear-btn:hover{background:var(--surface-variant)}.header-actions{margin-left:auto;display:flex;align-items:center;gap:6px}.fw-badge{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:var(--radius-pill);background:var(--surface);border:1px solid var(--border);font-size:12px;font-weight:600;color:var(--ink);white-space:nowrap}.fw-dot{width:8px;height:8px;border-radius:50%;background:#137333}.icon-btn{display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border:none;border-radius:50%;background:transparent;color:var(--ink-secondary);transition:background-color 0.12s ease;text-decoration:none}.icon-btn:hover{background:var(--surface-variant);text-decoration:none;color:var(--ink)}.icon-btn[aria-pressed="true"]{background:var(--active-bg);color:var(--active-fg)}.user-avatar{width:36px;height:36px;border-radius:50%;background:var(--primary);color:#ffffff;font-weight:600;font-size:14px;display:inline-flex;align-items:center;justify-content:center;border:2px solid #ffffff;box-shadow:0 0 0 1px var(--border);flex-shrink:0}.app-sidebar{grid-area:sidebar;padding:8px 12px 20px;display:flex;flex-direction:column;gap:12px;background:var(--shell-bg)}.new-btn-wrap{position:relative}.new-btn{display:inline-flex;align-items:center;gap:12px;height:56px;padding:0 22px 0 16px;border:none;border-radius:var(--radius-lg);background:var(--surface);color:var(--ink);font-size:14px;font-weight:500;box-shadow:var(--shadow-sm);transition:background-color 0.15s ease,box-shadow 0.15s ease}.new-btn:hover{background:#edf2fa;box-shadow:var(--shadow-md)}.nav-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:2px}.nav-link{display:flex;align-items:center;gap:14px;height:38px;padding:0 16px;border-radius:var(--radius-pill);color:var(--ink);font-size:14px;font-weight:500;text-decoration:none;transition:background-color 0.12s ease}.nav-link:hover{background:var(--surface-variant);text-decoration:none}.nav-link[aria-current="page"]{background:var(--active-bg);color:var(--active-fg);font-weight:600}.nav-link svg{flex-shrink:0}.sidebar-divider{border:none;border-top:1px solid var(--border);margin:6px 8px}.storage-box{padding:8px 16px;display:flex;flex-direction:column;gap:8px}.storage-header{display:flex;align-items:center;gap:12px;font-size:14px;font-weight:500;color:var(--ink)}.storage-bar-track{width:100%;height:6px;background:var(--border);border-radius:var(--radius-pill);overflow:hidden}.storage-bar-fill{height:100%;background:var(--primary);border-radius:var(--radius-pill);min-width:4px;transition:width 0.25s ease}.storage-text{font-size:12px;color:var(--ink-secondary)}.storage-cta{display:inline-flex;align-items:center;justify-content:center;padding:7px 14px;border:1px solid var(--border-strong);border-radius:var(--radius-pill);background:transparent;color:var(--primary);font-size:13px;font-weight:500;width:fit-content;text-decoration:none}.storage-cta:hover{background:#edf2fa;text-decoration:none}.workspace-container{grid-area:workspace;display:flex;gap:12px;padding:0 16px 16px 0;min-width:0}.workspace-main{flex:1;min-width:0;background:var(--surface);border-radius:var(--radius-lg);display:flex;flex-direction:column;position:relative;min-height:calc(100vh - var(--header-h) - 16px);border:1px solid #e6ebf1}.workspace-toolbar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;padding:16px 20px 10px}.breadcrumb-nav{display:flex;align-items:center;flex-wrap:wrap;gap:4px;min-width:0}.breadcrumb-title{margin:0;font-size:22px;font-weight:400;color:var(--ink);line-height:1.3;display:flex;align-items:center;flex-wrap:wrap;gap:4px}.breadcrumb-link{color:var(--ink-secondary);padding:4px 10px;border-radius:var(--radius-pill);font-size:20px;text-decoration:none}.breadcrumb-link:hover{background:var(--surface-hover);text-decoration:none;color:var(--ink)}.breadcrumb-current{padding:4px 8px;color:var(--ink);font-weight:500;font-size:22px}.breadcrumb-sep{color:var(--ink-secondary);font-size:18px}.toolbar-right{display:flex;align-items:center;gap:8px}.view-toggle{display:inline-flex;border:1px solid var(--border-strong);border-radius:var(--radius-pill);overflow:hidden;height:36px}.view-toggle-btn{display:inline-flex;align-items:center;justify-content:center;gap:4px;padding:0 14px;border:none;background:transparent;color:var(--ink);font-size:13px}.view-toggle-btn + .view-toggle-btn{border-left:1px solid var(--border-strong)}.view-toggle-btn[aria-pressed="true"]{background:var(--active-bg);color:var(--active-fg);font-weight:600}.view-toggle-btn:hover:not([aria-pressed="true"]){background:var(--surface-hover)}.filter-bar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;padding:4px 20px 12px;border-bottom:1px solid var(--border)}.filter-chips{display:flex;flex-wrap:wrap;gap:8px}.chip-btn{display:inline-flex;align-items:center;gap:6px;height:32px;padding:0 14px;border:1px solid var(--border-strong);border-radius:var(--radius-sm);background:var(--surface);color:var(--ink);font-size:13px;font-weight:500}.chip-btn:hover{background:var(--surface-hover)}.chip-btn[aria-pressed="true"]{background:var(--active-bg);border-color:var(--active-bg);color:var(--active-fg);font-weight:600}.sort-controls{display:flex;align-items:center;gap:8px}.sort-select{height:32px;padding:0 10px;border:1px solid var(--border-strong);border-radius:var(--radius-sm);background:var(--surface);color:var(--ink);font-size:13px}.selection-bar{display:none;align-items:center;gap:8px;margin:10px 20px 4px;padding:6px 14px;background:var(--surface-hover);border-radius:var(--radius-pill);flex-wrap:wrap}.selection-bar.visible{display:flex}.selection-label{font-weight:600;font-size:13px;color:var(--ink);margin-right:6px}.action-pill-btn{display:inline-flex;align-items:center;gap:6px;height:32px;padding:0 12px;border:none;border-radius:var(--radius-pill);background:transparent;color:var(--ink);font-size:13px;font-weight:500}.action-pill-btn:hover{background:var(--surface-variant-hover)}.action-pill-btn.danger{color:var(--danger)}.action-pill-btn.danger:hover{background:var(--danger-bg)}.trash-banner{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:12px 20px 0;padding:12px 16px;background:var(--surface-hover);border-radius:var(--radius-md);font-size:13px;color:var(--ink)}.files-area{flex:1;padding:8px 20px 24px}.drive-table{width:100%;border-collapse:collapse;table-layout:fixed}.drive-table th{text-align:left;font-size:13px;font-weight:600;color:var(--ink-secondary);padding:10px 12px;border-bottom:1px solid var(--border);white-space:nowrap}.drive-table th.col-name{width:46%}.drive-table th.col-owner{width:20%}.drive-table th.col-updated{width:16%}.drive-table th.col-size{width:10%}.drive-table th.col-actions{width:8%;text-align:right}.drive-row{border-bottom:1px solid #eef1f5;transition:background-color 0.1s ease}.drive-row:hover{background:var(--surface-hover)}.drive-row.selected{background:var(--selected-bg)}.drive-row td{padding:8px 12px;height:48px;vertical-align:middle;color:var(--ink-secondary);font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.item-name-cell{display:flex;align-items:center;gap:12px;min-width:0}.item-link{color:var(--ink);font-weight:500;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-decoration:none;display:inline-flex;align-items:center;gap:6px}.item-link:hover{text-decoration:underline}.item-sub-mobile{display:none;font-size:12px;color:var(--ink-secondary)}.badge-inline{display:inline-flex;align-items:center;color:var(--ink-secondary);flex-shrink:0}.badge-star{color:#b06000}.row-actions-cell{text-align:right}.kebab-btn{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border:none;border-radius:50%;background:transparent;color:var(--ink-secondary)}.kebab-btn:hover{background:var(--surface-variant-hover);color:var(--ink)}.grid-section-title{margin:14px 0 10px;font-size:13px;font-weight:600;color:var(--ink-secondary)}.folder-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px;margin-bottom:16px}.folder-card{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 8px 8px 14px;height:52px;background:var(--surface-hover);border-radius:var(--radius-md);border:1px solid transparent;transition:background-color 0.12s ease}.folder-card:hover{background:var(--surface-variant)}.folder-card.selected{background:var(--selected-bg);border-color:var(--primary)}.folder-card-main{display:flex;align-items:center;gap:10px;min-width:0;flex:1;color:var(--ink);font-weight:500;text-decoration:none}.folder-card-main span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.file-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px}.file-card{display:flex;flex-direction:column;background:var(--surface-hover);border-radius:var(--radius-md);padding:10px;border:1px solid transparent;transition:background-color 0.12s ease}.file-card:hover{background:var(--surface-variant)}.file-card.selected{background:var(--selected-bg);border-color:var(--primary)}.file-card-header{display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:8px}.file-card-title{display:flex;align-items:center;gap:8px;min-width:0;flex:1;font-size:13px;font-weight:500;color:var(--ink)}.file-card-title span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.file-card-thumb{height:132px;background:var(--surface);border-radius:var(--radius-sm);border:1px solid var(--border);display:flex;flex-direction:column;align-items:center;justify-content:center;overflow:hidden;padding:8px;gap:6px}.file-card-thumb img{width:100%;height:100%;object-fit:cover;border-radius:4px}.file-card-meta{display:flex;justify-content:space-between;margin-top:8px;font-size:12px;color:var(--ink-secondary)}.hidden{display:none !important}.details-panel{width:var(--details-w);flex-shrink:0;background:var(--surface);border-radius:var(--radius-lg);border:1px solid #e6ebf1;padding:18px;display:none;flex-direction:column;gap:14px;height:fit-content;max-height:calc(100vh - var(--header-h) - 16px);overflow-y:auto}.details-panel.open{display:flex}.details-header{display:flex;align-items:center;justify-content:space-between;gap:8px;border-bottom:1px solid var(--border);padding-bottom:12px}.details-header h2{margin:0;font-size:16px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.details-dl{margin:0;display:grid;grid-template-columns:96px 1fr;gap:8px 10px;font-size:13px}.details-dl dt{color:var(--ink-secondary);font-weight:500}.details-dl dd{margin:0;color:var(--ink);word-break:break-word}.activity-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:10px}.activity-item{font-size:12px;padding:8px 10px;background:var(--shell-bg);border-radius:var(--radius-sm)}.activity-item time{display:block;color:var(--ink-secondary);margin-top:2px}.empty-state{text-align:center;padding:56px 20px;color:var(--ink-secondary)}.empty-state h2{margin:12px 0 6px;font-size:18px;color:var(--ink);font-weight:500}.drop-overlay{position:absolute;inset:10px;border:2px dashed var(--primary);border-radius:var(--radius-lg);background:rgba(232,240,254,0.92);display:none;flex-direction:column;align-items:center;justify-content:center;gap:10px;z-index:50;pointer-events:none}.drop-overlay.active{display:flex}.drop-overlay p{margin:0;font-size:16px;font-weight:600;color:var(--primary)}.menu-popover{position:fixed;min-width:224px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-md);box-shadow:var(--shadow-lg);padding:6px 0;z-index:120;display:none}.menu-popover.open{display:block}.menu-item{width:100%;display:flex;align-items:center;gap:12px;padding:10px 16px;border:none;background:transparent;color:var(--ink);font-size:14px;text-align:left}.menu-item:hover{background:var(--surface-hover)}.menu-item.danger{color:var(--danger)}.menu-sep{height:1px;background:var(--border);margin:4px 0}.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,0.45);display:none;align-items:center;justify-content:center;padding:16px;z-index:200}.modal-backdrop.open{display:flex}.modal-dialog{width:100%;max-width:460px;background:var(--surface);border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);padding:24px;display:flex;flex-direction:column;gap:16px}.modal-dialog h2{margin:0;font-size:20px;font-weight:500}.form-field{display:flex;flex-direction:column;gap:6px}.form-field label{font-size:13px;font-weight:600;color:var(--ink-secondary)}.text-input,.textarea-input{width:100%;padding:10px 12px;border:1px solid var(--border-strong);border-radius:var(--radius-sm);font-size:14px;color:var(--ink);background:var(--surface)}.modal-footer{display:flex;justify-content:flex-end;gap:10px}.btn-primary{height:38px;padding:0 20px;border:none;border-radius:var(--radius-pill);background:var(--primary);color:#ffffff;font-weight:600;font-size:14px}.btn-primary:hover{background:var(--primary-hover)}.btn-secondary{height:38px;padding:0 18px;border:1px solid var(--border-strong);border-radius:var(--radius-pill);background:var(--surface);color:var(--primary);font-weight:600;font-size:14px}.btn-secondary:hover{background:var(--surface-hover)}.btn-danger{height:38px;padding:0 18px;border:none;border-radius:var(--radius-pill);background:var(--danger);color:#ffffff;font-weight:600;font-size:14px}.preview-backdrop{position:fixed;inset:0;background:rgba(15,17,21,0.9);display:none;flex-direction:column;z-index:250}.preview-backdrop.open{display:flex}.preview-topbar{height:60px;display:flex;align-items:center;justify-content:space-between;padding:0 16px;color:#ffffff;background:rgba(0,0,0,0.35)}.preview-title-wrap{display:flex;align-items:center;gap:12px;min-width:0}.preview-title{margin:0;font-size:16px;font-weight:500;color:#ffffff;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.preview-topbar .icon-btn{color:#ffffff}.preview-topbar .icon-btn:hover{background:rgba(255,255,255,0.14);color:#ffffff}.preview-body{flex:1;display:flex;align-items:center;justify-content:center;padding:20px;overflow:auto}.preview-doc{width:100%;max-width:860px;max-height:100%;overflow:auto;background:#ffffff;color:var(--ink);border-radius:var(--radius-md);padding:24px;font-family:var(--font-mono);font-size:13px;line-height:1.65;white-space:pre-wrap;word-break:break-word;box-shadow:var(--shadow-lg)}.preview-img{max-width:100%;max-height:82vh;border-radius:var(--radius-sm);background:#ffffff;box-shadow:var(--shadow-lg)}.preview-pdf{width:min(900px,100%);height:80vh;border:none;border-radius:var(--radius-sm);background:#ffffff}.toast-container{position:fixed;bottom:24px;left:24px;z-index:300;display:flex;flex-direction:column;gap:8px}.toast{background:#202124;color:#ffffff;padding:12px 18px;border-radius:var(--radius-sm);font-size:14px;box-shadow:var(--shadow-lg);max-width:360px}.semantic-footer{margin-top:auto;padding:16px 20px;border-top:1px solid var(--border);background:#fafcff;border-radius:0 0 var(--radius-lg) var(--radius-lg);font-size:12px;color:var(--ink-secondary);display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px}.semantic-links{display:flex;flex-wrap:wrap;gap:8px}.semantic-links a{display:inline-flex;align-items:center;min-height:32px;padding:4px 8px;color:var(--primary);font-weight:500;border-radius:var(--radius-sm)}.semantic-links a:hover{background:var(--surface-variant);text-decoration:none}.content-page{padding:24px 28px 36px;max-width:920px}.content-page h1{margin:0 0 12px;font-size:26px;font-weight:600;color:var(--ink)}.content-page h2{margin:28px 0 10px;font-size:19px;font-weight:600;color:var(--ink)}.content-page h3{margin:18px 0 6px;font-size:15px;font-weight:600;color:var(--ink)}.content-page ul{list-style:none;padding:0;margin:12px 0;display:flex;flex-direction:column;gap:6px}.content-page ul li a{display:inline-flex;align-items:center;min-height:38px;padding:6px 10px;border-radius:var(--radius-sm);color:var(--primary);font-weight:500}.content-page ul li a:hover{background:var(--surface-hover);text-decoration:none}.tldr-box{background:#edf2fa;border-left:4px solid var(--primary);padding:14px 18px;border-radius:0 var(--radius-sm) var(--radius-sm) 0;margin:14px 0 20px;color:var(--ink)}.spec-table{width:100%;border-collapse:collapse;margin:14px 0 20px;font-size:13px}.spec-table th,.spec-table td{border:1px solid var(--border);padding:10px 12px;text-align:left}.spec-table th{background:var(--shell-bg);color:var(--ink);font-weight:600}.faq-item{border:1px solid var(--border);border-radius:var(--radius-md);padding:14px 16px;margin-bottom:10px;background:var(--surface)}.faq-item summary{font-weight:600;color:var(--ink);cursor:pointer;min-height:28px;display:flex;align-items:center}.faq-item p{margin:8px 0 0;color:var(--ink-secondary)}.move-folder-list{display:flex;flex-direction:column;gap:6px;max-height:260px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius-sm);padding:8px}.move-folder-option{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:var(--radius-sm);cursor:pointer}.move-folder-option:hover{background:var(--surface-hover)}.drawer-scrim{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:80}.drawer-scrim.open{display:block}.mobile-bottom-nav{display:none;position:fixed;left:0;right:0;bottom:0;height:64px;background:var(--shell-bg);border-top:1px solid var(--border);z-index:60;grid-template-columns:repeat(4,1fr);padding:4px 6px}.mobile-nav-item{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;font-size:11px;font-weight:500;color:var(--ink-secondary);text-decoration:none;border-radius:var(--radius-md);min-height:48px}.mobile-nav-item:hover{text-decoration:none}.mobile-nav-item[aria-current="page"]{color:var(--active-fg);font-weight:700}.mobile-nav-pill{display:inline-flex;align-items:center;justify-content:center;width:56px;height:28px;border-radius:var(--radius-pill)}.mobile-nav-item[aria-current="page"] .mobile-nav-pill{background:var(--active-bg)}.mobile-fab{display:none;position:fixed;right:16px;bottom:80px;width:56px;height:56px;border:none;border-radius:16px;background:var(--active-bg);color:var(--active-fg);box-shadow:var(--shadow-md);align-items:center;justify-content:center;z-index:65}@media (max-width:1024px){.drive-table th.col-owner,.drive-table td.col-owner{display:none}.drive-table th.col-name{width:58%}.fw-badge{display:none}}@media (max-width:768px){.app-shell{grid-template-columns:minmax(0,1fr);grid-template-areas:"header" "workspace"}.app-header{padding:8px 10px;gap:6px}.brand-area{min-width:0;gap:2px}.brand-link{padding:2px 4px}.brand-name{display:none}.menu-toggle-btn{display:inline-flex;width:40px;height:40px}.search-form{height:44px;padding:0 8px 0 12px;gap:6px}.search-input{font-size:14px}.header-actions{gap:4px;flex-shrink:0}.header-actions .hide-mobile{display:none}.app-sidebar{position:fixed;top:0;left:0;bottom:0;width:280px;max-width:82vw;z-index:90;transform:translateX(-105%);transition:transform 0.2s ease;box-shadow:var(--shadow-lg);padding-top:16px;overflow-y:auto}.app-sidebar.open{transform:translateX(0)}.workspace-container{padding:0 0 80px;width:100%}.workspace-main{border-radius:0;border-left:none;border-right:none;width:100%;min-height:calc(100vh - var(--header-h) - 64px)}.workspace-toolbar{padding:12px 12px 8px;gap:8px;flex-wrap:nowrap}.breadcrumb-current,.breadcrumb-title{font-size:18px}.breadcrumb-link{font-size:15px;padding:4px 6px}.view-toggle-btn{padding:0 10px;font-size:12px}.filter-bar{padding:4px 12px 10px;gap:8px}.filter-chips{flex-wrap:nowrap;overflow-x:auto;max-width:100%;padding-bottom:2px;-webkit-overflow-scrolling:touch;scrollbar-width:none}.filter-chips::-webkit-scrollbar{display:none}.chip-btn{flex-shrink:0;height:32px;padding:0 12px;font-size:12px}.sort-controls{width:100%;justify-content:flex-end}.files-area{padding:4px 8px 20px;width:100%}.drive-table{width:100%;table-layout:fixed}.drive-table thead{display:none}.drive-table td.col-owner,.drive-table td.col-updated,.drive-table td.col-size{display:none}.drive-table td.col-name{width:auto;max-width:0;padding:8px 4px 8px 6px}.drive-table td.col-actions{width:48px;padding:4px}.drive-row td{height:58px}.item-name-cell{flex-wrap:nowrap;gap:10px;min-width:0}.item-name-cell>svg{flex-shrink:0}.item-text-stack{display:flex;flex-direction:column;min-width:0;flex:1}.item-link{max-width:100%;font-size:14px}.item-sub-mobile{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.kebab-btn{width:40px;height:40px}.details-panel.open{position:fixed;inset:auto 0 0 0;width:100%;max-height:75vh;border-radius:20px 20px 0 0;z-index:150;box-shadow:var(--shadow-lg)}.mobile-bottom-nav{display:grid}.mobile-fab{display:inline-flex}.toast-container{bottom:76px;left:12px;right:12px}.content-page{padding:18px 16px 32px}}@media (prefers-reduced-motion:reduce){*,*::before,*::after{transition:none !important;animation:none !important}}';

    /** @var array<string, array{offset:int, length:int, mime:string, cache:string, etag:string}> */
    public const MANIFEST = array (
  '/sw.js' => 
  array (
    'offset' => 0,
    'length' => 1604,
    'mime' => 'application/javascript; charset=utf-8',
    'cache' => 'no-cache',
    'etag' => '"fafda811a1610bde"',
  ),
  '/assets/app.js' => 
  array (
    'offset' => 1604,
    'length' => 3755,
    'mime' => 'application/javascript; charset=utf-8',
    'cache' => 'public, max-age=31536000, immutable',
    'etag' => '"1b0747c11e07f56c"',
  ),
  '/assets/drive-ui.js' => 
  array (
    'offset' => 5359,
    'length' => 47671,
    'mime' => 'application/javascript; charset=utf-8',
    'cache' => 'public, max-age=31536000, immutable',
    'etag' => '"7b80e0d8909a04e2"',
  ),
  '/assets/app.css' => 
  array (
    'offset' => 53030,
    'length' => 30730,
    'mime' => 'text/css; charset=utf-8',
    'cache' => 'public, max-age=31536000, immutable',
    'etag' => '"db11488874132bd6"',
  ),
  '/assets/app.min.css' => 
  array (
    'offset' => 83760,
    'length' => 24778,
    'mime' => 'text/css; charset=utf-8',
    'cache' => 'public, max-age=31536000, immutable',
    'etag' => '"a4458f364c20ae56"',
  ),
  '/assets/favicon.svg' => 
  array (
    'offset' => 108538,
    'length' => 449,
    'mime' => 'image/svg+xml; charset=utf-8',
    'cache' => 'public, max-age=31536000, immutable',
    'etag' => '"55498ca27e7bdb13"',
  ),
  '/assets/icon-192.png' => 
  array (
    'offset' => 108987,
    'length' => 2249,
    'mime' => 'image/png',
    'cache' => 'public, max-age=31536000, immutable',
    'etag' => '"ce4f4149e85efc30"',
  ),
  '/assets/icon-maskable-192.png' => 
  array (
    'offset' => 111236,
    'length' => 1896,
    'mime' => 'image/png',
    'cache' => 'public, max-age=31536000, immutable',
    'etag' => '"e7873d5348fe893c"',
  ),
  '/assets/icon-512.png' => 
  array (
    'offset' => 113132,
    'length' => 6084,
    'mime' => 'image/png',
    'cache' => 'public, max-age=31536000, immutable',
    'etag' => '"11b76cd1b3aede40"',
  ),
  '/assets/icon-maskable-512.png' => 
  array (
    'offset' => 119216,
    'length' => 5392,
    'mime' => 'image/png',
    'cache' => 'public, max-age=31536000, immutable',
    'etag' => '"b37427e232b342e3"',
  ),
  '/assets/og-image.png' => 
  array (
    'offset' => 124608,
    'length' => 9020,
    'mime' => 'image/png',
    'cache' => 'public, max-age=31536000, immutable',
    'etag' => '"d2899446cd746ed3"',
  ),
  '/assets/screenshot-desktop.png' => 
  array (
    'offset' => 133628,
    'length' => 135002,
    'mime' => 'image/png',
    'cache' => 'public, max-age=31536000, immutable',
    'etag' => '"c23bc25723a74cbb"',
  ),
  '/assets/screenshot-mobile.png' => 
  array (
    'offset' => 268630,
    'length' => 170338,
    'mime' => 'image/png',
    'cache' => 'public, max-age=31536000, immutable',
    'etag' => '"6c1c8719e66718c5"',
  ),
);

    public static function readBytes(string $route): ?string
    {
        $meta = self::MANIFEST[$route] ?? null;
        if ($meta === null) {
            return null;
        }
        $fp = fopen(__FILE__, 'rb');
        if ($fp === false) {
            return null;
        }
        try {
            fseek($fp, __COMPILER_HALT_OFFSET__ + $meta['offset']);
            $remaining = $meta['length'];
            $buf = '';
            while ($remaining > 0 && !feof($fp)) {
                $chunk = fread($fp, min(65536, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $buf .= $chunk;
                $remaining -= strlen($chunk);
            }
            return $buf;
        } finally {
            fclose($fp);
        }
    }
}

final class SingleFileRuntime
{
    private const SCHEMA_SQL = 'PRAGMA journal_mode = WAL;
PRAGMA synchronous = NORMAL;
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id INTEGER NULL REFERENCES items(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    is_folder INTEGER NOT NULL DEFAULT 0 CHECK (is_folder IN (0, 1)),
    mime_type TEXT NOT NULL DEFAULT \'application/octet-stream\',
    extension TEXT NOT NULL DEFAULT \'\',
    size_bytes INTEGER NOT NULL DEFAULT 0 CHECK (size_bytes >= 0),
    storage_name TEXT NULL,
    owner_name TEXT NOT NULL DEFAULT \'å±±ç”° å¤ªéƒŽ\',
    owner_email TEXT NOT NULL DEFAULT \'taro.yamada@clouddrive.example\',
    description TEXT NOT NULL DEFAULT \'\',
    is_starred INTEGER NOT NULL DEFAULT 0 CHECK (is_starred IN (0, 1)),
    is_shared INTEGER NOT NULL DEFAULT 0 CHECK (is_shared IN (0, 1)),
    share_token TEXT NULL UNIQUE,
    is_trashed INTEGER NOT NULL DEFAULT 0 CHECK (is_trashed IN (0, 1)),
    trashed_at TEXT NULL,
    last_opened_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_items_parent_trashed ON items(parent_id, is_trashed, is_folder DESC, name ASC);
CREATE INDEX IF NOT EXISTS idx_items_trashed ON items(is_trashed, updated_at DESC);
CREATE INDEX IF NOT EXISTS idx_items_starred ON items(is_starred, is_trashed);
CREATE INDEX IF NOT EXISTS idx_items_shared ON items(is_shared, is_trashed);
CREATE INDEX IF NOT EXISTS idx_items_recent ON items(is_trashed, last_opened_at DESC);
CREATE INDEX IF NOT EXISTS idx_items_name ON items(name COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS idx_items_share_token ON items(share_token);

CREATE TABLE IF NOT EXISTS activities (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
    action TEXT NOT NULL,
    actor_name TEXT NOT NULL DEFAULT \'å±±ç”° å¤ªéƒŽ\',
    detail TEXT NOT NULL DEFAULT \'\',
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_activities_item ON activities(item_id, created_at DESC);
';

    /**
     * Handles dotfile 403 protection and embedded static assets (/assets/*, /sw.js)
     * before booting SQLite or the full application pipeline.
     */
    public static function tryServeStaticOrBlockDotfile(): bool
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        // Defense-in-depth: block dotfiles (except /.well-known) even without Nginx/Apache rules
        if (preg_match('#^/\.(?!well-known(/|$))#', $path) === 1 || str_contains($path, '/.clouddrive_data')) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
                echo 'Forbidden';
            }
            return true;
        }

        $meta = EmbeddedAssets::MANIFEST[$path] ?? null;
        if ($meta === null) {
            return false;
        }

        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') {
            http_response_code(405);
            header('Allow: GET, HEAD');
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Method Not Allowed';
            return true;
        }

        header('Content-Type: ' . $meta['mime']);
        header('Cache-Control: ' . $meta['cache']);
        header('ETag: ' . $meta['etag']);
        header('X-Framework: ai-framework');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Cross-Origin-Opener-Policy: same-origin');
        if ($path === '/sw.js') {
            header('Service-Worker-Allowed: /');
        }

        $inm = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        if ($inm !== '' && trim($inm) === $meta['etag']) {
            http_response_code(304);
            return true;
        }

        header('Content-Length: ' . $meta['length']);
        http_response_code(200);
        if ($method === 'HEAD') {
            return true;
        }

        $bytes = EmbeddedAssets::readBytes($path);
        if ($bytes !== null) {
            echo $bytes;
        }
        return true;
    }

    /** @return array{dataDir:string, dbPath:string, uploadsDir:string, secret:string} */
    public static function prepareEnvironment(): array
    {
        $configuredDir = getenv('CLOUDDRIVE_DATA_DIR');
        $dataDir = (is_string($configuredDir) && $configuredDir !== '')
            ? rtrim($configuredDir, '/\\')
            : (__DIR__ . DIRECTORY_SEPARATOR . '.clouddrive_data');

        $uploadsDir = $dataDir . DIRECTORY_SEPARATOR . 'uploads';
        $dbPath = $dataDir . DIRECTORY_SEPARATOR . 'database.sqlite';

        if (!is_dir($uploadsDir)) {
            @mkdir($uploadsDir, 0700, true);
        }

        // Protect data directory on Apache / LiteSpeed rental servers
        $dataHtaccess = $dataDir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($dataHtaccess)) {
            @file_put_contents(
                $dataHtaccess,
                "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n"
            );
            @file_put_contents($dataDir . DIRECTORY_SEPARATOR . 'index.html', '');
        }

        // On Apache/LiteSpeed shared hosting, auto-create clean rewrite .htaccess if absent
        self::maybeCreateRootHtaccess();

        // Fast-path once initialized
        $envSecret = getenv('APP_SECRET');
        $secretFile = $dataDir . DIRECTORY_SEPARATOR . '.app_secret';
        $secret = (is_string($envSecret) && strlen($envSecret) >= 32) ? $envSecret : '';

        if ($secret === '' && is_file($secretFile)) {
            $stored = trim((string)@file_get_contents($secretFile));
            if (strlen($stored) >= 32) {
                $secret = $stored;
            }
        }

        $needsInit = ($secret === '') || !is_file($dbPath) || filesize($dbPath) === 0;
        if ($needsInit) {
            $lockFp = fopen($dataDir . DIRECTORY_SEPARATOR . '.init.lock', 'c+');
            if ($lockFp !== false) {
                flock($lockFp, LOCK_EX);
                try {
                    if ($secret === '') {
                        if (is_file($secretFile)) {
                            $stored = trim((string)@file_get_contents($secretFile));
                            if (strlen($stored) >= 32) {
                                $secret = $stored;
                            }
                        }
                        if ($secret === '') {
                            $secret = bin2hex(random_bytes(32));
                            @file_put_contents($secretFile, $secret . "\n", LOCK_EX);
                            @chmod($secretFile, 0600);
                        }
                    }

                    if (!is_file($dbPath) || filesize($dbPath) === 0) {
                        self::initializeDatabase($dbPath, $uploadsDir);
                    }
                } finally {
                    flock($lockFp, LOCK_UN);
                    fclose($lockFp);
                }
            }
        }

        return [
            'dataDir' => $dataDir,
            'dbPath' => $dbPath,
            'uploadsDir' => $uploadsDir,
            'secret' => $secret,
        ];
    }

    private static function maybeCreateRootHtaccess(): void
    {
        if (getenv('CLOUDDRIVE_NO_HTACCESS') === '1') {
            return;
        }
        $serverSoft = (string)($_SERVER['SERVER_SOFTWARE'] ?? '');
        if (stripos($serverSoft, 'Apache') === false && stripos($serverSoft, 'LiteSpeed') === false) {
            return;
        }
        $htPath = __DIR__ . DIRECTORY_SEPARATOR . '.htaccess';
        if (is_file($htPath) || !is_writable(__DIR__)) {
            return;
        }
        $self = basename(__FILE__);
        // Rental-server safe (no Options directive that triggers HTTP 500 on Sakura/Lolipop)
        $rules = <<<HT
DirectoryIndex {$self}
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteRule ^\.(?!well-known/) - [F,L]
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteRule ^ {$self} [QSA,L]
</IfModule>

HT;
        @file_put_contents($htPath, $rules, LOCK_EX);
    }

    private static function initializeDatabase(string $dbPath, string $uploadsDir): void
    {
        $pdo = new \PDO('sqlite:' . $dbPath, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $pdo->exec(self::SCHEMA_SQL);
        if (getenv('CLOUDDRIVE_SKIP_SEED') === '1') {
            return;
        }
        self::seedDatabase($pdo, $uploadsDir);
    }

    /** @param array{int,int,int} $bgRgb @param array{int,int,int} $accentRgb */
    private static function createSamplePng(string $path, int $w, int $h, array $bgRgb, array $accentRgb, string $label): int
    {
        if (!function_exists('imagecreatetruecolor')) {
            $fallback = EmbeddedAssets::readBytes('/assets/og-image.png') ?? '';
            file_put_contents($path, $fallback);
            return strlen($fallback);
        }
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, true);
        imagesavealpha($img, true);
        $bg = imagecolorallocate($img, $bgRgb[0], $bgRgb[1], $bgRgb[2]);
        $accent = imagecolorallocate($img, $accentRgb[0], $accentRgb[1], $accentRgb[2]);
        $white = imagecolorallocate($img, 255, 255, 255);
        $card = imagecolorallocate($img, 248, 250, 253);
        $ink = imagecolorallocate($img, 31, 31, 31);
        $muted = imagecolorallocate($img, 95, 99, 104);

        imagefilledrectangle($img, 0, 0, $w, $h, $bg);
        imagefilledrectangle($img, 24, 24, $w - 24, $h - 24, $card);
        imagefilledrectangle($img, 24, 24, $w - 24, 88, $accent);

        $barColors = [
            imagecolorallocate($img, 26, 115, 232),
            imagecolorallocate($img, 15, 157, 88),
            imagecolorallocate($img, 244, 180, 0),
            imagecolorallocate($img, 234, 67, 53),
        ];
        $barW = (int)(($w - 160) / 4) - 24;
        $heights = [0.75, 0.58, 0.88, 0.66];
        for ($i = 0; $i < 4; $i++) {
            $x1 = 72 + $i * ($barW + 24);
            $barH = (int)(($h - 190) * $heights[$i]);
            $y1 = $h - 64 - $barH;
            imagefilledrectangle($img, $x1, $y1, $x1 + $barW, $h - 64, $barColors[$i]);
        }
        imagestring($img, 5, 44, 48, $label, $white);
        imagestring($img, 3, 44, $h - 46, 'CloudDrive Visual Asset - 2026', $muted);
        imagestring($img, 3, $w - 170, $h - 46, $w . 'x' . $h . ' PNG', $ink);

        imagepng($img, $path, 6);
        imagedestroy($img);
        return (int)filesize($path);
    }

    private static function createSamplePdf(string $path, string $title, string $body): int
    {
        $safeTitle = preg_replace('/[^\x20-\x7E]/', '', $title) ?: 'CloudDrive Document';
        $safeBody = preg_replace('/[^\x20-\x7E]/', '', $body) ?: 'Generated sample PDF document.';
        $stream = "BT /F1 18 Tf 50 750 Td ({$safeTitle}) Tj 0 -30 Td /F1 12 Tf ({$safeBody}) Tj ET";
        $len = strlen($stream);
        $objects = [
            "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n",
            "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n",
            "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj\n",
            "4 0 obj << /Length {$len} >> stream\n{$stream}\nendstream endobj\n",
            "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= $obj;
        }
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer << /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF\n";
        file_put_contents($path, $pdf);
        return strlen($pdf);
    }

    private static function seedDatabase(\PDO $pdo, string $uploadsDir): void
    {
$insertItem = $pdo->prepare(
    'INSERT INTO items (parent_id, name, is_folder, mime_type, extension, size_bytes, storage_name, owner_name, owner_email, description, is_starred, is_shared, share_token, is_trashed, trashed_at, last_opened_at, created_at, updated_at)
     VALUES (:parent_id, :name, :is_folder, :mime_type, :extension, :size_bytes, :storage_name, :owner_name, :owner_email, :description, :is_starred, :is_shared, :share_token, :is_trashed, :trashed_at, :last_opened_at, :created_at, :updated_at)'
);

$insertActivity = $pdo->prepare(
    'INSERT INTO activities (item_id, action, actor_name, detail, created_at)
     VALUES (:item_id, :action, :actor_name, :detail, :created_at)'
);

$pdo->beginTransaction();

// 1. Root Folders (IDs 1..5)
$rootFolders = [
    ['name' => 'ãƒ—ãƒ­ã‚¸ã‚§ã‚¯ãƒˆä¼ç”»', 'owner' => 'å±±ç”° å¤ªéƒŽ', 'email' => 'taro.yamada@clouddrive.example', 'desc' => '2026å¹´åº¦ã®ä¸»è¦ãƒ—ãƒ­ãƒ€ã‚¯ãƒˆä¼ç”»ãŠã‚ˆã³ãƒ­ãƒ¼ãƒ‰ãƒžãƒƒãƒ—è³‡æ–™', 'starred' => 1, 'shared' => 1, 'token' => 'project-planning-2026', 'updated' => '2026-11-24T08:45:00Z'],
    ['name' => 'ãƒ‡ã‚¶ã‚¤ãƒ³ã‚¢ã‚»ãƒƒãƒˆ', 'owner' => 'ä½è—¤ èŠ±å­', 'email' => 'hanako.sato@clouddrive.example', 'desc' => 'UIã‚¬ã‚¤ãƒ‰ãƒ©ã‚¤ãƒ³ã€ã‚¢ã‚¤ã‚³ãƒ³ã€ç”»é¢ãƒ¢ãƒƒã‚¯ã‚¢ãƒƒãƒ—ä¸€å¼', 'starred' => 0, 'shared' => 1, 'token' => 'design-assets-2026', 'updated' => '2026-11-23T17:20:00Z'],
    ['name' => 'è­°äº‹éŒ²ãƒ»ãƒŸãƒ¼ãƒ†ã‚£ãƒ³ã‚°', 'owner' => 'å±±ç”° å¤ªéƒŽ', 'email' => 'taro.yamada@clouddrive.example', 'desc' => 'å®šä¾‹ä¼šè­°ãŠã‚ˆã³ã‚¢ãƒ¼ã‚­ãƒ†ã‚¯ãƒãƒ£ãƒ¬ãƒ“ãƒ¥ãƒ¼ã®è­°äº‹éŒ²', 'starred' => 0, 'shared' => 0, 'token' => null, 'updated' => '2026-11-22T14:10:00Z'],
    ['name' => 'è²¡å‹™ãƒ»ãƒ¬ãƒãƒ¼ãƒˆ', 'owner' => 'éˆ´æœ¨ ä¸€éƒŽ', 'email' => 'ichiro.suzuki@clouddrive.example', 'desc' => 'æœˆæ¬¡KPIã€ã‚¯ãƒ©ã‚¦ãƒ‰ã‚¤ãƒ³ãƒ•ãƒ©è²»ç”¨ãŠã‚ˆã³äºˆç®—å®Ÿç¸¾CSV', 'starred' => 0, 'shared' => 0, 'token' => null, 'updated' => '2026-11-21T11:30:00Z'],
    ['name' => 'æŠ€è¡“ã‚¢ãƒ¼ã‚­ãƒ†ã‚¯ãƒãƒ£', 'owner' => 'å±±ç”° å¤ªéƒŽ', 'email' => 'taro.yamada@clouddrive.example', 'desc' => 'PHPãƒ•ãƒ¬ãƒ¼ãƒ ãƒ¯ãƒ¼ã‚¯æ¯”è¼ƒæ¤œè¨¼ã€ã‚»ã‚­ãƒ¥ãƒªãƒ†ã‚£ç›£æŸ»ã€ãƒ™ãƒ³ãƒãƒžãƒ¼ã‚¯ä»•æ§˜', 'starred' => 1, 'shared' => 1, 'token' => 'tech-architecture-2026', 'updated' => '2026-11-24T09:15:00Z'],
];

$folderIds = [];
foreach ($rootFolders as $idx => $f) {
    $insertItem->execute([
        ':parent_id' => null,
        ':name' => $f['name'],
        ':is_folder' => 1,
        ':mime_type' => 'application/vnd.clouddrive.folder',
        ':extension' => '',
        ':size_bytes' => 0,
        ':storage_name' => null,
        ':owner_name' => $f['owner'],
        ':owner_email' => $f['email'],
        ':description' => $f['desc'],
        ':is_starred' => $f['starred'],
        ':is_shared' => $f['shared'],
        ':share_token' => $f['token'],
        ':is_trashed' => 0,
        ':trashed_at' => null,
        ':last_opened_at' => $f['updated'],
        ':created_at' => '2026-10-01T09:00:00Z',
        ':updated_at' => $f['updated'],
    ]);
    $id = (int)$pdo->lastInsertId();
    $folderIds[$f['name']] = $id;
    $insertActivity->execute([
        ':item_id' => $id,
        ':action' => 'created',
        ':actor_name' => $f['owner'],
        ':detail' => "ãƒ•ã‚©ãƒ«ãƒ€ã€Œ{$f['name']}ã€ã‚’ä½œæˆã—ã¾ã—ãŸ",
        ':created_at' => '2026-10-01T09:00:00Z',
    ]);
}

// 2. Subfolders (IDs 6..11)
$subFolders = [
    ['parent' => 'ãƒ—ãƒ­ã‚¸ã‚§ã‚¯ãƒˆä¼ç”»', 'name' => '2026å¹´Q4ãƒ­ãƒ¼ãƒ‰ãƒžãƒƒãƒ—', 'desc' => 'Q4ãƒžã‚¤ãƒ«ã‚¹ãƒˆãƒ¼ãƒ³ã¨ã‚¹ãƒ—ãƒªãƒ³ãƒˆè¨ˆç”»', 'updated' => '2026-11-24T07:30:00Z'],
    ['parent' => 'ãƒ—ãƒ­ã‚¸ã‚§ã‚¯ãƒˆä¼ç”»', 'name' => 'ç«¶åˆåˆ†æžãƒ»èª¿æŸ»', 'desc' => 'ã‚¯ãƒ©ã‚¦ãƒ‰ã‚¹ãƒˆãƒ¬ãƒ¼ã‚¸UXæ¯”è¼ƒãƒ¡ãƒ¢', 'updated' => '2026-11-20T16:00:00Z'],
    ['parent' => 'ãƒ‡ã‚¶ã‚¤ãƒ³ã‚¢ã‚»ãƒƒãƒˆ', 'name' => 'Material 3 ã‚³ãƒ³ãƒãƒ¼ãƒãƒ³ãƒˆ', 'desc' => 'ãƒœã‚¿ãƒ³ã€ã‚«ãƒ¼ãƒ‰ã€ãƒ€ã‚¤ã‚¢ãƒ­ã‚°ã®UIä»•æ§˜', 'updated' => '2026-11-23T15:10:00Z'],
    ['parent' => 'æŠ€è¡“ã‚¢ãƒ¼ã‚­ãƒ†ã‚¯ãƒãƒ£', 'name' => 'PHPãƒ•ãƒ¬ãƒ¼ãƒ ãƒ¯ãƒ¼ã‚¯æ¤œè¨¼', 'desc' => 'AIè‡ªä½œFWãƒ»Laravelãƒ»Phalconãƒ»Slimã®è¨­è¨ˆè³‡æ–™', 'updated' => '2026-11-24T09:00:00Z'],
    ['parent' => 'æŠ€è¡“ã‚¢ãƒ¼ã‚­ãƒ†ã‚¯ãƒãƒ£', 'name' => 'ã‚»ã‚­ãƒ¥ãƒªãƒ†ã‚£ç›£æŸ»', 'desc' => 'æ•µå¯¾çš„ãƒ¬ãƒ“ãƒ¥ãƒ¼ãŠã‚ˆã³è„†å¼±æ€§ãƒã‚§ãƒƒã‚¯ãƒªã‚¹ãƒˆ', 'updated' => '2026-11-24T08:10:00Z'],
    ['parent' => 'è­°äº‹éŒ²ãƒ»ãƒŸãƒ¼ãƒ†ã‚£ãƒ³ã‚°', 'name' => '2026å¹´11æœˆå®šä¾‹', 'desc' => '11æœˆåˆ†ã®é€±æ¬¡è­°äº‹éŒ²ã‚¢ãƒ¼ã‚«ã‚¤ãƒ–', 'updated' => '2026-11-22T10:00:00Z'],
];

foreach ($subFolders as $sf) {
    $insertItem->execute([
        ':parent_id' => $folderIds[$sf['parent']],
        ':name' => $sf['name'],
        ':is_folder' => 1,
        ':mime_type' => 'application/vnd.clouddrive.folder',
        ':extension' => '',
        ':size_bytes' => 0,
        ':storage_name' => null,
        ':owner_name' => 'å±±ç”° å¤ªéƒŽ',
        ':owner_email' => 'taro.yamada@clouddrive.example',
        ':description' => $sf['desc'],
        ':is_starred' => 0,
        ':is_shared' => 0,
        ':share_token' => null,
        ':is_trashed' => 0,
        ':trashed_at' => null,
        ':last_opened_at' => $sf['updated'],
        ':created_at' => '2026-10-15T10:00:00Z',
        ':updated_at' => $sf['updated'],
    ]);
    $folderIds[$sf['name']] = (int)$pdo->lastInsertId();
}

// 3. Seed real files on disk & database
$filesSpec = [
    // Root files (shown immediately on /)
    [
        'parent' => null,
        'name' => '2026å¹´Q4_ãƒ—ãƒ­ãƒ€ã‚¯ãƒˆä¼ç”»æ›¸.md',
        'mime' => 'text/markdown',
        'ext' => 'md',
        'storage' => 'seed-q4-product-plan.md',
        'content' => "# 2026å¹´Q4 ãƒ—ãƒ­ãƒ€ã‚¯ãƒˆä¼ç”»æ›¸\n\n## æ¦‚è¦\nCloudDrive ã¯é«˜é€Ÿãƒ»è»½é‡ãƒ»PWAå®Œå…¨å¯¾å¿œã®ã‚¯ãƒ©ã‚¦ãƒ‰ãƒ•ã‚¡ã‚¤ãƒ«ç®¡ç†ãƒ—ãƒ©ãƒƒãƒˆãƒ•ã‚©ãƒ¼ãƒ ã§ã™ã€‚\n\n## ç›®æ¨™KPI\n- Lighthouse å…¨4é …ç›®ï¼ˆPerformance / Accessibility / Best Practices / SEOï¼‰100ç‚¹\n- ãƒ¢ãƒã‚¤ãƒ«ãƒ»ãƒ‡ã‚¹ã‚¯ãƒˆãƒƒãƒ—ä¸¡ç’°å¢ƒã§ã®ã‚¼ãƒ­CLSæç”»\n- PHP 8.3 ãƒ•ãƒ¬ãƒ¼ãƒ ãƒ¯ãƒ¼ã‚¯é–“ã§ã®å…¬å¹³ãªæ€§èƒ½ãƒ™ãƒ³ãƒãƒžãƒ¼ã‚¯æ¤œè¨¼\n\n## ä¸»è¦æ©Ÿèƒ½\n1. ãƒžã‚¤ãƒ‰ãƒ©ã‚¤ãƒ–éšŽå±¤ç®¡ç†ãƒ»ãƒ‘ãƒ³ããšãƒŠãƒ“ã‚²ãƒ¼ã‚·ãƒ§ãƒ³\n2. ãƒ‰ãƒ©ãƒƒã‚°ï¼†ãƒ‰ãƒ­ãƒƒãƒ—å¯¾å¿œãƒ•ã‚¡ã‚¤ãƒ«ã‚¢ãƒƒãƒ—ãƒ­ãƒ¼ãƒ‰\n3. ãƒªã‚¢ãƒ«ã‚¿ã‚¤ãƒ æ¤œç´¢ãƒ»ç¨®åˆ¥ãƒ•ã‚£ãƒ«ã‚¿ãƒ»ä¸¦ã³æ›¿ãˆ\n4. å…±æœ‰ãƒªãƒ³ã‚¯ç™ºè¡Œãƒ»ã‚¹ã‚¿ãƒ¼ãƒ»ã‚´ãƒŸç®±å¾©å…ƒ\n",
        'owner' => 'å±±ç”° å¤ªéƒŽ',
        'email' => 'taro.yamada@clouddrive.example',
        'desc' => '2026å¹´Q4ã®æœ€çµ‚æ‰¿èªæ¸ˆã¿ãƒ—ãƒ­ãƒ€ã‚¯ãƒˆä¼ç”»æ›¸',
        'starred' => 1,
        'shared' => 1,
        'token' => 'q4-product-plan-2026',
        'updated' => '2026-11-24T09:10:00Z',
        'opened' => '2026-11-24T09:12:00Z',
    ],
    [
        'parent' => null,
        'name' => 'PHPãƒ•ãƒ¬ãƒ¼ãƒ ãƒ¯ãƒ¼ã‚¯æ€§èƒ½æ¯”è¼ƒãƒ¬ãƒãƒ¼ãƒˆ.pdf',
        'mime' => 'application/pdf',
        'ext' => 'pdf',
        'storage' => 'seed-php-framework-benchmark.pdf',
        'type' => 'pdf',
        'pdf_title' => 'PHP 8.3 Framework Benchmark Specification',
        'pdf_body' => 'Comparing AI Custom Framework, Laravel, Phalcon 5, and Slim 4 under identical Nginx + PHP-FPM 8.3.',
        'owner' => 'å±±ç”° å¤ªéƒŽ',
        'email' => 'taro.yamada@clouddrive.example',
        'desc' => 'AIã‚ªãƒ¬ã‚ªãƒ¬FWãƒ»Laravelãƒ»Phalconãƒ»Slimã®çµ±ä¸€ãƒ™ãƒ³ãƒãƒžãƒ¼ã‚¯è©•ä¾¡ä»•æ§˜æ›¸',
        'starred' => 1,
        'shared' => 1,
        'token' => 'php-benchmark-spec-pdf',
        'updated' => '2026-11-24T08:55:00Z',
        'opened' => '2026-11-24T09:05:00Z',
    ],
    [
        'parent' => null,
        'name' => 'ã‚¯ãƒ©ã‚¦ãƒ‰ã‚¹ãƒˆãƒ¬ãƒ¼ã‚¸åˆ©ç”¨çµ±è¨ˆ_2026å¹´11æœˆ.csv',
        'mime' => 'text/csv',
        'ext' => 'csv',
        'storage' => 'seed-storage-metrics-202611.csv',
        'content' => "æ—¥ä»˜,ã‚¢ã‚¯ãƒ†ã‚£ãƒ–ãƒ¦ãƒ¼ã‚¶ãƒ¼,ã‚¢ãƒƒãƒ—ãƒ­ãƒ¼ãƒ‰ä»¶æ•°,å¹³å‡å¿œç­”æ™‚é–“ms,Lighthouseã‚¹ã‚³ã‚¢\n2026-11-18,1240,8420,4.2,100\n2026-11-19,1310,9105,3.9,100\n2026-11-20,1285,8890,4.1,100\n2026-11-21,1420,10230,3.8,100\n2026-11-22,1390,9870,3.7,100\n2026-11-23,1510,11240,3.5,100\n2026-11-24,1625,12480,3.4,100\n",
        'owner' => 'éˆ´æœ¨ ä¸€éƒŽ',
        'email' => 'ichiro.suzuki@clouddrive.example',
        'desc' => '2026å¹´11æœˆã®æ—¥æ¬¡åˆ©ç”¨çµ±è¨ˆãŠã‚ˆã³ãƒ‘ãƒ•ã‚©ãƒ¼ãƒžãƒ³ã‚¹é›†è¨ˆãƒ‡ãƒ¼ã‚¿',
        'starred' => 0,
        'shared' => 1,
        'token' => 'storage-metrics-csv-2026',
        'updated' => '2026-11-24T08:30:00Z',
        'opened' => '2026-11-24T08:40:00Z',
    ],
    [
        'parent' => null,
        'name' => 'UIãƒ‘ãƒ•ã‚©ãƒ¼ãƒžãƒ³ã‚¹æŒ‡æ¨™ãƒ€ãƒƒã‚·ãƒ¥ãƒœãƒ¼ãƒ‰.png',
        'mime' => 'image/png',
        'ext' => 'png',
        'storage' => 'seed-ui-performance-chart.png',
        'type' => 'png',
        'png_bg' => [232, 240, 254],
        'png_accent' => [26, 115, 232],
        'png_label' => 'CloudDrive Core Web Vitals & Throughput',
        'owner' => 'ä½è—¤ èŠ±å­',
        'email' => 'hanako.sato@clouddrive.example',
        'desc' => 'Core Web Vitals ã¨ãƒ•ãƒ¬ãƒ¼ãƒ ãƒ¯ãƒ¼ã‚¯åˆ¥ã‚¹ãƒ«ãƒ¼ãƒ—ãƒƒãƒˆæ¯”è¼ƒã‚°ãƒ©ãƒ•ã‚£ãƒƒã‚¯',
        'starred' => 1,
        'shared' => 0,
        'token' => null,
        'updated' => '2026-11-23T19:40:00Z',
        'opened' => '2026-11-24T08:20:00Z',
    ],
    [
        'parent' => null,
        'name' => 'OpenAPI_ã‚¹ã‚­ãƒ¼ãƒžè¨­å®š.json',
        'mime' => 'application/json',
        'ext' => 'json',
        'storage' => 'seed-api-config.json',
        'content' => json_encode([
            'service' => 'CloudDrive',
            'version' => '1.0.0',
            'standards' => ['PWA', 'SEO', 'AEO', 'GEO', 'AIO', 'LLMO'],
            'quota_gb' => 15,
            'max_upload_mb' => 20,
            'frameworks' => ['ai-framework', 'laravel', 'phalcon', 'slim'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n",
        'owner' => 'å±±ç”° å¤ªéƒŽ',
        'email' => 'taro.yamada@clouddrive.example',
        'desc' => 'AIOãƒ»LLMOé€£æºå‘ã‘ã®APIãƒ¡ã‚¿ãƒ‡ãƒ¼ã‚¿å®šç¾©JSON',
        'starred' => 0,
        'shared' => 0,
        'token' => null,
        'updated' => '2026-11-23T16:15:00Z',
        'opened' => '2026-11-23T18:00:00Z',
    ],
    [
        'parent' => null,
        'name' => 'ãƒªãƒªãƒ¼ã‚¹ãƒã‚§ãƒƒã‚¯ãƒªã‚¹ãƒˆ_v1.0.txt',
        'mime' => 'text/plain',
        'ext' => 'txt',
        'storage' => 'seed-release-checklist.txt',
        'content' => "CloudDrive v1.0 ãƒªãƒªãƒ¼ã‚¹ãƒã‚§ãƒƒã‚¯ãƒªã‚¹ãƒˆ\n=====================================\n[x] å…¨4ãƒ•ãƒ¬ãƒ¼ãƒ ãƒ¯ãƒ¼ã‚¯ã®å®Œå…¨ãƒ‘ãƒªãƒ†ã‚£ç¢ºèª\n[x] Lighthouse Mobile / Desktop å…¨100ç‚¹é”æˆ\n[x] PWA Service Worker ã‚ªãƒ•ãƒ©ã‚¤ãƒ³å‹•ä½œç¢ºèª\n[x] robots.txt / sitemap.xml / llms.txt / openapi.json æ¤œè¨¼\n[x] æ•µå¯¾çš„ã‚»ã‚­ãƒ¥ãƒªãƒ†ã‚£ãƒ¬ãƒ“ãƒ¥ãƒ¼å®Œäº†\n",
        'owner' => 'é«˜æ©‹ å¥å¤ª',
        'email' => 'kenta.takahashi@clouddrive.example',
        'desc' => 'æœ¬ç•ªãƒªãƒªãƒ¼ã‚¹å‰ã®æœ€çµ‚ç¢ºèªé …ç›®',
        'starred' => 0,
        'shared' => 1,
        'token' => 'release-checklist-v1',
        'updated' => '2026-11-23T13:50:00Z',
        'opened' => '2026-11-23T14:10:00Z',
    ],
    [
        'parent' => null,
        'name' => 'ã‚·ã‚¹ãƒ†ãƒ æ§‹æˆå›³_Nginx_PHPFPM.png',
        'mime' => 'image/png',
        'ext' => 'png',
        'storage' => 'seed-system-architecture.png',
        'type' => 'png',
        'png_bg' => [230, 244, 234],
        'png_accent' => [15, 157, 88],
        'png_label' => 'Nginx + PHP-FPM 8.3 Isolated Pool Topology',
        'owner' => 'é«˜æ©‹ å¥å¤ª',
        'email' => 'kenta.takahashi@clouddrive.example',
        'desc' => 'ãƒ™ãƒ³ãƒãƒžãƒ¼ã‚¯ç”¨Nginxï¼‹PHP-FPMç‹¬ç«‹ãƒ—ãƒ¼ãƒ«æ§‹æˆå›³',
        'starred' => 0,
        'shared' => 0,
        'token' => null,
        'updated' => '2026-11-22T18:05:00Z',
        'opened' => '2026-11-22T18:30:00Z',
    ],
    [
        'parent' => null,
        'name' => 'SEO_AEO_GEO_LLMOæœ€é©åŒ–ã‚¬ã‚¤ãƒ‰.md',
        'mime' => 'text/markdown',
        'ext' => 'md',
        'storage' => 'seed-seo-llmo-guide.md',
        'content' => "# SEOãƒ»AEOãƒ»GEOãƒ»AIOãƒ»LLMO æœ€é©åŒ–è¨­è¨ˆã‚¬ã‚¤ãƒ‰\n\n- **SEO**: ã‚»ãƒžãƒ³ãƒ†ã‚£ãƒƒã‚¯HTMLã€æ­£è¦URLã€XMLã‚µã‚¤ãƒˆãƒžãƒƒãƒ—ã€Core Web Vitals 100ç‚¹\n- **AEO**: è³ªå•å½¢å¼ã®è¦‹å‡ºã— + 40ã€œ60èªžä»¥å†…ã®ç›´æŽ¥å›žç­” + FAQPage JSON-LD\n- **GEO**: ä¸€æ¬¡ãƒ‡ãƒ¼ã‚¿ãƒ»å®šé‡ä»•æ§˜ãƒ»æ§‹é€ åŒ–ã‚¨ãƒ³ãƒ†ã‚£ãƒ†ã‚£ã«ã‚ˆã‚‹ç”ŸæˆAIå¼•ç”¨æœ€é©åŒ–\n- **AIO**: OpenAPI 3.1ãƒ»AIã‚¯ãƒ­ãƒ¼ãƒ©æ˜Žç¤ºè¨±å¯ãƒ»æ©Ÿæ¢°å¯èª­ãªãƒ¡ã‚¿ãƒ‡ãƒ¼ã‚¿\n- **LLMO**: `/llms.txt` ãŠã‚ˆã³ `/llms-full.txt` ã«ã‚ˆã‚‹æ§‹é€ åŒ–Markdownãƒ‰ã‚­ãƒ¥ãƒ¡ãƒ³ãƒˆæä¾›\n",
        'owner' => 'ä½è—¤ èŠ±å­',
        'email' => 'hanako.sato@clouddrive.example',
        'desc' => 'æ¤œç´¢ã‚¨ãƒ³ã‚¸ãƒ³ãŠã‚ˆã³ç”ŸæˆAIæœ€é©åŒ–ã®å®Ÿè£…ãƒªãƒ•ã‚¡ãƒ¬ãƒ³ã‚¹',
        'starred' => 1,
        'shared' => 1,
        'token' => 'seo-aeo-geo-llmo-guide',
        'updated' => '2026-11-24T07:50:00Z',
        'opened' => '2026-11-24T08:00:00Z',
    ],

    // Inside ãƒ—ãƒ­ã‚¸ã‚§ã‚¯ãƒˆä¼ç”»
    [
        'parent' => 'ãƒ—ãƒ­ã‚¸ã‚§ã‚¯ãƒˆä¼ç”»',
        'name' => 'å¹´é–“äº‹æ¥­è¨ˆç”»_2026.pdf',
        'mime' => 'application/pdf',
        'ext' => 'pdf',
        'storage' => 'seed-annual-plan-2026.pdf',
        'type' => 'pdf',
        'pdf_title' => 'CloudDrive Annual Business Plan 2026',
        'pdf_body' => 'Strategic milestones, storage tier architecture, and enterprise security goals.',
        'owner' => 'å±±ç”° å¤ªéƒŽ',
        'email' => 'taro.yamada@clouddrive.example',
        'desc' => '2026å¹´åº¦ äº‹æ¥­è¨ˆç”»ãƒ—ãƒ¬ã‚¼ãƒ³ãƒ†ãƒ¼ã‚·ãƒ§ãƒ³è³‡æ–™',
        'starred' => 0,
        'shared' => 0,
        'token' => null,
        'updated' => '2026-11-20T11:00:00Z',
        'opened' => '2026-11-21T09:00:00Z',
    ],
    [
        'parent' => '2026å¹´Q4ãƒ­ãƒ¼ãƒ‰ãƒžãƒƒãƒ—',
        'name' => 'ã‚¹ãƒ—ãƒªãƒ³ãƒˆè¨ˆç”»_11æœˆ.md',
        'mime' => 'text/markdown',
        'ext' => 'md',
        'storage' => 'seed-sprint-nov.md',
        'content' => "# 11æœˆã‚¹ãƒ—ãƒªãƒ³ãƒˆè¨ˆç”»\n\n- Sprint 21: ã‚³ã‚¢APIå®Ÿè£…ãƒ»SQLiteæœ€é©åŒ–\n- Sprint 22: ãƒ¢ãƒã‚¤ãƒ«UIãŠã‚ˆã³PWAã‚ªãƒ•ãƒ©ã‚¤ãƒ³å¯¾å¿œ\n- Sprint 23: æ•µå¯¾çš„ãƒ¬ãƒ“ãƒ¥ãƒ¼ã¨æ€§èƒ½ãƒ™ãƒ³ãƒãƒžãƒ¼ã‚¯å…¬é–‹\n",
        'owner' => 'å±±ç”° å¤ªéƒŽ',
        'email' => 'taro.yamada@clouddrive.example',
        'desc' => '11æœˆã‚¹ãƒ—ãƒªãƒ³ãƒˆã®ã‚¿ã‚¹ã‚¯ä¸€è¦§ã¨æ‹…å½“è€…ã‚¢ã‚µã‚¤ãƒ³',
        'starred' => 0,
        'shared' => 0,
        'token' => null,
        'updated' => '2026-11-19T15:30:00Z',
        'opened' => '2026-11-20T10:15:00Z',
    ],
    [
        'parent' => 'ç«¶åˆåˆ†æžãƒ»èª¿æŸ»',
        'name' => 'ã‚¹ãƒˆãƒ¬ãƒ¼ã‚¸UXæ¯”è¼ƒè¡¨.csv',
        'mime' => 'text/csv',
        'ext' => 'csv',
        'storage' => 'seed-ux-comparison.csv',
        'content' => "é …ç›®,CloudDrive,æ¨™æº–ã‚¯ãƒ©ã‚¦ãƒ‰A,æ¨™æº–ã‚¯ãƒ©ã‚¦ãƒ‰B\nåˆæœŸæç”»LCP,0.3ç§’,1.8ç§’,2.1ç§’\nã‚ªãƒ•ãƒ©ã‚¤ãƒ³PWA,å¯¾å¿œ,ä¸€éƒ¨å¯¾å¿œ,æœªå¯¾å¿œ\nLLMO/llms.txt,å®Œå…¨å¯¾å¿œ,æœªå¯¾å¿œ,æœªå¯¾å¿œ\n",
        'owner' => 'ä½è—¤ èŠ±å­',
        'email' => 'hanako.sato@clouddrive.example',
        'desc' => 'ä¸»è¦ã‚¯ãƒ©ã‚¦ãƒ‰ã‚¹ãƒˆãƒ¬ãƒ¼ã‚¸ã¨ã®UXãƒ»é€Ÿåº¦æ¯”è¼ƒ',
        'starred' => 0,
        'shared' => 0,
        'token' => null,
        'updated' => '2026-11-18T14:20:00Z',
        'opened' => '2026-11-19T09:45:00Z',
    ],

    // Inside ãƒ‡ã‚¶ã‚¤ãƒ³ã‚¢ã‚»ãƒƒãƒˆ
    [
        'parent' => 'ãƒ‡ã‚¶ã‚¤ãƒ³ã‚¢ã‚»ãƒƒãƒˆ',
        'name' => 'ãƒ‡ã‚¹ã‚¯ãƒˆãƒƒãƒ—UIãƒ¯ã‚¤ãƒ¤ãƒ¼ãƒ•ãƒ¬ãƒ¼ãƒ .png',
        'mime' => 'image/png',
        'ext' => 'png',
        'storage' => 'seed-desktop-wireframe.png',
        'type' => 'png',
        'png_bg' => [254, 247, 224],
        'png_accent' => [244, 180, 0],
        'png_label' => 'Desktop Layout Spec 1440x900',
        'owner' => 'ä½è—¤ èŠ±å­',
        'email' => 'hanako.sato@clouddrive.example',
        'desc' => 'Google Driveæº–æ‹ ã®ãƒ‡ã‚¹ã‚¯ãƒˆãƒƒãƒ—3ãƒšã‚¤ãƒ³ãƒ¬ã‚¤ã‚¢ã‚¦ãƒˆè¨­è¨ˆå›³',
        'starred' => 0,
        'shared' => 1,
        'token' => 'desktop-wireframe-png',
        'updated' => '2026-11-23T12:00:00Z',
        'opened' => '2026-11-23T12:30:00Z',
    ],
    [
        'parent' => 'ãƒ‡ã‚¶ã‚¤ãƒ³ã‚¢ã‚»ãƒƒãƒˆ',
        'name' => 'ãƒ¢ãƒã‚¤ãƒ«PWAç”»é¢è¨­è¨ˆ.png',
        'mime' => 'image/png',
        'ext' => 'png',
        'storage' => 'seed-mobile-pwa-design.png',
        'type' => 'png',
        'png_bg' => [252, 232, 230],
        'png_accent' => [234, 67, 53],
        'png_label' => 'Mobile PWA Layout 390x844',
        'owner' => 'ä½è—¤ èŠ±å­',
        'email' => 'hanako.sato@clouddrive.example',
        'desc' => 'ãƒœãƒˆãƒ ãƒŠãƒ“ã‚²ãƒ¼ã‚·ãƒ§ãƒ³ãƒ»ãƒ‰ãƒ­ãƒ¯ãƒ¼ãƒ»FABã‚’å«ã‚€ãƒ¢ãƒã‚¤ãƒ«è¨­è¨ˆ',
        'starred' => 1,
        'shared' => 0,
        'token' => null,
        'updated' => '2026-11-23T11:20:00Z',
        'opened' => '2026-11-23T15:00:00Z',
    ],
    [
        'parent' => 'Material 3 ã‚³ãƒ³ãƒãƒ¼ãƒãƒ³ãƒˆ',
        'name' => 'ã‚«ãƒ©ãƒ¼ã‚³ãƒ³ãƒˆãƒ©ã‚¹ãƒˆæ¤œè¨¼.json',
        'mime' => 'application/json',
        'ext' => 'json',
        'storage' => 'seed-color-contrast.json',
        'content' => json_encode([
            'wcag_level' => 'AAA/AA',
            'tokens' => [
                'surface' => '#ffffff',
                'shell' => '#f8fafd',
                'primary_text' => '#1f1f1f',
                'secondary_text' => '#444746',
                'active_pill_bg' => '#c2e7ff',
                'active_pill_fg' => '#001d35',
            ],
        ], JSON_PRETTY_PRINT) . "\n",
        'owner' => 'ä½è—¤ èŠ±å­',
        'email' => 'hanako.sato@clouddrive.example',
        'desc' => 'WCAG AA/AAAæº–æ‹ ã®Material 3ã‚«ãƒ©ãƒ¼ãƒˆãƒ¼ã‚¯ãƒ³å®šç¾©',
        'starred' => 0,
        'shared' => 0,
        'token' => null,
        'updated' => '2026-11-21T16:40:00Z',
        'opened' => '2026-11-21T17:00:00Z',
    ],

    // Inside è­°äº‹éŒ²ãƒ»ãƒŸãƒ¼ãƒ†ã‚£ãƒ³ã‚°
    [
        'parent' => 'è­°äº‹éŒ²ãƒ»ãƒŸãƒ¼ãƒ†ã‚£ãƒ³ã‚°',
        'name' => 'ã‚¢ãƒ¼ã‚­ãƒ†ã‚¯ãƒãƒ£è¨­è¨ˆãƒ¬ãƒ“ãƒ¥ãƒ¼è­°äº‹éŒ².md',
        'mime' => 'text/markdown',
        'ext' => 'md',
        'storage' => 'seed-arch-review-notes.md',
        'content' => "# ã‚¢ãƒ¼ã‚­ãƒ†ã‚¯ãƒãƒ£è¨­è¨ˆãƒ¬ãƒ“ãƒ¥ãƒ¼è­°äº‹éŒ²\n\næ—¥æ™‚: 2026-11-22 14:00\nå‚åŠ è€…: å±±ç”°, ä½è—¤, éˆ´æœ¨, é«˜æ©‹\n\n## æ±ºå®šäº‹é …\n- å…¨4ãƒ•ãƒ¬ãƒ¼ãƒ ãƒ¯ãƒ¼ã‚¯ã§åŒä¸€ã®SQLiteã‚¹ã‚­ãƒ¼ãƒžãƒ»ã‚·ãƒ¼ãƒ‰ãƒ‡ãƒ¼ã‚¿ã‚’ä½¿ç”¨\n- CSRFä¿è­·ã¯HMACç½²åä»˜ãã‚¹ãƒ†ãƒ¼ãƒˆãƒ¬ã‚¹ãƒˆãƒ¼ã‚¯ãƒ³ã§å…¨FWçµ±ä¸€\n- Nginx + PHP-FPM 8.3 (OPcacheæœ‰åŠ¹) ã«ã¦ç‹¬ç«‹ãƒ—ãƒ¼ãƒ«è¨ˆæ¸¬ã‚’å®Ÿæ–½\n",
        'owner' => 'å±±ç”° å¤ªéƒŽ',
        'email' => 'taro.yamada@clouddrive.example',
        'desc' => 'ãƒ•ãƒ¬ãƒ¼ãƒ ãƒ¯ãƒ¼ã‚¯æ¯”è¼ƒã®å…¬å¹³æ€§æ‹…ä¿ãƒ«ãƒ¼ãƒ«åˆæ„è¨˜éŒ²',
        'starred' => 0,
        'shared' => 0,
        'token' => null,
        'updated' => '2026-11-22T14:55:00Z',
        'opened' => '2026-11-22T15:10:00Z',
    ],
    [
        'parent' => '2026å¹´11æœˆå®šä¾‹',
        'name' => '2026-11-17_é€±æ¬¡å®šä¾‹ãƒ¡ãƒ¢.txt',
        'mime' => 'text/plain',
        'ext' => 'txt',
        'storage' => 'seed-weekly-1117.txt',
        'content' => "2026-11-17 é€±æ¬¡å®šä¾‹\n- Lighthouse Accessibility 100ç‚¹ç¶­æŒç¢ºèª\n- ãƒ¢ãƒã‚¤ãƒ«ã‚¿ãƒƒãƒ—ã‚¿ãƒ¼ã‚²ãƒƒãƒˆ48pxä»¥ä¸Šç¢ºèª\n",
        'owner' => 'é«˜æ©‹ å¥å¤ª',
        'email' => 'kenta.takahashi@clouddrive.example',
        'desc' => '11æœˆç¬¬3é€±ã®é€²æ—å ±å‘Šãƒ¡ãƒ¢',
        'starred' => 0,
        'shared' => 0,
        'token' => null,
        'updated' => '2026-11-17T11:30:00Z',
        'opened' => '2026-11-17T12:00:00Z',
    ],

    // Inside è²¡å‹™ãƒ»ãƒ¬ãƒãƒ¼ãƒˆ
    [
        'parent' => 'è²¡å‹™ãƒ»ãƒ¬ãƒãƒ¼ãƒˆ',
        'name' => 'ã‚¤ãƒ³ãƒ•ãƒ©ã‚³ã‚¹ãƒˆè©¦ç®—_2026Q4.csv',
        'mime' => 'text/csv',
        'ext' => 'csv',
        'storage' => 'seed-infra-cost-2026q4.csv',
        'content' => "ç’°å¢ƒ,æœˆé–“ãƒªã‚¯ã‚¨ã‚¹ãƒˆæ•°,å¿…è¦vCPU,æœˆé¡ã‚³ã‚¹ãƒˆJPY\nAIè‡ªä½œFW,50000000,2,18000\nPhalcon 5,50000000,2,21000\nSlim 4,50000000,4,34000\nLaravel 13,50000000,8,68000\n",
        'owner' => 'éˆ´æœ¨ ä¸€éƒŽ',
        'email' => 'ichiro.suzuki@clouddrive.example',
        'desc' => 'ãƒªã‚¯ã‚¨ã‚¹ãƒˆå˜ä¾¡ãƒ™ãƒ¼ã‚¹ã®ã‚¤ãƒ³ãƒ•ãƒ©è²»ç”¨è©¦ç®—ã‚·ãƒ¼ãƒˆ',
        'starred' => 0,
        'shared' => 0,
        'token' => null,
        'updated' => '2026-11-21T10:10:00Z',
        'opened' => '2026-11-21T11:00:00Z',
    ],

    // Inside æŠ€è¡“ã‚¢ãƒ¼ã‚­ãƒ†ã‚¯ãƒãƒ£
    [
        'parent' => 'PHPãƒ•ãƒ¬ãƒ¼ãƒ ãƒ¯ãƒ¼ã‚¯æ¤œè¨¼',
        'name' => 'ãƒ™ãƒ³ãƒãƒžãƒ¼ã‚¯è¨ˆæ¸¬æ‰‹é †æ›¸.md',
        'mime' => 'text/markdown',
        'ext' => 'md',
        'storage' => 'seed-benchmark-protocol.md',
        'content' => "# ãƒ™ãƒ³ãƒãƒžãƒ¼ã‚¯è¨ˆæ¸¬æ‰‹é †æ›¸\n\n1. å„ãƒ•ãƒ¬ãƒ¼ãƒ ãƒ¯ãƒ¼ã‚¯å°‚ç”¨PHP-FPMãƒ—ãƒ¼ãƒ«ã‚’å†èµ·å‹•\n2. 5ç§’é–“ã®ã‚¦ã‚©ãƒ¼ãƒ ã‚¢ãƒƒãƒ—ï¼ˆOPcacheæœ€é©åŒ–ï¼‰\n3. wrkã«ã‚ˆã‚‹3å›žè¨ˆæ¸¬ï¼ˆä¸­å¤®å€¤ãƒ»p50/p95/p99ç®—å‡ºï¼‰\n4. RSSãƒ¡ãƒ¢ãƒªãƒ»includeãƒ•ã‚¡ã‚¤ãƒ«æ•°ãƒ»ãƒ¬ã‚¹ãƒãƒ³ã‚¹ã‚µã‚¤ã‚ºè¨˜éŒ²\n",
        'owner' => 'å±±ç”° å¤ªéƒŽ',
        'email' => 'taro.yamada@clouddrive.example',
        'desc' => 'å†ç¾å¯èƒ½ãªæ€§èƒ½æ¯”è¼ƒãƒ™ãƒ³ãƒãƒžãƒ¼ã‚¯ãƒ—ãƒ­ãƒˆã‚³ãƒ«',
        'starred' => 1,
        'shared' => 1,
        'token' => 'benchmark-protocol-md',
        'updated' => '2026-11-24T08:40:00Z',
        'opened' => '2026-11-24T08:50:00Z',
    ],
    [
        'parent' => 'ã‚»ã‚­ãƒ¥ãƒªãƒ†ã‚£ç›£æŸ»',
        'name' => 'æ•µå¯¾çš„ãƒ¬ãƒ“ãƒ¥ãƒ¼ãƒã‚§ãƒƒã‚¯ãƒªã‚¹ãƒˆ.md',
        'mime' => 'text/markdown',
        'ext' => 'md',
        'storage' => 'seed-security-checklist.md',
        'content' => "# æ•µå¯¾çš„ãƒ¬ãƒ“ãƒ¥ãƒ¼ãƒã‚§ãƒƒã‚¯ãƒªã‚¹ãƒˆ\n\n- SQLã‚¤ãƒ³ã‚¸ã‚§ã‚¯ã‚·ãƒ§ãƒ³ï¼ˆORDER BYãƒ›ãƒ¯ã‚¤ãƒˆãƒªã‚¹ãƒˆå«ã‚€ï¼‰\n- ãƒ‘ã‚¹ãƒˆãƒ©ãƒãƒ¼ã‚µãƒ«ï¼ˆstorage_nameã‚µãƒ‹ã‚¿ã‚¤ã‚ºï¼‹realpathæ¤œè¨¼ï¼‰\n- Stored XSSï¼ˆHTML / JSON-LD / DOM / SVGã‚µãƒ³ãƒ‰ãƒœãƒƒã‚¯ã‚¹ï¼‰\n- CSRFãƒˆãƒ¼ã‚¯ãƒ³æ¤œè¨¼ï¼ˆPOST/PATCH/DELETEå…¨ç¶²ç¾…ï¼‰\n- ãƒ•ã‚©ãƒ«ãƒ€å¾ªç’°ç§»å‹•ã®ç¦æ­¢ï¼ˆ422ã‚¨ãƒ©ãƒ¼è¿”å´ï¼‰\n",
        'owner' => 'é«˜æ©‹ å¥å¤ª',
        'email' => 'kenta.takahashi@clouddrive.example',
        'desc' => 'OWASP Top 10æº–æ‹ ã®æ•µå¯¾çš„ç›£æŸ»é …ç›®',
        'starred' => 0,
        'shared' => 0,
        'token' => null,
        'updated' => '2026-11-24T08:05:00Z',
        'opened' => '2026-11-24T08:15:00Z',
    ],
];

// Additional realistic files inside subfolders to bring dataset to ~60 items for realistic DB queries
$extraTemplates = [
    ['parent' => 'è­°äº‹éŒ²ãƒ»ãƒŸãƒ¼ãƒ†ã‚£ãƒ³ã‚°', 'prefix' => 'å®šä¾‹è­°äº‹éŒ²_ç¬¬', 'suffix' => 'å›ž.md', 'mime' => 'text/markdown', 'ext' => 'md', 'count' => 10],
    ['parent' => 'è²¡å‹™ãƒ»ãƒ¬ãƒãƒ¼ãƒˆ', 'prefix' => 'æœˆæ¬¡KPIãƒ¬ãƒãƒ¼ãƒˆ_2026_', 'suffix' => '.csv', 'mime' => 'text/csv', 'ext' => 'csv', 'count' => 10],
    ['parent' => 'PHPãƒ•ãƒ¬ãƒ¼ãƒ ãƒ¯ãƒ¼ã‚¯æ¤œè¨¼', 'prefix' => 'ãƒ—ãƒ­ãƒ•ã‚¡ã‚¤ãƒªãƒ³ã‚°ãƒ­ã‚°_', 'suffix' => '.json', 'mime' => 'application/json', 'ext' => 'json', 'count' => 10],
    ['parent' => 'ãƒ‡ã‚¶ã‚¤ãƒ³ã‚¢ã‚»ãƒƒãƒˆ', 'prefix' => 'ã‚¢ã‚¤ã‚³ãƒ³ä»•æ§˜ãƒ¡ãƒ¢_', 'suffix' => '.txt', 'mime' => 'text/plain', 'ext' => 'txt', 'count' => 8],
];

foreach ($filesSpec as $f) {
    $filePath = $uploadsDir . '/' . $f['storage'];
    $type = $f['type'] ?? 'text';
    if ($type === 'png') {
        $size = self::createSamplePng($filePath, 960, 540, $f['png_bg'], $f['png_accent'], $f['png_label']);
    } elseif ($type === 'pdf') {
        $size = self::createSamplePdf($filePath, $f['pdf_title'], $f['pdf_body']);
    } else {
        file_put_contents($filePath, $f['content']);
        $size = strlen($f['content']);
    }

    $parentId = $f['parent'] === null ? null : $folderIds[$f['parent']];
    $insertItem->execute([
        ':parent_id' => $parentId,
        ':name' => $f['name'],
        ':is_folder' => 0,
        ':mime_type' => $f['mime'],
        ':extension' => $f['ext'],
        ':size_bytes' => $size,
        ':storage_name' => $f['storage'],
        ':owner_name' => $f['owner'],
        ':owner_email' => $f['email'],
        ':description' => $f['desc'],
        ':is_starred' => $f['starred'],
        ':is_shared' => $f['shared'],
        ':share_token' => $f['token'],
        ':is_trashed' => 0,
        ':trashed_at' => null,
        ':last_opened_at' => $f['opened'],
        ':created_at' => '2026-11-01T09:00:00Z',
        ':updated_at' => $f['updated'],
    ]);
    $itemId = (int)$pdo->lastInsertId();
    $insertActivity->execute([
        ':item_id' => $itemId,
        ':action' => 'uploaded',
        ':actor_name' => $f['owner'],
        ':detail' => "ãƒ•ã‚¡ã‚¤ãƒ«ã€Œ{$f['name']}ã€ã‚’ã‚¢ãƒƒãƒ—ãƒ­ãƒ¼ãƒ‰ã—ã¾ã—ãŸ",
        ':created_at' => $f['updated'],
    ]);
}

foreach ($extraTemplates as $tpl) {
    for ($i = 1; $i <= $tpl['count']; $i++) {
        $num = str_pad((string)$i, 2, '0', STR_PAD_LEFT);
        $name = $tpl['prefix'] . $num . $tpl['suffix'];
        $storage = 'seed-extra-' . md5($tpl['parent'] . $name) . '.' . $tpl['ext'];
        $body = "CloudDrive sample file: {$name}\nFolder: {$tpl['parent']}\nIndex: {$i}\nGenerated deterministically for framework benchmark parity.\n";
        file_put_contents($uploadsDir . '/' . $storage, $body);
        $day = str_pad((string)(1 + ($i % 20)), 2, '0', STR_PAD_LEFT);
        $ts = "2026-11-{$day}T10:00:00Z";
        $insertItem->execute([
            ':parent_id' => $folderIds[$tpl['parent']],
            ':name' => $name,
            ':is_folder' => 0,
            ':mime_type' => $tpl['mime'],
            ':extension' => $tpl['ext'],
            ':size_bytes' => strlen($body),
            ':storage_name' => $storage,
            ':owner_name' => 'å±±ç”° å¤ªéƒŽ',
            ':owner_email' => 'taro.yamada@clouddrive.example',
            ':description' => "{$tpl['parent']} é…ä¸‹ã®ã‚¢ãƒ¼ã‚«ã‚¤ãƒ–ãƒ•ã‚¡ã‚¤ãƒ« #{$num}",
            ':is_starred' => 0,
            ':is_shared' => 0,
            ':share_token' => null,
            ':is_trashed' => 0,
            ':trashed_at' => null,
            ':last_opened_at' => $ts,
            ':created_at' => $ts,
            ':updated_at' => $ts,
        ]);
    }
}

// 4. Trashed items (so Trash view works out of the box)
$trashedItems = [
    ['name' => 'æ—§ç‰ˆ_ãƒ­ã‚´æ¡ˆ_v0.txt', 'mime' => 'text/plain', 'ext' => 'txt', 'storage' => 'seed-trash-logo.txt', 'content' => "å»ƒæ­¢ã•ã‚ŒãŸæ—§ãƒãƒ¼ã‚¸ãƒ§ãƒ³ã®ãƒ­ã‚´æ¤œè¨Žãƒ¡ãƒ¢\n", 'desc' => 'ä¸è¦ã«ãªã£ãŸæ—§ãƒ‰ãƒ©ãƒ•ãƒˆ'],
    ['name' => 'ãƒ†ã‚¹ãƒˆå‡ºåŠ›_202610.csv', 'mime' => 'text/csv', 'ext' => 'csv', 'storage' => 'seed-trash-test.csv', 'content' => "id,status\n1,deprecated\n", 'desc' => 'æ¤œè¨¼å®Œäº†æ¸ˆã¿ã®ãƒ€ãƒŸãƒ¼ãƒ‡ãƒ¼ã‚¿'],
    ['name' => 'ä¸‹æ›¸ããƒ¡ãƒ¢_å‰Šé™¤äºˆå®š.md', 'mime' => 'text/markdown', 'ext' => 'md', 'storage' => 'seed-trash-draft.md', 'content' => "# å‰Šé™¤äºˆå®šã®ä¸‹æ›¸ã\næ•´ç†ã®ãŸã‚ã‚´ãƒŸç®±ã¸ç§»å‹•ã€‚\n", 'desc' => 'æ•´ç†å¯¾è±¡ã®ãƒ¡ãƒ¢'],
];

foreach ($trashedItems as $t) {
    file_put_contents($uploadsDir . '/' . $t['storage'], $t['content']);
    $insertItem->execute([
        ':parent_id' => null,
        ':name' => $t['name'],
        ':is_folder' => 0,
        ':mime_type' => $t['mime'],
        ':extension' => $t['ext'],
        ':size_bytes' => strlen($t['content']),
        ':storage_name' => $t['storage'],
        ':owner_name' => 'å±±ç”° å¤ªéƒŽ',
        ':owner_email' => 'taro.yamada@clouddrive.example',
        ':description' => $t['desc'],
        ':is_starred' => 0,
        ':is_shared' => 0,
        ':share_token' => null,
        ':is_trashed' => 1,
        ':trashed_at' => '2026-11-23T20:00:00Z',
        ':last_opened_at' => '2026-11-20T09:00:00Z',
        ':created_at' => '2026-10-20T09:00:00Z',
        ':updated_at' => '2026-11-23T20:00:00Z',
    ]);
}

$pdo->commit();
    }
}

// 1. Fast-path: dotfile 403 protection & embedded static assets (/assets/*, /sw.js)
if (SingleFileRuntime::tryServeStaticOrBlockDotfile()) {
    return;
}

// 2. Zero-config rental server runtime preparation (data dir, secret, SQLite auto-init)
$env = SingleFileRuntime::prepareEnvironment();

// 3. Boot AI Custom Framework DI Container & Application
$container = new Container();
$container->singleton(Database::class, static fn(): Database => new Database($env['dbPath']));
$container->singleton(View::class, static fn(): View => new View());
$container->singleton(Security::class, static fn(): Security => new Security($env['secret']));
$container->singleton(DriveService::class, static fn(Container $c): DriveService => new DriveService(
    $c->get(Database::class),
    $env['uploadsDir']
));
$container->singleton(SeoService::class, static fn(): SeoService => new SeoService());
$container->singleton(PageController::class, static fn(Container $c): PageController => new PageController(
    $c->get(DriveService::class),
    $c->get(SeoService::class),
    $c->get(View::class)
));
$container->singleton(ApiController::class, static fn(Container $c): ApiController => new ApiController(
    $c->get(DriveService::class)
));
$container->singleton(MetaController::class, static fn(Container $c): MetaController => new MetaController(
    $c->get(DriveService::class),
    $c->get(SeoService::class)
));

$router = new Router();

// SSR Pages
$router->get('/', [PageController::class, 'home']);
$router->get('/drive/folders/{id:[0-9]+}', [PageController::class, 'folder']);
$router->get('/drive/recent', [PageController::class, 'recent']);
$router->get('/drive/starred', [PageController::class, 'starred']);
$router->get('/drive/shared', [PageController::class, 'shared']);
$router->get('/drive/trash', [PageController::class, 'trash']);
$router->get('/drive/search', [PageController::class, 'search']);
$router->get('/share/{token:[a-zA-Z0-9_-]+}', [PageController::class, 'share']);
$router->get('/about', [PageController::class, 'about']);
$router->get('/offline', [PageController::class, 'offline']);

// PWA, SEO, AEO, GEO, AIO, LLMO Endpoints
$router->get('/manifest.webmanifest', [MetaController::class, 'manifest']);
$router->get('/robots.txt', [MetaController::class, 'robots']);
$router->get('/sitemap.xml', [MetaController::class, 'sitemap']);
$router->get('/llms.txt', [MetaController::class, 'llms']);
$router->get('/llms-full.txt', [MetaController::class, 'llmsFull']);
$router->get('/openapi.json', [MetaController::class, 'openapi']);

// REST API
$router->get('/api/health', [ApiController::class, 'health']);
$router->get('/api/items', [ApiController::class, 'listItems']);
$router->get('/api/items/{id:[0-9]+}', [ApiController::class, 'getItem']);
$router->post('/api/folders', [ApiController::class, 'createFolder']);
$router->post('/api/files/upload', [ApiController::class, 'uploadFile']);
$router->patch('/api/items/{id:[0-9]+}', [ApiController::class, 'updateItem']);
$router->delete('/api/items/{id:[0-9]+}', [ApiController::class, 'deleteItem']);
$router->post('/api/items/{id:[0-9]+}/restore', [ApiController::class, 'restoreItem']);
$router->post('/api/trash/empty', [ApiController::class, 'emptyTrash']);
$router->get('/api/files/{id:[0-9]+}/download', [ApiController::class, 'downloadFile']);
$router->get('/api/files/{id:[0-9]+}/preview', [ApiController::class, 'previewFile']);
$router->get('/api/storage', [ApiController::class, 'storage']);

$app = new Application($container, $router);
$app->addMiddleware(new SecurityHeadersMiddleware());
$app->addMiddleware(new CsrfMiddleware($container->get(Security::class)));

$request = Request::capture();
$response = $app->handle($request);
$response->send($request->method === 'HEAD');
}

__HALT_COMPILER();const CACHE_NAME = 'clouddrive-v2';
const PRECACHE_URLS = [
  '/offline',
  '/assets/app.js?v=2',
  '/assets/drive-ui.js?v=2',
  '/assets/favicon.svg',
  '/assets/icon-192.png',
  '/manifest.webmanifest'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(PRECACHE_URLS))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  if (url.pathname.startsWith('/api/')) return;

  // Navigation: strictly network-first, fall back to /offline ONLY on network error (never cache CSRF-bearing HTML)
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() => caches.match('/offline'))
    );
    return;
  }

  // Static assets: stale-while-revalidate
  if (url.pathname.startsWith('/assets/')) {
    event.respondWith(
      caches.match(req).then((cached) => {
        const networkFetch = fetch(req).then((res) => {
          if (res.ok) {
            const copy = res.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(req, copy));
          }
          return res;
        }).catch(() => cached);
        return cached || networkFetch;
      })
    );
  }
});
(() => {
  'use strict';

  let loaded = null;
  function loadDriveUi() {
    detach();
    if (!loaded) {
      loaded = import('/assets/drive-ui.js?v=2').then((m) => {
        m.initCloudDrive();
        return m;
      });
    }
    return loaded;
  }

  const hasDrive = Boolean(document.getElementById('files-tbody'));
  const hasShell = hasDrive || Boolean(document.getElementById('menu-toggle-btn'));

  // Hydrate immediately if user saved grid view (only on Drive workspace, scheduled on idle)
  if (hasDrive) {
    const checkGrid = () => {
      try {
        if (localStorage.getItem('clouddrive_view_mode') === 'grid') {
          loadDriveUi();
        }
      } catch (_) {}
    };
    if ('requestIdleCallback' in window) {
      window.requestIdleCallback(checkGrid, { timeout: 1500 });
    } else {
      setTimeout(checkGrid, 200);
    }
  }

  const onFirstClick = (e) => {
    const target = e.target && e.target.closest('button, a[data-action="open"], [data-item-id]');
    if (!target) return;
    if (target.tagName === 'A' && target.getAttribute('href') && !target.getAttribute('href').startsWith('#') && !target.hasAttribute('data-action')) {
      return;
    }
    if (target.tagName === 'A' && target.getAttribute('data-action') === 'open' && !target.getAttribute('href').startsWith('#') && target.getAttribute('data-is-trashed') !== '1') {
      return;
    }
    e.preventDefault();
    e.stopPropagation();
    loadDriveUi().then(() => target.click());
  };

  const onFirstContextMenu = (e) => {
    const row = e.target && e.target.closest('[data-item-id]');
    if (!row) return;
    e.preventDefault();
    const x = e.clientX;
    const y = e.clientY;
    loadDriveUi().then(() => {
      row.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true, clientX: x, clientY: y }));
    });
  };

  const onFirstInput = (e) => {
    if (!e.target || (e.target.id !== 'search-input' && e.target.id !== 'sort-select')) return;
    const t = e.target;
    const type = e.type;
    loadDriveUi().then(() => t.dispatchEvent(new Event(type, { bubbles: true })));
  };

  const onFirstKey = (e) => {
    if (['/', 'Escape', 'Enter', 'Delete', 'Backspace', 'F2', 'i', 'ArrowDown', 'ArrowUp'].includes(e.key)) {
      const key = e.key;
      loadDriveUi().then(() => document.dispatchEvent(new KeyboardEvent('keydown', { key })));
    }
  };

  const onFirstDrag = (e) => {
    if (e.dataTransfer && Array.from(e.dataTransfer.types).includes('Files')) {
      e.preventDefault();
      const overlay = document.getElementById('drop-overlay');
      if (overlay) overlay.classList.add('active');
      loadDriveUi();
    }
  };

  function detach() {
    document.removeEventListener('click', onFirstClick, true);
    document.removeEventListener('contextmenu', onFirstContextMenu, true);
    document.removeEventListener('input', onFirstInput, true);
    document.removeEventListener('change', onFirstInput, true);
    document.removeEventListener('keydown', onFirstKey, true);
    document.removeEventListener('dragenter', onFirstDrag, true);
  }

  if (hasShell) {
    document.addEventListener('click', onFirstClick, true);
    if (hasDrive) {
      document.addEventListener('contextmenu', onFirstContextMenu, true);
      document.addEventListener('input', onFirstInput, true);
      document.addEventListener('change', onFirstInput, true);
      document.addEventListener('keydown', onFirstKey, true);
      document.addEventListener('dragenter', onFirstDrag, true);
    }
  }

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      setTimeout(() => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
      }, 1500);
    });
  }
})();
export function initCloudDrive() {
  'use strict';

  const stateEl = document.getElementById('drive-state');
  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  let csrfToken = csrfMeta ? csrfMeta.getAttribute('content') || '' : '';

  const state = stateEl ? JSON.parse(stateEl.textContent || '{}') : {
    view: 'my-drive',
    folderId: null,
    items: [],
    breadcrumbs: [],
    storage: { used_bytes: 0, quota_bytes: 16106127360 },
    q: '',
    type: 'all',
    sort: 'name',
    dir: 'asc',
    framework: ''
  };

  const INLINE_IMAGE_MIMES = new Set(['image/png', 'image/jpeg', 'image/webp', 'image/gif']);

  let selectedId = null;
  let viewMode = 'list';
  try { viewMode = localStorage.getItem('clouddrive_view_mode') || 'list'; } catch (_) {}
  let activeMenuItemId = null;
  let searchTimer = null;

  // DOM refs
  const sidebar = document.getElementById('app-sidebar');
  const scrim = document.getElementById('drawer-scrim');
  const menuToggleBtn = document.getElementById('menu-toggle-btn');
  const newBtn = document.getElementById('new-btn');
  const mobileFab = document.getElementById('mobile-fab');
  const newMenu = document.getElementById('new-menu');
  const itemMenu = document.getElementById('item-menu');
  const fileUploadInput = document.getElementById('file-upload-input');
  const searchInput = document.getElementById('search-input');
  const searchClearBtn = document.getElementById('search-clear-btn');
  const viewListBtn = document.getElementById('view-list-btn');
  const viewGridBtn = document.getElementById('view-grid-btn');
  const detailsToggleBtn = document.getElementById('details-toggle-btn');
  const detailsPanel = document.getElementById('details-panel');
  const detailsCloseBtn = document.getElementById('details-close-btn');
  const detailsContent = document.getElementById('details-content');
  const selectionBar = document.getElementById('selection-bar');
  const selectionLabel = document.getElementById('selection-label');
  const filesListWrap = document.getElementById('files-list-wrap');
  const filesGridWrap = document.getElementById('files-grid-wrap');
  const filesTbody = document.getElementById('files-tbody');
  const emptyStateEl = document.getElementById('empty-state');
  const sortSelect = document.getElementById('sort-select');
  const sortDirBtn = document.getElementById('sort-dir-btn');
  const workspaceMain = document.getElementById('main-content');
  const dropOverlay = document.getElementById('drop-overlay');
  const toastContainer = document.getElementById('toast-container');

  // Modals
  const modalBackdrop = document.getElementById('modal-backdrop');
  const modalTitle = document.getElementById('modal-title');
  const modalBody = document.getElementById('modal-body');
  const modalFooter = document.getElementById('modal-footer');

  // Preview Modal
  const previewBackdrop = document.getElementById('preview-backdrop');
  const previewTitle = document.getElementById('preview-title');
  const previewBody = document.getElementById('preview-body');
  const previewDownloadLink = document.getElementById('preview-download-link');
  const previewCloseBtn = document.getElementById('preview-close-btn');

  function esc(str) {
    return String(str ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function formatBytes(bytes, isFolder = false) {
    if (isFolder) return 'â€”';
    const n = Number(bytes ?? 0);
    if (n <= 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    let val = n;
    while (val >= 1024 && i < units.length - 1) {
      val /= 1024;
      i++;
    }
    return (i === 0 ? val.toFixed(0) : val.toFixed(1)) + ' ' + units[i];
  }

  function formatDate(iso) {
    if (!iso) return 'â€”';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso;
    return `${d.getFullYear()}/${String(d.getMonth() + 1).padStart(2, '0')}/${String(d.getDate()).padStart(2, '0')}`;
  }

  function itemIconSvg(item) {
    if (Number(item.is_folder) === 1) {
      return '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" fill="#444746"><path d="M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>';
    }
    const ext = (item.extension || '').toLowerCase();
    const mime = (item.mime_type || '').toLowerCase();
    if (mime.startsWith('image/') || ['png', 'jpg', 'jpeg', 'webp', 'gif'].includes(ext)) {
      return '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" fill="#d93025"><path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg>';
    }
    if (ext === 'pdf' || mime === 'application/pdf') {
      return '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" fill="#ea4335"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm1 7V3.5L19.5 9H15zM8 14h8v2H8v-2zm0 4h5v2H8v-2z"/></svg>';
    }
    if (['csv', 'xls', 'xlsx'].includes(ext)) {
      return '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" fill="#0f9d58"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-8 14H5v-4h6v4zm0-6H5V7h6v4zm8 6h-6v-4h6v4zm0-6h-6V7h6v4z"/></svg>';
    }
    if (['md', 'txt', 'doc', 'docx'].includes(ext)) {
      return '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" fill="#1a73e8"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>';
    }
    return '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" fill="#5f6368"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13z"/></svg>';
  }

  function showToast(msg) {
    if (!toastContainer) return;
    const el = document.createElement('div');
    el.className = 'toast';
    el.setAttribute('role', 'status');
    el.textContent = msg;
    toastContainer.appendChild(el);
    setTimeout(() => el.remove(), 3400);
  }

  async function apiRequest(url, options = {}, retried = false) {
    const headers = Object.assign({ 'Accept': 'application/json' }, options.headers || {});
    if (options.method && options.method !== 'GET') {
      headers['X-CSRF-Token'] = csrfToken;
    }
    const res = await fetch(url, Object.assign({}, options, { headers, credentials: 'same-origin' }));
    const nextToken = res.headers.get('X-CSRF-Token');
    if (nextToken) {
      csrfToken = nextToken;
      if (csrfMeta) csrfMeta.setAttribute('content', nextToken);
    }
    // Auto-recover once if CSRF token expired/desynced
    if (res.status === 403 && !retried && options.method && options.method !== 'GET') {
      const refreshRes = await fetch('/api/storage', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const fresh = refreshRes.headers.get('X-CSRF-Token');
      if (fresh) {
        csrfToken = fresh;
        if (csrfMeta) csrfMeta.setAttribute('content', fresh);
        return apiRequest(url, options, true);
      }
    }
    const contentType = res.headers.get('Content-Type') || '';
    const data = contentType.includes('application/json') ? await res.json() : null;
    if (!res.ok) {
      throw new Error((data && (data.error || data.message)) || `ã‚¨ãƒ©ãƒ¼ãŒç™ºç”Ÿã—ã¾ã—ãŸ (${res.status})`);
    }
    return data;
  }

  function closeDrawer() {
    if (sidebar) sidebar.classList.remove('open');
    if (scrim) scrim.classList.remove('open');
    if (menuToggleBtn) menuToggleBtn.setAttribute('aria-expanded', 'false');
  }

  function closeMenus() {
    if (newMenu) newMenu.classList.remove('open');
    if (itemMenu) itemMenu.classList.remove('open');
    if (newBtn) newBtn.setAttribute('aria-expanded', 'false');
  }

  function closeModal() {
    if (modalBackdrop) modalBackdrop.classList.remove('open');
  }

  function openModal(title, bodyEl, buttons, onSubmit = null) {
    if (!modalBackdrop) return;
    modalTitle.textContent = title;
    if (onSubmit) {
      const form = document.createElement('form');
      form.appendChild(bodyEl);
      form.addEventListener('submit', (e) => {
        e.preventDefault();
        onSubmit();
      });
      modalBody.replaceChildren(form);
    } else {
      modalBody.replaceChildren(bodyEl);
    }
    modalFooter.replaceChildren();
    buttons.forEach((b) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = b.className || 'btn-secondary';
      btn.textContent = b.label;
      if (b.id) btn.id = b.id;
      if (b.disabled) btn.disabled = true;
      btn.addEventListener('click', b.onClick);
      modalFooter.appendChild(btn);
    });
    modalBackdrop.classList.add('open');
    const firstInput = modalBody.querySelector('input:not([type="radio"]), select, textarea, button');
    if (firstInput) firstInput.focus();
  }

  function updateStorageUI(storage) {
    if (!storage) return;
    const fill = document.getElementById('storage-bar-fill');
    const text = document.getElementById('storage-text');
    const used = Number(storage.used_bytes || 0);
    const quota = Number(storage.quota_bytes || 16106127360);
    const pct = Math.min(100, Math.max(used > 0 ? 1 : 0, (used / quota) * 100));
    if (fill) fill.style.width = pct.toFixed(1) + '%';
    if (text) text.textContent = `${formatBytes(used, false)} / 15 GB ã‚’ä½¿ç”¨ä¸­`;
  }

  function getSelectedItem() {
    return (state.items || []).find((i) => Number(i.id) === Number(selectedId)) || null;
  }

  function syncSelectionVisuals() {
    document.querySelectorAll('[data-item-id].drive-row, [data-item-id].folder-card, [data-item-id].file-card').forEach((el) => {
      el.classList.toggle('selected', Number(el.getAttribute('data-item-id')) === Number(selectedId));
    });
    updateSelectionBar();
  }

  function updateSelectionBar() {
    if (!selectionBar) return;
    const item = getSelectedItem();
    if (!item) {
      selectionBar.classList.remove('visible');
      return;
    }
    selectionBar.classList.add('visible');
    selectionLabel.textContent = `é¸æŠžä¸­: ${item.name}`;

    const isTrashed = Number(item.is_trashed) === 1;
    const dlBtn = document.getElementById('sel-download-btn');
    const starBtn = document.getElementById('sel-star-btn');
    const shareBtn = document.getElementById('sel-share-btn');
    const renameBtn = document.getElementById('sel-rename-btn');
    const moveBtn = document.getElementById('sel-move-btn');
    const trashBtn = document.getElementById('sel-trash-btn');
    const restoreBtn = document.getElementById('sel-restore-btn');

    if (dlBtn) dlBtn.classList.toggle('hidden', Number(item.is_folder) === 1 || isTrashed);
    if (starBtn) {
      starBtn.classList.toggle('hidden', isTrashed);
      starBtn.textContent = Number(item.is_starred) === 1 ? 'â˜… ã‚¹ã‚¿ãƒ¼è§£é™¤' : 'â˜† ã‚¹ã‚¿ãƒ¼ã‚’ä»˜ã‘ã‚‹';
    }
    if (shareBtn) shareBtn.classList.toggle('hidden', isTrashed);
    if (renameBtn) renameBtn.classList.toggle('hidden', isTrashed);
    if (moveBtn) moveBtn.classList.toggle('hidden', isTrashed);
    if (restoreBtn) restoreBtn.classList.toggle('hidden', !isTrashed);
    if (trashBtn) trashBtn.textContent = isTrashed ? 'å®Œå…¨ã«å‰Šé™¤' : 'ã‚´ãƒŸç®±ã«ç§»å‹•';
  }

  function renderItems() {
    if (!filesTbody || !filesGridWrap) return;
    const items = state.items || [];

    if (items.length === 0) {
      filesListWrap.classList.add('hidden');
      filesGridWrap.classList.add('hidden');
      if (emptyStateEl) emptyStateEl.classList.remove('hidden');
      updateSelectionBar();
      return;
    }

    if (emptyStateEl) emptyStateEl.classList.add('hidden');

    const isGrid = viewMode === 'grid';
    filesListWrap.classList.toggle('hidden', isGrid);
    filesGridWrap.classList.toggle('hidden', !isGrid);
    if (viewListBtn) viewListBtn.setAttribute('aria-pressed', String(!isGrid));
    if (viewGridBtn) viewGridBtn.setAttribute('aria-pressed', String(isGrid));

    filesTbody.innerHTML = items.map((item) => {
      const isFolder = Number(item.is_folder) === 1;
      const isTrashed = Number(item.is_trashed) === 1;
      const isSelected = Number(item.id) === Number(selectedId);
      const href = isTrashed ? `#trash-${item.id}` : (isFolder ? `/drive/folders/${item.id}` : `#preview-${item.id}`);
      const starBadge = Number(item.is_starred) === 1 ? '<span class="badge-inline badge-star" title="ã‚¹ã‚¿ãƒ¼ä»˜ã" aria-label="ã‚¹ã‚¿ãƒ¼ä»˜ã">â˜…</span>' : '';
      const shareBadge = Number(item.is_shared) === 1 ? '<span class="badge-inline" title="å…±æœ‰ä¸­" aria-label="å…±æœ‰ä¸­"><svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg></span>' : '';
      const subText = `${formatDate(item.updated_at)} ãƒ» ${isFolder ? 'ãƒ•ã‚©ãƒ«ãƒ€' : formatBytes(item.size_bytes, false)}`;

      return `<tr class="drive-row${isSelected ? ' selected' : ''}" data-item-id="${item.id}" data-is-folder="${isFolder ? 1 : 0}" data-is-trashed="${isTrashed ? 1 : 0}">
        <td class="col-name">
          <div class="item-name-cell">
            ${itemIconSvg(item)}
            <div class="item-text-stack">
              <a class="item-link" href="${href}" data-action="open" data-item-id="${item.id}" data-is-trashed="${isTrashed ? 1 : 0}">${esc(item.name)}${starBadge}${shareBadge}</a>
              <span class="item-sub-mobile">${esc(subText)}</span>
            </div>
          </div>
        </td>
        <td class="col-owner">${esc(item.owner_name)}</td>
        <td class="col-updated">${esc(formatDate(item.updated_at))}</td>
        <td class="col-size">${esc(formatBytes(item.size_bytes, isFolder))}</td>
        <td class="col-actions row-actions-cell">
          <button type="button" class="kebab-btn" data-action="menu" data-item-id="${item.id}" aria-label="${esc(item.name)} ã®æ“ä½œãƒ¡ãƒ‹ãƒ¥ãƒ¼">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>
          </button>
        </td>
      </tr>`;
    }).join('');

    const folders = items.filter((i) => Number(i.is_folder) === 1);
    const files = items.filter((i) => Number(i.is_folder) === 0);
    let gridHtml = '';
    if (folders.length > 0) {
      gridHtml += '<h2 class="grid-section-title">ãƒ•ã‚©ãƒ«ãƒ€</h2><div class="folder-grid">';
      gridHtml += folders.map((item) => {
        const isTrashed = Number(item.is_trashed) === 1;
        const isSelected = Number(item.id) === Number(selectedId);
        const href = isTrashed ? `#trash-${item.id}` : `/drive/folders/${item.id}`;
        return `<div class="folder-card${isSelected ? ' selected' : ''}" data-item-id="${item.id}" data-is-folder="1" data-is-trashed="${isTrashed ? 1 : 0}">
          <a class="folder-card-main" href="${href}" data-action="open" data-item-id="${item.id}" data-is-trashed="${isTrashed ? 1 : 0}">
            ${itemIconSvg(item)}
            <span>${esc(item.name)}</span>
          </a>
          <button type="button" class="kebab-btn" data-action="menu" data-item-id="${item.id}" aria-label="${esc(item.name)} ã®æ“ä½œãƒ¡ãƒ‹ãƒ¥ãƒ¼">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>
          </button>
        </div>`;
      }).join('');
      gridHtml += '</div>';
    }
    if (files.length > 0) {
      gridHtml += '<h2 class="grid-section-title">ãƒ•ã‚¡ã‚¤ãƒ«</h2><div class="file-grid">';
      gridHtml += files.map((item) => {
        const isTrashed = Number(item.is_trashed) === 1;
        const isSelected = Number(item.id) === Number(selectedId);
        const isImg = !isTrashed && INLINE_IMAGE_MIMES.has((item.mime_type || '').toLowerCase());
        const thumb = isImg
          ? `<img src="/api/files/${item.id}/preview" alt="${esc(item.name)}" width="220" height="132" loading="lazy">`
          : `${itemIconSvg(item)}<span class="storage-text">${esc((item.extension || 'file').toUpperCase())}</span>`;
        return `<article class="file-card${isSelected ? ' selected' : ''}" data-item-id="${item.id}" data-is-folder="0" data-is-trashed="${isTrashed ? 1 : 0}">
          <div class="file-card-header">
            <a class="file-card-title" href="#preview-${item.id}" data-action="open" data-item-id="${item.id}" data-is-trashed="${isTrashed ? 1 : 0}">
              ${itemIconSvg(item)}
              <span>${esc(item.name)}</span>
            </a>
            <button type="button" class="kebab-btn" data-action="menu" data-item-id="${item.id}" aria-label="${esc(item.name)} ã®æ“ä½œãƒ¡ãƒ‹ãƒ¥ãƒ¼">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>
            </button>
          </div>
          <a class="file-card-thumb" href="#preview-${item.id}" data-action="open" data-item-id="${item.id}" data-is-trashed="${isTrashed ? 1 : 0}" aria-label="${esc(item.name)} ã‚’ãƒ—ãƒ¬ãƒ“ãƒ¥ãƒ¼">${thumb}</a>
          <div class="file-card-meta">
            <span>${esc(formatDate(item.updated_at))}</span>
            <span>${esc(formatBytes(item.size_bytes, false))}</span>
          </div>
        </article>`;
      }).join('');
      gridHtml += '</div>';
    }
    filesGridWrap.innerHTML = gridHtml;
    updateSelectionBar();
  }

  async function refreshItems() {
    const params = new URLSearchParams();
    params.set('view', state.view || 'my-drive');
    if (state.folderId) params.set('folder_id', String(state.folderId));
    if (state.q) params.set('q', state.q);
    if (state.type && state.type !== 'all') params.set('type', state.type);
    if (state.sort) params.set('sort', state.sort);
    if (state.dir) params.set('dir', state.dir);

    try {
      const data = await apiRequest('/api/items?' + params.toString());
      state.items = data.items || [];
      if (data.storage) {
        state.storage = data.storage;
        updateStorageUI(data.storage);
      }
      if (selectedId && !state.items.some((i) => Number(i.id) === Number(selectedId))) {
        selectedId = null;
      }
      renderItems();
    } catch (err) {
      showToast(err.message);
    }
  }

  async function openDetails(id) {
    if (!detailsPanel || !detailsContent) return;
    detailsPanel.classList.add('open');
    if (detailsToggleBtn) detailsToggleBtn.setAttribute('aria-pressed', 'true');
    if (!id) {
      detailsContent.innerHTML = '<p class="storage-text">ãƒ•ã‚¡ã‚¤ãƒ«ã¾ãŸã¯ãƒ•ã‚©ãƒ«ãƒ€ã‚’é¸æŠžã™ã‚‹ã¨ã€è©³ç´°æƒ…å ±ã¨ã‚¢ã‚¯ãƒ†ã‚£ãƒ“ãƒ†ã‚£å±¥æ­´ãŒè¡¨ç¤ºã•ã‚Œã¾ã™ã€‚</p>';
      return;
    }
    try {
      const data = await apiRequest(`/api/items/${id}`);
      const item = data.item;
      const isTrashed = Number(item.is_trashed) === 1;
      const activities = data.activities || [];
      const wrap = document.createElement('div');
      wrap.innerHTML = `
        <h3 style="margin:0 0 12px;font-size:15px;word-break:break-word">${esc(item.name)}</h3>
        <dl class="details-dl">
          <dt>ç¨®é¡ž</dt><dd>${Number(item.is_folder) === 1 ? 'ãƒ•ã‚©ãƒ«ãƒ€' : esc(item.mime_type)}</dd>
          <dt>ã‚µã‚¤ã‚º</dt><dd>${esc(formatBytes(item.size_bytes, Number(item.is_folder) === 1))}</dd>
          <dt>ã‚ªãƒ¼ãƒŠãƒ¼</dt><dd>${esc(item.owner_name)}</dd>
          <dt>æ›´æ–°æ—¥æ™‚</dt><dd>${esc(formatDate(item.updated_at))}</dd>
          <dt>å…±æœ‰</dt><dd>${Number(item.is_shared) === 1 ? 'ãƒªãƒ³ã‚¯ã‚’çŸ¥ã£ã¦ã„ã‚‹å…¨å“¡ãŒé–²è¦§å¯' : 'éžå…¬é–‹'}</dd>
        </dl>
        ${isTrashed ? '' : `<div class="form-field" style="margin-top:14px">
          <label for="detail-desc-input">èª¬æ˜Ž</label>
          <textarea id="detail-desc-input" class="textarea-input" rows="3" placeholder="èª¬æ˜Žã‚’è¿½åŠ ">${esc(item.description || '')}</textarea>
          <button type="button" id="detail-desc-save" class="btn-secondary" style="width:fit-content;margin-top:4px">èª¬æ˜Žã‚’ä¿å­˜</button>
        </div>`}
        <h3 style="margin:16px 0 8px;font-size:14px">ã‚¢ã‚¯ãƒ†ã‚£ãƒ“ãƒ†ã‚£</h3>
        <ul class="activity-list">
          ${activities.length ? activities.map((a) => `<li class="activity-item"><strong>${esc(a.actor_name)}</strong>: ${esc(a.detail)}<time>${esc(formatDate(a.created_at))}</time></li>`).join('') : '<li class="activity-item">å±¥æ­´ã¯ã¾ã ã‚ã‚Šã¾ã›ã‚“</li>'}
        </ul>
      `;
      detailsContent.replaceChildren(wrap);
      const saveBtn = document.getElementById('detail-desc-save');
      if (saveBtn) {
        saveBtn.addEventListener('click', async () => {
          const val = document.getElementById('detail-desc-input').value;
          try {
            await apiRequest(`/api/items/${item.id}`, {
              method: 'PATCH',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ description: val })
            });
            showToast('èª¬æ˜Žã‚’ä¿å­˜ã—ã¾ã—ãŸ');
            await refreshItems();
            await openDetails(item.id);
          } catch (e) {
            showToast(e.message);
          }
        });
      }
    } catch (e) {
      showToast(e.message);
    }
  }

  function openTrashedPrompt(item) {
    const p = document.createElement('p');
    p.textContent = `ã€Œ${item.name}ã€ã¯ã‚´ãƒŸç®±ã«ã‚ã‚Šã¾ã™ã€‚é–‹ãã«ã¯å…ˆã«å¾©å…ƒã—ã¦ãã ã•ã„ã€‚`;
    openModal('ã‚´ãƒŸç®±ã®ã‚¢ã‚¤ãƒ†ãƒ ', p, [
      { label: 'é–‰ã˜ã‚‹', className: 'btn-secondary', onClick: closeModal },
      { label: 'å¾©å…ƒã™ã‚‹', className: 'btn-primary', onClick: async () => { closeModal(); await restoreItem(item); } }
    ]);
  }

  async function openPreview(item) {
    if (!previewBackdrop) return;
    if (Number(item.is_trashed) === 1) {
      openTrashedPrompt(item);
      return;
    }
    if (Number(item.is_folder) === 1) {
      window.location.href = `/drive/folders/${item.id}`;
      return;
    }
    previewTitle.textContent = item.name;
    previewDownloadLink.href = `/api/files/${item.id}/download`;
    previewBody.innerHTML = '<p style="color:#ffffff">èª­ã¿è¾¼ã¿ä¸­...</p>';
    previewBackdrop.classList.add('open');

    const mime = (item.mime_type || '').toLowerCase();
    const previewUrl = `/api/files/${item.id}/preview`;

    if (INLINE_IMAGE_MIMES.has(mime)) {
      const img = document.createElement('img');
      img.className = 'preview-img';
      img.src = previewUrl;
      img.alt = item.name;
      previewBody.replaceChildren(img);
      return;
    }
    if (mime === 'application/pdf') {
      const iframe = document.createElement('iframe');
      iframe.className = 'preview-pdf';
      iframe.src = previewUrl;
      iframe.title = item.name;
      previewBody.replaceChildren(iframe);
      return;
    }

    try {
      const data = await apiRequest(previewUrl);
      const pre = document.createElement('pre');
      pre.className = 'preview-doc';
      pre.textContent = data.content ?? 'ãƒ—ãƒ¬ãƒ“ãƒ¥ãƒ¼ã‚’åˆ©ç”¨ã§ãã¾ã›ã‚“ã€‚ãƒ€ã‚¦ãƒ³ãƒ­ãƒ¼ãƒ‰ã—ã¦ã”ç¢ºèªãã ã•ã„ã€‚';
      previewBody.replaceChildren(pre);
    } catch (_) {
      const box = document.createElement('div');
      box.className = 'preview-doc';
      box.textContent = `${item.name} ã®ã‚¤ãƒ³ãƒ©ã‚¤ãƒ³ãƒ—ãƒ¬ãƒ“ãƒ¥ãƒ¼ã¯éžå¯¾å¿œã§ã™ã€‚å³ä¸Šã®ãƒœã‚¿ãƒ³ã‹ã‚‰ãƒ€ã‚¦ãƒ³ãƒ­ãƒ¼ãƒ‰ã—ã¦ãã ã•ã„ã€‚`;
      previewBody.replaceChildren(box);
    }
  }

  function closePreview() {
    if (previewBackdrop) previewBackdrop.classList.remove('open');
    if (previewBody) previewBody.replaceChildren();
  }

  function openNewFolderModal() {
    closeMenus();
    const wrap = document.createElement('div');
    wrap.className = 'form-field';
    wrap.innerHTML = '<label for="new-folder-name">ãƒ•ã‚©ãƒ«ãƒ€å</label><input id="new-folder-name" class="text-input" type="text" value="ç„¡é¡Œã®ãƒ•ã‚©ãƒ«ãƒ€" maxlength="200" required>';
    const submit = async () => {
      const name = document.getElementById('new-folder-name').value.trim();
      if (!name) return;
      try {
        await apiRequest('/api/folders', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ name, parent_id: state.folderId })
        });
        closeModal();
        showToast(`ãƒ•ã‚©ãƒ«ãƒ€ã€Œ${name}ã€ã‚’ä½œæˆã—ã¾ã—ãŸ`);
        await refreshItems();
      } catch (e) {
        showToast(e.message);
      }
    };
    openModal('æ–°ã—ã„ãƒ•ã‚©ãƒ«ãƒ€', wrap, [
      { label: 'ã‚­ãƒ£ãƒ³ã‚»ãƒ«', className: 'btn-secondary', onClick: closeModal },
      { label: 'ä½œæˆ', className: 'btn-primary', onClick: submit }
    ], submit);
  }

  function openRenameModal(item) {
    closeMenus();
    const wrap = document.createElement('div');
    wrap.className = 'form-field';
    wrap.innerHTML = `<label for="rename-input">æ–°ã—ã„åå‰</label><input id="rename-input" class="text-input" type="text" value="${esc(item.name)}" maxlength="200" required>`;
    const submit = async () => {
      const name = document.getElementById('rename-input').value.trim();
      if (!name) return;
      try {
        await apiRequest(`/api/items/${item.id}`, {
          method: 'PATCH',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ name })
        });
        closeModal();
        showToast('åå‰ã‚’å¤‰æ›´ã—ã¾ã—ãŸ');
        await refreshItems();
      } catch (e) {
        showToast(e.message);
      }
    };
    openModal('åå‰ã‚’å¤‰æ›´', wrap, [
      { label: 'ã‚­ãƒ£ãƒ³ã‚»ãƒ«', className: 'btn-secondary', onClick: closeModal },
      { label: 'OK', className: 'btn-primary', onClick: submit }
    ], submit);
  }

  async function openMoveModal(item) {
    closeMenus();
    try {
      const data = await apiRequest('/api/items?view=folders');
      const folders = (data.items || []).filter((f) => Number(f.id) !== Number(item.id));
      const wrap = document.createElement('div');
      wrap.className = 'form-field';
      wrap.innerHTML = `<p style="margin:0 0 8px">ã€Œ${esc(item.name)}ã€ã®ç§»å‹•å…ˆãƒ•ã‚©ãƒ«ãƒ€ã‚’é¸æŠžã—ã¦ãã ã•ã„</p>
        <div class="move-folder-list" role="radiogroup" aria-label="ç§»å‹•å…ˆãƒ•ã‚©ãƒ«ãƒ€">
          <label class="move-folder-option">
            <input type="radio" name="move-target" value="" ${item.parent_id === null ? 'checked' : ''}>
            <span>ãƒžã‚¤ãƒ‰ãƒ©ã‚¤ãƒ–ï¼ˆãƒ«ãƒ¼ãƒˆï¼‰</span>
          </label>
          ${folders.map((f) => `<label class="move-folder-option">
            <input type="radio" name="move-target" value="${f.id}" ${Number(item.parent_id) === Number(f.id) ? 'checked' : ''}>
            <span>${esc(f.name)}</span>
          </label>`).join('')}
        </div>`;
      const submit = async () => {
        const checked = wrap.querySelector('input[name="move-target"]:checked');
        const val = checked ? checked.value : '';
        const targetId = val === '' ? null : Number(val);
        try {
          await apiRequest(`/api/items/${item.id}`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ parent_id: targetId })
          });
          closeModal();
          showToast('ã‚¢ã‚¤ãƒ†ãƒ ã‚’ç§»å‹•ã—ã¾ã—ãŸ');
          await refreshItems();
        } catch (e) {
          showToast(e.message);
        }
      };
      openModal('ã‚¢ã‚¤ãƒ†ãƒ ã‚’ç§»å‹•', wrap, [
        { label: 'ã‚­ãƒ£ãƒ³ã‚»ãƒ«', className: 'btn-secondary', onClick: closeModal },
        { label: 'ã“ã“ã«ç§»å‹•', className: 'btn-primary', onClick: submit }
      ], submit);
    } catch (e) {
      showToast(e.message);
    }
  }

  function openShareModal(item) {
    closeMenus();
    const wrap = document.createElement('div');
    wrap.style.display = 'flex';
    wrap.style.flexDirection = 'column';
    wrap.style.gap = '12px';
    const isShared = Number(item.is_shared) === 1;
    const shareUrl = item.share_token ? `${window.location.origin}/share/${item.share_token}` : '';
    wrap.innerHTML = `
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
        <input id="share-toggle" type="checkbox" ${isShared ? 'checked' : ''}>
        <span>ãƒªãƒ³ã‚¯ã‚’çŸ¥ã£ã¦ã„ã‚‹å…¨å“¡ã«å…¬é–‹ã™ã‚‹</span>
      </label>
      <div class="form-field" id="share-url-wrap" style="${isShared ? '' : 'display:none'}">
        <label for="share-url-input">å…±æœ‰ãƒªãƒ³ã‚¯</label>
        <input id="share-url-input" class="text-input" type="text" readonly value="${esc(shareUrl)}">
      </div>
    `;

    openModal(`ã€Œ${item.name}ã€ã‚’å…±æœ‰`, wrap, [
      {
        id: 'share-copy-btn',
        label: 'ãƒªãƒ³ã‚¯ã‚’ã‚³ãƒ”ãƒ¼',
        className: 'btn-secondary',
        disabled: !isShared || !shareUrl,
        onClick: async () => {
          const input = document.getElementById('share-url-input');
          if (!input || !input.value) return;
          try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
              await navigator.clipboard.writeText(input.value);
            } else {
              input.select();
              document.execCommand('copy');
            }
            showToast('å…±æœ‰ãƒªãƒ³ã‚¯ã‚’ã‚³ãƒ”ãƒ¼ã—ã¾ã—ãŸ');
          } catch (_) {
            showToast('ã‚¯ãƒªãƒƒãƒ—ãƒœãƒ¼ãƒ‰ã¸ã®ã‚³ãƒ”ãƒ¼ã«å¤±æ•—ã—ã¾ã—ãŸ');
          }
        }
      },
      { label: 'å®Œäº†', className: 'btn-primary', onClick: closeModal }
    ]);

    const toggle = document.getElementById('share-toggle');
    toggle.addEventListener('change', async () => {
      const nextChecked = toggle.checked;
      try {
        const res = await apiRequest(`/api/items/${item.id}`, {
          method: 'PATCH',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ is_shared: nextChecked })
        });
        const updated = res.item;
        const urlWrap = document.getElementById('share-url-wrap');
        const urlInput = document.getElementById('share-url-input');
        const copyBtn = document.getElementById('share-copy-btn');
        if (updated.is_shared && updated.share_token) {
          urlWrap.style.display = '';
          urlInput.value = `${window.location.origin}/share/${updated.share_token}`;
          if (copyBtn) copyBtn.disabled = false;
        } else {
          urlWrap.style.display = 'none';
          urlInput.value = '';
          if (copyBtn) copyBtn.disabled = true;
        }
        showToast(nextChecked ? 'å…±æœ‰ãƒªãƒ³ã‚¯ã‚’æœ‰åŠ¹ã«ã—ã¾ã—ãŸ' : 'å…±æœ‰ã‚’åœæ­¢ã—ã¾ã—ãŸï¼ˆæ—§ãƒªãƒ³ã‚¯ã¯ç„¡åŠ¹åŒ–ã•ã‚Œã¾ã—ãŸï¼‰');
        await refreshItems();
      } catch (e) {
        toggle.checked = !nextChecked;
        showToast(e.message);
      }
    });
  }

  async function toggleStar(item) {
    closeMenus();
    try {
      const next = Number(item.is_starred) !== 1;
      await apiRequest(`/api/items/${item.id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ is_starred: next })
      });
      showToast(next ? 'ã‚¹ã‚¿ãƒ¼ä»˜ãã«è¿½åŠ ã—ã¾ã—ãŸ' : 'ã‚¹ã‚¿ãƒ¼ä»˜ãã‹ã‚‰å‰Šé™¤ã—ã¾ã—ãŸ');
      await refreshItems();
    } catch (e) {
      showToast(e.message);
    }
  }

  async function deleteItem(item, permanent = false) {
    closeMenus();
    try {
      await apiRequest(`/api/items/${item.id}${permanent ? '?permanent=1' : ''}`, { method: 'DELETE' });
      showToast(permanent ? 'å®Œå…¨ã«å‰Šé™¤ã—ã¾ã—ãŸ' : 'ã‚´ãƒŸç®±ã«ç§»å‹•ã—ã¾ã—ãŸ');
      selectedId = null;
      await refreshItems();
    } catch (e) {
      showToast(e.message);
    }
  }

  async function restoreItem(item) {
    closeMenus();
    try {
      await apiRequest(`/api/items/${item.id}/restore`, { method: 'POST' });
      showToast(`ã€Œ${item.name}ã€ã‚’å¾©å…ƒã—ã¾ã—ãŸ`);
      selectedId = null;
      await refreshItems();
    } catch (e) {
      showToast(e.message);
    }
  }

  async function uploadFiles(fileList) {
    if (!fileList || fileList.length === 0) return;
    let uploadedCount = 0;
    for (const file of fileList) {
      const fd = new FormData();
      fd.append('file', file);
      if (state.folderId) fd.append('parent_id', String(state.folderId));
      try {
        await apiRequest('/api/files/upload', { method: 'POST', body: fd });
        uploadedCount++;
      } catch (e) {
        showToast(`${file.name}: ${e.message}`);
      }
    }
    if (uploadedCount > 0) {
      showToast(`${uploadedCount}ä»¶ã®ãƒ•ã‚¡ã‚¤ãƒ«ã‚’ã‚¢ãƒƒãƒ—ãƒ­ãƒ¼ãƒ‰ã—ã¾ã—ãŸ`);
      await refreshItems();
    }
  }

  function showItemMenuAt(item, posX, posY) {
    if (!itemMenu) return;
    activeMenuItemId = item.id;
    const isTrashed = Number(item.is_trashed) === 1;
    const isFolder = Number(item.is_folder) === 1;
    itemMenu.innerHTML = isTrashed
      ? `<button type="button" class="menu-item" data-menu-action="restore">å¾©å…ƒã™ã‚‹</button>
         <button type="button" class="menu-item danger" data-menu-action="delete-permanent">å®Œå…¨ã«å‰Šé™¤</button>`
      : `<button type="button" class="menu-item" data-menu-action="open">${isFolder ? 'ãƒ•ã‚©ãƒ«ãƒ€ã‚’é–‹ã' : 'ãƒ—ãƒ¬ãƒ“ãƒ¥ãƒ¼'}</button>
         ${isFolder ? '' : `<a class="menu-item" href="/api/files/${item.id}/download">ãƒ€ã‚¦ãƒ³ãƒ­ãƒ¼ãƒ‰</a>`}
         <button type="button" class="menu-item" data-menu-action="share">å…±æœ‰ãƒªãƒ³ã‚¯</button>
         <button type="button" class="menu-item" data-menu-action="star">${Number(item.is_starred) === 1 ? 'ã‚¹ã‚¿ãƒ¼ã‚’å¤–ã™' : 'ã‚¹ã‚¿ãƒ¼ã‚’ä»˜ã‘ã‚‹'}</button>
         <button type="button" class="menu-item" data-menu-action="rename">åå‰ã‚’å¤‰æ›´</button>
         <button type="button" class="menu-item" data-menu-action="move">ç§»å‹•</button>
         <button type="button" class="menu-item" data-menu-action="details">è©³ç´°ã¨ã‚¢ã‚¯ãƒ†ã‚£ãƒ“ãƒ†ã‚£</button>
         <div class="menu-sep"></div>
         <button type="button" class="menu-item danger" data-menu-action="trash">ã‚´ãƒŸç®±ã«ç§»å‹•</button>`;
    itemMenu.classList.add('open');

    const menuW = itemMenu.offsetWidth || 230;
    const menuH = itemMenu.offsetHeight || 290;
    const left = Math.min(window.innerWidth - menuW - 12, Math.max(12, posX));
    const top = Math.min(window.innerHeight - menuH - 12, Math.max(12, posY));
    itemMenu.style.left = left + 'px';
    itemMenu.style.top = top + 'px';
  }

  // Event bindings
  if (menuToggleBtn && sidebar && scrim) {
    menuToggleBtn.addEventListener('click', () => {
      const opening = !sidebar.classList.contains('open');
      sidebar.classList.toggle('open', opening);
      scrim.classList.toggle('open', opening);
      menuToggleBtn.setAttribute('aria-expanded', String(opening));
    });
    scrim.addEventListener('click', closeDrawer);
  }

  function openNewPopover(anchor) {
    if (!newMenu) return;
    const isOpen = newMenu.classList.contains('open');
    closeMenus();
    if (!isOpen) {
      const rect = anchor.getBoundingClientRect();
      newMenu.classList.add('open');
      const left = Math.min(window.innerWidth - 240, Math.max(12, rect.left));
      const top = Math.min(window.innerHeight - 160, rect.bottom + 6);
      newMenu.style.left = left + 'px';
      newMenu.style.top = top + 'px';
      if (newBtn) newBtn.setAttribute('aria-expanded', 'true');
    }
  }

  if (newBtn) newBtn.addEventListener('click', (e) => { e.stopPropagation(); openNewPopover(newBtn); });
  if (mobileFab) mobileFab.addEventListener('click', (e) => { e.stopPropagation(); openNewPopover(mobileFab); });

  const newFolderBtn = document.getElementById('menu-new-folder');
  const uploadTriggerBtn = document.getElementById('menu-upload-file');
  if (newFolderBtn) newFolderBtn.addEventListener('click', openNewFolderModal);
  if (uploadTriggerBtn && fileUploadInput) {
    uploadTriggerBtn.addEventListener('click', () => { closeMenus(); fileUploadInput.click(); });
    fileUploadInput.addEventListener('change', () => {
      uploadFiles(fileUploadInput.files);
      fileUploadInput.value = '';
    });
  }

  if (itemMenu) {
    itemMenu.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-menu-action]');
      if (!btn) return;
      const item = (state.items || []).find((i) => Number(i.id) === Number(activeMenuItemId));
      if (!item) return;
      const act = btn.getAttribute('data-menu-action');
      if (act === 'open') { closeMenus(); openPreview(item); }
      else if (act === 'share') openShareModal(item);
      else if (act === 'star') toggleStar(item);
      else if (act === 'rename') openRenameModal(item);
      else if (act === 'move') openMoveModal(item);
      else if (act === 'details') { closeMenus(); selectedId = item.id; syncSelectionVisuals(); openDetails(item.id); }
      else if (act === 'trash') deleteItem(item, false);
      else if (act === 'delete-permanent') deleteItem(item, true);
      else if (act === 'restore') restoreItem(item);
    });
  }

  document.addEventListener('click', (e) => {
    if (!e.target.closest('#new-menu') && !e.target.closest('#new-btn') && !e.target.closest('#mobile-fab') && !e.target.closest('#item-menu') && !e.target.closest('[data-action="menu"]')) {
      closeMenus();
    }
  });

  const handleFileAreaClick = (e) => {
    const menuBtn = e.target.closest('[data-action="menu"]');
    if (menuBtn) {
      e.preventDefault();
      e.stopPropagation();
      const id = Number(menuBtn.getAttribute('data-item-id'));
      const item = (state.items || []).find((i) => Number(i.id) === id);
      if (item) {
        const rect = menuBtn.getBoundingClientRect();
        selectedId = id;
        syncSelectionVisuals();
        showItemMenuAt(item, rect.right - 230, rect.bottom + 4);
      }
      return;
    }

    const openLink = e.target.closest('[data-action="open"]');
    if (openLink) {
      const id = Number(openLink.getAttribute('data-item-id'));
      const item = (state.items || []).find((i) => Number(i.id) === id);
      if (item) {
        if (Number(item.is_trashed) === 1 || Number(item.is_folder) === 0) {
          e.preventDefault();
          selectedId = id;
          syncSelectionVisuals();
          openPreview(item);
        }
      }
      return;
    }

    const row = e.target.closest('[data-item-id]');
    if (row) {
      selectedId = Number(row.getAttribute('data-item-id'));
      syncSelectionVisuals();
      if (detailsPanel && detailsPanel.classList.contains('open')) {
        openDetails(selectedId);
      }
    }
  };

  const handleContextMenu = (e) => {
    const row = e.target.closest('[data-item-id]');
    if (!row) return;
    e.preventDefault();
    const id = Number(row.getAttribute('data-item-id'));
    const item = (state.items || []).find((i) => Number(i.id) === id);
    if (item) {
      selectedId = id;
      syncSelectionVisuals();
      showItemMenuAt(item, e.clientX, e.clientY);
    }
  };

  if (filesListWrap) {
    filesListWrap.addEventListener('click', handleFileAreaClick);
    filesListWrap.addEventListener('contextmenu', handleContextMenu);
  }
  if (filesGridWrap) {
    filesGridWrap.addEventListener('click', handleFileAreaClick);
    filesGridWrap.addEventListener('contextmenu', handleContextMenu);
  }

  // Selection toolbar buttons
  const selDownloadBtn = document.getElementById('sel-download-btn');
  const selStarBtn = document.getElementById('sel-star-btn');
  const selShareBtn = document.getElementById('sel-share-btn');
  const selRenameBtn = document.getElementById('sel-rename-btn');
  const selMoveBtn = document.getElementById('sel-move-btn');
  const selTrashBtn = document.getElementById('sel-trash-btn');
  const selRestoreBtn = document.getElementById('sel-restore-btn');
  const selClearBtn = document.getElementById('sel-clear-btn');

  if (selDownloadBtn) selDownloadBtn.addEventListener('click', () => { const it = getSelectedItem(); if (it) window.location.href = `/api/files/${it.id}/download`; });
  if (selStarBtn) selStarBtn.addEventListener('click', () => { const it = getSelectedItem(); if (it) toggleStar(it); });
  if (selShareBtn) selShareBtn.addEventListener('click', () => { const it = getSelectedItem(); if (it) openShareModal(it); });
  if (selRenameBtn) selRenameBtn.addEventListener('click', () => { const it = getSelectedItem(); if (it) openRenameModal(it); });
  if (selMoveBtn) selMoveBtn.addEventListener('click', () => { const it = getSelectedItem(); if (it) openMoveModal(it); });
  if (selTrashBtn) selTrashBtn.addEventListener('click', () => { const it = getSelectedItem(); if (it) deleteItem(it, Number(it.is_trashed) === 1); });
  if (selRestoreBtn) selRestoreBtn.addEventListener('click', () => { const it = getSelectedItem(); if (it) restoreItem(it); });
  if (selClearBtn) selClearBtn.addEventListener('click', () => { selectedId = null; syncSelectionVisuals(); });

  const emptyTrashBtn = document.getElementById('empty-trash-btn');
  if (emptyTrashBtn) {
    emptyTrashBtn.addEventListener('click', () => {
      const p = document.createElement('p');
      p.textContent = 'ã‚´ãƒŸç®±å†…ã®ã™ã¹ã¦ã®ã‚¢ã‚¤ãƒ†ãƒ ãŒå®Œå…¨ã«å‰Šé™¤ã•ã‚Œã¾ã™ã€‚ã“ã®æ“ä½œã¯å–ã‚Šæ¶ˆã›ã¾ã›ã‚“ã€‚';
      openModal('ã‚´ãƒŸç®±ã‚’ç©ºã«ã—ã¾ã™ã‹ï¼Ÿ', p, [
        { label: 'ã‚­ãƒ£ãƒ³ã‚»ãƒ«', className: 'btn-secondary', onClick: closeModal },
        {
          label: 'å®Œå…¨ã«å‰Šé™¤',
          className: 'btn-danger',
          onClick: async () => {
            try {
              await apiRequest('/api/trash/empty', { method: 'POST' });
              closeModal();
              showToast('ã‚´ãƒŸç®±ã‚’ç©ºã«ã—ã¾ã—ãŸ');
              await refreshItems();
            } catch (e) {
              showToast(e.message);
            }
          }
        }
      ]);
    });
  }

  // View Mode Toggle
  if (viewListBtn) viewListBtn.addEventListener('click', () => { viewMode = 'list'; try { localStorage.setItem('clouddrive_view_mode', 'list'); } catch (_) {} renderItems(); });
  if (viewGridBtn) viewGridBtn.addEventListener('click', () => { viewMode = 'grid'; try { localStorage.setItem('clouddrive_view_mode', 'grid'); } catch (_) {} renderItems(); });

  // Details Panel Toggle
  if (detailsToggleBtn && detailsPanel) {
    detailsToggleBtn.addEventListener('click', () => {
      const opening = !detailsPanel.classList.contains('open');
      if (opening) openDetails(selectedId);
      else {
        detailsPanel.classList.remove('open');
        detailsToggleBtn.setAttribute('aria-pressed', 'false');
      }
    });
  }
  if (detailsCloseBtn && detailsPanel) {
    detailsCloseBtn.addEventListener('click', () => {
      detailsPanel.classList.remove('open');
      if (detailsToggleBtn) detailsToggleBtn.setAttribute('aria-pressed', 'false');
    });
  }

  // Filter chips & Sort
  document.querySelectorAll('[data-filter-type]').forEach((btn) => {
    btn.addEventListener('click', () => {
      state.type = btn.getAttribute('data-filter-type') || 'all';
      document.querySelectorAll('[data-filter-type]').forEach((b) => b.setAttribute('aria-pressed', String(b === btn)));
      refreshItems();
    });
  });

  if (sortSelect) {
    sortSelect.addEventListener('change', () => {
      state.sort = sortSelect.value;
      refreshItems();
    });
  }
  if (sortDirBtn) {
    sortDirBtn.addEventListener('click', () => {
      state.dir = state.dir === 'asc' ? 'desc' : 'asc';
      sortDirBtn.textContent = state.dir === 'asc' ? 'æ˜‡é † â†‘' : 'é™é † â†“';
      refreshItems();
    });
  }

  // Search Input
  if (searchInput && searchClearBtn) {
    const syncClear = () => searchClearBtn.classList.toggle('visible', searchInput.value.length > 0);
    syncClear();
    searchInput.addEventListener('input', () => {
      syncClear();
      if (!filesTbody) return;
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => {
        state.q = searchInput.value.trim();
        refreshItems();
      }, 220);
    });
    searchClearBtn.addEventListener('click', () => {
      searchInput.value = '';
      state.q = '';
      syncClear();
      searchInput.focus();
      if (filesTbody) refreshItems();
    });
  }

  // Drag & Drop Upload (fixed #main-content ID!)
  if (workspaceMain && dropOverlay) {
    let dragDepth = 0;
    workspaceMain.addEventListener('dragenter', (e) => {
      if (e.dataTransfer && Array.from(e.dataTransfer.types).includes('Files')) {
        e.preventDefault();
        dragDepth++;
        dropOverlay.classList.add('active');
      }
    });
    workspaceMain.addEventListener('dragover', (e) => { e.preventDefault(); });
    workspaceMain.addEventListener('dragleave', () => {
      dragDepth = Math.max(0, dragDepth - 1);
      if (dragDepth === 0) dropOverlay.classList.remove('active');
    });
    workspaceMain.addEventListener('drop', (e) => {
      e.preventDefault();
      dragDepth = 0;
      dropOverlay.classList.remove('active');
      if (e.dataTransfer && e.dataTransfer.files.length > 0) {
        uploadFiles(e.dataTransfer.files);
      }
    });
  }

  // Modal & Preview close
  if (modalBackdrop) modalBackdrop.addEventListener('click', (e) => { if (e.target === modalBackdrop) closeModal(); });
  if (previewCloseBtn) previewCloseBtn.addEventListener('click', closePreview);
  if (previewBackdrop) previewBackdrop.addEventListener('click', (e) => { if (e.target === previewBackdrop) closePreview(); });

  // Full Google Drive Keyboard Shortcuts
  document.addEventListener('keydown', (e) => {
    const inField = document.activeElement && ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName);
    if (e.key === 'Escape') {
      closeMenus();
      closeModal();
      closePreview();
      closeDrawer();
      return;
    }
    if (inField) return;

    if (e.key === '/' && searchInput) {
      e.preventDefault();
      searchInput.focus();
    } else if (e.key === 'i' && detailsToggleBtn) {
      detailsToggleBtn.click();
    } else if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && state.items && state.items.length > 0) {
      e.preventDefault();
      const idx = state.items.findIndex((x) => Number(x.id) === Number(selectedId));
      const nextIdx = e.key === 'ArrowDown'
        ? (idx < state.items.length - 1 ? idx + 1 : 0)
        : (idx > 0 ? idx - 1 : state.items.length - 1);
      selectedId = state.items[nextIdx].id;
      syncSelectionVisuals();
    } else if (e.key === 'Enter') {
      const it = getSelectedItem();
      if (it) openPreview(it);
    } else if (e.key === 'F2') {
      const it = getSelectedItem();
      if (it && Number(it.is_trashed) === 0) openRenameModal(it);
    } else if (e.key === 'Delete' || e.key === 'Backspace') {
      const it = getSelectedItem();
      if (it) deleteItem(it, Number(it.is_trashed) === 1);
    }
  });

  if (viewMode === 'grid' && filesTbody) {
    renderItems();
  }
}
/* CloudDrive â€” Google Drive Material 3 UI (Desktop + Mobile, WCAG AAA/AA, Zero-CLS) */
:root {
  --shell-bg: #f8fafd;
  --surface: #ffffff;
  --surface-hover: #f0f4f9;
  --surface-variant: #e9eef6;
  --surface-variant-hover: #dfe6f1;
  --active-bg: #c2e7ff;
  --active-fg: #001d35;
  --selected-bg: #d3e3fd;
  --primary: #0b57d0;
  --primary-hover: #0842a0;
  --danger: #b3261e;
  --danger-bg: #fce8e6;
  --ink: #1f1f1f;
  --ink-secondary: #444746;
  --border: #dde3ea;
  --border-strong: #c4c7c5;
  --shadow-sm: 0 1px 2px 0 rgba(60, 64, 67, 0.25), 0 1px 3px 1px rgba(60, 64, 67, 0.14);
  --shadow-md: 0 2px 6px 2px rgba(60, 64, 67, 0.15), 0 1px 2px 0 rgba(60, 64, 67, 0.28);
  --shadow-lg: 0 6px 20px 4px rgba(0, 0, 0, 0.16), 0 2px 6px rgba(0, 0, 0, 0.12);
  --radius-sm: 8px;
  --radius-md: 12px;
  --radius-lg: 16px;
  --radius-pill: 9999px;
  --header-h: 64px;
  --sidebar-w: 256px;
  --details-w: 320px;
  --font-sans: -apple-system, BlinkMacSystemFont, "Segoe UI", "Hiragino Sans", "Hiragino Kaku Gothic ProN", "Noto Sans JP", Roboto, "Yu Gothic", sans-serif;
  --font-mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}

*, *::before, *::after { box-sizing: border-box; }

html, body {
  margin: 0;
  padding: 0;
  height: 100%;
  background: var(--shell-bg);
  color: var(--ink);
  font-family: var(--font-sans);
  font-size: 14px;
  line-height: 1.5;
  -webkit-font-smoothing: antialiased;
  overflow-x: hidden;
}

a { color: var(--primary); text-decoration: none; }
a:hover { text-decoration: underline; }
button, input, select, textarea { font: inherit; color: inherit; }
button { cursor: pointer; }

:focus-visible {
  outline: 2px solid var(--primary);
  outline-offset: 2px;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

.skip-link {
  position: fixed;
  top: -100px;
  left: 16px;
  z-index: 1000;
  background: var(--primary);
  color: #ffffff;
  padding: 10px 16px;
  border-radius: var(--radius-sm);
  font-weight: 600;
}
.skip-link:focus { top: 12px; text-decoration: none; }

/* App Shell */
.app-shell {
  display: grid;
  grid-template-rows: var(--header-h) 1fr;
  grid-template-columns: var(--sidebar-w) minmax(0, 1fr);
  grid-template-areas:
    "header header"
    "sidebar workspace";
  min-height: 100vh;
  width: 100%;
  background: var(--shell-bg);
}

/* Header */
.app-header {
  grid-area: header;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 16px;
  height: var(--header-h);
  width: 100%;
  min-width: 0;
  background: var(--shell-bg);
  position: sticky;
  top: 0;
  z-index: 40;
}

.brand-area {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: calc(var(--sidebar-w) - 16px);
  flex-shrink: 0;
}

.menu-toggle-btn {
  display: none;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border: none;
  border-radius: 50%;
  background: transparent;
  color: var(--ink-secondary);
}
.menu-toggle-btn:hover { background: var(--surface-variant); }

.brand-link {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  color: var(--ink-secondary);
  text-decoration: none;
  padding: 4px 8px;
  border-radius: var(--radius-sm);
}
.brand-link:hover { text-decoration: none; background: var(--surface-variant); }
.brand-name {
  font-size: 21px;
  font-weight: 500;
  letter-spacing: -0.01em;
  color: var(--ink-secondary);
}

/* Search Pill Bar (Google Drive signature) */
.search-form {
  flex: 1 1 0%;
  min-width: 0;
  max-width: 720px;
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--surface-variant);
  border-radius: var(--radius-pill);
  padding: 0 10px 0 14px;
  height: 48px;
  border: 1px solid transparent;
  transition: background-color 0.15s ease, box-shadow 0.15s ease;
}
.search-form:focus-within {
  background: var(--surface);
  box-shadow: var(--shadow-sm);
  border-color: var(--border);
}
.search-icon {
  color: var(--ink-secondary);
  flex-shrink: 0;
  display: inline-flex;
}
.search-input {
  flex: 1;
  min-width: 0;
  border: none;
  background: transparent;
  font-size: 15px;
  color: var(--ink);
  outline: none;
  height: 100%;
}
.search-input::placeholder { color: var(--ink-secondary); opacity: 1; }
.search-clear-btn {
  display: none;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border: none;
  border-radius: 50%;
  background: transparent;
  color: var(--ink-secondary);
}
.search-clear-btn.visible { display: inline-flex; }
.search-clear-btn:hover { background: var(--surface-variant); }

.header-actions {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 6px;
}

.fw-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 12px;
  border-radius: var(--radius-pill);
  background: var(--surface);
  border: 1px solid var(--border);
  font-size: 12px;
  font-weight: 600;
  color: var(--ink);
  white-space: nowrap;
}
.fw-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #137333;
}

.icon-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border: none;
  border-radius: 50%;
  background: transparent;
  color: var(--ink-secondary);
  transition: background-color 0.12s ease;
  text-decoration: none;
}
.icon-btn:hover { background: var(--surface-variant); text-decoration: none; color: var(--ink); }
.icon-btn[aria-pressed="true"] { background: var(--active-bg); color: var(--active-fg); }

.user-avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: var(--primary);
  color: #ffffff;
  font-weight: 600;
  font-size: 14px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 2px solid #ffffff;
  box-shadow: 0 0 0 1px var(--border);
  flex-shrink: 0;
}

/* Sidebar */
.app-sidebar {
  grid-area: sidebar;
  padding: 8px 12px 20px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  background: var(--shell-bg);
}

.new-btn-wrap { position: relative; }

.new-btn {
  display: inline-flex;
  align-items: center;
  gap: 12px;
  height: 56px;
  padding: 0 22px 0 16px;
  border: none;
  border-radius: var(--radius-lg);
  background: var(--surface);
  color: var(--ink);
  font-size: 14px;
  font-weight: 500;
  box-shadow: var(--shadow-sm);
  transition: background-color 0.15s ease, box-shadow 0.15s ease;
}
.new-btn:hover {
  background: #edf2fa;
  box-shadow: var(--shadow-md);
}

.nav-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.nav-link {
  display: flex;
  align-items: center;
  gap: 14px;
  height: 38px;
  padding: 0 16px;
  border-radius: var(--radius-pill);
  color: var(--ink);
  font-size: 14px;
  font-weight: 500;
  text-decoration: none;
  transition: background-color 0.12s ease;
}
.nav-link:hover { background: var(--surface-variant); text-decoration: none; }
.nav-link[aria-current="page"] {
  background: var(--active-bg);
  color: var(--active-fg);
  font-weight: 600;
}
.nav-link svg { flex-shrink: 0; }

.sidebar-divider {
  border: none;
  border-top: 1px solid var(--border);
  margin: 6px 8px;
}

.storage-box {
  padding: 8px 16px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.storage-header {
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 14px;
  font-weight: 500;
  color: var(--ink);
}
.storage-bar-track {
  width: 100%;
  height: 6px;
  background: var(--border);
  border-radius: var(--radius-pill);
  overflow: hidden;
}
.storage-bar-fill {
  height: 100%;
  background: var(--primary);
  border-radius: var(--radius-pill);
  min-width: 4px;
  transition: width 0.25s ease;
}
.storage-text {
  font-size: 12px;
  color: var(--ink-secondary);
}
.storage-cta {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 7px 14px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-pill);
  background: transparent;
  color: var(--primary);
  font-size: 13px;
  font-weight: 500;
  width: fit-content;
  text-decoration: none;
}
.storage-cta:hover { background: #edf2fa; text-decoration: none; }

/* Main Workspace Surface */
.workspace-container {
  grid-area: workspace;
  display: flex;
  gap: 12px;
  padding: 0 16px 16px 0;
  min-width: 0;
}

.workspace-main {
  flex: 1;
  min-width: 0;
  background: var(--surface);
  border-radius: var(--radius-lg);
  display: flex;
  flex-direction: column;
  position: relative;
  min-height: calc(100vh - var(--header-h) - 16px);
  border: 1px solid #e6ebf1;
}

.workspace-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 16px 20px 10px;
}

.breadcrumb-nav {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 4px;
  min-width: 0;
}
.breadcrumb-title {
  margin: 0;
  font-size: 22px;
  font-weight: 400;
  color: var(--ink);
  line-height: 1.3;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 4px;
}
.breadcrumb-link {
  color: var(--ink-secondary);
  padding: 4px 10px;
  border-radius: var(--radius-pill);
  font-size: 20px;
  text-decoration: none;
}
.breadcrumb-link:hover { background: var(--surface-hover); text-decoration: none; color: var(--ink); }
.breadcrumb-current {
  padding: 4px 8px;
  color: var(--ink);
  font-weight: 500;
  font-size: 22px;
}
.breadcrumb-sep { color: var(--ink-secondary); font-size: 18px; }

.toolbar-right {
  display: flex;
  align-items: center;
  gap: 8px;
}

/* Segmented List/Grid Toggle (exact Google Drive M3 pill) */
.view-toggle {
  display: inline-flex;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-pill);
  overflow: hidden;
  height: 36px;
}
.view-toggle-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 4px;
  padding: 0 14px;
  border: none;
  background: transparent;
  color: var(--ink);
  font-size: 13px;
}
.view-toggle-btn + .view-toggle-btn { border-left: 1px solid var(--border-strong); }
.view-toggle-btn[aria-pressed="true"] {
  background: var(--active-bg);
  color: var(--active-fg);
  font-weight: 600;
}
.view-toggle-btn:hover:not([aria-pressed="true"]) { background: var(--surface-hover); }

/* Filter & Sort Bar */
.filter-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 4px 20px 12px;
  border-bottom: 1px solid var(--border);
}

.filter-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.chip-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 32px;
  padding: 0 14px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  background: var(--surface);
  color: var(--ink);
  font-size: 13px;
  font-weight: 500;
}
.chip-btn:hover { background: var(--surface-hover); }
.chip-btn[aria-pressed="true"] {
  background: var(--active-bg);
  border-color: var(--active-bg);
  color: var(--active-fg);
  font-weight: 600;
}

.sort-controls {
  display: flex;
  align-items: center;
  gap: 8px;
}
.sort-select {
  height: 32px;
  padding: 0 10px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  background: var(--surface);
  color: var(--ink);
  font-size: 13px;
}

/* Selection Action Bar (Google Drive M3 pill bar) */
.selection-bar {
  display: none;
  align-items: center;
  gap: 8px;
  margin: 10px 20px 4px;
  padding: 6px 14px;
  background: var(--surface-hover);
  border-radius: var(--radius-pill);
  flex-wrap: wrap;
}
.selection-bar.visible { display: flex; }
.selection-label {
  font-weight: 600;
  font-size: 13px;
  color: var(--ink);
  margin-right: 6px;
}
.action-pill-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 32px;
  padding: 0 12px;
  border: none;
  border-radius: var(--radius-pill);
  background: transparent;
  color: var(--ink);
  font-size: 13px;
  font-weight: 500;
}
.action-pill-btn:hover { background: var(--surface-variant-hover); }
.action-pill-btn.danger { color: var(--danger); }
.action-pill-btn.danger:hover { background: var(--danger-bg); }

/* Trash Banner */
.trash-banner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin: 12px 20px 0;
  padding: 12px 16px;
  background: var(--surface-hover);
  border-radius: var(--radius-md);
  font-size: 13px;
  color: var(--ink);
}

/* List View Table */
.files-area {
  flex: 1;
  padding: 8px 20px 24px;
}

.drive-table {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
}
.drive-table th {
  text-align: left;
  font-size: 13px;
  font-weight: 600;
  color: var(--ink-secondary);
  padding: 10px 12px;
  border-bottom: 1px solid var(--border);
  white-space: nowrap;
}
.drive-table th.col-name { width: 46%; }
.drive-table th.col-owner { width: 20%; }
.drive-table th.col-updated { width: 16%; }
.drive-table th.col-size { width: 10%; }
.drive-table th.col-actions { width: 8%; text-align: right; }

.drive-row {
  border-bottom: 1px solid #eef1f5;
  transition: background-color 0.1s ease;
}
.drive-row:hover { background: var(--surface-hover); }
.drive-row.selected { background: var(--selected-bg); }
.drive-row td {
  padding: 8px 12px;
  height: 48px;
  vertical-align: middle;
  color: var(--ink-secondary);
  font-size: 13px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.item-name-cell {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}
.item-link {
  color: var(--ink);
  font-weight: 500;
  font-size: 14px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.item-link:hover { text-decoration: underline; }
.item-sub-mobile { display: none; font-size: 12px; color: var(--ink-secondary); }

.badge-inline {
  display: inline-flex;
  align-items: center;
  color: var(--ink-secondary);
  flex-shrink: 0;
}
.badge-star { color: #b06000; }

.row-actions-cell { text-align: right; }
.kebab-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border: none;
  border-radius: 50%;
  background: transparent;
  color: var(--ink-secondary);
}
.kebab-btn:hover { background: var(--surface-variant-hover); color: var(--ink); }

/* Grid View */
.grid-section-title {
  margin: 14px 0 10px;
  font-size: 13px;
  font-weight: 600;
  color: var(--ink-secondary);
}
.folder-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}
.folder-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 8px 8px 8px 14px;
  height: 52px;
  background: var(--surface-hover);
  border-radius: var(--radius-md);
  border: 1px solid transparent;
  transition: background-color 0.12s ease;
}
.folder-card:hover { background: var(--surface-variant); }
.folder-card.selected { background: var(--selected-bg); border-color: var(--primary); }
.folder-card-main {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
  flex: 1;
  color: var(--ink);
  font-weight: 500;
  text-decoration: none;
}
.folder-card-main span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.file-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 14px;
}
.file-card {
  display: flex;
  flex-direction: column;
  background: var(--surface-hover);
  border-radius: var(--radius-md);
  padding: 10px;
  border: 1px solid transparent;
  transition: background-color 0.12s ease;
}
.file-card:hover { background: var(--surface-variant); }
.file-card.selected { background: var(--selected-bg); border-color: var(--primary); }
.file-card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
  margin-bottom: 8px;
}
.file-card-title {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
  flex: 1;
  font-size: 13px;
  font-weight: 500;
  color: var(--ink);
}
.file-card-title span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.file-card-thumb {
  height: 132px;
  background: var(--surface);
  border-radius: var(--radius-sm);
  border: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  padding: 8px;
  gap: 6px;
}
.file-card-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  border-radius: 4px;
}
.file-card-meta {
  display: flex;
  justify-content: space-between;
  margin-top: 8px;
  font-size: 12px;
  color: var(--ink-secondary);
}

.hidden { display: none !important; }

/* Right Details Side Panel */
.details-panel {
  width: var(--details-w);
  flex-shrink: 0;
  background: var(--surface);
  border-radius: var(--radius-lg);
  border: 1px solid #e6ebf1;
  padding: 18px;
  display: none;
  flex-direction: column;
  gap: 14px;
  height: fit-content;
  max-height: calc(100vh - var(--header-h) - 16px);
  overflow-y: auto;
}
.details-panel.open { display: flex; }
.details-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  border-bottom: 1px solid var(--border);
  padding-bottom: 12px;
}
.details-header h2 {
  margin: 0;
  font-size: 16px;
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.details-dl {
  margin: 0;
  display: grid;
  grid-template-columns: 96px 1fr;
  gap: 8px 10px;
  font-size: 13px;
}
.details-dl dt { color: var(--ink-secondary); font-weight: 500; }
.details-dl dd { margin: 0; color: var(--ink); word-break: break-word; }
.activity-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.activity-item {
  font-size: 12px;
  padding: 8px 10px;
  background: var(--shell-bg);
  border-radius: var(--radius-sm);
}
.activity-item time { display: block; color: var(--ink-secondary); margin-top: 2px; }

/* Empty State */
.empty-state {
  text-align: center;
  padding: 56px 20px;
  color: var(--ink-secondary);
}
.empty-state h2 {
  margin: 12px 0 6px;
  font-size: 18px;
  color: var(--ink);
  font-weight: 500;
}

/* Drag & Drop Overlay */
.drop-overlay {
  position: absolute;
  inset: 10px;
  border: 2px dashed var(--primary);
  border-radius: var(--radius-lg);
  background: rgba(232, 240, 254, 0.92);
  display: none;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  z-index: 50;
  pointer-events: none;
}
.drop-overlay.active { display: flex; }
.drop-overlay p {
  margin: 0;
  font-size: 16px;
  font-weight: 600;
  color: var(--primary);
}

/* Popover & Context Menu */
.menu-popover {
  position: fixed;
  min-width: 224px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-lg);
  padding: 6px 0;
  z-index: 120;
  display: none;
}
.menu-popover.open { display: block; }
.menu-item {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 16px;
  border: none;
  background: transparent;
  color: var(--ink);
  font-size: 14px;
  text-align: left;
}
.menu-item:hover { background: var(--surface-hover); }
.menu-item.danger { color: var(--danger); }
.menu-sep { height: 1px; background: var(--border); margin: 4px 0; }

/* Modal Dialogs */
.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.45);
  display: none;
  align-items: center;
  justify-content: center;
  padding: 16px;
  z-index: 200;
}
.modal-backdrop.open { display: flex; }
.modal-dialog {
  width: 100%;
  max-width: 460px;
  background: var(--surface);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-lg);
  padding: 24px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.modal-dialog h2 { margin: 0; font-size: 20px; font-weight: 500; }
.form-field { display: flex; flex-direction: column; gap: 6px; }
.form-field label { font-size: 13px; font-weight: 600; color: var(--ink-secondary); }
.text-input, .textarea-input {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  font-size: 14px;
  color: var(--ink);
  background: var(--surface);
}
.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}
.btn-primary {
  height: 38px;
  padding: 0 20px;
  border: none;
  border-radius: var(--radius-pill);
  background: var(--primary);
  color: #ffffff;
  font-weight: 600;
  font-size: 14px;
}
.btn-primary:hover { background: var(--primary-hover); }
.btn-secondary {
  height: 38px;
  padding: 0 18px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-pill);
  background: var(--surface);
  color: var(--primary);
  font-weight: 600;
  font-size: 14px;
}
.btn-secondary:hover { background: var(--surface-hover); }
.btn-danger {
  height: 38px;
  padding: 0 18px;
  border: none;
  border-radius: var(--radius-pill);
  background: var(--danger);
  color: #ffffff;
  font-weight: 600;
  font-size: 14px;
}

/* Google Drive Dark Fullscreen Preview Modal */
.preview-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 17, 21, 0.9);
  display: none;
  flex-direction: column;
  z-index: 250;
}
.preview-backdrop.open { display: flex; }
.preview-topbar {
  height: 60px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 16px;
  color: #ffffff;
  background: rgba(0, 0, 0, 0.35);
}
.preview-title-wrap {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}
.preview-title {
  margin: 0;
  font-size: 16px;
  font-weight: 500;
  color: #ffffff;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.preview-topbar .icon-btn { color: #ffffff; }
.preview-topbar .icon-btn:hover { background: rgba(255, 255, 255, 0.14); color: #ffffff; }
.preview-body {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  overflow: auto;
}
.preview-doc {
  width: 100%;
  max-width: 860px;
  max-height: 100%;
  overflow: auto;
  background: #ffffff;
  color: var(--ink);
  border-radius: var(--radius-md);
  padding: 24px;
  font-family: var(--font-mono);
  font-size: 13px;
  line-height: 1.65;
  white-space: pre-wrap;
  word-break: break-word;
  box-shadow: var(--shadow-lg);
}
.preview-img {
  max-width: 100%;
  max-height: 82vh;
  border-radius: var(--radius-sm);
  background: #ffffff;
  box-shadow: var(--shadow-lg);
}
.preview-pdf {
  width: min(900px, 100%);
  height: 80vh;
  border: none;
  border-radius: var(--radius-sm);
  background: #ffffff;
}

/* Toast Notification */
.toast-container {
  position: fixed;
  bottom: 24px;
  left: 24px;
  z-index: 300;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.toast {
  background: #202124;
  color: #ffffff;
  padding: 12px 18px;
  border-radius: var(--radius-sm);
  font-size: 14px;
  box-shadow: var(--shadow-lg);
  max-width: 360px;
}

/* SEO / AEO / GEO Footer Summary Card inside workspace */
.semantic-footer {
  margin-top: auto;
  padding: 16px 20px;
  border-top: 1px solid var(--border);
  background: #fafcff;
  border-radius: 0 0 var(--radius-lg) var(--radius-lg);
  font-size: 12px;
  color: var(--ink-secondary);
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}
.semantic-links {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.semantic-links a {
  display: inline-flex;
  align-items: center;
  min-height: 32px;
  padding: 4px 8px;
  color: var(--primary);
  font-weight: 500;
  border-radius: var(--radius-sm);
}
.semantic-links a:hover { background: var(--surface-variant); text-decoration: none; }

/* About / FAQ / Share / Offline Standalone Content Pages */
.content-page {
  padding: 24px 28px 36px;
  max-width: 920px;
}
.content-page h1 { margin: 0 0 12px; font-size: 26px; font-weight: 600; color: var(--ink); }
.content-page h2 { margin: 28px 0 10px; font-size: 19px; font-weight: 600; color: var(--ink); }
.content-page h3 { margin: 18px 0 6px; font-size: 15px; font-weight: 600; color: var(--ink); }
.content-page ul { list-style: none; padding: 0; margin: 12px 0; display: flex; flex-direction: column; gap: 6px; }
.content-page ul li a {
  display: inline-flex;
  align-items: center;
  min-height: 38px;
  padding: 6px 10px;
  border-radius: var(--radius-sm);
  color: var(--primary);
  font-weight: 500;
}
.content-page ul li a:hover { background: var(--surface-hover); text-decoration: none; }
.tldr-box {
  background: #edf2fa;
  border-left: 4px solid var(--primary);
  padding: 14px 18px;
  border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
  margin: 14px 0 20px;
  color: var(--ink);
}
.spec-table {
  width: 100%;
  border-collapse: collapse;
  margin: 14px 0 20px;
  font-size: 13px;
}
.spec-table th, .spec-table td {
  border: 1px solid var(--border);
  padding: 10px 12px;
  text-align: left;
}
.spec-table th { background: var(--shell-bg); color: var(--ink); font-weight: 600; }
.faq-item {
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  padding: 14px 16px;
  margin-bottom: 10px;
  background: var(--surface);
}
.faq-item summary {
  font-weight: 600;
  color: var(--ink);
  cursor: pointer;
  min-height: 28px;
  display: flex;
  align-items: center;
}
.faq-item p { margin: 8px 0 0; color: var(--ink-secondary); }
.move-folder-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
  max-height: 260px;
  overflow-y: auto;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 8px;
}
.move-folder-option {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  border-radius: var(--radius-sm);
  cursor: pointer;
}
.move-folder-option:hover { background: var(--surface-hover); }

/* Mobile Elements (hidden on desktop) */
.drawer-scrim {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.45);
  z-index: 80;
}
.drawer-scrim.open { display: block; }

.mobile-bottom-nav {
  display: none;
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  height: 64px;
  background: var(--shell-bg);
  border-top: 1px solid var(--border);
  z-index: 60;
  grid-template-columns: repeat(4, 1fr);
  padding: 4px 6px;
}
.mobile-nav-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  font-size: 11px;
  font-weight: 500;
  color: var(--ink-secondary);
  text-decoration: none;
  border-radius: var(--radius-md);
  min-height: 48px;
}
.mobile-nav-item:hover { text-decoration: none; }
.mobile-nav-item[aria-current="page"] { color: var(--active-fg); font-weight: 700; }
.mobile-nav-pill {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 56px;
  height: 28px;
  border-radius: var(--radius-pill);
}
.mobile-nav-item[aria-current="page"] .mobile-nav-pill { background: var(--active-bg); }

.mobile-fab {
  display: none;
  position: fixed;
  right: 16px;
  bottom: 80px;
  width: 56px;
  height: 56px;
  border: none;
  border-radius: 16px;
  background: var(--active-bg);
  color: var(--active-fg);
  box-shadow: var(--shadow-md);
  align-items: center;
  justify-content: center;
  z-index: 65;
}

/* Responsive Breakpoints */
@media (max-width: 1024px) {
  .drive-table th.col-owner,
  .drive-table td.col-owner { display: none; }
  .drive-table th.col-name { width: 58%; }
  .fw-badge { display: none; }
}

@media (max-width: 768px) {
  .app-shell {
    grid-template-columns: minmax(0, 1fr);
    grid-template-areas:
      "header"
      "workspace";
  }
  .app-header {
    padding: 8px 10px;
    gap: 6px;
  }
  .brand-area { min-width: 0; gap: 2px; }
  .brand-link { padding: 2px 4px; }
  .brand-name { display: none; }
  .menu-toggle-btn { display: inline-flex; width: 40px; height: 40px; }
  .search-form { height: 44px; padding: 0 8px 0 12px; gap: 6px; }
  .search-input { font-size: 14px; }
  .header-actions { gap: 4px; flex-shrink: 0; }
  .header-actions .hide-mobile { display: none; }

  .app-sidebar {
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    width: 280px;
    max-width: 82vw;
    z-index: 90;
    transform: translateX(-105%);
    transition: transform 0.2s ease;
    box-shadow: var(--shadow-lg);
    padding-top: 16px;
    overflow-y: auto;
  }
  .app-sidebar.open { transform: translateX(0); }

  .workspace-container {
    padding: 0 0 80px;
    width: 100%;
  }
  .workspace-main {
    border-radius: 0;
    border-left: none;
    border-right: none;
    width: 100%;
    min-height: calc(100vh - var(--header-h) - 64px);
  }
  .workspace-toolbar { padding: 12px 12px 8px; gap: 8px; flex-wrap: nowrap; }
  .breadcrumb-current, .breadcrumb-title { font-size: 18px; }
  .breadcrumb-link { font-size: 15px; padding: 4px 6px; }
  .view-toggle-btn { padding: 0 10px; font-size: 12px; }
  .filter-bar { padding: 4px 12px 10px; gap: 8px; }
  .filter-chips { flex-wrap: nowrap; overflow-x: auto; max-width: 100%; padding-bottom: 2px; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
  .filter-chips::-webkit-scrollbar { display: none; }
  .chip-btn { flex-shrink: 0; height: 32px; padding: 0 12px; font-size: 12px; }
  .sort-controls { width: 100%; justify-content: flex-end; }
  .files-area { padding: 4px 8px 20px; width: 100%; }

  .drive-table { width: 100%; table-layout: fixed; }
  .drive-table thead { display: none; }
  .drive-table td.col-owner,
  .drive-table td.col-updated,
  .drive-table td.col-size { display: none; }
  .drive-table td.col-name { width: auto; max-width: 0; padding: 8px 4px 8px 6px; }
  .drive-table td.col-actions { width: 48px; padding: 4px; }
  .drive-row td { height: 58px; }
  .item-name-cell { flex-wrap: nowrap; gap: 10px; min-width: 0; }
  .item-name-cell > svg { flex-shrink: 0; }
  .item-text-stack { display: flex; flex-direction: column; min-width: 0; flex: 1; }
  .item-link { max-width: 100%; font-size: 14px; }
  .item-sub-mobile { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .kebab-btn { width: 40px; height: 40px; }

  .details-panel.open {
    position: fixed;
    inset: auto 0 0 0;
    width: 100%;
    max-height: 75vh;
    border-radius: 20px 20px 0 0;
    z-index: 150;
    box-shadow: var(--shadow-lg);
  }

  .mobile-bottom-nav { display: grid; }
  .mobile-fab { display: inline-flex; }
  .toast-container { bottom: 76px; left: 12px; right: 12px; }
  .content-page { padding: 18px 16px 32px; }
}

@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { transition: none !important; animation: none !important; }
}
:root{--shell-bg:#f8fafd;--surface:#ffffff;--surface-hover:#f0f4f9;--surface-variant:#e9eef6;--surface-variant-hover:#dfe6f1;--active-bg:#c2e7ff;--active-fg:#001d35;--selected-bg:#d3e3fd;--primary:#0b57d0;--primary-hover:#0842a0;--danger:#b3261e;--danger-bg:#fce8e6;--ink:#1f1f1f;--ink-secondary:#444746;--border:#dde3ea;--border-strong:#c4c7c5;--shadow-sm:0 1px 2px 0 rgba(60,64,67,0.25),0 1px 3px 1px rgba(60,64,67,0.14);--shadow-md:0 2px 6px 2px rgba(60,64,67,0.15),0 1px 2px 0 rgba(60,64,67,0.28);--shadow-lg:0 6px 20px 4px rgba(0,0,0,0.16),0 2px 6px rgba(0,0,0,0.12);--radius-sm:8px;--radius-md:12px;--radius-lg:16px;--radius-pill:9999px;--header-h:64px;--sidebar-w:256px;--details-w:320px;--font-sans:-apple-system,BlinkMacSystemFont,"Segoe UI","Hiragino Sans","Hiragino Kaku Gothic ProN","Noto Sans JP",Roboto,"Yu Gothic",sans-serif;--font-mono:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}*,*::before,*::after{box-sizing:border-box}html,body{margin:0;padding:0;height:100%;background:var(--shell-bg);color:var(--ink);font-family:var(--font-sans);font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased;overflow-x:hidden}a{color:var(--primary);text-decoration:none}a:hover{text-decoration:underline}button,input,select,textarea{font:inherit;color:inherit}button{cursor:pointer}:focus-visible{outline:2px solid var(--primary);outline-offset:2px}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}.skip-link{position:fixed;top:-100px;left:16px;z-index:1000;background:var(--primary);color:#ffffff;padding:10px 16px;border-radius:var(--radius-sm);font-weight:600}.skip-link:focus{top:12px;text-decoration:none}.app-shell{display:grid;grid-template-rows:var(--header-h) 1fr;grid-template-columns:var(--sidebar-w) minmax(0,1fr);grid-template-areas:"header header" "sidebar workspace";min-height:100vh;width:100%;background:var(--shell-bg)}.app-header{grid-area:header;display:flex;align-items:center;gap:12px;padding:8px 16px;height:var(--header-h);width:100%;min-width:0;background:var(--shell-bg);position:sticky;top:0;z-index:40}.brand-area{display:flex;align-items:center;gap:10px;min-width:calc(var(--sidebar-w) - 16px);flex-shrink:0}.menu-toggle-btn{display:none;align-items:center;justify-content:center;width:44px;height:44px;border:none;border-radius:50%;background:transparent;color:var(--ink-secondary)}.menu-toggle-btn:hover{background:var(--surface-variant)}.brand-link{display:inline-flex;align-items:center;gap:10px;color:var(--ink-secondary);text-decoration:none;padding:4px 8px;border-radius:var(--radius-sm)}.brand-link:hover{text-decoration:none;background:var(--surface-variant)}.brand-name{font-size:21px;font-weight:500;letter-spacing:-0.01em;color:var(--ink-secondary)}.search-form{flex:1 1 0%;min-width:0;max-width:720px;display:flex;align-items:center;gap:8px;background:var(--surface-variant);border-radius:var(--radius-pill);padding:0 10px 0 14px;height:48px;border:1px solid transparent;transition:background-color 0.15s ease,box-shadow 0.15s ease}.search-form:focus-within{background:var(--surface);box-shadow:var(--shadow-sm);border-color:var(--border)}.search-icon{color:var(--ink-secondary);flex-shrink:0;display:inline-flex}.search-input{flex:1;min-width:0;border:none;background:transparent;font-size:15px;color:var(--ink);outline:none;height:100%}.search-input::placeholder{color:var(--ink-secondary);opacity:1}.search-clear-btn{display:none;align-items:center;justify-content:center;width:36px;height:36px;border:none;border-radius:50%;background:transparent;color:var(--ink-secondary)}.search-clear-btn.visible{display:inline-flex}.search-clear-btn:hover{background:var(--surface-variant)}.header-actions{margin-left:auto;display:flex;align-items:center;gap:6px}.fw-badge{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:var(--radius-pill);background:var(--surface);border:1px solid var(--border);font-size:12px;font-weight:600;color:var(--ink);white-space:nowrap}.fw-dot{width:8px;height:8px;border-radius:50%;background:#137333}.icon-btn{display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border:none;border-radius:50%;background:transparent;color:var(--ink-secondary);transition:background-color 0.12s ease;text-decoration:none}.icon-btn:hover{background:var(--surface-variant);text-decoration:none;color:var(--ink)}.icon-btn[aria-pressed="true"]{background:var(--active-bg);color:var(--active-fg)}.user-avatar{width:36px;height:36px;border-radius:50%;background:var(--primary);color:#ffffff;font-weight:600;font-size:14px;display:inline-flex;align-items:center;justify-content:center;border:2px solid #ffffff;box-shadow:0 0 0 1px var(--border);flex-shrink:0}.app-sidebar{grid-area:sidebar;padding:8px 12px 20px;display:flex;flex-direction:column;gap:12px;background:var(--shell-bg)}.new-btn-wrap{position:relative}.new-btn{display:inline-flex;align-items:center;gap:12px;height:56px;padding:0 22px 0 16px;border:none;border-radius:var(--radius-lg);background:var(--surface);color:var(--ink);font-size:14px;font-weight:500;box-shadow:var(--shadow-sm);transition:background-color 0.15s ease,box-shadow 0.15s ease}.new-btn:hover{background:#edf2fa;box-shadow:var(--shadow-md)}.nav-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:2px}.nav-link{display:flex;align-items:center;gap:14px;height:38px;padding:0 16px;border-radius:var(--radius-pill);color:var(--ink);font-size:14px;font-weight:500;text-decoration:none;transition:background-color 0.12s ease}.nav-link:hover{background:var(--surface-variant);text-decoration:none}.nav-link[aria-current="page"]{background:var(--active-bg);color:var(--active-fg);font-weight:600}.nav-link svg{flex-shrink:0}.sidebar-divider{border:none;border-top:1px solid var(--border);margin:6px 8px}.storage-box{padding:8px 16px;display:flex;flex-direction:column;gap:8px}.storage-header{display:flex;align-items:center;gap:12px;font-size:14px;font-weight:500;color:var(--ink)}.storage-bar-track{width:100%;height:6px;background:var(--border);border-radius:var(--radius-pill);overflow:hidden}.storage-bar-fill{height:100%;background:var(--primary);border-radius:var(--radius-pill);min-width:4px;transition:width 0.25s ease}.storage-text{font-size:12px;color:var(--ink-secondary)}.storage-cta{display:inline-flex;align-items:center;justify-content:center;padding:7px 14px;border:1px solid var(--border-strong);border-radius:var(--radius-pill);background:transparent;color:var(--primary);font-size:13px;font-weight:500;width:fit-content;text-decoration:none}.storage-cta:hover{background:#edf2fa;text-decoration:none}.workspace-container{grid-area:workspace;display:flex;gap:12px;padding:0 16px 16px 0;min-width:0}.workspace-main{flex:1;min-width:0;background:var(--surface);border-radius:var(--radius-lg);display:flex;flex-direction:column;position:relative;min-height:calc(100vh - var(--header-h) - 16px);border:1px solid #e6ebf1}.workspace-toolbar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;padding:16px 20px 10px}.breadcrumb-nav{display:flex;align-items:center;flex-wrap:wrap;gap:4px;min-width:0}.breadcrumb-title{margin:0;font-size:22px;font-weight:400;color:var(--ink);line-height:1.3;display:flex;align-items:center;flex-wrap:wrap;gap:4px}.breadcrumb-link{color:var(--ink-secondary);padding:4px 10px;border-radius:var(--radius-pill);font-size:20px;text-decoration:none}.breadcrumb-link:hover{background:var(--surface-hover);text-decoration:none;color:var(--ink)}.breadcrumb-current{padding:4px 8px;color:var(--ink);font-weight:500;font-size:22px}.breadcrumb-sep{color:var(--ink-secondary);font-size:18px}.toolbar-right{display:flex;align-items:center;gap:8px}.view-toggle{display:inline-flex;border:1px solid var(--border-strong);border-radius:var(--radius-pill);overflow:hidden;height:36px}.view-toggle-btn{display:inline-flex;align-items:center;justify-content:center;gap:4px;padding:0 14px;border:none;background:transparent;color:var(--ink);font-size:13px}.view-toggle-btn + .view-toggle-btn{border-left:1px solid var(--border-strong)}.view-toggle-btn[aria-pressed="true"]{background:var(--active-bg);color:var(--active-fg);font-weight:600}.view-toggle-btn:hover:not([aria-pressed="true"]){background:var(--surface-hover)}.filter-bar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;padding:4px 20px 12px;border-bottom:1px solid var(--border)}.filter-chips{display:flex;flex-wrap:wrap;gap:8px}.chip-btn{display:inline-flex;align-items:center;gap:6px;height:32px;padding:0 14px;border:1px solid var(--border-strong);border-radius:var(--radius-sm);background:var(--surface);color:var(--ink);font-size:13px;font-weight:500}.chip-btn:hover{background:var(--surface-hover)}.chip-btn[aria-pressed="true"]{background:var(--active-bg);border-color:var(--active-bg);color:var(--active-fg);font-weight:600}.sort-controls{display:flex;align-items:center;gap:8px}.sort-select{height:32px;padding:0 10px;border:1px solid var(--border-strong);border-radius:var(--radius-sm);background:var(--surface);color:var(--ink);font-size:13px}.selection-bar{display:none;align-items:center;gap:8px;margin:10px 20px 4px;padding:6px 14px;background:var(--surface-hover);border-radius:var(--radius-pill);flex-wrap:wrap}.selection-bar.visible{display:flex}.selection-label{font-weight:600;font-size:13px;color:var(--ink);margin-right:6px}.action-pill-btn{display:inline-flex;align-items:center;gap:6px;height:32px;padding:0 12px;border:none;border-radius:var(--radius-pill);background:transparent;color:var(--ink);font-size:13px;font-weight:500}.action-pill-btn:hover{background:var(--surface-variant-hover)}.action-pill-btn.danger{color:var(--danger)}.action-pill-btn.danger:hover{background:var(--danger-bg)}.trash-banner{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:12px 20px 0;padding:12px 16px;background:var(--surface-hover);border-radius:var(--radius-md);font-size:13px;color:var(--ink)}.files-area{flex:1;padding:8px 20px 24px}.drive-table{width:100%;border-collapse:collapse;table-layout:fixed}.drive-table th{text-align:left;font-size:13px;font-weight:600;color:var(--ink-secondary);padding:10px 12px;border-bottom:1px solid var(--border);white-space:nowrap}.drive-table th.col-name{width:46%}.drive-table th.col-owner{width:20%}.drive-table th.col-updated{width:16%}.drive-table th.col-size{width:10%}.drive-table th.col-actions{width:8%;text-align:right}.drive-row{border-bottom:1px solid #eef1f5;transition:background-color 0.1s ease}.drive-row:hover{background:var(--surface-hover)}.drive-row.selected{background:var(--selected-bg)}.drive-row td{padding:8px 12px;height:48px;vertical-align:middle;color:var(--ink-secondary);font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.item-name-cell{display:flex;align-items:center;gap:12px;min-width:0}.item-link{color:var(--ink);font-weight:500;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-decoration:none;display:inline-flex;align-items:center;gap:6px}.item-link:hover{text-decoration:underline}.item-sub-mobile{display:none;font-size:12px;color:var(--ink-secondary)}.badge-inline{display:inline-flex;align-items:center;color:var(--ink-secondary);flex-shrink:0}.badge-star{color:#b06000}.row-actions-cell{text-align:right}.kebab-btn{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border:none;border-radius:50%;background:transparent;color:var(--ink-secondary)}.kebab-btn:hover{background:var(--surface-variant-hover);color:var(--ink)}.grid-section-title{margin:14px 0 10px;font-size:13px;font-weight:600;color:var(--ink-secondary)}.folder-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px;margin-bottom:16px}.folder-card{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 8px 8px 14px;height:52px;background:var(--surface-hover);border-radius:var(--radius-md);border:1px solid transparent;transition:background-color 0.12s ease}.folder-card:hover{background:var(--surface-variant)}.folder-card.selected{background:var(--selected-bg);border-color:var(--primary)}.folder-card-main{display:flex;align-items:center;gap:10px;min-width:0;flex:1;color:var(--ink);font-weight:500;text-decoration:none}.folder-card-main span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.file-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px}.file-card{display:flex;flex-direction:column;background:var(--surface-hover);border-radius:var(--radius-md);padding:10px;border:1px solid transparent;transition:background-color 0.12s ease}.file-card:hover{background:var(--surface-variant)}.file-card.selected{background:var(--selected-bg);border-color:var(--primary)}.file-card-header{display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:8px}.file-card-title{display:flex;align-items:center;gap:8px;min-width:0;flex:1;font-size:13px;font-weight:500;color:var(--ink)}.file-card-title span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.file-card-thumb{height:132px;background:var(--surface);border-radius:var(--radius-sm);border:1px solid var(--border);display:flex;flex-direction:column;align-items:center;justify-content:center;overflow:hidden;padding:8px;gap:6px}.file-card-thumb img{width:100%;height:100%;object-fit:cover;border-radius:4px}.file-card-meta{display:flex;justify-content:space-between;margin-top:8px;font-size:12px;color:var(--ink-secondary)}.hidden{display:none !important}.details-panel{width:var(--details-w);flex-shrink:0;background:var(--surface);border-radius:var(--radius-lg);border:1px solid #e6ebf1;padding:18px;display:none;flex-direction:column;gap:14px;height:fit-content;max-height:calc(100vh - var(--header-h) - 16px);overflow-y:auto}.details-panel.open{display:flex}.details-header{display:flex;align-items:center;justify-content:space-between;gap:8px;border-bottom:1px solid var(--border);padding-bottom:12px}.details-header h2{margin:0;font-size:16px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.details-dl{margin:0;display:grid;grid-template-columns:96px 1fr;gap:8px 10px;font-size:13px}.details-dl dt{color:var(--ink-secondary);font-weight:500}.details-dl dd{margin:0;color:var(--ink);word-break:break-word}.activity-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:10px}.activity-item{font-size:12px;padding:8px 10px;background:var(--shell-bg);border-radius:var(--radius-sm)}.activity-item time{display:block;color:var(--ink-secondary);margin-top:2px}.empty-state{text-align:center;padding:56px 20px;color:var(--ink-secondary)}.empty-state h2{margin:12px 0 6px;font-size:18px;color:var(--ink);font-weight:500}.drop-overlay{position:absolute;inset:10px;border:2px dashed var(--primary);border-radius:var(--radius-lg);background:rgba(232,240,254,0.92);display:none;flex-direction:column;align-items:center;justify-content:center;gap:10px;z-index:50;pointer-events:none}.drop-overlay.active{display:flex}.drop-overlay p{margin:0;font-size:16px;font-weight:600;color:var(--primary)}.menu-popover{position:fixed;min-width:224px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-md);box-shadow:var(--shadow-lg);padding:6px 0;z-index:120;display:none}.menu-popover.open{display:block}.menu-item{width:100%;display:flex;align-items:center;gap:12px;padding:10px 16px;border:none;background:transparent;color:var(--ink);font-size:14px;text-align:left}.menu-item:hover{background:var(--surface-hover)}.menu-item.danger{color:var(--danger)}.menu-sep{height:1px;background:var(--border);margin:4px 0}.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,0.45);display:none;align-items:center;justify-content:center;padding:16px;z-index:200}.modal-backdrop.open{display:flex}.modal-dialog{width:100%;max-width:460px;background:var(--surface);border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);padding:24px;display:flex;flex-direction:column;gap:16px}.modal-dialog h2{margin:0;font-size:20px;font-weight:500}.form-field{display:flex;flex-direction:column;gap:6px}.form-field label{font-size:13px;font-weight:600;color:var(--ink-secondary)}.text-input,.textarea-input{width:100%;padding:10px 12px;border:1px solid var(--border-strong);border-radius:var(--radius-sm);font-size:14px;color:var(--ink);background:var(--surface)}.modal-footer{display:flex;justify-content:flex-end;gap:10px}.btn-primary{height:38px;padding:0 20px;border:none;border-radius:var(--radius-pill);background:var(--primary);color:#ffffff;font-weight:600;font-size:14px}.btn-primary:hover{background:var(--primary-hover)}.btn-secondary{height:38px;padding:0 18px;border:1px solid var(--border-strong);border-radius:var(--radius-pill);background:var(--surface);color:var(--primary);font-weight:600;font-size:14px}.btn-secondary:hover{background:var(--surface-hover)}.btn-danger{height:38px;padding:0 18px;border:none;border-radius:var(--radius-pill);background:var(--danger);color:#ffffff;font-weight:600;font-size:14px}.preview-backdrop{position:fixed;inset:0;background:rgba(15,17,21,0.9);display:none;flex-direction:column;z-index:250}.preview-backdrop.open{display:flex}.preview-topbar{height:60px;display:flex;align-items:center;justify-content:space-between;padding:0 16px;color:#ffffff;background:rgba(0,0,0,0.35)}.preview-title-wrap{display:flex;align-items:center;gap:12px;min-width:0}.preview-title{margin:0;font-size:16px;font-weight:500;color:#ffffff;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.preview-topbar .icon-btn{color:#ffffff}.preview-topbar .icon-btn:hover{background:rgba(255,255,255,0.14);color:#ffffff}.preview-body{flex:1;display:flex;align-items:center;justify-content:center;padding:20px;overflow:auto}.preview-doc{width:100%;max-width:860px;max-height:100%;overflow:auto;background:#ffffff;color:var(--ink);border-radius:var(--radius-md);padding:24px;font-family:var(--font-mono);font-size:13px;line-height:1.65;white-space:pre-wrap;word-break:break-word;box-shadow:var(--shadow-lg)}.preview-img{max-width:100%;max-height:82vh;border-radius:var(--radius-sm);background:#ffffff;box-shadow:var(--shadow-lg)}.preview-pdf{width:min(900px,100%);height:80vh;border:none;border-radius:var(--radius-sm);background:#ffffff}.toast-container{position:fixed;bottom:24px;left:24px;z-index:300;display:flex;flex-direction:column;gap:8px}.toast{background:#202124;color:#ffffff;padding:12px 18px;border-radius:var(--radius-sm);font-size:14px;box-shadow:var(--shadow-lg);max-width:360px}.semantic-footer{margin-top:auto;padding:16px 20px;border-top:1px solid var(--border);background:#fafcff;border-radius:0 0 var(--radius-lg) var(--radius-lg);font-size:12px;color:var(--ink-secondary);display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px}.semantic-links{display:flex;flex-wrap:wrap;gap:8px}.semantic-links a{display:inline-flex;align-items:center;min-height:32px;padding:4px 8px;color:var(--primary);font-weight:500;border-radius:var(--radius-sm)}.semantic-links a:hover{background:var(--surface-variant);text-decoration:none}.content-page{padding:24px 28px 36px;max-width:920px}.content-page h1{margin:0 0 12px;font-size:26px;font-weight:600;color:var(--ink)}.content-page h2{margin:28px 0 10px;font-size:19px;font-weight:600;color:var(--ink)}.content-page h3{margin:18px 0 6px;font-size:15px;font-weight:600;color:var(--ink)}.content-page ul{list-style:none;padding:0;margin:12px 0;display:flex;flex-direction:column;gap:6px}.content-page ul li a{display:inline-flex;align-items:center;min-height:38px;padding:6px 10px;border-radius:var(--radius-sm);color:var(--primary);font-weight:500}.content-page ul li a:hover{background:var(--surface-hover);text-decoration:none}.tldr-box{background:#edf2fa;border-left:4px solid var(--primary);padding:14px 18px;border-radius:0 var(--radius-sm) var(--radius-sm) 0;margin:14px 0 20px;color:var(--ink)}.spec-table{width:100%;border-collapse:collapse;margin:14px 0 20px;font-size:13px}.spec-table th,.spec-table td{border:1px solid var(--border);padding:10px 12px;text-align:left}.spec-table th{background:var(--shell-bg);color:var(--ink);font-weight:600}.faq-item{border:1px solid var(--border);border-radius:var(--radius-md);padding:14px 16px;margin-bottom:10px;background:var(--surface)}.faq-item summary{font-weight:600;color:var(--ink);cursor:pointer;min-height:28px;display:flex;align-items:center}.faq-item p{margin:8px 0 0;color:var(--ink-secondary)}.move-folder-list{display:flex;flex-direction:column;gap:6px;max-height:260px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius-sm);padding:8px}.move-folder-option{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:var(--radius-sm);cursor:pointer}.move-folder-option:hover{background:var(--surface-hover)}.drawer-scrim{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:80}.drawer-scrim.open{display:block}.mobile-bottom-nav{display:none;position:fixed;left:0;right:0;bottom:0;height:64px;background:var(--shell-bg);border-top:1px solid var(--border);z-index:60;grid-template-columns:repeat(4,1fr);padding:4px 6px}.mobile-nav-item{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;font-size:11px;font-weight:500;color:var(--ink-secondary);text-decoration:none;border-radius:var(--radius-md);min-height:48px}.mobile-nav-item:hover{text-decoration:none}.mobile-nav-item[aria-current="page"]{color:var(--active-fg);font-weight:700}.mobile-nav-pill{display:inline-flex;align-items:center;justify-content:center;width:56px;height:28px;border-radius:var(--radius-pill)}.mobile-nav-item[aria-current="page"] .mobile-nav-pill{background:var(--active-bg)}.mobile-fab{display:none;position:fixed;right:16px;bottom:80px;width:56px;height:56px;border:none;border-radius:16px;background:var(--active-bg);color:var(--active-fg);box-shadow:var(--shadow-md);align-items:center;justify-content:center;z-index:65}@media (max-width:1024px){.drive-table th.col-owner,.drive-table td.col-owner{display:none}.drive-table th.col-name{width:58%}.fw-badge{display:none}}@media (max-width:768px){.app-shell{grid-template-columns:minmax(0,1fr);grid-template-areas:"header" "workspace"}.app-header{padding:8px 10px;gap:6px}.brand-area{min-width:0;gap:2px}.brand-link{padding:2px 4px}.brand-name{display:none}.menu-toggle-btn{display:inline-flex;width:40px;height:40px}.search-form{height:44px;padding:0 8px 0 12px;gap:6px}.search-input{font-size:14px}.header-actions{gap:4px;flex-shrink:0}.header-actions .hide-mobile{display:none}.app-sidebar{position:fixed;top:0;left:0;bottom:0;width:280px;max-width:82vw;z-index:90;transform:translateX(-105%);transition:transform 0.2s ease;box-shadow:var(--shadow-lg);padding-top:16px;overflow-y:auto}.app-sidebar.open{transform:translateX(0)}.workspace-container{padding:0 0 80px;width:100%}.workspace-main{border-radius:0;border-left:none;border-right:none;width:100%;min-height:calc(100vh - var(--header-h) - 64px)}.workspace-toolbar{padding:12px 12px 8px;gap:8px;flex-wrap:nowrap}.breadcrumb-current,.breadcrumb-title{font-size:18px}.breadcrumb-link{font-size:15px;padding:4px 6px}.view-toggle-btn{padding:0 10px;font-size:12px}.filter-bar{padding:4px 12px 10px;gap:8px}.filter-chips{flex-wrap:nowrap;overflow-x:auto;max-width:100%;padding-bottom:2px;-webkit-overflow-scrolling:touch;scrollbar-width:none}.filter-chips::-webkit-scrollbar{display:none}.chip-btn{flex-shrink:0;height:32px;padding:0 12px;font-size:12px}.sort-controls{width:100%;justify-content:flex-end}.files-area{padding:4px 8px 20px;width:100%}.drive-table{width:100%;table-layout:fixed}.drive-table thead{display:none}.drive-table td.col-owner,.drive-table td.col-updated,.drive-table td.col-size{display:none}.drive-table td.col-name{width:auto;max-width:0;padding:8px 4px 8px 6px}.drive-table td.col-actions{width:48px;padding:4px}.drive-row td{height:58px}.item-name-cell{flex-wrap:nowrap;gap:10px;min-width:0}.item-name-cell>svg{flex-shrink:0}.item-text-stack{display:flex;flex-direction:column;min-width:0;flex:1}.item-link{max-width:100%;font-size:14px}.item-sub-mobile{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.kebab-btn{width:40px;height:40px}.details-panel.open{position:fixed;inset:auto 0 0 0;width:100%;max-height:75vh;border-radius:20px 20px 0 0;z-index:150;box-shadow:var(--shadow-lg)}.mobile-bottom-nav{display:grid}.mobile-fab{display:inline-flex}.toast-container{bottom:76px;left:12px;right:12px}.content-page{padding:18px 16px 32px}}@media (prefers-reduced-motion:reduce){*,*::before,*::after{transition:none !important;animation:none !important}}<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64" role="img" aria-label="CloudDrive">
  <rect width="64" height="64" rx="14" fill="#ffffff"/>
  <path d="M23.5 11h17l15.5 27-8.5 14.5-15.5-27L23.5 11z" fill="#fbbc04"/>
  <path d="M8 38l8.5 14.5h31L56 38H24.5L8 38z" fill="#1a73e8"/>
  <path d="M23.5 11L8 38l8.5 14.5L32 25.5 23.5 11z" fill="#34a853"/>
  <path d="M24.5 38l-8 14.5h15.5L24.5 38z" fill="#1967d2"/>
</svg>‰PNG

   IHDR   À   À   RÜl   	pHYs  Ä  Ä•+  {IDATxÚí=lUeÇŸÓro(š&¥'áCŒ€e*.€¦š¶®]TDLZV4â¬Ñˆ$20Ðèê`L “Mü($°7"-mèuÀs{n{?Î½÷|<Ïóþ~4„œô½¿ÿÿ}Ÿ÷¢F£Ñ€@á[  €   €   €   €   €   €   €   €   €   €   €   €   €   €   €   €   €   €  y±‡oAv®þô‘Ìýöµúçœ¸xG¢ñŒÈ»«+òýÚªüQ? ú9ëñˆl=ü‚C€|¹¶²(""7ÇOª~ÎZ<"ÇKÒx¼Ä¢!@~éwuEDD~¯PÛõx{9iÈ=ýnzUmú'ÐÛ‡?Iÿ„åÍ	¹9®K‚tú'<[~›D€áøöÏÅ¶_×v¨Åí—’­ä¶õÙÉ­ÃSjÓ¿)Àƒ+,$ä›þ	7FOÈž"µéO ÀÀ\¼ýY¦?WõY ÷^Â­W8#@vÒcÏ^Ü=QéX´g[BZ rÛûï:T4­ÇÙ—±(äžþ	Ë›ò×ä¤Úô§ °ôß>”;­Çý/-€ =?üý¦ºî¿|Pmú'p9† é5öìÅ'[¯—2­ÇÃ-[!Èmë³{+Tü¸6¬ \Ž!@ÞéŸPôåØ°éO À.²^zeeñPq¯HÔò€Ë1lìÙ‹ïþ=ž[R‘þ´ ä¾÷ßÉÂØjÓ?±hà‘þ	yE‹hZ pòÞûy ®$@ãñRÐ+@Q[Ÿ-Ç+E¥ú@Œ ‘×Ø³Œ¨ÅÅ/S¨-¤ e¤º†‹Öãr–(ÔN€»«+¥¥Â0cÑZ\Þ…øžPp”™þiyE¢—»<!ŽEƒ È±g–ØrTmú‡zJ€ªÒû@|2ó¸W³4¡µ@0T™þéÈ:­ÅÕ-MH-Œ E_zeoÞcÑz\í²„t9„ Uo}Ò,oNÈýcÕ¦³‹!@ÙcÏ^\ï2­Çz–$„p/€¦ôO·À½gš&h×Tqé••ÿ9·k,ª)ý¼_Ž¹@cú·ˆ[Ç¢5…x‹º@ÃØ³éW$4¦g·hOÿ-PS,€çp)€…ôO·Àþ£êŸÓk¸@Ë¥WVF§QÿŒ^/ÇÜ	`eë“páÔœDã3&þckcQwh{vâý©¹çqô²‰çõÖ®°–þß¼õyó×´ }ðµ”þ§'§äôdëJZiO—cn°–þÉÖ'M4>##/}jâ@ìe,êB KcÏNéo­¼œ\à!ý[…@ ¯éáÔ\Çô§ o¬]zõJk-`]ÓX¼ôÊ¼0G/3E€îX½ôêG¶B\ú'p9† ¾Ö.½úMk-`õrÌ¤ ÞÆžZÀêXÔœ ž.½²báui«gs„”þ-Åå\[Y.ý9 @kcÏôëÎ¡´€µ­B{ziKcQ3x¿ôâ@Œ ±ö¾OéŸ`érÌÂX½ ÇžE¥¿¹­P/@¨cO-`a,ªZ€/½h ý3¶ —cŽùÒË[h¾S+@è—^™Ë1péåð, ôrL¥ \zÑÁ
À¥×`-Àå˜¸ô
`+¤¬T	ÀØsp¸3. —^´@ìÑò _.]—'¯™àIã°|õóSeOõšl=œ7ñý;÷â†œ9¶Ò‰zûÞ¯f¸sïœ,Õ×>™.}°WÅs¨Ù-Ì¼kæÃ­ÏŠ<Ûy¶)0ÀZŸãÐ6Þ|ÏÆ
®Í>?Ðm<åÓ<HGß‡ Zàì‘iÝéÿèÒöoh¾ùáÃq]ƒuõ¨y+´q\dã•–/ÑÙ9sl¯Šƒ¯jÎ™VÛÑÿ[Ÿh>öþûÔ=“ÊwT¶@›ôo‘ Ì¥¿Z4¶@Ûôgd:ýÕ
 "òã;Wõ|ø×g;§³! ó‡Leú«@DÑX´KúÓ½™WšþêÐpˆÖg³ÿa$h›þšQÿÏ¢TºÚ8ž)ýiÎ_ÍéoB€*ÄQ~ZÀÎÁ×” •m…º=»µ wÍô×zð5'@-0Pú‹p1f(ýÍPz˜þœl¥¿)Î™.m,=Zî/¼´½ðæB€²Z ¯±'-Ðfë3fêyÍý'y…·ÀZ>„ÚóFöþf(ògZÞõÏK¶>`b+4äÁ7ôm¥ƒ¯yŠ‹Fym}v‰†Æ¶>¦È½
HÿZÀjú› Ï±èÐcÏÀ[ÀâÞß¼ yµ@^cÏP[ÀÚØÓ• "9ŒE×fËyP§ÌÝû»`˜(#ý›-àðNÀzú»@dÀŸèó]ÿ¡qv1fá]ÿ`d,•ùáwxXpðáw#@ß[¡Çž!´€å±§[úi*ÒßSxIWd=dú'Nh®_/éïN ‘cÑ
Ó¿E£Ì;J—t;”9öô¸ò0öt/@×X›Õó%ð–þnh÷3¹¿ëXX~ß'8vm…ª{:iOcÏ`HE#M[ƒ-°àpëã^€fhM#-à9ýED¢F£Ñ€@á[  €   €   €   €   €   €   €   €   €   €   €   €   €   €   €   €   €   €   €  Ãñ¤e¥¡wƒ¦    IEND®B`‚‰PNG

   IHDR   À   À   RÜl   	pHYs  Ä  Ä•+  IDATxÚíœÍk\e‡Ï$åŽñ#…$"Ò4	*³2“Š­Y
‚®"î”íÂ…R¥þºA°iƒ‚¸‰.\Œh®â¶*‰µLIÇE{‡d’IîÌÜû~œó<ËReš÷}Îïœsï$¹×Ük	€Qjü                                                                          1Ì 6ÿþCî®¿&/üwËÛgHç_•SoüÀa îùúæwríô¬×ÏPýI’68p_ý7oý.[é„üVŸðsù³‡Ç—l_å@À}õÏñ•i.ÀR ÜWÿœ­tBnNNz©þ9¤ 8ãƒOýÙ¥Ç½Tÿ¶ w6¤¶ý9‡ƒ îZŸN®žóRýID€Y~2q^ý()€ •µ>?~vâß©:êÙñG–l_e F€êßn|3t®ÒH³“ŒVœ¶>\®h ®gÅŽ‹µ(x©þ9U=K³âÇE
 €—êŸ³~¦ÜY žõvT¤ ”vù{©þ9¿Ü/õáXšõ~Tµ__ç À}õÏ)ë‰zÖÿ1±E /—?Oõ©y/ÕŸY ¼ 2øZtêO
 @ßyèU¼ê NK€‡cÐ½®=«J2ª?­xi}:ùè™%/Õ¿- kQðQý÷Ä½¬EË¬þ¤ ïýx×¿¼Y øZ4­B ¾3€ ®[ŸÎøóù§½TR ¼Pt N³j…@ /—¿È,PÏª?R ¾®Y}ðJ×H37GRÛâ=!p\ýÄs^ªÿþ˜µ(T¶ö,2t^ø4s{´Bà¥ú•õÌýQÆðUýs¾º;ÓˆÓÌÏQXÿÎ€iª|èU<få‰Ù!¿—ÀðZÔ¬ >[Ÿý´ÆÎËÐ³ç½~Ë³ xfeöMiM}âÿ"M“”ù®ÿ 4&æ¥1>'­§–¤5¶äõ³XýÎ€9|¾Õ?çÁ‹ß{ÿ<[!s„Ôú4Æ>óÝ
Y\‹š ÔêßN©I°Qý»A
 @e—?äêR
Xz8fJ€Ð/[‚—üÄVÖ¢5.¿;ó…e-Š Jß˜ª(³€ˆï¨ ¤êß¹ö>Äª}íC
ho…Tkõ?¬E öêÿÅâ•¾ÿ[Ž!@_„ð®¿­Oh­æ_¨¥R€PZŸ²  JÊ¸üm	x8† ±¾e
ÀÃ1(4øj¬þ¡Ì"úŽ© öµg) l-ªF€ØzÅ”šZ!5h¯þ¤ {ù-Tÿœ¾?¬å;jåòWYýƒˆ¬Ek\þ¸ª;x8† ¡U×%H|¯¹×ŠõÃÿü×¦¼õíûaü w®H"±ÆÂôˆ¬½7FxùáŸiÈÂT —®9#²›‰EV/ŒÒùäÃÅw¨þËDø×\õyz|§€W	š3"Í³{rcÄ^ýUð0VüUÿÛ—:„°!ÁêÅÑè«¿|µBÉîò¡?³’«SñïP$ÀŠûxg¹K[¤[‚ëo}Ô
à:ŽªþR@Ãà«V gkÑæL÷êŸ³wŸÁÜsãí/«¯þ']~¥) ­ú« òVhßÚóøhªK5E½¿rª[‹©þS`õâ¨Æ«¢÷÷Ýx§üV(Ù].Vý•¥ÀÂôˆšµ§*ˆ{¨þšR@ÛàkB€²gãÖžšS@ãàkF€ÒÞ*²ö<Iª?Ä:'ƒ\þˆÛ -ïû˜`àV¨èÚóÄÿO|h|
Ðÿ{BƒVÿXSàºÂ¿YúMž×žJR@ûàkR€¾Ö¢%UÿØR@ûàkR ‘ÞÞê{íy
Xªþæè©Ú©F€ÐS`ÍHïoX€“×¢ÉíËÕ~ˆ@Œi}ß:[¡ãÞ*kíY
h~ßzˆ“ŠZŸƒ	Þë–_ótTÿSÀÚà‹ rô{B‡~Å‰‘°ZýMÐ9W¶ö<,¼ïƒ EZ¡÷„ßý#ÀŠHó¬$ÍI?`hDdè”Ÿ6ð¹ëÇ÷¯G @   @   @   @   @   @   àG  €   €   €   €   €   €   €   €   €   €   €   €   €   €   €   €   €   €   Pÿ[hsð/Š    IEND®B`‚‰PNG

   IHDR         ôxÔú   	pHYs  Ä  Ä•+  vIDATxÚíÝÏ«]g¹ðµ“PZLŠ’8(ÔjÅ¤M'¶£6-QÎÎHÈÀ‰m±fP0™jQÿ†¢hfÐAK:J'Î°ê8JHÌ ¡Ùì>Ù9Ùg¯ßk½Ïû~>³Û{{ïuAöú>ßõ>oËårY E9ä €     €     €     €     €     €     €     €     €                                                       €     €     €     €     €     €     €     €                                                        €     €     €     €     €     €     €     €     €   €G                                        ÐÒ ööRÿË¯=ˆˆ?n/ª/ÿ£j±sÎÃ  4÷Éµ«Õïo^«þúØ	# Çž8TÝû÷¯<  ß]ý ªªªzoç#àôäØ¢Z~öqµüìc h>ýríjUUUõé£'´ §ÿ-  Ðzú_yÿÉg=”`ÓÿŠ  hüò_Mÿ+Wî¯ÞÛ¢Mÿ+Ÿ_ù® l÷Û¿}°ñŸ;oú_çS  °uúßæý¯žñ‚Mÿ{à_¿ô€@ €vÓÿÊåÃ§«#/<¨`Ó¿  8Ð?z«ÑÿÜ¥£¯xXÁ¦ÿõÀ@ `ÏúÚ_k1§-  ðºoÿûYŒ7ý¯X ZOÿ+Wî¯þqò¤‡lú×€  §ÿ•‹Î„›þµ   ÀÞË¿íô¿ÎZ`¼éÅå@ P°ºµ¿:ÖãMÿë|
@ €B§ÿ!¸"8Þô¿ \„  ¦ÿ>-€µÀxÓ¿  
ÔôÒŸ¦¬Æ›þ×[    ]ÖþêXŒ9ýk   C}ûßÏßoú_±ˆ  ¦ÿ^-À?¿ñ9Øô¯@  ÓooÞ{ÙZ`°é_€  ¼üÇšþ×YŒ7ý¯¸ 24ÔÚ_—Å›þ×ù€  ™MÿSúàIWG›þ÷€Ë ÀôßÕ;·OY8ýk  #C_úÓ”µÀxÓÿzà@  6æÚ_—Åœþµ  ©¿ýojŒ5ý¯XD  Ó¿ °é_€  ¦ÿÞ\oú×  @à—ÿÜÓÿ:—Å›þW\„  L½öWçòáÓ³O³¦ÿî|
@ € ÓŠ´ ñ¦ÿ½ àr  0ýwår ˜Ó¿  ˜ëÒŸæ-€µÀhÓÿzà@  $(…µ¿:ÖcNÿZ  HXªßþ÷ó·Æ›þW¬" €é¿Wà@`¼é_€  ¦ÿAZ k±¦-  $øò2ý¯ÓÄ›þW\„  	Huí¯Î;·Oi‚Mÿë|
@ €™§ÿÈ.}ÅÀ`Óÿ^ p9 ˜þ»²sú×  ÀŒR¿ô§©Ò×#q9 L,ÒÚŸ Ïé_€  3ˆþíSPâÀþ3[D  Ó¯ ´µÀ¦-  ˜þ{+m-0§ÿ¬Z  ˜àåŸÛô¿î½g‹8˜Óô¿âr  Qôµ?-@~Óÿ:Ÿ `¤é¿oÞ{9ë Çé/ ¸ Lÿ]å¾˜{Ã¡@ €åréOS¹®æ<ý¯· " À r\ûkÒü=Ã ”--   ”oÿû½›ÙÀ¦ÿk `úïÕä´XÚM‡Z  0ýw–ËZ`IÓ¿  xù—:ý¯»|ø™ð-@‰ÏAU¹ :)eí/÷ ÄéO Ðrúç¾7ï½6”:ýï —! €é¿«¨k¥OÿZ  h¡´KšŠ¸Xúô¿Þ8ˆ  [”¼ö×´ˆr Ðô¯@ €Æ|ûßîÂ³a¦jÓÿƒ¬" €é¿—k¦-  ˜þa-Ðô¯@ €Æ/Ó» Õ—¬é;—! Àkí[€T×Mÿõ|
@ €JõßUŠk¦ÿ†Àå@ `úï*Åµ@Ó¿  qéO>-€é¿}à@  E²ö7Lðé£'’hLÿZ  hÄ·ÿa¤p9é¿k ˜þéeîµ@Ó¿  Lÿ3xçö©êÑ™^Â¦-  4~ù›þ‡÷îíÓ³Lâ¦ÿþ\„ @¬ý×L}9é8>  ýôÏ˜-À©êØ3‡Mÿ€Ë 0ýÓÕ”k¦-  4âÒŸéZ€)&sÓÿ8-€ dÅÚ_^-€é_€  øö?­7ÎVÇNwÀô?k ˜þée¬µ@Ó¿  Lÿ	ër Ó¿  ½üMÿó¶ C®šþ§ãr  B³ö70ä@Óÿ´|
@  ìôO
-À0k¦ÿ€Ë 0ýÓÕPk¦-  ÔréOz-@Ÿµ@Óÿ¼-€ „`í/Í ÏZ é_€  µ|ûOS×µ@Óÿü¬" `ú§—.k¦-  ˜þ3hŽ[4>hú×  @£—¿é?}nœm<Õ›þÓâr  ’dí/†¦k¦ÿ4ù€ @rÓ?q4Y4ý' \„ €éŸ>-À•»Ç|É›þµ  `úÏ¸8h-ÐôŸ~à@  ³úäÚUÓà`ÓZ é_€  ¦ÿÌmZ4ýÇ`-€Y§kñ­_lú×  €é¿ÀÀô¯@  ÓA.Ü8[;}Øô¯@ €íüu¿y¹r÷xµØ9çAm„  &¡úÏÏëÏ¯}ígDÔÀå@ LÁÚ_~^;s¾ZìœÓDZ  Lÿ´ñ›ïüâþ‚@€  û¹ô'?/œ<S½pòÌÞ½Ø9WúúÏ=˜ üm ˜þiäµ3çþQÐ„e-€Q¦kyOÿü0hÂr  Ó?­§ÿõÀ@-  ¦Óf^îüÓÿz@€ @Á\úSÖô¿b-0v   Ð‹ê?Ïé¿ñ„ n`-€>¬ý•9ýk2	Z  LÿTÕƒ—þ4uøù?ypZ  JáÒŸül[û«ý¡°–Ë 0ý®MõÿÐ…³ aYD  ÕôoíÏô¯È‡³  ˜þMÿ½Z µ  ˜þ	¢É¥?mB Z  2äÒÓÿ6Öc· B  ©þóœþÿÑÐÄm¬" °‰µ?Ó¿ € @ ÀôŸ·.—þ4år -  péO~†Xû«ýñ°–Ë 0ýgjŒêÿ¡gÂ²ˆ €µ?Ó¿ PÎ  ˜þ=Ó¯À@-  ¦f6ä¥?mB Z  qééÖc· B€ @aTÿyNÿ³ýhâ¶ Ö ÊbíÏô¯`/h LÿÄ4æ¥?M¹H€ @Â\ú“Ÿ)×þjP¬†år  Ó?ÁÌYý?ôƒâ,@XÖ 2Ÿþ­ý™þÇæS@\Î ˜þ1ýwæ@   Ó?#šãÒŸÆ?,>h HƒKLÿZ š¶ B€ @&TÿyNÿÉÿ¸hâ¶ Ö ò`íÏô?W`-0pÐ ˜þIK
—þh´  $Ì¥?ùIqí¯öGF–Ë Lÿ$"Bõ¯È‡µ@€ Ó¿µ?Ó*\—³  ¦LÿYÔ  `ú§ƒ”/ýiücãS€ €q¹ôÇô¯`è@ Hœê?Ïé?›-@ÜÀZ  @Ú¬ý™þSo¬Z  Ó?Óˆté@€ À\ú“ŸÈkµ?<Z€°\$ `úgd9UÿZ€|X Hlú·ögúÆå@q9  `úÇôß™µ@-  ¦ÖäpéOã Ÿ´  tãÒÓ¿€¹Z !@ `&ªÿ<§ÿâ~„´ q[ k ó°ögúÏ¥°8h Lÿô“ã¥?Z -  réO~JXû«ý1Ò„år  Ó?•XýojŒÉZ  ÀDÓ¿µ?ÓÎ!€˜œ 0ýcúïÌZ   ÓJºôG @  #—þ˜þµ ¤Þ Sýç9ý³™¿((p`-P `XÖþLÿÅý8YŒ´  ¦6+ùÒŸÆ?NÎh JæÒŸüXûÓ”Àå@ ¦öQý·kŒÉZ  @ÏéßÚŸé_ð) *g Lÿ˜þ;³¨@ 0ýšK´ Z  j¹ôÇô —@ hHõŸçôO?.
ÜX hÆÚŸéŸ~°¬ÆZ  Ói\ú3à–³ Z €¹ô'?Öþ´ Üçr  Ó1Tÿã´ Æd-P à€éßÚŸéŸæ!€˜œ 0ý›þéÌZ  ÀôO’\ú£@  PË¥?¦´ ¥µ B€ P<ÕžÓ?Óp9PàÀZ  P:k¦zþˆYŒ´ €éŸ\¸ôg†1g´  ‘¸ô'?ÖþæãS@\. Lÿ„§úŸqY Š›þ­ý™þøÇÌ§€°œ Lÿ˜þÑh LÿÄàÒ- Z €Z.ý1ý3n`-0n  ÙRýç9ý£` ÀZ  +k¦&úaÓÄZ ÀôOê\ú£@Ùb¹\.=†ñ}ïò…êÏÿ¹âAäöèæ[Õ¢ú–úðêÅ§ñ 4 y¸xî‡Bnîœªª[Ox0 Ÿ~ÄË_ ÈËKO=_½ôÔóDVÓÿîAà¿5,½ú%A Ðøôç›UUUÕR  Ó¿ @]péÛ?ò r˜þ¯_Ü„ èëÃ7v<@@Â/ÿ[»ý3- ôüm|õ¨‡  äOÜÍÝÍÿ\€Î~êÛ¿ PJà@`Ðéÿú¥ÿ{ËÏïz@Ðê_ (.ÌÚÁ¿>¿SUB ´âàŸ Pk§ÿƒªÿõÀg h7©þ - ¡§- ˜þ Ú´ ™þ÷¯ýi 7ßþ - i¿üoí¶û´ PÿÛgíO ÀZ`ònî¶þW´ °µ?€ÊZ`ÒÓÿ–µ¿Ú ØHõ/ °/˜¦ÿü÷µ °Ÿƒ ûXLpúïPý¯ó 6;ª -@ÖÓ¿ Lÿ mZ ™þ[¬ýi ßþ ´ i¿üoíû¿P k MhfvsØ   k [ gšþ»®ýÕq1Sý ´Ll¨ƒZ ØãàŸ @KÖg˜þ®þl \L¡ÃŒê_ @Pêô¯Àô @ëÀÀ‰¦ÿÖþ´ pŸoÿ Z€´_þ·v'û¿¥ ˜ß.k ýiFvsº   Öþ ´ ¦ÿM! Lÿ 4ñ‡¼í!íÎ©i§ÿ/ø@Î^|úÓ¿ À¬Ž0ýÏðò¿>„ rþ½ü †ÿƒåSÀ°ÓÿÈkZ Jœþ­ý	 hLÿZ Lÿ ÅY€^þ·vgþµ äùò?jú ›µÀžR˜þµ dÆÁ?€)’¶³ ý¦ÿ„,Ý	@&Ó? ñ) Ë´}*­é¿ª\DxÖþ &æ@`‡é?µ—ÿªð€ÐÓ¿—¿ Àôð|
h7ý'pðO@nÓ¿ƒ Z Ó¿ Ó? Sq ÁË?‘µ?- y½ü­ý	 ÌÎZ`Ä§ÿB áàŸ @
IÜY€íÓ>iúG  >lâÚ_íÿÏB i³ö' 7LÿÑ^þZ BLÿ^þ éýÁô)àÁé?õƒZ Nÿþ	 hLÿZ Lÿ ¤ÂY€ kZ Â½ü­ý	 $¯øµÀÀÓ¿€T9ø' !©| ÒÚ_-‘Ðô @E~
ˆ¸ö§ qÖþ ‚)ñ@à"£—ÿÿ ×“Âôïå/ ïnIŸ"¯ýiHxúwðO @`ú×`úG  ŠÎd±ö§ ¹—¿µ?€ð²_Ìuú_o`bþ	 ää3>ÕÚß6Z &žþ ÈD–Ÿ2[ûÛÆg ¦bíO  39\òò×0íôïå/ ßìœ>dºö§`îéßÁ? -€é_€é€\äp ÷µ?- ó¼ü­ý	 d/üZ`©Ó¿€9ø' PBÒ| ˜µ¿m-€›aúG  !?´ö·•ëµ?€ÂD<¸ðò¿ßøÀ`Ó¿—¿ @yð#}
(píOÀÓ¿ƒ Z Ó¿ Ó? ¥ˆp äµ?- ã½ü­ý	 /ùµ@Óÿö 8ø‡ @Òg¬ýmç3 ]§ ¨ª*ÑOÖþ>'!€æ¬ý! ð€:ø§`ŒéßË€ý?)}
°ö§`”éßÁ? ’nLÿZ Lÿ L(…³ Öþ´ Œñò·ö‡ @Ù×MÿÝZ w°…ƒ ÔO
3ž°ö×ƒ‹Ø2ýƒ @Ú-€é¿_à3 ¦ ú¶ S\\¿äÁkØ‡oìx ´“±ö§`pÖþ èdÊµ@kZ Fñª ’nLÿZ Lÿ ¤×Œ} pqý¢=F@Ñ|ûG  éÀÚßˆ´ åþ™µö‡ ÀPFk|ûÏ å²ö‡ À -ÀÐ­ýižê€QBÀp/&ÿ´ ÍÁ? F1äZ µ?- #„tÕ? I· ¦- ¦ âµ }Zû›‹²çÛ? I· Öþ´ ŒðgÒÚ SéÜøö?SàzàœYûC `Ò í@kZ †§úG `–Ð˜ƒZ çà ³h³híOÀ!\õ @Ò-€é_€é€üZ€ºÖþ„çÛ? I· ÖþÒã3@æ¬ý! Š[ ßþÓ$„fí€¤Z€ý­ýižê€$CÀý	ÓÁ?- Csð€$­¯ZûÓ0BÈVý# t`ú×`ú'y‹år¹ô @    €     €     €     €     €     €     €                                                             €     €     €     €     €     €     €     €                                                        €     €     €     €     €     €     €     €     €                                                       €     €     @HÿéÜ
ØžÈ%|    IEND®B`‚‰PNG

   IHDR         ôxÔú   	pHYs  Ä  Ä•+  ÂIDATxÚíÝMË]Õà}LR´~4ÄI±¡6 Ñvø;ÒQŠ#KãÐR­ý-:ŒjÀŸP:h¥£ˆ–&³RÇ5Ô˜4è›ÆtÏéy?Ï>ïYkïg­u]P¨lÝ”½ïç>ëYN¾Z¿s· šrŸG                                                      €     €     €     €     €     €     €     €                                                        €     €     €     €     €     €     €     €     €                                                         €     €     €     €     €     €     €     €                                                        €     €     €     €     €     €     €   ìÉ~ Òúú/¿ñr¼¬{¶Û÷Ø³ Ï®~Ô}}õ£î…_ñ0ûÎ‰}]÷Øº‰ø	 €÷ð RO*LºýOºû>û­‡ ÄûøO½è¤’Ðß¿÷ªš|ö–‡ D 'ºýL<”„Óÿì¥¥  ¢xåÒ›[þÜkñ`NÿS“ÏÞê&×/z0  À¸>þüJ÷ñ¿þ¾åÏzÿ‘îoñ€Nÿó!  `TóÕÿf¦þgà‹‹Z   Þô?ß\}ôQ*áô¯  BOÿZ€<Ó¿  ýã¿Ûô?uùöak‰§ÿÙì“ŸzX  @¼é¾°˜núßð³ Düøÿ?hRNÿSÎ€  ¡À{û×$žþµ   À`¶»ô§/—¥þç[ A €l­ý-b-0ýô?  ²ØKõ¿™µÀ´Óÿ, X "NÿS—oÖ$žþµ   @èé¾p 0Ýô¯  ²}üSLÿó-€µÀ´Óÿì¥ær   âô?e-0íô¿áÅf- ˆøñŸÒ¤þ§œ  B€÷ö=žåØòô¯  V¶Ê¥?}kør œáÇå@  Àž¤Zû[¤ÕµÀœÓÿ|  XJÎê³×‡øéÃZ  rúoµbú×€  ¡§ÿ©–Ö‡<ø¨  zü‡œþç[€Ö‡œþg/:—  §ÿù öµÀ±þþ¬‚  !?þS5· cLÿSÎ€  ¡Àù[Ç«mÆþûÒ€  [qéO_ç:SÝÀ1§ÿùÀ@ `fèµ¿Ej\ŒÒjø)  ˜‰PýoVÓZ`„é ¬‚  §ÿ[€hg´   @Èé¾(ý@`¤é_  Ì>þ§ÿù ôµÀ¨Æå@ `ú­äµÀˆÓÿ† µ@ ðñìýC'‹<=¸8€  € ±é_€  >þExoß‰¢Z€R‹  ññçWŠ ço/f-°”éö"üÔ@ 0ý‡nÊX,íç
k ÐÀôyío‘Ë·wW‚· ¥Mÿ³à§  0ýGönð¥®,j Àô_Dñ@`©Ó¿  *öÊÅ7ªù{yùúÓ!'íÒ¯-ž|qÑZ  Ô¤†ê³hk¥OÿZ   Šír Ò§ÿ/F-  øøGo"|xk™þµ  P‰R/ýY¦ˆ°XÓô?{9º Lÿ‘½XÛô?k¬" @¹Ók‹Œ½Xãô?~
@  Ó¿ é_€  ¦ÿbZ€Oï?2xPóô¯@ €ÕtéO_C_Tûô?ßXD €´Týo6äZ`Ó¿  €"œ¿u¼»€s+Óÿ†—¥  |ü#{÷ÖãÙ§ó–¦-  Wû¥?Ë´ 9×[œþg/L—! €é?vo-°ÅéÖXD €xÓKk‹äZlyúŸ… ?  €é¿µ åé_€  ¦ÿ¢Z€TmÓ¿  BiñÒŸ¾^¾þt²µ@ÓÿÆÀZ  ŒHõ¿XŠµ@Ó¿  €ÂL/Zå@ é‡¨  |ükmLÿZ  Ã¥?Ë· {]4ý/x‰º Lÿ±[€å×Mÿ=Z k 0Üôoíoy{Y4ý÷~
@  Óô ïZ é_€  ¦ÿŠZ€Ë·÷šìMÿZ  Ã¥?«›^´Û@ÓÿÞZ k ê?Ek¦-   :ëx·ÿáÉ¶-€éÅ—ª  |üKlLÿZ  Á¥?ùZ€Ík¦ÿD/V—! €é?v°q-ÐôŸ¨°ˆ  «OÿÖþò™_4ý'~
@  Ó	-€é_€  ¦ÿ[ Ó¿  BpéÏp¾yòB¦ÀZ  ,Aõ?œSGžèN>ÙÝ=ú+C€  @+ÎžøÙ½àèëF®­  |ü£}üO>9ûc-€  FáÒŸq¦ÿ©oŽ¾ÞÝ=øŒ“ãeër  0ýGüøk2· Ö `çéßÚ_€ ð½g´ ¹B€Ÿ Àô?¦·Ÿyc×ÿ\ @  Óe¦k» -€  †àÒŸáìTýoöÍ\”«°ˆ  ê?Úô¿¡	ðS€  €v¦ÿYàr |/_-  >þõñ_fú×h  —þÄžþç[ 3½€]„ €éŸˆ-@æÀZ  -NÿÖþ

 Öó… ?  `ú'‡E—þh´  `ú¯Ì²kZ -  dãÒŸá¬Zýoær |-€µ@ ª¦ú/súßÐø)@€  @;Óÿ¬p9P¾²  Výøç˜þµ Z  XŠKê˜þç[ 3½”]„ €éŸˆ-@æÀZ  5MÿÖþ†qêÈÃ kùB€Ÿ 0ýqú×h ÀôhúÏyðO @ €Þ\úSïô?år |-€µ@ Š¤ú¯{úßÐø)@€  ÀðÞ>óëQÿû]”ñ%­@ ÀÇŸíŒUýk´  °KÚ .Êø¢v9 ¦"~üµ ™[ k ”0ý[ûÆ—þô Öó… ?  `ú'âô¯Ð  `úg€éÌµ?-€  Lÿ¦-€ €–?þ¦Óÿ†@ÈóÒþÄF  ¦ÿ&}éO_.Êøâv9 >þm‰^ýoi´ Y8€ €   h´   0¼W.½é!øøïžôOÌÕ8ˆ À(¬ý'â¥?}YÌ@ `pªÓïà,@ž `- ÓÝÓôµ?-€  Ó?¦-€ €Z?þ¦ÓÿžZ ! Ï‹Üå@ ˜þëRÊ¥?}YÌø2·ˆ €j©þ·4Z€,œ@ @  ´ Z  HÇ¥?>þÉB€Ë²µ " ”µ¿á”|éO_Öó†  HFõoúOœÈ ¬" `ú/sú¯eíO @ Àôé_ @  ´¿éßôŸµò¼Ü]„ €é¿µ]úÓ—µÀŒ/xk øøÇÖZõ¿¥	Ðdá,  €  Ð€ Àb.ýññ<¸([à@  ½XûN—þôe-0o €…Tÿ¦ÿÑB€³ y€µ@ Lÿ±¦ÿÖÖþ´ Z  Lÿ¦Ó¿@€ @”¿éßô¢ò¼ð]„ €é\­^úÓ—µÀŒ/}k øøCõß³	Ðdá,  €  Ð  Ð2—þøø‡.ÊÖ8ˆ Ð8kÃqéÏò¬æ 4LõoúœÈ ¬
 éŸa¦kZ - ‘ì÷Úõ»Kïv_~~ÃƒÀ—“/»ßÿé–±çiõ—ÝäúSD?~èv÷“c<€¦¦ÒÃOt¾úWb ÿy¤»ôß/ºnŸíÞ<õí¿Hígý²U~hØ/Îœõ†rc­»»þÏP^}þAA  Ýðs!³ÉÍµ{ÿæÎz×Ý¹í' <÷]A  åàôÑSD.ëÇ»îÆÚìµ DñáK= !@múŸûøkˆâô±þ! Ðu§pJkú_ÿá–?­`l¯>ç· ´ ÃMÿZ Lÿ Dl„€„ÿ›kÛNÿZ Æößþ ØÚXLf§é¾€YûC `G^|ÇCH1ý÷¡`è `í€8¸¢Mk»ñ3 C²ö‡ ÀBÎ¬0ý÷üøk4Ø;ø‡ € óô¿ËÁ?- c±ö‡ @o^p ûô¯`ˆÿóšþ XŽŸòNÿZ †™þüC `é `-°÷ôíÜŠB Ïô {b-°ÇÇÿæÚÊ»n$±ÓÇ˜þ Xá%â@àb7V ®&ùôïà «r`—éÿÚkÉþZÎrúwð -@.+üÓ`úG  Ö·™þSTÿZ Rü­ý! šŸ2NÿZ ’Mÿþ! < XœMÿ«®ýiÈ4ýƒ @ÖÓ¬ýiHÍÚ y_2¦Yûë`™éßÁ? rkù,@Êµ¿Ýø€e§ÿ Ðä’ëàßŽÿ}B ¦ ‚iq-p2Dõ¯`Ù¿µ? †ÖÔOCOÿZ zOÿþ! 0x 8ÛÌO9×þ´ ¬2ýƒ € ×ÇÿæÚ¸ÿ„ ¶aí€q_B-¼1n Ð°íôïà Z€ŒÓÿ@k¹ˆMÓ¿ƒ „hªcüÓ°À/ô ˆÒÔ÷Ï	˜Œ\ýol \Ì=þ! 0TÔšþµ l þ! ±¨å@àXkZ vó¡ê -@ÆÿÍµ°ÿÛ´ írð€Ø/©ÖoÄ Z€vYûC @Ðèô¯0ýƒ @ø È°~<öô?ßÐk Ô”·8)áã?+Z€VXûC  ÀPPpío7~h( XûC  Ä ”EMÿZ€fXûC @óãs­¨é_Ðÿ (û%VÂZ`‰Ó¿ zÖþ Ðäžþ¦0ýƒ @è d(eíOÐk TÔÄ[œÔðñïºî®›«bí€ê\xñXÓÿ¶åzàº€µ? jé@`-Óÿ¬ð3@¬ý! P­gJ]ûÓTüC @YeÓ¿ Öþ ¨Þ…Æ;PúÚŸ Òÿóšþ hÃ(?Ô²ö§¨púwð€fÀðk“Ê?þZ€r§ hÊ k5­ýõ	áô±¦ |ùx °‰éÿ[~(húwð€Vq Êµ¿E„€"¦ÿ ÐäÔÐô¯0ýƒ @1r®V½ö§(÷ãoí îÉòS@kZ€R§ÿ àÛ ~-pÒðÇ_{ú æ$]liíOPk °ÝË1á@ÓÿÅ™þüC €í¥8ÐäÚŸ ˆéßÁ? ÈÙ˜þ75 ®6ýƒ @VYlzíO÷ãoí úÙóO¦-@ÈéßÁ? è Î.ýSÀäÚkœ äô äj¬ýi²ö‡  {yy.q ÐÚŸ äôïà dlLÿËµ 6ý;ø‡  +´ ‹BÀäÚ9jZ€A|ðÒA VkvþçXû[žŸòsð ’…€Z ¿ýk" ÿ  ]°ù@ µ?-@Dªþ  cààŸ  ÿ  ÇËun-ÐÚŸ "k ³0ýkLÿ°²ÉWëwîz      @     @     @     @     @     @     @                                                    @     @     @     @     @     @     @     @                                               @     @     @     @     @     @     @     @     @                                               @     @     @     @     @     @     @     @     @                                               @      ÿÉ§GFX3´    IEND®B`‚‰PNG

   IHDR  °  v   À"ì   	pHYs  Ä  Ä•+    IDATxÚíÝOÌç}ðyIFÇ-E6@ˆ¢Ìª²-ç"·ElÇe‚øßE—"Ð%rëÊÍ!@È«lDnonoF‚Xb>Xpz$Ë…,p}1¶–ƒ„•DQ1ŒÚ²!¼É†Y­vgggfgvfçûù„÷ÏîÌožW˜/ÏÌsôæñ  €<'Æ.   €q„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   ujìvuíÚkc—   ä:{öÞ±KèîàaQœ¿oì  €DW^zuìvbÊ(  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   Ô©± Ï_þÝØ a>úî±+ ˜)B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡N] ëw~õÜú¿úÝ«‹_•_/¿ø«ß½Zó®V{ìð–†ïª¬°yñ5ÇÞj4¶n§r4vÙ`ÃÑèe_•ŸËÖj8ª5Åï8 05!0²•KêßùÕsM.©;¼kùz½Ã[Z•WÔƒšâ	d}G‹ŸÔD¬æêG£Éð¶Ê?5cX³…M£Ýùs©Ãš‘ï}4 `:L"¬\¯oJ+¾úÝ«ëÑeë»v/²&´ô²÷n£Q³…æÚîkSrk)+7X³Ín±4Àá+úÝ®à½îßÒV~5¢]Æa 1lÛ¨¬™êÙ-²Žþ¡ @7¦Œ#«¹I¯æ¢¼Ã­}åd‹7¶m+µ}WÍýŠŠï¼¯¢SbÙú¡tËÒ[Gc}®f«iº•Åï®÷Ñ €é‘5¿×®á»6épáÊîúº‡p—£t;{¨°Õ=„»×Üo8ü ˜SF»ß5×ù]•Û©¿KpÓ½‚+÷4§¦Â¡íÛ{¿osÓÖŒüp£ £ÆQ™%ÊìÑcð[¹mr÷
‹½§ ]–úhµ—ú‘ŸÈh @¿B B“‡Ê¬w‡V~ÒûcûÉë!§ù#vzOA»áú®÷MeB æÄ=„@Šú‡ÊT.C·ò–bªw‘ÕÜ`¹r,G£‰VO|Ùñ;õÅw«¿_Ýž £;zóøÆØ5ìäÚµ×8ßØU@–¿ü»±+†'FÂd}ôÝcW °Á•—^={öÞ±«èN‡à6Q HãB  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡N] px>úî±+   :„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „:5vÌÄ—þâ?=ú¿þh¬½ßýø·N_{  àÀèÒƒï½þâŸýôõÿs×/²÷»Îœ¸ùÊÆ  8<!=øò‹OEñµÓïeïwž9qëÇ/Üúñc  ]}ïõ¿÷ú‹EQüï;yÿMÂ»ÎÜ>‡5	  -]•íÁÒ×Ï¾Ï{¿óŸ¡&!  ´%²“/¿øtÙ,]¾~÷×Nï/.Úƒ¥—?6öx  À!ÙÉŸ|ÿé•ŸìóNÂ;Ï¬žÀ&Ž @s!Ý-O]öõûÚÃÞï:SqöÞ|ùÉqÇ  ˆuén½=XúÊÉ÷þö;ð?¹5èÞï<SýÏ7_ùÂ‰ûŸ{l"œ;wnùÛ«W¯®ÿjù‡ýî÷êÕ«+´ÝÝb;ÃÓn‡ÙíÐ:ïk÷½Ê‡2ÖÙÛ¡È!>”Åf×wQó÷5…1 w:„tôøóŸ¯ùíÐwÞufã©{óå'=]fW¥âíû¼jÜTÃA«ÞÞõþyMüC™ÎÙ»OW—?—nïà€„t±Xjb“¯œ|ï KPÜy¦îÔu'áÐÖ»
S¸†n{Áº¸ÆšÊáf©[MðC™æÙ;Öh €)£t±éîÁe_?ûþ_ùëA:uwÙòåG§/Œ28±^¹V¶bjæ­­¼«Õ^V¦½mØ¼Œæ•¡¦Œ­Ç»øÕ¹sçšB‡<å¥—³wÓYÚ­ÂúÑ˜Î¼V !­mm–._¿û¯î¹gˆîÜMÂ©ZžªW4n-¿«ù<·úÝu¸ŒîV|ïjÊØô«ÎPû=äCÿPê÷UìæJÃáÚTI‡ž[R¬åÌå_5| ó ÒZ“ö`iˆ%(¶¶KÖ©Ÿ •Ê†—³•ïjnù[ß»)GíR|¥¶ã¶xWMm+Ü:Ý¹‰q?”n¶î«—¥Ã 6ßàÕ·Ûô«æï`L¥••èë]¾~÷•ù®þï{, I{°tãòÇNýæõ=óÖöR¾—èæ}Ñ)²
±B ’	„´³i©‰M~ÿæ¯}óÿ½¯%(¶,A±OïI›¸2}mºAë@på ZÈyÊ gïÆ 61e”šO]ÖãÍÛƒ%ëÔdÓR­Þµ<›®òW5ïª4è=N•‡<Pç§f`Û1\¿m¯ùÄ¿^y‚JïgoïÀ>½y|cìvríÚkœ¿oì*Rü›¯ÿV·7~óï{hÞuæDÛ@XÅ‰÷|N“p +×µ+Ï¨¨üyÃ_-&I.^Pù«õëõ*/ÙëßXóÞšCî]¿ÃÛd¬¶n°É!Ê‡ÒãðV>£¥¨:{›W¸i4¶î«fk5Ã^¹AO‘hèÊK¯ž={ïØUt'ÒÔãÏ¾ùÝƒ+>ýÏþúßÿû;púßvœá|òáoY‚‚dÝR ÐÄ¡BSFi¤áR›<õóÛÞþ·b—·[‚‚p‹æÒòK¥A  ðPêv÷à²‹ïøè.º¯Ña²è‚uêAü *é²ÝŽíÁR¹E·÷îØ],4	  Š@Èv?ÿù^¶ó•“ï=õÎ£oÜ¥=XºõãdB  X!²Åî“E._¿û¯î¹§í»vo–,A  +B¶h»}½MÂÝÛƒš„  °L ¤NíÁÒåëw?}ö¡æ¯ï«=XÒ$ €e!}ïõûm–Z-AÑc{°tãòÇz?"  8P!õÞ\øÚé÷7yY¿íÁR¹Å@Ç  ‡E ¤Z/KMlòÔÏü¥÷Üú²ÞÛƒ%w @I ¤ÚpíÁÒWN¾¯þé2C´Kš„  P©0h{°ôÔÏ¬_‚b ö`I“  
J}­D_¯f	ŠáÚƒ%ëÔ @!²nèÉ¢—¯ß}åü»*5h{°d	
  Y5ÄR›üiÕC·4	 'ò6{k–._¿ûkwî¡=XÒ$  œ@È[Z‰¾Þïýÿ,/A±·ö`É:õ  $yËžÛƒËKPì­=X²  ÉBnÛÃR›<õOwî¹=Xr'!  ±Bn«=X*›„{n–4	 ˆ%R£¶KOýüÁ_zïÉÝ·Ó&!  ™BŠb_+Ñ×;ùð·ÆÚµuê È$2òdÑÒg>ðèÑéG§/ŒU€%(  $²×•è7ù=ZÅ‰ûŸ±MB  Ò„é¦ÐüãßøÃòMB  Ø'0Ú(+Ñ¯øà=}ðž‡ßŽÛ$´N=  QÂhSh–“EŽN_8ñžÏUŒ%(  ˆ"æ}©‰b­=Xr'!  ì‡@˜k‚íÁMB  Ø0ÔÚƒŸùÀ£ëíÁ’&!  ì@j
+Ñoj–ÆmÊ„  $Ma²èg>ðhýNÜÿ„%(  `Pa¢Ñ—š(¶µK&Ž À Â8Ñ,Y§  %f™ÈJôMÚƒ%ëÔ ÀpÂ,Sh6OƒÅØMBKP  0oa),5Q¹}½“kÄ‚ÝI ÀŒ	„A®=¸`zBœ¹ôÃ±Kˆ`œûe<š@˜âË/>}ˆíÁ’;	 `§Æ.€=ýY2EQüñoüaç÷žxÏçF|ìçÍW¾0n(¬²3ð·_|×ÊO–U¿‘æ¯¯ßÔŽ[hRä¢ÔõÝWÀè#°KIEûO¶rl_~Œ›Îóãßm|ú­ÿÔ—´þ¿”Êòú=Û'ø·°a„)Lm¸ÔÄ&'îbÌ@øò“áºÅõÓò…ÔúOn¤òÛI™laW&çVoYýÏ“¾êé0>½×?ºšÏw?ãcÖ+Ì”ÑShv»{p™§ËLÊ@Wl/RÏ\úaYÃâÔÂ¡¯óÄ)„Ó!œ¿ÇŸÿüØ%ìÚ,•KPŒõˆ—›/?yô/þÝˆk`¤©éTN-«éL®¿~ñ“E”ìTÕUŽ[Yùò€4y}å÷5¥³ò½ÓÞeÍÇ³ÉFšl§æs<DÍÇ§rdZý]Ä/À Â™›ÈR»·K'îâÆxÏü¼ùÊN
„ck;upÓëWd“‹ÂIÝÃÖã¸U~Ý×8÷~åSÿšÀ6Üx¶ý;Ô?Šÿ4S91¡íßµ4P„³7…»ûJƒÅØMÂr	
MÂ…)ÌÌÜçÅÜA_8VöñÖk¥ãZLãS®<ŠÑ?ŽMÔw8»g“ƒm5 S{RñöîýèÅ Dçl"íÁnKMl¢I85t§“êlåKí&ŸWåë—Ÿ­ºÞ­û'­íøgjûTªÝ-SÆ û'ÎÙÌÚƒ¥£ÓF\‚B“p
¦ö´ÉÅí[£W²µÈá^_Î'>¼³¯hcÏÔþg0
O­ƒ^‰¾žuêg¬²s²òOø·CiÇE Êôëê¹³'gïç³? tgk
KMì²}=ëÔOÖâB³á½@›¦€®<2±òëõí¯?šbÐ)¦ËÁé4	+×L¯ÃšCëöú¢ê–¹VO}\Ë¡OÞ4ž›Æ§ÉßÅ.ã0µñl{þt8¯ ØäèÍãc×°“k×^{àü}cW19_~ñéÑág>ðhïóE—ýÃÿø…îÔo^qïÌÕD"åìç~O Ü•—^={öÞ±«èÎ”Ñy=Ü=¸âÄ{>7âÑY§ €gh6+Ñ×;qÿ#>ÜåæËOŽµú3¦Í²Æ¹_Æà 	„s3‘¥&†n–Æ½‘O“ €C'ÎÍ,—šØ¤\§~¬Ã,— kï  °;pV&Òb©‰M4	  3ËNÌÊ}áOò÷?·†ŸÜºï¿}óg{Üá¿¾ùÊŒx¼ùçÇ:Çˆ  @gá¬|ðž‡žÿÁÿ·†oÿà#/ÜõÆ~÷9f ¼ô¥A  •)£³rñÂcãpôÆ#ÅãâFÊ}?ñŽ±K  €îÂ¹¹ôëŸs÷?}¤(Š[Çûœ2:¦?øÄ/Ž]  t'ÎÍÅ}øÜÃ£ìúèG—n•Ñ$üÆgO]  ìD œ¡q&Ž?Xÿ«Åw³o~èüž% À¡gèÃçÞ“ðè§¼íû¹7	/š,
 ÀáçißMÂ··o»q<ö0E{ €yçiÏMÂÕö`Q³ž5ª= À<„³õç¿û¥ýìèèG*Úƒ¥9fÂ‹Ÿx‡ö   ó ÎÙž– ¨j–fÙ$´Ô  ³!ÎÙî$<zã‘-¯˜W&´=  s"ÎÜ°G¬i–æÔ$üÐù;´ ˜pæ}ºÌÑ¶4xÛ\2¡gÉ  03áü5q´r©‰*·f± ¡¥&  ˜pþj6m3Y¤^{ €ù#ôß$lÜ,ú„Úƒ  Ì’@áÃçîw	Š£]l÷†o~ã³§Ç.  ú'¦è±I¸}©‰*‡Û$´Ô  s%é­IØüîÁeÛ$´Ô  s%¹xá±ÝŸ.sô£KÝß|ãxì1hÍdQ  fL Ì²ëÄÑ–Ï’Yqp³F=K €y³ì¸E‹¥&69¨Lh©	  æM ŒÓ½I¸[{°t@MBíA  fO ŒÓy	ŠÖKMlr ™ÐÝƒ  Ìž@˜¨C“°ÛR•¢Ih©	  „¡Z7	w¿{pÙä3¡¥&  H †jÕ$ì±=Xº5í	µ !æúóßýR£×?Øs{°˜ô"õ:‡ö   !Â\— èa©‰*“½“ÐR  ä£mŸ8ÚÇRÕ&Ù$´Ô  QÂh[›„µKlj E LWs'áÑÕ,M¬IxñïÐöïãŸúäØ% K dóC¶o»q<öÑ¿Å³d  HsjìßÅ}ñÛO­ü°÷¥&*Ý:þÙÑ“ˆa–šhk¥§ñÜ3Ï–?,¿X~ÙòOÊw­¼fˆ’»Xï½TþjÐ’ÖG`”zq|6ýª÷zÖOÝšŸ×Ÿç£ÔÙä·»l§ù–÷lÓTTþ ÿÿ8\!EQ—~ýÓ«™píÁÒñÏŠ	dBíÁV.Ö·^f-^3ÄÅåzò\|ÛäBÐ’V6>J=‡2>ÍÿeaÜËúM	pjuÎ[åTÛMwƒþÿà ™2JQÅÅ-?]æèG—ö¶ë)<ZæŸ==v	ó·rÑßð¦©©Ý[Õ¼žnÇ;\=+öÐ¤ÝúÊ)Ïjò2[_P¾fñÅdmmÞ.ŸWû9ß ”!·]¼ðØw®^.Š!—šØdÔ&¡¥&†0ú¿Áë 0¨2T,ÿwìŠ¶Ì“lø«úZå¦6MEÞÔ)Ý4¥¶íT[ã }¹­\‚â;W/ºÔD¥qï$´ÔDg{¸Ç¬¹ÅEyÍUïêT©Ì¥+I¬&¤5Ÿò]4˜–Ùd#»l€	„¼åâ…Ç¾ó7?Ûw{°4R“P{pm¯ØZÍ'\ÿzëîW–+/ÞýÊ²[=›Žw¬z6½~jã³»]Æ§¹E{p £ZÛÊ‡8ÒÃ=€9yË‡Ï=üÿþËŸ]³Uÿ°ÍÊWv¸àâ¹Ýêi~¼û©ge|F¯gšãÃ VÚ˜Ã 4'=[¾·jÏ;ûÐÉ2ýSn9€M¤ÚVO»`<e ‹VLšçžy¶[ÜêåT©œÁ;Ü‘n}²¨<X¡Côf¹1ØK“°ÕüÃ†SÑ–Ëk»£^®#G©g¥STs>îø,_µo¿Ñqèz*Ç¡æç›ö8ôCz*ëÙ4n+oi>n×pßt¼›Î·&¯oXçÖSee#Í
 ÍÑ›Ç7Æ®a'×®½öÀùûÆ® :®gåé {på¥WÏž½wì*º3e   ”! Ì–u&†vèB÷Àl	 Ô3e   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   Ô©± Ï™K?» à0üíß5v	ÔÑ!  %  „  B	„   ¡<ThÍÝá  ó C  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P§Æ. ˜¡ê“EQ<÷Ì³£o­|oÅ0®~O­Éjx˜9§wÈç0
hmqºÐË…Ú×|Ï=óìzµ[jQÃúW¶Öã×ï¨òçõ¯lx˜­öÞã‘nä†{ÜOTøø§>¹¼‹•oG)©Øùô®Ù~aËÐâëÎy€é3ehí¹gž]\*-½‡ÝdqQ¸|XùÃ•’¶^ŽüSŸlxÉ^³£•_múvëX5?¢¶q“Ãl;Èõ£Ô£†ŸÑâ5?Ð]ô{˜yG:Ê€lÝÅv0:„@o*/Û¶¤Vº+•“â6šº©|ï¯'XÒÐÅ¯Ÿ[O›Îûª/ ò'Ïäb[·¹ù9?èh×f·#êðÊbóç¾ÞOès˜7B ë¥¢eKjåç‹oëw´|±Ø¹³±x×r³¨ò‡‹¶N\~WÃÚú6›—´ûÇ×m¢cóÃÜ½øõÚ*O›æ±¶ŸQeIMÎäVÝæMÿ$±KãnyØ›÷{kúÒÍh÷W¶<<µö&ÀÄéƒîÊlSe—M•WÃå·•?¬ÜûGTÔF Ê’š¨¨ý´Sv/~ÇÃì¥þå-·ÚËÖ´<Üátù•×¬÷ô›êësìe›r @[!0¸=„MÓÆ†;¢­ÖmŸÒd›}ßã(uxÌº!Žºyæé\|Û³nÇ3îé½éß&ú}‚NÃm.*\¯ÍLQ€¶Lö¤ù”¼!^Ùa;'@ö8D­¦8œšâ›?-³íöÇ±‰”Ôa›NÅÅ¦*§•®¼¦óôãÝÿ¸ú/`ÏŽÞ<¾1v;¹víµÎß7v¥ÉŠ•?ÜôˆMOh¾ôB±aJ[åÛ·TÃúê´zþ¦Çf4)¦fGõó{9Ì¶ƒ¼²÷š’j6ÓoåõO1i[R«‘âôîv˜ÖiþwÔðdX¯¶Õa¶Ý@½+/½zöì½cWÑ@À$ô;ùýó	™=º‡€I$ `ÿB  "=À!òP  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡-/ŠÁ  âIDATB  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @¨ScÐƒ+/½:v	   ‡çèÍãc×   ÀL  %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @(   ”@  J   %  „  B	„   ¡B  €P!  @¨Õ¶ Ê‹…0    IEND®B`‚‰PNG

   IHDR     „   Ø/…   IDATxœìÝ@SõâðíˆŒ)èÄÇPce²¡KËiå¼«ÔÝÂ()*¥B)12“$#±P*”’¢$ÑH¼¡ÞîÔ®³Ò™ÔÔºÃ²‘Ê|Ðô0Ô3^ÿßx?Æû!Ãï'Âíììœ³³Ãv~ßó{8_µ”        {æH        ì       °{8       Àî!à        »‡€       ì       °{8       Àî9 è¥¥åeåTEy)¯¨ ÿT   èkè#ýþGG'GGgg\A ¸6p tg””–—–Y   \èz£œÔ^È(£ÿ;;9Ò&  zŠÃUK€Î©¨ W-¥–ü5  @†ïÄðœ‘r  ô B¯×X,¥œ¥-P    )‡Ê˜ƒÏwFÊ ÐÝp t\yyEñÕú›    4ÏÑÁÁÕÅÚd…  @·AÀÐA¥eå—¯” Û   €¶pp ýûñœÐ) ô}e´¬t•»j))/«q¡ÜÚG¡£µc"G'Ç~|žK?¦;>p t„¥¤ì
WJ    ÚÃ¥aV  */¯ ¹Æ•«!ýû1.ýøNN4Ð°Ž0E-+·Æ4û¸rÕBgs Ž..|†éÂÚm8 Úé   t˜ãÌç9 €¾å*ga‹.ÓÌb [ÿ¶ÔÎ(-+¿È_áJ„†ðIW@ÀÐ>ôï°øJ	   è(W´U€¾Ãb)¹XtÙÙÉa°»ýt«š¸#›Ýª½xìôÕl©‰µ^2Ày›óØýfM8}‚°ú¹%e.•—77†Ç#ƒ€ ÊË+ÌW,èx   :ÁÁ\øèsô:QRZVRjEoXJJÑ?}£h|ž3-u;;ÓßÎµÅoè*œ¥¤ð’y °ÿ ½{±¸leFþgß](æÊ[x–›‹ã³÷Z0”Þ w/™¯\d/»0üNe8 ÚŠæÅW,eøN  €Nsrtôïš*ÙÐk]¹Ê_¹JZÀfxÎ|¾3½Ê;=¬´¬œæJK)G—”:::ººôsé‡¿¾®QT|Å\|eèàýk0ñÑ¿Ï¿qŽfm|º»«Stà°çýÑÛW¸’üó—Ü\]®.¤£p ´g)Åß   t•~|'†ïL *¼Täà@†xPU§©¨¨È¿ÀÒîÜtÇY
.™GxºóœŠ®”½÷×w¿šIûÝ7Îí‹ðÑn.Ž%¥e§óÝ:Ü%GW/çºXòl?Æ^Ñÿ&Ð[•WóeS    «Ðb¯[…ß¾ç*g¹p±hˆ‡› ?½RQñÕó‹<¸uUß–×¡ÒÒ²¿.«¬»‘›oyèmÃ_ùÒQÞžüo¢$£‡ð«êqˆ<8w¨1QÏÅ%—ì{#™÷1Ÿp6æ.›tœ8ö' ½Ò®ÔR‚ê   Ð•¾S?Tâè[ªš¥tDvÕ»•—Wœ=Ñµ¿‹2Ž1\èæBS¼BséKþ8eêì8£Düß¹i «3[|¥ÈÌö’öë¹`Ÿÿžùû¥¼TÞÓ¶.9ï˜·Š ôJåé   t9ÎÚ»*ˆöW9Ëå+WGqGºÑû9::Ðwª˜¾a\Çë4çêÕ«¹¹úCokª›¶äòU‹³“#M7JJ+‰û«óéuÒd	ˆû‹.PèêâèäpåjGÞ—ªÁqö²é	õÂ’òRž£ó–!‡‡–lÌäÀ/»ãwÂ'×Vaö‡KÞø÷ßÞËãž¹U@ *Ç.ºÂ•   €®æÂ8óyÖ¡8“aÔˆÁH7ìHyyÅÉ³ç‡DºBaaáŽYÚƒÚ'þªxÃhïÉ“'OŸ¡8` é)Ý½%ùç‡Àç9ÇmÍkË9ÒuböÊCC,%¥ùØ!ƒ’vê¡€cÙÁ÷÷œ>PuûÞÁ^+È6g«Í.÷ùœt†9ûý…oìù»ö¾ë^bï±ò¾ïVÏ¶Ô=²w,[ðÁ¯%¼Û~sŸ' ¤øJIiY9éÓÆ¼cÇôÃüßÿùº{x   €zèUPW—N‹½Dá%³ ?ýnØ¢â«—¯Z
;{m;kû76|\Zj½,êèè({ÑFc^y¹µÁãñ^|á¥ûîS’î×Ý[R|åjYi™ÈÃÍÄ–J_Ò_±te547Çÿ%úxœÿ.`]]Ú÷ÕMþ~+ø£6Ý öœÏ{hØ´ÛKö6ÓÁ´ÙÁëå
Œt–û­÷*np¥7,…¿øþûc¿|¿í›»_zõ…Þ­·|±bNx±¶ÐsªéXUTŽ¥¿ÿžóYJò©Su¡éøñžzzîÐa×ºš   ô¾ˆ½Ê•«=mDºÑí8Ž%Œ!]ÈÍµ_ñîÊUKgÆŽMß¼iãFë¥zÌœ9sô¨háœX{â,ýë¯ÜÌÌ¯÷}¿/aÍûW®^UÍü'éN=°%—¯pC<¬cÐÄl>×µéUt¥|Å–ü÷‚Gô7µ7àpZúÆ2ÒÍÞ8øžéJAý)z"öw:âè`c_8˜­ö,é0Ëí·ÿýËrSÀ¢Eÿ˜4aÂ¤»”<x›ûù#?ý–óÓ>#-$‚Vê òú¿ñcG»£¯¨DO;JJÛ}æñÛoGcßZzùrñôÍ˜é?aÂíýûjÔìÝ=ùN…««+é‹Š·ç7ÿÌ—ëÿË»eÜ°|Ã[NŸš–-JÕþÿþMbÂÓß±Cø-?ñ³ÝŽ7ß6ç  `oœé5VG4j°o—ŠŠ‡pmF„åŒisïúçjƒL¥ÝôÓ¤ŽšñoævÅèÂm/Ï;9úÞÛÅ]š4Äê“BB>>w³âöaLå†=¹Y¨Tùz4¹°Îj¢üŸ\m(£ÛvbÌê3R24i1okEÊ{%]{2íêÂ\2÷wéàyäþóïO>I¦Æ‹½8ûqAyÆ¼ï¿ß÷Ç¿»ºô÷ö–ÜyçÝtâO‡þüsöèÑ£½¼F’îñí·ÿþdCƒ-¡·«¢7D·ÄÝÝãÐ¡Ÿè–Œ9Šþv*+/7_4Pp™+êýmŠ7ú÷
ð|ÒÛáT.w¡åª_O^	W‰¾S[Ü¿Ó®Çnïdt·ñÇÿo4ñ¤ùü¿úÙ~BÑAÓfÒ¥ÜÇÎxùí×îHŠ~úå/ =ªªrµÇqë’†y'nÍãOÌ™xû¤)w*Bæ½ôò+KèC¯K$ÝÀRl£žbÓ_'MM¦[
OÿëbÛ{í±XÚ8/Wüó7éÇEw:‘uàD£'YÎì\úÂ_ŸlyY%Ÿüó¯Ku½YNîþb‰\Ú”²ßhiù‰Ç—º {#  €žVVŽJö­¤´¬¬¼¢§ÓÎdÐi­4jžŠD¬^[Eg0ÕŒ[ÉÔ)Y:ë£Ä¨ÍÐ¸.®ý`]…>-dN”ÚX¹J“6%Mk$bQ3`pÆêÔdehôF–­¹_Mo¬qÓ˜â;Ú7$ÃX÷‚5ij"›´zi“:fNHŠžkÃ¬ô½£oaiiGzo8þ|ÒºèW¿6M1ÞørSÚ‹/Î_¿>‰þ¼ðÒüŒ¯¿¢xàÁ^x‰ÞøàÃÄâË—I7 [²n}ƒ-©òõ×ô§öîƒN¯Ú’’>èÀ–\å,®ý­Ñî#Eåm«½Á¸ºÌ¼oð‹~n#Û6hª¥´bÏQ3©ž®ZÚ×Õh÷6Q±”—|øë6Ú|Ée–À”6}È1wIÙ ‡‰cWþéñ='?ñÄ­Ú~Ý÷ïŸŸ¸ÍÏÚ‡9'9â•oøÏ®}ûa×ß6~øÙ¶_Nk¿òëôÓÞsÖ¼ÿèÃg¯dœÿsÕû!cë7n)Ø·"äÝƒü{ßüàå	U½*˜s÷¥}üñ¶_/ÒÛ®7ÜýØÓ'{£Ò¾ ¼¼ÝÕ®rÇ
}ô‰F­QhÒ!“MÔé²/_.îß¿Ã¹³åøç/ÇºååwBÇY—Ql:n,éñ›Ä¯>½,PZ±Å'ÿµ2¶^Ÿ4uxcž]¹â¾m¨§Tü×¿×®üÖõ©¥óïUÍNó‘“§‹mF	%o|1Ç4$ðq•‰Gçà“â¿O^äáNo9ùwÿá7¦|ÂhºT|¹˜wÛð–vƒåŒfmâ>×G^Yà{)=nmÌ——¼tßÖ­(þ-åµ¥{Î7~ÂŸ^_ÿþpÕ›1soê›Õe   ïèÀÉô*%¥¥LÛzŠýþû5ß}vìœuÈ…±C%ïyæ~éÝ¶geµ1þ)"	NÏŒ–WæÆ¬éaItfz°”!&MÌœ[3¿))lNRÕM¡2qg²JL*k=¤éˆ,2HJt	)Z–%1ÓoŽ©yŠ8(ug(1=LÍÖ­W¶(ñÁï"Þ6(âw&ˆ9CÊœé1ºÀD•ˆÞ	œÃÕnPåzµ)):)¢¥7V—’”edD$â®ÑaµKŒº÷æ(ë¿ô•¤ª4IúºµeDÍÉ¨ÿª¥¡™é‘²Ê…Ó(†!L] #”EGKˆ\)­|Ø¤™¢¶™t«¶](‘}RŠF¯‘VñyÎô}tvnw¿[33JKKi~q×]Ö·2ç˜þË/72ÄÿáGèÝ¯·~õÙg)·Énóö–LpÆÏ?g<¨Ý¹cû£>Fº]Wý-©•™iÝÇ<P;…nIvö¡Ÿ~:Ø-±XJ]û[ÏÇÿýKºÍ·¿°Ó'ûñy—¯ZˆK;žØ½Çæã;5N©õØÿ,gnv4,²ñwÚÁø^ÅÈ%¤+ñ=ožìÍûõ÷Ülc±_]w£ÅyÇö¼óå'.òÜÅ7Œ ¦%¡z,ÈåÞ_çþªÍ³Œõ©+;ð{12yªweºaÎù*öÔßŠy#&Ü+÷âÓG¿ÿ4ö—ìÞ^1Ý]ì^N9òòNÑßcÆú4}è–[|iÀ‘wêäÍc¥¤£øø<>¿: µçü{ížsŸ|pHJÆw“_Sª;è\¥Ï%§=G:Ìrúû”Äµß_¼åÉE·‰jk1LykÝŸÍ×•6¢¸ÿ”Ûª^^ñŸéqï|w©ö1ÞÚ×ÿtïoíI7pÔ=³Æ‘VnM7v»¾6w"]÷ˆ¹K_ñÁêå±'Ÿ¸ç¦·'n®?÷‰¯cWÿvoô²©mø  èUoØ»RkÀÑzO±;Ûûôgµw:q$(ååÏŸ‰Ÿ~Ë´¦3³zµÆ(‹9£F­gårÛ5"„òèÌÔ`IÝ•aVXp†¬„4é©'   IDAT='•	Q‘”–øõœL ·¶RŠåô™:ëœ"y€Jf(ˆPr×ÿ‰Î‰ˆÎh0±DÌéuŽ˜èo–F½‰£±Aý&.Fmš–“…+è4NŸ—¢ãDÊÐø`‰uí1t¥4´ŠTÑÇ…b©X,ßy"’Î™4Ç?Îš«håÔ3ê²RtZ­No°n]Xjjõ“¬[!Q)$õ*¥pMšÚXó <@š“¡1*Ä¤5ŸGÒî«ì?þøý={öãUwµZkï“a/-”Én£7D"ÑŠØ:‘ôî£Î¦ÇÁƒº#àØ¿ÿÇú[Rëõ¨è¦3?öX 8:°%eÎNÖèèÉ+ÍÎÄs¾[1ä—¶óÂ¡KMÐît÷1ÙÕ»‹þn¦LQµp''Ç²v~>vcÀQÈ±Ï´ùÐh7±ê†{+È=g>r¸bcÈXÇSqeÃž#¼®,©ðÝ½h|ñ{ñßÆBË„Ú6þÖ|œê>á…Â”TW·07x’·|Â_çþõË¯Æ'|jÃŠÂßüz‘¹Wq³»õ	Ç6}@Ó!÷¾¶òEUtòÄ¯ë£—mKMÝukÔ/Dv®£Ó;UþÍÛ¼SQYw±¬¼“£ñHíw(ˆüÕÄïÎß<œîºãí¢>kýÚGy“ž}mÁ}õÚFòG<°üól?Çô}ÜëÈ(÷Ú¹]Ç-øhÓkZ±{eÜnñKo4[Ÿ¢øÈÚÅ5QÈû¡?ÞŸ‰7“ÿyÁêf »ûòÁ=Gn5ñï”×b¾oµÇ€»^~'bb»‡—  èQ8Ù€^¥¤´ÌÕ¥õ‚ñšï>³9ÑVÀÁ4Z£HÌ%Åi5V.³™pT5S1Õ8F¶ºM«K‹Š³ÖðàX}JL‚–(b#Ušˆ(£<42RZó£5à`ÄÊàÈàšiœA/ƒuIcUs£NoädŒÁh""eý@Á¤£ñMPD«‰
‹ÓZ×ÍéUbF—” 6†æ,¡ÉŠH§6‘ª±$8£:)EËÈ#åú°›çhëšˆ•ñ©‰bV§QÓ@CO_˜µ™IˆºêQ¦j­ÚØˆ¤ªðhU½B#«aÕjcÝÃ
1—¤Ñ™Ä­,¾ó•¢öµ† ŠŠŠ.\¸0öæ±¢ÁÕk¸ï¿‰&ÞtÓÍUw«ºÞ£óTÝ¥s4èøŸÇËËËk{Çè—.]¢k3ææÚ-©%õ±qa•nÉÀÿ8þGEEûZW•—•;;Y·Üt©´ù¹œoWznÔå?4‡.5þ|c\]|÷™²4àhæùU§IJY;»aîÆ€ã}zq©íPç¥[Ÿrr°î”
ïx‡ÿùÛ˜£üŠCîë7Lº¯òèºhÎ·6ã©-–¸Êƒ_¬M7šà{+¦ÞðÕ_Æ¿ö®N8Ì¹Ú£‰û½wW6AÉÿ9cO.2ýåçµCÜo}øÑÛv½û‹æ×¿ý¼ÄH8ì[EûÏ9Fö¦¿?¦¿åßFåè£¿Gˆ»²c!š8¼üÆ=|×FGZ±éÄßDtƒÈf Pü÷™Ë®Ãm?F,ß·iÝæ}Æ“ç¿=÷QmnâQ|Fw¦dÈ½£š$
Å|»ûôÿmœnX
ÿyQ$½a`MbÒ,}Þ;Kê¥ï[½üÀÄe‘Š!õ·ŸïzÃV<e]"MOVÇýË¢zmÉ}#ø–âb~åIññÏW¬û›   ôz8ìœ¥¤”Ïk½`õÇß'Ú8‘æjA(V(ˆ&!J£6„Êl&œ!-bNZã©Âªò,gªÎ:hâ •Dª$zÇé“¦ß\Ù–Eš/iºPF$‘ŠˆÖ¨7±½•(”ŒN§5˜TBƒ‘Ieõú×`:ƒI$“ˆºR•RÅôj5Áˆ‰	KÑ1ŠèD•6&J—¢ÉÐ
ƒD™±
¡>#**‹•†F)ÅŠtÝFÎ¤IŠJÒ‰B&f«MŠŠ²¶=©2¤Ñ¡r™TdLš˜dTI›éÞƒØ|%‘I§3²*QkÏ¢ï }I;™‹ŠèowÚ)^#Gz‘êS}:|üI2½qûí·×Î {Ñ$ââÅ‹õžÕyÅÅÅô·Íe>4›FiÓM9rÔÑ£G

µgKÊÊÊœ*£™¿»³÷»ólUÀáHW×®'vWÀñ[ÁY'¾³ùÐžãäC«‚­4˜J.ík:›cþçå#^èŠ!c[&žúà÷ç{M˜êšjÐþžÿ wemšoüú7r_Uû”Â¿²“!w+nh‘¸»“ƒùÆBAÀqý‘Hn3fìŽí™wÞ9µ~7?gÿôsöÁÛï RrùdÖ»ÑùN¼kêÔ»Çpm’nXùzµ:uúËæ½Ó¸A‡ÅôÛ¾¥oÝó›µ&Åà{^jOºam²ÿ·K¢»|D£ý×I{NÞôjPâEÂqoØ²'Ç‘?|ûí7Y?_¼ùÙ˜èšÞ@
ÿÌþãò¥â¯ÿý»ÏƒS^[©­­Ò–´Îú/ïÆ¹o¿¡ªÞ§—ÿ>~¤øòé¬”o
'/°¦ÅúÏß]ûÝ€Çß}¥~   `W8ƒVc`¤A2±˜SHHœVkde¶
ö-5QÊƒã“E	qFF¬ŠL¶vÀ`²ö~!VÅÆYŸÂˆiŽQÙ§«éšCº3^!–J„Dg4õZ##	UŠŒ­VoXF$«_‚cY–.§²R…H+JÑ¨5qs²LÖåJå4™ b“•a]kr¤BH£°(KD}–5-¡›œ4'MËJ’c¬›%”‡Æ§†Jd"}”ˆV¢T©ä"V“¢§¯6\%iOF(ÉÔ–ŽF;Ä™o­V]Zj#ÉËË{mÉbºƒžzZ.ŸR÷gëSº<Ö¬¶¤=MÕSÊÛ_µ¼ªÂßÙ±ûF¹®¨·¢vé®€#ñhjy…WëèàvëÓõ§”ß¸ÆñgÛ)†ãñ…e²}¤«”[»Eä	<ùõK_|w×–¾ø6Å_~zlßÑÂ{½<ùÄü×Ÿÿ®mŸb1æÓ¬ìâ·‘ß6}®{q»k9A¯CóÎö~ Ñ§„¾ørÔ’W^‹\øàƒÿ¤qÇ]ÕëÛûß]ôQ™ìöN×IãõpË„]OjÖ½ûÍ†a¾wOUÜ?uâÍëË®·ÍOÜ6ßöÓ¬~9åâm7ÖÅ,“þ‡oÿõõž£§Éà[¦ÎžPœþÁnÒ>Ûw¤xÔƒw7ÌD¬]i|ðï¿‡M~zÞÓ÷ß4°øÈ'Kß_·4»äï‘lêƒKVM®×ÏèÅ?²Ïðxý‡”ÙºOzxË“oD?8âbmŽ‹ûãWì®~uÁïÜf9ýÝú÷ÖjÏœ0ÿÝYR÷‹G6Ä­ýŽL^ðÔdkºázÓÓ+W  €ÞïÚŒ-
]§êâ¿³S+×5Çýó©ßšNl2#Í7ÔzŽ­í“0j1Xj£«¹·®ÓÐÂê'DR©˜i!`¹š‚m"¹Äš£H$bFc´ÖÛàÄA2™H"ÌÒëÄÄ	¥’ƒÌÒ%Ô[SµV¥’1è³+7E&“0:ž$e8}ZRFu\eÇœ5“ÑX‘"T)4ê´&‘D*‘)¤:©bÒ©µF‘226HÚÞ.2Â´-Ýhc5œFÜºÓ¿ßsçÎ5}hÝú.]º´xñkŠ©ŠúÓÏž=Mîîî¤KyxxÐ-ùûïü¦96ó	sîÜYëØ±ƒH{8ZkU”;;;‰„Î6†kì"ž­ïPÚWhê–€ÃæÐ°UT£ï¹AèUJ…«´bØógmµF±›Y!ò']ÁR˜g,¤…ž!CÜÛW¥‚/ž0Õ{cê_~-œáéi6þœmÍ7în0ª
ï†É÷ÝêÙ¸g!žxªoØ?ú‰PNÚ°â÷îÚ>|ï›o¾®xÏ½J±×¨äõ‰¿üòÓ‚…¯VuÕÑAo¸÷é¹7¹Z.ž8˜õù[¿Û:üž—^›?QÔú!g9}p¾dÔã²š;ŠoZúæ7ÆÁ>÷<³bêMô/Ärf'i'ËIÍ×GÉ˜§¦4š¥XŸžøùï£æ¾û²uôË™ýé[œ.!c&Ï_2kÊj‡˜ôßŸ LxãFýýí¿ŠZ«õÆqOØÊ1?_›òùòôCN)ž8?î^²õƒ·Þ;wîÔÑí¨|  p9"à°s<g'‹¥´¿VNÃÞóLýNFk'6ž¯2ß BY@€œfœQ“–¥WkÁ6ªp0’ Øh•¸~i1QZÒcVÔœ¬Ê'Ë£w¦*+o5èƒƒË$BN£S³&‘B*ÓØƒ$iÕœ‰ˆ¥âú[Á…Ç±"F$×Gù‡Y£¡T¥’¦Äedhƒd
¡4 <’Õh³ÒtœI“W7|‹&.DCª@ImÒ%¨H~(’F)íî”3qœ°MOã,%<çv—Ž¥>ÒÿéÿwæÌ™áÃŒŸ(¸ÝvÛ„©wO­?ñÄÉtÎqãe]ÛGË[bSÕ–Üzë¸ön‰“£ciy¹3q2 ;ŽÖ²5MRœ{AÀ±õ96§—Ý·_ùKv“~T+–9°’
uc*º8{é
ã/»/¡™ƒ¯w{K=|¯	wK¾üä˜ö÷‚ûÜýÅhmŸrsU¾Áñt%¿ñ}O<?¥‹S8è:|Êá1hÐÒèØKì¥&ÓUîÊñÈÂW._>ðã÷Ù‡¾¿zåÂ—#ymèp»Eü£ï~*úîÙ§ÉÛðmÔ,ÿüõÖ?E÷¼:¹6~s½é‘èÿé>°@ññ¬”oþ|ßŠ	Þ€›§>~Ëä©7ð-ÆV§Ÿ¼áN*>0dÂ¸Æé±ü•­ù{Ø¸›Ïî+¼QqÏàçìÓÕ<DšÝM4€|P•}díÑ“7?õZ„5Ô(VÝ} >%úUýüeÁS† b €Þù†½£eKÎÒzIoú-ÓÒ‚ßou˜XÎh­¿!V…FF[‡7åŒƒ&B«Öƒ”MÉˆ$2¹¼~F#l±8Ï0ÒÐÌÌzŒêmÍ%ËÄL–NËŠ”áb!#”JE¦Í7¤"¦áúEŒÚ`d‰¬i/ž«×¤Å¥éB1£ËˆKPI£å"Ep¸È¤IÓ1â€Ä£¡ÖÙLšÿ2µþØ³u¯Çd4q¤rZ£Þ`4±BieGÕLYa·g…5Ùzë.•õIX£ÑÄˆ†2Íà,¥ý˜Žœ9ÞyçÝ4VØ¼yÓË/7!ô¡‡&MêgmÚô%ý­¸[AºAs[bóRmÕ–LS´{Kh [Õñ§ïh—C^&ÝcÜÖ±aËÊËÛ›ÿvKÀq{ÀS{4N+×?vîJ©£‹Í?þ;šNru6'Néþ›€#   IDATšk°£æ³Œ\BÜåMöl÷q[Y‡#5ù˜öXÞã>ceû”šúîâ‰c]wk=p¬pÊd$}Q'kÒP£~.ýû/‰Z÷Îr.{åÛË"_[Ö(º)þÀ›'ßwsf´œ9ðyú×JëÿmñÝv"	°Ž&»ö‹“U¯>xs£¿XþˆIŽ¨\ïîégn™÷ÚS£N®Û³ÏÆ‡@qÎ·û.ßx“ëÖ}…¼áw?8üàŸß|±1â±U­íƒ£òe^ü>nÑê£õ?QÏ·~Éw•C¨ðú÷w0€tÃÒ÷M‹^zèfTä  €^MTì½òo.¾Ò–9iœÑ4ÑhÈšoè8¡\^]aƒÉFcm¤¡hRNo¹‰JÇUöÎItFkU‘µ†‡õƒ‰iTÃºq21§©D¶æŽ3©bÒ4FÎdŒ‹±V¿ˆbcRRÂ"$éÉA’¯U£Öšô4!MÏˆ­£Ê2YÚšÞAªˆƒRwVœµM³ÃÄVb:'VÈDm8Ý¶””º¹ö'í7}úŒmÿÚºç»Ý÷Ýçwë­u#¬X±¼Q×ž‡ýôãß>œÎIºAí–ÜsÏ½ãÆ¯>Ë? Ñœ?ýtnÉ±ØÏï~ÒN<óJ]]¿ñÂ»/îqe§ºW-%|~û®wKÀáí1pÒ€G~2YâeÓÿ•™ÝWQVêàÔ¦õ¾2éAîíÞãMÛ‘¼êƒ_Š‰ëmÏ>s[Gúªå™8õæO?þUó‹ûßÆ’!÷ÉëµOñœp¯·ö›Ý<Ñ;¼n K¾ÁÈ÷òvÇõc»çìäh)éä¨®ÐDcÉëË×¼G3Žwß‰ycY,é!…Çw®ûàë¿n|:z–´«Jý–3‡¾X¿.ëÏ÷¼üÆ\is²Zþ>²ÿ¯Ÿ¾‘æ(%“x'jüIe¹\LnºïžQýVO8)lÝ6È[NdÅ¾“~†7fvå )ÕòïŽÜpwÕ’õGþIo`ÊŠ]ýË½ÑKä¼¿ŽêKFM¤Sîû“   ô~ÎN]\Yz¾…ŽŽín³Œ$(ý÷ ‚3®º|TÕxæv7QiH¬j¼ÌJ"Eü'âëÏuHeëéB©R%ÉÈj4-g4p"…J¥P¨”Rk5¥‹É’T&®‚3b¢4¬P©”4Noihj¦$K£3V·c¡‰‡TZ;šŒP™š*”Ê>‘RéMBIe¤ak×$Q5]tåååNNNÎÎi?îìì¾ð•×£^‹‹{ûÕW—øúŽ«šðÈ£õgÓj¼Ÿ°š'‹½ÚåíSmÉªUïÔß’G	°¹%‹^ŽèÀ–¸0üY®ÿ¸Eàè@Ê[hÊÏ¼ûžï»µw]o[?˜´ÏÉaÚ-ÖÒŠù2'òhßàÝÕÉè;÷þóÞŒï*xu½­\ú£æOÂRL\ZßÊÝN<ýà}¤cJŒßg|jtµsÿúý˜Ñ:br÷K¯¾àçÙ±ÀïyÛÔ±¼ö}öoR2dúÔ›ë÷¿!ûøK¿Gf|ÿÎÜ_·È'Œu·äåæsOºÞûFb8Ú­Ø½î8çàñxá¯¼öAbüwN#QBÚ7*“Åt$ë‹Ï·µŒ™µ`Åãº¢áF±Q kë¿¾Ë9_2À÷©WCUã6?3ŸïÊ#¦??o¹a¸hÒ}5Ù–âÂË¼ªÊ#|Ñ”Ð`kœq°Á/þ²q]ïÁ%/“ô”YÃ<R?C)>ñýæ²ÉÝónSÿ3åâ‘)›†¨,xPÚ  `œpØ=W—~ùØ¡ƒ;9R^{ˆR4™*—+ë¥#"eâ¿×»Û\NÑqŒT$KKHÑ%Ò …†3G+WÜp.‰*6UUóŒÈ¿GVÝ¥j~á4>	’*›Y±P"“ÛxŠX&¯®ÀbÒ¤d±²peÆ•Í/`]û÷#E£„çæ†|²!™†÷Ý§
zR4X4kVõ»sæÌ™ÏR?Ý¿ÿš&,[8fL[ª]wå–Ô>ZKÂ^êà–8WÆyô°k?§'÷´0s¾éêÅ¦U·ùN7‹Zª—ñô?<øÎt¥tM½¢bÝfÇ§$O}~jUÕÝKySË-5ÁbY›*qDÝ_Ñ‰.Ý³í×êÛ¼!7O~èñà'xv¢PÇ÷¼U1–÷ë¯%dÈm“ŽK#ŸgÞ|_œºþË=¿j÷äÒÞpë„‡RÜ{÷D¤} âé_q—dMX_~tˆå²¥ÄbicÄQ|üë÷ßù"‡7æî€%	SoicS”+KI³ë°˜²¿ø`Ã/¼'Í~:ðÞÛÅ­	Cä¡–Œ¯—¤Ÿ:s±f¢ëàoQÍ]tßh›ÛT|RóùßüÆ›8ÿ¥oH<rfùûoü¡z:pê¸’¿ü{CÊ×¿õŸ:éÓwçKõRùü7ß'Ý¿!qõ«9ÿ\<ýæ   ÷¢§h Ò¸ôã_µXŠŠ¯º¹v¼œl—±*:^ÌJD¤·ÉCãã…²VÇ•¥ïš£ƒ£Ó©+€?ìïíí½ú½U»w«éÍ˜Ê±;/_¹b0X+><bÑ«7wgºÑc[Òá_2_¹»½ñ¨gú÷¥6*»WäŸ¹r´äÊê¤3_›×ñ`DÂ¸Ð¡¾9›ãÛ0<‡¨ OzãbÑ•ôŠâpÕÒ•uïyà«·ÍN‡ËËÏþø)­—V8ñ\Z*tø‰¬±£Õ7 ºM(¯píSº·)¾XÌèÚÕÍ¥,ÅÅÄµýKµÿ}þ²ë`‘ígZN|›ø—êµÀË›ÖîãÝ­úç=ãFÔ…'·uÓ÷¼‡<uãÅ}ß|W,UÝ[SÅÚ’ÅÚDeÙÔšoWë`19®“Ûœé   \.Œ3Ÿ×‰Õ 79ó÷…QÃ;"³²ååå'ÏÒ‘~šºzõê×[¿úñÇO:Y;ñFÉMwÞyÍ:=¼@/Ú’sç‡Àç9/ùâLâé:áª!+‚†YJJÏž¿4tp»+toÀqäìß/ü^øÇ#WÎŽmü˜‹»ƒ“íÝÊwàö,¨1Ô‹ ôåEÅÝ5   \ÏÜ\‡ûŒ«œÅ|ùÊˆ!¨Æm7Nçº	ú3ü.Ž®\¹ræÌiG'ÇáÃFtÉ½mK._µp7tð€Ë\ù?Þ8þ¿SWIW7ÚeOÌýøŽgþ¾èâÒ¯Õ¡—›êÞö~ã†¹ÑéáËçÆ6}¨‚+nîYÏŽËAº½Š£ƒC?>.­   @£'H7ú’~_Ðßåôß…å-u½½Byy9}§®.]žnP...É7Œö¾¶éF÷m	JJËØâ+ýÇo^÷îÑûÐk0ï›(ošn°æ+eeåH7Hw×à è3**¬½øâ«
   ºŠƒqëÏ`ˆØ¾ç*g)¸T4x Ûu×‡ý(*¾zþ¢yÐ@·îH7®%¥¥¦vØàýÞñ³ÜCoçž2u¼ÎûHŸ%7c®p%çÎ_yyÎé0@[q%eWí¹'   èUú1Îzßè»
/Ñßžƒ„±z“òòòüÖÁÁÑ]( Ð9ÍòX³xˆ»³³SaqYàª¿~<VÜåÜåãšqÃ@W§’Ò²Óù…U£v €v(¾RÒåÃ©   ÀuÈÙÉÑÕ—Žû¸+W-ÅW¬•í¾3ŸÇ«ümd“@¢gï–’RÎRRù»ÔÉÉÉµ¿NŽ™µÌ—¯™/­¬ÇAËIvŸkó9v´ñéƒÜœ–{æžAŽä
gÉ?Ïº	ú:1d/€v(¯ ‡*   3.|\×¿N”––•”–Vþ”Ñ26Î${ýs£¹M–xÎNô7M˜t)]¸X4x  ªMÖÅâ²·3Î¥~WPÌµtaØÍÅñÙ{½0TèbüXó•—Š;ßh@û”•W˜/cD   è8At.
 }Mñ.\*â;;(àÕDH;f·üXxü,w-5^(éÏ8rsäæ<f80Å}úaÕl4ø;_h.)+4À­óñ€v³””]Ag   Ð!ýûñxÎh¤  }JE¹|åjÑå+®ý˜ÂþmiŠUZV^È_¾jôwqué×%•Úp t2   è †Çç!Ý €>«ø
wåª¥¢Â:Î«K?¾““£“£ƒ“£#M@Ê+ÊËÊ+ÊÊÊé—¯rNtžþ.]9~-€¢‰ãå«%hD	   màè@\úñÐÁ$ \h)Éb±p%¥¥¥e´ÜTVfœœœèg ³³Ãsæó»¥" WQ^Q|µ¤=E  @‹èÌþ.<Gt+
 Ðp tg)£Ù$ªr   @S4Ó ×*>n  èv8 º M78K)W‚¿&   ¨Csšn Þ @Ï@ÀÐeÊË+JJËKË¬?   ®WÖFæNŽ<ž#Ú¤  ô$ Ý¢´´¼¬œª(·v\AÿA  €¾‡Žô?úC+G
pÆ°  ×ˆ3€n@Onœ	Îo      z
`       `÷p       €ÝCÀ       v       Ø=       `÷p       €ÝCÀ       vÏ™ @3***ÊË+JËÊÊËËKË¬?t
     €öspppvr¤?”³ý‡Np ]Çáª¥Œ @—¯rœ¥”þÅñœyÎNÎÎNôý$      Ð~ôÒqIiyiiY‰õ‡^G.ïÇð]úñIAÀÐXYY¹ùÊU†'páwm       U***Ì—¹«–R×þýºäZ2€®rÎRê.tá9;      èN%¥e…ìe†ÏëÇt¶*€:lñ†ç$tíG       §°ÅW-%en®.¤º2à¸ú_Dƒ…>¬¨øJ†×ß¥Ë€     @_±\áJ:“qt6à¨
5*ªÐÅ5œÞ7Ô†U/ÐÁ¡º£W„}ÉUÎBßOÔÝ      ¸VXóUZîîp[•Ž[•i”W¤Y¥êf_­ÅAÃÇÊAl+9TÝqp@ÌÑ”••s–Ò!      ×ˆPÐïï‚"Ï¹c}Žv$à¨¨QZZZVVFÿ§ëføÎ<gë(šUCÙ’¾…¾Ø²Ê§¤¤”–„émg'+gggÄöÏ|åª»°Sm½       óÜ…ý‹®ô'í×î&*UÕ4h®QBËú¥¥ý¾› ÿõV¼//¯0_¾ÊYhÀÁãñiÔÑcë}#i<èÖŸ!     ÃXtë   IDAT p­_-+'.ýÚÝP¥}µ>Ê«k1”\½zÕTö ts½ö4Í /œ¾|º®\½ZZRRµgØØ]åJèX      wpíÏp–’ôìÙŽ&*Uex‹ÅÂY,BAÿþ.×{wŒŽŽŽî…æâËæËWéžáóùU	Øòò
Ô¾     è=*û»¬,¬9µ¯¤ÖÖ€£~ºá1@PU˜JàÚŸÇs.¼d¦·‘qØÒ²2ž3Þ/     €^„çìTV^îäÔ¾ÂZ›æ®êR´¤¤äÊÕ«H7šbø|º[èÎ¡»¨fÀ\°4¶£9      zkÀQÖ¾CI[Žª^EKJKiÞ]ˆtÃ6º[èÎ¹J3ŽÒÒªs	ØƒÒ²rg      ½	-¦ÑÂi§6MP\®û~7Z@w}èŽBÀaGèßš¨      ô*´˜ÖõG]ã”+WÜ¸hÝEtG¡¡Š¡o“úL     èMh1­ëGQ¡K,--å8N èïä„šü­ »ÈMÐÿ*ÇÑ<cs     Àu¢¬¼¬  Ð\l6›‹,–ú_yy»¯ÀC¯åèèÈçñù|ž@àæ&x¸{ôÂá5Z
8¬S**ÊÊÊèÑ9d°;6º	Ø¢¿¦ÌÉÙÙ¡¢     ôm—ØKùÿ}¾ð’«H\Ñx‰‹sY…C9ý!}ã‰ýwÊ§ë’5(±Ðp ´¤Ätáü ’^£µab+*JËÊœ1ôiÑålm,TÆG     èÓÌÅæ“§NYœ\ÞP&pfé$TÚè£œœœ\¬ÝrºÐÛƒ¢¿/]ºt*ï$>Òk¤ÀU@zVjpÐ¦¤¤´Ãh3º»8Ki¿Œ†¨Á     }Ò™sg/s%ƒÆ—;#×¸0àÖ·æ6t¹ÖZª—Q5~Ji‰…axÚŒî®’Œ¥     }ÖŸ¹†BÎñ’ëhkº×1wwŸ›Ç^¾rÅk ×Z³GÕ( •Ýp”;;ãmž³s¹µ÷Ä     Ðåó,.ƒ
Oy Šäo~?&Ïh$×TK58)///+«àóPƒ£x<Ýit×9T&D      ¯ø3×Pä(¼à0 Ôã5\ìàà`ø+—\;­tZY‰£I´Ý]ÖŠ/      O9sîl‰sÿŽ@â#h‚p6ÿ¹FZu: ;     ú³Ù\Pt¹¹ö}IB¯%ñ–\¼XX\\L®…V:%(«wvÀ5Á²,ý¥K	ñK3p    ºÐÉ¼S–£ÊPÊyñ¤‡
¹Z¯Á `Lêÿé4Ø`$2Ñ$Äe â    è2—ØK%ŽÆLVy¸{”––²E,éq× ààöî6Ýwýá4»	 tÎ¨‰	‹Êj©DÏÕQsüýCâ´&ÔI1)¶éImx-)‚ƒÄú¤„,V Ò$%¨¯q/Î    }Gþß;zŒ" m0Êk$=`HCü`ÿLÚ”ˆ°-+d¥Òä 	cs&†°&N'”Ôh°‘’a”IˆQ“ÁŠSã•b›«ÐDøÏÉh-/*âw&ˆÒÅ¯‚3êõ¬P"[\#U…*Ó"2²ÊààÈh)#    è¼²ò²…Ë(?B›0àÇô’¼{´REŸ9@YmÌœQlj¨´qÙŠ5ht¬T.U=Àê²Ô¬L¥è` ­Lê˜ˆ$]»êe	%á‘Aí+•s†¬˜°ˆ4=G¤Añ±’ælFÈÐ†aêO#Fµ5ØHV†%¤håÑr¡­'Keô¯ˆkíˆ˜ýeµü*8}V„œ¾ËÊLF–(A    ºDAAaÑˆkÐäÀþ8p !!¡êvxx¸ÍÛ›7onóòÇšXë)¼(4RÙ›Kµîî……ƒ"=¨o&pœÉ`äÎ¨Õ¨µz#UHëÒg¤é…
…XDŒY!ÓÃÔõÿJ­×ŸSÄ Óè^«Óµ¯µ…NÏJ¤©¡2aÛfçŒš„ˆ°$-K„òÈäÄPy[¢‘†‚"ex¤Â?"!% =<4T–E7An#áÊ‚ÓƒIwhÃ«ŠiºÂ´º/9Ö¨7˜XÍ7`   t!sqé?‚”·ã)ºŸ¾ÁÛ›Þø+7·¹yd&ØšÌU¿¿zë™‘s–-˜\U<æNnY½õÂOG/˜6œ´¨èÂ2h[£©¬{çc=_1ïµ9ÿçFzRAAÁÁƒ[¾Ý
“:*$E\YL`õ1iŒJbLIÑÕ\š¤¥\+’HDµ'Á&]†Ú(U©¤m,X4^£.#Ë QÔ^z¥E£P&kO 2xÐ`³¹Gûpº8ÿ¤šK»ÓoŽ#ÖBR¨(C#Žnù.#Q%&'ªªÞ5cVXHèŒ$ õ÷ 6~˜´1)"	hcºQD$iM„‘Å'F«$,Ó‹¡Á²(­ÎÈ$föp­‡¶½
ú‡›®j}i¬.Æß?Å(‘IÑ4    +™Íæ—ö††Æ¬\YQQ±ìõ×››gßO?Ùœn¹ðÇá_þ 3¹zSNþí$ßláê.Øù÷‚ÇVj-MŸ.}:iÕKæ\Á¿æ±»Ãé³R4D–(©*&eª m’I›(V8cVL˜6(5YUs­žcu)š …R*ìH!cõYij¥BemaÒÄ%äJ.#ÅÞb…ñFx<^Q±™ô,»8YdæNQDŠÐº¯9m\Xš$:^aˆÊÒ‰Å¢ûžÓ§ÌñÑVþhnO£EÉø Ž¾YsüSªæãL“TA ºH›k°º”šnq@d¸¢EsŽ&ªq1qÖö/´èŸÔÁd¶z;¥Á©™]T9ƒ5™‘¨M/¼‹_Eåõz#Ýí‹–    UKIi;G¨¨/þqß}¤[0cn“š/ÔMàÌçŽŸ- |ÁèAü&3Wþæ{ÃÕ	IZ£Iëï›T3‘†¦‰DõNž+KN;w>\µ¼ªeˆ¤r’”f–M,'µuÉ|ßbéé@©ÛŽŠBM£)¥Å¿VÝ8c.»t²¬Ñ£ãG9‘vbÄR1›e`U"£Î$	¦™’ÁÖlÒàôßƒ9CZH„>89Vam¢&RD¢\[¬6!*EOùAÑ‘ÊÖâš
d%ÅÅUwB“‰ÔhEûj+²â¢ˆJ lcžÀj¢¦ÏIkûp$’€ÄÔXU‹Cç_…M&ZÏÑ|C.édR    YJ,eíiŸB-˜7/xÞ¼ŠŠŠO““››gíúõ¤ãMzií¤z÷¹_/Ž+43H1ÚöÉ¨å‚¹ˆ#n=z1L.—oÚ´©êö˜1clÞn«O‹ˆ1Èˆ¯9¿æôIa:‰¸#'¼ôb$¡¹½ô–À…&†Ê˜Ê3sž‘)Z¨-R„GY±L"'íÁç÷Å€£ì¿FSOÑ÷Âû²\üÜ‘»È‘«Ýóº+icFÈôˆÊ~4ÔiUS2RÄÊ ‰Iu×ÍQ5s1²èÌô`)®êBoÃ²¢"RôœPÝR¼Áuê´”¤´,½õhIÄ¬ÁÈˆ¤(ÊÓp!…þÄˆ¤Ê€àà •¼µê"Iº%5=`–cš­»Òu¯ÂÖ²µjK?•;    ë9:²
r­ñ›?u½pxkúa3ÿŽ¥Íõ²qvWÔÃn”+î9ëÅ­Ãz¤|¨Õj_|ñÅªÛ~ø¡ÍÛ'NœhúDÎ:È`D
	HŒ®»zÈ4jVÜ†a	ªšò4˜P—c¥×úÅR±)Ak’Ñ+žt%qi²d¹´qÍ“&*pŽ6ˆ^UÊ%R{9Ã¾6MTt·‰ëÿé
â€ä£Ö^Œ¢bRGÌ1Åf&	õq!q$29R&äis¦GiªJiÖ&*D¢
²ÂnÏ
«]šP&*Ðh¹^k×åS‹&²QQYFzÇÆÙà8“^‘’––¥5V¼"yPdd¸‚K
L1‘)‚ƒ%ÆŒµA¯NŠP'E‰åªàààæ+t¥mì^Ô¤›’EdÊ¦ño—¿Š¦8£:CÃÒ]€|    «ñyür‡Š²
‡¶?¥¶v†¬SÕ4ªX,,t+‚f/¤U¿·:ý$ÿ–§ç)G7\ð=nfþS»ƒþ¬'Òsæ½4K6¨W^7©cÃ2˜ äÔÈz}ð›´iY¬4\ÑjO,ÇYQ¬?‰‘*”Â¨,I¡”*e$Ac–JY½Æ R„Ú¸ä)”*bcZT`\„P¢PÑC»®KZ,>¿§Ûu{ÀátÛ®FS~ýíò!+_¸õ	ßÁ7“îÀqM1’ Ôßƒ««<¬mRŒ,:…nÆ3"ü#²L"etj|p]ŒÀ2¢Bb´¬PY?”­Ãêhd§6T¥r"™* (8¸²£è‘ŽJ”AÑòÐh–†.Yi))ZmFýËè§VªÃa˜´YZ©Trq÷¿Š&X]Zš–#%ò   €®ÇçóJÊiñ®íO™zÇîd”?hÔøÛ#k£®èÔ‰„?ì¦á[³s|¸<f×9B†#h¡L=L–òâÈšoÓS¿Úu\à—‚ç'½“HŸ,iÐøÚ¤MŠÓ’›´îæ¬`®^•kÖ¤g…:¥¤Eb¥Jª³Ž8(“G&§Òüƒ3h2ô"U¨­¼„¡¹G,½kÒ©3ÒÒÒâ"$=µ=­",%}1àppo\'¢ØÌ+sù7½1|ÈÅñ^íîq£SVØô°¬škÀ•£¨0Ò€ !
mV“çX“Q¯ËÒË¬G‡Ðíq@l*GB¢²bCLÉ‰•¬µ5]H”ÚÈHƒ›V(‘JDbÍKT*…LÔµÑ²P,S†ÒŸh½:-)))K§ÍH*:<’gPghLDÐh”×î}µkÏJÊ0‘24H†þ7    ºœÀÕ­¸¢¤]Çš¤¤ªabéÒ>Ì0å²xeÝý¿¥¥5“›&Hmµ*¹ðóÇQ‹ÓõÄcä°‚S»–.v#«^V6ßü„t«2èVeÀ+èÏð¥­Œ9{	%²}^Ð3Þ˜¨¢LnZóÛÚÝäžúU®Mº,=‘¨wÔa-˜$Vß2„ÓgÄ%å-ÇÈÐ+”¡ô'–eIûFd)))qsže÷£¨ˆT‰‡T‰ÖRcô²èÔÄ`™ÓÅÍ‰!6RœIoÊ¤ú¸ˆQr°*y1'M–-LËR&×«Ð•„7d±ò   IDATÒ øt‘0$,-)0Ð­4$D¥èX$&G6ß½¦P™¾‡t3¡Tš¨ŽÔfe%ŠþTvî¬a…Êè`yã—Óý¯‚Õ§DÅhhª”tK€   pss]<O\Ûq¶(›0¡ÑáÎîýpeLúŸDp×ã³št®qá›ÞYþ¾æœà–y«VŒþ5qÁ«ßl]º˜ðW½<­å.6˜AcdƒH˜Q©þ]›·[F³ˆˆ°$29\ÞÚõ¼ÊSs½$4¹Ånü9cVTXœQž«hÓBFØÞëˆ¦óç‡ˆzºruûFúé¥Lš¸˜4“PÈ˜²¢B¢2t½KE6Þ Î¨Õ²Ò ÈØ`aF-`²F‘‘H„(AwcÄÊèÔÔH¹Ð5'Œ|B¹uðe/Ð”ËB:XûÁÚ
%*Ë(TDFªz<` àqaqZŽˆUÁ*Tß    èîæÆvôÀÑŠ~Ý¾vñcO½š~ØLFÎx-Ì¯~çEÇÕ¼ôPhMº4a3hZXlä]äÔÖ¥+Ó-j°°ªö==¨GW`õY1j& >6¨•êÖtÞ¨@ÿ(½<:6@Ò|ÇÿÚ´¨Àé:Ylb3ƒrõ~wTAa=lHÏ²ÿ€Ã¤‰	‰ÒI#Ã‰*:1T¬ËJÉ0ˆ¶âsµš•©db‰*<X.äLº-+SJkkg	aiúÎ½ Í`DòÐääÊÑ˜‘„‡+zIºÑ)¬.)lNœ–«b[øí®•ëÓÂCÒÖjyñÑ¨   ÐMEƒKIr=f!CoQDoX³¬a«®èÜ/»~8n9#2é«šnTMfF?²hÙ¬‘Ö®fœÅLK‘Åž"WÄ…Ü{ûô0µ0(13=ZÞr}“6.,F-NÞ™Þl-qzòx×]QaPrjlóW'YŽãXÒq/]"âàÐÃ™˜ý7Q1é4Fid|¤Ü—TyZ)KÑ+bm\ç	YL@båa!	"1a:qp¢LÈ°Ö™­o¡ÉÄ¡:t#¡<<>Ö8'"KŸ"NM¶ç:œI›—¥çÄÊØäXUÏæ5¬>#*,*ËÚ{©$ 61RŽÎE   º-¯þuæ/2ð&ÒcÜÆ<ºê_Ú|ˆ~÷ËII3,#oÝ¨ÙÊ É/®J	Œi8}¸òíï”ÄÎE"¡HžœÜ¦†Ø"ydjzk‹”DÆË„2y‹¢0’àôÚ2Žb³Nå=êÒãì¿et²õ@e«úµF"ex¨†I¬^«GW]cfµq!1zixrde"”ª\Ô½¾&F™.BÂÝˆ±–ÆM¦9qZmLDŒ85¶·´RiÖ NŠ‰JÒ˜¬½ú&&ÆödÛÖ:ælJR†ÖúW/VFÇÇ#Ý    èf„Bþã GË¥òžÃ6føÿÝjó·acÜHŸ ’‡ÆÊI—bD2EwŸ:8óxB·kð.\ƒ€ÃÏkŠßì)¤‹	åÑ™UiUýC@HóŠôÚÛA±²ºÙ#ÓwÖ=›+#S•‘ 'e¡‰‰ÆÀ9i†Œ°0Qjr‡+p\{ÚSqÕÃGu¦kÔi²ÒÒÒÔZ#[õ‡ÜÉ¦6mÞ"Î NIJÉÈÒV9K£•èØhŒ›   ÐCFy:qæ$3ð&®Œ 4'ßô÷(ñHr-ô‰NFìŽH]Ùa1§KKRÚ9pËÕ$íyŽõI,Ûá„ƒ&1!aqZ#'Q'îüaOrh'Òö¾
FÈ˜ôºÊ½ÅHTÑé{2ã‘n    ôWWW7°¸§{;òg®Á}à@z¨kÁáªÅvøVQQQVVvåêÕ‚‚‚1’ÑÚãÃ	—~ýœœœz¾ch»‚Kæaƒ¯U™3ªS²XEP€ÔŽ
é&mJŠ^¬
P^›æŒZµÖ$”ÊåR´%   ¸&¹®ŸÇGwrýq<±ÿNy—7Gè;òNK,ï¼IW8{žõ h×Sptöâš     v)ït^‘ƒà:Ì8p´ Ï˜çàè ÞeCv àh©‰JUÉÜÑÑ‘†ÚŒî.ºÓHÍ     èK¼Fx1\á îŒJ<PéÏ\CIII¦Óz4Û°XJ´Ý]„      “ÜàíÞÏqÀÅ?Ü;Ó‡=Ø½‚‚‚œßs®®]Õ2¥3ZE…–ÒiçèàH“†éCÙº»èNs¨Ü      }Ò0Ï¡nÁ©¼\qvtCQÙ5£®¡‹—.žÊ;åÌãòéÚÿÚô*ÚH³‡ Ceû
GGGg'‡ËW®½bsíÝ]t§Ñ]ç€V*     Ðw	\Ò±>l›Ÿÿ×åÂ‹ý‹‰ë âTVáXVáPN ï(++³P%ÖÿLÎxŠ†ŒuƒÐÍô­dlÖ˜ÃÁ‘-*"L m.±EÓÑ     \„nBú#)//(,4›Ï›iA¸Âb!å}0âøQ»Ÿ\—è%|~%7WÁ‘hì˜›{a™·¥€Ãšm8:òxü"sqyyyUÇ™Ð2º£8Î"¸Ykp ã     €ë-4ˆþ€k¤•QTœœœx|úŸóß¦óÚàoÓ>Ãðø|      ÐcZ©”A‹èÎNNÓïÜßÊúbý¢®EwQ¾ÉÔéçŒt       µRƒ£ª•
Ãð]þ¹ü¿	´èÌÙsŸé×©ª¾Œ       g´^ƒÃÉÙ™Ïç»ôïoºP`6hË/(ìßß•Çã9¢      @j=àptppvvîçââÚßõ¸á¯«W9M\¾råxî	7[?—~tw9¢ú      @j}`”ª®F†¸ºº¸ô;ö‡¡´´”@=K	Ý-®ýûÓº£Ð½(      @kSÀáèèèìììâââ&À0ü_ŽüvñK RAaááßôý]ú¹@w‘µúˆ      èYÎm™ÉZ‰ÃÑ‘ðxý]\ªúÎüãÏÜn‚Fäóùäze±”üuâÔ¥¢¢B¡@(ìß¯ÇsBº      ÐãÚpšz4Î¨î•ƒÏ7ý¢ûuøpÏÃ‡999‘ëIiié™³çÎœÉï×ßÅcÐ AnXÓÔÝ       ¸&ÚpP´ôN[3ŽÊ&+w¹ß•óóŒg…n‚Áƒ	\ûóxÎ<ßÉ©õ–/ö¥¼¼Üb)¡ÌÅ—Ï_¸À²f—þ.îýû»0..ŸÏCË      €k§©Ì8***haÞÉÑ‘WÙó¨‹K?‹›åÊÕ«gÎæ[J-å¥åå„ÎRA#ÒgT&ŽÄÁÑÙ‘ïÌç3|OÏ!4è©ªµQUqé      À5Ô¾€ƒÔ´U©úíääD‹÷¥eeýKJKK,ååee4Ü(¯¨Dú
‡jÖ*,NŽÎÎÖLƒf<•jF„Eº      pµ;à 5~kU–ðùeü²òò~Öl£¼¼ï9TV`©MvHe_$ˆ6       zƒŽUjrRÓt¥¢2¨ÒÇjpTÝ¨zµ¡¢      €^¢ãG•úEý¾j4¡4§¬‚œ2“‹r¥Ôz»×rr ýÉ åFÛy8—••Ïœ6›ÍÅ—‹KJJˆýãñx®ý]ÝñqU?Êm×÷öF×Â¾µöûNÙû1Ö™í è|Ãö¤ëí\¨—|¯9\µ”€ëUÁ%ó°ÁBÒiùWÈñKÄî¾1ˆ§K[gþÛd2üeå5ÒÅÅ…~xñù|bÿ,ýÚ¸|åÊÉS'o’Ü(,jãûäÞèZØ·öÂ~ß){?Æ:¼ý  ƒoØv½uÇ÷ÚÙó¬Ç A»ž‚€®k]pœ*"ÆË½ºÖFsœñXZ•ÿw~aaáØ›Ç’¾Kÿ{Î`÷AC†iuÎëaot-ì[{a¿ï”½cmß~ €ŽÁ7ìµu½uÕ÷ZT‰è”s—I^±]¦M7éÆÿ}¥•ÙhŠ\p±ï#Joö¹PxÁtþ|Ë³]'{£kaßÚû}§ìýkãö t¾a¯¹ëí\è~¯!à è8šküÉ’rbÇèKøýiaô£²²2Ã_Ÿ1×Å7¢ÏÍ>ÇÇËË›}K¯«½Ñµ°oí…ý¾Sö~Œµºý  ƒoØ^âz;ºVßkNKßXF ®WW8‹[†tÔ)3aí¿{¦ª˜s`3»!Ïhôpwwss#×GGÇK—.0Àæ£×ÛÞèZØ·öÂ~ß){?ÆZÞ~ €ŽÁ7lïq½uþ{Í|™sé×¾.HPƒ ã.Yì¯cÑ¦Ê+_HsÌÅf—6÷Djÿ\\úÓ—ÜÜ£×ÛÞèZØ·öÂ~ß){?ÆZÞ~ €ŽÁ7lïq½]“ïµÎp=+.%}ƒ¹ùR|¹Øµ¿+¹n\]ÍÅÅÍ=z½í®…}k/ì÷²÷c¬åí è|Ãö×Û¹Ð5ù^ëÊ€ƒ+#,—JJË­ûKÊûÂÅm¸æá9'âìHò*<˜
¦×T<²Ó¾E›já…”””\W£ˆÑÛÂ`ã×ÛÞèZØ·öÂ~ß){?ÆZÞ~ €ŽÁ7lïq½]“ïµ.8hAsÎÁÜW®fC¯B‹Þ–ª¾iÊHQ‰CÞe7g2ˆ©pçWðÐÄ
      *u¶€Xh!ÇXÇSÅH7 ç•’ÅôÀ»h!v­èuÚ©ÿþõ®Â±,WïŽÉ¨‰‹HÑ± Ö NIJS8ÐYœQ“”¢6r„Ó§„„$á# úV—4Ç?"ËØü7%gÌŠ˜ÓpV8Ÿƒ}«K‹KÓÛÕûÙñl	¡¹Æå2p-\)#9
œ+¼úW¸ñˆ=²œØõÕçFSÜsë ¶årá_ÍÃnUÛ³2wáä'
hÊc¹pê?ÎY,æ³§Nœ1»Mžý’ìºì*›~Ï¦‰S£eBzG§SHt´+t|´œNâê-‘(k63ju¬T.6™SŸ•B‚cƒ¤]¿±4ú!BaG—KËojV jºÑö‰Õ¦¥™¡R]Rš.<XÖ¯ª­ï;ÇqÓeox¯{§Lšÿ9Æª;B‰\$5š.U…GGÉEL“§XI‚Ó3£å=ü‚XMÄô[Ñ”8 ug¼‚ngÒ¦©õ¬.%.EoÆÌÉâL£8<=5TzÍ>y  :Å¨Ž	‹Ó˜Ä¦°Àë}Ž*¢#|3be°2+*A-‹U‰­wœ>#I'R%Ö~	Ñ$¡Pí1   IDAT"M¯´~ðGE¨%rVMó`ú!©72©XÈˆ”‘ñ¡ò>rzÑÓLY!þQz¡DÔè»†î_VŸ™Hw{§ÞÎ•a˜„©Õo±è`ÀAÓC‘CIÒ¸ÆÌ¥‰[…Ð2Ž¢ãê­{þ¨«¯a>yÂL,‡ãó‹«špÓ¼)A·6ýðàÎØúÃËÿ>ÿøÏÉ+Ö¼6áÜÁ¡ŸŸlv=‚A×¬n‹I6=$Ëdë!¡<²#gûœ1#lN–"99HÂfÑïXibzs¡sš^¬¯þV+‚äêN,¼¦ŸÈœI“–F$*kA—F1B•T›¢!±‘rQÃ9!Ãê³t¦ iÕY‚ÉÈ
Å¢®ØöÊK,i’øÔÈÚ²<‘%	UˆZ_€IŸ‘¦©TRÒ[p†´9Ó£´M.+ÑÓªÌøšï`NŸä?=NoëùŒ,ZA„b™´zVë%§(SxzåÉ@—l`›Þw“&fNŒQ®ìš£´÷½S„‘¥fÆÒ €3ªãÂ"B¢˜ÌÄ ¦n:kÐ¤ÅE…âÓc«÷~íS®¡"þ‡ñµw­DaÚ äx.4U²†J"m\T†02=Y%&ÆŒˆ0µ\)±“sA €FLÚ¤¨SpæÑêk ´¥—Jj?—MYaÓÃjOøÔwe„Õ{¶æ®¬ëròÎDyå³i	L‚žèHƒbƒC­ßá!úàäXE}ç^¿hÆ›Øä»É˜¦©?¡#oK¯»E¤qôÒŒHdJ
‰àc$öDu$à8sÅÁxÑô4h;Æ:xõ¯æÒ»ûü,:±'õÃ/7ž<T>ã®1nÖ…ÜFI‡×œ_øù«÷ÞIûÙížW¢ç)ÜþT§¯ÿeÌ³~·èwí8|æ¶‘n|`ØÐ1£n3ÚƒÏ·œÝ•±ë¬Çm³ž}þé&¿¦gÔ"eâ¡‰M&Óræœ0­¨ÙEk½î(ƒÍOYëÅm¦ú
7ÓÒ•nzÑ .EgätÓoŽ«H/ýŠ¥×üZvÝF3™Ô“%›L,©Ý#œI¯7°¬QÏšt)!ôò¯QOsuyhrrd‡®jp,ÇÔÕ× Ëƒ8 ²þž ßˆáÁÚ]hb|«u*ßÒ›Ð"púïA&Y3ŠƒBV—	1ÒÐ'B›>™q	F–‘(T’šY…ô
»ô5¶þ¾Ó¿EtjlZTT` 6>9ZÞù½Þ÷NÕ¡Ç\h¸R£Õ³²ºÉB‰"4>‘’ ”õ–s'V– ŠŒ•dÅ$hM•—ºXýÃˆU‘Ñr}TýÈ²V,±&«F‘JGã='Ñ–þ=X±Ä˜f½†›™¨Â)< Øë9aô¼A¥ªþZ¤9nJQÕ/FÓË	‡T‰­/ŒmTŽž†¥˜Dbºè„éQuú„?þøá‡‰ê^Édúæ›oæÎKº=Å‹²~75šjýö	hæm|ŒÚ42YÅ%Di”±¡LZBšVmoU»ŽKb¼L z›¼ËÄÅ‰ìÍ»ytÕ¿ƒ†ªj;Âùã°æƒ•ïÿ2R1gÞ#£|.ýñÕòÅ‰Zë¨ÑKšW$…=ÿü=1j29zÃœánôùsÒvÎ©ž—;±}ùü³ò—W­zü¦ÞZ®¡§ûZ“ÀÍn PØl¡Œ«ºFßÊk3iâ"’L©‡j3N—¥—÷²¨™‘DGI½}aÌ
ñS›„4‰‘Ši›c$ªàP©D"•t´ö†IA¿•âcƒ¬ÅEVŸ¡ædÁ/'[CÿtQJDXHIM®­C£¢½48ÀÞêÖ5i¢ˆV´^‚5êŒŒTÕèØ°î©ÖžiíØ…cDB†îâ"6V)¶.Ï Ñš$
yók¶ñ¾Ós½QH¯†‰äÁÉ™J½IXÿœö½6§èB©R.ÎÐiœ¬Õ=±¢ï8QF§†ŠµÚ€Äh‰.*BÍròšÇ«'DGãô‰ô#È˜¥J¤é‡I–E  ìGeÍB465ÜÅ$F+8ôäÑÑ/q†Œ¨¨´š®¬‘*2>H¨ŽË††Ë	!qÂèfêí2²ÐÄÄª³ë‡¤šô_|ñEllìÆ¿úê«!C†Ð)ÿý÷£>zòäÉþýû?þøã¤´±G£'µå-+£ã+g°>+"ãÄ>´/à¸RFŽ›*îèmf"PAcŽÞŠTU»‚;óý‡KW¦ÿf0HÓHæÂÁ£hºÁ¿íÅUsŠÖ/þüÛ˜¥£’Ö¼¾n¦­e^8œþñ–[Â^šÕkÓb­è¨6ÐÏÒúÅ?Vå˜fh0›úö4ú[¨LÜi­ã]ô8ÒÊ•ukßQL@rdÝ<«ËRY¸¼WÍœ>%,Ð¨
P©2d4xP¬ŒÿáDÍ5CJH˜^®T6³Ýôk:-]5xz@rf|£<]¤ŒMML‰‹Ô…ÇGÊô)Za@¼Í:ˆBYp|ªÌ(”Ô«í‘•ÆDH<žÈDšèk°¾†ÞWCÑ¤MIÑK‚“ÛðŽs­Ž•I;pl51aiÒØä`‰TÆ$eéLJ±ˆæ		¦p™\Üh‰-¾ï&mRXH–0 22X%—ˆëŸ6²º¤°žJã)ÖZ—§Ùà…±æbr•ÞûNY/&i8I¤JUŒˆæycu'ÁÖH¾iUH‚Ò3c{këlk¼‘bYßy¡HdÍ½æèELe} ŽÆ•ÖkhörF @‰ä‘é{¬I>á$$jN„¿¿XÈŠ‚’Ã›œF°#£ŠO¦§ÖV|:–ž²™®µ‹ô
T˜–°ª1.ýT’>âX¿~=3h¨‘‘‘A§T¥£Fºï¾ûH·‰…†„ÿ(›?yî³oA•ö¹f‡rô»½UY…Ã_•éÝŠ~û|}ú#_Ü°ì–_V.þðÔo'Ìu58¸“ê÷ÓOþMÏ¾8Kv+Yôâoßÿe}Ì;«^{pt“ŽC‹Nüù,í7~T/Ž78½:C/V…7¨z.”Çî9[uÛÚKw`œIÕ´ ^ùt“µ±EóŸÑ&M\HD˜Ä&Ì	G+ÄÄ•¤aT‰½¤XÄˆå
Ñ&„¤Ä‘L¥


UÉk¯å×‹o‘TL²´zƒDd2zÞÀ(#ëršf­WãÐÚ&#†³õF“öÐD™"-*Äÿv#‘§Æ6Û€PRïš9«MˆˆÓK#S›¼ŒH¦i‰Ý?Îz¹= (88@Þkº›bµ)qjqµ¥Ó“&-Ã$‹”‹I[˜tY"Uí±L)IHÑ‚¤¹R”¦Ñ™TJ¡Q§ã¤Á6ò’ßw‘"vçq1QQ\bý
4Æ¬˜ˆV‘X™M°ÖŒ$ÅÐìæIC33#e^t¯{§ê§bEt|l Ýš¦õÔßÂ^ÐG-Ž¾j†¥¡….PÇ°FN^ïTP(Tj3Ö‹hñ{äúŒ¸¨">Yœ•!¤W3Å¬^Ï^Ouq Àî1Âê/*k»Â`¹&Æ(ï\wÜ*öÕ"‘è«¯¾š={65fÍšE§äåå9’N¬ßh¥ëp&–Q‹é•kb9F¤U’,k\'oA•v,Å¥H7 W3—:Z*Ü{sCë˜'Ì„/5Ücô>9üÇ/çŠ¦U5\¹°÷¨¥»Nþ/.¸Õ:é¦YÑKN†FmÝµr1á¯‰¾·Q–"³…~¾‘ÞÌÚÕ"-NªškðPy	Ô$n® ÆÒd™±ÑVƒ3j³ôB¥RjíK"9Tníx
ÐFDLÏÈ9µQ­ê5,„Ò€Èhy,kÐd¥¥¥eDE°¢Ìš*UýopÖtzN£×™æÜ›!–J$©T¦T¶ÐÈ†5™8¡¬Ù†,B©*8(K§5êÔZ£¬Õ^,Mº¤ˆˆ4V™˜hkÐ¡D¯Œ5jÕÖ×fd2“Ú–t¯ÊH IÇ)ƒÚÐªƒÕe$i…ÊÄ6Õí1iãÂ¢t²ØÚöôÊV€*+Ë`â¤bU|ºµu2«ÍÈâä¶ë
µô¾“Ê("(>3 ^kÎ¨Ž	‰ÒJ¢S«»"Ê£÷œˆ&íÒÛÞ©6¥œÉH£L™5­ëehŒPß°‰JíƒBYP´Ì˜¡Ë°¶ìŠJHÑ‹T¡áÁl’Æ$—°Yê,úñ¦•„ÇË$ À¾°ÖÀ6&Í:ÞkFXý>Dëleíz´²"€µ€¼™.ØÆ­ŒûrõaÃ†¥§§ÓŒƒFô.M76oÞ\Õ\¥;pF½NË2"V«åBS«.rM/Š\Woi{ÀQQAòŠI{]½rõXŽ¾¢¢¥+êc}¤ý\ú€®`¼ì0WáÐ‹³8Áè[†ò5ß}¸Ü<è
Ä¯dÜÜ|2T½hÖ˜ê"føÝ/®
;3?Q»kùB>Ý²»Õ[‡€“öü1ç¦[{ãÀ°Ö˜ã´ÒðT…íâ«K	K!ÁÑÁú­hŽa beã2¼I›cPÆ++Gž¬©ÎHTÑé2y\H-g$Ëz]?HB‰"(šþ°,'¬-îÑ"PL‚Žv­p(‚¤—!ŒNoK|Ž5šèõ‚æª ZÇ¬ˆÓHã÷Ä’”°¨9lr|³£åZDE%èÅÁÉ©Íµë©*w
Åò€HúS9î,¹ö8CVTXŒ^/ÌŠ‰ -¼Èª]•d’UõMÒòrMºŒ˜¨“"6±Á°'ByhbuÞaÝñ&MBŒF”ÜâÛeó}¯U›n˜t)QI&E|zdç†TéïT‹X½ZmÉ{O vc$ÊÈd%«ÏJJÑ2b6K+SRÄ±ÉÉJû}Q p=¢%^&%..ËDÏ0d¡©õ`«ì4>ª®
}ØÚh³º‰Ší¥±Mú”êãÕ†¾eËgggšnÐÈƒtÊSk Uy©Œ™ãŸT™X°Fƒ3XLV¾AÑ²ëî-hkÀaâ,åí+2Òhãå^,.n=qº}ôñ†Q7Œ& v¥ÌÁÄ‘!ýzoCfLà’$íóÔo±öÂ1tÌ-5ÑÄ 	/.[sË¹aŠaõ?‡ÜÆ<º,úäüW¿9¹ceÔhUsþ¯6Êp}·ò¦/?<þåÒÅäÅWUŒÔ›Î¤MÚ¤Z^S&¦Ú9›£eÃ°ˆ4“2>=X¦‹H±uÝÖ¤IÓp²ÈúQÇ²¢RXU||¦ü¬A«ã¤ª FÃ$G÷ÊÈ„õ3	‘26³^tnj2ÒôFNÞzõ“ªqdm÷²`ÝùQY¢ðähkñ*6•‰	‰˜Æ%'5\.-Ç«3RRR²tŒ"293XÑÖÂ#¼ö…æÊöIaœ*61Z%a”â¡$¥â   IDAT¸°ˆˆqjdsƒ¹GY[3%G«Z¬Î`-¤&$TŽ;“ÛbmRXÝÉ©mTØleÒe%ÅÅ¥$á‰É¡]ÜqLox§ZT5Ll«èdèîÁED3§êì/nŽ¿µÒ¶ÊÆIßÃ¤„£,(<>–h¢B’ô
…ÌÈöÞ1m  l¢'gô$KšþƒœK‹i±C6Ôà°öÔ,jØÒ¸W •õ8¾úê+''§îK7He' ©•ûÎD¿tR$4jª<Ãk2þ«Qw½½m8Îsí¾ ®ýq?M7^Žölñ­Í?{öí˜Í÷<}CwŸÓ‡pEE7·Þx¡¾W¡‡ko8èùñ¨iÏ2Eç–~ØrË£O®W'ƒ6AiãfÐ´yÑ/žZøááßÔßÿ1ëÿ&Ô%ÿ7kÙ’?¯ÞõË—KŸü’Ám/'­yüš÷ÈÁµi		)j£$<95ØVyÍZ÷?$É LN·–çLÄFÍtkõ­84µváŸ&¬ì0ºiIœÕgX¯€«biQHÓlNb‡ÃŽ	%R¡Ik0K[kS@3z#'T5)ZËçq	j¬¨®û"RD''J²êu`ÒÑ\##K­5rŒT™ž$ÙSQÌZç$&*É ‹L­©!’‡Ç‡‡F„‰““æ8t—$$$¤©MÒàäÄHÛ¡ÇÒ}’–‘¢Ó™ÄÊÐøJY;„Õ«ÓbtâðÄÆ™Q;^½J¦ÓjÔYYZNžžÔûªuŸº¾9„RUxrzPïè¸þšöÊô¦0ôðK”[µ¸5‘È„F¬Hˆ‹ËÇ¶1  èèe—ôªò.§omÞÆ58è§yt$#f˜Úg²&=+”ˆD"©¤ª™#MM—¤¨‰2HfÊÊ0Húæ7Ÿ——éœ>+ÅÚY˜¤Ù¶Þ×Ý[Ð¦€ÃRNÌ¥í<¥¢¼œþ+•Žööna¶¿¹ôwYiæýœº:ý¬ì–3i[É³kV=8'K-¡‡+=hùŽ¤WâÎîýpõ‡[:i¡	ï=ÑÑÿÓ¦wÓíÖ9ËÖúáÂ-Lh˜p¹y0vËø™éë?Hýî¸yè-·x\ë£ƒÕ§„ÌI`iasgP³5Î…b™"(>:4@JŒzú=È6©¿AC’5QÅÇÕ|d3B±XÌÊÂcÃ×ùæŒ:uVZZ†ŽQF&ÇWu4)’ÊHïÀUiãÜýi&–4ªšÁj¢¦ÏI36ž_sïÍD¤JÞY5„9gÌŠ‹IãTñé¿®Dò àúë±–èEŠðx•RÙê`ºÇ²lÛ_C÷3ibB"´’ðôÌÕxèµõèHmHJ†N][Z6fE†eÅòàääHe­JÄZ–‘…&Ç¶ÜP‚îâ¨9F› mnßµú¾›´	aQZF*WFf&¶§´]¯{§DŠø={lOÿáD|»žÒóèa6ýæ˜æ•×ÿƒâ*{ZÉÒrÒ èx‰.*Ì(ÉT‘ª9s"˜ôDUoè­  Xm’¿oRÃi’à&³qU©ëˆXR}ÅÊú¥Æ´zzE,–ÒOL“Fm­ì– a¥‘±b¡H$¦EÌÑ¨¢cC{eÂÝûÑ‹UqQ1&(1@Ö\¾q¾m
8.Z:;4ìw»v¿õzÕíÑ7ÜðùæÍ¤·(úß¦wþEÃoÐwb{â{›N1ÊyÑUÍ¸¢þT¿³þ7Å’èGê.ýñ¯wÞù÷Ù¦ç’ÌÈYÑ‹f¶šCØ^»|rîçíÿöâ‚ÿµý»^ê•Ý-ô"-½¶•
3l‚ß¤ÑÌcf>ûbà¤áí(ÓºuæCÍ-sòœ7éé„ÒàÔ=A­Œìjíš;ÒzƒÓÓñi:#'
fÍQ]õ®vÁòÈô¶F/„ku4QIí•„ŠØv×DŠØt…íåüP3ðLóh?6]EZ'”Å&‘¶Ê"wþ@z‘":ób£ú?}ýñ™ÊMéÉ”GE­‘’€øÌ ÒÖjb“ƒ$ò–#‰Öß÷ÊqTH×êuï”]£‡ÙÎä`[t8CZXTƒ~Ü8£žDÇrQaFF©3Bœœ.7‰n €=¢£‚ãã#ëÕàYq)5£·	%R©á¬Õm­¡hål¦¬°éaY,½¨kíB¢U?_$'Z­!49¨ª.#ŠOghqÝ¶cèå½¬,. ygKup;ýˆ”ñ©vÖ„¥MGq)é¸ÊÎÝ=ÜÇËª¯¦1¢ÁãŽŽNÎ|ÿ^LÚoEÍ=l¾pÁÃoÙ²ç'7Û+wæà‡K—§ÿf&xÜ’2¿aÄqê—ã‡‡±TÞ>¹}ñÂIÐócÈ·ßûHí5÷ÇÿøMÒÆ²-·5»aÍ¯½H÷Þ“Ó/½iTã‹ðœùœÙ"(´ö_ƒÜð9ÐªâÞ\%ÈíÖGW}õ(éËZI7ÌJ?ZÓÛZÎnn´Ä­ p=i¡oƒ&0¢®¾êul}ÍŠRS›{Éé5wÄ‰éuÙXhrfhýÅH¤½¾‹W  [¬µ"ãeíÍ=¶öv@tå¥ªçq"Uâ!Ubí]¡T^WŸV(‘)èOÃu(‚Útqšjz)°rªuè²z÷¯Ã· MT:Q}£rÙ„	²õëm?^ÞÑËìœåÜ¿èki–SÛ7ý4ó6Û:Šþø÷;‹Wî:Kø£ˆ^õl£*|R9²Eõ0Ð£sØ´ð«Š°Wž'cêõÿè69â«Ÿ"Hûµ´vË¹ãÇÏÙzRÁ/Ne7vVÓ‡”ZÚn¯Ááä@Êzs_mæÔüŸ8Ç³X,|~ïz·ëÐK_rs^o{£kaßÚû}§ìýkyû :ß°½Çõv.tM¾×Úp””“Ž«¬Á¡ûùçO““=<oÞøÛnëxfô£ë~²yœ;ñõòùq?ðÇÏyééwáç­‰ï¼ÿÝIBF)ÅF?4¦µn]¶ênÁ-ÿ7¼mMC¸g/ð‡Ùž¹…µ»É^ù×¾Wl<åxjðÜ{Ü2ùÆAÚ‚ë‘ýIQ	é\›ÿ$píïZ|¹øúùR4\]›{ôzÛ]ûÖ^Øï;eïÇXËÛ Ð1ø†í=®·s¡kò½Ö¦€ƒëLÀQYƒ£° ð°®ñèÈ…¤358šÃùîÃ( üÛž›Ù¸ÿÆ¢{¿ùðýO5g-„ðo	\µêY‘ÁÉ­QóOÜ£œy¯b²tÌäÿk´¨_ß[87ýÏfŸ<ìk)ä+í[{Ý+ºðçÏ4áßxÛ6éÔAÛfø}$àÐ|´*®\¹â>Ð\._¹,4›c^o{£kaßÚû}§ìýkyû :ß°½Çõv.tM¾×Ú:Ll'Ýãwý!Ýƒ£©ÁÏÌ]3'W6)úßÖ˜D™xÈ½xSõþä.üñËOÔß«5?7×>‘?hÌÐÖòóñ_¾¡?þ(yÀ‹¯M]ûñÝÆÜxÛM|KÓ2Ÿ;~¶€YŸéÜÚ«—wöÀžßèÂn™të0ô¾Ñ«Œc1éF5ÿÉã5B¬=tpø°áäúpòÔÉ)“&7÷èõ¶7ºö­½°ßwÊÞ±–·  cðÛ{\oçB×ä{­MÏ‘”u¸Âe•‹……åæ6zäoïîîèd´RÑ¯ŸÆ¼úåoä+íËË^SZ¶GE}ø‹™Ó±ËêºÞ¸ðË§Q¿±vÊ)Ÿõèãã‹>\¸TciuáÃü½xÛÉ]éßjNšOj¿|õ±f­IzmrUa=óõu3›>‰;ñuTpÜOn“ï0¼Sk¯ÙúÃéŸÿDÅ¬»F!ßh+^ŒKÞ1Bòg)·Ûž8è~ºi@ÕŸi338:Þ$¹1ç÷c>7%}þXÎÍ7qh~w\W{£kaßÚû}§ìýkuû :ß°½Äõv.t­¾×ÚpðÉÕ•MT~Éþ¹v˜ØZËß~û÷Ý×Ù&*n·>»â]ËâW34ï‡jÞ¯œ4ÌÚmç„zµ#ÝôâÓü³cT*nªRå‚ºmç»œüÈCÊG8¸=õÓôßnT´Ú]Ç…ŸR­yÄHåÌñƒ:·v+îìÞ·ž¥—ØT þFÛñ{$à <û[›Ãœ4;5R@†¸´2h°¨¼¢"ç÷Ÿ›}HßE?…Eƒ4¸åÙ®“½Ñµ°oí…ý¾Sö~Œµqû :ß°×Üõv.t¿×ÚVƒÃf^*3›Û&NX“”Ôè‘¼½I§†‰­ÆŸ6oÕŠ‚Ð¥ßÑ€îˆ\µH9¼aÀ›öÒÒ	n£'=¾lÒ¬"Ži1ß°ŽüºxùŽ³d˜âÙ§osëìÚiºñ~ÔÒ§ê÷üC· ]n;T´=„fýœÈï—ˆÝ¹y@ëéFOÑGÇýŒôÙß¥¿ÀÕµot€d±XÌÅÅW®^>qò$Í˜Ûø)ÜW÷F×Â¾µöûNÙû1Ö±í è|Ãö¼ëí\¨—|¯µ)àp¡sµ¹AEc•58º»Ë&L°ýxWTîg†+­*²,ˆû¡Àü§æçSÊ17uGÀ¸µP…¢è„úÓ˜w2~3Áma+¢ïÞ¹êÜ™ï?^ºòsº8Â¿iÖ¢W”¨¾Ñ.®=Ô½L5îg­ÇqÉB.—öê±c¬ƒ¿ä[;iW•1šÂòð0ž6žË?K?¼JJúB÷ª<~mnS&MnWº>¹7ºö­½°ßwÊÞ±o? @Çà¶‡]oçB½ä{­M¥@w~…ñ2éx%Žá6æ‘%±g/ü\¯}?êAk¢[J,­&6A‘¶á.üqà»MŸ§íøÍ:*ŒÇmóbc½Õ­Åe·´vîÌo·nß³k×ak…šnÌX¶êåI¶}*<˜žÎÈ}½’££#Í’	TÂÞè>Ø·öÂ~ß)c p}Â§Ÿ½À;Õam«ÁáDÎæRÒ.ŽÖ^Žéõ………-Ì–ölíÌä6áùE/ÿ±8N{î^Pk¶G,ýÇÂqô&±9—ÅlÍ Š.˜[9¸‡7½Ÿ¸ƒ¾
;ž~%lŽrT+¥ÜVÖÎ0ää/Ué†`|àk‹žouÐ=\ùŽvÛí'      ´ŸÃUK›º=sÅÁx¹}58~Ðh^_¼¸3Ç½÷Þä»î"]àÂÿ¶ï5ß6sÒðlÑÁÑ8)¸õ¶ª>D»`y~þágËÈ	]¶Àë¸Åp—6—ÌÃ	      ô&gÏ³ízJ[ŽŠ
rä¢ƒ¥¼}ÇïúœËW.·:›››ÛcÆ€®Àw¬7°¢Í¾p      ôB_4Y7   IDAT8ÚÚ#-.z¹Ci—›¥ˆz=PÑY     Àõ¦=_âW¸8¡_èÕè!JT      ×™öuíé-¨pt@éz)zpJ8>     ®Gí8\ÉMÖ$ÊÐUÐƒ³[]     @ŸÒîÁYðÉÈþ ·¡‡%=8     àúÔ‘ëÝC]¬q7;”W /G¸ö*Æ¸Uy      ®[í®ÁQ…^*ÿ¿ýÑV®1zÒCé     Àu®ã=¸8‘[Ü+LWÉ¹+\9èa|G2Ì¥BÔ¯¢ƒ)      ô!W-e¤ÓLW.XØÐ„<2˜© ?¤Ó
.™¯\å      ô&.ý‚v=¥kÆœ WÑéO9!E%¤¨Ôáj)áÊ®–“24aNsr ý	ãTÑÏ™¸9W¸ñH×VÙð‹      ô&gÏ³¤ºrPMZìÀ£?U©²       è!è¾        ì       °{8       Àî!à        »‡€       ì       °{8       Àî!à        »‡€       ì       °{8       Àî!à        »‡€       ì       °{=p|ñÕ¡óÞ_m6›«¦°,KïÒ‰Û¶e’NãŒêØÈ¤ƒ¦šû&mrä[»\3³›v¿1/v—‘       @_áLºÙ''¯z÷zcÏî]?gg‘¶‰Þ~æé_=Z5ñrqñAO’ÎàL>|¾î¾éðaƒ[sù†Q·ã'ÓˆG„¤ûp¦ç-3¤ÃØƒŸ½»›y$ìqYwn(      @ÑíÇ.õjoÿôÓÁ‚‚ŽãªÒ*ÿÞ¹£³Gk8}ÒS®:b©7éÑñ7žËgÑW[CÆ3¦}‹žûõùæ—Æ¿cé–”`ŸæÒÎ¸#òñWö‰ßüê³Ç%53™²^¸áž¢¦sÜ¸cù$aÓ…Ù®>â÷D'"      €ëH·C<=ko3ãæææêêÊãñJJJªg2„tgHšñvMnñýïWê=¸çŸ7é?ÞÏ~þÕ“¤¡[þjÊêâƒç>þÙGˆ›[ªhÒÿþm•ÐV´À³^›ûVÑL…wsÁ«ß½àí}¢çVÍ’Ô›I¤úèiû+3ê~:/7^Œ€       -º½ŽE‹‹½¼è~ýú½ýÎ»4Ú 1GÌŠ·é:Ñ["yù•Ò1ŒdNÆ±ßçýö­»½ýÞÛŸKo[ö¯¹×{ê›ßæXoÿçzÕ#¸Ü]ëvÉ§Š[\¬°^ºa:üuÊÖÃ•Ý{pú­Ñïñ^øzƒè¢gT/{rÖ›Å)ŸELíT6aÊÙ—+”ßçƒæ)       mÒí58Fßpƒz×w¿ÿ~lôèú÷ï_5qÖ¬G”ÊûO:9v¬£cäÂ2ÞŽÝuºˆ<?åëFùzËb™H‚clýt·Ÿb†»;zÁ¬ò½¤ Õ78ÓáíkÞŽý2×gñç‚'‰˜‹0Y‘DÜ\XÁ™rd„¤þsLGvÜîxù    Ø‹ùÜ9‹`¤‡€ ôi³…/àè­º=à þÊÍ-bYšqÜz«¯³sõËÊÊèÄ\ƒáÆ›n"=À¤\s˜¸Ý0kíq
QÝìÁÏ¬nöigmYÃ^ûÖÛ‡¥‹Þu’¨áÃ&ý÷»¿Nÿ,#‡‘ùˆÜûÐßòÓ÷ñ³^Ù]DZà³xÛW¡uz˜t»sÈø(‰°f‡OÇ£¹J_PðãêðØl÷YKbŸóµýõ_´lÝ1ŸùË^˜èQ5Å|4iÉê£C‹X<C‚R €¾Æ’÷ãußw¦ßíC;ù)o>—“›oqõô‘Å÷t‚ùÈÚ"væÛx„ç»ð£Ø™^UÇWÁ+o O½½hZåwîÇÕ/¼}Ô÷õÞüGmótsÞéá]hi~]ü‘SügúVžó˜­zqé!ï%ë–M£÷Í„æüœýãÞ½ÙFÁ´°EOOÄ­0ççò‰»·ÏHë‰¶¥À“gxI$]rèXÎý˜¹#Ÿ_¸}[á¬Ëjþ ×éÆ€ãðaÝÆÏSµÚ&Sõ®ýúõ»ýŽIÓ§ÏðŸõHiiiìŠ·hêáééyç]wÏ}î¦›ÆîÁê3–½¶®èîUïù­y1þÙ»¶6žalH3ÏäØÓEŒÛ`ñ¤Ç7ý{S¿cÓÁ¤eÑëö™q~1;ÞO¾|<ÙÆ"Ä3’ŽÌ¨wß´kñS‰Âåß˜ÔLSÎ®"ð©ŽRØÜ¯WG›ælX5‡+ÈÞ˜´+¿ÄÕSPsä(ßb.4ÆÞéÛàÛÚÃËÇ£xÛö»”cgß(°žøª7î8V ¹s¬'>D ú KÁ¡m[vð,w*:pXò5k^M1xoH˜=ßÐIî“ßüðÍ;=jï›sÖ½°`©w_³õ(ñyÌ¼wÇþ)~Ç¶l8PLÈ·çÜÿvåÓ#Þ}ý~WƒzË–ìâ–ÖãÅŸ6Í}ÿ’UõÎ—¿rö+‰X1¿o®uÏ}ìûOóöÀA}0çl^õiÞ´°ÿ¨KÌ9»vüXàz§ßL	9¢Þ–möž6sš¤ÉÕBs^æÊˆmdÆŠÂ'
ègâÞÄ¨¤¼q¯û¬´éLyGveª=”ÓÄæ#Çò•^H8z©î
8Þ/~]ÒG&^½zõû}úóÍ7ÿú(iýÛïÄ=âÿP~~þÖ¯32·~ýzÔOÏy†´™qû¼‡_ÞU[5Âv'£„wÌŽïß²öî™’ÓŸ­ó	üôÃ·ÚZƒƒ3Ns¢;F­=s4ŒDÞŠÀ(ùòI²š&Ò&\Ñy“Å­I=:¦#;tÄçÕq5›(šôìŸg’×kïh°Ù`gÌ†í«Vï°^)>ºášQ„¼[ûî\ŸÊŒùÏÌÅ¡ëUO/L	H©÷ìc^|dá—´Ú¦  }	_`í”¬+>Úù®®<Âãã[ºBá7Ÿ¸¿ñD^uÝZŒx5å”ä±;mIÊö$ž9‰ô$Ç}Ê¼3Ýs>_½å˜«çPw>L[ùõ´º§ì]öüÊ#c­‹U­¿X‹ëÓOú®ÚFfÍ{Ì3wãš–i³§Ý±¯xÆÛ±óoÇ%ž^Ã’·}QmÕO<mþëgH:ÙBÉlÈŒ]š’]ÌËßåw{ð¸š¥™OíÝ¼ù¨dè¥Ärlû–-ç&K”Ó$MžMúyZRûZùqÈëÈJö/{~ù~[Ùœ§ïä§,h-0k©[Ž3gÎ4M7ê;°ÿÇm™[ŸzrèÐ¡çÎ£S***bWÄÌTýÓÃ£­Œxæúì™6áX®Q!™a“;MÚ…;ýýöFú¼Íª"éÔŽälîa#_,qk.à0év&ã#dõ–-¾ûejèšÔGdãÑ-‡=²œÚ»öÍÕ»òJ<•Ñ	‹§Xë]æ¤D-Ú’wûc3|ª?¹ùcgÌ}xlAKËñŒuÇ—< €=k®òÿ–[¬ÿz?–”0÷F|ÖÃ5Ç;{Ñ“·×V›°v¬MÊ®¾Ç¸ºˆ!ÿVCÄoFÞÆuû]Ýy…GäŒï?Vâ:ÑfõŽ9ç£ððLc½%ÿ¼ú©ª¯-ºN¨;øC}$^|‹ï%šk¶ð%·ûzî¢i§'N|z>ßCâã]bn4ÙbÎ7æ+°Ì³Ù±nMŠ&¯ÄÕ÷ÙeQ³Ç5›•4,
}´zK¾‡=(é½£™)í§3òJHq®úÓø½ù…ž-ªm ÞúJù®^cÇJêµ®²æåÒÏèi~>H7z¹n	8Š‹Í­ÎsáÂyúÛUÐàÐ-¸p¡íGVŸ¹4gÆÚå3iÁ\õÌ+»E!1+æLjš@p9éÏÞ•ÞxªÍ&*œ!+þ3ýˆÇßèÂTÓg¥î#ãã¤Íe#¦Ã»³¢ûXí¦õ9§Y®è´!7×˜““[DÈéõêÀ„ ´S±7–S;–/H¬ªž™ÿß”µ÷ÓŠ7¯É<F|ç?5¥î†‡Ï4%ß+¯¸î“ÔÕS2’—oÈç{z¹æivå¸N¼Ów Ø5¾»¯bßRSh+6Øu4ßzñsŠßDë…j÷ö|Ð›$¼øêŽFiÉ±usÿ¹®ú6olèG«f¡æ´-ÛyõÊÏÞ’’Ý`º»ÄÇËÝÕz8ñ½f.^”»`©µÖ†g	ñ™8çï?‹·9â-IGéq<WÑà°ã}xñ“SæìÏWo1x=¼8xŠÀrlëê”œª‡Íç…ô¢0çh¶ÅPÂwXòòÎYHIþþ/ÖTE|¯‰³ünG3•kÌsJø»SšL-øqåojÌ_/S
m-‚æ±Ëg7ú ³œËÎLJLÙO?ÍÜ}ýÃ=7Åv‹=j³G—’â¼£G³Kª«HçØ‘W÷X~ö®]ÖþcžÙâJãž‹]ó\½¹ÏízsþêßJ	ÎÆ{»n	8\\úßqÇ¤–ç1Â:V«ï­¾ƒ<ÕNìçâBÚË¤M_ºÛûÕ÷üªÊþÂIÖ®$‹—„>“·þu¿F ÓÆ&*œ!ãµ¹K±ð“Ç¥])pFu|ôšï…¯ÛJ^r7Í{(z_Õ_ÞÇ¯$n#ÆŠEŒ›ÈûŽGüÃ¤ÂÓ_¾½þëïªÇ%H8ì¤bþb¾ÁcÂD×c_¬Z½-éåIt²§ß›‹f4¸Fg)Ø¿~ñš£uÕþ$%,t]·h‹`á»2S¶{¸N›"AŸÍ  öŒ?rÚÜðiÕwÎí_ûú¶Ê[ãf‡Ö]¯4ç|¾ÙØüb¼ƒ“fßH#é5F×ê³vKq~^~±5{VY$ÖÐ¤KÃ÷X{rÙÿÑê/Ž5w¥ÒœóÅÒ…j¿Qþ>ŸÇwuŸ8ÃÏ²kÇ‘°„¨)‚sû«ÛGõòjtºRrlÛÛK·UßÉ­»íZµÔc[V­ÞE‹™yëWïµNØñöJƒ¯;½ö~lïÎšÖ»br§G¯d6:VHC¬ÛÇÖ^¥x)fÏv?× Q°æh4ÇŠC½<õžªÞµC­9`¨C\§Ì[òÂ”¦õ,JJJ,„o=èloƒç´•YÓè|9ëBl+™ñîGá¾k‹šWC×äM|ýã7ë÷ÁÑÖ•6x9Û3ó¼ç>;=õ~ÝpˆÅâ/Ò6µeÎwÞ'ÀN[¶âË"¿7>|FQ×«#žºxý‡¢/†ÏÊ]°þÃy²öÖÀ°¦/¬9}÷ª-K´—¦i   IDATUÞmÌ8Žc9Žáš{Ø¸ë­#¿<íýì;qA>6–ÉxÏxãoÇGxKmŒ)Ë
n_üSŽéq‰˜€}xÝéçe½1å©òŽ.ø4—¦È^bþ‘ÌG¬Sùcëõ“äé·0LáYx ií  }—9gCìJz™ÑÕW\H,–,B ™µdÍ¬š{Ã†/n97a~|£Z¯VPf7Šûw%¾~SÄ…Ù»²<'+'Öïƒ?Ô‹——¹8l½õšLÞkÓ*×-)	Ã^M.ßw†¿{Îö]Ëƒ~/„+}ª‰šû?_½­iÁÄ¿]Ð`KÞÖWCsÆÎ]»Ässøò#¾ËÖ-™‚v½Ó¹ì{ó	Ï×¯Þabç¹Êªçö®]µ-ßçÉ°iù‰êÄ]üTU;Ë¹ÿ¬~5^S]Í}¬bÉÞ{¬¹•XÎ™KèÑi«î„ÅBšíyÈl)±Æ"õÓž•Ö-&gËÚÍ¹ÄkzðLT¦¶=1Llwaõ™›ö˜·>Ì¯i»áøgÖ~âöÖêŸô§Y™ÐfÂÁ·†ÎXò}‘›÷$ÿˆK`$ï|¥jÜ­hËñ¬”Ÿfµðð¤g_OxÂ{’´ÙîE…’ñ“šo·&”E¤í`Tß°WfÃÞô”/¶þ\u­$ïçy?W?ä:ÁkZmÀ‘¿kÍÒ]U7;ÑŠ  z5sÎæeK·+ñœ¶h¾dÇÛ
Ö»ø<÷Éžkç2rsÏÑ§zº7>ïÐÒà:%ðQú+þ³ëè±£»vTÖ÷wåfo;P×Ê}òëþC==ý_à+ÌG·|žíþ…û©cG÷fóÆ=ìY˜?Tñú:¿ýI«¿ø|ãXße3GV>©•G“æ¿º­zÕ£¨Lžb½ÄÃÅò^Î’§NZ ˜¸+žb«jƒÅRœ—sÌ@ö§Ð°Ìâ=#jÞ´êv |ÉXO÷£<¯‰3g=æw§9²ö…½ÍÕ*Ì³ö­áîÙ¸"³Å°}ùÛÿõ¨iób±ö0ZË|.ÏZ§Í½®âO»VZ£ ;eÕ–ÜÊ¶ã¨Bdì9àJçÄ­oáaŸGV}òHý)ŒÏ3›¶ÔÝ£‘Ä‘æ"‰ö¥m!”LšJ:é†*ÈÙ«Þ–™®9VÕ³ëä%)•¼W>¶Ù‹ËÔ›™ç¼.aöÐüíK^XWL   ï)8’¹vÕzZbtõ·l¡‚¿w—µÐ‘õYò²÷æ×q¾^¸ÂÅwõÿÑÊÉy«^\zÄsÊ4÷üí…Ÿó÷úïº”¯qw¾ÀÌûsGæ1Zž´äùÙ21ìÝ•c=Haöç‰™IKÕ’é¯œîÁX‹£|/ål¿œy’Çfûä}±f[îP¿E¡~Õ×úùî^ÖÚFÞþQïŽ3ìX·†^Z÷œ<÷Ù>‚ü­«ð%‚ÖGÀ€k§àÐGËWï/¤×êžœ=Ñv‹Ù\B^gß)ñ!gÜ^7ÿFÿU[ükï¶2XNeï8RL<§T~ºÕ„mæüCêµ«7ÐÃobm¾ûDÿÙ|2®òø*È=p„~ÎNÔ}Û¾Òš9r2+«ÚQÅÙ‰Q±dUÔtkÔÛÙsÀ`Š³·kŽY<'Î~Ø;gã–¼ç-ÉM™ûÏêbQƒ  ¯)ÈI_½rÃÏÖnEÇ>¶l¹¿D`9GºBAöæôŸi	Ào¦ïPÐI–â£‰sf$Zoz
¼§Mž¸wåÞôüüb÷i+è…zK±Ñ_H$kDáš—½+÷i_qŸòÂ»¾ÿÈ\›žï*ÔŽ#@Ÿ…yùÇ™;òIn	oìÌ‡§Ý.iXBõr=ðÉþÊ’kþÏ·x,~’—gæyzÓrjÞÈ|jÿŽ¤õ)ÙÖŠ“.PÚî™Â’ìç<Âó¹ÑËëö)=±-8²uËÑ"ž6ÝZMÃš—â£ëÌ),¦Ç\ØªÅ3j'þÐ‰þÏM¬ZµA½QMó)~þL4ÿ¹cíÛ‰š<â>mQì,sÊ’õÙû£Öx&,žˆöR½€îåáûdÔû³½|¼û—mimîú}p¸wßO¾DÀ7  °wz-‘çî;kÑ¢§'

òøî•ª;UÃrnÿº×cvÑSù‰Í‡oèKA~^1áI¦/xÊ7okâ–|ë0õŠ‰ž»vKxÞ³gûZ0s^N>ñ¼Ý“œËŒSL$Æüý‰×ìÊ÷ô6ëáÙgHê†ü‘Š°Ðýáñ²³IeOM®[òÔkbÖå{º“|‹D1Ž¶oÉÏ#^³'Òö8zË¹#»2·îÐìÏ­ì¢ÓS½àž¶ë4Xƒ×£%<_…¯gë•,%¶¦š[W¯¤Ù˜§bî¬êf(fëÇeqa1ÏÛÉ2[ã­X
rö¦š’yŒæiO=5qh»WjMfmµV)$<±rÑ›§Ñ£vÉòüÅK¶åªW¯˜ðú?<Q£÷BÀÐÍøC}|jn[HáÊÆ¥õ¸Ö»Ísõ’xyX¬_<kKAŸiÊõÖÍ{æ>ý$Åg) €]ã½ÑGw’Êªûæœ­Qá™U]3¹w|Œ,óÑ/*kPóÆ>¼d1êNCç˜s¾x}é6kmÓ«ßÜiÂËÙøúÇ•ífKrS„õä|_à%ñõu?ö#áyù½èA#1ámÛªÙ•³+Éu¬XÄÓÓ¼fÃwdnßµ÷¨±ºYr4qîã™¾¾>^ž®Õ­T¼¦Ü?²¸€ç;wÑŒ¼õ+¹OyÊ?MT
O2ÛZ¨D¹½_@òÊµ–ü§øÏûØ”‘6ÅYòþ».~ÍÎc4†˜ýd[F±T6Ô³˜‹¦½|zfì#)ÏWÝË,ßkÚ¬ÉûÓ-ÊÅ‹üÇÙêƒæe±KÒ”Î{FÔÿí_iþÞU¯®ÜK³=ÏÉ_‹¨éXT0.øõò®ùùÀæ†ÛƒÑÝhï…€ ñ­}…+'‹«Cbãþ]Ù¿»ó÷&­Þ‘ç:v®oe¤a>ªÞy ÐÕ[éçƒËr  v_Su_àõÙ‘£ùydÜŒ';\óBà;wåG¾GÌ^·ûEº$ø¿ùá4ßUà*”]¾ü_Às;íÉàP…GÎ®/Òwå[®ž¬žb9•¹¸î‰^·Ï\@
rv¬[µnÿÑ\3™fmžR|t?M7\Å“g?5Kb9²oöQã©¼£{³ó‹+C¯‡§Îð_ùÑ¾ùÈªªE‰ÇÞ9Eñ‡ý0tq¯$÷dìj_z¢:ndËEü³ÅÕÛIìs¾m‰<î\’¾kI“Éü¡·ÇÞÞ`ý’™Ë6Ìl~A|¯™QËÈÖ<¯Y3ZýXmf¥žÓ¾fv?*˜9cZƒ×H½h	9@¦ø!ÝèÕ®ZÊÀõªà’yØàöŽ#    Í°˜ÍUÕ”(sAxU\‡èqPètÐÙó¬Ç€öF¨Á     ]¤~©Vàú§×+¤pm8        ;‡€       ì       °{8       Àî!à        »‡€       ì       °{8       Àî!à        »‡€       ìž3€ŽÊ5š      Øâ-‘„€ ãzøÏ      šƒ&*       `÷p       €ÝCÀ       v       Ø½íd”ã8ƒáO''§nðæóù        +ôDÀQVV¶-s+ý9tè§ŠŠ
:ÅÑÑñŽIòGU©þéàà@        :Ááª¥Œt'ÃŸ†…½@Oœxûm&üßÿÝRZVv,GÿÓÁƒGŽ¾å–[?L>|8¸
.™‡      èMÎžg=Úõ”î8ŽË	z|6½±òw•÷?ÐèÑÌÌ­oF/uqqù*#ÓkäHÒ\î×K—m2p6bFÌ|uù3²ÊÂ+›{ØÀ÷–‰›–dYý¾Û¿7¾o^ð$QÝdãöÅK¾÷{/NY9Í´;ò™ôñk?|\ÂžÂsrX7©¸çVy=BÀ     Ðu àèÆ&*f³ù…ù!|>Ÿæb/¯ª‰GñôêééIoûûÏú¿ÿû¿ÀÇæÏ{>ó_Yë•ƒÍ5p“Þøb±¬aÀå¬æ5[3“a÷ÚéäÙ‚}„„3d%n:Ì¾ßµï¯"BÜFŒ›¤ô1±DTSÐeué›ôƒ_(jeÝÚØGŸø<·¹‡ùwDíøä™ÚL„¦$³–™nN
hKfÁæ|¹,Ò0gÃz±˜tÎ”cà¼;¡°?{w7óHØã2„      pucÀñÞêU§OŸþ|ã—µéõÚ«‹Ÿ|êé'‚ž¬º;fÌÍËßŠx%<eÃ'óC_ Áån]üØAa£€ƒ=m ÷ÕÜÊBâV±¡^[%ùä-sþð÷»õw<óÄë¿áã-	ðû>ýžÈg}ÿÌÍ/×K/¶úùFWß±}Ë<©õinãŸÿê³ˆñÂêHÅ8Ÿ.¿®ÒGƒ¥2nnB7NÈ´-NàŒÆó„äìN:lÈM]â×8ç0í[üðÜ¯Ï7¿þK·¤û0Í®`Gäã¯ì¿ùÕguSLY/Ü¿pOQÓ¹GnÜ±|’°éBŽlWñ{µL      àÚê®€£¬¬ì_Û2Óþ1i’¼å9Uª®OúhÛ¶­8ïY+l×à0Ö› ’‡Å½Zô™ñ<GF†!›1K5Þf­V›š¬Îú,ð™7æÄUMê¢&*DZÎ7Ø}oÌx>ýtíýôÏŒ>>2o¡'‰&½ñïßVÙz„ÆY¯Í}«h¦Â»¹•±úMÑÞÞ'z>aÕ¬ú¯I¤úèi3Î¨ûé¼hÜx4£     €k¬»Ž_=j6›Šip U©Ô§˜6í““Ï;7tèPÒnm¨ÁQ‰‘,ËzƒmiiìÁµo}žÃÞA¼ýiŽunî|nŽiß¼Ç¾®\‹H¾(æÕI­4_±µ¡tZ©¿!œúÖ÷¿Óä›Bçíñ[ßR¤Âëå3¦Ã_gåJT³Æ‹§ßýîï…ŸÌ²ý\Î¨~û…é&ùë)kçŒïTËSÎ¾\¡üy4O     €k¬»Žó&ý-0 -39Šþþ+×Ðþ€ƒ¿xGöbÒNûÓ§³'¬k8ÑíÞ÷·†ë•ÌÝwT¶aM§9Ù¢”7&	ëÕà0e½<wW]Ã¢Ã?:þãº…¼«®]
ÿŽ¨+µX8ÒÆ*¬ñÈyáXCã›Ïžy(ö ¥úÁ÷Ælz¿iêÁ±G¶~ºÛO1Ã‡Ý½àVù^Rêœéðö5oÇ~™ë³ø <u¬   IDATóÁ“DLƒEŒ¬H"n.¬àL¹F2BRÿ9¦#»nw<‹|      ®¹î
8x<ý]b±4š.pìÜ±]¡˜V¿c'''ú»´´”´{ð­Çžüô¯Væòyþ«­ãUïà˜»—þ;)˜F ¦ížÛ1éÃ„ ªVìáG^O’º}½øsÒ6íèƒÃZ…ƒLÚ’p°¹ûr8IØkr0bÆªïf‰ª;
áL&Îv«”Êê!ìáµo½}XºèýÆL8“þûÝ_§–‘ÃÈ|Dnƒ}d>¢F‹1}?ë•ÝE-m–Ïâm_…ÖuêaÒíÎ!ã£$ÂšU>-æ*      p-tWÀ!2„þ6þl4ý“”ÏÞ[½êÁüBæ…†Ì›_ÕdãÏ?Óßží¬¾!œôÆ·ûÇ¿ºë¾¤µ3DÖ|a™iá'KåMë·Ï{øå]´ì>ø¾5Û”E§MôÉn¶
âÂñª„Ý÷uõ]öð§OOø´úNm'£n~õÚ¾0BÒÖ½u,[a[fæNÖxÄ»ò•0"q]XÁˆD¶›°§‹·ÁâIoú÷¦þJL“–E¯ÛcdÆùÆìxo<ù>òñd‹ÏH:2£Þ}Ó®ÅO%
—o|cR34L9»rˆ4Â§zëØÜ¯WG›ælX5      ô¸î
8n¾yì€víRG,Žtpp¨.
ß\þÖCû¿¾$rÛ¶­ßþg·“““ú?ß<ø¦›ÆöãÎkVÏ•Ê§E|#™´ouÐâ¹Ìˆ´ŒÏÏ1¸=þÙKåâ™ë³gã×ó^üžXKâ‡BI«í*±bÎâ6+xªÐº8÷™Ï–Omø$Ž+:ðóG}V×Mj©‰Šµ–E›^’ñ§}¦ÁSe#˜ê*¢ÖbÎd8Í‰î°Öø`Í+òVFÉ—O’Õ$#&Ò¶(:o²¸ùˆš]³éÈñyu\Mú"šôìŸg’×kï¨®Ã      ÐsI÷ ±ÅÃþ³Nž8±KýŸ¦Êd·}“µ36v%ÇÛ¶-óôéÓ>6»~ÒvÌ`Å¢[·dl}ÿ©pðÔç?á3B¾(ië	óÇ‰%wøIEFÍÖÃÌ¸Im¨fÀ0Üá/W¯UºÃçEã¼›v|a<?ø‘÷çþ~œþäl‹˜46ðÓŽWÝ=ø¡­>>m¬”3îX±8ù [o‚öûÓÂª¡IŒÛ—>žal%áN¿=‡‘Ž³ùšDÒ©Se¢vWª°Æ@|±Ä­¹'št»“ñ3dõ²ñÝÏ(™ÝkR³       guWjÞü¶lN_µdìXŸ‘£F5z”FòÉS~ÿýØŠ˜7…BaðÜçI‡4¨Á1ƒ}T’y¯Oûëü§9>?i4Jg:˜üå±ÏF´¥cLF<#êÕŸ^X:{.a¼†5		¸Ó9,ã=¢­]l2401M\ƒ˜ƒ=üÙâe[¹@¿zKýiWNõÐ$\QKD¢–{&åYñŸéG<þÆø®ëì“Óg¥î#ãã¤ÍÕÅ0Þu˜ÝÇj7­Ï9ÍrE§¹¹ÆœœÜ"BN¯W& JS¹å5èåÄûo777      ÖÇ AƒÞ]µ:ì¥üV­|ç]åý4šasú¦Ø1Ç}úÙçBaKçÖqoÜAŒ?}¶4Õz_|_È³™¾ú¶è‰7ªê³Ùó¹¦"–Íšÿú¥wÛŠßŒX8K²;öàyÓ§ñ«˜9Ï('Õ2ÂæìÓ“ñ3G´µ$/ô™$>¿v]â$QˆØÜƒY©ë×l=-¨?\+g<¸Ë0øŽù•	3x„¨è³/w–WÏÀ±F‰ëš¡p†Œ×æ.ýiÄÂO—vU¤ÀÕñÑkr¼¾nc,\.wÓ¼‡¢÷Uuûñk‰Ûˆ±bã&ò¾ãÿ0©ðô—o¯ÿú{£ª…ñm¯Ceee‹GüO¯o4],Ç¿»j‡     èq,Ë¦mÚäà@ž|"H ;×EC÷×$F.^DcŽ;î˜$»í¶[n¹•³Xôÿûí§ƒûíWzùúƒ“¦Üyi?öà»Ï…®;RD¾Ÿkðñ»ÑwFbüéëw·¦f±~óMšÕÑï…ÍŸ!“.5zqr®ä>Ÿ}©»ý^
©[ˆhêÂw|„¶Zp°¹ûRR×º;Wøþ—ãOúöÚWŸN}uÄ¸™÷Îœz÷ÝÊ©BmæOä¾i3ÁLeËxÏZñúáÅo¿<³fˆ·q³^ý"!h|½ÍQgæï^P½XÑÔWß<ÿiæŠûÞ>R94-Ä¯xoiu'Ötã…5§ï^µe©ª‘u»XŽcškôÂw½õbä—§½Ÿ}'.ÈÇÆ2ïo|ámâáo©1eYáÁí‹Ê1=.¨qîÜ¹¦ée4Ã_yÙïÞûš>Dÿ4îùÇ?´m åV±‘3f¤UÑ¡²Ö³DŽe‰Pˆˆ
  ›˜ÿÜ±ù¨—ÿ,_KÞÞO2ÍÊù3oäÛšïèÚˆ^¯¯ðI5ç|“"˜(á7·Üs»ÞŒ=ª\¾h
Î-†Ì%‰yO¯X0ÎîOV  }‘¶qÛ¿þEo8;9?7w.±sW-e¤›óòÖ$¼÷í·ÿ¶Ô5ÖÕÕõŸ=üâKD¢ŽvIimðÁÔTg°Ž¢òò>Ñxoñ3Ÿ˜aíbƒÍÙ•–ºé°÷‚UþÜºe›ÜæDOq¹»2R·n?xøð_ç-nðô÷·¾?“ËÙ}o<ó¹xþ½¹Ÿîá?òÒ‚€ê ‚5ÜõeúVõO¹Bÿ„Ïæpï.Ù1éÍ˜ÚJ"ÕÃÄ~øûö“÷œ§éÅ#¯Ç­hÚg2äœæ{KÄÍ ÛU¸¤iÓµQÖpð0ç=I*êèRi„Â0vS6.¸d6¸ëÚö4ãäÉ“ÏÍiï³hº±icZÕˆËÃé’BbŒ¡bµZPuPrÆ¬˜8":ZYu€›´q1â %1ÆePÆFÊ[Þ1&ƒŽÉ$Ý¾÷ˆIcÝ²Ðø iWW4ô1±T"B =­`ìÒLŸE±³$|sv|DæØ×—ÍY/µ0çlŽ]¿·ÐB,Åyyù|O±§€>j)>gÌ'žâ¡®|Â÷˜6oI OUtaÉI	]°ÅØÒ]'¿ùñ›wz‚½o¿s ØÖ,<ßùkWøßÈ'p}³œÊÎÜ˜²?ŸOßÙó==e¨õ °äý7ñÍU»Œ%ÄsÚ¢ØÅ~^ÖÌÍ°÷óõë2žç”'-ôçQuü˜óþ³þÍ5Ö™y^Š¹‹Ãü}jR6‹aó²®¡Kfzäïßž©Vk²ù­Y;»&´3çügÇõÎ½ÏùëVÌÚàh,øqõÊCŠ%Ï¹µ±R³a{âÛi¬kô;-0lþL	‚=€¶úh]Ræ¶môÆ#³fÍ™Gz“³çYíûsîÞUÄ^^«V¿³âí_=ZQ^n]+çë;®³%7nÔÕ`|æmú¶ÁÛ!ôñ}§ºo‹W?šT=›·_ðr¿à—+œúÖÖ©ÖÊÇLOzäUúSsÅ‡SëWÑ°nÀ¿*o}t@EZÚjÉø#¦=­u˜.¿Ì.”LšJ:ÃŽÒÓ±t/]ºt.ÿœ—Ø«íOauqþIMêŠˆTñÑr½–S„×«®Ä±F½ÁTWÕÈDïF,5d°r¥˜5q-jlÒDÍ	É`‚ÓS#k«„ÕQâðØ`y‹¡I1}NFÃù0òèÌÔàzigÔI“:QÃêR"¢LáéÉªöÖ5:—ŸÿUÆWô=|löàÁƒÝ%  ­°ü©Ù‘'ñµ–é,y4³Px4Œ>³W&Ì&m­ÁÁ÷	Þ°«òÔ¦ÕÓÞÜ6­Ñæäýw]ü†\Iè¢H7À’·7i]¶$lÓŽ‰‚s»â_OLôòZv¿ùsÇÚ¤œqQ©k|ò¾x}uüV1=ó·¯ÿ"oò²Ìw}ˆ!3véê$¯„(ë¡WðßÄ¨…Šå©O#9Û÷æ×_ü©£G‰o0M.Ì_@Ãº’ú›KŠù®|CÓ-+8¶?ßsŠ¸pû{6VZ{ô”çìÕ_Nóáçm]õÉú]¾U5 ÐA?QQ^AËæû×G—;î˜Dú”ã¡Ê+*H‡”—•·k~¡,rç‰HÛ¨H0bexx+Uˆ8CVLXT	ˆ¯ßàE,
ÈŠñ×†ÆÇ·rˆäÑ{~·¹Î˜1'†U)luáRkÐéŒD"—u¼7[¦
i·µ‰‰‡²Ñ/^\úzT£»  eÙ™»
$Šìˆ–çUO:ê¿³îqÏ‡ÖÍ÷ÌY·zÃÑ^xúùñê­ù>Ó<÷\ýzæÿgm&öüx6-½YÌÅÅ••eÍy†¼BK«Ûb6¨“Vo4x=µb¦.x=à¼”Ë?šÆç[®‘“gLÜ¼z¿Á|¿{¾zW¾dö¢;=ÄÓöØW¿ÐfúŒ\‘X9'‘(”c3¿È6˜iÀ‘“¹ù˜g`ôS·[Ñq³f×[º%ÿØQ2îIkíåsŸ­¹Ùêúk÷¼}fð8	É9r¬ñ†äfŸóœâ3Tr§­•zøú¿à[5£dÊ4IúæsôðGÀÐFxñ…H_Ñs ôÆ¬éaêš±|5wÝÜ Lîï›D¬U>w&ªlÖ42iãÂB’ô’ÐääHy£9„Ò€ÄtIJDXH !:¹¦-L%Ö 5eU7¨¤dÒed$ª ™ˆpúŒ¨8$<5 i¾ÁqFÆ ×êF­«¬‰-HÍŒWÔlÇš¬c	éŒˆP+âã«šqÚ(R(¤]Ö”æÊÕ+Õ¯‰e›Þ h‰åÜ×¯SçòüÃ‚S¾]@Ì9-]GæÅ¾Ð8]8WPH&†­šU¸.ö¨…?…oØ¢vŸ˜oñœHò³÷{Íu·Ùc‡A­É÷ò÷‚C[Öª}—¬NÚÂ¶˜³ãç¯6L	{wí”¡(	B­ªt£>kS©b÷qÞU}¢{xu7ÿœW`'¨7gÍMKAî1³ç”‰^6Ž)K~Î!‹w g7s^vžÇ„Ç<ê­©áÍÚuäeg›}f(ÑB íúX'£Ž úÎd0˜¸7ZKàbUòÑÔï{bEì¿Ÿ¨r(Q%‘…f­¾m#Ý`õY1ÓS¸€äéMÒjBYp<Í6dú˜aµIq†¦›Ç±ºŒ”Ëq†¬¨°VTÝGˆA“–xï½!Y}JXHLšÆÈ	åAÑéé‰AR±"4´Þ6°º„-}…"©\hÈ ³VmsFB\ŠÞöŽa®¥}fÓËÃGuÓ7½þrÓ»  Í³œÚ•˜t”'©)àY
ú˜MN   IDATÌž>ž-žMZò÷oÜaÈÓ¬IÔ4+ƒÃ7ê·—o8bn<ßŸ»Röó“u\pè“¨…±»òlVçà{NSNDº¶™s÷-öG£s±™ðÕ
_à!à›óQÇö³xùzÑC¹8ßLxê•Oû=p¿ß#¡	»æº¹²Í’‰^(=™óå¹Oôi8ê\½•VÞÝûøý~ÿ|îs‹ßS~È7 Úá‹´[3·~½ukúæÍÄþ¡@ŸÀ2Â¦Çé[šE¤LÜ™¬j®˜Ê„¶ÚlÐhD«ÉHKIÓr2¹X$·Ò2D(U…JëÝ7ibÒL²X•­†'•]k04žˆ‰ÑI#ërÖ NËÐÑ´" T¢NÓËÓ#eUÏçô)!F•Ø`yBY€’‰ ˆ\%–+ÅIYZc€DBŒZ­I¬’ÚÜ^Î¤O‹ðO‹IÊ ÐÐ yÛÚ»Œôòúx}rsw šg)6óÆ=ù”§&³²ph9w4»Ðs²í¢˜%OýÎÂý–B‹—å…–-Â¸òùæs‰EP˜ûã±‚qëÊ{æœÌ5)GÉŒàºÒ£¥0goæ'Ÿ°ø>öÂdz=½à¿ËŸ{»I£¡ÿL©wO<ûý5Ï¡±
k'.ê‹ï‚iôØ1´<«ùÈ¶/ñ¦,Ÿ8”Þ¦‡jþQMþü%›¹çíZ·*eíHïU³¬ÇœËÉ.”Ìt(ßØoàû*T\ª¿ÒJS¢6ýgqþ‘­«WFÄ”Ä£Ç\€6«í.°´¬”Ø? }#Ýy"´î>Mæ„hTéÉAm+¶sË6ÿ†Õ¥DE%©õD¢TEfÆË…º˜À¨vµÄàôiQQiœ*9ZekC8ÖÈ2Bš™¥ï	oÐg®H»SYyË¤ÖgèëmSZ]^lãñn‰B!Ì ¹†*@¬ŒŒ—
­C)i3²LÒ ›]zXŸ¡JLŽë³22²âïŠ“*‚ÃC­ešQQQñó/¿?~Ü›[TTä3v¬··÷”É“Ož<™üÉ'µwñ¹
 6	|f¿¾Ø’¿]CÎkÕŒlMž%/{î?ëÍã9cÅGá­}!Ìzm‰ÒÚDEêg\³rÿ´÷M×¼Ïc6Z[ôB½tÃ’¿?iõÇÏ«þ¤ÄÅ9¾3BW|òêÆÿX¶õõÖdÎŽ_°Ñëõwg£M˜¤Äžï»pQåq&pó¹êšsÙ"¨ë·`ÿºU;,Ó–<YÙ-Ÿç)ðôõ›ëçCçðð›=m×òCÇ
fI†Z{Ñ0{Mw ß Q`.¢²~]£†+­Ã÷7kžÿK·«~7"ªëR4t]•˜™gnæa¾ç´yØçö	Œ@ïW•[êE³²ÎFí£¡nÔåB‰" RYWc£}½L°úŒ˜ˆ˜,V›©°XÛÍp"¹XØÖ8CFB	Jo:†-#NLç„¢Êí–I+çMÒ¥íš#ÕÏŠeªPúËÔIq):ÐÜš9îµ×—üö¿ÿÕNùE÷ý}ã7®yïý¦mú²ö.Ÿâ ØT÷éPp4ó¿¼o¦Ò|Ár*sùª¼ÇVÖxb),)4l}g¡ÚRhö4¯É>–çê‘»ymö´þ÷Â¤Õ‹KHìâ)•×®-çþ»nÝÏ§"&îß^·wßy±+ý%ø0‚ö³œÛ»v•†Ì\² º/[W/‰k±ÁX@|è!Ww¬Pàí]•pXò¶¯JÌö|2¶&qã•Œì8f0ÏêQu¼óIåœÇ²ÏyN”x´{kè:Ž#ãž¬ëº£ÉJ+k8ÕþmY¿ƒK,%ºßKõ®’@_„NF ×ãŒz=+RJšïZ“‘§ÿP;b2«×èXQ€¸Ñü4áP­^›‘”¦e%±©±ÍDœQ“¥g¤¡m…nuòÎ fF?aDõ†n1i¢’LŠøDy›ºJ”‘É-}mÿðãõÓZþùç?þ ?þ»ÿ~W{÷žÜC  Z4}gžÏ“ÁÍÔž°˜y¾sß¸Ý°6ö€ïÓÍ7ïß’mKJH~öæCžó×¥ú
ø5WJùCï_òÉ?øäÔælRX»¾À:'@{YÎí_÷æzƒOð›OûÖcÉ4?Ouæ–}ƒ%y™›¹+Ÿ´¶41ç©×,ßX8yÁ›3ê¢4ÁX¥ÒcùŸª=Ÿ"8–¹¿ØsÖXk¸ÙyC}ŸìH¾‘ŸsÄâûTM¾ac¥–sÿY•I¦Ì~lÆí…{7fæï™ž¨J ÐV}¬“Q }I›–fÉ%mLŒš4µIÜæù›Å±mVZJRŠÆhío41>²ùúœ!+.E/Š–µ)‚àªGŠmÃØ®Öl#bÔÂÐÔð–ãvt0ZZÚl£Ä’’ÒÚ¶‹Uw	 @KÌ†_X¼ƒWú6siÎÏ#î>®•·]=½¼†’)“ówiNä›øìÄ¡‚†Ù…õ’uóƒÂZò¶._[ðä2t®­2ç|ñzÌŽ<B«ŸÛ»ºr’ø±µ	s}f,˜›ûfìœGJxžÓÄÎ’XÇêIZºzo>=‡XþTÕ8Ç¼±?Z5ÓK2óµ%ü)oÎYï1qö¼EJ/>]î¡üz½„š$¼øêŽüÊÛG_˜ñ)Ï7ì“Õ3ø?®|áMMUJ—øÔ?ÝËÖ-™h>šm‘ÌZuð6³Òiùg¯Nyûå-Ö)®¾sWDüÃ“ @}‘¶qÛ¿þEo8;9?7w.±s8 ºÇ#Ò±'²º´¨¨,¢ˆWIÛÔêÃ¨IˆˆÑˆRZ¿¥QZh¨—¡µŽ^"–©"#CTRQ³gDÌ‰ÒŠÃSƒZ]+ÃpM†ZNä2™XÈ™zƒ‰FÍB™B&jü\kS“˜˜½( :†+]68,¹_©¤?-Ï@  ÚD ¡qÃXË£fÅÿ\Ûëg¶eÍë±„ø‰9>^‚ÊÔ¢pRøê#ÄwÖÃ’¡ÇøS&óÔï,Þ:öÉÐ'ooqø‹Ù\l!•Ìy†b¾ õ9 uŸ¹)ßÚ,ÞxÝ¿hÃý‹êMð¸}qêÛš•ïá3sÁª™ê¦XGyLœå^»šqá©ÿ	oòÄ;—¤ïZÒhÚ¹CGK$3j:Ïmv¥3£>™E  #ÐÉ( ´Ï°¡Co¼ñÆ?ÿü³]Ïò;vèÐ¡¤}X]JDXŒš(££•¢Ög×§E…ÅdÅªØÄÐ¶T¤àX®aßµ‰< (T,‘ÊdRQË™…5ÝI0*â3£U­WÉ‚Ca1sÒêV&’HåÁ±ŠF‚²ú¤À$V™¾3HÖò«çè+a¹Ê—ÒÙZ+  íÁw÷™>C"ðùüûc·Þok–sûŽSŒ5ï]»f[¾×ÃÁ÷{Îõ4o_½òyò,ß™‹}Ç9ZÐâ:<|ü¦ñW¾0c}õ}Wß°Ùµ=XÎýgùÖ`ÅÕÓw†Â¹t;KÞÑ\¾¯²#[þ±ìB±Ò• ºQëdÔáª¥Œ \¯
.™‡îº«üÍ»|ùòñ?·ë)coÛ†ÖqFÖ(”ÉÛXw3¨Ó´Œ2@!îáb>MÚÖ­h/Y.   tˆåÜwXüžªÐ§Ì9Û??&yÚÍ« ®SgÏ³Ú÷€€®k=p      @Ûu àp$        v       Ø=       `÷p       €ÝCÀ       v       Ø=       `÷p       €ÝCÀ       v       Ø=gÒ³ÊÊÊèo'''       ÐEzºÇžÝ»è       è:=]ƒcãÆ/”÷?@        ºHÖàøóøñƒÚÚûérq¦ÃuF–t“vÓgY9—f:˜±uŸ‘#       Ðz4àHMý´êÆÆŸ“k‡3ªW‡/øà ‰tãÁ/S·n˜opç™ºËÐ\„Âî[úÐ?—ìª„      àº×sGQQÑ7ÿÚVu;së×ô.é\î¦gn½Éûæ¦?Óï¶`°9[¿Î©'‰ªa2äš:ZÙ‚3|¿UÇÜáç#lmF“ÑhªŽ4„>“¼‰ñX®!ç`VêªTõ       è¤îêƒ£¸¸ø‡ï÷Tºpá|aAÁ‰'®^½Zõ(½ñô“OŒ=ÚcÐ úCÿtç]w»ºº’öaFÜ÷ÆÞó	Îôýú·vŠüÆ‰l<Õg¬Þtø|ÑáÙÖÕMì÷ÞWëgˆI{±‡¿LÏ•FÉZÏ7®[ò™ž-2ææž·TNÚºàŽI²qã'Ý!d       tBw4ª0kÞkn½þô§önøË‹:Öó(#’ŒIêOáŒ©çE÷.ÛÈ7Xmrô:vÆ§?¼¥¨y”ËYÿøs»Æ{‹H»±úôµç}VŒ?¿æ¡Öküð³w¥»ÝýÎŽO¡Á	#ž¹â‹™uÛ˜³þ™×Œóß«Û°K…ìe      ¶¸û“Ô£¨¼ðâKb/¯%‘‹KKK[˜ÍÙÙyeÜªþó!Ò5Øœ_çŽx"b|“JœqkôÒÂù.®+°ú»ŒÞ3Þí®EÁÞôVâOEÂdð¤WÿuüÕúk¢¡ÉkÆ…ŸÔ‹QX£‰ŠDÕ•5ï»§
_û2s7â´!Ç$š1/ ÕF.ÐõðŸ+      4§{‡‰¥±…§§gè¼ç‹‹‹mÎàææöaÒúI“ä¤‹°‡ÓVobï‹S5
,X}Vbì[;IÀ{qJÓÚgÉ‹•tã¾Owž—¾t·¤fvvß3žO?ÝÂ*F~¹í-9·ïÝ×Ö}îð©îWƒ3>ÌyO’ØŽ)8ÓŽÈûÞü©²m
ð±O#œÕ‘†;&ÉÇM•º¡‘
      @'toÀAÑð"}KÆã³5›Í
…é›3$7ÞHºŠI›½&Çûå«opìéÃ§Eó?Œ	/ärÇ‹ÏGÏèû™aSs5üûÖßW›oáøyk?ŸÙR¯ŸÂRëÒ}f†Å=2âôše‡+W`Ò&G:"ê³¨©¶0âÇÓŽ=Þp{øÝç¢MsbVÜ‡f*       Óíuã7•””4^VVvƒ·7é*4b_ÉI„ß]ö™ôÃyõ;þdÄ~o|äW}Û{æŠ/&ÍL^üöËÛÉˆG>œ'¯‡Å²ImémT$ŸáÇånª]*lÖ×ÏÅ®¹Ûg…ÍˆƒpÆƒû¢I
oaå}Y?±ÞãÜ²’×kÇ-•#â       èŒž&öÿûãlÔ‰(..>v,‡tÎ¨‰—JžýpãÖÍIìúy/®×±ÍÏÕ.T   IDATÏ%c½EnôÖé¬ÄµY¹,é4ÆçñWýÝ4oÕÛ¬þÁþtõgk×cáØœÝÛq¢ÁG0J,      @çôDŽìC‡joûúŽ£¿=Ru÷çìl©ôÿHç°‡Ó^ëˆ÷«&[Û¦ˆ^}?ÊøTô‚e#6½3Sl£wV¿5vÁ²]Â9öÍâ¾|{SŽ‘SuAM¡lÎâ…?1"›ýi°F#'œ:¸ú1ÆÛ/8Ê/˜       @Wè‰?ÿœM‹D¢•q«¶dl¥?+ßy—Þ%Öìã'Ò	œI·uÅ3¯)ºoíæ¤àšž7ïGV¬œÅìŽ^z˜mô„Ã_¿õÔ?g.;,]¾ñ³ˆ©bo¿W?Iyuj5M
˜1Þö²D>ã™Ãk–¬Ê8¨7ÕÕØàXÕ7       :­'jpÖýòÜó!/¾´ ÿê15g=ðÀƒÓ?H\óÍ¿¶‘Nàrµ»s„'í˜3©Q¬ šºxUDÑ:S½ü€3d¼<÷Õ=§ÝÆ¾»eq·ËÊ™ŒÖõÖ¯ÊÁHƒß‹#oÇ¿õtjÝ€²üS#>Z?GŠ1T       :Åáª¥Œt§âââçÏ5Êæ£'þúËsèPÒ¸ÜƒZv„|¼¸«.wÓ¼—>òI‚JTs÷±è}E„¸yOòXå×–ÞJáZ*¸d6¸³.      è€³çY‚v=¥Û€Þ     @/Ô€£'úà        èV8       Àî!à        »‡€       ì       °{8       Àî!à        »‡€       ìž3€‘ûWnQQQý)Þ7x»¹¹      è4 Ý®¬¬lÑâˆÿéõ¦‹ÅâøwWòð       =ŽeÙ´M›È“O	bçp t»sçÎ5M7(£ÑþÊË~÷Þ×ô!77·{þñ.ÁÕ	qiFQ@x¸J"$½g2²ŒXÔ…ÛÄ³"Ã4’ØÌä 	C  z/sÎ5…¿)#ù¤ëXNíXþf¶âíeÊ¡ÄöJ?Zšâ(éÊµ €ú"mã¶ý‹Þpvr~nî\bçp t»ÒÒÒæ¢ÙýL±ùPÚ¦/7mLãñx¤ÓLÚ484>@Ÿ”¡•G*E¤½8Žc˜Æa«×ê©¼3	#¢“„Ñéñ-ng2èD"kËªLÚ¤$­P!5&E¥H“CeõŸÂjcüS­,Aœž-o}U%§¶å“iâ‘	 @‡X
Ûw5yÊÈF5ù,y[_}.éXóÏôNJ˜}cs…ÅBøü6¥4ìÏ4ÖÜõô{óýˆ;Q­ðºf9•¹!1e>=|gÏ[ôô”¡ÖcÉ’÷ßÄ7Wí2–Ïi‹bûyÑ‰fÃÞÏ×¯Ë<ZHxžSž\´ÐœGÕagÎûÏú7×Xgæy)æ.ó÷©¹$l1l^¶Ñ5tÉLüýÛ3ÕjM6ÿ±5kg×„mæœÿìØ¡Þ¹×à9ÝŠ™CÄ?®^yH±ä9÷£¶WZµñ»–/Xs{ô'QSp´•ƒƒCÕÒ²RbÿÐÉ(@·«ýÔh—K—.Ë?×Ž'pF½ÁÈÙ|„e«¦³&–³Þbz}3³Ò,ÁÀ6xˆ3d„Ü{o`L–ž­žÂêRÂ¢²Œœ!-&IË’cõYizq@°BT»rƒÉÆf±Ú„°ˆ©ÕÒM‰ÒˆBã£¦¤ˆ8MƒçåÑ{NÔw(5H*Üù{ƒ‰{Ú’nrñÒïÿáX	i‡sùù‰~ðÁGž?¾é] €:|ï+¾ÞõíèÏŽçûzû½ýåªîf®˜!i±q³ù†ùHÂœûýÂ3Ýð¢ÊïÐô\3ßÃwî‡Y»¾ÍJzÖ×S<ÖËîë&C§Xòö&­Ëö
Û´ãÛ¬OºïMLüož…NþsÇÚ¤œqQ©_o^19/%~kŽ™fÛ×‘7yY&=&ü-ÛV'eT.£à¿‰Qò§,OÍÚ‘;ÓÛµþâO=J|}hra1›Í|kÃãÔRl.)æ{¸Ú:zŽíÏ÷¼]\ØÌJ+Ÿž¿wÃfßU“ Ú'èñ'þçC>@oû‡ Ý®¼¢‚tHyYyÛg¶¦-UOHËÊ‚ãE!&]RÈœc33
‰;SUâÚûŒDkŠ‰Š˜®ÎŠMN’2B©\nŠI3ÅÉ“Ô:“\Ñþ:!§ÏJHÑMºé7ÇÕM«Óë­½jª´²@º¢â´’ðô ©!‘ñ¡a!a!Lbb¤B\óTk„Â‰¤âæÖ¨72©¨õ¦-—þõ K¶]º,ÜŸ´ÑÚÄÄCÙ‡è‹/.}=ªÑ] }ŸåÔ~µÁsÚ?lEfÃþ½yžÓ¦I:2”š	Ïv!ï9yáŠ×gÒ«ïCúÒuõÖ}d»Æ<eþ´‘(^ßø^ÊåM«
ÈFNž1qóêýóýîùê]ù’Ù‹îôOÿÙc_ýBc˜é3.pER`U”&Q(Çf~‘m0Oñ 9™›yF?u»'}hÜ¬Ùõ–nÉ?v”Œ{ÒZûÃÃGùœÄgkn¶ºþÚ=oŸ<NBrŽ4©¾T›}ÎsŠÏPÉ¶Vjý“±œû1es¾Äïö<… @;0àÅ^ }€>¢²zB´8cZH`š41=²^{FœžÞ´ª§K
ŒÐ5^#V„&ïQj2tLUÑŸ‘D‡kY¡\/'ÃêSb’LªÔCÑµñ«	Ñ‰;Ôæ…3iB"2˜€Äø ±1#Š.::81™„…„Ìac“c*»ã°6‰‰Ð‡&#ü£´5uEª¡2~g¬8)"AŸ*m-á°¶O±V¯ùöìÙ×KÚÚJåÊÕ+U7X–mz ®fƒfÇÞ‰m¹êíû§LlwÀaù3%4tK£ÐúUÿõîñ|#>Yu¿+±ý|yèvZ>´Ÿ+t¬YÂ¹ì]Ç<g¼>õ7ÀFõKq^^±û8ïªF^cÝÍ?çXÆ	êÍYsÓR{Ìì9e¢—¨Ì’ŸsÈâèÙÍœ—ç1á1zkjx“doü"Ïgî’‰GV"à ht2
 v…5èŒ4Ï·?7àQ1ZYhd€\Ä0E¤ö!F,—²’ÒÄAA5±	§‹ó÷O²Ñ™*¤îŒWÔ[?«O‹
K#A‰áõ*°ú,Q¬TÚÜPÎ¤×hõ…ÍÊ¬>#*,J+ŽLN¦[Ã™LzAÁ1BYhbª0"$bútMtblP½ØB(Ü™*ÑÇÍIÅ&‹´1!-†e—Žž=WVÝ"åªù‡m•áÄ‘³êmƒï]ÝØ×¯ÿ0¹[ó=Ã¾¼0ü­oñxüWÂ_nz  Cø7oØ\sÏbHuáƒ`Ú¢¢¦5ì†ÀLø¾O‡5©ÁaÉÛ¿ó˜»â©¡¨¾õ˜s÷-öœI7s1=vÕ‡_à!à›ó„Çö³x=kmá”—o&¼õÊ§7È'®Þ3æG<çWÙË6Kft¤”9ïPžûDÿ†Çs½•’‚CŸo4ø>{»{þ íƒNFÀž˜tY:"”v 	#’ÊÅi1w¥¨¢ãcƒtØÉé3bbR¸pe€LX•0²È'"«Ö©ŽLÆ¦ÛêÍ‚3ªã"bÔÂàÄäàúK4jÒ4¬,4@j3ˆáLÚ¤éID$Sª‚BCdu/Ç¤Ž	‰ÑËcÓ««i4 ”%¦cB¢’’4ŠD%é0§þK~™ÿË.M£Î—®üôìO?UÝôt÷ûlâ£--d¤—×Çë“›» PÇ’»cé#;êM8úÄ®º;ÞÁÍ>/?çˆÙg†9vàhÁ´imêh‘ï5í±ÉêÄuê±Ëfbh¨b1¨7j,¾¦Ñ8¬•Þ¹ÍG¶}qˆ7eùÄ¡ÖÍbÉ?ªÉŸ¿dó"÷¼]ëV¥¬é½j–õ¸:—“](™!éP¾±ßÀ÷Uºó›[é‘-}êí)4y! ÐN}¬“Q }«ÏHÑ0ŠDy‡ºÉJb3•qI:#ËÕ$ÖªYQaqzixj@»Gbå8N¤ŒNRŠë?“Õ¦$i…ªDU3ËJi D´êŒŒŒ(ÿ”8©2 4<TeMCDŠèÌ¡)+&JÐ¤u	#VÅîT²#¤Û]»ú”05ÃôF&Ä?‹a¬TJZÂ:ì‰oÿ1öÍìOâ
¯4yÔEáýÜgÒÛF;5óìŠŠŠŸùåøñã†ÜÜ¢¢"Ÿ±c½½½§Lž|òäÉäO>©½ëìŒÏd ¨dídtUøÄÊ¾ò2—¬4®ˆ¸½2®0g¯ØÒÜÓ,ç²5y‚‰OÏpÍ_¹c¯aÊ¬†…%ÿÀšçUkªïy×\§ã{L|*tòâ5Û²§,Âà@¬‘AJüçù¾M´W1Ÿ3W=d1˜-‚º¡K
ö¯[µÃ2mÉ“ã¬G+Ÿç)ðôõ›ëçCçðð›=m×òCÇ
fI†Z{Ñ0{Mw ß°œ;šËŸ¨¬_Ã¨ÁJ²Ów3/Ÿ]Û,+æñœéï®[0m®º%O½*13ÏÜÌÃ|Ïió"}°ËíRÐãOT”Wðx¼Ç‰ýÃÉ4@ÆÕ	iFix¬¬c¹
e±Éu÷Y]ZDXŒN™š,mo¼A'Q…JM£‹ŒË"ÊøÐÇ/Jä¡ô'Ö¤ÏJŠËÒ±DU9ŠÂz=gÿ…nkùåöŒšç)w&7ê·”±&íi¢R©_ÿÛÞ¹3F~du`Þ¹z£½x-¼cÁ;Ã÷kîi4Óyíõ%¿ýïµS~ÑýBßxãkÞ{àÀi›¾¬½ÛÆ± l(8²uGž—ÿ‰Äu–Oæšû§,™V¿XøÿìÝ|Så½?ð±	£1ØŒ˜:SÔvmó›­Ñ2Cu¦^mÜh»­ØIµ`gÀBðë(Tˆ+Ym™…L¢*ÅUŠ·”i˜wÍ&5Žº¬u7­WNEgcXÇ9§üù$ý“–ÚB‘Êç½¾FÎÿü9TžOžçû‹ŒjÂ÷îïLHOšÆ5oíÞ"ŠMÑD¬«§Û%H8®v\[ÝÆbI]±äþ`½Œð(*¼“v·“ØHþ&sµtˆ¢£ƒ	çÚ[ljÍ5.NÞ7‚H*FTÛBûR#%Á"Ø³½¥¡M–@âîâ\M-$~n_éŽ•$­{3©{›¯yÓòusK1Mì%'ˆÒè_¸ˆ®°pû–Å4± ßZ^[i®Á.MÓi¨‘çƒb½¶¢ÌÒ´ÊXÉ§‘š„r[ùBmaÁ0gcJ…ååç™ÍU,¤2ÊöÌûÚ40Ý¸(§Äˆú­® ã   IDATO›rMØ-“§L:Ï!ï8šnô:tèÐ{ÞSÞqGè" %_óK]g¡iŸ$~îªÕ²­~éÆ¢HAËöµt¿ïb}J3‚?Ý¨ß¼z«]ùx\Ï·ñ"*)YFW¿qÀãkk¨ÞÕ¡	”Õðñ_é¯ÙÑ1sÉ²”¾žB¢FÒ´ýë¡vÿÎõ²{b$þòº®È¸˜ÑäžæF..®'ßô¢ p1†1oÙ²¹l‹Ïç#ãzp |+1Î*C®¾ŠU—”ç©.EÁºíUæÒÒ
‡PSPnÔª¤—&3aéC®Á¡,,×^ài²äRbìE½³Ó>p›fQÖ‘§¿xûóÖÀ“ß-&09sdç‹ES¦™qœ:5ä€Æ®®S½ãƒ‹ `4|ÍÕëVÕÍŠüøú’Uy-ºU‹æÏ­_¹Å´[¶bv ß8×ÑÑÑîé¨ÝÖØÚÐØìñµXêbñê©[µ|}}gXÜÅ±øÚûêækÞ¾ÒPë"„^¿ n}`•ü‘¥ócS–Ìo]mÌ~¸+L–´Ä8›Ï7ÚšŸ[_çá¿¦X3/86$,æéMÅ©QTê³+;,«³×{$	s.ÕD	øóôD$ôÞ^¾ÆÒ§–×Ëe4-Ny%,.wëúÁu‹WÛ:kMó~jŠP¯Ú¼"Á×ÔÀQs"»ë”uQd £‡"£ 02aaadTFu ë¶U”š-Uv¯"ËTV8hQ–e–Ì8Ë '«ûÍ^SQcµÛl¯T•‘WY’¡’šm°^Ú+¤†˜ÅÍˆå#¯ÓÊG&§\gªÔ^ FHpäÉ¥"TdU~œ5ÈÆ~ÁcOúZöøˆð;?ÜœýK	ù]žùÏ¿ÙÛZSÓb†*ÀñFÃÿœç¤çß
 ß*œ§~}vòú¾MÙz‡ß•Ø³ÛŠŒú\÷Z6W¶HRW$HHÜ*nýòë¶¯Y»d™kÝªå‹ç.šŸÝ¼yc[C‘ûg-™WÕ±cÍk6Wÿ•»,iÍ›ø-~¢Øù–·mÞD=´tÛCKCVHf,+gÙ`»
$±©KŠS—ô­ñÏò*J˜Ñ{™ø¼òwòÎ9ðž•ûWX×v°©«oê•¡/Úûoz ÀH È( ŒÌ‘‘Ó§O?tèÐˆŽŠ‰‰ŒŒ$#ãµ²³-N!¥Ö••ç54E(V(3TeýæMbé*C)Ý[LÔŸ–˜Kk¼
®Ü”¦:ßL³Œ¿jgÿIbCJ[Y2¤$0M¬¡†Qd”Wg\p¨Ëðÿã±ÙkÍ•SãpÉ¾~Ò@qàf÷Û2²à<I×á£-“n|ÜÿðéJgˆÉûçGö÷Ç\7Ê  ®&YâÒUk’ùõÞ¶õêúà>a1ÉºÜE³›qÂGW›k#B6pmMµ‰fÍKéñÁº‚ÈûsWp¶Ž‘dšvUIÔfsm=”pÿÒ­÷øgøì>lš¶xG¿f°€±Å¹šZqÉ(ºYxZ:äšh”®Cß²"£Nr§ÉeÑÜìüÓ~kï¢æ¡ßv[øFµ÷Ý8õU’8¯'N|rè“s[ŒP8òn,ãe„Ré%ìï0&¼N-Tª¨Ëñæ÷aiKv®SW^2DµÆ®Ï,•—”ë†,žÚuìÄ‰IçVÜ8}üp×ä[&!à   €k;°£–KžwÿÈ’øš÷¾ÖB=žŽÉ9 ®R_e$SFö`ÌŽg—çþùçüƒÿýßvvvö®‰D
ÅÿãÜ$—ÿ¶¨˜ |.[À      Ã7Š€cÌ‡¨4}ÔDÖ3ßçóýíoð¨ÿ›\jŒ³¦ösyôç¯ï›úôÊTù¿Ëf[ßÜð'ñcÙÉ=zíå¯Òñ³î@C      àŠ4æÇ¾?Z?°¿žîVÍ$—šPÈþuË+Â%÷±¯nþàîµ÷oîÉnŒs÷NëçŸèE¼ÿx}çòø…       ®L—£Çmß>ÏÖ?i%—ÓüfE3•ñàMB¡´¯`"óÁoæi_ù\~÷~xß’U?¼CynÑD~ŸGæ¾òiïòM÷= ¶¿Û:7üÔômµ¿YjW(Q     ¸]‰58È˜ŒÝø‹Ç^â7e¾²ç7êWÆýØÿö—¿üõ¯ö¿µ½.úîý(5saÆÝÁ¢Œ^›Q÷›Ï.Îf6üÞ»è¥ç”Gw.Y°S¼rÓÓdË’rñò—–©Ðþ6AÀ     pºkpŒ-±"ûÅ×üêÜLã–ßì&ƒßËï¸/ƒÿÉ!°ãÛŸ>`z§Ÿd¼Œ4yyölêèNéT©>
7K=iXô \Ì.|â¯%ŽVF…J       Wšqpðq…B%d½÷«ÝƒL°˜?³oÄŠ?ìHÎº#9ätŠ´!¬û¯8…³—KÅRñƒÑOýôŽÕ=Jem1‰_¶§B{¥ÏÂ	      pïÇH°ÎZãs¿ßÿ£ÂèÞ§~ðG÷ýð>U¬´_PÁüã…y¿ØÜ\øÓÝÕ„Üô`êMòÔ—¶m|0PsÃ»Ù‚W‘m       \Y®¦€C¨x´t÷£þq)ö¿}ð—?í|®äÙÏ…ÑÉ¹EÅÙ=ÃNÄw,ë“åì?Ìó²6—íÜúKqW=õ‹ß<?v™À¿{”þ|j4      €+ÉÕptËïÐð?³ú‡¢üãZ= ¨†÷¯;_oä¾"Æ…Ï^÷êš;ˆ@ª^‰       W°«(à`8úIÊï¸o`æUåÍòÅ
?Ò|U¶pÕlG       àŠvõÌ?¶<÷|­­åsîºè»|ô‰ÌUwÈÏ™…±—¿yÝìåwÿeCó-M¾£™q¿öj†¨       \ÉÆwÀÁº«žúéò¿~5øÖ›2C—Äw/ÛòÖ2Â2ô?þb­Üù›ÆÏ¿ºî¦ø»Õ).Ê¼O.žoÿ+•.zq6õÁü¢Pz‡Fì®ªÄ      €+Ûå8bcÍÍÎA7ýà·“‹ ä£Ëë^öÜ-Ì_7>÷'Ž=w‹PLÝªãˆ¿Ú¨ãoûßÜWkàîŒ@Â!”?¼ñT¡¸?9„óÚÐƒ      àJv9Žòí{ªw3Ìñë¿ûÝ©©i?%C(¾C:hÞpw…&ÿ‹åw¨ùŸÙýÏÈÇ„]–Æ<¸èÉeº»ÃY˜Xžß+½Ž       ÀdÂIî4¸Zµ÷Ý8UL      àJòÅQF2E4¢C®!        ã       ÷®žib.½æ     €ÁDˆ'“ËÀè]æ¿®      0Q      €q       Œ{8       `ÜCÀ       ã       ÷p       À¸‡€       Æ=       0î!à       €q       Œ{8       `ÜCÀ       ã       ÷p       À¸‡€       Æ=       0î!à       €qïZ —Eë§­_}õUèšè[£¯»î:      À˜;}úôÒeùÿëtX/—ËK^(þ®DB      .;†a*vîœ0Ì},K$‘qnâs¿^E ®V_³Üu“…dŒ}ñÅ/oÛzîzþ·É{ø¾ò565øiýôÓïÝxã¤I“È°Ž¢_,>p[ú½7ö$—,]1îkáÞW¯£âµ§oSÜ(ä÷±îi`Åb¡0\ømH<Y¯»ãZq8Â[ ¸òùšÿ¼ÿ“‰·DM™H.îHmá3;OßD‰†¸è¦å/ÐßOúäR^  Æ©­–mÕ{ö477“³äÎ;ï$Wß	ö;“#: €1wêÔ©¡6µµµm¯Ø1è¦Š¯ïÜQF.5–®)ÒW]Y ÚaœÖŠŠµ;ìRÜª7;X~¥<£|_‰ZLÆ’×f.µSº<üFL|¤“›Y¥0Uª†ùìY·Ãn·[í^¥® ƒºôiW×‘=’$Ÿv= èë ÷Ö6Íž™8m@O>Îµ{ùsËÐGFkÍ¥s¦õO>Ž#Á°þ=È‡yyÕîžEYòêóïA·Â«w¤¡z›ÉRïáï‡¸9—>žé¿—8×ŸM«‹÷»»ˆ,i©qYr¿ÒG×½¶esuS	“%Î]útz¼$xÛù\ïlY½Á¿sX”zþ²ÜôØž°£w­Ú®[‘*ñÔï­¶Zm‚G6lœCuß®¾æwjk­ûêhÙ¢ÍkS#ûÝÄíÖ¯;¨^± ¢ið‹Ÿüþ5KÖ7Ï(ÜªOÄ}0\&L>8uúÿp Œ¹Þß#rüøñ6O[”<ŠŒËº«rÐÛØž¶U?ÅÓ»ej·9'·Jšg*I „rµN-¶1ÊÊB›ø^[þ¬ì*ïÐ×ª
«ËµŠÑÅ,m·¹5º¡–¶dÏ2ØÙs·(tûª¹¬×n©r+µFe÷kc½´W(—‹…};é‹ìÞÀ§›	®S
…"MMø]ò€ãØñ÷~û	‰”=¦~RÕæñü¡êüm“ùÈœ©S§X$ p5D§¬-ÎKð·9WõŠutæÚü&›¯acþç;tÈ|Ã×XúÔòZOàñSiÛHôü´IÜüÆLŠÚ¥_Ý5îû&ÃEá\uæÍTîÎÚQÛþ’•&STÔª‡¢È¡Úææx}ù†X×ö•ëKvËù{Æ³wËv×ÌUÕ/ÄºÚøÜzsTi Vhÿ³I¿­C½¦|^<iÞ[ç	=ý‘¦&§å“O 
®ÐÍ¾®N$\@ŸûÌÚ[ê=²DyÇÞßzÑÀážºm»hAøÈ¾ì€¬G;{æ,ÿ­ê£™™düCÀ0æÎœ=KFåÌé3ÃÜÓ]“3+×h¯§ÇYTÆ}M‡ýXº"'×‘U^¢‘öî*Ô”gPÄ–Ÿžiw3ÝMüª{k©Ašªßi¥ªÂw?.ÖêgÝ5ùÙ&M=êNbJ)g*ø¯oXÂ'V»ƒg•—ëBb!¥­üX;ü32ŽªÒ*§›Íà¶ü¾µTVyµ±§7ŠT‘•W f„b©œ’3UÙ9Ö´²²,jÌ†)hùü£²çø	ÕÔÉÃ=h£Ét°á ÿàØ±cÏ­ÔX$ 0ŽqGê­´,éþÁ†øèú:—,i¨¡%ÃÖÕá#aƒ7ò²™O¯]™ÊûÎÑ•Ïm¹vã^›/qQÒ4´¯n‚(ÍšMIÁ€lÚÌ”„Îµ4   IDAT]ëëißCë~5gé=2‘¥Ï‰Y¾ÝF§ÆÆg®5g£4J­‰©ÞÞ@ûø¬¡¹zW‹,³pÞ¿)~öœ³sž–&?×ßûC«Y@Åînm°†^]6#UO‘æÆsº/µ·6´Éc#©{»h l;`Ùå¡’g¸l€˜2eÊS‹“o ßò´²¦4ÖQ”i …•Êóµ×ù\Aìµé-´²pß»r¡·&?Çª6™Òäƒì,‡ôð:ªjh*-C)%¬³J_ä òÊG8¨ƒõÚ«ªl´ÛM;ùÿsÒ^"4›¥B¡ÌÈËK©‹­ÂØKÂ¼ê&]Oÿâµæ§ëÝŠÐ³Šå
U÷+e™žÓkÕçVÈËt£ì¤âŸÒÆÿùö_<;•î(•¯O~|À¨‹ 0žùh[m]BÂàG«uo}bÂˆîE§{ÃÝåòô}!Kaqù[‹
'\{Ókkt{ùö!×ÙÖÑóU×Ö°¿E–²2ý7`î?\§ËÕì(!‰Š‰ð}èjçâE!{ö<äÚ[[|²Ä„¨A¢2ÎÓ|‹Î”"Eó¹\’»‘„\©ÿCÒÞ°c»+vþŠ„Æu8 Fæ[Vdô¢ŽÏ=ÿ& ß´›dß%0nk©Á*Ì(Kë'°NKfº!P‚ƒ•…Õ•†ž°Œ£ÊbÕ¨Óßì/e4¦‘w}b)¥Rj´
Jè0äV(Jtƒ$2¬—v³ò¡ÿv"§¤ÝÛ½vs~¾UšW®UöE2^{¥tªÑÇ&bJ!t[nÿ“Œj¡Ý^šv‹•iiJé`œ>ÞÒñEÛéî·'}ïí	„_X÷L½÷–k‚«Ã&M¾QuÝ”!¯úÌÓy¿Yû›°0Á¯òž9w  ?Átí¶ý½½Ý8ºrùÓÛhQÒÒMú¤þe|D ‰{<÷œœ«~_K„z^$ºo@_k}S§,•Ü|ü½#ê¾="‰HàóôÚ[êZ¸¨'ü#œ\	k·®{|×û²(Arwf×ÞÒà£RF3Êç:èŠHHï?‡\”´|m7×8#ÂÓH `d¶WìØóÖ[üƒk'^»`þ|2Î]TÀ†%À)4ª ½58(meu¡Š8ø$ ‚&*±˜uÛ*¬$ÐPgè*‹]‘Qýq†£(§ˆ”(Ï­ÑÉúkTG©ÁàP˜
T=Mü2CPì«ô‚ª´ª¾*¥ŒÃí%Êž>^w dF`Œ~ðò½„*ã¾rÄÂ?ù¢Ü"UPfíPÂÒÖ
;Qö¢aì†ôLËÀ½önëõ!V—ì+ÏèíËÂ‡1b¹ÿË®!þtÃË±XÈ°
R­”VÅtâäë»þ¾èïûmj4}ý·'þö·àCYDò«	¿ ç1-*êå-eC-ÀUk­}îáÚMíï[ˆrôÿ%y£/6%™´¼ßÔž”4¬B‹‚¨¤GfZM›­1«R)„ÀÑÖ6.nI‡ÑçßÕ×¸gûÁ°Ä5	‘þã<M6Ï¢»–F¸öo.¶lœ]<Û_µ57tP)Ô¨òzZ§‰uÑÆ7v´ÄÌ{>QÂ'/ FEFàŠäO¼NkÎ½5”Ö˜§U•²ºòÂ`í	ÆQ”­÷ª(1al56’¦KsÒñ"V»T¡!Á]lušBzžó3î@í
UVå»yÂÐÂRuAy¥ö<a„P,¤¯Ë0DÈÐ57ë´;x4Ó“ZœS~Ã]•]¥2•6X„!ò,S^†JÚ¿@ªÙBSY…šÞ¸B¬*|÷paÈaü»’OkËËÓ[ßT,óOSœ¥Ó(”œµës-Òr¨9ZÂ"o|ìíûcV7l-êøúœ­ßQG/xUqç-CÍÍxöìÙÿþ÷O>ù„nmýê«¯bcb¢££gÎüì³ÏÊ¶ní]¼öZüêø¶e‘Q®­Áæ%<žîYW[G'ÎîXpž÷7<™¶¡{)ºç{:$ažnæ²{—bò	 þÈÀRòš'îé¥	þûA."¾6_pçk÷q¢¾©KÚë7×rI+æÆûïVA˜L$‹KžŸËï!Iž“´ÍÁ–öÙT¤¿Š†/*I>Š|ƒkkj$hB{õ»h{CåþºsÍœÞaY†G›g½°yI<Æ\]BœËZlªvù†Ø,%-ÌÏŒÅ[>.¡È( \qÜ5¹™ù5^¾¯Ôdi”RšçZªh•–OÜ6‹M˜fô¸P–«¼vC.MXÖË¥J±ÐÉ'RJÊÔÐLÆÐG`ÌˆTåï^!PvT(¦”*Šë¶UUXmþ0Ãéï!—²noMQ)mFžB¥RJOÂ2^Æ6¶Q¬ÌÐ)¬óÏAkg”e§xCÓÝýEÎ%¤2JÞMãŸŸ˜±2¬B­VJ§%¿ˆV/4í¤Éwþöƒªq}¦«-$ö‰zú‡K~{ãÔICÆ²ì³+Wüóÿ·wÍßçÿúôé~÷âõ×__±óõÞÅaN	 W—öÆÝµ®¨ô%>;¶zÃŽúÄI¡ÍÂ`‘QMxãÞý	éIÓ¸æ­Ý[D±)šˆuõt{¢	ÇÕŽk«ÛXl#©+–Ü¬—E…wÒîvÉßd®–Qtt0áà\{‹M²¹ÆÅ	ÁûFIÅˆj[h_j¤$X$C@{¶·4´É¨QÜ]œ«©…ÄÏí+Ý1ð¢’¤uo&uoó5oZ¾®cn)¦‰½äQýßFß²"£× ÿäcõÁ¦J­R®Ôh”r!*2tj¦¢Ôêöà(¥•ºŒžNBaÏ¯WØSÄ‚O8„Œû<ÂòÑDS¨P^LÐày¼D™UPþnÓáÃ‡›ö™²””*ÏXb,Ðe¥©‡H7üéŠÓÁ‡”xx× «ôz«<Ï˜§Ÿç‰8lN¡R=Ô9Åry`Š˜R)X‡ö2ŽŠ¢±®$O5œg1qJŒ¨ÿ´)×„Ý2yÊ¤óòÞ¡éF¯C‡½wà=åw„. €|Í{,u„n¤}’ø¹s¨VË¶ú¶s÷ˆ"-Û7ÔÒý¾‹õq(Íþt£~óê-t¬våãq=ßÆ‹¨¤d]ýÆ¯­¡zWK„&PVÃÇ¥¿fGÇÌ%ËRúz
‰b4IÓöW¬‡Úý;×wÊî‰‘øËë6¸"ãbF“oxš¹¸¸ž|cÐ‹ÀÅ`Æ¼eËæ²->ŸŒèÁð­ K…l¿úb•.OŸ™NXqZI™z`ß>3p2Ò4¹0X;ƒoÏìPÃLXº¦Èâ”g*‡1ù4åš‚’ø_,WHÙ
§—UKÏœ0ÎŠ
‡X­NÀÁçúüR¯¦ä¼½7ˆÛVZdkÊ.ü‚¤
5ÅZlna®¼š×é/Þþ¼5ðvŠï“†œ9²ÇóÅ¢)Ó†Ì8NrÜcW×©Þá‘ÁE Ð¯¹zÝªZ¢Y‘__²*¯E·jÑü¹õ+·˜vËVÌôç:::Ú=µÛ[›=¾K]Ì#þC=u«–¯¯ï‹{¢8_{_Ý|ÍÛWj]„ÐëÔ­¬’?²±t~lÊ’ù­«Ùw…É’–góùFûAósëë<üP×ÌŽ	‹yzSqj•úì
ÁËêìõIÂœ…K5Qþ¼=	½·—¯±ô©åµÁrM‹S^	‹ËÝº>Ep`ÝâÕ¶ŽÀZÓ¼Ÿš"Ô«6¯Hð55pÔœÈî:¥C]YÀè¡È( ŒLXX•Q$”Rrâ¶z©4Å¹ñË2ŒP©Qˆ…vÿ®B¹ºÐ¤öÇçž‡¥«ò³õvy^y–â"ûoœCJQR¯Ãé%
ùP»0t•!ßâU³ç#X¯£ÆRj®ò*òÊÊ³ÎÛyÃZ”›og˜tÃéÁ'r¦ÆA³Êá¿ü“¾–=>"üÎ7'dÿRBþA—gþóoö¶–ÃÔ´˜¡
p<¤Ñð?ç9éù·ÀøÀyê×g'¯ï[Ñô˜­÷qø]‰=» È¨Ïup¯ese‹$u…qA‚„Ä­âÖ/ß°nûšµK–¹Ö­Z¾¸qî¢ù)ÑÍ›7Ö¹1¹Ö’y…Q;Ö¼fsEñ_¹Ë’Ö¼‰_/à'Šoy{ÐæMÔCK·=´4d…dÆ²òw–¶«@›º¤8uIßÿ,¯¢„Ù½—‰Ï+'ïœïYQ¹Å€um›ºú¦^ú¢½/`ñ¦× ŒŠŒÀÈÜ9}úôC‡è¨Ø˜˜ÈÈH2r,ë¥v{M…¥Æ­Ð••	«úÌL›N—¥Q){çUŠYÆ4~Á{³ñéFN©[]R]˜F]êxÃÂ(äb‡×ËAka°Îªü\}£,03Î7:&0Ml©¨t…•yšó>Q·UŸ™k•jMåêóÔTyŽr¥Zj±9ÜYŠá¾]‡¶Lºñq{üýw2ª;(="&ïŸÙOÜsÝEÅV 0Î	d‰KW­Iä×{ÛþÕ«ëƒû„EÄ$ërÍlÆ	]m®ÙÀµ5Õ$š5/¥Çë""ïÏ]ÁÙ:"D’iÚU%Q›ÍµõtRÂýK·ÞãŸá³û°iÚâuýšÁF ÆçjjÄi$£èfáiièk¢Qº`}ËŠŒN8É& W«öã¾§^Ü ‹á9qâÄ'‡>Ñ!1·ÅôVË¸ >†ÈIÏ·eV‰I'´äêmbµV§ËPBÿ|°¥æ
;É0•ùçÿ`lúœ
…ÑäŸc•0vs‘C™§S‰Eééf§T•e,)Ô„¤	,Ã
Å—>Û¯ó¼•2z±^‡Ý-U¯FÃ°â‘¼">g)ª!yÃíÂÑuìÄ‰IçVÜ8}üp×ä[&!à  €«×v`G-—<ïþ‘$ñ5ï}­…z<“s \¥¾8ÊH¦Œì ¸ª]¶€      †ofQ      €q       Œ{8       `ÜCÀ       ã       ÷p       À¸‡€       Æ=       0î!à       €q       Œ{8       `ÜCÀ       ã       ÷p       À¸‡€       Æ=       0î!à       €qïZ £ÕÁœ       0˜ñdr!à ½Ëü×      †‚!*       0î!à       €qïòQùìðá7ßüÃ¡C‡NŸ>ÇÏÓg_sÍE%,Œ³ªº™JX)ÎÞ,½»ð…Ö”µù÷I‡±7ó—_?ú{ñÚíË”ÂÁÎåþ jß_>gÙtÝM÷e<z÷p.       —Æå8X6
UA   IDAT–5®5ìªÜº²rçë¯X¶m4½tkt4%ïßÊ6ï>ÁÖþrý_¿°í¦ÌWöüF|°nû¾¼?Ì"a%s~¹yÀÚ‡ãÊB–bíÚ±üÿ	ØÏ÷¿ù'á¯Ï‰?˜Öþrý_T³ï–
	       \&—#àX0ÿ‰¿}`?wýÿýßÇO>©}s÷[S¦L!#ÇÒÖÊ,RN}U>ëÅ-/¦Ê{·4oùå³îÀ#§yÞ/Š¹¾£þú³ØõÏ»ô»sø˜åº»ŸüÃ–ü;Ä,Ã±¸;¡èY`>xn^qèQLóîe| îd°L+-|”       Àå4¶Ë²¯•¿:hºä:rdÛÖ²_-]FFŒùàÕ×[cŸX+þÛÐ;	º7þWØÝnœ»ìóì›–q¾î?Y¯íùùÏy³_}ñaŠ5þñêÂ_ÕR+7ýú‡çœ[zõÃ»åŽÏ9o+      €Ëj¬Ž/¿ürÍêB[ÝŸ»ººÎ¿gmíÞQÌ›×ï¦oZ¦æP÷_^ÿ+«ZyŸ|È=ÄÊüŠ¬Ûú¼î¹¢½5•
œX|Ç/7®ö.üÕ/~™Yºñ­ªÞÊâ»Ÿ«ýŸAÏ³ì×       .¯1	8ÚÛÛÓR~|ìØ±áììv¹~•·$}öÃ?ºOM†‡!Ö--ùaÏò¾gîÛ÷L¿}nÊ\Ô·ÀØËËìÒÙ¯ÆzÍ$õ±BHtæ¶keG½˜Wämh¾ã×[irÆ¶jî»…O¼´í×÷=·e«ü¹_å-$E[òï“2ÿ0?[¼ÿ({Þ§(”>¸¬8çŽa?      €‹4á$wš\j¿1¬Þ±ý52B|À±É¼E \hG¶õÍçJþ{÷W{ÿzGñK¿¤zúpxÿTðËÊØ!kº×°ö—¿ú›êwÛ~:·	ëµ•è–ýU¾|SQF´0xÚß”üãŽÜe±¬½D·äOòE
ßÜéV¯)}ún)ÛüfUslÆlE ³`Ýµk—•7rå©+ÚX„ãAûqßSñQ     \Y¾8ÊH¦ˆFtÈ˜³~¬¡éCdä–<ýÌSÿ•;¼}™¿üú—¯Éýè/Kžÿ7Ø×ýè·µ[–6ï\² p?û£öX2z¨0ÿ°<[°¡5ö×=éFÈyUÏ?û›¿Ý´ü¥¢¬X¡ûOk®úµtSñìÐØ„u–ëžs/|U·88sÊï¥k·.TeM~þ¤eí}h58      ®@£8ÆdˆŠÛí"£ò§ýÖaB*³¢%ÛÿÈû—_?úde+!SøíÎ@}ÐÀ3Ù¿ì™µŽ©±7}Õ/Å`6ÿ¥õ¦åç¤LóÞÏ«¼÷­ÝjHl’?ø›W¯Û²ìW¿xpßÂµ«©î­)Ê2tuÁ£S…™Sš?%KÙïŸQ…ýÊý9Ñ       ¸ŒÆ$à˜<y2Ë²#?Ž9òïþ¿o¤ÒDZÅŠ£åþtwqJ ŠF'ç˜Å~þÂ³!Ÿ¿¹dÎ³{öô—Ÿü XTð#Cíï~D›
ž{íoí»ŸþÉî§û_áºæOüdwr~Qq6*k       \aÆ$à¸+aÆŸö[ÉÈMŸþ}2rŒ³b•ÉùÃüµ±µËŽÆ>ñdì«
+¢KýU0„”f6ÅºßôïvÓÃ<¼1pÛºsá3<üRiZß¬*ùÑ£kSôÂZãfé²WsîèëÚÁüã…k™ÿ2<|ô¯Ž©Së…ŠœªÆœží¡CT       àò“€ãé¼g†3Aì¹2Ë"#Ãz?0¯.x]°pKîÝÒ¿Ö’ÀÄ®ksš>S@~[¤Ag1u_*E{íÛ…råì…Êî«ÒU«
_oíî¤ÒoˆJp×t}ñ£ˆ;       .—1	8þã?nûÝ‹–ä>uöìÙá•ñ‹GÒÓg“‘`½ö—÷î½ná–ßúão÷Z!5Û°ñ«‚%ëw*_Z¨ñx’£¬ÿEìú+£]R¿­ÈèYB      €oØ˜<ÍC?þëûëÛ¼À®&üà?øéÏ~« #!V(b¦jò—d	…Á`Ax]¬âÖ›bE¶¹6“ÏIø0böãå­‚©±.¤É>„âØ>±nÓòÐ9SXºêùWÙÞ½yaÕFûÑG>7o¿g¿¤XŸ”       ÀØ“ibÆL     pºR¦‰€sµ~ÚúÕW_…®‰¾5úºë®#      pÑp Œ¹Ó§O/]–ÿ¿Nç€õr¹¼ä…âïJ$     à²c¦bçÎ	ÈÜÇ²D¢‘u—¸M|î×«ÀÕêk–»nò˜‡ýâ‹/^Þ¶õÜõüo“÷ð}åkljðÓúé§ß»ñÆI“&‘Ñb–üç·Ü«”
ùÇæÜõŸ)Ô
É ‘&cÓ§?óÊôÒAóNwM~æ35DyoœUtG†e¼îÿyþù•òF¼y p._óŸ÷2ñ–¨)É¥Ã©-|fçé»“(ÑÝ´üúûI?\Ê« À8µÕ²­zÏžææfr–ÜyçäJâ;Á~g’`D‡ À˜;uêÔP›ÚÚÚ¶WìtSÅÎ×wî¨#ÃÁºkù§îb©J§£lEvÚé¶;«äbâ¥^±Â›ãÌ+)T³VƒÞì`úŽä·ºInºUÜ×+tÆBÜTXl¬XnÉ7HËŒJGþ¬ÜoßU¥ieûLÓíæµæg›Å…e…*ÿ[Â8+ôa–šXÌ5ê²,Š  ôÇuÐ{k›fÏLœ6 'çÚ½|¹eè#£µæÒ9Ó‡ú'Ç`XÿäÃŽ¼¼jwÏ¢,yõ‹ù÷ [áU;ÒP½Íd©÷ð÷CÜœ…KOŒôßKœëÏ¦ÕÅûÝ]D–´Ô¸,9Š_é£ë^Û²¹º©ƒ„Éç.}:=^¼í|®w¶¬Þàß9,J=YnzlOØÆÑ»Ví×­H•xê÷V[­¶Á#6Î¡ºoW_ó;µµÖ}u´lÑæµ©‘ýnâöë×T¯XÑ4ÈEÛ›ßÙ±Ù¼¯¥“„SjíÓ¹©±ãþKh€ËgÂ„	Á§NŸ"ã€1×û[cDŽ?Þæi‹’Gko¡<ÍX™Ö·¬©LdìorW)++³ä}é…¦°\r$cÓg–ÊK*uçÎsÌÇE5B­©,‹TåêõF“éàaSÏVwUn®Œc7df[Ür•Z¥VkT*¥R~‘Å^½59äZ™s7È³*÷U#>;KWåëmÊ£v˜“M3‹ÙF”&Ewà#Vfhå¥Nª Ì(D #$ˆNY[œ—ào¥q®êëèÌµù3Ñƒ¯acþç;tÈ|Ã×XúÔòZOàñSiÛHôü´IÜüÆLŠÚ¥_Ý…fáÕsÕ™77P¹;kDmûKVšLQQ«Š"‡j7š›ãõåb]ÛW®/Ù-çïÏÞ-Û]3WU¿KèjãsëÍQ¥úDþmÿ³I¿­C½¦|^<iÞ[ç	=ý‘¦&§å“O 
®ÐÍ¾®N$\@ŸûÌÚ[ê=²DyÇÞß{QAã«;Ó7Vm˜Æ5n5¬+ÞµqQ<îe€aÊzô±³gÎòßª>š™IÆ? cîÌÙ³dTÎœ>3Ì=æY³Šzj|UÆrÛRj÷ÖëtzmÙéUb!‘j
JtÁ†>ß×Õ¸ýý=ÎéÁ!UåÔÒ`Ÿ
¢5e)Åb’e,;„^›!·´§÷ £L#£#Vî;¨u8ìv›­Ê`É§½bŠ:Ò²tªÑ…‘¦•5öÙFHi´knvº£ Ä˜uÁƒuVY^Ö‘gé[Ke•i¥RÄ Ðƒ;Ro¥eI÷6|ÄG××¹dIC-¶®	´‡@6óéµ+Sùoß9ºò¹Í!×nÜkó%.Jš6²ŽÀðm#ˆÒ¬Ù”È¦ÍLIØµ¾žö=á±î÷Ps–Þ#Yúœ˜åÛmtjl|æZsf0J£Ôš˜êí´8š«wµÈ2çÍñ›âgÏ	9;çii"ñsý½?$±šTìîÖkèÕe3Rµñin<§ûR{kC›,16’ºg‹Îˆ×nØØêÅj’£¬µm\ütÜÌ Ã3eÊ”§/&ß8 ¾º}‡uýW©Ô¤§G™)´ñW‡ ÝÂŒ’ò,JØ¯ë®ÊÍ±zÁÊ5YJƒ!=ÎÐ}‰êêea¹ºç$ÕƒÃO,Wª3øÿgÜ»ÍjgX–\~þ¼ÇÂfêTý¢±Rkª¦,¹úìlºÄT 	y§Ý-W)Ä!g0X˜ŒòƒFµ´wUQvŽ],Å,Ä ÂGÛjë8Z­{ëFpp‡,:Ýîþ+—§ïY
‹ËßZüP8áÚ›^[£ÛË·¹Î¶Žˆž¯ê¸¶†ý-²”•1øÎéþÃuº\ñÑÁÁK’¨˜ß‡®v.^²gÏC®½µÅ'KLˆ$]à<Í¹èLÙ(‚Ÿ«Á%¹ëIÈ•ú=ì÷¤¹î?à‚¾eEFp |;0n§ÓÝÝ·‚±Y,ö@LÚƒÃß9£ÀXàoÄÅ”œ­ÊÏ¬"çöàw÷ J•­I“UP•›ïÔ–*™ªœ¸tÛ€1 53è‚}ƒo¹ÐÓõ2BihÕ¹’S4dTüy#¥†äÂ¿D7‘Sƒ÷ñÚÍ¹ùUÒ¼2å -„Rµ®Ì$® CŸ+åÜºÊ²Œ@æÁ8Ì99fV[^ î;Ë'6n©JC¡º( Œ1Átí¶ýÚž%Ž®\þô6Z”´t“>©9HâÏ=§çªß×¡ž‰!„ðµÖ7uÊRùÀÍ×Éß;¢îÛC ’ˆ>ºk{K]õ„„“Ëã#aíÖuïzßCÂ£Så/HîÎìÚ[|TÊh†Aù\]	éýïç‹öá3¼zZ@ý<aÀ°m¯Ø±ç­·ø×N¼vÁüùdœCÀðí –+Tòž¥‚¢^7ßÆvhtj9+ÕJ¾‰ÞÛÖö÷M¨ìþ×ð58»~VfEð;Akœ…PYåûšÊäb!D¶Y^X®S·Ó-VlÃ³tUnf¾•hJ*Mƒ6ðYgA_jux…þq)µZ¥V)æüefeW¸ÉÐzªkxmEéƒ–ßè£(ØW}î+tÖ”ÕxÕ…e…iC&beÿJûž»»Æ`¨jLjºÁº­¥ùz‹Wc*ïW«ƒqTUy©Œ:p À¨p­µÏ=\²¢é±ý}ÑÚ!ó47úbS’IËûMíIIÃ**ˆJzd¦Õ´Ù³*•BÈmÝaãâ–$ñq}þ]}{¶K\“éOÑ²q9   IDAT8ÎÓdó,Z±ki„kÿæbËÆiÑÅ³ý÷U[sC•B*ßàC‹8M„`ˆ‹öioØQÙ*KÕ¢Æ(À È( \y§EoVÕàäYR[…ÍM»…YÆB¹×\j'ÄVckÁ^{‘¾ÈÞ=J¿,ã¦‰¦¬Ú¨±ÊøÞa£ÔJ ‡JìŸm6“Î(Éó—³ÚÄžŸoó7íû%¬ÛVeóŸœÿÃFY¦ê¬À¸»Ýf­Ðå»…”&¯¤$$%+u¦Ê´óYáCÀÞòå7¼ÖüÌR±±²pÈÒ¢]c6”Zl4«ÐUV¿©ö]ƒMšUžì­Á2¬X™W®Íê7º…¥­+£:Oj p~£,2Êµ5Ø\¢„ÇSÂ=ëjëèÄÙýÎóþ†'Ó6t/E÷|O'$ÌÓÍ\¶aOCâÒDL¢þøÀRòš'îé¥	þûA."¾6_pçk÷q"IïÕ^¿¹¸–KZ17PÕS&Éâ’ç'Çò{H’ç$í_s°¥}6é¯¢á‹J’"yàÚšZ	šÐFý/Ú³ŸËºaK}ÄÏ©òK{‹MÕ.ß›²¤…ù™H–Æ'½°Nöôo*??xèëÇîÿñß! aâ5„‚0ñuá¯¹†ÀPÄŠ¾>~šŒšÜ\¥ äN1aÅª¼Ba•öªü1¤ª‚²êž=ûõà`E™†ž®¬³ÂP#Oó÷ñOyj•êò
ÒróK­YBÖ?ÁJnW¬5eì!”«³2”nQgiäçmàûÇ¥dð?ºàˆZH‰lîë”2,ãõ²bÅ`#RX¯ÓVSQQUcs…F£f„öô-^»Å /²
3JÊòzr±"M§xg•¡È!×•«Q] .¯öÆÝµ®¨ô%>;¶zÃŽúÄI¡ÍÂ`‘QMxãÞý	éIÓ¸æ­Ý[D±)šˆuõt{¢	ÇÕŽk«ÛXl#©+–Ü¬—E…wÒîvÉßd®–Qtt0áà\{‹M²¹ÆÅ	ÁûFIÅˆj[h_j¤$X$C¬…ÑÞÒÐ&K Fqwq®¦?·¯tÇ9ð5ï^o¡£•¤£‘=&Qý£KW8½°Õ¯»-ÿão#ë<ƒ€.Òé3gOœäÈYqýu†âµå§gWÆs•iib7íõ:Œ#›³nÚmÍtÈÅB©Šâ#K_ï¿=8œ4kÈv«óJ
ÕR)qÛÝÒàjFš!+2

¯»J,§X¯T[V2Ø!•f¬>ß„&l G¿ B(WªGf†¡n¡œŸûÌXºÆ`°0
MaeYšJJ›3‡W&•¡m¥Eæ'QjMýËžÃk5äœÊÂÊ,ºo ÀeåkÞc©ëŒˆ¥ùæeüÜ9ÔrË¶ú}Rä€ý¢HAËêµQk’Cæ8ÀµÕo^½…ŽÕ®~<®'(QIÉ2kõâ´”«zWK„f®¿„ÏeÝ°fGÇÌ%«Súz
‰b4Éší¯XeO$ŠZªë;e³c$þòº®È¸¹£É7<Í\Ü¼ž|cÐ‹Òu»ÉËr5(%0R(2zaoÔ|pØûmÆW‚“\óRYeÕF•»(×Bé
ÕÄY¥7ØYJ1c­r§inF‘¥æS…Þ~ƒôàÈ+÷Qá—ä±z½D¨ÐPB–oè•Ò|ðA©3¤v‹Å¦6jFÜMq˜õ†›ÓÍŠ)•&K›¥Q)å—¬Vë¬±Øˆ²D1XÍP…¶ò½Þ~.Ã˜°%ØãÃb®à#!©º Ü¨UŸ/Üð(¹ú*aV™1£S à²ò5W¯[UK4+òãëKVåµèV-š?·~åÓnÙŠÙ¯´¹ŽŽŽvOGí¶ÆÖ†Æf¯ÅRóˆÿPOÝªåëë;Ãâž(ŽE÷«›¯yûJC­‹zý‚ºõUòG6–ÎMY2¿uµ1ûá®0YÒãl>ßh?h~n}‡÷šyÁ){ÂbžÞTœE¥>»B°Ã²:{½G’0gáRM”€?ïAODBïíåk,}jy­'ð¸iqÊ+aq¹[×§¬[¼ÚÖXkš÷SS„zÕæ	¾¦ŽšÓ]4tð‹®·èÍïwòM•‡o…'>»uMîf€aA‘Q ™°°02*£;õZõéš2¬TÊ¥J…‚µ™IAIZ°ÍìÇàdzv?§GÏ™„TFÖQ¡¯pÚ¥”Ð¢Ï¡íN¹ÎTHUå[(užÚ“Ÿ£'¦ÂŒDH¬*(ÛWàŸûÄa³VT²nÆ?E–¥ËRË/&`ÝÖ"}©“Ê+T]äðÆYaÈ/­rzý½KT…F]††Ÿ÷€*C¾¡Ê«,(/Ñ©08 †Àyê×g'¯ï[Ñô˜­÷qø]‰=» È¨Ïup¯ese‹$u…qA‚„Ä­âÖ/ß°nûšµK–¹Ö­Z¾¸qî¢ù)ÑÍ›7Ö¹1¹Ö’y…Q;Ö¼fsEñ_¹Ë’Ö¼‰žçà'Šoy{ÐæMÔCK·=´4d…dÆ²òw–¶«@›º¤8uIßÿ,¯¢„Ù½—‰Ï+'ïœïYQ¹Å€um›ºú¦^ê¢«vßO `”PdôÂI¼.8Då)¸4&	F™\	nŒŒœ>}ú¡C‡FtTlLLddäð÷çÛö†l§˜u3TAå{er¯¿GZšJl/²º¡F­ðV™j-ŸFPiÆòÞ!$ý{pxí566ØùÀk3dçXœ¬øˆŠoä+¥¬*Ë˜¡’26ÿô²B©ºÐT`ÐWYiµv4£1„bJ•¦ãüsÜ:vkUM]£ÊmÂárò+Ü”¶¤dDÃCXþ¹œ³RL©3´D%¦”*å`£]Bø£}Q•ƒQ¤V—g)n Àyd‰KW­Iä×{ÛþÕ«ëƒû„EÄ$ërÍ¬V¢®6×F„–Vlkª=H4k^JÖEDÞŸ»‚³uDˆ$Ó´«J¢6›këé¤„û—n½Ç?Ãg÷aÓ´Åë8ú5ƒ Œ-ÎÕÔ*ˆÓHF1xÄÓÒÐ!×D£ªÀú–p’;M.5…KhL‹Œ¶÷Ý8õrLäyâÄ‰O}2¢Cbn‹
‡ÝLg¥E^÷$$^[Q~©(µ:•×áU¤i„5ù¹¬*Ïh)šá®ÉIÏµzù¦|VaIÁ91Y¯Ãæ`)¥’
©–Á:-™é¯ª°Ì¤½â¦@eh;ÿ„Uƒ–KWJêÂBÍE÷ö¨2×6M)Å¸  €~¸¶;j¹äy÷G8áð5ï}­…zuC®V_e$SFö`L€ñâ²      0|£80ï&       Œ{8       `ÜCÀ       ã       ÷p       À¸‡€       Æ=       0î!à       €q       Œ{8       `ÜCÀ1ö8×‡öænèÍZ·ö-:¸Ýó—u+^nò‘‹àÙ¿6ýû      àêq-ù¶ãÜ¶×?ŒÈøYœˆ|38÷ûUUý 6BÀ/´wp’ˆÐ§âsÙßý²Sðû¥ïú#Ž®—»«%ÿ#Q¿÷ËåïŠðüiÕS¥ïìÜÑÙ^üE´€       \ÝÆ&àà\)ÿÝKÿÝÒIÂäwþüÉ<í]üÚöª~WTþá1~Ûs—?©–õk˜ûZÿôÊËU¶¢6”äÞpô«+îŸ¾øÜ}~¡õ-ÃÚweO­É½½³vÕ’ßÔÜ)Lþ£'ò§ÇŒ18Ï‡6à	Áy/äûÐ´hõ¾cÝgŠyrcÑÏä‚~çx}ý‹o~äß!,úÅ¿zòAJÄ?«üÿªú´{—ð[|rùÂå}—çZ«Wä—µFÈeÄãþ²kÅ£{øƒo¸5î¾'õËÛ%½»uüs=âçË—«eEÏ_Ö•~œ¢²ý“çŸâŸkGÃKÆ}·/+¼Oæ“ß2š‚ý>:>ÜòByO¿®Ãý¹¯eEëëÝ¯ â®þW      øö“€ƒëðt„=øÜkE·‘¦WWýîåª„ÛžŒ#-{^ýcçƒÏWd„XúÂŽWÿ~{*´Õ-VÿôAçOç;3ß’ñ¿¹ŸéŸà®“o¼ß¾xÍÚYr×Ñð2¡wãÖýŒê›pž>&·?š¥r¡®.ŸèÎÿZ§O‰:·3çúS©áõŽ„œ…÷E:Zlú¸£+˜+nP-Ä.œç/¥ËË^~ÿö5É²ÞËD§¯ßN|Í¯ËÈ²ßü2f°$M¯¿ú>yðçä¢ðo8wûÂuÁXÄ³mqÓú¥3¡Òû¥¿ù†«      À·Ý˜YÂÏ~|wßö/;ºø6ø??ê¼í?»]""êÕ¾R{k‡JzPt\xÇ~Áã-º|­µ/njg¿ð“èa "Fu[øGîN>y=×Ñòwû/nó^ˆóu……‹Dƒ]ºãïo¼þqô“%y‰ˆIþELð˜~§¼]E	þ»càåùÃ?þÓG‚ûòBŸ0çiùØ'¿ñ±Hùú?~ÎÝæH«6Ñ‚D¤gˆ
éôønÍ5=§ö_¶Óý—ÒgZÂüuÞñþª§þàíâë8&ú      €1­ÁÁµ·þýO¯Ûª'o ¾·/<Zîß ˆˆŽ tù¸„ˆa—à:Ê_ßò±|áF•ìÜƒ|îíŸŠî|T> ùèø¸©+f–üWá:;}¿Q¸äþ™Ý÷Ó¹?¹«÷>ÏG­äÖGbÎßÅÂG¿o÷ÈTýƒÿúæ·vÚZ=/,ÙÓÑ%ûÅ²ÜÛ>}ã¥rÛ—·þ|žŠ>Öô¡/!ûÇ®üõ6Âd,Õ?×Ù3D…|h2¾Ûsžpù}yç¢      p•³€ÃG¿µé÷üØvçÂ§ü Ú»|DÖ á¤«£køgã:>*ßÒ*‰pÛþâNî+«ÙñÑ&ÝÏ6^gÎs·¨4Ñþñß;oûqÔ…RÁw=’#¸ývŠ|lY[jèXVÔ;¢¤‹ãøçx®?äç”û‹nJ{Êù5_Ú‹³R‹ûÝp_^FTÿx¥ýý­¿ûSØ,ÃFííáþ1,/®,ß©þ™þåûn(¢ägžS·¾eø(pÏ»ëý7úõàˆëyù$Œ       ÀÆ,àQ?[þâÏ¸öË³vynñía"éêípÀ7Ù#FÒhïêºaÖ3k²oýæÅ×m÷-OîîbÑSƒƒø\ïï4•“åúô¾IE:Z>òÉ_xú‘<á>yàÑm)?‘ÛÞ²{‹	X„ËDäÃG"X-+®   IDATQ¿ØøÇ_t4¼¸ª¢û°ÞþÂ¥[^|áwä¹gìé.âkÙSÕ"ûåssokþÃ¿ûã§]²~µî©„Áž "Zý“Gg=ø“Ïë†×#rŸûYH(ÃqÇZß-}æ£óQéòµþwAæ÷Ñþxo‰‘pÕÆHs‚      À`"Ä“Ée4ÆÓÄ
$·ý(†”Ö·vÎ”ÉnO'¡þy>¾$2µhøÓ›
n¸ó§Ü!!?ûÅm+^yýïw=£êßYCu×OÔÿ½êý–/S¢{"†ŽÖ=2µ|„ÓÃr.üƒÛÃÿhûèËä¨ótEß÷ó»Þ6|èö=(ï~b¢íºœ€¸Þ2þþã;µæ¾}›>"CuZ\/|ôû—ºfÝð1‰Îî?p‡ëð„ÝõÔó+üÃR†¢"¸!æÁÅŸüI Àj¿"£œËnsË‡ÿFÃÈ\æ¿®      0”kÈ¥ÇyìoüÁîâ?úkSgDŒ<<´üéCG:Zþøß­ª˜ÒþþË†ÿH§„ 8N$"á±ŸÉ>ªÚÓâ°Ý×j·y"¢£¯ô­ù»G~ç0òŽ¦}Uûƒ'ìøè?¶Jî)ó!Šùùc·w¼^ºå/­þ=|®/;Ï=×ñÑŸ>êÝÞÿj€s7Ù;c~ö@lDD”\>d§õ³§²"þô»ÿþ2jÀI¸/[ÜJ~Þ" wiŸú	5XŒ!ˆR%ÏiÆ      0ÞŒEAÄm·GT½²ô¥†Oùžxfy`úÕ˜ŸÿòÁß½¨Ëx‰¢È]~§„pô—­-wvtþ„p™–¬Ü÷eàøî¿ûÙÍÁ	DÎ9yÔ²Tümù»jý]„t¶¼¾ê©?†þÙíé›ŒÕ×ú‘Gv{ô€ªÄ7È…îº]ÞRe|rÕ?Ý7ÜžñÔ¯~ÖoJWÉÌÅkIùú—žþÅüRØwþdá}Á>\Ç‡/äV‘®ÎN.L~×£¹žÛWB  ž¦?Ùé'|ä©Ûk8ŸôŸ±¥‹tºëÿXõ‡3z—¯Êhøð¾Y?QÅEù{rø>~÷ýÎ[sn@'      €¡M8É&ßF¾ËoSÏä©#È7Ëç²ï«úãþ¿üÝŸ~Ó]yÏþ¢;CáZß2¼üå]òÖ¿øîúÅ/~œèŸO–ó|ôîž?üÑÖJÔú™¾l‹~rá}Á>%¾æ·Êß—ÿlæG/üªêÓùÔO._x×€WØoˆ
\@ûqßSÅ      ®$_e$SF6áÛpp-U¿ýãŸñ«      €+€‘AÀ     pEÀ1EF       .+       0î!à       €q       Œ{8       `ÜCÀ       ã       ÷p       À¸‡€       Æ½kÉ7äÌ™3ÇŽµwžèììôuuq]]]üC»æškÂüá“Eáá¢ë¯à×       øFŽ¯¾bŽý²½ãß×_/‘DH®ŸÁ7Úù†ûÄ‰	íôéÓ]~Ü©S]ü»÷éáCÉÔ©ß•^w˜       \Ý.kÀÑÙÙùù¿Žœ9sæ¦ïEÝzët#11`Ò¤IüãˆˆïTtüó¹&^3ñ¦›¢&O'       W«Ëpx¾lûê+FvÃS¦\OàR¸îº)1·M9v¼ã_ÿr‹ÅSn¸!’À¬õÓÖ¯¾ú*tMô­Ñ×]w     €‹v™ŽÃŸµ^3aÂtê?\j×O‰à>;òégŸµÞ|s4+ÏéÓ§—.Ëÿ_§sÀz¹\^òBñw%     pÙ1S±sç„	dîcY"‘ˆŒsŸûõ*2Æþõ/wXXØM7M#0føŒãë“_õÕq”ä‘¯YîºÉB2Æ¾øâ‹—·m=w=ÿÛä½|_ù›šü´~úé÷n¼18"éâymE«þx­j†|È—Ê8*ŠötPq·ˆ¿±ÂÃ„u[Wåä®·Xêó<U €ñ;Rûì£Kwþ^‚ê–)þòcí‹-ÜÐô½%Þ"´YûÁM¥o“ÿwçMß!—€¯yÓòèï'ý@‚Úg  @¶Z¶UïÙÓÜÜLÎ’;ï¼“\I|'ØïLŒè1oÌþ¬u’pRdä÷Œ±#¿÷ÅŸväÓ›§ÝJàJrêÔ©¡6µµµm¯Ø1è¦Š¯ïÜQÁ‡ƒd8X§9sV‘£wYQP]­SS–õÒN§‚,0vCz¦…îÝQ¨,¨,S;JÍ…I|ùSwUN¦E^RY¨z6¡¶¬’²—V9½*•”Œ‚ÿ•º	¥¤óÀª½q¯M0{‘šÞñü&ÁªÅ‰¢Æ7vÐÑ)3<–µQkÒ©Aþ×Õá¢]±]äÒãÃŽ¼¼jwÏ¢,yõ‹ù÷ [áU;ÒP½Íd©÷ð÷CÜœ…KOŒôß“œëÏ¦ÕÅûÝ]D–´Ô¸,9Š_é£ë^Û²¹º©ƒ„Éç.}:=^¼}}®w¶¬Þàß9,J=YnzlÏWÂ½kÕŽpÝŠT‰§~oµÕjk<²aãœžÛÞ×üNm­u_-[´ymjd¿¿íÖ¯;¨^± ¢i°‹rmµ»k­Ö†Î$ã¦¼¸qÿ4Àå4aÂ„àƒS§O‘ñolçõ|ÙvÍ„	H7.›o¼‰œ=û¥×CàJÒû[cDŽ?ÞæiÁB¥¶²é0¯©R«²/V¾ûñÁƒüŽMûŒi
eVžš±èKmn‡9=î–3ò­^2RŒ³(}FºÙÉŽàa7þ‘T•&·éÍNJ£Uºá
öÒÜ|‹cäÏ à²ð5îÙÓ¡^ôxÊüe‹â<¶†ƒµÍMÔ¼ÜùºÜî5Åum3¾ÆÒì‡’3òª[š¶=•–üc]e«O ‰›ÿRÍþ·kÌOÄÉä1Qh^Ý8WysCTîÎÚ·k¶Í¨3™þìâoIî£6ÇëËßÜµv¦ËR²»ÙÇg{·lwÍ\Uýö;Õ¥éÜžõæ†öÀ9ÚÿlÒoó$®)¯©-7¦F‡VÁçŽ45‘¸X>¹à|>Ÿ@Þ?Ïã:}]Iø`ßÖ¶·Ô{d3äC\”?GDÜ¿ £õèc?ÿéÏ~ñpÿ€ŒcØƒãÄ	ßW_ŸNÝFà2ºùæèC‡>…‹0¯Ê•ãÌÙ³dTÎœ>3‚½YºJŸé	ËÐn±nèýÜ6s~‘•ö²ò¬’Ûo—kÒäTV¡VÉZõ¹âSáh:P…R>ª¸@7ÖK»Y95È>áÐnÚ+–¾ÿEo^ pÅáŽÔ®3V7dÏ/©å-æ7©Ï.¹‡ÿZ’¾l¡+ß´¼˜—%E¬?î°	d3Ÿ^»2•?=GW>·¹oƒ¯q¯Í—¸(iÚ]Æ	A”fÍ¦$ÀL›™’°k}=í{(ÂcÝï¡æ,½G&"²ô91Ë·ÛèÔØøÌµæÌÀž„Rkbª·7Ð¾D	i®ÞÕ"Ë,œ7CÆoŠŸ='äìœ§¥‰ÄÏõßÞ’XÍ*vwkƒ5ôê²©ÚxŠ47¶|bí­m²ÄØHêžÁ.*"¢éIs¦ÇÉ<MÛ	 ŒÐ”)SžZ¼˜|[ŒaÀáþÜ%»áF—ÝÔ©7|þ/×÷§Ç¸ª©cy¡Jì…’]ä°¤ßVÚ™Â:£&WQ°¯Z§k
+5yÎšÒ¢
+UPR!³vs~n6Ã0R­©@5ÚÆz­†YzÛ€Né·™{‹5%ûÊ2ä àÛF9sQÉLYdGí*7EJ×~Ë¤è¶W–-òù7‡ÉæÍÙ!
'ÍÛ´Ï¼áêpCöCÏ÷-Éç¼h^;â4‚kozmn/ß>ä:Û:"2{V·5ìo‘¥¬ŒÁ÷ß@‚éF(®ÓåêŒˆ^’DÅDø>tµsñ¢={rí­->YbÂ`çi>ÈEgÊF‘¢ù\.É]HB®Ôÿ! ŒÞ·¬ÈèX_}Åœ9s3Â~#®¿>â‹¶Ï}¾¯D"LAzÕòW)3z2¸krÓsíJÓ¾²4©¿èEö¬|Ó³ŸíÞ*¥Ö¨•;ªªn1%f½v›M!V+¤cÓB(Ï*ÿ8«oÙ[“¨ÁQ ¼T×c½N›ÝIÙ+  5$jßÌë.ˆbÓ—Äò’SÏÙq¾åíùÝ}Í[—/ÛÕÚ·hãÚôé×¢HâÏ=§çªß×¡ž‰æ"„ðµÖ7uÊR)ñuúøûµûöˆ$"ÏÓo,U{K]õ„„“Ëã#aíÖuïzßCÂ£Så/H¦DÝ{5ø¨”Ñƒò¹º"Òû—‡	¹( \Œí;ö¼õÿàÚ‰×.˜?ŸŒscp=úåMß‹"ðáß|ïÑ/p\]¢Â:­v¦oÔ†<£¼)ƒ¥+²Ó‹Ø¼ò©%3×’Ÿ+Vf–›2B·ÍbÐÏ2°i¦jSš|Àés3ó­DSRiÊ FøÇ¥99°CÄF³XÇØô³²+Üç9­<«rŸQ%x-»9g–™H•š´,.C9vÝQ  FÆ×\]¼Åêêð¸<?µYBBD{GHK1,*åé¥©!)wÄ¶£ž‹’…wFøöî²Í\–|n
ÑöŽ~AÉ‡ç¯A*KY»)o¨ž‚¨¤GfZM›­1«R)„ÀÑÖ6.n‰¼}þ]}{¶K\“É?&çi²y­Øµ4Âµs±eã´èâÙþûª­¹¡ƒJ¡F•oÔÓ‚8M„`ˆ‹ÀEù–“€ãÌ™3íÿ¾õÖé#:êø¡/7šüôES9éýªºyÂÞ'’õÛåtÃŽO7ÜxÓË|gáÞ{û{×Ô¤×Î›_=:%]þè÷Æ¶fê;ñ.M~p×dùæ‰ÅS>ù¤åæ›o½fÂ7úžÀåÔoˆJi÷J¯ÍRCÔiÊ~•?Y¯ÃêdXŠeÝN/¥-)ÉÐ¨äÎ¢ÌL¡±¬@WönšÃÉR‰°n[•Í_¼“ÿÃF á`é}®V—•ôÔ>eìEÙzFWY¢éÛÍ]“Ÿm”5Ýõ9ÄJ©2í|KÅrÅ¹Õ:Ä
­©,‹Ø­UUUútK‘B“¡ËÓ¥)0¯
 |ãDT‚&Y&‹ölÛÁÍNn¯ëÐ<Nq%ùÕ1«W¥J<{&W'Ÿwô´ãø@dC5§™«iÜÑpÏÜ¸?[Öï^5{`1ò!ãÞ‡†sy¾íéyÃ“iº—£{¾§Hæéf.Û°§!qi"&Q|`)yÍ÷ôÒÿý 
_›/¸‰óµû8‘¤7lh¯ß\\Ë%­˜ï¿1a2‘,.y~r¬¿°Lòœ¤ýk¶´Ï¦"ýU4|QIòÆ·×   IDATQä\[S« Ašíõ¿(\œËZlªvù†Ø,%-ÌÏŒÅç1.e=úØÙ3gÃÂÂÍÌ$ãß˜ÇŽµ_ýHÿûÈ½ûÎ±(Q÷´–G™ø•hèaz×Þ@:õÿ39aŽˆéØwL`œ2¢–üÉÆCÿÕRúqÚwëV}ïv9yè_­ù7yè–ÿž{Ý”Þ­¾W^ý—ÁÎú;öOžôË¬›
ï›<¥ÿ=´×P#ü}Ìd™¿¸çiÏ1"»>t†ù®Ú—=n$»áNyÝ3ƒÆ"'}ýÏ/N$n˜6ù?ÂGUL¹>âø±Žˆˆï¸zyíf­ÔRUz{ßZ>«¨q‹åR!ñÒ´P©Ó¨ùÖ¿×ë%BU …P®Tžs*¡\•¡tÛˆ:K#~ºáõ×ö(¥U…eºÞ™]{i‘U˜U¦–òÛ{÷”k
t5™E¥V…1-x±\©]-1¥ÊÐñ?F¯³Æ\Tã`H ¸t4ÕyÂ)¾æiñ„'ø¿’jÚsâ·µ¢GŒ©Ts#	§RžNoZ¹nc„1ÿ~Ù(ûY‹ŒjÂ÷îïLHOšÆ5oíÞ"ŠMÑD¬«§Û%H8®v\[ÝÆbI]±¤ûN¢Â;iw;‰äo]WK‡(::˜pp®½Å¦Ù\ãâ„à}#ˆ¤bDµ-´/5R,’!ví-m²jwçjj!ñsûnús.
—ƒ J£ACàÛEF/¬³³S"Y»úägÇv~6;}Ò¤áìÝy‚vþëér«öv‘©Â¨Édiñ×³çN[4}"¦°‰?x(ê¹¢þW<ýÉ‡'È´Il3óIçu	áÝ—Û\üÙæ°ˆí¿›~¯ôìÿ}èÉå³ÿ:qËË?þÎ$ræ£]‡’öö}Éœ´(Ø`›pÏ#·¼‘Öÿäa×¦?yëÖûB×9ò—Ï|ÔÑÎãKŸ;òêñkpãD¾•Çžàþyôì]Ýò‡ÐÀe¾+ùng§ÇUdàÆY¡×Ûåej±½*d?¯½¢ŠQj”´“O-4ibkö-éŽà¦š{oÓûÿ”¦•í3iúíRiÆê‘äm5‡<«¬º@Ý;<Åk-2Ø¤yeYŠ1‰Pž–§­Ê.5ÛUFõ¥W"”*2
Ë3 À•AAÉ¸½žv®«óH§lFôÍ3ÎU·a¥]½bMJ”ÈÓX'š–²ôi×šâ5«–Ü3ÚŒÃÿ,D‘‚–Õj£Ö$‡¬õqc8C-Œ\[ýæÕ[èXíêÇãz¾QIÉ2kõâ´”«zWK„f®¿‘ÏeÝ°fGÇÌ%«Sú†6‰b4Éší¯XeO$ŠZªë;e³cøûÜG7¸"ãæŽ&ßð47rqóznøA/
 EF/¬ó„ïúë#FrD×wŽ»qÖ0Ç˜„O~â©éOø¯ä+-ýrÒÜ[Ý|‰bpì»‡Î$<ô]òNû¾/N'âO“÷Õã"ãÊÈ{¥þ«üÇ]‘¿ïdº×Ûð£i÷†_sûœÿø÷¾ÑÖ± ´ãž§nyâ</¡ëÔ»oÖ¼Óoö8w<¶)Öð)ë_¼}½ÿÑ™#M_vû~ðPda`äÎˆ\{mŸ4¸JºÊ}J-U)‰ÓÁPJ©œŠWc*ÑÈIh¯µ´È.×Õ-”«3ø˜!#£€qšsrYå¦4y`áÅTèd½Ž³Ù\auË5yeû´êŒÝlv(òÊÒã"T¤éÒl'Í¨¥1¢„%  W(õÈ¢ô†7J<´+\æy¥Z´0=P±¬‹®}¾ØæD§D-LSCÄÏW­›3 Ã·dÆ¢Uú]ëŒyË—®é××\GGG»§£v[ckCc³Ç×b©‹yÄ¿ÁS·jùúúÎ°¸'ŠcñøÕÍ×¼}¥¡ÖE½~A]à£DþÈÆÒù±)Kæ·®6f?Ü&KZbô“j?h~n}‡÷šyû{†Å<½©85ŠJ}v…`‡euöz$aÎÂ¥š(Þƒžˆ„ÞÛË×XúÔòZOàqÓâ”WÂâr·®OX·xµ-Xƒ×4ï§¦õªÍ+|M5'²»NéàÕpÕKž²t—
Yþp-‰žÿÒ†L  Ãƒ"£ÖÕÅ……àwÊq¾oçnÈ
ºIåïé ~Ù˜úáÐM{Å%ÿIªíìÉ®3®/ºHé¡Ý“É¤©âÂœÈî>£uòæŽÆ†“¼>üúätÑ$ÒõÏ¾&Ód	ÒÞ`âši±âÛßø÷¾#§ïv9Ýð?GÜ,5öK7Îx>;ñå”É·_ß³2ìÚ‰ºpŽn]oÿËÐtíÂœè”QÕ	ãºðeÌÿ,È¨Œè@¯Ýl(%åJ9]a¨pd¨t¦‚4	Î~~±"­ D¡’;}ëX·Õ\C4…jÿ€Æíf¥šQ,Ëzí†YqzÂ''þZjj`N!VVV³þš§¬—2øËðõm–ªËÕäbð'Ãì) p…joØ˜¿†o=FPq‰3’“b¨i"œ"–JY¹>½{7ŽRÏ_;wÆà½ù%ñsŒ›ã=’‘~ÉÖöçÍëÜ‚ŠÜ?kÉ¼Â¨Žk^³¹øxE$KZó&zžƒŸ(6dŸ~¢Zºí¡¥!+$3–•¿³l°]’ØÔ%Å©KúÖøgy%ÌîýöSŸWþNÞ9Þ³¢rÿŠëÚ6uõM½2äEçl~{€QA‘Ñëêê~Ãìä¿:Vîèø„L¸á(ó_ÿõIõñ¾MIó»Ççÿ?oyç‰[?U}½ùùÃ»c£þ{ŽèÇiìÁÑõÞŽÃ¼Ãþ õæ?Ì9g|G×é¾óéMï„¿^EåMŸðI“ïË©·OÛ…Ì;Ì?SE	a§xOoëwødAÔäÓ.o	'?ûwÑÿ°_ÞØž¿º­áèµŸºù‰©'wïõli"™97÷ÃìÁÁãNÖ¾zøÉ¿žšv£`ÃKô–©“ý±4=V0¬ñ;=øŒ©Ç•áÆÈÈéÓ§:thDGÅÆÄDF»68K[+ìâ´•T,LÓi¬†*¶¬¤g GhÂ!”«ü	Ó»’qTèóKiuIa`>/mw)j4‡Òhó¼n©&-Muž2Á]X¯Õ®·³þñ0¦K6£+Ëðÿë™  \9$qsŸ/_)éÿ%P8EQAÈ:,~Æù*–óíÇ#ïiyÿÒ­÷øgøì^ž¦-^ÇÑ¯l`lq®¦VAœF2Šž–†¹&¥+ÆŠŒ^Z§ÿù××ô
o>~`ªø÷¿¿!XìêxóçóvO4.‹¼=äWáÉ£_í;rêŸGÛ^˜*öØrâ,	éÁAÈ5	³§=WÈìÿ4ä[:~È|’zÝÀÎçÖàà¾>Ðtêûªð×é×EUw¼÷Åé„iC?÷àŒlÇ¾2”ýû¸JþßY×OœnØÕú‹âÿ«œ&Ê|(ò­¬þER‡Ùƒ£óë/^z$ì—OÊŸö—YíúèÝ/þ«´õ½_Þ²~æ¤ep…¸æškÖ¿PüÉ¡OFtTÌm1üÃÜ™qTYIFA–ÒŸøç|-t‹ÅõE™éf§¿ÛF_g
¯5?=§Š¨Ò¤Œ½(×àTT5±È´ÐBEF‰I1ªÄAH¥é
‡»¯<«òã,r‰É3Ê¢ä \±’Ès’	Qlú²Øó%Ó¬Ûz	zX$ý˜^ì‚Š	À˜âÚÛ;£“GS6Æ×î‹˜™„©9 ÆŠŒ^ÿtuuMœ8œzŸæDÿ7éz·ìøìyú“¦¯…7†Ý3ý;GZÎÌúÑ”{¿ñè¬¡ø‹“snyž:ýîÿø„Tÿ—þô‡¦|ôWnÚ"~0Œ¡+þ …î:ÐòÉM¯v¯9þ!»hzØ´ë'÷v'¤/\8Á¹NL¸A:Ñ?8åOÃÍ7nâzwòÝî¥ÿsruãË¦NÝ¸?îëe‡GÃ_^%Oévûòí“]óÞiÿgÜ÷†?g¤…`LMž<9>.žŒ±ª \²H)ÁÊ‚}‡ì,Õ”¼w¸¤{¡ r_ÏzUá»‡‡›O    ƒ òí(Gõ‹bSÇ €á£€Ã?8bÒ¥ímpÌWM_ûÀÍ×¸1¢ð®k…GÛÿë•‘'$ä»¯æß7„õ?aSÿŸ¾îžxûÓþðÀ0/pæHsdš´ne°ÛÈ™jèy2Ÿp‘?¸ó;“Þ`¼Sz‡3Gš™Â¾cœæ†ãh¸k&užx¥Ì]þÝßÿ2Ìðá2h°ÐuªúåOª_¸ú†;¯ï{ö/ŠŽL6®ìM7‚®™6]ôýêã?“0ìÉbùŒI€€      ®cp„O?uêÒV(9ó‘½ýjê=G¿>6á©`’ô†ß?Áðr'Ã&úè*ò­¼ˆiÓœç¸vM‹¿û$×|?6ü†w¾:ð…tQœô—ÿó™¾ìË)97¦‰mËãÄ÷So} ;—¸f’€x>:úêqñïŸúî4ºëöï];X÷þ°”§bÿÕ±àù'<½èæS»_<¼OuËÖ™¡Oúši*ù±ÿc
·û÷æCgHØ5²éS\(3aÒHJUòÇäð‹«¹
      0N\¢ÙUû¿®½ã(¹”®¹ýG7•ührO¥ÏÓ|Êð_/¹ÿÓùeXØìG$?8Úñê‡'O’Ñ;yÔ·ïèÄ„»„½ÝN&Ý(¾w
·¯‰=>yQÞÍßóåüïwç9“v|ýƒGnÝúãÉ:¨°G¿ªþèk¹~Î”@i°Óžc§ûïâï$òÉÑ=S¯¸þýÏïž¿@ðÒ‰'´¿ú¯ÉÏ¯üëJù,ï—K+þµ´Ì{$6â?¥#ø¼ø@~      ¸
ŒIŽë¯øôðÈ&Œèz÷¥O·÷uýè™EåšYO~û}9íòïuöŸ5Ÿþ×_¯™•½5îÌ†—¾¼aºlëÍÞ¥ŸþW×­¿¿o˜c&=º<öÑÐåïMýÃ‹Sûí.*4þ¿žÇ“}bzzjÇÒâ6WlÄ£q“ÌÉ"»ëÆï¿/ÚÐÕ½F8E8+õ{ë,êÞ³óëwÿ§M¿·ë?ŸúÞí=ý*ØgøPcÇ5Ø;?‰ë+;6iºtyœû¿}ô	!7LFèŠúÏ›3¿3¢1?ÇŽuPÑÿA       ®Nr§Éøô0=õ»Òë®o÷ÓoÿkŸTVx×ù™œù¿¿|^.[~ûD"˜È6þÐóí'c¾ûû§¾wïõ„p§O
&Žõ$#'½Ç_­þ÷«|Ñ5áž,ê+tð4}>ï•¯&M?.{à{ÁÚ«gŽ4}Y´ëhå‘³þ¥)¢ß¯¼ùÑï]²5Ç™ãííG}§#   IDATo½…"p^íÇ}7Næ]
      —ÉGÉ”‘M¤4VÇW_1ŸÿËsÛÿ#ðMhnùg”üf‘CT.      ÀhÇ˜Ôàà]wxâ5ï pÙ;Ö~íµaH7      àê1Vï¦›¢Žõ¸ì¼G½ò›ä      àª1†ÇäÉáâëÄŸù”Àetø³Öë§\ÿï`‚X      ¸ŠŒaÀÁ»á†HröìmŸ¸,þõÅç×L˜ •Ê      ÀÕdlÞÍ7G“³ä‹/qŒ¹}áž0aÂ´i·      €«Ì˜¼o¼‰ëâ>û¬•À˜9üYë©®®#¿G       ®>—#ààÝ<íÖïL?t¨åØ1Ì«r‰;ÖþÉ¡Ã'‡£ï      \µ®%—ËR™(\ôù¿\_´}~ÓMÓÄ×‰	\†9Î¿Ÿ×^vÓ÷¢&OžL       ®VNr§Éååó}å=êiÿ÷¿§\!‰ønX7ÁÄ‰	íôéÓ]]\—×ÞñïcÇ:¾û]©tê"ÑuF«ý¸ïÆ©ÈÚ      ®,_e$SD#:ä8‚Îœ=süX‡¯Ów¢³“ó·Û¹3gÎÚ5×\ÃÇ@‚0ÁäðpQøu×_1aÂ     ÀhÇå¢2À5®‰ˆø.ÿC        .Î7p |t0'      &B|Y‹E"à ½Ëü×      †r™¦‰       ;8       `ÜCÀ       ã       ÷p       À¸‡€       Æ=       0î!à       €q       Œ{8       `ÜCÀ       ã       ÷p       À¸‡€       Æ=       0î]K àr9s–4¹»Npg¬Ÿ8aÂÝÑa      FÀerÔwæ§¦öÏþ}zÐ­~4yÍÏ®#      0*8 .“¿Ö5hº!ž4ºáÚ­=!¸v‚>ED      `äPƒà2aOt}Øµv-ŒˆºvÓŸ;Só     ¸,†1oÙ²¹l‹Ïç#ã€o^¸pÂIøŒc³íÄsÕWnÆá®ÑgëkÜ,¹"°nkQnv®¡†fÈ¨±Ã†,x·­(·ÈîlW¯Û{WìŒîšÜ{o‰Ë® /å[Ê8,zþM›O‰q;´÷
¹ Æ?_óŸkëpä’âŽÔêµk¬mdÈ‹nÊ[VI_â« À8µ½bÇîêÝoîÞ]¹kÿp \&g‡ZÖ¿¥7ãxåÀè2Ök7çÜ{‹ßŒL}•³·)ÎÒ5úYqþõqé[O:ÁðkÓko{ Çl	-üû§Ïž(»(dK[«l^¡T,¼ÀSaœUúÌàøs¤‡>™^^›þ[nK7;GÝVöÚ+lr]IžÂQ5h1¬Ó’ó@f‘#øüø¼Á_CÔJ·¥Ê1ð)óõ™™ë.Åzi‡c˜‰ÿ‰™íbµÂmÖ[^±¸å‚0ØƒÇy­†ìôn™¹¥}Nïb~Õ¥;‡97Ç`^ÆÕÕæù îD€¡pôÞÚ:×¹_˜q®Ýy%ÿxèŸÅ»' à8"È0ðaÇ‚Óf—h'puãŽ4ìZ•¼–m­oë¾Ñ8×Ÿ×ÏŸå¿O7îwWúèºMË3ý{¦=¾jWc{ï=és½Ó½sªv]usÈÎÑ»V¬ÙË‡z>W}åÆÕÚ‡Sí
	Û|Íïì*Yšúóå{ÛÞáíÖ/+mhò¢Á'¿_ÿóÏ6Öã>+íMÛ–>¸=]¶©ûöð5×–,zØÿq/Z··¹û½çÚª7­Ñý<íñÒ¦s~Çµ×íýÍ“:w±~S½™ë7hÂ„	Á§NŸ"ã€ËdÂPë{~§ðG•N2ãÖ0>ãøSóÈÚ¤Œ£4×àT›šþø=#e7ä–›¾ü†|ƒCi|ïã¦wå6½ÞßÚõ7Ös+„º}>ÜTÅZòõÝ`~K~ŽVßýøðÇï–¨ÅÞž¬ÛZÊ!NKfÜ`Mí¸ì*wÏô9ù^U©¼²¼¬PÅTåçè«ú7‰»¥Ôê^()	œÎIÞœîí{ÁðÏÑÿˆ¡Î!võÒNš9w“×f©¢©´4…8°(¤Ò´J§Qååi(qÿ]gM…Sž¡UK{O9hGÆ^š›oq\8qaé*ƒÞ&Õ•˜L…j¯9¿ÈÖï±ªðÝÃ¡–g)TþO,Ä»…ªàó”j
Ë«»UÓ*)ð¸¼@ÉÒô¥íw2\§¿Ýºg³çøÉÕæñ˜^úýï7½tôèÑs®"‚è”µoîûþ§öåEqÑÉÏ¿þNp±zm
uÞzMCæ¾ÆR¾áš‘WÝÒ´í©´äë*[}IÜü—jö¿]c~"N&‰B%¨«çª3onˆÊÝYûvÍ¶¹u&ÓŸýOîPíFss¼¾üÍ]kgº,%»ùÐ‚£÷nÙîš¹ªš¿'KÓ¹=ëÍÁ¦mûŸMúmžÄ5å5µåÆÔèðÐÓij"q±‘Âù|>(¼ÿ}Êuúº:’ðÁîÞö–zl†¼cˆ‹÷ÔmÛEÂ‡îÁ(øšw­ZW/Ñnªþï7Í"+Û6wx’Öî¬~yeœgGñŽƒ<ƒÿ„9"’ùE ÓtÿŠ«Ô'su¦m<¾)Y>öóŸþìgðÈø‡"£ ß°ã'Î<¼©ï?Ïl—¿CÇÉ~ï-Væ•Wƒ‰\•AYkl4«R²Î«W¡Õjäü¶4]FEv••ÎÐ)ÒJª5ÁÝ…Š´,µÅ`sz³(9ë¬°8äºòÅo£ÔZ]Ï³r[‹Šì
ã¾²,…ÿ(ÆQ”­gò*êA€×^Zd%i¦²’4ÿ)ˆJ¥
³óKKmêMO8à¬2[ÅiJ«óB¯‹qXr3-ôÛ+ªÄJm‰IÊ_Êë0çð)Ë;ŠÕ¦}åiò~OÕf.­¢½Ä0ë6CßZJ[)§^ÿ6–Zn¯cÖmE}kåi¦JSÿsòïg¹àëÒÙ©¼Ê,…XH
Jt¹9¹9B“©@-ï9Ô¡°R…\<Ô)ÜN·RH‰³"ß ùã7ÉM·ògfùÔ‡&ùéEFa‰V1ŒDéFp†-{Ž¶ÙIKÛ­÷Þ2ü£6šLòŽ;öÜJý€E0îqGê­´,éþÁ"
]_ç’%%Q2tuøHØà<læÓkW¦FñíKºò¹Í!×nÜkó%.Jš†¶áÕM¥Y³))M›™’°k}=í{(ÂcÝï¡æ,½G&"²ô91Ë·ÛèÔØøÌµæÌ`”F©51ÕÛh_¢„4Wïj‘eÎ›!ã7ÅÏžrvÎÓÒDâçò÷‘ÄjP±»[¬¡W—ÍHÕÆS¤¹±eàkomh“%ÆFR÷vQÿ_®í€e—‡Jžá²¡/ÀØh?¸§¶3!W—ìÿ5=iÎtÿJ®Ýå!1ÉI±ü§˜ùóº†}îvn†H òï'ó4m¿ÐiETBbÔu>‘áÐ7cÊ”)O-^L¾-p \&CQ9u†Ø[æÁq+#Ú¨‹Å„åñm]§“‘ª•ÁV³P®Pˆ+œüú|èÐ·»P,²^~g>Æp8¼rM_#»ŸZäŠ‚òŒ5‘½Ž;£ÐêIðìrN«´™k^M á`éšÒ*6£0MXzá€#Ð‘¡p¬»"'³Baª,Pö% B…¶²²§SCÈ¾sf¾càñ5E†qAu“®÷^[~f¾÷ÜHqZfoZùÁÂžþþ$™995Túp>¬×Vš“_%Ì0•dÉÝUzþÔ…ZSÉÍÉÉfŒeÆŒÀ{çï	“ïÔ•iÝùéz{O|XÄš’}F¹9¿T^R®S(²LeÊ
½¡jðî+bEVIuràç³­ê‚o…Áló
ù½LJ·9?×lg¤*¿ÔýjY·­TŸÏïC¤Ê4x˜]‹ºý î9ÞþýÄÝ·L#ÃôõÉ¯ƒ†9w`üóÑ¶Úº„„ÁŽVëÞúÄ„Ü!‹N÷Æ€|wyú¾¥°¸ü­Å…óm‘¦×ÖèöòíC®³­#"³çmû[d)+cÐéþÃuº\ñÑ’À’$*&Â÷¡«‹…ìÙókomñÉ¢i©ržæƒ\tæh±>WƒKr×#’+õHÚvlwÅÎ_‘Ð¸ÇØð¹é°ØE”¤ßZA$-Úc«§gSÄÕâD§HFô	smûë;¢n|søeUìÜ9a™ûX–H4îÿC€€à[‡ñƒ/×Hý_à»¡TÚÓJåbÖæu"íkïú¿ðgÄ”Üÿ}¿›ö~ý¬ô*'#”«µ……y|V!–«³
Ôk˜q‹-´™™WÑýH¬.ÙW–!'^šf¤
E¿„D(¥RÆîp³þ§äµ™-neQ)®!õn>Ï&a–'õ'6eÚx„g¬2OÕ¿Kã¬ÐçV,S^oº°bsË5šA/Ï§J6»“R+¤ƒ¤Aþú$¹z»¼ Ìä¿6ëõ:´šŠ•:S¹8?'Ö,[¡É˜’$‰UûÊt”³(»Tj,ÓJí†œªgæCSeVÏ%lúLs ú¸Pw¯ÍëM+0–gÐCQ~¦]N©´%åY³ÞRdV+ýYc/ÊÉµÊó*fPüg§×»Yåàg;yâÈ?¾:q’Ãºo»ZŽóžjÙLÿ=ò†É•adâä["¦Ý2qèçôÌÓy¿Yû›°0Á¯òž9w #˜®Ý¶_Û³ÄÑ•ËŸÞF‹’–nÒ'õo‰øˆ@÷xî9=88Wý¾–õ¼H´/ „¯µ¾©S–Ên¾NþÞuß‘D$ðyú…í-u-\ÔþN.„µ[×=¾ë}	NY”¿ ¹;³koiðQ)£åstE$¤÷¿ŸC.JÚ¾¶ƒŽ›kœái$06:;:º"zGîºÚ–N"»K»léœx	ÅÍÓ©—­È[| AÖá¡æ¯ŠÖ'Ìy¬Ï=ÜÓG–´4qòÕoÎöŠ{Þz‹píÄkÌŸOÆ9 ß€Íó¦¤ÅO°ò¿Oê¶'‹¥mVFQ âS'ÿºÞî<–í_ÃQQá”ªóü£2¼,Ã¸­öBÓ»+X›!'_o ¨²J®ÊÈ î*‹\SRæÏ1†¢Â0^†ÅFhøs>kñ_—µ›ÍNJW¦’7¹þ0‚(
Ò‘Pè   IDATÊ:ýEAìŠÂòB•»ÂìPfd(¥ª6¢*	Í7üAH¾Á*Öšú%!Äm«°1J]†bÐx%Pîu–™H•š´,.pöž§m5äœ*cew7~)…Ø£7›mj“†ã0çY{J‚„Q	J5y%:Õ¹OV(O+)7ùûÕ0B{•Ã®*,| JÖV“ï¯Z¢{ý7“º PËbDš–—g·åñÉM
#-‡·.jûwÿ>§l­›ïo>þnvÜ’Ò©ä|¦EE½¼¥l¨E€«×ZûÜÃµ!+šÛß·­ò8Os£/6%™´¼ßÔž4 á‚ *é‘™VÓfkÌªT
!p´u‡‹[’ÄÇaôùwõ5îÙ~0,q¿&ƒpœ§ÉæY´b×Ò×þÍÅ–Ó¢‹gûï«¶æ†*…U¾QOâ4‚¡.ÚøÆŽ–˜yÏ'Jøä…ÀXá8_ë÷ã–mz3Ö×Ti*)~%jãÒDAóþÊzÁ=éq\}mSŸpo¤‰¿Ç¦e	"ÿÀ½ºÍÏoÑÉk’#	|¾eEFp \&¡EF?ñœ®?gŠ¾OÚú~§ôþ¢)–®)*µËµåÁA"B4ØîŒÃ?n¥_Æn6T1êm°ù+‹å]a–Òßù@“W ±ëmN/póêB¡´{pLh±¶'ö`œ–R›8«LãÏ^Èè1Î*‹M¨6©F‘o0N›ÕÛ10¡Ó^•QhÔ
+*h*« ß)ù"ÕVfiúõHaì³]œfJ£ï!!VðH±[«ªªôé–"…&C—§T2•ª«ßŠ½5½#«ðÜ?|â`Ü§aX!ÿ.öþs’uZø´BÈò±…0'½FÈ¸…"ôù¸iV©ÍëíWÁÇ†*©¶ _†B»å¹ýMü£“ºPb¡CÜ½ƒX*ÝþO‘qÛ¬\;¼ )lÚ/ï6ÜAoÍü§ããs6
¿óÃÍ	Ù¿”Lòð³gÏ~ø÷¿òÉ'tkëW_}8sægŸ}V¶ukïâµ×â?[puð-ÎKÔpU¯XGg®ÍŸˆ+|óßê0®­Áæ%<žîYW[çï4Þ¯¥ÁyÞßðdÚ†î¥èžïé’„yº™Ë6ìiH\š8¬L¾å|–’×<qO/Mðß¢pñµuO…ÁùÚ}œ¨oB{ýæâZ.iÅÜxÿÝ*“‰dqÉó“cù=$És’ö¯9ØÒ>›ŠôWÑðE%ÉG‘opmM­‚Mh£~mo¨ÜßBw®™Ó;,Ëðhó¬6/‰GŸ€K)L$‰k´ó¥Xgÿ<®~Gƒ«=Êµc/I_½,%J07ý€eMñFUºdÆÞzÁ´$íü¦Æõ-íÉ‘øýóÈzô±³gÎ†……=š™IÆ?üKà2	-ª±Þê»ÀÎ#®ÁÀÒU|¼!Í*Ëêîph·ºùïöMtÖëf„bioÂáµ•*uaž:˜`ˆ¥r)q÷öðð·s‰“WœP.—‹½N§—ÿî¿÷¡K¯˜RÊY§¹ÂN»íÜÖ[,Ò>ë¶]u¿B~‘nki…[‘gTŽf|ŠX©+«ì}œeÚ§ª1äæ?`a„
íÀ#b*MG8žqTÕÍ ="BTeèø£×Yc.ªq0$-°Þÿî†¦N–	¾­ÿ™åÌ¨ê9NcÚW6 n©Ð˜=DÅÿö:ìîÐ¡CŠ4qÛm!=-Är•\!ócÁŽ0‰š|µÄaýåû;ß
ù
@vÃ£o'hî8O!>KzvåŠþïÿö®ù»ãïüÿOŸ>}Ãï^¼þúë+v¾Þ»8Ìé/®Fí»k]QéK(*|vlõ†õ‰+’B›…Á"£šðÆ½û;Ò“¦qÍ[»·ˆbS4ëêéöD	ZW;®­nc±¤®Xrð«øð(*¼“v·“XþövWK‡(::˜pp®½Å¦Ù\ãâ„à}#ˆ¤bDµ-´/ÕßTõÿºÀží-m²jwçjj!ñsûz¼¨$iÝ›IÝÛ|Í›–¯ë˜[ªGRwÉ‰¢ce]nöpq¢ÀçÐÕv¨Cè]#ˆœ‘¢¡Þ¯suø«Œ?¾eEF1M,ÀeÚ%ãû²‰ªè°?üÊ¾GÑƒƒõÚŠr‹œJ£)¯§ñí/!Üþ’£J*øu<ã¬È×W	³Jú†Kå*¥Ôëè™V”q{>å{krüSÃÞ›ouZóïÎ
›nv8+²ãLK¤ª•”®±:ûÚÛ,m­r
•iJ©XUø^ï§¿[¨’+ö}¼oDé†?”É5Ø¥i!uL/ŠRgÆšðïŽÍá½@sÞm5äWµ…êáuJ…åå…C§!b!•Qv°ç]i˜nãru–./KÁ8íƒr¸¥.ëœÊ±Ã|þ”œ¸#›höúÉS¯ï_ecRØÔÈó—}ïÀÐt£×¡C‡Þ;ðžòŽ;B	 Î×¼ÇR×AèFÚ'‰Ÿ;‡jµl«o;w?(RÐ²}C-Ý/k÷q(Íþt£~óê-t¬våãq=ßÂ‹¨¤d]ýÆ¯­¡zWK„&PVÃç²¯ÙÑ1sÉ²”¾žB¢FÒ´ýë¡vÿÎõ²{b$þòº®È¸˜ÑäžæF..®'ßô¢p9"Sâ:öYv7´ù|tÝ¾AÜL*"*VÖQ·£¶ÙÿÛÄw¤©ÁNQ#«2z¤Î²íý®øä8„Rß†aÌ[¶l.ÛâóùÈø‡ —Ih—Œ¥ÑùkpŒ¸Ë7½sò­R]™1-¤)+æ¿Ì—æTXlêB[c®ò*´`€qXòsK½*cY^hÛ[¬ÈÒR9æÒª@#tXÌv¡¦D)•KËšÒú_nÈib‰*/O™™Ÿ«—³bMÍœ"ZQhTb8É@üÙ¹ú*V]Rž§UyÑsxífÿ¡6–©¥EE¥*å¹/©K×rea¹ö¿˜á7çbìE½³Ó>˜ÑV¬)9g'/Í(òÊ¾Í¬ÓœßOø?µì*ia¥)ƒ"Ã"¤4YJ³¾´¨B¬SËù»¦´†ªÈh¯¶ŽêoÁ”ïDÝÂºÏÏŽþý'ïüñ¤¡9ujÈ1Ÿ]]§Bó>~‘ À |ÍÕëVÕÍŠüøú’Uy-ºU‹æÏ­_¹Å´[¶bv ê×ÑÑÑîé¨ÝÖØÚÐØìñµXêbñê©[µ|}}gXÜÅ±ha\Ý|ÍÛWj]„ÐëÔ­¬’?²±t~lÊ’ù­«Ùw…É’–góùFûAósëë<|ö¿f^plHXÌÓ›ŠS£¨ÔgWvXVg¯÷Hæ,\ª‰ðç=è‰Hè½½|¥O-¯–ËhZœòJX\îÖõ)‚ë¯¶uÖšæýÔ¡^µyE‚¯©£æDv×)ê¢È:.Iâ¢Õd×6Ëâm­„JÖ.Óú;j<”»ªk‡Åøèöv~‡¸tÝŠ9ñ"ÂÑ»–<eém»üáZ=ÿ¥™¡‘TH‘Ñðè™©™ètóÍA‘Q ¸X{Oþ_ÛÀvÚÇžÑ×àp×ò+üÝ&ŠÒãºÛÂB•q_y¥Ì+)drsï½%b•ÎTâï®ÁØKóV7!U¹÷Våw—gUî3ªÄò´B#k6çÞ›ë$ŠŒÂ’¼‘Ö¹®$¥Eæì8½×ßBVe–d]dwÖm«(5[ªì^ÿ¤¨…ƒ–¿`YÖiÉŒ³z±úÜý½v‹>¿È*L3••ðgÌRg°D8èóô:­UæR‹S®3Uj/ðŽ@ÏpY•g²±\C×è³fna7MÎ©W:‚çü8+,ù³ôn±*##MÉ\ <ìñ|ÞòùŽ:zÁ«Š;#Ù¿¯nØZÔÑ²§ãøoœ2äAi4üÏyÎzþ­ ã	ç©_Ÿ¼¾oEÓc¶ÞÇáw%öì6‚"£>×Á½–Í•-’ÔÆ	·Š[¿|ÃºíkÖ.YæZ·jùâÆ¹‹æ§D7oÞXçÄPäþYKæFuìXóšÍÅå.KZó&þŠŸ(v¾åíA›7Q-ÝöÐÒ’ËÊßY6Ø®Ilê’âÔ%}kü³¼ŠfGô^&>¯ü¼s¼gEåþÖµlêê›zeè‹ö¾€Å›^#0VDÓç¯Iì‡œóqóÔœÍoÏò4’DýÎwô®(2
 ko{þïùGÚƒCžQÖ”1ø&!•fÜ—f]%V¾{¸pˆSI•|"‘1ÔÖáSi…eiyKn®…Ugei›mDHi+ßÓë|^›!;ÛâRj]YyÞPCS„b…2CUÖo†Õ –®2”Ò!(üÍX,56·4­ ¼@Û=|C(t^×
}®¡†Qd”W1oJèµÿt1ìPŸ­×š;+§ÆëÅôÕàð“êpˆÏ;»ß–”¡Küç<xŽÞ@B¬,¨nê9FžU~°79*´Õ{?¡²`ßÁÞsˆiºþ§g¹ßEÏuòÈÛ'¦­ûavÞSý=6&ßùÛ{IÎòÒ/»ñöë	 d‰KW:Y@ÛþÕ«ëƒû„EÄ$ërÍlÆ	]m®ÙÀµ5Õ$š5/¥Çë""ïÏ]ÁÙ:"D’iÚU%Q›ÍµõtRÂýK·ÞãŸá³û°iÚâuýšÁF ÆçjjÄi$£èfáiièk¢Q-`}ËŠŒN8É& W«öã¾§^š±ôçnîÖŽaî¼3'â¾ÿ¸‚û[ú33“Q¨=oýÿ Ž|}…Ã?D‚`ªK4ƒu~`æÜü
z@& VhKJ´Ý‚¡TzÉzF0ÎªÒ*F•¡¦.üé{6Z¨TQ—é>éÆÒ–ì\§®¼dˆ±=Œ]ŸY*/)×òÖiÉÎ,uË©A{p”U9îæÒë:ÞF&G†(¹ÑuìÄ‰I“§L"  pµáÚì¨å’çÝ?ò$¾æ½¯µP§Ç"á ¸:}q”‘LÙ/ pU»œG×i’õrÇC.âöŸ1‚WµQ     ®V£80Dà2	›H*FüÃÕu²ë|ÃO&&ÄËÃF1‰
     ÀÕÀåsÍrç´0      —:Á      À¸‡€       Æ=       0î!à       €q       Œ{8       `ÜCÀ       ã       ÷p       À¸‡€       Æ=       0î!à       €q       Œ{8       `ÜCÀ       ãÞµ F«ƒ9A      `0âÉä2BÀ0z—ù¯+      CT       `ÜCÀ   ßLÀ   IDAT    ã       ÷p       À¸‡€       Æ=       0î!à       €q       Œ{8       `ÜCÀ       ã       ÷p       À¸‡€       Æ=       0î!à       €qïZ —ËÙ³gþŒå¸ë¯™pÍüÇt      £…€à2a˜¯Ö®{áKïÑA·>p¿z^V&     €QAÀp™Ð­ŸšnLþÎwd‘²wÿlã#ã      Ôà ¸LºNuº~âµ×äçÝzËÍ|ÆñJù     pY0cÞ²esÙŸÏGÆ? ß¼IBaÁ²gøŒÃö×È8¾µGQúùV/ ò5ÿ¹¶þG.)îH­^»ÆÚF†¼è¦¼e•ô%¾*  ŒSÛ+vì®ÞýæîÝ•»v‘ñCT .—³C­÷ofEÅ/ò¿øDö\2rŒ³ªÈPTa4¡¥Ê¬cA†BLÆKWäÌÒ;T%Õe”°{¥×–Ÿž]åî^’*4º<]šÿðmûÌ{Zy¥N!$—Ë¿æ*6-/K96/ô\^{‘¾(ø±Bg,T:ógåÔtçBeau¥ö"^¥×šŸžOgño•ò¿U p¥á:è½µM³g&N“ØàÚ½|¹eè#£µæÒ9ÓC—#€ƒ?ìÈ«îùµMdÉ«_Ì¿GBà*Æi¨Þf²Ô{øû!nÎÂ¥'Fúï%ÎõgÓêâýî."KZj\–Å¯ôÑu¯mÙ\ÝÔAÂd‰s—>/	Þv>×;[Voðï¥ž¿,7=VÔsvz×ªáº©OýÞj«ÕÖ xdÃÆ9T÷íêk~§¶Öº¯Ž–-Ú¼65²ßMÜ~`ýºƒê"š¹¨Þkz~“ÍEYLRfî¢TJDàãÚ¶×¿Á¿÷$\ž²h™v†dÈÛ€kk¨Ý]kµ6t&7åÅõÿ8ÚëO­©ë.„É¢ãç.Z5¬ßZ0&L˜|pêô)2þ¡Àå2a¨õÝøŒãÙeÏ|:ÅgÿhüˆŒë®ÑçäWxU¦òÊò²BS•Ÿ£¯r³d°^‡ÍM©än«ÃÛïB©ºä½ó>®Î“ÛÙùUôž·&wÆ-ƒ™‘3ä+aœV«Ó{Ñ/“õÒN7sž­n&x©ª ¬º[¹Q#çc¡\xÕM|´Ñ—³°NKúm!¯!.Ýì «rú½À{ómztHUYyº¥éÀÕM²öÍýo¿ÃÿÔ¾¼(.:ùù×ß	.V¯M9ÛmÈ|Ã×XšýPrF^uKÓ¶§Ò’¬«lõ	$qó_ªÙÿvù‰8™<&
­Â«çª3onˆÊÝYûvÍ¶¹u&ÓŸ]¿úPíFss¼¾üÍ]kgº,%»›}|V±wËv×ÌUÕü=YšÎíYonhœ£ýÏ&ý6OâšòšÚrcjtxèé45‘¸X>¹à|>Ÿ@Þÿ>å:}]Iø`wo{K½G6CÞ1èEÛ[›ŽÈæ¬½fÕ†Ùá[·ì?‚.J—çilâîYº•¿7¶/÷Ôš¶ñoþ·ÿ	sD$ò7Š@¦éþW©OæêLÛx|S²}ìç?ýÙ/Îàñ=8 ¾a'Nt®{áw½‹]]]½ÿ?^{i‘•¤™ÊJÒ=*T*…T˜_ZjS—h¤äÒb½v«[ž¦SØJ­voZÆ`­q¾ÑŸ—g³åWÙÝiÔpŸ€TWV™Õ?¬`fƒ…¨•ÒA›üB9E	-6«M.÷ÚmV»ÃÉ(Œ•¦¿d–®ÊUä<ß.
]eež¼&ç½­ïÊ3L&C„<þ$ÝÏØíp³”RÎ?;EVy™Q=ø“aæÌ|÷9«ÅÊ’ À·w¤ÞJË’î,¢ðÑõu.YRÒÅ~õÜÕá#aƒ¶ù6ÅÌ§×®Lå¿(åèÊç6‡\»q¯Í—¸(i¾B½º	¢4k6%²i3Sv­¯§}Ex¬û=Ôœ¥÷ÈDD–>'fùvŸ¹ÖœŒÒ(µ&¦z{íK”æê]-²ÌÂy3dü¦øÙsBÎÎyZšHü\ÿõ’XÍ*vwkƒ5ôê²©ÚxŠ47žÓ}©½µ¡M–IÝ3ØE%qé‹ã‚;R‰ITå®¶Žàf¾ÄQ÷kuÁ‡‘qÉ	²zÿ.¨AoMOš3=NæiÚ~¡óŠ¨„Ä¨7ê<| "ÃgöÍ˜2eÊS‹“o —ËCTNŸ>óñÿ}rÎ¾Ch‚×QcgZ¦w¼0è´J›¹ÆáÕh¤ŒÝi`²´âš"‹ÝK„”&ÏhÔ©-o†®*ÒG¶ÈÕ:£1OÍ^kn¶Ež§•Ö”Õ8Y©JWRØ¼š•*tJµWXcsx3äòAŸ”P*“@·ˆaÇbJ©¢ú¿2[[¨.è{a$Ði£ªÆáöÒNÚív:Ý,±˜Y•B¡Ôh5:¹œÅhþåì;¬|›?‰È4“4B,g•œÕo£»*¿T¨ì½&Ë²Œ£¢Ô‘a*Õ þ“JÏuj+Ë³øWÌ8+ôùüûÏ±"-¯°PëÿÄº¦Ôÿyñ/;d-	|Ä¬®@i/5T8±2ËXR˜F¡'ÀÅGÛjë8Z­{ëFpp‡,:ÝâÒåéûB–Ââò·?N¸ö¦×Öèöò­®³­#¢gâ.®­a‹,eeúoÀ Ý¸N—«3">:8xIáûÐÕÎÅ‹BöìyÈµ·¶ød‰	ƒ6à<Í¹èÌÑ4b}®—ä®G$!Wêÿ°÷®†_lŠ#TÆT{K-Jœ}W¤áüŸÈqmûë;¢n|s†©Ø¹sÂ2÷±,‘hÜÿåAÀðmÀziš‘*ýºR¥”‚O6nÖßŸeœU†
mIùÁr¹·JŸcÈ/¢ªK4R¯ÕSêÍ(y·L%g¥¹†¢ª$ÃŸXxíæ\·Z[PVNìf½Å`QWªüv>ßp*K.¥”)²:¼iòAÆKóYŠübŠc°´Õb—f”¨ú]@(äc"åóŒ%eªòõnm‰)íRwT	âC†"Me•u—ùðÚù¥Žà`¡\I16§²  PuƒOt„NCúB¥¶œ¥ÚÊjR4£·BG(ŠßZ½ï|oã0ç—ºÕeMû”¬£¦Ê\iç?#UPÙ”¦`lE¹ú|ƒ´²$ÍÿÉ³¬³"7ŸÖ˜Êu|2RZTªV™4có® À•C0]»m¿¶g‰£+—?½%-Ý¤Oê_NÃG’¸ÇsÏéÁÁ¹ê÷µD¨çE¢}!|­õM21_'ïˆºoH"ø<ý†€ðíÝ.ê	ÿ'—ÇGÂÚ­ëßõ¾‡„G§,Ê_Ü6´·4ø¨”Ñƒò¹º"ÒûßÏ!,v—uˆ˜™û|2ò1ÂÚõ´î•V¾jÕŒõz|"8“ÇúÜÃ=ýwdIK§á3ûæl¯Ø±ç­·ø×N¼vÁüùdœCÀðmÀ2^–ÅÂþ_×zP°Ëv/©
Œiþ–¸4C§­É´ðÑ„š²V8¤Z“Vøª_™•!Ï®±¹Ó²Äz…åeþŽDÁZkôNÚËªÄB¾á`©<J*+Tkö'ç6£Y·Õ\de*¾í=Ê
,]Udv+3TRiºÂ´ž%¯RNìN7“&íÞ‹q»Y©Ü?¤Å_Ü4Ý|¾'Ò4Ó¾~ÑûÞGÖm+Í/u*òÊuªî³ûßuïî^{•2C£‘w?/me“vÀ4¦ƒ‡Ms9Í™¹´®rØC†z>UÿGÑ=n…¥kJkµ1ø–ˆ5y…G¶¹Â©.Œj‘*ÊÊtþMJ¡½*‡·ôrU_€K‰k­}îáÚMíï[ˆÖyœ§¹‘ÿ;™´¼ßÔž”4¬‚¡‚¨¤GfZM›­1«R)„ÀÑÖ6.nI‡ÑçßÕ×¸gûÁ°Ä5	‘þã<M6Ï¢»–F¸öo.¶lœ]<Û_µ57tP)Ô¨òzZ§‰qÑ I¢~ç;Ë<»×¯Ë7t•¬MŸŽ{ùÒLŸcÞ?Çwhÿæç×-ï\Zº,±÷wÌ9ŸÈÎ$Ó¬Ý´,Aä¸W·ùù-z#yaMòðŽ…Kí[VdÀ·PÌ7çY·’qðd¦;öèÎ8zÇ¯H)¹”u{Fè i»mÖm†¾ÃÃ·g‹…ÝûóQ‰;˜”óRèÀ –3þ DÓÝhg½¶ü{oËžHªÒ™JÉ¨ÆaÑÙ¤ºJÕy>
Ýöª*‹•¶;xN/+ÏªÜgä	±"«¤ZÍœçòB©"4Ý +r²-bÉ˜å¯êµåäT¬²’â¡¡=8ÄŠ±èósûSžQVÝ›b0ö¢\‹´À¤U‘’òy•]”~¯5« @›¦¢ø¸Êíp*­w@ŒX®¢¤UNšaƒ0äÜ Æ'‘Ñâ<ÿ¿þ	çª^±ŽÎ\›ü¾Ô×°1ÿ¡ãÚl.QÂã)ážuµutâìþçyÃ“iº—¢{¾§Hæéf.Û°§!qi"&QcÕRòš'îé¥	þûA."¾6_pçk÷q"IïÕ^¿¹¸–KZ17Þ·
Âd"Y\òüäX~Iòœ¤ýk¶´Ï¦"ýU4|QIòQä\[S« AÚÃ¨ÿEûdñ³¦xn¯•Nž‹.cD4=Y;¿!oCmC[¢&˜Iõ‰ƒ`Z’v~Sã†ú–öäHüþùFd=úØÙ3gÃÂÂÍÌ$ã€o>° Ä^‡ÃÍªúf(e½N‡WLÆ­ÙÐ
…ruIeÙÀB¡Þ¡ð:­v·×­à6}ï*«Ó«	VÒôÏ¢R]–qñs€0ÎŠü\³[.eÌúReya¿ƒqÖTTùŠ:4áÈåB¯Û]!V+)u†F«PªT=	€P®PÊ‡U!•Ub¤ssÓÓí……ºT_A2Leýª„öïÁÁã£Œ\«²ÄèÕ¬ÓœëPõÅ!,ã¦½¤çé°þYTªz¶)öÞ\±RWy0Í^e1[²g™5Æòða
	Šl @¯öÆÝµ®¨ô%>;¶zÃŽúÄI¡ÍÂ`‘QMxãÞý	éIÓ¸æ­Ý[D±)šˆuõt{¢-Œ«×V·±ØFRW,¹?X!<Š
ï¤Ýí$–oÌ¶»Z:DÑÑÁ„ƒsí-65Èæ'ïA$#ªm¡}©þ¦ª¿$ƒ€öloih“%P£¸»8WS‰ŸÛW¡áœ‹vOÔýØ_D¤‹i­v†·Ùÿ.s]áüŸð ŸŒ+ß²"£˜&á¼ó¿   IDATà[AªLS‰éš*GßD§Œ³ªÊ)ä×ÒÿqÛ|öAI¥r•‚Ð6‡w¸ò:k¬ªp_`*X^SµNÁ8jœÃ>Á°.b7çd¹5¦êê}å:qMNŽ¡ÿlª|lÀÈ5:cõAþy|ü^e¡†Rd–”ê´u1ƒ2øüÂT¦•ÚôÙ¹|ºQV^¨éŸÖ°tEfïä¯·¥[œ¬T©–;-þÚ­þ$Œ*+äÆisº½ÞîEHe”<Ü£ú¼éF7¡\•UXÎ'PB[…•&rEü^Ïéi»“‘*(1b  ¾æ=–ºÎB7Ò>IüÜ9T«e[}Û¹û	D‘‚–íji_¿£9L¬	þt£~óê-t¬våãq=ßÅ‹¨¤d]ýÆ¯­¡zWK„&PäÂç²¯ÙÑ1sÉ²”¾žB¢FÒ´ýë¡vÿÎõ²{b$þòº®È¸˜ÑäžæF..®'ßä¢\Û;ët‹Ölûss›ÏÓlÝQÝL¢ãeè¾q‰ùšÌK/+­=x¤½ýHý®ÊúöÈJ$ü6	îHeÛû]ñÉqHG¾)Ã˜·lÙ\¶Åçó‘ñ=8 ¾¤ª¼uf~n›§Óªå^›¥´´Ê«6–ôõ>`Ü6;F)„´µÔPáVèJb"VgiÌ9Ez³Ø¨UK½ö«W‘‘¦*"`œVCe©z'çSµ¼Âj¥õ¥©fÉÒþ
¨Vq–©<ÐsBª-1ÒÙ¹ùùTyYVw àŸ!$t&U1%zý9¨Kò¤ª‚²2’S%–K…ƒ$b•®ÚT :ù7.°¿&/ÏÊ¿‡zcgÒŒFuß»çuZajªì…*2ôk®ÈÎ,"ÚÊr]È@ÖY‘o°+µZ5ÿ‘ÙiF,•ŠÅTš.­Âÿy	4”Ý
¯*8ž†! p5ó5W¯[UK4+òãëKVåµèV-š?·~åÓnÙŠÙ¾ú\GGG»§£v[ckCc³Ç×b©‹yÄ¨§nÕòõõaqOÇ¢…quó5o_i¨uB¯_P·>°JþÈÆÒù±)Kæ·®6f?Ü&KZbœÍçíÍÏ­¯óâ^3/8eOXÌÓ›ŠS£¨ÔgWvXVg¯÷Hæ,\ª‰âÁÍ=	½·—¯±ô©åµžÀã¦Å)¯„Åån]Ÿ"8°nñj[G`­iÞOMêU›W$øš8jNdwÒÁ/šôHzÃzËóÏÆn…ÇÍ_›¿ŒÀ¥%ŠIÏLX¿Áô\­¿°XXTrþê¹±¢öƒÅƒ}"®zÉS–îâ-Ë®%Ñó_Ú€„ž™ºÐ˜‰áqß€+‘Pžf,c‹ôEúì
ÿ²”Ê
2B¦	òíÜzšÌk*Ö•«òLF¢/Ê¹·ˆõwÈÈ+ú"ŒÓÆçê³ö$NfÀü®#Ç§'æR³]˜f¬,èY„rMa‰6;ÓkWQÄR¥œ˜í—°¢¦ÿ])aròsâ2cÆçZ•«²Ò„™EUbMI‰²ï²n[…ÿÕ°|Üd-É ##¥Tòsnº!8lIaš´MÏç5«(Ðq§ÀT˜&Gÿ€ñƒóÔ¯ÏN^ß·¢é1[ïãð»{vA‘QŸëà^ËæÊIê
ã‚	‰[Å­_¾aÝö5k—,s­[µ|qãÜEóS¢›7o¬sb(rÿ¬%ó
£:v¬yÍæŠâÛ-²¤5oj€¨Ò|ËÛƒ6o¢Zºí¡¥!+$3–•¿³l°]’ØÔ%Å©KúÖøgy%ÌŽè½L|^ù;yçxÏŠÊý+¬k;ØÔÕ7õÊMÕoMÕK‚È{ß³¨ÿÊ!?‘9›ßž3Ô™‚añy]9Pd ®PbE†±:Ã8Ôf!ÅG •ç6ÖÅTZaeÚ€XCª1½×÷ï]±²`ßÁÀ£Âî?I¿mM‡ê’w’AŸYïñçÁº566ÍT­Uh±‹•y%Fo‘ƒPEµo9+Ðn†(/Ù”!þ\ÅT“iÐ[åýÇ’0ÎªüL»˜0n¯8Ãí°9¬U¢1Ux+Js²íYYµR.öÚKÍyV¹&C.¥³sfe²¬Xå®20†RY•³‚û
l¥ª¬’Ê¬’ÏlÐÏËŸÉCÞ}ÿ„.‡œf ¾9YâÒUƒNÐ¶õêúà>a1ÉºÜE³›qÂGW›k#B6pmMµ‰fÍKéñÁº‚ÈûsWp¶Ž‘dšvUIÔfsm=”pÿÒ­÷øgøì>lš¶xG¿f°€±Å¹šZqÉ(0xZ:äšhŒ7Cß²"£Nr§	ÀÕªý¸ïÆ©—iÍþù¿ëK?ÌóµäŠXré0v}z.­­,Ï¢¾ÉoûYw>·Ôîí_óT(U˜Œš½lÚÉÊÒÐ©iÜÖR“ÑÝ7Æ?GŠÙ«Ñåe¨‚¡ëuT•–Ö¬’¹-7ß¦1™‚o9ëuÚjjl»Íêp÷½~yFyuIh·”À'åÖ]’J­   ß8®íÀŽZ.yÞýQ#N8|Í{_k¡OÇ„( W©/Ž2’)#û€€®j—3à8}útÉ‹¦æ–/¸gÜíÿ//wñ5×\ÊÀWHÀq•aY"ÉÛÍxÝ^ÿì1VEYµ–[    €«Ö(Q¸L&Nœ¸|éÓ­Ÿîê:ßÜeBà–[nîãÙˆÒßüô|‡XQ S"Ý     !ôà€«ÚåìÁ      Ã4Š—²<       À7       Œ{8       `ÜCÀ       ã       ÷p       À¸‡€       Æ=       0î!à       €q       Œ{8       `ÜCÀ       ã       ÷p       À¸‡€       Æ½k	 ŒVs‚      À`"Ä“Ée„€`ô.ó_W      
†¨       À¸‡€       Æ=       0î!à       €q       Œ{8       `ÜCÀ       ã       ÷p       À¸‡€       Æ=       0î!à       €q       Œ{8       `ÜCÀ       ãÞµ .›3gN}Òröä××_sMØíJ      £…€à29{¬½#wþé/>tëw~š!Z²œ      À¨`ˆ
ÀeÒåüçPéûûß:_ÝB      `Tp \&g»¸ól½æ»S	*ßÆ     ÀeÁ0ŒyË–Íe[|>ÿp \)Â~¦¸ý«ß=OàJÃxÝ^v¨¬Ó’=+ßê.0^~O–®Òç[Ì9»Ò¹9E6oÏ¢»Æ0Ønþ-VC¾Ùî%Ü3pÒœLƒ½ß6¯½(¿¨ïðK„qXrÒgÍJÏ¯¡YraÜNÝóùßºÌ¸[n¹å^½íR¿ —š¯ùÏµõG8rIqGjõÚ5Ö62äE7å-«¤/ñU `œÚ^±cwõî7wï®Üµ‹Œ8 .—³çßêß,|ð'„ÂQe^[þ½·ôš1+§¨Ælò2Ž¢Y3ÒÍÎ¾&)K[2ãèn}à„±[ô†
Ç7ô¼‡9;=×âd‡³kiNn©ÃËxi'}ÎÓeœUü‹KYGE‘Å?ð-s§“aý‰Íb0[Ý½—`½´ÃÒ>ìév¬Àú¯ï½)„ÿö¹7»ÊíHÛªÂÊò©Ýáí¹ýa„Ó{IóþóÉÍ1t¿y^[‘¡ŠÕU7}ün¡JzÁcÛü½å4øfpôÞÚ:×¹_˜q®Ýy%ÿxèŸÅ»' à8"È0ðaÇ‚Óf—h'puãŽ4ìZ•¼–m­oë¾Ñ8×Ÿ×ÏŸå¿O7îwWúèºMË3ý{¦=¾jWc{ï=és½Ó½sªv]usÈÎÑ»V¬ÙË‡z>W}åÆÕÚ‡Sí
	Û|Íïì*Yšúóå{ÛÞáíÖ/+mhò¢Á'¿_ÿóÏ6Öã>¾ôøÏnÑ€_D¶…|¸íÖ=žœ¶ìO÷²¯i×ÒGý»ý<o[ã€¤½ÞøhïyRç.Öoªw!sýM˜0!øàÔéSdüCÀp¹LÖ^"]Þé¶qö¿’JÕ%ï}|˜÷qužÜnÈÎ¯ÞíCè­É™qËPâ2Íý"ÆY”Þ?I¹TX·£Æê`„ÂÁ7{­¹C?Ë[n›5 wÃH¹m+«Öf(º/Ï¸iwï	ù$XÖmµTØù\ÃIJ%}þ©´…\.gkJËÐ†Ì{ãÈ6;œî‘5üY§y–ÿ> ·Ú-Nq<xÑ÷æZÖÜ`rõ€¾÷õ{krï½·§³Éð‰XèÇ?Sjµ»Bo¨"j•\HF…áOc¨qU–q{©RI‰»Ÿôù¤7b}Û×E†¯Íã1½ôûßozéèÑ£ç.\2‚è”µoîûþ§öåEqÑÉÏ¿þNp±zm
%:ß¡Cæ¾ÆR¾áš‘WÝÒ´í©´äë*[}IÜü—jö¿]c~"N&‰¸šq®:óæ†¨Üµo×l›Qg2ýÙßðäÕn47ÇëËßÜµv¦ËR²›o×rôÞ-Û]3WUó÷di:·g½¹!ØŠmÿ³I¿Í“¸¦¼¦¶Ü˜zú#MM$.6R@8ŸÏ'…÷¿O¹N_W§@>ØÝÛÞRï‘ÍwqÑÀážºm»hAø°Â=)5góÛÝ¿…öW•Î‰‰ÖÌM‰íùÑ^¿c{Þû¤½Þ¼±ZðÈ¦êªM"lÅ[êfN™¦ûW\¥>™«3m;à!ðMÉzô±Ÿÿôg¿x8ƒ@Æ?Ì¢pE8Eÿß±_->>{¢ó,wQA¶P®ÉË³Ùò«ìî4êÂßbq %$RMÉ{‡k­óßëgæØI¿&¤P(åÛ”âQ¶|ÏƒÏ7ì^¾Ñ:t£Z(¦²JªjñÀ#éŠìL¹^›Å\CÓ$gFUÈõ”••:¥¿@M‘Ùí‹YG…Å*WÛköŒŠÀ^ö¸àŸÊ‚}•Y^Ã¬ì
7&Ògä{½KÄæ…‚oñ{IF^yIÚð3þýpÿv(tûëã^ô´¶¬P|ùŒÓfgj¥”ðû±ßÖï;Tìÿ€.ÐðçF,,¤+Ò
*ÚÍÊG›otç%brIx2üëïùµu|Pç£{¾X4eÚ$2<M¦ƒùÇŽ{n¥~À"¸ îH½•–%Ý?XDá£ëë\²¤$ê"C†®Û™¹   IDAT	¼‘'Í|zíÊÔ(¾}IW>·9äÚ{m¾ÄEIÓÐ6¼º	¢4k6%²i3Sv­¯§}Ex¬û=Ôœ¥÷ÈDD–>'fùvŸ¹ÖœŒÒ(µ&¦z{íK”æê]-²ÌÂy3dü¦øÙsBÎÎyZšHü\þþ#’XÍ*vwkƒ5ôê²©ÚxŠ47¶|bí­m²ÄØHêžÁ.êÿ+Ãµ°ìòPÉ3\6ôcÜ¡ý–ºð”ç#»W´|mG£,91ÌÖR´}hm¨õÉ”HD’QïýíþƒžÄ‡dƒþ~Q	‰QoÔy|‘áÐ7cÊ”)O-^L¾-p \.ç¢r¶³³«É²|þ-&”ÊÅÄéïi0’€£ß¥Áó
Öëtž?r¸”¼N-Vé#o³Í)%õÓt[‹V¹ñÝê,ªû¬»*7½TØÝÄg½n"–‹½B©:ƒªª0W°jÓ{¦4©ÓœïÖU†F.Æ÷zišáõ‡Œ£Âlåßf¹:¯°@ø¼«S¨RŸ÷u²ü3ÊÍ¯b3ÊÊù@Ã3Õ°|’A³t¦a¨ÂÊÊj0ØÓÊÔJ±×Q¡¯Ë
”#yçþ¹gV¸û¯ÌqKnï‚BW]9²sžcXc7d˜,­¸&0 GHiòŒF]pÜ	ë¶•êóÍ6/‘*Ó4â`‡ÖiÎœUÄÿ5²§ßV¡,¨®Ô)Î{¡ãÿø¼å3rª­­å05-f"–¯O~ÝýôæÜE€ñÑ¶Úº„„ÁŽVëÞúÄ„Ü!‹N÷Æ€¿µËÓ÷…,…Ååo-~(œpíM¯­ÑíåÛ‡\g[GDfÏÚö·ÈRVÆ ÿÒý‡ët¹:#â£%%ITL„ïCW;/
Ù³ç!×ÞÚâ“%&DÒRå<Í¹èÌÑ4b}®—ä®G$!Wêÿ´7ìØîŠ¿"¡qŽ1ÖÞX]Û¯MìÉC}oìh’ÍY9³cCwÀáó4»HT’,ðE$‹"õÍ.nð€ƒkkÜ_ß•˜€tã›Ãÿ¦bçÎ	ÈÜÇ²D¢qÿ ßNŒ—æ›„ò‘·B‡s ë¶[b¥Vq‰¿‰ßîçóÑ\,Ãˆ©Q,ÁàP”gPÂ¾•¶‡PQ¨6³½n¯RŠi>	RjµÎü"·Z9D¢Ä:-™éGïØ*«²<ËáèFÄÐ5f³T®R[åüå{b…®²PÌ‡55|ƒßæ–g”ì+Ðø–ˆ¥êÂÂ<Ö\äÍ*TÚôŒ³ª´J˜UxÆî8¹¥“nq„XÅG1Æ¾§í0gæ;´•ei#LÊ†Äòï¡Í¡JS]øîdù×c¨Ð–”,—{«ô9†ü"ªºD#eìE9¹Vy^åÁÊk3ëõnVÉï.äß¨w…Ù™UŠ²êBå zWÛñ#-'Oô,Ùìñ‡lÇ{›?¿ñçÝû‡‘°©wH¦^?Ôszæé¼ß¬ýMX˜àWyÏœ»ðML×nÛ¯íYâèÊåOo£EIK7é“$ývô$îñÜszpp®ú}-êy‘h_@_k}S§,•Ü|ü½#ê¾="‰HàóôÚ[êZ¸¨'ü#œ\	k·®{|×û²(Arwf×ÞÒà£RF3Êç:èŠHHï?‡\4Ðƒ€Ž›kœái$0¶Úšj	ú¸îÃ×¼kkSÄœUIÓ|µ=»p¾®.AxDÏ-Þåï ŠóXŸ{¸§ÿŽ,iiâ4ä«ßœí;ö¼õÿàÚ‰×.˜?ŸŒs8 ¾…ø¶±¹ÈÊ*
üÃüíg¯£hÖmEýö¡:pèÓ;­U±jø­fÖK»‰<306}`¨ÆÐäY•ûŒª¾Sûû5ea÷@>³p¸ÅÃì;âuØh!•ÕÓöX™¥·Ÿ§üƒPeÜWè¬Á8*ôù¥n•±¼Pr-¾ÁÍ¿tµI-.ºiVª’+TH)¥&C¥
&ƒâCÊÀH>5È6ð9’T¥–šsfÜÒ{ùÂêÞ,E(×˜zcÖiæ£'FZ`zW£Ék¯Íãô÷à°ÓbÆ+T°n¡*MÊ2,‘
Úé¥”B§ƒf”ú¬X7ÍGA¡‘Žsúï0+î[7’÷³ßYè}f^L©4Yº¼,õy>Kþ*0¤ù#i†N[“i±:¼…½ÂÊ¨
µþÞÒ´¼<»-ÿ|·T¯°ë¯9ºçŸ[7ø”ÐrmpünC÷ðbÂ‚¤óœcZTÔË[Ê†Z¸d¸ÖÚç®YÑôØþ¾…híÇyš}±)É¤åý¦ö¤	ÇQIÌ´š6[cV¥R9 €£­;l\Ü’$>£Ï¿«¯qÏöƒa‰k"ý)ÇyšlžE+v-píß\lÙ8-ºx¶ÿ¾jknè R¨Qåõ´ N!ê¢oìh‰™÷|¢„O^Œ­vúÃVALOPå£÷¾Ò™¾ê~™€9Áè`¿Uü586-KùîÕm~~‹ÞH^X“Ià›ð-+2Š€àr	)2:ñæ[¯™2ð+â3ÇþìÓž‡W’´Ö?!ÊmùÁ©Jg*	4-ýíO©² ¼¯¿>K[²Ó+.xàP¼öª*šÊ(f¾ÁòÍÙ\­.+÷k+u¦Ê´ó•˜ËûõÕð:ù|CQ èî?ÀÐUEz¯¶¼$­¯YÌ?å“:· ‡¿³…Ò¨’w¯à#ƒÂJŠ9_ƒ\Ju=aY¢Ð•3ú½H–¶šk¼ª<mOþ"¦4Z9%¶UÒt:2þŠ˜ò¼ê&ípÞJ¡\•¥KÐ?d`y¡Qí.Ê)­¡Ê´rÿ EI™‰ºPäŸ†$·BXPiêyWóÙÖ(Må:*t¯œ\›ÚTÖ“làýì¿Rm,3*ÝÖªª*sö½E”*-+//k¨L­·œ†ÿdRÖíe¼n»“•k£èR2éº»Kï›¦úÈüK—ëÜç}ó•ÊÕPÕ8Îž=ûáßÿþÉ'ŸÐ­­_}õUlLLtttâÌ™Ÿ}öYÙÖ­½‹×^‹ÿ°Â¥à/2Zœ—¨-àª^±ŽÎ\›?#Wø6æ¿1Ôa\[ƒÍ%Jx<%Ü³®¶ŽNœÝ?°à<ïox2­;Ï#Ñ=ßÓ	$	ót3—mØÓ¸4qX™|Ëù-%¯yâž^šà¿Dá"âkën¾r¾v'’ôÞXíõ›‹k¹¤sãýw« L&’Å%ÏOŽå÷$ÏIÚ¿æ`Kûl*Ò_EÃ•$E¾Áµ5µ
4¡=Œú]´½¡rÝ¹fNï°,Ã£Í³^Ø¼$}.=Ÿ§™&²ôž|£¥zwSkGSvÝúîí-Ù³ëŸÝ0;<Œëèè™‚§££“ˆDC…§‚iIÚùMê[Ú“#ñûç‘õècgÏœ{43“ŒøwÀåRU#üñ'…êlgëþÄ¬]Ù³ó(jpø'C©.Ëy]ŒÈØ-¥5¬Ê˜¦V
»9?·”Vö¶ŠÅreoâ0œ8j|¾ÑÛ®—ª´ZE¶ÙlWÕ½\1¥<·?
ã¨¨ðw¶‰„R…rx­b¡”O¬cÝÖR³ƒÒ–§…ô²P©ë´õìÑS"¨»È¨¢ ºZ0áÏz2b¡?Ùpÿ”N§[<¹½¦†–gd©FÔlïßƒƒ¡
‰P¬HS²EUv•ÚJËÓ´úTù“Þ*Ì2•õfFþÒ©ZY`TC:£ˆ•YyiÖü¢
UÏ‡9ü÷s þH¶ÿñO-Sdv¸Ù¬Í’‹™„%ìÆÌ;Ÿ½#¢üçMû¸oíwÔß_R©ˆò»#>òzvåŠþïÿö®ù»ãïüÿOŸ>}Ãï^¼þúë+v¾Þ»8Ì	:.½öÆÝµ®¨ô%>;¶zÃŽúÄI¡ÍÂ`‘QMxãÞý	éIÓ¸æ­Ý[D±)šˆuõt{¢-Œ«×V·±ØFRW,¹?X!<Š
ï¤Ýí$–ÿ%ÙîjéEGÎµ·ØÔ ›k\œ¼o‘TŒ¨¶…ö¥ú›ªþ_†‚à÷÷í-m²jwçjj!ñsû*4¼¨$iÝ›IÝÛ|Í›–¯ë˜[ªGR7F:=tG˜LÖ‰òßx»û2Ž®^jhH}ÁøŒ´‘(òF³Ç—$uäHG‰+Ø·¬È(¦‰¸\BúdœþìÓ®Æüœú¬5dçQôà¸XwAÏ7}µyšÆ!m-ÊNÏ6³ieÕ%Y£,×áuXDÙ¯ß‚\­Õ­¥Çùj:²t•>ßâVjµªKT:‚qXò‹œÊBcÖÐÉŽP¡«>˜nw_J‘UÞXØ§ë)!¤ÒŒ•Õ~%ZE÷PþmªÐg?0c–ÁF¤Ò‘½KBeAõÁ÷ªM:•’?ñ»ßã3
ÿ\'YYR[~ŽÞAe¨Ï×{ƒõÚ+òÓÓóTAeyAo^Ä8+¥^µ±@3ð­«´:•×RTué¦ÊÕ:S¥)ƒºp\Æ¸íÁŠ*b)%'n}E='G^7e@GëESÏ×3ö½BÓ^‡zïÀ{Ê;î]$ ß_óK]g¡iŸ$~îªÕ²­¾íÜý¢HAËöµt¿å>¥ÁŸnÔo^½…ŽÕ®|<®§„ˆJJ–ÑÕoðøÚªwµDhe5|.kñš3—,Kéë)$ŠÑh$MÛ_±j÷ï\ß)»'FâÉÐàŠŒ‹M¾áinäââzšÇƒ^.Îçññ1Ö…æâŒÓÄsï¿¶Ÿöùèº6_LòŒ¨¡áŽÔY¶½ßŸ‡Pê›Â0ŒyË–Íe[|>ÿÐƒàr	é“ÑùÚËÚùbgQþÞù¥Nª Ü¤Uœ·Ýì¨1›ÍV·\“W¶O«ý\+þ|ƒ‘j{…ÙéfXÆMÓ´Ûéô7mÝfk–ið^'þÊ”¹úaš©p8çá<ÿK¯ Y¦Â´‹™8æœQ*n&+¯¼@9’‚°Œ³ÆêV¨HizN7¸ÊçŸ—OXª”b¹BÊÔÐÊ¡ÎÉ2´­Ê\j®âÃ£¬Âê‚Œø‰Ž¥lVIÞ ÉTÍGFíf—æÈ]“›©§5e•Ý“Þ2n›N£BÚZj¨p+t%|2$Ôd)ÍúÒ¢
±N-ç“§Ò:PdtøN´|þQ Ýµ·‰§cÚ<äkûç-‡§Ý{ËPGœ:5ä¨Ô®®SBI~‘ ||ÍÕëVÕÍŠüøú’Uy-ºU‹æÏ­_¹Å´[¶bvl`¨KGGG»§£v[ckC#ÿÝj‹¥.æÿ¡žºUË××w†Å=Q‹ÆÕÍ×¼}¥¡ÖE½~A÷ ù#KçÇ¦,™ßºÚ˜ýpW˜,i‰q6Ÿo´4?·¾ÎÃÿâ^3/86$,æéMÅ©QTê³+;,«³×{$	s.Õð-[_óAODBïíåk,}jym°\FÓâ”WÂâr·®OX·xµ­#°Ö4ï§¦õªÍ+|MÁá÷±   IDAT5'²»NéPEÖq™p\{']¸Ÿ¢$qþ—qÍâô-$<î‘5Ï©Rd4<zfêBc&:Ý|sPd .–ð¾&Þ|kðñÙ“_ý‡Š{\I=8Úa·U˜ùæ°Wª.,3Y1‚eY¯Ý0+NOäê]Ù»ijj´Ó¬°tEÎ,½-ØQÀœo÷WæK…üw÷ªŒŒ<…Ø]a0WÙÜiŠ…ðÙJUi‘¹ÊNT•%Z•ô¢á¬Û^e)5×°êÂòÂ´‹lÔû{p„%Z]Zðeìæ"‡²@×Wa•e¼N§ÍnuŠwCžmµÔU™éàaS ^†žÖ–õDsn~´°DYcÎ/›
B;Û°,ŸäÜ[ÁŠi…Õeý*–v?Ç,Ó¾¡¿òC;ÝB)Û¯£„P‘eªÉp’‹$$LMî=Íf‰5'Ó‘§•”“
KEþ,½[ÌßiJfXEF{t}±çh¹&ja¼®tÚÔcë"GÕ[íØOÜ}Ëä°ÁyH£áÎsÎóoèÃyê×g'¯ï[Ñô˜­÷qø]‰=» È¨Ïup¯ese‹$u…qA‚„Ä­âÖ/ß°nûšµK–¹Ö­Z¾¸qî¢ù)ÑÍ›7Ö¹1¹Ö’y…Q;Ö¼fsEù'qLZó&n`ðÅÎ·¼=hó&ê¡¥ÛZ²B2cYù;ËÛU ‰M]Rœº¤o–WQÂìˆÞËÄç•¿“wÎ÷¬¨Ü¿bÀº¶ƒM]}S¯}ÑÞ°xÓkÆŒ(VgÙ9ø&•n*Oï]”ÄÍYÿæœÁw•$êw¾£'p¥@‘Q ¸XBõ½58Îk$àq©ºäÝƒƒn+öõß"¤´•MÚØ‹±[*â´’jËóÌxAi´y^·T“–¦’_d²ÀG…•”—Šå”b~ŒØ^“owz³¨z^{Qv¦…Vd«3”£ÍVzù§‰Í×[bM^Iµö‚/‰qe§›!kºkpÂ•!Õ:\¦§ ë¥íNZÁÆí°[m4mÍ ÎkH•iZy†	LŽâïÖàöŠåçŽgáÓ˜Š"ƒÅ­,,óÏý¢‘åäÎÊÌ(é9ÄÊ,N.T¦¥©â¡GÚëz2NKN¦Â¡B'¿TÝ5øŒaYvÈ!.ò4Ó{i¡ÏÅŸ	Už,ñ®„ÿéY.èÝ_[ypÈé%ºüêï-Â¤ê>öóëüqF¤,eOUêøÃÛíÇ3'O% cK K\ºjÐÉÚö¯^]Ü',"&Y—»hö`3NøèjsmDÈ®­©ö Ñ¬y)=>XAyî
ÎÖ!’LÓ®*‰Úl®­§“î_ºõÿŸÝ‡MÓ¯ãè×60¶8WS« N#E7OKC‡\j¡ cè[VdtÂIî4¸Zµ÷Ý8õ¢ÂÃÃ|ÿøŠ§ƒCgQ9{êÔ)çGvžRdÜu7¡ñd¡p`»—e¼ŒP*½d­q·Ýî¥TÊóŸÐ„”Òi…˜6—åóƒ[gÒ1†Y95¬2£°¤Pí.ÊÌ®è›Ê(«4Ê«²s*¼r¥Z“¦Q)”ÜE°ÎŠœl½ÍÛ»§¶²º»ËF_Æj0X)]aVï3ñÚÍ†*1¿Fq™nóK‰±ëÓsimeyÖ%sòäÑ“aS¯Ÿ8`õ‰¶$ròd  —×v`G-—<ïþ‘$ñ5ï}­…z<=	ÀÕé‹£ŒdÊÈ~ à€«Úå8È©SÇž]Òõ†î(øaâ”µëÉ5	ÀUlÌ    Fp`ˆ
Àåríµ×¿ðûS;Ïrç›‚bÂ¤ï\û±Wê,*      W(ôà€«ÚeíÁ      Ã3Š×       €q       Œ{8 àÿ³÷?`mÝw¾àÜ©T¥p£+:Wx{}27 ÝŒ¦øŽLú[‘›e®A½ÅÕ5´²ÃTÎ ÷‘Í\Ë¦¶²²ÑXq‹©líZ˜±Õ!¶&ØÕX™•ñÓˆ_6èÙ˜h.´¸3‚Ns¼©¥»E«ŽhÎˆ4ç4{ŽÄâ¯m\â÷«~éèü?Ç˜ï[ßïç      ly8       `ËCÀ       [       Øòp       À–‡€       ¶<       °å!à       €-       ly8       `ËCÀ       [       Øòp       À–÷ 5IH      @>EâÇ‰M„€`ã6ù¯+      ,CT       `ËCÀ       [       Øòp       À–‡€       ¶<       °å!à       €-       ly8       `ËCÀ       [       Øòp       À–‡€       ¶<       °å!à       €-ï1 6Ëo?%FãÓ²Ÿ.šþùmÛþxG      …€`“ü*ýÛ¯»R¿øçOò~ú§ÕÛö|‘      €AÀ°I~ú‹é¼é†øÛvHû«Á¹×È8      658 6	óñ§y§<¶ízkQe)ŸqýM      l
š¦Ý/vu_L§ÓÄÖ‡€àá+nûQk1—qüõßýÇ#‰ÌÍùµØÃÉ™Ù’ÑP˜ZtC0QwSƒ9´Üm’{<(Í$#>_ˆbˆõ¢©p8¾þÅ6F¢¿Ó{°ºô{}—oŒ¦¸WllàÂù›ï±ËÌ7z¾õ˜ÿnöÓôØ¥cG{)v…õNôŸ2v¦2¯YÊ¤íüÈgá—U  x€®x¯Þðßø›7z¯]#¶>QØ$Ÿ.7ýSþ“lÆñŸ»R\ÆÁ½=ûŸÅÄz1ñÇnws[‚“š6›E§”‰¸¯e·)˜Û–ézoÙ”ü®5l6»ƒ™6´T·Y,z•L¸ú¶è°¹¡ÉKè{ýåìž>ˆÑ¯ÕêôE’3‹µ·ëÔ¤˜oä{š¬áÜV®¼ý–ß fNDÐi¶zB™F°X®á7¦”¬¸dÈÔdJüÝÚÜâ·Òì•»rrfæ†æ€¤Ýß›ÝÞì¬VJÝ}Ë¥Înj•Cf’ñ¤¼­×¦Zx¡™¸·¥%œœ90†I†ìV¯ÆåÐ$&g$sr:¥˜xST’]™DÙnkŸ=>&êsº
…Vû<”X¥"%+¸ÝµÙtü‘Ða·5(SK¾¨Ø¥—¯|yh*’”(ÈE÷)“ŒFãB2w:C'±dý÷ó2˜¨×ÔÖt÷k¸Q§'?ÿâÕ<Ž¾ð;%5z­o¼üˆˆ{É&†Geõ?O]³]˜d	v*KNŠÜ¬Sña5
AqÍKÇ›ÊE™ÙÙ1áÐõøìÒï6öÏ¯Ê¤íãÿS¸ëÔ_žz¦˜HœÒÿÝ©|;UPÑzþtÃSmìÝaÿe—g0AÒŠÆ—Ž¼P]Âßlìm×©³ýñiBZsÄv´¶”›˜¦^»Øå$
¤ÕûŽn¨,ÎÞ?éØ›Oãg.(U8jl˜¹UùÜíÚÉ«…†ãõÅ‰Á›þ`04,Ø{î|#9sß¥ÇÞìëÞ ¤­]§ëKÜ©Ûg†TÇ_,Í¿ÑìÎ÷wêÛi¹d®.&à>cïö™[]£Ó3o«¿w©£¦˜Oi;Ï÷s?WŠÈ]‡Ì_îe¯HjÐöÝŽÉì›éŽÊê}­/V—âÇÏÃ²mÛ¶ì‹?ù˜Øúp l’mËMŸý™Âe>Cñ·ÿr’Ë8Ôÿ£°V¾†öÛ<:â1¶8iu{·E.LF¯Ý£èiWðK¡LÓíŸmx/\¤ÙUèmíjRºíö#Ý;³ÐŠ‹£„RAD‚QZ™Óø¿¿âki±ÇåZ[7ŸjÄ#>ŸÇ+WXTÙõ‹IýâèÇ5Ù[Œž¤Òà°©dpÛ­-&¢·[¿¾ó¹2¡¡‚!J/ŸY)C…ƒQF(^Ï!ó8æRŠ¹‰tœ¢ÚÙÍÈTíƒ±Ùh•õ8zü\ŠÒbü~þ ãcK@åriÉœ5ÄCn/%7Øäb‚"Ö„ÛEÌä)b…ZéµG”:U<gääò'Œ‹B¸K“Ôôø%4ÉÓè–8ü™\‹æ®Xœ”ÇÝ¡Á¡WÜ·Œcí>ùàÇwÞøq	©ü·O~a‹L$?òýˆû[Ù´·ñÉ'Ÿ\ô– ¸Ø÷B}1²ÁÀ·éØT,AHUÅ¯•7žq6™¦«¥'N7ldzpX=¢—lMä¢V€ \¹_Ï¿šè?eUwá[,å?îŠ½púPå|cƒ(®9õFÍ¢Ý‰½Ýå¸|‡4©Cº\cÕÝ5L_ï«Mô;N¸\¥¥'Ÿ/%Þë;ï«4÷œ+]9Ñé¸!ãîÃÄÍ‹Wb»Nú_-'(¿íåNw©3ÓˆM½í2_žTuôì¯$Æn$rWwt”¨ÐsÉEš#qaÝtîÇSéé)Aq¡ Ï?a©ñÁ„´Z6yó/òn4³xbàò5JPˆûøAaÓlñ®#Ž“ê’ùié»ãc‚Ú“×^­%‚¶—»Î]-s´Î†¯+\T}úÂÑ*ÿ£ÍßqÒu¹œ<õ¬”€‡B÷­oúÛO

¾ÕÔDl}8 ²>üí7/¤æÞ2Ó|‡f½ù))™®Û¦Ï´ø•*žášá+-õÙÝQ¹¡»»=(¤¤¥Ùmõjrz&äÅåF©ÓÓnoˆ¢•«´\7¸!:â¶:)…­w®	¯TiÄ*¸`ÁîM*-=.½<s"”
™°¹Åí¨]ZÙýŠ8„B™‚¤CáÙ„ƒ‰‡C4©Ñ3XÓ!sim™¹›"5•dƒ*9É³§<	ÆIµrÁ!Ña3,Ñvk¸Æ,>-fwR7ßÓcn{w›ˆ…sS··%Årr¥+ÊÝjv£ÙK«l®v•xÉ™áÍ¾›h×)¼¾P\®¹Oç_¼öÕ|8þÆ¯&ÂÄøÄ¿ýÚWÖ¸Èy—khxˆ{ñë_ÿúåæEo	€{—ö÷§HÕ°éO:b3“FnÍ.ý†³«U:ÖÕy™Ã"ÖÖ
¨àDyt*¬=,M¼yÞ/hl}Vº´ÍÀ¦SSS™!,é›dWÝ—4tw^¥J÷šO×ç|é
.A©ºãB@Àß]ÛwÕU]ë¤ÒÏ%‚ý	²ñÈ3R!mh,;v%DÕ—W6v7eæ$H•ºÌe˜JsYÃ˜ÿÚ¸´É²'‹VîiÌY;›%*÷ñ_Ô—«_$ËoÜæn]º³^_Ic#ã‹w,u7"uÂ   IDATgxBZ]^B>“o£™ÞP·=×díÎXhõ[6‚žd¢¢…?*DOÕžÊ¾,­ª¯›œýÑ³Ö+""«ªK¯$Ò,!E8õp<ñÄß=xø¬@€M²Ü•K„ïLÏýóÙFvÜÊzeb!Ós[áÊÍ@†
¢bµA?ßB¬Ð4¾gÂÊh*¡I¥B¡”%ÃAj•š!Ü÷í?—Øhjr=­b:àÚýƒV>·1‰JoP‘Àý,,Á…|Âg`6ß fb…µr¦Ç’ÍÎ¹*3„2•V-Fì»+ž3‡	&lÞ]ñôÎfwœˆ{š¿V±ÛÉÎÏ6ñD…rÕ¢x‚I†íÍÍö!Ë&åixú+3¾f
Raûî¹÷_kq†âË9ß3¥¡ÅË¨]=|•>\8Ó¦WŒÜà²åK7’Aãî{0ènù¿íçŒ¾h2ö4ïäw¤Á˜»büpŸì<»¾ÈZ«ÔL¿ÿ«¿ø-‘Hý]øÃé5.Cüæ£ßÌ*M/}p¯Ø‰·/vï°…zÏßì÷9ÊÊ~ð7ýÜëÙ?Wù/?ÙÔ$Qe<ûÊ¾Òt‚¨PpäN‚•J‰ÄðàT)Y”¯ÀÅ¡DiÕ‘¹~¾wRÕé\Ð}cñìÃŽÖ—ý…û^=éÌË¦¹ø¡RSE•;²%ŠKËŠÒwb)–Ÿ5g©™ySwÆÓÒêª|£ØÄØ»£r#Øtl8VüÕÌäÙ(/5|õJ¬|ÿ¾ªB6=551x¦áOž¯Õ¼pÜ38±0·`£1iM]EöFYëa'Fú'K««n<<Ÿ±"£èÁð™À}÷nÐŒ¦çBmmZ¾ê(“x$IÈ´Ãb®•.F¨$!—-»$CÃI‰†”I¥$Î7ò+UCØØ†˜dœJòs­k\“¤(ZL.ª!áVÃx"qFKÞ¯.!æ‚„0wð:¹œˆ‡ƒ4©‘ÑP¶E¾†C’úÞQ=±.b¹ÞÒc“A³•ÒÚ
1u7[³…9â«ÙG	…ä‚Eh*`uÚŒ²½·[§ÈöÞà6íÿÙÌ¦é°µÉÊ´Ïv$á²æ–ð2AC…<v³3$Ô¸nY4Ëvò`¾2jT®’sy
qHbÉˆÛhU·µ÷h“>«ÕÔ!e
­GGyÍN»S¥t¨%\jåiiñu=ïLÄm5éåêŠ|ôáÝ¿ÿ—?šéñüácãpÿýx¼‹úiÉ¿~<3±€øüã_)Úþ•Ï/³Š?;Üöç§ÿ¼ @ð_Úþlé[€{ÃÞíw¹GÈÙßãÙÉq*-­—®˜.°‰Á«ýT,qûÝQ4ªïKKK_éHŽX^°ïõ{*ó®1ß+H]ê¼š¨1e‹&,"Ö¨«JÐª€¼ÒwG§¤õ¤ˆHO¥	hæFˆŠE.xË55>0Î–~§”»%c‰4Q
žyáwÇî¨k5½XKŠfæN“u¥HÓÒ±¡XQUÃÂ²9åîó×®Rûl;‹#< "é®†¿ZQS]JŒ_9Ñá8+=w¦.SeÔÝzìîzW|Çv "sW½"l"øò7gûïHkŽToGÌúð\ñ^}ãoÿ–{ñØç{ñÀb‹CÀðtíBS¹¸(Àÿ>ò‘áÊÄFI”†¿Âk·Ú[v;åšvK¶Æ(ký¶ìÌÌ'7ø{Û¹¶1Íð¥u÷Jø²¤ÜGÜËe¶ÃðOÙ+¸Öº˜PÈÅn®‘¯WÈïÿ†øOÄ3ÝPâæÝÆÌsCÄ*Ç­m&¡)OS…gfuJ‹¿G/çZ×4#”-Þ–˜Û:CÓ÷÷Á…Zîõ…ã:’àûohå’¹n¶+2Í¥»í‘UV/ÓõÜjc¬-¾8ApiH†àKlX›ÍÑ$ã0a*(‘)Õ.já¯‰ÏlIt6UÄ;¿
nª©%ªµ¸niù.Š2’œÎLö¬/œ‹¦Â¡ ×ã	%I¥L"*WŽ˜dØÝ²Û-”)T][ngš<„•­'3‰S WÖæri¸ËKÃ–0•d	ñúâò¶Þ¶L‰VU[›6´`O®/ãï_jøç…×úãÐ®gïd_ÿ«æŠCÎjil/-ýË‹ÝË½¸7ìTº rß~iÈŸi²£Ã“Ò]dÞ_ëÙXðû‡ÙI¶”b¹¯ÔX–(
ééô4+š¼s{<UY5ßÞKùÏyF‰:ý|ë‘ð_úá»lÅÞƒ»¸FHêíŽ_YR`ÔðuOÎ;YãÎ½ˆî@ðE\‚WClÅ¡îÞY¥ªSzä+CÕU%ÜkîVMŒ†­Ç¯)Šõwõœß¾ãì¾pÌÄØð$YGn(ß¤ê—ltäúÕñ²ý¯TsÉŠ`{uÃöìËŠ†ýU¡sƒc©ºRîˆ*W| 5<÷}ó‰É“ŽV’ZõŠÌ×à`ït½rÑl#^í¨-!àa@‘Q ØˆÜ"£?O|2¸äQ?Ÿ˜ÿ™2÷ƒf]„2¥žkÍ¶…}N³½¹‰êæ¾™—ùkòáß_˜0db ±x…á-Ü÷øAJ¬hÏ|ƒOªâÀ|)Šû¸!¡%’ü° nC2MÏ¨† #öfãüoYyŠŒ2Üb™-ÜåL¡	¡ø~uß˜ÙC>áðxÃ”RNÊ5\„š¢²ò!‹¹ägTanvËÝ\:÷am·C/ƒp¼ó¾%bo±Î¼å®¯2iu³QètŠh„n·/ZK·R-ô5ñœ=TZº»õ+ÉH()–Ëç
ò<Î3|nbr"´Œ‹*=•,îl6F‰•Ï‹X®wuëˆ°Ïç˜w»í
Vo0h–Ùî\e·Çå?üý0³q¡„©øÁ:L<¥%J¹dM—¯`ûŸþ±õ©KMÿùÙÒmýÞ¿ïªjþÓâÇó/ûé§Ÿþä§?ýùÏNÝ¹ó/ÿò/åee;vì¨Þµë¿øE÷¥Kso{ÿnÂ†‰ÊOe7CÄ‘y€J(ÆÆ†|Ý•3´îô…¶*¾ÂžïWOvÙFU†Úø¹3ƒ5?8R-"Šc¯Y¯²{ÌI7ØÄ »óÊ8QPš;Éut¬¢ÎpúÒ³3]7ŠŸ=yãÙœ-¥‡‡®–žxµµEa‰ôˆÇñZ¢âð‘Ì}&*é‰™®ël:•fEó…qSƒ]gûØšãû2]ŠR‘´¢ö@m97GqmcMÇÐxjYÂWÑH—ÖÈ6opQàA•:·¯Ñ‚¦†{ûÇ©©ŽÆ¹Z6Öoí~µk¥ZpDRi!‘˜bù‘JÙ)‚âòzÃ¾w]íSOÞXÇl¯Ñ978žª-ÁÃo
€È-ªÑ\e„ÛúkpÌ“J½ÃE47¹½aƒJ³Ü\2…„ñ„£´V6ß²æ¾«§™v…'‹fBS‘¦Š¹®Âì@áýÝ˜$%t0§5ë¥"”¤˜/þI«sžKšŒ†ã©‘Ýß€#“pno $NÊµ
±p¾á¿¦C¦#Î–Ÿ˜ŒCËIw‹=f$$×ŒçÇÙj—?oäAÓI™¡·G#¡¼&Ï|U±\£åk›.œY,“­ôxV&Î]K±Ò0?Ú$Ó†œ/*SjÛ…rvf…ê·L*µíÜ[2p:ù'kÖ°Tþ´$°ZÍãH
ÿôÝ×ÿ6çké¿þÖ«Ô¸Üãb¹8ê{'ŽÿÃ?þãÜ”ŸF~ÊýÿSO=uî/~ð¥/}Éûú_Ï½]:@`ÍæïžÔ¨ÿí‚ºS=\¾ÀÞõwœí=3÷Û?;9=IÝøþá ;™–¦ÏÇ¤zCñkç‡k5E“îÎ£Ó„íhuæÛNvâí®®é~SÕàÍùíU¼d;Ó@âf…õc'ÎŸõÇÍÔ²-,%§¨xŠ(çn¹Tl|R´cG6á`c7Ïº†¥ûl³‰› „,õSéz¾©Êßï‚l85><!­"7Ð~ec£ãDå¾ù
‹7Z\sæojf>K]8vfrŸ‰}¸8cöGX:‘˜H‹Dn";ÿÏbAÿ¨•/áŠl1(2
 ‘Û%ã¤ŸWî(Xô‡›8?óº{pÐT4§Ü'÷¸„ï±|›^H*5r:ä[Põ’{›$ÕªåKUðmâ¸LÛ3ôþŒw\jq4¸RÏmˆk"k4d2è	®«6(×ÐWË’A_89¿ËTÈfäÕý8²	GÜëŽÊTû¬éÅŠ¶n?§»M)+ômz…Lapõú»ÛU2™B½Ì ®¹²·444´¸£÷8æ†Žx<Q™&§kCÇ“ŒXœûØ_¥JIÞÃã]ù®%¶žžvåÆ×Á…V2a’»¹×u´_züÉ/-¬²ñ…‚'K
–_àÛ·sÓ9ï½÷Þ;·ßQüáæ¾% î]b´÷z¬¼q¹'³²é‚Š¯ºÆêÒÒêN:WŒSibšH_”¶võœ3TÍöå”<ü’ÇÖ°°ò¨@$ÂÓ2aØ‰Á®S©rý‰*f¿n‘5µRÊýv"=1ì¿6^¤Î”ÕHÇ‚g;®Nî:t´n>J•©ÕÅ£W~|/ÅÏ<8%}¦¬˜/;+©(ÛH¾‘a+*fó¼…Mu:h¾Ð?r7•z¯ßsy-ßUQœ<×f8ÞI¤SÔ@ïõa¶´jeVØ»žËïNWÖV yXPd 6"·KÆµhåëíÁÁD½¦&g’k'´j¹˜
¹ÝaBaá[ÉË}é.”kÛÁfk‹1ÙfÐ(ˆHÀãt‡$ºn|¥|#D‰ù\ë[¦Ð(„Ö_ŠBx7DðY…Îbq‹Y…®v)¦£™Ä`•:¡\TÐ®MÍ¦¶6½ZÎ„=N§7*oï¹ï8x>ˆˆüø"÷k9äÜ
­Të”>§ÓGÞ°DïR-Ì7æ÷b™RïÐêgžöÊDìÄšÛýÉ°/D¨43…Y’a§=@¨]Úœ‹À$£ÉìØ†¡¼ÍMvBßÛ³Ê£ˆçÉT:µ»ÅiõHÚ4$õ:}QZ²Z‡‰É¿Èœ™'~¯ô+Llä·Ä/~õÓ¿ÿèþäË,ðñÇË:žþ87päÞ ÷*MÝºÂîÐŸ©X¦NÄˆ¢òì³
¥¥¥%Dõ®Dènj,5^ßôªÑÂ–ÿýéòÏadc7:Î§öDqXUzìÊ	k_Œ ¨Î:3“d{Ï;”×:pç”­ù›ÓÒšC¶=\¾‘r¿Ü9à~ÏèØŸ‰PPvøÂÙúR²þ{ÇW=§š;ÅU/Q—
¸õ%ŠªÊg°éçwõe‹3Œ¬ûaA…ñRgàö™ƒ§B“™©®ý_w©Nv¯J³dcÉLÒå6Š¬ãåt>ú   IDAT•5ì¯ê<×yÌÏ½)*S·ž<PÃ]ÐªÆ½Ãg]¦7øÛ¥ ìßSo_ËÕÈ)2Z¸cWýK¶&tñxxPd îÕÍ‘þibq3ég‰×àòu$^ÛÜä6ñ]TºvW›fæ	%jrŸªlouëdB.p¹³Õil°™±´¹,ú¾lgâ¾þ†^žÓúæÂŒÄµÊû·¡,.Iqu­VósFþ¹¬*•ÖÑ£UÏ>v%·È(G¢é¾Å—ÿ¨Ú]ncÖ_ºB,×´w[ô«·¦™dÈôµ§M³oI}¯¿]¼x+™§ªù…$jÇ[êüû¾Ú!sGgshÄÉˆÇêÌ”ÑP¾Ý-fBãòç¤ÉH ‰$½!y¶šŠÚ0ÿ ”†&OR¡s¬­:CGÞ¸LÍ4°Í±®»ß#.b‰RÂDƒZ¢_¡Ç³à?ËÌsk¹üƒ~-Ý.¯Çkm°SB…F§QÅW+ÑúÁßÿ÷ñ_¿§Úñâ_Éÿ¨„ùé©áKöÉñ7&?ø“/?‘‰çÕjîÏ
«\ùS€u‘\ÜPÆ¾mÞãøÉ\ÕÏá†Lƒ­t¯ÓQ5–*./eR‹ÉAw[çQ±çdÉ¸ zWAðûGo”í3ìÛ¹âãOØtzjft|:FM	ÊEhÂêDå<?ÎÛ¼)}þÈåçäL(Þy´çÍ£ùfå‹1:[h~
ÿ”WQÕž¢¹ÍT¶õ¼Ù¶dÁgŽ÷ö_4mbhtzþÑ+Ëotî ^x€APòLëÙgZOÝ^sÈUshÙ¥ò^‘âjóëoš	ø]ñ+2ºí#öàQ•ú ýå'ï¡þz¬ë!)y³Ÿ	LÔÓÒl%	±LNòÛFÅz‹†	x¼—?0‘0#×·µñO>¡£^c‹›Ðè%!wD¦5è4J…|ùš$LÔÝdŠ·÷Ú¸Ø"óÄ×¦»{¶kM<`lñ)]\à÷›í´ÆåjÏvI»[™A=bµÃïZþiº|¨bŒêz{ôËÌÂÄ}-N™ãÖÌsg’þ[Û–üAsÛ—ŸœùKôÉ¯~íqþVÝ[ùÿùð¥ÇÙŠšÊâe‡‰ÁkUuúú¹7b¥ßÐ?/•JÓ7;ÏôNî:óª¡œˆŒ¦¤U•ö½kæs“ú3­|?Ôèµ³g<Ã“3ŸVÏŸ®ŸùZ•x³ã ¬J+êŒfýN|m
;v­£WÔj®[G‹Ä€­sLm1ìD÷#€æƒ>¸êõf‹Œ~ñ‹_$~—üòWtñëûû€i›p¼=Îî»4¹Æ™_o)úŸÿÝÃû¶¡¼F£;J/œ*”ilÝ÷RNá!nh“·µòŽ$ãIB"Ëv¼`ø¸ÁK(H™B£Óò%:h*ðx‚b½Ã ô™¼„ÞbPŠ“.ÿ„Ã‘H|Ñdz•tkù8¹_bÃØÂç(s$™ô"3T‡a¡ðŽDyÈ¦?˜ /)XTrcú×~ø…ÇŸ@n °¹Ø‰ÛWûØÚýÏ®?ßHÝ|mœ|¡Ã« Q8 Ög3ŽéOÝ_NÞ~]uÎÿP&ø+}ÑçQ     U8Pƒ`“|žè}©èïcÓM¯T@ôqÁ¶JYÁº¢     ðhCÀ°y>·ø£í      Üoè       [       Øòp       À–‡€       ¶<       °å!à       €-       ly8       `ËCÀ       [       Øòp       À–‡€       ¶<       °å!à       €-       ly °Q“ô‡      äS$~œØD8 6n“ÿº     Àr0D       ¶<       °å!à       €-       ly8       `ËCÀ       [       Øòp       À–‡€       ¶<       °å!à       €-       ly8       `ËCÀ       [       Øòp       À–÷ ›…‰þÃo?œZu¶Ï=^(”ÿO      ¬€Íðé‡S¿hndßû§5Î/|º|ûk?Ú&      °¢°>úÙØÚÓó³1f<J      <04M»/^ìê¾˜N§‰­À¦øäb>ebóÑTÀÚÒlt‡âcë¿[˜¨§©ÁJò/)ŸÕì.{N’!{‹5˜$Öƒ¡i&çM’Ž‡ìF{x}+yh*¦p{ Ü‹ô{}—oŒ¦¸WllàÂù›ï±ËÌ7z¾õ˜ÿnöÓôØ¥cG{)v…õNôŸ2v¦2¯YÊ¤íüÈgá—U  x€®x¯Þðßø›7z¯]#¶>QølHŒv¢ÝïÒHf¦0kCKT×Û£#…É©É”4ø»µ2áJk‰‡<!y›ƒÛ=a¹E%! ƒ¡©h”á	†¡"¡ ×ãu®nƒB<3LòŸÆÆÝÆ ½dy¡ÂâïÕËsÎ=—ž´4Ý=íü:¸­vZ«WÄ=¾ˆ|~µëÙE†
…ÄÀ¥7Ö€Ì`ÓÉ…ñ ÕI©4„×;Ïš1ñh”“rÙü12É$!‘<}_é»o$ˆÙö/ ZjôZßxù÷’M&Êê~ž»f»80ÉìT,–œ8	¸Y§&â	Âj(‚âš—Ž7•‹2³³cÃ¡ëñÙ¥ßmìŸ_•IÛÇÿ§p×©¿<õL1‘8¥ÿþ»ykAT´ž?ÝðFF>êØ»ÃþË.Ï`‚ ¤/y¡º„¿)ØØÛ®SgûãÓ„´æˆíhm)71M¼v±Ë?:IH«÷9ÜPYœ½Ò±7/ž:ÇÏ\Pª:pÔØ0s«ò¹Ûµ“WÇë‹ƒ7ýÁ`hX°÷ÜùFræ¾K½Ù×¼5@I[»N×—,¸S·;Ï©Ž¿X4šg£iê¦ë•!~‹Ò²š&ck=)"àI\h;vS ?ïlÌý‰Á]ÜCßõP¹sÊöžw˜½úì{×Žžx·ÆñjÃvþÚÄÛÇÜ“¯Xžbmßí˜ÌÎV ÝQY½¯õÅêRü4Ú4Û¶mË¾øø“‰­À#ŒNÆ	±LÌ7-¹¯å£)f¸–¦D,áZŸ™îåmÞm/óu½DeëíæââáR…¤˜$—iúÒqŠ‘Ì}ÈP>“9¤h·éWI¸CjÙmå9$™¶ÛïPK–ÝoË×<4AªÔjµÞÑ¦R’K6$”iºG5|¶änñH,mB·5ªuµ+—îQ2äñQ¤Î"Ï~$$5z…9@ÚÚ„’¤¶7Ã¤ÁbÑ)ï{T@Ç£‘9a2¥†ôyâ:$¡ù†ËHZªž^¿p2ìÒrIØ”·Û´äCÍ8~ýÁ;ßÿ9Q"ý¶²€ x Ø÷B}1²ÁÀ·éØT,AHUÅ‘•7žq6™¦«¥'NgÚé±KVè%[¹è×~A¹þr¿ž5ÑÊ6ªî8R]œéÁqÜ{áô¡Êœ†^qÍ©7jíNìí.Çå;¤áHÒ`cî®aÒøz_•h¢ßqÂå*-=ù|)ñ^ßy÷X¥¹ç\yìÊ‰NÇw&n^¼ÛuÒÿj9Aùm/wºKfþÖK½í2_žTuôì¯$Æn$rWwt”¨ÐsÉEš#qaÝtîÇSéé)Aq¡€Zºg©ñÁ„´Z6yó/òl4ugô®´±ó¯kÊ±gÌ—.öWdÿÖÀý—»îœ*,Idc×çæºôòùÉoÔ•‹–Y‰¿ÓM•luOq3¤¸…¥êÓŽV‰2uœt].'O=+%`sè¾õíOûiAAÁ·ššˆ­À#‹‰úŒ-AUw×ÖL†Ýfs\×mQÑÖæZ¢²8dßðÖvêÄyžÉ©Á”\±Ë ÷]=Amp'CÎfc`¥!µÃïÊvJ’j½:hlnˆ´;lºB¡Lmé%“&r[µb&ÝÈAÂ;½­Ë&á—sX4òÙ£I†¬Æ°Ê¢gì»[|™ôíôÙ¹œ"ˆb&ž¶PD’b"MA:)1ôôätxà6çôQIÂºûiëü^ú^)ßH¼Abe›­Ýnµ6=Ð»ºï±ï“¤âŒŒÌ×ÙG¬Ð¶é¸«ª¶lø²
…ó=MÄb&h÷hÚµ¯—;™³ËÐ‡ãÿý¿ýM¼ñÁ‡Ê''  Ô°¿?Eª†MÒ›™4Úpkþsé7œ]­Ò±®ÎË£™ßúkkTðF¢¼F:HÖ–&Þ<ï4¶>+]ÚzcÓ©©©Ì–tŒŠM²«îKš
º;¯R¥{Í§ëËñ…7p7\©ºãB Sã|û®ºªkƒTúù¢D°?A6yF*"¤eÇ®„¨úòÊ¦Óî¦l5t.û/ó_¦Ò\À1æ¿6.m²ìßÉß¢•{sÖÎ&ÆG‰Ê}ü7óÅåêÉòw†ƒ¹[—î¬×W’ÄØÈøâKÝžV——ÏäÛhqEÃÁŠìŒduÙ{m‚»ýp<é±¾ƒ‚jUùàø
s±ïõ{
ë^©.ÉûqjÐsöV}üP¾Ÿc"²ªºôú@"ÍR\ÂMòÄO|÷àAâ³À#‹Nò$™v¬D¥ÓÊZ|º×Ö{+w&¡8o;6^jù
­\þ»zcD×;?hf$×Æ•ÿ³dÐÔ`+´ªùV¸X¡wùIÑÜÜL9\íêœö9ÇeÊÙÞR!!î©Ï“”¨Û”³;*$u=?Ó1QO³™2tÛT&êöb‰lþpéx8B%U|Æ2ô¾%lm2'Ü¡
ÃV#ÝnÑÓnØÐF¸íI½E2¹l-`·ÄíþÑù¡(Ù!D¹Ã6ˆl]¡D"äâ£9ªsÙ²í:Ñ¤Z¹¨¿=Øü*­/˜”m,#™ßn2h]ÚÇ¥áéùƒ«·ºµ2âž	åÚv*Û]ê<'ƒÆ&·Ä e¼vo„&ÄrM»-›^ÑÜ·2†vEØiå>+t6.}Êœ&öÚ­Î@tn¤L×{Ë¦\ù¬ðãS&¸ÿþø—¿üÞ“$F©ÀÃN¼}±+x§ Á¨÷üø×V¸ðrñ’íàâta"5ITÏî™ì²²‚ju=XT•`¥UDbxpªô@Q¾ßû¹¸"”(mØ!"RC×Ï+Žw:KVØ—ô°£µ“ª6¾z¾ºÍ˜#Xò7~¨ÔTQåŽâÌ»âÒ²¢ôOb)¶R”3çìK6ò7Gð   IDATug<-­®Ê7¼€MŒ±;š6ÒjMÇ†cÅ_Ý[œ³¥…/ç¶N—×©1Bå`©àƒ‚:“Ztu¥€#5âïKUê«ófLSÔM·k°hßÙ*ò]$vb¤p²´º
éÆ&¢iÚûúëÛ¶û¾­‰¶ü_ *®‰N¤~f …P®ÖÊ=noØ T¯Hpì (WT óáÈ¨FÉµÀö ¡r´/úÊ_(Qº]b/%Ée’A»Ñ7ô.W€„Ž|”L×¾b·¥ZÖÒ\áÍ¦Ð÷È%Ùý‰F(*ÇÕ*¾Ž‰½%Â÷àˆD3=8(1ßƒcv×ãüÎDäíÝ¹Ci’‘@„Q´)DÉµÙC:zr™BAx}á¸JÃÍ@G¼v'ÝÎûì±'Ãv³—Ð¶ÔÜ•”(´º…Çöz“J½fîBñÉP‹/¾ðèä†ÞÞùÃÊøx'gGÆ&ÌÁÍq†0\«d*þÖËµù
3ÓUhpùG•â¨Çd´›d¯…+&ê5š(}»«Çõšv§JéRsQ”ÇÔâ·÷Žêdq¯±ÅÍ½tiòd1Ÿ|0>ùË‰OfúF”~çßð/F~|ãÉ¯}e¦þvÁÿ²ò‹O ÷	{·ßå- gqg'Ç©´´^ºâo“lbðj?KÜ~7AêûÒÅÒÒW:R†ã*,Çe:(P™wqiEj~rjèRçÕD)[4a´F]…tòKß’òÅ,ÒSiB š¹Q¢b‘ XÐA(5>0Î–~§”»%c‰4Q
žyáwÇî¨k5½X;“6¤Æ‡Ód]éZOéØP¬¨ª¡˜Xf£™·3uŠv_©E¾ñ p?Á<7êOÔ‘¢ÐJóMŒöªÌÅyV19üZçÈðä´”šH¥ós°‰àËßœíÎ#­9R½—p]ñ^}ãoÿ–{ñØç{ñÀb‹CÀ°)f‹÷äõØïÿ›'¶M¼|tí‹Ü;&‰Ð2ÍÜ!©Ö*ÝVoˆR­ZŽø¸|C«^XƒµÒ¼¨íL¿0rÿ‘h»oeë[Ä-yëpÎ“·ßòrV½¨|&õ™ÍBë°iò¢ÐDÎa¬Ö€PíR-S^•¼´Ú¡É{Ð¤BAŠËrôÝCú¼+à»ð¡ÃnwXi³¼5d!–™1ê3µ˜ÃrKE÷º#
­–ÃPAoˆP:æ„D™¹aMEªTb_0×Èdt4aH½|.ÙË”
Æl|Î§0ØmêGÉ—µ‡.­†œ_¢v¼ó¾#gŸ(oss@œo¬CÓ„xQžEÓèyw³7N,/_ï	¾oOsÐÊ§F:}›NM®Ð¹B(Q·sAj”º6m ™»´2s%ŠöîlW…0ìk	Gâ4pPÑ¸DeËÜ×rµ^é±†¨¤F¶4³ûüã_šþiëOûC‹ªiýæ¿~ç¿þ×ìKiQí_Uýgàþa§Ò•ûöKCþLãž”îÊßccÁïd'ÙRvˆMsïY¢@T(¤§ÓÓ¬hòÎíñTeÕ|ë =æ?ç%êôó­GvrlÀé‡ï²{îâÒÔÛ/¾²¤À¨áëžœw²Æœ{ƒU€È|W5ÄVªáîjåYÓ#o\*¨î¨*á^s·jb4”h=~íHQ¬¿ë¬çüög÷ð…c&Æ†'É:rCùÆ %¨P/è¸”»ÑŒâjóëoMŒÜè<c²N;P1÷~ã~&]î#êùb=‚‰•fLQ?¹#(Ë›d±S‰á‘²½§¾7uùâÕ`]yÓÜí0_ƒƒ½;ÐõÊE³xµ£v¥>hp¡È( ¬ß§Ÿæ¾þ»2†úyöÙ±Û

þ³KøOÿfä§üèõåYU¦w~n	&;u™¬‚¡ÂÁ¸X¥Èis-j•ØäQR¾rm_²mQ¾AHT9mg¾ÿA³'¾t,ƒDÕÞÓ«_¡_‡P,ËÍø™)¥Å‘tÂµÇMÍÖˆ‚ËÖÐÑ„ÿþßdIt=mË”wH†fg”´Øvß £Á@ F¢TÄWëDÐØâ‰.ÜO…åV¯žrg2Ä
¹X!§<¾°Pìsú>EU¬hsXT.›&•¶^¾š&F­Í>­Å¦z½©kW.ÞC±\­Z‘¤JEj-Z&ã÷×bÙÿÌnÈT†î·4!ÝGq1ËüõL†ÝFS€Ð¸ÚV*mÁ$)š;ãK‡Ña{³™6ô:rÆŽÄ¦f·°½Û6›£ˆW¯f¥N:bÙ’ª"B±ÒÒëP%C^ŸÏk|Î)Qjtƒ^•?VË­Ø!‘Ë„$w”Ù¾$ÂÙ¨E˜<Ä0™=RÉ	O0Wª%ñH0ÊÈtËÙ)(ùò·ülÙ©áKöÉß,ùô÷T;^ü+ù}åóÀ}$*o<q”MÜ|ó€M‡blløÀ×sÇâIëN_h«âk!ìùÞq5?DEe¨Ÿ;3Xóƒ#Õ"¢¸0öšõ*»÷ÈÁªÜï>ÝWÆ‰‚ÒÜI®£cu†Ó—žéºQüìÉÏæl)=ì8tµôÄ«hÂéãµDÅá#™ûLT("Ò3fÓ©4+š/Œ›ì:ÛÇÖß—éR$(Š¤µjË¹9Škkú;†ÆS{È¾ŠFº´F¶|ƒ‹ïªÔ¹}ntž@Z¹ç¥†Û/ßRµO!ª»ŸXªß?x'>ø]ÍìˆÕaÃ7‡_°Õ/ê–NŒQ„´!oOAAÑ®£Ç÷?S”J‡Ú._¬9®^Ò…L°½F`täÜàxª¶¤˜€Í€"£ °~9Ý1>ÿ¤DÖõWSƒÿg¶ËÆ¿þž…K7¸Òc/4aþiœØ¡P,dâ4kÌ¶i¾çƒ˜XfXF(@ñ¥"s?•(4*±1¢t+=ƒ¯«]êúyÄƒv«&3MËœ=Êì¨˜T(IbÍøz§“Ñ¸;ªwdsf|‡C·ú<’aÕl
µŽî¶ü£OøÀècHqØnöÈsúÊpËÚ½I™DLj\[\BGìb¥Áß=7Ž#âšú™WÜ™
•,L)ôµT’Ž^]ÞâÒ€îÞ¹×:×-eÀj4=ç¡…r}6Ï‰•mÝ=\³ž?r¾¯ñyÂm÷’Ä‚O9\ªù÷™‘0&/­qõ¬R¹“NÆi¡lIï:ìäN.³¥ù2¯2u»!Ðdwå³½gÄ2…rCµ8$¤Jgáþ0ñ°Ïéq‰jK­>Þ‰;:%wYŸóeqÒJý‘¾ðø}ÿ«r¤³)6‘³îÒÃÿþÐ÷¿üä€ûo¾h@jÔÿvAÝ©._`ïú;ÎÆöž™{à	;9=IÝøþá ;™–¦ÏÇ¤zCñkç‡k5E“îÎ£Ó„íh¶€;ñvW×ˆt¿©jðæüvŠ*^²i ‘]Àú±çÏ†ˆú¹…¥dáOåÜ-—ŠOŠvìÈ&lìæY×°tŸm6q”e¢¾q*]Ï·Mùû]@dæLOH«È4XÙØè8Q¹o¾$Ã’fz8ÍýÝâ‹ˆL³ÓÜWóOk"ø6™M}§òÆ£S	j²@ºÌÈ;‘´’,ân‰’gö5Ü|ùZïhU[BŒ‡ï3Vdôs l‚ÙîÛ

d®¿ü|Q±¸î?íûÎkÿãsÆ+øý\Øöx!±B™\BPáùÊŠ4ŽÐbR–·y›Œø|I¹vqF!–«U’x §>ãbLÔk6û•Á \®ÝÌPs‹)$k³dƒ{¯Á!”ëÝ6UÜÝÒlŽÈ-=®ÕžKS!·q÷sMÖXçê¶äÉÂÄCÖ–¡ïîõû»µ´»¥Å™=l!©ë~gè–CGŠWËQâ!oH¬Vgz@Hä$ålnÈ£Ùˆ/9B®¯åû70ñh(’Ìw¢Ä’ùj"L2ÈWÛÐµ¯îÐQŸ©á¹–€ÐÐÓkQËV> †Ž'¹ý^ÔƒÛ’5$i³-Ù’P¦iÓKBNw8IÜ\¡sôvë×òÄÙ$¦î$¯tõ¹»ÏQ8Þùgô­žUO 8>Q&ZøØ”Ï|åñ'nÀ–í½+o\îÉ¬lº âÀ«n‡±º´´ú…“ÎÃãÃTš˜&Ã×¥­]=çs=ó%Ï¿ä±5:ðDü#8Ö‹ì:u‘*×Ÿ˜¯)"kj¥”ÿúíDzbØm¼H)r‘ŽÏv\ÜuèhÝ|”&*S«‹G¯ü0ø^ŠŸypJúLY1_w8VRQ¶‘|#16ÂVTÌæy6ÊN¼yÆÐÚqùí±‰tb,xÕ?Fì¨”¢ûÆ&™tŸì¸62[ù‡M'ÒÜO¥Õ~üˆÈÚª¦®ö¿·øqOìÝÏåw§+k+|lš¦Ý/vu_L§ÓÄÖ‡ ›Jú¿œ–ÍU”üYû§ÌG¹Ÿü¾ìËÖïÿß&#±nÙo®MV³GlÓ)ˆh€k'åíù:ðµ ÜAB•§ì—p(%¾p8Î(–.ÈÄƒN“ÙWÚzlËÔëä»M˜Mö°¬­Ç¥“…#Ùa÷NHj=Ñl¥$2Éòí[&¼|©Ôdf(ŒMŸ¿òñšŒÖÙÞKTí.K¼Él4ËzÙ8D(/ŠføMáÙ¸’ªŒ¿ŽB¾®'—
…3»)Q[ºå!oQi$>£]ÜîÒÜŒ
Ež§Í$Ãn“Ñ™TÙº•§ÝîT*lªeæ‚%£wVJwèhÐëv:q.êi×®á‘³tœ¢Å2Ù‚‡/&‘·uç­J"”kš'JÑ*É=>­e™=ŠØ››}K¯KË÷óá/i˜RkHîüÛ­ZaÉ¤rËßVÃpÙ^8ªXñ?9>ùåÿûÌúÄ,&þŽ¦‰ßÞ}#ñËÖ'¶#ã€'MÝºÂîÐŸ©X¦–NÄˆ¢òlÜ](---!ªw%úCwSc©ñú¦ïT•ˆ6ø¯¬—(,»Ñq>µï$ŠkÀªÒcWNXûbAu¾8Ð™™$Û{Þy ¼îÐ;§lÍßœ.Ö²í!ùgõ¸_îHpIÇþìã×
Ê_8[_JÖï¸àªçTsg¢¸ªñ¥#êR·Þ¡DQUùl‹5=âüî±¾DæõèÁºT/uÖ	nŸ9x*4™™êÚÿuW‘êd×ñªôè0K6–ÌÔ)Í¿Ñš½ÃžWþì:?¥°âÀiÓ³R6ËNM&&§ÒóïSS„H°z¼Z\¹woù±«WBªŽ¯.(2Z¸cWýK¶¦jä›EF`ƒ“–Lÿ÷Ø?w_e¶-ýøÿIëÄ}¹nëfìVgóN+×\“)´íÝ]ž6*Cùìž¨LoÉ÷àL1©”‹}¡p\/'ç»P‘0×rv¢4©qô8òwúÏ‹àÖ¬sø3OìL÷ç©3ÇGjm.ÚØ¸ ©   IDATb2Ê\®%ýG¸ Àjrú¢ÉÙ	mÞÚ•L2p;Ý¾¨DëòÏ?J–_¹#ÒÔb6zd=†¼‚P®uä¢"Qê´ÜÛÙ:›|Ñs@nÓÌ=µ„òšíK·!÷l3Ù((Ô¸ºù˜I§Ò2„0ÿÙb¸ÔÂiµzãr½«·-o`3s<ž@„‘«uŽ[z<_ÿš¼U`yMüãa$j×­nþ‘¾|‰¦®+—.Pt¦ºEN  QYzÖ2œd9ÜºÖ{Í^!÷6?mŒ™§ÄºòÞÒ¹‹‘Âc4îž-›È]7›Ã²|ØóQzü4!ü½ßUÕü§ÅÄßS=Mÿð_Ããï“ÛËP€ÉÅeìÛæ=ŽŸÌUýnÈ4ØJ÷:Uc©âòRQ&µ˜t·uŽ{¾A–Œªw¿ôFÙ>Ã¾+>þ„M§§X"38 £¦å"ôç€Õ‰Êx~œ·ySúü‘ËÏÉ™P¼óhÏ›GóÍ*(.¯?t¶þÐüþ)¯¢ª=Es›©lëy³mÉ‚Ïïí?¾hÚÄÐèôü£W–Ýh½ùR½™€MRRk»Z›})Ø^{¢«vþ#Q¹ÁózÞ…O5ž»Þ˜ó¾´þÌëõÙõ™_—ï!B‘Q X·mÜÿœ˜øç‹ÿëšY÷/£b®-×«µåùD¢r¼54óšaø–¢z™…Ö £4£¹&fÐã¥d:—C¿Â×âB	©PjmƒN!NRQZ(¤iæ>õàÈ+ôG¼Ùh²“~ÇÂ£bR¥ÕJ¾¾‡b¥q%|mÕ¨X×}K¿8"‘¨Úí´;I/Øãù7ËôàX¸ò€Õä“´¹ÚU&ÂOr—¤½-Üb¶’½™=f¨Dx¡¸DÓÞÓ>ÛÃD¸LºÁ—úlj	5·f¹6:_JÄs±Î­î»-H”–·Þw¬%uÊî“ZÌaîH4.É}J«½üm!V´ûGÛsÞËu.Wï’¬JÛÜÍ§úÞ™gÜðÏü¥õþÑ™”*¶6·8Ýj•k™š´Óïÿjü_~!\ùìòE‰?$á¢²¶øoáŸ-ûbpÿ	ŠÊw×‘2îNÁó¶Ïç›ebp´RU–8îDé7ôÏKëÒôÍÎ3Cé]{*êVTŽŒ¦VÜFqymàÌÁº‹3ï+ŒsØ‰7;òÁJ¡´¢NUŒÜ866zGP¡ÞÈÍ–ž”©w óÀô+2ºí#öàQ•ú ýå'HGûE>ýè£÷›þÓô/þ¯5Î/ ÿàøkÿ6ÁýÅ3îkÙmâ+Óv/Ž"îÆÅ+×a¸²’¤ÆÖÍwXá{½ðå/æú®Ð5 k›í’˜ZBªn›Š‰3’ó3ÊvW¶ãÍÅ=yöatÔçôÑ*VµÖCàÒ ¡ø>¥[L2hl°í~—f­·279…üsxÅk[Åô¯?üðK+n|òÁûÓå8  î;qûj[»ÿÙÒuÿV“»ùÚ8ùB†W<¢~ù+ºø‰õý @À´M88¿ýà×ÌÏ¶Æ™…eòÏ‰¾H <ÊÖp0wSK@nq´ó^ø.5FkXaëµÝß”    ¼¢°I>÷Ä—~¯ê	 x`„
¥öz[vš¾vªD¡utëTH7    	èÁ´ÍìÁ      k´Ÿ#        ¶8       °å!à       €-       ly8       `ËCÀ       [       Øòp       À–‡€       ¶<       °å!à       €-       ly8       `ËCÀ       [       Øò# `£&é	      È§Hü8±‰p lÜ&ÿu     €å`ˆ
       ly8       `ËCÀ       [       Øòp       À–‡€       ¶<       °å!à       €-       ly8       `ËCÀ       [       Øòp       À–‡€       ¶<       °å!à       €-ï1 6ËßÿêÎ¿L´êl_,øÂ>¹ƒ      €5CÀ°ÒÓ©oZ¢“±5Î_Qü•~Uøù      Ö CT 6ÃK½¿ötƒ3šzôŸß'      š¦Ý/vu_L§ÓÄÖ‡€`3|üÛßëôÑ'ÓÄÆ1Éx’&î/&Dã÷{­Ÿ!Ü	
x¬î ÅäLL†}¾pœY÷ºâ>³ÉÉ=ÛLÔÝ°ÛZÓ`âA·;@­{³¶6½Ñß‘½xØØ»}Gwk^°ÄØì„ÔÐÙæú½ì2K¤†.œ¹<”"îôØ…¶£½K   ÄïÕþsãFïµkÄÖ‡!* ›Gñäu©"wÊùÑ ÷ÿ‡*4¹ßŒEþþWwˆ{ÀÄæ&·ØÒëPKˆû…Ií-^y·¿]!\~¦x4J‹I¹Lœ³\’H„ÄÆ1I*'H)&6Ã0Báâg¨p˜‘«äÜþÐT$	‡‚¡(EE¨$!Sê4×’Ÿ]„‰‡¼^F¦VÊ„|Dáij°F–mèËþÞv”tÄãŠÛe·.ñ
çŽÛMöp2ûF¢l·µ+%ÙÍ†ƒA¡R§!V;ñ4°›Ì^.J’ê6‹E¯’	gVí6šì¡8A›Ë¦ã–»aÙdÆù­éÚ-íZùìEá?1ÛùtG(×Ú–¹OøSŠ•«ÜÉHÀç!FÛã·(gWKGƒ™ÉÁ¸ÒuË•{?s±IC“‡Ê³*¡Òr«GOÞË=ðà¤Fn†{ZUÔÕW.N¬\¿Jí¨Û™ðœë+íh K™žŒQ±ò{	½—Ã‡mþøì[ií©˜ž)&àÆÞö_vyÜýPÑøÒ‘ªKø{’½í:u¶?>MHkŽØŽÖ–rÓÔÀk»ü£“D´zß‘Ã•ÅÙÛ7{óâ©süÌ¥ªGå¢ÙµS×N^-4¯/NÞôƒ¡aÁÞsçgoûôØ›}}Á[”´µët}É‚¿©Ûg†TÇ_,Í¿ÑìÎ÷wêÛi¹d®Æ}|ß±©á+¶ÎëÜ¹'
eUu­Gõ;3g9ý^Ÿã”ëÝQ@î>qª•»cRow¼øÊ»S9ËVïBGMÉÌ»Ô í»“Ù7Ò•ÕûZ_¬.ðplÛ¶-ûâãO>&¶> ›çž$+´¹Sþ2ä~¤,š˜üÍ÷pÐÑ€7*Óv«fZƒ\@'ddn“¡<M»ó5µ%Úž[UþXDHpMý[L<hm	¨zzr!ßäõi¹$ìÊÛmÚ¹ö&Cy[v›CyšùÜ>ûód2tØitŠm½6Õ
qµ77‡Õ3›¾O’As“™R¶[Ú´
ÉÌ¾›Ý„N/ØíŒÍ¦–ÐT0fH¥ZH{ÅúÞn]æ0“AS³WáâÞ-^£P®ëéÎ÷¶´„4ÝÝZYö ­Íö™C¡Â>îêl’¹É'Q>Ox®ßFs…7ó_‰Úu«[ÃeÝ~bÃø$Ìè•´Ýú™ZÆ¥/Æf“Yœ=î“—Ðû‡t2Êiä’YM%Œ8Ö¸Æ5Ú­rWºÙdtÊ²Yq>I[ïVND¾Hr.™I†ÝÎ E-;}ùvAÑ~+sÉ†æ21·Lrá.Ò4#s÷n<ÏÂb…¾·g>ÉˆûZš}›šmL$?òýˆûå ioã“O>¹è-°@zä7&U­GëJÓRölÿðÐäèåQòÀé•‰â“ÖŽ³E¯­)yP¿å§Gœß=Ö—È¼þ®æ2±ãÀ«zAqÅã¶&’xïšùÔhY©ˆ€Gpw“Æ×ûªDýŽ.WiéÉçK‰÷úÎ»Ç*Í=çÊcWNt:nÈ¸{&qóâ•Ø®“þWË	Êo{¹Ó]êÌÄ
©·]æË“ªŽžý•ÄØÍDîêïŽŽzîOs¢B‘Ü±Séé)Aq¡ OvLH«e“7ÿ"ïF3‹'._£…h&?lbd”}æÈ¥3UÒÔ`×‰N×å
çÑªâôèÇÕÉšW_o*uwœ?×_ÚQWúìÉÏÎ.ÆÝH§úÉ¦ª’kHÕ§/­ñ±–¿ã¤ër9yêY)…î[ßþô·Ÿ|«©‰Øúp |Ö0Ñ€Ó‰'#»Ÿ¶ÏO•i\½.lö­Ôû¦_uU”·y·9œE„v?íž[‰¢½·Ç XÐ¼
ç;<ˆÅLÐîÑ´k%^oXe™'„2µ¥—L.8˜dÈmHÔŠ¼†P˜»Úü„B	7‹øþ¶l%ªv›Áj67<çkïî6(ÅB™JÅ˜<Q‹^ò…)•–T·;ÔiD}q_0ñÓ‘XIŸ•’ë-µÅÂçMæd[¯K-ŒG“b9ß›…‹HšŸ[ÍwY¡Lãð«³§V(×èTk(šÔ‘’8wtB•‹‹u„„D¯Wì\f¡R+Úzü3B¦ÒiÉ` D1J.íy|IµÅ¥Ë\?…Ö0×O)rZƒbƒ4»[|¸ÕÒörÑÌ’]QéJ¥8ò,˜.QjJU€
E‰ßUç]®¡á!îÅ¯ýë—O˜½% æ±wûÎØücé+‡ú¸·‚¢¢q÷uAý÷=Ã}]Üpô¥˜Éuì,a;Zó ¾ÉHw>}¢ž[=Kõ¾Ü5ÿAzäf(]ÝZ³mÃG› TÝq¡F àïƒí»êª®uRéç‹ÁþÙxä©ˆ64–»¢êË+›N»›2s¤J]æ¿2L¥¹¬aÌm\ÚdÙ¿SÊ}T¹§1gílb|”¨ÜÇßÞÅåêÉòw†ƒ¹[—î¬×W’ÄØÈøâKÝžV——ÏäÛ(ŸË±·=×díÎXã¯Aé³zCöeIEm•t06ÉòñDÿpºâÀž
î*T7Öõè¼[ÛøÔÜO’ÔÈ¬LßZ¾lx*"«ªK¯$Ò,!Å ‡ã‰'žøîÁƒÄg€Ï:ê±º“šž¡¹<!Ó6n‰È66ÀƒkwÚºgú%,Ø·RctåÔA(×¶ë˜ˆPÙîR/˜.!’}˜¸Ï“”¨Û”÷oDÍý!”(õ®[j] ÄdGnðéL»8.–+6âÁÊu¶¶p‹ÓdÊt®ìe¤£Á¡0($L<`7º…íÝ6µŒû¸çg:âÞä\B¾ÿÃO?ð&Î]½™[FL*eŒ/§ÕqÎM[   IDAT‹Åü†ïrÂÅ
‹|ñÕ£#“9(ÑwatQtZíž?Ž†Ô´;lz…˜¡V“ÕIr“Tz‹MG¸›M”¾·gæ¦§ÃæSÒÐëÒÊ–¹ÙóÑo²/hš^ú ‡ dW«c—´d²ï¤‡=p¼nºßs»fÇÄ¶fÊºH«öïÛ5)*$Æ.ëÿìú¢ÂÔÃÍÏ¿2ÿNÖø÷‹åën°©Ñ×:7¹ö!;51Y4ûU;1Ü?.­;Q†þ@dÓ\ìT,6UT¹#ÛQ¢¸´¬(ý“XŠ­åÌ9û’MÝOK««òetlblˆÝÑ´‘Fl:6+þêÞâœ--|I¤†¯^‰•8^5rÇ— DÕ{¾ZÂE¥TlZZ›íû%(&wÞ‹¥‰§f{ÕÜ]©OU,?bˆéœ,­®Bºñðp¿±x_}Û6bß·u"Ñ–ÿ‡ Àæ¹<ÞÏýY:ýKžo÷õš^BçjËÌAG¡¸L­ž/‹÷5ï6-[¬R¢vp¹U
0É8•äkm,™aâápX%Sñ­c±\kkØï€’éVè{À$£¡p”TÉ%›3ø€kcÈôí|I
¡L©Ï„…Šy<”F¯’p­ßÝMÞÙÑ¾çž6g:¶t/Û9†‰z›wzçß‡¾öÓÜùÌ7#BRÛ¦´H%RzÉDƒaFÑ&'’IF¦R2f³UIò)²šœ³¥H%
µ8dÏéHÒP‘ém#V;nÍ„Yñ˜“_?E&4·ñÜ˜&¡X"&ùA'¹h¾0ŠLÍÍÄÝqF,§<Ífw(NˆÚvK;ß™C,ShÚ¤ÐóÜW¹OB;¿bœYµ¢ÝƒŠ¸¥©Â³d²P©Ì?·Ód
ˆÝïèL$äw‰sdËmC½j!ð†B¢T+Å¦`8®%ù¿Ü½aäz¥lù}ü³Ãm~úÏ
ÿ¥íÏ–¾È%(.ÝÎý>Ÿy.•7*ç^ÔÖ/™ñ€çÇf^¦Ç.;zíÎtAEëùÓOÝ[@P\ñ‚qI66xk¼Hµ¿íÈ‘¾38:%­'EDz*ÍÝ¯3·‡@T,¤B®½;Î–~‡oåÆi¢ <óÂµwDáŽºVÓ‹µ¤hf®á4Y·‘aPéØP¬¨ªaa#9g£Djèµ«TÅ>ÛÎ¢ÄûÞµÃ†Þ!
v4œ<ÉWàH³)–(.š$(,.$îLMq“2ÒT0” ëvåùÁÅ&‚/s¶ÿŽ´æHõvä«ÏïÕ7þöo¹}þ± ¶8 Ÿ\“Ün²ÅzW·>7(à+<Ò
Ã|1H¾ÔEÏ¨6ï:(oK³W&[=Eà¾³§æ¿â_¼#Öæ •KÔ:}›N½jÏ:âµ{iµC³B¨Â$Ãî–Ýn¾¯ÑZÅ:ºzä” ¡CæÝÍÞø
3Ët½·lJ±PBªÈ¤¹ù9¯ºÍa3,èYÂÄV³“Ò(´*‰Xi{ç}[æÄ5Ø™vÏ\ŽeÖ¿–ü)ñ…:K›*ÎmÉàN6!ÝÆ~b™\%‹x¼µE)QYzT6`pÌ­MhéYÇxî2x£Uw£0™ñC95ø—Ì‚DŒ¡B|•ölñTšIF¼>¹£çgÝ’¨×d´šÝd¯E)–(´Zîd$JKOž¯´±ì•YO†
8}I•ÅePñ'\¥Ë¤I4Å7Ù^,b¹&›Æ‰5*.áˆd¾ëÍå+íîöÒÒ¿¼Ø½Ü[€\é1ÿÙ‹ÁØd"–˜þº‡VU¥&sZŠ¥u‡Ô?•[41tu-•N¥o^í:Z»4…˜xÓü¢ã'+× •Ö¾ÐV¶Ì‡‚Òš½»‚®®`ÙÉz!d°Tðjˆ­8Ä—Zefzä+CÕ|‘…4Á²‰ÑP¢õøµ#E±þ®³žóÛwœÝÃßWcÃ“d¹¡|cT¨‹ËmtäúÕñ²ý¯TsÉà©Fwcú½þ®WÎ›:â<ZÁOe—é3Ã&ÆG&¥Ï—åë¾1_ƒƒ½;ÐõÊE³xµ£¶„€‡EF`ƒ”ÕvV/øn‡·…û‘B}{Asè¿^öŒÿÿ‰ubF¢¶ôêÔ¾j¦ÃwX¬qiÖòH	&
PâLw×Î—“yâŽd„ÿZ»-_Ì +-½U2äõù¼Æçœ%ŸHèUËí@2ì4;£¤Å¶bS\,ç‚ú|>sƒÇ.Wkm|Õæ;×¬5­”ª;ÓÖ+®^ÍJ+åÒƒÌ:…$w2•º€Ó¤’Ìü@øÉhIt¹Ã.è¨Ï¦iÆmçùº•dQpw–8–D…B¦a§](–ÈÔ¶ÞwÔâx”"H¹Œ‰G“BRÆð52¾ÄEnu»Ã ÜØ`:ì¶úh•CŸYž¯e’|2»OÜká‚µ»3,Ó÷¨ùËÊb¹ÎÒž¹Ëº6}°9¢håƒ˜’Ù)rÍUW˜x„"d†Eãh¸TC§ô™›¿Ò´[Z¥œ[ŸX®æŽ@$©!ÅÑ`8“oäßÊ§Ÿ~ú“Ÿþôç?ÿ9uçÎ¿üË¿”—•íØ±£z×®_üâÝ—.Í½}ì1üS3Dd•ºV*Ý‘¸|•ÝS›˜Tn Ùa‡É_vêd}qâ¦Íã¾û$fÛq\ rÎÏª÷©G®?³¯âmOçÍ'÷,n!–<o»ùüZ6Ïµ=ïžûÿiÎÍ¼ß1û= ¸j¿a×ÑsoWÁÃ'€à#ãµDÅá#Uüý *é‰tö#6J³¢ùG—¤»Îö±5Ç÷Uò7¦ @*’VÔ¨-çËÔ6Öôw§ö%|tilù;1zGP¥ÎÍöl45ÜÛ?NMu4ÞšýØú­±Ý¯vªDŸ€CôT­þÀpÛ¹¾á‰*þBS³}6Ø©ÔQX8[ç5™,ØQZ´rl*Ø^£?0:rnp<U[‚Ÿ?ŠŒÀï"1©1,~fß7"@¨×Øêž8i°åä2µeaùŒ™ÕúÜ!BéP-;êABªtîûœN¾ä*ï|É0ÿð†‡íf|•Rb’¯7©5Ø’Ñ€ÛàšõbeÜêMF'¥´Ì­Y,S(W«‘³E¥;g#4°¶˜‚b}wwûü)åZûüéPÈ("é6¶Ð®ný2›`èd2âmªXqˆ
_YcHG0Qw³ho—G#B†¡£^«]ÜÞ­c<f·ÌÖ­Wë²}p–ôàØ¾¨—VYÚf.©X,2É$œˆgv›Ë†æCÊÇÅ\Æ3“åÅ2™˜˜‹C„‰DHÓñ`1tœŠ†½»Ÿ¶.ùh™!*yC
¡\×ýŽ:ð¸=ÆÝn…¥Ç¥—s	Gf”JRE†"´\—|
°|ïÄñøÇœ›òÓÈO¹ÿê©§ÎýÅ¾ô¥/y_ÿë¹·KÇ´Ã#krt QÈý°N%Æ…Uü/þËÕ`cÁ³ßïíµÕ“c#DA!Yw¸aôÄ™óE6Ó³¬ž-2ª.¹Ù?UÕP³»4ó‰¨¼N]tfJU£…ñ¨c'ÎŸõÇÍÜi…¥dáOå%|£u|R´cG6á`c7Ïº†¥ûl«²÷ „,õSéz¾©Êÿèd»Ôøð„´ŠÜÀÝÅÆFÇ‰Ê}ó7ýâ×œù›š™ÏÒcŽ™ÜçÄcb–«{Âÿ³ÆNs?½ŠÈÒ‚›£±t]i1Á¦¨;é¢ªÙQHìäx‚-Fm-à3Vdôs |FÅƒV“W¨·´«Ö0€áâg„Ô·¯V}ƒoó].tú5TÊ”:Go·>_‘&²¶´x}w¯ßß­¥Ý--îÈZª2
%r­¥gñÈ„Eh*honhv3šn¿C'¿÷®Éˆ§e÷sÆ0Émº=çÐùÖ¾'©Ôkd|âÐ}ÖeƒË„jÇ;?{Ÿ÷³wl*9÷îý¬Ñ^½B8·Jc³=’äæx=Q	)ãÓF,^Ò[¡¼MOeÖNS0I¬ž˜Ì>¡Î1ÿ(_!©Ñp<{4ŽÜqöBv£=ª°¹Úæ.€X®"	*gfŽ2Îí¬L,¤Cæ¯ñ»Õâ‹†­»3ûùôsæ4îüÊ¼§<Ñ„!4¡–·~öþ"ï8Ô²|Y†P&—ñp4Ï)JÚöîÞžvYÔëò±ŸpP¡p$ÌåêenôwnßÎM7æ¼÷Þ{ïÜ~Gñ‡˜û– È‘R–J¤Øé©»SÒ;–mƒ±±sž”êÈáºÒÙÚ¢íuGW%Üço'î©Š¢@T"¿r®JçNM³(Í|º1Øuê"U®?ñBÅlYS+¥ü×o'ÒÃþkãEêLY4—Áu\ÜuèhÝüÐ&Q™Z]<zå‡Á÷RüÌƒSÒgø
ij8VRQ¶‘|#16ÂVTÌ¶’ón6AzÔ}äàQgßÐÝTêîàµÞÁTI)ˆÊk«D£×nŒ¦Ò±Á+}1²¶zöaLéÔä” H´ZÂÏÞð\~wº²¶¡ÔÃBÓ´ûâÅ®î‹étšØúÐƒ`óüôWÔ™ˆ/wÊo>æ›v‹&þôWwˆ{Å?Âhp_Hë×Ð{ƒž™ÛÖ£[¹Þ#ØÍfoRiéÑÝÓø:ÂWjˆíÝ®LÅU»Ëo2Í²^‡&ÿ÷åk\3“ŒÜn·7—©ÛºoñEB‰{“Y£Óé1
C·£mÁ :â1»ãJ‹EA8ù®
]·_Kˆ…qZ¯•ð]rw›‰Ç“B™lÕAL2JKäaTÈ%L(Ž+I*NH´Ü
—4ÏÅ
C/ßŸ$0CÄºñ:1:“J[w[ÎÂ—UU8ín_D®“EùQN*‡B’­¯Òb
JÝ¶Ü«$”©¯Ùé‘ÛôòdÀí‹+ôj’ÛûL’‡vßjp$£¡¨P¡“¬Ç™›ZB
—ß¡&5§Åj²í:I‡}aB¥•G­¦ ©3¨âx˜Ë”$Ù1/b9_‡Ãç¡’ËOùøãeÇ©NO<7œ5û– ˜!"÷¶6_w$¨X¡4ñC¿è¥†R~ú4Õ÷ÊÙPB°£®Hiaº†‹¾qòLã¢G+ïl=i¾vÆÖvtäÈÉÙïÌ×ŠœœL%&û.ÜK¤Ç=e{ù'uNT|çl9Z¶ôØ•Ö¾AP/tf&Éöžw(¯;tàÎ)[ó7§¤5‡lü8©ÔûåÎ÷uJÇþìØ‚²ÃÎÖ—’õß;.¸ê9ÕÜ™(®j|éˆºTÀ­w(QT5w{¥Gœß=Ö—-—1z°î‡ÆKu‚Ûgž
ekðºöÝU¤:Ùu¼*=:Ì’%3uJ—Û(²ŽNTÖÐTÕyÎõrŸ‹àëÕšNíËü€ªØoÚç8qì[×ˆr÷‰SµsƒM§[S>W   IDATY¢¸0ÿµÉ)2Z¸cWýK¶&tºyxPd 6(ò«;‘|á…=ò7Äý“Œ}n§'*3¸zWëeÁÄ#Á€×Ë¥¤ÎÑÓ¶RÀeN³Ù¡ùº–|7þeÖÈÌŠYD6.pû¢­Ëß>—I­Íij1=²ž%CU˜eÆ,Ü2“[wW˜	™Jkè~K£"ï©ß·£AŸ/
‡Â”P¡Õ»ÞÊS0•¡	Roáƒ™­.óój-¹d…T8D“úÕ‹®R!J¬ÔŠù,C"×hâ4-Z’/—•¨U÷ÞÐa§ÉŒ„Ïø5ßÌƒM²eVe›#n46TX¹ƒÑØ\™n@ñ€Õ”éá`o¨˜)ˆ*TÚnõèH±Bïh÷:á¤D¥·9´äúv-÷É>aþÉ(2m7JAÓîßLªÓ²3@pIË­­Œ¯q«]ëêš#Vµ¹lŒÕilp‹er¹RÛ¦ŠI¥œq›ì|=u›Í1ñ‰IµRèvÇÕŽeŸŸò¼ZÍýYa{+
¦ÔðyS×z,"+ªwÖÖV•‘ÛEDö±dÝ‰Î†™ÙXRuàô¾ù{óW6Úº*FÅë-/0ñv×ù¸ Œ$žÝ}h¿¥tòjÇk¡¯ˆ¤5ƒÛx¢òœ'ø,Púü‘ËÏÉ™P¼óhÏ›GóÍ*(.¯?t¶þÐüþ)¯¢ª=Es›©lëy³mÉ‚Ïïí?¾hÚÄÐèôü£W–ßèÜ¼ð‚ ä™Ö³Ï´.ý@ôTÝ©ëuK§—<oëÍ_¨¸Úüú›f~W È( ¬›àóëþ»&\ÿ"ÙÇÄZ´\×Þã×®Öø£#öæ&wTÆ5ëüËå1qŸ©ÁHrßk[,rù¯ÍÞŠå¸f~0*ÖußZ¾HTíŽvÚ\²8·BšaVÎMøæ©¾-—¨5å=wÚ 2Ïmñ8½”L¥sX´êåÎ¥De°ej`,76„æ¯šéÀ?…V´-÷ü—Ì
3/h±\­ƒ|ª#Qj5!§1 Ô:øˆ&²Í¥X!•JErqˆÏÜ™$E+W+J²˜Xiyë}ËrŸz†&q¡CþÇï™^z÷‡Ø˜åžì£v½¿dL<àtGIƒ%ÏMËŸœ4L¢²½õ³¹wb¹ÖÖ«]Ð©D¢µôhóœ±¢ýÖûíÀýU\±ï•žÖ’â…ßh’$YœÛ‹[ ­Ü)]a5\ûqçú¿ë,yöÈ¥gø'|Î¼ß®?{†¥^³n ïÀº°±Ñ;‚
uñºY$Æ‡'eê¨
ð ¡È( ¬[Å¿úÊSO|ù½~¹ÆùË‹d•ÿêßëÅ5Œ¹F_›B¹¦¾bE[Ï;¡dõçPeZÇ-•M¼ê¬B¹Á¿¨Q¼d«JCwïrŸ)ô®<Ï×äÚÕCËµ«s¶Mjâ¾Ê4®·Öäàâ£¦w”¿&m6îzÐ‘%Ñµ/íR™›øG×rûßîàf*×°f¢ÙÕD}V/£sugŸƒ#”)•Bëî
»P®s¸uIšvM»vå‘E\ `6:ÃÉ…‘K”\6õÚk®ÞB	©PÜãð.Ë¡„j›E»`°PØÞÄÅu|ß…J'—Ü‡Œà/}H€¨¼áhùŠKIÕg.Ý‡‚â…L§üÅWÏ ›JMí¨©ÞH­Ét*]´«¦ùÀƒô+2ºí#öàQ•ú ýå'è£,çM2éHÝ]ãÌ\ ò„àq>S=k     –õË_ÑÅO¬/ãD€MR$ý¿,'àÑ…t    àÂcb      `ËCÀ       [       Øòp       À–‡€       ¶<       °å!à       €-       ly8       `ËCÀ       [       Øòp       À–‡€       ¶<       °å!à       €-       ly °Q“ô‡      äS$~œØD8 6n“ÿº     Àr0D       ¶<       °å!à       €-       ly8       `ËCÀ       [       Øòp       À–‡€       ¶<       °å!à       €-       ly8       `ËCÀ       [       Øòp       À–÷ ›…‰þÃo?œZu¶Ï=^(”ÿO      ¬€Íðé‡S¿hndßû§5Î/|º|ûk?Ú&      °¢°>úÙØÚÓó³1f<J      <04M»/^ìê¾˜N§‰­À¦øäb>eâ3‰‰º››Ü‘™£‹ŒÆ@’Ÿìin²†éå¤£^³Ù‰=žPü3zr àQÂÆüG:†Ry?LxN9³Ÿ±)jldtèö€ÿR—ÛyþüÍ±¥¿ƒ¦ßë¿¼pzjÈsþ&ÅËlünŸYßœ   à‘vÅ{õ†ÿÆßÜ¸Ñ{í±õaˆ
ÀgÃ0B¡xxè°ÇjÛt
ñ*3FÜfk &#Í‚ŽSoŠšv‡A™oYšŠFZOasS@eqµ«eÜ&ƒ¦Ý-¾äJ[’h\·\Érg²;£ëíiŸÝç¸aH‡ß¢\ö ¸í6˜(]O¯AqN7¿ÖðLt#$•¡M§â‘ÿ¨Ù+wåìO<ÐÒ`Ûüµ„;“ö¦÷l1©Òê­R"\´Îyû-¿AhÙm
Î¦I2EfSj’[y2djhHÚý½¹pÁžQêî[.µ„ €ccÁ³þØlæÀN%b	6vŒ*ž("Œ‡ž/åÞ³éÉI¾h;qûêÕÁ´ =:@Iëö×U•ˆDE¥¢Å«NSAh˜¾IEUõ…½¡X"gC#7EÒÚCærñ G–%		‹°w‡ý—]žÁAH+_:òBu	“°±·]§ÎöÇ§	iÍÛÑZþMS¯]ìòNÒê}G7TÎÜÉéØ›Oãg.(U8jl(Ÿ½aYêÚÉ«…†ãõÅ‰Á›þ`04,Ø{î|ãìÍ™{³¯/x‹»Õ[»N×—,¸;S·;Ï©Ž¿X4šg£©±7¯v¹oO…¤JØX_."àAI\h;vS ?ïl|*çq÷Ðw=Tîœ²½çf¯ûÞµ£'Þ­q¼Ú°ÿ	7ñvç1÷dÃ+–†§ØAÛw;&³³HwTVïk}±º?6Í¶mÛ²/>þäcbëCÀð™B‡íMÆ0i°Xt|ûvåmÙmåé!ÓvgšÌó’–çŒÁ<+dºÞ[¶¥í&	#jÝê~±ÂàòkFcPÝíÒˆ£žkÜÐ½B¤Àç64!Qê»{å«7H)õòÌÌ¥¥§GŸmÓñH\(—Ï<Cyš["«îÃD|î Ö¥%×šVH”º6CT&»¯a’˜ÔÏ¤tÄk6[âÝ½+œ‘ÜÝQ´÷d2	&ö˜LF#ÝÝcP,\ç¼8w6ešn&° £>«ÉÔB9zjÁŸg†
†(½|&á`¨p0Ê×² °"A©ÚìT¯k‘tbtpœ¢Rl*1•žô_ÎL.®j=òbEñÜl©ñÐXQÃ©ãª„çLpGÙ355Ï4N¼ÙÑI5ß“öœMs¡Ê¢ÖòXŠ¸»†Iãë}U¢‰~Ç	—«´ôäó¥Ä{}çÝc•æžså±+':7d¶&2qóâ•Ø®“þWË	Êo{¹Ó]ê4Ws÷dêm—ùò¤ª£g%1vs ‘»ú»££D…žK.Ò¨P@Lç~<•žž
¨¥{–LH«e“7ÿbéF#×Ïû§ÎûÎmgG.YÏœ½Zz¾µÇƒ‘»îœ*,9¿²±ëÇss]zùüä7ê–IšÒcþN7Uf°Õ=ÅÍâ–ªO_8Z%Ê|ÔqÒu¹œ<õ¬”€Í¡ûÖ·?ýí§ßjj"¶> Ÿ)be›­Ýnµ6=Ð»º-ªüß·ejK/™\p0ÉÛ¨‹‘hºG5ÄšqùF8)Q(òµû™¨»i·=6„žv*ôí
Ê/ìÁULÈÄ½-Ïå	aš+¼³/sËoÈì/CG<¦€Ø`Q3»™ËIl*I2h6±*!Ÿ¨e”ÇVÛTkmÈ‹Úl‚ð@pko3š<(­T®'Dr	P›&ØSz¹–%ÄrnS¡&{ ’ä¡P¦ éPx6á`âáM*dôÃìð™’8Þvž*”ÎwÜ`'ïN’G/Ø²¿ÐOô›[;‡ùîýßÕhÝ_ºr³ât×¡òÄµŽ‰†ŽCÕÅ¹+d'†üƒSÒã¢©ñkñ"Rä1^Ml*gïf16öZÕÉƒUšžL…H8 —Áu\¨É&_ÛwÕU]ë¤ÒÏ%‚ý	²ñÈ3R!mh,;v%DÕ—W6v7e32R¥.ó_¦ÒÜ9æ¿6.m²ìß)å>ªÜÓ˜³v61>JTîã¿™/.W¿H–ß¸3ÌÝºtg½¾’$ÆFÆïXêÎð„´º¼„|&ÏFwVêÏŸ‰ëÊÕµ¥Á¾á	¶ò)ÜÜ@z¬ïÂ  ZU>8¾Â\ì{ýžÂºWªKò~œôœ}ƒU?ô¬téE‘UÕ¥×\*+Å%Ü$O<ñÄw$>+p |Æpc›_¥õ“²å[êB	©,hü2qŸ')Q·)ïqB2¢ÄJƒ<ß¶…rƒÿ}=åkiö*]3#;ÔŠzpÈÔŽ—v6+ávÑØÂÍÛ­•-ØbfÕbRAÆÍÎ Ò¦ÖHL¾p\A½‰Ö WÛg‚á[õ
–vº}Q¥^¾¨%OGN«ÝŽÏˆÒv«G“´6)}oŽ$¢îfcTkQGÝVO8)”k-‹N.N†¬Í¦ˆª;3ô%45YiCÏš;‰Å‰¡ùj½Ñ‚˜»ötœ^{¡nSb&JsKˆ¹³Á%2ŠHt|Â1›oDóõø¡ÃVî tzq€;?IBHªÛl6wñùTsPeÑ1^«=ç.¥ÅáÐg¯mþó©# À#D ­>l1=38¤l/Ì}ZRk{c×Ø…cg{5¢‰Á®SwîŠºNŒØ©X,1zhêÈ)£ú©Ù¯FSÃW¯üd’ØÍ¦Æ'vÔô;›ôÜäÜµ/T‰Þó0\/Ü‘c·rÞT˜.}ßš>Ú–öëáïº©¢ÊÙûµ¸´¬Aµ˜Ë   IDAT(ý“XŠ­åÌ9û’MÝOK««ò/`cCìŽ¦´ZÓ±áXñW÷çliÁË;y¹´ÏÜ;–
þ0(¨3©EWW
8R#þ¾T¥¾z{¾K0EÝt»‹ö}¡"_ïvb¤p²´º
éÆ&¢iÚûúëÛ¶û¾­‰¶|ç' ŸÉ°Ýì%¸¯âùJ
…V·àS:ìõ&•zÍrH®Íé£dºöu3šŠÓrÙ „IR\Ë•Ì
“Œ¹|C/_>[¡ãa*Ç“I"h¶øæ;ãÜý´‘‘È¹5Ù›„2M»MŸ§®…P(æšíI¾ÙÎD}ÎX¯WÏwá‚ÒÊíZ¥Ó
…É ;@hmÜqb-¸V=©Ñ+vwPíÒä&(É µÅU»†z”D˜,¢3½ ì˜Œqm»­GŸôÙ­v»RÅ1*C›ªÉl÷iz´ŒÇ–èº5koÆ3t<ÉIñúÛýÙ%åëXNÆiB,ÎoáR¹Šg"Ò¤FNä8ømE}V¯ÞÑ3Ô#KúÌ-V“ô;ÔüÀå;-qM›¥GGíf»Ý«äÇÐ,w>)lbðÜ1Ã•…=8ªÌ0:K¤BÃc"êÒlYUY©ê¤¹¦8=|þøÕTqÂíÝòlîÕ×Ç¤»ÊXbr¸xìÎ˜)önMiâöèD"Óƒc”¯Á!‚§ô—ûõs z¾L‰jŽ\àVK ,‘¾38:%­'EDz*MD3·«@T,¤ª×¦ÆÆÙÒïðµab‰4Q
žyáÚ»	¢pG]«éÅZR43×pš¬+Ý@ë)ŠU5,¼Qs6:MRò% rÿ±wû=7êOÔ‘¢ÐJóMŒöªÌy~°°“Ã¯uŽONK©‰4Q:?›¾üÍÙo¤¤5Gª·ã
n¢+Þ«oüíßr/ûüc/8@lq8 6Ålñž¼ûýóäÁ¶‰—®}‘¥Ä2¥‚1Ÿó)6G›zÁ‘dˆkr‡.­†ÌÛAƒŽxí^ZíXÔúækÈ[~c^¦bå|KšË7¢„ÂBf›«L2‰‹W¡£ÁpœIÆnm/÷¾2Ê5¬™dÐéäZÂ™\D,“ËùH…oMÇƒ¦¯=mÊ,'Ñtß²I$B®éNsÁ‹Ï¢ÛóëeøMG$j.ÝJTZ…×ØWÚl|bm†¨ô:o³ÇVµÍ'L2eäZ¾¤‰Pêµds0gKÒ¡\ßÝ“é~ÂH(_È¡­ŒÛ—¶6e³Óía˜ ¡¶éÖ:ÐŸÝ•¨3aøƒ )OS…gÁ<m¾I†½v_œÔ«g.Cî‚BÅ‚ê¡3›¢|NgˆPº²™1üˆ¹×ŽëH‚ï¿¡•K–í#”(Ûmí~­Ahò#IµŠÈTùpôfª|0d2´G¨$!—¬ñ||–IkÎ¼^³âìÝÑá” P4zsRßqZ:ÑþÜusëuþ³¢ªÖ£úœú"²þÈ©šñËç†'‰ŠÖó­ì5U¾ÏÖ$Êöà8y0ß×¤Üwé#éòºZbüÝÑTX‚û®þjˆ­8TÃÅhÔÊ³¦GÞ¸2TPÝQUÂ½&X.%Z_;Rëï:ë9¿}ÇÙ=|Ñ‰±áI²ŽÜP¾Á…ê"Á2—¾Ú{GZ¯GÑû/÷õGêžV|úRŠúÉAYÞ$‹J”í=õ½©Ë¯ëÊ›æn‡ùìÝ®W.šmÄ«µ%l
€õûôÓÜwÂWÆP?Ï>;v[AÁ¿qv	ÿàéßŒüôƒ½¾Ü"«ÊT†î·4!ÝGÅb>TH†ÝFS€Ð¸Ú–©È‘;ÍÎ(i±-þ]¶¨ü?ÈÂ)¶-_õ2åòy»|f;\ÃÙnNê{š¹½I†!ÉeŠ¤ÇÔÒng\®"…Ñd’‡CqšHäŽ¹ÌQ!(o‹)"äÖH•L0¡lÏ­"äƒ2i7>g—i4$Hª6åz»IAí3yšv±pf\w^U²`(Õ’|/†P\Ì0yZãB¡d¦¯…03Þƒ“]\Ý¦ó6Xíbm÷vhAAjmÝísmQ­ÐÌSTr–LFì»Ÿž™ Vè.½b&ÉWd”Èô9iÙ˜y#S[\6õ|¬#ä.ê¡”ÂpR®áNuˆX>+š{jw…d†Ï 2gO(ž=%Bî”™!3k=Ÿ ŸQé¡Îƒ'‡JŠò”÷d'cé²CçOªKÒÔÀ0QNJ»T%TÐÝ?Jå<þur¸Ë4N6=;3
@PRZ:1N¸@D@L%b)bú¶ë°;–ÎÔà8<Z((,Ûo^P¶ƒÅDU/Ô&ÎôPÕ{Hô‡\éãµDÅá#™Â-¢B‘ž˜¹	Ùt*ÍŠæÈ¤»Îö±5Ç÷eR7AT$­¨=P[ÎÍQ\ÛXÓß14žÚC–ðU4Ò¥5²$ìÄèA•:÷¡*7:;_,xîâ`Ñ7lõ$òûŽ¥úýƒwâƒßÕ¸g¦¾9|ø‚­~Ñp¤tbŒ"¤y{ê
Šv=¾ÿ™¢T:Ôvùê`ÍquÉâŸ=‚í5ú£#çÇSµ%È^7ŠŒÀúåtÇøü“Y×_MþŸÙ.ÿú{.Ýà^H½üÑH„ù§qbãø”Ã¥šÏÄƒv£ÉKk\=Ëåã£!Åa»Ù#ï6¬0V€¡“IF,_þá,ÉH ÂåsÁƒD©×Ë›Ýî°Ò–Ý8x)R­LFd:‹ZHS^&IEÂI‚Ë7˜h8gâ4!ŸÝ—31C&+f
mâd$ä…%Z×¢j!\“\kéÕè|¦f“7.V*f—Z.Ð+šÝÞˆnnQ±B£‘ùìM;í|9Nu›­mÕœ"w«™n,4·(³–Z&ŒXƒ¹§¨¬ÕüSTò~Ê%¤Û‰“r­B,Œ÷ËÎ'ÀgJAQå>SG]žý§NfŸøîµa¢J]48&­Ú£œµNí?S°ƒ¥z_îZ8B »f"=Ú¼Ã²²½/:$"–ïÁ‘¹Ñ+m8D’…{Êýç®V¯)AÄ3Ø‰ógCDý\ÈÂR²pŠŠ§ˆrîõTl|R´cG6á`c7Ïº†¥ûl³%l%d™¨oœJ×ómS>Çdka¤Æ‡'¤Uä¬lltœ¨Ü7_’aÉF3Òc7:=ÔŽVGºo<‚òœ1nýfS_Å©WóTrJP“Riþ‹ ’V’EÜ-QòÌ¾†›/_ë­j«Bˆñð}ÆŠŒ~Ž €M0Ûc[AÌõ—Ÿ/*×ý§¢}ßùbí|b®Âøc¿ÿƒÛ/$î:ê35<×zz-ê¼O5‰‡¬--BßÝë÷wkiwK‹;²üˆšŠÄ…²åkBðƒD…&·c…L¥WƒNOv­É°7À(µªÌ¾HH¹L,”(Ý~O›J¡qp;ášXøêš™ªD&ár±L!cvOReÐæiÊ3TÐiuGI®¥o“GL»w½‘uÚÌÊÔ­˜ËPæÎ·×>FïýgÈïÒ­¯\ 9#r‹«]v:Ck«ò;!“pÄ½î¨LµB¦µG“\µÒ÷v>>+'¿µgß‹†Öƒ†Ö_øFó©·S9‰Eaeý¾êÜ{ìkÆæô/Ø÷­gSé¼ëHËËÒo÷ÇD‚á³mÜšÍ—‡Gƒ‡ùM;{>I½á˜*"¨*]\¹¯‘¼ã¹<8A ðX¾¨íEª\b¾¤ˆ¬©•Rþë·é‰aÿµñ"u¦¬F:<Ûqur×¡£uó]€Dejuñè•ßKñ3NIŸ)ãZ°ij8VRQ¶‘|#16ÂVTÌþ}È»ÑLºqæ† ö°Q¨n“MºOv\IÍ¼eÓ‰4`¬ö€&YûBÕôÀÕþ÷gµìÝÏåw§+k+|lš¦Ý/vu_L§ÓÄÖ‡ ›Jú¿œ–ÍôQüYû§ÌG¹Ÿü¾ìËÖïÿß&#qoèhÐëv:q¹ÎÑÓ®Í[ò“ŽxMFk„lïÎfPµ»,ñ&³Ñ,ëÍR2‰<!Bá/÷˜>ß %j:Ì5Šã4Ã?õ•ŠG£—ÄÝAK+ãGÚ.S}™íS>«Õ¦„-^"IQL´)*f¸…Ú®tYÔD<NÏt¡“Ñ$!Ï+b	Š(ÚÜ—þ|5F¡Î>¨5I¬V¾ò„ÎÒ“)@¢ê&åV“Óî“·ë#”kJ_³"g®¯$#áH\¦”­·-NG<Î XÓ­ÕòH Åî)lª{|NÍ¦á…$Bd«ÜØ+06™)u÷ìH":
SÜ
ù|É—ü·|Pvçà³DPZ{Ôbà;Ø§‡œÖþÜOJªê‰Ôí[f/ùj«¡µ"qÕñæ²´¢b{a¨¸¢áh«Èßƒ#=æ?s²P7U:N¶N¶Ø7xâ¢ë†ôø|óýÈK]9aí‹Õùâ@gf’lïyçòºCîœ²5sº@ZsÈ¶‡Ë7RCî—;Ü¿
û³÷jAÙágëKÉúï\õœjîLW5¾tD]*àÖ;”(ª*Ÿm±¦Gœß=Ö—È¼=X÷Ã‚
ã¥Î:Áí3O…&3S]û¿î*Rì:^•fÉÆ™¢¡ù7z¢Ðcv¿;E×O|;S¨†(¬þÞ¥Ô–Ù,;5™˜œJÏ¿OM"Áê1SqåÞ½åÇ®^	©:¾º ÈháŽ]õ/Ùšªqù6ŠŒÀ=&-™þï±î:¿ÊlÿZúñÿ“ Ö/3Þ#èõxF®Ö9né5ùâ®ypóD•h]þö¹¾|ÉG¤©ÅlôÈzUá‡ºðu:Ú,y#ËPÞ–Ýæ™gl¸Ma¾†L"KH¥VÛ&Ç½V·/×èH‰‚kÝ3ÙÑbns–hÒCÚ\se¢žsÜÀïŽPBR“ô4<m0µÅ c¢^“9@‹™h0šT+éˆÏ—(™Âb‰L¢±ôj4sa—¦8ni¸Š{ˆu’(zUÐŸ}§S¬MAëüÇW›jõ^LÔg÷ÑjG&@RÚµa“Ý­QX¶Ê‰Úñ–zõÙ„0î4SLæ)±.‹~¶,ê2k]æ|JÖ;œ`‹ccýgÛFgžLQTõì*ívâ']¦¶B@ª®°ùg™V”Žu¶CõµeÛ÷ê+sÂ§cC7=]½ãÅõÇm/V'ÙÎcçÎ\é8}èhìÌÉcGöµ¨ÃÃi¢òžçmÞ”>äòóGr&ï<ÚóæÑ|³
ŠËë­?4?…Ê«¨jOÑÜf*ÛzÞl[²à3Ç{û/š614:=ÿè•å6zòÆ³lž’¤¯å   IDATZÛÕÚìKÁöÚ]µó‰Êž×ó.$xªñÜõÆœ÷¥õg^¯Ï®Ïüú›f€uÛVPÀýÿÇ‰‰¾ø¿®y‘õv²äËi˜Cb¥Ör«[½Â°†
£b]÷-ýâ´B¢jw´Óîä‚q\¸am1yã¤ÞáÈû.‡°ô’IF(–‘ò<_ÊÓâpÀŽ&u¤ŒX«L÷y›B¥öÿÌ@dâ1šb}w¯"bn±Ú}E8)ži'ÃölQ$sþ¾/n¥	åúÞwôŽH×3:³y#r×;þl·†ò›¬NŸFiPÚÞšY@nðæ—iºßÉgÕ÷Í­W¢²ÜZm7†ôkýˆÛÈÐlX±¢}™U/·N™¶{H›w	‰Êñ–jÉT±ÊöÖ[s[v½“[{–»þ¶îÞE¿Êt=CºkÍîáòçRGJ!©~é8Y½3Û>5ì8¤Õ$
w¨[ó×(HUG_­Û.±ÔÍsžë©Â{Šÿ1u§?¸£îðñš’É‘ ßìòÅ'§g>”5¼zVë"Ôÿ[Ce¶|‚ äYãq64Y$*Þ®?é(ír÷R5UÛEèâ÷½#¨PoàÎJŒOÊÔ;º<@Ÿ±"£Û>b?! U©Ò_~r3¾Nÿô£ÞoúOÓ¿ø¿Ö8¿€üƒÿá¯ýÛ¿¿eÒT8ÂJù†¿cÏÖÐ˜}Cì^B×®!™°µe®G<Ð²Û*t—E#¡<&{RçjÏvv`â>“Ñ'182ýM¸&²ÓlöD$úîîv¥„Žx¬I[»&ÿ³8øÎ'NJmÑ2N£5_Ø³@(V\-¹üñÝSZBþa.Ù™˜¨»©9¨îî5(6r:˜xÀlt†“‹öƒ‹–<Åd« Ãæ#¥ïíÑ‘k;÷û| Àï:vâöÕ>¶vÿ³¥ëþ­&=vóµqòÔxTýòWtñëû€€i›pp~ûÁ¯™Ÿÿl3ËäŸ}‘x¤mà(B2`l°Ó:‡M¯”	ùŽ$&“‡Ñ÷ôèÑá€Ø@Àó	    k³€CT 6ÉçžøÒïUý1kõ;ÒÞ•¨ÛmI×º»".æ+‹ª¶î¼Ïp5Áù   €=8à‘¶™=8      `6Ðƒãs       À‡€       ¶<       °å!à       €-       ly8       `ËCÀ       [       Øòp       À–‡€       ¶<       °å!à       €-       ly8       `ËCÀ       [Þc lÔ$ý!      ù‰'6€Ûä¿®      °Q      €-       ly8       `ËCÀ       [       Øòp       À–‡€       ¶<       °å!à       €-       ly8       `ËCÀ       [       Øòp       À–‡€       ¶¼Ç Ø,¿ý”OÈ~ºhúç·mûã      l€Mò«ôo¿îJýâŸ?ÉûéŸV?nÛóE      6À&ùé/¦ó¦â/lÛ!yì¯?ä^#ã      ØÔà Ø$ÌÇŸæ^ðØ¶ë­E•¥|ÆqôG4     °)hšv_¼ØÕ}1N[€‡¯P¸íG­Å\Æñ×÷d›†IÆ“ ð°°1ÿ‘ƒŽ¡TÞÓ#žSÎÁìglŠº=à¿Ôåvž?sléï é÷ú//œžòœ¿I±Ä2¿ÛgÖw'  x¤]ñ^½á¿ñ77nô^»Fl}¢°I>]nú§ü'ÙŒã?w¥¸Œƒ{{ö?‹‰û„IF£I±\.Þ¿9ï/&	‡ÃÁpRah×’›µq†ò›|rW¯E¹ŽsMÇ£-‘Ë%÷²›L<¥Å¤\v.2ú|@ Wºn¹Ô’¼³xÍFk€b‰ªÝå2,9dšŠÆ¹=Zé°¸C²ûµÓ &6<ÛéÍfìT"–`cÇ¨bÁì"²ÁxèùRî=›žœœâgš¸}õê`Z ¤uûëªJD¢¢RÑâU§© ?4Lß$ˆ¢ªÆúÂÞP,‹³¡‘›…"ií!s)X¼7,!€\ìÝaÿe—g0AÒŠÆ—Ž¼P]Âß$lìm×©³ýñiBZsÄv´–¿GÓÔÀk»ü£“D´zß‘Ã•3wr:öæÅSçø™JUŽÊgoX–ºvòj¡áx}qbð¦?öž;ß8{s¦ÇÞìëÞânõÖ®Óõ%îÎÔíÎ3Cªã/æÙhšºézåBˆß¢´¬¦ÉØZOŠ¸ÏØÔð[çuîÜ…²ªºÖ£úÅ™kzè»jv&éîW»UògùÛ€HÚ¾Û10™}S ÝQY½¯õÅêRü8zX¶mÛ–}ññ'[€M²m¹é³?S¸ŒÃg(þö_Nr‡úÖÊ×ß†¦ÃÖ&SÒÐëÒÈf'1ñ ÝÈµã{ÚâÍ¹6LÄÝÐ`.ý@(×÷,È’a»ÙNòy
×¨žé®Âµ­årŠ`¸ˆM‘{|q…Þ6{¬ÜÞ$…2™8góÉ ±É.¶ømª¹½çR³1¢íéÑå1k?ö™ùã!«Ñ«põnäDçÁÐ4#sÙD|™’!»ÑWuÝRÄ½F£É,Ë½î¼xÈÉïQ~ù›ŽŽ¸Mv¡eÕ<h"‘ø‘ïGÜ]Ý´·ñÉ'Ÿ\ô– x´	JÕf§z]‹¤£ƒã•bS‰©ôÔ ÿêpfrqUë‘+ŠçfK‡ÆŠNW%<g‚;Êž©©y¦qâÍŽNªáøž´çìhšU5oÀRllÀÝ5L_ï«Mô;N¸\¥¥'Ÿ/%Þë;ï«4÷œ+]9Ñé¸!³5‘‰›¯Ävô¿ZNP~ÛËîR§¹š»'So»Ì—'U=û+‰±›‰ÜÕß%*ô\r‘æD…b:÷ã©ôô” ¸P@-Ý³Ôø`BZ-›¼ùy6šº3zWÚØù×5å‚Ø3æKû+N7lÇÝ}±‰‘Qö™#—ÎTISƒ]':]—+œG«ŠÙ©!«}åUÓÎâÜ¹W¸2RõéG«D|¬åï8éº\NžzVJÀC¡ûÖ·?ýí§ßjj"¶> Ùþö›æ;(3Ó|‡fãù©0_F 
ïeÎ5lU®qøô‚L<`·†jù‚±D®kkWÑ\‹\FÊh_sKPÓÝ­#7·×ñ9}Ñ8czîiÓüTR×“›fð§G¸ø‰—ž´µûì¼žè<$J­A©
P¡hþÏ“_ˆQ¶T\"ÑT^“/Wks:ê0|FB¬<\'3‹„XÕy—khxˆ{ñë_ÿúåæEo	 à$Ž·§
¥ó7ØÉ»“äÑ¶ìï÷ýæÖÎa¾ûFÿ·du'Z÷—‡®Ü¬8Ýu¨<q­ãB¢¡ãPõ‚¦;1äœ’M_‹‘"ñzlšûº5gïf16öZÕÉƒUšžL…hB..ƒë¸P“M¾¶ïª«ºÖ9H¥Ÿ/Jûdã‘g¤"BÚÐXvìJˆª/¯l:ínÊfd¤J]æ¿2L¥¹sÌm\ÚdÙ¿SÊ}T¹§1gílb|”¨ÜÇQ_\®~‘,¿qg8˜»uéÎz}%IŒŒ/Þ±Ôá	iuy	ùL¾W4¬ÈÎHV×½×&&YÇ}&(}VoÈ¾,©¨­’Æ&ùÞhlzŠ‰žíô
·Áb"²ªºôú@‚‹a¥¸fÇO<ñÝƒ‰Ï
 ›d¹!*ÿ–ß™^<ó§ËÍþ»J(“+t
 Êã¤å:Ýân
b™\93'sßê0É$#–ˆ…tÄmtÒm®lç&	E¹J¹pÔvZ½Â6ÿ¨anß’ASƒ9¾ÁÑ9k?öììÄ0”·¹ÉC:zm*ÉÌû–f/÷~Õ6t<B2ÝLÔ"&•rÂ¡˜¹€#4în	$	"´ûi;Aê]-I»ƒ6ôtëäB†ò›ÝbÓQ±«5Óý´©ÂCÈ´Ý~‡z¹¬ã7ýff»4½ô- d	¤Õ‡-¦gæ‡Ô€íå¹OKjmoì»pìLb¯³£F41ØujàÎ]Q×‰q;‹%FM9eT?5Ûß;5|õÊO&‰ÝljlpbG­A¿³IÏMÎíÁQûB•è=ÏÃõE]½Ž5ÜÊyWPaºtöy|‰úh[Ú¯‡¿ë¦Š*wdï×âÒ²¢ôOb)¶R”3çìK6ug<-­®Ê7Ú€MŒ±;š6ÒˆMÇ†cÅ_Ý[œ³¥…/ç¶N—×©1BåJP¢ê=_-!øÛc2<®½>E”î:pØØPY¼Òm°;1Ò?8YZ]…tãáá~Ió¾þú¶mÄ¾oëD¢-ÿ— <ü¤Â –o¼£2ïnöæx!7øýíŠ¹53TÀlª\.I*HÚê‹|OŒdØmuËÊÜ€ƒ›f2%m=zEî¸™@˜!ÊûT~dõc§¹è%LÉTäZ©eJÜí	R´J"æ{ˆ„ƒ”XÙ&_}y†NÒB™dvðP"3ñ$éŒÂ“¨]ïøeÍaMo¯ßc&Î¼ÕätTåñDå†žÿø'ä|KÖÜä•»ü«*v¸íÏOÿyAà¿´ýÙÒ· Å&Ï3\YØƒ£zÁ££±D*4<&¢.½Á–U••ªNškŠÓÃç_M'ü×Þ-ÏÖAà¾*½q}Lº«Œ%&‡û‡ÇîŒ™bïÖ”&nN$2=8FùÜ×«‚§ô—ûõs z¾L‰jŽ\àVK ,‘¾38:%å‹Y¤§Ò„`ö+z¨X$H'T¯åÚ»ãléwøÚ0±Dš(HÏ¼píÝQ¸£®ÕôbíLÚN“u¥h=¥cC±¢ª†…7jÎF3ogÊ:í2¾R‹|ãaß»vØðÃ;DÁŽ†“'³cRD;j÷´~£Zµ³djð‚ù›«èüÉê©åoƒÙ5%‚/s¶ÿŽ´æHõv\³‡çŠ÷êû·Ü‹Ç>ÿØ‹[€‡ kÿšÊ/,šø¿|d¸òqß1±ÆÑ‹ç\>`˜%ÓõÞ²åkñr1‚=$Öu«ôl ÃÖ†&Ïâ¡µáçžžº V9nõhe3¯mï¼oË¼ŒûZš}ÊåŠDI•–ôx‚Q­A!W+„Ö`$©RI’Ñ`T(×ŠI:b  IDATÎ‡4å³í²½Û–[Í”¡‚Þ0¡´¨V(ÃüHáÊç3ÿ±/ZQ<hoÚ¹ØB­ÓtšÕƒ
~VÒíFi¥RÌ$#|¾aXS>Âdvy~E|†Á0Ë—;Ê4m†@‹Çí¥¨°XçRó§eÍ›Ù^Zú—»—{ <iÍ™×kVœƒ½;:œŠ&CoNê;NK'úÏŸ»nn½ÎVTÕzT_9ß‘õGNÕŒ_>7<IT´žoe¯¹¨ò}¶&Q¶ÇÉƒyÚÜwé#Ü÷ÜµÄø»£©$°K¯†ØŠC5\ŒF­<kzä+CÕUÜ÷ùi‚åÒ¹P¢õøµ#E±þ®³žóÛwœÝÃWž$ëÈåƒ” B]$Xf£ÅÕæ×ß<š¹ÑyÆdvœnx
î?ÁSîþÆô{ý]¯œ96uÄy´ºXDÖ4‘™EÕM•ƒW©TUñ²·ÁÜšæjp°wº^¹h¶¯vÔ–ð0 È( lDn‘ÑŸ'>\òè¾ŸOÌÿL™ûAsïè$[ˆ72§XapõjVjÚŠeùZæ|
³—V8wa+-o½oÉÙbÄÞl¢ô=Ýš²€5‘(4ò¤;Õ)
½ÅÆ.¶ù"\^¢ÌLAs‘Œ«M«\ð´dÈí¡HE½†`øzžâòe}ñþ¶÷¸ÔtÈçóyM»v…ZghÓ«WJXø>¤ÛÃ'
:ÓÃ _Sÿ.ÑÈä³{È¬šÑåš6·Áê–ë{uòµv2ùôÓOòÓŸþüç?§îÜù—ù—ò²²;vTïÚõ‹_ü¢ûÒ¥¹·=†wàQ–ê<xr¸ ¤(_çúÉXºìÐù“ê’450L”“RÁ.U	t÷R9î2“ÆCÏÎtÿ””–NŒ.S‰XŠ˜¾í:ìŽ¥358

Ëö›”í`'†C1QÕu…‰3}TõmAÈ•ñ8^KT>’)Ü"*é‰™›M§Ò¬h¾€Lj°ël[s|_&uHEÒŠÚµåÜÅµ5ýCã©=d	_E#]Z#Û@¾ÁNŒÞT©sª²p£óÒÊ=/5Ü~ùfª}ª]ÑSµúÃmçú†'ªÕ¹™„H*°	vzùÛ ÁöýÑ‘sƒã©Ú„­ŠŒÀFäÕè¦W™yƒ58¸ÀA.Ì­ŽÀ$)þé ä’6ùšæËÊõFL2do1‡´Z}r×J~¥©ì£Kˆ{'QYz?zƒ)[x=Q¹Þ¢XØhŠErñ†3$Öò'}"“rrAÀð‰«–Ýáõ;!”*];÷‡I†}vgˆ¢Wy†Lf”ŠÓŠ&eÉ`T²æ|CÌ•H’/*$²ÕJ„’UsË$#Ñ8·(“›Œ¬¼Ã|ïÄñøÇœ›òÓÈO¹ÿê©§ÎýÅ¾ô¥/y_ÿë¹·xp<Ò
Š*÷™:êòŒMŸè?uj@ìÄ»×†‰*uÑà˜´jJpÖ:µÿtNÁ–ê}¹+Á.]3‘íÞaYÙÞË÷àHÜè‹•6"ÉÂ=åþsW«×”àï%Ì`'ÎŸõÇ=›-‹PXJNQñQÎ5PS±ñIÑŽÙ„ƒÝ<ë–î³Í–°”e¢¾q*]Ï7UùŸö‚ìó{RãÃÒ*ríW66:NTî›¯Ð°d£3ÏšyÍÿ3ÍNpßåœfþ,³Ó,Áÿ/§K,Æ–.{Àï&€Èí’ñÒÏÿ«ÂÏ-šáŸ§~ûóÄ'33o¬‡X®w¸r'0TÐ*K†2¬}Îuá‹a´˜Ãd{C+™›ÍÍF¦Û±L' &	E…
y?¾/ÉÙŸÕ”z4+WÕàæ3›ƒ²¶î¶<å@ÅJƒK™;Ž”LÝžë:ö{.Qê½:‚XÃŒj¹ÛŠqJ²Æñ)™”Š$á(­#%|¦Ž2ýŠË2ñ€ÓW9\ÕîÖ(²e7VŽDÞ¹};7Ý˜óÞ{ï½sûÅþáÿñöÿ1÷ö?<û€G\bàdæA*|]vjbzÇáœ(Ÿû´°²~_UÑ­Á±Ù	ìkÆ?ë›*(`§ˆÊ—Z‹¸oJ—®S -/K¿öÃDå7†Ï¶y,;×ƒƒ•ï?ÒúÌL1=ö†g`ª¨œáš•ûÉcžËƒeæt‚O7»N]¤Êõ§^˜‹ÆDdM­4è¿~»BOÆü×Æ‹Ôûø‘&éXð\ÇÕÉ]‡NÕÍw•©ÕÅW~”~§Z4Î?ÝgO×ÆMSÃ±’Š}É7c#lÅþÙ|#ÏFÙ‰7Ï˜ýDuãÞºÅ“WýcÄŽz)ºoÜgéQ÷ñówÈ†½{v‘Ä¸¿w0URGŠÒƒg_¾ÂV56ÕU%ú/_}•¿9–¹òbïx.¿;]i¬@÷‡EF`#r»dQ‹V®Áqž¢BG<Vw\Ñ¾`”Æ=Î¹¾|§ÑI)mÝ6-ß¬×XÉ–f«É)ë±¨–®7rÚCbu·â¾äó˜dØk69)•£gå§ÏÒ~¾¤Ú‘§÷FÉÓê¥U.­"Í‘õûÚKZ,­Ãáñú±råŒ‚Û[sSKHáâŸw¢ÐªÄ&'¬l“Ç}î£hWqMæ¹,vBßÛÃE%¤DèGâzRÆWJq»Ã2]F-‡B&.âèiWˆ¹üH˜ŒD(ZÁ¬¥#\>þxÙA›ÓÓçvÜ[ øG.Öµøöé!§µ?÷“’ê†z"uûÖ‚ÙK¾Újh­H\u¼¹ì¡iEÅöÂPqEÃÑV‘¿GzÌæd¡>nªtœl7œl=°oðÄE×éñ=èÒÿÈK]9aí‹Õùâ@gf’lïyçòºCîœ²5sº@ZsÈ¶‡kÂ¦†Ü/w$¸Î;ögïÕ‚²ÃÎÖ—’õß;.¸ê9ÕÜ™(®j|éˆºTÀ­w(QTU>Û€M8¿{¬/‘y=z°î‡ÆKu‚Ûgž
Mf¦ºöÝU¤:Ùu¼*=:Ì’%3uJóo´foÃp§ç•?ËT©)¬8pÚô,t¿‰Êšª:Ï¹^îã¿ +(­5ÚW.°û_é:sˆ?÷ÒŠÝ‡Nd+	òÝ¹rŠŒîØUÿ’­©ùÆCƒ"£ p¯nŽ|ôO‹›y?KÜ·L2ú¼_D¢sÙ´+ôbXûœ+n/ö¹î@\nèñëç*\ˆ:‡-Òd4šÈÞ…!_cÓh
Šµ.Ãòå ã#!ó¦ ãqF"[òQf°‡Ý¤•mÝ½ºåÃ&	xœn_RÞÖÝ£[-a¡ã‘pÈçáls-‰+Ö}ì³áÆ†2¾‡Ý”ÚgódÐ´»Å—Ì¾iÙ˜)Óš»A‰ªÝÑf66íts+à^Û—<á‚JÜb~îi³ìëß{.Æ]˜n­œ1Tmíªf³Ý«î1(:µ¬ÙÞPa—¨~×â[åyµšû³Âž¯ü)À#ˆõŸmy2EQÕ³«ôßf'~Òej+¤ê*›–É±ÐxaEéXg›1ÔP_[¶}¯¾2ç‘éØÐMOWïxqýqÛ‹UÅDÅI¶óØ¹3W:N:;sòØÁ‘}­êð,ƒGš¨ü€çÇy›7¥Ï¹üü‘œ	Å;ö¼y4ß¬‚âòúCgëÍOáŸò*ªÚS4·™Ê¶ž7Û–,øÌñÞþã‹¦MNÏ?zeÙÖ›/Õ›	x%Ï´ž}¦uñÔíµ¦®ZSžÙ—Üó2aq½~w È( Ü«›£ÌÊÜ{ëÁAGv«—Qzni’û3çÊ¤‚AŠlëíÖ.!”i,Ž¨ÙK/xhG<hnâézWO»jù&#Îæf_î3\‚»Ÿ¶Î®Xañ÷.z¤
¿ZS˜Ô9üm+TëÌ<&Ö"”Ko›š\=ÑIF½v¾«I»¿[“§×Ä:=³7‰I2ÌjÃ>ò‘iº‡4¹¸¸aè}ÇÒU¶·~6÷F,×¹ÞÒ¹î©ëš#S;Þš_Këü1¨]oÍ¥åÖÏ, Ü…¤ú¥ãdõÎlå‹Ô°ãV“(Ü¡nÍ_£@ U}µn»TÄR7Ïy®§
wì)ZœˆLÝéî¨;|¼¦dr$è÷»ücñÉÙB²†WÏªc}C„ºãk¨Ì–O”<k<Î†&‹DÅÛõ'¥]î¾Aª¦j»cåáþbc£wêâÜY‰ñáI™zR7€è3VdtÛGì'À£*õAúËOÞçËy{œÝwir3¿ÞRô?ÿ»Ïò¯˜\Å‡‘lCÓÄª«e’‘p\¢TÈîûæï:â6š¼Ô¢ô‹/™âÐË7éV  ¸Ø‰ÛWûØÚýÏ–®û›ôØÍ×ÆÉ0z
àõË_ÑÅO¬ï x¤mfÀ1ý	¡ûËÉÛï±«ÎùÊ¥/úüç     €GÓQØ$Ÿ'z_*úûØôGÓ+?y\°­RVpo%8      98 6Ïç¶´½€      €ûà      `ËCÀ       [Þÿ  ÿÿkµ™F   IDAT Ñ^rnù«á    IEND®B`‚‰PNG

   IHDR    ˜   ¥ÒÌÞ   IDATxœìÝ@“åþð‡Ë6ä2.:ñ2¯óºL#õ„v¡‹ÒÅ¨,þE‡Ê–I'Œ’"Ã0)4
O&fGÊŒ“uH;¡]0+¬”J#É†·yc¢8å2°Ëÿ÷îD°mù~¢9Æîïíûþžç}^wmÞÅÅ…µÒæ×.o   ¸ü¸2    h‡I­ojók—·   \~š*I@    ­™nnCf  €>}’    L¸’Ìì™   ÐXVIBr  €>ÂµuîA   à¡O   €	&ÆIjaòvT›    /h[IB÷m    †Á$   LBŸ$    ¬	I(;  ÀeÏDHB   èpÄí.o   ¸Œ¡O   €	®(   ´gº’„ä   }œ{ë_(¹¸¸˜ó0óï	`Wuõéi†l0þ°F—††ÆÆýÂ  §¹¸º¸1ºd.n.-˜»:{@/À…$$è](Q62Ô5ÔÕÕ#
83Ú]©c¬»nhu;mrÜÝÝÜÝ]n®Øþ€Órg ½•ˆ¸`TÏý0 èÍh÷ÆPWO?5Œ«*ÑÀÝÕÕq	œ‹Y!	¥&èAT7Òêõ”ŒÐ‚p9â÷|jõŒB’ÐÝM(pÃœ„K¥¶–û§Õ,ÙrÝäÜ`[”Š¸xd@›@B›¡ÐÒ
KÐãš*I¨³©Õ×éôõ úÚ)¢eŸ~DB7‘Ð[&èAè“N§®®¾FÆ5€¾Žr’¡®¡ŸÈ‡Âõ^zC×W¢¡¡Þø_]C#wÝØ¯Ôj…®®î®.®nMÿ¹»º
ÎKššÛ˜©V64·ãÕèê¨} 4
Ü(*1è=t:}ÎP«ãöw-z 'a?‘P$0'`nHêòW€n¢e©º¶$ hÏÕÕÅËC€^JÎ¬±±‘R‘ñÇÐÐ<$µ€ë‰ÏÕÝ¸cÝŒ—®|iï°_Ï]ÖóW¨ìd¨kÚIvuqñ	<DBúéÁ¼á¢©ªá_!	zíoÔêë @Ç¨žDU%N†âQUuÕ\ZN×AùÆËCäÙ’eÓ‹BRu^[«£¤ÅßBaÃÛ³Ÿ—G¤„$èyhb 3‰nhzs&UÕµ•Új¾À«ŸÈÛSÔÏCØýB©Å¥¥ý0c)ÑÇËÓÛÓƒ9Bô°šZƒ¾ƒC€¹“œÕ{ªªªùÑ}}<=ü}½ìÑÅžž¿´‚^‡‹JÔZ'ööòô2Gé,$1ôÝ;CB +Ü]==œ¢coßT«Ók*«õ\ •ŽÄ^î4«ªjsò+O—ê/TÖŸ×Ô]¨ä~ÎUp+úº÷÷á~ˆéÒM:@z•xÂP‘Éç¡f¸Ò
-_U¢&<1Õ”DŽˆJCk=Iàhe «á·žR¦©ª6†ªèøzµ?h_ghÜõWÕW¿k¶ï«8}Á`Ñ“ ¸cªïíW‹¯—{‹m3†ÞPw¡B[S«ç^½ŸÈ_ìÍì!	zzj@7yÝEBôãvœÆFv¡¢R§ã2ÊÀ ªæ´­úœ-«[¶ùÌ–=e5úî§ì)r½w†ßËäß6„UU×ž+­¤+"‘°¿¯]“ˆ‰Ä0TØ¨ŒÄ  º§Ÿ‡@èŽ¡&¡¾¡±´L£¯«suu4À×ãÒqŒ*kVý¯$m»šÊHÌv¨˜´èÎ‹ïèíqÉT®ÕÎž¯hhhº»ø‹Ýì66B8Z}}CUeX €Žx{
Ü\‘“ì«¾®^]^Y__/pw<À·u¤úöÁw^ùäÌùJ{uŸàã–1xÞMý[÷¯««?s¾ÂPWïææ&ñóqs·KM!	ª¡‘i«u0 lÅÅÕÅ§Ÿ›#ûÑé¥•T¶éç!·ÒS­©O9¶OYÃìoæ¯ÌgGJÄ[ßè-•”jjjõô–úû‰íqJ¤op¨êZ ØPcC#šïí§¡±±¼RKqDìåA5¤Ö	iÏ!í5ÏrLB"»jƒŸ?ôë‘ê–[èÍÐ[òñò ·W¦©jæÛ†¸ÔhÞó66bãÝ¢Ó×ñç5 °!jsÑáPYû(­¨¢†­~"A¿K%[÷ÍùÛ–åæw˜’òºÙËŽ¬Ï9ßúÆ~Þôöê¸1ª˜­qÍm¬ÝqmOlŽ’~eµž Ø5º¹ºa«dKšªêJmÀÝmè@ÿ–R¾áñ·O}þKë9×û¿½@*4µ†Ñöåô¹2ÊÊ>^ýÄÞžÌvºI¬ÓnIIöU±ËÅòê]£_sJÚCÊH `7în®^ý0Â¤ÍT×è¨‹²‘40 e(íZCcè²#¿sP['¦Ê<s“Ç¶üJÛÕÙRjqó÷óñ´Ý8“INjÓ¡ÏÅeÙ÷ê?bjµ¢qØbæd¸sÖ¢Ó  Ø——‡À#Ø‚Î`8_ª¡+Cú‰ZížrìëüJææ^ã»é™‘-¿ÖêÅêr
&’ _š˜-`frFe:Í‡‡¶¼w¾±ÔUÂ,ärjÓ«™“©Õ£»  Ø]†¨µ‘2cÿžâÖ	éÕÿžuž„D¶þR±"«¤åW‘@âïÃxYn³7‰äŒþ­Ø\SW[®¯zÏåÿ˜…\ê«\N¼Äœ	×Ì†CÚ ÀþhUC+Ý£­©­¯oðê'j=¦öç¿”¿öY	s2+²Înß{±w”—‡§‡Þ<}fINç¸F•}|'ý‹’â¿Ü§1¹œ}Ÿi™ÓÐ¡Œ Ž¢Ó;{ßÇòŠ
•ªèàÁBú9­RÑ¯Ì™446VVq‡ÙøzµÜø»²:*ísJóÞ:Ypâb)þmÓG°Éù8; Óy«àýÖ4méÊªê©Â½–<s¡ð{ô©†);™0pGý£Œ B…ëººgë™¤×ëwïÙýý÷ßåçïkhhã\]]§NvÓM·_,:âäöÐV×ÔGE4aM¿F¾yB_ç¤kòZCã#«Oìû×þä$B;Õ“*µµUÕ5>^ýX÷ $9—oU?ï=÷gë[
5g·yàNý'Ì.?¹¨·4Jîe=Íù÷ê à2£3Ô;OHÒVWgf~˜óÍ×µ:]G÷¡ØôÛo¿Ò‡‡Ç·Ï‰ˆø»§§-c7å!Š..Ì_|±ŒôïŠÎ;õ¹¤ŽžÕð]éüYýù_éÍWQHÒÖxöuó”58ºÍ‰èë9‹ÎÕ\hs»ŸÐû¿¾Û¼-+É6Š†5L/d®=y@,U;5Z p,±—¨Ç7PõõõÛ¶}±ù“++-ëGìëëùðm·ÞîêðsÒ•k(ZèüÅž-!©Z×0îIE¹ÖÙ{M»ÿµf‚—GSõ«´B[^YííéáëãÅº!É‰l:ôù»ýÇäŸþoÐgëÒ™…F¾Ò8üyÖsuÕµŽØÿ(.VíÛ÷ë¡BÅ™³Å¥Î…¢•Ž¸âŠIÓ¦÷ë¡}2 è)žA“Î¯LYqì˜²ÍíÓ§_#—Ëýýüüýýé×2R^þ×_öîý­Í=ÇÿBÂ@‰ÅÇ8[­¾¾áìù2W—áƒû·¹ü“³oluºþÚ&½p_àÒÿÄ_§âÜÉ3¥´§8ÀßÍÍú9!ÉY”é4÷óTMéù®Ìå£AÊQu2K4ºökøÛ&tÜ2Ö%$ÊIÌž4šŠÿ|ôÁ?~ßÑ||Ä÷…?8;ôvNK¯Õ2//¡¾x÷†Œ/NOž¿ä®±ÝÚù3ÿ…‹ûf×Qýˆ·ÍÙÑ+jä~wR;xÊm7Lh}o‘òß~õËyÏq7Ìº~ˆc>\ßF	‰rë!ß}ÿÝÚµoéõÏ1000ðî»î¹ù¦[|||L>¤¢¢â»ïv~þùgJK[n¤F·Ø§Ÿ9óZæÚšÚrÖ×§_ß¦3¨5uãŸT8mo¤6D—ƒk'J|›¦û…òªŠª?±—W?f-·%/.eIN`íŸ”îè¯4‡žL¾ÃõWf	—Æ:Vwõc=¤ÆÎHªÕç–/{áàÁ¿:¹^¯ûã}§U§¦ÿmæX'¤?‘½2aé'
¿i×Œ÷¬Ø»}ûž?Š¼‚®‘ûu•GôåÇ;­Ö2O?/k³‹æÐgonÜ™_9rö£M'—òßÖ½žö¿‚£…ür¨aÒÌIý­|)ýÙ3ÞùïoÇjÇÏºq˜õkl0Wcc£HØ3n¿þú«·Ö¼Immü¯ý{lÁâgâ&ŒŸ ‰:z”‡‡ÇÄ‰çÎ½ÏÏÏïð‘Ã:c&ƒÁðãO»†>bøfm½mJHîÍ]¶ãÞ?oÛÁµ}ÅëR'lž7èÅðAOÊë¶í®¹`»ýèúV¦­Ÿ3Í—ÿ•VøUÕµÌÅÓCÄ¬ÕÃ·ïsÜþý¶ì¯˜³ú«ôHËaÿÙ[V´cÈ=³õŸ3K¸œÝØ8äŸÌ{
s8{ÕváÂùÄ¥ÏQ%‰ÿ•*FWN¾J*.6¢ººªø´Š
Ýþüƒÿë/¿ìnxëØgâYÐ—Ÿ>^\a`¯!#Fu¸í×—)<ç7q¼¤‡méšöDvÆge7ÌdŠë>á à^…›?Û{uÌÌˆ‡oÈs×æM{®y~–´ÓoBò‹Ôä¯NÆÌ_ñR˜—}¾4í‘ÏÞLûî<íÐû
ª+g¯JðÂ’Y#~
±Cd]}ƒ»›£[ÜŠ¿ÞI_Ûòëøñ_ZæëkÁ¢rÇw^sÍŒåIËZšêÞ|sÕðaÃGŒÉì©¡±Q§ÓS°h	—ÚÚúÌÜRÖ«Ð~cÞ¾g’‡Ð>}(úh®Öî!ãè6§ðÖþ‹‡ýwbm©ïooofÁ‰Ž¹á Žü³!è'æp{ž©v°Þ\µ²%!]ÃÍóæ=Ö¾ïÑÉ“ÇW¿™r®ä,]ÿí×=_üï³»î¾9šöè+ßÜUÎ“žXõêm?ù¦&ƒâÃ¥É;ÏM|øõçïÕE8øvMÆ·§Í>W°@rCôS]<§´G²×®ÚPPÁòŠ	/Í—w?'	¥7<ñcá†¼?»aJôä»"&ï]SðÕ7'gÌï´Í­üÌérúg€|œ¯Ò‰ï2Öl:j`žç/·ÍÒOŽæ¿ÿÚ*öÂ¢YíÛæ´2^Xºó|WOZ¿6úžµÜaÈ½/'=2íq¶àøtN­¦pÓr„ÿ!7ÆÅY³cÖ¿ÿÔ7þõZÊŠ_ý…‡X¾<ñ­·Þî¨©Î&ju\ÿQ¯~Â–Šû´½nüÚìäþ¥½cª˜+IžBmUåý<¬\M $õ¼oU?+ÊŽšsÏsµåø=øTÝ{Ì.•¿¹¨³%áÌ±ìÚ‰âÎñãM»Ysîœû÷ÈyŠb”   IDAT&ï6bÄ¨—“RV¼ò’JÅƒöé'MvÍÐ¡RæP^¾^´Ñ+§f!Ï6}$ÊßôÚÊì6.m„?rýþ•?nNÿZ¾ì®‘l$Ú‡ž<ÇÌV]¬¥•ŸM‚Dùþ)«²OqëRÁ˜®a*!éËU'‹Ë-ë®oð›8ÆëhñébµvÊÈë¢^X-7¢ó P®RœÔ26P>EÊÝQ¯-WŸSŸ?wž.‹UÅêr6ñÞ‡ïomØÐ«Û´6-›rë‹Âè“ŽX¼äü+I;‹yÉóGc–DÍlSèò<vÜp¯?·A{îÌyzÃÌwÈ(ßŽ»ÊGH½p†V¡U‡c«~›>|_«Õò×ÇŽÛIB:|øNÇõ@‰<ÆßþB¡ðùç^xæÙEEEEÌ¿6òñã-`vS[Ë5ðyõ»Ø2µc¿†õB;*ùÄŒ‡Iz=BRo¥¯7¼ó§g±ýô\éÉÄQõ–¨írì…ÆþaÌÕúvYK544ÚïT$ee¥_ü/‹¿~ýõ7u”x¾bß–Ç?ÿte¥¦±±ñ?½ÿ\¼£OÛ"—OPÐfûç7~ª\úõWÙ_\³ôÁk"¹¹à_ßmÙðõ”îë8"$ÓÃÂ„çÌN!‚SÚb³K¹aCJÚ7gø„t×²gîždò=Vì]—¼á€5Ç4úêÏ«Ëô#¥’±“.}ås»Ò–VÌ<Ws\æàÊˆçv¾i²¡ÚWp³•ÇUêÏíÝœúîW‡«©•mLØS‹njUô»:ê¥”é+?)8ýcÚÒó'=ußÕO½FÝ¾èõNzèO|¶lÉ¦SžA¿´l†-š)¡KüZ¨å(-{;qòÄ÷?4AâëëûÒK/wrç7Wÿ«¨ˆÛs>|Ä;o¯3y—–¾¼èéÖÖrqêÿÛzï½áý˜}Ôê¹%¦u˜ør_¯IßrÙn(ï¼ÏÉ¬ÓÃ!É™û	9Æ'G··©††º·ô7®v³0$éŠ\To69Š]û#ý²ç§º:®K¸H$Š|øÑ.ïïëç÷ÜÿûèÃtý?öU”—Ñ-Ì‘:‚ÀK~×ü[ö/ÝùÕæ]7$Þ>%"bò/ïþ´§ø¶¶¸%SÂ"ÜÇÌ˜Ö~uØ¸¦z½‰:Š-TV›^—éµçÎw¾šóòàG“U"è':X2pÈÄID½zÿ7;÷ó)S_|„k³+Þ½yS±(GøŸüêÃOÒ½ÆÜ¾è©ˆk.é"æ7þ®%oNürÝÚM¿~‘ôÏÜq×ßqß¬«¿YŸUïÀôÑG¶\(ò‘€.ÒLÓ²±Ósg2$ü¾û?Êlzæÿüç£˜§1;Ðêèô	Zúî=£+.uê$;¢<«/:¯6€[,]]]=D
Iô…k*I=©L§ùðÐf¡F¡¤Ñ÷V—Òo,z”Ë©×ÍwØp vIùüÎ_	
šîã#6ç!3g^Ï‡$Æå¤ßCn¼…9	¿q·Ý´gC~öæ_¦Å\üXâ6j„3muµ§¿Û”–nÌŒ¸æÑÅ¦zä´"™õê³˜EÔ¹ËŸ[—o`ÇŽ4uŒ½pà¬ÄÿÎÒk©M]Í½ƒúÇMë¾9#÷ÐÊWojéwePÿ–½ó’öÊŠÂï¾n½ß!ðó4”ýjel‡;q^¾žÚŠŠÃ?nÞ<D>žkÕ«ö~û]aÇ£Êp–ªïüxÃÑN¾?ßQ7Ìºyú$Ùˆ]WD­•––æåíá¯244ôVf#÷Ü3wÛö/ÊË¹4¿sçŽÇ_à!²ýá‘”!×Æw±è¼ÿ„Õµ¹NpÌ÷wÏ¢×&¾¤ü×Is§‚hHÿÏ_‘^oœÿOæ›™^iÝiíòÕð!‰{N;BRoõÎŸ›:©#®Ì%æÊy‚\K¯tatúqi¨q=þBÃøÌ!í¹n:[¬â¯Œ›0ÑÌ‡øúú*=}š{àÙ3ÅÌ‰‡Ìxäaõ(6m$ãq¤¯ÙÊŸ­]µ©;Ù%0máSo³CŸbm±â4.<‡Nìd!¡—däxcÆ×*>Üp†ÞÏŒûfµ"M¶j¯Ô—)výtÊÀ|'ÞÌ“dP/ö¼ù»æýké×Ì&®{*ñ¶Š¯>Ü%¸ï>2VŸÛóÅ–¼®×èå…»²;«&žvÝ/Ô¦l£ÑQ!)/owËõûÿï77·Îïçœ»**¸ÜÓåoÔèvWØ=nú€®SýüüààÌÖøÎæî­ªngÊze‰WÜêÍó#I¶?_ž™’zÌ_¥G¾:•Ë,6ê–Qbc¿ã¡ÑìôÛ=Ö¥ä#6t‘c†h´gs[EËaÿÞÞæ?jàÀA|H*+/cNÅkÔŽêúnÚã{r$CX¶ÕW«Ï†\3c¬…-Œå‡v}¼nÓ®ãÆ€44x~\Ô¬Qv)pèÏ4öCŒ¸z„o×w×*>ÛôÕqæ{MDxP×}{ôçŽ–I&Žo5òB«öJõw){($	†ÌŒxxÖÀÖ»êó‡›_pú?_ß¥¼ðíøKîÃYÖnc5ôú…óo¸¤¨7¨ó·|˜}´šRæü‡gòì ƒ˜¡ü—Íë²ô4ë½–£2ÛÓ\F¢ö™3gvyÿ9sîdf›<ƒI$ï—=öI­G¦îÕ!éL«†BþÜmI½Ï[ûßgòú<&à¯7Éµ$Ó¥®Ü¢gpØp v=pT Ü2PScAŽ?–„øùöP¿Ùó¿gøYõ¬›§Žõ·¢P /?´só†B+W\›÷,|õ©ó:éÏíÿjÃ¦Ï~9ÓüZ¾Të²SBâºý¾«ÙFL›4 Ë÷§=°åÃ-§˜×äð{G”çïÚ}èd…A0`Üdù¤1#Û|«Ú“»?\·á›SÕCo{éõ‡å&Þ~ù‰ßOrÐ Õjõ69 Pà5fÌ$yëúVùï¸„D-•÷Ýwó´æ–JzébÁxùÐVïJ]ö5cI¶å°”ŸßÔàŠ+®ôô´ñ²2løðÀAƒJÎrC™äÿ¾Ùß‹´õ¹`Ï–Ùw(`»:Ó®’du7Y„¤žaþaÿ­QBò5wÁø7Ž\ærôKž€àÓFÉýÌÎìºnòóó¯®æŽ³-9{ÆüG.>Í_6¼§Z´¨©eýpíJÓ®¹!ä¶`¹%§¹
}‡Ì„–ªFe¤Óç¹à5dH×É¬üÈ_•½kÏá¦JÀÏ×Pn]Ÿ ³iOîúŽ;ÒžI§Ê»l<Óîÿ8ýë“tEôÃøçZåÅlæ7ùá%OñÇükU{s·|ýÕw…M£Þ¿÷ø½òöÇâiOîmú¤§>3Ž¼À5%R´úzÏÑÖCè‹ÒÌf8™»yÓÉÖÏ!ðsómv`çFZZµ&ï<À[ŸZ<ZS…I{äË5onú¥‚ùM}xÙwŒB$ûipÈ8?çÎ]ìâ6fŒŒÙÁÄñùt¡´´VWkónI|¡ÅµU%©—öÚæ-»\*I}sÄmKûçôJmm­oiüD£j­K­Ò’§a.Êçûßmïá ™×M#FŽ*6vKúã}èæ<äøñ£Í­l´[ÆzVõéÂ][è'cHÐ-·‡Ý0mÒ¿®Ã’PrsÌk73‹èÏíZõÌ»Z6äš©0L[¼ÿ»/²>ûñhsaÒsÔÔY÷Þ7kàžUñÙ'™Qyì7.¼
Æ\7eHç_‚¾87=c'Ÿt©…,(xÌ¤‰r©D -øjÃ×…å›Ö|â1`ÿ–ì=|!ãî3e’|Úõ¦Ã¨öÐEó¶Ípø“ŸM|é‘±^Ú“¹[²´íïÞ¶s7}IÓFÝÐ’^#ÆŽ+ÖŽòcåŠ/7d|U6fú¨ó¹ßV3ßióã†5õÅÖŸÛûáÊµ;¸ÒÙ57LoUJx™8ªÜ0Ô’lÇ®+¢|ï"žŸ¯YíÚo¾¹êÜ9î¬±Ÿ‰]Üåý^lê½ >?TjãÁÞššÛ\/“æ¶Ö	ÏÍëh…æ¶ÞÄÒÃþyO]ùˆ›Ë¥£Çº¸5Œ]ãöçf	ýY—¢ÔÆ	Ìžìº4mÏîéÊéÓEûöþ:uÚßº|ÈÖ-ÿå¯¸¸¸2Œõˆ7?ÿÂ½‚â{Úµë Õ0Åù_o ÁàwÝ6m¤k
zõï»
¹íýði×˜8`J{îˆâ—¼=¿ìßàLuË›
{d~Øt~„Æã{˜}©÷gïãê=^“g]?¢ÓlPþÛ†­û‰»¯ç¸Û.z`úÅÎ”)£¼öüG…§w®[e¼ÁoÌ÷…Ý~sçßgùþo
šÈ0fhõÑ-ï~võò~Sä59bÑmcÃ:ÊŽþoÝ'…úá·DGÌäGœÒWìßüî—žiÑkü]‹_¿‹»¦=yr Ÿá—¿âîà;mÑÒ˜››â_ùÍkVfsSD0ü–E1\I,ô»úá%W3°)ÇT’ÊÊ.vsôõ3«5ÿð‘Cü(‘Ã*ÌªÕJ\IçÔçl’êêùt±ãöéfêï|Î´j+ä“_]=BR/QR}ÞŠÃþÿ8%xP‰?øÏj¸Ý¥Ô²"™KÑƒ¢˜h0³;¶¸MÿÛÿ7–Ï•ýï¿C…¥:ÚàÛo¿Þ·÷þzÈ³ÜÝ{n¶÷“Ê‡JåÓo»÷ÁCûr¿Ù¹ë—Âb®)çÌžMoîÙD•[îzäÞ[µ¿èÏìýÆØ‡iÔŒ&NK¢Þ¿áõ´_šÓ‘ßði×ß2ëÖü ÖŽQþûgYùÜpÍ-SvrGí‘ßLûæWp
{fñÃSZÞôçö¶!»©¯–ßÄ[y üº±]ŸWµçÛß+<ÇMrzßQ½dÆ#“Yê'ßnÈž²h÷g/ÉÈISšº1i»¬Pï7–niz“åÚï¬£ÓQ{˜9é´{ßnxwÓOç÷®yu•*ê‘ûÆT|½vÍ¦Â
>äÅ=0Åëäžå“n›uÍ´·ÙcNiíÚª Ãír¦Ö‡Ë54Ô3[suqi3\‚ñä$½í¤$Íõmß9ÎÝÖkX}ØGm¥º–~cùp /6L°¸ç¸/áâb¿ƒo…Báßÿ>ïíµoÒuMEyÒ²%/.M4xˆÉ;oß¶õ?™ùë>>âÎ‡çv¡ßøwÓÓžþ}×g›¶[ˆ¨°´WF!‰Ù‚^ýËg_g\«PØS-Y’i÷NVíb“n¹yÆ´ñ~ŽnäÑ*¾Øð#WËŒ™6±ãÝoýé¯×¾¶…?CÈ3‹ç·:«®¶x÷‡ië¾9Õ\Yp{Ü3L2'rèO~·«Ðà9íúÉ‚Ï($1áÐá7ï|í›ßþÆlAè/¿#n…üj®ORÁ–7ãšö‹|'?üT}G3   IDATt˜ÜO¨?ýãÎ¯¾þñdö®]×=0þ-r´±Ùƒ‹CR’¿ÿÅ&¶¥v9#ì…ú÷g¶æêæÚPW__ßàîÞ”ÆúºŸ8×[‹I~³M}}ÛîVÁˆÛõWé‘§-nÁ¸oÌíM‡ý›Ôo“>ÅTk˜%\Îý‡Icí7 ·_bÏ½™×†ìßŸÿÓ?0n­tþÅ%ÏÌ½ï¿MŸ10p¿ZÔét'Ž+·lùôÀŸ´<ê‘<îåeÁ¨à5ôêÛ]}Ë#‡ö}µå³ÜsSf·MYAnßÇöQÝB0*ì®kLN)uû’·×+ðRZÅæMß;¹íYW8ôæ‡çŸÎøªì†…­’þÜžË×î¢gð~CÄ,û£]ç˜¹«ô²‚¯¾;ÅÞ2k’_îgü‹øN¼/âv!»áÃÇm
½zã‘”BÀâ“»x¼ùáÇŽóg»ówË#Ñ4—	…C¯_˜4iÆg«Ö~ñÓG¯ü²ó†EÏÎ¿~‚’­¹:($]\»´Ôâ®æ µ\«—³}H¢6©:V_×ÐàÎšBåŒ^’Z±®]w+‹ ’äPÖöÿøÄ:¿OÃðD×³:Õp X5ÍìIõ¹’C‡¸Î´‰6ÿçCú¡"Ó¡Ã´U•jµ‰SÀ~ñyÖ÷ßåüã†J{¨[R¸ÂÒƒKfÜ§Õm’‘ô'¿MÏØÃÍÃgÍ¿ÅùÎ)¯?ñõ›|tŒ{`a„¼‹7(BX­‡žiIH¾A-Ž»}¬àÈÇ_s·ÌZ§k|÷ÙÞr6âÞëÇøU4U&œñÈ|ÆÎíâ~;·óµ¶§„Û—öhdÚ%·\r²n@¦ãZ}µÁ 7hZ½¡º¼øÄïyÆq2'O/8ùÝ–Û¼—¡GßwR{f×ªOx"æ‘CÑöfKŽink}åQ3[nzgfÖºZ?­Ÿ¯ã‰YˆO“õ­:îôíÅgZ6QIBs›ó³ú°OA¿.îäîÓ82Éå¨eçôá†8·¹q`³T¹)-IHzkuJþï{[nÔëõT@jsÏý,ˆ~úÓÍ›Žåú‘¼’”°ìå•ƒ‡eÎFh“ãšôêßÖýk××ÇsòÂ˜ˆIÎ¶Õ¥–¦Œ´uÆQ¼/Œ³|oŠ€©ïRBòœô —èázflq3ëhíñ]o9eðš|;÷Ê{ÛþYà;rÜðV+´egÎ7ýê9à’‘<ý†ø·lFôÅ?f¼¶©ÃQŽç
ve°ÎN~³vÉÂ˜eMˆŠ’­8¦¹\yåä?ÿä¦ðÁC+4¾â.sŒYçnã•––9z„¿d—ÞýMƒ	]’zq<ô»˜ðøåÖK›Ûú[öß‘ÆÁ5¿ãR}YÂåX|ã€¹öÀ1UnjY—°ûç]€Î_8oòwÝußwÝK‰jðà¡|Hª¬Ô¼úêÒe/¿6p` »Ü”)þ·fíæ|î€™!7?}›“µàhOæ¦§­ûÉ8¼ÕÀëc£fZ´‡wr§ÚŒ¹k~sÀâª7ü¿]ç$CÙ™
-rkØ´ôuµû³ÿ”—MyðâïêÜå/®Ëç»·˜µhùÝãM7]J&Ýqoqµ—§'7Ø)×:g<×H'àþe?mÚðÝyÁ¸°…L•xq­wt»—ñRàå%,;ò¿5¯oÎßõÅ×Ëïs¾Ú_oåê¨tÍß‚ùDòöì¾õÖ.Û±-¨$ýüóÅ’ð5¶n›5÷=¯o¸lBÒÅ7ßÔÜÖÕ‰b:‚ä Ÿî±ô°ÿ†:‘¤úïkwš}–ÁÚÿp'±ÐmâœqrÛôUmÍagÞ¦UÌµ×…Ì˜yý±cÊCœ9s¦ôÂÑÀƒÆŽ›pÅWzôkªÃ=øÐ?”Ê#ü KåeeIË—$RN’d—í‰ï>Y—¾³éL´×=ñìÂi=4´¸Izõï_¸aË^cóWC¢„$µ"ÂéËŸä2àÀ‰Ÿôq÷â„™ï¨®?±ßÕ÷F<<@x³Üœ ¢=ðõ\B<í¶÷—SŸmÚ9%ñö‘¦Þµ×øîßúmªÛüÙ^&o2Tâ4íÜžxOëg¼Ãþìì¯´¦q5Mÿ±w/Y!?n2»ÙÃVDÁÁ3þ½a=ýÓÿ~2kVhW[es+IuuuÿÍú´å×3º>ç‰ÚW’&ìªÃB·qrß;%­Ž+òòÑÜPíáéqý4ñ¸V{6"?›´òµ~óMÍmè“ääîüî_™L`ÁYÃÊßóyiQµÙ íý³ÌCú}bÌXfZ35£`Ì˜±c:ý,T OXš”””ÀÓ]VZºâ•¥/-[Ñ¿ÿ Ö»éÕG~ÚùUöÎæA}'ÞûÄ¢ˆ)~ÎRDj}‡?¾»JZu¹¶åT"^’ñr‰¹ô›~ß]æÜO{üÛu©_3Áˆ{£æ‡|U¯ï<ðÑªu~/,ìêÄºeÆ1µ¹z^!5qÌ@çËïü2{gAùÎ[†Ïˆxø‘[å~BÉØñlÊQ‰4è
ù)þ¢ë%%%ß|óÕwt~v6s+Iÿûßç-Á©­­uÿ'FÑ./tó•Þôíu{œ)‡æx¨£¿ðÖÓ¶_ý
Ü\n™|ñ žûPB÷ÞYIê;#nûˆ„÷œ÷ééÕfÞßP= ¶dB#«e/k#p—–…–‹„6Þžçâêà˜d?ÿ€ÄÄä——½Àwë¦ËW–¿øòò×üüí²Þ¹¨%èÚt[íéûv}½3÷§£-ÃÑùNºí‘…÷Î´ÑXG†n½a*ùîÿ}Oî7y…çšŸÆkÌ-?~óØn8¡ß¨¾ìèùò¼kF°Gn™2ª}O.½öÜùâsÅêsålè´™ã­(¨éOÿøQÚ®,'˜ôÀÂûä~^ìÁEa'—fýní+eåM}¡L)?ðEzê'åÜÐ·/y&âjÓŸÕoRÄ’õ·íÏ^›¾©pÏ†äý?Þ6‘Í&4sXŸ$òØcžyöiþú¦>œzõ´ÀAƒX÷}òéÇ-¿ÎŸÿ8³ww7w7WÊTwá«J^n×Ë½sÿªb½ÍõWxÝ›6šôqô†:úhî½4$õ)‹®™™ýiN»Âœ;—þÅÑÆÈ¨×ºxø0;¸apÁì`³:<YÁÍÕIG"£<DÕ£—ãù3ÊIT[Z¶ü53:ZZOÏuÕÔqÆ6Ô»S—¥ýtq°^ÁÀÉ·Ïø.ÛŽLØô†+ŽÖŸÙûáÚM½yèíM¼áÞºšxMº÷±°#«²OUäò¯üO˜×€!=›Jôúêòòó­N*2âá±–¥UíýbCÆÆ~]®ytñ¢YüÈÝ^ãï]WýZêÎâüŸ)¸ëáˆ»®¿ds½jïW›6ó'ö6á½Ó;oOúM¹oéª«÷|œþîÎÃ_§=µëÛ°§¢ž"A¿mÛpq`s;v\È!¹»¸&+++_Z–ð¯Uoy{›sä™ØgùSn‹:>Û…–-K¨®nZ†n½õö‘#F2»	u5ºÞÛ³é-…^%î!)ôª‹[ÌêZnÝ%Zß†‡äP/ýíÑ%{—¸¸vqvåšcëªš‹†šF¡§‹«•)¸#BWÝÒ{F3{rww5ÔÙeðÙn¢öµ—^^ùÊËKøœD­o¯.OXº,Ùn9ÉP­å»km’$3ç?¡8þúÎÓ¾c®»aVØm3ì0¤A[®åÿÕr1É²çŽ˜÷¼>õÝù-÷ÞrµÓ›ß”ùñ/úúÛ?Z\~¾ø¸éûy3b §ÙO«=ñãÎ¯¾Ù¹çÀyn:QÑkaÔƒ—Ä ¡äš¨¤×‡¯[ùþÞs_¬*øbÃ˜"¢¹m„—^ýÛ¦µë²r›3ÁàóŸ˜oöA{^£f<öê˜)›ßÝ°¥°0ûõ$íS‰g 'Ù‚»»½jðyôÑÇòÿÈ×h4t½¸¸xéÒ_}u…Éœ4n\M«JK—¾´äœZÍÿ* ùÇ¼G™=õ	µ5:mÍÅ4û*Ÿ„LÖ=µm,Ù¦6wC òóYüÈ€ÉÝ›ý)Ûµ\çC}4f-„$‡ºaäˆ1¡Êº/;¿[ù¡¹-×¹ó{è«m^LŠº²`Ìð;˜=	ÜÝœ3$‘’TOZþòåÆ“.«V¾š˜¼ò_VÑ)¯1w-N1pg05‹Ý5ÿ)½þöƒ;žÕïê˜uŸÇ0«y½cYª}æ2¿±7GŒåN÷Ëõã.¾$}zIFX“È¼¼ØÉý”ƒ§EDDÜ6ÍäE^£f-~slî‡s´Âà9vÒ`î^BÉ”°°ãŸu×ƒ3,>ŸP2ý‘¥c¯Þ¼á36+b*’Üí±8wfÀ€//KzîùÅõõÜ™CŽ*ÄÅ=³bEŠ¥‰(`½˜þ|Ó»B¡pùòW||ìÒ¤ÐBhL”*ùfJù0!‚Ö'‹µ˜¾þ°¢bÛIsDC,Ý»Ä ÷qCšŽ×¦R]«£VQ7B’‹¦Š;xŠÿFZšo[·ãvt9¶¹÷²qN«½wÛ³ôà®<¬9|kë[™‹g€‹›Íí~g¿Žííißþô¶5ZsbgÏžY¾ì†kWy|ÁS7Þ4‹A£=}èdó2jWˆÓ—9 ö›4Ö¬˜¢-Vg#&™[!Ó—<zZ+ð16N>Þ¢ÙDíØ‘óÖš7[~íß¿äß½ÕÌ‡oÛ–ýŸ?âËQ¼—$Ìœy³¿óå•:~ˆÄÏCÔÔ>•úù¹—7Ÿaòâ5}ˆW2Ô©>eþÑGÝöÊß?sWÓ1Ë5:Ãu¹‡HØßÏú|‰Ô^ÿù‡/JÞ1ù§ÆF×âŸ–°ºvyÈMäÒÏfAkn?pç71û«®­3ÔÙþ\Œ6tú´êÕ¤„ûÂ#fÍî©ós €í	Ü]==zlÌè¯¿þjíÛ—œ*jÐàÁawÞuuÐÔaÃL÷ì˜rßïû¶eÿ¯ÍÙßâââo¹‘9„¶¦¶\£õóñðmJòµúyLá¹Š:ÖH|ÝÓ&z›šY/TTUTÖø‰½¼úY|BRÏ˜õIb­ÀÄÀeGî®V]eú1~.î6¨Ã_X°)Ö^ýµÛ æ¶êZ›ÐeZm•³Ð º‰’Àá}’Z+(ØŸ¼â­VÛæöÉØqF1räˆºú†¢¢“ÇŽ+,T´.ñüýý_zyìØqÌQêëÎž/suu>¨KŸ÷w¿9¿øýÓ¬7xë1éüYM'¶khh<uæBCcã þV·Í’zÊ®'—ì{ÞÅå’–Úz½×™Ýq.´Þ6º
]=»Ê$q   IDAT;8 +«ÿzaå˜v©=çoq€Ë’¸‡ÚÚZ+9{öÝ÷Öýúë/Ìr7ÝxÓ£>f§Q‘:QQ©­ª®õ{ú‹›ŠIúº†+Ÿ>xú‚³ïîŽÞšàÖœíJ+´å•ÕÞž¾>ÝjßîÉ Ý—Ý0rÄ—ÚÜXZø KÇýÛ\ôõÝM#Æÿá°„ÄŒ½ÎÝÝ0€C¹»9Å@mƒQ)håŠ”Q#G™ÿ¨qãÆ¯ykíâÅÏ;>!o¯~®../ZFßº»¾üÀ`æô–En™ìuõõô¨ŽãíÕÝƒePIê1ÇJËþöY÷¦Q(t•CÕ{ëâu¸xú3kK¿yFÒßÏ¡ž¡®¾º¶w´gÀåÁËCàîî\»gG•G~ûõ·}û~;tøPûS‘¸¹¹ÉåWL›:}ÚôéÔÇzT¥¶š‚—‡Ä¿©¿35]M‹;t¸Øy›äÃ<~}ãâ¨
êÒÊÊêZ±w?„¤^íÅ_îªø€¿~ö—gê«Å]>¤ÑÃ×ÕÝÊóÑ¾zóŸŸ}3s¸Êj}CƒŽ+	 —!*'x{:õ0
åååUU•UUÜ²7ýçãëkÇÁl-ÕÐØxî|Y}C£4Ð_(h:Š¨àDÍÍ/©58ãšÜCàòÓkã&mê­7Ô©JÊh68À¿ûg8FSHOzùÆ[Ýõ#éŠöÜsG¯eV™âè›od=ÁCˆá¸ ÀADN¿Âñóó“J‡M˜0‘~†J¥N•wÂ»¦VªÒVƒ×OÙoãÓ=\âêÈ¦gF¶$$ÆÔÆ½mú®¶¨ã $õ$¡›Û3W=JW*ŽÜiæC\êÖÔ<_¾ÛÛ­‡º	Ü]]òTn p™¡UÀÉÚz#¯~´½¨®ÕWjk[nœ3ÍwÉ}ÌÉ¼tÿ Û¯¾Xb 7\S«§7ßÃþ[Ã.~»{ÂÄ-ùÿÔ¼˜Ù#zx²a–uUÀ±)ãƒXÏ¡b’ó  ½‡ÐÑ£l_–¨ãïë}¾T£.«¸»µŒ-™ðƒöŸ¨ùrŸ†9‡»ÿæïÅÜV«3Ð¦+¾>¶ê„>Ià ÚC]½“ž¥ .î\ý Ç¼üðcKRqNÐrœr¾qÖ²#û×°žvõè~ß¼<¶Ÿ°åˆ¶ÕÙÒ†ÆÆnŽÙÊ’à ýD([€a%c[5¼ú‰Ï¨Ë[¾¡P²ãå1¡WÙ÷Dr]š3Uüõ²1-	©¡¡¡˜Þdc£·§‡CH‡¡Ý¬Â À>hõ‚¾6ç'ö‰„Ô
Pr¡¢eäO‘ëgñ£ß=õîÜ7ŠÞÿ+½±³ç5uuõ"¡ ›CG¶‡æ6p(4º€Í	ÜÝ<=°fTžQ—VP{õàÉœ>ý¹<*í$s ‘Àeý“Ãï›qÉÉ'Ôe••ÚZww7I€¯«­c	B8•lµÕ:Œš ¶Bõ#oO6GöC	é\)WIêç!·®ØíSVß—rœê8Ìþù»öüè)£úµÜB€%¥ššZ=½%‰¿/å$fkIàhTI¢z °ï~7œþÈÎêëÎ—sMZT´<à’8RZU¿êó’ußœ×Ùm¨IË“wHž½k Ÿ×Å×¥7sæ|…{Kî~>v:Bô ¡¾V‡s• @wõ¹8ìß¨Ý­¬¢ªVÇ•mðõ^r áé†ä¬³ýPjÛ†J>ónê¿$<p°ÿ%/W«3œ½PA•$‘Ð†ü·‡=£V_§Ó×3  kyÝœ|íËŒ¦ªºRËÅI€gÛãÈÖ%}ræ¿V0[¸o†ßK÷3¸í™¸*«kÕ¥ÜxH>^žbï~Ìž’ ÇÔèêôä$ °pÌ¨®Õ—k*™§‡°¿Ÿ· ]O âRÃÎ‚Jîge™Ö²•|€·Û¬)>·Lß<Ù»MõˆèëêKË«èPú •‘˜!$AOB=	 ¬ ¸aH‘T_ßP^¥­­Õ3®œãàëåæjºKÐþã5ß¨¤Øt¡²þ\¹¡T[AS§º`ð¹ö÷qëïãN©Hâ+ ëC„7]é=yd¿Ž^±T£åO“âá!ôóörLG4„$èa’(*1  óP<¡’ÐMµ¾®Ž²€Ÿ'ýØ#446–kª+ªª©v%tw‹=EÇ«Ž=Ooh¨Ñáx7 èš§È]€„äLjju”`¨ÒC‘À«ŸÐÛÓƒšáX·5rÏ¬§¦5mŽâÕ|}¼úÙ¿}­„$p
uõ”“ê0€ tÀÕÕ¥ŸÈÝGû;¥Jmýð£rS6ðî'òöõ³*-UsÙHgÌFÜ³¹º¸øx{z{Úòd#æCHgA‹Cu-Æã î\7mlsœešZ¾Vg¨Õëù=^Ê7•îî®n.n.®s]Ýè?7WWZá746Ôÿ§;sÔrgmØøXW¡ÐC$ð	{0l $s1ÔqMo¨(€mgú‰wz½¡Žµ—ÕÕ[vhŽ»›ÕŸ(	NÑ1!	œ%$ÊI”– ôm”(!aSÓ{QY¨žÔ5Ô54ÿm ÿëÉÉ¸»º»ºÿu¥ÿéŠ«“MlB	N‡–O×K©¶®5%€>‰6–ý<Ð©×£éèêîîU!k $“¢•£—Ð`h¨5 C7@âêê"¸ˆGÐó’À©	®Po¨×ê• .oÆxä†s±ó@H‚^@h\oRH¢¢5Ãá8€Ë	•éGàÎýÄ œ	BôÜ^¦ÐMÄÜÕ•ê(0ÕÕ£¸Ð¹¸³‘»›Àéºê\„½­R…î®Bî`÷†FN÷wEcý×tù	 gQøqquáºîº4]¡…×Õø’ô
IÐ»q«Z  6‡m   €	I    & $   ˜€   `B   €	I    & $   ˜€   `B   €	I    & $   ˜€   `B   €	I    & $   ˜€   `B   €	I    & $   ˜à^ZQEÿ¸¸¸´\¶¾ÒÉõö¿   \6PI   0ÁE§Ó1T’    .…J   €	I    & $   ˜€   `B   €	I    & $   ˜€   `B   €	I    & $   ˜€   `B   €	I    & $   ˜€   `B   €	I    & $   ˜€   `B   €	I    & $   ˜àÎ   jjjªkªuúÖtôkCC€f®®®"!1^4ÿãÙÏ³_¿~ÌY¹èt:î—–ËÖW:¹ÞþW €¾£BSQZVVZVZ[[Ë ÀZþýýÄb1s2I  8á|YYÙ…²Òúúzþ__?‘¯¤ÑMØà"¨wu¯cn†FW}ƒK}#»r=±›.¯žÉ 7 Úª¡ŽŒÿqWèR£©(¯¨àïàææFQ‰ôèÏœšÛ  ÌrN}îTQ‘NÏíXRÃX2ÄÝG¢wó,7¸pq¨Ñøƒ6€ðÍmôsÉ­C¥´¿A{.\(¯(?w^M?"‘hø°áHXOC%	  êójŠGµ:®YÍ«ÿ`7ß!U¢:Šz*I—*5••—9{¦B£aÆf¸Ã†è?€õT’  :TZVvªè”¶ZK×ûù¸”UÖP.°*5QCýPIéÄÉ“´Ü:rXU|zÄ°þ~~¬' $ ˜ÐØØH+è¥èºÐK,«i±z öæçëwÕd?õùó'‹NjµZÅA…dÀ€±²±Žo¿BH h«®®î¯BE•¶Ê]äé1xœ†yÖ¢zàXŒôï¶äì)U¦ššÚ+&ÊÝÝš[0˜$ À%ª««óþ „ÔÏw@ãÐ«(!1 è	T:<hðÕWyyzÑ"IfMMs „$ €‹ÊÊËö(Ðëõžý‡Öø3 €ÐÓî‚É“®ôõõ¥ó?÷ÓBÊ!	  	ö|©ò€à$\]]'M¼bà 	-ž´ž-)a À)¯(W?FW|†_Q!È ÀÉŒ3V:TJW”Ç•åååÌþ’  ¸ó¯:HW|†M¬põe à”F>tðºRxø`µýû'!$@_WWWw ð/*ã{õZáæÏ À‰1ÒÏ×Ø¿
ÿ¢…—ÙB ôi´ªÕëõ"¿jñ NoÂø	´Ø*Ò"Ìì!	 ú´CGWiµnîÂFÉDœ’ Wp3öãvss«¬¬<¢<Êì!	 ú®¥èÇÅÕµßˆ ÚœŒ ×‰Dò	rõyuiY)³„$è;tª¼¬”¤”•Ž?y‚.}‡ŽÓÔ»1 èUÄ>>CŒ¸Od{@H‚Ë™F™“37")WÍý¢È^ž‘—¥DLã¨H:Î] ª0 è…†•º»»×ÔÖ–œ³ËÈIIÖÒa3ÛmvÿuªœŒÕÙùyIé“ÄAQ‰ñÁb¦É]’˜Ô×544œ**¢+^C'`Xm€^ÊÍÍm¸t]9YtŠjfkIÖÐägÌ»å–ˆô<³ŒN™“‘b”ž™kN£Z‘žž­°ôuz«¿CKˆä‘‹DŒ)3Wgäkè÷ðøè Sç¬^«bÐ—.>m¨3ˆ<½Ë™€^kð Á"‘È`0Ÿ=ÃlÍEgÜ›wqqi¹l}¥“ëíí#tªì¸ˆ˜ln+
ŠZ¿>>D"2÷±šü”¹sÓ•ÜUyüÖ­Üöº³WRdÌ››”§Ço^$fæQç¦$¬ÎS3ëH‚ãS¹r‹]uç;´}çé
ú·nŒ’‹ÔyIJ&Ú¼9ÑÞœT]]Ýo¿ï¥ýN_ÙÔ²z»Í|`g®'vÓåµÁ3ômJ/<|ˆªJÓ¯žF—ÌvÜXB§ÈŠ‹‰Ë¶¶!K(p-="±ØäöY§ÊÍ\ž#ŽNK‘PÝ#6'"%/ouRzÐFs£‹N­ÈËÏ·¶@#‘¨íÜÕÍïÐRÔÈ–½ K•—™•ž,	¦F7¦„†!!õY%êsÜÐ‘¾ .ýúûxûTVUÒ¢=dÐ`f;=’4yéqéùb™L"•É¤ân­²4*E~~¾R,a6¦QdÆÌKÈmªÑˆCâ7¦E[¸¡‰Eôù$2?¡HM   IDATñQu´)OÏ¢2zuÈæä	×‹&7")/?#%3tctïß¬[úª³cîˆËÉ¤‘õs†N­aâàøäX>fJC¢Côe¥¥ÜÃn†³zÐËè4:‘é¾!ƒ:RI‹öå’tE~nŽ:—ÙRv®B™º>1Lj»ùH—“ÒÜŠ%	MÞ˜)·4³ˆ˜¨õ/&î ‹ÏÉ‹ËQf&¤„lMåªI‘YsÓùé)Y¡£d]"iXÚOabk!Fg·$fåw¨£ì«èvá)/e^„zãÆDÛ‡gèeêëë5•\©µ†y0€^C£ÑˆDšœ¸ˆUØúñè-p	?î„Bš
ZÀmØâv¹vÜ¦&ÛõwVçe,˜Ñ²u—†¥nN³<!™G$5öURe­6öjæš‹Â¥ÜÑìyyæ’%wc'Cl§G~‡K%X« ãÇóñ—èzmIwâ‡ÿ®ý×:úÙ¸í¯&îPy"§ùÿRì°C9/ü˜póû÷só¢Å¬ ÊMO¢öe›í—Šªê×E¤ä‹‚ÂC$ŠÌÕÙ
k&«F™Ÿ———¯täÁ@´—K¯™§èèX%®‡ˆÂÚ^´Q0ò5öb)+/c¶Óó•$±,,65L*íö¶Y§ÎY—‘ÏOqHbr”-§t´q‹IinâŽ—J[Ÿ*µc©S,ŒÌŽÈP(²Ò³#ƒ"e’Ðøõ›#ÅAAÒÞY`íÎw(	‰¥ÏÞu¼N•—©à®JårIïüÁ¦øäæ;ˆõZú3¹ÿýpûYº&œ48döýÛÍØgöd~¸Ý8ñ`ý¤;¯brÎ¯<’³eçá]¾ZéÉbaðãOÜw¥O÷z÷§»TqW}útuo'täÈ‘óçÏ·¹qÆŒ–ÞÎ¬¦Sæ¤¯ÎÈÓå(×sM	ÿ ŽÎš—””¶1:64'&%%+x}ä¥mªìscr4A‰[7GÉMMrM~FÌ¼L•4rã—É!Úe¤upî‚¿lÓ¢Óh˜X¤ÌŒ™›¤MÛšÚí¶ÿ€
†ðý0éùD•“Hë>unBnSBb¢àèøpy··‡:UnF\\KñƒÞjxrZb¸ýËâàèè°ì˜l
àJN&Iä½µ‘¨Ûß¡XÜùC“›Ý´ÏD_¢…$àB·£©w÷fö=}¸=	[VoÂŽîàMŸÔxMØÁ]˜î‚bË{ÿù]ÏÌ‘›÷ÇÄäîÑÙŠUèÝòbÞ>BÖû¼ùæ›_~ùe›Oœ8aéíÌZšüÌÌ<“PƒBH›•¾H–9/kunxZlbb8¸]¦ñ]•D÷àäûÂŠ˜#wÅc§M7*uNÜÜ˜üà´ÍÉáñQÙé)«Ã‚/I…Vð8qò¿€ÛÊårt›:wuR¦’¿.
ŠìnDÒ(³WÇ%d´$&’‡'§:" ñŒÅ£XYïÞª÷ðwh¤kéÎ$êåß&ØFyEECCƒÈË§ªgj³¨@—ëÕ‹Ñ¨7f¤ž¦Qd¦p2ip°Xßv Úk–GF‡IÅ2Y”É}GQ«ËŽÿÎš‘Z½vë—•„DGÏMZ¾9>:>2'"ƒbRPrHwbR?
ˆ:•¨4@n±Ë"$q-*	ÍIßÍ†6îTIIé”ÒS&G…8´±K$
¶èT²ÉÊVŠdÝ=@«€Ržèf pŠïqßJ~®‚¿*é­Í•`KZ-×$òé_Ãl`ÑOÐeÐ´i>þ8]yÿ½÷ò÷îe–[óî»ÌñD>ã&Éõ•ÞAWuöÈ™¦‡z_ýW÷Â´^„ªHI«í!ªì¤Ù¦ï$	•GE²Þ¤£Õ®HÎUÆ²Â7FF''KT2ìAûxûPHªÒV!$µÐ)2²š¶ÆÒðäd««H:µ"7+#=#+¿u€‡Æ&G‡H™miòÒ’Ôa¡!!6ªoPÙ&=%ËVƒI‚£SÓâ­ÈôŽüÍy7ù9M½åAÁÈHÀ˜Á` KwÛÌäçÓ¥ÿþü¯'Žãoé%ú_ÿìºë;þ³®x[ÂCI¿»
wÝH,AvÄmVçéD²ÐðYû]]îôÜÙT–—ÉÝµR£ÌÍÊÌÑ…ÄFÙxÔ_qPTt˜2‡ÑŽ¹œJdÌøÖe~1·‰^’4y«ãRrùQPtšu‡ýÓÆ”"ÆêŒ<ÇªB£ÈÉHÊÉàúi‡†GFF†…8ScZeñÁ=ðvE§ÎËijì“‡¢±XËÚÓA]ÒÚ±åcBbÂIÿwïÕý-zô….3u‚•9tªœ¤„t…Nš¼>-ÒÄH/:eæ‚,*.É©Êoï¯T§Væç+ÅA¡ò¦ÝdM^VF¦R!	¾¤;¸&?eA\ž8$:1ÞÊc™DÒ°ÔaÌ–„cHªCH2Ò)³âÒ›J4w¥ÆZÚÎF9;333;ç’¥Á‘Qá¢¬¸³?.-%ÐXÎ…%ëÁSé2^¢êÎ:z+™|™,$,ÈÜ2’|‡¦Ñ®WvÓ©áä¡ard$ zãÚ³ÑUÈlq*Ì]¿þÚú×å+W.g—	]ñŽ÷?>Àwê~çã7³pµTúû»Ñ÷¼+ô>iÆuwÞ{wÈÔÁh«ë€N™•° .KÉíæ§FšÌ@´23îðÉCº··gÎÂšüô˜ˆEPôÖñÆ-ªXœ‘”—«ˆ”_<—–N£T(”ù"™Î¬.Nš¼ÌÕâÈÐ`¹ÔžaöJ’F‘•™'	‹ì¢‹:/¥åì"yTjr¸Ù	[£ÊËÍÎÊÌÊn;ò$8*>>šÊŒ‘y³”u$AÁUëÁ!4ŠÜÌ$úIƒÃ")`„Z–DÒÈèî#­ÉMÈÉ4^“u½$:ÁwØ92'“ÏH¢ 0d$àñkÏ:—ËÿŒLÍ­	­	'ºâïÞ^ûß) äÑG,,#]|U§~ßñúaÞcBî¼;âÞ›§ŽDXj;(AR®š¶aë×Çv0>¤F™“E+3Qpxw×dÍG€wrqPddpVB^N¶":Èø†DÜ^³8//;[ÔR‰Ð(ò&	5s‡Z“Ÿ•´ +‰]Ý*tN(°Ë;$éT¹)1¸áŽÒ³#“SãÃLo¬uêÜ”1|É8îŽ9hŒÛõì¬¬ìÜöCiQKpT,mÛ›^ÏÞ[vjŒM[&Ö(ósr²²2³óZÆÙâšžSèGËeG•«©¹¬©£µ4¸¥ÎÚŽ}‡SçfdòcBˆƒÃCeŽúÁÉ5…¤FÛŒ£›¿oãFgñ9z43öI*³êðã ©S™Méõ•M!I(¢ÿ˜etG¶½–¶ƒHÞ×=õÏëlÐhVu4wó*ú<é¶ˆÇ½wÆ`§\$ÇwáÂ…îßn"{uw °88~}Zt‡½¨óŒ+3ixTXwÛæOißù˜Á”‰"C$T8ÊQh‚ù”D»î”’rór”šæ”DMùjÊm¡fn£DòðØQ^NV®²©  	ŽŒµñ6Ž¯$é/ß¤SågçñÑG“Ÿ3W‘Ÿ–ß¶¢Äµ²-ˆÉj:œMnæÈ„Æü“Ýv`On†ˆŠŽ
ï‰~½"±,8<š~’©e(3##«U“7ÀPnFRPXtttd¨Ý!Ü™usøŒ$ìhiuÂïÐ47gSäBF‚f|H24Úæøÿ§£¹Þ¦7Íšµ|Å
f<ºíû;™åÚ4Ûu›N¡%$yx[4ûëNnKJHÉã;#Ì~6f¶U¶ÇÞ›ülpéŽÍ™[~?Ûúö3¾~óéŸ~OÜðêN˜“bº»9Ôyé	ÜøÇ²ðÔNÇŽ£ZÓjZ™‰¨ÀÒÝñòtîæâ®N¬@™(Dš•™lºˆRRhH°Hzñ]ªó(íPÉìµ>´âÃ£ãic—Áoìò2ãæf&ÈBÂ£¢¸f8›„¥¦>I¶IÎvZ‘,,uóÖÔ°¦! hî˜Aq¨UM‚šoãæµ$$iXêúd3ûŒ‰¤¡ÑQ-ªbÚ®'¦mÞYP°scbdOoÝiþ	_ÿåoMoæâ4ùÙ)î˜›”gçQäé›ÎÈ1¾†8$²ãÓ…8ówØŠN•½ºiðuZ³DátmÐÂÅå²©¥PD5#S>{ø$ŸrXÿ!–Œ|­;“óZBÒŽ–XSºã½÷sOXUöwãÝ/¬ûtçGÉÏÌ¹jDëj–÷U³¯vÎJÒ“O>9²+n7‡$8~óÎ­ô“ÚÙàqš¼ô$®Í„;^>¨ËïŒ/é:š`:µJ­æNÐ$í"H‚Âh;DA¨¹C$‹LÛ¼¾¥i‡*Ü¤¡‘!–îr»Äõ_|¹Þ¸ÕÐ)s3æÍËRÚ¤íÁæ¸36Ì‹åá©%¾Ñ**9Š4i©ÜàG½[ß‚ÕúÔp&ÕûâãUÙjjqÊSTPìˆJ‰ŒÍÏZ´:³ù0z»w;¦ºËjþÔ;;¯»8ÿwÈ-¿I)9üwÇâEî”kcè´£Y««¸4ÖÛ¢˜ôVz:36·ñ¿>úøã÷„‡3û‰|¤?×Ìã3NîÓn¯<ñË¾3üUï‘“™’t's’–î8uÉg¾^ú4}ÐÅ¡Ö à3îú—ÑîÂ¾ï6¾÷>–ú‡Ü=u3ÆÃSç¦$¤Ó†PFSfŒFÍíÅFKu² Ó•"j©ÉSOcÐåfS›¶9JdzO[âŒ<­^#­ßËC£’C£©#%%Sf£QôôÆ½¾ždNÚ{Q$IÜ¸Y“dlRå$Í›Âr2šZ„¸3w¬O‹·xI‚£’-¤±'ˆ$A‘É[Ãc9«Ó3óÄáöÍH:EfÒê<¾îÕå\ïäß!ÕÄ’š"’4<6gÊ†Ö…$w—Ælpžð6}‰FŽ=’9‚hdèÿ=ÚÁugr?üú]8æêqÌºâ_ÞKZþáïMõ§€I·MÕý´ãˆñWÊIQ¥ÞZöà•Ýés-ê?õög§Þþø‰¿NÇXÙ¼o¡f“$ã0ÉÒðøX³NØ!–‡EÉ;<¤¾éx_QpˆÝ€:ÎoÜiR¹1ù¤aÑ6Ø2ÑÆ>*-$RÇD¶Ù™åÚÆîÛ6áÄ‡xp]›7K“Ädû()s2”Í’†&¯O¼ÜWIäañëÃbç dv£Î[Ò|X”-Îy×ƒtªœ”¸¦OÃþ(6-mp	~íéÖXo“ä„t'v¼û^nÓ0ÙWß2¢Ë%ºòð¶´„¤¯O6ÿóFòÿ]©ÏüÜsò£ Týúæs«|Ö-¹³ÛJúŒ¼âJ]ÒägÆÅ$pUYdblh÷Wd:UîêtÚ‡†wçíš¼ÕÜF™ÞU¯PÞ»   IDATrb˜ÍºXˆl¶ÙÑúF%©	Õ“7n”Æ,HiÕ%ÇâF¶^N$¶cä†QXÎ÷Þ‘„$&Çõâäyi‡~ydjr8Ù†6øµ§KCMN.vÃßþÆZuÜ^¶dIÏvÜ®üóã•Kßü©¹©íºÇŸ½¹óÓt'~ÙüvÚÛ¹›Ø‡,~#ñîq\Í(èñ7’õŸÛÌ§§Òï^{ÎÛû­˜1>¤ÝÑŠy­˜5Üj99->´ûg+Ð(2âŒÝ¿££­ÏHÔÌfÜ`ˆƒSãs”?®M(°Y%ÉÙ:n·'¡vµ‰¡(ØœŽOHMù³['tqÜh#;ô‡¦¦%¢ŠíñkO—›üâ,tÅù?7~KBbÞÁÿ|¢“ÂîÂ_Û–/ºûþçZ%¤€àGÞH•OHQÿkþùÖ²9ƒ›~ÕŸübésYV2°2;)â–cB’…%oLë~›	ÕÒcæ%åjŒC.[{jS*m-˜A‘<*--ÊY÷§››ÛúH%©‰±áM7/ÆxL·2;.F,Ù˜ŒM`7ÐR“‘×ÒžŠHi‰¡Ž?·šmp&)!¥¹»šñÓô¥Z#XB`¬$5Öémr–ú«‚‚èrä¨Qü¯#G¾ª´”9Tå‘œ-ÿÝ¶í×¼“­_ØûêG’—Ý×aK[å¾uO?ýŸ­ŒŽ¸í…Ä˜;Ûõ:¹å…·ôú…+ùa“ôGÞ}í=ùºgƒ:ètñ¨;¦g`nØ¤¤¤Œ<ãjYŸ–ÝÍ#siO8kõêÕ™Æ§¤†ëVŒ­O9%ILMµì€aÇ˜×Üq»/ôIº74Àz[À°­ÈœÁ¸úZS,G3|VJRJV~s&eŠõÎ»_Ð"+).!KÑ¼Šäá©iÉaHHÐo//º¬Ó–2¡v³Ö¼ûnë_}üqúaåàsáÔï—$¤á³ã—¼pßô²ö™úßûÇs›Æ$#1{qò·ëà¢‘·¿°¦ªraZ^óþx„¼ÃgÖë[F$¨ª¬b`*?7‡ ·y˜<ÚÜÅ''FwëàáK
Drnhæp‹kRÜÐÆéÍ!‹ÏmQV¼-”D:ãÐö^5Wi¹ùÎÛË›ÙH/›ŸÛüm‰šÆHRfÆÅI×¯Ç±K– Bîê„æ#*Ò¥¦Å÷Æ°IY/'+33#«Õ§¡âtZòeß¡ºÅÛÛÛÝÝ½ºâB¿¬¦ž]úÏx6ù–`L<ƒ‚#q³§•åµò½3Aÿó®]*ÄgÜ]/¼pòÍ=W<þl‡YŠ£/½ÐÜ§«Ò÷ÂZÒ;ï¼c“Û-@A$#)©iè[IPxl||xp÷W‘GÇG+â²YPXxdx¨5ÃZkòRDÏ¾Éõ*6>6Üº•«F£Ñÿ±{MIo0hµZZÀ½ŒûB6Ñ»N`$’ÒVP£™—ÀáÍMÀ¢Íë£zÕF±GNÕÁŸJ„ä(ZI)¥½•('Ò<\:ÊHOÏ¼ä³Ð¾Wxr§£Ö4ðó?w^íÑ ­a6[“ö,J<o|zçY6xìÒ§G¥Î0÷Î¢!¡qo„vù”·$wK2Ðº+u£Hšž'³á
Y½qg4³w²”’…4<²;Çú‹äÑ[Otç}˜¯´”;,Àß¬Q/ÌÔëÎòHeÃÔ4Õ¼yÆ#²4yIq«eíÓC·eÐRæšÖ#‡ëìž—tjE^®ñ$´yªKÿ"‰ONŽ
±{>²åwÈÝŸ”ž“—¯j;ø¸4$:11§3ùûs!©A£f>—IH">CÆáÄ±½×¾–Æœˆ[¹vï¼éuÁØ#0 ypW›pþ£ÛÚÇ¦&7î¦àFT1›ÓQiß²ët6Ì3-ÏeÓg5ë®—Ò&!ÑL¿~çÎÑöOH6þEÒ PS_Z>
‰JÝúÛOã‘À|þ~Ü:´ªôŒ°7®ÿ À”†††òŠrÖ¼€ÛJï\IÛVŒ§ãdJ¶ËaY´…o¹b»4£iy2ÝÛg©Øš–)oúM"‰äN˜ãÈDaãï(?5œ›ØbîÃlÜùÛÎ‰áA8Ê,ãææ&ö766zºôHó7 Ø^iWFò‹]]ml\ø=|þœp-g†k}Š¸Ž®³ž>U¤F‘£
éM}jtªÜ¬ì<®½H,	w@ */WÁdÁÁ²Ë¤·wk‘DŒÂt‡ªøôÉS'½ü$•~côZ®'vÓåµÁ3ôyþ¬¬ª9bäÐÁ¶<3`/I  Ö1{ó÷Q}ÞW6­¬ÞfãÎƒ!$¯´¬¬ðP!Õ¦_=ÍÝÝ–­Ñ& }Ž@ JWtçŽbW ·;~ò8]*µmBbI Ð72Tà.¨­,÷s©a ÐkSŸ«­­
C{>¶… }UæGNWªÏrC5	 wjll<YÄ~pÄðöè„ }TàÀ@Cmµ¸'mè•ŠÏœÑëõýúõ(Èì !	 ú®Ñ#¹sÓV)¼Ý ô*ÚêêS*®Œ4zÄ(fI Ðwùûù”Hêë§DXôƒá¯Â¿¨†äççÇìk èÓÆŒ#öñ¡F7Á…ÃX!ô
”þE9ÉÇÛg¬l³¬  Osqq™8~¢P(¬®8ï]]Ì Àé<|¨ººZ$É'Ldö„ }»»ûä®®®UçNø5”3 pbÇOž(+/sss›4ñ
›ŒÔB  óôôœ0n<]ÑœRøÕ•2 pJ”ŠÏp_ùø‰ÌÎ’  8þ~þ²Q£éŠFuÐ§ê$Op*õõõ*ø„$-‹qFRûÖ©  z‘AƒD"ÑÁÃ‡´çO‹j´k• zžN§;PøWmm-5‹SÑ—vi˜C ’ p­|§Lš,
uÚòúSû|Üê ô(F“_ðwî¡ðª+§8,!1„$ €6<==ƒ&_åíå]_§×*ñ3¨XSôCáè1åŸŠÔÖæãíCf¿~ý˜¡¹  -ww÷É“®<¢<¢>^súˆ«û	ß¡4.> ¡¡¡¡øL±ê´ª¾_2`ÀXÙX{œ­sI  &ÐêxÜ˜qúKNœ:QSSSyòO‘§(p\E£ˆ€=ÑÎÉÉS'tz=]÷ì×oÄð‘þŽkbk!	  C´j¦Ÿ’s%'‹Né«+õÇ÷‰¼Ü†Õºy5 ¬`SæBé…¥¥:½Ž~
Ã‡ÈzB @h5- 9]|š~U¥ôC…&Wß ½À§®GÀX©±±±¬¼œ²QiYi]]£››ÛÐ!C‡âêÚÃý’  ºF+ëaÒaC†¥µùùóçËÊËê«ÎÓýÉ­ŸX(t™› ÑÅ½ÑÅ­ž¹RrB¥	 µúúz*é¨MÏý_k¼¦©Ô4{ñ¨p; ÿ€þý{<ñ\èý2cë{Ëeë+\oÿ+ @A«{JKêçËËq€n¡,áçëËg#ª!1g‚ `=j 8á|MmmÝE÷¿@+”ÜÝÝîÍFýúõ“ôàlÙ¨B   €	"   À„$    ’    L@H   0!	   À„$    ’    L@H   0!	   À„$    ’    L@H   0!	   À„$    ’    L@H   0!	   À„$    ’    L@H   0!	   À„$    ’    LpgŽrAÇÎ×0m34p?.¢¬*peî®ÌËèÇú‹˜ƒ•––ž/=_]]c¨3ÆFL{quuuww¸<==ôïàÀÓºÏÂ¼}ÇµÁ3™-¸èt:î—–ËÖW:¹ÞþW“êÙ©Jv¦†5`q0››äÉFxsWìª®®®HUtö\ICCƒžàæê8h¸t˜››³'Lkhó\ÆzGH:]Í%¤:c<¢‹–{·¾¬ƒ/„Ö[£Äl°'³“â³gNª¯¯§ë¾b_??±X …BÚãd`´Ðëõ´­©Ô”–—i4º‘öï‡>8p³Lk`˜÷ ,g¯TßÈe¬\@d­FÖèÂ}sþ"6ÑÏÆ%%Za:X¡© )80v%iÅ 'Ð&‹6!%çJèºŸŸß„±ãm»[iÁ¼Ð%»„¤Úzög)wÉ·°!#YO˜nìÊ îÒ&jkkÿ*TÔêj=ûyN?ÁÃÃƒAO«©­=xè`uM5MŽI¯‰lÓ+Óº„y ¶IÔ¸–žKH`C”‚0÷nçMÚ·Ë/øƒ&z€À¸±cÝ\íÛÌG“æðÑÃ¥ee´•
š|U÷÷é1­ÁL˜÷ :bã&aª|(EB²=úJÿ*íî!´oGk.?ÿ	ãÆcÍåThË4aÜÚ¨Ðúë ¢›þ`Zƒù0ïtÄÆ!©¨ŠUØƒÆÀTZÖªâÓ•U•^ž^ãÇ3çÈDp0š(´ÛíééYYYyúL1ëLk°æ= “l’¬¨{[qèeÐ:k÷ñêêêT§U´Î¢5öíœMšñc¸MM,šdÌ*˜Ö`Ì{ íÙ2$¨Ä`HöUßÈ}ÉÖ9qêdCCCàÀÀ~ý81Ú›(X__ªè³
¦5Xó@6IŒ­Álvw¶ÚšžI´Ú*9Wâææ6bØpN&“««ëÙs%VôÁ´†îÀ¼ÐšÍBRY-w‰B’}5rßp™ŽYª¬¼œ.ûôwwwÜ‰hÀj€&m¥ø	gLkèÌ{ ­Ù,$]0n¹’ìËX©»`yH*-+¥K±ƒ^‚ŸXeÆ	gLkè&Ì{ -l–÷µ8¨ÍQ¬øªµÕ\zOO»âlÍ³7±ªª->Óº	ó@›…$=N\è(V|Õ¬„!ƒ^B ä&?á,‚iÝ„y …ÍBRqËŽÛ`°6$áÄI½ˆÐ8±¬ÞPaZƒÕ0ï´°YHB!Éa¬g?PçÜîEøSC44X¼`aZC7aÞh¹   À„$    ’ l ¤¤„ Àå¥‡¤Ã…ó÷í£ŸÇŽ1€žpöìYº|ûí·ïºë®.0 ¸,üñÇÿûß'Ož¼páB•JÅ ¯êÅ!iÓï?M?Ÿö°§Ç<>>þàÁƒ­o<qâÄµ×^»mÛ¶¨¨(ooï¤¤$Ö¨Õê={öäååut‡cÇŽ)•JfžÝ»wv*?©   IDAT?ôÐCôÝ2çVQQ¡ÓY>Šk÷|ÿý÷kÖ¬ùüóÏ8–^¯‰‰‘H$+V¬(++{é¥—Xo`«eóÏ?ÿ<þ<s&=¸,ôâ$8¿âëëËl¤Z«ÍÞúyúš5ß­±±±¼¬ì¸òÕ±~Ø¹ó[¶lÊx?uÅŠÅ1O=ûÔ?=ñÄ=·ß~ÃßþöŸ?dàpÅÅÅ#GŽ?~|ëCCCéFÚ$3ËÑšeÇŽ´|¶9¶™žâÑ²eËêëëŸþùÿýï´ra—»¿þúëÁ¤MH›Û)CÐæÄ`0Ðþ÷âÅ‹é;1çÙè+ýé§ŸŽ=ÊœMý©S§®Zµª“ûPh6?šC¡Püë_ÿÚµkÇ¢™œ.ßzë­;ï¼3==ý‡~Ðj/Z³¼¼<ÒèÔ)+Ïl6Y6éÎ±±±³fÍÊÌÌdN£—§>ÃÎ_þI‰¾¡¡¡¬´´ìBiuMuë¿k^±8ðçÆÚ<öoÁÁ¯¸‚™íÀþW7×:CÝ+WÐ¯#G¾=ìNfÌCfdÐƒÞP\|ú\I‰úÜ9õ¹’ºº®·Vœ!²©««3fL@@À„	\\º!«²²òÐ¡CqqqóçÏ·÷Âýû÷§K??¿Ö7R¥‡.=<<˜åÞ|óMº|ì±Çd2Y›?Ñúè¿ÿýï»ï¾K«ž´´4úBØåŽÿbÛï™„‡‡ÓÌ°qãFJÑÑÑ´†}ä‘Gº|¶«®ºJ$8p€sg>t<''§ªªŠJÝö¼iÞ>}ú4}|ÚP1[ð1žßƒŸuû2~U3vìØÍ;Æ&ÑúöÜ¹s«W¯ž4iRû¿R€Ø¹s'3Ï™3gè©(òÒ"ÿë¯¿0 Í0âb±˜
6”6œjxq›,›”ÿè{¦Ïžðí·ß¾ñÆôñYOëÁeÁ©CÒÊ¤å§NvÓËû…~ÚÜHß©ÉDëâ½¿ür¦¸øºûèÏß¸níZªú˜ùßÏø{ä'ÿÉ\·6mÆu×ùùûÑæŸ~­ª¬b–kl¸üÏeWZZj~y†b-œ´QdöÄò"
Ûßh*ófggÓ•ÚÚÚ5íJŒþhõ}Ï=÷Ðõ°°0Öðå´ößç’%Kh?õë¯¿¾ýöÛo½õVÚí£Ñ!C†tùl´IÛ·o“ÆG³]¡fÍßÿÚ†úúë¯³žvüøñŸþ™ÞêÝwßÝÑ}(Óæ‡¾‡“'O¾ðÂ¬ÛøÓÄZ=ë^fŽuy7
C&CRaa!Í“Ìv)™ýñÇÚìR §œDå™6ë™že“e300ð?ÿù%ª•+WÒÚBWèþ¬Gõà²àÔ!IÐáíMÕ6Î•œKziiÁÐõV¤¦N˜(§ëøá_}EýbË–G¢ýrÛ4÷gnüàŸ±±ôWŠÕ­C’»»Û€’ ªW öõýæËí|UÉÕÅåæÙ³GŒ5@"2t(Ý2dÈPvù¢ùõã?6óÎ´G²aÃj°°"!Q‘æìÙ³Tô¦µ’9÷ç‹É]·ÌqáÂ…–î2ÚU+[Pc\û"S_3sæLZŸ¾öÚk!!!‰‰‰YYYmŠymTWW>|˜ò?½žzê)JE´Üµ¾5h2‡ 	½~ýúþóŸ&ç1~>Ÿ3gN'-û´›œœü·¿ý^×­[GQ6EfÎ±Ð9sV5Ô"FÝÑ£GÿßÿýŸÉ;Lœ8ñÙgŸeæ¡¹—ÚÑæÎKIâ¥—^¢b9ëÍ,]6iåùüƒE•rjøå—_z<$õ §IsÃÿ¯õ™¨)³ûH$©]Œ~ÝùÍ7_»qÝuÏ=·Üzk›ÇJ‡ksgþùdQsòyõùgŸzêõ­ž4e2e‡£ýWÊëdü›‚Î‘‘Å*ÕwßÃß3xæµòIWJ>bÄÈQ£FŽÍçYj»]²øY>!M>ýé¸8ú+ë3hAš1c†™wÞ¾};]Ò&„YN¥RÑRjþ¹øn÷÷ð¨zOùŒªî7ÝtÓ‚ÚßêÒT»f}¦†Ô%
:¨áiØ°aO?ýtû;¬]»vÏž=ÌØ8E+ßÖ¢ÀD¢-]>\*•R‰N.—3‡ †`ÚiÞ²eË«¯¾zë¥+“ššjQ¥+æ´R©‰Ú&è{ ½ª/¾ÿþû#FŒèèÎ´gÛm·1èŠ9«¾»Õ~:j´½Âˆ™‡&]Ò¤’9Ðþýû·nÝJ9ï
KúŠ˜£Ëe³=ªìnÛ¶ö CCCYæÔ!é®{ç¶¿‘ÚË<X[SãÚ\y4xHÐÔ©?=*iiBÑ¥ì(6-‰[¼fÝ»£d£oŸöÁ¿7PÃwyyÙ#Fµ¾ÛÂvýàxo¯^½÷×ßèÊÍ³g-}9É]àÔ_fÏÚ»w/]^wÝuÌrýúõc–tð¢­]v¿Q/))‰vO©Ž˜ššÊ÷sjcóæÍtyÍ5×z9—ÍGkv*ÔwrjG£v+þº——Š(CPËø'Ÿ|B[Aó“6·|ùrFC­~O<ñmh—.]JoÿÓG}Dõ­k¯½öê«¯6ç©èžÔ>KÏCí>Tš¢ÍŒÉ»Ñk-\¸bY—OKMz½Þ©šuœµøÓ†Ú‰"##Y·Ñ^Mq‰Dbï„D[%JE´¯E»aü-%%%|ð·µºôPYJNÔþþÐC1«t¹lÒw˜––Fe³Ö{&´kzÇwtôÚÚZÚ¸óÎ;™£ôÈ²ÐË¶ëüþûª•+©É¿õfœ8nÃºuyÆ]XrçÝwSYèý÷ÞcÆ#{_|nqú†?ÿþó±Ÿ¿3Ï¾_ã‡>bø’ÄeHH "m)I\yå•Ü‡÷]Úÿ‰Iuuuü¯TÀûóÏ?'MšÔQ/lZŠ˜qÌºVR´7IEéÿûß&kI|‡¤¾†6$´czã7RuÐü"ßÍ7ßLõ!zÈ¨Q£ÌßH«ZªÿS¤ li#)5ð=ÆL¢Š/åò·Þz«óÆBµ+
jkÖ¬yûí·)«ýúë¯ô(šÇ(9Q3Ýá¹çžcf£2Ø¦M›-ZôòË/›¼Í¢Ë–-£+¿ÿþ{—!éÿûßÎ;éƒÜrË-´ÅêæŒ}Y¢©F—Ôšf“nÔß|ó]: ©ªªjÞ¼y4—R@á×r´€PÔ£Yšbzë(pàÀ?þøcöìÙæ<­uËæ§Ÿ~JU^šÁ¨.ž0hÐ .òúë¯Ó:ðª«®¢ž9D,½iÓ¾3'gùÒ¥íoo¨ï"$}½mÛ¦>à¯ß:ûù„ºB©èMcŸÐÓªÓ	Ï?÷æÚwÌOHä«mM«æyó³wOäÞŽvƒè²ó%œvb:ÙÚ‘É“'·þ•j<uX¡½ÖD!Œšíi“IÙˆöê(ØÑ&sìØ±þþþ­ïF• ZmÑÖ·“Î¼—1­Vûž}wQE­Ëˆsï½÷¶¿‘Âî„	($8qb”…Ö´Qœ9s¦É×54áhóÃÓ%wwwÚÊÒ¦å™gžQ*•÷Ýwmi~üñGµZM{ð´%`– TG}ã7h³G»ìÿøÇ?˜hkúµíèß~ûíÿûß§OŸÎÀˆvf¨<9mÚ´x€Ùß7€6ÃÌvhyi¿F‹ÅÔ²¶aÃÚðóõš	é
ÝòÝwßµnŠÍÏÏ§Ëë¯¿ÞŒ—²rÙ\½z5´øõ0}Ÿ”ïïºë®NîOKVFF­[w¦¦ô¿nÝ:ú´KL¥8söyhï÷Ø±cT­xòÉ'ùÞÙsü²ÐkBíefwéÈ¼¨ùWN™ücnîÿ¶l¡_wÿøã?{¬£îýå××^y…¿>bäÈ–&ò×ç†‡ŸPÛúY]ÿsÁ+‰/½¼b…™çi;úýÎo™±÷Í•R0‰æi~¼‡~¸“»Q¢Cû…ŠÊ¹t…Ú/ZÖ2•••ÌÏ|ÝQù§s
…‚¶‘‡¢f ZùŽ=šêá>ú(½\ll,Õº[Þ!µÁÑ%í:Õ1ÀC¥—ˆˆZUÑÔùÈˆ‚ÎüùóçÎkÎš®J¢’èËo’(èÐçÎë(a§¤¤tôœT¼¤ÍÌèæ„f¢ºm$žþyúhqqqÌxXµmÇº¤|O0ÚÅ§7oÎwEsE7zWô–¨ê¶ÕˆZ'üq*<°¾í·ß~{õÕWiñä—Çî£j
•÷¨‘ý†n`6B-e´CÅ$ÒµíR$¢IK£Õœ9sø[ZBÒéÓ§é-Qìh³£Ø«—MÚß£òÕP×®]KuP
ñiRXXHMÉteåÊ•-%af\`-=„°­á»\zdYè5!Iyäß£hêôéó>AWþ6cÆîŸ~RŸ;w°°ðÄñã&;M«N'/_Ö`ìÎBëÊø¥/‰<.V}žŒ}Zñ×CÆa”s¿ÿþÉÏ',5g°–3§O\ëlÌX”‘:G;ÔfAû(ÿEsyûiÕÐrÔ=ís·^;ÁŸ*Äü±=ZïäQÛ
%$Z?Òš‚/Qõ›ö)¿øâÚqÙ¶mÕ hD«0Z™Râ;n÷AÔ:ð¤}]Tu£ö2
%Ô&EÓ‹Úo½õV“‡aw„¿3å!Z/SÃëÑ£Gùã½é
íÐšÚÌ††ÖøQþ¬8>ŽöïÓÓÓ©9ìCã`°´q²á2N™;ÖxÌ,ÍH´>·Fqj–-G4Ò.m¢öÑÆ6fô¹¼Ó¦º¾¾žr’­„|çwè’"…­:¾Ðº‚ÚîióO-hí÷ÜhÞ

Ú±cµ‘ñVôëÔ©Sƒƒƒ[îÃwk£r¦™¯Øe“f6Ú7àËç%¤ãÇÓÎaYY]¶?ò€ÚéhÏÄÒvs3OüÒ#ËB¯	I.ÍÙ¥e8)ƒÞ ­j:2¿X¥j’(W½wá|ÓµžŽ[<iò%k%Z÷-_±raÔ£åååôë—ÙÛÜÝÜã^|‘uåLq1¥¡¡ëîP}•øêë’%K˜…¨ñeáÂ…TŒ¥PB<3GpfÆñTè²“£ŠZ£U5«ÓÂÆ‡cª9ÓbÚ’•i™§•UÅ)$ýòË/Tà¥õ2}(fìíkÝè”—“ñãÇSë$•^hUþé§ŸÒº•*öŸþù®]»:YQÒ^  šÄ´r¤eŠGÌØ³„ï\Â£Ås‚Ñ°aÃ¬è®Ä‡¤QVpJ-¶?ýôæa¾M¤û/¨œðÈ#Ðúfló»²µ²@éí!#*ŸÐK­B´ñ£DßI´/DM-TedÆid“ÁH·lÙB¬ÔNDÏÌl–…U«VÑ
fìŽjÛ4ù¨5VD|m†æóÏZh‹–*iÓ²`Å[²nÙ$·™üíÃP6¢¯ãø~u­ÑÞf''Eé¾YzMH1b$Í(´[#|¶   IDATùËîÝû~ûM>iÒï½W]Ý47UùÚ?äÝµkÏœ9Ã_¿!äÆ{î3‘Ä‡H‡&¿þÆâ˜§j'fúâóÏûyzý3¶‹#$EÍ›ÆÓ*U¡½¶M¢)&&†RÕQMöÈîmA###éh·†6¢´Áké¸Ý%~‹;vìØ.ïIëÖ_|‘öNh>i9BÍäQÙ×_ý—_~Ií#T]à‡7¤u~QX7¢]ºääd*š\Óêž‚Ñ¾}ûønÚíï@ÿ±FTw¤|Ó±ãŠŠŠ˜U!‰v‘iå{ìØ1jz£Ù6]´G~ß}÷eddtçÀlÚòñÇÐQ»³Eíwí¿ÉéF			47Þÿý¬ï¡}'Ož¤ï¦µ(Q#;U»Sþ¡Í?U¤˜ñh 3û±u‚v¼)äääÐ<LÑ¤“nà´&¡ÒNGc|ðÁ©ùŒJÚÌ*f.›æ  Dùž>E|
Ž"¿G–…^sî6
"7Kî”fžùç?o	ù¸U¿È¦f —W®œ|Õf¦ý™Ž×JW^5%©UÏ†Oþ“Ùå¹Û†Î_¡”öcîÚQ«Õ”rhKsÍ5×˜?†ïçŸ¦Ù½¤¤„ŠÞO=õ¿¥43$Q¢Ú½{7ígtÙÜóý÷ß?øàƒz½þ½÷Þ3ç~OOOª‡µ4‘ÐfžZ\ŠÖõT7ÙxJ¨<³qãÆPB¢ö‘9sæÐZ;;;›ö¤ù)”Ð§½X*õwst]š÷˜åÍmÔ‚Cu#Ú%¥XOÕ£™3gþç?ÿ¡ÒÍ4·P¼c–£í÷c=¶xñbJHô$ÔøbÑVª£*½+zÎaí„»ìÑT ÌJ	éºë®Ûdäçç÷ÕW_Qœ5Wªz6Z_Q‘ÖÝG€¦Õ6¨žÒÀiÇ€ÊÏÜŸÒOG	‰Ö0Ô,KÕŠ&¬Û:_6;GjýúõL)!QËUÜ{dðëYzÓ	nŸyîù«M‡Dhìx#¢ÒVmeê¿&L”Ç<ólËHL
ž9ó‰'ÿÙò+Å¯µ¦:ÙµxEóÆ’UK‹ð¨Á‹v\è’ê½AÌïÒA•BÚ™£ýxZ[EEEñ'ßæ÷WÌ\ýÑËÑ˜ÚË:o[»ví£>J	éwÞ1óà­VK¡­  €VjTQP*•´5}÷Ýw\Š?cƒÉ?Ñ
ú¿ÿý/íúS=é‡~ 6Ú Qîô÷÷ç‹4GÃ·	z	º=z´ù¡9ö *&?¨ß)mâÄ‰ôžiýKçÿø•÷ÍBj+IOOŸ={ö·ß~K-/¿L;n+m2|ŸEÍFÔðDû`”¤)ÅÒb>eÊ
%Ø±c‡ùgVn21MwzNjQ¥LÓÍÿÎ;©M…JÆÔôC9ÉêàÇ§lD‡Rµ­z\u²lv‚²•»V¬XáííM•-¾Y°ïèM!É£ŸÇªµkcãâæ?ñDh«6‘ÛæÌqw7=[ûˆ}Þ|ûíÐ;nïòÉ#ÿ1oN«q“?ýøãuiiÜÿæbª…>9?jgNŽçå§¡¡¶(÷Þ{/í™ÑŽËæÍ›Í_&©œ@‹â¿ÿýoZ’i-C­éüíü:Ëœ¯—žáãX€:ºß#$55uðàÁÔbf`ªÑÎ+•¸èQŸ|ò	}.Ú‹¥·´ÿþËû4Æ¶L‘ÔdhæîûóÏ?™-P‰—šÛ¨!Õüþû_ý5Mbz½Ã?þ¸õiE™‰rRee%ÍZ´MíòÙøÖÚ@R-%~zNÚ‰7ó€ÿ6Ðñ‘G_)-¹Ô†E»RT£ôÙ2#Q¨¥7šdÿûßÿžyæ‹VÅÛ¶m£=:ZSB¢µG'ç0îÜÞ½{)ñÓ~Emzªýë_´Öå ]¡õ'í+ž;wŽæ7[m`ÚÓˆ¥å‚V€Ôú¼}ûöžícÐ#ËB/ëLCÛË{ï¿ÿûo¿Í2ŽãÇŒe¤Èyÿèä!^ÞæŽ—³ø…%eee»›;læ|ýÕßyDÜÁÙšn¼å–ðˆþmœ9sfùÒ¥ï¬Y3ûÖ[ÇŸàß?`mK»:µçe¦¦¦æË/¿¤5íˆ3ã9(å˜y48­õhBû…T.¢–/jÂo=Â¿çm²ÛYkT^´h]¡uJG}GhCEË9í2N:•Þjç'çQµ‰êÌT^¦÷FM0T©â»„S³BhˆÂ€MðÍmÔgæýi›ÐQ¡ˆöÂ™±C?uÎŸ?O›®¿ýío&‹4ûñÃHÒujç}å•WÚÇ8JH”“¨†AÑNú9Qb¦Ö^ŠÑß|óÎØÍ‘fÅ§Ÿ~º;'v@
§ÐC»^ÔðD9‰V)ñññíÛŒ&L˜@Ë)-û´ŒÓä£;wù´ÔàN? ­¨*lÅéî)UÓ3Ðî?R	ÍO<ñ5KuçˆH*²RÔ£¥øjºƒÑº477÷óÏ?§wB³ílÐ‡¢´dÅè¶Õ#ËBo
I§NœÜýóO?|û­â¯¿ø[¨P´2u•Eƒ@vÂ]à¾,9yqÌS
þ¤ìõÆ[kÄŸÏ’Ä<óLcCÃgŸ~Êÿª>wî?›6ñ×Sßz«ï„$Z×ÐV'j\`ÆÃ¹©YaÚ´iæ? Ÿ~ú‰€yóæÅÅÅµé5Éoç:ß;¤úÐ‚h…E	“«þá|‰vè_|ñEsúxîÚµ‹jÝ|ì»öÚk×­[×òÞhsËg²¾†_OÙ|mE³MhÚÒP5g]Ma·óû>|¸u;í·Ðf<ÚúR\¦9‡^”æš;z6ÊIT® ‡ö-¹ômÐ|BÏðàAšùi{I›ºˆˆ«û·òMÌ}¼DMq“vQøêL&£8ÛÑ.íYÑTX²d	MÓ1cÆÐv½ý}hJíÛ·ZÁ¨mŽOÒ” h^zê©§,êôM	˜ÊŠÌ8¬½"U\hç°;Ã@Ó|Hõ'þü<´B£²™E3O7—M
FÔ\ÈWÁßâééIÕ}j_ëñ®o=¸,ôš”¹qã»­&ã&ŒOLzuøH³Žô6S¿~ýV¥­MŒaÞüù²F‰hAëô§ãân¿3ì?›6~·ãÛ–ÛiwªUgrí¥ú÷ïÿã?RB¢`D%bZ¨,Ý*ðMÝ§OŸn×˜V@Tçk6|ÿèÎ—üµk×þúë¯TW ½É–A"Zã[ýh5ºtéÒ.OAE]Z~øá‡T`Æ“¥Ðj",,ÌñGs8!þ¬/V÷íMµ±cÇR¬ùã?ÌIØ´ëÏo¥Ì×¾ŒDûÊ/‡ü vÀåË—?¾ógèh\	Zð]t™±|uýõ×Ïš5‹ž³›cíð{üÞ7ÑRŸ——G	ÉßßŸž¨pÛy¡f8ÚÀS–¥–q“!‰b1Uh¿ŽçªðQ¨Þ| ŽùhkM±žæUjY›cdæH¡J'Å#š!é#S¼^±b…cƒusÙ¤¥Œ¾š‡iG¥,'9N.½&$QËWÉÙ³üéÒ(Þ>4ï÷ÿýïB‘íOtG9é5o™Êj/'¯xlaô®ï¿ÿã÷ß‹Nžymôüï)´v E‹v¯»s€´Ä¨åWŠ)­‡V¢µóc¹iS7sæÌ+¯¼²£ÁH¨–NIî–[n1§uŒöDùƒ´iÿ‰ŠÌ÷ÜsOŸš «¨¨`Æãä™­QH¢meesB?TU7ýßÿýÍi4q©ÐÍfÓÇ{ÌÏÏïšk®±¨«xç¨lIõKsÎ¢u¹¢‰²lÙ2Úw¢%×äÎO{t
¬òJ±•BâéÓ§S
ñí´­ ´ÊÌÌäOïÚý•ÕD)ÞQ£0•3©©Ž
Vt¯fÝ^6©F9iÊ”)”ÍéŠàH=¸,¸ðçü
¢e5Ñz}ÑÑõ6¿þx–9Àûï½çëç7+4TlíÌ}y¸ÞÂYåç<®"rmðLÖKÐ¦ë—_~¡ª³gÏÒ’ë­·qló%å$­Vûw
â=wöuë¦Z¯›Öà„0ï9µéÓ®&5±9ìd±`¦^ÖqûQ«Æx€^‡¶:;Ä1¨ÆÀ  bÌ˜1IIIœúX    ˜€   `B   €	I    & $   ˜`³äŠs3Ø?œ¢_5?
"NÕ‹Ô'–ÃWbZC7aÞha³$@MÊþøt$´ü«t©7ôÝ¡{{ƒqlY¡ÀâQš0­¡›0ï´°Y´á·Ü8º]ñgæ°"$	+¯.ÏÎƒßÒ„f!Lkè&Ì{ -l’¼ÚÜìŠáÜÛâuãÏ¿SUUÅ —à'–·å'NÂ´†nÂ¼ÐÂf!É_ÄÀ1,ÿªýýüéòBé½?±ø	gLkè&Ì{ -l’zìW}Õ’ü,I~¾~t©©¬¬®®fàôªkªib¹¸¸ðÎ"˜ÖÐ˜÷ Z³åÑmýØÛ OkÚ4]]]J666žR1pz'O¢ËÀ­85=¦5tæ=€ÖlyLÚHôI²/J¢#¼™uFNk½Ò²Rìä99­VK“‰¶7Ã¥Ã™U0­Á:˜÷ Ú°eHº²!wõñ´~¨¡P8xÐ`ÚÉ;¬<RßPÏÀ)Ñ¤9|ô]¡‰ÅPmLk°æ=€öl<ºÑHoæåÎÀ¼ÝÙÖ´“çééI;‹‡Žfà|hÓB“¦º¦ÚËËk¸tëLk°æ= “l’¨=èÊ kòÎ‰ÜØ•ý»;µ¨Š>iâ´«WVVVx¨°¾ûyN„v»¤I#Šh2Y1Þqk˜Ö`>Ì{ qÑétÜ?Æ>z-=õZwÙëèzû_[ÔÔ³¥¬¶¾ilItTê&7.zÒ¥MÔÖÖþU¨¨ÕÕÒÞÞÄq<<<ô´šÚÚƒ‡
«kjhr\1An«‰‚i]Â¼Ð	»„$R×Èþ*eƒqn¤$+4¿9æ+drænÓïöíhýUYÅè80ªëVwA€nÒ§ŠN•œ+¡ëb±X>~¢››â°¦5tó@—ì’xgªÙ‰J.01„¥N™ür®Üƒƒì6°ÂÙ’³'‹NÕÕÕÑu_±¯¿Ÿ¿ØÇ‡ÖbØç³«ú†ƒž6OM¥¦¬¼¬B£¡ÝÝÝGAf˜ÖÀ0ïXÎ¾!‰KJ'+ÙÙÖ€óº™ÍÍ…i„7wÅ®hWï¤qW'îî)n®®ƒh'Û¶;ñíaZC˜÷à2vmðLfvI-.è˜º†U×1C÷ƒÈÔš«±näîÊ<Ý™¤ëïðs¼”–•ªÏŸ¯©©1ÔÑ~¦¡±ÓÇ^\]]iß]à.è×¯ŸdÀ€ ÿ æX˜Ö}æ=è;z_H   èEp°>   €	I    & $   ˜€   `B   €	I    & $ ÒðÌÎ   IDAT  ˜€   `B   €	I    & $   ˜€   `B   €	I    & $   ˜€   `B   €	I    & $   ˜àÎ  :VZZz¾ô|uu¡Î@@oæêêêîî.pxzzèß?À?€9–)+ôÔTsÑétÜ?..-—­¯tr½ý¯ pÙ¨««+R=WÒÐÐÀ ._n®®ƒ†K‡¹¹¹1{Â2eC›jI ÐVñÙ3§ŠNÕ××Óu_±¯¿ŸŸØG,„B!íÏ1€ÞŒ2Š^¯§Ž¦RSZ^¦ÑhèFªR6|pà fX¦º©G¦CH€Öh%^xè`…¦‚–îÀ´£Fëqpù¢/Å—’s%tÝÏ×oÂ¸ñ¶-NPéàaZ¦4X¦l¨ÍT?v&fI Ð¤¶¶ö¯BE­®Ö³ŸçÄñ<<<@ßPS[{ðÐÁêšjší'M¼B$1[À2eWvšj­!$ ‡öwÿøs?­úŒ3M Ð×PƒÎ¡#‡KËJi[{Õ•Sº_™hY¦üÆëæjßÞ3}¿¥©Vf«©ÖÖƒ Àimîïç?aÜ$$èƒh¶§bZ‡
»yÐE.ÅAEó25	ÉN¨m”VYMSí`¡Í;ÅcU LU|º²ªÒÓÓ“Öæ ?v5UVVž>SÌº^YUEËÔøqãÐêbWôõR¡Î³_?Z‰Ÿ=Ãl
!	 ¯3ÔT§Ute"jHÐçÑ"0Þ¸«@µ—1«MËjHŽA_òø±MSVhÌv°BèëNž:E5êAƒÐ«€PM"p``}}ý©¢SÌ*'‹š–©~ý8íø©VTTÄl!	 O£UyÉ¹7W×Ã†3 01|8•”Îž+±¢gm§i™rÅ2åpô…óSÍ†=“’ ú´²òrºìß€Fèî‚þý)!ñˆEÊ+*èr –)‡š¦?	l!	 O+-+¥K±€Vø…¢Ì¸€XËT²zªu9 OÓVk™±9Ÿ@+žý¸…¢Ê¸€XËT²zªu!	 O3¸#A„!€VBn¡à‹`™êAVOµŽ $ôiM+t!Vè —Ï°fuHÂ	Úz„ÕS­#I }ð»hƒ?Í­ÇIñË†ëVOµŽ`*   ˜€   `B   ØNÇz+ôI  p~:µB¡ÔX7Db™\.µ¾I£ÌWê$r¹TtÉÓªDR¹ìÒ{ÚŒ&/iÁj»>1XÌz„$   §§Sf%ÌMÉ·à¢àÄ/7FÉZeuîêqÊðÍ›ãƒÄ­ž6"]œ¸9Z‘®èìù¤á¿L±,êhòV'eæ)XRzèæø^“’ ÀÑtŠÌÒUÒà¨ÄÄH¹UëM:/;#;_,éønêœ„«UòðèèÈ`©}ö“IŸÔÕB£Se'Åeª:¹‡F‘•'
‰•6Ý ’†DÆ3•¦é	4ŠœÌ\•HÒ²äH‚e–-DEfLL†‚+~)Òcâ¤S­\Þ{B 8í¹¦¤äÒÊX$’J¬]cŠÄbM~vf^ŽJ´1-ªƒ¯&/cuf¾‚š¢bM¬Üuùés#RXÔ—[ãå¢‹ï.sÞÜUØÆÍQ,7'¯y›Ñ±,$<¼³”`["‰,(¸ËYN§PPãY'!‰BPVzºXv1$I‚#£ƒ[þžŸ’—™+	ŽO“2«PBŠ[«‰¸>I"·Ç’ Ú˜.ëM»+I àH:EVRJ.EqHh°H™—§¼ôïb©\.5#:‰ä‘Éñ¹sr“âVË7›êî@¯´:“Zd‘‰±!–ç*'=¥Ó}qŽ\,A
E“4onzWsœN£¢ú¥óåÅNO:u-™"™DG‹hÓB ’ÈƒdæîÕð	)G%	—f'd²Èä0UJJvÜ<[Ÿ.ï5‹B 85ÐÎd®±:£ÉMÉM7q'qHâæõQ]¯EE²°ØÈ¬¼ôü¬ÕÙ‘#Ûìžr'#OcfDRçÆÍ]²qkrPóM’äŸN$_|>c…)Oš¶u½µ»Ö ÎŒ–™¤ˆ„¼V=Ãqó2š‘†¯ßšjÎî†:7%&&=O#	I\Ÿ.ÊÌ¦Û$òÈØõ¢˜I9qóæ)ÓÒâ{I!	 D—“E»¥ÒÈP¹¸m
Ò©ó²²ò5L,1}˜N™””¡hÝþEûË´»,VgÆEdµ¹3íI+ÕŒkrKY0wõÅ?ˆåQ‰‰a–üu*5½‰CCƒÐ¸ÖìÙ³gÆŒ]Þm÷îÝ3gÎd½ˆ$4qcZh×Ímóæ&YÒÉ»…8$>5úÒNOÔBž”eþau\›Hžš–LMkºæ¾à"qPÔúâ„¸ª£’ pwÍ‚„,¥ŽIÃR7§†·ïE­ÉKÊÉàÈ‰Šê`“rJ^~~ûUµZ©Pwôºíþ(b*³WöEvf6ŸÊtêüŠw®›“²ù½‹e¡ááMÎ'99ù½÷Þ{å•W~øáNî¶iÓ¦—^zéÉ'Ÿ|þùçYo¡S+óóòºî¸­T[96‘X"¾´ùZ'Î§}µùO»ù§x‘‰ ÄE§/Ãt:B  +ÅÅeã$,>>ÌÄqfšüÌ”LÚÁ”GÅvÔ_A$Þz(š9£¬Õé¹­·5TìÊÈ»øk8$,=’œËÚµk)!Ñ
@tÙQNâ]yçwüüü,XÀz®4aæ}­š3ÕŠÌÕ)y—<”Š¼´{cÉA¢ÎR¨÷D$„$ °+
?I	IYŠæ¤¡ÎŽ‰Ð©ÓR£.©ç«sW'¤Pˆ¢¦°(»¥BE¨|•®ýÐzf…$¹¾]'eÆ¼;’ò8™#GŽ¤¦¦¶üÚQNjIH¼+VÜtÓMcÇŽeÎL$OÞÔf0I*,åååkd!¡&fm‘XfùÀ:EN†ÉÑ’zãPÝ‡ öB;½óÒ¹€Ä­ßÓC4Y	qI99IsïÈONKŽäƒ×G«eª%ÆvÙ›“ë¸”«4}`¾DfÀx˜ŠøîM:EFLD†*8ùËv¼á²BAgåÊ•K–,i¹¥}Nj“ÈªU«œ=!q¸ÃË$:J© yyy
E~¾‚oU«¤×s=‰Ô9qsd±ÈÍ_&[¹»abÄH"=bnŠùÍm—„$ °qpTld~º:$6>Š‘.jýVyz\LJnvÂ<Ž;ˆM“›“£f¢àØäè®Wë:unFJGÃ‹Cd¡ÆÄM¬IýrcxwD£ÆÂôU›½tj‰SöÞÓQ]Þ|ðA¡P¸xñâ–[Zç$“	é¾ûîcNNGó}V^¾â’‘»ÄòÐ¨ð°Ðàà 3K¤˜i-… ö#	MÜzé-ÁÑiE1ó’rs“æÍËÖädS©IÙ¶®Sâ¶ñG“—47"Ã>»ºªKû!óãCOûœÔú
oÍš5wÝus~b‰X$’……ÉdR‰X—»:)[–¼1-ÌÂ¸˜$î¬·’*'!bî¥‘‹u©o| &–G¥­×ÅÌKÉÍËÎ¦F„ ¨õëãCœµÿ3ú$õJ”“ÜÝÝcccù[ÚÄ#JHaaa¬Wà¬×‡7ÿ¦S¨3EÙ]tÚžŽ#uÚ£[$•·9ýˆN§RöÍÖ6„$ p<Z¥jêK¿>-ÚÒqåtE~nžêâŠ\£PYÝÐ7û£öwß}·««ë¢E‹ZrRJHk×®3gëÔ9Iqéùmãºúå¤Ì››Ñ¶!XÁe§ì¸ÅÅÝqPtr¢ñü#:†Y¬³Äúß®OR~v>ú$ ØF™—Ù´ÎçúvOOéðÎÒð[SÛ˜­ËÏˆ¹8pãŽŽiŒãÞuq/ôIê½¨P$þùÏÖ××·ÜèææööÛoßvÛm¬wÐiÔJ“„ñmš|Œæ’¿ˆD*wø®4:‘LÜ›ŽÁïaI à :U^æê”ÕY|>â=ëx¶eÄl“¨….56TÒº’”‘””ÓùÉhM¼ˆÎxâÍ.î…>I½…¡÷Þ{ïñÇçsµÁ­_¿þæ›of½†H¹ñPdë[øÖÞü Ä/7Fµiîüè6*árCÚsç•îpaQeÍ›œeêè“ `²ÒSVg¶ªØËÂ“Mž–¶ùÊÌyw$t”MDbyPHpëŽÛ,ÇŠ}c7æLW{Õè“ÔëQ$¢œÄùþûï_ýõ¬WÓägfæé˜<(ÈÂQ4Ü¹uÄ’ŽŽ„IC7Ë4´ë@†¸ÝŒˆÈú„$ °2/77;;+;‡?¹‡HŸ®K_Ò½|a›>IB	m} å$* QÓÛåââ2”L)·lÎÕ©¹!U%áÜàa´Ÿ-âÌ¸¸çÂÄ$Rf%Å$åˆÂSÓâC¥Œ-?%_š)ï“½÷’ À^4ŠÌ¤ãè½yHhxTTxˆLL5¢tÖM6é“¤Ó©¹N¬Æ¶‡ÎmYòòÔm*IÖŸzH¯jb3M£ÈNŠ‰ÉRrÇ;¤&†Y:š¶:?W¡‘„Ë¸E,‹’s·^œ©à›A‘(GÅµgóõ&YHdxvNBÂy¹ÉëSû`PBH {G'&Š”²ð°™MW®6é“DAG¥I¤\³B'G§ÈŒ›—É zF‘•’”žÅBM$çF¯·x‰Ò©r³ó(#…™:%³NMû9JÆ¤¡ñÉ‰Mƒ¿2.LE®ß)Ïˆ[”07B‘ºÞòlÖ»!$€ýH‚£âƒ™ÍuÕ'‰?Ë•N,ë¤…N­Tª™$HÖá M|ªé¸f¹‹]4tµ†
P\“…'9GË‚äb•u"£cÃƒ­SL$¥p%RHÛ,ÚtâhM~zœB1]|õ ¨´Í’¤¤lqß›Ý’ àòc<ËU§÷Ðqm:‘œo{Å¦mÖHd—ôÐ g‘‰Å¹«c2ÒH¾†:7eA\F¾$<u}r8"8ˆH¹þËÈ®ï'	MýéDjÏ!éø,=â èõ›Y'¯–¼Ñª7)„}I!¬·BH€ž ÌJˆÈï| Ûuú1ñLê¼œ|HÒt€XÔî®ENzJBz®š¶ á!|IPxT¸")#+n®2?99>¬ovgè’  Gh”Š|ÖMTÖIX§6·£¤ sÉaËüˆ1*±T.kTÜŸ[ý]•—“§……š.éT¹éqéYYÜs‹ƒ"“S‹ñD   IDATÃd-}4Â7Êƒbb²2cæ*ë×Ç:í)U  {’  'È¢6oµzœ¤f¹\¤JçG_ÉÂãc[÷·…K³²T*w|H}ñÏÒ°äõºlMûî"ihòF™F,QgÄdJƒ£bcÃÛ‹¨í!u³,8)!S‡|psáFœ5žÈ¦å²õ•N®·ÿ zŸóvÓåµÁ3™“¡ÚB©aRy´‡Ú³Út×†¾Çº¥Ãi—©>Â¶ß?*I àŒºîzm÷w Fo#€>Î•@æêÊ­ ´Ro\(øÄ"X¦zÕS­#I }š@  K½AÏ  ƒž[(„!³–©dõTëB@Ÿ&4®Ð€Vø”#
˜…°Lõ «§ZG’ ú4///º¬ªªb Ð
¿Px‹`™êAVOµŽ $ôiþ~þty¡ô€Vø…‚_@,‚eªY=Õ:‚Ð§ùùúÑe…FS]SÃ À¨¦¦FSYéââÂ/ ñû2~™ª®fà@Õ5ÕüTã'M $ôi®®®%éÊ©¢S ŒNœ:I—Z1 ››[Ó2¥*bà@'Oq+1šj8º lfÄ°á´% 25ŠI D[­--+¥…b¸t8³Êpé°¦e
Å$GÑj›¦Ú°¡Ã˜í $ôuB¡pð Átåð‘ÃÜú8Z9LW†ÂÌo‘HÔ´L=\ßPÏÀÎèK>t´iªÑ
ÙB pÅ$OOOÚ>täèÃh¨©©ñòò¢jëz¸q™ªæ#ØOcc#}ÉÜTóô&•2›BH ®gÒ¤‰WÐXiYYá¡ƒ¨'AD³½â`!-"¡ˆ‡nökqss»b‚œ–©2n™*¬¯G=É.¨†D«¬2ãT»b¢ÜÍÕÙNp Mjkkÿ*TÔêjixâ¸	 o¨©­=x¨°º¦†f{
7¶šù±LÙ•¦ZkI pQ]]íLWVq‡Ñ¤&«»e ô
zƒáTÑ©’s%t],S”qw·å©ß±LÙÃ%SÍG<q¼§Z„$ hëlÉÙ“E§håÎŒƒ¾øûù‹}|hÍŽý`¸Ô74ô´‘5h*5eåeÝH›Ø‘ÃGPˆaöeª›zdª1„$ 0©¾¾þ¤qGý“àòææê8ˆ
<nn6îÎÒ–)rØTCH€Î”–•ªÏŸ¯©©1ÔÑ^œ¡±±‘ôf®®®T¸úõë'0 À?€9–)+ôÔTCH   0C     ˜€   `B   €	I    & $   ˜€   `B   €	I    & $   ˜€   `B   €	I    & $   ˜€   `B   €	I    & $   ˜€   `B   €	îÇTjúÇÅÅ¥å²õ•N®·ÿ   à²J   €	.:Ž¡’   p)T’    L@H   0!	   À„$    ’    L@H   0!	   À„$    ’    L@H   0!	   À„$    ’    L@H   0!	   À„$    ’    L@H   0!	   À„$    ’    L@H   0!	   À„$    ’    L@H   0!	   À„$    ’    L@H   0!	   À„${Ñ©r3’’2ó5   z!„$[Ó(²ÓSŒÙH—ŸC1)ÝÆ1I§Ñè   ØB’iY«SÒVç‹B“#¥ŠJLV¥Z™Ÿ———¯Pë¸_T*N§ÊZpÝôˆÕ¶É]Ef\Lzžš]6tªœ¤ysçÎˆËR:y’Ô)2c"è­ÎKÊQ1  pJîº¢Sædæ(™D*“JDÞS£Rj$a±á91Y™«³Ã7FFEg'¤§d…lŒ”·{¨&/anD¦’É£·nj÷gu^Ê‚˜µ($ùËTyÖ¼ˆLIòÖõ!á!’˜œ,EtP°˜u—$Ð:[©Þ¸9±ÃgÓ©óóÕÒ`¹„Ù€F¥Pé$2Y_cGUjÄr¹´“ÇŠD:•‚Þ¯HÎœžFI˜‰%h pVI]36 e›Yp‘EmÞš—“‘žš¯É—›Ü²‹Äbîf‘Ètf‰Dü?b‘X”¹:+W"ÍÎÍÌS‡ZŸ[Ô¹Id\â °0yÇ	)7eÞ¼…$$>-5:¸»AI£Èˆ‰ÈP2ë‰‚¢×¯é8d5}gâ¦/Ï‰qÓ•¾aþ  pFI]IäAA*óB’H&‹eañ‰ê ]PD$–FFuxï.¶Æ½È—¤¡‘ÁéqYYŠðèðPyn^žBbU-‰š¤¨B•¡0&¤àøõë£ƒ:|cõ†q¡*%bn~bZrTM*JVÓ©”jzC¿	çG-šÞhïyÃ  }BR×ÄÁÑë·F[ôYX´eè’4824(]£ÖˆBã·þÄ¬Ã¤¸¸Œ<.øˆä‘©i‰a²Î¶Ñ*ÜlÊˆ‰IÉS«r’æ*©ë“ÃeÖnÖÅAÑŠ–H-mmÓ©²bæÆå¨™$,±‹wl6®±‹šƒ¥})£Ô××ÿ/û‹?ÿ<@×§Lž|WX˜«««™ èƒ’zIHòæf=2;).¡iHYXjZr¸ÜŒR”H½q«4i^L¦’)³âæitëS#Íx¤®¥VÒúÉ¤Rf)"+!…“„ÆÇ†Zj4Ê|…’ë
Ÿ¯à¨¸ïš×§uRI»¬PZôLìáÃ‡ù_úù§]?þ¸ê7\\\ºü+ @ß„ä0š¼Œ¤L¥XÚÒÆ¦Sçqýs¨¼“‘¢n_]¡­:×Ä§Vd­NRðÒéÔJ¥&86ÕÒ»:/=.&%×Ød(KNK6'æ´IÃ7ŠE11ùUNÂÜyÊõëãC:«©s“’rCCÌnã2ŒZÇÄR¹\Úú­©s’b¸7.µ°Š¤¡¯J©R)órsóéšB©n¼›Z©PktÌÌ^A3S’Òs5ÁÉMtt×i4LìÌý‹²·okÉ@¼?üùíw;gß2«Ë¿ ôMI£Óäçf›êÿ­ÎËÊÈëøqªÜÌŒÜV¿K$‘–Þ®Qd¥$4j)’‡'§&†Ë-/ˆ¤!‰›·Êp×çg®ÎŠïðˆ8M~ÆêÌlE¾<<8Zn^l0öéÎT1Yäæ­É-OLÙ.&Ž*XLŸ–ÖeI£Vq]–˜&'á–É:‰O!"R–—É}!âèØŽž”Úãrrr4òè¨æ¤'‹u¶TêÌ¼è¶Ýæuªì˜;âò%¡ÑÉÉõp×iÔ:SE7Û;pà@ûóÏçcPç è›’:Ò|à¿L&µ®> S)ihdhSùC$–«ZÕ3t\¿h.4Idr¯¡SS£Î¸e—·ªÚˆ$æA¯£x•’²:[Ñ|¹4,6Þš„ÔüÊ²ðäIR†.29¶ãtŠìÕÆ¡¡ŒŸNnfûZsë‹M“—¾`A
e‘<Êt£˜N£TpMh¹¹y
•šÊBª–Ãé›’Ø‹‚ƒƒé²©F¥SdÌËÐ”‹í¸4¥Ê]—­ËB‚#›î%‘‡†H33UyYyªÐ°K>˜N•O/®Ñ¨4vÄÖäÄ]7yuphXXxXh°\bÏ´d¢á¬‘þ3ë¯  }BRG4Jüïˆ$L*ã\ÿïÍ­»sëéw¤äÓÆ:msTûŠ‹:'nî‚,•$$~ãú0‹*S+²³Ò33sòT|TK%:•Ú£+RA)~}§=£Ôy)qI¹”AÄ!±Ñ¡æ÷@jzwMY‰¾üª!)te¢Ö¯7Õl§SeÎ»%!Ïô§’…ÅÇF†µTåJH¡GQòŠì¤ÑR&ËÎPægå(Ã›ëa’ ðPY&Ý˜¯
kÝ½Êh¹×‘YBuª¼ìtúI ‹ŠŽŽ•Û¡›Ô”ÉWæîÊmsãUS¦˜óW €¾	!©cb©L&é°›IKH,“Ë:º“HÒ­_± ¶ Sæfe¤§gæ5âÌmt£¢Ã¤¹1sSò™Ýž3Ž.`,ÓÈ-)‹ði‡áˆ‚LfB—¸ŽÕ©Ñ¦‡Ñ÷NífJšLÆ*‘ñB¢N_“­ËBÂÂ‚M$4zæ¤Õù\ôŠ¥V±N§8ˆ›*%??+3?²¹P,•g¤+ò²©–ÞÒRG)'‡â[dqFÑËH•9ÙÆþgLŸ² ;…Þhdd4%2†¥9wÌÉÙ±ãà¡C-·L™<9tv¨9 è›’:"	MÞÚÙ&B“Ÿ17]ÁäÑi[£åöh%iÊH]¶ßhTùy¹999¹9¹--k"Yhl||$¿•Õ)r™pEš¸,.Ÿ‰Câ“#-ê[®Óh¸·.s©RÌ< ÍÌ]l«4A»ñ§XÉ%•êÜÎ¾-u^zSDŠJìúíÑW´:?‚g¾:¸©–%–…†ÈÓ”’rUaÍÍpÔ®™Íõù’†„Y<ŽÅ®ØÔädŠYÙYYYÙy\ØÒÐk&åf¥ÈBÂ#£¢ÂCl’•\]]ß\õ¯/²¿(øóO× «®ºsÎœ–ƒ×:ÿ+ @ß„d-ÆØGXd¯#š¸Ø`|®†dVå¦pÕþ‘4(4,22<4Xf‡›Nð=ˆŒ#0É"ÓR£,k0¢/ÓømRFâSe‡hY‰-P@§äÎTÇW‘Í::Ú#C$y9*.%…„6§$j³Ð‹ñM£ÈÊæú`QùÇêÑÄÒàðhúIåú‹geffq¥%*f&åff„¥mN³Å€Nîîî÷Î½—~¬ø+ @„d%®y³.±OJÒ©ùƒÖÅ]õ—†FÇ†*³XPXxdXˆ}»þšÆµ²qãpoW–ºÞ‚#ÿ›ŸAÍ÷Q—È¤vÉvº–‘$aÉ©QffJœaAâœªì¨4¬©åT,—·¾—:/“ËHâàÈ¨àî¿y±,8<>8<61/+%%%‹ë·.é¸9  ì	!ÉJ:UÓx„R«»QÎâb…Ž™ê|¬ãÆða\Fêòà:‘<rý—‘¬‡P#[\LÓ©ì¹„”jÅˆÜ:e~>÷Ò  ûŒ€-q—èM†§Y4b¸4$~ã—‰UW»:…kb¤ÊW¸Ìvï]$	ŽLÝ›•¥”…u¿÷…öœ<yRyLYSSC·ôë×oŒlÌˆ#¦LžÀß­¡¡áý>P*Lþ  x¤°u   IDAT¯AH²mÖ#AJƒºqb‘T$b&é§fî´i"™\Ö³§Kë7Š7‘…§­O¶ê¤!:E^Ž‚YÕïÙ\TÿIÞ¯³ôt²bY¼“?SDJâÆq’„ÅFÛþ­‹e!QñÝfœ**zwý»¿þö[û?ýòë¯ü•™3f.\°`ðàÁ®®®?ôÐS‹b
þü³ý_ @ƒs3Y…ë9Âe$Iµo1+‰åQi›·nÝl¢öÂqdÛnµ•î¢w˜3wnŸ¸³²m¶.!qã0¦gp‡ÞI‚Båv„"Û¶Zé”Y	MC]FE‡:ådúxóæÇŸX`2!µ¶{Ïî¨ÇËÞ–M×…BaôÂèŽþ
 Ð§8¢’TPPðíw;‹‹Ï466tr·‰&>6>ëT¹éÆ8ÒÐð {lÖÕyééÜXCLb—!sºE§Väf­NYm<èq)295>ÌÊE$z*.ÊÃ#ƒ·hÖÑp'ÝeâàøÄÈnÞh‹ñ«LÚúùç¼oæëêêÖ¬]ëã#¾1$äª)S¼½½«ªªLþ• ôvIÛ¶o+m9÷¤
¿¡®.ú‰'˜sÓ©rV¯6M&³KFRç¦§p
&‰Št¦³¯R<ÊÉHIIÏm†‰Rbbj2ek#‚:ou\B6÷tòèDÛ5Xé4:»žëCÇé7Îø¾eá©©‘Ndöý¾Y(w×.ŠA...C>|äˆÉ¿2  3466©¸Õä0©´÷Ž'bß¤ÕjßY—nþý·lÝ"pwwêzmÖcb²ŒÝ‘"c£laš{ùp×å‘Ñ¡²oÄÑiT*¥"/';;»eo"	ŽŠ³>éÔy™	q|o*Æ$Û$"5— ËÏÊÌ‰7y^6F­R©¸ÿ5LfÅÀFeöê„„~°®–s6´¾ƒ¶E4š
þŠ‡‡G' è­b_~%éäÉ“t}äˆI//ï¥ýí’Ž*‹òÉ?
…<ü0s>êüŒ„¦¹ŒGº‡Ú¶Œ¤Qd%Å%d5wƒNM‹µ]_`+ÚttÊÌ˜IÍj-Ärn„ÃÈ°nô±Ö©ósÒS’2òŒƒ;IC“×Ûª#–Ë¥J•.?cÞu™R¹LÒ2¸¤N§ÑpÉèâÇ'dAÊÓ(s2“’RøBš88:--6Äút—ü p¹ í~BâKÅÅÅü¯'Nž|éåeëÓ×¹ºö¾nÐöIååÖìznÊüˆ~Ì¿ÿ!Cfß2ë¡H;OMÙéI)­7ëá¶e›Š*ÙéY9MfsgtÝb³t:uÓ¿–<J$ÎÈQ*¿Qü·Á(•êœ¤ˆ™ü³›ë¢lØIBO¯‰Iáz½sgVur_‘ÈÌ“Ñ{aeddf5ŸîEŸ–Ü­	¤ÑóšN§³WL’ÉºŽ³ã‡˜|¬O }Ð¡C‡ZJJGŽ?~<ëmìÝ'Ég§‰±qÓ‡UÚª…ìÕŸI£ÊÏÉá’HŸœlÓ!ujeNNSBâš±cÃmÚË¥éœÜ¿uÕá²Àú …N"

²Ù(”c6oÇ¥(dÑñ±a6ïÎ#	ŽÞø[´F™KL©iÿw±D*‘rdR©Yã€jòVÏ›—®hŠ2byht|bTH·ÛØDbnüØ~uO^zšÃ Ð^}C=ë…\øY¾SUK×ªÖ}¬:ºÞþ×örwízuE2s”-ÿÍòññav¢QæåkhÃf—!­uªœŒŒ|IXTXP™2Ž(ž­‘‡Ç&F‡Éœ²—6 €³ æ¶¨ÇæŸ-)i¹eèÐ¡ÿ~w½»{ïšñ²
I¯¯|J   zÎ‰“'_NZ~úôiº>jÔ¨Ä„¥RKN¶é<.‡æ¶šÊJ   =jäˆïý»¨¨ÈÕÍU:C     4suu1bëåpZ    PI   ÃˆÛ    m]6#n£¹   l†q›OH¬yÄí††Ö!$  €Íê`ÄmÖ¡¹   ì«—Ž¸J   ØÌøñã¶¾eèÐ¡ãÆŽc½B   ØŒ@ x%é
Fü¯£FzuyRo<'	Cs   ØFÜ   0#n   \¶PI   ÃˆÛ p¹ÐW•ê½¼ @÷aÄm ¸P8ªÒW)·<ÿÈCK?9ZÅ  º	#nƒ³Ñý$úŽ°ùÿ.tÀVÎ‘¯6RòCò“ÑKÖlÛ_ÚúVý©­	=ž°¥(04jÎpå¦·¶+õ  [0âvß ?[˜»}Ëö?,3p¿ûËBˆ~bî”€öw­:ºcÓ¦{UeÂ@Ù„É¡sºsÚ a›ç+Uþ¼cë¶/((1>Ÿ—tæœ…ÑMo{¿¦;ïÏùèÓoö,Ñ2/î9§Ížq£Ìt{ˆ¾¤`O‘!pÎµÃÚý½´ð›»‹˜,tÎÃ½;û°ûwäþ\"œ>gÎô@!ëD»×2÷séÏþ¶ýÓm{
•Êcô™è~ô}ÎšÛÅËõIg·?•VÀ&Ç¬{õÎáÆ¯G¯üdQl†’MŽû÷·Zú|úS»·þ|ì˜A{ðÞ‡îlu»pøì¨;w<÷á»;¦½:÷á™;^þ|ÓÏ7¾|“ÅÏ Ð…^:â¶}C’¿ s þýû3Ñå¼±tÕ%­o+Sæ®‹S­x}ÑôK>VÕþŒç–|zŒ‚ t+QîÛ‘¾ooáâ7ž›=¬yû¯?»{ÝË+·+­§Uíþt©²dIêâÛä‰¶¯®åž“~~Ø·lÍâ™í¿S}ÉþŸ•†ÀÙ3[2’¾´ðû;vïÙ»ÿ H¦Éfß8œµäÙßr·ÿðCÁþ‚ƒÆ„ã¯<»óÔrék™û¹ô%»ßY¾bû±Kî§ÌÍxq÷s–,{ræ 	J¥»W,LÊ­
”÷êòõõUEªá„¹‹—<fÿw+ô÷ç^B ¶¼’ÐËËx)0‹U)·m?h`þ3}hz›¹Ç{â½ÍÌYùÉ'{g<÷Ä²*ï‰HH Ð=üˆÛgK.nA{ïˆÛöIcd2OOÏêêjfÞÞÞcÆŒa6QUøï•\FHgÜûÀœ§Nã]U˜›ñÆšEªío¼;yÝ’[¶5U{3ÞàRà‹“©¨tÆÊeŸü°nÝ´	Ën5Æ$½r{²1IN½'bnè´	² ½òçO×¼õùÁ’Ü5oM–-Ÿ3ìâv·t÷[Ë	ÉkÂœ˜EqÕ£ª£?d¼•öó÷2ù~KöPn¹qæÅ:Sé¾Þz¯¡«ª/ÚóášOvy¿^ËìÏ¥?»wûô%ùOžµð±ÙÜ':µû“ô´O÷–Ûž–1}Â‹×:4N_ÂP¢Rš{×ƒÛ>ßsç´¹Ã“éZg$¡Õ/YµÿÓ5ÛT¹¼XÁÖw¶ù«¡ìXQÀäÙwNšÞsS .üˆÛ/'-?}ú43Ž¸˜°#n›@	éåÄeÏ¿ÏìÌC$Jzùeºd6á-»óþÙÊ\Ùc‹çNlÊg/zY_¶(moÙîí»‹fÞÙ¼õÿy+—g†Ý³øé¦ºQÀ”‡=¦|ú­}{?Ù^xíÂ)ôÂa!sönÞó\TËfHvÓÂe¬ìÉ¹e{·ï>5û1MAÚ¤mÈ¡MštÎËÉ¯m*êx¹qQÚÌ(½ÐÛÄ¦’ZÊvjg^;úbsÚ ÙÉ_Î6>]Áš…Ïo/éàƒ
esÓ²çr×Jw/{|ùn-ëÂ¥¯eöçŸ³dÍ°"áè‰ƒšÞ£÷ð™ó„Ú…K·—”ì>Vzm@lŸ&?¼üõ9fõÂÑ—ýœ‘ºõ˜aÐ„	–Ö‘ô%?¼µê“Sþsž[|§ƒÒU+U…Ÿ¤ËœÌ Úý¥ªƒ{i¿?FíÈˆH `qÛ\AW]õöš´ìíÛÎœ9ÛØhûÎí..®C‡¹ûî»FÍlF8üÆ¸U7¶»qÚì‰^{÷jKŽ–é_"*)È) d1:töÄ‹	E8læìiÿÞ—[´wwQÕîÂ€k&_ÛöU¦ÏžæŸ»£¬¤°¨Šá·P¥ÛriKæõÐµmš½L&$Rvp÷A­ÿ´™2lãÚ¼–ùŸ‹RÑä‰mîç=zÆ”Àí9%U¥e=Ô]Ø{ØÄÉÃÌºgUáÏz®Auâd‹»P	¹ÞmÇÓZð!õÜ}»Q=jVúÛ¿W}Ju²a³c¢C†µ{>j-Íxëóc†Ài7M@B [º<FÜvDùkÜ¸q‹Ç=Ë./n ­ÞÐ¼¹«*)(¢MgàÄ‰—n:½e“e‚Ü‚’ƒÊ2ýDïŽ7u^þô|eÌÀš¼Jþ°¿ŒžpÚ“ÍÝh•þ°_ë5e†#¶ræ¾V»ÏÕm÷Mz{{9¼Àb!}ÉÁBcè´Ñ–ÍM]‹,8°iþ
ºõ½T)·¼¶r»Š	&D½øÄcLtÛ/-ÝÊuE6#D†’  ÚÁ ÐÊÎrGPûû7mÔõUE%´éŽn[ôñ6,€ëïq´Ó‰¾Œ{8óhêk¤/=XDI <ÌÜViážƒZ¯É7NtHF2ïµÚ}.Ó÷:µo‡1Îµ&àUÝ‘¾$öéÅ+?ù­ÄÞ…(ýÙƒ»•ÊH3'v8]ªNž*5ùNŒ5@=k•­•……g;	Mz-WvêVFªRn[µ<ÊœÒÙ	KæŽ1ù¶›ê ÒiÈH  ¦` óéÏîÍ-äêF²)Í™H[Vj0Ä×¶!Cè=ˆËUe¥l¿Ks($xn‰DUeetô.-üæÓO¶í)0&6uîüE_Û¾©§TùCA·ñögvgîkµÿ\—Ò—ž:X°{Çö-;
´iè£÷O±8#Uf<·˜ïjsð`AòÅÕ/ÚñÀu}Ñîí4ågNî°CRiá¦¤¥?”uü$eŸ.
ÿôâ¯³W¼7½ƒôU\Ûe+ëRRË±„éœ®g[ÕÑ‚ï	/}óúÂ>Ù«¥:Sh(2 €)If£-åŽý\FšÒ\IÑëõÆö$»ü|‹þbËœ‰çÛ»m/mTý§‡\lZ«2¶?	»ßxæóÝ%é0™´ì”ª¬hß§/?_öòëq×^ªŽí),ÈfN°ƒ•™¯eòsµümËóÑéÍGÓ	¤³_\¶è¦a–¿u}YaA«áÊ
v+Ko
´W-­´`û6z5¯wNÔá„SBæx±vs‚¾dïŽ=\›¬`ôÌÙ­:4ùOì¸s“^Ëee¿¿ÙEê‡u+Vq‡zM¾ùó§T~²rñû†Àiócbîm¾«tï–Ïq“éž1©
 À„$3éOíÈØL[w¯iÏrqÛÕY;7 Ÿ’L’öóG>4S   IDAT÷ãç>Ðnä£¢Ÿ†<ýö¢;»øú’ßþ½|ùÖc;ÞZ7M¶¤õ JUÊÝ{K#&;`@Fó^«ÓÏÅ„^£'ÊªJÎª¸ú˜Aµãåzí’¦i!7^áâoB»}zåŸæp0†ÌÞI±Ê[vçÂEín­:ºuù6þmL¼7*zŠYT_Ê5W
½ýÍì«¥×³¦ÏO‘ì“÷)!	d³ã^Œ¹Ñx00pâÌé»KönXúXÎì¸—¹ÛK~Õ½¼&?0æ   ¦ $™E_”óVmú½¦E-lUù
ù¡ýLÕ‹ŒE&/aýJÎîÎHÏ-¡(ñÈsZïÇ7ö\?®hÔœ1„ÓY8gïóŸíÝ¾¿læ –”RUôsA‰@6×!É¬×êès5¸vÑÆ£áô¥…»7¿»fëÁÜ·ž×
V7(e.aà´;fäòCxMžsç;5qáøÃóyø)¾uÖ¼ün%£Pñ++á2d` YåÁÒŸW%löŠZ¾…A8ó¹·ÿzŒÉ.6u
&Ï]þÞŒß¶¦½ñþÞ¢+çì˜3YÿÃŽúâ‹™ãøQ	  ztÜ6CUá‡«ÖPDš=û’¹À80rßHÖwNuî_“Çmé•ÛÞHË-£(ñÐÂ;/é	,äï?lÚœ6Ýt¼¥Ó¸ž@†’ÂV”«ŠvïU	†Ï˜<È©ë×êøsµ'˜xã“¯¾=YÀ´{7|´·”YD8èÆ×½÷êÓ÷ß?ÿ…Õ¯>0Ñ>©ª`ÓŠuŽýgFE]kYŸ'JW©/¾–Ë%‘ûÞÈ=Von÷ò2å~îHºa²À.?•þèÖ•oì8vpïÞ¢–~àÞÃ¦´ï&4ýäuo.œ(`%{·ï(àŠ}÷/†ˆ Ð!„¤®è‹¾Yµüî¤3ž^zén·0 0Ð‹‹.EemUÒ–pG©±ÀöÛ9}Éî·V¦q‘kòüçæ¶Ù¸{NFµ©Ò’vÇkqm/Ü£µ7µú¢={‹Ã¦MvÀ†®ë×êôsuÀ[6söqô%¥ågËõ6ýÎ¨ù7Ú«?ViÁ†%Ë?åÆŸ½èéÙÑªÂO­¢„ä?3æå÷6NºÎg[èOíÝ±Ÿ"Ì°i²Î;ÈëKËxnÑ»ZÿiO/™oN™+`âÜç^˜+k:±‰¡hOÎoE8¡- @‡’:E	ieBê*ŽŒ~`IÜMíZšh§5ÆñZßÎŸD&þÃdþ—PDI"a¹q@íÙÏ-6qv‹€Ñ“‡SÄ**,iôUÆ³ì
ý[:ßè‹öï-b“¯uHFêüµºü\zyñÜõN¶±.Ý»áÅ„ORiÆâ13-è^Z¸eù“‹Þ/àêO1É	sdÞ|*3+IUÊœíÔB'…Lëìk¬â^åÅO(“&.¹SfÖ7NÙmÙR.ö1)U”´?ùÉ§Wÿ€  `BRÇª
7/}:u5˜L˜ŸœüØd»êÂa”hŸ¼ðçƒ­Û‹¨mŠk0ñŸ|cëQ°«”Û’Ÿç’„`ôœÉ‹L6ß‡M»q‚€©ö|sì’”T¥ÚËfè?aŠ´émèK
~.2›6s˜zã´Òùk™ó¹ªJ”¦Æª**Pr#šÑ²ä(úÒÂoÖ=·Ð&ü§Æ¬Xjvæã:!-œ›Nó“†¾ð%$lUáöu[ŽQ	òÆˆŽÂ¯žûÂÿ›¾¯L ‰{}Y„Ye;î½Å=ŸÁ‹8ûÅÕéï,žÉ5à)Kª’  LBÇíT)·$%l0öÜ˜ÿjr‡Û!î$>|÷àÞ­[÷Ïh:vI_´ûÃ*nÄÙ×å¼±4mwwR¶å¯.êðL¢BYèý3·$ån_“1½åÜm¥û7gl/â°jé«¤?[°[ié€!n:{-³>WÉîôç—Ïf<öBÜ½­¾IJÆ/J01d¦3t¦xôýG¾,0Žvä?ùþÅÏ=4ÝÌf6}ÉþmëÖü{Š;Ø_òäâEm{e™QHª*ØôÖGÔ²+˜|ÿ¦¿IúºW=ýÖ¾c\™rêÂåKÌj×Ô—îß–¶ÊÝZ?*4aµÎnÃ´9&  8„$S¸~H	ÜhÅŒ;ñû†gîÛÐö£ç¿ýVW&y(tûÒíÇ>_òÏc7MíÅJ
r÷(¹^Þ÷ßÛR|¢Ö¨¥«vó#ª¶¿ø÷ímŸ/pNêÛ‹øŒ0sáÓw(_þrûËOÎœ99PP¦Ücœ1pöüû§\¬#åo¼Vêmêíç¼•¶•ïå£×qÛFíÞôç£?1>lÎ¢çø
ÁUkrøîOú²SÜÇ-û>íi¥±ÌKöÀs1Æá:y-3?—@àå%0Û“þLÄ–Ñe²Ñ^†’ƒ»wä)˜ððc³{´ÿ°þìoÛ?Ý²c÷ÞcMcAúO¾':æá›,©ÑéKö~¿—RàÌGc¢ç¶VMÇçwú¥–-ÿœ+_ÍXôt»/¤©=Ò Ú»q§­y4æé¹f8ÊGÛ×½•‘ËÔDMx÷,[5½¥—0pús  t!É”ª¢½ËÌ½sÀô'—-®[·u_AÎ—Æ›¼d³£ž~ôâ)ß«Jöï/af¸vá+W½õÑÞÝ;Žq7'ßñPtThËÉ%ôe…?+žÔ¶ª¨°àXÑ¥·•©š"@U‘¾yè¦Rå±ƒÊKß˜¡D¥lºÚÔy¼“×2÷sÑW´úßÓ·¦Ñ'*9V@?M·§Í]ýPO—‘„ÞÞú¢Bã×#Îxdáü{§YÚ¼'Ï_¾zZ‰÷Å³øU)È)ÐóVrÏÝé™ìªöo^·õ .Gß“cªOHï’¦¢`Øì…/>zç˜®:IqU±O?údÇÞ"cÔ§Ü77fÑc3q0 €\t:÷‹KËeë+\oÿkWUR¨är…Ð_*n£®ªJ‹Îj½†kûtgw$,\¥œžøN‚%]Š­cË×ÒWR*Ëø¬ &à,íª£»÷–Nž"³Ù;:ûÃË_Û£mù}ôýk^ŸßYË–þláÞ"¯ÉÓ‡wp}IáþÒÀ)æÌ§/Ú¶ò¹·ö\Œ}wN†65  K!$õF¥ß/lÅÁÉ/®~ÙŽ',ë×º¼P=ïXiUUIÑÁ¢RÙµ!9wL}Ñ÷mUŽžq#NÌ `%4·õB¥wÔzž9Ñ©Å‘¯u™ñ6qò0úwúLÖ„ÃnŠZt  ë!$õ>”[ö—ÏHÏìÎ‘¯  àTÐÜÖûèK•…*8Z6ÈGÿ;ðµ   œ
B   €	q   À„$    ’    L@H   0!	   À„$    ’    L@H   0!	   À„$    ’    L@H   0!	   À„$    ’    L@H   0!	   À„$    ’    L@H   0!	   À„$    ’    L@H   0ÁY¨LSÝrÝÅÅ…   ôþbO‹îJ   €	.:Ž5×„Z*C­KD]g¨$  Àå•$    ’    L@H   0!	   À„$    ’    L@H   0!	   À„$    ’    L@H   0!	   À„$    ’    L@H   0!	   À„$    ’    L@H   0!	   À„$    ’    L@H   0Á}hT
¥J'–ËebƒË…:?++G©‘ÈÃÂÃäb  —1„$;Ð©r’Äd*tÜ/Ò°äõ©‘r§J:†‰{<Çé”y¹*qP°\âÔ‰R›—¥ä®æª$›ƒ{{L2(?P±{FÉü  ´ƒds:evRRŽ8jýÎè‰*;aAJB†|ctÃ¶§:N$2+k¨s¹0§ßØÓ›{M~FÂ‚L%‡$n]%ëVPRç$Å¥çkDÒ°øä([~ë”}S¸„$’ˆujEFBRðÆäPi—o–¾å¸ÕùÖ}â èäÄP)³™òÒŸÖc#¥²  ÚBH²5:?;Ÿ'G…Êh-
Ëœ—“§Š
rL1I“Ÿ¾`A&OLŽ564ŠìÕ)™š°ääpYPX°,3=#!%dsrˆ„õ";=›+ÏHBÂCeÝþš(Àäç«™.ˆÙ’N‘•—¥¢Ò`xÚÆ(URDRnVLŒlãÆè.ò¥N§VæçÛ$$‰Dj•'m6#UÿqúÏ_ªØç¥Õ7z2  h!©3´yS(Õ:£ -™¸ÕƒtÔ¤Õú94*…B¥I,ê®DO«PRÃ˜LÞi‹”:?3+O­bénÉ‰ŠOŒ“µÝ„‹ÄL­ÌËNI	JŠNŒÍ‹HÉLÉŠï¡j’*7==—2„((*ÚeQSã¡H"²YšàPcèMŠ‚ãS)þê’“ó#b²óS,`×wZ&IB7nîl.Ò)2’²UbzîØNK_"±LjÃ¨m8õùùôï×gO•N@‹ @[Iùöþ¬­óÎ¾Ý€Ò@ÕVœF8‰'1z·8jðû(x®¹:¨ók&dµ	;rêFÎP+ó`3kj+Å¶ZÙUw°ÙV„IÔ„g±—5Þ‘ñ3Ó	êÖXmp5(½i}xKi­Q­*Ò‘?ï}	p$$þã|?õÕ€ttŽ$°ÏW¿û¾‡”ì­w/¤à·™•ŒA%	v5;‚ìŒ¢NÄï¨­vpÌBH5MWÚôiN”2µ³Si1Ûœ\Ðí¨ý¢³Ãhµ6Ì(ÏHÚ:“ÆmvÙš]ê&JoÒuÔt9š»tíÆU˜:ñt4;I†Œ%©¯Ç3ë~’ÝnOXª3Õe8SZ’øÿ%ËHawsm-dXCK‹Qˆ’d0ÏÚïµy<¶½óä$‰ŒUÉØ4ûHÜÒÄvjõÊÕó~û»÷ý~ñVèÇÿ6±#n  ³!$-“ ËVëbè©ßØÔb\¹	IäT«6¶\Ñ:„¤ö8jvyMímIg_	««3tyìN»Ã iTkê¬² »]Y£³Ý/|ñØk«í)7u¹¼þÎöÕJW»ÂnR-²{yF¦mjkÔN—ñ¤*S[§Ô\cv’œ´7Ò6ã-^Þ~wx0þå†ÿùwï•vÙFÜ>øàƒÿåü§7ßüùz{IÉ_ét7nÌð^ €U„”ŽBßîÓgõ&è¬ÙUëŠ0¬±³;ílh‰Úz¥ßŽes   IDATÝi0	»ëwíí
Ì`óxRÒêf³Í-aUŠYO„D7“ÎYKæÈ0« sÌ*ˆø;kÅ$
%›btŒ§½è—±jÅ
'¤éeŠtÈ¬I?û§%UšÚ%LM½Ók'9©…„(Ñ*ïµìªrp“¿‰6I‡ñÐRZØßÕló&½ÄÉ>¼ß^½Ëæ%¿ÝMK6sŒŒµ…nM~sëŸuókŸ]ž7’ü}ÝÏþóø·?ºú£þïÿ}æôé6Ì{/ ÀêBHZZ‘ W8ûÉæ“'QhLíW´þ°L9÷Ô-ÓZ¯ø$«¹ôŸbÕ[<ôÍRè[:›RÍF"ñ°joWQ¨µÊ­ÔDü]fèTm­ÐÅ!þó»jwÕ8Ã2}ûšX$¬žä$ÉÞÚ.GMu¸±Éjœ¯ Ä‡=6»wîœ«cF%MªaµËÕŒé¿ûé?ÿaúÛÁÿéðÄ6õrŒ¸9{.Oe ¸7öæ¿üë*¾øóÞ °º’–ô{iQ@ÂjVðŒñ;ÌÍœÊ`ÔkæLÓ–²©Î²‰„ñ»ýRµ:Å<'žœ¸]©i(3™1Ì‡9.È(õ†yžx®Ë\ï ãlRMc[šeía¯S(³Œ¤ÊêåùpdÁ+Áø Ipµvá¤ê†¶SÚ×Cç'uJ¤5µ¯ËRíw7X­FÍ¼o—Dejjˆ×…ø°ËFÞ™ÎÚhˆÿ y®Ãlv.Å‚¸ÞþÕ›ƒÉ7Dß¼ôû÷Ô…Ë0âö³ŸýlîÞŸzã1(ý½  «!i)ñA¯‹¶”¨´*ÅJix¿ÓÒìôDœ®Žfµ©¥¥N“ECÆ°‡„kílÒ‰>áç²ÛâÃ|™bj½:íSˆxõ5Ù«„ŒWMÕhDŸŸ×éŽg$*ËÔé·W•t)µZ½N§Õd1RáœÍõf]³¯Ð5µ5é3˜Í.‘iÛ»•–zs—ßmÛûgSCƒQ›n!¢T¦TONÓdC©‚ÜŸsÅS¹’þøûŸ¾üöðÛŠÞù»ÞšyË­—ÖþÇOúÎ¹Ûndþä®mmþÂ¶…Ö™DÎ>"ÿËè^ €Õ„´„x¿«ËC3’R«^¹YÐV[×àµØºü‘ˆÇ¾÷‹C£µAŸÑ(ñ2;ü†ëpµ¢=…WªYë×ùçO**³Gì$lºCÓ¹Þî¦±K¢4¶µ7¦›dCòŸƒf)F©3¨R™û]ò‡–ö&“Q§N›]i»H’<ô
MCS“Qyà”*õMÝjÃb¶¹‚n{­Ûmì\Í&w~êóåŸ~ó+ƒ}?þ0£íCc?9;&rû§¥å/—~~Á	‰Ù^òy÷Ý³n|hûöLî X]IK'âíê¢3M$´Eã
Nö‘ÈÔ†¦n­¡Ëf¶¤äí¨ßårÒ3|ú’ñ²˜éÂv†Õ›t)ž°°„Ë4ëÆˆÇRUíà$êº¶Ì§žÇ#Hm½#ÞVQš(z¥{@ØípÐÐ)ÕõÙOÍQhjÞãts4áñœÛQOþ(T:­Áh"e)±§-‘)Èp—D¦Ò7dš3gí@¡1µý@ë´Ô7sZk]º„	û=Yb¸ÍOch$HnáÃmÁ%kËÙ¶yo_Áç{_´ýYˆOjî{æåÿð…û>Á,\å®JWoïð[oMÝ²½¤D[¡Íä^ €Õ…´d‚n‡+Þ6Ú ]ù~CäÌnhêT©Ìµ'Ç‡ÃáôW&áƒNK|V²De²6¬L·mž"ÿ•©MMMuóMÛ!™Ó.4PRêMhO@øYV+É"®®Ž.§ËKëCA¯ÓAþØT:½Áhœ“•Èð_Ët‰kÙ»jvÕ»ø¬V!
;auÖn-?OŸ&Þ;»ã½„3ù†¥(BÝù©/|k§¥ÜÿâWFÞ
eõÈ;<õ…gêîþìÌâlÜ¸ñÎü×rþ“ïÍ77lØ¨zè¡Ý••S‹×Òß °º’–HØmoF†XRÀXÂ«keEJÎñW4úŽŽ Ê˜¦ŠÁsät\+¬½WhŸ•ÂƒRçåYõü³ÚyÎewxy:/¨Î¸ˆ'(‘)5†Fò‡{ÝÎ®ŽŽ.ZZ
{v’•::ç\TO"Íè`¤`×lóÌÝtrÙþü,Cbé!£‚ôú¾:}b8zºœ^f‰|â³ùùCÿö¸ö{»þWfíãïýŒþåRmùK´ÚíŽ;îx¼êqòg÷ ¬"„¤%ñ8lÂÈ•DÁÒ®e%™§ëQ¼÷OW|˜­­­qe/Ù&aÕê6ãýaf·L[× Y’ÌIJmZ#ùÓàwÙm;.U(Ü§Ÿ½^?!ãeû2¥¾®A—˜¸ÝÅ9ÝA…ÚX—h–É{m§°Nr©äl’W^*g[½/šþý7i·üÌ_?ÓúÀ¶M ÀÇBÒˆxì6a9;­©3¬Â¥=2Fê–z‹dZkK&×°_a7y’nauYc£n‰Ÿ¢T©mh×½Î.£YxB”°Z½–«$)W¦.·wnÛ_jzû‡ß´ESn²}‹©óv±ClS~ó›ßú|ï¼ó7ÂýátbÔ'?ùÉ­ìÖ{ï½w{IIaaa|³?üð{/¿ìò‹Þ °ZV"$ù|¾ù×üò—¿úè£tëlŠ·?³o³Þð\Gm-½l#\ T½fO‘a½¾Þ&,¨Ÿl¸FRMM‡ŸåZ¬ºåIqtn¶IÅ,‚Te¨kÐ¬jÍp!þøÞ°'šnƒá_À>´˜™Úq7Ú^øÉoÌ½ëÇ?ùIü‹²GÊö×ÔÜ}÷Ý7n|jÏžÿë@­ïÍ7çÞË  ¬’eI—{zÎ¶œËdKòïãÄûï›¾úUfýà9§¥Ö",hWè­i;þ¬&>ìq˜ëm®x@ÒdÖíp5D¼ŽÚÚÄT¤¦&£jíe-ì¶í­rÄ|ØOêfþŽújO¼£½ËÒLÜžiâíÐO=i·à£?í‹júôâf#ý÷ÎÎ—_i'õ¡ô›õ_ëÿÉ?yvÿ~Ýn]nn®i¿é¹¯5ˆÞË ÀzóÑG‚ô¬S¤P¬ßÕË’ÆÇÇ¿ÛjÏ|û‹Ýsî¸cÔ“èôçÚú.Ú<’aõ-mËUõX:'¸ÙÖìÂ3ôÚõVÃÚÌ<ç²ÕÇÒÚ};—ÐdwöiÎ¿d“µE}ð«¾[7ç™ºýáÍK¡_íÿôæ…¸u_ºäxù{nüþûïŸûoÿíSŸ’–k4mßžŸŸFEïe `ýƒÇ¿a!Cíäëûî½×rüÄ:­
/oHºÁÝ˜˜˜Èê!çÿÇò™òËO=Å¬a|ÐÓa³ØœþDòh"§ôl©“Œe·3íUá¼Y6Î‰pn§Ãnïð$NÄVÛÐd5ªÖâ‘ÐbÒ,L¦¦”ÚZê²hâ¸îH”¦î·MÌÊûct¸ówï'ß²ýž/71/ÿì•ö¤`â¹5ü6»yÛ‚GÜ®ÿô:“%÷Hbù¬ù¹»ïþù/~!z/ ë9ï›¿þË_þ2þíÛï¼óõãÇÚì­d`Yo–7$ýö·¿c²÷jÇkäOæÛîsŸ«øâ_ì1˜å	º»š›í]ÞÄ•:¤*cSKÃ‚æ?‡=]³ä"œÇí¢KÝ=Se
©RG¯’‘õIf/tÕÎâAñë·É”lf1‡.vo¶Xâ¹E¼›swÌð™-wÿØ˜xû×?öL€m,úêvSóæ»IÅ¨¼pÛ_¾ißÄÿ¶ò¿ûqß{nûÔBGÜâ´³‰$þ¡¸óÎ;ÓÜ ëÂ[o½5•âHIé¿øÅƒ>È¬7Ë='i%®ÁD~í¯¾î¯Y®ùL|Øïõxœ]ñ.;q2µ¡¡¡.Eïæô{K<^Ÿùƒ#ñÍ&=çA$j8].›<Io09ÈÔÆFkŽ]È [Øm«©u¥¾nï1ñA³Ø=õm†ùšE’@»ÝîL”È“ÕZ·S¥ø5ŽHý±ÙæÎþòa/Ç,Ú¿î{71Ö&/¨x¹ô?ýå]“1(çîê/|]}÷ÿøÊO{ÝïÓ·ÎÿõW>u÷’­q øàÃ˜uh¹CÒÊÍÕúŸ/þóß|êSŸb–Ï¹škÍ“yA¢ÐêêL‰GÂÎâgq‰Bkl0fºÀ,ìº\})@Ðew$™R£ÕzíœJN×lfs‡;‘æ„Ëª-üíœ+OI¼ðßl÷Iž\—ÓC/
ÂGüt:53kX4ÂùéÛœª™ä$™Jo˜Ý'œ^ŠÎÏ¬’?¾÷æ%:Ö&ýÒŸ>óòƒŸß4{4-ç¾»ÿæŸý|óO_<ò›ˆçÝ7ß¾ïîŽ¸±,Ëdiê!¢]À`‘ŠÑ&¹üVhºËÿ=÷ÜóÀŸ>À¬C·UŸ¤7n¨T‹ZØ½X}_oîâUôbv1ÉC¦2µtêy‰”Íb“TS×Öi -µHº:““s„*5	G‹|†“OTc½âk“-`O$¦é8-Q¨­Çã²ºº†:ƒf‰/t'‘)UªˆD± ÙW´Ó·­#yBµø°hªf’“dZ¥NËÎ|
¥Î°R©$uuyw1·‰·ÿý§ÃÕ?|aoÝg?j£;ïúü×þÌRþóö¯Œütá#nÏî_ø„«Å< ÖˆœœœoX¾qÜrâÝwß%ßÞÿýæçï¸c]æñ²F|yÞÔ"½äÕz©¾žûí\îº¿yò$³Rž?jÖüùŸ3°ð‘HâikÊt%iqfT’x¯eW•ƒcÝh¤ÅûíÕ»l^…¾½»i¡­™Þû·_qÌg?ÿPf±ç·¿{Óóû—…w1  óá‡ŸØ¨¸-  ²”á5ÒVK5-õJ*‰ÒØÒ©Hü”°zk·*Â(”_šx×Cw>ó­ÿäÓŸÿK `6nÜxï½÷2ëBÀ²“(”‹¹ê1@\wÍ½ Ö?„$   Xbè¸   0ÛmÓq{ýµ¿  €5+Þq;ž˜ÉŽÛó^ÌqmBH  €%“ªã6³a¸   –×:í¸J   ,™xÇíä[ÖoÇm„$   X2ñŽÛ$Å¿½ÿþû¿yÂ²N;nc¸   –Ò}÷ÞëøÇÑq   `¶Û£ã6†Û    D ’   K·   fCÇm   €ÙÐq n/±èh” X¼Û©ã6BÀÇG±(wñ¹/ïyþüä$ Xè¸-¢  YAŸùÌg˜©Øó¦]º}/­ÀYn%K$Ôg}ÖtäÜåÁÑä[c7»Í{þÖ|1 ×+7s¯žíáb À¢ÜN·—wâöV–½ë®»Þ{ï=fùåççoÝº•YB±[Cîž‹=½W‡Ç&è÷¬æIÓW«¶‹¿èÞW_í
ŽåÊÙm%Úª=»K7åÎÚ‰x I   IDATß(wµ·ûò•>_HØ_ž¢¬r¿iÏŽÙÛ%6t½váû×‡†CãLÝgiEUu9›/þTC¾k	yåÎ¢9÷}¿·?À°ÚÊòÍâN¼ØÁ^÷ÕPîŽÊÊò\&9ÇÊêuM‰r}—ÝC£Ì¦ùøñs«ç°±ÅÇ”Ô¶~s÷fá½‰qçÔ98¦¤þÅÓ_’g»¿ØÍþî«##ãÃïÙt{îæ
ãîÞÃ¯¼Ð[úÍª§Êz_zõjùñG³Þ? À´xÇíã–ï¾û.#tÜn4?ŽÛ"HB:Þxì¹¯50ËìN‰Ärü8ùf‰Ä®ÓÏŸé%ß6Æ¹[ë¹ÀÉoØ1#'E‡\!¡@®(bBÜõ^ûõ¡C§WMžüc·ú[Ÿêá&’7ì¿ð<:Òt¨|Vž˜}ôqºOò§ïú±s‡Êæ†´Xhð*7!¯(›ÊH±Ñ¡×{{û¯ÒE‚K)[Q¾™™ûÈ[o¸{úú|ƒ¾a!áŒ—T¤,3•ÝëJ~Ï^9uª›.•Çæ;âòí?¹ßâŽÊ›óæ}±h ÊÝVuèÈ3e›–ûéæÐCääæN)7/OøÿÜ&kQîrÏðSPöôž³~{ò‹ßSæ:uþüÀ#‡¿zìd0¿		 ·3¥zè¡ïœkqö\þÕ¯n}ôÑÒOnß°aã=ŸûÜ_ÿõ_m¹³T¢C/ž¢%GñÈãOV–?¼}k~tÈí8}®7ì9ýBIë‘ò©sMtÀqš&$yù!«ŠF§Ž]ðõµ¶–n;ö%!&Å¸«$ä?V]¥-ÝÆÆ¸«Î½4rŸ;[Âž¨,š>ïŽöŸ=!$¤¼m•µž¡Õ£è>ÇÙ–>¦ OôùŽú®‘ÜR^6]g½þÚÙïLÌ÷Bck¯œ»0<ïv)Ž•ÝëJz_;'$¤5a"ä2Ýtøò¥k»K«6¯L¨KÎH¹>dtðÂ¹ËäÝÎÉc|ÝßžuïÄØH °¤bwIaná¦+:< ·³Û£ãöJ”¿xàCüfÉgw?QÁ¹ÙgU'rGaqÅã±±-cý=ý²Ýñ³ìÖÕnšgŠ;t0Q7*Ü¾çÀ3ÜÁ³×Î÷íÜ¿ì ·HS]9p9÷±ÃÆ©ÓûèþcÌØ³'Ýc=ý7+žÜš8	’SÚK.rJST·îß™(±äo-?ÐRfŒåæ‹œ*ÉHYÿÐ¸¼lç–éá´MÖ+Âî|çö?×JñBsÙªgýj´ÿØßžègæ1óXÙ¼®iQßyû¥ 	 ;&®õ‡˜UTXòÔ‰oWf4'6vÕÑÔ=2±iÛ¶lëH±PßÙ3çoT>´{…ÒU’èÐy»Pæd&‚ýWR%Óñ×GÈ82" Àh&)*wsyý™ò97–VçŒ‡nŒÅ˜x‰(äsùH²Ø¢­(žN(¹Ee¥/^wúÑíôŽÜÂû­;g¥pGEi»w,4ˆ2[ãg¨Qße79“h{vÎ„MHÄØpÿðxAi»ç¸YÇÊüuM‰u¶vsLAù“Ú‚ó×˜Õ•_T\R”Ñ–Ñ¡«1: Z\’õÐ`.Ý62\4žÅœèÝvÕ£I£o¼xæ©“UÔš4EsöGFKg/LÈKÝ†„ 0BR6rò
IâMLžî¢!_€œ:åÅÅ3Oùl	›ãö…†¹±Xq~êS]^Ùß3ÁLx÷Ž‘––—dzÒêÏÛþÈJœå2=Öœ×5):táÜÅ‘‰œmO>Y’ç:Ï¬±Ðð0 Zº%û·91µ(‹Å€‰ß¯ÜœE¥¤(wñ[§z‚LÎ6ãÑ¯îÞ*2mt´›NE+zDÃæ3  0ú$e!61v‹® .(HÌóE!rêË‘o™]ô)(**¤ó=hÍ)ÍÇèÃ™|yab®Qlt8@2RŽ¢¤(Ó“ÖèÐµáñ¼’òâÉH™kÎëJˆr=ßí¦éËµ[óY&‰Þèµ©;xèÔù7BË½n=vk¸Ÿ› ©¬8åÏ%zshèæ¨è3j€1&)[sCC·Ò„¦Ø8-;-*#E¹ËgNØI™SQa>RµUôi'ê ŠRd$  1¨$e.vkÀ=DëFìöÉL4>6:!´ƒš=‘›¿‰æƒèØhšó÷èÛG"QÞ–©H#70…ryþèÐ÷/œ¿|Í'¬NË)z¸jß§vÎêåú|côä]À,»L5÷u	bÜå–W‡IDzúÀn:Y„èãð¡øT›áaŸ;Ú|t®Çý=ä'ŸS\V’rBÒèÐ«–çûÆRïdìÂý…éoå'¿S¿#EG‡X”Ží‘lµ°”õ÷ÄÉG•f:³-zÃÊßV<óÉÇ†úÎŒ“:“V‹Œ  !)cäLÙ;H3ÒÃšÉJJ,Æ“D>òÇGXbÓ#s"û¸<@Nª;4ÓCkÑ¨0e¢ÿôß_êå(ŠXÅØÍàXàú…ãÏÿvýÎ™Q :rmh,‡-+.\þÁKôuÑ”=­¯Ðˆd<X¹èsrllÈ72=”7æëçF•/W-mÔ×s™-ï‘Ý¥›Rn”[¸]S™ÇÌùMˆ…z¯Ñ1Ùœ-eIš
ŠSOnŠÓ¬œSP°€÷)v³¯õäºä0¯ä‰_Û·½0:tþÔ¡ïù&ä¥ûjkŸjß5:pñÒý1=¦ÙŠ6U  b’2»Ùëè&gžÒ§ª¶OŸ»ÒóÐ†6ñ”$º$íêk¯	Ÿã«žœÓù(Ð{) ×üŽPq¡çÙ7^<q¢{¤÷lk){$¹ùP”ëåW—¬@£¡ÌŽ•âuÅ8—ý5‰Hûj+·.EÙ"—öš˜þ.wÙÞ€×wÁE0jªv¤)Vå³»÷˜skôF÷‰Ëñ§YXü¸Ñ´=£×¥Ã•¹ùIÆbLâõ“Hvþ{$!å°õGkË…Åt¹òâ²r_hà¥çŸqUÔ§·^ý^+©{å•<¹¯l  b0')#±€ë¬ƒœúóJû-Jn^#´ö«	E&ºèyîV¿Ãî‘(ñå¯V&ŽOœìÇÿáèîÉ‚K®|Ç—÷W‘ŠÉ@Ï`ò$§hàª/Dj;+’‘2:–øë"oŸÃáÏÙ¶çÀî%ÙÉ•—î*›J,y%•»·-Ó€Ç¯ø&˜ÍSOnÏò¤¨sîø¾lºPÅ7¢c¬ùòŒÊƒ£WÏ<ÔúFür#¹ò²Ãßy±éÛö¦Cå“írKªNücûÉ§K˜‰@ï©}kn>sêtoˆ¼qÏÔV®|W €õ•¤D‡^9Óê#éa£©bFwÄ¡1r4>H–,OFÊ«Ä¸Ë§[Üc$JìÙ¿{ÆLàÜøöE¥•³.~’¯(-.¸…b_šŒ)Ñ@ÿ@0góÓ%›V"#Í¬T¯ë–»õÅëä¬/Ïº^iÚ:ä£M’ÈX–ã»y›HÐÉ¢“uî¦ò£­lE_¯/º¥L»l£Qß«'[I8.(3wf7ç‰¤«¦£gÜ4‰<ñT¡»µ/¾¬?cÜ ]IWÄÊçMe±Ý$îŒŒ+Ñ…ñÍó‹¶—ÌÙ0wÓŽ'­­%Ýg¿õ))õô’›r¶=±_[„ˆ BÒ|bïŸ9qž^Ôá‘ƒ´3?vçÊåyÌÈx(0e6%ŸÑèMã=ç<õŸ=ÕB#WÉ¾ÃU³KåË‹‹r®„FC¡S<ã:öBNŸ±ñéSm,pm SôdÉ
œèæ?Vê×ãB—ÊÐ@ï¥Ù›\ë	×CÑfy¹ü¢»;˜e3ê{éØ‰´ŸxÅƒY=·èÐùG¾ç£éªÖzX}Ñ-ŒÌfÖ¶òæ@ï ¯,eÓO¾ñÚ‰c†'
JÙ—I™«°¸êð×FëŸ» \F†¼ó®7ÊöíDN H!)-’N™›®‘âÈ–'Ô?:g¤‰|h/Ê¹6<§íŸDrAA[0sAIæBCíŠÃ‡D®nQ¸¥d33À†BÑòÂKÃ¢ÂUvs¦&ßÄƒ†^hv%2Rúc¥}]ùlÕ±¦²Ù‹ÙbÑ‘Ë-­ýcy¥O×?^œ—_°¶VX¼tTHH:Y[–ÅœðÑ¡‹-§ì×H-ˆ&$s%›¢£²±Ì*IQÎÕCFèr¶iJÓ„E‡.ž:a¿Ngík<²›Íèw€d·cÏ	©@!CÃ—Ž?ë«<tôÙr%  I©E‡:-æ—hmdÛ¾cÇªKDNâ¹E%;‹˜á‘¡«Ã£»‹¦&À’±):`RPRžÜ;JF£,-ý!’¸*OÛ/:8·¨´|Û«Üðµï<UœtÄhp€63,Ø¶]‘¸‘ŒV]LU–-¸H¬y_W.mš0÷a¹oÐÚX¾œ-ÙQ²†½6ðÇK—hƒ‚‡kOÒf<kgzeI‹Ú¯?˜uøˆõ´^!%ÈòjMªÇÆHŽ:ki®-¨9xäÀ—2Š—IÏM^qôÛvÆú¬Ïép¡¨øÚ €=„¤¢ÜÅxBÊÙ¶ï›ÖêT=éH¶½òÂð@w÷à#‰µK±@ÿ+½¤¨"/«˜^¸N?ßÒ?F/Êvâ›R^I4—Õ>QvÑâî9çØ1uí¶ÑÁN“*ÒLÍUŠÝòõsrmÙ
`Ò+Ó×µÐxôšã¥+>¡ÛQAÉ‡ïÙ‘á0[,4x¹õÜ‹×‚t±¿Bóì¡»gýÎdPHŠú^=ûÙÍ)yâIñw’¼Ýgž½>BË”ï?q¤ª8ƒltðrË¡¸•ü(­¹¹ÀÕ?QZY¼¦jx  kB’:ÉL»3ôÂï/ýý|iö[ö}çl5ãÈÝ¬Ù£íy¾gäÒ‘¿yôá-yLÈç¾ÆÑYÞO<>U !£QÏŸéwöý›žÙû“W6}ç@<c–í?¸‹;~¥çø³Cee%òœ1îšÐœQ^±ï‰íÓu$÷Ð„¼|§"_ìé»Î¶tsÂ Wl<@ÏãöçLç…áE•W
Ã3$ž9çŠ·«ŽÝ¤/wìõ–ƒœ0y<}òp­Ðn Í±²y]kWìÖ=.ööŒ$zA”<fª}êÑljt±ÐÀë$!ÉËž®5UÍŽV‰õùi÷ Lº$ð8X1»Œ”¬›\gèekž®=X•A€#ù¨§õ¬ÃM519ìcÇNwLMrÏ•ïØ]Å  @*Ib¢á±L7.Üñì±c¹­­Ý×}®+>á¦<¶ÂxðééK¾GCƒƒY\î¾pçþÓ'ågÎ¾6Ðß;BoÈ‘—ìÚc2j§:ÅÆ†®r)/jùF3o&"@4›^åF†¹™Ol"ä_&.ö‘æXY¾®µ*7??1Ó   IDATÞžÅ#_Þ¿ïñÒ¬çèä—ì;Ñ\ÊŸ¾Š_”ësùbò¢‚Ü¨oˆî{î•ì’D;[»‡IárËcæZ±¾\ò,ÉO1§¨bÿÑ§wo¯b'¾v¾w >gžä¾ªÚÏ”aî @6ð<Oÿ³aÃÔÿ'‘æë¹ß~ÌECCÍ¹
vó-IŽnçm.š½»[½æýg¸ß5—-ûøÖJkõDoôŒÉK¶³KÖLàVßñýßº6>õý–'Î}{_º‘­Ø­¡@^ÉŽÍ)¶‰…†GåÛ3iw\>uøìµéØ·»´cj  ÙBHZF_?ñÌÉá’£ÍÇ—ñ‚e«p¬Û©çŒF£¡Àp`´€Ý©Y‘kÇL‰^­›ÛRY]Ž³ ,†ÛÖ¡Ñáþáñ¼-eÅ+ZVòX·™ü¢â’"òßeÌ*È-zÔxàQ  !iý!¹epL¸"=³ìVòX   k
†ÛÖŸØ(7dä[ØM+°ú  °¦ $   ˆØÈ    ÀI    "’    D $   ˆ@H       !	   @B   €„$    I    "’    D $   ˆ@H       !	   @B   €„$    I    "’    D $   ˆ@H       â&Kc‘÷¦¾Þ°a   °HïÊj{T’    Dlàyž™¬	MU†’KD©¾fPI  €Û*I    "’    D $   ˆ@H       !	   @B   €„$    I    "’    D $   ˆ@H       !	   @B   €„$    I    "’    D $   ˆ@H       !	   @B   €„¤ùDüN‡Ífwú#i6
{ºì6›Ãä™ÌðAÛ›ñÖ   °â’æáºšíöæo8u¤áÃ^‡Ín·;¹H&»dxG}ÍÞª?«²y“vÕï¸ï¾’½~¿Ën±9¹EˆâÃÁðzx¡¿ÛåáÖÅS]-ÜËÿ/÷[ àvq+-âm6Û<†54UR‘$	ïuÚ›½RE»Iì¨·tÍ,:IXƒÕªg%ÌúÆû{÷Z<a™®íJ‹VÆ¬ia½¾Æfdº–î&byß{>èõ¥*5+eÖ‘ßŽþ¨u„¹OÁ–ç0  ·„¤Æs]æZ»—'©±N“"HÚÆF]Um³¥CÝ¢âü/7k/ê¡)ôyËÊpê&å"R¥R±ò‘K"So@$ŒðŒl¡O ì²ÔÛýŒ‚eÒù÷ÁG‚A‰ÎÚ˜](‹xvW˜|Ájõšåx«"A¯×ãõsÁ`Ðïq{hIR¡oëlÒ*˜õâ½{÷ÍG™K£ï•Ëïb  nI+*è²ÔšAF¦iljÐ¤9M“˜TW§q›vOgËÞnL»×ˆßQ[íà˜…“¨Lmm$³­pP’²jVâó4§1ìKI|$LR¤‡÷zÜ>B¢VgÊxÎIÆ[I.•jLFõBª;¿³ËéÏÙq$ÈqÁpógÔJJOîãŠuR0œ¸yé×¿!ÿýç[7+ßö' Àú‡”-žsìÝeñˆMM	wíÝÑ5ë6©¦åJ»NA§Þxæz›KHHí-Få<çZ	««kˆp¬z%N’|“³ôJxId¬RÁx8’Â3S¡õ3Ç$ƒ’•4íNT†:“*³Ù`©R•U»ív7Ý¿L­–†½žÙY‡T<noX©o0jSý´x—”»Ò=+V£ÕjÔ*%‘QMlmùíï~Ü÷úÅ[¡ÿÛÄ6Œ¸Àí !ITÄc¯ov	“tù°°®ÍÓ\SÕEÎ\…ZEþÌ<'ótÌ*,|üŸ=ä%UNÞá¼Þ £Ð5µ7ÑéD­ÆÆ)õz+Ú‚N="$é HocU¬,ƒ³¥Tejÿ‘‰Œ]eybåƒ]µUõ.:Ï†Œî­Æ'©BÅJ.t;]Ê Oš¼dÄIx3ã$*¥A¯R¦{nR¥Î¤Ô1Ë„Žš»‚Â×a—­Æ•rK§Ëíiìl3Š=Y©R_×ÈxgÆ+òëBÊE.[}‡ŸamÝêEMA"%5^"[¥tõÞð»Ãƒñ/ÿ0üÏ¿{¯ü³Ë6âöÁü/ç?½ùæÏÈ×ÛKJþJ§Û¸qc†÷ d!I!Aeæ)-ÌÅGKxuCw{ÃÌ3ï·Wí²ùeZk{SÊyF¬¾¡Ij`µÂ”2€cïðx#	*þYÝÈàÙ^Gâk™¶åJ›n¾
D¦È~ê
©m˜mtžLÛP§]ÉA)éäŽæ!¯ðâI(­õÌÝPªÔêuŠÕœ¼ñ4×›…ÉH’hR¤PZöÖ5*ROí’°ZcƒVä±\îuqkæø ÓVosx}{w“f5fÀ“±¶Ð­Éonýó¯n~í³Ë3âF2Ð¿¯ûùÏÿöGWôÃÿý¿Ïœ>½aÃ†yï ÈB’(’MÞx»Eø2èÜ»«ÖÍ«­WÚ‹+¶HXŽ¾¢'µf2€#ÕÔ5¯'1!%Â¹:\£Ð´ÊDA@¦R..#ú•ŸTÄ„¢EòžÂtv”›œý+QEŠxíµ56w8íFRGÇ›XòG±ÊƒNäÇd©§“ìFilïLUè¡¹]õ®ÃªµÊUIt©Œ	“b—„y~Åg–üÝOÿùÓßþûO‡'¶©—cÄÍÙsy*Å½ù³7ÿå_PñÅ¿˜÷^ €l!$­<>è²‘±<Fª®kÐ«•µ&qGØv¹8žÕ™ô“	RuñxæÎ·™zRætwÖÐÙm:É‡Iá¦¾ƒ£S˜Z¬ºå¯"I¤>ùEÐÉH4©”¬„ë°uøù™Op-ˆxõñq62DÚÒò¹ñaKø	)5ÚÕZ²OJnÖÁÑy\A£2]äåÃa^&[â§ùÞð¯ÞL¾!úæ¥ß¿§.\†·ŸýìgsoôþÔAéï ÈBÒJ££\t›DM¢r¾x’jákìÌl
‹D?ÄôÈÐVMíb)QÛZLª•8¯Kdj½Ñ¤Q¨TJÅ¬¥ú/×ÑAFßhDyåˆ$M‹ÙÁ‘´µÀz“°°L¢m´Ä~a­¶ÆN³TmjjLÓ“ŠºîxFÒ)W-äI•µ’ÜþˆaÆ:A>ì÷¸ÜúÓÖ¼ðŠÚHèËnTî¿ÿéËo¿ý¡è¿û·à­™·Üzùgíüô§ïœ»íFæOîÚöØæ/l[hIdàì#ò¿Œî ÈBRö"K•èŠû9«Ûš†¦Sr–	»-õfzN•(5šÎ©)«R«¤ñùÜþ Ÿ˜#aÙ“Ab²K"+E8§Ôüd?*c[[ãŠÍ_‘*õJñ{dñ¹Ûœ7»6 $ãx<žñ0‹À²>9@R<yêÍ¡ºTÝÐÞ–6GF¼]nºµ^/öó$õ6Wê>Ý4«Ñ;¹.sµ7ÕÏT¢4XÓ7%µ$•¢ƒzI ’Jü´å’ŸãÈ›:wˆ“'?ý¬cåŸú|ù§ßüÊ`ß?ÌhûÐØOÎŽ‰ÜþiiùË¥Ÿ_pBb¶—|ÞýÃÙ½Ú¾=“{ ²…”%>É|’m8Â$Ÿ÷è(WmÇ¬uàä,ZSÓÁè›ÚêØé£pN‹ÙÔX[L†¦Nƒð`gÍ®Z¯®kk×g3K;þlÉx—„ªÃl¦	I¦ihk2©DIÄc®ªîXLÓ%Fªiº’ù“”±*Vâò†9o0¢M5ÄGxÉÌ!e5zƒRš2+òÂ/~Æ¯¹ÏtV/€ˆ¿‹„Ø.¿ð¦É4mMÆ´•6r‡ðUhâ»xòºf-áüÞÔÇÑ¦þ¥‹·¡ôÐ&ý5©¯vÎ¸ŸŽÌäMövØ]däU_g\Hù0gÛæ½}Ÿ?î}Ñ6öf!>©¹ï™—ÿÃîû³p•»*]½½Ão½5uËö’m…6“{ ²…”>ìé°yèÕ»x]{wSw·loµÍ+Mq=Þo¯®²y•N;uæ$5¤½5zú•H™¤	:´t8(™5íHÂý~ã“óÙbf‘L'TD¤4}HU¦¶nE‡[¢5h×Î5M$2%) yƒBGÉøÙ›$?åõÒpAºvlöÄy‰Bc²jÒì6ì
:]\˜”°LºÌS%O04ô4¶Xªy
[;hYPm0¥(ÊIY­É$K’È/VW©ZIUz½:Õ”kRNœsWØm37»¼þ¹W“#CoZµšŒlÒY_“C›tv¹#HÛx7Ô-¼~xç§¾ð­–rÿ‹_y+”Õ#ïxðÔž©»û³w2‹³qãÆ8ó_ÿÉùO¾7ßÜ°a£ê¡‡vWVN-^K/ @¶’RáÉ ùxN/œ31À¦ EamºªÙëq9œœÆ8;pÝvÚŸY¦3LW‚ž'åR›ê$Žšé¾‚|„Ô›$ô\–´Ô¬ÄÍyfÏ0ÉúE„ÃBH’%v/eu&6íC$2µÖ(YÀ€LEVLªP)$]ÁˆÇa®q’‚Š_ì*²ARgÊæ­ o+Ý‹TšMÈ”*-ÝjƒŸQfpÙ4ÞßÑ,”ÜXCA™åÒÿI·ÙEB’„d¹†¬VO’i81˜&Q!YòGæï°99†Õ5XM3Ÿyªf‹+Lž©µa±ÉûÄgÿòó‡þíÿpí÷vý¯ÌŠª÷~Fÿr©¶üÎ%ZívÇw<^õ8ù³€{ ²‚$†T‚öVÙftÕ–(µ½^§U«â‹ÊXQ«ð8=»[×4ã¼CNö6:1[eL®.(´z	§k¬S‡Ž¤C‘CWèÏê™(¯ÐËUè±‰ÓyLt1YÆÝ†$äÛ¸l­)Úy“^ŠÃãñøýÁ0
O’¼ëîäNRm &f“¥ÉnV4É¸Bc)›íÛ'eÕê6‹xšÍÍôW„54˜¼,¼x!èHeÙv¤?§–¡ý&¶O°ËÉq~—‡3*§Sã^kq“ f²6,Í,´œMòÊKål«÷EÓ¿ÿ&í–ŸùëâgZØ¶‰ X‡’ÄHhçlÆaÕZŠñ8ºüŒÊÐ0ó“¾‚^ÆËeñt5;ôêéâäÜi¡5'V_7s"¯„Õ7	—øc/‘0mc$SÐ]ò[¼¼Bª*~án¼Ðä›<W•ju¯ &Œ9=Þ`ú‹‡(ÈÐ”N­¢Ñâºýð‘x×n™B±mƒxÎiŽwP¢×)^Dm&Â	Î$¬*ëî…zÖ0¢”Õ¨f†Ž8   IDAT.èw{ƒ†ÉF ä²¶–¼¤ê:«iI{,Ü¹m©éí~ÓM¹Éö-¦ÎØÅ±MùÍo~3èó½óÎ;Ü÷‡?Ð‰QŸüä'·²[ï½÷Þí%%………ñÍ>üðÃï½ü²È/z/ @æV"$ù|¾ù×üò—¿úè£t+cŠ·?³o³&HÕÝ¾FáÃ}Ð¹·£K¬#2!©3víµûí–fu»°PŒ^Â¢Þá§½­™Íü 0å™ÓÌ™hNÒµÐ€Ãû=.aV±J³Z|¤Ra¼,ùV­Q“ˆL¡T©XÞYOÞHzY_nIz„½nÚÊ[Âª—~Y>©Ì˜kjI±‘i¬-‹Z!öº„éÖ¬JµYŽÒªdÁ°r˜–.¨¡aN¡·6—¼ÛÃßöDÓm0üëááØ‡3S;îf ðBÛ?yã¹wýø'?‰QöHÙþšš»ï¾{ãÆOíÙó¨õ½ùæÜ{ €Œ-{HºÜÓs¶å\&[’Ñ&ÞßôÕ¯2k€DšÁIKª6Yë<Õ6ÚuPÙÙ¤	6×ÒH$"‘AµÌNtå;/!U£9›Ó!2	ã'g3g‹:íº`J¦Ò*WãRI$
NTÉT*áú­sú`†ƒä”îÍ’T‹o³ÍÉ@hXè³°ä×¦”j»âS‘ššÊEìžçÜ.Ú([¥[¢KR:*ét“”Ä	Ã´dÔV£QEx]Sã2´x;ôÓôøèOû¢Ú‡>½¸ÙHÿ½³óåWÚI}(ýfý×úòÆOžÝ¿_·[—››kÚozîk¢÷2 °ü>úè£@Že)ëwýÄò†¤ñññï¶Ú3ßþb÷Åœ;îX3õ¤ùIUFk£w¯Ùåª¯®R¨eõ-iÚ3Ï	zÈ¨L+rÉ/‰TAÎr|88·“O&„+ŸSêêUÎH$¨©MMifúHY2è“áÁ oXôÂ»°×.,:“ªõK»Šv¨­§	I¢4¶´-n‚)H5ÓkÓ¿Q§\š§IC+q{9¯›‹¨UR2 gjÑ˜˜eñÁ¯únÝœgêö‡7/…~µÿÓ›>âÖ}é’ãåïe¸ñûï¿î¿ý·O}JZ®Ñ<´}{~~~4½—€åÃBÇÉ×÷Ý{¯åø‰uZÇ]ÞëcßànLLLdõóÿãÂ+¯¾Ê¬dÐ­±‘^6L’LÛÔfÍøJhd¬Íåñ“JZ¬ÒCNp:naWÓë²Ò¢£45šÖÔÕ>DÑË”Ð· ÌÅçp/M‡faÑ™b);D8§¥ú‹»„„¤Ð6vv6.îšÀ“×‰ÚhÒfyâèB7ºz‘óxûFÎçÑáÎß½Ÿ|Ëö{¾üºúË{ó“o{ßskøí˜…»þÓëL–Ü?ü!ùòÉõsbÿ(Çï€åCÎûæÆ¯Çñö;ï|ýø±y‹ÁkÓòV’~ûÛß1Ù{µã5ò'óí?÷¹ÏU|ñ/öÌÊãÃ‡Ùæœœ‹vÙ›»”VCFÉ†ç9—ÛÏ(ô:ÑJ„Õ7¶è™lÑŽNæzÆ¡S«¬ë "1ÂTy’9‚|®jW.83ða·-^êYdC ;åÜ›¥Ù%tÆ–iZšŒêÅÍ Šø;êkâ×UuåV»H´V±v?G'o§¿ŠÛ"M¼ýë{¦þÍÛXôÕí¦æÍw“ŠQyá¶¿|Ó¾?ˆÿÝç÷ã¾÷Ýö©…Ž¸Å'hg%Iü³sçw¦¹ –É[o½õË_þ2ù˜~ñ‹_<øàƒÌz³¼•$fE®šD~í¯¾ÒÚö³¢HÈ^³ëÏªmnÚ£Oc4ééÜ!ÎiÞõÅj‹Ó™w©ª®­³ÍjZª‹ƒða¯Ó²wWu<!)´ÖÎ¶•¹4ÛâÑRFBßmfAèuƒ÷îÚë kÎú–ÎÅ_¸—¾Ÿ¶š]%_Ük£	IÂ’
Ò•vÓ¢RÄOD»ÌÂˆ5´´,õtjš’„5n}¾ìƒ_÷½›k“Tü?_üz«¨œ»«¿ðõûÿVhâŸÀ>¼Ùùï¿þ# o|¸˜šòªYî‰Û+7Wë^¼høÏó©O}ŠYb³G-"A¯ÛÙÑÑáLhÈÔÆÆÆ::ó–7hIÃá%Õ¥Ú]6µÞh4è4Ê4çT‰BñPK¼½cê}…]–êšÄõDÈ€P“Õ¸úS‘2'UÐÙÜ½ÆFWgY[áƒn‡e²ÖÃ°::æ¹¸Á0zYK‡'3¤JÁd2ê9ÿ^f¯Íôây-š¥ŸN-SjTÒ.WØãôuŠ%È›éï½y‰ŽµI¿ô§Ï¼üàç7Í^¿–sßÝóÏ~¾ù§/ùMÄóî›oßw÷¶…­qcYvÁ}ìv Y!£Mrù­Ðt_þ{î¹ç?}€Y‡n«>I7nÜP©TÌRˆxìõÍôº¤|$H‹B2¡s3ïwì­¶x&K2µ¡®Î¤ŸZ@%¡“U®h3=_=òGªÔ[[2Ÿ§4í)`î ç~>ÂÑÊ€L’rí—Œ¼]Zoó³¦†ºU¼ ýIYVÁÑG¡	d–i„Ö¡T
©‹+tÖ¶G;ÓîÕõn·+¢4Ô5$ýˆ…Nó·kë;‚JƒÕÚ°\?#’’”—'â'µ$bY‚òÄÛÿþÓa‰ê¾°·î³ŸNµÑw}þkf)ÿyûWF~ºð·g÷/|Þùb –““óË7Ž[N¼ûî»äÛûï¿¿Ñüüw¬Ë¼±ÜOz%†Û¦D~ÿ{f‰H
	çM\˜”ž2ãe¥Î¨uxºx•No0u"ÍmHmÈÔöcÐCŠM]Nrª"EM†	‰œëUjÏÊ¦®	"‘)¤a¯wòr³•Þæzb™¦¡}½®Û‘*ëÚ~T'S,¨ ½’[ûôAòs“.Mi†öþ¼¢md¤Ò¥,õHXµ[Ó°Ä{}…RÅ2Ðãò‡µšåHI¼«òÒ£ŸhþØóiõ<ò7=týÆ]– Ö¾ûî½×ñ/ŸØ¨¸g· Ø¿žgüL½Œä×“êë¹ßÎåþ¡û›'O2+åù£fÍŸÿ9³4ø ŸãiGÇY§3>a2¾„	óÒÙ×ÅàÃ~²k	«Tf0¹…^ë5~-3	½œûê6Î†õ!Â¹Ýœ„iH  YÀeIR!ŸÇ•¢·gs-0Ñž”™R•ñÇ{Z]’adƒvÀï À¢!$  ÀCÇm   €ÙÐq   `¶Û©ã6B   ,™T·™uÃm   °¼ÖiÇmT’   `ÉÄ;n'ß²~;n#$  À’‰wÜ&Á(þíý÷ßÿÍtÜ   ¸}:n#$  ÀÛ¸qã½÷ÞË¬sn   J   ,1tÜ   ˜·   fCÇm ¸½Ä¢£Q `ñn§ŽÛI g4Å¢ÜÅç¾¼çùó7“ `y ã¶ˆ‚‚Bf}æ3Ÿa>¦b7Î›véö½8´g¹•<,‘PŸõYÓ‘s—G“oÝì6ïù[óÅ€\k¬ÜÌ½z¶‡‹1  ‹r;uÜ^Þ‰Û[Yö®»îzï½÷˜å—ŸŸ¿uëVf	Ån¹{.öô^› ß°š'M_­Ú.ü¢7z_}µw`h(8–+g·•h«öì.Ý”;k£ÜÕÞîËWú|!ayŠ²Êý¦=;fo—ØxÐõÚ…ï_3ytŸ¥UÕål¾øSù®&ä•;‹æÜ?:ôýÞþ Ãj+Ë7‹?8ñb{ÝWC¹;*+wÈs™4æ+«×5%Êõ]v2›æ?âÇÏ­žÃÆSRÛúÍÝ›…÷&Æ?Pçà˜’úOIžíþb7û»¯ŽŒLŒ?¾gwÒí¹›+Œ»{¿òBoé7«ž*ë=~éÕ«åÇÍzÿ  Óâ·[N¼ûî»ŒÐq»Ñü<:n‹ 	éxã±ç¾ÖÀ,³;%Ëñãäÿ™%¸N?¦/”|Ûçn­ç'¿}`ÇŒœt>ra„„¹¢ˆ	q×{í×†>\Q4yòÝêo=~ª‡›HzÜx°ÿÂó\èHÓ¡òYyböÑÇé>ÉŸ¾ëÇÎ*›Òb¡Á«Ü„¼¢l*#ÅF‡^ïíí¿60HC	.¥lEùffî#o½áîéëóú†…„S0^R‘>²Ì<Vv¯+ù={åÔ©nº8T›ïˆËg´ÿä~‹;*WlÎ›÷	Ä¢`(w[Õ¡#Ï”mZî§›[P@‘“›;u¤Ü¼<áÿss˜¬E¹Ë=ÃLAÙÓ{vÌúíÉ/~|O™ëÔùóþê±“Áüb$$ X4tÜÎ”ê¡‡¾s®ÅÙsùW¿ºõÑGK?¹}Ã†÷|îsý×µåþ-ÌR‰½xŠf”Å#?YYþðö­ùÑ!·ãô¹Þ@°çô%­GÊ§Î5ÑÇišäå‡¬B*tœ:vÁ××ÚZºíØ—„˜ãz¬B’?üXu•¶t[ã®^8wöÒpÈ}îl	{¢²hú¼;Úö„ò¶UÖx†V¢7úg[ú˜‚<Ñç;ê»FrKyÙtiôúkg¿701ß®½rîÂð¼Û¥8Vv¯+éý|íœÖ„‰PËtÓáË—®í.­Ú¼2¡.9#å.øÑÁç.“w;'ñuwxÖ½c#Â’ŠÝ%…¹…›v¬èð8 ÜÎnŽÛ+Qþzà=ð_˜u$ŸÝýDçfŸ9TUœÈ…ÅŽÇÆ´Œõ÷ôÊvÇÏþ±[W»iž)zìÐÁDÝ¨pûžÏpÏ^8ß3´sÿv²ƒÜ"MuåÀåÜÇ§NCì£û1cÏžtôôß¬xrkâ$HNi/¹È)MQyÜºg¢Ä’¿µü@K™1–›/rª$#eýCãò²[¦‡Ó6UX¯T»óÛÿ\O(ÅÍe«ZœUô«Ñþc{¢œ™ÇÌceóº¦E}çí—‚$€î˜¸ÖbVQaÉS'¾]™Ñ,œØØUGS÷ÈÄ¦mÛ²­#ÅB}gÏœ¿YPyøÐîJWI¢CçíB™“™ö_I•LÇ_!ãÈˆH  3 ™¤¨ÜÍåõgÊçÜXZQœ700º1câ%¢Ïå#Éb‹¶¢x:¡ä•U”¾xÝèD·Ó;rwî·îœ}”Â¥îÞ±ÐP ÊlŸ¡F}—ÝäLV 5îÙ9kJ4!cÃýÃã¥eì
œãf+ó×5%:ÔÙÚÍ1åOjÎ_cVW~QqIQF[F‡®Æè€jqIÖCƒ¹tvÛÈpÑxs¢ctÛET&¾ñâ™¤NVTQkÒÍÙ-uœ½42!/}t ÀlIÙÈÉ+$‰g<61yº‹†|rê”Ï<uæ³%lŽÛæÆbÅù©OuydcÌ39à5:Ü78FvXZô{ÑÏ   IDAT^’éIkt¨op<oû#+q–ËôXs^×¤èÐ…sG&r¶=ùdIžë<³^ÄBÃCÂ hé–ìßæÄÔ¢,&~¿rs•’¢ÜÅoê	29ÛŒG¿º{«È´ýÑÑn:­è›Ï  À,è“”…ØÄØ-º‚º  1Ï7„È©/G¾evÑ§ ¨¨Î÷ 5§4;£gòå…‰¹F±Ñá ÉH9Š’¢LOZ£C×†ÇóJÊ‹W$#ev¬9¯+!Êõ|·›F¤/×VlÍ[d™$z£×~¤îà¡Sçß-÷ºõØ­á~n‚d¤²â”?—èÍ¡¡›£¢ÏD¨Æ˜¤lÍÝJšbã´ì´¨Œå.Ÿ9a'eNE…ùHÕVÑ§¨ƒ*J‘‘  Ä ’”¹Ø­÷­±Û'3ÑøØè„Ðjö@Fnþ&š¢c£iÎß£Cn‰Dy[¦"QtlŒÜÀÊåù£Cß¿pþò5Ÿ°:-§èáª}žÚ9w¨g”ëóÑ“w³ì2=ÖÜ×%ˆq—[^&éé»édtf¢CŽÃ‡âSm†‡}>îhóÑe\¸ô÷Ÿ|NqYIÊ	I£C¯ZžïK½“±ô¦¿•WœüNýŽbQ:¶G²ÕÂRR,ÔÿÝ'{HUTšéÌ¶è_([ñÌ'ê;?0NêLZ-2 €„¤Œ‘3eï ÍHk&+)±XLOùÈa‰MÌ‰ìoàò 9©ìÐL­E£Âd”‰þÓ©?”S (bc7ƒcëŽ?7vüÛõ;gFèÈµ¡±¶¬¸pùggx,Ñ×E[Pö´¾B#’ñ`å¢ÏÉ±±!ßÈôPÞ˜¯Ÿ}T¾\µ´Q_Ïer´¼Gv—nJ¹QnávMe3ç7!è½FÇds¶”U$Mh*(N=¹)6N³rNAÁÞ§ØÍ¾Ö“gè’Ã¼’'N|mßöÂèÐùS‡¾ç›—î«­}|ª}×èÀÅK#ôÇô˜f+ÚT ˆAHÊPìf¯£s˜œyJŸªÚ>}îJ7ÎCÚÄS’è’´«¯½&|Ž¯zrNç£@ï¥€\sð;BÅ…žgßxñÄ‰î‘Þ³­¥ì‘äæCQ® ”S\]²†2;VŠ×ã\ö×|$"í«­Üºe‹\Ú/hbú»Üe{b\ß]À¨©Ú‘¦X•ÏîÞ`Î­ÑÝ'.ÇŸfañãFÓöŒ^{l”Wææd8$‹1‰×O"Ùùï‘„”ÃVÔ­-ÓåÊ‹ËvÈ}ý¡—žÆUQœÞ>zõ{­¤î•Wòä¾²M  ˆÁœ¤ŒÄ®³rêÏ+5î´(¹yÐÚO¬^$™è¢ç¹[ý»;D¢Ä—¿Z™ü9>q²WTÿ‡£»'.¹ò_Þ_YD*&=ƒÉ“œ¢«¾©í¬HFÊèXâ¯‹¼}‡o<gÛž»—dd'W^º«l*±ä•TîÞ¶LF4¿â›`
4O=¹=Ëc¢Î¹ã/ø²éBÜXˆŽ±æË3*Ž^=sðPëñËäÊËçÅ¦oÛ›•O¶È-,©:ñí'Ÿ.-`&½§öý­¹ùÌ©Ó½!òÆ=S[¹ò]	  ÖT’2zåL«D¤‡¦ŠÝs„ÆÈÑø Y²X4>)_¬ã.Ÿnq‘(±gÿî3sãÛ•VÎºøI¾¢´¸àR`,4Š}i2¦DýÁœÍO—lZ‰Œ4ÿ±R½®[îÖ¯“³¾<?èz¥ujë6I"cYŽïäm"A'‹NÖ¹›Ê¶²}½¾è–2í²6F}¯žl%á¸ ÌhÜ™Ýœ'’®šŽžqÓ$òÄS…îÖ¾ø²þLŒqƒt%]+Ÿ7•Ånt“¸32®DwÆ7Ï/Ú^2gÃÜM;ž´¶–tŸýÖK¤¤ÔÓKnÊÙöÄ~m" @JIó‰¾æÄyzQ‡GÐÎüØ[(—ç1#ã¡ÀX”Ù”|F£71tŽ÷œó\,ÔöT\%ûWÍZ,•//.Ê¹…bLñŒ{èØ9}ÆÆ§Oµ±Àµ@NÑ“%+p¢›ÿX©_WtŒ]*C½—f?n"p­' \E›åå>ò‹vì6î`–Í¨ï¥c'.Ð~âVdõÜ¢CçOùž¦«ZëaMôE·02›YÛÊ›½ƒt¼²”M?A>6úÆk'Ž]ž((=xd_&e®ÂâªÃ_­î‚pòÎ»Þ(Û·9	  „¤´HB:enºFŠ#[ž<Rÿèœ‘&ò¡½(çÚðœ~H´ÉElÁÌE$I˜Oµ+¹ºEá–’ÍÌ 
EËg,‹
WÙÍ-˜š|z¡Ù•ÈHé•öuå³UÇšÊf/f‹EG.·´öå•>]ÿxq^~ÁÚZa5:ðÒQ!!<rèdmYsÂG‡.¶œ²_#µ šÌ•lntˆŽÊÆ2«$E9W¡ËÙ¦)M7ºxê„ý:U´¯ñÈn6£ß’ÝŽ=/$¤…<_:þ¬¯òÐÑgË”  D $¥ê´˜_¢µ‘mûŽ«.9‰ç•ì,b†G†®î.šš KÆ¦è€IAIyrì(²´ô‡Hâª<ql¿è,àÜ¢Òòm¯rÃ×¾?òTqÒ£ÁÚÌ°`ÛvEâF2Zu50QTYV´üá"ý±æ}]¹´iÂÜ‡å¾Akcùr¶dGÉ
HôÚÀ/]¢]
®=yH›ñ¬é•e$-j¿vü`Öá#:ÔÓzq„” Ë«5©#9ê¬¥E¸¶ æà‘_Ê(^&=7yÅÑoØë³>w¦,À…¢âk  >ö’Rˆrã	)gÛ¾oZ«Sõ¤W ÙöÊÃÝÝƒ$Ö.Åý¯ô’¢Š¼¬bz|,à:ý|Kÿ½(Û‰oHy%Ñ\VûDÙE‹»çœcÇÔµÛF;tLªH35W)vË×ÏMÈµe+P€Iw¬L_×z@ãÑkŽ—®ø„nG%O:¼gG†Ãl±ÐàåÖs/^ÒÅþ
Í³‡ìžõ;“A!)ê{õìkdd7§ä‰'ÅßIòvŸ9xöú-S>¼ÿÄ‘ªâ~ü±ÑÁË-g„âVò£´ææWÿDieñšªá ¬Ibè<$3íVÌÐ¿¿ô÷ÿñ¥Ù[lÙ÷³ÕtŒ#w³f¶çùž‘KGþnäÑ‡·ä1!ŸûGgy?ñøT„ŒF=¦?Þi0ØsôozfïO^ÙôñŒUX¶ÿà.îø•žãÏ•••ÈsÆ¸kBsFyÅ¾'¶O×‘ÜCòòŠ|±§ï:ÛÒÍ	\±ñ =7ŽØŸ3„U8\)Ï xæœ+Þ®:6v“¾Ü±×[rÂäñ<öÉÃµB»4ÇÊæu­]±[oô\¸ØÛ?0’èYPò˜©ö©G³©ÑÅB¯„$/{ºÖT5;Z%Öç§Ýƒ0ê’0Àwà`Åì2Rb°n"8p¡—­yºö`UŽä£žÖ³7mÔÄä°;iÜ15É=W¾cw  © $‰‰†Ç2Ý¸pÇ³ÇŽå¶¶v_÷¹®ø„›òØ
ãÁ§§/ùfq¹ûÂûOŸ”Ÿ9ûÚ@ï½!G^²kÉ¨ê0ºÊM¤¼¨m40ä	Ì¼m,˜ˆ Ñ@lrxe”æf>±‰PK|™¸ØGšceùºÖªÜüüX`Hx{r|yÿ¾ÇK³ž£“_²ïDsi(ú*~Q®Ïå‹É‹
r£¾!ºï¹W²Kìlí&…Ë-™kÅørÉ³$?Åœ¢ŠýGŸÞ½u¾Š0høÚùÞøœy’ûªj<S†¹G  YØÀó<ýÏ†SÿŸüEš¯ç~û1q4Wä(ØÍK´$=:¸5ž·¹höînõš÷Ÿáv4~×\¶ìã[+y¬Õ½Ñ?0&/ÙÎ.Y3[}Ç÷ëÚøÔ÷[ž8÷í}éF¶b·†y%;6§Ø&•oÏ¤ÝA,pùÔá³×¦cßîÒ"Œ© d!i=}ýÄ3'‡KŽ6_Æ–­Â±n/¤ž72†ÃÑv§fE®3%xýµnnKeu9.Ì °@n[‡F‡û‡Çó¶”¯@jYÉcÝfò‹ŠKŠÈw”1« ·èQãG  X8„¤õ‡ä–Á1áŠôÌ²[Éc  ¬)n[b£ÜP‘oa7­Àêÿ<  Àš‚    b#    s $   ˆ@H       !	   @B   €„$    I    "’    D $   ˆ@H       !	   @B   €„$    I    "’    D $   ˆ@H       !	   @B   €ˆ;˜,EÞ›úzÃ†   ÀzP ½+«íQI   ±çyf²&4UJ.¥úšA%	   n_¨$   ˆ@H       !	   @B   €„$    I    "’    D $   ˆ@H       !	   @B   €„$    I    "’    D $   ˆ@H       !	   @B   €„$    I    "’.ôº\Þ0?ç>è!wpžÉRØÓe·Ù..ëG
‡å\y¸Óa Vö{Äþ.  ¬gw0 Žº»:<Á™7Ê”:½N)¾ŽpNK­-¨iêlÑ³’¤Âîæúš®ˆÆÚÝf˜qÇü‡ô:lv¿L«ÔiY“>è´Õ[\4‘˜ÔÞÞ –&îà\]ÞpêGJj^£Èæ™È¯¬7¬P±aGm•›ûw `]CHJ…d »½kVH’jXídH’ªFg•ÝbéR%¥¡ˆ×ÑìJ5Ö†9§òiÛŸ®¾ÄsB(B>”{déB’T¡T*¤3Ÿ®·ÃA’L!½öÚZY[‹Q%^ˆË1ç…Ì ˆ(u«’"œ?(QÌ~!°Æ„Ý–Z{X¥7têY¿$ä®ê½ñFWµÝÖìR7é·àv±çéI{Ã†SÿŸüEš¯ç~{›!£iþà¬D#‘±JVÂu˜-]ô.žœç¹½•UHãg„È]$Æ°2z‹Te²6jÂ]~{u•Í»C¬±³»Q.xÎ±·Êâ‰(Míú yo½+H]cg‹Q)MSIâ#~W‡;¨0´_±jV8«„Ýæª½Bv“²jZ£Ñ(2™‚];©)â±Õ7{y©‚UÈ$óžøùH0ÈhW´”B+9<«f—ï-ºj«jœaF¢"¿[ª™âý{«Í‰®¥»IÍÑÄÄëÛº›´Ù–A–u(½   IDAT Ö&T’R’*Tj…ØÓ3~$RV¥¢_ðaÎ¤urŠWªRœ"$2µ¡Î¤N=aˆ{ºº¼F¡1L–«R<³™uR¼27{"$;5Õ¤ÕÔ©Ùkñ¸-õÍl{#)~´âvqNwº"Ó²	{§á<Nò§#ñ-ySÕjšÔj+]Åªý©z<$^º3}„RQÇ¬žsÖï­uZkK“AµA‰÷;í.°Z£È$J]ƒ©«Úær89­ÑT§çœR†~ìB-	 n¨$‰‰xíf›+(Vó‘(ÖFÝìbAÄmÞµ·#¬jìî4*|‚à½öª*›_¦m¹Ò¦ËôÃ8tÖW“3¥DijïœœˆÄ]æê2Æ&ÔœTd›TK6³™~ÙjT’âu80ô»ÝnÛÃ‰¥GZdÒêt:­F)[ñï¼³¹f“²½^½Re>ìn®­µ{èû&aõÖ–F½riˆôw«ª–”‘¤êÆîv£x‰,ì±Ôšýl]S#ÆÙ àvƒ$&ì®¯Ú›b²¡»Û¤Î<}òœ»Ãåçej½N•|2—©ôíœsKÄc©ªvpL¶¤š¦+múYE${Í^›'"ÓXÛÛÉù,ì¶Õw0&+ÉM\ÇÞ]fOêa>Åê„¤Y"A?“Ìä!™Éãñ'‡“9ã‹·9ò·R"É$qÐ˜[[ïð
AIilikÐ.]R!#¢d þžJJñJŠmA‰ºÎj5¨eHH pÛAHZ°³fW­kþƒL×r¥eNQ(’¤*~ÆTØ”Ãm<œêò„g‡$ÞßQ³×ì&OcN:®Ôëut¶JÄïìí°šs’æ	z=nå²&ÕÇå$v[j,~µµ¥!³¢TÄße®©w
™žÕ·´YuÌŠšwHŒç:j«Í®Ìêhä÷¼»E§`  n/˜“´ 2]Ûº¤ï4Ü¦PëfÌ„%Ãmž.¯Ÿ„›º†ÉŠç~§gÎùJÂ’4¥‘ðŠˆ³£Ëá™}·T«ÔéXú…RgRêDžÁ*ÎIš—T¡ÒÈŸå"Œôã’"te"­yÍvMfÕ3©RßÔ.‘ÔÖwùy®«¶*lk1¥ËWdÍbê­¦ÔÓ˜"žæzMH•ÑZ—¢:%tèð“ßdN„ ·!„¤èR´]6ï¬[%jë•vÃ-_
zÍ¶Y•$XÂþ®f›V%‰ûè/QhÛµL„ÓëÂ¼pî«ïàX}S£Pn’È”·Å •Dú1gãƒn[mƒ®”ªšê2_”°º¦N…²¾Öâ
F<v»S£JÖÃ{s‡3ÔèfÝNŠøµµvú4Z«µAŸjOAg‡Üe*­
ëÙ àv„”]ŠÖ0g)š”Uø-_übGŠùD^Ë®-³oL9;âu:¼b»	º;ì/¨bâËÁXšëüdÄ“²Jµ:~^#C&Õig#%žHÇÞ’Ž,¦&ñAÓåæV£Ó:'3a·ÝÒÅÉ”J¥‚eU*¥bUŠÂn›™$„¤¶Ùá#AŽcÔuVSV“¨"þsY4“iZšLÙ®V“ªŒ-í2‹Å©05¥)gÒ>^]ô÷7èå"z…LäþÚ‹[("545éSï‰üÐÍÈX¯	 nOI©ÈTz“jîÍ?¯T«¤Y4;’Í™,$U7þàíÆ¹[.hu[Z¤˜¤VñÉI§ó£Ãñf33€D‘Q—ï Ë¼·&‘í»¡­ÝªÍr¨…çœöf'ÉnÎÉ[è"¶8µrµ' óaÎãñDO69u6™4‹‹Ò$®'ã¢oªTeji«Ó,ìM %k».Ý‘‚.K½P#’éêæÉ	ÓÀk…R–Dejk3¦‰y<çv
ë=2 Ü¦’Rm-!eCK§!þ[ìñxýçõFTMFÆa¶s¬ÑÚ áÝŽŽ ÒhXæFÖÂ›Ã–4ÿ¯H% ÊÏ?¥±©­{ÆSH#Òá™ÆéZá%•M"~§=¹ŽÆuØµ)»q=‰LS×Òä'o\ÐOÞ@’Ù’¶x®Óh´ZJÉÒ&Ži÷”ˆ•Ì"HfO$“(Ô:ƒBšòí u´.§7Bâ]îŸb+RrÌèO§äÛÌæ.áGH{¸ÓÑ­å]$áÔbªHRMÝœ‰Fd®¾Ö&Ô†$*c[[ƒ&]ô‰ø»:Ü4#)ud$ ¸]!$¥Às]æ*±9IÝM¬Ûbq¸f,Q—(”2 ¥¤©‰×FÂž®æf’]œîÆÉ‹ƒL;k¾XëJsÚ°«vÇ}µ¢w)MÝ3æz“\äñÎz–anú¹#Â’¹ Ò Ÿñ4‚~o˜WÅƒ@ÄcÛ[ÓÅè­-ó¯'e–ðì£eYSR8Ò’?Ìäk ëØh¯$·7È“pêî"ìÂ}
µ±‘<­•¬.ÉÔF«:ÍýÅMB’D©353=N’:„åû¤ºhjËt9ÛÂÐžæDcÈÆY}ÁÉX_ýÞø¥ÞÚ4_Ç%·šéŒm’¶ŒúÛcæ €„¤RÌI¢×L“Ê¤´·R&åý.OÒú°Ë+¥=n™¦±ûŠÊV_ï°ì­•tÎl`DÎ®u&VliušŽÛÎÕáš;JBRÓÛ¦É‡û…k“(®tš¦HNéÕf‡_êÏXòÄ{I¦3°J	ƒd &"Ó&®£’ž”^£+™y³(“ëØ˜Dkºðßh.ŒHÒ^DÆêŒFd1J–åóç#a¡+©5-ì°¤zäêr8Îä>•
ÍrNìIšNÆÑššf_:EÊjzµ×T[Û¬ó§ž°Ûn—‘uZ\Ð n_I©¤˜“Dé›:õô¿¤äãõ$- §çMáJ—'±ºÆ6©ÄÜ%=Ó‡Ücl8’² =TÐåâD›ÖBŒ7(U©gœ¬ø ËáàzƒNÝÐdåöšÝ¶×ÚìäžŽ.¯ÎÄÛkÍÎ TÝÐÖ”Ñ5Ç$
µÅŸ8ßÒa™ëRöY–ÈXµŽü1
W%#a‰DÐt§l‰BkjÔ2+‰^¢æ©,ûkÌE‚—Ãnïps‰â›T©ÕH½"Ý–)û˜kj…a6†5´´ˆ­š“(4?2e4èv5Û„N«¬¾Á¨B	 ncI©ÐªŽ››SIÒ(ƒöÄKÈÈ=»9ÍÕ~¡ÃG8z&êª¯ö&e%KçwÌ‘‹”- h%I8Ôd× ©R—(6Ñ©¸{ëýÚöî¦ä1"2”ãp¸•JV©PššüÕ{;<Í–uËÔV\—­ÞË¹ýŒŠôd¾–ŠœN»ß0ÒÙß²å¼­D¡ÒUÌšá¼ô§OX3{:ìŽ.—Ç›T£¼Î¤×(8[Õr†¤°Ç^[cfXÓ„ÔÞ˜fD5“„D+faVšBWW§Ál$ ¸­­DHòù|ÿò¯?øå/õÑG¦Ù¬x[ñ3ûö1kqØì³§KÕÒ:™—œí’oŒý3n˜õ½TŸ´ñwÙíu0NÝ`²k€D­Ðj•R¡’J£”º½î`D-Iz
XÂN^ðL¦©³š‚öÈô‚}Rôâ#Þ ÂhÒi†¬§ÂHJÕÇ³{`Äï‚.«Êb^„Å&%HñÈ`2µ‰5YÏçÊí*@
…B	‰ÎÅniXä:šÈkë…ßaÖÐØ¨EI ¸Í-{HºÜÓs¶å\&[úÞ|sâý÷M_ý*³vH5‰†5<d¨wp™¶éGÆ–ø½d¸mWS2Ý^(~½F¸Ð˜$ì÷†eI§R™¦¡­Ó˜î¤Èsf2øEgÌ^Zv7›ížì²Ò^–’éÕS™R)c:üþ0?Uw¡+ó‚ä<¦š:'ÊÔíj:_*ŸØ¤Ð”^»ÓÍ)êVö²¤äÌÝÜÁIÈh•R¥R«V}ÅvÂžx‡rV­É|*“Lcj02nF©Ö¨W¨ÇA„sÙm6‡+>®'Óˆ, ÈZØc«­¦kË4Ö–FíúúÉÀ
ûè£Aúïe‘B±~/b¶¼!i||ü»­öÌ·¿Ø}1çŽ;ÖP=I*SªÔñ:K0è?)ðA¿Ëf"tÂµ’•I?ŒD8¥¦ÞÉÚ»­“y'Ñó15^â•2NzPµzVq'NI´‚£žu²#)I!áÉ Pd*$…ý´Õ£vÆy<ìí°˜I€kPÄ¤1™8O½«ÙæT·èWnò-yvGGr¡¼+$-©5*•2£¹ã«(âí²íUz]6‘CÂê›tÌŠàƒ¤ÚØÜì˜¼ÚŒLmjjª[t+
š3Ñèð­A‰„ ©ƒÁãß°¼óÎ;äëûî½×rüÄÝwßÍ¬CË’np7&&&²zÈùÿq!77÷ËO=Å¬MdÐÌÖì&9ˆà„eNŒÛV/ŽÉtmíRÕñøÉYª«ËTèšŒsF²"^‡ÙæŠU”h£#FX«½·jV"KL#‘*2	Ç'õt’(tF#?•¦ø Ûa®§-p$*XÆOnÓPçô˜]æúfE{ƒze&àò¼D©3hi{©Ä|¯çuqt¹—ðL©Õzíê÷”œ+ìi¶4µí	´º½ÙÖ15ÃIÂê›‹­ Ñª”ÅbR×òw* €uœ÷Í_ÿå/ÿöíwÞùúñcmöÖ72ëÍò†¤ßþöwLö^íxüÉ|ûÏ}îs_ü‹=³HZqu	‹ñÉˆ‘”Txhc™£ŽTA”¬BaT
G—Ûá96Š•h"þÙ“šæcÖ$§yHUÝ>«TÂû‰[d*­^ê÷»n–eÜäÔN‡]È)“’0{ãIè9´Ñ]]ï²ï­Ž4µ4êV ž$Q¨já'EWåy„nœä¿“÷È»ë0“?4.‘rš–R+Ö@ ‰xíõñJŠL[× [3ëÞéEPÈ/”Ëétº’V(È_ƒQ»Ø¨É‡=4^»â¿¬¾©üJc= ¤õÖ[oM%¤8RRúÅ/~ñàƒ2ëÍrÏIúˆY~ä‡Ñþê+ÑñèþšåŸÏ$U™Ú`"Å©drN’J§×O^òLHN]úc>pGü.£Ö&-c—jš~ôv“è¾Ó\–$~¨Å$‰TJ›JzœÂÒrosu‰mV«p	"i!§v)Ã'ÏEr©±{:j¿èî2Y­ušK$R…J£'Œô>LNõ$,yhKI¡hö{œäÃ"SÉs×¬^e)9)È´Öö&ÃZˆH$µÕÖÄd'“)u:£Á¨[l²¤Íœ„Q»ø¬&:`Øb5 o$ ,Ð~À¬CË’Vn®Öÿ¼xÑðŸÿæSŸú³„‚]{wt%}/¥)H!~þ¡ƒZ6Ks|1Q$ÌÓ“=¿Ö;œMÝ-ÚÅPÈÔum:'iÆi*âí°5w¸=þäá;2ð&eU„F¥ðw4»¥úFkÊÚ‡LÝÐÖÉZjÍ]É¼WY>p#XË   IDAT«Ò’?42E8ËMþGŠL4.Ex™lµ®K~~6‹-~ÕaþsÓZ	
R¥N¯v¸ÁYªÔèHV×iU‹GñöçN2Z<U—RhŒk¨x k©m’Ëo…BS·ÜsÏ=üéÌ:t[õIºqã‰Ì’°Z½6i|Aª¤ëî#œ×æé:¯F!‘A¢{s³ÓÏ(õMWLÇÞÚ.sm=oRû»¼Œ¢1õÏ¹;º&'‘˜ÇÙ<Õ¬oÎëöƒ!2V­R«4ñ	ÐÓí‹ô^"t†Žx»:\\„ÃO‡Ú’b‡”>iµ1(Q®ó ”UëÉ!.¹º:8ÅêŸ%2¥JÉÊ¤‘fK€çÛ“%‰B×ØÆ¨9†U.Ý"Áçl6OÖ§h7.“‰%c
 d!''ç–o·œx÷ÝwÉ·÷ß£ùù;îX—yãvn›ùýï™¥%Sêæ\è“zí5õ“W_Sj´J…ÌŽHuua˜£¡)¬µ9-õN’¡ô-i†°$2IÐew$]pDªÒf~V’²††µD©R¥Z&™¾vç´'?É4úYW“(”iÞ­)«562«Jª2¶ü@×‘.S•çã!‰&Þgy™JgXÚ~›d4¹¥]j6wIt‰_f €¬Ýwï½Ž|1lüÄFÅ=ë¸À†ø¿Ññ0õ2’_Oª¯ç~;—û‡îož<É¬”çš5þçÌò£}ˆ8á¤&cS-['ƒFnoƒÌ·P+Q—¢$d8M¹l'¦É¥yÒ°â?Z³Â”h €µ—%YzF›¯Ø#¥W!c20oû¤¥²b‚,à§ °f!$  ÀCÇm   €Ùn›ŽÛë¯ý%   ¬YñŽÛñ„ÄLvÜþðÃ™u!	   –LªŽÛÌ:„á6   X^ë´ã6*I   °dâ·“oY¿·’   `ÉÄ;n“`ÿöþûïÿæ	:n   Ü>·’   `‰mÜ¸ñÞ{ïeÖ9·   ˆ@%	   –:n   Ì†ŽÛ    ³¡ã6 Ü^bÑÑ( °x·SÇm„$€3ŽbQîâs_ÞóüùÈI °<Ðq[DAA!³‚>ó™Ï0S±çM»tû^Z³ÜJ–H¨Ïú¬éÈ¹Ëƒ£É·Ænv›÷ü­ùb@®5Vnæ^=ÛÃÅ €E¹:n/ïÄí­,{×]w½÷Þ{ÌòËÏÏßºu+³„b·†Ü={z¯MÐïXÍ“¦¯Vm	~Ñ½¯¾Ú;04Ë•³ÛJ´U{v—nÊµ¿Qîjo÷å+}¾°¿<EYå~Óž³·Kl<èzíÂ÷¯‡Æ™<ºÏÒŠªêr6_ü©†|×òÊEsîú~o€aµ•å›Åœx±ƒ½î«¡Ü••;ä¹LsŽ•éëÊâýü¸»ÕsØØâcJj[¿¹{³ð6Æ¸óêSRÿâé/É³Ý_ìf÷Õ‘‘‰ñáÇ÷ìNº=ws…qwïáW^è-ýfÕSe½Ç/½zµüø£Yï `Z¼ãöqË‰wß}—:n7šŸGÇm$!o<öÜ×˜ev§Db9~œü?³Db×éçÏô…’oãÜ­õ\àä·ì˜q^:¹0BNürEâ®÷Ú¯:}¸¢h2'Änõ·?ÕÃM$=n<Øáy.t¤éPù¬<1ûèãtŸäOßõcç•Í±ÐàUnB^Q6•‘b£C¯÷öö_¤!‹—R¶¢|33÷‘·Þp÷ôõù}ÃBÂ)/©H’f+Ó×•Íû¹rFûOî·¸£rÅæ¼Üù¶EÁPî¶ªCGž)Û4ïÖ‹”[P@‘“›;u¤Ü¼<áÿss˜¬E¹Ë=ÃLAÙÓ{f¿ÕùÅï)s:~à‘Ã_=v2˜_Œ„ ‹†ŽÛ™R=ôÐwÎµ8{.ÿêW·>úhé'·oØ°ñžÏ}î¯ÿú¯¶Ü¿…Y*Ñ¡OÑ3zŽâ‘ÇŸ¬,xûÖüèÛqú\o Øsú…’Ö#åSçšè€ã4MHòòCV!:N»àëkm-ÝvìKBLŠq=V!IÈ~¬ºJ[º-ŒqW/œ;{i8ä>w¶„=QY4}Þí?{BÈyÛ*k<C«GÑ}Ž³-}LAžèóõ]#¹¥¼lºÎ4zýµ³ß˜˜ï…Æ×^9waxÞíR+Ó×•Íû¹ò&BA.ÓM‡/_º¶»´jór§¤¸äŒ”»àCF/œ»$‘+ñuwxÖ½c#Â’ŠÝ%…¹…›v ª Käöè¸½å¯xàÐÿ…YGòÙÝOTpnö™CUÅ‰ÜQX\qàxlì@ËÀXO lw<ÕÄn]í¦y¦è±Cu£Âí{<Ã<{}à|ÏÐÎýÛÉr‹4Õ•—s;lœ:±î?ÆŒ={Ò=6ÐÓ³âÉ­‰“ 9¥½ä"§4Eåqëþ‰¢NþÖò-eÆXn¾È©’Œ”õËËvn™NÛTa½R!ìÎwnÿs=¡/4—­jqVÑ¯Fûýí‰þqf3•éëÊüý\Y…%OøveF³pbcWMÝ#›¶mË¶Žõ=sþfAåáC»7¯ø«Œ·eNf"Ø%˜b«ñ×GÈ¸'" Àh&)*wsyý™ò97–VçŒ‡nŒÅ˜x‰(äsùH²Ø¢­(žN(¹Ee¥/^wúÑíôŽÜÂû­;g¥pGEi»w,4ˆ2[ãg¨Qße79“h{vÎöMHÄØpÿðxAi»ç¸YÇÊôueú~®´ü¢â’¢Œ¶Œ]ÑÕây¶O4—ÎÆ.ÏbNtŒn»ˆêÑ¤Ñ7^<sÔÉŠ*jMš¢9û#£¥Ž³—F&ä¥nCB ˜!)9y…$ñŒÇ&&OwÑ/@Nòââ™§Î|¶„ÍqûBÃÜX¬8?õ©.¯€ìoŒ™`&¼F‡ûÇÈKËK2=iõŽçmd%Îr™kÎë7çý\³b¡á!a ´tKöosbjQ‹ïGnÎ¢RR”»ø­S=A&g›ñèWwo™¶?:ÚM§¢=¢aó  ˜}’²›»EWP$æùÆ¢9õåÈ·Ì.úÒù´F’f‡côáL¾¼01×(6: )GQR”éIktèÚðx^IyñŠd¤ÌŽ5çu¥Ølöû™•è^û‘ºƒ‡N#´Ü!+vk¸Ÿ› ©¬8åÏ%zshèæ¨è3j€1&)[sCC·Ò„¦Ø8-;-*#E¹ËgNØI™SQa>RµUôi'ê ŠRd$  1¨$e.vkÀ=DëFìöÉL4>6:!´ƒš=‘›¿‰æƒèØhšó÷èÛG"QÞ–©H#70…ryþèÐ÷/œ¿|Í'¬NË)z¸jß§vÎêåú|côä]À,»L5÷u‰y?3r>Ÿj3<ìóqG›.ãÂõX ¿‡<Óœâ²’”’F‡^µ<ß7–z'cè/L+¯8ùú)::Ä¢tld«…¥¤X¨ÿ»'NöŒLä(*Ítf[ô†/”¿­xæ“õ'u&­	 @BRÆÈ™²wžÓÖLVRb±˜0ž$ò‘?>ÂK3’\ 'Õ‚šé¡µhT˜Œ2Ñúï/õ‡r
E¬bìfp,pýÂñçÆŽ»~çÌ(¹64–Ã–.ÿ”ž%úºD·›ó~f,66ä™Êóõs£Ê—«–6êë¹LŽ–÷ÈîÒM)7Ê-Ü®©Ìcæü&ÄB½×è˜lÎ–²Š¤	MÅ©“alœfåœ‚‚d—ØÍ¾Ö“gè’Ã¼’'N|mßöÂèÐùS‡¾ç›—î«­}|ª}×èÀÅK#ôÇô˜fëjÌ Xû’2»Ùëè&gžÒ§ª¶OŸ»ÒóÐ†6ñ”$º$íêk¯	Ÿã«žœÓù(Ð{) ×üÎÝÂGüXèOœèé=ÛZÊInªåúB9ÅÕÙÏ&Î^fÇJûº’·?3–KûML—»lo@Œë»à¢5U;Ò«òÙÝûÌ¹5z£ûÄåøÓ,,~ÜhÊì¥ÆFépen~¦Ã±“xý$’ÿIH9lEýÑÚra1]®¼¸l‡Ü×xéùg\õÇéí£W¿×Jê^y%Oî+ÛÄ  €ÌIÊH,à:ë §þ¼RãþG‹’›×­ýÄêEB‘‰n zž»Õï°»C$J|ù«•ÉŸã'{EÅñ8º{r$W¾ãËû+‹HÅd g0y’S4pÕ"µÉH+Õëš%Åû™±\yé®²©Ä’WR¹{Û2Ñ0÷Šo‚)Ð<õd¶aŽuÎÁ—MªøãÆBtŒ5_žQypôê™ƒ‡Zßˆ_n$W^vø;/6}ÛÞt¨|²Ý@naIÕ‰l?ùti3è=µïoÍÍgNî‘7î™ÚÊ•ïJ  °^ ’”èÐ+gZ}ä”þ°ÑT1ã”ž#4FŽÆÉ’Å¢ñÉHùbµ€wùt‹{ŒD‰=ûwÏ˜	œß¾¨´rÖÅ:ò¥Å—c¡¡PìK“1%èæl~ºdÓJd¤ù•úuÍÚWÊ÷3c¹›Ê¶²}½¾è–2í²6F}¯žl%a® ÌhÜ™Ýœ'’®šŽžqÓ$òÄS…îÖ¾ø²þLŒqƒt%]+Ÿ7•Ånt“¸32®DwÆ7Ï/Ú^2gÃÜM;ž´¶–tŸýÖK¤¤ÔÓKnÊÙöÄ~m" @J¨$Í'øþ™çéE9x@;ócwn¡\žG[6Æf-UÑUjŒ|îy.ê?{ª…F„’}‡«fE‰|yq©M†æ¬×¢c/ôÑãÓ§ÚXàÚ@ §¨´dNtó+íëš±§Ôïgvò‹vì6î«._®„4ê{éÈ‰´ŸxÅƒYÑèÐyóš
ÊjOŸxb‹ð£K?8;%vs wŒW•²é'ÈÇFßp>ð‚o¼ ôà‘}™”¹
‹«­ŠM\Ød"pÍõF ´ H	!)-rF?enºFŠ#[ž<Rÿèœ‘&ò¡†¡Ròí´0)(bf.("IÂ|Bh¨]qøÈÕ-
·”l&+0š•ºbQáª°¹¹Ó—C0ò’+’‘ÒkÞ×5µŸôïçÚ1:ðÒQó…aRDzäÐÉÚ²,æ„]<ñìïùhý©Öj®$ƒ¦ñQÙÌ*IQÎÕCFèrXMiº¥G9zax‚dÒÆ#»ÙŒÞI’ÝŽ=OcS ç0ãÃ—Ž?{°¹A	 @BRjÑ¡Îç6]#&ÛöY­Ï”ˆ|TÏ-"¹|&º:<šüÈ@?0)()Oî‚å.[Ÿ£I"gKåIëÑá›Ü¢Òòm9LðÚ÷Gf¤@u/   IDAT¤hp€63,Ø¶]‘x±ïj`¢¨´¬h™fã$I¬L^—°ÝüïçZú~ëáýB˜(x¸öä¡ÌË]tÒþ½uvò…ök§IBÊ2F‡zZ/Ždyµ&UøÑ7üïêì×Çršúo«.Îä¤Ï­þ9í‹$¯8Úlw|÷PY-'q¡(B €(ÌIJ!Ê]´˜_òÑeZû¾iMy¢W ÙöÊÃÝÝƒ$Ö.Åý¯ô’¢Š¼¬bz|,à:ý|Kÿ½(Û‰o¦¾î}.«}¢ì¢ÅÝsÎ±cêÚm£ƒŽž ]`55W)vË×ÏMÈµe+Ðâ&Ý±2}]¾Ÿ«ŠÄ£×_s¼tÅ't;*(yâÐá=;2f‹…/·ž{ñZ.öWhž=t`ö¬¬
IQß«g_##‘9%O<)þN’·ûÌÁ³×Gh™òáý'ŽTeòFÆF/·œ¢[ò£´ææWÿDiåšüa  ¬Ibè¼3íVÌÐ¿¿ô÷ÿñ¥Ù[lÙ÷³Õ´L»Y³GÛó|ÏÈ¥#7òèÃ[ò˜Ï}£³’Ÿx|ªXBF£ž?Óï4ì9ú7=³÷'¯lúÎxÆ*,ÛpwüJÏñg‡ÊÊJä9cÜ5¡9£¼bßÛ§ëHî¡	yùNE¾ØÓwméæ„RTl<@ÏãöçLç…áE•Ç+$¸œ9çŠOŠÝ¤/wìõ–ƒœ0y<}òp­Ðn Í±2|]¹™¿Ÿ«!vëž{ûF½ J3Õ>õh65ºXhàõ’äeO×šªfG«Äúü´{õ½tìÄ%Z¾zäÀÁ9óÙcñŒ5¸ÎÐËÖ<]{°*ƒ GòQOëY‡›6j"Cx;iÜ15…+W¾cw  © $‰‰†Ç2Ý¸pÇ³ÇŽå¶¶v_÷¹®ø„›òØ
ãÁ§§/ùÒá·Œ÷¸sÿé“ò3g_èï¡7äÈKví1µS—ˆ]å&R^Ô6òfÞ6LD€h 6Ùºi”æf>±‰PK|™˜<žæX™¾®¬ÞÏ•—›Ÿ	oOŽâ‘/ïß÷xiÖó¼òKöh.åO_Å/Êõ¹|1yQAnÔ7D÷öJvÑÁÎÖîaRhÛò˜¹Vl€/—<KòSÌ)ªØôéÝ[ç›$E«b^;ß;¢)É}Uµž)Ãb6 €,làyžþgÃ†©ÿOþ"Í×s¿ý˜‹††8š+rìæ%ZpÜÏÛ\4{w·zÍûÏp;¿k.[®6Ó«r¬Õ½Ñ?0&/ÙÎ.ÙR¹[}Ç÷ëÚøÔ÷[ž8÷í}éF¶b·†y%;6§Ø&•oÏd1_,pùÔá³×¦cßîÒ"Œ© d!i=}ýÄ3'‡KŽ6_Æ–­Â±n/¤ž72†ÃÑv§fE®3%xýµnnKeu9.Ì °@n[‡F‡û‡Çó¶”¯@jYÉcÝfò‹ŠKŠÈw”1« ·èQãG  X8„¤õ‡ä–Á1áŠôÌ²[Éc  ¬)n[b£ÜP‘oa7­Àêÿ<  Àš‚    ·   D $   ˆ@H       !	   @B   €„$    I    "’    D $   ˆ@H       !	   @B   €„$    I    "’    D $   ˆ@H       !	   @B   €ˆ;˜,EÞ›úzÃ†   ÀzP ½+«íQI   ±çyf²&4UJ.¥úšA%	   n_¨$   ˆ@H       !	   @B   €„$    I    "’    D $   ˆ@H       !	   @B   €„$    I    "’    D $   ˆ@H       !	   @B   €„$    I    "’–\ÄÛe·Ù..Â,ô8].Ì3{ao—¥¦ºªjo½ÃÄû  +æÄñAw—ÓL‘td*½AËJÄGNêÍvD«ÔjY)“ùñ"¼D:¹Ã ÛV_ë*ômÝMZóññXjj:ÂJ­FÉpöšj¯µ½I/ú¾  ,1„¤Tø Ënë¦¸W¦Sé´lVñ…ç:öî2{R×BdšÆ¶£JJ’AG³3ÈHT£æã’Hý­ƒß¤¬F¯WO½è Ûá
«›:Ût
²‰Þ¶·¦Ãå×šTY„Ï%vYêíÞˆD¡k°Wüè  °:’æÁšuŠéÒì²ÔwqLö$™R­âç”¦øç†æ$2…LJãB‡Íá'ßË$\W³M¬j"U¨uzbE
*aRÔjöD™¶¡É¤^¶|!™ÔîŠHÔ
­N-K¼2>ŽðR¥,~T©L)“ µCn|Øïõ†^Å  ÀÇBRz«R«“Æwx¿w¡AA¡ml×Î¾1âuÔìµpŒTÓØÒ¨Uú•¥Öær@ÐÓåð¤ØUD©[¡DbçõvyóD*Œ5JÈãôË’(”JçêpTz6âv8<¼¢‘]…BNâÙ‘_	†ú  >6’VXÄßÕÜÁ±Z½NÃJ#þŽÚ‹'"Q™ÚšÈ0ILfs—0ÄÇê­I“ox®Ëbî¢YŠÔsTR’V**H'óÁjÄ©ÚÔ`ô××ñÁzú”äMÑ)V#¦HÿŒ ð1‚´¢x—Åâ ƒWv›R­ä½Ž'A ¥¥A-{lµ5v:°¥…ƒœÛ4µ1)ìêòÐ!>Vo2hÕ+\JIéª™¦¡óGF: IËJ
d  X1IéñË´ˆÝCª@õö.nÎ>ô“y·­¦Ê!Œ )´ÖÄ\c‰ÒØù#­ÇÙl³uy=t,M¡ojiÐÈH|"£JÞˆDihk«“ºj÷ZÜdÜMÑÖ Q0¤Šd¡õ%…¾Á¤^·³†É@¢ÙîMÕsa†®f›|ÛDHUuM›©ì  °Ú’æ!Sëu*YÒœ¤°×ÙåÎçœßãõ§| ô{ãkãÂìŒ¹ÆR…ÚÐÔ­3vÙÌ–o°«fWÐÔÔT§1´tªŒa…Š¤Zš¸êšÇÞjÎ aÜnºÖÍØÔ°ŽÛðäñx½óÍºž~Ûæ’JÃ<?9ô  °Ì’Ò“°ZSƒqÆÄm»WIRuã•·gmÎÓÌSï&å©¶åŠ°p=©RoíÔèõõ67©)yB"Sª!H"Ó6´4†÷Z\îŽF¨@µµ5¨×sE"SéëLªT•¤çêr‘ºœBcÐ*¥â9H¦LuÏ
áùp) àc!i)ñ~g‡'U»-õÍs†›x:×'ì¶íõÐ“ÞdÒÅ§d‡9¿ºß6©_¹\~¥>¹¦•áS¢­»ÝÃj´:õªNé‘ªô¦Ô+äÂî°ËÅ…¥¬ÞÔ˜&[®2¿½ª¤K©Õêu:­F­@Ã$ €ÛBÒÒáƒ.‡#åxnâR7EâCLÞ T«cÃÞ®ŽŽ.—ŸÆ)R}1j%nG‡ÇÛa®ê°«F£^«Qe˜v‚.óÞšŽxS'»Ånhk·j×l Y/Â~W‡…ü!UFÁd2®rö €eƒ”ï±×T9“yè¼lñM#^G³3LÆ‹4·{n·I2‚ÖØÉÆ‹C|Øm7Ó…l“Kú#~‡Ùâ
‡µ»œ‰í¥JCcSƒžÖ•Œz½Ónkvx‚žùÃHYm]SÓ|­Ÿ#~§½#é‰pv§AmZþæ$-6;Âºƒ2uz˜q–tûŠDiÊy¯½ªÊægA¢jìî4*3Ë9
­AÍ{œna¾>Ï¹õäB¥ÓŒ&]öe>  XÓpÛ%ÂsN;íc¤5Ôâ'Ëx_JËp‰ËßJô6•‚	“, 6ÐÇ*Ô†Æ¶îý¨»AÉuØl§Ÿ'¥ÆÎ¼q¥¥A'ÌZ’’ÓòüY'±`,Ix%®˜ñvÔ×Û6K—?åÁÂ®ú];þl¯Í•îŠµtýà®;vÕ;×Êum%2µÑÚþßWÚ­Fíä² ×é¨¯ÚñgUµ¶./.I pû@%)=‰ÚÔÖ>kâvõ.›wÖf´vÒì¦M!MZÖëL¿ORèp‡g<Úëâx)ZkÐ54NXx¿Ûa³{éUâtJúTdJ©EgjŠ„#YU)«f¥]I—èn`–UØc¯­±Ñ™B&Mq,>è´XœäyñA>]ï%‰TFNA§¹^Å¶ES¡ŒÕšEup’eýžŸ„ÆÐHþða¯ÛIGii)ìuÚI\êhèl7áòn  ·„¤%@ªHä¬–(Mäôö¦ß:ì¡—×©Ô¯'¸ÛéHÕ:µ"¹ac$(‚âtKB²Cf«Ü$
µÅ_[Ÿ'%Q[¬ËÚ®š¼æšz¡u”TÝò`aw³Í¤#uui[HÚÆ&Wm÷zHYJÝ.2&F615j™Õ"‘©´Fò§Áï²Û,v7}U
æs Ü&’-ì±Õ›]aFil¤­ùpÚ­#Þ®æ.Ž”œ*’øçqyy™V¯
wÔN-ÑÉO4Cq]õUž“r$Âµf3)VHšÆî7Œ~’¶d¬r9OÞ¤”f«­w‹÷dš†¶–TÏ”šl]BDjH¾ìJ
R•©Áà®vøILêIk‚T©mh×½Î.£A·K €ÛÅJ„$ŸÏ÷/ÿúƒ_þòW}ôašÍŠ·?³o³¶ð©„g6“LîuÈû;êki©†5dÒ;âï"#h¼BoÒ+¹øÅk%R¥±íŠŠ“°² Íë™ÓI‘sþY¹‹Ue3ñEªPª–uI[ØÓa1[œÂdf©Ê`mjÔ¥H?ñw‹#cqºFëü‰’ªëŒî½ÎÓlëÒL]§e¢ LË{`  XQË’.÷ôœm9—É–¾7ßœxÿ}ÓW¿Ê¬%aO—Ã“â>:]¦Öì“¡,“µaþ
B˜T[â—³5Ò‹Lß!eUôìª°þèmkb×\GM•ÙQšº;¦Š2B«J/c×È:ª°·ËÞlw$Öò‘
RS“Q“ò¹…Ý¶zŒ%ÓÖ5d>ð'Õ˜ô®š® »¹Ù¥iÒaÁ= ÀÚ÷ÑG‚ôc‘B±aÃf}ZÞ4>>þÝV{æÛ_ì¾˜sÇk£ž$UšÚ:uáH0ÂÈdSÃ]|bÖ´D¦ˆ8-5µ]í†ÝÒR7o‰Äžúú²¹ºÎJ×Æ§]·ñ»º<¾tº¤ùÊäàt¶B!“®rPàƒ:<“u/ZšLiZ‚“AÉš‡Ÿ§Ã¢·   IDAToW“UŸUÒ‘iLuW½;ìl¶ëÕVŒg ¬mÁ`ðø7,ï¼óùú¾{ïµ?q÷Ýw3ëÐò¶ ¸ÁÝ˜˜˜Èê!çÿÇ…W^}•YÈ0•šå]Íµ556·D©V«dá.Km­¥#(S©dw‡SHHmíÚÎùË*$2M£Õ8ßê}>èºRÊ4†W‘ Ð8@Ê®âÔà¬ÝµãÏªÍ“	I¦64]ùA»)ýES$RúòéèYSCÖ)‡^F¯”±:ò~`V4 ÀšFÎûæÆ¯Çñö;ï|ýø±?üY‡–·’ôÛßþŽÉÞ«¯‘?™oÿ¹Ï}®â‹±Ç``–ïw:è:,V¥ÎÍ
•F)ërºÌfÛf24µHÜ­!Ó+ªIÕ&k›N¡™oVí3dv’Q)¥Þ4#}ñaŽ‹¯ŸZBRÐiÙë
z½I=‹è$œ:“^“Ip‘*õMÝ*CPª\Ðäk©º¡ûG’Õ. 1<Ú  ¤÷Ö[oýò—¿L¾…¦_üâ>ø ³Þ,÷œ¤˜åG~í¯¾î¯YÒùLA·Ýá!õ­ÉŸD×Ô7ù¹½vºBKÓnÔd™Ëdªyk(<×e¦ó–èõsg­`ãÃ~G«**v%Ç›"á°Ði)â÷LÍÍR¨õôú($2f•Z¤¬J™ü=>ä¥ñ$ #i,YÍ„Ä# ,Æ~À¬CËÝq{åæjýÏ‹ÿûß3K…vÙ,¤Œ$ÕÔ5$y‘rP£AÉD<öæÔ} …¶D’l/ñ;-ÕöÅzrP‰ÊÔÖ2kTŽç„iJ¤¬µ²×
“*µšDñG¦Ôš:àûQg“I«\ììqÞß\]r_Üƒ»,ám“®vµH\$ž’øÒ @z¤b´I.O¾åž{îyàO`Ö¡ÛªOÒ7Tª¥X„M»þÔ˜…ˆ4§›Te4j]¶°ŒI:]F¼³MHM|„óíe¢Q†îÙl÷Fh]ˆNÝ–
¡€Üj©©íˆ_ÆC¡µ¶4%jW¥ªÚÁIJ…$L÷+QéôÊ–#!Ï§½[ëåÙŒ¯«›)©‚ä,.9u°ºŒG/W–D¦T©"æŒ Ì#''ç–o·œx÷ÝwÉ·÷ß£ùù;îX—yãvn›YªJ’„öbe¼Ò*ÒÍG¢Ð·\ÑÍ¼:«”e¥t¾Îä&*Cøôl‰ŒUðþxlÚTÈ¿¬B¥S³$”‘²UƒA3E¤4™8¸`0ž¨”úF«a*Ê”šehk-ao"AŽŒ´ñÂ[£\+½f“iÛW¯±7 À:sß½÷:þñÅ@ °ñ÷¬ã â³-â/`êe$¿žT_Ïýv.÷Ýß<y’Y)Ï5kþüÏ™%BÞ™,æÁÐž\˜'%›~^5fâÌÞ&ýåî  `…á²$)e7S˜ÔAT26³-EË%)·  ¬!I   °ÄÐq   `6tÜ   ˜ívê¸   K&UÇmfÂp   ,/tÜ  €»Û©ã6B   ,™xÇmŒâßÞÿýß<aAÇm   €Û§ã6B   ,±7Þ{ï½Ì:‡á6    ¨$  ÀCÇm   €ÙÐq   `6tÜ€ÛK,:e  ïvê¸ðqFÃQ,Ê]|îË{ž?9	 –:n‹(((dVÐg>óæc*vã¼i—nß‹C+p–[ÉcÁ	õYŸ59wyp4ùÖØÍnóž¿5_ÈµÆÊÍÜ«g{¸ °(·SÇíå¸½•eïºë®÷Þ{Y~ùùù[·ne–PìÖ»çbOïÕá±	ú}«yÒôÕªí"Á/z£÷ÕW{††‚c¹rv[‰¶jÏîÒM¹³ö7Ê]íí¾|¥Ïö—§(«ÜoÚ³cöv‰]¯]øþõ¡áÐ8“G÷YZQU]Îæ‹?ÕïZ`B^¹³hÎý£Cßïí0¬¶²|³øƒ/v°×}5”»£²r‡<—IcÎ±²z]ÙëãêVÏac‹)©mýæîÍÂ;ãÎ¨spLIý‹§¿$Ïv±›ýÝWGF&Æ‡ß³;éöÜÍÆÝ½‡_y¡·ô›UO•õ¿ôêÕòãf½ €iñŽÛÇ-'Þ}÷]Fè¸Ýh~·E„t¼ñØs_k`–Ù‰åøqòÿÌ‰\§Ÿ?ÓJ¾mŒs·Ös“ß>°cFNŠ:¹0BB\QÄ„¸ë½öëC‡N®(šŒ ±[ý­ÇOõpIö_xži:T>+OÌ>ú8Ý'ùÓwýØ¹CesCZ,4x•›W”Me¤ØèÐë½½ý×iÈ"Á¥”­(ßÌÌ}ä­7Ü=}}¾Aß°p
ÆK*Ò—™ÇÊæue¬å6Úr¿Å•+6çÍû4bÑ@0”»­êÐ‘gÊ6-÷“Î-( ‡ÈÉÍ:Rn^žðÿ¹9LÖ¢Üåžá	¦ ìé=;fýöä?¾§ÌuêüùGõØÉ`~1 ,:ngJõÐCß9×âì¹ü«_Ýúè£¥ŸÜ¾aÃÆ{>÷¹¿þë¿Úrÿf©D‡^<E3JŽâ‘ÇŸ¬,xûÖüèÛqú\o Øsú…’Ö#åSçšè€ã4MHòòCV!:N»àëkm-ÝvìKBLŠq=V!IÈ~¬ºJ[º-ŒqW/œ;{i8ä>w¶„=QY4}Þí?{BHHyÛ*k<C«GÑ}Ž³-}LAžèóõ]#¹¥¼lºÎ4zýµ³ß˜˜ï…Æ×^9waxÞíR+«×µ€c­Œ‰PËtÓáË—®í.­Ú¼2Ñ.9#å.øÑÁç.IäÊc|ÝßžuïÄØH °¤bwIaná¦+:< ·³Û£ãöJ”¿xàCüfÉgw?QÁ¹ÙgU'rGaqÅã±±-cý=ý²Ýñ³ìÖÕnšgŠ;t0Q7*Ü¾çÀ3ÜÁ³×Î÷íÜ¿ì ·HS]9p9÷±ÃÆ©ÓûèþcÌØ³'Ýc=ý7+žÜš8	’SÚK.rJST·îß™(´äo-?ÐRfŒåæ‹œ*ÉèUÿÐ¸¼lç–éá´MÖ+Âî|çö?×JñBsÙªgýj´ÿØßžègæ1óXÙ¼®ìµü
Kž:ñíÊŒfáÄÆ®:šºG&6mÛ–m)ê;{æüÍ‚ÊÃ‡v¯PºJ:oÊœÌD°ÿJ0ÅVã¯qdD$ €ÐLRTîæòú3åsn,­(ÎÝ‹1ñQÈçò‘³ýmEñtBÉ-*«(}ñº;0Ðˆn§wäîÜoÝ9û(…;*JÜ½c¡¡@”Ù?Cú.»É™¬@kÜ³sÖP”hB"Æ†û‡ÇJËØ8ÇÍ:Væ¯kmÊ/*.)ÊhËèÐÕP-.Éz€0—În.ÏbNtŒn»ˆêÑ¤Ñ7^<sÔÉŠ*jMš¢9û#£¥Ž³—F&ä¥nCB ˜!)9y…$ñŒÇ&&OwÑ/@Nòââ™§Î|¶„ÍqûBÃÜX¬8?õ©.¯€ìoŒ™`&¡F‡ûÇÈKËK2=iõŽçmd%Îr™kÎëZïb¡á!a ´tKöosbjQ‹¿_¹9‹JIQîâ·Nõ™œmÆ£_Ý½UdÚþèh7Vôˆ†Íg  `ôIÊBlbì]A]P˜ç‹BäÔ—#ß2»èSPTTHç{ÐšSšŽÑ‡3ùòÂÄ\£Øèp€d¤EIQ¦'­Ñ¡kÃãy%åÅ+’‘2;Öœ×µ¢7zíGê:uþÐr¯[Ýîç&HF*+Nùs‰Þº9*úL„`ŒIÊÖÜÐÐ­4¡)6NËN‹ÊHQîò™vRæTT˜Tm}Ú‰:¨¢	 @*I™‹ÝpÑº»}2Ní fdäæo¢ù :6šæü=:äö‘H”·e*EÇÆÈL¡\ž?:ôýç/_ó	«ÓrŠ®Úwà©s‡zF¹>ß=y0Ë.ÓcÍ}]K.:ä8|(>ÕfxØçãŽ6]Æ…ë±@ùÉç—•¤œ4:ôªåù¾±Ô;»p@aú[yÅÉïÔïHÑÑ!¥c{$[-,%ÅBýß=q²gd"GQi¦3Û¢7|¡ümÅ3Ÿ|l¨ïüÀ8©3iµÈH  b’2FÎ”½ƒ4#=¬™¬¤Äb1a<Iä#|„%6=2'²¿Ëä¤Z°C3=´
“Q&úOÿý¥þPN¢ˆUŒÝŽ®_8þÜØño×ïœ¢#×†ÆrØ²âÂåŸœá±D_×‹ùF¦‡òÆ|ýÜè£òå:Ú¨¯ç29ZÞ#»K7¥Ü(·p»¦2™ó›ô^£c²9[Ê*’&4§žÜ§Y9§ `Ù%v³¯õäºä0¯ä‰_Û·½0:tþÔ¡ïù&ä¥ûjkŸjß5:pñÒý1=¦ÙŠfU  b’2»Ùëè&gžÒ§ª¶OŸ»ÒóÐ†6ñ”$º$íêk¯	Ÿã«žœÓù(Ð{) ×üÎÝÂGüXèOœèé=ÛZÊIn>åúB9ÅÕ%+Ðn(³c¥}]K(—öš˜þ.wÙÞ€×wÁE0jªv¤)Vå³»÷˜skôF÷‰Ëñ§YXü¸Ñ´=£Ø¥Ã•¹ùy™½ªXŒI¼~ÉÎ$¤¶¢þhm¹°˜.W^\¶Cîë¼ôü3®ŠúãôöÑ«ßk%u¯¼’'÷•mb  @æ$e$puS^©qÿ£EÉÍk„Ö~bõ"¡ÈD7=ÏÝêwØÝ!%¾üÕÊäÏñ‰“½¢âø?Ý=9’+ßñåý•E¤b2Ð3˜<É)¸ê‘ÚÎŠd¤ŒŽ•êu-±\yé®²©Ä’WR¹{Û2ÑpüŠo‚)Ð<õäö,AŠ:çŽ¿àËzòzl,DÇXóå•G¯ž9x¨õøåFråe‡¿óbÓ·íM‡Ê'Ûä–TøÇö“O—0ÞSûþÖÜ|æÔéÞyãž©­\ù®  ë*Iˆ½r¦ÕG"ÒÃFSEQò9%GhŒ’%‹Eã“‘òÅj1îòé÷‰{öïž187¾}Qiå¬‹Ÿä+J‹.ÆBC¡Ø—&cJ4Ð?ÌÙütÉ¦•ÈHó+õëZr¹›Ê¶²}½¾è–2í²6F}¯žl%á¸ ÌhÜ™Ýœ'’®šŽžqÓ$òÄS…îÖ¾ø²þLŒqƒt%]+Ÿ÷=ŒÝè&qgd\1ˆî(Œož_´½dÎ†¹›v<im-é>û­—HI©§—Ü”³í‰ýÚ"D$ €”PIšO,ðý3'ÎÓ‹:<rð€væÇîÜB¹<¶lŒÍZª4¢«ÔùÜó\,ÔöT\%ûWÍŠùòâ"R›ÍY¯EÇ^è£Ç§Oµ±Àµ@NQi^±¬   IDATÉ
œèæ?VÚ×µ,ò‹vì6î«._®„4ê{éÈ‰´ŸxÅƒYÑèÐyóš
ÊjOŸxb‹ð£K?8;%vs wŒW•²é'ÈÇFßp>ð‚o¼ ôà‘}™”¹
‹«­ŠM\Ød"pÍõF ´ H	!)-’N™›®‘âÈ–'Ô?:g¤‰|h§¡Fè‡”|;íŸDL
ŠØ‚™ŠH’0ŸjW>$ru‹Â-%›IÄ
…f¥®XT¸ÊnnÁÔä›X`p ÀÈKv®HFJ¬y_×º3:ðÒQó…aRDzäÐÉÚlfW]<ñìïùhý©Öj®$ƒ¦ñQÙÌ*IQÎÕCFèrXMiº·1JrôÂðÉ¤Gv³½ã$»{žÆ>¦@!ÏaÆ‡/ö`s‚ €8„¤Ô¢CÏlºFL¶í³ZŸ)ù¨ž[DrùL>tux4ù‘~:`RPRžÜ;Ê]¶>G“DÎ–Ê“Ö¢Ã7¹E¥åÛr˜àµïÌHIÑà mfX°m»"ñ4b!ßÕÀDQiYÑòmÒ+“×µŽÄF‡¾ßzx¿&
®=yH›qæ£“öï­³“ßF¡ýÚi’²Œ‹Ñ¡žÖ‹#¤Y^­I~côÿ»:ûõ±…¦þÛÇª3*ÛÑçVÿœƒöE’Wm¶;¾{¨¬€–“¸P!	 @æ$¥å.ZÌ/ùè2­}ß´¦<Ñ+l{å…áîîÁGk—bþWzIQE^V1½>p~¾¥Œ^”íÄ7¤¼’h.«}¢ì¢ÅÝsÎ±cêÚm£ƒŽž ]`55W)vË×ÏMÈµe+Ðâ&Ý±2}]ë‰G¯¿æxéŠOèvTPòÄ¡Ã{vd8Ì^n=÷âµ ]ì¯Ð<{èÀìYY’¢¾WÏ¾FFvsJžxRü$o÷™ƒg¯Ð2åÃûOÉh\36:x¹åŒÝ’¥57¸ú'J+Wbl `=BHCç!™i·b†^øý¥¿ÿ/ÍÞbË¾ïœ­¦e‚ÜÍš=Úžç{F.ù»‘GÞ’Ç„|îkåýÄãSÅ'2õü™þx§Á`ÏÑ¿é™½?yeÓwÄ3VaÙþƒ»¸ãWzŽ?;TVV"Ïã®	ÍåûžØ>]GrMÈËw*òÅž¾ëlK7'”¢bãzn°?g:/,/ª<p8^á AðÌ9W|úSlì&}¹c¯·ä„Éãyì“‡k…viŽ•ùëÊðX«"vëž{ûF½ J3Õ>õh65ºXhàõ’äeO×šªfG«Äúü´{õ½tìÄ%Z¾zäÀÁŠÙe¤X<cM®3ô²5O×¬Ê À‘|ÔÓzÖá¦šÈÞcÇNwLMáÊ•ïØ]Å  @*Ib¢á±L7.Üñì±c¹­­Ý×}®+>á¦<¶ÂxðééK¾GCƒtø-ã=îÜú¤üÌÙ×ú{Gè9ò’]{LFíÔÅ%bcCW¹‰”µ†|#™· ˆM¶nåF†¹™Ol"ä_&&§9V6¯+£c­ŽÜüüX`Hx{r|yÿ¾ÇK³žç•_²ïDsi(ú*~Q®Ïå‹É‹
r£¾!ºï´W²‹v¶v“Âå–ÇÌµb|¹äY’ŸbNQÅþ£Oïž÷²Á´*váµó½!ê“ÜWU{à™2,f ÈÂžçé6l˜úÿä/Ò|=÷Û¹hhˆ£çúÜ»y‰\EG·Æó6ÍÞÝ­^óþ3ÜŽÆïšË–}|k%µz¢7úÆä%ÛÙ%[*w«ïøþo]Ÿú~Ëç¾½/ÝÈVìÖÐ@ ¯dÇæÛÄBCƒ£òí™,æ‹.Ÿ:|öÚtìÛ]Z„15 €l!$­G£¯ŸxæäpÉÑæã.û,é•<Öí…ÔóFF£ÑP`80ZÀîÔ¬Èµc¦Ä¯¿ÖÍm©¬.Ç…Ù  ÃmëÐèpÿðxÞ–²âH-+y¬ÛL~QqIùïŽ2fä=j<ð(  ‡´þÜ28&\‘žYv+y,  €5ÃmëOl”
2ò-ì¦Xý¿‚Ç  XS’    D ã6   €„$    I    "’    D $   ˆ@H       !	   @B   €„$    I    "’    D $   ˆ@H       !	   @B   €„$    I    "’    D $   ˆ@H   q“¥±È{S_oØ°   X
¤weµ=*I    "6ð<ÏLÖ„¦*CÉ%¢T_3¨$  Àí•$    I    "’    D $   ˆ@H       !	   @B   €„$    I    "’    D $   ˆ@H       !	   @B   €„$    I    "’    D $   ˆ@H       !	   @Ä'¾þõ¯“ÿlØ°aêÿ“¿HóõÜoaŠø].oäYa¡äæˆp^ï;cLžL*aÖ°çîøÇ<¨¼/ïvû1òA÷ùŽóÿÏÀÏ?¸[yŸt¿ºø½úó;î~P±–Ÿ' ,ü=¿íñA—­¾ÞQèÛ»›42’ð\WýÞzg~-Qê›Ú¬:Åª$%>a¤¢!z=~F©Q†Ýv›Ý#+º›´þ#~·'¢P©XÙZŽ|Ði©7»ÂäË7ÓÙfTJ™5ŠüerØ:8–WéÔŠ5©gàÃœŸóŒT¡T*–úÍý]7¼é¾ÿsÛ'€ÛBÒí.âwvx"£ÔêUHH3ñþ³ÍË6tvUG}ÅÜ¥l3°+~ö#O¤¦ÚTêŒuõŒDÃs.[­Å¯²v·éÝÕf§½Ë 6©29Õ…=öúg˜‘éZº›—þHš4›;8^Â¬VýR¾C<ç´ØhB’Ê¤‘°ÇVß¬loTË2y˜Åá0‹'Ó64™ÔÉo()u9=Á¹;'‰•ê §£Ùæžû&ÈÔƒfí…'>ì¶ì%Ÿ“ÖØÙÝ¨^Ú”ôÞðËo»¶|ák…9Àí!)>èq:»œ].7'ü{)Q¨õ¦:“>Í‡H2´Õápt¹½\˜Q(5ZƒÁ¨ÿgs;þõv4;\^?Ý¿DÆ*•J•Î`Ò©S•
Èö/ùIþõV-Õ?Ž|„ó¸\.§Óåö‡ãO]¥Ñhu:­FÍf|šÞœä$'U¨uúU9³ðœÛé—iÛÂ[®ÒµõNOXÏ®ô“!oE—'Â3/Ç3³Ž-QM®*›½Ù¥iÒÕ:ªìöf§6ƒ(ñ:ìBy†Õ.ÅûË‡½^//at<³„È“Œ‘”¦¶»~¯Ýë¨©g;ÛÊyž1ô{ÈbO&ÏÞM$èq{‚swÎG„é±=AÉŒÛI©†‘…Õ:âcõYäÖèÿ9:2|ëf]!{'pÛAHJ…~R­­ïð'ÿCIrM‡¹º«ÃÐÒÖ¨{Ú¡ÿà×Z\ÁÉïƒ~—ÃìêèÐ7µXu¬dq;>6×ÖÚ=‘é8¯›üq:m-F‘Äûá<ñ˜¿ø ™É©>\iÑÉæî±«¾&1>•ôÔ½®òÇFÎèMm™Ô,„×âàè—Š°R»è3öû¹Hv§Lž#à§Ã3Â7Éûˆp^˜Ïr‚œ/ýÁˆD¦T²Mr"µ"{‡Ÿ|!ÕšŒ"%2hÔ:j\§_c466F\’Þ`ò+ÖÜA3„TCöºè|,‘H%ä ¼D*]ºB-OÕÚÈ“”i¬¤–ÃJUÖFÿ^³ÛmÞ[Ë´·¤ÍIVoíT¥û‰G¼öz›;"Ó6ZÓà‘Õ¬wü·tÄöé1WU“á6rìÞo¯Úeó3;¿ó¼Ë…æ·¡ááÙ‡0â·„$Qäß×š½¶x!Ž–HÈÉÌa#CWdl¤¶VÚÞÞ0³BÏ‘ô`‰BÛÐ` c[A—Ýæð„ý]dsÙôÂv.L†°ì­éˆ§
µÑdÔ’šM„<¨™!ìqºüzÕìS!=OÚ½KøÁ?B‡¤Ì“)œ[T*%+#«ýBeK6ªÝË3m³2áÜyÈ;Ã1K‡¼TsµeA¯•3›Ùƒ’ñ:,Îˆª1¹ö4×Ðáª…éÚ®´d2wˆŒ‰5»è/ƒB£‘†½ž¹‡ãÃA)Ë²2âÈ€ŽÑªž§$„ÚínºW™Z-¶×HÐëq{ÃJ}ƒQ›Q•IÂH$“_0KüF›…¸-Q™ZšBŒ¡!»‰«©qxÉ}µL[ºœDýÒ¾½a¾‹ì3B"®ZYJäÉ_¤®Da7Õ“n#¤·G¼äv=–L©ÓëÖîÜª¥òGîÒ}»øÈû¢Ú‡>7¸í $‰““h¥jcSSÃÔID­Ñ¨UÂ<_ÞÛápTúéÓùç11¶ahï´&æG«Õ*emu½+ìïhvêÛ‰ñ³Þ¹Px±	‰Õ·$EµZ«Ó;;<RaÎi€ä›œ}¥êÆÎ©COßÙ±w—ÙÃ¨­WÚ3ƒvÛj	I¦ih!ý“ÎQd°¹^(s‘°XÏ*ÚÓÌš!ÉBK'2VæA–©êÕ8áEµ5Íšn#Yx Èì‘4.vÅSgÐiž‡8©R/“f¸üN˜@”ØkØe«q¥Ü’Œ˜zéTé`Œgþ.Nø…n©KúM’iÛ;eµ{mn—yo/^!].dpÚfÏ D+´b›‘:vá+©†Õj•k{¹äâÝûq_âƒÉ¯.…~µÿÓ›—qÄíƒ>ø_ÎzóÍŸ‘¯·—”ü•N·qãÆïX0„$QRRnïVú¥jæ§l	«k¨sºëÝ‘ˆ×íè§‰ÂÞ.Í0dÄÄ”´‚LÂjëŒ*7Qð8ÝœA)œ‹²Þ9ã²Ó.ò™»©qV‘FªÔ™”s_)úXh]Kª®k4,Å)Œ%Úâ…,)9Í9­J$b´1{÷’ÒïµÛœÚTá‹ÒÈ‰ˆ'gG“6h³‡—¤Ò%Q»ß2fõžsìÝeñðŒªáJ§)Ý[$Ó¶\iÓe:tîÝUëÎlB1ÉfGfƒ4Žáe¶¥§9±VLBÆ	SLVã#œ_(™Ð¡Ä•NHœ0dK~™º¦6ëœJ–””–:%õ{-.7IRá¶–ñyÜawý®½]áÄÏoÎdk2VJß„9U™JoÐ’ßÍˆÇRUíà”¦îÎ†x“]½Ýî©O·Íž n“éÛ¯|ŒVþîßÞ~'ñõûž[o¾Ín^®5n$øûºŸÿüçñotõG?üßÿûÌéÓñ64éïX„¤T$
•Ùï¥a   IDATJìf™RÍJÜ^>’ó{âáÜB^¦Ö©3¶—²­²Ùëåýd£2‘²Ú9ýÜêŒÏ.Épö5­$ÔXÈ‰šG¬K‘H´ér	gs‰º®!Å.¥jSƒÑ]M>ŠG<dk½I%ÙO‡Åá¥£Q$=ÍK:	8;äÊÑÃ§ËŒŒMÖ×
¹SiìL¹ ‹÷;ª«È@¢D¥Ëdöµ°œ>>Æª4¶w¦ZÊÄ»jwÕ“:#«Ö®ì 	Ûµäw3,t\˜š«GžOÍ.òñ€5v_i$¿6R¥±¥]RKÆv=öêê ÙP?ßÓ¤kÄÆpçT}zµNËÊÒïËïŠåÜˆ?LoŽÐ‰ÛLrHâ°Î.>ŽT•‰Îãñ7)5ZÍŒ5d[Çë÷V£Kß~€NUôzý&C¤ÔLv$‘,Çoøo^úõô«æ÷S2â¶m™FÜœ=—§2PÜ›?{ó_þõ_ü‹yïX„¤¬I…žƒ<Ÿ4g”¶!¡IÂªgOò‚!çcÎÃE¬,ë“€É¿œô>µVÁ‡TCêãÃb2]ÃR­ÖŽx]žø|(Q—:uIU£¦ƒ.6¦ÛU³·$%1[3]l§©«Ó²¼cU3’GXšGsé*|ô'ƒ¡ä§DN¤|a­KùƒVàÑ€œQca­˜0ÎFj4-)gâðaK˜GNÆlV‰çÃôwsa¿TÓëÚÆ–¦ôitòt»Ô\SÛEÞª]~w£µÑ ž7ÎJ5M‰‘^¼,õ]khš\J@Š˜f››™ÿyúµÕéæÌ…]–4c˜¢y¶ÚB†¼¯´ëW³Ùæð$-ˆ`I•Mýp]–ZK×T
³ÛÌ©:zÍYÛA6¶(u&õ2¤¤ßNµ	>¼Ùùë_åÓw/ËˆÛÏ~ö³¹7zêÇ ô÷,BR¶H >LJe²©âÉOae°ŒéÚ'UÐ¥Q)qIÕ¡ÌwÎ„ý^úïµ„UeVò
‘$¬±©QË¸ÌÕ¿èRfáVOsMU—T¬áKƒ5iƒ$
o<#itigËÒ>j©›”(fTÎ%C€Í‘é¿þKÒäfHíOH}R•våÛGEü]ñ9Ë$!ÛÚÒ„RÀëŠˆj½f¾¸öØjk„$)é55¦‰Ç¤èâtÇ3R¶“‹ye×ƒ•N«£T™Ô‘×l!ŽþÖ)éÎL–@’è¦n…Šf~¯ª]ºº†:CÚX'U¨&§ió~¯”úd¨Õñ´á=_‰ªño7ŠÝ“j¸mø ·Ëâìèð
ñJï&ê‘hX¯PXUn3©F„ÂJÂ~Zà"Ÿ5,ªeæ{H’moMüo»„Õè5äGË9·‹$SçÂžÛÄÍKÿï=˜½ïÖèOßšqËûnîÿ®‹Þý'¢»Ê¹û/ïû³ò»^g8ûˆü/£{!)KäŸ´x\ E£É¢"!Ú02…XŠ‘‰'ÂChi?ƒ‹îœŽ½	Ë”â	Œ{ãŠÈ2•Þhœ]a²ú¦n•1,S²RÞ/4“IsHR›:-•Í¸5äâM©ÉÈÀ<§™‚%ŽþDpL:Q“1@[39…OF$fËHS£‡$#­tçä°Ç^[k–›)mmYêvÛAwºC²©^£H³O:I¿ÞìˆŸgÕími›M’ßá¦¿Šj½~AcmA¯ÓAþXÈ¨˜Ö`2IÎLûkM2a}¢Âê¬MÖlZv‘âdÛµ³ÙlqxÈ«¬q¹ºÓO![´xãñ»R·%‘H³œætÚ;È§S[£Q#üµ'¿!5{ig¯½zCWË6¶5êÕ´](Lx»;ÜAmÒ´?aðVHHdWMMuÓc³¤~FçÁ/äIÎæòÏr—^iÿCfÛÿág/¼-RÓaîx°áZõ"³½äóîÎ.>´}{&÷,BRv„¶Â„µzê(Ú`‡þ—ö’yÌTk™¤ž<™ï|²Ù$ì¶›Í¶éFLŒ×ãr4«MMu3g¿JÙ'?	ùg³nÆ|RRê·‘qršnjûL/‘%ï€ä´øIC&SÌW·'xÉ¿æä„HC‹“» '¸fwRDZUawbž½Z§^É:
5×šã]¦è².!!Ñ+£˜ÝRm]cãÌ6Io¼Ë•De0¥žœT¢Ö†µÍ3ŒE›Mm™Z£.ëŸ„D©Ó+Ãng¼ÓbÄï²×ºì¤~¨5LzRôYJ,‰Ö…ÆÐØX—åðž@Êê;5º³ÙÁ¬i—âÑÖ‰øBª(‘øP¸ÇŸó
¾ó½d2dUS›¾ñCÚá¶¤™à"c£íIë*ejCƒ¾«Z˜®&”§~ú…–T»j]Þïò†¬bêI;Bb-m3ÇY¥*ccƒ{—Ù³O%RøèË²9hÿÊ»·ö©æÞÏìzù-¦†DUîªtõö¿5]»Ú^R¢­Ðfr/Àb¬BH¾õþèø‡nü)ÉÆÏ+ÖL’£sC»èùE¢Ò'ÏÌáãñG">?R"‰ÿÇ§ïu8ÏÎéJ¨½X¦Ò´ú‘3ìé°Û]\T&êeÝíiÎRm3#$qœ4¾ÒN•ÈR?¹ÄK‘Î?·ar>þÄã_íÂk$"ñ~gG¼¡•n/"1½¦‹Iîä@‚o³“ž»Éx’Ûel²6hÙÉ÷,ÞåJ¦M7Œ™ú¡ó|¬Æù…^tôF.y¥$‘iMM:«5ìu;»:ºœnZ¢ÍãmäM­7†9SÁ¥*Sûñbïwì­²x¤Y­ŒïÄÐÒ­çç™ƒqÛjf–¸Žú½É7¤+ÉQ2]Ëº–»_úá6’ªt³ÓªT¡f¥ýLa0Îú)ÉXòÖEwÐáûÄ¯/ù¸…Y‘j£X™.þ/ÓBK·9›«K-êMÿwµ·ïÇ™þÃx]lj}`Û&fÑ6nÜøgþë?9ÿÉ÷æ›6lT=ôÐîÊÊ©ÅkéïXŒÍ¯ÇþþüïÂ¿Ïî/ÚÉÇ¥{Ë>É¬>z¥X›ð	“Õ×‰¶&+J³oæ•îtÿ¼Ï»s2à''›Î¶é©BµFe©®éàxÝîÖµh—¯(2¹ÎŽÏàÚÉ@5õ‘¼¶æ5‘‚.»#^°Ó­äåì$¬Æ W{l~…Ájm˜š$ÓZ;ÛYs½Í&§`GÍÝ:k‹ÕÀF\Í´òŸãžî¼.t”PHU0ƒkÂA™æx¿-CÝ—=ÆËš2•ÖHþXI>r‘´Ô!”–‚ž.›§Ë¡±vÎ¹jŠ$³FÝa¯ÃfÉ­‰eûó¯Ò’ÀÄì-20M*³a™Z¯Kòtø2]Ç…x³ôtÌ?Ü–xÓkÖBš¢Ûsbq“ô!„Îz>,ÛuwsîSìíûôç{_´e6ööI•½ô™ý…w1KåŽ;îx¼êqòg÷,ØÊ…¤ÀèÏ¼<öÇ÷™l½¹cãGõÒým[xŠ¡ŸÖXCãÌ±«ÉK60)"?9—òŸø4;Ÿz˜Dejk›Ù1F"Ó˜Lg½;¦•´šeZÌMã/0<ï•?ø‘ô ©ø·6"L‰¿ÓR­Ñ°²«ßejSçüìAY2ejÿfrÐŒsš«8·Vêu
#m¦†ÆÄ¤¬:“6ÜO³™Îœ'¿e¦¥)„‘6I­36xví/OŸÌÂÓa›B§Ð«æ[¶'Së&'nÛ½]ž«56$ª¬wÄ•.$Eæ[Õ6iþÕmRMÓ•6ý²–¹-Ïyº(„~!S(–±åŸúÂ·vZþÒÿbõÈ[¡tÞ±ýž¿yyû£¡ÿ6ÜV.$uþäY%¤?ùäþæ1ºBá¹®ßðÑ†/?²zõ¤ˆ¿ÃlNôs´6Ì¦Ÿ“#|bþö,“7§ü0~çLâ¤*cUsÏô½RâöðaŽN_®!¥2Ebî9ù ­“¥òösñé1ñë…=öx(áÉ¸GuWò–¤6FÿKç¢V‘Óê¬uË‚F$a‰¼T]W§S¬|d“¤øI•ú¦nµÎa6ÛÜAÞï–#IT´è9zå–x%Ä—¸êH~õ:£§«Ã+Õ/¼G¶T¥½Â³LÅ®@ž¥Ý™:µK²æ’T’V²ûVÃá‹ó‰Ï–Þô2ßøÿ{7åÛ$)øOªG—²«äo~ó›AŸïwÞáF¸?ü²>ùÉOne·Þ{ï½ÛKJ
ã›}øá‡ß{ùeÿ_ô^€…Y¹4þ «íÿé'Î<)}¢u,ž“ŽüÏÈG1«3îÆ‡]¶ZK|ìƒö´™óÏ5]¸Ë$Ò84ëôÃÇg0I²óÄLèp0™ÛB€ßigîàäôî™WzÏ]e"´læÄm$>óÖëñ„SýÃJ/%œœ¤t¹]G0Ìáˆ0¯J“Åø 7¾\.Ñ£‘ŸZ>'4è}L$~T¶4Ý·S¢Ë|âÝéEOÊ•Hé	%¥ne}õÞ®x5ƒþ,ƒÕÄ$áBiµ4¬Ê4Ö–Æej
MÊJ††LJZ)‘BPCƒjÕ~0ô:oñ¡MÚ¿»ÃÌöñd°O·‚—Q™4UÊŽðËûWˆšøUßhº ÉÿþMÿè¶»–¢Žt3x¡í…Ÿ¼ñÆÜ»~ü“ŸÄ¿({¤lMÍÝwß½qãÆ§öìù¿ÔúÞ|sî½À‚¬\Húð£¬»V|asN—©àÉÖ±(O»:ãnô*S5µ‰ë¦YÅO­tE—Œñ†Ãœ'ÑÎ*´D8o¢4·žÉÎIIF¥`\~ž.LfØ9ÓS&'K§öX={!ï±×VÛEvÍøgÏjyä©‹´Ò‚•ÔåŽD<NOPÏ¦ ŠøÝBspò °ÔIÂê¬JÑA:RZ
h“ýÿè‡ofÙÐÞDµñË
+MÖ50ð'‚.:´tM÷æ]^·µ­iqõ$ÚA©¶+>©©ií¥Ã%tÖWû3Hø&ÿ!¿ûUNib–íd1ßÄmA„sÚí]Ù†$‰Z¡ÕªVüŠm’é.#b¤–Öo÷ÓN?+é}îÒ¯÷•ÍŸeé¿wv¾üJ;©¥ß¬ÿZÿOÞøÉ³û÷ëvërssMûMÏ}­Aô^VÖG}Ò¿DE
ÅúG¿r!)ÛˆoöPQÎÅgÿn"'‘q7òÿ+—“h6bèòj…¾¥Ýšj€F¦Ô¨¤NWÄïqqÕŒ’a¯K˜S9÷ú™îœNûP2¤„ãqùÃ:ÅÌ ANÂt©"Þ£IB/m¢âç2à''c•©§1È¦®ÐI×okî® ïq8<Z«x)‚.Ð‹_‚Õêg©/ÖÎKÝÂ"•Ñ%vË9Ì–´ò^XRÝ°&/¯EF]ëk„VétúY‹žw˜ëž4K»›83ÄËM   IDAT8H;ÔÖÓ„$QÒÕá·û•Å"s
–)ZedÞëúM
víÝUï^­Ö_´a­„”“yÎãŸ¿­ÿ¢¼7ü«7çÙæ}ï¿½ùÏîc¡ûÒ%ÇËßËpã÷ßÿÜûoŸú”´\£yhûöüüüh4*z/+%ÿ†…’’¯ï»÷^Ëñë´ž·ú$ýîY¥œ”ÔÁV¦ilIÛ#˜^´Mær…ýÎ.¯Q5}"â9w‡Ð×ˆÕÛ8›K•ªÙï»N¿fÆRÿp¢)¢lªq´BÛØ>RÅÙ»ËìaÔum©.D;ûÒ9â.³;Bê?6u§H¤ú ôdZ“I½6NÆ´+ÍfsÅ«3´×3‰	Y¦°¿«ÙâÏ°>ÀGâŽÍNÄï´Õì Í ÛÚ„iÕíWT$ yØ†:íB©†=…nó_d“ªSµÉ^„çl²”M³¤{Ýä£’L­Õ*—å¯mT¥”x¼dìÞÑåÕÌj%JÏ-ÉL+a¬íßo%ßðiiy«òó·Fþï¿ÿ÷ßLÝø»Ñ{Þû?ï[ÌˆÛõŸ^g²äþáI"‹ÏÝ}÷ÏñÑ{XæÆ¯ÿò—¿Œûö;ï|ýø±6{+eÖ›õÑLrr’pXá8£ÐY›ŒJž.ú)©·.©´õJ—Ýì0[”-ñžÂ¤°BNRñéF¦äö%Yîœ^´Îà$‘ÊCç$Sƒ/d73«“¨ušŒFAÂê\Õfw8ØU[Z­Æ¤®¾œ«Y¨{0tHÇj]žu=Y!O©£¹Ùîœ¼ø‰LÓÐÒ´°ìtw82¸ä×ÂÐÕõæx”!ƒ®MmÓC…®éŠ&E7îô;åÜ›¥Ùè¥¯Ü¨–­údý!Q÷ve¾¹è;Ìû5Õ6áâ2Nkw†ŸI²$aµF­£Öä½¶z‹¢mòÂÁôoÝf±»²8Lá¿ÿiçt‰æ“š-Ï¼¬üÂ}Ÿ`ù¶r®ý+?ûI¢È$Œ¸U/fÄ->A;+‘Èïâ_Üyçiî…ðÖ[oM%¤8RRúÅ/~ñàƒ2ëÍºé¸ÏIÿ©uìwXœvÖï­w&þa	:ÍÕâ×>R6twO^ê^ª65šÜ{ILrš«ÜvzùŽ ¿ºLÛÐ˜´Ž{;'ç¹:k¯ÍC_výÙ»Œ^*„'š6Wb1»„5´´3tàŠ’:ØŸ5+h+ÉôØðd¬-ÚU¬"ñ´…ÐèpzÚ-I°uº¼Gñy°t¬Q™i%)ìwuÑÆ~½9ÎÖìŒ//ödØ`húð^—Ãnw¸â¸ÉÉ³¤Ù%(ë%5¸Xðn—ãµE ùàü³Y½5£¾¤„Ó\ï¿þOØŸ”Ÿž¸L£aB+n—þ»ÂuÕ~ÑiaéráÊÛ<­3kÂ.÷âƒÒ{ÃïNŽµIþÃ)Õ3uòOOÆ‘»bMžÏ~¾n ý…èûtÄíWÜ­ÍŸ]‚6’pÛøàÃìo­ëé²$$'uÿ]aÕwF—='ÑË¥e?Aª®ko—YÌ6'G'FÄo”©Ö­n¶sÚµ¸­]JªÞðô´©ÚØÒÔ°rm£…Î…Jµ™¼H2¾@[ïMß§Ð˜L:åªéq+Kb~6_mdª3ê8È‘hp%eu¦†Lkcd Óïtç¹ MÐãêp(Ã%¢Œ®ÁÚ`X\@žsZÌ–OâwKªÔÑËªé–h|‡O,œâùHéOäÉ¹:º„¹y$ÅÑM‡0mþéKD')S5“L*Ô:ýìßö°§Ëáa–‡”UM^7½`Ð!IŒeJÐ¥ƒþÖ,ßßRémê”)ÌõvwxzQ)IÉV«õÖ{ÝÁOÍJ˜øÕ?ÿ{€ü÷ÁMÿùåíZõœrÍŸþ³Örö/öâWÞ	ýúÇ}ïýŸÕþ×™eY&KS}ìvF*F›äò[¡é~Z÷ÜsÏú ³mˆŸâ3Ï§æŸ'ODOõõÜoÓÛÿêïœƒÌ|ûäw¼~ø3so¾õþüÎèoÿ˜~æIiõŽµÐ{Úô"|‰LI*JK`„hBw/‘ÊÈ_ü…í~jN’õÊëÿ´9±ÇaŽO	V‘¨V§aW|Y(º–Ëì–jŒs/þ›%zª!Õºìº''~ü)öd´voýäø'!U.íRŒÁ£¸æ®ˆÒP×`Ò/mëeºo2´ËK„ÁÔ¬ŸmØY³«ÖµØ“4	â†Î+ÖÉ6awý®½]É-µ³ï¿Í‹_jì»jïV·‰ÿ}~‘"ÒE5áÎ­ëãÿþ#*—îˆýù“ŸÞ÷ ©ùþÍ’nÃ‰[¿ºô•ÁÿÉøZ§bÑkÜ`zûwŽ[N¼ûî»äëûï¿¿Ñü¼B±ÜsB–ÅÚmðQŠõpÛ6Ýq1©žôú¿ÖBRêõ\K³{m	°êhK}]Û#L„puyôjvm¬-—©:ÐÀ,’ŽT²l?Î÷ã§ŸøÛK½=¨4MzíÒuI¤{¾¢m\À¦ÌöÝ©gJªÔ›Lì¢C©$Í~t0Õ´€_½ˆ‡w;ýéûkKÕz}&ŒTÂ\©Ç³„_$f…H—éˆ‰ß~ÈßYYþ©y«C9›îþOÿ\ð…› ŸŠïdàcé¾{ïuüã‹@`ã'6*îYÇ- V®’ôÕW~{Ù—E¥¾0oÃWÊRþu|+ô~°·Ý%’¾ü'd)^ï`ýQ3ì¶$éÑ±-½Á ×ªéùH0ÌËYî›YþÌVâH•&k£võgˆÃj‰½d¼wÁ¿Ãñ+µI*¥È/Q–ûŽ™¥-Ý Àª[¹TóÊo{|™ÎBÒª{ìõµôú¬³,àRé¼ß^µËæÏlc‰ªñJ§qM6… €ÛÁzš¸k“Lmjÿ‘ÎÓå°w8ÝI}ûÂÂÌˆ¬†~$JÓ•·M  ¬sè¸0‰^µ«‘ü¡×‰ãH8’¼  øºm:n¯¿ö—°¶Ie¬J¥BB ø˜ŠwÜŽ'$f²ãö¼—á[›’   `É¤ê¸Í¬C«<Ü–Õ´ëešú   ËjvÜF%	   –L¼ãvò-ë·ã6B   ,™œœœoX¾A‚QüÛûï¿ÿ›',wÜ±.Š­Ü“Þ(¶ð7ãõs1&3£ã"=¸×ïÂB  €ÛÒmÓq{•/Kr‹ý'{¦!IÔGY^í   –ÛÆï½÷^fÃp   €4“  €%†ŽÛÙ“ô›û_´yLfþ«küÚœ	L˜“  °¦Ü6·WyNÒgò6”±¹Lf^Î{oî˜“  °vÄ;nOõ“ŒwÜn³·nÜ¸þfø`N   ,tÜ   ÈÔ:í¸½Ê!é²¿§>ÄÀbÅnœ?xàµØãß>ûLq>³¼VòX  °ÎÄ;nß
MŸÜ×oÇmT’R‹Ýr÷\ìé½:<6A¿/`5Oš¾Zµ½pî¦Ñ½¯¾Ú;04Ë•³ÛJ´U{v—nš5Ù*6Ê]íí¾|¥Ïö—§(«ÜoÚ³c“Ø¤¬Øè ëµß¿>4gòè>K+ªªËYñTù®&ä•;‹æÜ?:ôýÞþ Ãj+Ë7ç§{±ƒ½î«¡Ü••;äig‰Í9VV¯kJ”ë»ìe6ÍÄe»õFÏk½¾¡òìóäÛŠ·?RùdeÙì÷Š¼Î»|ä‡ÁääWT}¹"uBÌð=‹}¿§›q[nÁ&–-Ù¾-D
€õ&Þqû¸åÄ»ï¾Ë·ÍÏ£ãöm%p~þLßŒ"×çn­ç'¿}`ÇŒœt>ra„„¹¢ˆ	q×{í×†>\Q4yòÝêo=~ª‡›HzÜx°ÿÂó\èHÓ¡òYyböÑÇé>ÉŸ¾ëÇÎ*›Òb¡Á«Ü„¼¢l*#ÅF‡^ïíí¿60HC	.¥lEùffî#o½áîéëóú†…„S0^R‘>²Ì<Vv¯+ù={åÔ©nº8T›ïˆË(Ê]<e¶_ŸJ(ã¡á×ðÀëî'N}{ßöÉ73ê;ì„ÃGÞÇ<yQsËçö¹]}û›ŽTmM
1™¾ç3ÅÆ†/¿vixBôÎ‚Ò'œ/n ¬5è¸µO,Ï{ô‰Ë°ÛèÐ‹§hFÉQ<òø“•åoßšr;NŸë{N¿PÒz¤|*©D§iB’—²
©htÐqêØ__kké¶c_bRŒë±
IBþðcÕUÚRR!ˆqW/œ;{i8ä>w¶„=QY4}í?{BHHyÛ*k<C«GÑ}Ž³-}Lx»„Qß5’[ÊË¦ëL£×_;û½‰ù^h,pí•s†çÝ.Å±²{]Iïïàkç„„´ªbïŸ‰'¤‚’'ÜS¶9—ä×KgoN¿Ñ£oØÏ„TðÿgïàÚ:Ï<oø€-)	ô¤0Uål„Ûúd' g‹G~FÁ}Wäéƒ:¯±ö	]mÌ³JËTÉ+óÁfÖ8šD)¶5%5x›·•Ë6š’F»Ä=ÅûÊøÝŠO·Ö|j¬nÈh`f™öxë¤EUŠ’S‘äÛ¼÷}Ž$ôçHÜë[qtþÜçèÜ¿ó»®ûº›»ûO¶ñG½0ýšÍúÆ[.Ö˜ÖÚæyP´tÕÕUñÍÇ¢Èß{+<uñÅbß<ì 8J  ì,îŽŠÛåIu°‹Ø>³«­¦?ÑJû¨§O´'ûÀÚ†Öc§ãÑcCSÑÉñÉPóa¡÷Ï_Ãz¦îñÇ¾Qíþ'=M?÷ÖÔã³b?BZ§íh›º,}ü¤)eBQ=EDŸ}ÉŸ¼Ùzä¡„šˆM_|Õ‹„²ítÿÑƒ	‹¥ú¡–cCÍ¦¸´ZDs HÙäì¢¢ùà¾•žtOkÿ•V~u3ç>7ž/íKJµyÚñ«…ÉSvfr‘X…Ìm­å¸V@ÆŒãƒè¥ë“[—¶0õÆ«×£„¤þHÿ©§…Ý—Ö6>ù–´vŽß¼~ñ'a¢êQ“¹5¡A¥µû¿vâ©ég/Ìù&è'R†S‘mž‡*ª¾é@Z9ŒÍÍ'{/ÞùÆß<rà1   ”™ò• ø7ûï!6óG›±ZéÞ–Þ³§Ú3³N¤{›ZÃ°þE4QÓ2žñâ0Ì>]z†Š´®¹µ	-ššÅ„wjíÙ”¦#j´6Õ Ã³‰ÅlÔ\ö!‰T£3=y0+%ªÑ¹É¹Åšúfª–Øt²¶Uüq¥ˆÍŽ^£‰šƒGt
	±e 8á¥«ÈDªk3u4fî~F;Ç£4ŽCî©£jÓÞ•ÖÔ7 Õ²
E74ó`AªZÛêQ-†è…   ”ò‰¤õàîï~í“DIyê“”1­JRÅ'ÒÆ—’=c,<BqUgâ   IDAT]¨¢¡!SÐTSêÞÂstáN´ª†—VKD2H³0wuuÝŠ¦–Æb%ÏÂìÕéÅª†GëË ‘ŠÝVÎq%‰Í^<ÿÃKÈ¾9ÒXl¥õM!:s'R)›µ…GèI«XïÍ#¡"òG©Dº¹éBüÚ‘jƒ¤$  €- ¬‰Û‡egùT˜-M±„?¹ë3›ÂËG|):»Êš!u„ˆÇBaôŒ/QìË6}jêêj‰™p{Nuy{¸xœ¨V$S`âs!¤‘$ÊÆºb“Pf¯Ï-V5¶4”E#·­œãJ£Ç¿3†%ÒSÝ­U…¼ÄFÀ#
_Ÿ‹+š;LíkMýŽ…çB‹xÀbÃ*”îmnm|}næú£SM=M‰…&gBÒøhC±iÄoNNÌ.’}MM
I   [@¹G·Qò]è‡Ø‘Äç§|³Ø7¢ö';­ÅèÔVS›ý¨/­ÞƒõA,ºPÀIZ˜õÍ ITµ/%‰bÑ(iU«PTócÎ/_ŸáGJIêiêØWæv–ôÕ™hU}óföÖkÝVîqñÄéËC?˜CéëÇãdtbÄf'Oð#
‰¹¹™ú…ÁÖ”µó2®†RH¦q	 <$m	ïó£_;zì+i6ž´NwüØTïÙëã/>M:züIÝCÕ¡«¯ÓHê}½eoéÕK|¦CÑð¬olt·Ö‘î#AÚ6  ÀV % Š&šœ˜ÆémÒI‰Çã|<	E]²—–ò²)¾™YßÔå)<´ê€v¥OŽÅðòÒ¥ÉWþâÒdXR£¬£”Ñ›L4ôÖÅÓÏEO«÷`¦ˆÝ¸>•PÍµ›ï5¹-ÑãÂ%(Ç/¼†;}Óñ6j£~<:;sc%”™¤Sï¥Åã±8jh‰4ì}ùÙ±·¢…r¯R¹f¢7®;¬áùSýÏ6¥Ö&ÝÛÚ{röøW˜¹+gÍWœuJ"ÄH›»{¿RÊZš7^ýsý«Yï)´½}Ç¾LD  Ø@$Iüæ„stn‰¨jújûþ•^«PÆNKT’è´k¯¿>µHHêÛäT>
M\
)´Ç¿Í;.8=üÍï93vcâÜ…&êùôâC1zr*,ièh,C<¦¸må9®8íu¼>ÃÚÚJâ‹H¥b%ãI*]sÖ>sKsãc’}mùÊ³-B©†ÐO†¬/MÜrø›Þd:zìã¯\aP´ai…PCˆV—¸Å«(mKcM"•=¾ÈÌMMÓaß@Ïœ·ÍtÜ´–   °
 ’Š"òžs¢®¿ªÉtô±•#)ßU§gr§}›LUR“	3?étøÂHJ|í™¶ôAò|g¿H([±i”ÔRÅ¯m›zîRhj|:Ú¼'¥Rb¡k3a	Õ^TÔ¶Ä5ŸÓ9³˜´m©¢éP³bÆ'³¯jl;\¿¦ÕJ¥Õüò’}Gú_yº1ùYiÝc_?:9uæjxÊ;·p ‹¼øÍñ—{‡¦âõOœýæSÕñ…Ù«£ë›a¼g/Ä_9ÓF•¨é-OíÈ\ÙÂìås¶¡ÉK/¿$U¤d   Êˆ¤"ˆÍ¾vöÂ’H˜Ì­iØ’ü[L’¥	ÉHÕUb.}ù•!.ÑóäÑÃ}ŸTX¾®©-kò“jeSCÍ%œªŽ9)Sb¡É)F²÷ë{Ê¡‘VßV¾ãš÷]øÞ[‹HT3Þ×.¤–Ï`³03îüÎTÕ$tš‹?éž–.P­W'fbûšuk6J«pC/Q-º,uU»¯i_ÕÕ·¢üàþZéÂôÈ“Ô›»¿Ê´¶A÷ì·ZtcÖÞïÎL]xmß+›7]mÃáãÝ3³6=þÆ›Ÿ~¬©ù   @ ’V×e>ó
´Õ<zü˜.3ê!­U(ªˆ‹áP4FìIï+ñ[ÎñVd÷ ñðä¹—‡°äj|êdV%&¢ZÑP'¹^‡ãDCÆ_¤Õ¸Úv4¾¯ìÙõ©¤îHcÝæk¤Õ·•ÿ¸bQ:ÄW©OM\šÊþÜRèúxˆŸE×¼6±W]wà°é ±.ªÔÂGGC¨¡3Ïiu-.ó°ãk7,ÌM¼Eâ¸­9Ãã‘>ÔvÂ<õìÀ[7&}tGÃþÍóxªëë$¾èbˆÇƒ‰Ü   Êˆ¤‚ …ô²u ×eÞwäùÞÇr"MÕuûë$×çøzHiY*¸~a^GÕd|+	ë¾ vëÉí¹‰&µû÷Sth6kÉèã1~–]iM*ù&šž
x¢Ùrh¤ÂÛ*x\ÕTû©æìÁlñØËC&£UM_ïýJCUuMYó“¥ŠúzA/Ðt8~ #½Ù‚|ù|âP£#õKÔÖÖdïr”šê$oÍÍó5 6qÏã‹‹üy‡RI   åDR~b³£6ë«Ø©êÔ©ŽF‘®PZ×x°Ž˜»1{mnápÝžÔ'C“Óa<%XKzìŠFÙ†&ÃHqµ9uô€Øui]SKýè¹ë?ºñÕ†´-Æ˜©Y¼ÆúýÊÄ›(Zu-´T×Ö\·ùâ¢ð¶V=.).šû1é›ØCžNãÆ²{$ÕÔc*Ç/Ý¸6ú
•f-Ü˜œÃ‘ÁF\@	E?qTn!Ä,dI)\ý!‘t¶™â%Fû®Ò„HµR    ”¯âö#FÿPPH’ú§¾Ùß‘¯þ5ž¤^B,MM'½’xhòµ	dª(š[W>y_y+	BÙvæ›Çò?”Rº'škˆðøyçµp2²¶0=êÄ1©:m*W)>?3I/)›Ë`ÀÚV±Çµí¨nh;ÒTEÌ½~þµ™d1m<4ïÕ«8©ªMÈUª¦š(tvç~à§3ÒÎÂ“?œ¸Á—9Ø¬Bçñðìå³¸AHoƒ´m  €òSÁqþ§¢"õßô^çþz÷€£l'q”-/ûžúö¹ÄX¤…7Ÿ{qœ!$ŠÆÇÙWE„g|×iœåÝ}þÔáDà)<ùÊsg¼¦<U´|ûX2·eáÚù“§¯0DÕ¾ææF…$J_ç‹3*ZOÿMrÈ[<tùEó¹pËK+#ÕÓwß{nhŒæE[|1ÂU‰å>>»YZ×vì¤0&	Á³ç½‚‹ãjLK¸z¸r/Ÿ<^E9ÙÍ—(°­µW&±ÙïôôŒ1Š¶o~§§iK$@üæÄK½gÑi®©×â
™á™«“73‡¼ÅfÇÎ<ÿ],–ëmûJk“Bº´0sulÜ‡N±BûüÀ‰DE†bÛ<w'Bc'ž½€‹K(”{’•ÜãÑp(*8P4?óüñöHÚ  (?n#šš‹»pígO’^¸0öÖŒ÷ÊÿVÕj:þõÃ©ÔœXxzz“Â×<úÊKŠ³ç^ŸšÄvV.‡ž4›t©
Cñèì5z©¦)Ï¤¶±ÐìÌPæ{QæF4ñÇx²tÓ}cŽÎÜ±¥0C'^&Œ¬ÛZãqm7¤{[_¨zõÜwÇg|ãsøIÝ#?uô©´Ô«ê†öþó5^§¯_:wýRâm‰²ù™cÇ‘¯—Z°Ø6ÏËâJË’eý#š›Ô‰  °5€“T2báYë
i’Ú[¢Ø±…ÐübÕÞºìÕÍOXž¥ô}ÇÚ¼éC9·µUÄcóóá¥Úºº%"ca:æk=TÕR—   îÀI*ÕŠ†ýk™>¬¨uÖÖ=$¢Lf'gkóøH¥¥œÛÚ2¤Õ{ö®æ×T+¨†RŸ_   `;"i²079·Xµ¯¹}v9·    Û	I;¤[¦£’†æÆ=Ä¦SÎm   À¶r’vñz–!û¨=eý_Æm   À¶D     €PL     @I      "€H     D     € ’      D ‘      ˆ$      @$     ˆ "	     @I      "€H     D     € ’      D ‘      ˆ$      @$     ˆ "	     @I      "€H     D     € ’      D ‘      ÂnbDÙS¯+**     €@yßš–'	     @„
Žãˆ¤'”r†Ò-¢|¯	p’     ¸{'	     @I      "€H     D     € ’      D ‘      ˆ$      @$     ˆ "	     @I      "€H     D     € ’      D ‘      ˆ$      @$     ˆ "	     @I      "€H     D     € ’      D ‘      ˆ$      v   ›ÇøÜ?#SŒ:JF   ì(@$À
\„aI¥ü.íÍŠ…Íùƒ<©a¸ˆßãöÑ¬È‡åëÒ9ãuÚ]´\¯Òë(9  °£ ‘ .èìì´ù#rýð•!ÝÝÙ#Åâ°»˜Ü?ÈõjAÃ å´;‚b–ë¶\ç°—Íîfäºž>³  ›ˆ¤¢`i¿Ïëñx}¾ Ãá7ä*­V¯×ëtŠÜ©¶Gû\n„ HJg4¨É<‹±·Ë›ë-rŠRa”bdƒ—;‘)5F£N¹ZH&WòÝ.‹¼$Ž¸;½$ReÕs¹ÉUšC©ïï7&]#.âµ÷:ƒyÖÉE‚Ašåòü]|é°‘ ß/Ï¯kÐ¨)’(tÛlî ÚTÀ¦ÔŒYÔ½ `³‘´ã±uõº‚™Ý@$ès£;îN†Œªx¿Æå6'_’A¹fØ ®e¸HÀ=èðçíåj½©ÇbÒf~š¥=§—%”œÆ°q‘ÄÑn«ÕÍ*UT1â…‹ÐÈê1ëWëu3!)%óD8õùÄvˆqÍÈ”Ô:õ
"š¥`I¥Z£,âÃ¤R¥Ñ¤DÃ8óî>5íö ±ÚÞøÝŽü/Ê¿“©ÝXÓ™  X/ ’
ÂÑkW·›Nü*§ÔØ:‘Eè` Œ`áÀx¬1‚tÒ»o³A¯—Œ”q,ðø#zCa1CªõMrŽeè€ßÚ ðØ;ÁÑÃæ9FlmŒ&üÅÂ‘S#¦µˆW™œR)	?DR$ÓJâX:èG;àg(£Å¨.Ó‰fÎ®'Ml ¥®h ±ÃŸÝ:ˆ}Ã<È5–‹†à8tUËdk¶GeJ•j=r·­h
”È&(Cÿ7è
:³l$  Êˆ¤ nª·WPH¤ÆÞ8ó   IDAT<0Ð“á‰DüÎÞn›/BD¼Ön»r´_»“²$‰Ò÷hi»3P„JRjL–ìŠªÙ{‘Í†Ü6«F=lÜ¬ñK¤J­Q“ÜªË!í†$hŒºµÚ{ÈfAÞÍ2>WÅpA¿Ï ™Ä
ydjŠKîÛñ:¼Ú!þ¼¢°˜?È/Gär,úqâ:VÎò¼JYGvgDc4hÓE‘\×7²®L.Öok/Z
¢¸°yHG   ”IyA.’Ý3 Pßh¶h²ú¹Æ4<BtuØ|,A»ìNƒÚ¢Þ1nRB#)5z­Îïv‚H%1zÃZE©Ò[úvgõ¹ÜAý&µ òF«/Ç1îîC½(ÊGéŒÚbÂJ$«‚H
ÑX‚|þ
‰xV¤JgÐ+Ëw†I•ihTWœÉ’’16+¯í)ã@Rù*#3«6aÄëDÐõ®)à
q´ßåð°Ô]›Þ  DR>¸ ×íç»(•ÉbÖˆvŽ2•ÑÒãõÛü–šÕ+‹q($‡,’JF!ð“<r&"8ª£VSÊÂ´4
èá5Èó¦G‹l‚ OÑh#*^§ÎßÑ!k$¹Z«RªdÊ¤ýX%Qk·‚HJ«V:ƒÁÐÈ„ØR™ÈÑ^·!c¡ ptwÙ}‘‚ë"•” j{ôŠR*Ë¡ƒŠTzipŒÇåãÊØ×³&w“c|.ÖH:­ÈuÀq|(Žep&öÖ”I®xN†ZF•ãz¢À¯Ïëàè¤¡¸x¡l.,èû(„ÐÕª|y8-	©Œ¯1Ãp¤Z«×í´ ;  kDRÕâáÓQI©@+SéMz§ßÍHeYMJ%EüöÎn/K™FGM„×ép¢ž+-\DjLÑ”fŽñÚ»{™‘´¾þþÌIlBeíQÝv›ÝL}Èa#Õ¦¡á>Ñ^i$?³ŒtH^‘2­Fé¤™€×ÏÖ¡’d$ÉïÇFø|b«@ñO'N0§fC¡Ÿ†•þ;î‘R«(²]AŽ2ŽŽõkvbÿ‡/»ë?1‰Ä¸»x£-R7t¥_éµÚ‘Æ”ëLúÌ«@&ÇÑ7Æ×ûÅ‡{…w”uRµc)§–‘*RÆìíŽ Q<”ilÔ@¡ÏËÉbšÐÁÙ:¬>Ž@—|æà¶ˆßaíµ{3‹ ›³§¯Ï¨É\3ñ»¬½¶¬ee”Þ2ÐgRË³·è±uØü„¦ïÊˆQæwÚlƒÞ•¯±Ý*×Z††Ì;òJ  (@$‰ƒþq\ŽRSuäj½FéÆ*)àc8MVïL{¬í.—SŠb´ÏäëwvuDF²"\N2ÅÑ–uØ@Š}(4ôX»	rt@Ÿ³3‘ w°Ëãôñw}Ôe¡MðdN«]36YÑH|§@ª´¹ËÁ·u¨$ŽeùnwÙ¾¥î :×V¸×’É5“Y«T#û ùEé ].}£ƒN³ój;°þÁî^ƒ5¸eÀ’«±l0S")Ú;º=8 «·ähw¹ÆÜgfþHB Õœ\¹L©ïÕ[_uŒ›¤Ê84j$6tövay‡¿Œ:š’qŒßƒY‚»M©M‹sAWW§U0Ñ}s…eÚck÷ûF‡ÄBÎpÛ¼.ï,#MM‘,³ûq6¼mP=Ú2	 îV@$‰Ã2‰‚HÊUË·JµRæFKGhdÌ«²z¦ÍàŒš¡>CÒ™çÌÈ)êvt´i}ò†ºøLqJ?0Ô¿ò‰Ä;Í®SçÈ¤ˆÏáDý–¡¿¯'1úŒ£Ý½¸³d¼.£ÍÎÇNh$’KääX%¹=ëSI,B|K©¶°d®gààô1“I»Ú(;Re°¨Äÿ"r·éÀ6)°ðø5>‰Ž2™ÅÜO$’L*½è‡‘Â	]¤yx@D%È(exÕ„iRm¹òKKöz…Äl¹~èÊ~S¹H¬äºþ´A¦}}.'t¦´vàkQb…„¿-+ßÈ³ÛÖmuÓ¯ÍæV‹ô`<­Ù>sâKƒ”Y7ŸŽˆ£òf¤gÀ]
ˆ$q¸ˆ`(Ò°J—+#ù2„Á²‘Ü2„2•ix$#è%Sê,CýŽ{0^d i’"†zq=&™Ú<°¢!Ðç÷wºxÑ£ËCFjûF†Òz´|Ùã³úX6è°eÆœðùHX#%Õ„R­U“/þËÚTñÚø:ƒ2ô\¾e	¸÷Dò çˆmlˆ¾œBF„†ÕåâÅ±\–kÆú­í®×'µWFùs¸HÀÏÈ5ê<
y$Ö^¼èb²äé³9=¹ÉUH8š9í´…ö’Òhµ•|ËÔp¨]„ñŒCz†EfuÆ‚´×1ˆ½ ÜBýéR‰æþaŽi·úYŸÔçž¥>ÓöEúŽùíèY'à£ó_+  ìl¶@$1ïþ*‹¹ð=÷ÜóÙÏì%ÊÇÿÅÅd¤°ê;sû9Ò9=–L©ÑkH¯7CÄ ƒÇSj”z³)§£—ãi#\N-1R™÷p¥Þ”]/wzJÂ$ØH$«èðâÈÒHš•[¾\£S“^ÿ7c•Ä2A¿ŸKþ	õ·~Ÿ×ëIdZ¡.Ã°UCãQÉjÇImêïÙ`à52_QRhT¬,ø$ß Î×epYMÿ•‘M+w .FÑmó28f wF¤º»¬Þ–äC¦¼byMys’úpêœ·p:;ÂáÉNZÙ¹ò12¥J£U•ÏY‘ñ1H+yý´VŸ÷¬àúò|HN©3åNA‡¼2“Îáw3ŒÏí§u9nN=ÌzO†L K…ˆðe@$À]IYEÒ?þÓÿüžóµØµkþšñÿþ?û×DY‘%…=«!,#[C¯I*±Ò	¢h\áôXÄ 'RÃBb=Œ}@†A¸†¥²áÆ§Úp\ö„ì¡)MšFÂÏÝè1ÜçÇ*‰6R¢rgKyÄ¶¥Ô¡'s“zk
{tó^–ÚÔgÞøàºDð”õ;­]ÔÚ¢ÎŠÅ²Æôhœ©L“lMW@Ê‚ªK&Ã+G*§£=˜éi ÕÝ…ëu¡0ÛÐ°¥Ðx¶¼9IHS“$a0£?âl¼ º<²Ý".âw»(ž¬Í;ËmÄï´YùÁ
c}esVdJ-zàðûXÆÝÝÎPÐYÜÑŒý¼žGgJTÁÉq†ž›‰°h9ÖPT¨55b#¶tÄ  ›GùDÒ{ïýöü·¿»´´D¬‘×\ÿ¥rWeË¿þ"QFVÜ!vÕ![\DP"(îVô2)bPŒŽÖÏF‚|ë·wµ‹L:/ügAö¬á†œ©H#Ak$!Dá›´j@ÈnBw|–ÃÏãF•¨JÂ©Ó|5<[¥ÖètZj«ž¢qò• ÷ [7dZ£NÂÕ'™Cûýþ`ANôyÓÃ3ÕQ”’/€t”6«æ¢zbÓ ÕÆ¡1•¬7ÂxzÛ#ôÐ@V)CáN{W—“OD2ô­2Lœ$ŒÎÔ£òt·;¬Z7ÐcÎ¸Ø€Ýç,UEW0ÍW{Ÿwî¶b&m+.Ä=ìêF‘j$m»9­*Ád6ë3J`$v_Åyj]	QóÁ¢ë¡¨ÉûËœ|YÄƒ  ;’ò‰¤¿ûéäšRUÕ}KK·âñ8zýý×\wîÜù?ZÊç'‘¼qá8¾Ï,øŒúU¡&3©”¯!Wƒw8‚HYUÉQ·`
|l#=JR#¯­Ë+¶ðäQI*ÃÀ¶šT§³óééØe“3¨kóÙ::d¦¬"ø	:<¸¢NÁõ+u¨£Õ¨xi´rppÞð˜’ÏùgýŽ®.¶¿‡òÚø±ì2òúVŸ#O;ì	Š¶\m0bƒˆË½ž€Ëá5¤%Zã|w'Gç¯®€K
ñÉAbó“ä»MŽ}ÃÌè–)uýW~jp;N7ˆ¼ ¯³×ë´«–~ËÊè‡Ä²ùÝ>™œÿJr\4  	Ê'’æÃá5-ÿÉûï7ýéWíƒ‚NzíõÿB,e‹»áq¾|ÂƒüN[ ÝÉ(Aw”jM)ÏIû)aÎÈ·o¹aøÊÀ&–Ii¤,S(¹KøX9\g2—´m@Š³·W©¤6÷Pô`W²@Pð­‹éÓ
:‘|¼,ýJ£Õ ‘+qOÎÓÛéâ®W¯ß^5ÔÑ.õŒQ½]V/tõvño’óðESÄ#ÌUì‘ r=ÎyÃ«kÍ&µ×À#/5‰ãÄOjƒ)ÿvXÚŸ×HÊ?·›\]"Št^ß°¡çE9N7KWoÃ	“å$¿^X‰[±‰¿ðu´   žò‰¤ååebPû>÷—'ÿâ[gÏ}üñÇD™ãn|Âµ›‰/hTåí,ñMY(Ÿ‚ÇÑ}wÅÃŒøOR]
êEÀpB–M±.ø1þ®á74&RÚ…::Ùž±Ä´UR2‹™À9ëýÃýü°o¹edDÎçæ]Ä0NÎÉs8‹ÅÀ¨åjµZ´˜y„Ar!á‹%©·]‡É’ÛøðÁº*N!­€ÂS}©œ&$+­6oæú-&O‡¿¯Æu¹ˆ ÛjÅ†eè)ÌÄc°‘ÄúÝ>FŸÖ.×YF6©@&È3ôiô&¯­³ËE³>§+ Ç×:
ë¡óŒ\´ƒ¬aµÈ'YdFò»ª¤¶Ñà= Ø¹ N?ÄàoURYQQAìL*‰r±V‘$,¿ïsŸ}á¹ÿpÏ=÷o¢¸ÛÕ¿û)Qðb~YÐéô1ùx\å™4—k«‡;’pL@PV8A6ñ)ê´)‚/ Ù8xü³->›ê_4*~¼ÕÁ·lÐÝÛÑ!($
©…•Â8|8j¤OKâ¡³«kÐŸ·åóÀP¿ÅdÐiÄ¦{!)~œ=G˜íÙ
ØP2â«…`<6›{m×:>Í
¹-€¬©~We<½]6·ËÚmÅ£ÂV™ä$9 €¬9|«’Û\6›ñ¥€`!z&W	‰d‘€/(¶w‘ —/rOæ$œ °v†ù³£Ï<õgO£Ÿ®£Ïüú×¿&v&åIëfïÞº-ÑI¨+µðS"k·]L'EüŽ^¾6Aj{zôyfÊÇ\B7"ÓV"y¤JoÐÈøñZvw°ôÝsÒGRjòÍ8ER:-¯’ÛQ%á7:¿t¨—o$ÆFû³›œT™†L¼Ô8º‘×².±‰§)Áj€yHlOx4À_žXËXÝ%=_Hoè)\Z —Ÿ*W®È3ÃMÆïÂI‰§4!·Õ¶±Âú]N—/ª´•   IDAT7X„ØâR:HœÄ%‚t“‘wÜÏ ;}a°~ç _þ@©3h•`$À†XZZ²ö}ãwÞ~ýå;ï|ãô©;wî; ’ˆ­ÒI2¥Þ‚âàé<Ôauû“&º‰{l‡:„iRåº¾¾|ù¬.~í¤nö¼ÒéàEe4ëÓ>&Sé{øj¿œßÖÕåÈe,íµwwòQu²ºFâ'«ÕðþÄöRI8—ÆÖù¥/v	Ò’Ò÷]ÎSs@®µñ5#>ë:ÅƒùÄ~½ÅŽH!ð¨®„ra<Ö.›§”g	Eä1%QLºBùvlÐãÄ3ãjL==ýfµ,âéíìrø×™º:º¬6kWG·k•£Â3±´úb{·ÃK§)äðòe¸eêTÉT¤’„	k¸€½Ûšî¾¡‡–Þ^þ+)ÇÏ€òöÛoÿêW¿J	¦ŸÿüçÄdÇTÜtÒ_~øá‡¯“Ð7=?	— àº­¨ÂwÒW¯ø"†¼]ˆL.£Ý½ín«’¢äx"¶¤;!×õçµ‘k,Ã,ž»ñÙ;¿hÇ–†Œe‚É,cYÄkÒ™ÖUÅk$þI9O™R¥ÓRNšæ3±Lª­Ë†kiÛS"S¦6XôÇŒËˆaÙ+?Z¾›$‹÷•¹œ×Nøi¾î¶^¹}ƒ/È9â:;íŽvõvËÈ‘¾wñãs:Ò„=tt¶3–>‹QÔdAb;ž…×ü$IôôÓ½^Ÿ½ãK½AY³—,i”H7(ZÄO(ãw¡ËcÇóë¨äÈZ
xsI¦î±¤=½ðÍÁ	þèÂ8ä±ó£8–N Ñ·o¨¬5Bà÷‰Ûwn;á$	 dµœ¸ï¾û„_Ëã'‘*ÃÐØ•!“F¤{£.û
ö4
t£”~èÊh¿QC24¾u
	=°ŽŒ‰Þ‘{50:6˜[ƒ¯õÌ+$™RcìAÛZç=<©‘ä‰Imó'»2±<Þà6ð’H¥Rž(õ¤ÔZF~ŒBlETÕ‘	³—¥jZëV…L&°]Ó’RÔñóÎò_¬voÖWÀ~èáÏ&iìp&fSá8Æ»Æ/vÚ°B’Qº¾ÑŸ¾=óã!t5r´ÇÖùÅ‡ºíž¬ØVÄgïÅs˜ÉuæD)p<Wáè 'Øarñ×]$èóx}þBRNNÎÚª	BxôÿØOÇLüU‹·âv{ýxåãÀØHvyQd4¢ožðàod@PHè9œ»4  ëâá‡Þ£P¤¿óàƒþá¿üCbR!¤Ì™ç©üóôDô|¯s-Ìw.|ïL½Uüòþ‹úm}¹ï3ïþêåo]\üPøõ©?ýêÿë‹ÍDàX†¦™dkì6Ãxºu{Y‚/?ŒL~üÀ*t/2RI©Vµ'X&¸b ‘”j;M—UfØ ×ÁIåkkŽöºƒÊõÍ(ÇlíÎ êî‡F¶ÿä¥¬ßÖÑå•›ûLÚ„ì¦®CV—[ ‚¤ÔdÐ0€žzo´2Z3pÙ­ödˆ
 ëÁ#äÆkïêv"5­4ögvXs9#å/Ng’ÚþÄh}þÃxø'Kf~Ç’ûO¨ÄfFI›˜nµ/g¢†hê»Œë¢B" ”’_¾óÎiÛ™wß}½þÜç>×g}Q©T;ò…Ûî,¯-i+ßh8åƒÿâ…çNôÛÏ
q·™úŸeIøVª^óINæmËð˜"ªøÏ¡iväUzPp=Å¬‘b\Cƒgm²gø§=rå©˜CjúÆ~j)¶¾Ú44jÎN¡‹x{½ž L­7™L]vñ"Þ35ôø=.§Óå¥ù™ñ2¹ÅeJó@nH›Ÿý£Æ~–Á³ÞÑH•&aÅ%©ÎH{B2g­óÜð)ö„¯ë» @Ñ|ö3Ÿqþ§ï…B¡Ê]•Êwp	€ò‰¤µ¶Ñï~û¯—óýUÕððÔ[Ø)°A—Õæ.2x%×Xú×Xü§Ä E¼³úÐbŠBŽ¼mÿ›\×e¦Ÿ,´l YÐË²2aÖ2$žLÃW´¡,Å³¸ ¢$'I„BF °ý©¬¬üÌg>Cìp¶o1ÉßÅbÿÕ3N w	l0èÐÅ-K’03DÙI
ŸU!3Äµé‰òÀ&j2àD5PI  ”ƒ3ºØÙšþÿ²Ÿ €µ	x,)c.»±€r °#¸;*nƒH `ÃøÖî•Q{¤ÆlÖA–5 ls†9ýW6¡žäg?óÛé3<ð ±‘´I”¡ÇLEH¥
¯ Àºá8B®R«ù:$¥5š×µ `ë*n§êI
·‡*+wRÕ!I›©Ò™T: €À×]ÏèF  ¶Š|·~øab§"	    €Í*n    ðûÎÝTqD     %C"‘ü•í¯0~ýÜç>÷Í3¶Ý»wdä
Âm     ”¨¸      ÎÝQqÂm      "€“    @‰ŠÛ      ÙÜ5·!Ü    @É*n
‰HVÜ¾sç±‘    @ÉÈWq›Ø@¸    €Í*n[Hüo˜éŸúÞlŒØtÊ¹-   `‡q7UÜ')?ñùYßøÇ'®ÍE—ðï5”öˆù™öýµ¹‹Æ~1ñƒLLÍÎ2Q©‚ªoÔµ?y¸i4k}ôµ‰±ËW®Î„ùõU)›ÛŽšŸ<½\báiïëôÖì\x‘¨Âëljmïh¡ªÅw5<s=´¤h;X—ó÷…ÙML†J×Ö²WüÃ‰ƒžð]K´µPH‰äl«ØãŠÏ¿9~ñòõYš¾Ž	-‡Úó)Sû*›ÛDb7'ÇßŸœ½A‡ø3\SßúµgL‡²OpŒžôŽ_öM…ðnEãá¯{º¥.ï~Ùæ‰µÏþhü*ÍxOZ³‡¢÷×Sµ«~  `›!TÜ>m;óî»ï|Åí>ë‹;´âvÇqø~x^j^úh½|¯s-ÌÿÇ1<õV€(5š¾ðçGÿŒ(5ñ÷•Ï^çüAÙöÒ·ŽÈèFcÓÎ“Ï_¼ºÙ…²š‡°V¨i9ñÊÉÖT?ŸŸ¼púåqz){}
íó'Z²ôD¾­+ZO?Ñ\+²·—Ÿv(Üú­Çö½j|aö'“×§¦çAÒôÂêLä“óoúÆ¯^™ž™ãNî›ß;ÙT¨gÎÜV±ÇO~çÌKã7r–“ìk{þÔ³Í{Ê-”â¡µørOpUã§¬¦”^˜v¾|êâÌbÎbMÝ¯œi£Òw»Ø6ÏÞ‘±Ï^˜[ýcMÓ'Žç‘Ñ   Û™;wî@Åí5PY¹)¡½ÊŠMXmlö{/c"Q>ú•#m-ì¨:6ës¾r~"ÄŒ¿òÝÆÏ·¤ú¾Ø”ó¬-'úyU”èV¯^¸ÐTêË¼LŠÓãý¼’P<òxG»®	9qúÚÅóç.Í…}çÏ5RgÚÒl‰…Ésgx…TUßÖäÅ~qÕynè*QS%º¿3×é%EKóŠÏ´ðÖëçþvjiµ‡®¿vþâÜªËåÙVÑÇŸŸ¿Š©¦±ÍtôéV|D7'ßp]œŠÞr¨á`-QN9ty5±¢é‰£æ'š‘ß³0ë}í»Æçg.ž}­é;=¸-ç'Îó
©ªñÐW¿Òúè~jž½pþ·¢SÎÿp_GÃŠ ,²Íó hé>ª««š+‹¾9þúØ[á©‹/¾ûæùcÀQ `gqwTÜ.ŸH’êSÄ&ðéOË‰’SM~¢•öQOŸhOv‚µ­ÇNÇ£Ç†¦¢“ã“¡æÃÉÞÿÚÖ3uŸ8žðj÷?yìiúø¹·¦ÞŸ=x[;Ò:mGÛÔeéã'M)Šzìè)"úìK¾èÔøäÍÖ#%TRlúâ«^[V§ûL„¢ªj96ÔlŠK«E<)›œ]T4Ü·Ò“îií¿ÒÊ¯næüÑçÆÃyTJµyÚñ«…ÉSvfr‘X…Ìm}\Ò½mÏŸ¯I÷5ìIìcõÞæ§¬ÒÅ£/Ž‡£3“7Ö–U%U793X®nhHûjt=ÏK£=/OFÃ3“¡X#>ó{iïxti^ÛýlÊ«k~úDUì¹çÆ™¹Ëôá†ý©F/²ÍóPEÕ7Hs¦47tžì½x#äóÈÇ   PfÊ—¸ý¿x„ØþxSV+ÝÛÒ{öT{Cuæ›M­ÈÊY
ÿ"ÞŠ‡g¼8³O×š¶¬´®¹µ	-šB­ðNíÁ£ý/›2ÃtDíÖ¦ôox6”Ê^˜¹ìcpØËôäÁ¬dQ…„ˆÎMÎ-ÖÔ7SeYÛ*þ¸*jL)¤ä[ûÝûþØB²=ËI-µ¿!«…-õØ«‹E“;T»¿ãTOV8´¶áËÍJôïZØÄ¯nhm«—Äbˆ^€y  €- |"é3{ëJž<ôÇžU*$Ê†¤ŠO¤/%»ÆXx&„â+Š†¬î¶šj¤P÷ž£wÿU5¼lX"’Aš…¹«ÓQji,Vò,Ì^^¬jx´¾©ØmåWc¸yª««¶GÒM|qq‘ß¡ššÂ;„ŽÞ¥øzckEÃïRÇ”  °”5Ûü@Óþºÿôûï@”‚ÚÚÚOË7%„—øRt~ý[S“èÔã±P8†‡;íË6}jêêj‰™p{NùGAÅ£øãDµ¢6‘k_˜!$Q6Ö›„²0{}n±ª±¥¡,©¸må—øR7ßšàa³n=(|u|.®hî(Ñ¹xhòG³H÷ìkÖnþx”WUSS½‰ê%~sríŽd_S“D  ÀPî!y{Š¬ò	;‡øü”÷¡
j²ÓZŒ.à‘l5µÙúÒê=BÜ¦P<faÖ7ƒDBÕ¾”$Bq<¼V¡¨^˜ýÑÅ7._ŸáGJIêiêØWæv–ôÕ™hU}sC±é»­ÜãÊ$¾psnfrbü‡3‹¥îëOì_³FŠÍ:OžàGss33ôƒ/l k'¾@O¿é›¸<î›[’P›¾ÒPP#ÅèŸ¼Æ'¥‘Ú„|j´3t(žõN Ý©?Ò}ä!HÛ  Ø
 NRÑ Ÿabk¤G´I'%.RIŽx‘ò²)¾™YßÔå)¤%jhWBk1>þ$]š|å/.M†%5Ê:J½ÉDCo]<ý\ôô·zfJØë³Q	ÕÜP»ù^C‘Û=®Ôß~øœÙ‘M'Q¶¾pêØcukßõxtv&­œ@tf’^xL±;*>ë4»È$~«ªoÿæóO7­2â~afŒ“4|¹”ÏWÿßùU   IDAT\ÿjÖ{
moß±/S ‘   ¶IE¿9áE½{UÓWÛW4…2Ž$DR%‰I»öúëS‹„¤¾ýHNå£ÐÄ¥B{üÛÇód<üæ÷Îœ»1qîBõ|zqŒžœ
K:Ë)n[‹V)ö5P±ð<ƒý±%fâ•3ñÅç‡¹&¤Rœ´òÛz³vªê¨ú}D4ÌïÐâÜØËgâÝ/<[¨Pä›¯9}HÖµ=ÙRWÂV¯¢´-5á—ø"375M‡}=sÞ6ÓqSË^¸  ”IEyÏ9Q×_Õd:šæ|Hù®:=“;í#Ødª’Š˜L˜ùI§ÃFRâkÏ´=”¶ßÙ/ÊVl%5†TqàkGÛ¦ž»šŸŽ6ïI©”XèÚLXBµ—E#µ­|Ç•¤öà±Wâñ…ÙÉÑïž›ó{nQ2˜((U,REÓ¡fÅL¢dUcÛáúõÙ-Ò½­/ñãöãáYïëç¿3qcü¯­KÒÁ“Í¢¾ÔÂ›¯_ç4˜ŸØ_R‡GÑòÄÑŽŒâ”(lyùœmhòÒË/I¦0”   ÊÌÝV±Ù×Î^À%1™[3:s	?
*&ÉÒ‰Ç„d$Ñq[qúò+CÈÔ?yôpFß'–¯kjËJÓ©V6áL ¥ðlxeS±Ðä#Ùûhãžrh¤Õ·•ÿ¸r‘Ö6´<ûÍWÌbqêÕ×§ˆ5!ÝÓòÂ…ÿôÍãO<ñÔ_:¿ydã
Bªh8|â•—ßGa¯óõi‘A÷ñù«^¹ÂK¤£OØüDùÚ†ÃÇ»µè´Óão¼¹Æ   6ˆ¤ÕÀóWœyÚj=~L—õÖ*|á¤P4«O]ãQj„‚Rd÷Þñðä¹—‡ø*ÎOÌªÄDT+ê7µg«.i5_m;¾¯ìÙõ©¤®©±nó5ÒêÛ*x\y¨¦š[÷KøêKôÚ+U×8lzª£¥„ùXÕ­-¸v3ýf({‡¦^=}–—€Oô>ÝT¦Ò—ÕuuB©¤0”J  (7 ’
‚ÒËÖë¨gÜwäùÞÇr"MÕuûëÄê!áúI‹QSGeÜÁJÂz†/¨ÝzòD{n¢Ií¾Æ½¸OœÍîã1~ViM*ù&šž
ŠÆƒeÑH…·µêqåCZU%$¸Ç· œ¤	-šS	)¤Sg.ÑX*;ùd#_ñÅEþ¼C©$  €ò")?±ÙÑ\UõOõ÷?Ý(Ò3Jën@]êìµ¹ôpŠMM‡‘FjlI¯‚£/÷?‡•„d_ÛKýÇŠY—Ö5µÔKæúnd¨¤35‹×X¿_™ØxxæZh©®©¹nó{ìÂÛ*æ¸baz^Ä
‰…fh\á@ÄqÛdb¡›bÕè)\´ªŽZÄñ›Wz_Ä3ÜáY{Ÿ×•1‡:Fû®Ò„HµR    €HÊCŒþ¡Íúê¦õÔ7û;òÕ¿Æ3 Q³456–Êb‰‡&_›`p‘ÄÖ•ÅCÞW^ÂCÇ•mg¾y,oñC)¥{¢¹†Ÿw^KÅÜ¦Gã!‚¨Ó¦r•âó3“ô’¢±¹Äm«¨ã
O:ž{Öôç§8›©üfÇù†’4h›Ë:z‡PO>Õi~e‚NJñ…7G_ŸÄž[“¥°âó“ç_øë‰Á+¤åeÏ^>‹kA¡öi|¼Ò¶  ÊOÇqøŸŠŠÔÓ_xûëÝŽ²ÄQ¶¼ì{êÛçc‘Þ|îÅqä£({d_žñ]§q–w÷ùS‡}jxòäµ˜òTÑ6ðícÉáR×ÎŸ<}…!ªö577*$Qú:_œQÑzúo’CÞâ¡Ë/šÏ…[^ú›ÞÜb<ohLÈò‰/†Ba°©Qîãsw¤umÇN¶ñ»Ž„àÙó^AŠÅq5¦%lê(÷ò°*êÈÉn¾Ü@my\ñ7¿óâ™±üê÷5PÔ>EÕRxnrr·°¤Þt¶™×k >ÿ“!ëKXŸUŠ}T}U#YÍ\{ëžá·¦éø·N	/Ì8^°â([>ª}Þyª¥¶ø6ÏÝ—ÐØ‰g/àâ
åžd%÷x4Ì×ô&°Ô~æùãíeÊ   Ò€ bÄBSsÑb®=ðì©SÒÆÞšñ^™áßª¢ZMÇ¿~8å:ÄÂÓÓk˜¾öàÑW^Rœ=÷úÔäÄüÒ_‡ž4›t©ÊËñèì5z©¦)Ï¤¶±ÐìÌPæ{QæF4ñÇx²tÓ}cŽÎÜ±¥0C'^&Œ¬Û*ö¸P~ïÀØ:¢ðô“x_¢hj?j~²¹ÜE€¤{;qŽzôç†.ÍdîPö)³©-é‡Åfgè"çg+¶Íó²¸Òò„¤FYÿHCCcãææu`"  là$•ŒXx–ÆºBZ£¤ö–hÀUl!4¿Xµ·.{uóÖ£gé}ß±6oºÅPÊmÅc7i:a‘Hjë¨ºÚ2Ë£œZÑ¡DÒ}µb_Ýžê-Þ!   `û NRÉ¨V4ì/õ¬tÕµu‰(“…ÙÉÙÅšÆ<>Ri)é¶¤Õ{öï%¶ÒÚº†Ú:    r‘´Y˜›œ[¬Ú×ÜP†™‚Ë¹-    ØN€HÚy Ý2•447î!6rn    ¶“´óˆ/Ð³¡ØGí)Ãèÿ2n    ¶ ’      D€b’      "€H     D     € ’      D ‘      ˆ$      @$     ˆ "	     @I      "€H     D     € ’      D ‘      ˆ$      @$     ˆ "	     @I      "€H     D     € ’      D ‘      ˆ$      vk$Ê~˜z]QQA      ìjÈûÖ´<8I      "TpG$=¡”3”nå{M€“    ÀÝ8I      "€H     D     € ’      D ‘      ˆ$      @$     ˆ "	     @I      "€H     D     € ’      D ‘      ˆ$      @$     ˆ "	     @I      "€H     D     € ’      D ‘      ˆ$      @$     ˆ°ëßøú§¢¢"õßô^çþ
 ÀN‡cüo¸\×þùö–Üw)ÚûÚrûßÙýÙFeQhµÿ??s›”Ë«v‹üÑ÷†ëkÿ¼û‡•ù·™ó¡à?éyŽ¬­•‰}†eà;,AÊI±"~÷k.ß;UŸU=PàÈØ Çå¼ˆÖRË×´ú­!pŸ=etz¦>¬U©>[t#Àï=à$À]Gû½¾`„#ÖÇxv‡Ýá¡Ù¬?DhšI®”¥½N‡Ãîô2Åm…8mÝ]íÝ.šÛa/Úbñkã?tõ¶wttÚ}¬èß#ÁîŽŽŽ.G%Ö	 ãt
·K»h±5®}K`ý¶®N«—•«T$ãèêèuÓë»6~O`™  E/¤7mÝíˆŽ®^›ÓK—è
Xú%ó“K¿["€íˆ$à.ù×dØßÃÞ 	kWç¡:%ì#>[Ç—¾øÅGpëŒ<¾ A(õ&=%Ã
ÃïõúWWDè4útèÅÂÜ»Ýîpû#iï2>§7¢êï6Éý.ïVh»ˆ×Ö‰¥E·3°­•%tbyÝÑióE²þÄÑî®öö^§Ç@ø½nçš„}!n¿wõ—Þïÿæ½	`›¶k^8ä»»}LÎ÷™TªT¥R«DmvtŸr¡‡’Òôš<F<Š.¸\è>&W:ŠÜà76à°ÚÑ€T›útrbg€Ü k·—%dš¾±“jÄCJô ½kÑÅU²#GÁ+uJJ¥\û:¿Û‹vI¥3jä¼odïîõ²jËèˆYMŠ} ‹\—Ûíqû™Ú<2bÑÄ¦¾Žn—ŸIþÁ/Ñ>:í‘ä÷TézÕv!â³÷úYB®³˜7ïXXÆëpxY™F©[¹ùpl„åH•\Ø*)GwtƒÚ
åÉE‚@„àÔÄ…ñ¢{!~%£t-%ã8¥^U’›âÇÎFçýÄÜügøì.ØF€HÊ–2vG0ïß•ƒ©§Ç¨Éè4Ylx?G¨^“çëÃÒ‡Ã!HJ—!’Ö³EþƒA·ÕæŽÈ)JYtþŠž™Öl1iw{è±?$äJbçÀ2~þQY¦Öi×¬Ók÷D8™œRQÅËRŽeV®7÷l¨7Ý0ŒÏáÀ(™ÚdÖ•îœ¡þ-ˆz’Ò¬]w!Ù†¬tµ›Œ|ÓÈ(½¥Çã·úínm¦„EâÈíóx‘˜´:HtIs¨K'7Oè
_G&óMô¼âô'‘©YÖKgX_¨Aðú»Ú]i»&S™úô"_¨0µ¹ú@FòÍ$“‘²•=¡G,9íuyjÅúœN?§ì£¶àMì!“ËvæSK$è,ºò~,Í¿÷3ÿâƒèÏ®~øÅ?ý„„ ¶ ’VG®1èÕ+ÏeL0à÷#øñØÖ GFûµ¥6XÖºEŽÅKÐ„ßG¬zèóê$ÉßÒ¸Œûî6‡cA|3“©õÚuøƒd!öbþ5Ö‹z÷+#Fj‹ÚŠõ»=¸»'Õ%‹®‰¬¿£®Ý‡nô¤˜–ã"R2©«.âwv;hr>po€>‡EJC­ÙÍDî†3€žhK6‹Œ2`™dó;ìí°J,I;;9¯‘«7zíæÛ§$¥7›åiN’Û`3¾‚¤RÉ¸\‘Ç}73å#J"“ú`+¾D¤=	{{¿ôp/Þ•¡¿O¯ÜŠT–øïÕH8)O¸­è´%~ºýÁÕwo~€^Ü¹yé½:>ñ©{`Û "iUÐƒ¯Ù’ÕíaÃÈÖkõÐ´ËjÓŒéK*“Ö¼E©RkÔäZ,t,½ø¯<)_mô,ãŸíÎ[Æ‰3èf¦Ù@g@ªõ†¢?N‡ÏQª(ùµÇxmÖ„ÉúÝŽ¼‹z½àèˆ%ýa˜:°)béá•ÉPÿï÷ú>ý°^¡}¼ìDÁdpÀ/è þ'9Û3ÚˆTjô^šb_Ôáö!ùÎ/ÊxzÛ½2…X—¸TQÊîÑé’ŸF®‡F£ÕézíæyØã(u‰ð¿   IDATj·eJ­Ñ¢Mý5èð»4¥3Y2L.£±?ñé—9`G‘"òU‚á	a°5r­eô§&:H³ØVRî˜oò6ƒ‚”2²äßpnîRô#þÕGWççæ÷~"nÛIëB&Wû,@—›a‘Më6Ý;(¸Eôt80j Öë·êp1„\­¡¶2<TzPÈFÐH*íF4
nš,u±")`÷c‘DÊ·J#±AWo¯`2^¨‰îEJË)2ó´£Î?‚t%êþ…lURc4}6ŸÓÔ"^!g]zÊ‹xœÌM(YUÂ¿Ã£ä=©¿²LÚ0 ™’B»ÈáA>“_-HÊ42Ö—›±ƒUÉ¤Ry6™ÊÃ#ý©p$‡¢Qî€pÜ"9IrRm‚wñYÛ;Ý•ydTÈ“Â©¼½>NÛ?:lÜüä7^çÍ{F^/eýö®vgž}!Õ=}Ú’\¸M)íy^úeÇÚ>Xø™ŸûãÏÞ·mˆ¤u#W!ÓÕÍ°(¸Ã°Fª·nõO¼5@ª4ª»N#ùøXªuä#­Çª,>¶F$E|ƒ½6?î<•†¡Ñ|ÙH_/êã$ uYù¦¨E"¹I§DTIFéŒZ‡ßãqûä“i­±´×í¥¹\·9I‰VGMÏE¦zúVæ4°w´;‚>oP•ßeƒ]Ö e´XŒÊÁV¬Ýfw
ƒõº|FmRÕ°A·ÃáÍÊ”ž“Dê(/’°‘†¢™(Œ«K~kdJQ«ô¹}6«SUâD‘ƒÀö^ °šeœ\$#ØÇ»{Ü$œ\‰´!I©^röøñû8©P­^-M—Ó
ƒA†%å*^‡b¬bAQŒÒB(•_¿_õèqD­Üð¿ýž§~½E¢ˆÛÞOÀ6DÒú!I<b„%X6R¦±"%Û"þžÓ‚Û¢Þ	µðŠûH|?ˆâ6åÓHXbðšS©Þ‚`r3¬½N¬mHmßp_þ|mä!¤‘ÔYYLGfÆ
åä	yÜ.»CŸRêzúRDÄñyiF®.à¶‘*­^E€T™úGt(PElö|q,êýAcI¬ÏÞ™åšÈ5–~‹feèb÷ ¨ Ã—ÔJ­e`À”=öT®º‚£ØO÷¡î€vdl ,ãé:ÔK\Ðmw ¢4˜+»"Sê,ýÆ@§+`·:Ô£›:ûÉ†³:Ÿ“”®è HÅ‰Ÿ\,iK¿ñè|°¥Ti¿½³ÛËR¦ÑQáu:œ._zåRc°èÄ¾–È|µvÛ<éKÛäÈ?6ò*)í]Ög=ÔéJe­½|îœÜ0re`Ã®G_Zø(í÷üïÒ¿Üû©ÏÀö DÒúÁ¹ø_<˜„(¥Ú"
)ðJbƒY;Û¤Õ•1{:BOîò5/,x0^—Û 2•q`ÀXÀÄÕŠ¤ÏÖH?Â=z£ XêM™\cÑxì½.‚Pêµwx¸šÇÇäéÕIJg0 GqVxvÇ£‡Jm†ÎdiŽƒQJ9™oÙ®	)¬tÂlÀÑuÈŸ¥áì£ž³~êQmÂ(þžž¬~Q®íé3ù;A§Ý¥Û\7‰TÌùGÈE|¯—Ž”ÁÜ§ß¶ÃQƒŽöF·J§3èõ:­FY¢Ö¢=Öv ¢P²FƒŒRù{4Çú]‘‘Cæý ÏîN«P	I®ÖëÑcé€×ëì]ûxU@^ÑÍŸ^Š}@ÜùãÇþã¥[ï„ß»ô—O‹§%UÞÿG|±ãS÷@Ù ‘´nðÐx~°¥Vn®É^ê-rèÓÇ÷ª:uwi¤H`E#•/ŒˆN¼D~}9¼8Æ7híuð7z™Ê4<R0×$1µÞ˜«‘ð9¯Oè´÷IJÉzƒøyÜ [O\–¯ßí¤óýYMj±b!)Jé¤¿£«]4ÛœÒäO$S›,™‡-“ãPbB—±(<EànÐ`î1
¥ƒ#¿iÐDŠFç2pÚý$’FüHŽöØm8œ©Ô÷‰“kÍƒ·Ë@Q;ýR£W#ôºlèÝË´F³Ù¤ßøÃ2se”a`¨Ï¼T8³×ÞÝípŒgÐaÐ¦{>Ÿ½›WHè#ýi!úh­«Û•yÅ’ÚþŸþ²ŸØÛQtý6pexMçx×ýš(Àñ¿)®Šæù7Bób ÿ¯s(¤2"i}ðwMÞ€ÅÝ‡ˆÒ@K;ˆ²ê‹†zÜ~Ž_ÏædíDüÎÁ€Ê’Y5£]]‡¬¾…	‘¥.–Ù»²ˆßÃk¤´¤‘t°˜áÖ…UdT€úÂ¸{Ñ®œ8ÚÛíAbÈÔ}c£Y…pEÆDíbRcê)ìûGø
9ø†o2äŒþg°¶$ÕšÜHŒRß?Jª½µ~#—
ê¼ú2º60Øk÷§º¹n`lLçt¹ý"“<jSŸE›çœ!E„|ƒG®DúÅbÖc¢½i}#–¢N¢ëŸa™J¯%}ö^ÆØg^Ù$ãéíd(ä‹h|nŽ$J”ñƒNð 3¢·ÊçX®(O™cY‚Ì»à¦\ŸPêŒÎï‚bísö¢%úÊMë2ù’ûó˜€#¡CýÌ¡^/‹´ºÑ$/Aô¼0ÈqÀáéþ‹	ñêóûV‚k%âžúžGmzäOÿ)ð6±.îU;š:Ö‚B*;[ ’nÿ/úû~‘WÜWµû_Ö[‰P„(²RµÝ1½^!¹wO=ºR?8nî‘„qã;"ºWmFD
'ÈtÛ¼¬šÔdŽ1/‘d¬M§Í¹a³~{g—#ÀRÆÕ*[á.ÿÃ)èPËË'¡àeù¼8NØAèY­ž°‚/ ¤2˜sÏ:êúGä4¡BÉ­÷NiMfbCð%*2LË«ÌP|>·ÇÏ*cš€@É\\yÏ9I$
döL)gì]¡Ók’á¬íóiÔ´–þá÷%Š¶²Wo¯ÃO(õH>™ñö¶÷(c_ŽöÚ=¬v`t@¿-.Ä5õ›úû#A¿×ír{¼B…<NÒã´«õ£É´­$WksãÁ2ZÖ^/‹dXƒ’_ ?%òÉï”Þ$vÜ¬jV÷k¨cþšŸôL½6òÑÚ>¹ÿÁ¯}ÿc#Þ¶„²Š¤ø›×÷-Ûèo×ô©êcÏÝûoÖ6¾½´]½.‘÷qY¶>ƒø=÷ç«À½ùæå\ÿ‹#â´{ø ‹ÎPêI‡ÞiÅA9©Ì¼Õ ˜ŠÖˆ67rÿ‘¾wF¾„$¢H\ÀÒ.Û ®LBnú›¿ Š&âwóSÈÔº<»'G7c“vcGž'ÂºfL¥p”fõyð²¸ #º*{L¢NœL©ÖæObÁ“ÉÚÜY§ñ1ÓîÞö@š.ñónäHãLÕaéŒµá	n]ŒŠÐ4Ûtº|Õ*¬¤4J·Û‡Xt‚-‘È…GE’%z$ˆøÝ]¼Ï¦”“yS±<6›‡aÑ®Ð•%#åØpb<Ö^55l½ulÆõ¹*ÈÔûÐ
‘û<n—'áæt ­ä*]IkRI¡;Hà	8=¾ºÐùZ±bã6OÖ>öýÇ¨?™v}wþƒb>P¹ç™ýæÁ½{¡¼ä–Q>‘t{þWœzŽˆ¯9Þ;ÿ-b×î{Û'¶
9¥JËÇ•¡¯J­Öêt…’I\/Ï¸Ÿç–•~‹ÅñÚø˜‚\o6•ØæI áÉ²€ÑdŸ†ØD"/?k 2tÄnÙÈ‚ë7û;ÚewèÕ}¥†„œ{ŸéƒŒ¹<ÁKäú›ûtDé‘QšbIŸÙ-×õX´ëÉçåX:(!Ïý!£N’œw‰ÄT§›X+lÅ¼\A¥ßX†:üÈn9r’‚èpH¿Œ~IC®6êUn
1zô„-0œŸ'¼»ž½ =Ö®^7Í‡ÃQ°(ûÃ?ëà²Úžž‚3*¢‹¯oÀL£ËÞo·¹5bnÚõY2¹ZgB?– RÊ6~"©T–,ÍÝ<I~¬š0êE&EÅ'U†äÓ–(rÉÞŽ&›fÏî\ýÙBÞO¶|¿éß?³”l-åIÿ7Ï:’À‡¯wnß«ÿ·Ä Óô—w¢‰MÚ"2°º{y‰¤Ô[,%âI,z»í|
1©6[Ê_®Žñ{°Fâ'?4Rm¶½N:è²»£æ×þãø!O|¦Ù¬Ý–ã‰">[¯Û–yrŽ‹T™†FuéÖg"§(Ç/’¦sX-')<&Îápz||†’—U©FÍêÍþf)÷Ù»Rsü¤÷×¤Ê`Ôº¬>—+ ïSÑÂ¬t8«½4iH©<3¹Ö2<”ÏLAV“ÝÍK$KŸaÕûÙû:œA$“\šm;ß3©ÒYF´¦€Çí%´¥¼}$ô§âç‰Ë7*YÉg•—î¼÷G
ç[d÷¿ºðÇßG [L$æb½Üsèñ¥¿ÿÄòòÖÆÝv2Øžïæ»%~ÎÀaƒn»Õæâoí%ˆ®&ÐHúÙè8¡Kïíö0—ÃgÒmìVŒçvç›T®ëC½û¤ºº\A¬»ó»E€}Ñ	añ£>Ë§M¯._VËIJÀ±Èt¸:JÙÇQ@=žÓy“V$£&¡ëäh—Õêa(ãÊDµ2yZáVç4éH$ºÔú ®N¥Ô›7žÜñ»lÖDÍRmDß¢|™ò8öÙ"ÕÛo(jÃ¤¦Çbòu:i?žSxxË¦\\Ê\ÚY€…²L+óëâWBÑÉÄ4#[Åÿð››á¿uó¿}ðAÇ}©½Õ”1'éÎ2±^***È¿´-<ûµŠ]»ïÙÂ¸Ûû<Ö^;Ÿ9ƒžý‡,%JFÂáÁÁA~ÞAÂUÛ¶æŒ½‡„T¸ª\g6ª½ö ãu¸šä=`_®Û‰2öÙY•6àä@ã“>0`RorM°ZNRÚÕÕ!¤âÉU:^o0è7)gÏUÊÉU©˜I©5‰iv92@\­Y£×½¤ÆdÖy{‘ëƒê Ñ´¡ïS$àv:œ>!š)ç]jóffaanó%b§Å«^2Q£€ñ¡ï«v›¤p—\;\K4j2 ‡'qáˆ-ûâ~L¾W¸" {õ]zþ/ì!v,ËËË!w;uJ%êÄ‰I9·×/’P[#sô“/Ÿ_0ý
:©xÒa(ê14Ò_‚$žÎÝØWJÛ(k[pn¶tH1‰c†F·Jš¡ÑC%Ëi&\À1èÕ¯KÝà|]V>qD®E¢SWöØâjp´×Þ+($tÒ‡û·¸W,2'I©ÑëZíf—|Roþ   IDAT›âhgw‡‡Dqá>-É­KÕ»™»àë,R•·d”ÎdPyñ\Â25²&×ÙÏòAEô-ò'G›#44`. ¸"~{WæØî_›,×š{´Þ^_×ÒôÿžÌÞÆaƒ™?µ¸WÊDÎ`€!è`€á4[ä ¿ýû««Yï¼÷÷ÿðñþd‡æl3sú¯lï¼ƒ#HŸýÌgl§Ï<ðÀÄd'ÕIªü´â“/F=E€N*6ˆ”Ì Ó+(ReìêÛhg‰§vt$gå×k°ôY¶rT	úœ.?ÿ¸H\ƒ4‰ /(òÒQü†¡™¼“8øN¿®_»¦þëC»ÕêæÝ3Rc2ª¶Î¢ƒ/1i&á@áÅá¡ž­1&×2Gu³4ž¥9k)•Þ2 2É	KÙ4×g5ðd\A‚*8!
Ç²,Áb'K­ËZ"}î6Oß†¼­¤HB_,¡ŠŸÒŽ£9kLnÁG¾EžàJ¡(‹V¹”d$E)eˆºg`íIHÚ™ª 2êwè”ÖÈ¹Fj=‰#©ÙÁâ°:_kUcXÉTD>%£È ›®ý™U›œ¥Iáƒxw.#ÿd÷Ã–ýk¢—ŽÞx{%Çýã¥èòÀŒ¸---Yû¾ñ«_ýJøõ—ï¼óÓ§†*++‰Æ+&¹ûay²ýëSè¤ü°Œßãt £'Ò ôý}Æ…."þÁîv<vvEr*½Ñ\lš’“˜5óÍ¬®-™R¥á§»T*U”œqZ‘»F{œ“¦ØÜµÙíƒIÑ©6ôé¶U˜{ƒ6[BÁ¡ðçÐº{iàñSSFN’—Ÿ@£˜³´«EáôÈ?$…ƒŸÏëõ	•|Í£}þU¢ODp*IÊd8¯yxTŸº¬Er’ˆô„tÞ™^”’¥iww·|¸Ø
ŒÇÖéE".-ÖXD-ðèd`LmdHÕºì¤éÇ~Ú'Ûâ‚#Š-@–ù1ÚÕÝaå§¸Íc£¹%ØäV}ýCâ>ÇÝ¶ÞÄÓeD·©•ãF’Ô¨öØ|e•|(éÞ¥&AÞô\¥oŽ¾·R	ç3Ÿ6|_­k¹GB(Oh>í=pÿ×Ä°ÿí×7ßàóŸ$vo¿ývJ!	 Kéç?ÿùÃ?Lì4v^ÅmÙÿùÿ¾÷ÿü»¿y	Eïî”o"Ø\¾%#Sézz,¦õ÷å\„¢S´?5ÚG®ÖL&“nKçÆ%ñ<iD0Ý&’á‰æI9rÓ)¹ŒDZ—™!Ñ›¹ª±dÀãsYŸËÔ¯Ró’‹Ð¿×åXyò—kÌý=ÛB$àGË£=*pãŠýý«V˜Ü`×‡ÃI¶½;
¡bC°ÞÞ/5fõºrµ†ŸË=ññÜ™X¦Â@óÌ¬ôB9I‘€JÅ>e±¨‚öÎ.WÀÑÙî3ä×9‚(ã'IIix¥—MDÇÚ¾EB½¦àãHab;<¶½p–‹l+ÇmD{ ƒ0y[£ƒA–Sg§er.Ûå¶òw"‚ô²°=¹®?[Ä¢'‹ÉÛép¬ßÞqÀŸ›ðÔmA´	¥ÕQ~ï¦
¥÷?øY2ÖöGþ•ùE%ed¢íRKý…iGÏüo9qûÙ?|üù–»£JÒí;·‰ÈŽœ–¤ª«ûÖÏçbó:)<HG¹q¨ÝƒñœHÚZë$¥ÓiÜ>áG¥ÕiõzƒN»-ìz¡°¨99¥ÖhÖ<—z&7h\6?Šœ¸ü&u¤"±uv{<ž…ÝÒcØ>Ó#]Œ¢)ÓŸ
«g#³;dÂú½vo®ã“§˜$¿J½¥õ4ñŒ>Hè¼Y±éáñ·¸Z#…/:t´N»Ãã¤•/âx¹Ã×ÓkÕ*UBsÁ€çEx6{D¯¡R{‰×1ˆÐ£§øÓˆ“¼¬V'_ˆKÎÏƒƒ˜Ê¾‘!¢«×t÷~Éï±ôõåy!qx»|N:úŸ®4yW\p°£=kr<¹Õn‘8¬ ’8v=3·’“n†œ¡»¼)ýÐŽvºüÁT¸L®5ˆ>- WmdŒJXMlr’dtOéë³h	W§/ 6k{HõÁMÿþOïÏåuôm-ÿë?wüÓµéæF?ø åžqCŽÑ…b>¼:|ðÁÿð_þ!±)£Hª(Y0²¢²ò~Û@ôÏ;‘NªØµëž?Ñ› ©¶\ù¥…X2Ê4ú¶i•…äyj:¯k‹Y«PF•þ¥-U?.×X†Ç4þˆR£Ù^©xJw³–X'2JgÔye2½i•±I¸´õ0Kôº8É\:õQ*6u~¿“¡ôH¾K<.ªä‹e“ÄDTyëÑn›K>}]­‰"ÕÈ·Á3ë2îÎÆ<éÝ”FèèdòbøÉ„I
ic•Z£åå†ÈÜèkŸPÃ9wE
¹WOÇm¬6·Pã‚B
ÐbL}¹"ìQR½]6R«]_r ¾w8wÌ$Š7öŒéÅÏ!\JH%º"étÉIéšm™–b–j5+S®kçPKýX…<$¥5ß8^ãô,Èiâ54_„7ÿã^^Ñ[hšŠàÝKÜâÌcoçÎÄ#ÛøíYàã›—¢KÚÏ½ð¯þ¸~W¾…î«ÿÜÓþO}þ/§þóÕwo¾¯Øi7‰DòW¶¿:m;óî»ï¢_?÷¹ÏõY_Ü½{Gš2eÜéå;Dé¨¨ªºÿåsÑgžŒÿÉMI;ÛÅD))ý·JýÀhqRžCWŒÄ6×T{³‡ 7£4ž\Û3<j\[äA¦Ì—¥†k³W"‘O«&„,ñTìÃœÃátûE*`a2˜-Y®6šÍj¹N§[Ul •QHây½Þ`új‘$Së†UjE¬€£rÈû@j´Ç"¦’åjÓð˜Êi·9¼¬Ö˜7EOŽŒX¢äðOeF–A]=oÃ¡0!µMgv‘ëúF6Ôxj˜ü%æ“Ñ<YZ=‡"Ö‰L^Þ›ÛÇw$-û¿ñ×ŸúÔª1´{>ñÇƒÿšòÿú½÷oŸÜEì0>û™Ï8ÿÓ÷B¡På®Jåƒ;¸@…pi	:ŒôãÉ÷:÷×Â°¶ç¹¿û1±.$jÉþGrß_úŸ3•ÕŸ û^&  (	Èä	2èy_¥.ÅÔüÚ82õôÏFÐoÊmÚópügù,Ë®>5ž™$·ñ¡Ý•ðs{YBi¹²Æ¡ª P;£NÒÒL ýˆþIö¯¿D  P*rªk—tm¤|[NÞ’/³ß,jòZ	]4 ÜmìÈ!     Û¨¸     TÜ   €­…¤=f*B*5ÛpþÄß[ â6    l9¤JgRmÂ¸A`#@Åm     €bÙ¡·wž÷    À¶E¨¸þÎÎ­¸"	    €’!TÜFÂHøõsŸûÜ7ÏØ âöjˆMKRqÿ'wæsDqÜzç-ð~Î*vêÀB    ¸+¹k*noñ´$Òý_/›µý%÷wÿ=gµë¯Q	    ÀfPYYù™Ï|†Øá@¸     @Ý    @‰ŠÛkD,')>ýÖûÿá¢8n½ó¿ÄV9I    °€ŠÛkG,'iùƒ÷óÍ\[ôj!'	    ¶wSÅmÈI     dä«¸Mì@ '	    €Íe‡VÜÞb‘$û×_ÊWàö/o,<ÝA EÿÅÇ½ÿÊ·Î=ÝPMl.åÜ   °Ã*nÏ‡Ã©wvnÅmp’òŸŸõÿp|âÚ\t	ÿ^Ci˜Ÿiß_›»hì?øÁÄÔì,•*¨úF]û“‡›öH³Ö·@_›»|åêL˜__•²¹í¨ùÉÙË%žö¾~ñGoÍÎ…‰*¼Î¦ÖöŽJ\•ÄÃ3×CKŠ¶ƒu9_˜ýÑÄdˆ tm-{«ìô„ïZXz ­í€BJ g[k:®1úêeßì±gõ-–žøüäøå™ùxÞ¤”¶ýËµ¢}sbüÍP¼–ÒnÍ>ñð›—_¿ä™¥ÃñeÃþ¦¶#O¶<TXIÆf4~•Žfn¾fE5î¯§jA… °Ó*nŸ¶y÷Ýw	¾âvŸõE¨¸}Wy_yñìÕpú{QÚw¡—½ô­c2zÏØ´óäóo P£PÖaú­	Ç[S³'^9ÙZ—ìüQ¯|áôËãôRÚç™É‹/ÒáçN´dé‰ì­/âu¢Ÿ«o:¢9·ëŽ‡§¯ÑKŠÖæ”FŠ/ÌþdbbòúÔ4YH¸4Q­-{‰ÜOÎ¿é¿zufzfŽW85‹­…%Kæ¶Öv\émöÚË/áÁ¡Šøj[Üâ¡ëo¼1Í¿€¤^¡{¬¡6g·â7'†ú‡¦P“JëšµTµ4}¥ÞþçÎN¢µJuJIŒ™¹ÊÌ\›£Ï~ó©Ž[<:‡tÕÜ’èkšž8q|5¹	  °Ý€ŠÛkG,­ýÎÑ¥é·D¿þ5±ÞÕn”Øì÷^ÆE¢|ô+GÚZÙÿPulÖç|åüDˆå»žoI)•Ø”ó¬-'úyU´0í|ùÔÅ™«.4ÕŸú2/“âôx?¯$<ÞÑ®kBAœ¾vñü¹KsaßùsÔ™¶º•~paòÜ^!UÕ·u{»G±_\užºJÔT‰îïÂÌu¤[ZšWŒ…·^?÷·SK«(¯¿8·êry¶µ¶ãJkßé×Ïó
i«¨nxâÔ@k®‘_˜|ý¤Xº¯iEö<ºêpb…$’HCç‘BªªâLßSÈnL(Ý¹‹¯¼ÞtþèþÕ,!EK÷Q]]¿Ñx<}süõ±·ÂS_|!öÍóÇ€£ ÀÎâî¨¸]>‘´kÏƒ¹o.Mÿýû'ÌÄØõ/”DÉ©¦?ÑJû¨§O´'=€Ú†Öc§ãÑcCSÑÉñÉPóa¡Ï_Ãz¦îñÇ¾Qíþ'=M?÷ÖÔã³ùÞQZ§íh›º,}ü¤)eBQ=EDŸ}ÉŸ¼Ùzä¡DŸ›¾øª	eÛéþ£KõC-Ç†šMqiµˆæ@‘²ÉÙEEóÁ}+=éžÖþ+­üêfÎ}n<œç@¥Tû§¿Z˜<õgg&‰UÈÜÖZŽk…ØÌŽK –®O†‰-¡º®a]Î»o^GŠQÙÖýä¿nþ'N$‘jµu´o&KaÅæ.Î,!Çîé_²Ò:Ýñ4ýÜ¥ÐÄÅkí_^Å-«¢ê›P+Ëhn>è<Ù{ñFÈ7þæ‘)    Ì”¯€¬åÿ$6{k%JtoKïÙSí™QéÞ¦Ödå,…Mt‘ñðŒw)‹}ºÖ´e¥uÍ­MhÁÐÔd(&ZSTO   IDAT¼S{ðhÿË¦¬ž·ö@kSú7<›XŒÀFÍe’H5:Ó“³ºUQ…„ˆÎMÎ-ÖÔ7SµÄ¦“µ­â+ElvôÂMÔ<¢SHˆí’§œ¤žè}ºI¤)Q`ñÕëQI}ûWsý¼}õz‡ÇÚšÓ¨êz]ó>œ¾:³@¬™ê†Ö¶zÔD‹!z!F    e§|"i÷C?—m‘ÜÿÍÿ¸ëseCRÅ'ÒÆ—’6B,<B±*ECC¦ ©¦)Ô½…çèh¼Ð
«jxiµD$^sW§£h…M-ÅJž…Ù«Ó‹UÖ—A#»­œãJ›½xþ‡7–$õGŽ4VÛ‰8=ñ½q†¨Ñ~õq±¢ðÕW¿{=J({Åá–²Oj<Jß@2HR×¸/£e¤ŠõÈZ
Í„Ö'sø«
©cHJ  ØÊš¸-û×_ªý¾ûÎo#D)¨üôž]<H”‘øRt;55Bê…Â1œ§»/Ûô©©««%fÂQì9ÕåíáâQüq¢ZQ›ñ…¹NüU6Ö›„²0{}n±ª±¥¡,©¸måW‚=þ1,‘žên}¨*ä%6Qøêø\\ÑÜajßpê÷Â›—Þ@‘6ªUtôâÂ5§ó*’HÚ£O5ÕÆ}9ÏÓx¤¢.û´Ik(ä—…ÂáXœ¨^Û>ÆoNNÌ.’}MM
I   [@¹G·íRîE?ÄŽ$>?å›Å]!µ?Ùi-Fð ¶šÚìG}iõ¬bÑ…NÒÂ¬oI¢ª})I‹Fñ«Z…¢zaöGß¸|}†&©{¤ý©c_=˜ÛY.ÐWg¢UõÍ5Ä¦Sì¶r‹'N_úÁ’H_?v'£ 6ë<y‚QHÌÍÍÌÐ/¾°‘¬xhò‡HIsGõ8_Ëéð…‘D2›šk¥Ä¼Èçù€XÕžªœë ¦½µ}¸ñšEÃ³¾±Ñ	ÜZGº<iÛ   [” (Ô‘NLcôˆ6é¤Äãq>ž$•äˆ)/›âñ¥xþõM]žBZ¢æ€v%´‹áå¥K“¯üÅ¥É°¤FYG)£7™hè­‹§Ÿ‹žþVïÁL)»q}6*¡šEF«—œ"·%z\¸åø…×p§o:ÞFm´ÓGggn¬„ò¢3“ôÂcŠu{iñù,«ÍÈ(J°ðæ«ß5-Ï=˜O‡-¡+IY©Tì:À")_Œ'‚gy¸ñêŸë_ÍzO¡íí;öe
$  ÀÖ "©Hâ7'œ£sxøÒWÛÓ†sÊ8ÂiÉ‚J’víõ×§	I}û‘œÊG¡‰K!…öø·yÇ…/Qø½3gÆnLœ»ÐD=Ÿ^|(FON…%eˆÇ·­<Ç§½Ž×gø@[[I|©TB¬d<I¥ÉÚ‰/ðI²¿97×jáÍ×‡¼ØE:fnÎ+Â
æñ,­Ze¡ŠÒ¶4Ö$RÙã‹ÌÜÔ4öôÌyÛLÇM-{!à  Pv@$E<ä=‡äT5™Ž>¶b6Hù®:=“;í#Ødª’Š˜L˜y!‚#©ÿÚ3méƒäßP¶bÓ(Ù)K¾v´mê¹K¡©ñéhóž”J‰…®Í„%T{Y4RQÛ?.Ô|NçÌb2Ð¶q¤Š¦CÍŠŸP@ ª±ípýV»F#T`_¶Z˜zÝq%hË¿)‘ô‹r®ƒÄ[ÉjgHÑòÄÑ*c©…ÙËçlC“—^~Iª0Á0   åDRÄf_;{aI¤GLæÖŒxŒ'œ Y,g¸SLHFª®s‘èË¯ù¢¨[~òèáŒ¾O*,_×Ô–•>\­lj¨¹„SUÂñTÅXhrŠ‘ìýzãžrh¤Õ·•ï¸æ}¾÷Ö"ÒÕŒ÷µ©¥Ã3Xã,ÌŒ;¿3Uµ	æâCº§å…TëÕ‰™Ø¾fÝÆ¢ô½Èg]gé?\­`<„ÇêoŽ9¦“o/ÞàŒ‡|o8CU5Ôcmº	_žaqq!'¨_\ÄÙHÒZÉÚ÷°¶áðñî™Y›ãÍÇO?V†Ô|    I«ýèì<ð©æÑãÇt™Qi­BQEÜX‡¢1bOºÚÁo8Ç[‘ýüOž{yK®Æ§NfUb"ªu’+x0TœhÈø‹´WÛŽÆWÌŠxèúTHRw¤±nó5ÒêÛÊ\±(â«T†§&.Men)t‘šÅF]óÚÄ^uÝÃ¦ÄF‰/„h~*‘úìc[Ñ¼Uµ8çŸËù\tÆ{i	Â=Í-
…¢½ãüíŒdõhhžŸ¯F±>W]×X'ñEñžÄƒ‰Ü   Êˆ¤‚ …ô²uà:2Göy¾÷±œHSuÝþ:Éõ9¾RCÚo\?	é‚š:ª&ÓW@JÂz†/¨ÝzòD{n¢Ií¾Æ½ÄšÇZ2úÄxŒŸeWZ“J¾‰‡¦§Bžh¶©ð¶
W5Õ~j 9{tW<vãòÐ…ÉhUÓ×{¿ÒPU]³UùÉ±ð¬g«Šì¼¦š¦ãßúV4;›(>ÕyîÊ%e[ïQížªjì?IkêjˆQz·4µÓsa,À×{tÈ‰âÏ;”J  (? ’òƒ¢-6ë«Ø©êÔ©ŽF‘nNZ×x°Ž˜»1{mnápÝžÔ'C“Ó¸þrcKzìŠFÙ†&ÃHqµ9uô€ØP)i]SKýè¹ë?ºñÕ†´-Æ˜©Y¼ÆúýÊÄ›(Zu-´T×Ö\·ùâ¢ð¶V=.).šû1é›Ø«VP·Î#‰/†…¢N5ÕÙ;!­ÝÛP›[¯B¾D\¹!©Ý×Ð¸?)k´5>_xf2Û¿b£Egq¶±ïà:³Æb´ï*MˆT+   Ê@ù*nï0bô…$©ê›ýùê_ãHê%ÄÒÔØØtÒ+‰‡&_›@¦Š¢¹uåcüt§XIÊ¶3ß<–·ø¡”Ò=Ñ\C„ÇÏ;¯…“‘µ…éQ'ŽIÕiS¹Jñù™IzIÑØ\¦Ð¶Š=®mK<6[ÂÙe5jÈÚúV¬™‰Ñë¡T=öé±7ð@¿F±Ê«îWxöòY\
í[ããm¶  P~ÀIç!Y3|"ÍÒÜ«ño³Øûžúö9~,’t¯öIÝø‹ã7.=ÿç7{d_žñ]§q–÷_I$(õâÙÉ(ÿšáßg¯OÑ6ðícBiÚæ£ÇÑ§¯ŒŸ~v¶¹¹Q!‰Ò×ùâŒŠÖ§žØ¿â#ùf—-•Õb»ï=74Fó¢-¾Ây5‹SŽçÌoàžZZ×vìd?Œ
	Á³ç½‚‹GoâÃþdè8Í'WQGNvóå
lk-ÇµM‰ÇñK¥Õ’é»ÚýíO4]šš<{üØdK£‚ˆÞ˜º†SÓ•Hõ1€ÿÆ¨Í|5YÉ=‡q>Eó3Ïo§ÀG  (? ’Äˆ…¦æ¢Å.\{àÙS§¤.Œ½5ã½2Ã¿UEµšŽýpªkŒ…§§×0Ý}íÁ£¯¼¤8{îõ©É‰ø‰¢ñÐ“f“.Ua(ÎÇqjšòLjÍÎÜe¾enDŒ'‡`-Ð7æèÌ[
3tâeÂÈ*°­5×ödQš(•lP‡Hj;ñ2á<çœ ¯m(Q4yæè×šŠ–¶¸ÒòÈ×RÖ?ÒÐÐØx ¹ù@˜H   [CÇqøŸŠŠÔÓ_xûëï9±ð,u…´FIí-QìØBh~±jo]öêæ'¬GÏÒú¾cmÞôqáåÜÖÝ@|átg[W)(j  €8I%£ZÑ°Ó‡‰¯³¶î!±ùVg'gkóøH¥¥œÛº+Ö>Ô m  p7 "i²079·Xµ¯¹¡Ôšl‹·    Û	I;¤[¦£’†æÆ=Ä¦SÎm   À¶r’vñz–!ûÊ‘ðRÎm   À¶D     €PL     @I      "€H     D     € ’      D ‘      ˆ$      @$     ˆ "	     @I      "€H     D     € ’      D ‘      ˆ$      @$     ˆ "	     @I      "€H     D     € ’      D ‘      ÂnbDÙS¯+**     €@yßš–'	     @„
Žãˆ¤'”r†Ò-¢|¯	p’     ¸{'	     @I      "€H     D     € ’      D ‘      ˆ$      @$     ˆ "	     @I      "€H     D     € ’      D ‘      ˆ$      @$     ˆ "	     @I      "€H     D     € ’      D ‘      ˆ$      v   ¿'p´×åp*½Q¯"	  €Â€H `àŸËåkƒTjô­R–µ.$}\´ÒdÒeÿ%6èv8¼¬F©Ó©ÈU–%röØÝÛ9ÈPÆž~³VŽÖåwôÚ½,eìï7Pk\×zˆ íÙ|TßPŸFN ë€¥ÁGÈä*5Ub‰üñ?ý¸þO÷À]ˆ$  ¶ –ö8î!§TÊbä
ÇÒAš%”¬JŸ%’8Æë°ÛÜ4áö÷™‡ˆ‹Ñâ\öZ"Á‹×øý‘Ü­Ê”*µ2O÷É=./Ír™\Xm"D8GlŽv[­.š#5=­<ãè|NÛ ²õ¨4*Ù ÓîÔXÔ%èã9Úch-–U¥%‚eAéR©Rå_<ôÓ¤Z£,ƒd\ÿ`W—]r†á+º’
Í¥¹w½ƒ¿#þDñÇ{àîDÒêD‚§ÃéòXþW¹J§Ó#´šœ»)p£Û([hed>£õ~¯×ëñx}AþñZ¦TkµhK:­†*y`€‹øÝnÃ¢'s­Á ‘ç»©mäˆÐùøãñ¾‘«´ZÔp:: ÒßE9Æ¶†6ç0üó¨7õ:UÎýp›Ÿ©Äî‘”Ö Ïr’û“8—¤JgÔ¯Úy¢>×íñã’kFm‰·aÁÝØàY–iÌÃ#¦b|ÖokïpÒbëPêFI²³Ëé³utE††zÊ@¢£ÝÈ·² «·Ó%ò¾Ü0re@+Ö}b­âp„Ò`Ò«6C pre¶â’Éd:à÷ºõ#f³ÅèítÚ\ºóeRHÖ®n ìSã   IDAT¤,]AvxtUÉÀÒ.k‡#HÈÔ}WFsÎÇ¢;	IÝ¨ÍU–ÑïÝNãöÍÿöëÓÿÌÿñ?~ÜU€H*6¸{í>&ã½ ×…~ì2µih(ã!Œ‹ÜƒÁçJ™VÄèç‚îÞ®^“ù&ðºxC*ãÀpŸ¾”Ogè¹Õn³ðžÊü„zÔœç¶¿Þ#â­«×Ìü`$ès£;¡Ô÷K×ÕD.»mÐÈŒÝ$pPmèëï3¤ëm~¦R»§"4úUC+8neÇýŽ!©W&ØÀ±»ùðrª±>M¾Þ,âsØl¾IóìF)Î2‡€˜§“»ãA¦€¨•kûF†‰®.§ßÑÕMŽ›ä£Å¬aùM½Cjzµ<ë2õ¹<AN¾òy>™‹îƒ¨1d“I»±.™Œ”¡=à$Ên¹Æl1x»ÜN‡W? 3õh=ÝN‡O?´ëŒ8º;í>Ü:2•üªŸ‘r’ß?9™µƒlÀÙÝegtÃ£ýZcÉÓé´9Ô#Íï‘Lúøƒ¹K1ô/=úÞ+!âvw")?\ßI=)ò°uÄ2A¾]sgW'1*ÚÍä!”<ë,zªí²zý.—«U”œÃ
Ð~?\Ý1Ü¯/UâGû¼¸kC,ôúh“j5Å²†#J=£&?©Va‡>B£àÎ ÆcE4‚zÐßHQ¬ÅÞÝípé›£(9ºï3¨sô3Ø–éí–ÉÑ=\¾¡ãÚŠ3µ©Ð>OÕäéÌ¿§`ÂP©Î2ÇÓY;r­e ?ÒÙëñÛ»Õc}³ZøCÄÇ|.†SzLé×9°ùœ¡Ô™-}…•’H6:TÊØc(^Ûs—+yò-›øW&v1Êµæ¯×3è4jû´}Ãc¥^¿BBƒTŸWHjÓð°¥(Õ—WÄ‘*ò#m.Û }ÅÌ}&_‡ÃaÔŽöýÞÈ¤¥¹ùŸý~Áúçéyå âvW")/Œ×áä©µŒ˜ÓŸ¶ð“>rb|2ƒø•a`Ì¢.ê.ñÙ»ý.ºÉ˜ÓŸØÑ“î`o·ÝÏhwo/¥,‘‹ÐHrÙÀ:þbTRñG„,{{…¾“Ô˜z2"~go·Í!"^k·]).\Ö ~ŒM*$Jß?ÐgÌx*îëG{ÓmPF£øÓò6?S›ˆŒ¿û¦;(véÆ	õˆœ˜ßVº³LŠóÞØ ÓjóLô–Q†¾¾ ëQš3š™JFÛæwºüú´]Añ3O}»Í¦UÌ:ä©YqœO©ï1{Z±†ì¶Ñä,w‰#EÅ»¢ªJ†®íaÒH¨Ñæe¤ZM¬Ž?3vßŠØéë/ÁsŠLeè3{ÚíîA·AmÖ˜,O§ÛïF4¿'ùå·}5üká[òÎ{?û‡¿ð'›q»}û¶çòåé™©Túõ}Y÷eØ|@$å#BûøðLÓÓgÊêaerµq`Ì°†Å< ¾Æîº.0e)™Rk&:;Ž8ìÝˆqãEB#‘j½Nñ9ýþâ¼¤"×í±;„0žÚŒR³&åÓðÑÕaCV?í²;ê¤ "çÆ6(lMeFÝQNgK¢GåpÂÄnKÎÔ&¢Ôhe>í÷"ÚÜ|¤ùp
ž\£U½œ(W	Ï²YPMQ9I„­cµÑpr]ßˆ.ù‰TâÇGê*´ùW‡,TL¤dA÷ =±ÛFcî%ñÚøT(µÑ¬+Ê¿YQÌíp4éÁltYížˆLIQ&zîâÃ–(r<h÷æ¤ü04ÍÊµFJMÈ×{Uñ{ÕëìA¥®o¨ß¤.‘ˆ!U³ÞÕåFIÝ°QcëQæÍ¿ëø8ö—Ø[‰_¸¹ÑèòÀ&DÜB:ö=ÿüÏÿ,üúßòßA$•Iùà",ß9ò|co6ªø»¸7È¯JÓcÉ“½Ô-ØÁ"/-bÜjR#it*%ÅiU2 d*‰zÝ¼O¨L³¸ÝŽôŒ¥Çë·!—-4«×íÊ³\ò†_¥©ß¢Í×ÉÈß®·äLm*¤F«òÓA$†"ºl•ÄáXÒHz-É}.åYæüŽ®vO‘£Ûhb-pXp8iFãs9}YKÑ^—#ñRÑèµÊŒ¶ÀiWVAËŠÚX?öZ¼°ÄJd [Nãqu†ø}¢ŸøÝNžuûýye}âEŠ­]V!—N©CaIsi]¹¶§ÏB±jü%Äqèµ|V7G*Õ‰Qs\$è÷ùQÈ–DJ^—Wdi?º]Ñ4'SkºÂ.K£Ào Œ°hÝZ=Î/”mÂ€‘¥_†ÿ>íŒ±Wß½ùþŸÿ$Qj<ã—S
	(' ’òAÊñ@_=Úù‚Ý¦$k²¯Ÿ¿ÿ*õ…FÌj£IëêEOåxy“zcb†ú¼¨ã@7tû‘qè)ž@·¯/hRm¸SÇç|§JêLÆü‘L…Ž×éw3r2ògÅ¬ŠyúÍW|d}lÉ™ÚTP´U«W¹Píö3:½2ão4’N¡Ôê(Â™ûÑe©RkÔ‘¢‡ÏËH
Å™³Æ¯¢Ê<6šfd1ž®öî vd,mÇ¸»õæª–ˆ°;;K¿ H”ÙzmrûûM¹Â]&×èM$!ËñŠ‚^—Áö®Ad =‹¤œ—F~è:QôY‡Íæð%R)¤4dJÙL¬‡H`°»ÓÍ(#cý*Úe³Ù=iCÐ“Çð):>R˜>ˆÆnCQïá!Ñ¸6NLïÎpc·âÔ3Qòoäí÷®ÎßL¿ˆQÄÍÿñçKqû§ú'Ø
@$åƒTi5JºÅ3®Þ^å€Øoƒ §¢€Ðó®2¾D¦Ô4¤ÏËA_€1©6Çáh¿÷pøèd8ÙA«¡A:àñêŽ*Ž…’kô…[K®Ök”nÜ|§Y—˜`Q<”¿R:Ã&'?lÉ™Ú\8$‚4zµ,àG¦£3¤ÒLh$^Eúr+•ê,ãHè¨‰Ø¶ð£¶AÅ•ÄjQ8äry­V'ï¯)µæ¾¾ø¹—©–>‘5ì~—«l‹ÅÛ¨/ãõÒ‘5z¢¸6Ä }Ð•õIjsSÖ’*vaÔkKN‹0^G·ÕÉ'ýá`$á‡Ç°~G·1qè¼\%•x¬ÅuÄg·:4£Ù¡\ô‘®Db:ºˆ:µ\†Í)/Z¿—XÈ+úéèo~ýþ±?Þþõ¥è­Œw¸¿?==rõ‰ÈÂ•’=5ü§Ê½ëó™*`+ ‘”¹ÖlÖù¬Þþ2vòêÍf³A¤äÎºašïäe”jµò:r%…žõƒè‘tÛ@×Ëâø
úW¥I<¯’*Fé¤ä/Ñæª$ä›¥r”«Ö³E¸RæFKGhš%ÖÓ¨¸¶ EMmzúÃVœ©Í©$J£SËü¼JÒ¯”ŒN„c‘DÑo®8ØØYÆUª½ÅûGV®6÷÷é”É™FÒ’•äjƒ1M£plÀv¨ÓLÿ°»ó€;ùZe ²VŽktó­Lmê×möüõ"Ð²]¯ŸÁ×‡®§¯o=S¨ð&§Äcû+YÝE­I6·ÃåBAt!L&WÊ"K”<f¥Ûm'Htõèƒ®DµÁ8¿ËÉ'¨÷[/£Ý½ØÇ‹xzyxQ=ÔoF7`™ `;qÆ[Ðã˜ÕÚ•=@¦ µ—?qHpöXô©½cƒnk×|Á$ŸýÔçÿè7?ûÓ_¾.jù~6õgbØÿà×¾¯X§B"ö7~Þ÷w>(; ’ò#£Œ#„=ÁÐøké±wyìèáØ„´’VU02èhØ‘û6v”WL~Ž}…ÝÂV¹÷ÉðÝ“²úþþëõ'ÜYPmòæAR:Þ1+¬’Š;¢T—|µ¨€Œ”ãä†`Ù2+Ö‘Ê¡»>ß|¤R¾þ»ô¶=S›ît‘¨×~¯’¨„ª`ƒì#Q:\:( ¢6v–ùZ×µNF"ºrY„?¡t·']$éUzÝJTN&Sj™ „¸V¹ºÄ7€¤¨É*|ôòCÀ”úþM »ÐŽDèAŠÄ‘Å Y_^5ž._-¸…ÈŸ¹ÄÃUSjp2™ÓápùèÄ‰ÃFMf£*hí°úJ£’R»ìw;ÐºˆF#²–Tò>Â €äyC‚©ÇìöYwN×?<´’Hª=F·Ï$¿/ÈjS¡\¤ž¼â‡îéÓ¾ð²x}ÝÞu4Ã®OýÉçOüÃ§/\ù¯ëÓ÷•{žÙoþë½{×Ÿ«Ôv¨Í;11÷öÛP^¶@$ÍÍßZX¼SäÂŸU~^¹uJŽT‡ÆÔ:Ç ÃåÅw1tCÄZiÝu{Ìf½fc÷…Ä×MF®þ|˜\†ž)×¹Y\‰×HÚ´œG¹
Òn&²a/‰KQ1ù‘É4XŽåÖé*p‰’9}uÄgëÌ“Eª{úÖYVö3U•äóú=>Æ ¨$¤‘ð%¢ÒæËŠÝàY–ë†ÞüåP¢|6£éOODF1§övG2Ž­¥ÆI!“WÉ×©B_Ownu'™\c4k¿0}	ÔÆKFNRæT¸îòR}#Š`·–îÈ,VºF8:ˆ#Ç2•&O,Lh?™L\–ò™Î¾´ð¥Öd±ô%JPxŠ(rýÐ¨EÉø>/²ªCÆ£Ë†~‹fêé1éÖ­•äSV-œ®$üørÔ›³Rù<oyÉ&Í–D²Bš¢J/VóS¶‘±6’=Šw©åóƒÇ_üfm:ë~²åûMÿþñOHˆPYYù7gÿãÿ÷²gffæw¿ûÝç?ÿy(eÕ?™‹ÿÅD~W¬Bxé+dgó½Ä–Aªô–a½%‚œ]‡ÓéÁOÁxŠ‚^Ÿ×70Ü/zoD1ý"ƒPÐ—>ó& üÃ#¸Œ¬äÂóI©Ñ¦ïµ\­ÓÈ=ž‚*©˜#’%w+îˆ„‡ãu‘,¹56[Œà*Î,‘$#1Í²MÏTÙPâRÚ^/î[Œê†2ZÅÇÚ±¾ ,g™e‚4Ãæ[½ŒT¦[.¸{¶ðÃþ9ÚÌSÏÆâB!1ä$!!Á´÷“ÉÈ\îâ¤Æ<4fVR¼ùE†Òšôª6„3áùgµ:ßÜ/Øˆ#ò¤m³þÁîNWªª§J§çÒÏe” 3 Síó¸\n—¿'Ò8ç‡7–X2–$7h2ß	H=¥=Œ¤r‘ÆßœÃ¿§¾çÑ—[èïuüS 8Gç^ígŸþþ¿úÂgw%`÷îÝ_y¼ý@)ŸH
-Ü~úûÑo­ùƒ/üÝ]¹lÔÜGl)rô43¤7÷#'Þfsâ;1ãéí–‘bÓ‘J•ìkJð   IDATF³ÚX1™pÏãpübµû0Ç²BŒCFÊ×=Š5©‘Ð‡úÓF­r‰nÎ.èóä«]ì%|vU%yÐ2rOžèÊ— &-“¾³È0«„¾29l(ÛóL•œi.G}4VI*‡‹A2µ¾@¯_Â³Ìù­_zØšû>tÚº2“ˆ²@†ÆØPæ˜¼Â$¦ÓUªTr9Ò"xò” ddzµ^¼?•SQbã5Rþž®)Üò"Íˆ]>à`Uü<…jeÙ®:¨4ö¡Ž	xœöA'NËÂm~äÃ­»Þe²B—Wn[à„nü¯\©’o–D$ˆûþˆ:æ¯ùIOà?Ä
vg»~¹ééÅ§`2·MùDÒèÿøhM
é“÷Vp·‰âËèõsîßÝ^®øÚ£[è'%A¾Rß¨Î˜˜’ÝyÝ&­y}C½I%NØ`‘adX}Áo52Ô…,ùúpP7Ák$~ÀH‡Cl‘ ßK³šuGÜÐ¾‹­"’–¹Hâ~F*åëSIH$!3=ÃFhl¸§tŽL*}â—¯0K‚)ó™*+rµ^«ô¸±:2*9Ÿi$¾Ð¼·¥<ËòŒùÔp™o~iR©3š9¯×ã‹(õ†ô<h~zÞ Kª´ë±*”º¾‘¡ä3MÄ×ÛÞé'¶¤*ø!]…”©ßFä“Ý2¥~èŠžØ2dJµ¡oÔ€Œ%·cÐT¶ªÒvZ(x“•â'k»Ð´ôË¿û/¾¼aò™/˜ÿRQ¢ª’¿ýío§gfÞyçúýÑG¡w”*ÿ¢§‡ 6Ÿò‰¤‘ÛkZþÓä®³GÈ'.DôüÿÃ./[w[Ï)ôx{½,Gûƒ“j]Oo2\•ÖE³ƒ" \¡™IÑ£šl _ÿœM‰d>;4wzv¡Â#n±—Ä®»º£=}Ë‰ ƒˆÁe*ó.‰:X?“Ü™uIi(™uÖÈˆ©5¸
k¦¼gªÌð*ÉíB*) Šø‚«i¤Ržeô=2[2r’üž€6ZTJÖïs±¤Ö´’=ñY½ülkFíiÝÕ`.Î¨&µÆ¼þq‚H’!Ý½K¦­ÏC@lÉœ£â"Áci~áÿ¡PâëÿÍ¯ßàþV•¼
}wø»ÿãÍ7³ÞŸùÇ‘TÊ'’î,/¯ñÄöJÜæš#¢1v›ÄÝHJêÇíëC&WëÔ2Ÿcñð"•·gâÇíão=úÀzK ¨_Q®™‡‚vuµ[},þ½îêŽhÿ´|8@‚Ì¨ÊkI%*:óS¯;ù).ŽšÏUé§Ô”õL•9Ž8¹œ§ÝNà¹ÌôšÂMYº³Ì!'ÖÎ¤;IiqQ'òz¬îÁÔ¬&ŸOCjÍæõ¥à3^[g»#™`†,Häß›ê­h$‘ùl"Ê`Ê?å	áý¸üµÿ·2ÙÖí£LŽçµŽ ãÚ6sGnàŸ§?(¸Èô{?·T¯ÙHºöýþk#wî¬-‹wû°¼¼bðwºN©¬¨Ø©už*‰r±V‰´Ìâê$?|¶¦Z–h_wsù?$Êo
ä} áâˆ$×U‘)µþnÏùÎ¼S®ã,q!?C©5jÖi˜$ÇµÉ5:Ñ8…'sã›
¯’ˆõ‚Â':~ƒN§É×xt¸>^Jhºõk…¾öŽÏáð—bTyþM•ïLm¤J§¥ÐiÁ)Íä*>¦tgOÂáHáôddÛcŸIOq‡uÐ‡CÚmíubg±Öé>Ê”*µ&‰Vo4›ÍúÌ4 ÷«¬°ãKV·øW*â´âúKJC¡’ñHÍñ"i§p·Rž(™FûÌæzIÜÜèÂG«,ûÇKl ³»tÉùý¿Ý¹
‰a˜?;úÌSö4úé:úÌ¯ýkbgR>‘´nþ··@'áJfí‡uÙ½´X­ÚçjÃâäˆõwóJ]O_	v¥æžÌÜãµw[=BÉKfý>?4;"ò<»‚+WIfŸOñXñ¼ ¹m‡ËôõòOÏ¤¶§G¿ÿG†šÄÂ»b´«»ËæÝÄûbÙÎÔ–@ªô:ÿJ®Ñ¡îJu–ešþ¿ýËo™U™û¥µX4² ³³½ýP{¯7‚äD¿!„O³àJNÙ»„ëX}–4LÆ…¾æíí|aÂÏ‚,²?†ö»]þÜ§-œØíò©¯Ð÷€‹ù²G8yg‡qi%§Ù¾›ö=Eæ2ï‚³>§+÷;Š²DÏRóïý½?#Ã–ü¿º¢nùãŒuþ¿ýú×ïëå­¿‹Ø±,--Yû¾ñÎ;ï¿þòw¾qúÔ|;£˜¤ “¾òDÜé$ôßÍŒ»!‰/¡Kø]_r«õzƒ^¯QS”œÄ·,Ëåtñ¥ÙTÆ±§Z–	úý\žŽAN©¨ÔÝN¦2ôõù:Ðý?â³wò™ú,f »p´Âa·¹„§kÊÐgÑ¯?IÐHd^„ŸÛ5üd·xRŠÜˆ[ÑG„…KŸ/Øå¦Ù€³óPÀˆ(¿á"/: „#×õõ6:¹2úúý´°µ®/ùt¦³I—’­ØÚ±Ù½nŒÛíL½{2R©ÊJ…ãë4ú#²U%¤’ôjG0€<¢¢°ÒŸeŽed˜äTT›úz¼íö@Æ¸¾á¾üM‹¬+Û vr~{ï rÈ¬ÌØc­Ñ¬Íúj‚&£Å[²˜K< ÒHXár§™g
ên'ÞWRcêÓhstóú~î˜ ®(¯µ³‹/ôèŒŒõoÊl˜¸:”žò"uN;{­Ôð@rnAôuÙ­vO‰êE½wõÝ¹TõmÙ½ÿû…¦Î?­EÝÑ®~êó§§¾g&L¦éß ˆµÎˆÛÇLìXÞ~ûí_ýêWéï ÁôóŸÿüá‡&v;¦â¶ “þÝ…è•A'É(ÃÐ(iÇ³1á¢*'úÉYFe¶ˆ¦ï0k§'ÏªIíÀ•á´´©a¶·!ø6ßÝîÛ›þÂwÒÂ¤i¤@2\br0€T’È|¤k9"~òs*çØ zRwõæ|„_d½A“Ì½ækñÊº{]A.QªEd)œß"òÙfgj»§²Œ™3‹]½.Ñ…eÚþ+Ã…¦—©Ìc¿\Ó¥ë<Ëx÷ aøªÛHÍt~ieò¥õñ"×fOÖýf‘^ðk•båÑ.X…É½HŠ’ÑtÀÑÙI[,ý?}» ý¢4ŒÌˆu“(Ô“·’GðÄ ”<ýÒ[ÙU<åÉPÖ­H?9¾H²Ì_&;»²Äd‹ë£™Ì±¡U LdœñyÚÍ±Åäš‹Ñß…deíƒ¸ –,1,E¦Òé8Ÿhh`m|H_JÄÚvÿq]ç÷?ÿÅú¤ºç¾/üõÁ—ÿäíïýéÏÿ	›(±¼ú»6Mí¶H£ÝznßYÛà­mÂNš–é¤±?¯mÿöB™t’®oTcôº./­’-Á‡¦&³¹d#lðTŸWÔn»ÍîÊž°ŸQ¹ñ)†Çµ	I_04ˆžU5x‚SV£]ÿ&I•*´Ú]9Þº\m´ô[ªÒ=³ã	ŸÐÖœv»Ã“)ä+"Ï§4µå6ùLí,Ös–±‡Òe_©`ÍòixÀ¥TÊeL2Ö‹Ë[ÚíNŸ %ŒýmÄaµcc×¥6X,=†É€³•ºzÝøWêFúõ„OúE£-yí(À¬USr9¿…BÃYdd¡`ŒR“Ys 2>1B{]—û.âã§È«5©Ê—\ÐÙÕiÒÙùY8²®íÊ™:C©3é7ðL‘ÐH±þ*ðÉU¬Wn‘x*m'Ï’J³™#?åºþÑ1ÊÊ?Ë ““¸sãY‡ûÍjf°ÃKoÔNšïgWQ¬m÷¾ãûŸþkåÙevÝß¢:ñŸöýûÿòÆG¡Ñù_÷ÔRë)•´oß¾å5ŽvZ^ûè¨MâÿðŸþô|xe¶;¥Rù‡ÿò‰H…pÍ™ç©üóôDô|¯s-ÌÑ|à™^ƒø‡ŠÝ?9ù¹ïÏÍßú·ß^xÿ£ÄÕpöÙq`óëàg~æéU#%Ú‘SXxGÂ÷=©ÚÉ›ÚvüæÒÛ5 ¥Ú¬<Ž»îLm„5œe«´¹#¨½fþ·‹JLK"×è)Æ+è.+¢ä”hHí;xá„ÃT*ÎÐcÁY0ÞîÇ‘©LÈÙMø…‘€i,×šÓùåúá+Ùƒ?9ÆÝu¨×Çåºq¬ßÞÑéV*Óèhú,+‘ rÇ¼¤©¿OTö ÷ÌåòÑ©E±Zo4é7úˆt—½5,A¨-WF×WÕ:ÚÙqˆŒ™dÖ¸ØC’åÄ‰7‡´[vi¿žï]úÙ_÷,ýñ…/<þ'÷¤-Ýüþ´ãôï¾pé‹ÿî66%‰ÛGåã—ï¼sÚvæÝwßE¯?÷¹ÏõY_D:)k™1ä­|"©ëµ÷ÇgÖðò/»®žü”èŸÞž¿•ò“7Ê¾ûµÖ¢  `ËIÎÝfì7D\VeÀnmNŒ¥Òà Ë'3Œ$ÄtÙœ„¡Ï˜R„5ü~ Ð$'éjsN´4¿Hâ'ãÄ¥¦ÅW.“«´“Qw7¸‹,.‹ÅnêƒÇÎ`é×ÿí½ÿèÓÔž¢fùð—á¹ùû?¯¹§*©€0ÚžšéÎ;¡P¨rW¥òAe–ÆÈeÛ
¦ò‰¤g^{ÿòZDRmUÅŸ6ç¥½¾%H®ßK‘Äú½ƒÞHQ­‰slûMêm~›¾ûŽHàn=®Íã;bÞjX­8–Ý„Ñhy¶Å{ÄfÛ   J® •DÛß[¹on7µT¾œ¤µžÀ…Ååÿ8±H "p‘`À(2”ÀPì´éq÷‘ÀÝz\›®cTdÒ»Œ,Ÿ˜DñCµ¼\3¹€@–è)ðkêÕvŽ]-óûŒPÚž£_—3%Òm›¨¥ín+·   wùôÐÊ‹4=T¤Ï´%·‘NÈª¸z~ ¢òƒØRi'n   €»•(K¥„ÿërž…³ÛB†éÿë—oÞ¼‰^¦®®ïÅo<ðÀé^QâuÚ"sŸsÞv@Åm    ¸‹YæÉz½œöîò;èåþ¿‰üŒ¨Ù ÷·ÁOœãNÛÎÜ|ç¬{–—ß¹yóÌ7ÿêöíÛ‰}ä*ãXøˆ¤øK?üÜö)3à$   À–!PC/RÞ	¯*RoâÌáuº„J.ŸOI”Ç‡Imûíþç_¥MÖ†¶Ž,¥þç^©¸qCuçŽŸ„£4c)•´”r•Êo)m±HZSFÑ&e5   @ùÉ™­ÈÁY„Ÿïœ.ŒVÂmËËéÖKáM¬P*©!¶r¤cnßºEd¦–£í-Ý´7   IDATZBfRBñr§"3’H$ÕRErt©´UÑ7p’     ÜdH+¿¦É#A^,'#S‚“”zL&ü’7_*sÖ¶%ìÝ»÷ÓŸúÔoÞ{OøÏ<PW÷™øÒÞI^UVV
ÿÚ§"	:”Š¤$¤Ð²Ø¸²é$I    PVrRB-gXC„x$¼çŽ Œnß¹s¿ªxÿ–„[®¼½\q‹¨¼CTÞæ–·ÃÈù
âÙÿø_„—KÇ}#«îÝÞµû£;»*–ew>¹{©²âÖ®ÊÊ]¼TÂ‚‰WKÉˆ[J*	ˆZJåÑIåI•bÇóÛÅåI:NÇÂ¢¸³G    ÀAÜCJå¥þ–ü3ŠRÝá…ú¹u{ùýÛ’ß-ßûqE²´éöî%2úAfÒG©·–‰ðq/ÿññý»–vïªÔb×®]ËiR)cÈ[R!•Y'•O$Ýsÿ®Óñç(V$‰²µ¦"    Oº0JO¸NO-J$!U””GèÕÂ-	KTDHñZv¾9€ýüæ6qïíøýÄG5»ãX"Ý¾¤ÒîÝ»3¤R*ú–)›ÒÓæá6    (Y
I+ª(M(!]tëÖ-A}p{÷{ËŸŒß¥ýµ –~»´$¿»…ávíBtRKéRIÐF™ßÊ “@$   À¦“«„[ºƒÄg`ãøÚ-ä!Ý¾»Uñ›eòcÁ=º«YªüŠ¨ùíRüÓ·bŸÜBí°{) \Î§`U¤²”–SÃß6_'mqNÒ£”ô?èªˆâøÞÅë9	L“   lsDRJ 	ÖúW°ÝZZZºu+¼ü	–¸ø}‚«†ˆÚû—>üô­ßa7I"Á™ÝH*-/#c‰H•MBV.´Å9IPUÑL«‘¿_õaî›“   lg²óˆ…$”Ï^N3—îüz¹&^q÷H¢|@Ü÷ñÉž¥÷«ˆ¥;¼BB–’0ÞÇà’:)+•{“t„Û    `³XÎ¬™å!%Ÿ„ò>¸#ýQ{ç÷;HÂUHn.ÿÁ§ãì'wÅ—%ÔVÈW"*+Qs¥t&é'	¿m†N‘    ›Âræìc¹
I¨)È£ø­Ûá;U¿«(6åîf¹¢2L|ò£Û‹Ÿ^^\Þ¥äî]»*PÐ-M'¥ânÄ¦Õ™Üb‘ty†{°7L %þ‹7Ž{=þ•o{º¡šØ\Ê¹-  €ÏrrþŠd¦6‘­n-ÞZþÕš¥
	¤ÁVT}|GúÀ­ªˆ[/Yîdê¤ÊÔton+7ñùYßøÇ'®ÍEq)u¢†Ò1?Ó¾¿6wÑØ/&~ðƒ‰©ÙY&*UPõºö'7íÉŠ'Çèkc—¯\	óë«R6·5?y`XÜ9¾0í}ýâÞš/UxM­í-”¸*‰‡g®‡–mërþ¾0û£‰ÉAéÚZöV:Øé	ßµ°ô@[ÛEÁ@xÎ¶Öt\)bôÕË¾ÙbÏê[Ü$¦/_š¼)R¥KJiÛ¿Ü°ršc´wô¢oš¦i_	E£îÉ£O·Rbüæä¸wfž¨iÐµ·ì-p|±Ù_¥£™{P³‡¢÷×Sµ B`Ç’›Š”e#øòÙH!¡ÿEn“K• DˆWH"·ï“¿#x¹›Ý–Š»-3þ¦•M*­Z‘$N<ä}åÅ³W3L®(í»ÐK‡^úÖ±:)6í<ùüÅ¨û¬Q(ëˆ0ýÖ„ã­©Ù¯œl­KvŽñùÉ§_§—Ò>·ÈL^|‘??p¢%KOdo}¯ý\}ëÔùÍ¹"-ž¾F/)Z›S)¾0û“‰‰ÉëSÓXd!áÒDµ¶ì%r?9ÿ¦oüêÕ™é™9^áÔ,6¶–,™ÛZÛq¥·Ùk/¿<Æ WŠøj[Ü$bÌäèÅK¢6&UÓòXC-¿O±_Œ½üÂw§Ò5ÌRxfüì³“W¾ô|ûCyDLŒ{åeçj–ª0nùÚ¼»Î]~ýÒÜ’èkšž8q|5¹	 À6dYlòÚå4Å”e[zï–ìÃ]÷@>¬¼ï7·âr‚Ä.‚“”ï–©J¨“Ê'’vmNÚ®ÊMXmlö{/c"Q>ú•#m-ì¨:6ës¾r~"ÄŒ¿òÝÆÏ·¤z½Ø”ó¬-'úyU´0í|ùÔÅ™«.4ÕŸú2/“âôx?¯$<ÞÑ®kBAœ¾vñü¹KsaßùsÔ™¶º•~paòÜ^!UÕ·u{»G±_\užºJÔˆÇªf®#ÝÒÒ¼âm,¼õú¹¿ZZí@ã¡ë¯¿8·êry¶µ¶ãJkßé×Ïó
i+‰Ç¢ØERh?ÓZW±›UÊän/Ì^G
IR÷èWÍGÛ‘˜Ãßß^øÎÄè[ÆÏœ§/ý`M­Šö£¥û¨®®Š_[íÛ›ã¯½žºøâ±ož?v % Ø‰,‹•Aâl·xâK·Ø¥Ê÷w’ 
òÁ®OÊ–"÷ü•Ïã¾ÃOú–ÊI"2““JEùDRÝì"6ÏlÆj«©ÃO´Ò>êéíÉ¬›Ú†Öc§ãÑcCSÑÉñÉPóa¡Ï_Ãz¦îñÇ¾Qíþ'=M?÷ÖÔã³îG+Öi;Ú¦.K?iJ™PÔcGOÑg_òE§Æ'o¶y(ÑÙÆ¦/¾êEBÙvºÿèÁ„ÅRýPË±¡fS\Z-Ö%ÏOOÎ.*šî[éI÷´ö_iåW7sþèsãùÒ¾¤Tû§¿Z˜<õgg&‰UÈÜÖZŽk…ØÌŽK –®On]BZ|q1ÎÇÎššò[5µží|,¦ØO%ŽOZ»ÿp÷DØ|nféæõ™ùv*'”ÿÅø…×æ–<Z%Q$UT}Ó4Åu ¹ù ódïÅ!ßø›G<¦   Ø)dŒöçó‹©Hiá¶Ûü¬#Þ®ˆìú(‚÷vÿÁîÛ¿ÝUy‹£;wvñÕPS
9I•™6R©Ì¤J¢\ü›ý÷›À¿ù£ÍX­toKïÙSí™yÉÒ½M­U8Üò‹h"“%žñÎ e±O×š¶¬´®¹µ	-ššÅ„wjíÙ”¦C=pkSú7<›XŒÀFÍe’H5:Ó“³‚P¢
	›œ[¬©o¦òÇtJFÖ¶Š?®±ÙÑc4QsðˆN±•ñwäÖÄÐY¬®©•fIû³ZVªh|”Bû¾›æ¤4Åi¯ãõ™¥ª¦Ž¶†_uCk[=ZÅbˆ^ˆ  ì@²Ô’`&Ým·n-ÝºõâþåŠòuÄ;š;Dåo‰O,ñž°EpäDg.å;7ÿêÁÝßýZ‰Å‘§>Ùð@Óª$U|"m|)Ù5ÆÂ3!dþ)2M5Õˆ{Ñð-8}oU/­–ˆdhfaîêt­°©¥±XÉ³0{uz±ªáÑú2h¤b·•s\Ib³ÏÿðÆ’¤þÈ‘Æ-æÆÐÞI«ªÖ‘ðÆqwõžšìäü÷»N$›ëZŸh.E¢¿
¤Ž!)	 v½uEE*â–Š»	3²¡Îþý[»—*áÛ½¸Ê{>¸½û.»‰u‘Ö°‰fNŽt#J$˜Êš¸}¸Qöw–O…ÙÛD)xð“»>³9!¼|Ä—¢óèßššDÇ…Â1²Ù—múÔÔÕÕ3á(öœêò~âQüq¢ZQ›ñ…¹ÒHec]±I(³×ç«[Ê¢‘ŠÛVÎq%ˆÑãßÃé©îÖ‡ªB^b#à…¯ŽÏÅÍ¦öµ§~Ç±FBûX-/ü‚-.áA„{Õ«¯(6ë¼ÎRCkÖÆøÍ‰ßCIùøñ'÷W‡o"~srbv‰ìkjRÀm v"&‡™$TÖF"	mkçƒÊûÉ[‘[•˜åÊJ!s;ñ_>-i¹¤iIåÝFÉw¡bGŸŸòÍbßˆÚŸì´£xP[nÈFZ½ëƒXt¡€“´0ë›A’¨j_JÅ¢Q<ŽªV¡¨^˜ýÑÅ7._ŸáG§IêiêØWæv–ôÕ™hU}sC±é»­ÜãâI¤3Kê¿~ì0NF'6@lÖyò?¢˜››™¡_|aY;ñNI"æ¯¼ôäß2©ÁkUõ­æî£_Î3º?žžºêŸdˆªÆ¯>­ÍÐ¿ñÐU‡s
I¤öc_Ý_B±Äºˆ/Ðt(žõNàÖ:Ò}ä!HÛ€B¶$D„’o¥mÈ
yùžÛ0æí ï-º$ûÔŠ8s[Â×IÊéV‰Cd¥ÊL‚ EMNLcôˆ6é¤Äãq>ž$•äˆ)/›â+‘9‘õM]ÆCËkhWBk|ž!]š|å/.M†%5Ê:J½ÉDCo]<ý\ôô·zfJØë³Q	Õœ°¾©¹-ÑãJ¥3KêMÇÛ¨vúñèìÌ•P^tf’^xL±&/MZ]S#!bÕÊý_ùúQdûÅC3Þ±±É¹‰çÂ‹ßêÿJæ¨µØÔ€éÅ‰¤–ªiúúó'dÌŠÏÿÄ‰%RÝãÇ¾Ö¸Žã»ñêŸë_ÍzO¡íí;öe
$ ì<2l$!”œÅ‰PßÀîi°»îÿD|9I»ù´¤JôŸÊÊTµ¤ÒšI ’ŠERœ£s($ÓôÕöý+½V¡Œ#üˆ ¨$Ñ!i×^õ©’úö#9•B—B
íñoóŽNó{gÎŒÝ˜8w¡‰z>½øPŒžœ
K:Ë)n[yŽ+‘ÎŒmm%ñE¤Rœ9½òÛš³vª÷u\|2V]Ü›ÆµÍß{®ç3¯9½M§g[«¢êéP8Âå$£SûrôÄIÓÊ°¸ùÉ¯^&mÄ:¨¢´-5‰ÇÊø"375M‡}=sÞ6ÓqSÁr”  l'Dl¤äè¶ÛIé¢
l¤uƒš5 ä6‡ùFüô·»Pƒ'?ÝL"6ˆ¤¢ˆ‡¼ç°MPÕd:úØJŒEÊwÕé™ÜiÁ&S•TÄdÂÌO:¾0’_{¦-}<ßÙ£ˆM+6’Cª8ðµ£mSÏ]
MOG›÷¤TJ,tm&,¡ÚË¢‘ŠÚ–øq¡æs:g“¶#U4jVÌø„ˆVUcÛáúõ¬vE!%~oøÊ“­^ÛDtfb&¬Û›L«nh?3È×Jˆ…ÞœxõÜw¯Ï\zñâ[çùDøê«ßÅI´­EËG;2í«…ÙËçlC“—^~Iª0Á0 °ÝÉJB^ãÿS$ñÈÿ[·>ÜU†‰»™wÝwûÖâÝ»±ÝµKH¯LÅ×ÒÌ¤J%IE›}íì…$‘1™[3ÒP$üè¦˜$K'’‘ª«Äk¾2ä‹")ñäÑÃ}ŸTX¾®©-kò“¶9"   IDATjeSCÍ%œªŽ9)Sb¡É)F²÷ë{Ê¡‘VßV¾ãš÷]øÞ[‹HT3Þ×.¤–Ï`³03îüÎTÕ$tš‹?éž–.P­W'fbûšu¥‹6ÖRTÕÄÔ"nçÃ¢÷ÕuÚOŸW³M„Æ/\n=×AÅ®½î¼ŠŒµ‹“?øÎdbÁxt|\ºáý[ÇlMmƒ¶í±5
ÄÚ†ÃÇ»gfm>zü7?ýXRó (‰D!ÊÆü¿Í¿C,I6¥&ÎïK»îán»nÝÚ…¢n»v¡¡‘+øJÜ0-I‰‡~töÌ(ÐVóèñcºÌ¨‡´V¡¨"n,†CÑ±'½Äo8Ç[‘Ý/ÆÃ“ç^Â’«ñ©“Y•˜ˆjECäJx!Ž‘VãjÛÑøb<¾²g×§B’º#u›¯‘VßVþãŠEé_¥2<5qi*ûsK¡ëã!~>]óÚÄ’+‡MˆÒ"IŒn‹ÇERkÛš•cLèÍ™ðWêˆÍ[ZÑï¥™œe…£®
Õi[»‹V]×X'ñEÑbÁDn °É˜£•x7Us‰$>â#@!•€Eâ^ÙíQ“îæs’ˆä07!ƒ»TéÛ ’
‚ÒËÖH‘ì;ò|ïc9‘¦êºýu’ës|=¤†´Áã¸~Ò5uTf!¬$¬gø‚Ú­'O´ç&šÔîkÜKLÑ¡Ùp¬%£OŒÇøYv¥5©ä›xhz*Dà‰fË¡‘
o«àqUSí§š³³Åc7.]˜ŒV5}½÷+UÕ5Û"?9ó`•¢¦p‰I)ï]-aÍ*­ÓøVCN±òxhâÜÐDXRäÄ“j«k”ë2‚â‹‹üy‡RI °SXNN¹ºœRHÉ¡m¼•tçãÊû`Ã|\yïíÛ¢&½ÃÛHÉjIDIÓ·A$å'6;j³¾Š½‘ú§Nê²$­k<XGÌÝ˜½6·p¸nOê“¡Éiä-Ô4¶¤×jŽ¡h”mh2ŒWÛ™SGˆY—Ö5µÔÿ€ž»þ£_mHÛbŒ™šÅk¬ß¯L¼‰¢U×BKumÍu›/.
okÕã’â¢	¹“¾‰½±jÕx ±Ü	³_D¶]Övc´Ï‹ç\Sì? ü)¾p3Lì©ËŽæÅÃ4>ÁH×!1%­¥ö‹Ì:\u£F2–Ö ãÛ¿ÎHÚŸ«xb“œj¥  ls…“=·0	o&ñÝ ’J jÆ;Ü{Bš×®;w°u$H¢ÒÍIB”³âö#FÿPPH’ú§¾Ùß‘¯þ5ž¤^B,MM'½’xhòµ	dª(š[W>y_y+	BÙvæ›Çò?”Rº'škˆðøyçµp2â³0=êÄ1©:m*W)>?3I/)›Ë`ÀÚV±Çµ­ˆÓ—ÿúä±gO¾2>=¿U[˜8uW‰l|BˆÆ~1öò±?{úÄùÉùôà[|þÚE<‚P4é6­Îy<<{ù,®…÷çñ6HÛ€C²``%	}u"eûö+dÄæL÷þ{GEÅGË’Û·ù1„wîï	¾‘©œ$1p’Õ1ÃGP–æ^ý‹›]À†Ø÷Ô·Ïñc‘¤{µOêÆ_¿qéù?¿ñØ#ûªˆðŒï:³¼ŸøJÊ AÑ¨ÏN
ev˜ñþýxöúmß>&Œªm>zü}úÊøégg››’(}/Î¨h}ê‰ý+>’ovIÑrPY-¶ûÞsCc4/Úâ‹!ly,N9ž3¿Œ´®íØÉ6~‚gÏ{)ÞÄ‡ýÉÐqšO¯¢ŽœìæËØÖZŽk;Q¥PÖUÍÌy‡žó^P66ï¯#B3S3BæTÕ#G“sKùDúÅ¹+g¾êSÖ×ST]ef¦¦ø%º¯?¹¿déÆ¨Í|5YÉEýøZx+ÍÏ<¼	 ¶?iIÉÿ.'cmü„$·ãÄ½P">®”UßY\¦qK&$™©H0ºmˆ…¦æ¢Å.\{àÙS§¤.Œ½5ã½"äíVQ­¦ã__)´OO¯¡þríÁ£¯¼¤8{îõ©É	~j‰¢ñÐ“f“.Ua(½F/Õ4å™Ô6š¹Ê|/ÊÜˆ&þO–nZ oÌÑ™;¶f’×'Œ¬ÛZãqm¤{{…jñþàÂïfÆÇ$Ó­«ê;y4U”Hº·í´³Þë8‹›{ý¤–k}úÓá’N³¸Òò„¤FYÿHCCcãææu`"Àv'kÌÿrêM!â&è$Þó¸ÓÙ–ŽÛ¸B2è‰$1–0mÌEÚëuK¥
ŽãˆdrSjéëÊ÷š(éô(w±ð,u…´FIí-ÑôØBh~±joNNÌü„õèYú@ßw¬Í›>.¼œÛ*;ü üü$„¤ZQGíÉ#Hba:
=H«u)@¸  °BºoDð¾Qª*Ò2Ÿ®_ZZŠÇ?ú˜‹ìúƒ¸ôP
¤ñßÉo¿wÏ=÷Èd2©DRYYYQY¹‹ÿ/R'•ü ·
b½Šœ¤’Q­hØ¿¶éÃŠXgmÝC"Êdavrv±¦1TZÊ¹­ò#­¥Š²ƒªTC©O.  w1)Á”þ+
·Ý–@·[2nWî¾½„ã˜üô$óÀ@âöï1s“s‹UûšËÑm—s[   ;Ô4«Â¯ÉÑm8·x'ÍÜ©Ø¡S¼oGPcVð›Vj¥ñK’»"içtËtTÒÐÜ¸‡ØtÊ¹-  €Kz¾vú;ÉB¹¤e˜²­t ÆÄMJ¤Ê$å,sRÖ
ä$í<âô,C(öåM Ù¡Û  Ø¡¬d#ñ±!ú#M&kãâqôóÑ‡þ†üC(ŸþàŸï­ºO&•¢Ÿ]»w	I•|R%_9©2)o 'é÷ñÚ…;[   ;Ñ¡méZFZ¥åÍ ¥BÐ¤é#
Ó¥ÐÆ¸H   €² á—’³ÉM
9I    PR’ÖADN…I „d4lúkÝ    Û|ÄY‘  $TäT[HQI
"	    JI¾n(%lÈI   €Mbm›Ê¦6/8I      "€“    ›ÂŽsnÝºuów„×»ví’úÓ÷Ýw±s(yâˆ$     0ïE"úÿßÂëššš¿üÆ7ýâ‰ßc@$   À&’6•Mk^ÈI   €Ò#L‡‘ñ+°	lêti ’      D ‘      ˆ$      @$     ˆ "	     @I      "€H     D     € ’      D€iI    à.çÖ­[ïE"«.	ÿ&õúÎ;ïG£ó¿þuáTVTÞ_óI™LFÜ€H   €»œ›ï¼“š¹¶H>øàƒ—ÿê¯V]¬¦¦Æzúôÿþè£ÄÝ„Û      DX³“e?L½®€i  €ßo–——…ÿ
/P”êz•øÏôÿ¥Û·–âKü÷@	a?æ–îp·‰ô–d×îŠJ'ü?è?¯U¹‚þ[CÞ·¦õC¸    îrvïÞ]û°êbwnß~ÿý÷…×HdT}¢Z"‘þÈ'ï¿_"‘w)ÇIO(å¥[Dù^à$   ¿÷p’nNÒ­[ñxüã>ú˜ã"÷?LlÜÇÿâç?_u±…÷~kµ<'¼þù‰NÓSÿÛç?_ø#»wí~°NYý‰O[üƒ·ï‘Éî¹÷^©T*Ù¤]$b€“    w9²{îYUî ÒÇ²!õS·wo1Ÿº‹Äm      @$     ˆ "	     @I      "€H     D     € ’      D ‘      ˆ$      @$     ˆ Ó’     €©¬Ü•š÷“÷ß/•J‰ßo@$    €©©ùäËgÏ
¯wïÚõÀ¿øÄï7 ’     ÀH¤Ò•Š ’@N     € ’      D ‘      ˆ$      @$   @éYÆÿ_ÎøØ2vy¹´í£Û    `“Y´™lZó‚H   €r B©älv“B¸    6…ŠŠŠÔáõòíÛP"„ÆLµ-‘Öà¥D    ”‰Ê;KP"ÊÐ˜ ’ `'	¸v»ÓKsiorŒÏå°;\þG¬ƒÄÇ=A–Øb8þðÜ[¿# °{£²w¸ ’J‡Ð˜‰†-µ‡$ 9I;Ü]8ÜˆŒÒÍFE €UÄ ÃOêÕz%O½K{v£"4z\F¬$’œv'£Qêt*œeAfzK&W©‹¸@Y–#I±ÝãX:àgäpÚÝŸ\5bR­ý@€LÐ‰´9mß€ZssÁ6úÉÚ†~Ð»•Ë·ï@i@‰št9'3)= ·A@$íØ€£»sQétjYÐÕÝì0(á>¬Žñ»î e´ÕÅ)mþês1DÑÈõÃW†tòÂE¼¶ön/©5š{ÌzUÆž°~‡µËMGFû,ýÆ@§Ëáðêôe½à9&HË(•|G}ÉXÚëFR¤R£7h“ÍÅÑkï £é¶¨H2ðØm*Õ°Y]æÇ¬„Ò–‘J•*ÿ™Œý4©Öìì{êžsÇ¡ó¢‰ï¹oƒ“T:n/U$©ˆ$*‰N‘”s@ñ
B®6 ß&Ïw–zÜž`„¤´ƒ&­S@ò.·Ÿáð‡³:€uíŠwÐÉhF‡ôJ´M=ÕÙåptýp“6–öÓ2µú®P£,ô{].·×Œðoxnt¸(™—fÉ$c^ø›á	ÊÔzêËp·ì¥¿$? /ÂGb|nÃ±„?ÈÊ²÷A®³Xþ.÷ Û0b2÷è¼ÝžA§QÛW¾ž8{;ìÊÐ×´¤œØ) Ðä¬ŠÐ´É÷dJJMFÜN»C7b1ZLž²ç´eöæXÚeíp	™ºïÊ¨)û†Ê±,A’DÐÑÝn¨,£#e×p¥†IX-I[ƒïÉ+++v·o@iØ½|5iER¼*Jh#t
@$m*èùËép3è©X•ÉÈ„¥Q°ÃÇ*R›Ø`¯ðaÚ ßðŒÊ\„FbM“Ø	’R©H–¡#œ&«àÍê¢eJUô£/ŠiiBm²ôäÕ€; Ô÷£$µÎ~.ÂDdJ¥èý˜Å–ÕÇªCýúR4QÄo·ÑÞ*ÉÕ×Æ±Ã¡³cÔ¬ÛÌˆ ãðÁ` \IOR¢Ðœ^§Ój5E”L‰ìžTŸ‹|Î‡D’ÆÔcA¯/âõÒrÙbYKŸËúN/k*ƒ+Õ   IDATƒY¬måZ“Qí±»œ>ý€ÎÒg’3eí4‘ôóøQƒÝÖvKoéëÛÀi('$/8e²Œ•©}o»Õ5è1L=O—ÛåúÊù”%#åøªçdr2K#AÚÝegtÃ£ýZcÉÓé@1Aõˆå.{¬H²ûÖÇëJDØu›«ØUQÂàZ. ’¶œˆÏ1è(ÕZÔm¥ž¿¹ˆßãpº|¬ºÝÊä”’pùü´É#ÔÕzýR£Ì¸²È¦ö8ÂO¬¿ßì+ÒQØ†Düƒ]]î5D‚r¡Ãý†œŽ)Ó`™'\ÐÝ}ˆöômX(qø$ùX¿¯èOx‚56 ]§“ñ:l^á%’ÏZÅ`4(Ö±ŽÎ'a™ò¯Q¼5¸Ìï´c')€Éï°Û“NRAïUXCÐ=è
¢¤F£æP£ˆlaå%#	–“©ô}ýDY!Õ–Ñ+”ÍjsX.è±uxÝ†þ>ƒj›÷Ûù]Fé{Œ®§ÛÔ[´–¡Q“RSîc‘ñâˆ“É²CR¥ÑR2›Ë6¨C2ÉÜgòu8¶AíhßŽ‘I©øzq'3ÐVÁg&U"©»’[wn•»`cT,ß‘Þþ°RrOï&	*)K-¡“Q¹±ê  ’¶–ñ{\¨Ã@:I«¥’ïÊÐ£¶×`ˆà Ç8bBá“»ÓÖþ%r“˜ -7™3o ¤\¥Qk„ºl>—”+×ÿŒúHäQÈä”ŠÚYÙ+Ðn‡S¯íÏV"¨_é¥Ô¶n«›æ®îv:8<dÑl$ò‚ó™Õl')yjdj£Qd‹È‘êµûùõ ¿)Èñù=íÞ„Î2`N\G¤Wôj•JÜºŠøÝ~B£K„É"7Š›	+åU²RvUR‰Š–iÚ><Î€è/Jƒ&¿÷Jð+tøù‡id(uûy—D_	r«.+9ˆcZ£Ûn”’»·“%Æ†vj& ©îý©¹9øµf[±Óeö´ÛqxUmÖ˜,O§ÛïF4Ûk?×B¢?¾s‡HyH¨#G¯y´‹‹Ý¾÷~Ø•½lÉ†MùI¸}Wb^Ø‘Q™Ovr­¹GëíE¶ƒÛ4ô©ÕæÑ7Aï ÃËéÍF}VðA¦Ô÷è‰µÀí‡ìA½ÛÀ3%töv¸h¹nhlçK•ç5¬Ã½F{n³y±#¢6Xòx5|GIQ(à‹°~GW74dÑ®»›”k,#cE,ÇlíNtj4˜Ã‡4T È[ð~šœŠp\B$)Q¬W—7LÅ2>G·£Wm¹2jVÉwé²;é åä=%‹ÄúW¦é¿2b¤ÚÕÙncÍ£cfµ,âë=ÔéÓ]ÒË‘+ÚÛÞé&
Â]¨á#D1 ˜r~w¤ S+%§­×î%&ÝÖ+$²µûó5
¡ÓøŸ€£«Ý“'¦+S™+º½´‰£®®.·cÐ£6j,Ãc=Jµr§…Û?)=k;Ýaþ‹zòÊ]•»¸@$mœ]ñß¡Æ¬Li£Š¼¹ÛL ’¶dð	±”:kŒÒuJŸ›	z^~€•‘(ì0´6%”ÜÑ&ÕÙúÖe$³•‘²­ºç’”ZC­ùS¨§vøùÃW›ú
çˆ’jóÐií²z6àìì`ú‡Œ…d%Zw¯ƒè0®7:	¸¼8¥Ô›Ä£VrmßÈ¨c„“Ñz]4©±ôð‡Ü*t:e¼´à
ŠG–Á¾©\&™ÙNfKÃß<HƒÉ5½Zp’”²âTÍêÇæ³uÛ°#%×õåk¢ˆ··Q•Zý“¦Y,Á©eA
.û¯BzpþÓÂ¡“å¦‘«MC?6°,Inƒ^EýYY+ÎûGf–’k{ú,«Æ Øq]Ëg…qsü¨9þá
>?j dp#D=nlÖ¢ëÅçcášA+Rkõº‚ŽlÐëñP3’($œØ¨ †–¥ŸØ×Ð —am”%”x…TI`T)ùx1N EzëÃ]²Ý¸E+*S’¬½œô“6ž¾"ikI J•&ç¹	õZz•ÛŒx® ÎRâÄU–öó%ú(Í††Û"é&[­7Þvp´Û>ˆ{jôPm)b©2Q*d(ùY»yÚüõ8Æ‹‡=2™^7 [O°€CªØƒ®	Rk6å‰6ÈV”!¢{<âê´Ø'hWÚ;h—ëµ¢äh?¾‰êD`™&f¡kb<ÝO ÇÍ–¤ŒaVñ‡Š™qÝÝ.lu(õ}}†¼"2ðò
–Òê
žŽú=¡ëI3@B†Ò4‹TžQ•ÒÂívšÒ[úûÅª°þÁÎnLg2›9vá*
	7§Gf0k7â"+SÊnˆ¤=f*¯“”¥oÅ×°-sÐeJÙL¬‡H`°»ÓÍÈÃcyÀép gŒ4(S†²
ñÃE‡E›û7*ó#Wy0­«Ý*×Z†²S0è9ÉÚk÷Ð¹7¼„(’©ŸûþóQÞÿM/•$ä#!Óc÷î]·ní–TÄ+¸Ø²¬š Öj@IÅò®]»wíÂ[YY™;LI ‘´¥p‘€ßä*­*÷îŠ<h£Öeõ±A+`RkKŸ=|?¤Òn°,¥ðÜ½“TRÄkë²"}€ÍŒ¡žbsCQ\ox”D‘¥¿@^JÄ$Ž!ñËêäknZ\íÏ×Q{ÖŸ(ŽlHƒfÐæg|.»ÏU`A¹Î ÍÞ
Kû¸F¶ËeD»YJ¨W¢YŽóºì¬WHÜFWÓ =Hâ5Ü®b[â"¾ÁînÎ¤B¡ááþüñŽñ¹½Âµi(¬‘PG×mõ³rZ­M{•j½FîõÒ^OÀ¬ÉüÊàÈ6‡þ!ÄÌ$\€Óˆ°„Ë†„†	ÅU×\c|v›ÝCFÔê|ÉÆ(ºÝqÈXuM¤ÚØ_hŒ ©Ò™Tº|Ÿæ6IXpXLÛsÌjÄï´;cí¾‘{í^»dHê4*%Áø=H.!g·»‰ˆNá˜€Ûæu¹ø?ô¤A‘(^‰=Ú:¡ƒê¬ÔqE1;q2 z~0™Z´…€Û9èÁYØ“Âž,©zß
ö’	IŸÍ]™ÈÛÆ½÷.Düý[ ’6À.Ž­²‘ÊW[H…Þp²|ei&‘´¥ 'aüÌƒú4QwWFéLz‡ÏÅ0^—Ñ”.gWºññÏêmaÄúmíNš(LÄÛ}à³Ýéï(#WúµÛ/© w¬6lfÈPmÀ°¦~J#ÆB+§ÝÖ^Þ)¡Ó:¬?$‘ìvœ¯#×›Œ±‘E6òc•×àèåTªuúœ€ô	ÙMDpÐæÒd¬Ie)aPÿêói¯ËAä7LW¯·
÷ãpe¾Ðz$pzñ—B­_e0þzh~rOüŒ.ùíPjPïåu3>Ô?jÓ¯@~Œµ—há+e¾"´Z>†8»¾èÕYúûLEd ¡kÊnÃæŸG}4'Êü§ßž§$Dn{·ÛÎKÁ`ÐiJ4{ K£ ­®o¨ß”ŒÑr=~go7N(ôñÙN†œ´IÆãp!ajê3ëë:»;pD8èuûÍš7˜"ÿ«)•ix¤/¡¿5Zt5uu:Ü=ÿÛ³C¯~PŠ}£;i¡¶T¬mYH.®¬ÄÞÇ­]÷~Ý[³¼»Š ÖÁ-îÞ~»ë^ÙnÉn¡U‰TúQZÒÆ‡¶ ’¶VÀOÞ²<·m‚¹Õn{€Å×úR=¢¾Ø­BmÐ—±üLÄÓ}¨Û³¡ä%²ÔE±Ð“¡?¢Ôä‹4°èžiÅÝ23zJ<À;ñBF²RoYOÜ…—Hxïä:Kn=iðH™š—Ü2¥FoÒ¬9…z}IMÌmÍŽô¦¥A­
N‹ZùUw¯“O£!5–á!Ú;ÖoïìrFÔÆ¾>K†LÃ3¢8ù„,Ù°ªÜ@_åqÒ>§'¨Kª99ò’”n¤’<þˆvåZa™@ ˜*|Y¦ÔZF~lðZ{8°jïôû,#«Ô¦Æe'ùkJ¦6Yòï2Ò`ýcj¶@"ó¢u¶¤r9‚«%Y·G»ºY}rƒ)ÓèØÚ†ì£€¿Û~°íi4"kiÃÕÍ)ÃÐH†E)Cþï@?óÛ"èÔ‹˜IJýÀHúÓzê1{üö º—øè7ÁÀ¯\ß“ñå–kLf»ËƒüO¤IõTîöJ7ÿJˆíª@7¤’–*wUìbs«ös°vv³¿BÈ·ä®]i‘)‰6žµMÀ·[J*õ¢@~<S¾õp~—+P¢|K6€üe<ö_kÔ­Ö‘jóèÌÛ¿ãí$c¤và§™ý¸ü5NP@ÇÞy¨½³½½W´©°YŽ³ŠpW=Ôo(í³:’¶Ä°|Êˆ:þµKË²Ù×·æ’L‘€£ûÐ‡¿ÔÞiÛÐt°lÀƒ4’CEb“8mÜ˜8Q\F*©g ‚ÄÐ¡.A!ÉµIÁË$¡÷° êúÒ¡nçÊ`-¤8„h¥Úh*Ff’*½×jzÕ0r•EBPÌXQä,ó¬Šˆ/£oœedl˜ÏCýwa›×Aä0«Ënœ¬)@rä)—“Ež|$5;¾ø¥›XŠL
<)IÇ|©ËÜ1q¹~èÇ?èKŽJàhŸËÖuè@ã—ºìž`dý;)Gg+÷F*× ÍL`ÅïˆÌ?¨Ò›²¿l2J%¨üH$-Åã‡Zâ«+;LˆY¼Y<'¹~^áéÉ1|_Î÷é8w["‘}ü>ÿ ÖHÅÒG¨éP
#Û„&ÍG)m”39Ì: 'iëˆ þHH½Ð¸ãQnZ¹Ï¡=NŸI½ñ¡»H"Ù…äYQ»z_,“‹÷¬Px€Æp99I2‘ánÈÛ0˜äÄFÂ‘ªBÇ/#e$¾Y¡^÷¥%• Þº«w÷(Ì6<Tâ9p?ÔÕÍG“HmßPß:$õÕ+Ú,E%Ãà”ÿ€ßçóy¸í‘ƒÆ¿MªTèbŠø¬íÅL¶&G¦c§Ñ¤Z¯Žx|dæèd½6§Í¥CA7—í,ÂÌ°ä*Nñ<œ¶g ß”LFÁÀá1ÒÖku£~iÃŸ×44`Ñ’A‡piRF‹A]œ$CÐ8mþ ×4'3µ‘J2›•¤V¿¢³Ø€”áy€W?÷H(õªüœ4ù—æ§´³ó ÔÈ°Ø ìÆeõ…Ü–"3Ù"¾A«	b’.(Še¤\Æ²è	‰xJ¼>£ŒD7d²ldÿåêâ} ¤*´ôcê¡}—ËíáuœÖíå%SOOéæ‚‘)ñMƒá8^Äu’Yú2þV²òÍBÀ5   IDATÃE²2õÐÝ/"š“¹’–D$ÜŒD$^!ýÿÙ;€¦Êþ?¶aCt(ÌËHeH/‰5õìUèUB³ÐÌK&©‰¦R"¤xEÐTJŒo¡æ%/”&’ïú±@ŠK5L7Ó‰0›º3aÿçœ³ÛØpÀß'Â³³sß8Ï÷ü®kë˜,&S~MÝóiXƒÓ	¾tNŽØäDÇl3ê[¿PØ. 	µ¥Hzxýš² ÿ¡ô:²N½úp„ÃzõFí†öÃúÛ¾%P}ðm»™ænîÂìSØ/ª"›]…Ny´[±¼ -!…V]…‘‘-'cLæc× 66„º—’Ã´%‘Û®Sâ¬JíµÒ¶¾çh@•„Vºöx‰6A…Ò01øq{D¶o³m…:¼«˜ÙñÙt=¨íÉ-¨]N)8ª5U%;-;ÀlÒ€0=­¡] I"±Ë"”öÛÊsµsé`Ò¦5(EM~¦ÇnXl<æïÏDT=Í¨ˆý¹éØˆ Ê£Y‘äÊoÅã*ˆHþ>tE_UJN:¶°ã»A§/SŠeñg„]d¡ì‚•D‚5†v/d9aƒËNÏQÍN,÷/»ûš;²ÐÃlJ!ÙÈw+—T‘×o™Åÿ!¯O§cëš‰ðwÆ%Ç•NÂB2¬imL&a—Ñ
!j{Hq¶ÿ`ÏVfúú”tº†invAIÈF°ñs>eü-«"¹ºZ²†~¡ú?2W_¡¯kf.vf—Ê…‘tPSÕãëý¤t’Þž¤H¢^:RéXµµµØSä„I÷ï=| ×8Û_ô¦½Âx g©•NOtq"ÿ(›c}¡$†XzD7›Ž6Iò¬ïn,[ŒZ‹Õ+qc×ÑÈæèžä”ÄèmH¥¢m(l«U¶ç¤P5•Ã›5ç¸D„úîÇ£bv…DZªí!ÃHƒGôŠ)-ÜY|xvLfõü¼ÂwÿtÚ–aPBÉÒDØû6{¶<)Ò5#~=å*ŒzÔ’Ù¡âmæ§SçO*¤–°ª,ë¨{´T"Ù2nR•ÜL1&|7ÆÿR•@,àË³Ó2Ex”‹‹j«ÅF¥šèbj4×ŠtÃæÊùS„îÚ”8× ¼]6"Ë~³ÝC#ãL¦U™ƒm"{ž
Ï MJU™T~x\´5êÝ“…î¾¦#ZÈŒA²â–ÈQÁ¶¨x*/ÍX¿ŸúØ©ÛmÑÙO%§};î|KI”9˜²Œá¿ãfeÛwÊŠ¨ìñëI™”zÐþš¢±ù+F`ÃRFZJz©o„MÿJõ²GÊÁeóC#ÃÓs÷‹J÷'¤Sµ¡t*²ÌD1ÔÓ+#m(’>&©¾4CœD9Ý˜NNX*Õ2˜wÄËº”XD]-ëŽ˜´À9a…‰9Ñf¤òÈÐ×f¶Î¤…´…Hª­º)]³µ‚¸±jéBG.Ù¶+Ù­C¢’ËM'árÊDÎvwuµæ6I>Ùj½3¡ÍkWßðð€´Ò‹*2`dŠoÅói‹?")©e‰½™=ŸŠn «DÙt†¹=U `cãÅA¾€ÊjÁ„vC¹‡®ÀF›zÙªÈÌ™* žŠHÞeõàƒG[­Ó‰ŽE™ñÓc2%’l,óØ{RMxpø¡Éß³é~¡¥é)#É#C
e”"ßHr„Ò§°á¡!.N^š™¶ÞšH§æ»·é¡LJ¾¾Ó±¥ƒÞ\rQTÊ·Ü­Lû˜3ùèrÏÑþÀYüüúŠ“uF·cEg‹è6²Q3¢"ºšÝ>£øùë±DsŽK²ì;eR\tö¤õKÓ×§…î‰°KË6,E&OQ©lZšËO:^žmÝMº	î¤+]2)!·4}öÈý|a°/*Í- âôUÌÅÀÐ7:Çé†p<´ã™Òéö°Ö¹öAÝ-ÑCgÐN·þd3°°tÆúˆTšØ’„uíÊÔý¦}mt1IÛìµ>Ês?hZ³ŽŽæžòÞÏçº†ŽA6…í.ÀÏëØY.*(­Š4£ªº¨íÁ·*‹?¦eS†úÐ)=Ú²É²z)/ªð¥(²*	ï1>&!S›ƒÚ²®dÏl*‹,µ–L¹DªèÒÎöV(‰ªjäC
ò¥ 2Õ¦
©ê"~âMIÏ¥£~°¾ÙžduïSÒ
5{~:)ÈÁ65šrSa}çêJúÞ$Ù1Óç«LØ“Øu4#¥øú*£Kâo®¤Ñ²T~³+™MéÊnhbRÉ/’Û¬ø„ÝÃÌvok þn&¤Èë_¦Oy»EWØ¢0Þ8þÞSE±LÖæ´²sKB¼®’  <95ÉfoUUT…²‘b3[¬Ï¦FÇYþ¨Cúº§dNJ/Å6¨Ìˆ=Sì·Æ ÛÆÕûU’R:jËÝ÷+3‘=¯ÉO	 J%)È¦ÿ„ÈHÿd]Ù†A.£­Žš¨£ÚÜÒö%r€§t‹Í"ÕÒ}E­L¬áz"À4™˜ùPÉìò¶$9Rymø2êÍHôoÃRI[˜‘PÛˆ$õõk¨•!þºŒlŽ« @ÀÎ¬ÂCDf$ÜÈC½Š¬XGÅZ©—m²¿'eFb#-½o“*É—TI³¬µ%Q)ž~öuMÚc¾©†‰c–ä’•fs©‚Sc¢í÷^‹J{¢t’hÂú€í¶hXEö#N[¿^Ÿ>Ç]‘œ4ÅÚÆ—“*”Z¯à°éfÅöíhúìý"ÒžÏÞ“Üâˆàeš;$Ræbi jd3ÂŽ¶”ÉÔ •\~1»1å¾‘IMÍ0·W	6oÎ¦ü•¤ÔŒ\\O~UÉ:E³ÙÕâA…‹Q*wl{ŸÖ”™i)iûsi}Ä&‹v¯ˆ´¥OHN‡m³›Éÿ'‹øÌÖfÓ%[iÆîæèˆìÙ’‚””ìàd;ëÞÖjT]Ì¦ftÊT‰µ[¸âèö)î¤òRQÒFÚž0¶o£_àÁ»N7hã“jëêœÈà$'g,»îUÝgvA.=`†²Úù^•³3_.&%˜”N¢u¾ª$²Y$R=m“d‹4¼f¨«E6‡Í²rUòÜõë3|SÝ’ðMz£-OÅ $èTñˆ¨p‹ïrl0Ô]´Îã&åîOYŸ–I{Jð­}Ej’õ
©
?éÇ'hËÊ
©`èú³eÓ2‰Ü²?ÁDÖ¦I•Ë§'ä’>Óû`Rh‹6,2RRêì©ÚÈqáÖVÂÃ—s}|åb#C~’“ã;¦ØTE<ÉôøÜ*Ò±âî¾'®e.ÁSLUìçË0ðÒ‘a+Iì*ãeÝÉ¦'îj„žŸ"!(ào‘\•É¦äP(ä#Qöú„4Ið
+vøYœjKUÁÐEöø¦bßÛìÙiª–Uã4€ÌÎŸ@ËúÈ–æžau”žž¶?Sÿ©ó--1iÝ~ä"	¶Í7ëb³ù¤#R.Àgd½èsÆOe¹éìˆp_w{û[µ2j:»„CÃMÑFˆ6xƒlò“Ai$ßàðG3û‘[ðgä ¸²Ù¾ÍßòÃ·5úÔ6¬tÆ$õ.“lóFZ•jïüùàaCwð»5F£R°o_a±˜$,¦ù‹©FÂÕÐŒ„…” 0VÑ‘iUUÙñÓgK’â"é‚g*Êœ@Gnº‡F[ž‹ƒªré¶È58ÒªGQ6Œ°J*U‘Åþšó¸‘Ù"ûÓÒÓ³u)Püà¸¤¤ÜÚÉ8¦˜4ºŽÞFrr¤]ö}2)C¶WQ%qEûçÏfoßn•àPUID¢‹¹™™™¹õA9ä#.zŠµ]äT\jJm…rRÉøGB™ëäT„RÒ¨²gO¢mÉ§\¡ÐDàöE×&»7WM›¥RHÛ—Jàj$´X%—ˆJ/ææNš^eÑ¡êÜ¿”R$+LÖÿ	‘•¹~˜¢b?JüˆA¬Ðh­çŽ³33|êds»èè)Öþa_åþlQ3]Ø¢FÝLädã—Ý\ñGÒpdÇ®¤ƒ§#°Nh‘‚Ä_«=?D²;¤@"?ñ]˜òŸ§_Üs4©Ég*ÏŸ#OŠ›¢UPøb¦ÄÄÓiüð¨Gõ·»º’ß{üÍÈ¼(÷5óoPIRŸàFÇ$Q¿i“Ú±4Â¶–6DI£Qÿumç$§WÒ¸~­‡Ôq¹'cUW²Ù”>ÂÿÓŽ6]IS­‹ÍF!Û4 ’Ìâš*§"•±º™Û¤õé+±ÂR•›B‚i>q·	Øã H+™WIò‚ôøl;Ò§jc{Ø”¸¸è>E¹“ÅÀ÷¨q+Œ§ÑØï6w2¨Z>}:þ±qa¾{s%”)ªrfÇ¤_l”ñÎæC§L‰Œ°N*’90	ëÓsuEÿøTƒy½I*UMŸŽ;¶¡O®­Ñ‚"ÚŒ)²±>X¬EÓbâæ<„¤ç*û+iáMjX#MÒA!ÉK3S´õ)¹2›…
òNÏ.¸(i jÈZeÑ-üRU•fÐÝø,AnØë±ƒùd;úÆ‡Ð =^%¯RÑQüúÈ&Ó°ÛQ!©-¿ƒlÉ­¤“\,­R5}Øqu­ÊL˜”™@U¾@Ú "„èê±ì^Ä–ãHafBÁÅ´IþiËk¸ò}…Á¡ÁÁÂ†6ºÆÆ$ÝoÚ¤„°<¢`QËÖV—Ü½~»fçTQñO†ˆ |åª¯²”Rg6‹…¿¹,,”ÈêH¤£ÍÉ‰’Húh¤z5dkˆ¤fÀ®¤ƒG…¤Y&³ÁIÕ<‹ŠŠZñ·‡¥„ ›iE¥î‘qÖ·ær}ù*w³u•\‚C}÷S"	?ù’ýÌ­-VˆjXæ†*Ð/§J0ãQE{UìX%ÑÁêÉU“b²«äëcRšo à.œá›q1—¾ËºûR]¦ÂƒZfGc“U¨i!‰æ±“Î²€_2>>IB6ÛÌàfòÊ«
ÒæÏ4Y'	YF}gZZr˜,L@jQu™1³]]÷$5®¨‰ÕBAfzJZ†öCÖæYn»ÈgÒ1–ž¡ÿ+„'%'YùW†moîUº¡•üƒÆ#_„®úTË ’ªAwð›×4Øs:{LBÃ*XdÚŸµH[qV¥já•+Yþ<]D·–1ö‰à[õž)ªý))ûsKõJËð¤äèp[´‡s%[iî/ØO{©±3.b{óþõØÃ½ôé3&!1É°k	ìéwXÌŠËgTš:¦Ëf·ç47ÊÏtêº š:t£‚ýP‰Å‘³³36!Qõ#™t^[£ž#õ	n¶6#!I­ÞQ©áQÉU¥t°uãó˜{ÚuNþá¯äÆs±U'2582Y.7µ‹×.   IDATU:Æ,î¡Iß7—ÆŠ]AI{ÜC%îB[õlr¬ÔÃz¶ámßÕÂ
xí[±"©@sÑ7Ú²Âd<Óvv®ÄUðÈgG—%/e“·w+>ê(P°œlÚjxò‚´˜”ì*ÌBåÇoeÑèFÞ"Øø@7ÁJ&ðH…Lip•ˆê;ˆ‚@Üƒ£“¢/NO©ÂÈ‘áy’½Ú¦§éFr× l˜Œ³]MeÊ3i¶>ÆŠtŒÅÅµ$°šÍŠ“ì—ð…ÁM¬-ƒ0ÛÓ¢òR–î€®‰o(<øÁ¶š²*•œŽýWµ0~Ñ=8éè¥Ul¾¯ñdcþf…¯Ø-)Õ*]¼¨ñ¿7¶ ò`E¤‰±}£¾ÿ+ªñÖ%Ù	d:–AÛãHdÐ@EE¥»’a›¢ýiS‚£ìüŒ†}ÜHó {Ø({’ÆÑ‘‰ßPßPTˆºŒñýf¾DJâïß4¼gÓuBª¤Ì:‚4‘$ü›éH•Fr |m´³MÛ²Í¦¹lM‘d9ÍÔc±bC®-T/®H:…lQ3ÏxŠŽT‘q(¤uŸ,›a¿)Å:ÈêB?«,÷7]T¦ ›ÑÒO…¬9Ôd¦+ŸÏ]ÔM\}#¢Œ™¦È<kŠIš: aÔžÓ‘:ÛU•»?MkaÃï7Èc£ŠšöRuFoßŽbrÙÁØº`kIŸ¶'£ÙÔö±<z‰@ZZ±,|+Àß^NVP—PéV®&$D{Ãæ‡'“=PáÓáMÿéLT¤ïËÖ7Àª‚´lCâGÄES¶y2“Á ls°ÇÌÎ¨¯ÓPitƒ7Á­­@‡(98à—NHÍ¹cøSÏhB­&C¼*Uí_‰.=4Ýû:8±Pç@óPnKX÷«±bwqÖÆjS
	;Ù¨ž.ƒènÝµE¶Ns‘X[ÐÒÚËí»£Æ¬6Û'žŽ£Jyk•íÃU®SÒ?+mò¬\¿ºodêÁP•»~43ˆÂc]ÒAn<4-=cBÉ³Éþ¶§ãPkAÚêNG ÎŠ+i2AvMkH—6C.Ê%{¦!&TuÍOHWg£°$òJ!ª¥|•²^§ÑÓøw]Âþ#—A}ýêbÝ±ÕÁÑÁ‘ Æý[7ë8î¨{_Ä|\n_ÆÐ·%Ž÷ª±ba]Äb999aK’6
‰–Hf$ú72t´LØI Ða»¦ß}ÄÉìêlûÀÆÐòD^š}±*ÜX9xì†Î¥Ú†Ãuþ†FÕ’4M3Ý(wítÃ:É ’†üE¢TúáCõýêÚ»Ò:NO‹;ƒÓ=FÔÕÖ2TrÆÝ[ŽÊ[¤Â~5'',’(aDj;RHôF$ÝïÆmMG6”J ’    À®|:j¼*;fv¼|E´aÂY/bJÙÁÍ=4:Ú°‡Bã@mºZõ’H*DI«“è\-jIÚhB·¸wbÖ2ÔØWKÜ®­ªÖÜDµÎ®š.\ÇÑamKµJ£¬aÜ“9ªî8PÕ5±Õˆª„Å!“IÅQE#õ-lYÈòHz…¤ÓD­–"	    ÌA&%D‰f§Íû&eÄ#m£iU•.„›/Œ\a²ð™>Ó­>8‰ÒIdø6íƒ£ƒ¸ñT3²Zª@"­°ÎÉŒZÆµäÿuµjeJŽjþªurÖ°º '¶Æ‘M
&'gì’c8Ú×°~ÿžR)«v}‚å„êµ*ôPÅ î;ªï;8‘ÒÇ‘TFd86éQ£4åZs¤çÔ»Ø²t
I£7ÎÙÚÑF"	   è°¸
Â£âÜ%r÷ [Õ“0
YåthfÆþŒì‚‹d%-¹¤´”*[>eJD¨‰lbÃ¼6òµÞëÖT'Ñ
€r½Ñ>¥:<ñì[R[K©#<Aj¤º‡µX(aê4šºZB®y@ê/2n¿Æÿ¡VÑ
Vöó´-²šò…¦iî<ùóæaíƒ5ù»‹3-€˜TÅ#º›“¶P¤ƒÖ|DÒèJåº)=ÑÄ†dŠ1I     ƒ¬—ŒÚ÷€ð(üƒ¬£Qp’>ˆè$ÚïF6(ÁÊÔ:lPÂJH¶D™”°<ª%5R­†ÒIx3”PÂDSK½$ýwˆÞåàCíÆå¿ÿ®®®¦U!5CSuófõ­[ LA”a-D…h§uŽ:/#¹íbCú²ÚMR«†"éi‘ôhÇÝgSÚ±uwï¢VÛ    ´ƒ“êÍIZDË#¥t4”=‰ô"a×[]øŽc-)š°|"%©Š°6Â
‰”Fu¤%‰\ƒ´$Q´ïˆèìÌvdÐúˆ¡¯;Îd³œÙÎ¤ÔaèÒÔt®4G]–#ígÔÅe
#UÔH!µF(’{opë±d¥Ëˆ‘½×mºöþLÔ:»    €V¥©NB~7D[’h×¥›°ØÁø%í«Ãê{ßœ4´&ª£ƒ–¨–oˆ´-i4´ËŽ|Òîµ>ÞÞ=ÜzÜª¾…¨“Â?<Þ3O=Må©‘6$R÷h4ŽZK’C½ÑHŸ¶¦3 é52Ìek+…„ìÁÝÆèò„æþ=£o¹ŽÛ}"YH3<ˆ;}¦lÏN    Æ–$êê%iI¢d)•è”I	H%úMé'G]ß7©“µ"I§¹-¼Ú›ýá‡~–šzCzßÓsáÜ÷9O<ÁÐ;Ît4PB:yD[CŽênI‚¨õjww›£[~Žþ½hÞƒßJ½Å~Ú»WR}cžïxïçóªÒ    ¦:I?Â;è
sS‚a÷“†rºé¥=ÍÐ©%¤3AQ‘ßtüw=DRÛX•wé5`À¦O>ùûï¿±ÜéÝ»7C—’FÛ´'­÷šÈ£z!Õ¤J¤v}Å©ÖWH¨ÝmŽŽ}>ÝâÄëÕ'uÇÕ·^{(½¡›—ú¤l5\{gûlÚrå1u
³ÁI    `¯0šÄ$¡F#=Cg\ÒæÖè…u+è;œ`§éº¢$jjCjû€]ƒèß¯_£SC”K¶10#5’J†ôrÊ0ÛŸÑúgç€Ú÷èØ.ƒÈ6IN\·¾[Ò±0Ò¿Õ{Ý§Ì>}-Ïôàõþx   €‹á¯þ‘¾c«Á{Žô*ÞAW"È
å¡ë8ê[½ê~è1jCÇVÛaô0t%³õ‡mP1S[(’¡Nbè£Žè–ÀT jb:b´‰þk7‘ä2òîÔú—l¯§ú$§ÒÓnïÎq	þ—ÑµÈà¤·ßE    Ðai4Ò3L&º>®ÚXft 5‡ƒN<9è¤†V‚è”ýãH½l¯*¤ý}íö¸5=T†N-igêŽ™ÖCÝ4}5P“ $ÔV
	µWà6k€W¯5Íä{©çÂÅÊr{Ì6³nÏù‹îþÁI   @Ç…Ñ¨Î$…†
ÓFºùÚ|~:åŽCBZ_½Œƒ.2	õÜ™Šà¶•Â0¶qú ®]»¶nÃz±XŒ§Ÿôô\¶4¾W¯^Ú“Ò-ÖÈã†¨è+ÃTzƒÑõLÑ–$†s—>ŸnqèÒ¥é[nïÌê›²•á`î¨NN}?ýÂÁÅ   @‡Eo)AMMJ:;‹ƒAsS¿CoqÑÙiýÐ>¯ÆØÊbÔh«:ÿÚÃÚÚ„µIWÅb:ÿO¬Y·–®ÅÐY¹x	uBwr}ÜRSR[*$Ô."©÷Ç›Xý½L½k‰úqâõêýq
   €NSÀhh6Ñ;¦ºšŠ“NYºØôN7ƒäùÖF¿»?ÿøãÆõëZåP'!¾zµ²²R{¤ÂˆöÊéOPoâ2”GŒ6w±ÒÖî6î´HSñFVA'É¾ú   @G†ÑÄ_VŸGÏÔeÎk<qFDƒ~N»ÖIÒvjx0uš:†>CM—ã†ô‹Ñž5QGí"hÚZ$Éö¦ãØâÏCì#^ß°yæÀÖvD¶å¾   :Ó¡EHW…ˆÑ°š¢©8¤vTˆª¸ÝËÃCzó¦~Nß¾}½Ÿñf4ôiP}'úÔä\Ú÷DP[“l}§^kì‚¸Q–›õmVÎå25ùš+~3ê½ñƒÜš.ªø3gïÞœÂ²2‰ŒÅøø‡ŽŸúj`/V£íÕˆ~Ì9zâû3ÅRj{þ°°9QS‡4^N»ð¥ì}‡ÿ[TV.U"¹ÍÀñ“FŒ«BZ|N¬æ…÷lò~MÙsòÅH6âIs'{)'÷G)kHXØ™¡É¾,=/âÆÏY‡Oœ+‰*ñ9áåðõ|7r|3»k3jÊN;s•`=9lü«þn&ÃLÎÑÅ„ÉXžCÂBŸ”v]—!a/7ù$eÙ'rE5\ÁË!¡©ý+DgNä–Õ4Üþ~òñ8°èS x,1T?¨©aIW©þ]½y©áÚ6›°:!!qÍµk×ðËÄ/YêÄd2èú™:Ìê¡v?š¶IL¾'jeXOöC6…go\öÉ©á<™(wkŒH¼vÃ‚!ÆPÅ¥ôÅKWbÀåáS•ŠŠrÒŠ
Ëm\â©*‰ù[W­Ë©ÖSJò/I—$/ÑHO4Þ»’Ü&þ9S´ò³EÃšà„ôÒ"5/d˜^#5eÿËÉÉ?Wx©œ$‚O¢¦kÞø97ëÌ™âKÅå”Âá*ýCÌ«–†û²ô¼iþ–Õk³*,'ÊM_š&lÉÊ¹Ãzµ·PªùqÛºÍ¹ä÷dõw3y<„ôÇ£‡JÌlŠ†Ž0!’èu™"ÏaÃ.†K¢IËR•LŸ‰Ioë>`EeÎÁÃ…Ê&›9„ÿçø„Í™;ÂÓ>&  6†aºÚdSCQ#]eôï×oç¶íb±ØÁÑß—ï ÏÇjN	Ù‰6ÒÓ"‰3ôŸ]]ëîÊQëàÈå>!ü'²!Š²ëHÂä}ýÍ°ƒ=å¢(ËMßøYŽX’µq›ÿÖ%#ôJEQ˜¾‘TH¼‹’(UTs)}ÝÊÃÅg¶nôYù
5Š¢¬$JIð¿6i|h Àýxø³ÍÇÊ¥¹Ÿmö¬3íjò7¯¦9.˜IZžIßœzq9F·¦øÖ-#†Õ['jŠömÞU¨nîD	ñ¹¯>;\Þìr&öeñy7
³Îà‹Äõ‹œ33„<£«ù‡ÒRÊ*³RÓ‡ø,î†Ú‘šüô´\©E‹º^_²axSåB(ËŽ~öe‘Œ;ôÍ×VZyj~Þ²+$ÄŽ‰Ÿ:¨ñÊœÀw½6ÖT¡UfÚ›/)Ïúx±¥Äè…  x|i¤–QOœ	]ûâèèØ¿Ã9fŽûÃ0   IDATÐÞ^O[ˆ$G·ü´tIÔŒº»¶ï(âÈuã§íÂ:	ÙÁ«CD¹‚™‹Æë¢nÜ†,XEÈ¤Êò³òÅÃ^Õþ?%õŒçk‹jíFnƒ¦.˜)Z¸¹¨ðPVÙð9ä˜ÇòžVx‚õÚâH½Jðòœ•H6wm®¬0+ÿjÈ›OiU’âÒá/³±¡‚¶*iÎp­AÂå©R‡E,#–ì)Ë/Sò†÷ª^{…$}Bm®ø³9±Y¦Æ–`|jæxrª&å¬ÕùJÔ÷eñy±ž[ò™§˜å¥÷¹<9ìÝx–rÎ²,©¬8¿²f¸[û©¤šŸ¿Ü–#eúŒð—ž)’5·4«—`PSa¢(û2­X†¸ÁQsB­3‹)Dß~¼.K‚˜>‘ñóGY×…7Ðˆt2|hà·	ñî~ü¾ðê°WŸk t“L½Õè‡ÝFÛÎÏú÷?’¥Ì?ûPzÙ§^}\þìØÓÙÖ“#b>Ñdf`È@Na¡Rú§Œ@´‰HZœ]Œ•…WhˆA3ËsXHàÎ¢\qa¾X1ˆ|ƒå6|NÒðÆ{qÈÍÍ‘IËÄ
ô-jŠOäb‰Äœ:¼‘ËÆ¨BÂÈÊóË•ÜÀa‚6öeùyaUä?°Ñr.^Cñ²²¥ŠÚšŸ÷¥fK‘ç˜ñÃÔégP‹PˆNl;Šl¡‹æ¼Ì³jMìæ[›†¿B¼ø%ã-ƒwŒøUñ9¥´\ªxõÉv5Â Ðö•vånkD•q¨-³Ûœx½ºŸ€:4LŽÆ”„Z7¦+¤Åbì«âØPÐ¸üÌÜbi¹HFt1ý¤ÏáâíÉé^5åg.a[/p„¿¥C_MÙ™KJÎ ¡>m0VZº¯&çe¥‚¼’..œv3†ÔîÛü½»´ÞïÃÚ×2­FÜ8—~°\Í<uš¿U‚âÏ£ë6æHÇ?jÅœá-	`g±ÀŒ  EÇ"öL{6¸ípjÙü/—«Ô	…XªÀÚ‰çÕØèÃõôÄÃ¥Zö§Y	!#WÇþ7m¬QS.–‘¡îþž–†µÔ”+WrüGldÙ¾šœ—ñ¥®åPŠpXhKžâÏœ´%Ñ­;ô³´¥†(Å¥ƒ[³H‰9çåÇ@cÙ!2 hÄxëâÏÉ˜÷mØˆÄ[´Äº0&BüsiÀä*€$7  €V£}z·uLˆ…¹e¤ÝH0H§‰”²2©ëÖøžåÒ‹Ô
Y™ñ»¦,·‹Ž—^)d22&ÆÇs©)ûïáC'ÎSÙiLÏÁãß]0Íˆ±¡Ft¦XÆñ6Ð¦AY&Ž×Â}5=¯†5WË‹ós²¾Å#=“:câ «5’¢,}ñ"*£•—‹–¦,µÒÏEnäÒ¾ÏŽb;ÎàÈ(*âµˆšÂ£g°ƒÔ+ø+‹¢ìÐÆu9d¬ÓåIže+âë&K¥•?~4‹W§  Ðz€H²BœŸs‰ÔHƒƒu–‚ (‹ÙD¼Ð~¢Þ3gd{…'
±–à	®wÒ((ÿK¿ñƒcùR&—ï)àË®Jdâ¢Ã«be«6Äo(•çÊdLÁ°n­ïv±p_FÏKÿÞ·±Qiºl:&?déÊ-1á²²bƒr²â|QÍË<ë¤–¢øPÚ1,‘£æ„>BìsÍ¥\|¶Lÿ°PË:ø«´ùpz1}êò¯ÒÏ[i:Ü[š‘ÕhwðœÕ–Ç0   -D’…WsÈ¸<¦NoidÎÏÃD:•d4%íÇ}û°“†é3þÍ&•Ä9ÇÄ¼à…_,x•x	éÏ;W¯>Z™³yk `‰a”B”_(eœäß-Û—ÙóB,Ïk @!½!!ícjIÎÆÕ„r‰ö4­‚ÅÂ—W]ÿÊÚØEÙÁ­GEèhCdY~9y¶Ã},ÿÔåÇÈ¢óL¯×â'¢/?>&.Ú··0pñ0"É¨ÛºZ)]*,—m]0ëLèÔQaàp  h-@$Y!ÎÞœŽ‡þFc*‹ªÉ`¥¦b‰22qXFŒL$7èÂ<LŸ·ß{Ê`	j°Çž”Òh¤4Y¼!oÏ	+Œ=&.Ìº$ÖK?+Ä?K™‚ñm¢‘,Ú—©óÒá6|ÁF*Ž¨)Ë?¸í³£å¹›c•ÌmA)KañÇãkKqüÃ^õ±J*(Ê²¶~[©æžòHõk*óÉ°*¾Ï@k?îÐ…KÞî©@çÎ­Ê•fï;úªÿ»Æ-Cnƒ^ŒjX=IñgÎÖµŸäd§®fòR¢!½   U ‘dŠ²¯>ÙZ¬Ô¯ÔÃä’¯´“ÌBA#ÍÛ"D'6¦æbÏÔ9¯6YôòžaÂt\ø¹ÇÄ2i™”xE7+Äù…æ“3ü{µ…Fj~_¦Ï«),·#æ&zö"ëý~¹¯pÈ’aÖõ¬^#–n„œÉ)VxµÒÛHˆr¶ìÃVAæ“¬ÊéiºÙjq9VSxhÁuñözÈ æ6«–‘ÑWžyÖÙsøã- t¡Û©S+îÌ±n¼À²ópy*dÎBQYÌ1Iöáü×ý¡T  @« "©9ñ?Y}©øÑA£à–ÇA•J©X¦@ši‘³ãÝdð$¤ù›×¥’’ËÿÝÅ£J\x=™ßKk¤R5¬*Är!«mË%AÔÙ¹B1ÓóMÿ6èMÑü¾Ìž—	\ÃBí,.”•ç‹ÃÜ¬ô¹xy5r²–š¤§N-:—%jò®²27«’l.2<¸9‘D(D•¤1«—§§UÇ.˜ó¶ÎüÃòñöÄ¬Â]•Å‡÷þ8léË–Z¤\<ýœc(•  Ðz€H2VHëâ“Ïaãˆ×›Kbš`.žƒ<™çÊ›ÔC"ë')±KÅSÀm°
©$âWSµC/ßÔ àæåÿ$*‰Ë¤ŠD¡ ºì²¸úàB|©PŒÈF³m¡‘Ìï«Ùó2‹CšÎÔA´a9I7ÿi«7„51þÉ~>üÙ¡b%/x>¶ºpyÍûÒ?IµÅázZUè‰ÉáÖÎröý²,é¹/]Ô¨1 ™ËèòP*	   µ€:I¦Q”\¶0ùœq|ÞMJšéodÌdyú÷Äƒ|Ùå†	äØ7u	[¸þ#«`+D'’bI%Áô
[›´`¸±”u–gà&’œûo¥¢Á±H
ËÈ-úâkƒÿ(V{ólýÀ]óû²ä¼RÑ…‘ÙâbYáÀˆÅ­5qñè?hP£Ÿ@ÿ”¢eñ	4H`ëò¤XnÜG;z·!“fÅ†BiNú·e
‹V!Ät=
®—¿g[^:  €Îˆ$(Dß&ÄYL&.½›˜4ÉT%e²	5êÂ£G/éF7BœÿUŽ„,’R¿!ÎÞ¸,5_J6e[¸`ˆ)¯
K:qI³>KÿQ_#±æÒÁô,1BžÁúX%âFq¾HÍóÖÉMæöeÑyIóÓbçFÎ[ÕH(Ê²¨Å<Ìn£jnœIžúïWB¦'ÿ(mj{¢Ë`q¸ôˆô6ímü=ª<±ëÌÕfŒjDèLJìÂÍd	~èxÛ  h-ÀÝf2)žì¨…È|í/?xãËÆKx½ûÅæId”-ëÉà©¡YË²*-™Wùò`/’çž‘QÞ_×Ÿ°7jÙ'ùtïTIÖÒÉËÞ`OVòè&·asŽ­ú>kÕÜ²aÃüyL™èUœ‘òîÄAõv$lHàÎw1vøÙ›SŠ(AB(ÅdÔŒ²0-6êe(ñ[°8Œ
ÆBð“Ï²é±Ÿ]%OWö¿Ô…"ÊwÄ¼¹˜î·jf_ž“Éá0Õ•çÒ>˜ô­×@À‹ÇQKËóó©@i¦Ï´™!žvª‘°Áæ{²1’žù6ÿÍ!ã'AÜPªÉjn.|øXO;{L\¼oo~`üƒºÒìÑeº=
±DJ—>àú¿»hÉëþ`G  h-@$C!.,o¶¼·!sW®dmÝz´¨8ûûbjG¹pF}Î‘Bzé’YŒÛð9×ò>Ù¼¯0?§’œÁäù™ú”n@$de?ŠÔ&›Ú*ÄeÅ•â†ód’J™öMBWº©FTY.jx`j©DË¬µœ˜Ù—¥ç…/QÊÎ!GSñI+‹ñv>“8~NÔTû5#‘…È•ø$]z5<R*)!j›  ÿ7ßÎ]•+;³ïð«rþñgR©»ÌL.ß'P ä?40xÈS    ZF™H‚tñôíñûä™šFÐN    €ÇˆI     0C®¸À’     Ð°$     D     €@$     D     €@$     D     €@$     D     €@$     D     €@$     D     €@$     D     €@$     Á	=Ž<xˆdj†ª©ëjü[ƒÔµ¨u°ne:"&1ð†í€ÜX¶#     -aÈ÷ÉýoÃ	3ÓM_¶;XU«5*ÆýZô˜ñ„#rckz€Z   €¶â1I2ºvßáÞc§šÂqB}ºÔqY    €V¥Ã‹$¹]U2îÕÚ—M«µyÂQãù„¦H%    h5:°HR>$åÑÝ‡KâÊ$¥çñŒ+   €v¦£Š¤k÷×îw^ydHß.š¾Oh     6¥ãY!j5è/£š …¤‹Åµ¨¿‹Æ.	    ØŽV'‰¨Cew@!5_|YˆŽRä     :I$ÝU£ßnwºmÁ—_åC    €Mè0"©Nƒ®(j($“à‹sYÁ¨ƒð$    °F$‰`Cj|‰*p•    ÀtŒÀí¿ï3d‡d5ãïû¨O0(Ù/7«ªðoww  `k®ÿý÷¹óçªªnÞ•ß•+îâ9®.]»ºvíÕ«÷Pá0XL(pÿ!*¹ƒw"Ér4~Ý4] ~’½òÕÞÝÄ˜6m:  °÷ïßÿî»cygs¯^½bf±ý½^~ùå1c^uvvF@st ‘T!w¸£F€Utc"oWÈv³Gjkk§N{OìÛûµ£#´â àQQ«Õ™'Ž9rèîÝ»®Ò½{÷·&Myå•;9Áó´9ìýêÈÔÆ
Iõ@U)úóÁƒ¦à<Áyf ²oðE»C è[b‡œý!¾‘ýáìˆà  à¸>çr   IDAT}çÎòeK/ÿUÙô­=zôìÑOÜª¾U]]Ý`­Û·Ó¶~ñÿwjÕª5]»vE€	ìÚ’T§AÅ·D]Û9ÚrNþ'ùãuØhi~±~1i²oœI§›Ýô ´,^¼¨¬¼O<ëûìúõÉ   ¥H¥Ò¥ñqø·~Ž{O÷—‚‚_|áÅgŸ}®ÑÂ%¿–œ?îl^nuM~¦§§gâšuXN!Àv-’þ¾ÏÜk»Aþ×KÅsgÍ´pá¹Nš2Ù7O>¡éÜöÄÕ«WæÎ›£ùÅç[ûõë‡ €;M

Îý7û¿µÕ={ö÷Úø§O]ò?~?zô™LæèÄ3zô!/2™Lt2n\¿û!¶	Ñ/»téòÖ[S^ÿ†ùµT*ÕÑcßbßž çðx¼ë?dûIê:tIÆ¨kÃxíÍÉÉß>lùòs,˜4u*²cšAÝ5ÌVVýq&5uó³ÿ£ùï~Þ Ø!rëVìG17L˜Ñ£ÇÌ›;ßpŽF£IN^Ÿ›—k8“Ï÷\ÿñÆnÝº! Ó€µÑ¢˜¤ÒôË¾}û&%®ëÙÓÒœÙë×ÿ^¿¤ªê¦~õMŸ~öÄO  !ö+’î¨ân›ï+—.ýß©S¨`³Ùþÿ´pÑâ'û·µÙ` k]WxÂ´îÝSN›6EE¨ôsØ,öþÙcÒÙ©­­Ž^`4¬dîÜ÷ÇŒÓ¿Ü`ï×_hº˜Ÿß uk?F@§aþýAOûô]¹2ÃáX³ÆÁLþþƒÖ&ÁW¨1ökd¸­nëhM]k¥ƒa«æÏçšónäm™µ-mSäœÊ1THü2''žS§sŒ*$ÌW_í®ÓÝšð¨vðà×F+)¹t¡¨ƒS§OéRŸ>}L)¤«W®à/Æï¿WÝH÷nÝV¯^Ãåré—ÅÅ—~úé<b¿"éÎc7º+îÞ=~ì(j[ §ý™y¼éÌãÇ! ÓSúÛo¦ÞR(—/kõÓïåØÝfjÉ’_KÐ98°ÿ+zÂÙÙyÕ
“6¤¯î_²ô£”Í›LmÇÍÍmÅòUú—ûö}…€†Ø©Hzð=¨EmÃ¡Õ¯Æe‘µ-ø2>€®·vÀÅ‹nÜ¸Þtþõë×ñ:7uuæîwwîÜÑ-fÎÚ}G~€ììÿÒUû1'LêÓ·¯©%ÍHj=O?ýÌ«¯†ÓÓ•—+Á˜Ô;­“TÓf¤Ös·é©«m‡øböq‚·¶¦ººZò;·±‹“›{ÆÔ’ØŸòRPp·nÝ°õÛÿríi&Þ}úšy—Ï÷¤'úôîcf±¾}øèœÉý=í@ãÇ7³¤†Ê|jV*M™<ûýéd·¼¼3/¼ð"tØ©HºÆÛ³µùSôÇÉ“'±¢$)Š”J¥å«—W”ãŸF3±ý¼[÷îÝ])ÙÔ­ÛèÑ£Meƒ#‚F<xÀ¨¡Èß¾ÙÖ“ýúá¯>Å&“µI;wïÞýUçWý÷+c˜L”îÚµkPP0yþ§ŸÔj5T”Ðc§î¶‡ˆ¤±p1[<naÛOAA~iYéßÿm•B2ÞÈß×®áâÍöìÑÒãö˜Ìšõ^ÓùXðÁ"Ã9‹cb9Æò´ßŸ¿°gÏžxÜù©ð'½˜:l¨ù…ˆ´!Y’‡þâBzâþý{¥¿ýŠ v*’ÔÐvÌvÔ‚«­õ™üÖ”ØÅÙ¼ÞàÒ%ËÞzk2wÂ_;ÿýžžZÏØ„Âaë’Ö»7¬|Ó—ÏOL\÷üà@ý//ALLìÈ—G" P]u‹žàr¹ú{!ð¼~úÆM)tØ©»Mãºí€‹Ù6`{5Ç[½z¥ü®Ù×®®+W®öö¶÷F€­xå•Ñ¡¡ÿÆæÃºÚ‡î<^¯^F{úégV­‘HÄ2Y“ÅöoHgBžïîîÑìÂÆ$!ª˜¾}ÑíMªoU#@‡½ºÛÀ’d;ˆ6Ïì´`A“²9µÙ \ñô|o
Rgžõ}ÖÏo)…¤‡Ï÷Ä‹BêlÜÕ‰¤nÝº#›Ò«Woz¢ºDR=v*’ ŠÆvÀÅlK<Ü=6}ºÙÏÏ=xõO?Iñ°àI €N…£Î§¯Ñ4oK oþXWÝ¸~Ýò-#xê±S‘íÆl\Ì6†Ãá$®Yû¯–Æˆ¼úo¼z—.]ÐÉÉdååe%%—èŸ¿þº¬oA
 4=Ü´õAjjjš]Ø™ºÜ¾sgæìÈ;·+
3×ÜÒF;õè õØiLË¡ŠI>®°@$µ9ŽŽŽ~ód¿~»w§[¾vµDÎ˜9~üëè4<|øð‡øéü¹â’b};wCú÷0ò_£ð:=\®=QS}«Ù….øÀ“ïyàÀ¾*Õwßý¿ÿ;5mêÛcÆ¼jtá[ºº¹¹!@‡Š$&Cƒx‰lu1v â	üñÇ?žµpùÿ'(¤Î¶œÈ:þßÿþG_PÛ(Øž„Žýö™g¼Ã^4KptVôùØ>$‘ˆõ…FMñúëÁ#þõåÎmygóîÞ½»%í‹ÌÇ§¿=C(lP>àò_•z;“~ ²[w›?l\ÌvDTù§åÿYÙÖ]k€öâ»ãÇÞùÎáÃ‡Ì+$C~ÿ½bÓ§ÉóÌ»rõ
:+þþƒœéé‚ó–¬ÒÃÍ-6vÉºµÓêG,'&%,[/3h¸~îÜ9zÂÅÅåÙgŸC€;?9vjáêÀÅl/är¹%ñ’z®ÿý7^;~½cÇ6µZ¬çêÕ+11TÝªB@g%PW%ëôéS–¯åç7(mËöÈÈ™Îl6~)‘\år¹úwÿï§é‰!C`€Š$W²×8‰l‚Æ•	W²}()±ºsí¯ÐÈýq§ººzßþGêµ~ÿþ}¬±ÐYþÏ—è	±øêéÿ³B'a^ÿÆöíéÁAÁÓ§Gêg~ÿý	ýã\PP0°S‘ÄrDO8BL’pqb@àv{QZZŠ¬¤¬ÜêU€ŽÅT G¦¨¨•—þ¤âß»wµ}ÜÜÜ/þèå/Ó/oß¹sàÀ>zÚÇgà!ÐÝ¶ö;~vcýÃÀelGJËŒ+ž.Fß*++CÀcú¡:NCi€NÎl]§¿[·n­]»ÆhkdKxøðáêÕËoëã¢æÌE@CìW$qat·ÝÀ×ÖNñÇ¿7Ÿ·mÝ‰^ÒÙÌùý÷
¼"  0¿ÿ ‘ÿEO_*¾”¶õÔ"6mJþã?èéðð±ÁShˆýŠ$Ž#rlÛÜõžîî¨•q÷hÓÊør Y¸(/olêÕ«wRâº¸¸%nqqKñK}+ üDXñ»Ü1  <Þ¼ÿþ|//=}òä÷1‹?”Éš//©çfÕÍßÏÍË¥_è;óÝÙh‚ý&>1¨w$¹‡ÚŒ…„f:„Z“‘¡¡¨Á‘]íÄo¿ý¦Ÿf³Ù'Nz}üL&Óp™Aƒþ‘¶eÛ7ßf>|Po@*+-õ{Î)Ý»uƒÏxt˜LÖÊ•	~¸ ºšlµ†ŸÊ¢æ¾÷Ö[SÆ}ÍüŠØWûÍ7ß|{Dï´íÕ»÷Šå« þ–QrÅ}D•úÕÿ6œ03Ýô¥Í©Ó _dŒ‡š¶çÿsâÄÚ„Ô
tqv^?ê•WP[áÄÐüƒ«ÆmíÅòñ/^ÀBáÐÙ³£<ÌÚ)oVUmÝúÅO?ÇÓC†¼°rÅj  ÐÕ55‰‰«=ûøV3âå<äYßg-|é—‹?þœ›û?ÃÚîøQí£¸¥]»vE€1ìZ$an>`ü¥lÓqþÁý—+E<@¶ÃÙÙÙë©§ØTuŠ6£?GãáIíöš½9)ßwÞŸ·àùç[¸Ö…EŸ¥¦(•Ê#‡¿E   –±ù³M99ÙMç÷Äôè©~øð¶¬¦ÚX¯·ñã_7rLcï"I£A—n3ˆ:°‡XGGÍsÝ4àkk/®^¹röÇ¼)“§!+ÁN·ÃGþkÄÈ>}û"   ËÀÆ¤/ÓwZ^hmØ°áoO›ÞlWÀÞE¦š`ˆîÂhoOw­ã²   Ðy(ùµäìÙ¼üüŒ6KÆðzõ>t8öÇyðB€t ‘„©T0n©@'YJO¶ÆËm   ”Šß+®üõ×õë__uttzÒÓ³Wï>O	ú*”€…t‘T§A¿ßeÈÕ “šÇ•©y¦+Äk   À£Ò1:Và!ÿiMG°Ž4¾DøBB   €G§Ã´õrt@Þ]5LÐI&Á_"GèÔ    ¶ #¨,Gôl7Í`O2ÇIƒ/Š   €è`f–ØMÓÚº5Ä¥èªa	    l‡ý¶%1…#	ºjœï¡k÷!ô†äÉ'4½º€j    ÓñDMß'4ÝYñ½NòæÊÔx>¡átÔÏ    ìšŽQÀw„¥Ò½ÚÎ%•žp$åQ7(	    ­F‡·B`¡Ð¥©&Ð{e-zìÁv£>ÎuÜ6í    ‘ÇÄUÓƒEFsuèŽšq[Í¨ö1ŠÒqbžµîX25LˆÎ   €6¡Ã»ÛLq÷!RÕ2Ô!ÕCô Ž¡ªE;ˆlÂ’ˆíˆœ4äoòGãQG    Ðæ<¶ÃoW'ü£—Eü    €u€ó     À ’      Œ "	     À ’      Œ "	     À ’      Œ "	     À ’      Œ "©³¡ºQz.Ot÷FiVÌŒi¡kÎÝ@@G€PÈ„é·âòâòÊB&./)Ëç›Þ´¤ °R>`™¨(/¿\† ôWÜ6ÆÝóßäÜy1,”ßòö°·E~’÷
èíŒÚ‹G9Õ•oâ^ˆ-xði™‹»_ü#//%9ä™daX‚¢lwÒç•ü1&‡ùqQ["ÍûxÁæb¯™_¬çÉjú¶¬hÇªK¼ÿÙê0co7¨)¿PT©àòçZ²<Ð
ÈŠ¶-Ù\ì3wÇºÑ¼óR—l)÷[¸#aÏ’í(*ŽmJ:F¼´<5vXÛ~+¶DQþÝ§;NK-ÑÂ\¿Ì
¬ÿ2â¼©ß©ƒgMó1öÁº¼R"•É¤ŠJJ
+nªñLæ€		k#ý\P'§Óˆ$Õõì±vÿ¡ê[°cëòi¾].T±kî‰¿©ð¤×øìoâ‚\ëßº-'è«±“÷þÄ~vã”ùF¶p÷ü®5ËO\`þH«ß'J÷   IDATq;&?Ó™eÑY˜Ý/dæ¬$ÿrhcYJÌä§ó¶ý±ã³œi“µ\7v"d¿;õkåmYåÔ¶(DyÇ‹•LÐgICHóvzüöP—ÁþA ’:6ŠË…7÷¥ PH7ÊËå—‘ßÈà3‰NI8MíŠjÉéÏcJÊcWÏâÓó„øÔÇ+÷ˆåÍÛjÝR¾·wðØ±þƒýü½½x^ ‘´³HºQðíÞ¼+w,^þAÕu©`üÆC{Y5¨«®_=ñë+äôµ¼÷—å¿kÞ ×¦Ë±Ý{`íBŠ$×ÞÝt»x Éš?cý^¹ð«,›œ7v÷o‰³B·Nòn|ÄÒ_ó~©AÍqû
,¤¬IfÏâAéÁY‰9·{>ãÍg™ÙòùõŸª¨C•¨ü®É~QÑOø\¿ÇT!)ÊsNž­lÆfÌäú	ò²àn (>°;ï6Þ™ÒÆf$Ù¯‡¿»¬îþÒkA^6R4.žCý<ŽÿGTR!â3%å%Å*Š+•þóVÏ#­=#+;u:¯Áwš¨©8+AÈãvÁ‘í%¦Wdò‡Žñ³aG‡ã5ú­÷‚ÌXeù7‹ŠŠÍdyÍ[?Àû“•›OmX"“Å4Î‡ºã©²Û7Y|~ð`?ooÏ¾!i_‘t÷JÎ¡å»¯X¹VÞ%Ñò#‰aÞíòß÷.‹ž•¥Ó.^AŸ¯yÛ˜B"a³H…E›]/5œÝŸè²7+/q×øì9sgæÅíÌ;´ãbX²ÐZCÎ#ÐÜY<_ùéüoWÐo9–mï§)ÉA[’ƒû£ÇBV‘sàx‰º¹åŽŸs’5'åÿÙuê9Q±{ùüÞS+e
Žßä¹óÇø´Â£—¢ì»XœyEŒm™€Q”ïˆK, \8,¦Á\µ´ê“IïŸÔÏòð‚¨;‡•|·çØe#ïÜ,9}¬ÄÜª|–0DR‡GY´aæèÍ-Åä7ÇòµhµZ³¥è@Æ9alˆÎ(ÍNÿèƒÁ`32I‡t·ý~â«½o%Z Qˆr—-_óùyý²Û?ÞÜ»y®ÕÑ<ìÞcç¼”“’÷uJò«)‹7lšèú\ éÞ{„nüþÁFdsls Ïõñ¾c²xÂ÷¼”&ßÇ.§Ÿž¼ÆôýZ³–!Eùá/”«Ç£/Óè²
ÙM™;ýëÄQ>6#Äg÷Ÿ¼Œ<FÎÇWžûîpÉMcªO)ªTb{znÆ	ß!…¯ñsÓ½çÂŽ…*eR±ä¦R/ƒ˜.çöM%Óë¥‘ƒ}¼ñþ^|xŠlmˆÊï>þBV¢–‰ÕH]r`Ùü“.¼ZV‰?ã’=Ëæ×Îwñž;Œ7byMLÍœX¿¨¢lûÒË†Æ&¯²(~	èàpÇnN4oIJ\°¦Èø{,ÏÑ%óÅ,ï<øS·˜öI]½ßˆË±dÉêóÛÖ/Ï£U+dùšeÍ*$ùï‡7§,Þ}Aª›ñLXìÞÄ×MÙÌãì¶lùõl$œæÛ»Ÿkï~¨­°ø,ºû¾}xHsŽKÕ•o6Ìúö:9Ù÷¹A‚6´„µ,žÀÏôÝ„Ý}ßtÇ½$0Ç ¤¹_l<rYí12~ÓB·ÆoËò×/XsVí?º¹í´ ¢2kËž"%~Ú{É‡«./È8rú¶é¥o—œ>NZøÌ¡£üÜôÃõ{Ç¯éÒ5y‰³7\`ùŒ›:Ž·ÌŽ) O_FÞ˜ð<(¤N‚²òä×ÛÊÍÆ$Uâ[ßÔÛ.ž~`ílIêîû|‹Ý.øbYÖŽÂ‰N~ÃlÔ³êzö®M1›ó~×?5³úM[¾|ãäçº#KQÉ¯K«TÞõÖš®A“?0}¨ªŠì£{¹Ž,Útõyïig6c
²ö,\{öFÍP}üÀ]zŠ÷¡‹ôâãBMÑ#jÄýšù†æïXùñÙ›L¿éÍjª’–œºpq^lóŠŸÜ±«Doã~°÷äF—”å®ˆ²&»ÄÍ/hp÷óç
Š¤£ùäJ„B\ñ«˜éèN™Ö‚å5nÅ'ã´/¤§–ÍÙ\ì39±Qv›ôdìœ-å~Ó›Én“gdÚåvÞy&bòüÂFÁpð8Ààã=@ª¨,.if1/¾—åÃ²Ó«&œ6ýö€éŸ!èÌßŸàn{ ÉY¼|ïO´Vè;jã2ÓÑHªëy'¦n=”Yi8·ßû»vZ?Tv(4øïAÓf¼=+¤ÙÐfEÅ‰¯’³šÙÖsäDÁš[;*žE#ª~ÏüEk{!ø¹^¨ó¢(9²ã¬q†FŒ5FDHr7‘
	ñÿ½"6b ‘%	QÁñB%ò=ÎÖÏØ¸%aÇ¯Í†T5»ñ¹Ó…2¦—Ëqai³Z™´²R"»­dawOÆ§‰ÐÍJ‘„Lqáo_ä† ;‡ŸúâÓÓØŠdæ£‘º+ýG‚Hzpñ1PØ¶P“™þ/†è³f•’üÓnvn”.—…éõ\gf²'‘„ÅÁÅ»þÂgXJä¿¦.\³—–¬~ï¯ù`bÌƒªß³Odež¿—ûÇ#¡§,»…Ÿ²ô—¼ä…yÉ¬Þ!o¼9krØX_Ûù§\{{ól­õÎBÏÒSy×¨©®Ï…þ£„
ÑÉ=Y7± ˜ù^ÉáƒíÛ°ñH…+¤åëfre…Û7ì—	gÌç¯×C²‚ÃØÅ|îµ±Þ.MÖÏ?y²Dj•Èaz6˜<$¢òøÇ›NKðSÁ¬Á’ÝÇ¥/.ùUÊy.ÐËÂPK¥4ïÀö³æœtåJ<ü„!Ï{ð½üûB²jŠv$¤œ'“Ü˜Þ³?[;Þ¨ùd­\ð¹EQê‚UßßD-ÀcìÚ/fû¹HóRS2Êµ¾7hæG¨gCµL¡fzM~o‚.mVVPSpAæ4uöhðàj±‘TunÙÂåÉçýB¢o˜¤¹QýµwÙòå¿Ð’ÁmBâúÄà†eå6.\“˜w]Õpcl/áüOÿ´aoÞ]dˆë9_§àÔ³_ø«oÇÌõ¢{#¡Ö#há†ìÉÍæÝ=¿mYÕ/(ìwÔ¦g¡ú+swIØ-`Tÿ1ÍûoEÉ×Ÿî©PsžŸ?Ë´DR”ïK\uä2âø½±"öRÉ*Š+.—WüWpzÔ¼è÷Fa‘"+Ü½'ï6âÒt”"$çŽ|sê6²
®Ò/„I,þKc„\¿¹3|JÖ.!ûõð¦¤ShäòO¢-«ÈŒÛ{2Ž:"…TzS&SÒºÉå{yrd¹b>.ñšÖ†öÂ~ºá?Î€Áü›E•xÌañ‡Ž{ƒUyÛxdüCž§ÿPÉÒyÈë¥—L×ƒeò{4Ê	KnËø·é¡J!½©ÄOß\üå›Ã>DC±¤BÂ“WrRBÇ7¿ý"Â6¤èÅº`íÞKL}£ãP$×Þýºª´ë™ °ùï¼9!¸÷ªœ	›±,A-ƒýiG÷ÏÛýÕÞœ+õOñ·®dî^“]V·ëíFÕ»ž4·ÑªÜ½"útz‡¿ñ|¯Ö?C®äìLÎÓÊÍ±o{wV$-Ø¶éx%búLžlF¸øL]ìR€†ŽÒê®_ä'iß}ñÉŽó§R;c$qû;º¿4u‚±ª´,®ÿ¨±.V&Õs}tÇÄâ…|°6„Å"Ä])Üç§N~±(åôçÛ…>qB«¬>,ž'þi07tò8ŸEW1t6ÔÖí Ô”÷iâÎ"bÀkË¢ç%UTì_¹à”ÑaNMÈ$jdy &`Ÿ°xãÞ	”åI.ˆüÆÍÐþ¢#X$3.ÉŒåð2¹¼<é„U^ÐüuTD­ôÔ²›uº=$2ÔÝ‹ÏA€ìC$±ûOX³æRlÜççÉ1åNÞ–±3~Ë.Ø«Mzw	Z¼áðœçýµ÷1é…œ-á´7ÆO{U8ÈÝFƒ?»G¿€°DaX"!ôUâæ£ZGb½"l‘Â¸›·kç^j#ìŒjX,»ÕÎBÇIÎ²§´f¤ß~?¸“ö!Q”¤'n8E®ÕåGöó^<ÑtDË3h¢g£y\ÿqËv‹Ž}±e×…ãŸ’ê…ã7yòP£–WÈ;^èQ`Õp,^Ð;SN_øüìîý£¼-­ýHÈj®[Ãs%¤•"%Ë“?rJÐÉåÇSR}Ö.
kR+BTf|°`OcÓOÉ–w^Ýbdé›ßyusýK¯ÿlž¿2oVHêçf$ÄNôcšŠÖSnž\:á¤áŒjéÉOSŽµ'r_ŠÿÜÌs!). îA>PWÛ<öânsæMÞõUÐ†åÓvÿm*w~9µWûŽKøš-;&?cêy¨{À›ÇÏ¿ÙÝµÕ#ìÞAoÄe¿}£´àÈ®Éç{Lég}GÕ•ëgmûƒšv›øÞ¤F9ü­z$¹1s–¡EëéYÂ:eBZ´+1éØeä5vñDÖÉÔŒ»–,•-[ýž•×,Þà‰Ì/J¢Ä–Rr¶ |h„[Ö©fñƒ§ý>æ›œ§ÇøY’x¢(Û½äÃãêQ«kf*Ê3’âNÖG:ämßìÍ¿Z÷¹×"ÆV¶¨b'“GyY\‚æ'xLäúÈ‰z„dzOY1IÎóÓ?çMš‡¥øÔŽÏÏ"¦×ô/ö>¿mÅ–ò øõÚÐ"…h÷Ò2ˆ¡cž3ci&*óNU¨¹/	¡›M3ØSà6»ÿØå[2Ý£Ã7þ¦÷=½ðÞ3
‰^­{[¤²³{ùÏß<K®r¶^ÊÜ(Ø9m±ÖóÌëqËŒrZë,n—~;Î­BÂ×sF\Œð1/dŒš’Ý'~S¢äøM_µ‚Zzñ7Ä¥œ?–˜ÄLˆ´¦Í!ÎKMÚH*¤î}ùêk’’=qóKf,‹žØ†7Ÿˆ÷ÆÄÏØ_ðRóU’¼×°)"ØÛð]°X\¤TH/—WJ¥—+dÞ£ýávÙªp}BÞñ±`9…BÁr1QÙÓÅËüpù><âØwr…±!ž,¢òô¶ï.#¿¹¯y›þ¦Òs§+Ñ€	ãžƒDf°· ]ƒæ¤d³ã&&jË'þ”“•7ùÙ±velµB"«A®_¼û7útú…ÄîMn«^iw/}³~Ú²SúJKýÂ–ïXø\g‹Q dÅ'É@¢›Ì¾£b—éš;²<GE/—¬ŠË¨8²b%Z·6Ò¢¡‡¨)Ú³&ñx¹1Œ]‘0ÝŸ(Ùµaã1	âpÚØ¯ïâ?!vMÇÇÇ‚¥D,Ã~A¿Fùp.ž>þØŸè7xÚBš—²l·Ä+"zþÝ‡#Ãá¤#•ÝG} ÿ®šE]±=êíf€˜¤ÇåÜúBó  +IDAT…=k.Î@ýæá·hgjÊqÞ<œÄ%È{Æô—LWL#$§¶l+¸í12ú5ÜÍb‡u’º¾8cýqšx¬]™5m¡KæÖ‚ÜQCþWö-Ë¶æëÂ®±B:¼áõ¶pu©ª/åf}¾û+]Pµ÷°ØÃ–7¼{L¨)?¹-eG¾úüìe‹Ç7¸#¸|'>‘X¹ìøå#‰¹	‹Ç›Oª'$¹;RROV(òÎ]=Z@.>ø½Õ›GÉ8Ï6æázZhøa±(ó¼LI 7ˆ9²ñ©”e)gozŒœ:˜_ÿÍãz‡EŒ,ÚpüÔ†åEs?š5JÐÌ(Æñ9ÒÇ¸DWŠòN›¯;t,<F¯ýbž>p;æý=Úù,¯Ñï½S·cOÜr© X£ê‡—T*‘ìr‰t¨ŸŸp@È;ƒÁŒÔ<öYL²ë kŽTÅ†o#ýnª_…ÏAG¶~j­NªOÃ¶”¶RòßŸÈÉÎ»“û›A¹#— wâRcCZ¢Q¬9‹Û¿]öÙÑœ‚†•–X½',_“:¹3ÙˆšÊ‚Sì+¸FHô{ã£ØwŒI
®ÿ;±óo.ù´àÂö).ëbCŒiBZ’ûÝw‡OŸ—9$Cç.þÐ°‘­O`çc¯¡Þœs¾N=À›!ô„GÇv‡îNúøx…’?ò£ÕsfY²xCg­_çñiâÎs§7ÇÝ”h+P˜‚ÉŠ˜n2&ér^	Ä$u|YýUF`£pQî÷•J‰DtS")¿¬³$!òkã=Ø‹ù+ÙÏÛÃoØ Æ_EyVJÊ6ê~ˆ$ÿIýnìGÑÓÜ™ÝÞÞ2¾:ì¶âv …‰;ªæ¾M5Sý’•š3>hrË#¦¨ˆÚIBjñZ*B»–ª~u+a³îdíÌ©¯¾Í{1,qñÜi-I(³ö,ºû>?ˆ½s§BêôæÆ5síÇ_ÙF(+Oe
‰9 èYïö3iAañC¢ãe+—îª¸\|YìÙ0½‹älJL={®,Äñþ÷üèéÁö¨2BM}èÆóáøÁ³æ®Ü˜—‘4;ƒÉàççÃw£î‚„’êz«T*˜ÏÏ_7/ôSëCH‹Ž}‘²ëÂmþÈ…›æ2*oÈÚÊ[ý²R’>/ø&nþåqÑ¯ùµ¨îq‹‚Ä»‚Ÿ\¶`K	}*ùæór—çÁ÷ðàr9ˆ,ÃF(Ä¾ßsàpÁee÷çÆq‹Ožþ8ªèØÈˆ)“GÒ÷3YÑ¶IÇ.Óõ¹>)Ëw_uœ3àù¡A/õâÆ¿ùL˜îƒuTQA‘¤¼RªfòüBFÛÞPnGØq[vï‰Ë×\¹6wùùÖ¬OµF!‘È«ïhm0Õwª,¼W¨TUkE‰ü:¹z¤»ÿ´Ä7náÃFþ!aóßy{‚°·õÙp:¬=vÿY¯ÌˆNþ…Üû¬·ÞœÜ¿å{ï¸pg¯Þ4XÂòök^Ò¸øL\–ì§ðØtQ,/&Œ;w9CÊŽ76ØÏ~’e	é¹Ÿ¨ÐÖG!kÄá; g¢,Ë3hÉVï°SÇ³Î–”W^È»|¡Ñ?Ki}t.6|½#Ö~ôŽÙâæ.^a±«]È®8v­X©°4l)Š¾˜·ò?J¾\–ZŠ¿||²Ïoâ¼¹!>üO÷¼GºÛŠ.Krg¹©DÌ¾A³VEŽ&Ñã‚ö¥|qäôÎå§÷ðGF'ÎâÊ.—Kðã¿?Z6k^ÂgõvïÓ»vì9UqáÔå§Ìîùü€0Û¶Bép0äŠûä?†þ·á„™é¦/[ùïç«z¿ØVýêH~ÿIBÅñ°Ýüú·Ü?¥ª¾zôj/óMûî°)Ò‚Ô”™pæ‡ã’²‚më‚Î8øorDˆä§Ù7ŠÊœy¬QÁ–v•‘•}ŸqÎeäÄ ¦+(Dß¥|^À;¯AáEùá”¹Rò‘ŠÅõ3yzç>†½?9‰†Žö¾Y‰†ŽÙ¨î¶B\”sò
z‹
Ä$¤å¦—W#ƒ:!“ˆ$2}ã$Ò©wSéÂ÷àThczØÉ)Ù½H     h      ÐI      F ‘     `I      F ‘     `I      Fh¦˜$$ù    Ð9K     €HK’…æ"°*    Ðy K     €@$     D     €Œd·Aì      X’      ŒPoI     €sÅ$ÍË&U     <Æ€»     À ’      Œà^3     €¦8Y¸h)     :àn     0ˆ$      #hÝmàM     0äÿ  ÿÿ¹ù5P   IDAT ‘0Árûþ     IEND®B`‚