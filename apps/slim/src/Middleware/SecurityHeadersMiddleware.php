<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Services\Security;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $path = $request->getUri()->getPath();
        $path = $path !== '/' ? rtrim($path, '/') : '/';
        $isPreview = str_starts_with($path, '/api/files/') && str_ends_with($path, '/preview');
        $frameAncestors = $isPreview ? "'self'" : "'none'";
        $xFrame = $isPreview ? 'SAMEORIGIN' : 'DENY';

        if (!$response->hasHeader('Content-Security-Policy')) {
            $csp = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors {$frameAncestors}; frame-src 'self'";
            $response = $response->withHeader('Content-Security-Policy', $csp);
        }

        if (!$response->hasHeader('Cache-Control') && !Security::isPublicCacheablePath($path)) {
            $response = $response->withHeader('Cache-Control', 'no-store, private');
        }

        return $response
            ->withHeader('X-Framework', 'slim')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', $xFrame)
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin');
    }
}
