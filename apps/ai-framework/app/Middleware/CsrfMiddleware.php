<?php
declare(strict_types=1);

namespace App\Middleware;

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
