<?php
declare(strict_types=1);

/**
 * Deterministic SQLite seeder & sample file generator.
 * Usage: php shared/database/seed.php <db_path> <uploads_dir>
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php seed.php <db_path> <uploads_dir>\n");
    exit(1);
}

$dbPath = $argv[1];
$uploadsDir = $argv[2];
$schemaPath = __DIR__ . '/schema.sql';

@mkdir(dirname($dbPath), 0775, true);
@mkdir($uploadsDir, 0775, true);

// Clean existing db & uploads for 100% deterministic re-seeding
foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $f) {
    if (file_exists($f)) {
        unlink($f);
    }
}
foreach (glob($uploadsDir . '/*') ?: [] as $f) {
    if (is_file($f)) {
        unlink($f);
    }
}

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$schema = file_get_contents($schemaPath);
$pdo->exec($schema);

// Helper to generate real PNG preview images using GD
function createSamplePng(string $path, int $w, int $h, array $bgRgb, array $accentRgb, string $label): int
{
    $img = imagecreatetruecolor($w, $h);
    imagealphablending($img, true);
    imagesavealpha($img, true);
    $bg = imagecolorallocate($img, $bgRgb[0], $bgRgb[1], $bgRgb[2]);
    $accent = imagecolorallocate($img, $accentRgb[0], $accentRgb[1], $accentRgb[2]);
    $white = imagecolorallocate($img, 255, 255, 255);
    $card = imagecolorallocate($img, 248, 250, 253);
    $ink = imagecolorallocate($img, 31, 31, 31);
    $muted = imagecolorallocate($img, 95, 99, 104);

    imagefilledrectangle($img, 0, 0, $w, $h, $bg);
    imagefilledrectangle($img, 24, 24, $w - 24, $h - 24, $card);
    imagefilledrectangle($img, 24, 24, $w - 24, 88, $accent);

    // Draw clean geometric chart bars / UI preview blocks
    $barColors = [
        imagecolorallocate($img, 26, 115, 232),
        imagecolorallocate($img, 15, 157, 88),
        imagecolorallocate($img, 244, 180, 0),
        imagecolorallocate($img, 234, 67, 53),
    ];
    $barW = (int)(($w - 160) / 4) - 24;
    $heights = [0.75, 0.58, 0.88, 0.66];
    for ($i = 0; $i < 4; $i++) {
        $x1 = 72 + $i * ($barW + 24);
        $barH = (int)(($h - 190) * $heights[$i]);
        $y1 = $h - 64 - $barH;
        imagefilledrectangle($img, $x1, $y1, $x1 + $barW, $h - 64, $barColors[$i]);
    }
    imagestring($img, 5, 44, 48, $label, $white);
    imagestring($img, 3, 44, $h - 46, 'CloudDrive Visual Asset - 2026', $muted);
    imagestring($img, 3, $w - 170, $h - 46, $w . 'x' . $h . ' PNG', $ink);

    imagepng($img, $path, 6);
    imagedestroy($img);
    return (int)filesize($path);
}

// Helper to create a valid minimal PDF file
function createSamplePdf(string $path, string $title, string $body): int
{
    $safeTitle = preg_replace('/[^\x20-\x7E]/', '', $title) ?: 'CloudDrive Document';
    $safeBody = preg_replace('/[^\x20-\x7E]/', '', $body) ?: 'Generated sample PDF document.';
    $stream = "BT /F1 18 Tf 50 750 Td ({$safeTitle}) Tj 0 -30 Td /F1 12 Tf ({$safeBody}) Tj ET";
    $len = strlen($stream);
    $objects = [
        "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n",
        "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n",
        "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj\n",
        "4 0 obj << /Length {$len} >> stream\n{$stream}\nendstream endobj\n",
        "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n",
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $obj) {
        $offsets[] = strlen($pdf);
        $pdf .= $obj;
    }
    $xrefPos = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer << /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF\n";
    file_put_contents($path, $pdf);
    return strlen($pdf);
}

$insertItem = $pdo->prepare(
    'INSERT INTO items (parent_id, name, is_folder, mime_type, extension, size_bytes, storage_name, owner_name, owner_email, description, is_starred, is_shared, share_token, is_trashed, trashed_at, last_opened_at, created_at, updated_at)
     VALUES (:parent_id, :name, :is_folder, :mime_type, :extension, :size_bytes, :storage_name, :owner_name, :owner_email, :description, :is_starred, :is_shared, :share_token, :is_trashed, :trashed_at, :last_opened_at, :created_at, :updated_at)'
);

$insertActivity = $pdo->prepare(
    'INSERT INTO activities (item_id, action, actor_name, detail, created_at)
     VALUES (:item_id, :action, :actor_name, :detail, :created_at)'
);

$pdo->beginTransaction();

// 1. Root Folders (IDs 1..5)
$rootFolders = [
    ['name' => 'プロジェクト企画', 'owner' => '山田 太郎', 'email' => 'taro.yamada@clouddrive.example', 'desc' => '2026年度の主要プロダクト企画およびロードマップ資料', 'starred' => 1, 'shared' => 1, 'token' => 'project-planning-2026', 'updated' => '2026-11-24T08:45:00Z'],
    ['name' => 'デザインアセット', 'owner' => '佐藤 花子', 'email' => 'hanako.sato@clouddrive.example', 'desc' => 'UIガイドライン、アイコン、画面モックアップ一式', 'starred' => 0, 'shared' => 1, 'token' => 'design-assets-2026', 'updated' => '2026-11-23T17:20:00Z'],
    ['name' => '議事録・ミーティング', 'owner' => '山田 太郎', 'email' => 'taro.yamada@clouddrive.example', 'desc' => '定例会議およびアーキテクチャレビューの議事録', 'starred' => 0, 'shared' => 0, 'token' => null, 'updated' => '2026-11-22T14:10:00Z'],
    ['name' => '財務・レポート', 'owner' => '鈴木 一郎', 'email' => 'ichiro.suzuki@clouddrive.example', 'desc' => '月次KPI、クラウドインフラ費用および予算実績CSV', 'starred' => 0, 'shared' => 0, 'token' => null, 'updated' => '2026-11-21T11:30:00Z'],
    ['name' => '技術アーキテクチャ', 'owner' => '山田 太郎', 'email' => 'taro.yamada@clouddrive.example', 'desc' => 'PHPフレームワーク比較検証、セキュリティ監査、ベンチマーク仕様', 'starred' => 1, 'shared' => 1, 'token' => 'tech-architecture-2026', 'updated' => '2026-11-24T09:15:00Z'],
];

$folderIds = [];
foreach ($rootFolders as $idx => $f) {
    $insertItem->execute([
        ':parent_id' => null,
        ':name' => $f['name'],
        ':is_folder' => 1,
        ':mime_type' => 'application/vnd.clouddrive.folder',
        ':extension' => '',
        ':size_bytes' => 0,
        ':storage_name' => null,
        ':owner_name' => $f['owner'],
        ':owner_email' => $f['email'],
        ':description' => $f['desc'],
        ':is_starred' => $f['starred'],
        ':is_shared' => $f['shared'],
        ':share_token' => $f['token'],
        ':is_trashed' => 0,
        ':trashed_at' => null,
        ':last_opened_at' => $f['updated'],
        ':created_at' => '2026-10-01T09:00:00Z',
        ':updated_at' => $f['updated'],
    ]);
    $id = (int)$pdo->lastInsertId();
    $folderIds[$f['name']] = $id;
    $insertActivity->execute([
        ':item_id' => $id,
        ':action' => 'created',
        ':actor_name' => $f['owner'],
        ':detail' => "フォルダ「{$f['name']}」を作成しました",
        ':created_at' => '2026-10-01T09:00:00Z',
    ]);
}

// 2. Subfolders (IDs 6..11)
$subFolders = [
    ['parent' => 'プロジェクト企画', 'name' => '2026年Q4ロードマップ', 'desc' => 'Q4マイルストーンとスプリント計画', 'updated' => '2026-11-24T07:30:00Z'],
    ['parent' => 'プロジェクト企画', 'name' => '競合分析・調査', 'desc' => 'クラウドストレージUX比較メモ', 'updated' => '2026-11-20T16:00:00Z'],
    ['parent' => 'デザインアセット', 'name' => 'Material 3 コンポーネント', 'desc' => 'ボタン、カード、ダイアログのUI仕様', 'updated' => '2026-11-23T15:10:00Z'],
    ['parent' => '技術アーキテクチャ', 'name' => 'PHPフレームワーク検証', 'desc' => 'AI自作FW・Laravel・Phalcon・Slimの設計資料', 'updated' => '2026-11-24T09:00:00Z'],
    ['parent' => '技術アーキテクチャ', 'name' => 'セキュリティ監査', 'desc' => '敵対的レビューおよび脆弱性チェックリスト', 'updated' => '2026-11-24T08:10:00Z'],
    ['parent' => '議事録・ミーティング', 'name' => '2026年11月定例', 'desc' => '11月分の週次議事録アーカイブ', 'updated' => '2026-11-22T10:00:00Z'],
];

foreach ($subFolders as $sf) {
    $insertItem->execute([
        ':parent_id' => $folderIds[$sf['parent']],
        ':name' => $sf['name'],
        ':is_folder' => 1,
        ':mime_type' => 'application/vnd.clouddrive.folder',
        ':extension' => '',
        ':size_bytes' => 0,
        ':storage_name' => null,
        ':owner_name' => '山田 太郎',
        ':owner_email' => 'taro.yamada@clouddrive.example',
        ':description' => $sf['desc'],
        ':is_starred' => 0,
        ':is_shared' => 0,
        ':share_token' => null,
        ':is_trashed' => 0,
        ':trashed_at' => null,
        ':last_opened_at' => $sf['updated'],
        ':created_at' => '2026-10-15T10:00:00Z',
        ':updated_at' => $sf['updated'],
    ]);
    $folderIds[$sf['name']] = (int)$pdo->lastInsertId();
}

// 3. Seed real files on disk & database
$filesSpec = [
    // Root files (shown immediately on /)
    [
        'parent' => null,
        'name' => '2026年Q4_プロダクト企画書.md',
        'mime' => 'text/markdown',
        'ext' => 'md',
        'storage' => 'seed-q4-product-plan.md',
        'content' => "# 2026年Q4 プロダクト企画書\n\n## 概要\nCloudDrive は高速・軽量・PWA完全対応のクラウドファイル管理プラットフォームです。\n\n## 目標KPI\n- Lighthouse 全4項目（Performance / Accessibility / Best Practices / SEO）100点\n- モバイル・デスクトップ両環境でのゼロCLS描画\n- PHP 8.3 フレームワーク間での公平な性能ベンチマーク検証\n\n## 主要機能\n1. マイドライブ階層管理・パンくずナビゲーション\n2. ドラッグ＆ドロップ対応ファイルアップロード\n3. リアルタイム検索・種別フィルタ・並び替え\n4. 共有リンク発行・スター・ゴミ箱復元\n",
        'owner' => '山田 太郎',
        'email' => 'taro.yamada@clouddrive.example',
        'desc' => '2026年Q4の最終承認済みプロダクト企画書',
        'starred' => 1,
        'shared' => 1,
        'token' => 'q4-product-plan-2026',
        'updated' => '2026-11-24T09:10:00Z',
        'opened' => '2026-11-24T09:12:00Z',
    ],
    [
        'parent' => null,
        'name' => 'PHPフレームワーク性能比較レポート.pdf',
        'mime' => 'application/pdf',
        'ext' => 'pdf',
        'storage' => 'seed-php-framework-benchmark.pdf',
        'type' => 'pdf',
        'pdf_title' => 'PHP 8.3 Framework Benchmark Specification',
        'pdf_body' => 'Comparing AI Custom Framework, Laravel, Phalcon 5, and Slim 4 under identical Nginx + PHP-FPM 8.3.',
        'owner' => '山田 太郎',
        'email' => 'taro.yamada@clouddrive.example',
        'desc' => 'AIオレオレFW・Laravel・Phalcon・Slimの統一ベンチマーク評価仕様書',
        'starred' => 1,
        'shared' => 1,
        'token' => 'php-benchmark-spec-pdf',
        'updated' => '2026-11-24T08:55:00Z',
        'opened' => '2026-11-24T09:05:00Z',
    ],
    [
        'parent' => null,
        'name' => 'クラウドストレージ利用統計_2026年11月.csv',
        'mime' => 'text/csv',
        'ext' => 'csv',
        'storage' => 'seed-storage-metrics-202611.csv',
        'content' => "日付,アクティブユーザー,アップロード件数,平均応答時間ms,Lighthouseスコア\n2026-11-18,1240,8420,4.2,100\n2026-11-19,1310,9105,3.9,100\n2026-11-20,1285,8890,4.1,100\n2026-11-21,1420,10230,3.8,100\n2026-11-22,1390,9870,3.7,100\n2026-11-23,1510,11240,3.5,100\n2026-11-24,1625,12480,3.4,100\n",
        'owner' => '鈴木 一郎',
        'email' => 'ichiro.suzuki@clouddrive.example',
        'desc' => '2026年11月の日次利用統計およびパフォーマンス集計データ',
        'starred' => 0,
        'shared' => 1,
        'token' => 'storage-metrics-csv-2026',
        'updated' => '2026-11-24T08:30:00Z',
        'opened' => '2026-11-24T08:40:00Z',
    ],
    [
        'parent' => null,
        'name' => 'UIパフォーマンス指標ダッシュボード.png',
        'mime' => 'image/png',
        'ext' => 'png',
        'storage' => 'seed-ui-performance-chart.png',
        'type' => 'png',
        'png_bg' => [232, 240, 254],
        'png_accent' => [26, 115, 232],
        'png_label' => 'CloudDrive Core Web Vitals & Throughput',
        'owner' => '佐藤 花子',
        'email' => 'hanako.sato@clouddrive.example',
        'desc' => 'Core Web Vitals とフレームワーク別スループット比較グラフィック',
        'starred' => 1,
        'shared' => 0,
        'token' => null,
        'updated' => '2026-11-23T19:40:00Z',
        'opened' => '2026-11-24T08:20:00Z',
    ],
    [
        'parent' => null,
        'name' => 'OpenAPI_スキーマ設定.json',
        'mime' => 'application/json',
        'ext' => 'json',
        'storage' => 'seed-api-config.json',
        'content' => json_encode([
            'service' => 'CloudDrive',
            'version' => '1.0.0',
            'standards' => ['PWA', 'SEO', 'AEO', 'GEO', 'AIO', 'LLMO'],
            'quota_gb' => 15,
            'max_upload_mb' => 20,
            'frameworks' => ['ai-framework', 'laravel', 'phalcon', 'slim'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n",
        'owner' => '山田 太郎',
        'email' => 'taro.yamada@clouddrive.example',
        'desc' => 'AIO・LLMO連携向けのAPIメタデータ定義JSON',
        'starred' => 0,
        'shared' => 0,
        'token' => null,
        'updated' => '2026-11-23T16:15:00Z',
        'opened' => '2026-11-23T18:00:00Z',
    ],
    [
        'parent' => null,
        'name' => 'リリースチェックリスト_v1.0.txt',
        'mime' => 'text/plain',
        'ext' => 'txt',
        'storage' => 'seed-release-checklist.txt',
        'content' => "CloudDrive v1.0 リリースチェックリスト\n=====================================\n[x] 全4フレームワークの完全パリティ確認\n[x] Lighthouse Mobile / Desktop 全100点達成\n[x] PWA Service Worker オフライン動作確認\n[x] robots.txt / sitemap.xml / llms.txt / openapi.json 検証\n[x] 敵対的セキュリティレビュー完了\n",
        'owner' => '高橋 健太',
        'email' => 'kenta.takahashi@clouddrive.example',
        'desc' => '本番リリース前の最終確認項目',
        'starred' => 0,
        'shared' => 1,
        'token' => 'release-checklist-v1',
        'updated' => '2026-11-23T13:50:00Z',
        'opened' => '2026-11-23T14:10:00Z',
    ],
    [
        'parent' => null,
        'name' => 'システム構成図_Nginx_PHPFPM.png',
        'mime' => 'image/png',
        'ext' => 'png',
        'storage' => 'seed-system-architecture.png',
        'type' => 'png',
        'png_bg' => [230, 244, 234],
        'png_accent' => [15, 157, 88],
        'png_label' => 'Nginx + PHP-FPM 8.3 Isolated Pool Topology',
        'owner' => '高橋 健太',
        'email' => 'kenta.takahashi@clouddrive.example',
        'desc' => 'ベンチマーク用Nginx＋PHP-FPM独立プール構成図',
        'starred' => 0,
        'shared' => 0,
        'token' => null,
        'updated' => '2026-11-22T18:05:00Z',
        'opened' => '2026-11-22T18:30:00Z',
    ],
    [
        'parent' => null,
        'name' => 'SEO_AEO_GEO_LLMO最適化ガイド.md',
        'mime' => 'text/markdown',
        'ext' => 'md',
        'storage' => 'seed-seo-llmo-guide.md',
        'content' => "# SEO・AEO・GEO・AIO・LLMO 最適化設計ガイド\n\n- **SEO**: セマンティックHTML、正規URL、XMLサイトマップ、Core Web Vitals 100点\n- **AEO**: 質問形式の見出し + 40〜60語以内の直接回答 + FAQPage JSON-LD\n- **GEO**: 一次データ・定量仕様・構造化エンティティによる生成AI引用最適化\n- **AIO**: OpenAPI 3.1・AIクローラ明示許可・機械可読なメタデータ\n- **LLMO**: `/llms.txt` および `/llms-full.txt` による構造化Markdownドキュメント提供\n",
        'owner' => '佐藤 花子',
        'email' => 'hanako.sato@clouddrive.example',
        'desc' => '検索エンジンおよび生成AI最適化の実装リファレンス',
        'starred' => 1,
        'shared' => 1,
        'token' => 'seo-aeo-geo-llmo-guide',
        'updated' => '2026-11-24T07:50:00Z',
        'opened' => '2026-11-24T08:00:00Z',
    ],

    // Inside プロジェクト企画
    [
        'parent' => 'プロジェクト企画',
        'name' => '年間事業計画_2026.pdf',
        'mime' => 'application/pdf',
        'ext' => 'pdf',
        'storage' => 'seed-annual-plan-2026.pdf',
        'type' => 'pdf',
        'pdf_title' => 'CloudDrive Annual Business Plan 2026',
        'pdf_body' => 'Strategic milestones, storage tier architecture, and enterprise security goals.',
        'owner' => '山田 太郎',
        'email' => 'taro.yamada@clouddrive.example',
        'desc' => '2026年度 事業計画プレゼンテーション資料',
        'starred' => 0,
        'shared' => 0,
        'token' => null,
        'updated' => '2026-11-20T11:00:00Z',
        'opened' => '2026-11-21T09:00:00Z',
    ],
    [
        'parent' => '2026年Q4ロードマップ',
        'name' => 'スプリント計画_11月.md',
        'mime' => 'text/markdown',
        'ext' => 'md',
        'storage' => 'seed-sprint-nov.md',
        'content' => "# 11月スプリント計画\n\n- Sprint 21: コアAPI実装・SQLite最適化\n- Sprint 22: モバイルUIおよびPWAオフライン対応\n- Sprint 23: 敵対的レビューと性能ベンチマーク公開\n",
        'owner' => '山田 太郎',
        'email' => 'taro.yamada@clouddrive.example',
        'desc' => '11月スプリントのタスク一覧と担当者アサイン',
        'starred' => 0,
        'shared' => 0,
        'token' => null,
        'updated' => '2026-11-19T15:30:00Z',
        'opened' => '2026-11-20T10:15:00Z',
    ],
    [
        'parent' => '競合分析・調査',
        'name' => 'ストレージUX比較表.csv',
        'mime' => 'text/csv',
        'ext' => 'csv',
        'storage' => 'seed-ux-comparison.csv',
        'content' => "項目,CloudDrive,標準クラウドA,標準クラウドB\n初期描画LCP,0.3秒,1.8秒,2.1秒\nオフラインPWA,対応,一部対応,未対応\nLLMO/llms.txt,完全対応,未対応,未対応\n",
        'owner' => '佐藤 花子',
        'email' => 'hanako.sato@clouddrive.example',
        'desc' => '主要クラウドストレージとのUX・速度比較',
        'starred' => 0,
        'shared' => 0,
        'token' => null,
        'updated' => '2026-11-18T14:20:00Z',
        'opened' => '2026-11-19T09:45:00Z',
    ],

    // Inside デザインアセット
    [
        'parent' => 'デザインアセット',
        'name' => 'デスクトップUIワイヤーフレーム.png',
        'mime' => 'image/png',
        'ext' => 'png',
        'storage' => 'seed-desktop-wireframe.png',
        'type' => 'png',
        'png_bg' => [254, 247, 224],
        'png_accent' => [244, 180, 0],
        'png_label' => 'Desktop Layout Spec 1440x900',
        'owner' => '佐藤 花子',
        'email' => 'hanako.sato@clouddrive.example',
        'desc' => 'Google Drive準拠のデスクトップ3ペインレイアウト設計図',
        'starred' => 0,
        'shared' => 1,
        'token' => 'desktop-wireframe-png',
        'updated' => '2026-11-23T12:00:00Z',
        'opened' => '2026-11-23T12:30:00Z',
    ],
    [
        'parent' => 'デザインアセット',
        'name' => 'モバイルPWA画面設計.png',
        'mime' => 'image/png',
        'ext' => 'png',
        'storage' => 'seed-mobile-pwa-design.png',
        'type' => 'png',
        'png_bg' => [252, 232, 230],
        'png_accent' => [234, 67, 53],
        'png_label' => 'Mobile PWA Layout 390x844',
        'owner' => '佐藤 花子',
        'email' => 'hanako.sato@clouddrive.example',
        'desc' => 'ボトムナビゲーション・ドロワー・FABを含むモバイル設計',
        'starred' => 1,
        'shared' => 0,
        'token' => null,
        'updated' => '2026-11-23T11:20:00Z',
        'opened' => '2026-11-23T15:00:00Z',
    ],
    [
        'parent' => 'Material 3 コンポーネント',
        'name' => 'カラーコントラスト検証.json',
        'mime' => 'application/json',
        'ext' => 'json',
        'storage' => 'seed-color-contrast.json',
        'content' => json_encode([
            'wcag_level' => 'AAA/AA',
            'tokens' => [
                'surface' => '#ffffff',
                'shell' => '#f8fafd',
                'primary_text' => '#1f1f1f',
                'secondary_text' => '#444746',
                'active_pill_bg' => '#c2e7ff',
                'active_pill_fg' => '#001d35',
            ],
        ], JSON_PRETTY_PRINT) . "\n",
        'owner' => '佐藤 花子',
        'email' => 'hanako.sato@clouddrive.example',
        'desc' => 'WCAG AA/AAA準拠のMaterial 3カラートークン定義',
        'starred' => 0,
        'shared' => 0,
        'token' => null,
        'updated' => '2026-11-21T16:40:00Z',
        'opened' => '2026-11-21T17:00:00Z',
    ],

    // Inside 議事録・ミーティング
    [
        'parent' => '議事録・ミーティング',
        'name' => 'アーキテクチャ設計レビュー議事録.md',
        'mime' => 'text/markdown',
        'ext' => 'md',
        'storage' => 'seed-arch-review-notes.md',
        'content' => "# アーキテクチャ設計レビュー議事録\n\n日時: 2026-11-22 14:00\n参加者: 山田, 佐藤, 鈴木, 高橋\n\n## 決定事項\n- 全4フレームワークで同一のSQLiteスキーマ・シードデータを使用\n- CSRF保護はHMAC署名付きステートレストークンで全FW統一\n- Nginx + PHP-FPM 8.3 (OPcache有効) にて独立プール計測を実施\n",
        'owner' => '山田 太郎',
        'email' => 'taro.yamada@clouddrive.example',
        'desc' => 'フレームワーク比較の公平性担保ルール合意記録',
        'starred' => 0,
        'shared' => 0,
        'token' => null,
        'updated' => '2026-11-22T14:55:00Z',
        'opened' => '2026-11-22T15:10:00Z',
    ],
    [
        'parent' => '2026年11月定例',
        'name' => '2026-11-17_週次定例メモ.txt',
        'mime' => 'text/plain',
        'ext' => 'txt',
        'storage' => 'seed-weekly-1117.txt',
        'content' => "2026-11-17 週次定例\n- Lighthouse Accessibility 100点維持確認\n- モバイルタップターゲット48px以上確認\n",
        'owner' => '高橋 健太',
        'email' => 'kenta.takahashi@clouddrive.example',
        'desc' => '11月第3週の進捗報告メモ',
        'starred' => 0,
        'shared' => 0,
        'token' => null,
        'updated' => '2026-11-17T11:30:00Z',
        'opened' => '2026-11-17T12:00:00Z',
    ],

    // Inside 財務・レポート
    [
        'parent' => '財務・レポート',
        'name' => 'インフラコスト試算_2026Q4.csv',
        'mime' => 'text/csv',
        'ext' => 'csv',
        'storage' => 'seed-infra-cost-2026q4.csv',
        'content' => "環境,月間リクエスト数,必要vCPU,月額コストJPY\nAI自作FW,50000000,2,18000\nPhalcon 5,50000000,2,21000\nSlim 4,50000000,4,34000\nLaravel 13,50000000,8,68000\n",
        'owner' => '鈴木 一郎',
        'email' => 'ichiro.suzuki@clouddrive.example',
        'desc' => 'リクエスト単価ベースのインフラ費用試算シート',
        'starred' => 0,
        'shared' => 0,
        'token' => null,
        'updated' => '2026-11-21T10:10:00Z',
        'opened' => '2026-11-21T11:00:00Z',
    ],

    // Inside 技術アーキテクチャ
    [
        'parent' => 'PHPフレームワーク検証',
        'name' => 'ベンチマーク計測手順書.md',
        'mime' => 'text/markdown',
        'ext' => 'md',
        'storage' => 'seed-benchmark-protocol.md',
        'content' => "# ベンチマーク計測手順書\n\n1. 各フレームワーク専用PHP-FPMプールを再起動\n2. 5秒間のウォームアップ（OPcache最適化）\n3. wrkによる3回計測（中央値・p50/p95/p99算出）\n4. RSSメモリ・includeファイル数・レスポンスサイズ記録\n",
        'owner' => '山田 太郎',
        'email' => 'taro.yamada@clouddrive.example',
        'desc' => '再現可能な性能比較ベンチマークプロトコル',
        'starred' => 1,
        'shared' => 1,
        'token' => 'benchmark-protocol-md',
        'updated' => '2026-11-24T08:40:00Z',
        'opened' => '2026-11-24T08:50:00Z',
    ],
    [
        'parent' => 'セキュリティ監査',
        'name' => '敵対的レビューチェックリスト.md',
        'mime' => 'text/markdown',
        'ext' => 'md',
        'storage' => 'seed-security-checklist.md',
        'content' => "# 敵対的レビューチェックリスト\n\n- SQLインジェクション（ORDER BYホワイトリスト含む）\n- パストラバーサル（storage_nameサニタイズ＋realpath検証）\n- Stored XSS（HTML / JSON-LD / DOM / SVGサンドボックス）\n- CSRFトークン検証（POST/PATCH/DELETE全網羅）\n- フォルダ循環移動の禁止（422エラー返却）\n",
        'owner' => '高橋 健太',
        'email' => 'kenta.takahashi@clouddrive.example',
        'desc' => 'OWASP Top 10準拠の敵対的監査項目',
        'starred' => 0,
        'shared' => 0,
        'token' => null,
        'updated' => '2026-11-24T08:05:00Z',
        'opened' => '2026-11-24T08:15:00Z',
    ],
];

// Additional realistic files inside subfolders to bring dataset to ~60 items for realistic DB queries
$extraTemplates = [
    ['parent' => '議事録・ミーティング', 'prefix' => '定例議事録_第', 'suffix' => '回.md', 'mime' => 'text/markdown', 'ext' => 'md', 'count' => 10],
    ['parent' => '財務・レポート', 'prefix' => '月次KPIレポート_2026_', 'suffix' => '.csv', 'mime' => 'text/csv', 'ext' => 'csv', 'count' => 10],
    ['parent' => 'PHPフレームワーク検証', 'prefix' => 'プロファイリングログ_', 'suffix' => '.json', 'mime' => 'application/json', 'ext' => 'json', 'count' => 10],
    ['parent' => 'デザインアセット', 'prefix' => 'アイコン仕様メモ_', 'suffix' => '.txt', 'mime' => 'text/plain', 'ext' => 'txt', 'count' => 8],
];

foreach ($filesSpec as $f) {
    $filePath = $uploadsDir . '/' . $f['storage'];
    $type = $f['type'] ?? 'text';
    if ($type === 'png') {
        $size = createSamplePng($filePath, 960, 540, $f['png_bg'], $f['png_accent'], $f['png_label']);
    } elseif ($type === 'pdf') {
        $size = createSamplePdf($filePath, $f['pdf_title'], $f['pdf_body']);
    } else {
        file_put_contents($filePath, $f['content']);
        $size = strlen($f['content']);
    }

    $parentId = $f['parent'] === null ? null : $folderIds[$f['parent']];
    $insertItem->execute([
        ':parent_id' => $parentId,
        ':name' => $f['name'],
        ':is_folder' => 0,
        ':mime_type' => $f['mime'],
        ':extension' => $f['ext'],
        ':size_bytes' => $size,
        ':storage_name' => $f['storage'],
        ':owner_name' => $f['owner'],
        ':owner_email' => $f['email'],
        ':description' => $f['desc'],
        ':is_starred' => $f['starred'],
        ':is_shared' => $f['shared'],
        ':share_token' => $f['token'],
        ':is_trashed' => 0,
        ':trashed_at' => null,
        ':last_opened_at' => $f['opened'],
        ':created_at' => '2026-11-01T09:00:00Z',
        ':updated_at' => $f['updated'],
    ]);
    $itemId = (int)$pdo->lastInsertId();
    $insertActivity->execute([
        ':item_id' => $itemId,
        ':action' => 'uploaded',
        ':actor_name' => $f['owner'],
        ':detail' => "ファイル「{$f['name']}」をアップロードしました",
        ':created_at' => $f['updated'],
    ]);
}

foreach ($extraTemplates as $tpl) {
    for ($i = 1; $i <= $tpl['count']; $i++) {
        $num = str_pad((string)$i, 2, '0', STR_PAD_LEFT);
        $name = $tpl['prefix'] . $num . $tpl['suffix'];
        $storage = 'seed-extra-' . md5($tpl['parent'] . $name) . '.' . $tpl['ext'];
        $body = "CloudDrive sample file: {$name}\nFolder: {$tpl['parent']}\nIndex: {$i}\nGenerated deterministically for framework benchmark parity.\n";
        file_put_contents($uploadsDir . '/' . $storage, $body);
        $day = str_pad((string)(1 + ($i % 20)), 2, '0', STR_PAD_LEFT);
        $ts = "2026-11-{$day}T10:00:00Z";
        $insertItem->execute([
            ':parent_id' => $folderIds[$tpl['parent']],
            ':name' => $name,
            ':is_folder' => 0,
            ':mime_type' => $tpl['mime'],
            ':extension' => $tpl['ext'],
            ':size_bytes' => strlen($body),
            ':storage_name' => $storage,
            ':owner_name' => '山田 太郎',
            ':owner_email' => 'taro.yamada@clouddrive.example',
            ':description' => "{$tpl['parent']} 配下のアーカイブファイル #{$num}",
            ':is_starred' => 0,
            ':is_shared' => 0,
            ':share_token' => null,
            ':is_trashed' => 0,
            ':trashed_at' => null,
            ':last_opened_at' => $ts,
            ':created_at' => $ts,
            ':updated_at' => $ts,
        ]);
    }
}

// 4. Trashed items (so Trash view works out of the box)
$trashedItems = [
    ['name' => '旧版_ロゴ案_v0.txt', 'mime' => 'text/plain', 'ext' => 'txt', 'storage' => 'seed-trash-logo.txt', 'content' => "廃止された旧バージョンのロゴ検討メモ\n", 'desc' => '不要になった旧ドラフト'],
    ['name' => 'テスト出力_202610.csv', 'mime' => 'text/csv', 'ext' => 'csv', 'storage' => 'seed-trash-test.csv', 'content' => "id,status\n1,deprecated\n", 'desc' => '検証完了済みのダミーデータ'],
    ['name' => '下書きメモ_削除予定.md', 'mime' => 'text/markdown', 'ext' => 'md', 'storage' => 'seed-trash-draft.md', 'content' => "# 削除予定の下書き\n整理のためゴミ箱へ移動。\n", 'desc' => '整理対象のメモ'],
];

foreach ($trashedItems as $t) {
    file_put_contents($uploadsDir . '/' . $t['storage'], $t['content']);
    $insertItem->execute([
        ':parent_id' => null,
        ':name' => $t['name'],
        ':is_folder' => 0,
        ':mime_type' => $t['mime'],
        ':extension' => $t['ext'],
        ':size_bytes' => strlen($t['content']),
        ':storage_name' => $t['storage'],
        ':owner_name' => '山田 太郎',
        ':owner_email' => 'taro.yamada@clouddrive.example',
        ':description' => $t['desc'],
        ':is_starred' => 0,
        ':is_shared' => 0,
        ':share_token' => null,
        ':is_trashed' => 1,
        ':trashed_at' => '2026-11-23T20:00:00Z',
        ':last_opened_at' => '2026-11-20T09:00:00Z',
        ':created_at' => '2026-10-20T09:00:00Z',
        ':updated_at' => '2026-11-23T20:00:00Z',
    ]);
}

$pdo->commit();
$count = (int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
echo "Seeded {$dbPath} successfully with {$count} items.\n";
