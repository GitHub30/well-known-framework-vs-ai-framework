<?php
declare(strict_types=1);

namespace App\Services;

final class UiHelper
{
    private static ?string $cachedCss = null;

    public static function inlineCss(): string
    {
        if (self::$cachedCss === null) {
            $phpCache = storage_path('cache/inline_css.php');
            if (is_file($phpCache)) {
                self::$cachedCss = (string)(require $phpCache);
            } else {
                $minPath = public_path('assets/app.min.css');
                self::$cachedCss = is_file($minPath) ? (string)file_get_contents($minPath) : '';
            }
        }
        return self::$cachedCss;
    }

    public static function jsonSafe(mixed $v): string
    {
        return json_encode(
            $v,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
    }

    public static function formatBytes(int $bytes, bool $isFolder = false): string
    {
        if ($isFolder) {
            return '—';
        }
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $val = (float)$bytes;
        while ($val >= 1024 && $i < count($units) - 1) {
            $val /= 1024;
            $i++;
        }
        return ($i === 0 ? number_format($val, 0) : number_format($val, 1)) . ' ' . $units[$i];
    }

    public static function formatDate(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '—';
        }
        $ts = strtotime($iso);
        return $ts === false ? $iso : gmdate('Y/m/d', $ts);
    }

    /** @param array<string, mixed> $item */
    public static function itemIconSvg(array $item): string
    {
        if ((int)($item['is_folder'] ?? 0) === 1) {
            return '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" fill="#444746"><path d="M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>';
        }
        $ext = strtolower((string)($item['extension'] ?? ''));
        $mime = strtolower((string)($item['mime_type'] ?? ''));
        if (str_starts_with($mime, 'image/') || in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
            return '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" fill="#d93025"><path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg>';
        }
        if ($ext === 'pdf' || $mime === 'application/pdf') {
            return '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" fill="#ea4335"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm1 7V3.5L19.5 9H15zM8 14h8v2H8v-2zm0 4h5v2H8v-2z"/></svg>';
        }
        if (in_array($ext, ['csv', 'xls', 'xlsx'], true)) {
            return '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" fill="#0f9d58"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-8 14H5v-4h6v4zm0-6H5V7h6v4zm8 6h-6v-4h6v4zm0-6h-6V7h6v4z"/></svg>';
        }
        if (in_array($ext, ['md', 'txt', 'doc', 'docx'], true)) {
            return '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" fill="#1a73e8"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>';
        }
        return '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" fill="#5f6368"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13z"/></svg>';
    }
}
