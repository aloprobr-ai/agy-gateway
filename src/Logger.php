<?php
declare(strict_types=1);

/**
 * Логи в файл logs/YYYY-MM-DD.log
 */
final class Logger
{
    private static array $cfg = [];

    public static function init(array $cfg): void
    {
        self::$cfg = $cfg;
    }

    public static function line(string $level, string $message, array $context = []): void
    {
        if (empty(self::$cfg['log_enabled'])) {
            return;
        }
        $dir = dirname(__DIR__) . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $row = sprintf(
            "[%s] %s %s %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            Http::clientIp(),
            $message,
            $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        @file_put_contents($dir . '/' . date('Y-m-d') . '.log', $row, FILE_APPEND | LOCK_EX);
    }

    public static function request(string $route, array $context = []): void
    {
        if (empty(self::$cfg['log_requests'])) {
            return;
        }
        self::line('info', $route, $context);
    }

    /** Отдельный лог тел запросов — только если включено в конфиге. */
    public static function body(string $tag, $data): void
    {
        if (empty(self::$cfg['log_bodies'])) {
            return;
        }
        $dir = dirname(__DIR__) . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $payload = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents(
            $dir . '/' . date('Y-m-d') . '.bodies.log',
            sprintf("[%s] %s: %s\n", date('Y-m-d H:i:s'), $tag, (string) $payload),
            FILE_APPEND | LOCK_EX
        );
    }
}
