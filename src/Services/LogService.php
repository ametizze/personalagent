<?php

namespace App\Services;

class LogService
{
    private static string $path = '';

    public static function init(string $path): void
    {
        self::$path = $path;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        if (($_ENV['LOG_LEVEL'] ?? 'info') === 'debug') {
            self::write('DEBUG', $message, $context);
        }
    }

    private static function write(string $level, string $message, array $context): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $ctx = empty($context) ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        $line = "[{$timestamp}] [{$level}] {$message}{$ctx}" . PHP_EOL;

        if (self::$path) {
            file_put_contents(self::$path, $line, FILE_APPEND | LOCK_EX);
        }

        if (php_sapi_name() === 'cli' && ($_ENV['APP_ENV'] ?? '') !== 'testing') {
            echo $line;
        }
    }
}
