<?php
declare(strict_types=1);

namespace AiFramework;

/**
 * Zero-dependency PSR-4 style autoloader for the AI Custom Framework.
 */
final class Autoloader
{
    /** @var array<string, string> */
    private static array $prefixes = [];

    public static function register(array $prefixes): void
    {
        self::$prefixes = $prefixes;
        spl_autoload_register([self::class, 'load'], true, true);
    }

    public static function load(string $class): void
    {
        foreach (self::$prefixes as $prefix => $baseDir) {
            if (str_starts_with($class, $prefix)) {
                $relative = substr($class, strlen($prefix));
                $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
                if (is_file($file)) {
                    require $file;
                }
                return;
            }
        }
    }
}
