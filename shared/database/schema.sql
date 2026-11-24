PRAGMA journal_mode = WAL;
PRAGMA synchronous = NORMAL;
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id INTEGER NULL REFERENCES items(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    is_folder INTEGER NOT NULL DEFAULT 0 CHECK (is_folder IN (0, 1)),
    mime_type TEXT NOT NULL DEFAULT 'application/octet-stream',
    extension TEXT NOT NULL DEFAULT '',
    size_bytes INTEGER NOT NULL DEFAULT 0 CHECK (size_bytes >= 0),
    storage_name TEXT NULL,
    owner_name TEXT NOT NULL DEFAULT '山田 太郎',
    owner_email TEXT NOT NULL DEFAULT 'taro.yamada@clouddrive.example',
    description TEXT NOT NULL DEFAULT '',
    is_starred INTEGER NOT NULL DEFAULT 0 CHECK (is_starred IN (0, 1)),
    is_shared INTEGER NOT NULL DEFAULT 0 CHECK (is_shared IN (0, 1)),
    share_token TEXT NULL UNIQUE,
    is_trashed INTEGER NOT NULL DEFAULT 0 CHECK (is_trashed IN (0, 1)),
    trashed_at TEXT NULL,
    last_opened_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_items_parent_trashed ON items(parent_id, is_trashed, is_folder DESC, name ASC);
CREATE INDEX IF NOT EXISTS idx_items_trashed ON items(is_trashed, updated_at DESC);
CREATE INDEX IF NOT EXISTS idx_items_starred ON items(is_starred, is_trashed);
CREATE INDEX IF NOT EXISTS idx_items_shared ON items(is_shared, is_trashed);
CREATE INDEX IF NOT EXISTS idx_items_recent ON items(is_trashed, last_opened_at DESC);
CREATE INDEX IF NOT EXISTS idx_items_name ON items(name COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS idx_items_share_token ON items(share_token);

CREATE TABLE IF NOT EXISTS activities (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
    action TEXT NOT NULL,
    actor_name TEXT NOT NULL DEFAULT '山田 太郎',
    detail TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_activities_item ON activities(item_id, created_at DESC);
