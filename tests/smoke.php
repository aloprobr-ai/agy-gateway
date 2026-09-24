<?php
declare(strict_types=1);

/**
 * Проверка работающего шлюза: пройтись по всем ручкам и посмотреть, что живо.
 *
 *   php tests/smoke.php                          по умолчанию http://127.0.0.1:8080
 *   php tests/smoke.php http://127.0.0.1:8080
 *   php tests/smoke.php https://ваш.домен sk-ключ
 *
 * Ключ нужен, только если шлюз его требует: это видно в /health, поле
 * auth_required. Требование включают и 'api_keys' в config.php, и ключи,
 * выданные на странице /keys.
 */

require dirname(__DIR__) . '/src/Compat.php';   // mb_* там, где нет mbstring

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
$key  = $argv[2] ?? '';

$failed = 0;

/**
 * Один HTTP-запрос. Через curl, а если его в PHP нет — через потоки.
 *
 * Запасной путь здесь не роскошь: PHP для Windows приезжает без php.ini,
 * и curl в нём выключен. Проверялка, которая сама не запускается на свежей
 * системе, бесполезна именно тогда, когда нужнее всего.
 */
function req(string $method, string $url, ?array $body, string $key, bool $stream = false, array $headers = []): array
{
    $all = array_merge(['Content-Type: application/json'], $headers);
    if ($key !== '') {
        $all[] = 'Authorization: Bearer ' . $key;
    }
    $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE);

    if (!function_exists('curl_init')) {
        return req_streams($method, $url, $payload, $all);
    }
    return req_curl($method, $url, $payload, $all, $stream);
}

/** @param string[] $headers */
function req_streams(string $method, string $url, ?string $payload, array $headers): array
{
    $ctx = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $payload ?? '',
        'timeout' => 180,
        'ignore_errors' => true,     // тело ответа нужно и при 4xx, там текст ошибки
    ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);

    $out = @file_get_contents($url, false, $ctx);
    $status = 0;
    $respHeaders = [];
    foreach ($http_response_header ?? [] as $i => $line) {
        if ($i === 0 && preg_match('~HTTP/\S+\s+(\d{3})~', $line, $m)) {
            $status = (int) $m[1];
            continue;
        }
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }
    return [
        'status' => $status,
        'body' => (string) $out,
        'err' => $out === false ? 'нет ответа' : '',
        'headers' => $respHeaders,
    ];
}

/** @param string[] $headers */
function req_curl(string $method, string $url, ?string $payload, array $headers, bool $stream): array
{
    $ch = curl_init($url);
    $chunks = '';
    $respHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_RETURNTRANSFER => !$stream,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }
    if ($stream) {
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$chunks) {
            $chunks .= $data;
            return strlen($data);
        });
    }
    $out = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['status' => $status, 'body' => $stream ? $chunks : (string) $out, 'err' => $err, 'headers' => $respHeaders];
}

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    if (!$ok) {
        $failed++;
    }
    printf("%s  %s%s\n", $ok ? ' OK ' : 'FAIL', $name, $detail !== '' ? ' — ' . $detail : '');
}

// 1. health (с повтором: разовый сетевой сбой не должен ломать весь прогон)
$r = ['status' => 0, 'body' => '', 'headers' => []];
for ($try = 1; $try <= 3; $try++) {
    $r = req('GET', $base . '/health', null, $key);
    if ($r['status'] !== 0) {
        break;
    }
    sleep(2);
}
$health = json_decode($r['body'], true);
if ($r['status'] === 0) {
    fwrite(STDERR, "Сервер недоступен: " . ($r['err'] ?: 'нет ответа') . "\n");
    exit(2);
}
check('GET /health', $r['status'] === 200 && ($health['status'] ?? '') === 'ok', 'HTTP ' . $r['status']);

$backend = (string) ($health['backend'] ?? 'api');
echo "     режим: {$backend}\n";

if ($backend === 'cli') {
    $cli = $health['cli'] ?? [];
    check('CLI: proc_open разрешена', !empty($cli['proc_open']), '', );
    check('CLI: программа на месте', !empty($cli['command_found']), (string) ($cli['command'] ?? ''));
} elseif (($health['keys_configured'] ?? 0) < 1) {
    check('ключи Gemini в config.php', false, 'keys_configured = 0');
}

// Модель, которую понимает текущий бэкенд.
$model = $backend === 'cli' ? 'agy' : 'gemini-2.5-flash';

