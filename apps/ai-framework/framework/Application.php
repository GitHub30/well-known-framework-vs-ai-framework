<?php
declare(strict_types=1);

namespace AiFramework;

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
        $safeMsg = htmlspecialchars($message ?: 'エラーが発生しました', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = "<!DOCTYPE html><html lang=\"ja\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>{$status} - CloudDrive</title><link rel=\"stylesheet\" href=\"/assets/app.css?v=1\"></head><body><main class=\"content-page\"><h1>{$status}</h1><p>{$safeMsg}</p><p><a href=\"/\">マイドライブへ戻る</a></p></main></body></html>";
        return Response::html($html, $status);
    }
}
