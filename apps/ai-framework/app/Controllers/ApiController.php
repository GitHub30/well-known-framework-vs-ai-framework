<?php
declare(strict_types=1);

namespace App\Controllers;

use AiFramework\HttpException;
use AiFramework\Request;
use AiFramework\Response;
use AiFramework\Security;
use App\Services\DriveService;
use App\Services\SeoService;

final class ApiController
{
    public function __construct(private readonly DriveService $drive) {}

    public function health(Request $request): Response
    {
        return Response::json([
            'status' => 'ok',
            'framework' => SeoService::FRAMEWORK_ID,
            'php_version' => PHP_VERSION,
            'items_total' => $this->drive->totalItemCount(),
        ]);
    }

    public function listItems(Request $request): Response
    {
        $view = $request->queryString('view', 'my-drive');
        $folderId = Security::parseOptionalPositiveInt($request->query('folder_id'), 'folder_id', 400);
        $q = $request->queryString('q', '');
        $type = $request->queryString('type', 'all');
        $sort = $request->queryString('sort', $view === 'recent' ? 'updated_at' : 'name');
        $dir = $request->queryString('dir', $view === 'recent' ? 'desc' : 'asc');

        $data = $this->drive->listItems($view, $folderId, $q, $type, $sort, $dir);
        return Response::json($data);
    }

    public function getItem(Request $request): Response
    {
        $id = (int)$request->param('id', '0');
        return Response::json($this->drive->getItemDetail($id));
    }

    public function createFolder(Request $request): Response
    {
        $body = $request->json();
        $name = (string)Security::requireString($body['name'] ?? null, 'name');
        $parentId = Security::parseOptionalPositiveInt($body['parent_id'] ?? null, 'parent_id', 422);

        $item = $this->drive->createFolder($name, $parentId);
        return Response::json(['item' => $item], 201);
    }

    public function uploadFile(Request $request): Response
    {
        // Detect post_max_size overflow -> return 413 consistently (SEC-13)
        $contentLength = (int)($request->header('content-length') ?? '0');
        if ($contentLength > DriveService::MAX_UPLOAD_BYTES && empty($request->files)) {
            throw new HttpException(413, 'リクエストサイズが上限（20MB）を超えています');
        }
        if (!isset($request->files['file']) || !is_array($request->files['file'])) {
            throw new HttpException(422, 'アップロードするファイルを選択してください');
        }
        $parentId = Security::parseOptionalPositiveInt($request->post['parent_id'] ?? null, 'parent_id', 422);
        $descRaw = $request->post['description'] ?? '';
        $desc = (string)Security::requireString($descRaw, 'description');

        $item = $this->drive->uploadFile($request->files['file'], $parentId, $desc);
        return Response::json(['item' => $item], 201);
    }

    public function updateItem(Request $request): Response
    {
        $id = (int)$request->param('id', '0');
        $item = $this->drive->updateItem($id, $request->json());
        return Response::json(['item' => $item]);
    }

    public function deleteItem(Request $request): Response
    {
        $id = (int)$request->param('id', '0');
        $permanent = $request->queryString('permanent', '0') === '1';
        return Response::json($this->drive->deleteItem($id, $permanent));
    }

    public function restoreItem(Request $request): Response
    {
        $id = (int)$request->param('id', '0');
        $item = $this->drive->restoreItem($id);
        return Response::json(['item' => $item]);
    }

    public function emptyTrash(Request $request): Response
    {
        return Response::json($this->drive->emptyTrash());
    }

    public function downloadFile(Request $request): Response
    {
        $id = (int)$request->param('id', '0');
        $resolved = $this->drive->resolveFileForTransfer($id);
        return Response::file($resolved['path'], 'application/octet-stream', (string)$resolved['item']['name'], [
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function previewFile(Request $request): Response
    {
        $id = (int)$request->param('id', '0');
        $resolved = $this->drive->resolveFileForTransfer($id);
        $item = $resolved['item'];
        $path = $resolved['path'];
        $mime = strtolower((string)$item['mime_type']);
        $ext = strtolower((string)$item['extension']);

        if (in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
            return Response::file($path, $mime, null, [
                'Cache-Control' => 'private, max-age=3600',
                'Content-Security-Policy' => "default-src 'none'; sandbox; frame-ancestors 'self'",
            ]);
        }

        if ($mime === 'application/pdf') {
            return Response::file($path, 'application/pdf', null, [
                'Content-Disposition' => 'inline',
                'Cache-Control' => 'private, max-age=3600',
                'Content-Security-Policy' => "default-src 'none'; object-src 'self'; script-src 'none'; form-action 'none'; frame-ancestors 'self'",
            ]);
        }

        $isText = str_starts_with($mime, 'text/')
            || in_array($mime, ['application/json', 'application/xml', 'image/svg+xml'], true)
            || in_array($ext, ['md', 'txt', 'csv', 'json', 'xml', 'log', 'php', 'js', 'css', 'html', 'svg'], true);

        if ($isText) {
            $maxBytes = 262144;
            $raw = file_get_contents($path, false, null, 0, $maxBytes + 4);
            $truncated = filesize($path) > $maxBytes;
            $safeText = $raw === false ? '' : mb_scrub(mb_strcut($raw, 0, $maxBytes, 'UTF-8'), 'UTF-8');
            return Response::json([
                'id' => $item['id'],
                'name' => $item['name'],
                'mime_type' => $item['mime_type'],
                'truncated' => $truncated,
                'content' => $safeText,
            ]);
        }

        throw new HttpException(415, 'このファイル形式のインラインプレビューはサポートされていません');
    }

    public function storage(Request $request): Response
    {
        return Response::json($this->drive->getStorageSummary());
    }
}
