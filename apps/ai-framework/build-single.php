#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Single-File Bundler for CloudDrive (AI Custom Framework — Rental Server Edition).
 *
 * Compiles the entire zero-dependency CloudDrive PHP application, HTML templates,
 * SQLite schema & deterministic 71-item seeder, CSS, JS, Service Worker, PWA icons,
 * and screenshots into ONE standalone file (`dist/index.php`).
 *
 * Key Rental-Server Design:
 *   - Uses the EXACT SAME directory as `index.php` (`__DIR__`) as the data/uploads area so
 *     uploaded media/documents (`cd-<hex>.mp4`, `.mp3`, `.pdf`, `.png`, etc.) are served
 *     directly by Apache / LiteSpeed / Nginx with native HTTP 206 Partial Content (Range).
 *   - Internal state files (`.clouddrive.sqlite`, `.clouddrive.secret`, `.clouddrive.lock`),
 *     trashed files (`.trashed-*`), and unsafe/executable uploads (`.blob`) are strictly
 *     blocked from direct web access via both `.htaccess` and PHP 403 guards (zero RCE/XSS).
 *   - PHP's Response::file() and direct-file fallback also implement RFC 9110 Range (206/416).
 *
 * Usage:
 *   php apps/ai-framework/build-single.php [output_path]
 */

$repoRoot = dirname(__DIR__, 2);
$appRoot = __DIR__;
$sharedRoot = $repoRoot . '/shared';
$outPath = $argv[1] ?? ($repoRoot . '/dist/index.php');

@mkdir(dirname($outPath), 0775, true);

/**
 * Wraps a namespaced PHP class file into bracketed `namespace Foo { ... }` syntax
 * so multiple files can coexist cleanly in a single PHP file without `use` collisions.
 */
function wrapNamespacedFile(string $filePath, ?callable $transform = null): string
{
    $code = file_get_contents($filePath);
    if ($code === false) {
        throw new RuntimeException("Failed to read {$filePath}");
    }
    if ($transform !== null) {
        $code = $transform($code);
    }
    $code = preg_replace('/^\s*<\?php\s*/', '', $code, 1) ?? $code;
    $code = preg_replace('/^\s*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*/', '', $code, 1) ?? $code;

    if (!preg_match('/^\s*namespace\s+([A-Za-z0-9_\\\\]+)\s*;\s*(.*)$/s', $code, $m)) {
        throw new RuntimeException("Expected namespace declaration in {$filePath}");
    }
    $ns = $m[1];
    $body = trim($m[2]);

    return "namespace {$ns} {\n{$body}\n}\n\n";
}

/**
 * Compiles an HTML/PHP template into a static method body inside AiFramework\EmbeddedTemplates.
 */
function compileTemplateMethod(string $name, string $filePath, ?callable $transform = null): string
{
    $tpl = file_get_contents($filePath);
    if ($tpl === false) {
        throw new RuntimeException("Failed to read template {$filePath}");
    }
    if ($transform !== null) {
        $tpl = $transform($tpl);
    }
    $tpl = preg_replace('/^\s*use\s+App\\\\Services\\\\UiHelper;\s*\R?/m', '', $tpl) ?? $tpl;
    $tplTrimmed = rtrim($tpl);

    return <<<PHP
    /** @param array<string, mixed> \$__data */
    public static function render_{$name}(array \$__data, callable \$e, callable \$jsonSafe): string
    {
        extract(\$__data, EXTR_SKIP);
        ob_start();
        try {
            ?>{$tplTrimmed}<?php
            return (string)ob_get_clean();
        } catch (\\Throwable \$ex) {
            ob_end_clean();
            throw \$ex;
        }
    }

PHP;
}

// Enhance drive-ui.js for the single-file direct-download + video/audio partial streaming edition
function transformDriveUiJs(string $js): string
{
    // 1. Update openPreview() to support direct_url downloads & <video>/<audio> streaming
    $oldPreview = <<<'JS'
    previewTitle.textContent = item.name;
    previewDownloadLink.href = `/api/files/${item.id}/download`;
    previewBody.innerHTML = '<p style="color:#ffffff">読み込み中...</p>';
    previewBackdrop.classList.add('open');

    const mime = (item.mime_type || '').toLowerCase();
    const previewUrl = `/api/files/${item.id}/preview`;

    if (INLINE_IMAGE_MIMES.has(mime)) {
      const img = document.createElement('img');
      img.className = 'preview-img';
      img.src = previewUrl;
      img.alt = item.name;
      previewBody.replaceChildren(img);
      return;
    }
    if (mime === 'application/pdf') {
      const iframe = document.createElement('iframe');
      iframe.className = 'preview-pdf';
      iframe.src = previewUrl;
      iframe.title = item.name;
      previewBody.replaceChildren(iframe);
      return;
    }
JS;
    $newPreview = <<<'JS'
    const directUrl = item.direct_url || null;
    const downloadHref = directUrl || `/api/files/${item.id}/download`;
    previewTitle.textContent = item.name;
    previewDownloadLink.href = downloadHref;
    previewDownloadLink.setAttribute('download', item.name);
    previewBody.innerHTML = '<p style="color:#ffffff">読み込み中...</p>';
    previewBackdrop.classList.add('open');

    const mime = (item.mime_type || '').toLowerCase();
    const ext = (item.extension || '').toLowerCase();
    const previewUrl = `/api/files/${item.id}/preview`;
    const mediaSrc = directUrl || previewUrl;

    if (INLINE_IMAGE_MIMES.has(mime)) {
      const img = document.createElement('img');
      img.className = 'preview-img';
      img.src = mediaSrc;
      img.alt = item.name;
      previewBody.replaceChildren(img);
      return;
    }
    if (mime.startsWith('video/') || ['mp4', 'm4v', 'webm', 'mov', 'ogv'].includes(ext)) {
      const video = document.createElement('video');
      video.controls = true;
      video.playsInline = true;
      video.preload = 'metadata';
      video.src = mediaSrc;
      video.style.cssText = 'max-width:100%;max-height:78vh;border-radius:12px;background:#000';
      previewBody.replaceChildren(video);
      return;
    }
    if (mime.startsWith('audio/') || ['mp3', 'm4a', 'ogg', 'wav', 'flac', 'aac'].includes(ext)) {
      const audio = document.createElement('audio');
      audio.controls = true;
      audio.preload = 'metadata';
      audio.src = mediaSrc;
      audio.style.cssText = 'width:min(560px,90vw)';
      previewBody.replaceChildren(audio);
      return;
    }
    if (mime === 'application/pdf') {
      const iframe = document.createElement('iframe');
      iframe.className = 'preview-pdf';
      iframe.src = mediaSrc;
      iframe.title = item.name;
      previewBody.replaceChildren(iframe);
      return;
    }
JS;
    if (!str_contains($js, $oldPreview)) {
        throw new RuntimeException('Failed to patch openPreview in drive-ui.js');
    }
    $js = str_replace($oldPreview, $newPreview, $js);

    // 2. Context menu download link -> direct_url with download attribute (native Range / partial download!)
    $oldMenuDl = '${isFolder ? \'\' : `<a class="menu-item" href="/api/files/${item.id}/download">ダウンロード</a>`}';
    $newMenuDl = '${isFolder ? \'\' : `<a class="menu-item" href="${esc(item.direct_url || \'/api/files/\' + item.id + \'/download\')}" download="${esc(item.name)}">ダウンロード</a>`}';
    if (!str_contains($js, $oldMenuDl)) {
        throw new RuntimeException('Failed to patch itemMenu download link in drive-ui.js');
    }
    $js = str_replace($oldMenuDl, $newMenuDl, $js);

    // 3. Selection bar download button -> trigger direct download preserving original filename
    $oldSelDl = 'if (selDownloadBtn) selDownloadBtn.addEventListener(\'click\', () => { const it = getSelectedItem(); if (it) window.location.href = `/api/files/${it.id}/download`; });';
    $newSelDl = 'if (selDownloadBtn) selDownloadBtn.addEventListener(\'click\', () => { const it = getSelectedItem(); if (!it) return; const a = document.createElement(\'a\'); a.href = it.direct_url || `/api/files/${it.id}/download`; a.download = it.name; document.body.appendChild(a); a.click(); a.remove(); });';
    if (!str_contains($js, $oldSelDl)) {
        throw new RuntimeException('Failed to patch selDownloadBtn in drive-ui.js');
    }
    $js = str_replace($oldSelDl, $newSelDl, $js);

    // 4. Show direct partial-download URL in details side panel when available
    $oldDetailShare = '<dt>共有</dt><dd>${Number(item.is_shared) === 1 ? \'リンクを知っている全員が閲覧可\' : \'非公開\'}</dd>';
    $newDetailShare = '<dt>共有</dt><dd>${Number(item.is_shared) === 1 ? \'リンクを知っている全員が閲覧可\' : \'非公開\'}</dd>${item.direct_url ? `<dt>直接URL</dt><dd><a href="${esc(item.direct_url)}" download="${esc(item.name)}">${esc(item.direct_url)}</a></dd>` : \'\'}';
    if (str_contains($js, $oldDetailShare)) {
        $js = str_replace($oldDetailShare, $newDetailShare, $js);
    }

    return $js;
}

