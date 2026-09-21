<?php
declare(strict_types=1);

/**
 * Обновление списка моделей.
 *
 * Опрашивает оба источника и складывает результат в storage/models.json,
 * откуда его подхватывает /v1/models и подбор модели для CLI:
 *   - Gemini API  -> GET /v1beta/models (нужен ключ в gemini_keys);
 *   - Antigravity CLI -> вывод agy (команда настраивается в cli.models_command).
 *
 * Запуск (на сервере — от пользователя PHP-FPM, чтобы совпали права и $HOME):
 *   php bin/update_models.php
 *   sudo -u www php bin/update_models.php      # под nginx + PHP-FPM
 *
 * Ключи:
 *   --api-only     только модели Gemini API
 *   --cli-only     только модели CLI
 *   --dry-run      показать результат, ничего не записывать
 *   --quiet        только ошибки (для cron)
 *   --json         вывести получившийся каталог в stdout
 */

$root = dirname(__DIR__);
require $root . '/src/Http.php';
require $root . '/src/Logger.php';
require $root . '/src/ModelCatalog.php';

$opts = array_slice($argv, 1);
$has = static fn(string $flag): bool => in_array($flag, $opts, true);

$quiet = $has('--quiet');
$dryRun = $has('--dry-run');
$apiOnly = $has('--api-only');
$cliOnly = $has('--cli-only');

$say = static function (string $line) use ($quiet): void {
    if (!$quiet) {
        echo $line . "\n";
    }
};
$warn = static function (string $line): void {
    fwrite(STDERR, $line . "\n");
};

if (!is_file($root . '/config.php')) {
    $warn('Нет config.php — скопируйте config.example.php и заполните.');
    exit(2);
}
$cfg = require $root . '/config.php';
Logger::init($cfg);

$catalog = ModelCatalog::load();
// Данные источника, который сейчас не опрашивается, переносим из прошлого каталога.
$result = [
    'generated_at' => date('c'),
    'api' => $catalog['api'] ?? [],
    'cli' => $catalog['cli'] ?? [],
    'cli_display' => $catalog['cli_display'] ?? [],
    'sources' => is_array($catalog['sources'] ?? null) ? $catalog['sources'] : [],
];

// Источники, опрошенные именно в этом запуске: только по ним считается код возврата.
$touched = [];

// ---------------------------------------------------------------- Gemini API

