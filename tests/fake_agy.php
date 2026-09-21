<?php
declare(strict_types=1);

/**
 * Заглушка Antigravity CLI — чтобы шлюз можно было проверить без учётной записи.
 *
 * Говорит на том же языке, что настоящий agy в режиме
 * `--output-format stream-json`: печатает в stdout поток событий по одному
 * JSON в строке. Шлюзу всё равно, кто на том конце, поэтому так проверяется
 * весь путь целиком — разбор событий, потоковая выдача, файлы, подсчёт токенов.
 *
 * Как настоящий:
 *   php tests/fake_agy.php -p "промпт" [--model M] [--conversation UUID]
 *   php tests/fake_agy.php models
 *
 * Подсказки в промпте (для тестов):
 *   слово «картинк» или «нарисуй» — создаст png в текущем каталоге;
 *   «ДОСТУПНЫЕ ИНСТРУМЕНТЫ» + «погод» — ответит вызовом инструмента.
 */

$args = array_slice($argv, 1);

// Заглушка обязана отвечать и на служебные вопросы: по ним bin/doctor.php
// судит, годится ли CLI вообще, и без них он честно признал бы её негодной.
if (in_array('--version', $args, true)) {
    echo "0.0.0-fake\n";
    exit(0);
}
if (in_array('--help', $args, true) || $args === []) {
    echo "Usage of fake_agy:\n";
    echo "  --conversation   Resume a previous conversation by ID\n";
    echo "  --model          Model for the current CLI session\n";
    echo "  --output-format  Output format for print mode (text, json, stream-json)\n";
    echo "  -p, --print      Run a single prompt non-interactively\n";
    exit(0);
}

if (in_array('models', $args, true) || in_array('--list-models', $args, true)) {
    echo "Available models:\n";
    echo "  - Gemini 3.5 Flash (Medium)\n";
    echo "  - Gemini 3.5 Flash (High)\n";
    echo "  - Gemini 3.1 Pro (High)\n";
    exit(0);
}

$prompt = '';
$conversation = '';
$model = '';
for ($i = 0; $i < count($args); $i++) {
    if (($args[$i] === '-p' || $args[$i] === '--print' || $args[$i] === '--prompt') && isset($args[$i + 1])) {
        $prompt = $args[++$i];
    } elseif ($args[$i] === '--conversation' && isset($args[$i + 1])) {
        $conversation = $args[++$i];
    } elseif ($args[$i] === '--model' && isset($args[$i + 1])) {
        $model = $args[++$i];
    }
}

if ($conversation === '') {
    $conversation = sprintf('%s-%s-%s',
        bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), bin2hex(random_bytes(6)));
}

/**
 * Настоящий png размером в один пиксель заданного цвета.
 *
 * Собираем руками, потому что расширения gd может не быть, а картинка должна
 * быть именно картинкой: шлюз смотрит на содержимое файла, а не на имя.
 */
function fake_png(int $r, int $g, int $b): string
{
    $chunk = static function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data
            . pack('N', crc32($type . $data));
    };

    $ihdr = pack('NN', 1, 1) . chr(8) . chr(2) . chr(0) . chr(0) . chr(0);
    $raw = chr(0) . chr($r) . chr($g) . chr($b);   // байт фильтра + пиксель

    return "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', $ihdr)
        . $chunk('IDAT', gzcompress($raw, 9))
        . $chunk('IEND', '');
}

/** Событие в stdout: одна строка — один JSON, как у настоящего CLI. */
$emit = static function (array $event): void {
    echo json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    flush();
};

$emit(['event' => 'init', 'init' => ['conversation_id' => $conversation]]);

// Что отвечаем.
$madeFile = null;
// Признак того, что шлюз перечислил инструменты, — упоминание формата ответа
// tool_calls: за него и цепляемся. Сам заголовок списка переписывали уже
// дважды, а этот кусок менять нельзя, его разбирает шлюз.
if (str_contains($prompt, 'tool_calls') && preg_match('/погод/iu', $prompt)) {
    $answer = '{"tool_calls":[{"name":"get_weather","args":{"city":"Ростов-на-Дону"}}]}';
} else {
    $answer = 'Ответ заглушки CLI'
        . ($model !== '' ? " (модель: {$model})" : '')
        . ". Беседа: {$conversation}. Промпт был длиной " . strlen($prompt) . " байт.";

    // Проверка того, что картинка из запроса реально долежала до CLI.
    if (preg_match('/изображение сохранено в файл: "([^"]+)"/u', $prompt, $m)) {
        $answer .= ' Файл картинки на месте: ' . (is_file($m[1]) ? 'да' : 'нет') . '.';
    }

    // Проверка обратного пути: файл, созданный моделью, должен дойти до клиента.
    if (preg_match('/картинк|нарисуй|graph|chart/iu', $prompt)) {
        $emit(['event' => 'step_update', 'step_update' => [
            'step_type' => 'tool', 'state' => 'ACTIVE', 'tool_name' => 'generate_image',
        ]]);
        // Однопиксельный png — меньше некуда, а форматом настоящий. Цвет
        // каждый раз новый: шлюз отбрасывает повторы, сверяя содержимое,
        // и на одинаковых картинках заказ «нарисуй две» выглядел бы сломанным.
        $png = fake_png(random_int(0, 255), random_int(0, 255), random_int(0, 255));
        $madeFile = getcwd() . DIRECTORY_SEPARATOR . 'fake-' . bin2hex(random_bytes(4)) . '.png';
        @file_put_contents($madeFile, $png);
        $emit(['event' => 'step_update', 'step_update' => [
            'step_type' => 'tool', 'state' => 'DONE', 'tool_name' => 'generate_image',
        ]]);
        $answer .= ' Картинку сохранил.';
    }
}

// Служебный шаг перед ответом — шлюз показывает такие в «Размышлениях».
$emit(['event' => 'step_update', 'step_update' => ['step_type' => 'checkpoint', 'state' => 'DONE']]);

// Текст появляется по кускам, как при настоящей генерации: так проверяется,
// что шлюз отдаёт потоком, а не копит ответ целиком.
$chunks = preg_split('/(?<=[\s.,!])/u', $answer, -1, PREG_SPLIT_NO_EMPTY) ?: [$answer];
foreach ($chunks as $chunk) {
    $emit(['event' => 'step_update', 'step_update' => [
        'step_type' => 'agent_response',
        'state' => 'RUNNING',
        'text_delta' => $chunk,
    ]]);
    usleep(40_000);
}

$emit(['event' => 'result', 'result' => [
    'status' => 'success',
    'conversation_id' => $conversation,
    'response' => $answer,
    'usage' => [
        'input_tokens' => (int) ceil(strlen($prompt) / 4),
        'output_tokens' => (int) ceil(strlen($answer) / 4),
        'thinking_tokens' => 7,
        'total_tokens' => (int) ceil((strlen($prompt) + strlen($answer)) / 4),
    ],
]]);

exit(0);