// Свежие сессии на каждый прогон: иначе CLI продолжит беседу прошлого запуска
// и ответы будут зависеть от накопленного контекста.
$run = bin2hex(random_bytes(3));
$sess = static fn(string $name): array => ['X-Session-Id: smoke-' . $GLOBALS['run'] . '-' . $name];

// 2. /health должен говорить правду про защиту.
// Проверяем не поле само по себе, а запрос совсем без Authorization: по этому
// полю клиенты решают, слать ключ или нет, и расхождение здесь тихо ломает их,
// а не нас. 401 — ключ нужен; любой другой ответ — пускают и без ключа.
$r = req('GET', $base . '/v1/models', null, '');
$reallyRequired = $r['status'] === 401;
$saysRequired = !empty($health['auth_required']);
check('/health: auth_required совпадает с делом',
    $reallyRequired === $saysRequired,
    'health: ' . ($saysRequired ? 'да' : 'нет')
        . ', запрос без ключа: HTTP ' . $r['status'] . ($reallyRequired ? ' (ключ нужен)' : ' (пускают)'));

// 3. неверный ключ должен отбиваться — но только там, где ключи вообще заведены.
// На шлюзе для себя 'api_keys' обычно пуст, и ждать 401 значило бы ругать
// исправную настройку.
if ($reallyRequired) {
    $r = req('GET', $base . '/v1/models', null, 'sk-definitely-wrong-key');
    check('неверный ключ -> 401', $r['status'] === 401, 'HTTP ' . $r['status']);
} else {
    echo "     ключи не заведены — проверку чужого ключа пропускаю\n";
}

// 4. список моделей
$r = req('GET', $base . '/v1/models', null, $key);
$models = json_decode($r['body'], true);
check('GET /v1/models', $r['status'] === 200 && !empty($models['data']), 'моделей: ' . count($models['data'] ?? []));

// 5. обычный чат
$r = req('POST', $base . '/v1/chat/completions', [
    'model' => $model,
    'messages' => [['role' => 'system', 'content' => 'Отвечай одним словом.'],
                   ['role' => 'user', 'content' => 'Столица Франции?']],
    'max_tokens' => 50,
], $key, false, $sess('chat'));
$chat = json_decode($r['body'], true);
$text = $chat['choices'][0]['message']['content'] ?? '';
check('POST /v1/chat/completions', $r['status'] === 200 && $text !== '', trim(mb_substr((string) $text, 0, 60)));
// Заглушка CLI рассказывает, как до неё дошло системное сообщение: оно должно
// лечь в GEMINI.md беседы (agy ставит такие правила выше своего промпта), а не
// текстом в сообщение. У настоящего agy так не спросить — там проверка пропускается.
if (str_starts_with((string) $text, 'Ответ заглушки')) {
    check('системное -> GEMINI.md, не текстом',
        str_contains($text, 'Правила: Отвечай одним словом.') && !str_contains($text, 'пришло текстом'),
        trim(mb_substr((string) strstr($text, 'Правила'), 0, 70)));
}
check('usage не пустой', (int) ($chat['usage']['total_tokens'] ?? 0) > 0, 'total_tokens = ' . ($chat['usage']['total_tokens'] ?? 0)
    . (!empty($chat['usage']['estimated']) ? ' (оценка, CLI не сообщает расход)' : ''));

// 5а. промпт agy по желанию клиента (/v1/agy-prompt). Разрешает его сервер
// (bin/agy-prompt.php); без разрешения клиент не может ничего.
$r = req('GET', $base . '/v1/agy-prompt', null, $key);
$ap = json_decode($r['body'], true);
check('GET /v1/agy-prompt', $r['status'] === 200 && ($ap['object'] ?? '') === 'agy_prompt',
    'разрешено: ' . (!empty($ap['allowed']) ? 'да' : 'нет') . ', промпт: ' . ($ap['agy_prompt'] ?? '?'));
