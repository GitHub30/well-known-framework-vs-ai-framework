<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Generates SEO, AEO, GEO, AIO, LLMO metadata, structured JSON-LD, RFC-9309 robots.txt,
 * DB-backed sitemap.xml, llms.txt, llms-full.txt, PWA manifest, and valid OpenAPI 3.1.0.
 */
final class SeoService
{
    public const FRAMEWORK_ID = 'ai-framework';
    public const FRAMEWORK_LABEL = 'AI自作FW (依存ゼロ)';

    /**
     * @param list<array{id?:int,name:string,url?:string}> $breadcrumbs
     * @param array<string, mixed>|null $extraSchema
     * @return array<string, mixed>
     */
    public function pageMeta(
        string $baseUrl,
        string $path,
        string $title,
        string $description,
        array $breadcrumbs = [],
        bool $includeFaq = false,
        string $robots = 'index, follow, max-image-preview:large, max-snippet:-1',
        ?array $extraSchema = null,
    ): array {
        $canonical = rtrim($baseUrl, '/') . ($path === '/' ? '/' : $path);

        $graph = [
            [
                '@type' => 'Organization',
                '@id' => $baseUrl . '/#organization',
                'name' => 'CloudDrive Benchmark Project',
                'url' => $baseUrl . '/',
                'logo' => $baseUrl . '/assets/icon-512.png',
            ],
            [
                '@type' => 'WebApplication',
                '@id' => $baseUrl . '/#webapp',
                'name' => 'CloudDrive',
                'alternateName' => 'クラウドドライブ PHPベンチマーク版',
                'url' => $baseUrl . '/',
                'applicationCategory' => 'UtilitiesApplication',
                'operatingSystem' => 'Web, iOS, Android, Windows, macOS, Linux',
                'browserRequirements' => 'Requires HTML5 and JavaScript enabled',
                'inLanguage' => 'ja',
                'publisher' => ['@id' => $baseUrl . '/#organization'],
                'description' => 'Google Drive準拠のUIとPWA・SEO・AEO・GEO・AIO・LLMO最適化を備えた高速PHPクラウドストレージアプリ。',
                'offers' => [
                    '@type' => 'Offer',
                    'price' => '0',
                    'priceCurrency' => 'JPY',
                ],
                'featureList' => [
                    '階層フォルダ管理とパンくずナビゲーション',
                    'ドラッグ＆ドロップ対応マルチファイルアップロード',
                    '高速ファイル検索・種別フィルタ・並び替え',
                    '画像・PDF・Markdown・CSVの安全なインラインプレビュー',
                    'フォルダ移動・公開共有リンク発行・スター・ゴミ箱復元',
                    'オフライン対応PWAおよびレスポンシブモバイルUI',
                ],
                'softwareVersion' => '1.0.0',
            ],
            [
                '@type' => 'WebSite',
                '@id' => $baseUrl . '/#website',
                'url' => $baseUrl . '/',
                'name' => 'CloudDrive',
                'inLanguage' => 'ja',
                'publisher' => ['@id' => $baseUrl . '/#organization'],
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => $baseUrl . '/drive/search?q={search_term_string}',
                    'query-input' => 'required name=search_term_string',
                ],
            ],
        ];

