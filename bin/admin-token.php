<?php
declare(strict_types=1);

/**
 * Токен управления для страницы /keys (admin.token в config.php).
 *
 *     php bin/admin-token.php           придумать и вписать, если его ещё нет
 *     php bin/admin-token.php --show    показать текущий
 *     php bin/admin-token.php --new     заменить новым: старый перестанет подходить
 *
 * bin/serve.sh и bin/serve.ps1 зовут его сами при каждом запуске с --quiet:
 * если токен уже есть, ничего не пишется. Запускать от того же пользователя,
 * которому принадлежит config.php, — иначе записать его не выйдет.
 */

$root = dirname(__DIR__);
require $root . '/src/AdminToken.php';

$args = array_slice($argv, 1);
$quiet = in_array('--quiet', $args, true);
$show = in_array('--show', $args, true);
$renew = in_array('--new', $args, true);

$path = $root . '/config.php';
if (!is_file($path)) {
    fwrite(STDERR, "Нет config.php. Сначала скопируйте образец:\n"
        . "    cp config.example.php config.php        (Linux, macOS)\n"
        . "    copy config.example.php config.php      (Windows)\n");
    exit(1);
}
$cfg = require $path;
$current = (string) ($cfg['admin']['token'] ?? '');

if ($show) {
    if ($current === '') {
        echo "Токен управления не задан. Придумать: php bin/admin-token.php\n";
        exit(1);
    }
    echo $current . "\n";
    exit(0);
}

if ($current !== '' && !$renew) {
    if (!$quiet) {
        echo "Токен управления уже задан (admin.token в config.php).\n"
            . "Показать: php bin/admin-token.php --show, заменить: php bin/admin-token.php --new\n";
    }
    exit(0);
}

// Пустой admin.ips — управление выключено совсем, и пароль к нему не нужен.
if ((array) ($cfg['admin']['ips'] ?? []) === [] && !$renew) {
    if (!$quiet) {
        echo "Управление ключами выключено: admin.ips в config.php пуст. Токен не нужен.\n";
    }
    exit(0);
}

$token = AdminToken::generate();
$error = AdminToken::write($path, $token);
if ($error !== null) {
    fwrite(STDERR, "Токен управления не записан: {$error}.\n"
        . "Впишите его сами в config.php, в блок 'admin':\n"
        . "    'token' => '{$token}',\n");
    exit(1);
}

// Под systemd вывод уходит в журнал, а секрету там не место: покажем по --show.
if ($quiet && !stream_isatty(STDOUT)) {
    echo "Создан токен управления для /keys, он в config.php (admin.token).\n"
        . "Показать: php bin/admin-token.php --show\n";
    exit(0);
}
echo ($renew && $current !== '' ? "Токен управления заменён" : "Создан токен управления")
    . " — пароль страницы /keys, записан в config.php (admin.token):\n\n"
    . "    {$token}\n\n"
    . "Это не ключ к API: им выдают и отзывают ключи. Показать снова: php bin/admin-token.php --show\n";
