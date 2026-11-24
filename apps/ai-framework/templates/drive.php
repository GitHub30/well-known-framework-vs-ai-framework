<?php
use App\Services\UiHelper;

$isTrash = ($activeView ?? '') === 'trash';
$typeFilters = [
    'all' => 'すべて',
    'folder' => 'フォルダ',
    'document' => 'ドキュメント',
    'spreadsheet' => 'スプレッドシート',
    'pdf' => 'PDF',
    'image' => '画像',
];
?>
<div class="workspace-toolbar">
  <nav class="breadcrumb-nav" aria-label="パンくずリスト">
    <h1 class="breadcrumb-title">
      <?php if (!empty($breadcrumbs)): ?>
        <a class="breadcrumb-link" href="/">マイドライブ</a>
        <?php foreach ($breadcrumbs as $idx => $crumb): ?>
          <span class="breadcrumb-sep" aria-hidden="true">›</span>
          <?php if ($idx === count($breadcrumbs) - 1): ?>
            <span class="breadcrumb-current"><?= $e($crumb['name']) ?></span>
          <?php else: ?>
            <a class="breadcrumb-link" href="/drive/folders/<?= (int)$crumb['id'] ?>"><?= $e($crumb['name']) ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
      <?php else: ?>
        <span class="breadcrumb-current"><?= $e($headingTitle) ?></span>
      <?php endif; ?>
    </h1>
  </nav>

  <div class="toolbar-right">
    <div class="view-toggle" role="group" aria-label="表示形式の切り替え">
      <button type="button" id="view-list-btn" class="view-toggle-btn" aria-pressed="true" aria-label="リスト表示">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/></svg>
        <span>リスト</span>
      </button>
      <button type="button" id="view-grid-btn" class="view-toggle-btn" aria-pressed="false" aria-label="グリッド表示">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M4 11h5V5H4v6zm0 7h5v-6H4v6zm6 0h5v-6h-5v6zm6 0h5v-6h-5v6zm-6-7h5V5h-5v6zm6-6v6h5V5h-5z"/></svg>
        <span>グリッド</span>
      </button>
    </div>
  </div>
</div>

<div class="filter-bar">
  <div class="filter-chips" role="group" aria-label="ファイル種別フィルタ">
    <?php foreach ($typeFilters as $key => $label): ?>
      <button type="button" class="chip-btn" data-filter-type="<?= $e($key) ?>" aria-pressed="<?= ($type ?? 'all') === $key ? 'true' : 'false' ?>"><?= $e($label) ?></button>
    <?php endforeach; ?>
  </div>
  <div class="sort-controls">
    <label class="sr-only" for="sort-select">並び替え基準</label>
    <select id="sort-select" class="sort-select">
      <option value="name"<?= ($sort ?? 'name') === 'name' ? ' selected' : '' ?>>名前順</option>
      <option value="updated_at"<?= ($sort ?? '') === 'updated_at' ? ' selected' : '' ?>>最終更新日</option>
      <option value="size"<?= ($sort ?? '') === 'size' ? ' selected' : '' ?>>ファイルサイズ</option>
    </select>
    <button type="button" id="sort-dir-btn" class="chip-btn" title="並び替え順序を切り替え"><?= ($dir ?? 'asc') === 'desc' ? '降順 ↓' : '昇順 ↑' ?></button>
  </div>
</div>

<?php if ($isTrash): ?>
<div class="trash-banner">
  <span>ゴミ箱内のアイテムは復元または完全に削除できます。</span>
  <button type="button" id="empty-trash-btn" class="btn-secondary">ゴミ箱を空にする</button>
</div>
<?php endif; ?>

<div id="selection-bar" class="selection-bar" role="toolbar" aria-label="選択アイテムの操作">
  <span id="selection-label" class="selection-label">選択中</span>
  <button type="button" id="sel-download-btn" class="action-pill-btn">ダウンロード</button>
  <button type="button" id="sel-star-btn" class="action-pill-btn">☆ スターを付ける</button>
  <button type="button" id="sel-share-btn" class="action-pill-btn">共有</button>
  <button type="button" id="sel-rename-btn" class="action-pill-btn">名前を変更</button>
  <button type="button" id="sel-move-btn" class="action-pill-btn">移動</button>
  <button type="button" id="sel-restore-btn" class="action-pill-btn hidden">復元する</button>
  <button type="button" id="sel-trash-btn" class="action-pill-btn danger">ゴミ箱に移動</button>
  <button type="button" id="sel-clear-btn" class="action-pill-btn">選択解除</button>
