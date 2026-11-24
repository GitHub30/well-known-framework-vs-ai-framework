<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DriveService;
use App\Services\Security;
use App\Services\SeoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ApiController extends Controller
{
    private const JSON_OPTS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    public function __construct(private readonly DriveService $drive) {}

    public function health(): JsonResponse
    {
        return $this->json([
            'status' => 'ok',
            'framework' => SeoService::FRAMEWORK_ID,
            'php_version' => PHP_VERSION,
            'items_total' => $this->drive->totalItemCount(),
        ]);
    }

    public function listItems(Request $request): JsonResponse
    {
        $view = $this->queryString($request, 'view', 'my-drive');
        $folderRaw = $request->query->all()['folder_id'] ?? null;
        $folderId = Security::parseOptionalPositiveInt($folderRaw, 'folder_id', 400);
        $q = $this->queryString($request, 'q', '');
        $type = $this->queryString($request, 'type', 'all');
        $sort = $this->queryString($request, 'sort', $view === 'recent' ? 'updated_at' : 'name');
        $dir = $this->queryString($request, 'dir', $view === 'recent' ? 'desc' : 'asc');

        return $this->json($this->drive->listItems($view, $folderId, $q, $type, $sort, $dir));
    }

    public function getItem(string $id): JsonResponse
    {
        return $this->json($this->drive->getItemDetail((int)$id));
    }

    public function createFolder(Request $request): JsonResponse
    {
        $body = $this->readBody($request);
        $name = (string)Security::requireString($body['name'] ?? null, 'name');
        $parentId = Security::parseOptionalPositiveInt($body['parent_id'] ?? null, 'parent_id', 422);
        return $this->json(['item' => $this->drive->createFolder($name, $parentId)], 201);
    }

    public function uploadFile(Request $request): JsonResponse
    {
        $parentId = Security::parseOptionalPositiveInt($request->post('parent_id'), 'parent_id', 422);
        $desc = (string)Security::requireString($request->post('description', ''), 'description');
        $item = $this->drive->uploadFile($request->file('file'), $parentId, $desc);
        return $this->json(['item' => $item], 201);
    }

    public function updateItem(Request $request, string $id): JsonResponse
    {
        return $this->json(['item' => $this->drive->updateItem((int)$id, $this->readBody($request))]);
    }

    public function deleteItem(Request $request, string $id): JsonResponse
    {
        $permanent = $this->queryString($request, 'permanent', '0') === '1';
        return $this->json($this->drive->deleteItem((int)$id, $permanent));
    }

    public function restoreItem(string $id): JsonResponse
    {
        return $this->json(['item' => $this->drive->restoreItem((int)$id)]);
    }

    public function emptyTrash(): JsonResponse
    {
        return $this->json($this->drive->emptyTrash());
    }

    public function downloadFile(string $id): BinaryFileResponse
    {
        $resolved = $this->drive->resolveFileForTransfer((int)$id);
        $name = (string)$resolved['item']['name'];
        $safeAscii = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name) ?: 'download';
        $utf8 = rawurlencode($name);

        return response()->file($resolved['path'], [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => "attachment; filename=\"{$safeAscii}\"; filename*=UTF-8''{$utf8}",
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function previewFile(string $id): Response
    {
        $resolved = $this->drive->resolveFileForTransfer((int)$id);
        $item = $resolved['item'];
        $path = $resolved['path'];
        $mime = strtolower((string)$item['mime_type']);
        $ext = strtolower((string)$item['extension']);

        if (in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
            return response()->file($path, [
                'Content-Type' => $mime,
                'Cache-Control' => 'private, max-age=3600',
                'Content-Security-Policy' => "default-src 'none'; sandbox; frame-ancestors 'self'",
            ]);
        }

        if ($mime === 'application/pdf') {
            return response()->file($path, [
                'Content-Type' => 'application/pdf',
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
            return $this->json([
                'id' => $item['id'],
                'name' => $item['name'],
                'mime_type' => $item['mime_type'],
                'truncated' => $truncated,
                'content' => $safeText,
            ]);
        }

        throw new HttpException(415, 'このファイル形式のインラインプレビューはサポートされていません');
    }

    public function storage(): JsonResponse
    {
        return $this->json($this->drive->getStorageSummary());
    }

    /** @return array<string, mixed> */
    private function readBody(Request $request): array
    {
        $raw = (string)$request->getContent();
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $request->post();
    }

    private function queryString(Request $request, string $key, string $default): string
    {
        if (!$request->query->has($key)) {
            return $default;
        }
        $val = $request->query->all()[$key] ?? null;
        if (!is_string($val)) {
            throw new HttpException(400, "パラメータ {$key} の形式が不正です");
        }
        return mb_scrub($val, 'UTF-8');
    }

    private function json(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(
            $data,
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
            self::JSON_OPTS
        );
    }
}