if (is_array($ap) && empty($ap['allowed'])) {
    $r = req('POST', $base . '/v1/agy-prompt', ['agy_prompt' => 'off'], $key);
    check('agy-prompt: без разрешения сервера -> 403', $r['status'] === 403, 'HTTP ' . $r['status']);
} elseif (is_array($ap)) {
    $r = req('POST', $base . '/v1/agy-prompt', ['agy_prompt' => 'off', 'keys' => 'all'], $key);
    check('agy-prompt: чужие ключи отсюда не трогаются', $r['status'] === 400, 'HTTP ' . $r['status']);
    $r = req('POST', $base . '/v1/agy-prompt', ['agy_prompt' => 'off'], $key);
    check('agy-prompt: свой ключ -> off', $r['status'] === 200
        && (json_decode($r['body'], true)['agy_prompt'] ?? '') === 'off', 'HTTP ' . $r['status']);
    $r = req('POST', $base . '/v1/chat/completions', [
        'model' => $model,
        'messages' => [['role' => 'user', 'content' => 'Привет']],
    ], $key, false, $sess('strip'));
    $t = (string) (json_decode($r['body'], true)['choices'][0]['message']['content'] ?? '');
    // У заглушки CLI видно, дошла ли пометка для перехватчика до GEMINI.md.
    if (str_starts_with($t, 'Ответ заглушки')) {
        check('agy-prompt: пометка дошла до CLI', str_contains($t, 'strip-agy-prompt'), trim(mb_substr((string) strstr($t, 'Правила'), 0, 70)));
    }
    // Файл с пометкой пишет шлюз, а не модель: в ответ он попасть не должен.
    check('agy-prompt: GEMINI.md не выложен как файл модели', !str_contains($t, 'GEMINI.md'), $t === '' ? 'пустой ответ' : '');
    $r = req('POST', $base . '/v1/agy-prompt', ['agy_prompt' => 'on'], $key);
    check('agy-prompt: свой ключ -> on', $r['status'] === 200
        && (json_decode($r['body'], true)['agy_prompt'] ?? '') === 'on', 'HTTP ' . $r['status']);
}

// 6. картинка на вход (красный квадрат 8x8 PNG, сгенерирован прямо здесь)
$png = base64_encode(makeRedSquare());
$r = req('POST', $base . '/v1/chat/completions', [
    'model' => $model,
    'messages' => [[
        'role' => 'user',
        'content' => [
            ['type' => 'text', 'text' => 'Каким цветом залито изображение? Ответь одним словом.'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . $png]],
        ],
    ]],
    'max_tokens' => 50,
], $key, false, $sess('vision'));
$vision = json_decode($r['body'], true);
$answer = (string) ($vision['choices'][0]['message']['content'] ?? '');
check('картинка на вход (base64)', $r['status'] === 200 && $answer !== '', trim(mb_substr($answer, 0, 60)));

// 7. стриминг
$r = req('POST', $base . '/v1/chat/completions', [
    'model' => $model,
    'messages' => [['role' => 'user', 'content' => 'Посчитай от 1 до 5 через запятую.']],
    'stream' => true,
    'stream_options' => ['include_usage' => true],
], $key, true, $sess('stream'));
$frames = substr_count($r['body'], 'data: ');
check('стриминг (SSE)', str_contains($r['body'], 'chat.completion.chunk') && str_contains($r['body'], '[DONE]'), 'кадров: ' . $frames);

// 8. function calling
$r = req('POST', $base . '/v1/chat/completions', [
    'model' => $model,
    'messages' => [['role' => 'user', 'content' => 'Какая погода в Ростове-на-Дону?']],
    'tools' => [[
        'type' => 'function',
        'function' => [
            'name' => 'get_weather',
            'description' => 'Текущая погода в городе',
            'parameters' => [
                'type' => 'object',
                'properties' => ['city' => ['type' => 'string', 'description' => 'Название города']],
                'required' => ['city'],
                'additionalProperties' => false,
            ],
        ],
    ]],
    'tool_choice' => 'required',
], $key, false, $sess('tools'));
$tools = json_decode($r['body'], true);
$call = $tools['choices'][0]['message']['tool_calls'][0]['function']['name'] ?? '';
check('function calling', $r['status'] === 200 && $call === 'get_weather', $call !== '' ? $call : ('HTTP ' . $r['status']));

