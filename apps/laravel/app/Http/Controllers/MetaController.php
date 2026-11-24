<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DriveService;
use App\Services\SeoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class MetaController extends Controller
{
    public function __construct(
        private readonly DriveService $drive,
        private readonly SeoService $seo,
    ) {}

    public function manifest(): Response
    {
        $json = json_encode($this->seo->manifestJson($this->baseUrl()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        return response((string)$json, 200, [
            'Content-Type' => 'application/manifest+json; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function robots(): Response
    {
        return response($this->seo->robotsTxt($this->baseUrl()), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function sitemap(): Response
    {
        $latestDate = $this->drive->latestUpdatedDate();
        return response($this->seo->sitemapXml($this->baseUrl(), $latestDate), 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function llms(): Response
    {
        return response($this->seo->llmsTxt($this->baseUrl()), 200, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function llmsFull(): Response
    {
        return response($this->seo->llmsFullTxt($this->baseUrl()), 200, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function openapi(): JsonResponse
    {
        return response()->json(
            $this->seo->openApiSpec($this->baseUrl()),
            200,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'public, max-age=3600'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    private function baseUrl(): string
    {
        $configured = getenv('APP_URL') ?: (string)config('app.url', 'http://localhost:8082');
        return rtrim($configured, '/');
    }
}
