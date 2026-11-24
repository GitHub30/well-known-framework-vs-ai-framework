<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Security;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class CsrfMiddleware
{
    private Security $security;

    public function __construct()
    {
        $secret = getenv('APP_SECRET');
        $key = is_string($secret) && strlen($secret) >= 32 ? $secret : (string)config('app.key');
        $this->security = new Security($key);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $path = '/' . trim($request->path(), '/');
        if ($path === '//') {
            $path = '/';
        }

        if (Security::isPublicCacheablePath($path) && in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        $cookieToken = $request->cookies->get(Security::CSRF_COOKIE);
        $cookieStr = is_string($cookieToken) ? $cookieToken : null;
        $validCookie = $this->security->verifyCsrfToken($cookieStr);

        if (in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $headerToken = $request->header('X-CSRF-Token');
            if (!$this->security->verifyDoubleSubmit($cookieStr, is_string($headerToken) ? $headerToken : null)) {
                return response()->json(
                    ['error' => 'CSRF token mismatch or missing', 'status' => 403],
                    403,
                    ['Content-Type' => 'application/json; charset=utf-8'],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }
            $request->attributes->set('csrf_token', (string)$cookieStr);
            return $next($request);
        }

        $token = $validCookie ? (string)$cookieStr : $this->security->issueCsrfToken();
        $request->attributes->set('csrf_token', $token);

        /** @var Response $response */
        $response = $next($request);

        if ($response->getStatusCode() < 400) {
            $response->headers->set('X-CSRF-Token', $token);
            if (!$validCookie) {
                $response->headers->setCookie(Cookie::create(
                    Security::CSRF_COOKIE,
                    $token,
                    time() + Security::CSRF_TTL,
                    '/',
                    null,
                    $request->isSecure(),
                    true,
                    false,
                    Cookie::SAMESITE_LAX
                ));
            }
        }

        return $response;
    }
}