        $crumbItems = [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'マイドライブ', 'item' => $baseUrl . '/'],
        ];
        $pos = 2;
        foreach ($breadcrumbs as $b) {
            $itemUrl = isset($b['url'])
                ? $baseUrl . $b['url']
                : (isset($b['id']) ? $baseUrl . '/drive/folders/' . $b['id'] : $canonical);
            $crumbItems[] = [
                '@type' => 'ListItem',
                'position' => $pos++,
                'name' => $b['name'],
                'item' => $itemUrl,
            ];
        }
        $graph[] = [
            '@type' => 'BreadcrumbList',
            'itemListElement' => $crumbItems,
        ];

        if ($includeFaq) {
            $graph[] = [
                '@type' => 'AboutPage',
                '@id' => $baseUrl . '/about#page',
                'url' => $baseUrl . '/about',
                'name' => $title,
                'description' => $description,
                'inLanguage' => 'ja',
                'dateModified' => '2026-11-24',
                'mainEntity' => ['@id' => $baseUrl . '/#webapp'],
            ];
            $graph[] = [
                '@type' => 'FAQPage',
                'mainEntity' => array_map(static fn(array $faq): array => [
                    '@type' => 'Question',
                    'name' => $faq['q'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $faq['a'],
                    ],
                ], self::faqList()),
            ];
        }

        if ($extraSchema !== null) {
            $graph[] = $extraSchema;
        }

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'robots' => $robots,
            'ogImage' => $baseUrl . '/assets/og-image.png',
            'ogImageAlt' => 'CloudDrive — PHP 8.3 フレームワーク性能比較クラウドストレージ',
            'jsonLd' => [
                '@context' => 'https://schema.org',
                '@graph' => $graph,
            ],
        ];
    }

    /** @return list<array{q:string,a:string}> */
    public static function faqList(): array
    {
        return [
            [
                'q' => 'CloudDriveとは何ですか？',
                'a' => 'CloudDriveはGoogle Driveと同一の操作性・Material Design 3 UIを備え、AI自作フレームワーク・Laravel 13・Phalcon 5・Slim 4の4種類のPHP 8.3フレームワークで同一仕様実装した高速クラウドファイル管理Webアプリです。',
            ],
            [
                'q' => 'オフライン環境やスマートフォンでも利用できますか？',
                'a' => 'はい。CloudDriveはWeb App ManifestとService Workerを実装したPWA（Progressive Web App）であり、モバイル向けボトムナビゲーション・ドロワー・FABに対応し、オフライン時にもフォールバック画面とキャッシュ済みアセットを利用できます。',
            ],
            [
                'q' => 'どのファイル形式のプレビューとアップロードに対応していますか？',
                'a' => '最大20MB（合計15GBクォータ）のファイルアップロードに対応し、PNG・JPEG・WebP・GIF画像、PDF文書、Markdown・CSV・JSON・テキストファイルの安全なインラインプレビューおよびワンクリックダウンロードが可能です。',
            ],
            [
                'q' => 'SEO・AEO・GEO・AIO・LLMOにはどのように対応していますか？',
                'a' => 'セマンティックSSR HTML、JSON-LD構造化データ（Organization・WebApplication・SearchAction・BreadcrumbList・FAQPage・AboutPage・DigitalDocument）、DB連動sitemap.xml、RFC 9309準拠のAIクローラ対応robots.txt、llms.txtおよびllms-full.txt、さらに完全なOpenAPI 3.1.0仕様（/openapi.json）を全フレームワークで標準提供しています。',
            ],
            [
                'q' => 'セキュリティ対策はどのように実装されていますか？',
                'a' => '全SQLクエリでのプリペアドステートメント徹底、256bit鍵によるHMAC-SHA256署名付きダブルサブミットCSRF保護、不活性.blob保存＋realpath検証によるパストラバーサル防止、厳格なContent-Security-Policy、SVG等の危険MIMEのJSONテキスト化、32階層上限と循環フォルダ移動検証を備えています。',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function manifestJson(string $baseUrl): array
    {
        $icon192 = [['src' => '/assets/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png']];
        return [
            'id' => '/',
            'name' => 'CloudDrive — 高速PHPクラウドストレージ',
            'short_name' => 'CloudDrive',
            'description' => 'Google Drive準拠UIとPWA・SEO・AEO・GEO・AIO・LLMOを備えたPHPクラウドドライブ。',
            'lang' => 'ja',
            'dir' => 'ltr',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#f8fafd',
            'theme_color' => '#f8fafd',
            'categories' => ['productivity', 'utilities'],
            'icons' => [
                ['src' => '/assets/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/assets/icon-maskable-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => '/assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/assets/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'screenshots' => [
                [
                    'src' => '/assets/screenshot-desktop.png',
                    'sizes' => '1440x900',
                    'type' => 'image/png',
                    'form_factor' => 'wide',
                    'label' => 'CloudDrive デスクトップ マイドライブ画面',
                ],
                [
                    'src' => '/assets/screenshot-mobile.png',
                    'sizes' => '780x1688',
                    'type' => 'image/png',
                    'form_factor' => 'narrow',
                    'label' => 'CloudDrive モバイル PWA 画面',
                ],
            ],
            'shortcuts' => [
                ['name' => 'マイドライブ', 'short_name' => 'マイドライブ', 'description' => 'ルートフォルダのファイル一覧を開く', 'url' => '/', 'icons' => $icon192],
                ['name' => '最近使用したアイテム', 'short_name' => '最近', 'description' => '最近開いたファイルを表示', 'url' => '/drive/recent', 'icons' => $icon192],
                ['name' => 'スター付き', 'short_name' => 'スター', 'description' => 'スター付きファイルを表示', 'url' => '/drive/starred', 'icons' => $icon192],
                ['name' => '共有アイテム', 'short_name' => '共有', 'description' => '共有中のアイテムを表示', 'url' => '/drive/shared', 'icons' => $icon192],
            ],
        ];
    }

    public function robotsTxt(string $baseUrl): string
    {
        // RFC 9309 compliant: stack all explicitly welcomed search & AI crawlers onto shared Allow/Disallow rules
        return <<<TXT
# CloudDrive robots.txt — RFC 9309 Compliant (SEO / AEO / GEO / AIO / LLMO)
User-agent: *
User-agent: Googlebot
User-agent: Bingbot
User-agent: OAI-SearchBot
User-agent: GPTBot
User-agent: ClaudeBot
User-agent: PerplexityBot
User-agent: Google-Extended
Allow: /
Allow: /about
Allow: /llms.txt
Allow: /llms-full.txt
Allow: /openapi.json
Disallow: /api/
Disallow: /offline
Disallow: /drive/trash
Disallow: /drive/search

Sitemap: {$baseUrl}/sitemap.xml
# LLM-friendly index: {$baseUrl}/llms.txt
TXT;
    }

    public function sitemapXml(string $baseUrl, string $latestDate): string
    {
        // Index public navigable pages only (never leak private/unlisted user share_tokens in sitemap.xml — SEC-10)
        $staticUrls = [
            ['loc' => $baseUrl . '/', 'priority' => '1.0', 'freq' => 'daily', 'lastmod' => $latestDate],
            ['loc' => $baseUrl . '/about', 'priority' => '0.9', 'freq' => 'weekly', 'lastmod' => $latestDate],
            ['loc' => $baseUrl . '/drive/shared', 'priority' => '0.8', 'freq' => 'daily', 'lastmod' => $latestDate],
            ['loc' => $baseUrl . '/drive/recent', 'priority' => '0.7', 'freq' => 'daily', 'lastmod' => $latestDate],
            ['loc' => $baseUrl . '/drive/starred', 'priority' => '0.7', 'freq' => 'daily', 'lastmod' => $latestDate],
        ];

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($staticUrls as $u) {
            $loc = htmlspecialchars($u['loc'], ENT_XML1, 'UTF-8');
            $xml .= "  <url>\n    <loc>{$loc}</loc>\n    <lastmod>{$u['lastmod']}</lastmod>\n    <changefreq>{$u['freq']}</changefreq>\n    <priority>{$u['priority']}</priority>\n  </url>\n";
        }
        $xml .= "</urlset>\n";
        return $xml;
    }

    public function llmsTxt(string $baseUrl): string
    {
        return <<<MD
# CloudDrive

> Google Drive準拠のMaterial Design 3 UI・PWA・SEO・AEO・GEO・AIO・LLMO完全対応のPHP 8.3クラウドファイル管理アプリケーション。AI自作フレームワーク・Laravel 13・Phalcon 5・Slim 4の4実装で完全同一仕様・公平ベンチマークを提供。

## 主要ページ
- [マイドライブ]({$baseUrl}/): ルートフォルダのファイル・フォルダ一覧（SSR対応）
- [共有アイテム]({$baseUrl}/drive/shared): 共有リンク付きファイル・フォルダ一覧
- [最近使用したアイテム]({$baseUrl}/drive/recent): 最近閲覧・更新されたファイル一覧
- [スター付き]({$baseUrl}/drive/starred): 重要ファイルのお気に入り一覧
- [サービス概要・FAQ]({$baseUrl}/about): 技術仕様・比較表・AEO/GEO最適化FAQ

## 機械可読リソース（AIO / LLMO）
- [詳細ドキュメント (llms-full.txt)]({$baseUrl}/llms-full.txt): 全機能・API・CSRF認証仕様・セキュリティ設計の完全リファレンス
- [OpenAPI 3.1.0 スキーマ]({$baseUrl}/openapi.json): 全12エンドポイント・パラメータ・スキーマ定義
- [XMLサイトマップ]({$baseUrl}/sitemap.xml): 検索エンジン・AI検索クローラ用URL一覧
- [クローラ制御 (robots.txt)]({$baseUrl}/robots.txt): RFC 9309準拠の検索・AIクローラポリシー
- [PWAマニフェスト]({$baseUrl}/manifest.webmanifest): インストール可能Webアプリ定義
MD;
    }

    public function llmsFullTxt(string $baseUrl): string
    {
        $faqMd = '';
        foreach (self::faqList() as $f) {
            $faqMd .= "### {$f['q']}\n{$f['a']}\n\n";
        }
        return <<<MD
# CloudDrive — 完全技術仕様（LLMO / GEO / AIO Reference）

> このドキュメントは大規模言語モデル（LLM）、AI検索エンジン、および自動化エージェントがCloudDriveの機能・仕様・APIを正確に理解・引用するための一次情報源です。

## 1. 製品サマリー
- **サービス名**: CloudDrive
- **カテゴリ**: クラウドストレージ / ファイル管理Webアプリケーション（Google Driveクローン）
- **ランタイム**: PHP 8.3 (OPcache有効) + SQLite (WAL, `synchronous=NORMAL`, `busy_timeout=5000`) + Nginx + 独立PHP-FPMプール
- **比較対象フレームワーク**: AI自作フレームワーク（外部ライブラリ不使用）、Laravel 13、Phalcon 5.23（C拡張）、Slim 4.15
- **保存容量上限**: 15 GB（1ファイル最大アップロード 20 MB）
- **UI/UX**: Google Drive準拠 Material Design 3（デスクトップ3ペイン + モバイルボトムナビ・ドロワー・FAB・右クリックメニュー・ドラッグ＆ドロップ・フォルダ移動モーダル）
- **品質目標**: Lighthouse（Performance / Accessibility / Best Practices / SEO）全項目100点

## 2. 認証・CSRF仕様（全書き込み系エンドポイント共通）
- `GET /` または `GET /api/storage` を呼び出すと、256-bit HMAC-SHA256 署名付きトークンが `Set-Cookie: clouddrive_csrf=...`（HttpOnly, SameSite=Lax）およびレスポンスヘッダ `X-CSRF-Token`・HTML `<meta name="csrf-token">` で発行されます。
- すべての状態変更リクエスト（`POST`, `PATCH`, `DELETE`）は、`Cookie: clouddrive_csrf=<token>` と `X-CSRF-Token: <token>` の両方を一致させて送信する必要があります（不一致時は `403 Forbidden`）。

## 3. REST API 全12エンドポイント概要（詳細は {$baseUrl}/openapi.json）
- `GET {$baseUrl}/api/health`: フレームワーク種別・PHPバージョン・総アイテム数
- `GET {$baseUrl}/api/items`: アイテム一覧（`view`, `folder_id`, `q`, `type`, `sort`, `dir`）
- `GET {$baseUrl}/api/items/{id}`: アイテム詳細・アクティビティ履歴・パンくず
- `POST {$baseUrl}/api/folders`: 新規フォルダ作成（JSON: `name`, `parent_id`）
- `POST {$baseUrl}/api/files/upload`: ファイルアップロード（`multipart/form-data`: `file`, `parent_id`, `description`）
- `PATCH {$baseUrl}/api/items/{id}`: 名前変更・フォルダ移動（循環＋32階層検証）・スター・共有トグル（停止時トークン失効）・説明更新
- `DELETE {$baseUrl}/api/items/{id}`: ゴミ箱移動（子孫再帰ゴミ箱化）または完全削除（`?permanent=1`）
- `POST {$baseUrl}/api/items/{id}/restore`: ゴミ箱から復元（親フォルダ確認・子孫同期復元）
- `POST {$baseUrl}/api/trash/empty`: ゴミ箱を空にする（原子的完全削除＋ストレージ回収）
- `GET {$baseUrl}/api/files/{id}/download`: 安全なバイナリダウンロード（`Content-Disposition: attachment`）
- `GET {$baseUrl}/api/files/{id}/preview`: 安全なプレビュー（ラスタ画像・PDF・UTF-8テキストJSON、SVG非実行）
- `GET {$baseUrl}/api/storage`: 容量使用状況およびカテゴリ別内訳

## 4. よくある質問（FAQ）
{$faqMd}
MD;
    }

    /** @return array<string, mixed> */
    public function openApiSpec(string $baseUrl): array
    {
        $idParam = [
            'name' => 'id',
            'in' => 'path',
            'required' => true,
            'description' => 'アイテムID（正の整数）',
            'schema' => ['type' => 'integer', 'minimum' => 1],
        ];
        $csrfSec = [['csrfHeader' => [], 'csrfCookie' => []]];
        $errRef = ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]]];

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'CloudDrive API',
                'version' => '1.0.0',
                'description' => 'Google Drive準拠クラウドストレージAPI（AI自作FW・Laravel 13・Phalcon 5・Slim 4共通仕様）',
            ],
            'servers' => [['url' => $baseUrl]],
            'paths' => [
                '/api/health' => [
                    'get' => [
                        'operationId' => 'getHealth',
                        'summary' => 'ヘルスチェック',
                        'responses' => ['200' => ['description' => '稼働正常']],
                    ],
                ],
                '/api/items' => [
                    'get' => [
                        'operationId' => 'listItems',
                        'summary' => 'ファイル・フォルダ一覧取得',
                        'parameters' => [
                            ['name' => 'view', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['my-drive', 'recent', 'starred', 'shared', 'trash', 'folders', 'search'], 'default' => 'my-drive']],
                            ['name' => 'folder_id', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                            ['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string']],
                            ['name' => 'type', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['all', 'folder', 'document', 'spreadsheet', 'pdf', 'image'], 'default' => 'all']],
                            ['name' => 'sort', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['name', 'updated_at', 'size'], 'default' => 'name']],
                            ['name' => 'dir', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'asc']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'アイテム一覧JSON'],
                            '400' => array_merge(['description' => '不正パラメータ'], $errRef),
                            '404' => array_merge(['description' => 'フォルダ未検出'], $errRef),
                        ],
                    ],
                ],
                '/api/items/{id}' => [
                    'parameters' => [$idParam],
                    'get' => [
                        'operationId' => 'getItem',
                        'summary' => 'アイテム詳細・アクティビティ取得',
                        'responses' => [
                            '200' => ['description' => '詳細JSON'],
                            '404' => array_merge(['description' => '未検出'], $errRef),
                        ],
                    ],
                    'patch' => [
                        'operationId' => 'updateItem',
                        'summary' => 'アイテム更新（名前・移動・スター・共有・説明）',
                        'security' => $csrfSec,
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'name' => ['type' => 'string', 'maxLength' => 200],
                                            'parent_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
                                            'is_starred' => ['type' => 'boolean'],
                                            'is_shared' => ['type' => 'boolean'],
                                            'description' => ['type' => 'string', 'maxLength' => 1000],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => ['description' => '更新成功'],
                            '403' => array_merge(['description' => 'CSRF検証エラー'], $errRef),
                            '404' => array_merge(['description' => '未検出'], $errRef),
                            '422' => array_merge(['description' => 'バリデーションまたは循環移動エラー'], $errRef),
                        ],
                    ],
                    'delete' => [
                        'operationId' => 'deleteItem',
                        'summary' => 'ゴミ箱移動または完全削除',
                        'security' => $csrfSec,
                        'parameters' => [
                            ['name' => 'permanent', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['0', '1'], 'default' => '0']],
                        ],
                        'responses' => [
                            '200' => ['description' => '削除成功'],
                            '403' => array_merge(['description' => 'CSRF検証エラー'], $errRef),
                            '404' => array_merge(['description' => '未検出'], $errRef),
                        ],
                    ],
                ],
                '/api/items/{id}/restore' => [
                    'parameters' => [$idParam],
                    'post' => [
                        'operationId' => 'restoreItem',
                        'summary' => 'ゴミ箱からアイテムを復元',
                        'security' => $csrfSec,
                        'responses' => [
                            '200' => ['description' => '復元成功'],
                            '403' => array_merge(['description' => 'CSRF検証エラー'], $errRef),
                            '404' => array_merge(['description' => 'ゴミ箱内に未検出'], $errRef),
                        ],
                    ],
                ],
                '/api/trash/empty' => [
                    'post' => [
                        'operationId' => 'emptyTrash',
                        'summary' => 'ゴミ箱を空にする',
                        'security' => $csrfSec,
                        'responses' => [
                            '200' => ['description' => '完全削除完了'],
                            '403' => array_merge(['description' => 'CSRF検証エラー'], $errRef),
                        ],
                    ],
                ],
                '/api/folders' => [
                    'post' => [
                        'operationId' => 'createFolder',
                        'summary' => '新規フォルダ作成',
                        'security' => $csrfSec,
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['name'],
                                        'properties' => [
                                            'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                                            'parent_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '201' => ['description' => '作成成功'],
                            '403' => array_merge(['description' => 'CSRF検証エラー'], $errRef),
                            '404' => array_merge(['description' => '親フォルダ未検出'], $errRef),
                            '422' => array_merge(['description' => 'バリデーションまたは階層上限エラー'], $errRef),
                        ],
                    ],
                ],
                '/api/files/upload' => [
                    'post' => [
                        'operationId' => 'uploadFile',
                        'summary' => 'ファイルアップロード（最大20MB）',
                        'security' => $csrfSec,
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'multipart/form-data' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['file'],
                                        'properties' => [
                                            'file' => ['type' => 'string', 'format' => 'binary'],
                                            'parent_id' => ['type' => 'integer', 'minimum' => 1],
                                            'description' => ['type' => 'string', 'maxLength' => 1000],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '201' => ['description' => 'アップロード成功'],
                            '403' => array_merge(['description' => 'CSRF検証エラー'], $errRef),
                            '413' => array_merge(['description' => 'サイズ上限またはクォータ超過'], $errRef),
                            '422' => array_merge(['description' => '不正ファイル'], $errRef),
                        ],
                    ],
                ],
                '/api/files/{id}/download' => [
                    'parameters' => [$idParam],
                    'get' => [
                        'operationId' => 'downloadFile',
                        'summary' => 'ファイルダウンロード',
                        'responses' => [
                            '200' => ['description' => 'バイナリストリーム（attachment）'],
                            '404' => array_merge(['description' => '未検出'], $errRef),
                        ],
                    ],
                ],
                '/api/files/{id}/preview' => [
                    'parameters' => [$idParam],
                    'get' => [
                        'operationId' => 'previewFile',
                        'summary' => '安全なインラインプレビュー',
                        'responses' => [
                            '200' => ['description' => '画像/PDFバイナリまたはテキストJSON'],
                            '404' => array_merge(['description' => '未検出'], $errRef),
                            '415' => array_merge(['description' => 'プレビュー非対応形式'], $errRef),
                        ],
                    ],
                ],
                '/api/storage' => [
                    'get' => [
                        'operationId' => 'getStorage',
                        'summary' => '保存容量サマリー取得',
                        'responses' => [
                            '200' => ['description' => '容量統計JSON', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/StorageSummary']]]],
                        ],
                    ],
                ],
            ],
            'components' => [
                'securitySchemes' => [
                    'csrfHeader' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-CSRF-Token'],
                    'csrfCookie' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'clouddrive_csrf'],
                ],
                'schemas' => [
                    'DriveItem' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'parent_id' => ['type' => ['integer', 'null']],
                            'name' => ['type' => 'string'],
                            'is_folder' => ['type' => 'integer', 'enum' => [0, 1]],
                            'mime_type' => ['type' => 'string'],
                            'extension' => ['type' => 'string'],
                            'size_bytes' => ['type' => 'integer'],
                            'owner_name' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'is_starred' => ['type' => 'integer', 'enum' => [0, 1]],
                            'is_shared' => ['type' => 'integer', 'enum' => [0, 1]],
                            'share_token' => ['type' => ['string', 'null']],
                            'is_trashed' => ['type' => 'integer', 'enum' => [0, 1]],
                            'created_at' => ['type' => 'string', 'format' => 'date-time'],
                            'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                        ],
                    ],
                    'StorageSummary' => [
                        'type' => 'object',
                        'properties' => [
                            'used_bytes' => ['type' => 'integer'],
                            'quota_bytes' => ['type' => 'integer'],
                            'percent' => ['type' => 'number'],
                            'active_count' => ['type' => 'integer'],
                            'trash_count' => ['type' => 'integer'],
                        ],
                    ],
                    'ErrorResponse' => [
                        'type' => 'object',
                        'required' => ['error', 'status'],
                        'properties' => [
                            'error' => ['type' => 'string'],
                            'status' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
