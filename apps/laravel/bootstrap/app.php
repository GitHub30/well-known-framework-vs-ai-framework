<?php
declare(strict_types=1);

use App\Http\Middleware\CsrfMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Fair, lean stateless middleware stack (removes wildcard HandleCors & unused triple-encrypted session cookies — FAIR-01, SEC-01)
        $middleware->use([
            SecurityHeadersMiddleware::class,
            ValidatePostSize::class,
        ]);
        $middleware->group('web', [
            SubstituteBindings::class,
            CsrfMiddleware::class,
        ]);
        $middleware->group('api', [
            SubstituteBindings::class,
            CsrfMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (\Throwable $e, Request $request) {
            $status = 500;
            $message = 'Internal Server Error';
            $headers = [];

            if ($e instanceof MethodNotAllowedHttpException) {
                $status = 405;
                $message = 'Method Not Allowed';
                $headers = $e->getHeaders();
            } elseif ($e instanceof NotFoundHttpException) {
                $status = 404;
                $message = 'Not Found';
            } elseif ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                $message = $e->getMessage() ?: 'Error';
                $headers = $e->getHeaders();
            }

            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(
                    ['error' => $message, 'status' => $status],
                    $status,
                    array_merge(['Content-Type' => 'application/json; charset=utf-8'], $headers),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
                );
            }

            $safeMsg = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html = "<!DOCTYPE html><html lang=\"ja\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>{$status} - CloudDrive</title><link rel=\"stylesheet\" href=\"/assets/app.css?v=1\"></head><body><main class=\"content-page\"><h1>{$status}</h1><p>{$safeMsg}</p><p><a href=\"/\">マイドライブへ戻る</a></p></main></body></html>";
            return response($html, $status, array_merge(['Content-Type' => 'text/html; charset=utf-8'], $headers));
        });
    })->create();