if (!$cliOnly) {
    $keys = array_values(array_filter(
        $cfg['gemini_keys'] ?? [],
        static fn($k) => is_string($k) && trim($k) !== '' && !str_contains($k, 'ВАШ_КЛЮЧ')
    ));

    if ($keys === []) {
        $say('API: ключей Gemini нет — пропускаю (это нормально для CLI-режима).');
        $result['sources']['api'] = 'skipped: no keys';
        $touched[] = 'api';
    } else {
        $models = [];
        $error = null;
        $pageToken = null;
        $base = rtrim((string) ($cfg['gemini_base'] ?? 'https://generativelanguage.googleapis.com'), '/')
            . '/' . trim((string) ($cfg['gemini_api_version'] ?? 'v1beta'), '/') . '/models';

        do {
            $query = ['key' => $keys[0], 'pageSize' => 200];
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }
            $ch = curl_init($base . '?' . http_build_query($query));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_CONNECTTIMEOUT => 15,
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($body === false || $status < 200 || $status >= 300) {
                $decoded = json_decode((string) $body, true);
                $error = $decoded['error']['message'] ?? ($curlErr !== '' ? $curlErr : 'HTTP ' . $status);
                break;
            }

            $data = json_decode((string) $body, true);
            foreach (($data['models'] ?? []) as $m) {
                $id = preg_replace('#^models/#', '', (string) ($m['name'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $models[$id] = [
                    'display_name' => (string) ($m['displayName'] ?? $id),
                    'methods' => array_values((array) ($m['supportedGenerationMethods'] ?? [])),
                    'input_token_limit' => (int) ($m['inputTokenLimit'] ?? 0),
                    'output_token_limit' => (int) ($m['outputTokenLimit'] ?? 0),
                ];
            }
            $pageToken = $data['nextPageToken'] ?? null;
        } while ($pageToken);

        if ($error !== null) {
            $warn('API: не удалось получить список моделей — ' . $error);
            $result['sources']['api'] = 'error: ' . $error;
            $touched[] = 'api';
        } else {
            ksort($models);
            $result['api'] = $models;
            $result['sources']['api'] = 'ok';
            $touched[] = 'api';

            $chat = array_keys(array_filter($models, static fn($m) => in_array('generateContent', $m['methods'], true)));
            $embed = array_keys(array_filter($models, static fn($m) => in_array('embedContent', $m['methods'], true)));
            $say('API: найдено ' . count($models) . ' моделей (чат: ' . count($chat) . ', эмбеддинги: ' . count($embed) . ').');
        }
    }
}

// ---------------------------------------------------------------------- CLI

if (!$apiOnly) {
    $cli = $cfg['cli'] ?? [];
    $raw = $cli['command'] ?? 'agy';
    $prefix = is_array($raw) ? array_values(array_map('strval', $raw)) : [trim((string) $raw) ?: 'agy'];

    if (!function_exists('proc_open')) {
        $warn('CLI: proc_open отключена в php.ini — список моделей CLI получить нельзя.');
        $result['sources']['cli'] = 'error: proc_open disabled';
        $touched[] = 'cli';
    } else {
        // Разные версии CLI печатают список по-разному, поэтому пробуем по очереди.
        $commands = $cli['models_command'] ?? [
            ['models'],
            ['--list-models'],
            ['models', 'list'],
            ['--help'],
        ];
        if (!is_array($commands) || $commands === []) {
            $commands = [['--help']];
        }
        if (!is_array($commands[0] ?? null)) {
            $commands = [$commands]; // задали одну команду одним массивом аргументов
        }

        $env = getenv();
        if (!empty($cli['home'])) {
            $env['HOME'] = (string) $cli['home'];
            $env['USERPROFILE'] = (string) $cli['home'];
        }
        if (!empty($cli['path'])) {
            $env['PATH'] = (string) $cli['path'];
        }
        foreach ((array) ($cli['env'] ?? []) as $k => $v) {
            $env[(string) $k] = (string) $v;
        }

        $found = [];
        $foundDisplay = [];
        $usedCommand = null;

        foreach ($commands as $args) {
            $cmd = array_merge($prefix, array_map('strval', (array) $args));
            $out = run_command($cmd, $env, $cli['workdir'] ?? null, 60);
            if ($out === null) {
                continue;
            }
            $parsed = parse_models($out, (string) ($cli['models_pattern'] ?? ''));
            if ($parsed['ids'] !== []) {
                $found = $parsed['ids'];
                $foundDisplay = $parsed['display'];
                $usedCommand = implode(' ', $cmd);
                break;
            }
        }

        if ($found === []) {
            $warn('CLI: не удалось распознать список моделей.');
            $warn('     Посмотрите вывод вручную: ' . implode(' ', array_merge($prefix, ['--help'])));
            $warn('     и задайте в config.php cli.models_command (например [["models"]]) и/или cli.models_pattern.');
            $result['sources']['cli'] = 'error: not parsed';
            $touched[] = 'cli';
        } else {
            sort($found, SORT_NATURAL | SORT_FLAG_CASE);
            $result['cli'] = $found;
            $result['cli_display'] = $foundDisplay;
            $result['sources']['cli'] = 'ok: ' . $usedCommand;
            $touched[] = 'cli';
            $say('CLI: найдено ' . count($found) . ' моделей через «' . $usedCommand . '»:');
            foreach ($found as $name) {
                $label = $foundDisplay[$name] ?? '';
                $say('     - ' . $name . ($label !== '' ? '   (' . $label . ')' : ''));
            }

            // Подсказка: какие имена из cli.model_map больше не существуют.
            $stale = [];
            foreach ((array) ($cli['model_map'] ?? []) as $alias => $target) {
                $target = (string) $target;
                if ($target === '') {
                    continue;
                }
                $hit = false;
                foreach ($found as $name) {
                    if (strcasecmp($name, $target) === 0 || strcasecmp((string) ($foundDisplay[$name] ?? ''), $target) === 0) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    $stale[] = $alias . ' -> ' . $target;
                }
            }
            if ($stale !== []) {
                $warn('CLI: в cli.model_map есть алиасы на неизвестные CLI модели: ' . implode(', ', $stale));
            }
        }
    }
}

// -------------------------------------------------------------------- вывод

if ($has('--json')) {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
}

if ($dryRun) {
    $say('--dry-run: файл не записан.');
    exit(0);
}

if (!ModelCatalog::save($result)) {
    $warn('Не удалось записать ' . ModelCatalog::file() . ' — проверьте права на каталог storage/.');
    exit(1);
}

$say('Каталог обновлён: ' . ModelCatalog::file());

$failed = [];
foreach ($touched as $source) {
    if (str_starts_with((string) ($result['sources'][$source] ?? ''), 'error')) {
        $failed[] = $source;
    }
}
exit($failed === [] ? 0 : 1);

// ------------------------------------------------------------------ функции

/** Запускает команду и возвращает stdout+stderr, либо null при неудаче. */
function run_command(array $cmd, array $env, ?string $cwd, int $timeout): ?string
{
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $desc, $pipes, $cwd ?: null, $env);
    if (!is_resource($proc)) {
        return null;
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $out = '';
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $out .= (string) stream_get_contents($pipes[1]);
        $out .= (string) stream_get_contents($pipes[2]);
        $status = proc_get_status($proc);
        if (!($status['running'] ?? false)) {
            break;
        }
        usleep(100_000);
    }
    $out .= (string) stream_get_contents($pipes[1]);
    $out .= (string) stream_get_contents($pipes[2]);

    $status = proc_get_status($proc);
    if ($status['running'] ?? false) {
        proc_terminate($proc);
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    return $out;
}

/**
 * Достаёт названия моделей из вывода CLI.
 * Свой шаблон можно задать в config.php -> cli.models_pattern.
 * @return string[]
 */
function parse_models(string $output, string $customPattern = ''): array
{
    $output = preg_replace('/\e\[[0-9;]*m/', '', $output) ?? $output; // цветовые коды
    $names = [];

    // Формат `agy models`: "gemini-3.5-flash-medium<TAB>Gemini 3.5 Flash (Medium)".
    // Берём идентификатор (его ждёт --model), отображаемое имя сохраняем отдельно.
    if ($customPattern === '' && preg_match_all('/^\s*([A-Za-z0-9][\w.\-]{3,})	+(.+?)\s*$/m', $output, $rows, PREG_SET_ORDER)) {
        $display = [];
        foreach ($rows as $row) {
            $id = trim($row[1]);
            if ($id === '' || in_array($id, $names, true)) {
                continue;
            }
            $names[] = $id;
            $display[$id] = trim($row[2]);
        }
        if ($names !== []) {
            return ['ids' => $names, 'display' => $display];
        }
    }

    $patterns = $customPattern !== '' ? [$customPattern] : [
        // "Gemini 3.5 Flash (Medium)", "Gemini 3.1 Pro (High)"
        '/\b(Gemini\s+[\w.\-]+(?:\s+[A-Za-z]+)*\s*\((?:Low|Medium|High)\))/u',
        // "gemini-2.5-flash", "gemini-3-pro-preview"
        '/\b(gemini-[a-z0-9.\-]+)\b/i',
        // "claude-...", "gpt-..." — если CLI умеет и их
        '/\b((?:claude|gpt|gemma|llama)-[a-z0-9.\-]+)\b/i',
    ];

    foreach ($patterns as $pattern) {
        if (@preg_match($pattern, '') === false) {
            fwrite(STDERR, "Неверный cli.models_pattern — шаблон пропущен.\n");
            continue;
        }
        if (preg_match_all($pattern, $output, $m)) {
            foreach ($m[1] as $name) {
                $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
                if ($name !== '' && !in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }
        if ($names !== []) {
            break; // первый сработавший шаблон и есть нужный формат
        }
    }

    // Отсекаем очевидный мусор из текста справки.
    $names = array_values(array_filter($names, static function (string $n): bool {
        return mb_strlen($n) >= 4
            && !preg_match('/^(gemini-api|gemini-cli)$/i', $n);
    }));

    return ['ids' => $names, 'display' => []];
}
