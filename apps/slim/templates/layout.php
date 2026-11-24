<?php
use App\Services\UiHelper;

$usedBytes = (int)($storage['used_bytes'] ?? 0);
$quotaBytes = (int)($storage['quota_bytes'] ?? 16106127360);
$pct = min(100, max($usedBytes > 0 ? 1 : 0, ($usedBytes / max(1, $quotaBytes)) * 100));
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $e($meta['title']) ?></title>
  <meta name="description" content="<?= $e($meta['description']) ?>">
  <meta name="theme-color" content="#f8fafd">
  <meta name="csrf-token" content="<?= $e($csrfToken) ?>">
  <meta name="robots" content="<?= $e($meta['robots'] ?? 'index, follow') ?>">
  <link rel="canonical" href="<?= $e($meta['canonical']) ?>">
  <link rel="alternate" hreflang="ja" href="<?= $e($meta['canonical']) ?>">
  <link rel="alternate" hreflang="x-default" href="<?= $e($meta['canonical']) ?>">
  <link rel="alternate" type="text/markdown" href="/llms.txt" title="LLMs Documentation Index">
  <link rel="manifest" href="/manifest.webmanifest">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link rel="apple-touch-icon" href="/assets/icon-192.png">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="CloudDrive">
  <meta property="og:locale" content="ja_JP">
  <meta property="og:title" content="<?= $e($meta['title']) ?>">
  <meta property="og:description" content="<?= $e($meta['description']) ?>">
  <meta property="og:url" content="<?= $e($meta['canonical']) ?>">
  <meta property="og:image" content="<?= $e($meta['ogImage']) ?>">
  <meta property="og:image:alt" content="<?= $e($meta['ogImageAlt'] ?? 'CloudDrive') ?>">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= $e($meta['title']) ?>">
  <meta name="twitter:description" content="<?= $e($meta['description']) ?>">
  <meta name="twitter:image" content="<?= $e($meta['ogImage']) ?>">
  <meta name="twitter:image:alt" content="<?= $e($meta['ogImageAlt'] ?? 'CloudDrive') ?>">
  <style><?= UiHelper::inlineCss() ?></style>
  <script type="application/ld+json"><?= $jsonSafe($meta['jsonLd']) ?></script>
