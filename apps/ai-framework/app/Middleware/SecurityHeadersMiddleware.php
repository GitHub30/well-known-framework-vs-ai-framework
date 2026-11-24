<?php
declare(strict_types=1);

namespace App\Middleware;

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
