<?php
declare(strict_types=1);

/**
 * Файлы, которые модель сделала сама во время ответа: картинки, графики, таблицы.
 *
 * CLI сохраняет их в рабочий каталог беседы на сервере. Оттуда клиенту их не
 * видно, поэтому копируем в storage/outfiles под случайным именем и отдаём по
 * ссылке /files/<имя>. Имя — 32 шестнадцатеричных знака: угадать нельзя,
 * а заголовок Authorization тег <img> послать не может.
 */
final class Files
{
    /** Что вообще имеет смысл отдавать. */
    private const TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'bmp' => 'image/bmp',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain; charset=utf-8',
        'md' => 'text/markdown; charset=utf-8',
        'csv' => 'text/csv; charset=utf-8',
        'json' => 'application/json',
        'html' => 'text/plain; charset=utf-8',   // намеренно не text/html: не даём выполнить
        'zip' => 'application/zip',
    ];

    private const IMAGES = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp'];

    public static function dir(): string
    {
        // Лежат внутри docroot: nginx настроен отдавать картинки сам, до PHP
        // такой запрос просто не доходит. Имена случайные, листинга каталога нет,
        // исполняемых расширений в списке разрешённых нет.
        $dir = dirname(__DIR__) . '/public/files';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /**
     * Кладёт файл в раздачу и возвращает описание для ответа.
     * @return array{name:string,url:string,image:bool}|null
     */
    public static function publish(string $path, array $cfg): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) {
            return null;
        }
        $max = (int) ($cfg['files']['max_bytes'] ?? 25 * 1024 * 1024);
        $size = (int) @filesize($path);
        if ($size <= 0 || $size > $max) {
            return null;
        }

        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!@copy($path, self::dir() . '/' . $name)) {
            Logger::line('warn', 'cannot publish generated file', ['file' => basename($path)]);
            return null;
        }
        self::prune($cfg);

        $host = trim((string) ($cfg['files']['base_url'] ?? '')) ?: Http::baseUrl($cfg);
        return [
            'name' => basename($path),
            'url' => rtrim($host, '/') . '/files/' . $name,
            'image' => in_array($ext, self::IMAGES, true),
        ];
    }

    /** Отдаёт файл по ссылке. Имя проверяем жёстко: наружу уходит только своё. */
    public static function serve(string $name): void
    {
        if (!preg_match('/^[a-f0-9]{32}\.([a-z0-9]{1,5})$/', $name, $m)) {
            Http::error(404, 'File not found.', 'invalid_request_error', null);
        }
        $ext = $m[1];
        $path = self::dir() . '/' . $name;
        if (!isset(self::TYPES[$ext]) || !is_file($path)) {
            Http::error(404, 'File not found.', 'invalid_request_error', null);
        }

        header('Content-Type: ' . self::TYPES[$ext]);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: inline; filename="' . $name . '"');
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    /** Убирает старьё, чтобы раздача не росла бесконечно. */
    public static function prune(array $cfg): void
    {
        $ttl = (int) ($cfg['files']['ttl'] ?? 86400 * 14);
        if ($ttl <= 0) {
            return;
        }
        $now = time();
        foreach ((array) glob(self::dir() . '/*') as $file) {
            if (is_file($file) && $now - (int) @filemtime($file) > $ttl) {
                @unlink($file);
            }
        }
    }

    /**
     * Строка, которую дописываем к ответу модели.
     * Картинки markdown-картинками, остальное ссылками.
     */
    public static function block(array $files): string
    {
        if ($files === []) {
            return '';
        }
        $lines = [];
        foreach ($files as $f) {
            $label = str_replace([']', '['], '', (string) $f['name']);
            $lines[] = (!empty($f['image']) ? '!' : '') . '[' . $label . '](' . $f['url'] . ')';
        }
        return "\n\n" . implode("\n", $lines);
    }
}
