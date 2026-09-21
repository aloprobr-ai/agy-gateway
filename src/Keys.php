<?php
declare(strict_types=1);

/**
 * Клиентские ключи, выданные через страницу /keys.
 *
 * Ключи из config.php остаются как были — это «встроенные», их отсюда
 * не видно и не тронуть. Выданные лежат в storage/api-keys.json и их
 * можно отключать и удалять, не трогая конфиг.
 */
final class Keys
{
    private const PREFIX = 'sk-yug-';

    private static function file(): string
    {
        $dir = dirname(__DIR__) . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . '/api-keys.json';
    }

    /** Все выданные ключи, свежие первыми. */
    public static function all(): array
    {
        $data = json_decode((string) @file_get_contents(self::file()), true);
        if (!is_array($data)) {
            return [];
        }
        usort($data, static fn($a, $b) => strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? '')));
        return $data;
    }

    private static function save(array $rows): bool
    {
        return @file_put_contents(
            self::file(),
            (string) json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        ) !== false;
    }

    /** Ключи, которыми сейчас можно пользоваться. */
    public static function active(): array
    {
        $out = [];
        foreach (self::all() as $row) {
            if (empty($row['disabled']) && !empty($row['key'])) {
                $out[] = (string) $row['key'];
            }
        }
        return $out;
    }

    /**
     * Выдать новый ключ.
     * Если $custom пуст, ключ придумывается сам; иначе берётся заданный,
     * но сначала проверяется, что он годится и ещё не занят.
     * Возвращает строку целиком — показать её можно только сейчас.
     */
    public static function create(string $label, string $custom = '', array $cfg = []): array
    {
        $custom = trim($custom);
        if ($custom !== '') {
            if (!preg_match('/^[A-Za-z0-9._-]{8,80}$/', $custom)) {
                return ['ok' => false, 'error' => 'Свой ключ: латинские буквы, цифры, точка, дефис и подчёркивание, от 8 до 80 знаков.'];
            }
            foreach (self::all() as $r) {
                if (hash_equals((string) ($r['key'] ?? ''), $custom)) {
                    return ['ok' => false, 'error' => 'Такой ключ уже выдан — он есть в списке ниже.'];
                }
            }
            foreach ((array) ($cfg['api_keys'] ?? []) as $builtin) {
                if (hash_equals((string) $builtin, $custom)) {
                    return ['ok' => false, 'error' => 'Такой ключ уже прописан в config.php и работает без этой страницы.'];
                }
            }
        }
        $key = $custom !== ''
            ? $custom
            : self::PREFIX . substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(30))), 0, 28);
        $row = [
            'key' => $key,
            'label' => trim($label) !== '' ? trim($label) : 'без названия',
            'createdAt' => date('c'),
            'lastUsedAt' => null,
            'disabled' => false,
        ];
        $rows = self::all();
        $rows[] = $row;
        if (!self::save($rows)) {
            return ['ok' => false, 'error' => 'Не удалось записать файл ключей'];
        }
        Logger::line('info', 'api key created', ['label' => $row['label'], 'tail' => substr($key, -4)]);
        return ['ok' => true, 'key' => $key, 'row' => $row];
    }

    public static function setDisabled(string $key, bool $disabled): bool
    {
        $rows = self::all();
        $found = false;
        foreach ($rows as &$row) {
            if (hash_equals((string) ($row['key'] ?? ''), $key)) {
                $row['disabled'] = $disabled;
                $found = true;
            }
        }
        unset($row);
        return $found && self::save($rows);
    }

    public static function delete(string $key): bool
    {
        $rows = array_values(array_filter(
            self::all(),
            static fn($r) => !hash_equals((string) ($r['key'] ?? ''), $key)
        ));
        return self::save($rows);
    }

    private static function seenFile(): string
    {
        return dirname(__DIR__) . '/storage/api-keys-seen.json';
    }

    /**
     * Когда ключом пользовались в последний раз. Для ключей из config.php:
     * трогать сам конфиг ради отметки не хочется, поэтому держим их отдельно
     * и по отпечатку, чтобы ключ не лежал открытым ещё в одном файле.
     */
    public static function lastSeen(string $key): ?string
    {
        $map = json_decode((string) @file_get_contents(self::seenFile()), true);
        $at = is_array($map) ? ($map[hash('sha256', $key)] ?? null) : null;
        return is_string($at) ? $at : null;
    }

    private static function markSeen(string $key): void
    {
        $map = json_decode((string) @file_get_contents(self::seenFile()), true);
        if (!is_array($map)) {
            $map = [];
        }
        $id = hash('sha256', $key);
        $last = isset($map[$id]) ? (int) strtotime((string) $map[$id]) : 0;
        if (time() - $last <= 300) {
            return;
        }
        $map[$id] = date('c');
        @file_put_contents(self::seenFile(), (string) json_encode($map, JSON_PRETTY_PRINT));
    }

    /** Ключи, прописанные в config.php: их видно, но отсюда не поправить. */
    public static function builtin(array $cfg): array
    {
        $mine = [];
        foreach (self::all() as $row) {
            $mine[(string) ($row['key'] ?? '')] = true;
        }
        $out = [];
        foreach ((array) ($cfg['api_keys'] ?? []) as $key) {
            $key = (string) $key;
            if ($key === '' || isset($mine[$key])) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'label' => self::guessLabel($key),
                'createdAt' => null,
                'lastUsedAt' => self::lastSeen($key),
                'disabled' => false,
                'builtin' => true,
            ];
        }
        return $out;
    }

    /** Имя из самого ключа: sk-stepa-1234 -> stepa. */
    private static function guessLabel(string $key): string
    {
        if (preg_match('/^sk-([A-Za-z0-9]+)-/', $key, $m) && $m[1] !== 'yug') {
            return $m[1];
        }
        return 'из config.php';
    }

    /**
     * Перенести ключ из config.php в управляемые: значение остаётся тем же,
     * строка из конфига убирается. Без этого «отключить» было бы обманом:
     * Auth складывает оба списка, и ключ продолжал бы работать.
     */
    public static function adopt(string $key, array $cfg): array
    {
        $key = trim($key);
        $known = false;
        foreach ((array) ($cfg['api_keys'] ?? []) as $one) {
            if (hash_equals((string) $one, $key)) {
                $known = true;
                break;
            }
        }
        if (!$known) {
            return ['ok' => false, 'error' => 'Такого ключа в config.php нет.'];
        }
        foreach (self::all() as $row) {
            if (hash_equals((string) ($row['key'] ?? ''), $key)) {
                return ['ok' => false, 'error' => 'Этот ключ уже перенесён.'];
            }
        }
        if (!self::cutFromConfig($key)) {
            return ['ok' => false, 'error' => 'Не удалось убрать строку из config.php, файл не тронут.'];
        }
        $rows = self::all();
        $rows[] = [
            'key' => $key,
            'label' => self::guessLabel($key),
            'createdAt' => date('c'),
            'lastUsedAt' => self::lastSeen($key),
            'disabled' => false,
            'adopted' => true,
        ];
        if (!self::save($rows)) {
            return ['ok' => false, 'error' => 'Строку из конфига убрал, а хранилище записать не смог. Верните config.php из бэкапа.'];
        }
        Logger::line('info', 'api key adopted from config', ['tail' => substr($key, -4)]);
        return ['ok' => true, 'label' => self::guessLabel($key)];
    }

    /**
     * Убирает ровно одну строку со значением ключа из config.php.
     * Перед записью сверяем, что файл похудел ровно на длину этой строки:
     * так исключаем случай, когда регулярка зацепила лишнее.
     */
    private static function cutFromConfig(string $key): bool
    {
        $path = dirname(__DIR__) . '/config.php';
        $src = @file_get_contents($path);
        if ($src === false || !str_contains($src, "'api_keys'")) {
            return false;
        }
        $pattern = '/^[ \t]*' . preg_quote("'" . $key . "',", '/') . '[ \t]*\r?\n/m';
        if (!preg_match($pattern, $src, $hit)) {
            return false;
        }
        $count = 0;
        $out = preg_replace($pattern, '', $src, 1, $count);
        if ($out === null || $count !== 1) {
            return false;
        }
        if (strlen($src) - strlen($out) !== strlen($hit[0])) {
            return false;   // убралось не то, что собирались убрать
        }
        if (!str_contains($out, "'api_keys'") || substr_count($out, "'admin'") !== substr_count($src, "'admin'")) {
            return false;
        }
        @copy($path, $path . '.bak-adopt-' . date('Ymd-His'));
        return @file_put_contents($path, $out) !== false;
    }

    /**
     * Есть ли вообще файл выданных ключей. Нужен Auth: если ключи из конфига
     * перенесены сюда, а файл вдруг не прочитается, дверь должна закрыться,
     * а не открыться для всех.
     */
    public static function everIssued(): bool
    {
        $f = self::file();
        return is_file($f) && (int) @filesize($f) > 2;
    }

    /**
     * Отмечает, что ключом только что воспользовались.
     * Пишем не чаще раза в пять минут: иначе файл переписывался бы на каждый запрос.
     */
    public static function touch(string $key): void
    {
        $rows = self::all();
        $now = time();
        $changed = false;
        $found = false;
        foreach ($rows as &$row) {
            if (!hash_equals((string) ($row['key'] ?? ''), $key)) {
                continue;
            }
            $found = true;
            $last = isset($row['lastUsedAt']) ? (int) strtotime((string) $row['lastUsedAt']) : 0;
            if ($now - $last > 300) {
                $row['lastUsedAt'] = date('c', $now);
                $changed = true;
            }
        }
        unset($row);
        if ($changed) {
            self::save($rows);
            return;
        }
        if (!$found) {
            self::markSeen($key);   // ключ из config.php, отметка лежит отдельно
        }
    }

    /** Ключ для показа: начало и хвост, середина скрыта. */
    public static function mask(string $key): string
    {
        if (strlen($key) < 14) {
            // короткий самодельный ключ: показываем только начало, иначе в списке
            // не отличить один от другого
            return substr($key, 0, 4) . str_repeat('*', max(0, strlen($key) - 4));
        }
        return substr($key, 0, 10) . '...' . substr($key, -4);
    }

    /** Право управлять ключами: свой адрес и свой токен. */
    public static function mayManage(array $cfg): bool
    {
        return self::isAdminIp($cfg) && self::tokenOk($cfg);
    }

    /** Отдельно от адреса — чтобы сказать человеку, что именно не сошлось. */
    public static function tokenOk(array $cfg): bool
    {
        $token = (string) ($cfg['admin']['token'] ?? '');
        $given = (string) ($_POST['token'] ?? $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '');
        return $token !== '' && $given !== '' && hash_equals($token, $given);
    }

    public static function isAdminIp(array $cfg): bool
    {
        $allowed = (array) ($cfg['admin']['ips'] ?? []);
        if ($allowed === []) {
            return false;   // не настроено — значит, управление выключено
        }
        return in_array((string) ($_SERVER['REMOTE_ADDR'] ?? '-'), $allowed, true);
    }
}
