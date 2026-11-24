<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\DriveService;
use App\Services\SeoService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class MetaController
{
    public function __construct(
        private readonly DriveService $drive,
        private readonly SeoService $seo,
    ) {}

    public function manifest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $json = (string)json_encode($this->seo->manifestJson($this->baseUrl()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $response->getBody()->write($json);
        return $response
            ->withHeader('Content-Type', 'application/manifest+json; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    public function robots(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write($this->seo->robotsTxt($this->baseUrl()));
        return $response
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    public function sitemap(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $latestDate = $this->drive->latestUpdatedDate();
        $response->getBody()->write($this->seo->sitemapXml($this->baseUrl(), $latestDate));
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    public function llms(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write($this->seo->llmsTxt($this->baseUrl()));
        return $response
            ->withHeader('Content-Type', 'text/markdown; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    public function llmsFull(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write($this->seo->llmsFullTxt($this->baseUrl()));
        return $response
            ->withHeader('Content-Type', 'text/markdown; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    public function openapi(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write(json_encode($this->seo->openApiSpec($this->baseUrl()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    private function baseUrl(): string
    {
        $configured = getenv('APP_URL');
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }
        return 'http://localhost:8084';
    }
}
