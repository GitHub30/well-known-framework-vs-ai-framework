<?php
declare(strict_types=1);

namespace App\Services;

use App\Services\Database;
use App\Services\HttpException;
use App\Services\Security;

final class DriveService
{
    public const QUOTA_BYTES = 16106127360; // 15 GB
    public const MAX_UPLOAD_BYTES = 20971520; // 20 MB
    public const MAX_DEPTH = 32;

    private const ALLOWED_SORT = [
        'name' => 'name COLLATE NOCASE',
        'updated_at' => 'updated_at',
        'size' => 'size_bytes',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly string $uploadsDir,
    ) {}

    /**
     * @return array{items:list<array<string,mixed>>, breadcrumbs:list<array{id:int,name:string}>, folder:?array<string,mixed>, storage:array<string,mixed>}
     */
    public function listItems(
        string $view = 'my-drive',
        ?int $folderId = null,
        string $q = '',
        string $type = 'all',
        string $sort = 'name',
        string $dir = 'asc',
    ): array {
        $folder = null;
        $breadcrumbs = [];

        if ($folderId !== null) {
            $folder = $this->db->fetchOne('SELECT * FROM items WHERE id = ? AND is_folder = 1 AND is_trashed = 0', [$folderId]);
            if ($folder === null) {
                throw new HttpException(404, 'フォルダが見つかりません');
            }
            $breadcrumbs = $this->getBreadcrumbs($folderId);
        }

        $where = [];
        $params = [];

        $q = trim(mb_scrub($q, 'UTF-8'));
        if ($q !== '') {
            $where[] = $view === 'trash' ? 'is_trashed = 1' : 'is_trashed = 0';
            if ($view === 'starred') {
                $where[] = 'is_starred = 1';
            } elseif ($view === 'shared') {
                $where[] = 'is_shared = 1';
            } elseif ($view === 'recent') {
                $where[] = 'is_folder = 0';
            }
            $where[] = "(name LIKE ? ESCAPE '\\' OR description LIKE ? ESCAPE '\\')";
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
            $like = '%' . $escaped . '%';
            $params[] = $like;
            $params[] = $like;
            if ($folderId !== null) {
                $where[] = 'parent_id = ?';
                $params[] = $folderId;
            }
        } else {
            switch ($view) {
                case 'folders':
                    $where[] = 'is_trashed = 0';
                    $where[] = 'is_folder = 1';
                    break;
                case 'recent':
                    $where[] = 'is_trashed = 0';
                    $where[] = 'is_folder = 0';
                    break;
                case 'starred':
                    $where[] = 'is_trashed = 0';
                    $where[] = 'is_starred = 1';
                    break;
                case 'shared':
                    $where[] = 'is_trashed = 0';
                    $where[] = 'is_shared = 1';
                    break;
                case 'trash':
                    $where[] = 'is_trashed = 1';
                    break;
                case 'my-drive':
                default:
                    $where[] = 'is_trashed = 0';
                    if ($folderId === null) {
                        $where[] = 'parent_id IS NULL';
                    } else {
                        $where[] = 'parent_id = ?';
                        $params[] = $folderId;
                    }
                    break;
            }
        }

        switch ($type) {
            case 'folder':
                $where[] = 'is_folder = 1';
                break;
            case 'document':
                $where[] = "is_folder = 0 AND extension IN ('md','txt','doc','docx','json')";
                break;
            case 'spreadsheet':
                $where[] = "is_folder = 0 AND extension IN ('csv','xls','xlsx')";
                break;
            case 'pdf':
                $where[] = "is_folder = 0 AND (extension = 'pdf' OR mime_type = 'application/pdf')";
                break;
            case 'image':
                $where[] = "is_folder = 0 AND (mime_type LIKE 'image/%' OR extension IN ('png','jpg','jpeg','webp','gif'))";
                break;
            default:
                break;
        }

        $sortCol = self::ALLOWED_SORT[$sort] ?? self::ALLOWED_SORT['name'];
        $sortDir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        $orderBy = ($view === 'recent' && !isset(self::ALLOWED_SORT[$sort]))
            ? "last_opened_at {$sortDir}, updated_at {$sortDir}"
            : ($view === 'recent' && $sort === 'updated_at'
                ? "last_opened_at {$sortDir}, updated_at {$sortDir}"
                : "is_folder DESC, {$sortCol} {$sortDir}, id ASC");

        $sql = 'SELECT id, parent_id, name, is_folder, mime_type, extension, size_bytes, owner_name, owner_email, description, is_starred, is_shared, share_token, is_trashed, trashed_at, last_opened_at, created_at, updated_at FROM items WHERE '
            . implode(' AND ', $where)
            . " ORDER BY {$orderBy} LIMIT 200";

        $items = $this->db->fetchAll($sql, $params);

        return [
            'items' => array_map([$this, 'normalizeItem'], $items),
            'breadcrumbs' => $breadcrumbs,
            'folder' => $folder ? $this->normalizeItem($folder) : null,
            'storage' => $this->getStorageSummary(),
        ];
    }

