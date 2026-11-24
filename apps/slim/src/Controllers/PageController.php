<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\DriveService;
use App\Services\HttpException;
use App\Services\SeoService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\PhpRenderer;

final class PageController
{
    public function __construct(
        private readonly DriveService $drive,
        private readonly SeoService $seo,
        private readonly PhpRenderer $view,
    ) {}

    public function home(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->renderDriveView($request, $response, 'my-drive', null, 'マイドライブ - CloudDrive', 'CloudDriveのマイドライブ。Google Drive準拠UIでファイル・フォルダを高速管理、ドラッグ＆ドロップアップロード、検索、共有に対応。');
    }

    /** @param array<string, string> $args */
    public function folder(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) {
            throw new HttpException(404, 'フォルダが見つかりません');
        }
        return $this->renderDriveView($request, $response, 'my-drive', $id, null, null);
    }

    public function recent(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->renderDriveView($request, $response, 'recent', null, '最近使用したアイテム - CloudDrive', 'CloudDriveで最近開いた・更新したファイル一覧を時系列で素早く確認できます。');
    }

    public function starred(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->renderDriveView($request, $response, 'starred', null, 'スター付き - CloudDrive', '重要なファイルやフォルダにスターを付けて素早くアクセスできるCloudDriveのお気に入り一覧。');
    }

    public function shared(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->renderDriveView($request, $response, 'shared', null, '共有アイテム - CloudDrive', 'チームや外部リンクで共有されているファイル・フォルダ一覧を管理できます。');
    }

    public function trash(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->renderDriveView($request, $response, 'trash', null, 'ゴミ箱 - CloudDrive', '削除予定のファイル・フォルダを確認し、ワンクリック復元または完全削除を実行できます。', 'noindex, follow');
    }

    public function search(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = trim($this->queryString($request, 'q', ''));
        $title = $q !== '' ? "検索「{$q}」 - CloudDrive" : '検索 - CloudDrive';
        return $this->renderDriveView($request, $response, 'search', null, $title, 'CloudDrive内のファイル名・説明文から目的のドキュメントや画像を高速検索します。', 'noindex, follow');
    }

    /** @param array<string, string> $args */
    public function share(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = (string)($args['token'] ?? '');
        $item = $this->drive->getSharedByToken($token);
        if ($item === null) {
            throw new HttpException(404, '共有アイテムが見つからないか、公開が停止されています');
        }
        $isFolder = (int)$item['is_folder'] === 1;
        $children = $isFolder ? $this->drive->getSharedChildren((int)$item['id']) : [];
        $storage = $this->drive->getStorageSummary();
        $baseUrl = $this->safeBaseUrl();
        $kindLabel = $isFolder ? '共有フォルダ' : '共有ファイル';

        $extraSchema = [
            '@type' => $isFolder ? 'CollectionPage' : 'DigitalDocument',
            'name' => $item['name'],
            'description' => $item['description'] !== '' ? $item['description'] : "CloudDriveで公開共有されている{$kindLabel}。",
            'dateModified' => $item['updated_at'],
            'encodingFormat' => $item['mime_type'],
            'url' => $baseUrl . '/share/' . $token,
        ];

        $meta = $this->seo->pageMeta(
            $baseUrl,
            '/share/' . $token,
            $item['name'] . "（{$kindLabel}） - CloudDrive",
            $item['description'] !== '' ? $item['description'] : "CloudDriveで共有されている{$item['name']}の閲覧・ダウンロードページ。",
            [['name' => $item['name'], 'url' => '/share/' . $token]],
            false,
            'noindex, follow',
            $extraSchema
        );

        return $this->render($response, 'share.php', [
            'meta' => $meta,
            'csrfToken' => (string)$request->getAttribute('csrf_token', ''),
            'activeView' => 'shared',
            'storage' => $storage,
            'item' => $item,
            'children' => $children,
            'q' => '',
            'frameworkLabel' => SeoService::FRAMEWORK_LABEL,
            'frameworkId' => SeoService::FRAMEWORK_ID,
            'driveState' => null,
        ]);
    }

    public function about(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $storage = $this->drive->getStorageSummary();
        $meta = $this->seo->pageMeta(
            $this->safeBaseUrl(),
            '/about',
            'CloudDriveについて・技術仕様・FAQ - CloudDrive',
            'Google Drive準拠のPHP 8.3クラウドストレージ「CloudDrive」の設計仕様、4フレームワーク性能比較、PWA・SEO・AEO・GEO・AIO・LLMO最適化ガイドおよびFAQ。',
            [['name' => 'サービス概要・FAQ', 'url' => '/about']],
            true
        );

        return $this->render($response, 'about.php', [
            'meta' => $meta,
            'csrfToken' => (string)$request->getAttribute('csrf_token', ''),
            'activeView' => 'about',
            'storage' => $storage,
            'faqs' => SeoService::faqList(),
            'q' => '',
            'frameworkLabel' => SeoService::FRAMEWORK_LABEL,
            'frameworkId' => SeoService::FRAMEWORK_ID,
            'driveState' => null,
        ]);
    }

    public function offline(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $meta = $this->seo->pageMeta(
            $this->safeBaseUrl(),
            '/offline',
            'オフライン - CloudDrive',
            '現在オフラインです。ネットワーク接続が回復するとCloudDriveの最新ファイルに再接続できます。',
            [['name' => 'オフライン', 'url' => '/offline']],
            false,
            'noindex, nofollow'
        );

        return $this->render($response, 'offline.php', [
            'meta' => $meta,
            'csrfToken' => (string)$request->getAttribute('csrf_token', ''),
            'activeView' => '',
            'storage' => ['used_bytes' => 0, 'quota_bytes' => DriveService::QUOTA_BYTES, 'percent' => 0],
            'q' => '',
            'frameworkLabel' => SeoService::FRAMEWORK_LABEL,
            'frameworkId' => SeoService::FRAMEWORK_ID,
            'driveState' => null,
        ]);
    }

    private function renderDriveView(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $view,
        ?int $folderId,
        ?string $title,
        ?string $description,
        string $robots = 'index, follow, max-image-preview:large, max-snippet:-1',
    ): ResponseInterface {
        $q = trim($this->queryString($request, 'q', ''));
        $type = $this->queryString($request, 'type', 'all');
        $sort = $this->queryString($request, 'sort', $view === 'recent' ? 'updated_at' : 'name');
        $dir = $this->queryString($request, 'dir', $view === 'recent' ? 'desc' : 'asc');

        $data = $this->drive->listItems($view, $folderId, $q, $type, $sort, $dir);

        if ($folderId !== null && $data['folder'] !== null) {
            $title = $data['folder']['name'] . ' - マイドライブ - CloudDrive';
            $description = $data['folder']['description'] !== ''
                ? $data['folder']['description']
                : "CloudDriveのフォルダ「{$data['folder']['name']}」に含まれるファイルとサブフォルダ一覧。";
        }

        $viewTitles = [
            'my-drive' => 'マイドライブ',
            'recent' => '最近使用したアイテム',
            'starred' => 'スター付き',
            'shared' => '共有アイテム',
            'trash' => 'ゴミ箱',
            'search' => $q !== '' ? "「{$q}」の検索結果" : 'ドライブを検索',
        ];

        $path = $request->getUri()->getPath() ?: '/';
        $metaBreadcrumbs = $data['breadcrumbs'];
        if ($folderId === null && $view !== 'my-drive') {
            $metaBreadcrumbs = [['name' => $viewTitles[$view] ?? 'マイドライブ', 'url' => $path]];
        }

        $meta = $this->seo->pageMeta(
            $this->safeBaseUrl(),
            $path,
            $title ?? 'CloudDrive',
            $description ?? 'Google Drive準拠の高速PHPクラウドストレージ。',
            $metaBreadcrumbs,
            false,
            $robots
        );

        $driveState = [
            'view' => $view,
            'folderId' => $folderId,
            'items' => $data['items'],
            'breadcrumbs' => $data['breadcrumbs'],
            'storage' => $data['storage'],
            'q' => $q,
            'type' => $type,
            'sort' => $sort,
            'dir' => $dir,
            'framework' => SeoService::FRAMEWORK_ID,
        ];

        return $this->render($response, 'drive.php', [
            'meta' => $meta,
            'csrfToken' => (string)$request->getAttribute('csrf_token', ''),
            'activeView' => $view,
            'headingTitle' => $viewTitles[$view] ?? 'マイドライブ',
            'folder' => $data['folder'],
            'breadcrumbs' => $data['breadcrumbs'],
            'items' => $data['items'],
            'storage' => $data['storage'],
            'q' => $q,
            'type' => $type,
            'sort' => $sort,
            'dir' => $dir,
            'frameworkLabel' => SeoService::FRAMEWORK_LABEL,
            'frameworkId' => SeoService::FRAMEWORK_ID,
            'driveState' => $driveState,
        ]);
    }

    private function queryString(ServerRequestInterface $request, string $key, string $default): string
    {
        $qp = $request->getQueryParams();
        if (!array_key_exists($key, $qp)) {
            return $default;
        }
        $val = $qp[$key];
        if (!is_string($val)) {
            throw new HttpException(400, "パラメータ {$key} の形式が不正です");
        }
        return mb_scrub($val, 'UTF-8');
    }

    /** @param array<string, mixed> $data */
    private function render(ResponseInterface $response, string $template, array $data): ResponseInterface
    {
        $e = static fn(mixed $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $jsonSafe = static fn(mixed $v): string => json_encode(
            $v,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
        $data['e'] = $e;
        $data['jsonSafe'] = $jsonSafe;
        return $this->view->render($response->withHeader('Content-Type', 'text/html; charset=utf-8'), $template, $data);
    }

    private function safeBaseUrl(): string
    {
        $configured = getenv('APP_URL');
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }
        return 'http://localhost:8084';
    }
}