</head>
<body>
  <a class="skip-link" href="#main-content">メインコンテンツへスキップ</a>

  <div class="app-shell">
    <header class="app-header">
      <div class="brand-area">
        <button type="button" id="menu-toggle-btn" class="menu-toggle-btn" aria-label="ナビゲーションメニューを開く" aria-controls="app-sidebar" aria-expanded="false">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg>
        </button>
        <a class="brand-link" href="/" aria-label="Drive ホームへ移動">
          <svg width="34" height="34" viewBox="0 0 64 64" aria-hidden="true">
            <path d="M23.5 11h17l15.5 27-8.5 14.5-15.5-27L23.5 11z" fill="#fbbc04"/>
            <path d="M8 38l8.5 14.5h31L56 38H24.5L8 38z" fill="#1a73e8"/>
            <path d="M23.5 11L8 38l8.5 14.5L32 25.5 23.5 11z" fill="#34a853"/>
            <path d="M24.5 38l-8 14.5h15.5L24.5 38z" fill="#1967d2"/>
          </svg>
          <span class="brand-name">Drive</span>
        </a>
      </div>

      <form id="search-form" class="search-form" action="/drive/search" method="get" role="search">
        <span class="search-icon" aria-hidden="true">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
        </span>
        <label class="sr-only" for="search-input">ドライブを検索</label>
        <input id="search-input" class="search-input" type="search" name="q" value="<?= $e($q ?? '') ?>" placeholder="ドライブを検索" autocomplete="off">
        <button type="button" id="search-clear-btn" class="search-clear-btn" aria-label="検索語句をクリア">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        </button>
      </form>

      <div class="header-actions">
        <span class="fw-badge" title="稼働中のPHPフレームワーク"><span class="fw-dot" aria-hidden="true"></span><?= $e($frameworkLabel) ?></span>
        <a class="icon-btn hide-mobile" href="/about" aria-label="CloudDriveの仕様とヘルプ">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M11 18h2v-2h-2v2zm1-16C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm0-14c-2.21 0-4 1.79-4 4h2c0-1.1.9-2 2-2s2 .9 2 2c0 2-3 1.75-3 5h2c0-2.25 3-2.5 3-5 0-2.21-1.79-4-4-4z"/></svg>
        </a>
        <button type="button" id="details-toggle-btn" class="icon-btn" aria-label="詳細パネルを切り替え" aria-pressed="false">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M11 7h2v2h-2zm0 4h2v6h-2zm1-9C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z"/></svg>
        </button>
        <div class="user-avatar" role="img" title="山田 太郎" aria-label="ユーザー 山田 太郎">山</div>
      </div>
    </header>

    <div id="drawer-scrim" class="drawer-scrim" aria-hidden="true"></div>

    <aside id="app-sidebar" class="app-sidebar" aria-label="ドライブナビゲーション">
      <div class="new-btn-wrap">
        <button type="button" id="new-btn" class="new-btn" aria-haspopup="menu" aria-expanded="false" aria-controls="new-menu">
          <svg width="24" height="24" viewBox="0 0 36 36" aria-hidden="true">
            <path fill="#34A853" d="M16 16v14h4V20z"/>
            <path fill="#4285F4" d="M30 16H20l-4 4h14z"/>
            <path fill="#FBBC05" d="M6 16v4h10l4-4z"/>
            <path fill="#EA4335" d="M20 16V6h-4v14z"/>
          </svg>
          <span>新規</span>
        </button>
      </div>

      <nav aria-label="メインナビゲーション">
        <ul class="nav-list">
          <li>
            <a class="nav-link" href="/"<?= ($activeView ?? '') === 'my-drive' ? ' aria-current="page"' : '' ?>>
              <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19 2H5c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 18H5V4h14v16zM8 14h8v2H8zm0-4h8v2H8zm0-4h5v2H8z"/></svg>
              <span>マイドライブ</span>
            </a>
          </li>
          <li>
            <a class="nav-link" href="/drive/shared"<?= ($activeView ?? '') === 'shared' ? ' aria-current="page"' : '' ?>>
              <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
              <span>共有アイテム</span>
            </a>
          </li>
          <li>
            <a class="nav-link" href="/drive/recent"<?= ($activeView ?? '') === 'recent' ? ' aria-current="page"' : '' ?>>
              <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>
              <span>最近使用したアイテム</span>
            </a>
          </li>
          <li>
            <a class="nav-link" href="/drive/starred"<?= ($activeView ?? '') === 'starred' ? ' aria-current="page"' : '' ?>>
              <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>
              <span>スター付き</span>
            </a>
          </li>
          <li>
            <a class="nav-link" href="/drive/trash"<?= ($activeView ?? '') === 'trash' ? ' aria-current="page"' : '' ?>>
              <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
              <span>ゴミ箱</span>
            </a>
          </li>
        </ul>
      </nav>

      <hr class="sidebar-divider">

      <div class="storage-box">
        <div class="storage-header">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19.35 10.04A7.49 7.49 0 0 0 12 4C9.11 4 6.6 5.64 5.35 8.04A5.994 5.994 0 0 0 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96z"/></svg>
          <span>保存容量</span>
        </div>
        <div class="storage-bar-track" role="progressbar" aria-label="保存容量の使用率" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)round($pct) ?>">
          <div id="storage-bar-fill" class="storage-bar-fill" style="width:<?= number_format($pct, 1) ?>%"></div>
        </div>
        <div id="storage-text" class="storage-text"><?= $e(UiHelper::formatBytes($usedBytes, false)) ?> / 15 GB を使用中</div>
        <a class="storage-cta" href="/about">仕様・FAQを見る</a>
      </div>
    </aside>

    <div class="workspace-container">
      <main id="main-content" class="workspace-main">
        <?= $content ?>
      </main>

      <aside id="details-panel" class="details-panel" aria-label="アイテムの詳細とアクティビティ">
        <div class="details-header">
          <h2>詳細</h2>
          <button type="button" id="details-close-btn" class="icon-btn" aria-label="詳細パネルを閉じる">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
          </button>
        </div>
        <div id="details-content">
          <p>ファイルまたはフォルダを選択すると、詳細情報とアクティビティ履歴が表示されます。</p>
        </div>
      </aside>
    </div>
  </div>

  <nav class="mobile-bottom-nav" aria-label="モバイルナビゲーション">
    <a class="mobile-nav-item" href="/"<?= ($activeView ?? '') === 'my-drive' ? ' aria-current="page"' : '' ?>>
      <span class="mobile-nav-pill"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg></span>
      <span>マイドライブ</span>
    </a>
    <a class="mobile-nav-item" href="/drive/starred"<?= ($activeView ?? '') === 'starred' ? ' aria-current="page"' : '' ?>>
      <span class="mobile-nav-pill"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg></span>
      <span>スター付き</span>
    </a>
    <a class="mobile-nav-item" href="/drive/shared"<?= ($activeView ?? '') === 'shared' ? ' aria-current="page"' : '' ?>>
      <span class="mobile-nav-pill"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg></span>
      <span>共有</span>
    </a>
    <a class="mobile-nav-item" href="/drive/recent"<?= ($activeView ?? '') === 'recent' ? ' aria-current="page"' : '' ?>>
      <span class="mobile-nav-pill"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg></span>
      <span>最近</span>
    </a>
  </nav>

  <button type="button" id="mobile-fab" class="mobile-fab" aria-label="新規作成またはアップロード">
    <svg width="26" height="26" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
  </button>

  <div id="new-menu" class="menu-popover" role="menu" aria-label="新規作成メニュー">
    <button type="button" id="menu-new-folder" class="menu-item" role="menuitem">新しいフォルダ</button>
    <button type="button" id="menu-upload-file" class="menu-item" role="menuitem">ファイルのアップロード</button>
  </div>
  <div id="item-menu" class="menu-popover" role="menu" aria-label="アイテム操作メニュー"></div>
  <input id="file-upload-input" class="sr-only" type="file" multiple aria-label="アップロードするファイルを選択">

  <div id="modal-backdrop" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <div class="modal-dialog">
      <h2 id="modal-title">ダイアログ</h2>
      <div id="modal-body"></div>
      <div id="modal-footer" class="modal-footer"></div>
    </div>
  </div>

  <div id="preview-backdrop" class="preview-backdrop" role="dialog" aria-modal="true" aria-labelledby="preview-title">
    <div class="preview-topbar">
      <div class="preview-title-wrap">
        <button type="button" id="preview-close-btn" class="icon-btn" aria-label="プレビューを閉じる">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
        </button>
        <h2 id="preview-title" class="preview-title">ファイルプレビュー</h2>
      </div>
      <div>
        <a id="preview-download-link" class="icon-btn" href="#" aria-label="ファイルをダウンロード">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
        </a>
      </div>
    </div>
    <div id="preview-body" class="preview-body"></div>
  </div>

  <div id="toast-container" class="toast-container" aria-live="polite"></div>

  <?php if (!empty($driveState)): ?>
  <script id="drive-state" type="application/json"><?= $jsonSafe($driveState) ?></script>
  <?php endif; ?>
  <script src="/assets/app.js?v=2" defer></script>
</body>
</html>
