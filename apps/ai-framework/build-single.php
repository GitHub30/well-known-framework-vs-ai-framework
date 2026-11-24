#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Single-File Bundler for CloudDrive (AI Custom Framework).
 *
 * Compiles the entire zero-dependency CloudDrive PHP application, HTML templates,
 * SQLite schema & deterministic 71-item seeder, CSS, JS, Service Worker, PWA icons,
 * and screenshots into ONE standalone file (`dist/index.php`) that can be uploaded
 * directly to any shared hosting / rental server (Xserver, Sakura, Lolipop, ConoHa WING,
 * MixHost, etc.) with zero Composer, zero vendor/, and zero manual setup.
 *
 * Usage:
 *   php apps/ai-framework/build-single.php [output_path]
 */

$repoRoot = dirname(__DIR__, 2);
$appRoot = __DIR__;
$sharedRoot = $repoRoot . '/shared';
$outPath = $argv[1] ?? ($repoRoot . '/dist/index.php');

@mkdir(dirname($outPath), 0775, true);

/**
 * Wraps a namespaced PHP class file into bracketed `namespace Foo { ... }` syntax
 * so multiple files can coexist cleanly in a single PHP file without `use` collisions.
 */
function wrapNamespacedFile(string $filePath, ?callable $transform = null): string
{
    $code = file_get_contents($filePath);
    if ($code === false) {
        throw new RuntimeException("Failed to read {$filePath}");
    }
    if ($transform !== null) {
        $code = $transform($code);
    }
    // Strip opening <?php and declare(strict_types=1);
    $code = preg_replace('/^\s*<\?php\s*/', '', $code, 1) ?? $code;
    $code = preg_replace('/^\s*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*/', '', $code, 1) ?? $code;

    if (!preg_match('/^\s*namespace\s+([A-Za-z0-9_\\\\]+)\s*;\s*(.*)$/s', $code, $m)) {
        throw new RuntimeException("Expected namespace declaration in {$filePath}");
    }
    $ns = $m[1];
    $body = trim($m[2]);

    return "namespace {$ns} {\n{$body}\n}\n\n";
}

/**
 * Compiles an HTML/PHP template into a static method body inside AiFramework\EmbeddedTemplates.
 */
function compileTemplateMethod(string $name, string $filePath): string
{
    $tpl = file_get_contents($filePath);
    if ($tpl === false) {
        throw new RuntimeException("Failed to read template {$filePath}");
    }
    // Remove `use App\Services\UiHelper;` because it is hoisted to the namespace header
    $tpl = preg_replace('/^\s*use\s+App\\\\Services\\\\UiHelper;\s*\R?/m', '', $tpl) ?? $tpl;
    // Ensure template closes cleanly back into PHP mode
    $tplTrimmed = rtrim($tpl);

    return <<<PHP
    /** @param array<string, mixed> \$__data */
    public static function render_{$name}(array \$__data, callable \$e, callable \$jsonSafe): string
    {
        extract(\$__data, EXTR_SKIP);
        ob_start();
        try {
            ?>{$tplTrimmed}<?php
            return (string)ob_get_clean();
        } catch (\\Throwable \$ex) {
            ob_end_clean();
            throw \$ex;
        }
    }

PHP;
}

// 1. Collect binary static assets to append after __HALT_COMPILER();
$assetSpecs = [
    '/sw.js' => [$sharedRoot . '/public/sw.js', 'application/javascript; charset=utf-8', 'no-cache'],
    '/assets/app.js' => [$sharedRoot . '/public/assets/app.js', 'application/javascript; charset=utf-8', 'public, max-age=31536000, immutable'],
    '/assets/drive-ui.js' => [$sharedRoot . '/public/assets/drive-ui.js', 'application/javascript; charset=utf-8', 'public, max-age=31536000, immutable'],
    '/assets/app.css' => [$sharedRoot . '/public/assets/app.css', 'text/css; charset=utf-8', 'public, max-age=31536000, immutable'],
    '/assets/app.min.css' => [$sharedRoot . '/public/assets/app.min.css', 'text/css; charset=utf-8', 'public, max-age=31536000, immutable'],
    '/assets/favicon.svg' => [$sharedRoot . '/public/assets/favicon.svg', 'image/svg+xml; charset=utf-8', 'public, max-age=31536000, immutable'],
    '/assets/icon-192.png' => [$sharedRoot . '/public/assets/icon-192.png', 'image/png', 'public, max-age=31536000, immutable'],
    '/assets/icon-maskable-192.png' => [$sharedRoot . '/public/assets/icon-maskable-192.png', 'image/png', 'public, max-age=31536000, immutable'],
    '/assets/icon-512.png' => [$sharedRoot . '/public/assets/icon-512.png', 'image/png', 'public, max-age=31536000, immutable'],
    '/assets/icon-maskable-512.png' => [$sharedRoot . '/public/assets/icon-maskable-512.png', 'image/png', 'public, max-age=31536000, immutable'],
    '/assets/og-image.png' => [$sharedRoot . '/public/assets/og-image.png', 'image/png', 'public, max-age=31536000, immutable'],
    '/assets/screenshot-desktop.png' => [$sharedRoot . '/public/assets/screenshot-desktop.png', 'image/png', 'public, max-age=31536000, immutable'],
    '/assets/screenshot-mobile.png' => [$sharedRoot . '/public/assets/screenshot-mobile.png', 'image/png', 'public, max-age=31536000, immutable'],
];

