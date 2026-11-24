<?php
declare(strict_types=1);

namespace AiFramework;

final class View
{
    public function __construct(private readonly string $templateDir) {}

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        $content = $this->renderFile($template, $data);
        if ($layout === null) {
            return $content;
        }
        return $this->renderFile($layout, array_merge($data, ['content' => $content]));
    }

    /** @param array<string, mixed> $data */
    private function renderFile(string $template, array $data): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\/-]/', '', $template);
        $path = $this->templateDir . '/' . $safe . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException("Template not found: {$safe}");
        }

        $e = static fn(mixed $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $jsonSafe = static fn(mixed $v): string => json_encode(
            $v,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );

        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $path;
            return (string)ob_get_clean();
        } catch (\Throwable $ex) {
            ob_end_clean();
            throw $ex;
        }
    }
}
