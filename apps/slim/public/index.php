<?php
declare(strict_types=1);

use App\Controllers\ApiController;
use App\Controllers\MetaController;
use App\Controllers\PageController;
use App\Middleware\CsrfMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Services\Database;
use App\Services\DriveService;
use App\Services\HttpException;
use App\Services\Security;
use App\Services\SeoService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use Slim\Psr7\Response;
use Slim\Views\PhpRenderer;

$baseDir = dirname(__DIR__);
require $baseDir . '/vendor/autoload.php';

// Lazy service providers so unused controllers/views aren't constructed per request (FAIR-05)
$db = null;
$drive = null;
$seo = null;
$pageCtrl = null;
$apiCtrl = null;
$metaCtrl = null;

$getDrive = static function () use (&$db, &$drive, $baseDir): DriveService {
    if ($drive === null) {
        $db = new Database($baseDir . '/storage/database.sqlite');
        $drive = new DriveService($db, $baseDir . '/storage/uploads');
    }
    return $drive;
};
$getSeo = static function () use (&$seo): SeoService {
    return $seo ??= new SeoService();
};
$getPage = static function () use (&$pageCtrl, $getDrive, $getSeo, $baseDir): PageController {
    if ($pageCtrl === null) {
        $view = new PhpRenderer($baseDir . '/templates');
        $view->setLayout('layout.php');
        $pageCtrl = new PageController($getDrive(), $getSeo(), $view);
    }
    return $pageCtrl;
};
$getApi = static function () use (&$apiCtrl, $getDrive): ApiController {
    return $apiCtrl ??= new ApiController($getDrive());
};
$getMeta = static function () use (&$metaCtrl, $getDrive, $getSeo): MetaController {
    return $metaCtrl ??= new MetaController($getDrive(), $getSeo());
};

$security = new Security((string)getenv('APP_SECRET'));
$app = AppFactory::create();

// Production FastRoute cache (avoid per-request mkdir syscall once file exists — FAIR-05)
$cacheFile = $baseDir . '/storage/cache/routes.php';
if (!is_file($cacheFile)) {
    @mkdir(dirname($cacheFile), 0775, true);
}
$app->getRouteCollector()->setCacheFile($cacheFile);

// SSR Pages
$app->get('/', fn($req, $res) => $getPage()->home($req, $res));
$app->get('/drive/folders/{id:[0-9]+}', fn($req, $res, $args) => $getPage()->folder($req, $res, $args));
$app->get('/drive/recent', fn($req, $res) => $getPage()->recent($req, $res));
$app->get('/drive/starred', fn($req, $res) => $getPage()->starred($req, $res));
$app->get('/drive/shared', fn($req, $res) => $getPage()->shared($req, $res));
$app->get('/drive/trash', fn($req, $res) => $getPage()->trash($req, $res));
$app->get('/drive/search', fn($req, $res) => $getPage()->search($req, $res));
$app->get('/share/{token:[a-zA-Z0-9_-]+}', fn($req, $res, $args) => $getPage()->share($req, $res, $args));
$app->get('/about', fn($req, $res) => $getPage()->about($req, $res));
$app->get('/offline', fn($req, $res) => $getPage()->offline($req, $res));

// PWA, SEO, AEO, GEO, AIO, LLMO Endpoints
$app->get('/manifest.webmanifest', fn($req, $res) => $getMeta()->manifest($req, $res));
$app->get('/robots.txt', fn($req, $res) => $getMeta()->robots($req, $res));
$app->get('/sitemap.xml', fn($req, $res) => $getMeta()->sitemap($req, $res));
$app->get('/llms.txt', fn($req, $res) => $getMeta()->llms($req, $res));
$app->get('/llms-full.txt', fn($req, $res) => $getMeta()->llmsFull($req, $res));
$app->get('/openapi.json', fn($req, $res) => $getMeta()->openapi($req, $res));

// REST API
$app->get('/api/health', fn($req, $res) => $getApi()->health($req, $res));
$app->get('/api/items', fn($req, $res) => $getApi()->listItems($req, $res));
$app->get('/api/items/{id:[0-9]+}', fn($req, $res, $args) => $getApi()->getItem($req, $res, $args));
$app->post('/api/folders', fn($req, $res) => $getApi()->createFolder($req, $res));
$app->post('/api/files/upload', fn($req, $res) => $getApi()->uploadFile($req, $res));
$app->patch('/api/items/{id:[0-9]+}', fn($req, $res, $args) => $getApi()->updateItem($req, $res, $args));
$app->delete('/api/items/{id:[0-9]+}', fn($req, $res, $args) => $getApi()->deleteItem($req, $res, $args));
$app->post('/api/items/{id:[0-9]+}/restore', fn($req, $res, $args) => $getApi()->restoreItem($req, $res, $args));
$app->post('/api/trash/empty', fn($req, $res) => $getApi()->emptyTrash($req, $res));
$app->get('/api/files/{id:[0-9]+}/download', fn($req, $res, $args) => $getApi()->downloadFile($req, $res, $args));
$app->get('/api/files/{id:[0-9]+}/preview', fn($req, $res, $args) => $getApi()->previewFile($req, $res, $args));
$app->get('/api/storage', fn($req, $res) => $getApi()->storage($req, $res));

// Middleware stack (LIFO: SecurityHeaders -> TrailingSlash -> Error -> Routing -> CSRF -> Controller)
$app->add(new CsrfMiddleware($security));
$app->addRoutingMiddleware();

$errorMiddleware = $app->addErrorMiddleware(false, true, false);
$errorMiddleware->setDefaultErrorHandler(static function (
    ServerRequestInterface $request,
    Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails
): Response {
    $status = 500;
    $msg = 'Internal Server Error';
    $allowHeader = null;
    if ($exception instanceof HttpException) {
        $status = $exception->statusCode;
        $msg = $exception->getMessage() ?: 'Error';
    } elseif ($exception instanceof HttpNotFoundException) {
        $status = 404;
        $msg = 'Not Found';
    } elseif ($exception instanceof HttpMethodNotAllowedException) {
        $status = 405;
        $msg = 'Method Not Allowed';
        $allowHeader = implode(', ', $exception->getAllowedMethods());
    } elseif ($exception instanceof \Slim\Exception\HttpException) {
        $status = $exception->getCode() ?: 500;
        $msg = $exception->getMessage() ?: 'Error';
    } else {
        error_log('[slim] ' . $exception->getMessage());
    }

    $path = $request->getUri()->getPath();
    $expectsJson = str_starts_with($path, '/api/') || str_contains($request->getHeaderLine('Accept'), 'application/json');
    $res = new Response($status);
    if ($allowHeader !== null) {
        $res = $res->withHeader('Allow', $allowHeader);
    }
    if ($expectsJson) {
        $res->getBody()->write((string)json_encode(['error' => $msg, 'status' => $status], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $res->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
    $safeMsg = htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $res->getBody()->write("<!DOCTYPE html><html lang=\"ja\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>{$status} - CloudDrive</title><link rel=\"stylesheet\" href=\"/assets/app.css?v=1\"></head><body><main class=\"content-page\"><h1>{$status}</h1><p>{$safeMsg}</p><p><a href=\"/\">マイドライブへ戻る</a></p></main></body></html>");
    return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
});

// Normalize trailing slashes before routing (PARITY-03)
$app->add(static function (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
    $uri = $request->getUri();
    $path = $uri->getPath();
    if ($path !== '/' && str_ends_with($path, '/')) {
        $request = $request->withUri($uri->withPath(rtrim($path, '/')));
    }
    return $handler->handle($request);
});

$app->add(new SecurityHeadersMiddleware());

$app->run();
