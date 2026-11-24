<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\DriveService;
use App\Services\HttpException;
use App\Services\SeoService;
use Phalcon\Http\Response;
use Phalcon\Mvc\Controller;
use Phalcon\Mvc\View\Simple;

abstract class BaseController extends Controller
{
    protected function drive(): DriveService
    {
        return $this->getDI()->getShared('drive');
    }

    protected function seo(): SeoService
    {
        return $this->getDI()->getShared('seo');
    }

    protected function csrfToken(): string
    {
        return $this->getDI()->has('csrfToken') ? (string)$this->getDI()->getShared('csrfToken') : '';
    }

    protected function safeBaseUrl(): string
    {
        $configured = getenv('APP_URL');
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }
        return 'http://localhost:8083';
    }

    protected function currentPath(): string
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '/';
        }
        return $path !== '/' ? rtrim($path, '/') : '/';
    }

    protected function queryString(string $key, string $default = ''): string
    {
        if (!array_key_exists($key, $_GET)) {
            return $default;
        }
        $val = $_GET[$key];
        if (!is_string($val)) {
            throw new HttpException(400, "パラメータ {$key} の形式が不正です");
        }
        return mb_scrub($val, 'UTF-8');
    }

    /** @param array<string, mixed> $params */
    protected function renderPage(string $template, array $params, int $status = 200): Response
    {
        /** @var Simple $view */
        $view = $this->getDI()->getShared('simpleView');
        $e = static fn(mixed $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $jsonSafe = static fn(mixed $v): string => json_encode(
            $v,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );

        $viewParams = array_merge($params, ['e' => $e, 'jsonSafe' => $jsonSafe]);
        $inner = $view->render($template, $viewParams);
        $fullHtml = $view->render('layout', array_merge($viewParams, ['content' => $inner]));

        $res = new Response();
        $res->setStatusCode($status);
        $res->setContentType('text/html', 'utf-8');
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
            $res->setContent($fullHtml);
        }
        return $res;
    }

    protected function jsonResponse(mixed $data, int $status = 200, array $headers = []): Response
    {
        $res = new Response();
        $res->setStatusCode($status);
        $res->setContentType('application/json', 'utf-8');
        foreach ($headers as $k => $v) {
            $res->setHeader($k, $v);
        }
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
            $res->setContent(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        }
        return $res;
    }

    protected function textResponse(string $content, string $contentType, int $status = 200, array $headers = []): Response
    {
        $res = new Response();
        $res->setStatusCode($status);
        $res->setHeader('Content-Type', $contentType);
        foreach ($headers as $k => $v) {
            $res->setHeader($k, $v);
        }
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
            $res->setContent($content);
        }
        return $res;
    }
}
