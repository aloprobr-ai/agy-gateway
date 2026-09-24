<?php
declare(strict_types=1);

/**
 * Токен управления — admin.token в config.php.
 *
 * Это пароль страницы /keys и заголовка X-Admin-Token, а не ключ к API.
 * При первом запуске bin/serve.sh и bin/serve.ps1 придумывают его сами
 * (bin/admin-token.php), чтобы выдавать ключи можно было сразу.
 */
final class AdminToken
{
    private const PREFIX = 'tk-';
    private const LENGTH = 32;
    // Без 0/O, 1/l/I: токен иногда перепечатывают с экрана руками.
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    public static function generate(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $out = self::PREFIX;
        for ($i = 0; $i < self::LENGTH; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }
        return $out;
    }

    /**
     * Вписать токен в admin.token файла config.php.
     * Возвращает null, если всё записано, иначе — что помешало.
     *
     * Меняется только сам строковый литерал. Прежде чем трогать config.php,
     * новый текст пробуется рядом: PHP должен прочитать из него ровно те же
     * настройки, что и раньше, плюс новый токен.
     */
    public static function write(string $path, string $token): ?string
    {
        if (!function_exists('token_get_all')) {
            return 'в PHP нет расширения tokenizer, без него не найти нужную строку';
        }
        $src = @file_get_contents($path);
        if ($src === false) {
            return 'не удалось прочитать ' . $path;
        }
        $at = self::locate($src);
        if ($at === null) {
            return "в config.php не нашлось строки 'token' => '...' внутри блока 'admin'";
        }
        $out = substr_replace($src, "'" . $token . "'", $at[0], $at[1]);

        $tmp = $path . '.tmp-token';
        if (@file_put_contents($tmp, $out) === false) {
            return 'не удалось записать рядом с config.php — нет прав на папку';
        }
        @chmod($tmp, 0600);
        try {
            $before = require $path;
            $after = require $tmp;
        } catch (Throwable $e) {
            return 'после правки config.php не читается (' . $e->getMessage() . '), файл не тронут';
        } finally {
            @unlink($tmp);
        }
        if (!is_array($before) || !is_array($after) || ($after['admin']['token'] ?? null) !== $token) {
            return 'после правки токен не читается из config.php, файл не тронут';
        }
        $before['admin']['token'] = $token;
        if ($before !== $after) {
            return 'правка задела что-то кроме admin.token, файл не тронут';
        }

        // Пишем в тот же файл, а не подменяем его: владелец и права остаются прежними.
        if (@file_put_contents($path, $out, LOCK_EX) === false) {
            return 'не удалось записать config.php — нет прав на файл';
        }
        return null;
    }

    /**
     * Где стоит значение admin.token: [смещение, длина] строкового литерала.
     * null — если такого места нет или оно не одно.
     *
     * Регулярка тут не годится: 'token' => '' есть и в блоке update, а внутри
     * admin лежит массив ips со своими скобками. Поэтому идём по лексемам PHP
     * и считаем глубину скобок.
     */
    private static function locate(string $src): ?array
    {
        $sig = [];
        $pos = 0;
        foreach (token_get_all($src) as $t) {
            $id = is_array($t) ? $t[0] : null;
            $text = is_array($t) ? $t[1] : $t;
            if ($id !== T_WHITESPACE && $id !== T_COMMENT && $id !== T_DOC_COMMENT) {
                $sig[] = [$id, $text, $pos];
            }
            $pos += strlen($text);
        }

        $depth = 0;
        $admin = null;   // глубина, на которой лежат ключи блока admin
        $found = [];
        foreach ($sig as $i => [$id, $text]) {
            if ($id === null && ($text === '[' || $text === '(')) {
                $depth++;
                continue;
            }
            if ($id === null && ($text === ']' || $text === ')')) {
                $depth--;
                if ($admin !== null && $depth < $admin) {
                    $admin = null;
                }
                continue;
            }
            if ($id !== T_CONSTANT_ENCAPSED_STRING || ($sig[$i + 1][1] ?? '') !== '=>') {
                continue;
            }
            $key = substr($text, 1, -1);
            if ($depth === 1 && $key === 'admin' && ($sig[$i + 2][1] ?? '') === '[') {
                $admin = $depth + 1;
            } elseif ($admin !== null && $depth === $admin && $key === 'token') {
                $value = $sig[$i + 2] ?? null;
                $next = $sig[$i + 3][1] ?? '';
                // Только простая строка: выражение вроде getenv(...) — чужая задумка, не трогаем.
                $found[] = $value !== null && $value[0] === T_CONSTANT_ENCAPSED_STRING
                    && ($next === ',' || $next === ']')
                    ? [$value[2], strlen($value[1])]
                    : null;
            }
        }
        return count($found) === 1 ? $found[0] : null;
    }
}
