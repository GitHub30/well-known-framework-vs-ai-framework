<?php
declare(strict_types=1);

use App\Services\DriveService;
use App\Services\HttpException;
use App\Services\Security;
use App\Services\SeoService;
use Phalcon\Autoload\Loader;
use Phalcon\Db\Adapter\Pdo\Sqlite;
use Phalcon\Di\FactoryDefault;
use Phalcon\Events\Event;
use Phalcon\Events\Manager as EventsManager;
use Phalcon\Http\Response;
use Phalcon\Mvc\Application;
use Phalcon\Mvc\Dispatcher;
use Phalcon\Mvc\Router;
use Phalcon\Mvc\View\Simple;

$baseDir = dirname(__DIR__);

$loader = new Loader();
$loader->setNamespaces([
    'App\Controllers' => $baseDir . '/app/Controllers/',
    'App\Services' => $baseDir . '/app/Services/',
]);
$loader->register();

$di = new FactoryDefault();

$di->setShared('db', function () use ($baseDir): Sqlite {
    $db = new Sqlite(['dbname' => $baseDir . '/storage/database.sqlite']);
    $db->execute('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000; PRAGMA synchronous = NORMAL;');
    return $db;
});

$di->setShared('security', fn(): Security => new Security((string)getenv('APP_SECRET')));

$di->setShared('drive', fn(): DriveService => new DriveService(
    $di->getShared('db'),
    $baseDir . '/storage/uploads'
));

$di->setShared('seo', fn(): SeoService => new SeoService());

$di->setShared('simpleView', function () use ($baseDir): Simple {
    $view = new Simple();
    $view->setViewsDir($baseDir . '/app/views/');
    return $view;
});

// Route table with explicit HTTP method support (GET+HEAD, POST, PATCH, DELETE) for 405 parity
$routeSpecs = [
    ['/', ['controller' => 'page', 'action' => 'home'], ['GET', 'HEAD']],
    ['/drive/folders/{id:[0-9]+}', ['controller' => 'page', 'action' => 'folder'], ['GET', 'HEAD']],
    ['/drive/recent', ['controller' => 'page', 'action' => 'recent'], ['GET', 'HEAD']],
    ['/drive/starred', ['controller' => 'page', 'action' => 'starred'], ['GET', 'HEAD']],
    ['/drive/shared', ['controller' => 'page', 'action' => 'shared'], ['GET', 'HEAD']],
    ['/drive/trash', ['controller' => 'page', 'action' => 'trash'], ['GET', 'HEAD']],
    ['/drive/search', ['controller' => 'page', 'action' => 'search'], ['GET', 'HEAD']],
    ['/share/{token:[a-zA-Z0-9_-]+}', ['controller' => 'page', 'action' => 'share'], ['GET', 'HEAD']],
    ['/about', ['controller' => 'page', 'action' => 'about'], ['GET', 'HEAD']],
    ['/offline', ['controller' => 'page', 'action' => 'offline'], ['GET', 'HEAD']],
    ['/manifest.webmanifest', ['controller' => 'meta', 'action' => 'manifest'], ['GET', 'HEAD']],
    ['/robots.txt', ['controller' => 'meta', 'action' => 'robots'], ['GET', 'HEAD']],
    ['/sitemap.xml', ['controller' => 'meta', 'action' => 'sitemap'], ['GET', 'HEAD']],
    ['/llms.txt', ['controller' => 'meta', 'action' => 'llms'], ['GET', 'HEAD']],
    ['/llms-full.txt', ['controller' => 'meta', 'action' => 'llmsFull'], ['GET', 'HEAD']],
    ['/openapi.json', ['controller' => 'meta', 'action' => 'openapi'], ['GET', 'HEAD']],
    ['/api/health', ['controller' => 'api', 'action' => 'health'], ['GET', 'HEAD']],
    ['/api/items', ['controller' => 'api', 'action' => 'listItems'], ['GET', 'HEAD']],
    ['/api/items/{id:[0-9]+}', ['controller' => 'api', 'action' => 'getItem'], ['GET', 'HEAD']],
    ['/api/folders', ['controller' => 'api', 'action' => 'createFolder'], ['POST']],
    ['/api/files/upload', ['controller' => 'api', 'action' => 'uploadFile'], ['POST']],
    ['/api/items/{id:[0-9]+}', ['controller' => 'api', 'action' => 'updateItem'], ['PATCH']],
    ['/api/items/{id:[0-9]+}', ['controller' => 'api', 'action' => 'deleteItem'], ['DELETE']],
    ['/api/items/{id:[0-9]+}/restore', ['controller' => 'api', 'action' => 'restoreItem'], ['POST']],
    ['/api/trash/empty', ['controller' => 'api', 'action' => 'emptyTrash'], ['POST']],
    ['/api/files/{id:[0-9]+}/download', ['controller' => 'api', 'action' => 'downloadFile'], ['GET', 'HEAD']],
    ['/api/files/{id:[0-9]+}/preview', ['controller' => 'api', 'action' => 'previewFile'], ['GET', 'HEAD']],
    ['/api/storage', ['controller' => 'api', 'action' => 'storage'], ['GET', 'HEAD']],
];

