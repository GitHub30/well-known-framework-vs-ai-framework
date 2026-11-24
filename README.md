# CloudDrive — PHPフレームワーク徹底パフォーマンス比較（AI自作FW vs Laravel vs Phalcon vs Slim）

Google Driveと同等のMaterial Design 3 UI・機能・PWA・SEO/AEO/GEO/AIO/LLMO・セキュリティ・Lighthouse 100点満点要件を備えたWebアプリケーション「**CloudDrive**」を、4つのPHPフレームワークで**100%同一仕様**として実装し、公平な分離環境でパフォーマンス比較および独立敵対的レビューを実施したプロジェクトです。

- **Qiita 解説記事**: [Laravel vs AIオレオレフレームワーク【Laravelは過去の遺物？】](https://qiita.com/yangon/items/2552b40a820022126988)
- **スクショ＆ベンチマークギャラリー (GitHub Pages)**: https://github30.github.io/well-known-framework-vs-ai-framework/

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

## 各アプリのデプロイ方法

### 0. 全フレームワーク共通の前提条件

- **OS / Webサーバー**: Linux (Ubuntu 24.04 推奨) + Nginx 1.24+
- **PHP**: PHP 8.3 以上（`php8.3-fpm`, `php8.3-cli`, `php8.3-sqlite3`, `php8.3-mbstring`, `php8.3-gd`, `php8.3-xml`, `php8.3-curl`, `php8.3-opcache`）
- **必須環境変数**（各 PHP-FPM プールまたは `.env` で設定）:
  - `APP_SECRET`: 32バイト以上のランダム文字列（例: `php -r 'echo bin2hex(random_bytes(32));'`）。未設定時はセキュリティ保護のため起動エラー（フェイルクローズ）になります。
  - `APP_URL`: 公開オリジンURL（例: `https://drive.example.com`）。`canonical`・`robots.txt`・`sitemap.xml`・`openapi.json` 生成時に未検証 `Host` ヘッダを無視するために必須です。

```bash
# Ubuntu 24.04 での基本パッケージ導入例
sudo apt-get update
sudo apt-get install -y nginx sqlite3 php8.3-fpm php8.3-cli php8.3-sqlite3 php8.3-mbstring php8.3-gd php8.3-xml php8.3-curl

# リポジトリ取得＆共通静的アセット同期＋OPcache用インラインCSSモジュール生成
git clone https://github.com/GitHub30/well-known-framework-vs-ai-framework.git /var/www/clouddrive
cd /var/www/clouddrive
./shared/sync-assets.sh
```

> **DB初期化について**:
> - デモ用サンプル71件入りで初期化する場合: `php shared/database/seed.php <db_path> <uploads_dir>`
> - 空のDBで本番運用を開始する場合: `sqlite3 <db_path> < shared/database/schema.sql && mkdir -p <uploads_dir>`

#### 共通 Nginx サーバーブロック設定例（全4アプリ共通テンプレート）

各アプリで `root` と `fastcgi_pass`（ソケット名）のみ差し替えて利用します（設定ベース: [infra/nginx.conf](infra/nginx.conf)）。

```nginx
server {
    listen 443 ssl http2;
    server_name drive.example.com;

    # ssl_certificate / ssl_certificate_key を環境に合わせて設定
    root /var/www/clouddrive/apps/<APP_NAME>/public;
    index index.php;
    server_tokens off;
    client_max_body_size 24m;

    # ドットファイル遮断（.well-known は許可）
    location ~ /\.(?!well-known) {
        deny all;
    }

    # 静的アセットの長期キャッシュ＋セキュリティヘッダ
    location /assets/ {
        add_header Cache-Control "public, max-age=31536000, immutable" always;
        add_header X-Content-Type-Options "nosniff" always;
        add_header X-Frame-Options "DENY" always;
        add_header Referrer-Policy "strict-origin-when-cross-origin" always;
        add_header Cross-Origin-Opener-Policy "same-origin" always;
        try_files $uri =404;
    }

    # PWA Service Worker（キャッシュ無効化＋スコープ許可）
    location = /sw.js {
        charset utf-8;
        add_header Cache-Control "no-cache" always;
        add_header Service-Worker-Allowed "/" always;
        add_header X-Content-Type-Options "nosniff" always;
        add_header X-Frame-Options "DENY" always;
        add_header Referrer-Policy "strict-origin-when-cross-origin" always;
        try_files $uri =404;
    }

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm-clouddrive.sock;
    }
}
```

#### 共通 PHP-FPM プール設定例（`/etc/php/8.3/fpm/pool.d/clouddrive.conf`）

```ini
[clouddrive]
user = www-data
group = www-data
listen = /run/php/php8.3-fpm-clouddrive.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = static
pm.max_children = 4
clear_env = yes
env[PATH] = /usr/local/bin:/usr/bin:/bin
env[APP_SECRET] = <64文字の16進ランダム鍵>
env[APP_URL] = https://drive.example.com
```

推奨 `php.ini` / OPcache 設定（[infra/php-fpm-common.ini](infra/php-fpm-common.ini) 準拠）:
`upload_max_filesize = 22M`, `post_max_size = 24M`, `opcache.enable = 1`, `opcache.memory_consumption = 256`, `opcache.max_accelerated_files = 20000`, `opcache.validate_timestamps = 0`（※PHPコード更新時は必ず `sudo systemctl reload php8.3-fpm` を実行してください）。

---

### 1. AI自作フレームワーク（`apps/ai-framework`）のデプロイ

Composer・`vendor/`・外部拡張が一切不要なため、最も軽量・シンプルにデプロイできます。

```bash
cd /var/www/clouddrive

# 1. 共通静的アセット同期 ＆ OPcache用インラインCSS生成
./shared/sync-assets.sh

# 2. SQLiteデータベース初期化（デモデータ投入の場合。空DBは schema.sql を流し込み）
php shared/database/seed.php apps/ai-framework/storage/database.sqlite apps/ai-framework/storage/uploads

# 3. 実行ユーザー（例: www-data）へ書込権限を付与
sudo chown -R www-data:www-data apps/ai-framework/storage
sudo chmod -R 775 apps/ai-framework/storage

# 4. Nginx の root を /var/www/clouddrive/apps/ai-framework/public に設定し再読み込み
sudo nginx -t && sudo systemctl reload nginx php8.3-fpm

# 5. 動作確認
curl -s https://drive.example.com/api/health
```

<details>
<summary>ローカル単体テスト起動（PHPビルトインサーバー）</summary>

```bash
APP_SECRET="$(php -r 'echo bin2hex(random_bytes(32));')" \
APP_URL="http://localhost:8081" \
php -S 127.0.0.1:8081 -t apps/ai-framework/public
```

</details>

---

### 2. Laravel 13（`apps/laravel`）のデプロイ

Composer 本番依存のインストール、`.env` 設定、および `artisan optimize` による事前キャッシュ生成を行います。

```bash
cd /var/www/clouddrive/apps/laravel

# 1. Composer 本番依存インストール（権威的クラスマップ最適化）
composer install --no-dev --optimize-autoloader --classmap-authoritative

# 2. 本番 .env 作成
cp .env.example .env
php artisan key:generate --force
```

`.env` の主要項目を本番環境向けに編集します（`DB_DATABASE` は絶対パス指定を推奨）:

```dotenv
APP_NAME=CloudDrive
APP_ENV=production
APP_DEBUG=false
APP_URL=https://drive.example.com
APP_LOCALE=ja

DB_CONNECTION=sqlite
DB_DATABASE=/var/www/clouddrive/apps/laravel/storage/database.sqlite
```

> ※ Laravel の `CsrfMiddleware` は環境変数 `APP_SECRET`（32バイト以上）を優先使用し、未指定時は `APP_KEY` にフォールバックします。

```bash
cd /var/www/clouddrive

# 3. 共通アセット同期（Laravel標準 public/robots.txt・favicon.ico の競合除去も含む）
./shared/sync-assets.sh

# 4. SQLiteデータベース初期化
php shared/database/seed.php apps/laravel/storage/database.sqlite apps/laravel/storage/uploads

# 5. Laravel 本番キャッシュ生成（設定・ルート・イベント・Bladeビュー）
cd /var/www/clouddrive/apps/laravel
php artisan optimize

# 6. 書込権限を付与
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

# 7. Nginx の root を /var/www/clouddrive/apps/laravel/public に設定し再読み込み
sudo nginx -t && sudo systemctl reload nginx php8.3-fpm

# 8. 動作確認
curl -s https://drive.example.com/api/health
```

> **更新デプロイ時の注意**: `.env` やルート・ビュー更新後は `php artisan optimize:clear && php artisan optimize` を実行し、`php8.3-fpm` をリロードしてください。さらに高スループットが必要な場合は、PHP-FPM の代わりに **Laravel Octane（FrankenPHP / Swoole / RoadRunner）** を導入することで起動コストを削減できます。

---

### 3. Phalcon 5（`apps/phalcon`）のデプロイ

Composer 依存は不要ですが、**Phalcon 5 C言語拡張（`phalcon.so`）のビルドと PHP-FPM への組み込み**が必要です。

```bash
# 1. C拡張ビルドツール導入（Ubuntu 24.04）
sudo apt-get install -y php8.3-dev php-pear build-essential pkg-config libpcre2-dev zlib1g-dev

# 2. Phalcon 5 拡張を -O2 最適化付きでソースビルド＆インストール
mkdir -p /tmp/build-phalcon && cd /tmp/build-phalcon
pecl download phalcon
tar xzf phalcon-*.tgz && cd phalcon-*/
phpize
CFLAGS="-O2" ./configure --enable-phalcon
make -j"$(nproc)"
sudo make install

# 3. PHP 8.3（FPM / CLI）で phalcon.so を有効化
echo "extension=phalcon.so" | sudo tee /etc/php/8.3/mods-available/phalcon.ini
sudo phpenmod -v 8.3 phalcon
php -m | grep -i phalcon   # "phalcon" が表示されれば成功
```

> ※ root 権限なしでユーザー空間に配置する場合は、`make install` の代わりに `modules/phalcon.so` を任意パス（例: `~/.local/php-ext/phalcon.so`）へコピーし、`php.ini` に `extension=/abs/path/to/phalcon.so` を記述します（参照: [infra/php-fpm-phalcon.ini](infra/php-fpm-phalcon.ini)）。

```bash
cd /var/www/clouddrive

# 4. 共通アセット同期 ＆ OPcache用インラインCSS生成
./shared/sync-assets.sh

# 5. SQLiteデータベース初期化
php shared/database/seed.php apps/phalcon/storage/database.sqlite apps/phalcon/storage/uploads

# 6. 書込権限を付与
sudo chown -R www-data:www-data apps/phalcon/storage
sudo chmod -R 775 apps/phalcon/storage

# 7. Nginx の root を /var/www/clouddrive/apps/phalcon/public に設定し再読み込み
sudo nginx -t && sudo systemctl reload nginx php8.3-fpm

# 8. 動作確認
curl -s https://drive.example.com/api/health
```

---

### 4. Slim 4（`apps/slim`）のデプロイ

Composer 本番依存のインストールと、FastRoute ルートキャッシュ（`storage/cache/routes.php`）用の書込権限設定を行います。

```bash
cd /var/www/clouddrive/apps/slim

# 1. Composer 本番依存インストール（権威的クラスマップ最適化）
composer install --no-dev --optimize-autoloader --classmap-authoritative

cd /var/www/clouddrive

# 2. 共通アセット同期 ＆ OPcache用インラインCSS生成
./shared/sync-assets.sh

# 3. SQLiteデータベース初期化
php shared/database/seed.php apps/slim/storage/database.sqlite apps/slim/storage/uploads

# 4. ルートキャッシュディレクトリ作成＆書込権限付与
mkdir -p apps/slim/storage/cache
sudo chown -R www-data:www-data apps/slim/storage
sudo chmod -R 775 apps/slim/storage

# 5. Nginx の root を /var/www/clouddrive/apps/slim/public に設定し再読み込み
sudo nginx -t && sudo systemctl reload nginx php8.3-fpm

# 6. 動作確認（初回リクエストで storage/cache/routes.php が自動生成されます）
curl -s https://drive.example.com/api/health
```

> **更新デプロイ時の注意**: `apps/slim/public/index.php` のルート定義を変更した場合は、古いルートキャッシュを削除（`rm -f apps/slim/storage/cache/routes.php`）してから `php8.3-fpm` をリロードしてください。

---

### 5. 全4アプリ一括起動（検証・ベンチマーク環境用）

本リポジトリ同梱の管理スクリプト [infra/manage-servers.sh](infra/manage-servers.sh) を使うと、4つの PHP-FPM プール（`8081`〜`8084`）と Nginx を root 権限不要のユーザー空間（`/tmp/clouddrive-run`）で一括起動・分離制御できます。

```bash
# 1. 共通アセット同期とOPcache用インラインCSS生成
./shared/sync-assets.sh

# 2. 全4データベースを同一71件データで初期化
for app in ai-framework laravel phalcon slim; do
  php shared/database/seed.php "apps/${app}/storage/database.sqlite" "apps/${app}/storage/uploads"
done

# 3. 全4アプリ起動（APP_SECRET 乱数自動生成＆各プールへ自動注入）
./infra/manage-servers.sh start all
./infra/manage-servers.sh status

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

### 本番運用チェックリスト

- **HTTPS 終端**: TLS を有効化すると、CSRF Cookie（`clouddrive_csrf`）へ自動的に `Secure` 属性が付与されます（リバースプロキシ配下で終端する場合は Nginx から `fastcgi_param HTTPS on;` を渡してください）。
- **OPcache リロード**: `opcache.validate_timestamps = 0` で運用する場合、PHP ファイル更新時は必ず `php8.3-fpm` をリロードまたは再起動してください。
- **SQLite バックアップ**: WAL モード運用中のバックアップは単純 `cp` ではなく `sqlite3 storage/database.sqlite ".backup '/path/to/backup.sqlite'"` を使用してください。
- **アップロード隔離**: `storage/uploads/` はドキュメントルート外に配置されており、全ファイルが `.blob` 拡張子で保存されるため直接実行されません。

