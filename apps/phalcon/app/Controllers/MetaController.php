<?php
declare(strict_types=1);

namespace App\Controllers;

use Phalcon\Http\Response;

final class MetaController extends BaseController
{
    public function manifestAction(): Response
    {
        $json = json_encode($this->seo()->manifestJson($this->safeBaseUrl()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        return $this->textResponse((string)$json, 'application/manifest+json; charset=utf-8', 200, ['Cache-Control' => 'public, max-age=3600']);
    }

    public function robotsAction(): Response
    {
        return $this->textResponse($this->seo()->robotsTxt($this->safeBaseUrl()), 'text/plain; charset=utf-8', 200, ['Cache-Control' => 'public, max-age=3600']);
    }

    public function sitemapAction(): Response
    {
        $latestDate = $this->drive()->latestUpdatedDate();
        return $this->textResponse($this->seo()->sitemapXml($this->safeBaseUrl(), $latestDate), 'application/xml; charset=utf-8', 200, ['Cache-Control' => 'public, max-age=300']);
    }

    public function llmsAction(): Response
    {
        return $this->textResponse($this->seo()->llmsTxt($this->safeBaseUrl()), 'text/markdown; charset=utf-8', 200, ['Cache-Control' => 'public, max-age=3600']);
    }

    public function llmsFullAction(): Response
    {
        return $this->textResponse($this->seo()->llmsFullTxt($this->safeBaseUrl()), 'text/markdown; charset=utf-8', 200, ['Cache-Control' => 'public, max-age=3600']);
    }

    public function openapiAction(): Response
    {
        return $this->jsonResponse($this->seo()->openApiSpec($this->safeBaseUrl()), 200, ['Cache-Control' => 'public, max-age=3600']);
    }
}
