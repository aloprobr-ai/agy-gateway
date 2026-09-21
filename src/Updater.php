<?php
declare(strict_types=1);

/**
 * Обновление самого шлюза из репозитория на GitHub.
 *
 * Два пути, и первый лучше: если папка — клон git и сам git на месте, делаем
 * `git pull --ff-only`. Тогда историю видно, откатиться можно одной командой,
 * а config.php не отслеживается и его никто не трогает.
 *
 * Если git недоступен (скопировали архивом, поставили на хостинг без консоли),
 * скачиваем срез репозитория и раскладываем поверх. Здесь осторожнее:
 * свои файлы не трогаем вовсе, а всё заменённое сначала откладываем
 * в storage/backups — обновление, из которого нельзя вернуться, не обновление.
 *
 * Ничего не удаляем: файл, исчезнувший в репозитории, останется лежать.
 * Это осознанный перекос в сторону «ничего не потерять».
 */
final class Updater
{
    private const PROTECTED = [
        'config.php',       // ключи и настройки — только ваши
        'storage',          // беседы, каталог моделей, резервные копии
        'logs',
        'public/files',     // то, что нарисовала модель
        '.git',
    ];

    public static function repo(array $cfg): string
    {
        return trim((string) ($cfg['update']['repo'] ?? '')) ?: 'aloprobr-ai/agy-gateway';
    }

    public static function branch(array $cfg): string
    {
        return trim((string) ($cfg['update']['branch'] ?? '')) ?: 'main';
    }

    /**
     * Ключ доступа: нужен, пока репозиторий закрыт.
     *
     * Переменная окружения идёт первой: ключ — секрет, и не всякий захочет
     * держать его записанным в config.php.
     */
    private static function token(array $cfg): string
    {
        $env = trim((string) (getenv('AGY_UPDATE_TOKEN') ?: ''));
        return $env !== '' ? $env : trim((string) ($cfg['update']['token'] ?? ''));
    }

    private static function stateFile(): string
    {
        return Platform::join(Platform::root(), 'storage', 'installed.json');
    }

    // ------------------------------------------------------------ что стоит

    /**
     * Что за версия сейчас установлена.
     * @return array{sha:string,source:string,date:string}
     */
    public static function current(): array
    {
        if (self::isGitClone()) {
            $sha = self::git(['rev-parse', 'HEAD']);
            if ($sha !== null && $sha !== '') {
                $date = self::git(['log', '-1', '--format=%cI']) ?? '';
                return ['sha' => trim($sha), 'source' => 'git', 'date' => trim($date)];
            }
        }
        $saved = json_decode((string) @file_get_contents(self::stateFile()), true);
        if (is_array($saved) && !empty($saved['sha'])) {
            return [
                'sha' => (string) $saved['sha'],
                'source' => 'file',
                'date' => (string) ($saved['date'] ?? ''),
            ];
        }
        return ['sha' => '', 'source' => 'unknown', 'date' => ''];
    }

    public static function isGitClone(): bool
    {
        return is_dir(Platform::join(Platform::root(), '.git'))
            && Platform::findExecutable('git') !== '';
    }

    /** Запускает git в папке проекта. null — не получилось. */
    private static function git(array $args): ?string
    {
        $git = Platform::findExecutable('git');
        if ($git === '') {
            return null;
        }
        $cmd = array_merge([$git, '-C', Platform::root()], $args);
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $desc, $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $code = proc_close($proc);
        return $code === 0 ? $out : null;
    }

    // ------------------------------------------------------------ что вышло