// 1. Collect binary static assets to append after __HALT_COMPILER();
$assetSpecs = [
    '/sw.js' => [$sharedRoot . '/public/sw.js', 'application/javascript; charset=utf-8', 'no-cache', null],
    '/assets/app.js' => [$sharedRoot . '/public/assets/app.js', 'application/javascript; charset=utf-8', 'public, max-age=31536000, immutable', null],
    '/assets/drive-ui.js' => [$sharedRoot . '/public/assets/drive-ui.js', 'application/javascript; charset=utf-8', 'public, max-age=31536000, immutable', 'transformDriveUiJs'],
    '/assets/app.css' => [$sharedRoot . '/public/assets/app.css', 'text/css; charset=utf-8', 'public, max-age=31536000, immutable', null],
    '/assets/app.min.css' => [$sharedRoot . '/public/assets/app.min.css', 'text/css; charset=utf-8', 'public, max-age=31536000, immutable', null],
    '/assets/favicon.svg' => [$sharedRoot . '/public/assets/favicon.svg', 'image/svg+xml; charset=utf-8', 'public, max-age=31536000, immutable', null],
    '/assets/icon-192.png' => [$sharedRoot . '/public/assets/icon-192.png', 'image/png', 'public, max-age=31536000, immutable', null],
    '/assets/icon-maskable-192.png' => [$sharedRoot . '/public/assets/icon-maskable-192.png', 'image/png', 'public, max-age=31536000, immutable', null],
    '/assets/icon-512.png' => [$sharedRoot . '/public/assets/icon-512.png', 'image/png', 'public, max-age=31536000, immutable', null],
    '/assets/icon-maskable-512.png' => [$sharedRoot . '/public/assets/icon-maskable-512.png', 'image/png', 'public, max-age=31536000, immutable', null],
    '/assets/og-image.png' => [$sharedRoot . '/public/assets/og-image.png', 'image/png', 'public, max-age=31536000, immutable', null],
    '/assets/screenshot-desktop.png' => [$sharedRoot . '/public/assets/screenshot-desktop.png', 'image/png', 'public, max-age=31536000, immutable', null],
    '/assets/screenshot-mobile.png' => [$sharedRoot . '/public/assets/screenshot-mobile.png', 'image/png', 'public, max-age=31536000, immutable', null],
];

$binaryPayload = '';
$manifest = [];
foreach ($assetSpecs as $route => [$filePath, $mime, $cacheControl, $fn]) {
    $bytes = file_get_contents($filePath);
    if ($bytes === false) {
        throw new RuntimeException("Missing asset: {$filePath}");
    }
    if ($fn !== null) {
        $bytes = $fn($bytes);
    }
    $len = strlen($bytes);
    $manifest[$route] = [
        'offset' => strlen($binaryPayload),
        'length' => $len,
        'mime' => $mime,
        'cache' => $cacheControl,
        'etag' => '"' . substr(sha1($bytes), 0, 16) . '"',
    ];
    $binaryPayload .= $bytes;
}

$inlineCss = file_get_contents($sharedRoot . '/public/assets/app.min.css');
if ($inlineCss === false) {
    throw new RuntimeException('Missing shared/public/assets/app.min.css (run ./shared/sync-assets.sh first)');
}
$schemaSql = file_get_contents($sharedRoot . '/database/schema.sql');
if ($schemaSql === false) {
    throw new RuntimeException('Missing shared/database/schema.sql');
}

// 2. Transform shared/database/seed.php into an embeddable GD-optional seeding body
$rawSeed = file_get_contents($sharedRoot . '/database/seed.php');
if ($rawSeed === false) {
    throw new RuntimeException('Missing shared/database/seed.php');
}
$seedStart = strpos($rawSeed, '$insertItem = $pdo->prepare(');
$seedEnd = strpos($rawSeed, '$pdo->commit();');
if ($seedStart === false || $seedEnd === false) {
    throw new RuntimeException('Could not locate seed body markers in shared/database/seed.php');
}
$seedBody = substr($rawSeed, $seedStart, ($seedEnd + strlen('$pdo->commit();')) - $seedStart);
$seedBody = str_replace(
    ['createSamplePng(', 'createSamplePdf('],
    ['self::createSamplePng(', 'self::createSamplePdf('],
    $seedBody
);
// Prefix seeded trashed items with `.trashed-` so trashed files are blocked from direct HTTP access
$seedBody = str_replace(
    ["'storage' => 'seed-trash-logo.txt'", "'storage' => 'seed-trash-test.csv'", "'storage' => 'seed-trash-draft.md'"],
    ["'storage' => '.trashed-seed-trash-logo.txt'", "'storage' => '.trashed-seed-trash-test.csv'", "'storage' => '.trashed-seed-trash-draft.md'"],
    $seedBody
);

// 3. Assemble the complete single PHP file
$parts = [];
$parts[] = <<<'PHP'
<?php
declare(strict_types=1);

/**
 * CloudDrive — Single-File Standalone PHP 8.3 Edition (AI Custom Framework)
 *
 * Zero external dependencies, zero Composer, zero vendor/.
 * Upload this single file as `index.php` to any PHP 8.3 shared hosting / rental server
 * (Xserver, Sakura, Lolipop, ConoHa WING, MixHost, Nginx, Apache, LiteSpeed, or `php -S`).
 *
 * Storage Layout (same directory as `index.php` for native direct partial downloads):
 *   - Uploaded safe media/documents (mp4, webm, mov, mp3, pdf, png, jpg, zip, etc.) are saved
 *     directly alongside `index.php` as `cd-<hex>.<ext>` so Apache/LiteSpeed/Nginx serves them
 *     directly with native HTTP 206 Partial Content (Accept-Ranges: bytes) seeking/resuming.
 *   - Potentially executable or active-content uploads (.php, .phtml, .html, .svg, .js, etc.)
 *     are isolated as `cd-<hex>.blob` and blocked from direct HTTP access (zero RCE/XSS).
 *   - Internal state files (`.clouddrive.sqlite`, `.clouddrive.secret`, `.clouddrive.lock`)
 *     and trashed files (`.trashed-*`) are blocked with 403 via both `.htaccess` and PHP.
 *   - PHP's Response::file() and direct-file fallback also implement RFC 9110 Range (206/416).
 *
 * Optional Environment Variables:
 *   APP_SECRET             Override CSRF HMAC secret (>= 32 bytes)
 *   APP_URL                Fixed canonical origin URL (e.g. https://drive.example.com)
 *   CLOUDDRIVE_DATA_DIR    Override data/uploads directory (default: same dir as index.php)
 *   CLOUDDRIVE_MAX_UPLOAD_MB Override max upload size in MB (default: 200)
 *   CLOUDDRIVE_SKIP_SEED   Set to "1" to initialize an empty Drive instead of 71 demo items
 *   CLOUDDRIVE_NO_HTACCESS Set to "1" to disable automatic Apache/LiteSpeed `.htaccess` creation
 */


