<?php
declare(strict_types=1);

/**
 * Раздача обновлений приложения Gemini Desktop.
 *
 * Файлы лежат в storage/releases, опись — в releases.json.
 * Выкладывать может только доверенный IP и только со своим токеном:
 * этот файл клиенты скачивают и запускают, подменить его нельзя дать никому.
 */
final class Releases
{
    public static function dir(): string
    {
        $dir = dirname(__DIR__) . '/storage/releases';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private static function indexFile(): string
    {
        return self::dir() . '/releases.json';
    }

    /** Все выпуски, свежие первыми. */
    public static function all(): array
    {
        $data = json_decode((string) @file_get_contents(self::indexFile()), true);
        if (!is_array($data)) {
            return [];
        }
        usort($data, static fn($a, $b) => version_compare(
            (string) ($b['version'] ?? '0'),
            (string) ($a['version'] ?? '0')
        ));
        return $data;
    }

    public static function latest(): ?array
    {
        $all = self::all();
        return $all[0] ?? null;
    }

    public static function find(string $version): ?array
    {
        foreach (self::all() as $row) {
            if ((string) ($row['version'] ?? '') === $version) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Настоящий адрес клиента.
     *
     * Метод clientIp() из Http доверяет заголовку X-Forwarded-For, а прислать его
     * может кто угодно: для проверки прав этого мало. Сюда ходят напрямую
     * через nginx, поэтому берём REMOTE_ADDR и только его.
     */
    private static function realIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '-');
    }

    /** Публиковать разрешено только с этого адреса и только с токеном. */
    public static function mayPublish(array $cfg): bool
    {
        $allowed = (array) ($cfg['releases']['publisher_ips'] ?? []);
        $ip = self::realIp();
        if ($allowed !== [] && !in_array($ip, $allowed, true)) {
            return false;
        }
        $token = (string) ($cfg['releases']['publish_token'] ?? '');
        if ($token === '') {
            return false; // без токена публикация выключена
        }
        $given = (string) ($_POST['token'] ?? $_SERVER['HTTP_X_PUBLISH_TOKEN'] ?? '');
        return $given !== '' && hash_equals($token, $given);
    }

    /** Виден ли этому гостю раздел выкладывания (форма). */
    public static function isPublisherIp(array $cfg): bool
    {
        $allowed = (array) ($cfg['releases']['publisher_ips'] ?? []);
        return $allowed === [] || in_array(self::realIp(), $allowed, true);
    }

    /**
     * Принимает загруженный установщик.
     * @return array{ok:bool,error?:string,release?:array}
     */
    public static function publish(array $cfg, array $file, array $fields): array
    {
        if (!self::mayPublish($cfg)) {
            return ['ok' => false, 'error' => 'Выкладывать можно только со своего адреса и со своим токеном.'];
        }

        $version = trim((string) ($fields['version'] ?? ''));
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            return ['ok' => false, 'error' => 'Версия должна быть вида 1.0.1'];
        }
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Файл не долетел (код ' . (int) ($file['error'] ?? -1) . ')'];
        }
        $name = (string) ($file['name'] ?? '');
        if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'msi') {
            return ['ok' => false, 'error' => 'Ожидается .msi'];
        }

        $target = self::dir() . '/GeminiDesktop-' . $version . '.msi';
        if (!@move_uploaded_file((string) $file['tmp_name'], $target)) {
            return ['ok' => false, 'error' => 'Не удалось сохранить файл'];
        }
        @chmod($target, 0664);

        $release = [
            'version' => $version,
            'file' => basename($target),
            'size' => (int) filesize($target),
            'sha256' => (string) hash_file('sha256', $target),
            'important' => !empty($fields['important']),
            'notes' => trim((string) ($fields['notes'] ?? '')),
            'publishedAt' => date('c'),
        ];

        $all = array_values(array_filter(
            self::all(),
            static fn($r) => (string) ($r['version'] ?? '') !== $version
        ));
        $all[] = $release;
        file_put_contents(
            self::indexFile(),
            (string) json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
        Logger::line('info', 'release published', ['version' => $version, 'important' => $release['important']]);

        return ['ok' => true, 'release' => $release];
    }

    public static function downloadUrl(array $row): string
    {
        return Http::baseUrl() . '/up/download/' . rawurlencode((string) $row['version']);
    }

    /** Ответ на «есть ли обновление» для версии $from. */
    public static function check(?string $from): array
    {
        $latest = self::latest();
        if ($latest === null) {
            return ['update' => false, 'version' => null];
        }
        $newer = $from === null || $from === ''
            ? true
            : version_compare((string) $latest['version'], $from, '>');

        return [
            'update' => $newer,
            'version' => $latest['version'],
            'current' => $from,
            'important' => !empty($latest['important']),
            'notes' => (string) ($latest['notes'] ?? ''),
            'size' => (int) ($latest['size'] ?? 0),
            'sha256' => (string) ($latest['sha256'] ?? ''),
            'publishedAt' => (string) ($latest['publishedAt'] ?? ''),
            'url' => self::downloadUrl($latest),
        ];
    }

    /** Отдаёт файл установщика. Завершает выполнение. */
    public static function send(string $version): void
    {
        $row = self::find($version);
        $path = $row ? self::dir() . '/' . $row['file'] : '';
        if ($row === null || !is_file($path)) {
            Http::error(404, 'Такой версии нет: ' . $version, 'invalid_request_error', 'unknown_version');
        }

        header('Content-Type: application/x-msi');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('X-Release-Sha256: ' . (string) ($row['sha256'] ?? ''));
        readfile($path);
        exit;
    }
}
