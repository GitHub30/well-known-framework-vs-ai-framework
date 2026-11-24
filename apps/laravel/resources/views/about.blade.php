@extends('layouts.app')

@section('content')
<article class="content-page">
  <h1>CloudDrive — サービス概要・技術仕様・FAQ</h1>

  <section class="tldr-box" aria-label="概要サマリー">
    <strong>要約（GEO / AEO サマリー）:</strong>
    CloudDriveは、Google Driveと同一のMaterial Design 3 UI・操作性を備えた高速PHP 8.3クラウドストレージアプリケーションです。依存ゼロのAI自作フレームワーク、Laravel、Phalcon 5（C拡張）、Slim 4の4環境で完全同一仕様を実装し、PWA・SEO・AEO・GEO・AIO・LLMOおよびLighthouse全項目100点を達成しています。
  </section>

  <h2>技術仕様と品質基準</h2>
  <table class="spec-table">
    <thead>
      <tr>
        <th scope="col">項目</th>
        <th scope="col">仕様・達成基準</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>比較フレームワーク</td>
        <td>AI自作フレームワーク（外部ライブラリ不使用）、Laravel 13、Phalcon 5.23（C拡張）、Slim 4.15</td>
      </tr>
      <tr>
        <td>実行環境</td>
        <td>PHP 8.3 (OPcache有効) + Nginx + 独立PHP-FPMプール + SQLite (WAL)</td>
      </tr>
      <tr>
        <td>保存容量・アップロード</td>
        <td>15 GB クォータ、1ファイル最大 20 MB、ドラッグ＆ドロップ・マルチアップロード対応</td>
      </tr>
      <tr>
        <td>PWA・モバイル対応</td>
        <td>Web App Manifest、Service Worker オフラインキャッシュ、モバイルボトムナビ・ドロワー・FAB</td>
      </tr>
      <tr>
        <td>SEO / AEO / GEO / AIO / LLMO</td>
        <td>SSR HTML、JSON-LD（WebApplication / SearchAction / BreadcrumbList / FAQPage）、robots.txt、動的sitemap.xml、llms.txt、llms-full.txt、OpenAPI 3.1</td>
      </tr>
      <tr>
        <td>セキュリティ</td>
        <td>プリペアドステートメント、HMAC-SHA256 CSRF保護、realpath検証、CSP、循環フォルダ移動防止</td>
      </tr>
    </tbody>
  </table>

  <h2>よくある質問（AEO / FAQ）</h2>
  @foreach($faqs as $faq)
    <details class="faq-item" open>
      <summary>{{ $faq['q'] }}</summary>
      <p>{{ $faq['a'] }}</p>
    </details>
  @endforeach

  <h2>機械可読・AI検索向けエンドポイント（AIO / LLMO）</h2>
  <ul>
    <li><a href="/llms.txt">/llms.txt — LLM向けMarkdownインデックス</a></li>
    <li><a href="/llms-full.txt">/llms-full.txt — LLM向け完全技術リファレンス</a></li>
    <li><a href="/openapi.json">/openapi.json — OpenAPI 3.1 REST API定義</a></li>
    <li><a href="/sitemap.xml">/sitemap.xml — 動的XMLサイトマップ</a></li>
    <li><a href="/robots.txt">/robots.txt — 検索・AIクローラ制御ディレクティブ</a></li>
    <li><a href="/manifest.webmanifest">/manifest.webmanifest — PWA Web App Manifest</a></li>
  </ul>
</article>
@endsection