PHP;

// Request.php with rental-server proxy HTTPS & safe RFC host fallback when APP_URL is unset
$parts[] = wrapNamespacedFile($appRoot . '/framework/Request.php', static function (string $code): string {
    $oldHttpsAndBase = <<<'PHP'
    public function isHttps(): bool
    {
        return !empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off';
    }

    public function baseUrl(): string
    {
        $configured = getenv('APP_URL');
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }
        $scheme = $this->isHttps() ? 'https' : 'http';
        $port = (string)($this->server['SERVER_PORT'] ?? '8081');
        return "{$scheme}://localhost" . ($port !== '80' && $port !== '443' ? ":{$port}" : '');
    }
PHP;
    $newHttpsAndBase = <<<'PHP'
    public function isHttps(): bool
    {
        if (!empty($this->server['HTTPS']) && strtolower((string)$this->server['HTTPS']) !== 'off') {
            return true;
        }
        if ((string)($this->server['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        return strtolower((string)($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public function baseUrl(): string
    {
        $configured = getenv('APP_URL');
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }
        $scheme = $this->isHttps() ? 'https' : 'http';
        $host = (string)($this->server['HTTP_HOST'] ?? $this->server['SERVER_NAME'] ?? 'localhost');
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*(?::\d{1,5})?$/i', $host)) {
            $host = 'localhost';
        }
        return "{$scheme}://{$host}";
    }
PHP;
    if (!str_contains($code, $oldHttpsAndBase)) {
        throw new RuntimeException('Failed to patch Request.php');
    }
    return str_replace($oldHttpsAndBase, $newHttpsAndBase, $code);
});

// Response.php upgraded with full RFC 9110 HTTP Range (206 Partial Content / 416) chunked streaming
$parts[] = wrapNamespacedFile($appRoot . '/framework/Response.php', static function (string $code): string {
    $oldSend = <<<'PHP'
    public function send(bool $isHead = false): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        foreach ($this->cookies as $c) {
            setcookie($c['name'], $c['value'], $c['options']);
        }
        if ($isHead) {
            return;
        }
        if ($this->filePath !== null) {
            readfile($this->filePath);
            return;
        }
        echo $this->body;
    }
PHP;
    $newSend = <<<'PHP'
    public function send(bool $isHead = false): void
    {
        if ($this->filePath !== null && is_file($this->filePath)) {
            $this->sendFileWithRanges($this->filePath, $isHead);
            return;
        }
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        foreach ($this->cookies as $c) {
            setcookie($c['name'], $c['value'], $c['options']);
        }
        if ($isHead) {
            return;
        }
        echo $this->body;
    }

    private function sendFileWithRanges(string $path, bool $isHead): void
    {
        $size = (int)filesize($path);
        $status = $this->status;
        $start = 0;
        $end = $size > 0 ? $size - 1 : 0;
        $rangeHeader = isset($_SERVER['HTTP_RANGE']) && is_string($_SERVER['HTTP_RANGE']) ? trim($_SERVER['HTTP_RANGE']) : '';

        foreach ($this->headers as $name => $value) {
            if (strtolower($name) === 'content-length') {
                continue;
            }
            header($name . ': ' . $value, true);
        }
        header('Accept-Ranges: bytes', true);
        foreach ($this->cookies as $c) {
            setcookie($c['name'], $c['value'], $c['options']);
        }

        if ($rangeHeader !== '' && $size > 0 && preg_match('/^bytes=(\d*)-(\d*)$/', $rangeHeader, $m) === 1) {
            $rStart = $m[1];
            $rEnd = $m[2];
            if ($rStart === '' && $rEnd !== '') {
                $suffix = (int)$rEnd;
                if ($suffix <= 0) {
                    http_response_code(416);
                    header("Content-Range: bytes */{$size}", true);
                    return;
                }
                $start = max(0, $size - $suffix);
                $end = $size - 1;
            } elseif ($rStart !== '') {
                $start = (int)$rStart;
                $end = $rEnd !== '' ? min((int)$rEnd, $size - 1) : ($size - 1);
                if ($start >= $size || $start > $end) {
                    http_response_code(416);
                    header("Content-Range: bytes */{$size}", true);
                    return;
                }
            }
            $status = 206;
            header("Content-Range: bytes {$start}-{$end}/{$size}", true);
        }

        $length = $size > 0 ? ($end - $start + 1) : 0;
        header('Content-Length: ' . $length, true);
        http_response_code($status);
        if ($isHead || $length === 0) {
            return;
        }

        $fp = fopen($path, 'rb');
        if ($fp === false) {
            return;
        }
        try {
            if ($start > 0) {
                fseek($fp, $start);
            }
            $remaining = $length;
            while ($remaining > 0 && !feof($fp)) {
                $chunk = fread($fp, min(65536, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
                $remaining -= strlen($chunk);
            }
        } finally {
            fclose($fp);
        }
    }
PHP;
    if (!str_contains($code, $oldSend)) {
        throw new RuntimeException('Failed to patch Response::send() for Range support');
    }
    return str_replace($oldSend, $newSend, $code);
});

// Security.php upgraded so resolveJailedPath strictly permits only managed CloudDrive files (cd-*, up-*, seed-*, .trashed-*)
$parts[] = wrapNamespacedFile($appRoot . '/framework/Security.php', static function (string $code): string {
    $oldJail = <<<'PHP'
        $safeName = basename($storageName);
        if ($safeName === '' || $safeName !== $storageName || str_contains($safeName, '..')) {
            return null;
        }
PHP;
    $newJail = <<<'PHP'
        $safeName = basename($storageName);
        if (
            $safeName === ''
            || $safeName !== $storageName
            || str_contains($safeName, '..')
            || preg_match('/^(?:\.trashed-)?(?:cd-|up-|seed-)[a-zA-Z0-9._-]+$/', $safeName) !== 1
        ) {
            return null;
        }
PHP;
    if (!str_contains($code, $oldJail)) {
        throw new RuntimeException('Failed to patch Security::resolveJailedPath()');
    }
    return str_replace($oldJail, $newJail, $code);
});

foreach (['Container.php', 'Database.php', 'HttpException.php', 'MiddlewareInterface.php', 'Router.php', 'Application.php'] as $f) {
    $parts[] = wrapNamespacedFile($appRoot . '/framework/' . $f);
}

// In-memory compiled View & EmbeddedTemplates (share.php upgraded for direct partial download & video/audio player)
$templateMethods = '';
foreach (['layout', 'drive', 'about', 'offline'] as $tplName) {
    $templateMethods .= compileTemplateMethod($tplName, $appRoot . '/templates/' . $tplName . '.php');
}
$templateMethods .= compileTemplateMethod('share', $appRoot . '/templates/share.php', static function (string $tpl): string {
    $oldShareTop = <<<'PHP'
$isFolder = (int)$item['is_folder'] === 1;
$isImg = !$isFolder && in_array(strtolower((string)$item['mime_type']), ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true);
PHP;
    $newShareTop = <<<'PHP'
$isFolder = (int)$item['is_folder'] === 1;
$mimeLower = strtolower((string)$item['mime_type']);
$extLower = strtolower((string)($item['extension'] ?? ''));
$isImg = !$isFolder && in_array($mimeLower, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true);
$isVideo = !$isFolder && (str_starts_with($mimeLower, 'video/') || in_array($extLower, ['mp4', 'm4v', 'webm', 'mov', 'ogv'], true));
$isAudio = !$isFolder && (str_starts_with($mimeLower, 'audio/') || in_array($extLower, ['mp3', 'm4a', 'ogg', 'wav', 'flac', 'aac'], true));
$dlHref = !empty($item['direct_url']) ? (string)$item['direct_url'] : ('/api/files/' . (int)$item['id'] . '/download');
PHP;
    $tpl = str_replace($oldShareTop, $newShareTop, $tpl);

    $oldMediaBlock = <<<'PHP'
    <?php if ($isImg): ?>
      <p style="margin-top:16px"><img src="/api/files/<?= (int)$item['id'] ?>/preview" alt="<?= $e($item['name']) ?>" width="640" height="360" style="max-width:100%;height:auto;border-radius:8px;border:1px solid #dde3ea"></p>
    <?php endif; ?>
    <p style="margin-top:20px;display:flex;flex-wrap:wrap;gap:10px">
      <a class="btn-primary" style="display:inline-flex;align-items:center" href="/api/files/<?= (int)$item['id'] ?>/download">このファイルをダウンロード</a>
      <a class="btn-secondary" style="display:inline-flex;align-items:center" href="/">マイドライブへ戻る</a>
    </p>
PHP;
    $newMediaBlock = <<<'PHP'
    <?php if ($isImg): ?>
      <p style="margin-top:16px"><img src="<?= $e(!empty($item['direct_url']) ? (string)$item['direct_url'] : '/api/files/' . (int)$item['id'] . '/preview') ?>" alt="<?= $e($item['name']) ?>" width="640" height="360" style="max-width:100%;height:auto;border-radius:8px;border:1px solid #dde3ea"></p>
    <?php elseif ($isVideo): ?>
      <p style="margin-top:16px"><video controls playsinline preload="metadata" src="<?= $e($dlHref) ?>" style="max-width:100%;max-height:540px;border-radius:8px;background:#000"></video></p>
    <?php elseif ($isAudio): ?>
      <p style="margin-top:16px"><audio controls preload="metadata" src="<?= $e($dlHref) ?>" style="width:min(560px,100%)"></audio></p>
    <?php endif; ?>
    <p style="margin-top:20px;display:flex;flex-wrap:wrap;gap:10px">
      <a class="btn-primary" style="display:inline-flex;align-items:center" href="<?= $e($dlHref) ?>" download="<?= $e($item['name']) ?>">このファイルをダウンロード</a>
      <a class="btn-secondary" style="display:inline-flex;align-items:center" href="/">マイドライブへ戻る</a>
    </p>
PHP;
    $tpl = str_replace($oldMediaBlock, $newMediaBlock, $tpl);

    $oldChildDl = '<a href="/api/files/<?= (int)$ch[\'id\'] ?>/download">ダウンロード</a>';
    $newChildDl = '<a href="<?= $e(!empty($ch[\'direct_url\']) ? (string)$ch[\'direct_url\'] : \'/api/files/\' . (int)$ch[\'id\'] . \'/download\') ?>" download="<?= $e($ch[\'name\']) ?>">ダウンロード</a>';
    return str_replace($oldChildDl, $newChildDl, $tpl);
});

$parts[] = <<<PHP
namespace AiFramework {
use App\\Services\\UiHelper;

final class EmbeddedTemplates
{
{$templateMethods}}

final class View
{
    public function __construct(private readonly string \$templateDir = '') {}

    /** @param array<string, mixed> \$data */
    public function render(string \$template, array \$data = [], ?string \$layout = 'layout'): string
    {
        \$content = \$this->renderTemplate(\$template, \$data);
        if (\$layout === null) {
            return \$content;
        }
        return \$this->renderTemplate(\$layout, array_merge(\$data, ['content' => \$content]));
    }

    /** @param array<string, mixed> \$data */
    private function renderTemplate(string \$template, array \$data): string
    {
        \$safe = preg_replace('/[^a-zA-Z0-9_-]/', '', \$template);
        \$method = 'render_' . \$safe;
        if (!method_exists(EmbeddedTemplates::class, \$method)) {
            throw new \\RuntimeException("Template not found: {\$safe}");
        }
        \$e = static fn(mixed \$v): string => htmlspecialchars((string)(\$v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        \$jsonSafe = static fn(mixed \$v): string => json_encode(
            \$v,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
        return EmbeddedTemplates::\$method(\$data, \$e, \$jsonSafe);
    }
}
}


PHP;

// Services: DriveService upgraded for direct-serving safe extensions in __DIR__, trashed dotfile hiding, 200MB upload cap, and direct_url field
$parts[] = wrapNamespacedFile($appRoot . '/app/Services/DriveService.php', static function (string $code): string {
    // 1. Add SAFE_DIRECT_EXTS allowlist and raise default MAX_UPLOAD_BYTES to 200MB for mp4 uploads
    $oldConsts = "    public const MAX_UPLOAD_BYTES = 20971520; // 20 MB\n    public const MAX_DEPTH = 32;";
    $newConsts = <<<'PHP'
    public const MAX_UPLOAD_BYTES = 209715200;     // 200 MB (overridable via CLOUDDRIVE_MAX_UPLOAD_MB)
    public const MAX_DEPTH = 32;
    /** @var array<string, true> Non-executable extensions safe for direct Apache/Nginx 206 partial download */
    public const SAFE_DIRECT_EXTS = [
        'mp4' => true, 'm4v' => true, 'webm' => true, 'mov' => true, 'ogv' => true, 'mkv' => true,
        'mp3' => true, 'm4a' => true, 'ogg' => true, 'wav' => true, 'flac' => true, 'aac' => true,
        'png' => true, 'jpg' => true, 'jpeg' => true, 'gif' => true, 'webp' => true, 'avif' => true, 'bmp' => true,
        'pdf' => true, 'zip' => true, 'tar' => true, 'gz' => true, '7z' => true, 'rar' => true,
        'txt' => true, 'md' => true, 'csv' => true, 'json' => true,
        'doc' => true, 'docx' => true, 'xls' => true, 'xlsx' => true, 'ppt' => true, 'pptx' => true,
    ];

    public static function maxUploadBytes(): int
    {
        $mb = getenv('CLOUDDRIVE_MAX_UPLOAD_MB');
        if (is_string($mb) && ctype_digit($mb) && (int)$mb > 0) {
            return (int)$mb * 1048576;
        }
        return self::MAX_UPLOAD_BYTES;
    }

    public static function isSafeDirectStorageName(?string $storageName): bool
    {
        if (!is_string($storageName) || $storageName === '' || str_starts_with($storageName, '.')) {
            return false;
        }
        $ext = strtolower(pathinfo($storageName, PATHINFO_EXTENSION));
        return isset(self::SAFE_DIRECT_EXTS[$ext]);
    }
PHP;
    if (!str_contains($code, $oldConsts)) {
        throw new RuntimeException('Failed to patch DriveService constants');
    }
    $code = str_replace($oldConsts, $newConsts, $code);

    // 2. Respect maxUploadBytes(), guard finfo, and save safe extensions as cd-<hex>.<ext> (unsafe -> .blob)
    $code = str_replace(
        'if ($size > self::MAX_UPLOAD_BYTES) {',
        'if ($size > self::maxUploadBytes()) {',
        $code
    );
    $code = str_replace(
        "\$finfo = new \\finfo(FILEINFO_MIME_TYPE);\n        \$detectedMime = (string)(\$finfo->file(\$tmpPath) ?: 'application/octet-stream');",
        "\$detectedMime = class_exists(\\finfo::class) ? (string)((new \\finfo(FILEINFO_MIME_TYPE))->file(\$tmpPath) ?: 'application/octet-stream') : 'application/octet-stream';",
        $code
    );
    $oldStorageLine = "        // Always use inert .blob extension on disk (defense-in-depth against executable uploads)\n        \$storageName = 'up-' . bin2hex(random_bytes(16)) . '.blob';";
    $newStorageLine = "        // Safe non-executable media/documents keep their real extension for native HTTP 206 partial downloads;\n        // executable/active types (.php, .html, .svg, .js, etc.) are always isolated as inert .blob files.\n        \$safeExt = isset(self::SAFE_DIRECT_EXTS[\$ext]) ? \$ext : 'blob';\n        \$storageName = 'cd-' . bin2hex(random_bytes(16)) . '.' . \$safeExt;";
    if (!str_contains($code, $oldStorageLine)) {
        throw new RuntimeException('Failed to patch DriveService storageName logic');
    }
    $code = str_replace($oldStorageLine, $newStorageLine, $code);

    // 3. On soft-delete to trash, rename direct files to `.trashed-<name>` so direct HTTP URLs are blocked immediately
    $oldSoftDelete = <<<'PHP'
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
PHP;
    $newSoftDelete = <<<'PHP'
        if (!$permanent && (int)$item['is_trashed'] === 0) {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $toHide = $this->db->transaction(function (Database $db) use ($id, $now): array {
                $files = $db->fetchAll(
                    'WITH RECURSIVE tree(id, storage_name, is_trashed) AS (
                        SELECT id, storage_name, is_trashed FROM items WHERE id = ?
                        UNION
                        SELECT i.id, i.storage_name, i.is_trashed FROM items i JOIN tree t ON i.parent_id = t.id
                    )
                    SELECT id, storage_name FROM tree WHERE is_trashed = 0 AND storage_name IS NOT NULL',
                    [$id]
                );
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
                $renames = [];
                foreach ($files as $f) {
                    $oldName = (string)$f['storage_name'];
                    if (!str_starts_with($oldName, '.trashed-')) {
                        $newName = '.trashed-' . $oldName;
                        $db->execute('UPDATE items SET storage_name = ? WHERE id = ?', [$newName, (int)$f['id']]);
                        $renames[] = [$oldName, $newName];
                    }
                }
                $db->execute(
                    'INSERT INTO activities (item_id, action, actor_name, detail, created_at) VALUES (?, ?, ?, ?, ?)',
                    [$id, 'trashed', '山田 太郎', 'ゴミ箱へ移動しました', $now]
                );
                return $renames;
            }, true);

            foreach ($toHide as [$oldName, $newName]) {
                $oldPath = Security::resolveJailedPath($this->uploadsDir, $oldName);
                if ($oldPath !== null) {
                    @rename($oldPath, $this->uploadsDir . DIRECTORY_SEPARATOR . $newName);
                }
            }
            return ['deleted' => true, 'permanent' => false, 'id' => $id];
        }
PHP;
    if (!str_contains($code, $oldSoftDelete)) {
        throw new RuntimeException('Failed to patch DriveService soft-delete dotfile hiding');
    }
    $code = str_replace($oldSoftDelete, $newSoftDelete, $code);

    // 4. On restore, rename `.trashed-<name>` back to `<name>` so direct partial downloads resume cleanly
    $oldRestore = <<<'PHP'
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
PHP;
    $newRestore = <<<'PHP'
        $res = $this->db->transaction(function (Database $db) use ($id, $parentId, $now, $trashedAt): array {
            $filesToRestore = [];
            $selfFile = $db->fetchOne('SELECT id, storage_name FROM items WHERE id = ? AND storage_name IS NOT NULL', [$id]);
            if ($selfFile !== null) {
                $filesToRestore[] = $selfFile;
            }
            if ($trashedAt !== null) {
                $descFiles = $db->fetchAll(
                    'WITH RECURSIVE tree(id, storage_name, is_trashed, trashed_at) AS (
                        SELECT id, storage_name, is_trashed, trashed_at FROM items WHERE parent_id = ?
                        UNION
                        SELECT i.id, i.storage_name, i.is_trashed, i.trashed_at FROM items i JOIN tree t ON i.parent_id = t.id
                    )
                    SELECT id, storage_name FROM tree WHERE is_trashed = 1 AND trashed_at = ? AND storage_name IS NOT NULL',
                    [$id, $trashedAt]
                );
                $filesToRestore = array_merge($filesToRestore, $descFiles);
            }

            $db->execute(
                'UPDATE items SET is_trashed = 0, trashed_at = NULL, parent_id = ?, updated_at = ? WHERE id = ?',
                [$parentId, $now, $id]
            );
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
            $renames = [];
            foreach ($filesToRestore as $f) {
                $oldName = (string)$f['storage_name'];
                if (str_starts_with($oldName, '.trashed-')) {
                    $newName = substr($oldName, 9);
                    $db->execute('UPDATE items SET storage_name = ? WHERE id = ?', [$newName, (int)$f['id']]);
                    $renames[] = [$oldName, $newName];
                }
            }
            $db->execute(
                'INSERT INTO activities (item_id, action, actor_name, detail, created_at) VALUES (?, ?, ?, ?, ?)',
                [$id, 'restored', '山田 太郎', 'ゴミ箱から復元しました', $now]
            );
            $row = $db->fetchOne('SELECT * FROM items WHERE id = ?', [$id]);
            return ['item' => $this->normalizeItem($row ?? []), 'renames' => $renames];
        }, true);

        foreach ($res['renames'] as [$oldName, $newName]) {
            $oldPath = Security::resolveJailedPath($this->uploadsDir, $oldName);
            if ($oldPath !== null) {
                @rename($oldPath, $this->uploadsDir . DIRECTORY_SEPARATOR . $newName);
            }
        }
        return $res['item'];
PHP;
    if (!str_contains($code, $oldRestore)) {
        throw new RuntimeException('Failed to patch DriveService::restoreItem()');
    }
    $code = str_replace($oldRestore, $newRestore, $code);

    // 5. Add direct_url to normalizeItem() when active, non-folder, and safe direct extension
    $oldNormEnd = <<<'PHP'
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)$row['updated_at'],
        ];
    }
PHP;
    $newNormEnd = <<<'PHP'
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)$row['updated_at'],
            'direct_url' => ((int)$row['is_folder'] === 0
                && (int)$row['is_trashed'] === 0
                && self::isSafeDirectStorageName(isset($row['storage_name']) ? (string)$row['storage_name'] : null)
                && (getenv('CLOUDDRIVE_DATA_DIR') === false || getenv('CLOUDDRIVE_DATA_DIR') === ''))
                ? ('/' . rawurlencode((string)$row['storage_name']))
                : null,
        ];
    }
