<?php
declare(strict_types=1);

/**
 * Всё, что шлюз знает про операционную систему, собрано здесь.
 *
 * Остальной код про Windows и Linux не думает: он спрашивает у Platform путь,
 * команду или домашний каталог и получает готовое. Так различия двух систем
 * лежат в одном файле, а не рассыпаны по десятку мест, где их легко забыть.
 */
final class Platform
{
    /** @var array<string,string> найденные программы, чтобы не искать дважды */
    private static array $found = [];

    public static function isWindows(): bool
    {
        return DIRECTORY_SEPARATOR === '\\';
    }

    /** Короткое имя системы для /health и сообщений об ошибках. */
    public static function name(): string
    {
        return self::isWindows() ? 'windows' : strtolower(PHP_OS_FAMILY);
    }

    /** Корень проекта — папка, в которой лежат src, public и config.php. */
    public static function root(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Домашний каталог того, от чьего имени работает PHP.
     *
     * Важно не перепутать: agy держит там авторизацию и беседы. Под веб-сервером
     * HOME нередко пуст, поэтому есть запасные пути.
     */
    public static function home(): string
    {
        foreach (['HOME', 'USERPROFILE'] as $var) {
            $val = (string) (getenv($var) ?: '');
            if ($val !== '' && is_dir($val)) {
                return self::normalize($val);
            }
        }
        $drive = (string) (getenv('HOMEDRIVE') ?: '');
        $path = (string) (getenv('HOMEPATH') ?: '');
        if ($drive !== '' && $path !== '') {
            return self::normalize($drive . $path);
        }
        return self::normalize(sys_get_temp_dir());
    }

    /**
     * Приводит путь к виду своей системы: разделители одинаковые, хвоста нет.
     *
     * PHP на Windows понимает и косую черту, но в сообщениях и логах вперемешку
     * они читаются плохо, а сравнение путей строками начинает врать.
     */
    public static function normalize(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($path));
        // Хвостовой разделитель убираем, но не у корня диска (C:\) и не у «/».
        if (strlen($path) > 1 && substr($path, -1) === DIRECTORY_SEPARATOR
            && !preg_match('/^[A-Za-z]:\\\\$/', $path)) {
            $path = rtrim($path, DIRECTORY_SEPARATOR);
        }
        return $path;
    }

    /**
     * Абсолютный ли путь.
     *
     * В Linux это «начинается с /», в Windows — «C:\...» или «\\сервер\...».
     */
    public static function isAbsolute(string $path): bool
    {
        $path = trim($path);
        if ($path === '') {
            return false;
        }
        if (self::isWindows()) {
            // Обратную косую в наборе символов пишем двумя: одиночная «\/»
            // для PCRE — это просто «/», и путь вида C:\папка не совпадёт.
            return (bool) preg_match('~^([A-Za-z]:[\\\\/]|[\\\\/]{2})~', $path);
        }
        return $path[0] === '/';
    }

    /**
     * Путь из конфига — в настоящий путь.
     *
     * Относительный считаем от корня проекта, а не от текущего каталога
     * процесса: у шлюза каталог меняется на рабочий каталог беседы, и
     * «tests/fake_agy.php» из конфига иначе однажды перестанет находиться.
     */
    public static function resolve(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        return self::isAbsolute($path)
            ? self::normalize($path)
            : self::join(self::root(), $path);
    }

