<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\DriveService;
use App\Services\HttpException;
use App\Services\SeoService;
use Phalcon\Http\Response;

final class PageController extends BaseController
{
    public function homeAction(): Response
    {
        return $this->renderDriveView('my-drive', null, 'マイドライブ - CloudDrive', 'CloudDriveのマイドライブ。Google Drive準拠UIでファイル・フォルダを高速管理、ドラッグ＆ドロップアップロード、検索、共有に対応。');
    }

    public function folderAction(string $id): Response
    {
        $folderId = (int)$id;
        if ($folderId <= 0) {
            throw new HttpException(404, 'フォルダが見つかりません');
        }
        return $this->renderDriveView('my-drive', $folderId, null, null);
    }

    public function recentAction(): Response
    {
        return $this->renderDriveView('recent', null, '最近使用したアイテム - CloudDrive', 'CloudDriveで最近開いた・更新したファイル一覧を時系列で素早く確認できます。');
    }

    public function starredAction(): Response
    {
        return $this->renderDriveView('starred', null, 'スター付き - CloudDrive', '重要なファイルやフォルダにスターを付けて素早くアクセスできるCloudDriveのお気に入り一覧。');
    }

    public function sharedAction(): Response
    {
        return $this->renderDriveView('shared', null, '共有アイテム - CloudDrive', 'チームや外部リンクで共有されているファイル・フォルダ一覧を管理できます。');
    }

    public function trashAction(): Response
    {
        return $this->renderDriveView('trash', null, 'ゴミ箱 - CloudDrive', '削除予定のファイル・フォルダを確認し、ワンクリック復元または完全削除を実行できます。', 'noindex, follow');
    }

    public function searchAction(): Response
    {
        $q = trim($this->queryString('q', ''));
        $title = $q !== '' ? "検索「{$q}」 - CloudDrive" : '検索 - CloudDrive';
        return $this->renderDriveView('search', null, $title, 'CloudDrive内のファイル名・説明文から目的のドキュメントや画像を高速検索します。', 'noindex, follow');
    }

    public function shareAction(string $token): Response
    {
        $item = $this->drive()->getSharedByToken($token);
        if ($item === null) {
            throw new HttpException(404, '共有アイテムが見つからないか、公開が停止されています');
        }
        $isFolder = (int)$item['is_folder'] === 1;
        $children = $isFolder ? $this->drive()->getSharedChildren((int)$item['id']) : [];
        $storage = $this->drive()->getStorageSummary();
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

        $meta = $this->seo()->pageMeta(
            $baseUrl,
            '/share/' . $token,
            $item['name'] . "（{$kindLabel}） - CloudDrive",
            $item['description'] !== '' ? $item['description'] : "CloudDriveで共有されている{$item['name']}の閲覧・ダウンロードページ。",
            [['name' => $item['name'], 'url' => '/share/' . $token]],
            false,
            'noindex, follow',
            $extraSchema
        );

        return $this->renderPage('share', [
            'meta' => $meta,
            'csrfToken' => $this->csrfToken(),
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

    public function aboutAction(): Response
    {
        $storage = $this->drive()->getStorageSummary();
        $meta = $this->seo()->pageMeta(
            $this->safeBaseUrl(),
            '/about',
            'CloudDriveについて・技術仕様・FAQ - CloudDrive',
            'Google Drive準拠のPHP 8.3クラウドストレージ「CloudDrive」の設計仕様、4フレームワーク性能比較、PWA・SEO・AEO・GEO・AIO・LLMO最適化ガイドおよびFAQ。',
            [['name' => 'サービス概要・FAQ', 'url' => '/about']],
            true
        );

        return $this->renderPage('about', [
            'meta' => $meta,
            'csrfToken' => $this->csrfToken(),
            'activeView' => 'about',
            'storage' => $storage,
            'faqs' => SeoService::faqList(),
            'q' => '',
            'frameworkLabel' => SeoService::FRAMEWORK_LABEL,
            'frameworkId' => SeoService::FRAMEWORK_ID,
            'driveState' => null,
        ]);
    }

    public function offlineAction(): Response
    {
        $meta = $this->seo()->pageMeta(
            $this->safeBaseUrl(),
            '/offline',
            'オフライン - CloudDrive',
            '現在オフラインです。ネットワーク接続が回復するとCloudDriveの最新ファイルに再接続できます。',
            [['name' => 'オフライン', 'url' => '/offline']],
            false,
            'noindex, nofollow'
        );

        return $this->renderPage('offline', [
            'meta' => $meta,
            'csrfToken' => $this->csrfToken(),
            'activeView' => '',
            'storage' => ['used_bytes' => 0, 'quota_bytes' => DriveService::QUOTA_BYTES, 'percent' => 0],
            'q' => '',
            'frameworkLabel' => SeoService::FRAMEWORK_LABEL,
            'frameworkId' => SeoService::FRAMEWORK_ID,
            'driveState' => null,
        ]);
    }

    public function notFoundAction(): Response
    {
        throw new HttpException(404, 'Not Found');
    }

    private function renderDriveView(
        string $view,
        ?int $folderId,
        ?string $title,
        ?string $description,
        string $robots = 'index, follow, max-image-preview:large, max-snippet:-1',
    ): Response {
        $q = trim($this->queryString('q', ''));
        $type = $this->queryString('type', 'all');
        $sort = $this->queryString('sort', $view === 'recent' ? 'updated_at' : 'name');
        $dir = $this->queryString('dir', $view === 'recent' ? 'desc' : 'asc');

        $data = $this->drive()->listItems($view, $folderId, $q, $type, $sort, $dir);

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

        $path = $this->currentPath();
        $metaBreadcrumbs = $data['breadcrumbs'];
        if ($folderId === null && $view !== 'my-drive') {
            $metaBreadcrumbs = [['name' => $viewTitles[$view] ?? 'マイドライブ', 'url' => $path]];
        }

        $meta = $this->seo()->pageMeta(
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

        return $this->renderPage('drive', [
            'meta' => $meta,
            'csrfToken' => $this->csrfToken(),
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
}
