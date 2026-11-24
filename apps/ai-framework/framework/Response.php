<?php
declare(strict_types=1);

namespace AiFramework;

final class Response
{
    /** @var list<array{name:string,value:string,options:array<string,mixed>}> */
    private array $cookies = [];
    private ?string $filePath = null;

    /** @param array<string, string> $headers */
    public function __construct(
        private string $body = '',
        private int $status = 200,
        private array $headers = [],
    ) {}

    public static function html(string $html, int $status = 200, array $headers = []): self
    {
        return new self($html, $status, array_merge(['Content-Type' => 'text/html; charset=utf-8'], $headers));
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $encoded = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
        return new self($encoded, $status, array_merge(['Content-Type' => 'application/json; charset=utf-8'], $headers));
    }

    public static function text(string $text, int $status = 200, string $contentType = 'text/plain; charset=utf-8', array $headers = []): self
    {
        return new self($text, $status, array_merge(['Content-Type' => $contentType], $headers));
    }

    public static function file(string $absolutePath, string $contentType, ?string $downloadName = null, array $headers = []): self
    {
        $res = new self('', 200, array_merge([
            'Content-Type' => $contentType,
            'Content-Length' => (string)filesize($absolutePath),
        ], $headers));
        if ($downloadName !== null) {
            $safeAscii = preg_replace('/[^a-zA-Z0-9._-]/', '_', $downloadName) ?: 'download';
            $utf8 = rawurlencode($downloadName);
            $res = $res->withHeader('Content-Disposition', "attachment; filename=\"{$safeAscii}\"; filename*=UTF-8''{$utf8}");
        }
        $res->filePath = $absolutePath;
        return $res;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function hasHeader(string $name): bool
    {
        $target = strtolower($name);
        foreach ($this->headers as $k => $_) {
            if (strtolower($k) === $target) {
                return true;
            }
        }
        return false;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    /** @param array<string, mixed> $options */
    public function withCookie(string $name, string $value, array $options = []): self
    {
        $clone = clone $this;
        $clone->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options];
        return $clone;
    }

    public function send(bool $isHead = false): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        foreach ($this->cookies as $c) {
            setcookie($c['name'], $c['value'], $c['options']);
        }
        if ($isHead) {
            return;
        }
        if ($this->filePath !== null) {
            readfile($this->filePath);
            return;
        }
        echo $this->body;
    }
}