</div>

<div class="files-area">
  <div id="files-list-wrap" class="<?= empty($items) ? 'hidden' : '' ?>">
    <table class="drive-table">
      <thead>
        <tr>
          <th scope="col" class="col-name">名前</th>
          <th scope="col" class="col-owner">オーナー</th>
          <th scope="col" class="col-updated">最終更新</th>
          <th scope="col" class="col-size">ファイルサイズ</th>
          <th scope="col" class="col-actions"><span class="sr-only">操作</span></th>
        </tr>
      </thead>
      <tbody id="files-tbody">
        <?php foreach ($items as $item):
          $isFolder = (int)$item['is_folder'] === 1;
          $isTrashed = (int)$item['is_trashed'] === 1;
          $href = $isTrashed ? '#trash-' . (int)$item['id'] : ($isFolder ? '/drive/folders/' . (int)$item['id'] : '#preview-' . (int)$item['id']);
          $subText = UiHelper::formatDate($item['updated_at']) . ' ・ ' . ($isFolder ? 'フォルダ' : UiHelper::formatBytes((int)$item['size_bytes'], false));
        ?>
        <tr class="drive-row" data-item-id="<?= (int)$item['id'] ?>" data-is-folder="<?= $isFolder ? 1 : 0 ?>" data-is-trashed="<?= $isTrashed ? 1 : 0 ?>">
          <td class="col-name">
            <div class="item-name-cell">
              <?= UiHelper::itemIconSvg($item) ?>
              <div class="item-text-stack">
                <a class="item-link" href="<?= $e($href) ?>" data-action="open" data-item-id="<?= (int)$item['id'] ?>" data-is-trashed="<?= $isTrashed ? 1 : 0 ?>">
                  <?= $e($item['name']) ?>
                  <?php if ((int)$item['is_starred'] === 1): ?>
                    <span class="badge-inline badge-star" title="スター付き" aria-label="スター付き">★</span>
                  <?php endif; ?>
                  <?php if ((int)$item['is_shared'] === 1): ?>
                    <span class="badge-inline" title="共有中" aria-label="共有中"><svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg></span>
                  <?php endif; ?>
                </a>
                <span class="item-sub-mobile"><?= $e($subText) ?></span>
              </div>
            </div>
          </td>
          <td class="col-owner"><?= $e($item['owner_name']) ?></td>
          <td class="col-updated"><?= $e(UiHelper::formatDate($item['updated_at'])) ?></td>
          <td class="col-size"><?= $e(UiHelper::formatBytes((int)$item['size_bytes'], $isFolder)) ?></td>
          <td class="col-actions row-actions-cell">
            <button type="button" class="kebab-btn" data-action="menu" data-item-id="<?= (int)$item['id'] ?>" aria-label="<?= $e($item['name']) ?> の操作メニュー">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div id="files-grid-wrap" class="hidden"></div>

  <div id="empty-state" class="empty-state<?= empty($items) ? '' : ' hidden' ?>">
    <svg width="64" height="64" viewBox="0 0 24 24" fill="#c4c7c5" aria-hidden="true"><path d="M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>
    <h2>表示するアイテムがありません</h2>
    <p>「+ 新規」ボタンまたはドラッグ＆ドロップでファイルを追加できます。</p>
  </div>
</div>

<div id="drop-overlay" class="drop-overlay" aria-hidden="true">
  <svg width="48" height="48" viewBox="0 0 24 24" fill="#0b57d0"><path d="M19.35 10.04A7.49 7.49 0 0 0 12 4C9.11 4 6.6 5.64 5.35 8.04A5.994 5.994 0 0 0 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
  <p>ここにドロップしてアップロード</p>
</div>

<footer class="semantic-footer">
  <span>CloudDrive — Google Drive準拠の高速PHP 8.3クラウドストレージ（稼働中: <?= $e($frameworkLabel) ?>）</span>
  <div class="semantic-links">
    <a href="/about">仕様・FAQ</a>
    <a href="/llms.txt">llms.txt</a>
    <a href="/llms-full.txt">llms-full.txt</a>
    <a href="/openapi.json">OpenAPI 3.1</a>
    <a href="/sitemap.xml">Sitemap</a>
  </div>
</footer>
