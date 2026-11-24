<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Security;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeadersMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $path = '/' . trim($request->path(), '/');
        if ($path === '//') {
            $path = '/';
        }
        $isPreview = str_starts_with($path, '/api/files/') && str_ends_with($path, '/preview');
        $frameAncestors = $isPreview ? "'self'" : "'none'";
        $xFrame = $isPreview ? 'SAMEORIGIN' : 'DENY';

        if (!$response->headers->has('Content-Security-Policy')) {
            $csp = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors {$frameAncestors}; frame-src 'self'";
            $response->headers->set('Content-Security-Policy', $csp);
        }

        if (!Security::isPublicCacheablePath($path) && !$isPreview) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        $response->headers->set('X-Framework', 'laravel');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', $xFrame);
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        return $response;
    }
}
