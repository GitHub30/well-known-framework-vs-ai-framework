<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DriveService;
use App\Services\SeoService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PageController extends Controller
{
    public function __construct(
        private readonly DriveService $drive,
        private readonly SeoService $seo,
    ) {}

    public function home(Request $request): Response
    {
        return $this->renderDriveView($request, 'my-drive', null, 'マイドライブ - CloudDrive', 'CloudDriveのマイドライブ。Google Drive準拠UIでファイル・フォルダを高速管理、ドラッグ＆ドロップアップロード、検索、共有に対応。');
    }

    public function folder(Request $request, string $id): Response
    {
        $folderId = (int)$id;
        if ($folderId <= 0) {
            throw new HttpException(404, 'フォルダが見つかりません');
        }
        return $this->renderDriveView($request, 'my-drive', $folderId, null, null);
    }

    public function recent(Request $request): Response
    {
        return $this->renderDriveView($request, 'recent', null, '最近使用したアイテム - CloudDrive', 'CloudDriveで最近開いた・更新したファイル一覧を時系列で素早く確認できます。');
    }

    public function starred(Request $request): Response
    {
        return $this->renderDriveView($request, 'starred', null, 'スター付き - CloudDrive', '重要なファイルやフォルダにスターを付けて素早くアクセスできるCloudDriveのお気に入り一覧。');
    }

    public function shared(Request $request): Response
    {
        return $this->renderDriveView($request, 'shared', null, '共有アイテム - CloudDrive', 'チームや外部リンクで共有されているファイル・フォルダ一覧を管理できます。');
    }

    public function trash(Request $request): Response
    {
        return $this->renderDriveView($request, 'trash', null, 'ゴミ箱 - CloudDrive', '削除予定のファイル・フォルダを確認し、ワンクリック復元または完全削除を実行できます。', 'noindex, follow');
    }

    public function search(Request $request): Response
    {
        $q = trim($this->queryString($request, 'q', ''));
        $title = $q !== '' ? "検索「{$q}」 - CloudDrive" : '検索 - CloudDrive';
        return $this->renderDriveView($request, 'search', null, $title, 'CloudDrive内のファイル名・説明文から目的のドキュメントや画像を高速検索します。', 'noindex, follow');
    }

    public function share(Request $request, string $token): Response
    {
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

        return response()->view('share', [
            'meta' => $meta,
            'csrfToken' => (string)$request->attributes->get('csrf_token', ''),
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

    public function about(Request $request): Response
    {
        $storage = $this->drive->getStorageSummary();
        $baseUrl = $this->safeBaseUrl();
        $meta = $this->seo->pageMeta(
            $baseUrl,
            '/about',
            'CloudDriveについて・技術仕様・FAQ - CloudDrive',
            'Google Drive準拠のPHP 8.3クラウドストレージ「CloudDrive」の設計仕様、4フレームワーク性能比較、PWA・SEO・AEO・GEO・AIO・LLMO最適化ガイドおよびFAQ。',
            [['name' => 'サービス概要・FAQ', 'url' => '/about']],
            true
        );

        return response()->view('about', [
            'meta' => $meta,
            'csrfToken' => (string)$request->attributes->get('csrf_token', ''),
            'activeView' => 'about',
            'storage' => $storage,
            'faqs' => SeoService::faqList(),
            'q' => '',
            'frameworkLabel' => SeoService::FRAMEWORK_LABEL,
            'frameworkId' => SeoService::FRAMEWORK_ID,
            'driveState' => null,
        ]);
    }

    public function offline(Request $request): Response
    {
        $baseUrl = $this->safeBaseUrl();
        $meta = $this->seo->pageMeta(
            $baseUrl,
            '/offline',
            'オフライン - CloudDrive',
            '現在オフラインです。ネットワーク接続が回復するとCloudDriveの最新ファイルに再接続できます。',
            [['name' => 'オフライン', 'url' => '/offline']],
            false,
            'noindex, nofollow'
        );

        return response()->view('offline', [
            'meta' => $meta,
            'csrfToken' => (string)$request->attributes->get('csrf_token', ''),
            'activeView' => '',
            'storage' => ['used_bytes' => 0, 'quota_bytes' => DriveService::QUOTA_BYTES, 'percent' => 0],
            'q' => '',
            'frameworkLabel' => SeoService::FRAMEWORK_LABEL,
            'frameworkId' => SeoService::FRAMEWORK_ID,
            'driveState' => null,
        ]);
    }

    private function renderDriveView(
        Request $request,
        string $view,
        ?int $folderId,
        ?string $title,
        ?string $description,
        string $robots = 'index, follow, max-image-preview:large, max-snippet:-1',
    ): Response {
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

        $path = '/' . trim($request->path(), '/');
        if ($path === '//') {
            $path = '/';
        }

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

        return response()->view('drive', [
            'meta' => $meta,
            'csrfToken' => (string)$request->attributes->get('csrf_token', ''),
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

    private function queryString(Request $request, string $key, string $default): string
    {
        if (!$request->query->has($key)) {
            return $default;
        }
        $val = $request->query->all()[$key] ?? null;
        if (!is_string($val)) {
            throw new HttpException(400, "パラメータ {$key} の形式が不正です");
        }
        return mb_scrub($val, 'UTF-8');
    }

    private function safeBaseUrl(): string
    {
        $configured = getenv('APP_URL') ?: (string)config('app.url', 'http://localhost:8082');
        return rtrim($configured, '/');
    }
}
