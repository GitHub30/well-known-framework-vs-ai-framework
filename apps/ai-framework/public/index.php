<?php
declare(strict_types=1);

require_once __DIR__ . '/../framework/Autoloader.php';

use AiFramework\Application;
use AiFramework\Autoloader;
use AiFramework\Container;
use AiFramework\Database;
use AiFramework\Request;
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

$baseDir = dirname(__DIR__);

Autoloader::register([
    'AiFramework\\' => $baseDir . '/framework/',
    'App\\' => $baseDir . '/app/',
]);

$container = new Container();

$container->singleton(Database::class, static fn(): Database => new Database($baseDir . '/storage/database.sqlite'));
$container->singleton(View::class, static fn(): View => new View($baseDir . '/templates'));
$container->singleton(Security::class, static fn(): Security => new Security((string)getenv('APP_SECRET')));
$container->singleton(DriveService::class, static fn(Container $c): DriveService => new DriveService(
    $c->get(Database::class),
    $baseDir . '/storage/uploads'
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
$router->get('/drive/folders/{id:\d+}', [PageController::class, 'folder']);
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

// REST JSON & Binary API
$router->get('/api/health', [ApiController::class, 'health']);
$router->get('/api/items', [ApiController::class, 'listItems']);
$router->get('/api/items/{id:\d+}', [ApiController::class, 'getItem']);
$router->post('/api/folders', [ApiController::class, 'createFolder']);
$router->post('/api/files/upload', [ApiController::class, 'uploadFile']);
$router->patch('/api/items/{id:\d+}', [ApiController::class, 'updateItem']);
$router->delete('/api/items/{id:\d+}', [ApiController::class, 'deleteItem']);
$router->post('/api/items/{id:\d+}/restore', [ApiController::class, 'restoreItem']);
$router->post('/api/trash/empty', [ApiController::class, 'emptyTrash']);
$router->get('/api/files/{id:\d+}/download', [ApiController::class, 'downloadFile']);
$router->get('/api/files/{id:\d+}/preview', [ApiController::class, 'previewFile']);
$router->get('/api/storage', [ApiController::class, 'storage']);

$app = new Application($container, $router);
$app->addMiddleware(new SecurityHeadersMiddleware());
$app->addMiddleware(new CsrfMiddleware($container->get(Security::class)));

$request = Request::capture();
$response = $app->handle($request);
$response->send($request->method === 'HEAD');
