<?php
declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Idiomatic Laravel DB-layer DriveService (apples-to-apples SQL execution parity across all 4 frameworks — FAIR-02).
 */
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

    public function __construct(private readonly string $uploadsDir) {}

    /** @return array{items:list<array<string,mixed>>, breadcrumbs:list<array{id:int,name:string}>, folder:?array<string,mixed>, storage:array<string,mixed>} */
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
            $folderRow = DB::selectOne('SELECT * FROM items WHERE id = ? AND is_folder = 1 AND is_trashed = 0', [$folderId]);
            if ($folderRow === null) {
                throw new HttpException(404, 'フォルダが見つかりません');
            }
            $folder = $this->normalizeRow((array)$folderRow);
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

        $rows = DB::select($sql, $params);
        $items = array_map(fn(object $r): array => $this->normalizeRow((array)$r), $rows);

        return [
            'items' => $items,
            'breadcrumbs' => $breadcrumbs,
            'folder' => $folder,
            'storage' => $this->getStorageSummary(),
        ];
    }

    /** @return list<array{id:int,name:string}> */
    public function getBreadcrumbs(int $folderId): array
    {
        $rows = DB::select(
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
        return array_map(static fn(object $r): array => ['id' => (int)$r->id, 'name' => (string)$r->name], $rows);
    }

    /** @return array{item:array<string,mixed>, activities:list<array<string,mixed>>, breadcrumbs:list<array{id:int,name:string}>} */
    public function getItemDetail(int $id): array
    {
        $item = DB::selectOne('SELECT * FROM items WHERE id = ?', [$id]);
        if ($item === null) {
            throw new HttpException(404, 'アイテムが見つかりません');
        }
        $activities = array_map(static fn(object $a): array => [
            'id' => (int)$a->id,
            'item_id' => (int)$a->item_id,
            'action' => (string)$a->action,
            'actor_name' => (string)$a->actor_name,
            'detail' => (string)$a->detail,
            'created_at' => (string)$a->created_at,
        ], DB::select('SELECT id, item_id, action, actor_name, detail, created_at FROM activities WHERE item_id = ? ORDER BY id DESC LIMIT 20', [$id]));

        $breadcrumbs = $item->parent_id !== null ? $this->getBreadcrumbs((int)$item->parent_id) : [];

        return [
            'item' => $this->normalizeRow((array)$item),
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
        $item = DB::selectOne('SELECT * FROM items WHERE share_token = ? AND is_shared = 1 AND is_trashed = 0', [$token]);
        return $item ? $this->normalizeRow((array)$item) : null;
    }

    /** @return list<array<string, mixed>> */
    public function getSharedChildren(int $folderId): array
    {
        $rows = DB::select(
            'SELECT * FROM items WHERE parent_id = ? AND is_trashed = 0 ORDER BY is_folder DESC, name COLLATE NOCASE ASC LIMIT 100',
            [$folderId]
        );
        return array_map(fn(object $r): array => $this->normalizeRow((array)$r), $rows);
    }

    public function latestUpdatedDate(): string
    {
        $row = DB::selectOne('SELECT MAX(updated_at) AS latest FROM items WHERE is_trashed = 0');
        $val = (string)($row->latest ?? '');
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
            $parent = DB::selectOne('SELECT id FROM items WHERE id = ? AND is_folder = 1 AND is_trashed = 0', [$parentId]);
            if ($parent === null) {
                throw new HttpException(404, '親フォルダが見つかりません');
            }
            if ($this->ancestorDepth($parentId) + 1 >= self::MAX_DEPTH) {
                throw new HttpException(422, 'フォルダの階層上限（32階層）を超えています');
            }
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return DB::transaction(function () use ($name, $parentId, $now): array {
            $id = DB::table('items')->insertGetId([
                'parent_id' => $parentId,
                'name' => $name,
                'is_folder' => 1,
                'mime_type' => 'application/vnd.clouddrive.folder',
                'extension' => '',
                'size_bytes' => 0,
                'storage_name' => null,
                'owner_name' => '山田 太郎',
                'owner_email' => 'taro.yamada@clouddrive.example',
                'description' => '',
                'is_starred' => 0,
                'is_shared' => 0,
                'share_token' => null,
                'is_trashed' => 0,
                'trashed_at' => null,
                'last_opened_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::insert(
                'INSERT INTO activities (item_id, action, actor_name, detail, created_at) VALUES (?, ?, ?, ?, ?)',
                [$id, 'created', '山田 太郎', "フォルダ「{$name}」を作成しました", $now]
            );
            $row = DB::selectOne('SELECT * FROM items WHERE id = ?', [$id]);
            return $this->normalizeRow((array)$row);
        });
    }

    /** @return array<string, mixed> */
    public function uploadFile(mixed $file, ?int $parentId, string $description = ''): array
    {
        if (!$file instanceof UploadedFile) {
            throw new HttpException(422, '単一ファイルとしてアップロードしてください');
        }
        if (!$file->isValid()) {
            $err = $file->getError();
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                throw new HttpException(413, 'ファイルサイズが上限（20MB）を超えています');
            }
            throw new HttpException(422, 'ファイルのアップロードに失敗しました');
        }

        $size = (int)$file->getSize();
        if ($size > self::MAX_UPLOAD_BYTES) {
            throw new HttpException(413, 'ファイルサイズが上限（20MB）を超えています');
        }

        if ($parentId !== null) {
            $parent = DB::selectOne('SELECT id FROM items WHERE id = ? AND is_folder = 1 AND is_trashed = 0', [$parentId]);
            if ($parent === null) {
                throw new HttpException(404, 'アップロード先フォルダが見つかりません');
            }
        }

        $origName = Security::sanitizeItemName($file->getClientOriginalName());
        if ($origName === '') {
            $origName = 'untitled';
        }
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($origName, PATHINFO_EXTENSION))) ?? '';
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = (string)($finfo->file($file->getRealPath()) ?: 'application/octet-stream');
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

        $storageName = 'up-' . bin2hex(random_bytes(16)) . '.blob';
        @mkdir($this->uploadsDir, 0775, true);
        $destPath = $this->uploadsDir . DIRECTORY_SEPARATOR . $storageName;
        $file->move($this->uploadsDir, $storageName);

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $cleanDesc = mb_substr(trim(mb_scrub($description, 'UTF-8')), 0, 1000, 'UTF-8');

        $pdo = DB::connection()->getPdo();
        try {
            $pdo->exec('BEGIN IMMEDIATE');
            $usedRow = DB::selectOne('SELECT COALESCE(SUM(CASE WHEN is_folder = 0 THEN size_bytes ELSE 0 END), 0) AS used_bytes FROM items');
            $used = (int)($usedRow->used_bytes ?? 0);
            if (($used + $size) > self::QUOTA_BYTES) {
                throw new HttpException(413, '保存容量の上限（15GB）を超えています');
            }
            $id = DB::table('items')->insertGetId([
                'parent_id' => $parentId,
                'name' => $origName,
                'is_folder' => 0,
                'mime_type' => $detectedMime,
                'extension' => $ext,
                'size_bytes' => $size,
                'storage_name' => $storageName,
                'owner_name' => '山田 太郎',
                'owner_email' => 'taro.yamada@clouddrive.example',
                'description' => $cleanDesc,
                'is_starred' => 0,
                'is_shared' => 0,
                'share_token' => null,
                'is_trashed' => 0,
                'trashed_at' => null,
                'last_opened_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::insert(
                'INSERT INTO activities (item_id, action, actor_name, detail, created_at) VALUES (?, ?, ?, ?, ?)',
                [$id, 'uploaded', '山田 太郎', "ファイル「{$origName}」をアップロードしました", $now]
            );
            $row = DB::selectOne('SELECT * FROM items WHERE id = ?', [$id]);
            $pdo->exec('COMMIT');
            return $this->normalizeRow((array)$row);
        } catch (\Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (\Throwable) {}
            @unlink($destPath);
            throw $e;
        }
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function updateItem(int $id, array $payload): array
    {
        $itemObj = DB::selectOne('SELECT * FROM items WHERE id = ?', [$id]);
        if ($itemObj === null) {
            throw new HttpException(404, 'アイテムが見つかりません');
        }
        $item = (array)$itemObj;
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
                $target = DB::selectOne('SELECT id FROM items WHERE id = ? AND is_folder = 1 AND is_trashed = 0', [$newParent]);
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
            return $this->normalizeRow($item);
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $updates[] = 'updated_at = ?';
        $params[] = $now;
        $params[] = $id;

        return DB::transaction(function () use ($updates, $params, $activityLogs, $id, $now): array {
            DB::update('UPDATE items SET ' . implode(', ', $updates) . ' WHERE id = ?', $params);
            foreach ($activityLogs as [$action, $detail]) {
                DB::insert(
                    'INSERT INTO activities (item_id, action, actor_name, detail, created_at) VALUES (?, ?, ?, ?, ?)',
                    [$id, $action, '山田 太郎', $detail, $now]
                );
            }
            $row = DB::selectOne('SELECT * FROM items WHERE id = ?', [$id]);
            return $this->normalizeRow((array)$row);
        });
    }

    /** @return array<string, mixed> */
    public function deleteItem(int $id, bool $permanent = false): array
    {
        $item = DB::selectOne('SELECT * FROM items WHERE id = ?', [$id]);
        if ($item === null) {
            throw new HttpException(404, 'アイテムが見つかりません');
        }

        if (!$permanent && (int)$item->is_trashed === 0) {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            DB::transaction(static function () use ($id, $now): void {
                DB::update(
                    'WITH RECURSIVE tree(id) AS (
                        SELECT id FROM items WHERE id = ?
                        UNION
                        SELECT i.id FROM items i JOIN tree t ON i.parent_id = t.id
                    )
                    UPDATE items SET is_trashed = 1, trashed_at = ?, updated_at = ?
                    WHERE id IN (SELECT id FROM tree) AND is_trashed = 0',
                    [$id, $now, $now]
                );
                DB::insert(
                    'INSERT INTO activities (item_id, action, actor_name, detail, created_at) VALUES (?, ?, ?, ?, ?)',
                    [$id, 'trashed', '山田 太郎', 'ゴミ箱へ移動しました', $now]
                );
            });
            return ['deleted' => true, 'permanent' => false, 'id' => $id];
        }

        $pdo = DB::connection()->getPdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $storageFiles = DB::select(
                'WITH RECURSIVE tree(id, storage_name) AS (
                    SELECT id, storage_name FROM items WHERE id = ?
                    UNION
                    SELECT i.id, i.storage_name FROM items i JOIN tree t ON i.parent_id = t.id
                )
                SELECT storage_name FROM tree WHERE storage_name IS NOT NULL',
                [$id]
            );
            DB::delete('DELETE FROM items WHERE id = ?', [$id]);
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (\Throwable) {}
            throw $e;
        }

        foreach ($storageFiles as $f) {
            $path = Security::resolveJailedPath($this->uploadsDir, (string)$f->storage_name);
            if ($path !== null) {
                @unlink($path);
            }
        }
        return ['deleted' => true, 'permanent' => true, 'id' => $id];
    }

    /** @return array<string, mixed> */
    public function restoreItem(int $id): array
    {
        $item = DB::selectOne('SELECT * FROM items WHERE id = ? AND is_trashed = 1', [$id]);
        if ($item === null) {
            throw new HttpException(404, 'ゴミ箱内にアイテムが見つかりません');
        }
        $parentId = $item->parent_id !== null ? (int)$item->parent_id : null;
        if ($parentId !== null && !$this->isAncestorChainActive($parentId)) {
            $parentId = null;
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $trashedAt = $item->trashed_at;

        return DB::transaction(function () use ($id, $parentId, $now, $trashedAt): array {
            DB::update(
                'UPDATE items SET is_trashed = 0, trashed_at = NULL, parent_id = ?, updated_at = ? WHERE id = ?',
                [$parentId, $now, $id]
            );
            if ($trashedAt !== null) {
                DB::update(
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
            DB::insert(
                'INSERT INTO activities (item_id, action, actor_name, detail, created_at) VALUES (?, ?, ?, ?, ?)',
                [$id, 'restored', '山田 太郎', 'ゴミ箱から復元しました', $now]
            );
            $row = DB::selectOne('SELECT * FROM items WHERE id = ?', [$id]);
            return $this->normalizeRow((array)$row);
        });
    }

    /** @return array{emptied:int} */
    public function emptyTrash(): array
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $countRow = DB::selectOne('SELECT COUNT(*) AS cnt FROM items WHERE is_trashed = 1');
            $count = (int)($countRow->cnt ?? 0);
            $files = DB::select(
                'WITH RECURSIVE tree(id, storage_name) AS (
                    SELECT id, storage_name FROM items WHERE is_trashed = 1
                    UNION
                    SELECT i.id, i.storage_name FROM items i JOIN tree t ON i.parent_id = t.id
                )
                SELECT storage_name FROM tree WHERE storage_name IS NOT NULL'
            );
            DB::delete('DELETE FROM items WHERE is_trashed = 1');
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (\Throwable) {}
            throw $e;
        }

        foreach ($files as $f) {
            $path = Security::resolveJailedPath($this->uploadsDir, (string)$f->storage_name);
            if ($path !== null) {
                @unlink($path);
            }
        }
        return ['emptied' => $count];
    }

    /** @return array{item:array<string,mixed>, path:string} */
    public function resolveFileForTransfer(int $id, bool $touchOpened = true): array
    {
        $item = DB::selectOne('SELECT * FROM items WHERE id = ? AND is_folder = 0 AND is_trashed = 0', [$id]);
        if ($item === null || empty($item->storage_name)) {
            throw new HttpException(404, 'ファイルが見つかりません');
        }
        $path = Security::resolveJailedPath($this->uploadsDir, (string)$item->storage_name);
        if ($path === null) {
            throw new HttpException(404, 'ストレージ上にファイルが存在しません');
        }
        if ($touchOpened) {
            DB::update('UPDATE items SET last_opened_at = ? WHERE id = ?', [gmdate('Y-m-d\TH:i:s\Z'), $id]);
        }
        return ['item' => $this->normalizeRow((array)$item), 'path' => $path];
    }

    /** @return array<string, mixed> */
    public function getStorageSummary(): array
    {
        $row = DB::selectOne(
            "SELECT
                COALESCE(SUM(CASE WHEN is_folder = 0 THEN size_bytes ELSE 0 END), 0) AS used_bytes,
                COALESCE(SUM(CASE WHEN is_folder = 0 AND is_trashed = 1 THEN size_bytes ELSE 0 END), 0) AS trash_bytes,
                COALESCE(SUM(CASE WHEN is_folder = 0 AND is_trashed = 0 AND mime_type LIKE 'image/%' THEN size_bytes ELSE 0 END), 0) AS image_bytes,
                COALESCE(SUM(CASE WHEN is_folder = 0 AND is_trashed = 0 AND extension IN ('md','txt','doc','docx','json') THEN size_bytes ELSE 0 END), 0) AS doc_bytes,
                COALESCE(SUM(CASE WHEN is_folder = 0 AND is_trashed = 0 AND (extension = 'pdf' OR mime_type = 'application/pdf') THEN size_bytes ELSE 0 END), 0) AS pdf_bytes,
                COUNT(CASE WHEN is_trashed = 0 THEN 1 END) AS active_count,
                COUNT(CASE WHEN is_trashed = 1 THEN 1 END) AS trash_count
             FROM items"
        );
        $used = (int)($row->used_bytes ?? 0);
        return [
            'used_bytes' => $used,
            'quota_bytes' => self::QUOTA_BYTES,
            'percent' => round(($used / self::QUOTA_BYTES) * 100, 4),
            'active_count' => (int)($row->active_count ?? 0),
            'trash_count' => (int)($row->trash_count ?? 0),
            'breakdown' => [
                'images' => (int)($row->image_bytes ?? 0),
                'documents' => (int)($row->doc_bytes ?? 0),
                'pdf' => (int)($row->pdf_bytes ?? 0),
                'trash' => (int)($row->trash_bytes ?? 0),
            ],
        ];
    }

    public function totalItemCount(): int
    {
        $row = DB::selectOne('SELECT COUNT(*) AS cnt FROM items');
        return (int)($row->cnt ?? 0);
    }

    private function isDescendant(int $candidateParentId, int $movingFolderId): bool
    {
        $found = DB::selectOne(
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
        $row = DB::selectOne(
            'WITH RECURSIVE anc(id, parent_id, depth) AS (
                SELECT id, parent_id, 0 FROM items WHERE id = ?
                UNION
                SELECT i.id, i.parent_id, a.depth + 1 FROM items i JOIN anc a ON i.id = a.parent_id
                WHERE a.depth < 64
            )
            SELECT COALESCE(MAX(depth), 0) AS d FROM anc',
            [$folderId]
        );
        return (int)($row->d ?? 0);
    }

    private function subtreeHeight(int $folderId): int
    {
        $row = DB::selectOne(
            'WITH RECURSIVE sub(id, depth) AS (
                SELECT id, 0 FROM items WHERE id = ?
                UNION
                SELECT i.id, s.depth + 1 FROM items i JOIN sub s ON i.parent_id = s.id
                WHERE i.is_folder = 1 AND s.depth < 64
            )
            SELECT COALESCE(MAX(depth), 0) AS h FROM sub',
            [$folderId]
        );
        return (int)($row->h ?? 0);
    }

    private function isAncestorChainActive(int $folderId): bool
    {
        $rows = DB::select(
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
            if ((int)$r->is_folder !== 1 || (int)$r->is_trashed !== 0) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizeRow(array $row): array
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