// 9. JSON-режим (в CLI-режиме параметр не передать, поэтому просим текстом)
if ($backend === 'api') {
    $r = req('POST', $base . '/v1/chat/completions', [
        'model' => $model,
        'messages' => [['role' => 'user', 'content' => 'Верни JSON с полем city = Москва']],
        'response_format' => ['type' => 'json_object'],
    ], $key, false, $sess('json'));
    $json = json_decode($r['body'], true);
    $content = (string) ($json['choices'][0]['message']['content'] ?? '');
    check('response_format json_object', is_array(json_decode($content, true)), trim(mb_substr($content, 0, 60)));
} else {
    // 9. сессия: второй запрос должен попасть в ту же беседу CLI
    $sid = 'smoke-' . bin2hex(random_bytes(4));
    $first = req('POST', $base . '/v1/chat/completions', [
        'model' => $model,
        'messages' => [['role' => 'user', 'content' => 'Запомни кодовое слово: ГРАНАТ. Ответь просто "запомнил".']],
    ], $key, false, ['X-Session-Id: ' . $sid]);

    $r = req('POST', $base . '/v1/chat/completions', [
        'model' => $model,
        'messages' => [
            ['role' => 'user', 'content' => 'Запомни кодовое слово: ГРАНАТ. Ответь просто "запомнил".'],
            ['role' => 'assistant', 'content' => 'запомнил'],
            ['role' => 'user', 'content' => 'Назови кодовое слово одним словом.'],
        ],
    ], $key, false, ['X-Session-Id: ' . $sid]);
    $memo = json_decode($r['body'], true);
    $answer2 = (string) ($memo['choices'][0]['message']['content'] ?? '');

    $conv1 = $first['headers']['x-conversation-id'] ?? '';
    $conv2 = $r['headers']['x-conversation-id'] ?? '';
    check('сессия продолжает ту же беседу CLI', $conv1 !== '' && $conv1 === $conv2, $conv1 === $conv2 ? $conv1 : "{$conv1} != {$conv2}");

    // Проверка памяти самой модели — информационная: зависит от модели, а не от шлюза.
    printf("%s  модель помнит контекст беседы — %s
",
        mb_stripos($answer2, 'ГРАНАТ') !== false ? ' OK ' : 'INFO',
        trim(mb_substr($answer2, 0, 60)));
}

// 10. эмбеддинги (только там, где есть ключи Gemini)
$r = req('POST', $base . '/v1/embeddings', [
    'model' => 'text-embedding-004',
    'input' => ['привет', 'hello'],
], $key);
if ($r['status'] === 501) {
    echo "SKIP  /v1/embeddings — недоступны в CLI-режиме (нужен ключ Gemini)
";
} else {
    $emb = json_decode($r['body'], true);
    $dim = count($emb['data'][0]['embedding'] ?? []);
    check('POST /v1/embeddings', $r['status'] === 200 && $dim > 0, 'размерность: ' . $dim);
}

// 11. рисование: работает в обоих режимах — по ключам это модель Gemini Image,
// через CLI его собственный инструмент.
$r = req('POST', $base . '/v1/images/generations', [
    'prompt' => 'простая картинка: рыжий кот на подоконнике',
    'n' => 1,
    'response_format' => 'url',
], $key);
$img = json_decode($r['body'], true);
$url = (string) ($img['data'][0]['url'] ?? '');
check('POST /v1/images/generations', $r['status'] === 200 && $url !== '',
    $url !== '' ? $url : ('HTTP ' . $r['status']));

// Ссылка должна не просто вернуться, а открываться и быть картинкой:
// адрес в ответе — это обещание, и проверять надо его, а не наличие строки.
if ($url !== '' && !str_starts_with($url, 'data:')) {
    $got = req('GET', $url, null, '');
    $body = (string) $got['body'];
    $isImage = $got['status'] === 200 && (
        str_starts_with($body, "\x89PNG") || str_starts_with($body, "\xff\xd8")
        || str_starts_with($body, 'GIF8') || str_starts_with($body, 'RIFF')
    );
    check('нарисованное открывается по ссылке', $isImage,
        strlen($body) . ' байт, ' . ($got['headers']['content-type'] ?? '?'));
}

echo "\n" . ($failed === 0 ? "Все проверки пройдены.\n" : "Провалено проверок: {$failed}\n");
exit($failed === 0 ? 0 : 1);

/** Минимальный валидный PNG 8x8, залитый красным (без GD). */
function makeRedSquare(): string
{
    $w = $h = 8;
    $raw = '';
    for ($y = 0; $y < $h; $y++) {
        $raw .= chr(0);                       // фильтр строки
        $raw .= str_repeat(chr(220) . chr(30) . chr(30), $w);
    }
    $chunk = static function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    };
    return "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NN', $w, $h) . chr(8) . chr(2) . chr(0) . chr(0) . chr(0))
        . $chunk('IDAT', gzcompress($raw, 9))
        . $chunk('IEND', '');
}
