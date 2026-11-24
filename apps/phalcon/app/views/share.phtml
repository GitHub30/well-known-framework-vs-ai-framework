<?php
use App\Services\UiHelper;

$isFolder = (int)$item['is_folder'] === 1;
$isImg = !$isFolder && in_array(strtolower((string)$item['mime_type']), ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true);
?>
<article class="content-page">
  <h1><?= $e($item['name']) ?></h1>
  <p><?= $e($item['description'] !== '' ? $item['description'] : 'CloudDriveで公開共有されているアイテムです。') ?></p>

  <dl class="details-dl">
    <dt>種類</dt><dd><?= $isFolder ? '共有フォルダ' : $e($item['mime_type']) ?></dd>
    <dt>サイズ</dt><dd><?= $e(UiHelper::formatBytes((int)$item['size_bytes'], $isFolder)) ?></dd>
    <dt>オーナー</dt><dd><?= $e($item['owner_name']) ?></dd>
    <dt>最終更新</dt><dd><?= $e(UiHelper::formatDate($item['updated_at'])) ?></dd>
  </dl>

  <?php if (!$isFolder): ?>
    <?php if ($isImg): ?>
      <p style="margin-top:16px"><img src="/api/files/<?= (int)$item['id'] ?>/preview" alt="<?= $e($item['name']) ?>" width="640" height="360" style="max-width:100%;height:auto;border-radius:8px;border:1px solid #dde3ea"></p>
    <?php endif; ?>
    <p style="margin-top:20px;display:flex;flex-wrap:wrap;gap:10px">
      <a class="btn-primary" style="display:inline-flex;align-items:center" href="/api/files/<?= (int)$item['id'] ?>/download">このファイルをダウンロード</a>
      <a class="btn-secondary" style="display:inline-flex;align-items:center" href="/">マイドライブへ戻る</a>
    </p>
  <?php else: ?>
    <h2>フォルダ内のアイテム</h2>
    <?php if (empty($children)): ?>
      <p>この共有フォルダは空です。</p>
    <?php else: ?>
      <table class="spec-table">
        <thead>
          <tr><th scope="col">名前</th><th scope="col">更新日</th><th scope="col">サイズ</th><th scope="col">操作</th></tr>
        </thead>
        <tbody>
          <?php foreach ($children as $ch):
            $chFolder = (int)$ch['is_folder'] === 1;
          ?>
            <tr>
              <td><?= $e($ch['name']) ?></td>
              <td><?= $e(UiHelper::formatDate($ch['updated_at'])) ?></td>
              <td><?= $e(UiHelper::formatBytes((int)$ch['size_bytes'], $chFolder)) ?></td>
              <td>
                <?php if (!$chFolder): ?>
                  <a href="/api/files/<?= (int)$ch['id'] ?>/download">ダウンロード</a>
                <?php else: ?>
                  フォルダ
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <p style="margin-top:16px"><a class="btn-secondary" style="display:inline-flex;align-items:center" href="/">マイドライブへ戻る</a></p>
  <?php endif; ?>
</article>
