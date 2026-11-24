# CloudDrive (Google Drive Clone) — Unified Application Specification

All 4 PHP framework implementations (`ai-framework`, `laravel`, `phalcon`, `slim`) MUST strictly satisfy this specification to ensure 100% functional, visual, security, SEO/AEO/GEO/AIO/LLMO, and benchmark parity.

## 1. Routes (SSR HTML Pages)

| Method | Path | Description |
|---|---|---|
| `GET` | `/` | マイドライブ (Root folder, `view=my-drive`) |
| `GET` | `/drive/folders/{id}` | 指定フォルダの内容とパンくずリスト |
| `GET` | `/drive/recent` | 最近使用したアイテム (`view=recent`) |
| `GET` | `/drive/starred` | スター付きアイテム (`view=starred`) |
| `GET` | `/drive/shared` | 共有アイテム (`view=shared`) |
| `GET` | `/drive/trash` | ゴミ箱 (`view=trash`) |
| `GET` | `/drive/search` | 検索結果 (`?q=...&type=...`) |
| `GET` | `/share/{token}` | 公開共有ファイル/フォルダの閲覧・ダウンロードページ |
| `GET` | `/about` | サービス概要・仕様・FAQ (SEO/AEO/GEO/AIO/LLMO ハブページ) |
| `GET` | `/offline` | PWA オフラインフォールバックページ |

## 2. Machine-Readable, PWA, SEO/AEO/GEO/AIO/LLMO Endpoints

| Method | Path | Content-Type | Purpose |
|---|---|---|---|
| `GET` | `/manifest.webmanifest` | `application/manifest+json; charset=utf-8` | PWA Web App Manifest |
| `GET` | `/sw.js` | `application/javascript; charset=utf-8` | PWA Service Worker (`Service-Worker-Allowed: /`) |
| `GET` | `/robots.txt` | `text/plain; charset=utf-8` | Search & AI Crawler directives (SEO/AIO/GEO) |
| `GET` | `/sitemap.xml` | `application/xml; charset=utf-8` | Dynamic XML Sitemap including public shared items (SEO) |
| `GET` | `/llms.txt` | `text/markdown; charset=utf-8` | Concise LLM-readable index (LLMO/AIO) |
| `GET` | `/llms-full.txt` | `text/markdown; charset=utf-8` | Comprehensive LLM documentation & structured specs (LLMO/GEO) |
| `GET` | `/openapi.json` | `application/json; charset=utf-8` | OpenAPI 3.1 API specification (AIO/AEO) |

## 3. REST JSON API Contract

All mutating endpoints (`POST`, `PATCH`, `DELETE`) require a valid signed CSRF token in `X-CSRF-Token` matching the `clouddrive_csrf` cookie.

| Method | Path | Status Codes | Description |
|---|---|---|---|
| `GET` | `/api/health` | `200` | Framework identity, PHP version, DB health, total items |
| `GET` | `/api/items` | `200`, `400`, `404` | List items (`view`, `folder_id`, `q`, `type`, `sort`, `dir`) + breadcrumbs + storage |
| `GET` | `/api/items/{id}` | `200`, `404` | Item metadata + recent activities + breadcrumbs |
| `POST` | `/api/folders` | `201`, `400`, `403`, `404`, `422` | Create folder (`name`, `parent_id`) |
| `POST` | `/api/files/upload` | `201`, `400`, `403`, `413`, `422` | Multipart upload (`file`, optional `parent_id`, `description`) |
| `PATCH` | `/api/items/{id}` | `200`, `400`, `403`, `404`, `422` | Rename, move (`parent_id` with cycle check), star, share, description |
| `DELETE` | `/api/items/{id}` | `200`, `403`, `404` | Soft delete to trash, or permanent delete if `?permanent=1` |
| `POST` | `/api/items/{id}/restore` | `200`, `403`, `404` | Restore trashed item (and re-root if parent was deleted) |
| `POST` | `/api/trash/empty` | `200`, `403` | Permanently delete all trashed items and reclaim disk files |
| `GET` | `/api/files/{id}/download` | `200`, `404` | Download binary file (`Content-Disposition: attachment`) |
| `GET` | `/api/files/{id}/preview` | `200`, `404`, `415` | Safe preview (JSON text preview for text/code/md/csv/json, sandboxed inline for raster images/PDF; SVG never executed inline) |
| `GET` | `/api/storage` | `200` | Storage quota (15 GB) and per-category byte breakdown |

## 4. Security Requirements

1. **Prepared Statements Only**: Zero string interpolation in SQL; strict allowlist for `ORDER BY` column & direction.
2. **XSS Prevention**: Strict HTML escaping (`ENT_QUOTES | ENT_SUBSTITUTE`) in SSR; `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` for JSON-LD and embedded state JSON; safe DOM construction in client JS.
3. **CSRF Protection**: Stateless HMAC-SHA256 signed token verified on all `POST`, `PATCH`, `DELETE` routes against `clouddrive_csrf` cookie + `X-CSRF-Token` header.
4. **File Safety**: Random UUID `storage_name` on disk, `basename()` + realpath jail inside `storage/uploads/`, max upload size 20 MB, quota enforcement, dangerous inline MIME types (`image/svg+xml`, `text/html`) served as safe text or attachment with `Content-Security-Policy: sandbox`.
5. **Cycle Prevention**: Moving a folder into itself or any of its descendants returns `422 Unprocessable Entity`.
6. **Security Headers**: `Content-Security-Policy`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`, `Cross-Origin-Opener-Policy: same-origin`.

## 5. Quality Targets

- **Lighthouse (Mobile & Desktop)**: Performance 100 / Accessibility 100 / Best Practices 100 / SEO 100 on all 4 frameworks.
- **PWA**: Installable Web App Manifest, Service Worker registered and active, offline fallback page verified.
- **Responsive**: Desktop (1440px), Tablet (768px), Mobile (390px) with zero horizontal overflow.
