# CloudDrive — PHPフレームワーク徹底パフォーマンス比較（AI自作FW vs Laravel vs Phalcon vs Slim）

Google Driveと同等のMaterial Design 3 UI・機能・PWA・SEO/AEO/GEO/AIO/LLMO・セキュリティ・Lighthouse 100点満点要件を備えたWebアプリケーション「**CloudDrive**」を、4つのPHPフレームワークで**100%同一仕様**として実装し、公平な分離環境でパフォーマンス比較および独立敵対的レビューを実施したプロジェクトです。

## 比較対象（全4実装）

| フレームワーク | ディレクトリ | ポート | 特徴 |
|---|---|---|---|
| **AI自作フレームワーク** | [apps/ai-framework](apps/ai-framework) | `8081` | 既存フレームワーク・Composer・外部ライブラリ一切ゼロのPHP 8.3純自作構成 |
| **Laravel 13** | [apps/laravel](apps/laravel) | `8082` | `laravel/framework` v13.43.0（本番キャッシュ・権威的クラスマップ・ステートレス最小構成） |
| **Phalcon 5** | [apps/phalcon](apps/phalcon) | `8083` | Phalcon 5.23.0 C拡張（`-O2` ビルド済み `phalcon.so` + MVC構成） |
| **Slim 4** | [apps/slim](apps/slim) | `8084` | Slim 4.15.3 + PSR-7 + FastRouteルートキャッシュ有効 |

---

## 主なベンチマーク結果ハイライト

> 環境: Linux x64 (Intel Xeon 2.30GHz, 2 vCPU, 7.77 GB RAM) / PHP 8.3.6-FPM (`pm=static`, 4 workers, OPcache `validate_timestamps=0`) / Nginx 1.24.0 / SQLite 3.45.1 WAL  
> 詳細・全パーセンタイル・技術分析は [docs/BENCHMARK_REPORT.md](docs/BENCHMARK_REPORT.md) を参照してください。

| シナリオ | AI自作FW | Slim 4.15 | Phalcon 5.23 | Laravel 13 | AI vs Laravel |
|---|---:|---:|---:|---:|---:|
| **SSR ホーム (`GET /`)** | **900.94 req/s** | 759.47 req/s | 642.74 req/s | 260.41 req/s | **3.46x** |
| **SSR フォルダCTE (`GET /drive/folders/1`)** | **1,106.80 req/s** | 876.16 req/s | 742.21 req/s | 270.89 req/s | **4.09x** |
| **API 一覧 (`GET /api/items`)** | **1,435.11 req/s** | 1,205.77 req/s | 999.60 req/s | 309.23 req/s | **4.64x** |
| **API 検索 (`GET /api/items?q=企画`)** | **1,476.01 req/s** | 1,198.61 req/s | 962.48 req/s | 315.52 req/s | **4.68x** |
| **API ヘルス (`GET /api/health`)** | **2,116.22 req/s** | 1,508.96 req/s | 1,259.64 req/s | 334.18 req/s | **6.33x** |
| **API CSRF書込 (`PATCH /api/items/6`)** | **723.00 req/s** | 578.08 req/s | 486.00 req/s | 222.10 req/s | **3.26x** |
| **純PHP実行時間 (`GET /`)** | **1,656 µs** | 1,987 µs | 2,448 µs | 8,364 µs | **5.05x** |
| **読込PHPファイル数 (`GET /`)** | 20 | 92 | **10** | 489 | — |
| **FPMプール総RSS（1+4プロセス）** | **81.03 MB** | 85.80 MB | 143.74 MB | 135.23 MB | **-40.1%** |

### Lighthouse 品質スコア（全4フレームワーク × 3ルート × Mobile & Desktop = 24監査）

- **Performance**: **100**（全24監査）
- **Accessibility**: **100**（全24監査）
- **Best Practices**: **100**（全24監査）
- **SEO**: **100**（全24監査）
- **Agentic Browsing (Lighthouse 13)**: **100**（全24監査）

生データ: [docs/lighthouse-results.json](docs/lighthouse-results.json) / [docs/benchmark-results.json](docs/benchmark-results.json)

---

## 実装済み機能（全4フレームワーク完全共通）