$di->setShared('router', function () use ($routeSpecs): Router {
    $router = new Router(false);
    $router->removeExtraSlashes(true);
    $router->setDefaultNamespace('App\Controllers');
    foreach ($routeSpecs as [$pattern, $target, $methods]) {
        $router->add($pattern, $target, $methods);
    }
    $router->notFound(['controller' => 'page', 'action' => 'notFound']);
    return $router;
});

$uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
$rawPath = parse_url($uri, PHP_URL_PATH);
$path = is_string($rawPath) && $rawPath !== '' ? ($rawPath !== '/' ? rtrim($rawPath, '/') : '/') : '/';
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

$applySecurityHeaders = static function (Response $response, string $normPath, ?string $csrfHeader, ?string $csrfCookie) use ($isHttps): Response {
    $isPreview = str_starts_with($normPath, '/api/files/') && str_ends_with($normPath, '/preview');
    $frameAncestors = $isPreview ? "'self'" : "'none'";
    $xFrame = $isPreview ? 'SAMEORIGIN' : 'DENY';

    $hdrs = $response->getHeaders()->toArray();
    $hasCsp = isset($hdrs['Content-Security-Policy']);
    $hasCc = isset($hdrs['Cache-Control']);

    if (!$hasCsp) {
        $csp = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors {$frameAncestors}; frame-src 'self'";
        $response->setHeader('Content-Security-Policy', $csp);
    }
    if (!$hasCc && !Security::isPublicCacheablePath($normPath)) {
        $response->setHeader('Cache-Control', 'no-store, private');
    }

    $response->setHeader('X-Framework', 'phalcon');
    $response->setHeader('X-Content-Type-Options', 'nosniff');
    $response->setHeader('X-Frame-Options', $xFrame);
    $response->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->setHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    $response->setHeader('Cross-Origin-Opener-Policy', 'same-origin');

    $status = (int)($response->getStatusCode() ?: 200);
    if ($status < 400 && $csrfHeader !== null && !Security::isPublicCacheablePath($normPath)) {
        $response->setHeader('X-CSRF-Token', $csrfHeader);
        if ($csrfCookie !== null) {
            setcookie(Security::CSRF_COOKIE, $csrfCookie, [
                'expires' => time() + Security::CSRF_TTL,
                'path' => '/',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }
    return $response;
};

// Detect allowed methods for 405 Method Not Allowed parity (PARITY-02)
$findAllowedMethods = static function (string $normPath) use ($routeSpecs): array {
    $allowed = [];
    foreach ($routeSpecs as [$pattern, $_, $methods]) {
        $regex = '#^' . preg_replace('/\{[a-zA-Z_]+:([^}]+)\}/', '($1)', $pattern) . '$#';
        if (preg_match($regex, $normPath) === 1) {
            foreach ($methods as $m) {
                $allowed[$m] = true;
            }
        }
    }
    return array_keys($allowed);
};

$eventsManager = new EventsManager();
$csrfHeaderToEmit = null;
$csrfCookieToSet = null;

$eventsManager->attach('dispatch:beforeExecuteRoute', function (Event $event, Dispatcher $dispatcher) use ($di, $path, $method, &$csrfHeaderToEmit, &$csrfCookieToSet, $findAllowedMethods): bool {
    if ($dispatcher->getControllerName() === 'page' && $dispatcher->getActionName() === 'notFound') {
        $allowed = $findAllowedMethods($path);
        if ($allowed !== []) {
            throw new HttpException(405, implode(', ', $allowed));
        }
        throw new HttpException(404, 'Not Found');
    }

    if (Security::isPublicCacheablePath($path) && in_array($method, ['GET', 'HEAD'], true)) {
        $di->setShared('csrfToken', fn(): string => '');
        return true;
    }

    /** @var Security $sec */
    $sec = $di->getShared('security');
    $cookieToken = isset($_COOKIE[Security::CSRF_COOKIE]) && is_string($_COOKIE[Security::CSRF_COOKIE]) ? $_COOKIE[Security::CSRF_COOKIE] : null;
    $validCookie = $sec->verifyCsrfToken($cookieToken);

    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $headerToken = isset($_SERVER['HTTP_X_CSRF_TOKEN']) && is_string($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : null;
        if (!$sec->verifyDoubleSubmit($cookieToken, $headerToken)) {
            throw new HttpException(403, 'CSRF token mismatch or missing');
        }
        $di->setShared('csrfToken', fn(): string => (string)$cookieToken);
        return true;
    }

    $token = $validCookie ? (string)$cookieToken : $sec->issueCsrfToken();
    $csrfHeaderToEmit = $token;
    if (!$validCookie) {
        $csrfCookieToSet = $token;
    }
    $di->setShared('csrfToken', fn(): string => $token);
    return true;
});

$di->setShared('dispatcher', function () use ($eventsManager): Dispatcher {
    $dispatcher = new Dispatcher();
    $dispatcher->setDefaultNamespace('App\Controllers');
    $dispatcher->setEventsManager($eventsManager);
    return $dispatcher;
});

$eventsManager->attach('application:beforeSendResponse', static function (Event $event, Application $app, Response $response) use ($applySecurityHeaders, $path, &$csrfHeaderToEmit, &$csrfCookieToSet): void {
    $applySecurityHeaders($response, $path, $csrfHeaderToEmit, $csrfCookieToSet);
});

$application = new Application($di);
$application->useImplicitView(false);
$application->setEventsManager($eventsManager);

try {
    $response = $application->handle($path);
    if (!$response instanceof Response) {
        $response = new Response((string)$response, 200);
    }
    if (!$response->isSent()) {
        $applySecurityHeaders($response, $path, $csrfHeaderToEmit, $csrfCookieToSet);
        $response->send();
    }
} catch (HttpException $e) {
    $status = $e->statusCode;
    $allowHeader = null;
    $msg = $e->getMessage() ?: 'Error';
    if ($status === 405) {
        $allowHeader = $msg;
        $msg = 'Method Not Allowed';
    }
    $expectsJson = $status === 403 || str_starts_with($path, '/api/') || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    $response = new Response();
    $response->setStatusCode($status);
    if ($allowHeader !== null) {
        $response->setHeader('Allow', $allowHeader);
    }
    if ($expectsJson) {
        $response->setContentType('application/json', 'utf-8');
        if ($method !== 'HEAD') {
            $response->setContent(json_encode(['error' => $msg, 'status' => $status], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    } else {
        $safeMsg = htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $response->setContentType('text/html', 'utf-8');
        if ($method !== 'HEAD') {
            $response->setContent("<!DOCTYPE html><html lang=\"ja\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>{$status} - CloudDrive</title><link rel=\"stylesheet\" href=\"/assets/app.css?v=1\"></head><body><main class=\"content-page\"><h1>{$status}</h1><p>{$safeMsg}</p><p><a href=\"/\">マイドライブへ戻る</a></p></main></body></html>");
        }
    }
    $applySecurityHeaders($response, $path, null, null);
    $response->send();
} catch (\Throwable $e) {
    error_log('[phalcon] ' . $e->getMessage());
    $expectsJson = str_starts_with($path, '/api/') || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    $response = new Response();
    $response->setStatusCode(500);
    if ($expectsJson) {
        $response->setContentType('application/json', 'utf-8');
        if ($method !== 'HEAD') {
            $response->setContent(json_encode(['error' => 'Internal Server Error', 'status' => 500]));
        }
    } else {
        $response->setContentType('text/html', 'utf-8');
        if ($method !== 'HEAD') {
            $response->setContent('<!DOCTYPE html><html lang="ja"><head><meta charset="utf-8"><title>500 - CloudDrive</title></head><body><h1>500</h1><p>Internal Server Error</p></body></html>');
        }
    }
    $applySecurityHeaders($response, $path, null, null);
    $response->send();
}