    /**
     * Последний коммит в ветке на GitHub.
     * @return array{ok:bool,sha?:string,date?:string,message?:string,error?:string}
     */
    public static function latest(array $cfg): array
    {
        $url = 'https://api.github.com/repos/' . self::repo($cfg)
            . '/commits/' . rawurlencode(self::branch($cfg));
        $body = self::fetch($url, $cfg);
        if ($body === null) {
            return ['ok' => false, 'error' => self::$lastError];
        }
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['sha'])) {
            return ['ok' => false, 'error' => 'GitHub ответил непонятным'];
        }
        $msg = (string) ($data['commit']['message'] ?? '');
        return [
            'ok' => true,
            'sha' => (string) $data['sha'],
            'date' => (string) ($data['commit']['committer']['date'] ?? ''),
            'message' => trim(strtok($msg, "\n") ?: ''),
        ];
    }

    /**
     * Есть ли что ставить.
     * @return array{ok:bool,behind:bool,current:array,latest:array,error?:string}
     */
    public static function check(array $cfg): array
    {
        $current = self::current();
        $latest = self::latest($cfg);
        if (!$latest['ok']) {
            return ['ok' => false, 'behind' => false, 'current' => $current,
                    'latest' => $latest, 'error' => $latest['error'] ?? 'не вышло'];
        }
        // Неизвестная своя версия — не повод объявлять, что всё свежо.
        $behind = $current['sha'] === '' || $current['sha'] !== $latest['sha'];
        return ['ok' => true, 'behind' => $behind, 'current' => $current, 'latest' => $latest];
    }

    // -------------------------------------------------------------- скачать

    private static string $lastError = '';

    /**
     * GET по https. Пробуем по очереди всё, что может оказаться на машине.
     *
     * Запасные пути здесь не роскошь: PHP для Windows приезжает без php.ini,
     * и там нет ни curl, ни openssl — значит, и обёртки https тоже нет.
     * Зато есть curl.exe в самой системе.
     */
    private static function fetch(string $url, array $cfg, ?string $saveTo = null): ?string
    {
        self::$lastError = '';
        $headers = [
            'User-Agent: agy-gateway',
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        $token = self::token($cfg);
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        if (function_exists('curl_init')) {
            $out = self::fetchCurl($url, $headers, $saveTo);
            if ($out !== null) {
                return $out;
            }
        }
        if (in_array('https', stream_get_wrappers(), true)) {
            $out = self::fetchStream($url, $headers, $saveTo);
            if ($out !== null) {
                return $out;
            }
        }
        return self::fetchExternalCurl($url, $headers, $saveTo);
    }

    private static function fetchCurl(string $url, array $headers, ?string $saveTo): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if (!is_string($body) || $status >= 400) {
            self::$lastError = $err !== '' ? $err : self::httpHint($status);
            return null;
        }
        return self::deliver($body, $saveTo);
    }

    private static function fetchStream(string $url, array $headers, ?string $saveTo): ?string
    {
        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => 120,
            'follow_location' => 1,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('~HTTP/\S+\s+(\d{3})~', $line, $m)) {
                $status = (int) $m[1];   // последний — после переадресаций
            }
        }
        if (!is_string($body) || $status >= 400) {
            self::$lastError = self::httpHint($status);
            return null;
        }
        return self::deliver($body, $saveTo);
    }

    /** curl.exe есть в Windows 10 и новее и почти в любом Linux. */
    private static function fetchExternalCurl(string $url, array $headers, ?string $saveTo): ?string
    {
        $curl = Platform::findExecutable('curl');
        if ($curl === '') {
            self::$lastError = 'нечем скачать: нет ни расширения curl, ни openssl, ни программы curl';
            return null;
        }
        $tmp = $saveTo ?? Platform::join(Platform::tempDir(), 'agy-upd-' . bin2hex(random_bytes(6)));
        // Без -f: с ним curl на 404 просто молчит и возвращает 22, а нам нужен
        // сам код ответа, чтобы сказать человеку, в чём дело. Код просим
        // отдельно через -w, тело идёт в файл.
        $cmd = [$curl, '-sSL', '--max-time', '120', '-o', $tmp, '-w', '%{http_code}'];
        foreach ($headers as $h) {
            array_push($cmd, '-H', $h);
        }
        $cmd[] = $url;

        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $desc, $pipes);
        if (!is_resource($proc)) {
            self::$lastError = 'не удалось запустить curl';
            return null;
        }
        $status = (int) trim((string) stream_get_contents($pipes[1]));
        $err = (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (proc_close($proc) !== 0 || $status >= 400) {
            self::$lastError = $status >= 400
                ? self::httpHint($status)
                : (trim($err) !== '' ? trim($err) : 'curl вернул ошибку');
            @unlink($tmp);
            return null;
        }
        if ($saveTo !== null) {
            return '';   // файл уже на месте
        }
        $body = (string) @file_get_contents($tmp);
        @unlink($tmp);
        return $body;
    }

    private static function deliver(string $body, ?string $saveTo): ?string
    {
        if ($saveTo === null) {
            return $body;
        }
        if (@file_put_contents($saveTo, $body) === false) {
            self::$lastError = 'некуда записать: ' . $saveTo;
            return null;
        }
        return '';
    }

    private static function httpHint(int $status): string
    {
        return match (true) {
            $status === 404 => 'GitHub ответил 404. У закрытого репозитория так и будет без ключа — впишите update.token в config.php',
            $status === 401 || $status === 403 => 'GitHub отказал (' . $status . '): ключ не подошёл или исчерпан предел запросов',
            $status > 0 => 'GitHub ответил ' . $status,
            default => 'не удалось достучаться до GitHub',
        };
    }

    // ------------------------------------------------------------- поставить

    /**
     * Обновляет код. $log — куда писать ход дела.
     * @return array{ok:bool,changed:int,error?:string,note?:string}
     */
    public static function apply(array $cfg, callable $log): array
    {
        if (self::isGitClone()) {
            return self::applyGit($log);
        }
        return self::applyArchive($cfg, $log);
    }

    private static function applyGit(callable $log): array
    {
        $log('папка — клон git, тяну изменения');
        $out = self::git(['pull', '--ff-only']);
        if ($out === null) {
            return ['ok' => false, 'changed' => 0,
                    'error' => 'git pull не прошёл. Обычно это местные правки поверх: посмотрите git status'];
        }
        $log(trim($out));
        return ['ok' => true, 'changed' => -1, 'note' => 'подробности — в git log'];
    }

    private static function applyArchive(array $cfg, callable $log): array
    {
        $latest = self::latest($cfg);
        if (!$latest['ok']) {
            return ['ok' => false, 'changed' => 0, 'error' => $latest['error'] ?? 'не вышло'];
        }

        $tmp = Platform::ensureDir(Platform::join(Platform::tempDir(), 'agy-upd-' . bin2hex(random_bytes(6))));
        if ($tmp === '') {
            return ['ok' => false, 'changed' => 0, 'error' => 'негде развернуть загрузку'];
        }
        $tgz = Platform::join($tmp, 'src.tar.gz');

        $url = 'https://api.github.com/repos/' . self::repo($cfg) . '/tarball/' . $latest['sha'];
        $log('скачиваю срез ' . substr($latest['sha'], 0, 8));
        if (self::fetch($url, $cfg, $tgz) === null) {
            self::rmTree($tmp);
            return ['ok' => false, 'changed' => 0, 'error' => self::$lastError];
        }

        $log('распаковываю');
        if (!self::extract($tgz, $tmp)) {
            self::rmTree($tmp);
            return ['ok' => false, 'changed' => 0, 'error' => self::$lastError];
        }

        // GitHub кладёт всё в одну папку вида «владелец-репозиторий-<хеш>».
        $inner = '';
        foreach ((array) glob(Platform::join($tmp, '*'), GLOB_ONLYDIR) as $dir) {
            $inner = $dir;
            break;
        }
        if ($inner === '' || !is_file(Platform::join($inner, 'public', 'index.php'))) {
            self::rmTree($tmp);
            return ['ok' => false, 'changed' => 0,
                    'error' => 'в загруженном архиве нет шлюза — проверьте update.repo'];
        }

        $stamp = date('Ymd-His');
        $backup = Platform::join(Platform::root(), 'storage', 'backups', $stamp);
        $changed = self::copyTree($inner, Platform::root(), $backup, $log);
        self::rmTree($tmp);

        @file_put_contents(self::stateFile(), json_encode([
            'sha' => $latest['sha'],
            'date' => $latest['date'] ?? '',
            'installed_at' => date('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        self::pruneBackups();

        return ['ok' => true, 'changed' => $changed,
                'note' => $changed > 0 ? 'заменённое отложено в storage/backups/' . $stamp : ''];
    }

    private static function extract(string $tgz, string $into): bool
    {
        // Сначала своими силами: PharData читает tar.gz без лишних расширений.
        if (class_exists('PharData')) {
            try {
                $phar = new PharData($tgz);
                $phar->extractTo($into, null, true);
                return true;
            } catch (Throwable $e) {
                // не вышло — пробуем системный tar
            }
        }
        $tar = Platform::findExecutable('tar');
        if ($tar === '') {
            self::$lastError = 'нечем распаковать: нет ни PharData, ни программы tar';
            return false;
        }
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open([$tar, '-xzf', $tgz, '-C', $into], $desc, $pipes);
        if (!is_resource($proc)) {
            self::$lastError = 'не удалось запустить tar';
            return false;
        }
        $err = (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (proc_close($proc) !== 0) {
            self::$lastError = 'tar не справился: ' . trim($err);
            return false;
        }
        return true;
    }

    /**
     * Копирует дерево поверх проекта, откладывая заменяемое в запасник.
     * @return int сколько файлов изменилось
     */
    private static function copyTree(string $from, string $to, string $backup, callable $log, string $rel = ''): int
    {
        $changed = 0;
        foreach ((array) scandir(Platform::join($from, $rel)) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $childRel = $rel === '' ? $name : $rel . '/' . $name;
            if (self::isProtected($childRel)) {
                continue;
            }
            $src = Platform::join($from, $childRel);
            $dst = Platform::join($to, $childRel);

            if (is_dir($src)) {
                Platform::ensureDir($dst);
                $changed += self::copyTree($from, $to, $backup, $log, $childRel);
                continue;
            }

            // Одинаковые файлы не трогаем: так в запаснике остаётся только
            // то, что действительно поменялось, и видно, что именно.
            if (is_file($dst) && @sha1_file($dst) === @sha1_file($src)) {
                continue;
            }
            if (is_file($dst)) {
                $keep = Platform::join($backup, $childRel);
                Platform::ensureDir(dirname($keep));
                @copy($dst, $keep);
            }
            Platform::ensureDir(dirname($dst));
            if (@copy($src, $dst)) {
                // Бит запуска важен: без него bin/agy-jail и bin/serve.sh
                // в Linux просто не запустятся.
                @chmod($dst, (int) (@fileperms($src) & 0777));
                $changed++;
                $log('  ' . $childRel);
            }
        }
        return $changed;
    }

    private static function isProtected(string $rel): bool
    {
        $rel = str_replace('\\', '/', $rel);
        foreach (self::PROTECTED as $keep) {
            if ($rel === $keep || str_starts_with($rel, $keep . '/')) {
                return true;
            }
        }
        // Копии конфига вида config.php.bak-... — тоже ваши.
        return str_starts_with($rel, 'config.php.');
    }

    /** Держим три последних запасника: дальше это просто занятое место. */
    private static function pruneBackups(): void
    {
        $dirs = (array) glob(Platform::join(Platform::root(), 'storage', 'backups', '*'), GLOB_ONLYDIR);
        sort($dirs);
        foreach (array_slice($dirs, 0, max(0, count($dirs) - 3)) as $old) {
            self::rmTree($old);
        }
    }

    private static function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = Platform::join($dir, $name);
            is_dir($path) ? self::rmTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