$binaryPayload = '';
$manifest = [];
foreach ($assetSpecs as $route => [$filePath, $mime, $cacheControl]) {
    $bytes = file_get_contents($filePath);
    if ($bytes === false) {
        throw new RuntimeException("Missing asset: {$filePath}");
    }
    $len = strlen($bytes);
    $manifest[$route] = [
        'offset' => strlen($binaryPayload),
        'length' => $len,
        'mime' => $mime,
        'cache' => $cacheControl,
        'etag' => '"' . substr(sha1($bytes), 0, 16) . '"',
    ];
    $binaryPayload .= $bytes;
}

$inlineCss = file_get_contents($sharedRoot . '/public/assets/app.min.css');
if ($inlineCss === false) {
    throw new RuntimeException('Missing shared/public/assets/app.min.css (run ./shared/sync-assets.sh first)');
}
$schemaSql = file_get_contents($sharedRoot . '/database/schema.sql');
if ($schemaSql === false) {
    throw new RuntimeException('Missing shared/database/schema.sql');
}

// 2. Transform shared/database/seed.php into an embeddable GD-optional seeding body
$rawSeed = file_get_contents($sharedRoot . '/database/seed.php');
if ($rawSeed === false) {
    throw new RuntimeException('Missing shared/database/seed.php');
}
// Extract everything from `$insertItem = $pdo->prepare(` through `$pdo->commit();`
$seedStart = strpos($rawSeed, '$insertItem = $pdo->prepare(');
$seedEnd = strpos($rawSeed, '$pdo->commit();');
if ($seedStart === false || $seedEnd === false) {
    throw new RuntimeException('Could not locate seed body markers in shared/database/seed.php');
}
$seedBody = substr($rawSeed, $seedStart, ($seedEnd + strlen('$pdo->commit();')) - $seedStart);
// Rewrite calls to helper functions into self:: methods
$seedBody = str_replace(
    ['createSamplePng(', 'createSamplePdf('],
    ['self::createSamplePng(', 'self::createSamplePdf('],
    $seedBody
);

// 3. Assemble the complete single PHP file
$parts = [];
$parts[] = <<<'PHP'
<?php
declare(strict_types=1);