PHP;
    if (!str_contains($code, $oldNormEnd)) {
        throw new RuntimeException('Failed to patch DriveService::normalizeItem()');
    }
    return str_replace($oldNormEnd, $newNormEnd, $code);
});

$parts[] = wrapNamespacedFile($appRoot . '/app/Services/SeoService.php');

$parts[] = wrapNamespacedFile($appRoot . '/app/Services/UiHelper.php', static function (string $code): string {
    $oldCssMethod = <<<'PHP'
    public static function inlineCss(): string
    {
        if (self::$cachedCss === null) {
            $phpCache = dirname(__DIR__, 2) . '/storage/cache/inline_css.php';
            if (is_file($phpCache)) {
                self::$cachedCss = (string)(require $phpCache);
            } else {
                $minPath = dirname(__DIR__, 2) . '/public/assets/app.min.css';
                self::$cachedCss = is_file($minPath) ? (string)file_get_contents($minPath) : '';
            }
        }
        return self::$cachedCss;
    }
PHP;
    $newCssMethod = <<<'PHP'
    public static function inlineCss(): string
    {
        return \EmbeddedAssets::INLINE_CSS;
    }
PHP;
    if (!str_contains($code, $oldCssMethod)) {
        throw new RuntimeException('Failed to patch UiHelper::inlineCss()');
    }
    return str_replace($oldCssMethod, $newCssMethod, $code);
});

