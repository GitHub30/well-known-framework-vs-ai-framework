<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Cryptographic CSRF protection, Unicode-safe filename sanitization, strict ID parsing, and path-jail utilities.
 */
final class Security
{
    public const CSRF_COOKIE = 'clouddrive_csrf';
    public const CSRF_TTL = 43200; // 12 hours

    private const PUBLIC_CACHEABLE_PATHS = [
        '/manifest.webmanifest' => true,
        '/robots.txt' => true,
        '/sitemap.xml' => true,
        '/llms.txt' => true,
        '/llms-full.txt' => true,
        '/openapi.json' => true,
        '/api/health' => true,
    ];

    public function __construct(private readonly string $secretKey)
    {
        if (strlen($secretKey) < 32) {
            throw new \RuntimeException('APP_SECRET must be configured with at least 32 bytes of entropy.');
        }
    }

    public static function isPublicCacheablePath(string $path): bool
    {
        return isset(self::PUBLIC_CACHEABLE_PATHS[$path]);
    }

    public function issueCsrfToken(): string
    {
        $ts = (string)time();
        $nonce = bin2hex(random_bytes(16));
        $payload = $ts . '.' . $nonce;
        $sig = hash_hmac('sha256', $payload, $this->secretKey);
        return $payload . '.' . $sig;
    }

    public function verifyCsrfToken(?string $token): bool
    {
        if (!is_string($token) || substr_count($token, '.') !== 2) {
            return false;
        }
        [$ts, $nonce, $sig] = explode('.', $token, 3);
        if (!ctype_digit($ts) || !ctype_xdigit($nonce) || strlen($nonce) !== 32 || strlen($sig) !== 64) {
            return false;
        }
        $age = time() - (int)$ts;
        if ($age < -60 || $age > self::CSRF_TTL) {
            return false;
        }
        $expected = hash_hmac('sha256', $ts . '.' . $nonce, $this->secretKey);
        return hash_equals($expected, $sig);
    }

    public function verifyDoubleSubmit(?string $cookieToken, ?string $headerToken): bool
    {
        if (!$this->verifyCsrfToken($cookieToken) || !$this->verifyCsrfToken($headerToken)) {
            return false;
        }
        return hash_equals((string)$cookieToken, (string)$headerToken);
    }

    public static function sanitizeItemName(string $name): string
    {
        $clean = mb_scrub($name, 'UTF-8');
        // Strip ASCII control chars, slashes, and Unicode Bidi/RTLO spoofing controls
        $clean = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', ' ', $clean) ?? '';
        $clean = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $clean) ?? '';
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '');
        $clean = trim(mb_substr($clean, 0, 200, 'UTF-8'));
        if ($clean === '' || $clean === '.' || $clean === '..') {
            return '';
        }
        return $clean;
    }

    public static function parseOptionalPositiveInt(mixed $value, string $fieldName = 'id', int $errorStatus = 422): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value) || is_array($value) || is_object($value)) {
            throw new HttpException($errorStatus, "{$fieldName} の形式が不正です");
        }
        if (is_int($value)) {
            if ($value < 1) {
                throw new HttpException($errorStatus, "{$fieldName} は正の整数で指定してください");
            }
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            $intVal = (int)$value;
            if ($intVal >= 1) {
                return $intVal;
            }
        }
        throw new HttpException($errorStatus, "{$fieldName} は正の整数で指定してください");
    }

    public static function requireString(mixed $value, string $fieldName, bool $allowNull = false): ?string
    {
        if ($value === null && $allowNull) {
            return null;
        }
        if (!is_string($value)) {
            throw new HttpException(422, "{$fieldName} は文字列で指定してください");
        }
        return mb_scrub($value, 'UTF-8');
    }

    public static function resolveJailedPath(string $baseDir, string $storageName): ?string
    {
        $base = realpath($baseDir);
        if ($base === false) {
            return null;
        }
        $safeName = basename($storageName);
        if ($safeName === '' || $safeName !== $storageName || str_contains($safeName, '..')) {
            return null;
        }
        $full = realpath($base . DIRECTORY_SEPARATOR . $safeName);
        if ($full === false || !str_starts_with($full, $base . DIRECTORY_SEPARATOR) || !is_file($full)) {
            return null;
        }
        return $full;
    }
}