    /** @return list<array{id:int,name:string}> */
    public function getBreadcrumbs(int $folderId): array
    {
        $rows = $this->db->fetchAll(
            'WITH RECURSIVE anc(id, parent_id, name, depth) AS (
                SELECT id, parent_id, name, 0 FROM items WHERE id = ? AND is_folder = 1
                UNION
                SELECT i.id, i.parent_id, i.name, a.depth + 1
                FROM items i JOIN anc a ON i.id = a.parent_id
                WHERE a.depth < 32
            )
            SELECT id, name FROM anc ORDER BY depth DESC',
            [$folderId]
        );
        return array_map(static fn(array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name']], $rows);
    }

    /** @return array{item:array<string,mixed>, activities:list<array<string,mixed>>, breadcrumbs:list<array{id:int,name:string}>} */
    public function getItemDetail(int $id): array
    {
        $item = $this->db->fetchOne('SELECT * FROM items WHERE id = ?', [$id]);
        if ($item === null) {
            throw new HttpException(404, 'アイテムが見つかりません');
        }
        $activities = $this->db->fetchAll(
            'SELECT id, item_id, action, actor_name, detail, created_at FROM activities WHERE item_id = ? ORDER BY id DESC LIMIT 20',
            [$id]
        );
        $breadcrumbs = $item['parent_id'] !== null ? $this->getBreadcrumbs((int)$item['parent_id']) : [];

        return [
            'item' => $this->normalizeItem($item),
            'activities' => $activities,
            'breadcrumbs' => $breadcrumbs,
        ];
    }

    /** @return array<string, mixed>|null */
    public function getSharedByToken(string $token): ?array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{4,64}$/', $token)) {
            return null;
        }
        $item = $this->db->fetchOne(
            'SELECT * FROM items WHERE share_token = ? AND is_shared = 1 AND is_trashed = 0',
            [$token]
        );
        return $item ? $this->normalizeItem($item) : null;
    }

    /** @return list<array<string, mixed>> */
    public function getSharedChildren(int $folderId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM items WHERE parent_id = ? AND is_trashed = 0 ORDER BY is_folder DESC, name COLLATE NOCASE ASC LIMIT 100',
            [$folderId]
        );
        return array_map([$this, 'normalizeItem'], $rows);
    }

    public function latestUpdatedDate(): string
    {
        $val = (string)($this->db->fetchValue('SELECT MAX(updated_at) FROM items WHERE is_trashed = 0') ?? '');
        return strlen($val) >= 10 ? substr($val, 0, 10) : gmdate('Y-m-d');
    }

    /** @return array<string, mixed> */
    public function createFolder(string $rawName, ?int $parentId): array
    {
        $name = Security::sanitizeItemName($rawName);
        if ($name === '') {
            throw new HttpException(422, '有効なフォルダ名を入力してください');
        }
        if ($parentId !== null) {
            $parent = $this->db->fetchOne('SELECT id FROM items WHERE id = ? AND is_folder = 1 AND is_trashed = 0', [$parentId]);
            if ($parent === null) {
                throw new HttpException(404, '親フォルダが見つかりません');
            }
            if ($this->ancestorDepth($parentId) + 1 >= self::MAX_DEPTH) {
                throw new HttpException(422, 'フォルダの階層上限（32階層）を超えています');
            }
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return $this->db->transaction(function (Database $db) use ($name, $parentId, $now): array {
            $db->execute(
                'INSERT INTO items (parent_id, name, is_folder, mime_type, extension, size_bytes, storage_name, owner_name, owner_email, description, is_starred, is_shared, share_token, is_trashed, trashed_at, last_opened_at, created_at, updated_at)
                 VALUES (?, ?, 1, ?, ?, 0, NULL, ?, ?, ?, 0, 0, NULL, 0, NULL, ?, ?, ?)',
                [$parentId, $name, 'application/vnd.clouddrive.folder', '', '山田 太郎', 'taro.yamada@clouddrive.example', '', $now, $now, $now]
            );
            $id = $db->lastInsertId();
            $db->execute(
                'INSERT INTO activities (item_id, action, actor_name, detail, created_at) VALUES (?, ?, ?, ?, ?)',
                [$id, 'created', '山田 太郎', "フォルダ「{$name}」を作成しました", $now]
            );
            $row = $db->fetchOne('SELECT * FROM items WHERE id = ?', [$id]);
            return $this->normalizeItem($row ?? []);
        });
    }

    /** @param array<string, mixed> $file @return array<string, mixed> */
    public function uploadFile(array $file, ?int $parentId, string $description = ''): array
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            throw new HttpException(422, '単一ファイルとしてアップロードしてください');
        }
        $error = (int)$file['error'];
        if ($error !== UPLOAD_ERR_OK) {
            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                throw new HttpException(413, 'ファイルサイズが上限（20MB）を超えています');
            }
            throw new HttpException(422, 'ファイルのアップロードに失敗しました');
        }

        $tmpPath = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new HttpException(422, '有効なアップロードファイルが見つかりません');
        }

        $size = (int)filesize($tmpPath);
        if ($size > self::MAX_UPLOAD_BYTES) {
            throw new HttpException(413, 'ファイルサイズが上限（20MB）を超えています');
        }

        if ($parentId !== null) {
            $parent = $this->db->fetchOne('SELECT id FROM items WHERE id = ? AND is_folder = 1 AND is_trashed = 0', [$parentId]);
            if ($parent === null) {
                throw new HttpException(404, 'アップロード先フォルダが見つかりません');
            }
        }

        $origName = Security::sanitizeItemName(is_string($file['name'] ?? null) ? $file['name'] : 'untitled');
        if ($origName === '') {
            $origName = 'untitled';
        }
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($origName, PATHINFO_EXTENSION))) ?? '';

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = (string)($finfo->file($tmpPath) ?: 'application/octet-stream');
        if ($detectedMime === 'application/x-empty' || $detectedMime === 'text/plain') {
            $detectedMime = match ($ext) {
                'md' => 'text/markdown',
                'csv' => 'text/csv',
                'json' => 'application/json',
                'txt' => 'text/plain',
                default => $detectedMime === 'application/x-empty' ? 'text/plain' : $detectedMime,
            };
        } elseif ($ext === 'csv' && $detectedMime === 'application/csv') {
            $detectedMime = 'text/csv';
        }

        // Always use inert .blob extension on disk (defense-in-depth against executable uploads)
        $storageName = 'up-' . bin2hex(random_bytes(16)) . '.blob';
        @mkdir($this->uploadsDir, 0775, true);
        $destPath = $this->uploadsDir . DIRECTORY_SEPARATOR . $storageName;

        if (!move_uploaded_file($tmpPath, $destPath)) {
            throw new HttpException(500, 'ファイルの保存に失敗しました');
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $cleanDesc = mb_substr(trim(mb_scrub($description, 'UTF-8')), 0, 1000, 'UTF-8');

        try {
            return $this->db->transaction(function (Database $db) use ($parentId, $origName, $detectedMime, $ext, $size, $storageName, $cleanDesc, $now): array {
                $used = (int)($db->fetchValue('SELECT COALESCE(SUM(CASE WHEN is_folder = 0 THEN size_bytes ELSE 0 END), 0) FROM items') ?? 0);
                if (($used + $size) > self::QUOTA_BYTES) {
                    throw new HttpException(413, '保存容量の上限（15GB）を超えています');
                }
                $db->execute(
                    'INSERT INTO items (parent_id, name, is_folder, mime_type, extension, size_bytes, storage_name, owner_name, owner_email, description, is_starred, is_shared, share_token, is_trashed, trashed_at, last_opened_at, created_at, updated_at)
                     VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?, ?, 0, 0, NULL, 0, NULL, ?, ?, ?)',
                    [$parentId, $origName, $detectedMime, $ext, $size, $storageName, '山田 太郎', 'taro.yamada@clouddrive.example', $cleanDesc, $now, $now, $now]
                );
                $id = $db->lastInsertId();
                $db->execute(
                    'INSERT INTO activities (item_id, action, actor_name, detail, created_at) VALUES (?, ?, ?, ?, ?)',
                    [$id, 'uploaded', '山田 太郎', "ファイル「{$origName}」をアップロードしました", $now]
                );
                $row = $db->fetchOne('SELECT * FROM items WHERE id = ?', [$id]);
                return $this->normalizeItem($row ?? []);
            }, true);
        } catch (\Throwable $e) {
            @unlink($destPath);
            throw $e;
        }
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function updateItem(int $id, array $payload): array
    {
        $item = $this->db->fetchOne('SELECT * FROM items WHERE id = ?', [$id]);
        if ($item === null) {
            throw new HttpException(404, 'アイテムが見つかりません');
        }
        if ((int)$item['is_trashed'] === 1) {
            throw new HttpException(422, 'ゴミ箱内のアイテムは編集できません。先に復元してください');
        }

        $updates = [];
        $params = [];
        $activityLogs = [];

        if (array_key_exists('name', $payload)) {
            $rawName = Security::requireString($payload['name'], 'name');
            $newName = Security::sanitizeItemName((string)$rawName);
            if ($newName === '') {
                throw new HttpException(422, '有効な名前を入力してください');
            }
            $updates[] = 'name = ?';
            $params[] = $newName;
            if ((int)$item['is_folder'] === 0) {
                $newExt = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($newName, PATHINFO_EXTENSION))) ?? '';
                $updates[] = 'extension = ?';
                $params[] = $newExt;
            }
            $activityLogs[] = ['renamed', "名前を「{$newName}」に変更しました"];
        }

        if (array_key_exists('parent_id', $payload)) {
            $newParent = Security::parseOptionalPositiveInt($payload['parent_id'], 'parent_id', 422);
            if ($newParent !== null) {
                if ($newParent === $id) {
                    throw new HttpException(422, '自分自身の配下には移動できません');
                }
                $target = $this->db->fetchOne('SELECT id FROM items WHERE id = ? AND is_folder = 1 AND is_trashed = 0', [$newParent]);
                if ($target === null) {
                    throw new HttpException(404, '移動先フォルダが見つかりません');
                }
                if ((int)$item['is_folder'] === 1) {
                    if ($this->isDescendant($newParent, $id)) {
                        throw new HttpException(422, '子孫フォルダへ循環移動することはできません');
                    }
                    $newTotalDepth = $this->ancestorDepth($newParent) + 1 + $this->subtreeHeight($id);
                    if ($newTotalDepth >= self::MAX_DEPTH) {
                        throw new HttpException(422, '移動先のフォルダ階層が上限（32階層）を超えています');
                    }
                }
            }
            $updates[] = 'parent_id = ?';
            $params[] = $newParent;
            $activityLogs[] = ['moved', '保存場所を移動しました'];
        }

        if (array_key_exists('is_starred', $payload)) {
            $starred = filter_var($payload['is_starred'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            $updates[] = 'is_starred = ?';
            $params[] = $starred;
            $activityLogs[] = ['starred', $starred ? 'スターを付けました' : 'スターを外しました'];
        }

        if (array_key_exists('is_shared', $payload)) {
            $shared = filter_var($payload['is_shared'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            $updates[] = 'is_shared = ?';
            $params[] = $shared;
            if ($shared === 1) {
                $updates[] = 'share_token = ?';
                $params[] = !empty($item['share_token']) ? (string)$item['share_token'] : bin2hex(random_bytes(12));
            } else {
                // Rotate/revoke share token on unshare (SEC-10)
                $updates[] = 'share_token = NULL';
            }
            $activityLogs[] = ['shared', $shared ? '共有リンクを有効にしました' : '共有を停止しました'];
        }

        if (array_key_exists('description', $payload)) {
            $rawDesc = Security::requireString($payload['description'], 'description');
            $desc = mb_substr(trim((string)$rawDesc), 0, 1000, 'UTF-8');
            $updates[] = 'description = ?';
            $params[] = $desc;
            $activityLogs[] = ['edited', '説明を更新しました'];
        }

        if ($updates === []) {
            return $this->normalizeItem($item);
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $updates[] = 'updated_at = ?';
        $params[] = $now;
        $params[] = $id;

        return $this->db->transaction(function (Database $db) use ($updates, $params, $activityLogs, $id, $now): array {
            $db->execute('UPDATE items SET ' . implode(', ', $updates) . ' WHERE id = ?', $params);
            foreach ($activityLogs as [$action, $detail]) {
                $db->execute(
                    'INSERT INTO activities (item_id, action, actor_name, detail, created_at) VALUES (?, ?, ?, ?, ?)',
                    [$id, $action, '山田 太郎', $detail, $now]
                );
            }
            $row = $db->fetchOne('SELECT * FROM items WHERE id = ?', [$id]);
            return $this->normalizeItem($row ?? []);
        });
    }

    /** @return array<string, mixed> */
    public function deleteItem(int $id, bool $permanent = false): array
    {
        $item = $this->db->fetchOne('SELECT * FROM items WHERE id = ?', [$id]);
        if ($item === null) {
            throw new HttpException(404, 'アイテムが見つかりません');
        }

        if (!$permanent && (int)$item['is_trashed'] === 0) {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $this->db->transaction(function (Database $db) use ($id, $now): void {
                // Recursively soft-delete item and all active descendants (SEC-07)
                $db->execute(
                    'WITH RECURSIVE tree(id) AS (
                        SELECT id FROM items WHERE id = ?
                        UNION
                        SELECT i.id FROM items i JOIN tree t ON i.parent_id = t.id
                    )
                    UPDATE items SET is_trashed = 1, trashed_at = ?, updated_at = ?
                    WHERE id IN (SELECT id FROM tree) AND is_trashed = 0',
                    [$id, $now, $now]
                );
                $db->execute(
                    'INSERT INTO activities (item_id, action, actor_name, detail, created_at) VALUES (?, ?, ?, ?, ?)',
                    [$id, 'trashed', '山田 太郎', 'ゴミ箱へ移動しました', $now]
                );
            });
            return ['deleted' => true, 'permanent' => false, 'id' => $id];
        }

        // Atomic permanent delete with cycle-safe UNION CTE (SEC-02, SEC-13)
        $storageFiles = $this->db->transaction(function (Database $db) use ($id): array {
            $files = $db->fetchAll(
                'WITH RECURSIVE tree(id, storage_name) AS (
                    SELECT id, storage_name FROM items WHERE id = ?
                    UNION
                    SELECT i.id, i.storage_name FROM items i JOIN tree t ON i.parent_id = t.id
                )
                SELECT storage_name FROM tree WHERE storage_name IS NOT NULL',
                [$id]
            );
            $db->execute('DELETE FROM items WHERE id = ?', [$id]);
            return $files;
        }, true);

        foreach ($storageFiles as $f) {
            $path = Security::resolveJailedPath($this->uploadsDir, (string)$f['storage_name']);
            if ($path !== null) {
                @unlink($path);
            }
        }
        return ['deleted' => true, 'permanent' => true, 'id' => $id];
    }

    /** @return array<string, mixed> */
    public function restoreItem(int $id): array
    {
        $item = $this->db->fetchOne('SELECT * FROM items WHERE id = ? AND is_trashed = 1', [$id]);
        if ($item === null) {
            throw new HttpException(404, 'ゴミ箱内にアイテムが見つかりません');
        }

        $parentId = $item['parent_id'] !== null ? (int)$item['parent_id'] : null;
        if ($parentId !== null && !$this->isAncestorChainActive($parentId)) {
            $parentId = null;
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $trashedAt = $item['trashed_at'];

        return $this->db->transaction(function (Database $db) use ($id, $parentId, $now, $trashedAt): array {
            $db->execute(
                'UPDATE items SET is_trashed = 0, trashed_at = NULL, parent_id = ?, updated_at = ? WHERE id = ?',
                [$parentId, $now, $id]
            );
            // Also restore descendants trashed together with this folder
            if ($trashedAt !== null) {
                $db->execute(
                    'WITH RECURSIVE tree(id) AS (
                        SELECT id FROM items WHERE parent_id = ?
                        UNION
                        SELECT i.id FROM items i JOIN tree t ON i.parent_id = t.id
                    )
                    UPDATE items SET is_trashed = 0, trashed_at = NULL, updated_at = ?
                    WHERE id IN (SELECT id FROM tree) AND is_trashed = 1 AND trashed_at = ?',
                    [$id, $now, $trashedAt]
                );
            }
            $db->execute(
                'INSERT INTO activities (item_id, action, actor_name, detail, created_at) VALUES (?, ?, ?, ?, ?)',
                [$id, 'restored', '山田 太郎', 'ゴミ箱から復元しました', $now]
            );
            $row = $db->fetchOne('SELECT * FROM items WHERE id = ?', [$id]);
            return $this->normalizeItem($row ?? []);
        });
    }

    /** @return array{emptied:int} */
    public function emptyTrash(): array
    {
        // Single atomic transaction avoids cascading 404 crashes (SEC-07)
        $result = $this->db->transaction(function (Database $db): array {
            $count = (int)($db->fetchValue('SELECT COUNT(*) FROM items WHERE is_trashed = 1') ?? 0);
            $files = $db->fetchAll(
                'WITH RECURSIVE tree(id, storage_name) AS (
                    SELECT id, storage_name FROM items WHERE is_trashed = 1
                    UNION
                    SELECT i.id, i.storage_name FROM items i JOIN tree t ON i.parent_id = t.id
                )
                SELECT storage_name FROM tree WHERE storage_name IS NOT NULL'
            );
            $db->execute('DELETE FROM items WHERE is_trashed = 1');
            return ['count' => $count, 'files' => $files];
        }, true);

        foreach ($result['files'] as $f) {
            $path = Security::resolveJailedPath($this->uploadsDir, (string)$f['storage_name']);
            if ($path !== null) {
                @unlink($path);
            }
        }
        return ['emptied' => $result['count']];
    }

    /** @return array{item:array<string,mixed>, path:string} */
    public function resolveFileForTransfer(int $id, bool $touchOpened = true): array
    {
        $item = $this->db->fetchOne('SELECT * FROM items WHERE id = ? AND is_folder = 0 AND is_trashed = 0', [$id]);
        if ($item === null || empty($item['storage_name'])) {
            throw new HttpException(404, 'ファイルが見つかりません');
        }
        $path = Security::resolveJailedPath($this->uploadsDir, (string)$item['storage_name']);
        if ($path === null) {
            throw new HttpException(404, 'ストレージ上にファイルが存在しません');
        }
        if ($touchOpened) {
            $this->db->execute('UPDATE items SET last_opened_at = ? WHERE id = ?', [gmdate('Y-m-d\TH:i:s\Z'), $id]);
        }
        return ['item' => $this->normalizeItem($item), 'path' => $path];
    }

    /** @return array<string, mixed> */
    public function getStorageSummary(): array
    {
        $row = $this->db->fetchOne(
            "SELECT
                COALESCE(SUM(CASE WHEN is_folder = 0 THEN size_bytes ELSE 0 END), 0) AS used_bytes,
                COALESCE(SUM(CASE WHEN is_folder = 0 AND is_trashed = 1 THEN size_bytes ELSE 0 END), 0) AS trash_bytes,
                COALESCE(SUM(CASE WHEN is_folder = 0 AND is_trashed = 0 AND mime_type LIKE 'image/%' THEN size_bytes ELSE 0 END), 0) AS image_bytes,
                COALESCE(SUM(CASE WHEN is_folder = 0 AND is_trashed = 0 AND extension IN ('md','txt','doc','docx','json') THEN size_bytes ELSE 0 END), 0) AS doc_bytes,
                COALESCE(SUM(CASE WHEN is_folder = 0 AND is_trashed = 0 AND (extension = 'pdf' OR mime_type = 'application/pdf') THEN size_bytes ELSE 0 END), 0) AS pdf_bytes,
                COUNT(CASE WHEN is_trashed = 0 THEN 1 END) AS active_count,
                COUNT(CASE WHEN is_trashed = 1 THEN 1 END) AS trash_count
             FROM items"
        ) ?? [];

        $used = (int)($row['used_bytes'] ?? 0);
        return [
            'used_bytes' => $used,
            'quota_bytes' => self::QUOTA_BYTES,
            'percent' => round(($used / self::QUOTA_BYTES) * 100, 4),
            'active_count' => (int)($row['active_count'] ?? 0),
            'trash_count' => (int)($row['trash_count'] ?? 0),
            'breakdown' => [
                'images' => (int)($row['image_bytes'] ?? 0),
                'documents' => (int)($row['doc_bytes'] ?? 0),
                'pdf' => (int)($row['pdf_bytes'] ?? 0),
                'trash' => (int)($row['trash_bytes'] ?? 0),
            ],
        ];
    }

    public function totalItemCount(): int
    {
        return (int)$this->db->fetchValue('SELECT COUNT(*) FROM items');
    }

    private function isDescendant(int $candidateParentId, int $movingFolderId): bool
    {
        $found = $this->db->fetchOne(
            'WITH RECURSIVE anc(id, parent_id) AS (
                SELECT id, parent_id FROM items WHERE id = ?
                UNION
                SELECT i.id, i.parent_id FROM items i JOIN anc a ON i.id = a.parent_id
            )
            SELECT id FROM anc WHERE id = ? LIMIT 1',
            [$candidateParentId, $movingFolderId]
        );
        return $found !== null;
    }

    private function ancestorDepth(int $folderId): int
    {
        return (int)($this->db->fetchValue(
            'WITH RECURSIVE anc(id, parent_id, depth) AS (
                SELECT id, parent_id, 0 FROM items WHERE id = ?
                UNION
                SELECT i.id, i.parent_id, a.depth + 1 FROM items i JOIN anc a ON i.id = a.parent_id
                WHERE a.depth < 64
            )
            SELECT COALESCE(MAX(depth), 0) FROM anc',
            [$folderId]
        ) ?? 0);
    }

    private function subtreeHeight(int $folderId): int
    {
        return (int)($this->db->fetchValue(
            'WITH RECURSIVE sub(id, depth) AS (
                SELECT id, 0 FROM items WHERE id = ?
                UNION
                SELECT i.id, s.depth + 1 FROM items i JOIN sub s ON i.parent_id = s.id
                WHERE i.is_folder = 1 AND s.depth < 64
            )
            SELECT COALESCE(MAX(depth), 0) FROM sub',
            [$folderId]
        ) ?? 0);
    }

    private function isAncestorChainActive(int $folderId): bool
    {
        $rows = $this->db->fetchAll(
            'WITH RECURSIVE anc(id, parent_id, is_folder, is_trashed, depth) AS (
                SELECT id, parent_id, is_folder, is_trashed, 0 FROM items WHERE id = ?
                UNION
                SELECT i.id, i.parent_id, i.is_folder, i.is_trashed, a.depth + 1
                FROM items i JOIN anc a ON i.id = a.parent_id
                WHERE a.depth < 64
            )
            SELECT is_folder, is_trashed FROM anc',
            [$folderId]
        );
        if ($rows === []) {
            return false;
        }
        foreach ($rows as $r) {
            if ((int)$r['is_folder'] !== 1 || (int)$r['is_trashed'] !== 0) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizeItem(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'parent_id' => $row['parent_id'] !== null ? (int)$row['parent_id'] : null,
            'name' => (string)$row['name'],
            'is_folder' => (int)$row['is_folder'],
            'mime_type' => (string)$row['mime_type'],
            'extension' => (string)$row['extension'],
            'size_bytes' => (int)$row['size_bytes'],
            'owner_name' => (string)$row['owner_name'],
            'owner_email' => (string)$row['owner_email'],
            'description' => (string)$row['description'],
            'is_starred' => (int)$row['is_starred'],
            'is_shared' => (int)$row['is_shared'],
            'share_token' => $row['share_token'] !== null ? (string)$row['share_token'] : null,
            'is_trashed' => (int)$row['is_trashed'],
            'trashed_at' => $row['trashed_at'] !== null ? (string)$row['trashed_at'] : null,
            'last_opened_at' => $row['last_opened_at'] !== null ? (string)$row['last_opened_at'] : null,
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)$row['updated_at'],
        ];
    }
}