// Middleware
foreach (['SecurityHeadersMiddleware.php', 'CsrfMiddleware.php'] as $m) {
    $parts[] = wrapNamespacedFile($appRoot . '/app/Middleware/' . $m);
}

// Controllers (ApiController upgraded for video/audio Range preview + dynamic maxUploadBytes)
$parts[] = wrapNamespacedFile($appRoot . '/app/Controllers/PageController.php');
$parts[] = wrapNamespacedFile($appRoot . '/app/Controllers/ApiController.php', static function (string $code): string {
    $code = str_replace(
        'if ($contentLength > DriveService::MAX_UPLOAD_BYTES && empty($request->files)) {',
        'if ($contentLength > DriveService::maxUploadBytes() && empty($request->files)) {',
        $code
    );
    $oldPdf = <<<'PHP'
        if ($mime === 'application/pdf') {
            return Response::file($path, 'application/pdf', null, [
                'Content-Disposition' => 'inline',
                'Cache-Control' => 'private, max-age=3600',
                'Content-Security-Policy' => "default-src 'none'; object-src 'self'; script-src 'none'; form-action 'none'; frame-ancestors 'self'",
            ]);
        }
PHP;
    $newPdfAndMedia = <<<'PHP'
        if ($mime === 'application/pdf') {
            return Response::file($path, 'application/pdf', null, [
                'Content-Disposition' => 'inline',
                'Cache-Control' => 'private, max-age=3600',
                'Content-Security-Policy' => "default-src 'none'; object-src 'self'; script-src 'none'; form-action 'none'; frame-ancestors 'self'",
            ]);
        }

        $mediaMimes = [
            'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'ogv' => 'video/ogg',
            'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav', 'flac' => 'audio/flac', 'aac' => 'audio/aac',
        ];
        if (str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/') || isset($mediaMimes[$ext])) {
            $serveMime = (str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/')) ? $mime : $mediaMimes[$ext];
            return Response::file($path, $serveMime, null, [
                'Cache-Control' => 'private, max-age=3600',
                'Content-Security-Policy' => "default-src 'none'; media-src 'self'; frame-ancestors 'self'",
            ]);
        }
PHP;
    if (!str_contains($code, $oldPdf)) {
        throw new RuntimeException('Failed to patch ApiController::previewFile() for video/audio');
    }
    return str_replace($oldPdf, $newPdfAndMedia, $code);
});
$parts[] = wrapNamespacedFile($appRoot . '/app/Controllers/MetaController.php');

// EmbeddedAssets + SingleFileRuntime + Front-Controller in global namespace
$manifestExport = var_export($manifest, true);
$inlineCssExport = var_export($inlineCss, true);
$schemaSqlExport = var_export($schemaSql, true);

$parts[] = <<<PHP
namespace {

use AiFramework\\Application;
use AiFramework\\Container;
use AiFramework\\Database;
use AiFramework\\Request;
use AiFramework\\Response;
use AiFramework\\Router;
use AiFramework\\Security;
use AiFramework\\View;
use App\\Controllers\\ApiController;
use App\\Controllers\\MetaController;
use App\\Controllers\\PageController;
use App\\Middleware\\CsrfMiddleware;
use App\\Middleware\\SecurityHeadersMiddleware;
use App\\Services\\DriveService;
use App\\Services\\SeoService;

final class EmbeddedAssets
{
    public const INLINE_CSS = {$inlineCssExport};

    /** @var array<string, array{offset:int, length:int, mime:string, cache:string, etag:string}> */
    public const MANIFEST = {$manifestExport};

    public static function readBytes(string \$route): ?string
    {
        \$meta = self::MANIFEST[\$route] ?? null;
        if (\$meta === null) {
            return null;
        }
        \$fp = fopen(__FILE__, 'rb');
        if (\$fp === false) {
            return null;
        }
        try {
            fseek(\$fp, __COMPILER_HALT_OFFSET__ + \$meta['offset']);
            \$remaining = \$meta['length'];
            \$buf = '';
            while (\$remaining > 0 && !feof(\$fp)) {
                \$chunk = fread(\$fp, min(65536, \$remaining));
                if (\$chunk === false || \$chunk === '') {
                    break;
                }
                \$buf .= \$chunk;
                \$remaining -= strlen(\$chunk);
            }
            return \$buf;
        } finally {
            fclose(\$fp);
        }
    }
}

final class SingleFileRuntime
{
    private const SCHEMA_SQL = {$schemaSqlExport};

    /** @var array<string, string> */
    private const DIRECT_MIME_MAP = [
        'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
        'ogv' => 'video/ogg', 'mkv' => 'video/x-matroska',
        'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav',
        'flac' => 'audio/flac', 'aac' => 'audio/aac',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'avif' => 'image/avif', 'bmp' => 'image/bmp',
        'pdf' => 'application/pdf', 'zip' => 'application/zip', 'tar' => 'application/x-tar',
        'gz' => 'application/gzip', '7z' => 'application/x-7z-compressed', 'rar' => 'application/vnd.rar',
        'txt' => 'text/plain; charset=utf-8', 'md' => 'text/markdown; charset=utf-8',
        'csv' => 'text/csv; charset=utf-8', 'json' => 'application/json; charset=utf-8',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    /**
     * Handles:
     *  1) Defense-in-depth 403 protection for dotfiles, .blob, .sqlite*, .secret, .lock
     *  2) Direct static file serving (with RFC 9110 Range / 206 Partial Content) for safe files
     *     located in the same directory as index.php (when routed through PHP, e.g. `php -S`)
     *  3) Embedded UI static assets (/assets/*, /sw.js) with ETag / 304
     */
    public static function tryServeStaticOrBlockDotfile(): bool
    {
        \$uri = (string)(\$_SERVER['REQUEST_URI'] ?? '/');
        \$path = parse_url(\$uri, PHP_URL_PATH);
        if (!is_string(\$path) || \$path === '') {
            \$path = '/';
        }

        // 1. Strictly block dotfiles (except /.well-known) and internal/isolated extensions
        if (
            preg_match('#^/\\.(?!well-known(/|\$))#', \$path) === 1
            || preg_match('#\\.(?:blob|sqlite|sqlite-wal|sqlite-shm|secret|lock)\$#i', \$path) === 1
        ) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            if (strtoupper((string)(\$_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
                echo 'Forbidden';
            }
            return true;
        }

        \$method = strtoupper((string)(\$_SERVER['REQUEST_METHOD'] ?? 'GET'));

        // 2. Direct safe user/seed file in the same directory as index.php (e.g. /cd-xxxx.mp4)
        // Note: on Apache/LiteSpeed/Nginx with `RewriteCond %{REQUEST_FILENAME} !-f`, the web server
        // serves this directly in C with native 206 Partial Content without even invoking PHP.
        // This fallback guarantees identical 206 Partial Content behavior on `php -S` too.
        if (preg_match('#^/((?:cd-|up-|seed-)[a-zA-Z0-9._-]+)\$#', \$path, \$m) === 1) {
            \$fileName = \$m[1];
            if (DriveService::isSafeDirectStorageName(\$fileName)) {
                \$configuredDir = getenv('CLOUDDRIVE_DATA_DIR');
                \$dataDir = (is_string(\$configuredDir) && \$configuredDir !== '') ? rtrim(\$configuredDir, '/\\\\') : __DIR__;
                \$fullPath = Security::resolveJailedPath(\$dataDir, \$fileName);
                if (\$fullPath !== null) {
                    if (\$method !== 'GET' && \$method !== 'HEAD') {
                        http_response_code(405);
                        header('Allow: GET, HEAD');
                        header('Content-Type: text/plain; charset=utf-8');
                        echo 'Method Not Allowed';
                        return true;
                    }
                    \$ext = strtolower(pathinfo(\$fileName, PATHINFO_EXTENSION));
                    \$mime = self::DIRECT_MIME_MAP[\$ext] ?? 'application/octet-stream';
                    \$etag = '"' . md5(\$fileName . ':' . filesize(\$fullPath) . ':' . filemtime(\$fullPath)) . '"';
                    \$inm = (string)(\$_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
                    if (\$inm !== '' && trim(\$inm) === \$etag && empty(\$_SERVER['HTTP_RANGE'])) {
                        header('ETag: ' . \$etag);
                        header('Cache-Control: public, max-age=3600');
                        header('Accept-Ranges: bytes');
                        http_response_code(304);
                        return true;
                    }
                    \$res = Response::file(\$fullPath, \$mime, null, [
                        'ETag' => \$etag,
                        'Cache-Control' => 'public, max-age=3600',
                        'X-Framework' => 'ai-framework',
                        'X-Content-Type-Options' => 'nosniff',
                        'Referrer-Policy' => 'strict-origin-when-cross-origin',
                    ]);
                    \$res->send(\$method === 'HEAD');
                    return true;
                }
            }
        }

        // 3. Embedded UI static assets (/assets/*, /sw.js)
        \$meta = EmbeddedAssets::MANIFEST[\$path] ?? null;
        if (\$meta === null) {
            return false;
        }

        if (\$method !== 'GET' && \$method !== 'HEAD') {
            http_response_code(405);
            header('Allow: GET, HEAD');
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Method Not Allowed';
            return true;
        }

        header('Content-Type: ' . \$meta['mime']);
        header('Cache-Control: ' . \$meta['cache']);
        header('ETag: ' . \$meta['etag']);
        header('X-Framework: ai-framework');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Cross-Origin-Opener-Policy: same-origin');
        if (\$path === '/sw.js') {
            header('Service-Worker-Allowed: /');
        }

        \$inm = (string)(\$_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        if (\$inm !== '' && trim(\$inm) === \$meta['etag']) {
            http_response_code(304);
            return true;
        }

        header('Content-Length: ' . \$meta['length']);
        http_response_code(200);
        if (\$method === 'HEAD') {
            return true;
        }

        \$bytes = EmbeddedAssets::readBytes(\$path);
        if (\$bytes !== null) {
            echo \$bytes;
        }
        return true;
    }

    /** @return array{dataDir:string, dbPath:string, uploadsDir:string, secret:string} */
    public static function prepareEnvironment(): array
    {
        // Default data & uploads area = EXACT SAME directory as index.php (__DIR__)
        // so web servers serve safe media/documents directly with native HTTP 206 Partial Content!
        \$configuredDir = getenv('CLOUDDRIVE_DATA_DIR');
        \$dataDir = (is_string(\$configuredDir) && \$configuredDir !== '')
            ? rtrim(\$configuredDir, '/\\\\')
            : __DIR__;

        if (!is_dir(\$dataDir)) {
            @mkdir(\$dataDir, 0755, true);
        }

        \$uploadsDir = \$dataDir;
        \$dbPath = \$dataDir . DIRECTORY_SEPARATOR . '.clouddrive.sqlite';
        \$secretFile = \$dataDir . DIRECTORY_SEPARATOR . '.clouddrive.secret';
        \$lockFile = \$dataDir . DIRECTORY_SEPARATOR . '.clouddrive.lock';

        // On Apache/LiteSpeed shared hosting, auto-create hardened .htaccess if absent
        self::maybeCreateRootHtaccess();

        // Fast-path once initialized
        \$envSecret = getenv('APP_SECRET');
        \$secret = (is_string(\$envSecret) && strlen(\$envSecret) >= 32) ? \$envSecret : '';

        if (\$secret === '' && is_file(\$secretFile)) {
            \$stored = trim((string)@file_get_contents(\$secretFile));
            if (strlen(\$stored) >= 32) {
                \$secret = \$stored;
            }
        }

        \$needsInit = (\$secret === '') || !is_file(\$dbPath) || filesize(\$dbPath) === 0;
        if (\$needsInit) {
            \$lockFp = fopen(\$lockFile, 'c+');
            if (\$lockFp !== false) {
                flock(\$lockFp, LOCK_EX);
                try {
                    if (\$secret === '') {
                        if (is_file(\$secretFile)) {
                            \$stored = trim((string)@file_get_contents(\$secretFile));
                            if (strlen(\$stored) >= 32) {
                                \$secret = \$stored;
                            }
                        }
                        if (\$secret === '') {
                            \$secret = bin2hex(random_bytes(32));
                            @file_put_contents(\$secretFile, \$secret . "\\n", LOCK_EX);
                            @chmod(\$secretFile, 0600);
                        }
                    }

                    if (!is_file(\$dbPath) || filesize(\$dbPath) === 0) {
                        self::initializeDatabase(\$dbPath, \$uploadsDir);
                    }
                } finally {
                    flock(\$lockFp, LOCK_UN);
                    fclose(\$lockFp);
                }
            }
        }

        return [
            'dataDir' => \$dataDir,
            'dbPath' => \$dbPath,
            'uploadsDir' => \$uploadsDir,
            'secret' => \$secret,
        ];
    }

    private static function maybeCreateRootHtaccess(): void
    {
        if (getenv('CLOUDDRIVE_NO_HTACCESS') === '1') {
            return;
        }
        \$serverSoft = (string)(\$_SERVER['SERVER_SOFTWARE'] ?? '');
        if (stripos(\$serverSoft, 'Apache') === false && stripos(\$serverSoft, 'LiteSpeed') === false) {
            return;
        }
        \$htPath = __DIR__ . DIRECTORY_SEPARATOR . '.htaccess';
        if (is_file(\$htPath) || !is_writable(__DIR__)) {
            return;
        }
        \$self = basename(__FILE__);
        // Rental-server safe (no Options directive that triggers HTTP 500 on Sakura/Lolipop).
        // Real safe files in __DIR__ (e.g. cd-xxxx.mp4) bypass PHP via `!-f` for native HTTP 206 partial downloads,
        // while dotfiles (.clouddrive.sqlite, .clouddrive.secret, .trashed-*) and .blob files are strictly denied.
        \$rules = <<<HT
DirectoryIndex {\$self}
<FilesMatch "(^\\.(?!well-known)|\\.blob\$|\\.sqlite(-wal|-shm)?\$|\\.secret\$|\\.lock\$)">
  <IfModule mod_authz_core.c>
    Require all denied
  </IfModule>
  <IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
  </IfModule>
</FilesMatch>
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteRule ^\\.(?!well-known/) - [F,L]
  RewriteRule \\.(?:blob|sqlite|sqlite-wal|sqlite-shm|secret|lock)\$ - [F,L]
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteRule ^ {\$self} [QSA,L]
</IfModule>

HT;
        @file_put_contents(\$htPath, \$rules, LOCK_EX);
    }

    private static function initializeDatabase(string \$dbPath, string \$uploadsDir): void
    {
        \$pdo = new \\PDO('sqlite:' . \$dbPath, null, null, [
            \\PDO::ATTR_ERRMODE => \\PDO::ERRMODE_EXCEPTION,
            \\PDO::ATTR_DEFAULT_FETCH_MODE => \\PDO::FETCH_ASSOC,
        ]);
        \$pdo->exec(self::SCHEMA_SQL);
        if (getenv('CLOUDDRIVE_SKIP_SEED') === '1') {
            return;
        }
        self::seedDatabase(\$pdo, \$uploadsDir);
    }

    /** @param array{int,int,int} \$bgRgb @param array{int,int,int} \$accentRgb */
    private static function createSamplePng(string \$path, int \$w, int \$h, array \$bgRgb, array \$accentRgb, string \$label): int
    {
        if (!function_exists('imagecreatetruecolor')) {
            \$fallback = EmbeddedAssets::readBytes('/assets/og-image.png') ?? '';
            file_put_contents(\$path, \$fallback);
            return strlen(\$fallback);
        }
        \$img = imagecreatetruecolor(\$w, \$h);
        imagealphablending(\$img, true);
        imagesavealpha(\$img, true);
        \$bg = imagecolorallocate(\$img, \$bgRgb[0], \$bgRgb[1], \$bgRgb[2]);
        \$accent = imagecolorallocate(\$img, \$accentRgb[0], \$accentRgb[1], \$accentRgb[2]);
        \$white = imagecolorallocate(\$img, 255, 255, 255);
        \$card = imagecolorallocate(\$img, 248, 250, 253);
        \$ink = imagecolorallocate(\$img, 31, 31, 31);
        \$muted = imagecolorallocate(\$img, 95, 99, 104);

        imagefilledrectangle(\$img, 0, 0, \$w, \$h, \$bg);
        imagefilledrectangle(\$img, 24, 24, \$w - 24, \$h - 24, \$card);
        imagefilledrectangle(\$img, 24, 24, \$w - 24, 88, \$accent);

        \$barColors = [
            imagecolorallocate(\$img, 26, 115, 232),
            imagecolorallocate(\$img, 15, 157, 88),
            imagecolorallocate(\$img, 244, 180, 0),
            imagecolorallocate(\$img, 234, 67, 53),
        ];
        \$barW = (int)((\$w - 160) / 4) - 24;
        \$heights = [0.75, 0.58, 0.88, 0.66];
        for (\$i = 0; \$i < 4; \$i++) {
            \$x1 = 72 + \$i * (\$barW + 24);
            \$barH = (int)((\$h - 190) * \$heights[\$i]);
            \$y1 = \$h - 64 - \$barH;
            imagefilledrectangle(\$img, \$x1, \$y1, \$x1 + \$barW, \$h - 64, \$barColors[\$i]);
        }
        imagestring(\$img, 5, 44, 48, \$label, \$white);
        imagestring(\$img, 3, 44, \$h - 46, 'CloudDrive Visual Asset - 2026', \$muted);
        imagestring(\$img, 3, \$w - 170, \$h - 46, \$w . 'x' . \$h . ' PNG', \$ink);

        imagepng(\$img, \$path, 6);
        imagedestroy(\$img);
        return (int)filesize(\$path);
    }

    private static function createSamplePdf(string \$path, string \$title, string \$body): int
    {
        \$safeTitle = preg_replace('/[^\\x20-\\x7E]/', '', \$title) ?: 'CloudDrive Document';
        \$safeBody = preg_replace('/[^\\x20-\\x7E]/', '', \$body) ?: 'Generated sample PDF document.';
        \$stream = "BT /F1 18 Tf 50 750 Td ({\$safeTitle}) Tj 0 -30 Td /F1 12 Tf ({\$safeBody}) Tj ET";
        \$len = strlen(\$stream);
        \$objects = [
            "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\\n",
            "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\\n",
            "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj\\n",
            "4 0 obj << /Length {\$len} >> stream\\n{\$stream}\\nendstream endobj\\n",
            "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\\n",
        ];
        \$pdf = "%PDF-1.4\\n";
        \$offsets = [0];
        foreach (\$objects as \$obj) {
            \$offsets[] = strlen(\$pdf);
            \$pdf .= \$obj;
        }
        \$xrefPos = strlen(\$pdf);
        \$pdf .= "xref\\n0 " . (count(\$objects) + 1) . "\\n0000000000 65535 f \\n";
        for (\$i = 1; \$i <= count(\$objects); \$i++) {
            \$pdf .= sprintf("%010d 00000 n \\n", \$offsets[\$i]);
        }
        \$pdf .= "trailer << /Size " . (count(\$objects) + 1) . " /Root 1 0 R >>\\nstartxref\\n{\$xrefPos}\\n%%EOF\\n";
        file_put_contents(\$path, \$pdf);
        return strlen(\$pdf);
    }

    private static function seedDatabase(\\PDO \$pdo, string \$uploadsDir): void
    {
{$seedBody}
    }
}

// 1. Fast-path: dotfile/secret/blob 403 guard, direct Range file fallback, & embedded UI assets
if (SingleFileRuntime::tryServeStaticOrBlockDotfile()) {
    return;
}

// 2. Zero-config rental server runtime preparation (same dir as index.php, secret, SQLite auto-init)
\$env = SingleFileRuntime::prepareEnvironment();

// 3. Boot AI Custom Framework DI Container & Application
\$container = new Container();
\$container->singleton(Database::class, static fn(): Database => new Database(\$env['dbPath']));
\$container->singleton(View::class, static fn(): View => new View());
\$container->singleton(Security::class, static fn(): Security => new Security(\$env['secret']));
\$container->singleton(DriveService::class, static fn(Container \$c): DriveService => new DriveService(
    \$c->get(Database::class),
    \$env['uploadsDir']
));
\$container->singleton(SeoService::class, static fn(): SeoService => new SeoService());
\$container->singleton(PageController::class, static fn(Container \$c): PageController => new PageController(
    \$c->get(DriveService::class),
    \$c->get(SeoService::class),
    \$c->get(View::class)
));
\$container->singleton(ApiController::class, static fn(Container \$c): ApiController => new ApiController(
    \$c->get(DriveService::class)
));
\$container->singleton(MetaController::class, static fn(Container \$c): MetaController => new MetaController(
    \$c->get(DriveService::class),
    \$c->get(SeoService::class)
));

\$router = new Router();

// SSR Pages
\$router->get('/', [PageController::class, 'home']);
\$router->get('/drive/folders/{id:[0-9]+}', [PageController::class, 'folder']);
\$router->get('/drive/recent', [PageController::class, 'recent']);
\$router->get('/drive/starred', [PageController::class, 'starred']);
\$router->get('/drive/shared', [PageController::class, 'shared']);
\$router->get('/drive/trash', [PageController::class, 'trash']);
\$router->get('/drive/search', [PageController::class, 'search']);
\$router->get('/share/{token:[a-zA-Z0-9_-]+}', [PageController::class, 'share']);
\$router->get('/about', [PageController::class, 'about']);
\$router->get('/offline', [PageController::class, 'offline']);

// PWA, SEO, AEO, GEO, AIO, LLMO Endpoints
\$router->get('/manifest.webmanifest', [MetaController::class, 'manifest']);
\$router->get('/robots.txt', [MetaController::class, 'robots']);
\$router->get('/sitemap.xml', [MetaController::class, 'sitemap']);
\$router->get('/llms.txt', [MetaController::class, 'llms']);
\$router->get('/llms-full.txt', [MetaController::class, 'llmsFull']);
\$router->get('/openapi.json', [MetaController::class, 'openapi']);

// REST API
\$router->get('/api/health', [ApiController::class, 'health']);
\$router->get('/api/items', [ApiController::class, 'listItems']);
\$router->get('/api/items/{id:[0-9]+}', [ApiController::class, 'getItem']);
\$router->post('/api/folders', [ApiController::class, 'createFolder']);
\$router->post('/api/files/upload', [ApiController::class, 'uploadFile']);
\$router->patch('/api/items/{id:[0-9]+}', [ApiController::class, 'updateItem']);
\$router->delete('/api/items/{id:[0-9]+}', [ApiController::class, 'deleteItem']);
\$router->post('/api/items/{id:[0-9]+}/restore', [ApiController::class, 'restoreItem']);
\$router->post('/api/trash/empty', [ApiController::class, 'emptyTrash']);
\$router->get('/api/files/{id:[0-9]+}/download', [ApiController::class, 'downloadFile']);
\$router->get('/api/files/{id:[0-9]+}/preview', [ApiController::class, 'previewFile']);
\$router->get('/api/storage', [ApiController::class, 'storage']);

\$app = new Application(\$container, \$router);
\$app->addMiddleware(new SecurityHeadersMiddleware());
\$app->addMiddleware(new CsrfMiddleware(\$container->get(Security::class)));

\$request = Request::capture();
\$response = \$app->handle(\$request);
\$response->send(\$request->method === 'HEAD');
}

__HALT_COMPILER();
PHP;

$finalBytes = implode('', $parts) . $binaryPayload;
file_put_contents($outPath, $finalBytes);

$sizeKb = round(strlen($finalBytes) / 1024, 1);
$phpKb = round((strlen($finalBytes) - strlen($binaryPayload)) / 1024, 1);
$assetKb = round(strlen($binaryPayload) / 1024, 1);
echo "Built single-file CloudDrive (same-dir storage + HTTP 206 Range) -> {$outPath}\n";
echo "Total size: {$sizeKb} KB (PHP+Templates+CSS+Seeder: {$phpKb} KB, Binary Assets: {$assetKb} KB)\n";
