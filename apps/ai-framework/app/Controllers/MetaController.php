<?php
declare(strict_types=1);

namespace App\Controllers;

use AiFramework\Request;
use AiFramework\Response;
use App\Services\DriveService;
use App\Services\SeoService;

final class MetaController
{
    public function __construct(
        private readonly DriveService $drive,
        private readonly SeoService $seo,
    ) {}

    public function manifest(Request $request): Response
    {
        $json = json_encode($this->seo->manifestJson($request->baseUrl()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        return Response::text((string)$json, 200, 'application/manifest+json; charset=utf-8', [
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function robots(Request $request): Response
    {
        return Response::text($this->seo->robotsTxt($request->baseUrl()), 200, 'text/plain; charset=utf-8', [
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function sitemap(Request $request): Response
    {
        $latestDate = $this->drive->latestUpdatedDate();
        return Response::text($this->seo->sitemapXml($request->baseUrl(), $latestDate), 200, 'application/xml; charset=utf-8', [
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function llms(Request $request): Response
    {
        return Response::text($this->seo->llmsTxt($request->baseUrl()), 200, 'text/markdown; charset=utf-8', [
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function llmsFull(Request $request): Response
    {
        return Response::text($this->seo->llmsFullTxt($request->baseUrl()), 200, 'text/markdown; charset=utf-8', [
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function openapi(Request $request): Response
    {
        return Response::json($this->seo->openApiSpec($request->baseUrl()), 200, [
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