- **Google Drive Material Design 3 UI**: マイドライブ、共有アイテム、最近使用したアイテム、スター付き、ゴミ箱、保存容量ゲージ、リスト/グリッド表示切替、パンくずリスト、ファイルタイプ絞り込みチップ、検索、ドラッグ＆ドロップアップロード、フォルダ作成、リネーム、フォルダ移動モーダル、右クリック/ケバブメニュー、共有リンク発行＆トークン失効ローテーション、詳細・アクティビティパネル、ファイルプレビュー、キーボードショートカット。
- **モバイル完全対応**: 390pxビューポート対応、スライドドロワー、ボトムナビゲーションバー、FAB（新規作成）、横はみ出しゼロ。
- **PWA**: `/manifest.webmanifest`（通常＆maskableアイコン、Desktop/Mobileスクリーンショット）、Service Worker (`/sw.js`)、オフラインフォールバック (`/offline`)。
- **SEO / AEO / GEO / AIO / LLMO**: SSRセマンティックHTML、構造化データ JSON-LD（`WebApplication`, `FAQPage`, `AboutPage`, `Organization`, `BreadcrumbList`）、`hreflang="ja"`、RFC 9309準拠 `/robots.txt`、`/sitemap.xml`、`/llms.txt`、`/llms-full.txt`、OpenAPI 3.1.0 (`/openapi.json`)。
- **敵対的レビュー（34件すべて修正・回帰検証済み）**: 詳細は [docs/ADVERSARIAL_REVIEW.md](docs/ADVERSARIAL_REVIEW.md) を参照。

---

## ディレクトリ構成

```text
.
├── SPEC.md                        # 全4フレームワーク統一アプリケーション仕様
├── apps/
│   ├── ai-framework/              # 依存ゼロAI自作フレームワーク (:8081)
│   ├── laravel/                   # Laravel 13 (:8082)
│   ├── phalcon/                   # Phalcon 5 C拡張 (:8083)
│   └── slim/                      # Slim 4 (:8084)
├── shared/
│   ├── database/                  # 共通SQLiteスキーマ & 71件シード生成器
│   ├── public/                    # 共通UI CSS/JS・Service Worker・PWAアイコン/スクリーンショット
│   └── sync-assets.sh             # 全4アプリへの静的資産同期＆OPcacheインラインCSS生成
├── infra/
│   ├── nginx.conf                 # 全4アプリ共通Nginx設定
│   ├── php-fpm-common.ini         # 共通PHP 8.3 + OPcache設定
│   ├── php-fpm-phalcon.ini        # Phalcon拡張ロード追加設定
│   └── manage-servers.sh          # PHP-FPMプール + Nginx 起動/停止/分離制御スクリプト
├── tests/
│   ├── parity-test.mjs            # 敵対的セキュリティ・プロトコル・APIバイト完全一致テスト
│   ├── pwa-and-visual-test.mjs    # 実Chrome PWA・UI操作・レスポンシブ検証
│   ├── lighthouse-all.mjs         # 全24パターンLighthouse 100点自動検証
│   ├── wrk-json.lua               # wrk HdrHistogram JSON出力スクリプト
│   └── benchmark.mjs              # 完全分離マルチラン性能＆フットプリント計測スイート
└── docs/
    ├── BENCHMARK_REPORT.md        # パフォーマンス比較レポート
    ├── ADVERSARIAL_REVIEW.md      # 敵対的レビュー34件の指摘・修正・検証記録
    ├── benchmark-results.json     # ベンチマーク生計測データ
    └── lighthouse-results.json    # Lighthouse 24監査生データ
```

---

## 再現コマンド

```bash
# 1. 共通アセットの同期とOPcache用インラインCSSの生成
./shared/sync-assets.sh

# 2. 全4データベースを同一71件データで初期化
for app in ai-framework laravel phalcon slim; do
  php shared/database/seed.php "apps/${app}/storage/database.sqlite" "apps/${app}/storage/uploads"
done

# 3. サーバー起動（全4 PHP-FPMプール + Nginx）
./infra/manage-servers.sh start all

# 4. 敵対的セキュリティ・API完全一致・PWA・UI操作テスト
node tests/parity-test.mjs
for port in 8081 8082 8083 8084; do
  node tests/pwa-and-visual-test.mjs "http://localhost:${port}" "/tmp/cd-check-${port}"
done

# 5. 全24パターン Lighthouse 100点検証
node tests/lighthouse-all.mjs

# 6. 完全分離パフォーマンスベンチマーク実行
node tests/benchmark.mjs
```
