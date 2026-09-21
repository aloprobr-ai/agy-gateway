<?php
declare(strict_types=1);

/**
 * Роутер для встроенного сервера PHP: `php -S 127.0.0.1:8080 -t public public/router.php`.
 *
 * Нужен потому, что встроенный сервер не умеет «а если файла нет, отдай
 * index.php» — это за него делает nginx или Apache. Здесь то же правило
 * в четыре строки: есть настоящий файл — пусть отдаёт сам, нет — за дело
 * берётся шлюз.
 *
 * В бою (nginx + PHP-FPM) этот файл не участвует, см. deploy/.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Готовый файл отдаём только из public и только не-PHP: остальное — работа шлюза.
// realpath разворачивает «..», поэтому уйти выше корня по ссылке не получится.
$candidate = realpath(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));
$root = realpath(__DIR__);

if ($path !== '/' && $candidate !== false && $root !== false
    && str_starts_with($candidate, $root . DIRECTORY_SEPARATOR)
    && is_file($candidate)
    && !str_ends_with(strtolower($candidate), '.php')) {
    return false;   // false = «отдай сам», так условлено со встроенным сервером
}

require __DIR__ . '/index.php';
