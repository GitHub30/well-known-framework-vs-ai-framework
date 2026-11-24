<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Services\Security;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Security $security) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $path = $path !== '/' ? rtrim($path, '/') : '/';
        $method = strtoupper($request->getMethod());

        if (Security::isPublicCacheablePath($path) && in_array($method, ['GET', 'HEAD'], true)) {
            return $handler->handle($request->withAttribute('csrf_token', ''));
        }

        $cookies = $request->getCookieParams();
        $cookieToken = isset($cookies[Security::CSRF_COOKIE]) && is_string($cookies[Security::CSRF_COOKIE]) ? $cookies[Security::CSRF_COOKIE] : null;
        $validCookie = $this->security->verifyCsrfToken($cookieToken);

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $headerToken = $request->getHeaderLine('X-CSRF-Token');
            if (!$this->security->verifyDoubleSubmit($cookieToken, $headerToken !== '' ? $headerToken : null)) {
                $res = new Response(403);
                $res->getBody()->write((string)json_encode(['error' => 'CSRF token mismatch or missing', 'status' => 403], JSON_UNESCAPED_UNICODE));
                return $res->withHeader('Content-Type', 'application/json; charset=utf-8');
            }
            return $handler->handle($request->withAttribute('csrf_token', (string)$cookieToken));
        }

        $token = $validCookie ? (string)$cookieToken : $this->security->issueCsrfToken();
        $response = $handler->handle($request->withAttribute('csrf_token', $token));

        if ($response->getStatusCode() < 400) {
            $response = $response->withHeader('X-CSRF-Token', $token);
            if (!$validCookie) {
                $isHttps = $request->getUri()->getScheme() === 'https';
                $expires = gmdate('D, d M Y H:i:s \G\M\T', time() + Security::CSRF_TTL);
                $cookieHeader = sprintf(
                    '%s=%s; Expires=%s; Max-Age=%d; Path=/;%s HttpOnly; SameSite=Lax',
                    Security::CSRF_COOKIE,
                    rawurlencode($token),
                    $expires,
                    Security::CSRF_TTL,
                    $isHttps ? ' Secure;' : ''
                );
                $response = $response->withAddedHeader('Set-Cookie', $cookieHeader);
            }
        }

        return $response;
    }
}