    /** Склеивает части пути через разделитель своей системы. */
    public static function join(string ...$parts): string
    {
        $clean = [];
        foreach ($parts as $i => $part) {
            $part = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($part));
            $part = $i === 0 ? rtrim($part, DIRECTORY_SEPARATOR) : trim($part, DIRECTORY_SEPARATOR);
            if ($part !== '') {
                $clean[] = $part;
            }
        }
        return implode(DIRECTORY_SEPARATOR, $clean);
    }

    /** Расширения исполняемых файлов: на Windows имя без расширения не запускается. */
    private static function suffixes(): array
    {
        if (!self::isWindows()) {
            return [''];
        }
        $ext = (string) (getenv('PATHEXT') ?: '.COM;.EXE;.BAT;.CMD');
        $list = [''];
        foreach (explode(';', $ext) as $one) {
            $one = strtolower(trim($one));
            if ($one !== '') {
                $list[] = $one;
            }
        }
        return $list;
    }

    /** Места, где программу стоит поискать помимо PATH. */
    private static function extraDirs(): array
    {
        $home = self::home();
        if (self::isWindows()) {
            $local = (string) (getenv('LOCALAPPDATA') ?: self::join($home, 'AppData', 'Local'));
            $roaming = (string) (getenv('APPDATA') ?: self::join($home, 'AppData', 'Roaming'));
            return [
                self::join($local, 'agy', 'bin'),   // установщик agy для Windows
                self::join($roaming, 'npm'),        // npm install -g
                self::join($local, 'Programs', 'agy'),
            ];
        }
        return [
            '/usr/local/bin',
            '/usr/bin',
            '/snap/bin',
            self::join($home, '.local', 'bin'),
            self::join($home, '.npm-global', 'bin'),
            self::join($home, '.nvm', 'versions'),
        ];
    }

    /**
     * Ищет программу по имени: сначала в PATH, потом в привычных местах.
     *
     * Возвращает полный путь или пустую строку. Полный путь лучше короткого
     * имени: у веб-сервера свой PATH, и «agy» из консоли там может не найтись.
     */
    public static function findExecutable(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        if (isset(self::$found[$name])) {
            return self::$found[$name];
        }

        $dirs = [];
        $path = (string) (getenv('PATH') ?: getenv('Path') ?: '');
        if ($path !== '') {
            $dirs = array_merge($dirs, explode(PATH_SEPARATOR, $path));
        }
        $dirs = array_merge($dirs, self::extraDirs());

        foreach ($dirs as $dir) {
            $dir = trim($dir);
            if ($dir === '') {
                continue;
            }
            foreach (self::suffixes() as $suffix) {
                $file = self::join($dir, $name . $suffix);
                // is_executable на Windows врёт про файлы без расширения,
                // поэтому там достаточно того, что файл есть.
                if (is_file($file) && (self::isWindows() || is_executable($file))) {
                    return self::$found[$name] = $file;
                }
            }
        }
        return self::$found[$name] = '';
    }

    /**
     * Команда запуска CLI — то, что уйдёт в proc_open.
     *
     * В конфиге можно написать строку ('C:\agy\agy.exe'), массив с префиксом
     * (['sudo','-u','agent','/usr/local/bin/agy']) или не писать ничего —
     * тогда ищем сами.
     *
     * @return string[] пустой массив, если программу найти не удалось
     */
    public static function agyCommand(array $cli): array
    {
        $bin = $cli['command'] ?? '';

        // Голое имя ('php', 'sudo') оставляем системе, путь — разворачиваем.
        $one = static function (string $part): string {
            $part = trim($part);
            if ($part === '' || strpbrk($part, '/\\') === false) {
                return $part;
            }
            return self::resolve($part);
        };

        if (is_array($bin)) {
            $prefix = array_values(array_filter(
                array_map(static fn($s) => $one((string) $s), $bin),
                static fn(string $s) => $s !== ''
            ));
            if ($prefix !== []) {
                return $prefix;
            }
            $bin = '';
        }

        $bin = trim((string) $bin);
        if ($bin !== '') {
            // Путь из конфига уважаем как есть: человек написал его намеренно.
            // Если это просто имя — доищем, чтобы не зависеть от PATH сервера.
            if (strpbrk($bin, '/\\') !== false) {
                return [$one($bin)];
            }
            $full = self::findExecutable($bin);
            return [$full !== '' ? $full : $bin];
        }

        $full = self::findExecutable('agy');
        return $full !== '' ? [$full] : [];
    }

    /**
     * Запустится ли эта команда.
     *
     * Путь проверяем на существование файла, голое имя ('php', 'sudo') —
     * поиском по PATH: такое имя в префиксе оставлено намеренно, и судить
     * о нём по is_file() значит объявить рабочую настройку сломанной.
     *
     * @param string[] $prefix
     */
    public static function commandExists(array $prefix): bool
    {
        $first = trim((string) ($prefix[0] ?? ''));
        if ($first === '') {
            return false;
        }
        return strpbrk($first, '/\\') !== false
            ? is_file($first)
            : self::findExecutable($first) !== '';
    }

    /**
     * Можно ли запереть CLI в песочнице.
     *
     * Настоящая изоляция есть только в Linux (unshare из util-linux). На Windows
     * её нет, и делать вид, что есть, нельзя: пусть лучше человек прочитает
     * в /health честное «нет», чем понадеется на защиту, которой не существует.
     */
    public static function canJail(): bool
    {
        return !self::isWindows() && self::findExecutable('unshare') !== '';
    }

    /** Путь к скрипту песочницы внутри проекта. */
    public static function jailScript(): string
    {
        return self::join(self::root(), 'bin', 'agy-jail');
    }

    /** Каталог для временных файлов процесса CLI. */
    public static function tempDir(): string
    {
        return self::normalize(sys_get_temp_dir());
    }

    /**
     * Создаёт каталог и возвращает путь. Пусто — если не вышло.
     *
     * Права 0775 на Windows игнорируются, и это нормально: там доступ
     * определяют списки ACL, унаследованные от родительской папки.
     */
    public static function ensureDir(string $dir): string
    {
        $dir = self::normalize($dir);
        if ($dir === '') {
            return '';
        }
        if (is_dir($dir)) {
            return $dir;
        }
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return '';
        }
        return $dir;
    }
}