/**
 * CloudDrive — Single-File Standalone PHP 8.3 Edition (AI Custom Framework)
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


PHP;

// Framework core classes
$frameworkFiles = [
    'Container.php',
    'Database.php',
    'HttpException.php',
    'MiddlewareInterface.php',
    'Response.php',
    'Router.php',
    'Security.php',
    'Application.php',
];

// Request.php with rental-server proxy HTTPS & safe RFC host fallback when APP_URL is unset
$parts[] = wrapNamespacedFile($appRoot . '/framework/Request.php', static function (string $code): string {
    $oldHttpsAndBase = <<<'PHP'
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
PHP;
    $newHttpsAndBase = <<<'PHP'
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
PHP;
    if (!str_contains($code, $oldHttpsAndBase)) {
        throw new RuntimeException('Failed to patch Request.php for rental-server baseUrl/HTTPS');
    }
    return str_replace($oldHttpsAndBase, $newHttpsAndBase, $code);
});

foreach ($frameworkFiles as $f) {
    $parts[] = wrapNamespacedFile($appRoot . '/framework/' . $f);
}

// In-memory compiled View & EmbeddedTemplates (zero external template files!)
$templateMethods = '';
foreach (['layout', 'drive', 'about', 'share', 'offline'] as $tplName) {
    $templateMethods .= compileTemplateMethod($tplName, $appRoot . '/templates/' . $tplName . '.php');
}

$parts[] = <<<PHP
namespace AiFramework {
use App\\Services\\UiHelper;

final class EmbeddedTemplates
{
{$templateMethods}}

final class View
{
    public function __construct(private readonly string \$templateDir = '') {}

    /** @param array<string, mixed> \$data */
    public function render(string \$template, array \$data = [], ?string \$layout = 'layout'): string
    {
        \$content = \$this->renderTemplate(\$template, \$data);
        if (\$layout === null) {
            return \$content;
        }
        return \$this->renderTemplate(\$layout, array_merge(\$data, ['content' => \$content]));
    }

    /** @param array<string, mixed> \$data */
    private function renderTemplate(string \$template, array \$data): string
    {
        \$safe = preg_replace('/[^a-zA-Z0-9_-]/', '', \$template);
        \$method = 'render_' . \$safe;
        if (!method_exists(EmbeddedTemplates::class, \$method)) {
            throw new \\RuntimeException("Template not found: {\$safe}");
        }
        \$e = static fn(mixed \$v): string => htmlspecialchars((string)(\$v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        \$jsonSafe = static fn(mixed \$v): string => json_encode(
            \$v,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
        return EmbeddedTemplates::\$method(\$data, \$e, \$jsonSafe);
    }
}
}


PHP;

// Services (DriveService with finfo fallback for ultra-minimal shared hosts, SeoService, UiHelper using OPcache INLINE_CSS)
$parts[] = wrapNamespacedFile($appRoot . '/app/Services/DriveService.php', static function (string $code): string {
    $oldFinfo = "\$finfo = new \\finfo(FILEINFO_MIME_TYPE);\n        \$detectedMime = (string)(\$finfo->file(\$tmpPath) ?: 'application/octet-stream');";
    $newFinfo = "\$detectedMime = class_exists(\\finfo::class) ? (string)((new \\finfo(FILEINFO_MIME_TYPE))->file(\$tmpPath) ?: 'application/octet-stream') : 'application/octet-stream';";
    return str_replace($oldFinfo, $newFinfo, $code);
});

$parts[] = wrapNamespacedFile($appRoot . '/app/Services/SeoService.php');

$parts[] = wrapNamespacedFile($appRoot . '/app/Services/UiHelper.php', static function (string $code): string {
    $oldCssMethod = <<<'PHP'
    public static function inlineCss(): string
    {
        if (self::$cachedCss === null) {
            $phpCache = dirname(__DIR__, 2) . '/storage/cache/inline_css.php';
            if (is_file($phpCache)) {
                self::$cachedCss = (string)(require $phpCache);
            } else {
                $minPath = dirname(__DIR__, 2) . '/public/assets/app.min.css';
                self::$cachedCss = is_file($minPath) ? (string)file_get_contents($minPath) : '';
            }
        }
        return self::$cachedCss;
    }
PHP;
    $newCssMethod = <<<'PHP'
    public static function inlineCss(): string
    {
        return \EmbeddedAssets::INLINE_CSS;
    }
PHP;
    if (!str_contains($code, $oldCssMethod)) {
        throw new RuntimeException('Failed to patch UiHelper::inlineCss()');
    }
    return str_replace($oldCssMethod, $newCssMethod, $code);
});

// Middleware & Controllers
foreach (['SecurityHeadersMiddleware.php', 'CsrfMiddleware.php'] as $m) {
    $parts[] = wrapNamespacedFile($appRoot . '/app/Middleware/' . $m);
}
foreach (['PageController.php', 'ApiController.php', 'MetaController.php'] as $c) {
    $parts[] = wrapNamespacedFile($appRoot . '/app/Controllers/' . $c);
}

// EmbeddedAssets + SingleFileRuntime + Front-Controller in global namespace
$manifestExport = var_export($manifest, true);
$inlineCssExport = var_export($inlineCss, true);
$schemaSqlExport = var_export($schemaSql, true);

$parts[] = <<<PHP
namespace {

use AiFramework\\Application;
use AiFramework\\Container;
use AiFramework\\Database;
use AiFramework\\Request;
use AiFramework\\Response;
use AiFramework\\Router;
use AiFramework\\Security;
use AiFramework\\View;
use App\\Controllers\\ApiController;
use App\\Controllers\\MetaController;
use App\\Controllers\\PageController;
use App\\Middleware\\CsrfMiddleware;
use App\\Middleware\\SecurityHeadersMiddleware;
use App\\Services\\DriveService;
use App\\Services\\SeoService;

final class EmbeddedAssets
{
    public const INLINE_CSS = {$inlineCssExport};

    /** @var array<string, array{offset:int, length:int, mime:string, cache:string, etag:string}> */
    public const MANIFEST = {$manifestExport};

    public static function readBytes(string \$route): ?string
    {
        \$meta = self::MANIFEST[\$route] ?? null;
        if (\$meta === null) {
            return null;
        }
        \$fp = fopen(__FILE__, 'rb');
        if (\$fp === false) {
            return null;
        }
        try {
            fseek(\$fp, __COMPILER_HALT_OFFSET__ + \$meta['offset']);
            \$remaining = \$meta['length'];
            \$buf = '';
            while (\$remaining > 0 && !feof(\$fp)) {
                \$chunk = fread(\$fp, min(65536, \$remaining));
                if (\$chunk === false || \$chunk === '') {
                    break;
                }
                \$buf .= \$chunk;
                \$remaining -= strlen(\$chunk);
            }
            return \$buf;
        } finally {
            fclose(\$fp);
        }
    }
}

final class SingleFileRuntime
{
    private const SCHEMA_SQL = {$schemaSqlExport};

    /**
     * Handles dotfile 403 protection and embedded static assets (/assets/*, /sw.js)
     * before booting SQLite or the full application pipeline.
     */
    public static function tryServeStaticOrBlockDotfile(): bool
    {
        \$uri = (string)(\$_SERVER['REQUEST_URI'] ?? '/');
        \$path = parse_url(\$uri, PHP_URL_PATH);
        if (!is_string(\$path) || \$path === '') {
            \$path = '/';
        }

        // Defense-in-depth: block dotfiles (except /.well-known) even without Nginx/Apache rules
        if (preg_match('#^/\\.(?!well-known(/|\$))#', \$path) === 1 || str_contains(\$path, '/.clouddrive_data')) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            if (strtoupper((string)(\$_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
                echo 'Forbidden';
            }
            return true;
        }

        \$meta = EmbeddedAssets::MANIFEST[\$path] ?? null;
        if (\$meta === null) {
            return false;
        }

        \$method = strtoupper((string)(\$_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (\$method !== 'GET' && \$method !== 'HEAD') {
            http_response_code(405);
            header('Allow: GET, HEAD');
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Method Not Allowed';
            return true;
        }

        header('Content-Type: ' . \$meta['mime']);
        header('Cache-Control: ' . \$meta['cache']);
        header('ETag: ' . \$meta['etag']);
        header('X-Framework: ai-framework');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Cross-Origin-Opener-Policy: same-origin');
        if (\$path === '/sw.js') {
            header('Service-Worker-Allowed: /');
        }

        \$inm = (string)(\$_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        if (\$inm !== '' && trim(\$inm) === \$meta['etag']) {
            http_response_code(304);
            return true;
        }

        header('Content-Length: ' . \$meta['length']);
        http_response_code(200);
        if (\$method === 'HEAD') {
            return true;
        }

        \$bytes = EmbeddedAssets::readBytes(\$path);
        if (\$bytes !== null) {
            echo \$bytes;
        }
        return true;
    }

    /** @return array{dataDir:string, dbPath:string, uploadsDir:string, secret:string} */
    public static function prepareEnvironment(): array
    {
        \$configuredDir = getenv('CLOUDDRIVE_DATA_DIR');
        \$dataDir = (is_string(\$configuredDir) && \$configuredDir !== '')
            ? rtrim(\$configuredDir, '/\\\\')
            : (__DIR__ . DIRECTORY_SEPARATOR . '.clouddrive_data');

        \$uploadsDir = \$dataDir . DIRECTORY_SEPARATOR . 'uploads';
        \$dbPath = \$dataDir . DIRECTORY_SEPARATOR . 'database.sqlite';

        if (!is_dir(\$uploadsDir)) {
            @mkdir(\$uploadsDir, 0700, true);
        }

        // Protect data directory on Apache / LiteSpeed rental servers
        \$dataHtaccess = \$dataDir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file(\$dataHtaccess)) {
            @file_put_contents(
                \$dataHtaccess,
                "<IfModule mod_authz_core.c>\\n  Require all denied\\n</IfModule>\\n<IfModule !mod_authz_core.c>\\n  Order allow,deny\\n  Deny from all\\n</IfModule>\\n"
            );
            @file_put_contents(\$dataDir . DIRECTORY_SEPARATOR . 'index.html', '');
        }

        // On Apache/LiteSpeed shared hosting, auto-create clean rewrite .htaccess if absent
        self::maybeCreateRootHtaccess();

        // Fast-path once initialized
        \$envSecret = getenv('APP_SECRET');
        \$secretFile = \$dataDir . DIRECTORY_SEPARATOR . '.app_secret';
        \$secret = (is_string(\$envSecret) && strlen(\$envSecret) >= 32) ? \$envSecret : '';

        if (\$secret === '' && is_file(\$secretFile)) {
            \$stored = trim((string)@file_get_contents(\$secretFile));
            if (strlen(\$stored) >= 32) {
                \$secret = \$stored;
            }
        }

        \$needsInit = (\$secret === '') || !is_file(\$dbPath) || filesize(\$dbPath) === 0;
        if (\$needsInit) {
            \$lockFp = fopen(\$dataDir . DIRECTORY_SEPARATOR . '.init.lock', 'c+');
            if (\$lockFp !== false) {
                flock(\$lockFp, LOCK_EX);
                try {
                    if (\$secret === '') {
                        if (is_file(\$secretFile)) {
                            \$stored = trim((string)@file_get_contents(\$secretFile));
                            if (strlen(\$stored) >= 32) {
                                \$secret = \$stored;
                            }
                        }
                        if (\$secret === '') {
                            \$secret = bin2hex(random_bytes(32));
                            @file_put_contents(\$secretFile, \$secret . "\\n", LOCK_EX);
                            @chmod(\$secretFile, 0600);
                        }
                    }

                    if (!is_file(\$dbPath) || filesize(\$dbPath) === 0) {
                        self::initializeDatabase(\$dbPath, \$uploadsDir);
                    }
                } finally {
                    flock(\$lockFp, LOCK_UN);
                    fclose(\$lockFp);
                }
            }
        }

        return [
            'dataDir' => \$dataDir,
            'dbPath' => \$dbPath,
            'uploadsDir' => \$uploadsDir,
            'secret' => \$secret,
        ];
    }

    private static function maybeCreateRootHtaccess(): void
    {
        if (getenv('CLOUDDRIVE_NO_HTACCESS') === '1') {
            return;
        }
        \$serverSoft = (string)(\$_SERVER['SERVER_SOFTWARE'] ?? '');
        if (stripos(\$serverSoft, 'Apache') === false && stripos(\$serverSoft, 'LiteSpeed') === false) {
            return;
        }
        \$htPath = __DIR__ . DIRECTORY_SEPARATOR . '.htaccess';
        if (is_file(\$htPath) || !is_writable(__DIR__)) {
            return;
        }
        \$self = basename(__FILE__);
        // Rental-server safe (no Options directive that triggers HTTP 500 on Sakura/Lolipop)
        \$rules = <<<HT
DirectoryIndex {\$self}
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteRule ^\\.(?!well-known/) - [F,L]
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteRule ^ {\$self} [QSA,L]
</IfModule>

HT;
        @file_put_contents(\$htPath, \$rules, LOCK_EX);
    }

    private static function initializeDatabase(string \$dbPath, string \$uploadsDir): void
    {
        \$pdo = new \\PDO('sqlite:' . \$dbPath, null, null, [
            \\PDO::ATTR_ERRMODE => \\PDO::ERRMODE_EXCEPTION,
            \\PDO::ATTR_DEFAULT_FETCH_MODE => \\PDO::FETCH_ASSOC,
        ]);
        \$pdo->exec(self::SCHEMA_SQL);
        if (getenv('CLOUDDRIVE_SKIP_SEED') === '1') {
            return;
        }
        self::seedDatabase(\$pdo, \$uploadsDir);
    }

    /** @param array{int,int,int} \$bgRgb @param array{int,int,int} \$accentRgb */
    private static function createSamplePng(string \$path, int \$w, int \$h, array \$bgRgb, array \$accentRgb, string \$label): int
    {
        if (!function_exists('imagecreatetruecolor')) {
            \$fallback = EmbeddedAssets::readBytes('/assets/og-image.png') ?? '';
            file_put_contents(\$path, \$fallback);
            return strlen(\$fallback);
        }
        \$img = imagecreatetruecolor(\$w, \$h);
        imagealphablending(\$img, true);
        imagesavealpha(\$img, true);
        \$bg = imagecolorallocate(\$img, \$bgRgb[0], \$bgRgb[1], \$bgRgb[2]);
        \$accent = imagecolorallocate(\$img, \$accentRgb[0], \$accentRgb[1], \$accentRgb[2]);
        \$white = imagecolorallocate(\$img, 255, 255, 255);
        \$card = imagecolorallocate(\$img, 248, 250, 253);
        \$ink = imagecolorallocate(\$img, 31, 31, 31);
        \$muted = imagecolorallocate(\$img, 95, 99, 104);

        imagefilledrectangle(\$img, 0, 0, \$w, \$h, \$bg);
        imagefilledrectangle(\$img, 24, 24, \$w - 24, \$h - 24, \$card);
        imagefilledrectangle(\$img, 24, 24, \$w - 24, 88, \$accent);

        \$barColors = [
            imagecolorallocate(\$img, 26, 115, 232),
            imagecolorallocate(\$img, 15, 157, 88),
            imagecolorallocate(\$img, 244, 180, 0),
            imagecolorallocate(\$img, 234, 67, 53),
        ];
        \$barW = (int)((\$w - 160) / 4) - 24;
        \$heights = [0.75, 0.58, 0.88, 0.66];
        for (\$i = 0; \$i < 4; \$i++) {
            \$x1 = 72 + \$i * (\$barW + 24);
            \$barH = (int)((\$h - 190) * \$heights[\$i]);
            \$y1 = \$h - 64 - \$barH;
            imagefilledrectangle(\$img, \$x1, \$y1, \$x1 + \$barW, \$h - 64, \$barColors[\$i]);
        }
        imagestring(\$img, 5, 44, 48, \$label, \$white);
        imagestring(\$img, 3, 44, \$h - 46, 'CloudDrive Visual Asset - 2026', \$muted);
        imagestring(\$img, 3, \$w - 170, \$h - 46, \$w . 'x' . \$h . ' PNG', \$ink);

        imagepng(\$img, \$path, 6);
        imagedestroy(\$img);
        return (int)filesize(\$path);
    }

    private static function createSamplePdf(string \$path, string \$title, string \$body): int
    {
        \$safeTitle = preg_replace('/[^\\x20-\\x7E]/', '', \$title) ?: 'CloudDrive Document';
        \$safeBody = preg_replace('/[^\\x20-\\x7E]/', '', \$body) ?: 'Generated sample PDF document.';
        \$stream = "BT /F1 18 Tf 50 750 Td ({\$safeTitle}) Tj 0 -30 Td /F1 12 Tf ({\$safeBody}) Tj ET";
        \$len = strlen(\$stream);
        \$objects = [
            "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\\n",
            "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\\n",
            "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj\\n",
            "4 0 obj << /Length {\$len} >> stream\\n{\$stream}\\nendstream endobj\\n",
            "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\\n",
        ];
        \$pdf = "%PDF-1.4\\n";
        \$offsets = [0];
        foreach (\$objects as \$obj) {
            \$offsets[] = strlen(\$pdf);
            \$pdf .= \$obj;
        }
        \$xrefPos = strlen(\$pdf);
        \$pdf .= "xref\\n0 " . (count(\$objects) + 1) . "\\n0000000000 65535 f \\n";
        for (\$i = 1; \$i <= count(\$objects); \$i++) {
            \$pdf .= sprintf("%010d 00000 n \\n", \$offsets[\$i]);
        }
        \$pdf .= "trailer << /Size " . (count(\$objects) + 1) . " /Root 1 0 R >>\\nstartxref\\n{\$xrefPos}\\n%%EOF\\n";
        file_put_contents(\$path, \$pdf);
        return strlen(\$pdf);
    }

    private static function seedDatabase(\\PDO \$pdo, string \$uploadsDir): void
    {
{$seedBody}
    }
}

// 1. Fast-path: dotfile 403 protection & embedded static assets (/assets/*, /sw.js)
if (SingleFileRuntime::tryServeStaticOrBlockDotfile()) {
    return;
}

// 2. Zero-config rental server runtime preparation (data dir, secret, SQLite auto-init)
\$env = SingleFileRuntime::prepareEnvironment();

// 3. Boot AI Custom Framework DI Container & Application
\$container = new Container();
\$container->singleton(Database::class, static fn(): Database => new Database(\$env['dbPath']));
\$container->singleton(View::class, static fn(): View => new View());
\$container->singleton(Security::class, static fn(): Security => new Security(\$env['secret']));
\$container->singleton(DriveService::class, static fn(Container \$c): DriveService => new DriveService(
    \$c->get(Database::class),
    \$env['uploadsDir']
));
\$container->singleton(SeoService::class, static fn(): SeoService => new SeoService());
\$container->singleton(PageController::class, static fn(Container \$c): PageController => new PageController(
    \$c->get(DriveService::class),
    \$c->get(SeoService::class),
    \$c->get(View::class)
));
\$container->singleton(ApiController::class, static fn(Container \$c): ApiController => new ApiController(
    \$c->get(DriveService::class)
));
\$container->singleton(MetaController::class, static fn(Container \$c): MetaController => new MetaController(
    \$c->get(DriveService::class),
    \$c->get(SeoService::class)
));

\$router = new Router();

// SSR Pages
\$router->get('/', [PageController::class, 'home']);
\$router->get('/drive/folders/{id:[0-9]+}', [PageController::class, 'folder']);
\$router->get('/drive/recent', [PageController::class, 'recent']);
\$router->get('/drive/starred', [PageController::class, 'starred']);
\$router->get('/drive/shared', [PageController::class, 'shared']);
\$router->get('/drive/trash', [PageController::class, 'trash']);
\$router->get('/drive/search', [PageController::class, 'search']);
\$router->get('/share/{token:[a-zA-Z0-9_-]+}', [PageController::class, 'share']);
\$router->get('/about', [PageController::class, 'about']);
\$router->get('/offline', [PageController::class, 'offline']);

// PWA, SEO, AEO, GEO, AIO, LLMO Endpoints
\$router->get('/manifest.webmanifest', [MetaController::class, 'manifest']);
\$router->get('/robots.txt', [MetaController::class, 'robots']);
\$router->get('/sitemap.xml', [MetaController::class, 'sitemap']);
\$router->get('/llms.txt', [MetaController::class, 'llms']);
\$router->get('/llms-full.txt', [MetaController::class, 'llmsFull']);
\$router->get('/openapi.json', [MetaController::class, 'openapi']);

// REST API
\$router->get('/api/health', [ApiController::class, 'health']);
\$router->get('/api/items', [ApiController::class, 'listItems']);
\$router->get('/api/items/{id:[0-9]+}', [ApiController::class, 'getItem']);
\$router->post('/api/folders', [ApiController::class, 'createFolder']);
\$router->post('/api/files/upload', [ApiController::class, 'uploadFile']);
\$router->patch('/api/items/{id:[0-9]+}', [ApiController::class, 'updateItem']);
\$router->delete('/api/items/{id:[0-9]+}', [ApiController::class, 'deleteItem']);
\$router->post('/api/items/{id:[0-9]+}/restore', [ApiController::class, 'restoreItem']);
\$router->post('/api/trash/empty', [ApiController::class, 'emptyTrash']);
\$router->get('/api/files/{id:[0-9]+}/download', [ApiController::class, 'downloadFile']);
\$router->get('/api/files/{id:[0-9]+}/preview', [ApiController::class, 'previewFile']);
\$router->get('/api/storage', [ApiController::class, 'storage']);

\$app = new Application(\$container, \$router);
\$app->addMiddleware(new SecurityHeadersMiddleware());
\$app->addMiddleware(new CsrfMiddleware(\$container->get(Security::class)));

\$request = Request::capture();
\$response = \$app->handle(\$request);
\$response->send(\$request->method === 'HEAD');
}

__HALT_COMPILER();
PHP;

$finalBytes = implode('', $parts) . $binaryPayload;
file_put_contents($outPath, $finalBytes);

$sizeKb = round(strlen($finalBytes) / 1024, 1);
$phpKb = round((strlen($finalBytes) - strlen($binaryPayload)) / 1024, 1);
$assetKb = round(strlen($binaryPayload) / 1024, 1);
echo "Built single-file CloudDrive -> {$outPath}\n";
echo "Total size: {$sizeKb} KB (PHP+Templates+CSS+Seeder: {$phpKb} KB, Binary Assets: {$assetKb} KB)\n";
