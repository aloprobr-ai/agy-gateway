<?php
declare(strict_types=1);

/**
 * Кому из клиентов можно самим выключать промпт agy (src/AgyPrompt.php).
 *
 * Разрешение даёт только хозяин шлюза, здесь, на сервере. Клиент с ним
 * включает и выключает промпт для своего ключа сам — в Gemini Desktop это
 * команда /agy, которой без разрешения в программе нет вовсе. По умолчанию
 * не разрешено никому. Отозвали разрешение — промпт у ключа сразу как был.
 *
 *   php bin/agy-prompt.php                    кому разрешено и кто выключил
 *   php bin/agy-prompt.php on all             разрешить всем ключам
 *   php bin/agy-prompt.php off all            запретить всем (кроме разрешённых отдельно)
 *   php bin/agy-prompt.php on  <ключ> ...     разрешить этим ключам
 *   php bin/agy-prompt.php off <ключ> ...     запретить этим ключам
 *   php bin/agy-prompt.php default <ключ> ... ключ снова как у всех
 *
 * <ключ> — сам ключ, его начало (от 6 знаков, если однозначно) или имя со
 * страницы /keys. Запускать от пользователя PHP-FPM (sudo -u www …), иначе
 * шлюз потом не сможет записать файл. На сервере это делает обёртка
 * deploy/agy-mitm/agy-prompt.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
if (is_file($root . '/src/Compat.php')) {
    require $root . '/src/Compat.php';   // mb_* там, где нет mbstring
}
require $root . '/src/Logger.php';
require $root . '/src/Keys.php';
require $root . '/src/AgyPrompt.php';

$cfgFile = $root . '/config.php';
if (!is_file($cfgFile)) {
    fwrite(STDERR, "config.php не найден\n");
    exit(1);
}
$cfg = require $cfgFile;

$args = array_slice($argv, 1);
$mode = strtolower((string) ($args[0] ?? ''));
$targets = array_slice($args, 1);

$fail = static function (string $text): void {
    fwrite(STDERR, $text . "\n");
    exit(2);
};

/** Ключи по словам из командной строки; неизвестное или неоднозначное — ошибка. */
$resolve = static function (array $words) use ($cfg, $fail): array {
    $known = AgyPrompt::knownKeys($cfg);
    $labels = [];
    foreach ($words as $word) {
        $word = trim((string) $word);
        $found = array_values(array_filter($known, static fn($k) => $k['key'] === $word));
        if ($found === []) {
            $re = '/^' . preg_quote($word, '/') . '$/iu';
            $found = array_values(array_filter($known, static fn($k) => preg_match($re, $k['name']) === 1));
        }
        if ($found === [] && strlen($word) >= 6) {
            $found = array_values(array_filter($known, static fn($k) => str_starts_with($k['key'], $word)));
            if (count($found) > 1) {
                $fail("«{$word}» подходит к нескольким ключам — допишите начало ключа.");
            }
        }
        if ($found === []) {
            $fail("Ключ «{$word}» не найден. Можно сам ключ, его начало (от 6 знаков) или имя со страницы /keys.");
        }
        foreach ($found as $k) {
            $labels[] = $k['label'];
        }
    }
    return array_unique($labels);
};

$state = AgyPrompt::load();

if ($mode !== '') {
    if (!in_array($mode, ['on', 'off', 'default'], true) || $targets === []) {
        $fail("использование: agy-prompt [on|off all] [on|off|default <ключ> ...]");
    }
    if ($targets === ['all']) {
        if ($mode === 'default') {
            $fail('Для всех — только on или off.');
        }
        $state['all'] = $mode === 'on';
    } else {
        if (in_array('all', $targets, true)) {
            $fail('«all» — это все ключи сразу, ключи рядом с ним не нужны.');
        }
        foreach ($resolve($targets) as $label) {
            if ($mode === 'default') {
                unset($state['keys'][$label]);
            } else {
                $state['keys'][$label] = $mode === 'on';
            }
        }
    }
    // Разрешение отозвано — выключенный клиентом промпт возвращается сразу,
    // а при новом разрешении ключ начинает с «как есть».
    foreach (array_keys($state['off']) as $label) {
        if (!AgyPrompt::allowed($state, $label)) {
            unset($state['off'][$label]);
        }
    }
    if (!AgyPrompt::save($state)) {
        $fail('Не записался storage/agy-prompt.json — нет прав на storage/.');
    }
}

// Что сейчас.
echo 'Перехватчик: ' . (AgyPrompt::available($cfg) ? 'есть' : 'нет (cli.upstream_proxy пуст) — промпт agy не выключить никому') . "\n";
echo 'Разрешено всем: ' . ($state['all'] ? 'да' : 'нет') . "\n\n";
$rows = [];
foreach (AgyPrompt::knownKeys($cfg) as $k) {
    $own = array_key_exists($k['label'], $state['keys']);
    $rows[] = [
        Keys::mask($k['key']),
        $k['name'],
        (AgyPrompt::allowed($state, $k['label']) ? 'да' : 'нет') . ($own ? ' (своё)' : ''),
        !empty($state['off'][$k['label']]) ? 'выключен клиентом' : 'как есть',
    ];
}
$head = ['Ключ', 'Имя', 'Разрешено', 'Промпт agy'];
$width = [];
foreach (array_merge([$head], $rows) as $row) {
    foreach ($row as $i => $cell) {
        $width[$i] = max($width[$i] ?? 0, mb_strlen($cell));
    }
}
foreach (array_merge([$head], $rows) as $row) {
    $line = '';
    foreach ($row as $i => $cell) {
        $line .= $cell . str_repeat(' ', $width[$i] - mb_strlen($cell) + 2);
    }
    echo rtrim($line) . "\n";
}
