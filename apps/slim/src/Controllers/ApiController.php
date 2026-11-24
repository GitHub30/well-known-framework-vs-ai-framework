<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\DriveService;
use App\Services\HttpException;
use App\Services\Security;
use App\Services\SeoService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\StreamFactory;

final class ApiController
{
    public function __construct(private readonly DriveService $drive) {}

    public function health(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, [
            'status' => 'ok',
            'framework' => SeoService::FRAMEWORK_ID,
            'php_version' => PHP_VERSION,
            'items_total' => $this->drive->totalItemCount(),
        ]);
    }

    public function listItems(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $qp = $request->getQueryParams();
        $view = $this->queryString($qp, 'view', 'my-drive');
        $folderId = Security::parseOptionalPositiveInt($qp['folder_id'] ?? null, 'folder_id', 400);
        $q = $this->queryString($qp, 'q', '');
        $type = $this->queryString($qp, 'type', 'all');
        $sort = $this->queryString($qp, 'sort', $view === 'recent' ? 'updated_at' : 'name');
        $dir = $this->queryString($qp, 'dir', $view === 'recent' ? 'desc' : 'asc');

        return $this->json($response, $this->drive->listItems($view, $folderId, $q, $type, $sort, $dir));
    }

    /** @param array<string, string> $args */
    public function getItem(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->json($response, $this->drive->getItemDetail((int)($args['id'] ?? 0)));
    }

    public function createFolder(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->readJson($request);
        $name = (string)Security::requireString($body['name'] ?? null, 'name');
        $parentId = Security::parseOptionalPositiveInt($body['parent_id'] ?? null, 'parent_id', 422);

        return $this->json($response, ['item' => $this->drive->createFolder($name, $parentId)], 201);
    }

    public function uploadFile(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $contentLength = (int)$request->getHeaderLine('Content-Length');
        if ($contentLength > DriveService::MAX_UPLOAD_BYTES && empty($_FILES)) {
            throw new HttpException(413, 'リクエストサイズが上限（20MB）を超えています');
        }
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            throw new HttpException(422, 'アップロードするファイルを選択してください');
        }
        $parsed = (array)($request->getParsedBody() ?? []);
        $parentId = Security::parseOptionalPositiveInt($parsed['parent_id'] ?? null, 'parent_id', 422);
        $desc = (string)Security::requireString($parsed['description'] ?? '', 'description');

        return $this->json($response, ['item' => $this->drive->uploadFile($_FILES['file'], $parentId, $desc)], 201);
    }

    /** @param array<string, string> $args */
    public function updateItem(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->json($response, ['item' => $this->drive->updateItem((int)($args['id'] ?? 0), $this->readJson($request))]);
    }

    /** @param array<string, string> $args */
    public function deleteItem(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $permanent = $this->queryString($request->getQueryParams(), 'permanent', '0') === '1';
        return $this->json($response, $this->drive->deleteItem((int)($args['id'] ?? 0), $permanent));
    }

    /** @param array<string, string> $args */
    public function restoreItem(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->json($response, ['item' => $this->drive->restoreItem((int)($args['id'] ?? 0))]);
    }

    public function emptyTrash(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, $this->drive->emptyTrash());
    }

    /** @param array<string, string> $args */
    public function downloadFile(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $resolved = $this->drive->resolveFileForTransfer((int)($args['id'] ?? 0));
        $name = (string)$resolved['item']['name'];
        $safeAscii = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name) ?: 'download';
        $utf8 = rawurlencode($name);

        $stream = (new StreamFactory())->createStreamFromFile($resolved['path'], 'rb');
        return $response
            ->withBody($stream)
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Length', (string)filesize($resolved['path']))
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('Content-Disposition', "attachment; filename=\"{$safeAscii}\"; filename*=UTF-8''{$utf8}");
    }

    /** @param array<string, string> $args */
    public function previewFile(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $resolved = $this->drive->resolveFileForTransfer((int)($args['id'] ?? 0));
        $item = $resolved['item'];
        $path = $resolved['path'];
        $mime = strtolower((string)$item['mime_type']);
        $ext = strtolower((string)$item['extension']);

        if (in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
            $stream = (new StreamFactory())->createStreamFromFile($path, 'rb');
            return $response
                ->withBody($stream)
                ->withHeader('Content-Type', $mime)
                ->withHeader('Cache-Control', 'private, max-age=3600')
                ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox; frame-ancestors 'self'");
        }

        if ($mime === 'application/pdf') {
            $stream = (new StreamFactory())->createStreamFromFile($path, 'rb');
            return $response
                ->withBody($stream)
                ->withHeader('Content-Type', 'application/pdf')
                ->withHeader('Content-Disposition', 'inline')
                ->withHeader('Cache-Control', 'private, max-age=3600')
                ->withHeader('Content-Security-Policy', "default-src 'none'; object-src 'self'; script-src 'none'; form-action 'none'; frame-ancestors 'self'");
        }

        $isText = str_starts_with($mime, 'text/')
            || in_array($mime, ['application/json', 'application/xml', 'image/svg+xml'], true)
            || in_array($ext, ['md', 'txt', 'csv', 'json', 'xml', 'log', 'php', 'js', 'css', 'html', 'svg'], true);

        if ($isText) {
            $maxBytes = 262144;
            $raw = file_get_contents($path, false, null, 0, $maxBytes + 4);
            $truncated = filesize($path) > $maxBytes;
            $safeText = $raw === false ? '' : mb_scrub(mb_strcut($raw, 0, $maxBytes, 'UTF-8'), 'UTF-8');
            return $this->json($response, [
                'id' => $item['id'],
                'name' => $item['name'],
                'mime_type' => $item['mime_type'],
                'truncated' => $truncated,
                'content' => $safeText,
            ]);
        }

        throw new HttpException(415, 'このファイル形式のインラインプレビューはサポートされていません');
    }

    public function storage(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, $this->drive->getStorageSummary());
    }

    /** @param array<string, mixed> $qp */
    private function queryString(array $qp, string $key, string $default): string
    {
        if (!array_key_exists($key, $qp)) {
            return $default;
        }
        $val = $qp[$key];
        if (!is_string($val)) {
            throw new HttpException(400, "パラメータ {$key} の形式が不正です");
        }
        return mb_scrub($val, 'UTF-8');
    }

    /** @return array<string, mixed> */
    private function readJson(ServerRequestInterface $request): array
    {
        $raw = (string)$request->getBody();
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $parsed = $request->getParsedBody();
        return is_array($parsed) ? $parsed : [];
    }

    private function json(ResponseInterface $response, mixed $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        ));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
