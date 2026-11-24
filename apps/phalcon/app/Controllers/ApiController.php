<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\DriveService;
use App\Services\HttpException;
use App\Services\Security;
use App\Services\SeoService;
use Phalcon\Http\Response;

final class ApiController extends BaseController
{
    public function healthAction(): Response
    {
        return $this->jsonResponse([
            'status' => 'ok',
            'framework' => SeoService::FRAMEWORK_ID,
            'php_version' => PHP_VERSION,
            'items_total' => $this->drive()->totalItemCount(),
        ]);
    }

    public function listItemsAction(): Response
    {
        $view = $this->queryString('view', 'my-drive');
        $folderId = Security::parseOptionalPositiveInt($_GET['folder_id'] ?? null, 'folder_id', 400);
        $q = $this->queryString('q', '');
        $type = $this->queryString('type', 'all');
        $sort = $this->queryString('sort', $view === 'recent' ? 'updated_at' : 'name');
        $dir = $this->queryString('dir', $view === 'recent' ? 'desc' : 'asc');

        return $this->jsonResponse($this->drive()->listItems($view, $folderId, $q, $type, $sort, $dir));
    }

    public function getItemAction(string $id): Response
    {
        return $this->jsonResponse($this->drive()->getItemDetail((int)$id));
    }

    public function createFolderAction(): Response
    {
        $body = $this->request->getJsonRawBody(true);
        if (!is_array($body)) {
            $body = $this->request->getPost();
        }
        $name = (string)Security::requireString($body['name'] ?? null, 'name');
        $parentId = Security::parseOptionalPositiveInt($body['parent_id'] ?? null, 'parent_id', 422);

        return $this->jsonResponse(['item' => $this->drive()->createFolder($name, $parentId)], 201);
    }

    public function uploadFileAction(): Response
    {
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? '0');
        if ($contentLength > DriveService::MAX_UPLOAD_BYTES && empty($_FILES)) {
            throw new HttpException(413, 'リクエストサイズが上限（20MB）を超えています');
        }
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            throw new HttpException(422, 'アップロードするファイルを選択してください');
        }
        $parentId = Security::parseOptionalPositiveInt($_POST['parent_id'] ?? null, 'parent_id', 422);
        $desc = (string)Security::requireString($_POST['description'] ?? '', 'description');

        return $this->jsonResponse(['item' => $this->drive()->uploadFile($_FILES['file'], $parentId, $desc)], 201);
    }

    public function updateItemAction(string $id): Response
    {
        $body = $this->request->getJsonRawBody(true);
        if (!is_array($body)) {
            $body = [];
        }
        return $this->jsonResponse(['item' => $this->drive()->updateItem((int)$id, $body)]);
    }

    public function deleteItemAction(string $id): Response
    {
        $permanent = $this->queryString('permanent', '0') === '1';
        return $this->jsonResponse($this->drive()->deleteItem((int)$id, $permanent));
    }

    public function restoreItemAction(string $id): Response
    {
        return $this->jsonResponse(['item' => $this->drive()->restoreItem((int)$id)]);
    }

    public function emptyTrashAction(): Response
    {
        return $this->jsonResponse($this->drive()->emptyTrash());
    }

    public function downloadFileAction(string $id): Response
    {
        $resolved = $this->drive()->resolveFileForTransfer((int)$id);
        $name = (string)$resolved['item']['name'];
        $safeAscii = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name) ?: 'download';
        $utf8 = rawurlencode($name);

        $res = new Response();
        $res->setStatusCode(200);
        $res->setHeader('Content-Type', 'application/octet-stream');
        $res->setHeader('Content-Length', (string)filesize($resolved['path']));
        $res->setHeader('Cache-Control', 'no-store, private');
        $res->setHeader('Content-Disposition', "attachment; filename=\"{$safeAscii}\"; filename*=UTF-8''{$utf8}");
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
            $res->setFileToSend($resolved['path'], $name, false);
        }
        return $res;
    }

    public function previewFileAction(string $id): Response
    {
        $resolved = $this->drive()->resolveFileForTransfer((int)$id);
        $item = $resolved['item'];
        $path = $resolved['path'];
        $mime = strtolower((string)$item['mime_type']);
        $ext = strtolower((string)$item['extension']);
        $isHead = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD';

        if (in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
            $res = new Response();
            $res->setStatusCode(200);
            $res->setHeader('Content-Type', $mime);
            $res->setHeader('Cache-Control', 'private, max-age=3600');
            $res->setHeader('Content-Security-Policy', "default-src 'none'; sandbox; frame-ancestors 'self'");
            if (!$isHead) {
                $res->setFileToSend($path, null, false);
            }
            return $res;
        }

        if ($mime === 'application/pdf') {
            $res = new Response();
            $res->setStatusCode(200);
            $res->setHeader('Content-Type', 'application/pdf');
            $res->setHeader('Content-Disposition', 'inline');
            $res->setHeader('Cache-Control', 'private, max-age=3600');
            $res->setHeader('Content-Security-Policy', "default-src 'none'; object-src 'self'; script-src 'none'; form-action 'none'; frame-ancestors 'self'");
            if (!$isHead) {
                $res->setFileToSend($path, null, false);
            }
            return $res;
        }

        $isText = str_starts_with($mime, 'text/')
            || in_array($mime, ['application/json', 'application/xml', 'image/svg+xml'], true)
            || in_array($ext, ['md', 'txt', 'csv', 'json', 'xml', 'log', 'php', 'js', 'css', 'html', 'svg'], true);

        if ($isText) {
            $maxBytes = 262144;
            $raw = file_get_contents($path, false, null, 0, $maxBytes + 4);
            $truncated = filesize($path) > $maxBytes;
            $safeText = $raw === false ? '' : mb_scrub(mb_strcut($raw, 0, $maxBytes, 'UTF-8'), 'UTF-8');
            return $this->jsonResponse([
                'id' => $item['id'],
                'name' => $item['name'],
                'mime_type' => $item['mime_type'],
                'truncated' => $truncated,
                'content' => $safeText,
            ]);
        }

        throw new HttpException(415, 'このファイル形式のインラインプレビューはサポートされていません');
    }

    public function storageAction(): Response
    {
        return $this->jsonResponse($this->drive()->getStorageSummary());
    }
}
