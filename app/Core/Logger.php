<?php declare(strict_types=1);

namespace App\Core;

class Logger
{
    private static string $logDir = '';

    private static function logDir(): string
    {
        if (self::$logDir === '') {
            self::$logDir = dirname(__DIR__, 2) . '/storage/logs';
        }
        return self::$logDir;
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $logDir = self::logDir();
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $file = $logDir . '/litecms.log';

        // Rotate if > 5 MB
        if (file_exists($file) && filesize($file) > 5 * 1024 * 1024) {
            self::rotate($file);
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = $context !== [] ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
        $line = "[{$timestamp}] [{$level}] {$message}{$contextStr}" . PHP_EOL;

        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    private static function rotate(string $file): void
    {
        // Keep max 3 rotations
        for ($i = 3; $i >= 1; $i--) {
            $old = $file . '.' . $i;
            if ($i === 3 && file_exists($old)) {
                @unlink($old);
            }
            if ($i > 1) {
                $prev = $file . '.' . ($i - 1);
                if (file_exists($prev)) {
                    @rename($prev, $old);
                }
            }
        }
        @rename($file, $file . '.1');
    }
}
