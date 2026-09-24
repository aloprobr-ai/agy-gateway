<?php
declare(strict_types=1);

/**
 * Обработчики OpenAI-совместимых эндпоинтов.
 */
final class Endpoints
{
    /** GET /v1/models */
    public static function models(array $cfg): void
    {
        $data = [];
        $seen = [];
        $seenAt = ModelCatalog::firstSeen();

        $add = static function (string $id, string $owner) use (&$data, &$seen, $seenAt): void {
            if ($id === '' || isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;
            $data[] = [
                'id' => $id,
                'object' => 'model',
                // когда модель впервые появилась в каталоге: клиент по этому
                // полю отмечает новинки. Нет отметки — значит, была всегда.
                'created' => (int) ($seenAt[$id] ?? 1700000000),
                'owned_by' => $owner,
            ];
        };

        // Алиасы CLI из конфига.
        foreach (array_keys((array) ($cfg['cli']['model_map'] ?? [])) as $alias) {
            $add((string) $alias, 'agy-cli');
        }
        // Реальные имена моделей CLI, обнаруженные bin/update_models.php.
        foreach (ModelCatalog::cli() as $name) {
            $add((string) $name, 'agy-cli');
        }

        try {
            if (!self::hasApiKeys($cfg)) {
                throw new RuntimeException('no Gemini keys configured');
            }
            $client = new Gemini($cfg);
            $resp = $client->call('models', null, ['pageSize' => 200], 'GET');
            foreach (($resp['models'] ?? []) as $m) {
                $add((string) preg_replace('#^models/#', '', (string) ($m['name'] ?? '')), 'google');
            }
        } catch (Throwable $e) {
            Logger::line('warn', 'models list failed, falling back to catalog', ['err' => $e->getMessage()]);
            // Живой запрос не удался — отдаём то, что собрал update_models.php.
            foreach (array_keys(ModelCatalog::api()) as $id) {
                $add((string) $id, 'google');
            }
        }

        // Алиасы из конфига — чтобы клиенты с зашитым gpt-4o видели «свою» модель.
        foreach (array_keys(self::hasApiKeys($cfg) ? ($cfg['model_map'] ?? []) : []) as $alias) {
            if (!isset($seen[$alias])) {
                $seen[$alias] = true;
                $data[] = ['id' => $alias, 'object' => 'model', 'created' => 1700000000, 'owned_by' => 'google'];
            }
        }

        Http::json([
            'object' => 'list',
            'data' => $data,
            // когда шлюз в последний раз пересматривал список моделей
            'generated_at' => ModelCatalog::generatedAt(),
        ]);
    }

    /** GET /v1/models/{id} */
    public static function model(array $cfg, string $id): void
    {
        Http::json([
            'id' => $id,
            'object' => 'model',
            'created' => 1700000000,
            'owned_by' => 'google',
            'root' => Translator::resolveModel($cfg, $id),
        ]);
    }

    /**
     * Какой бэкенд обслуживает запрос: 'api' (ключи Gemini) или 'cli' (процесс agy).
     * Клиент может переключить его префиксом модели: "cli/<модель>" или "api/<модель>".
     */
    public static function backendFor(array $cfg, array &$req): string
    {
        $model = (string) ($req['model'] ?? '');
        foreach (['cli', 'agy', 'api', 'gemini'] as $prefix) {
            if (stripos($model, $prefix . '/') === 0) {
                $req['model'] = substr($model, strlen($prefix) + 1);
                return in_array($prefix, ['cli', 'agy'], true) ? 'cli' : 'api';
            }
        }
        return ($cfg['backend'] ?? 'api') === 'cli' ? 'cli' : 'api';
    }

    /**
     * Идентификатор беседы для CLI: OpenAI-протокол не хранит состояние,
     * а у agy диалог живёт на сервере, поэтому сессию нужно чем-то склеить.
     */
    public static function sessionId(array $cfg, array $req, string $keyLabel): string
    {
        $explicit = $_SERVER['HTTP_X_SESSION_ID'] ?? '';
        if ($explicit !== '') {
            return 'h-' . substr(preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) $explicit), 0, 64);
        }
        if (!empty($req['user']) && is_string($req['user'])) {
            return 'u-' . substr(hash('sha256', $keyLabel . '|' . $req['user']), 0, 24);
        }

        // Иначе привязываемся к началу диалога: одинаковое начало = та же сессия.
        $seed = '';
        foreach (($req['messages'] ?? []) as $msg) {
            $role = (string) ($msg['role'] ?? '');
            if ($role === 'system' || $role === 'user') {
                $content = $msg['content'] ?? '';
                $seed = $role . ':' . (is_string($content) ? $content : json_encode($content, JSON_UNESCAPED_UNICODE));
                if ($role === 'user') {
                    break;
                }
            }
        }
        return 'a-' . substr(hash('sha256', $keyLabel . '|' . $seed), 0, 24);
    }

    /**
     * Индекс последнего сообщения, на которое ещё нужен ответ.
     * Нужен, когда клиент прислал ту же историю второй раз: отправлять в CLI
     * нечего, и без этого ход уходит пустым, а в ответ приходит «чем помочь?».
     */
    private static function lastAskIndex(array $messages): int
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $role = (string) ($messages[$i]['role'] ?? '');
            if ($role === 'user' || $role === 'tool' || $role === 'function') {
                return $i;
            }
        }
        return 0;
    }

    /**
     * Готовит к отдаче файлы, которые модель сделала за этот ход.
     * @return array<array{name:string,url:string,image:bool}>
     */
    private static function publishFiles(array $paths, array $cfg): array
    {
        $out = [];
        foreach ($paths as $path) {
            $row = Files::publish((string) $path, $cfg);
            if ($row !== null) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Просил ли человек нарисовать картинку.
     *
     * Важно не спутать с разговором о картинке: «что на этом фото» — это
     * вопрос, а не заказ, и переспрашивать там нечего. Поэтому нужен глагол
     * создания, а если к сообщению приложен снимок — тем более молчим.
     */
    private static function wantsPicture(array $messages): bool
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ((string) ($messages[$i]['role'] ?? '') !== 'user') {
                continue;
            }
            $content = $messages[$i]['content'] ?? '';
            if (is_array($content)) {
                $text = '';
                foreach ($content as $part) {
                    $type = (string) ($part['type'] ?? '');
                    if ($type === 'image_url' || $type === 'input_image' || $type === 'image') {
                        return false;   // спрашивают про присланный снимок
                    }
                    if ($type === 'text') {
                        $text .= ' ' . (string) ($part['text'] ?? '');
                    }
                }
            } else {
                $text = (string) $content;
            }

            // «нарисуй» и «изобрази» говорят сами за себя; остальные глаголы
            // считаются только рядом со словом про картинку.
            // Просьба нарисовать — это пара строк. Длинное сообщение — это текст,
            // с которым надо что-то сделать, и слова в нём ничего не заказывают:
            // промт /human, например, сам полон «сделай» и «картина».
            if (mb_strlen($text) > 600) {
                return false;
            }
            // Слова ищем с начала слова: иначе «арт» находился в «старте»,
            // а «фото» — в «телефоточке».
            if (preg_match('/(?<!\p{L})(нарису|нарисова|отрису|отрисова|изобраз)/iu', $text)) {
                return true;
            }
            $verb = preg_match('/(?<!\p{L})(созда|сдела|сгенерир|построй|постро|начерти|начерта|сваргань|склепай)/iu', $text);
            $noun = preg_match('/(?<!\p{L})(картинк|картин[уы]|изображени|фотк|фото|рисун|схем|график|логотип|иллюстрац|обо[ий](?!\p{L})|арт(?!\p{L}))/iu', $text);
            return (bool) ($verb && $noun);
        }
        return false;
    }

    /**
     * Просили картинку, а файла нет.
     *
     * Модель иногда пишет «вот фотография леса», ничего при этом не создав.
     * Отдавать такой ответ нельзя: человек видит обещание и пустоту. Просим
     * сделать по-настоящему — один раз, в той же беседе.
     *
     * @return array<array{name:string,url:string,image:bool}>
     */
    private static function askAgain(AgyClient $agy, string $sessionId, ?string $cliModel, int $sentCount, array $cfg): array
    {
        $prompt = 'Файла с изображением в текущем каталоге нет — значит, ты его не создал. '
            . 'Создай изображение прямо сейчас своими средствами и сохрани файлом в текущий каталог. '
            . 'В ответ напиши одну короткую строку без извинений.';
        try {
            $again = $agy->ask($sessionId, $prompt, $cliModel, $sentCount);
        } catch (Throwable $e) {
            Logger::line('warn', 'retry for picture failed', ['error' => $e->getMessage()]);
            return [];
        }
        return self::publishFiles($again['files'] ?? [], $cfg);
    }

    /** POST /v1/chat/completions */
    public static function chat(array $cfg, array $req, string $keyLabel = 'anonymous'): void
    {
        if (self::backendFor($cfg, $req) === 'cli') {
            self::chatViaCli($cfg, $req, $keyLabel);
            return;
        }

        $model = Translator::resolveModel($cfg, $req['model'] ?? null);
        $shownModel = (string) ($req['model'] ?? $model);
        $body = Translator::chatToGemini($req, $cfg);

        // Image-модели должны явно попросить картинку на выходе.
        if (str_contains($model, '-image')) {
            $body['generationConfig']['responseModalities'] = ['TEXT', 'IMAGE'];
        }

        Logger::body('chat.request', $body);
        $client = new Gemini($cfg);

        if (!empty($req['stream'])) {
            self::chatStream($client, $body, $model, $shownModel, !empty($req['stream_options']['include_usage']));
            return;
        }

        $resp = $client->call('models/' . rawurlencode($model) . ':generateContent', $body);
        Http::json(Translator::geminiToChat($resp, $shownModel, self::id('chatcmpl'), time()));
    }

    private static function chatStream(Gemini $client, array $body, string $model, string $shownModel, bool $includeUsage): void
    {
        // SSE-заголовки отдаём только когда пошли реальные данные:
        // если Gemini ответит ошибкой, клиент получит нормальный JSON со статусом.
        $opened = false;
        $open = static function () use (&$opened): void {
            if (!$opened) {
                Http::sseStart();
                $opened = true;
            }
        };

        $id = self::id('chatcmpl');
        $created = time();
        $first = true;
        $toolIndex = 0;
        $finish = 'stop';
        $usage = null;

        // $delta — массив либо пустой stdClass (в последнем кадре нужен именно "delta":{}).
        $frame = static function ($delta, ?string $finishReason) use ($id, $created, $shownModel): array {
            return [
                'id' => $id,
                'object' => 'chat.completion.chunk',
                'created' => $created,
                'model' => $shownModel,
                'choices' => [[
                    'index' => 0,
                    'delta' => $delta,
                    'logprobs' => null,
                    'finish_reason' => $finishReason,
                ]],
            ];
        };

        $client->stream(
            'models/' . rawurlencode($model) . ':streamGenerateContent',
            $body,
            function (array $chunk) use (&$first, &$toolIndex, &$finish, &$usage, $frame, $open) {
                if (isset($chunk['usageMetadata'])) {
                    $usage = Translator::usage($chunk);
                }

                $cand = $chunk['candidates'][0] ?? null;
                if ($cand === null) {
                    return;
                }

                $open();

                if ($first) {
                    Http::sseSend($frame(['role' => 'assistant', 'content' => ''], null));
                    $first = false;
                }

                foreach (($cand['content']['parts'] ?? []) as $part) {
                    if (isset($part['text']) && empty($part['thought'])) {
                        if ((string) $part['text'] !== '') {
                            Http::sseSend($frame(['content' => (string) $part['text']], null));
                        }
                    } elseif (isset($part['functionCall'])) {
                        $finish = 'tool_calls';
                        Http::sseSend($frame([
                            'tool_calls' => [[
                                'index' => $toolIndex,
                                'id' => 'call_' . substr(md5(json_encode($part['functionCall']) . $toolIndex), 0, 22),
                                'type' => 'function',
                                'function' => [
                                    'name' => (string) ($part['functionCall']['name'] ?? ''),
                                    'arguments' => json_encode($part['functionCall']['args'] ?? new stdClass(), JSON_UNESCAPED_UNICODE),
                                ],
                            ]],
                        ], null));
                        $toolIndex++;
                    } elseif (isset($part['inlineData']) || isset($part['inline_data'])) {
                        $inline = $part['inlineData'] ?? $part['inline_data'];
                        $md = '![image](data:' . ($inline['mimeType'] ?? $inline['mime_type'] ?? 'image/png')
                            . ';base64,' . ($inline['data'] ?? '') . ')';
                        Http::sseSend($frame(['content' => $md], null));
                    }
                }

                if (!empty($cand['finishReason'])) {
                    $finish = Translator::finishReason((string) $cand['finishReason'], $finish === 'tool_calls');
                }
            }
        );

        if ($first) { // поток не дал ни одного кандидата
            $open();
            Http::sseSend($frame(['role' => 'assistant', 'content' => ''], null));
        }

        $last = $frame(new stdClass(), $finish);
        if ($includeUsage && $usage !== null) {
            $last['usage'] = $usage;
        }
        Http::sseSend($last);

        if ($includeUsage && $usage !== null) {
            Http::sseSend([
                'id' => $id,
                'object' => 'chat.completion.chunk',
                'created' => $created,
                'model' => $shownModel,
                'choices' => [],
                'usage' => $usage,
            ]);
        }

        Http::sseDone();
    }

    /** Чат через локальный CLI (agy) вместо HTTP-вызова Gemini. */
    private static function chatViaCli(array $cfg, array $req, string $keyLabel): void
    {
        $messages = $req['messages'] ?? null;
        if (!is_array($messages) || $messages === []) {
            Http::error(400, "'messages' is required and must be a non-empty array", 'invalid_request_error', null, 'messages');
        }

        $shownModel = (string) ($req['model'] ?? ($cfg['cli']['default_model'] ?? 'agy'));
        $cliModel = self::cliModel($cfg, $req['model'] ?? null);

        $agy = new AgyClient($cfg);
        $agy->stripPrompt = AgyPrompt::stripFor($cfg, $keyLabel);
        $sessionId = self::sessionId($cfg, $req, $keyLabel);
        $info = $agy->session($sessionId);

        // Клиент прислал историю короче отправленной — это новый диалог под тем же ключом.
        if ($info['uuid'] !== null && count($messages) < $info['sent']) {
            $agy->forget($sessionId);
            $info = ['uuid' => null, 'sent' => 0];
        }

        // История та же, что и в прошлый раз: нового сказать нечего. Это повтор
        // вопроса (нажали «ещё раз» или просто отправили то же самое). Переспрашиваем
        // последним сообщением, иначе CLI получит ход без вопроса.
        if ($info['uuid'] !== null && count($messages) <= $info['sent']) {
            $info['sent'] = self::lastAskIndex($messages);
        }

        $fresh = $info['uuid'] === null;
        $prompt = $agy->buildPrompt($messages, $info['sent'], $fresh, $req['tools'] ?? []);
        $sentCount = count($messages);

        Logger::request('cli.chat', [
            'key' => $keyLabel,
            'session' => $sessionId,
            'uuid' => $info['uuid'],
            'fresh' => $fresh,
            'new_messages' => $sentCount - $info['sent'],
            'model' => $cliModel,
        ]);
        Logger::body('cli.prompt', $prompt);

        $id = self::id('chatcmpl');
        $created = time();
        $promptTokens = AgyClient::estimateTokens($prompt);

        if (empty($req['stream'])) {
            $result = $agy->ask($sessionId, $prompt, $cliModel, $sentCount);
            $parsed = AgyClient::extractToolCalls($result['text']);

            // Файлы, сделанные за этот ход. Если модель просит инструмент, ответа
            // как такового ещё нет — тогда ничего не дописываем.
            if ($parsed['tool_calls'] === []) {
                $made = self::publishFiles($result['files'] ?? [], $cfg);
                if ($made === [] && self::wantsPicture($messages)) {
                    $made = self::askAgain($agy, $sessionId, $cliModel, $sentCount, $cfg);
                }
                $parsed['text'] .= Files::block($made);
            }

            // Подсказка для отладки: какая сессия и какая беседа CLI обслужили запрос.
            header('X-Session-Id: ' . $sessionId);
            header('X-Conversation-Id: ' . $result['uuid']);

            $message = ['role' => 'assistant', 'content' => $parsed['text'] === '' ? null : $parsed['text']];
            if (($result['thinking'] ?? '') !== '') {
                $message['reasoning_content'] = $result['thinking'];
            }
            if ($parsed['tool_calls'] !== []) {
                $message['tool_calls'] = $parsed['tool_calls'];
            }

            $usage = $result['usage'] ?: self::cliUsage($promptTokens, $result['text']);
            Usage::record($keyLabel, (string) $cliModel, $usage);

            Http::json([
                'id' => $id,
                'object' => 'chat.completion',
                'created' => $created,
                'model' => $shownModel,
                'system_fingerprint' => 'agy-cli',
                'choices' => [[
                    'index' => 0,
                    'message' => $message,
                    'logprobs' => null,
                    'finish_reason' => $parsed['tool_calls'] !== [] ? 'tool_calls' : 'stop',
                ]],
                'usage' => $usage,
            ]);
            return;
        }

        // --- стриминг ---
        $opened = false;
        $frame = static function ($delta, ?string $finishReason) use ($id, $created, $shownModel): array {
            return [
                'id' => $id,
                'object' => 'chat.completion.chunk',
                'created' => $created,
                'model' => $shownModel,
                'choices' => [[
                    'index' => 0,
                    'delta' => $delta,
                    'logprobs' => null,
                    'finish_reason' => $finishReason,
                ]],
            ];
        };

        $buffered = '';   // копим, пока не поймём, что это не JSON с tool_calls
        $decided = false; // true — точно обычный текст, можно стримить
        $sawTools = false;
        $streamed = '';   // всё, что реально ушло клиенту — для сверки с финалом

        // Агент бывает занят минутами: думает, читает, рисует. Всё это время
        // клиент не получал ни байта — ни заголовков, ни кадров, и отличить
        // работу от зависания не мог. Раз в 15 секунд тишины открываем поток,
        // если ещё не открыт, и шлём комментарий SSE: клиенты его пропускают.
        $agy->onIdle = static function () use (&$opened, $frame): void {
            if (!$opened) {
                Http::sseStart();
                $opened = true;
                Http::sseSend($frame(['role' => 'assistant', 'content' => ''], null));
            }
            Http::sseComment();
        };

        $result = $agy->ask($sessionId, $prompt, $cliModel, $sentCount, function (string $delta) use (&$opened, &$buffered, &$decided, &$streamed, $frame, $req) {
            $buffered .= $delta;

            if (!$decided) {
                $head = ltrim($buffered);
                if ($head === '') {
                    return;
                }
                // Ответ, начинающийся с '{' или ```json, может оказаться вызовом инструмента —
                // такой текст стримить нельзя, ждём его целиком.
                $looksJson = $head[0] === '{' || str_starts_with($head, '```');
                if ($looksJson && !empty($req['tools'])) {
                    return;
                }
                $decided = true;
            }

            if (!$opened) {
                Http::sseStart();
                $opened = true;
                Http::sseSend($frame(['role' => 'assistant', 'content' => ''], null));
            }
            Http::sseSend($frame(['content' => $delta], null));
            $streamed .= $delta;
            $buffered = '';
        }, function (string $line) use (&$opened, $frame) {
            // Ход работы отдаём отдельным полем: обычные клиенты его игнорируют,
            // а наш показывает в свёрнутом блоке «Размышления».
            if (!$opened) {
                Http::sseStart();
                $opened = true;
                Http::sseSend($frame(['role' => 'assistant', 'content' => ''], null));
            }
            Http::sseSend($frame(['reasoning_content' => $line], null));
        });

        $parsed = AgyClient::extractToolCalls($result['text']);
        $sawTools = $parsed['tool_calls'] !== [];

        if (!$opened) {
            Http::sseStart();
            $opened = true;
            Http::sseSend($frame(['role' => 'assistant', 'content' => ''], null));
        }

        if ($sawTools) {
            if ($parsed['text'] !== '') {
                Http::sseSend($frame(['content' => $parsed['text']], null));
                $streamed .= $parsed['text'];
            }
            foreach ($parsed['tool_calls'] as $i => $call) {
                Http::sseSend($frame(['tool_calls' => [[
                    'index' => $i,
                    'id' => $call['id'],
                    'type' => 'function',
                    'function' => $call['function'],
                ]]], null));
            }
        } elseif ($buffered !== '') {
            Http::sseSend($frame(['content' => $buffered], null)); // остаток, который придержали
            $streamed .= $buffered;
        }

        // Файлы, сделанные за этот ход, дописываем в хвост ответа отдельной дельтой.
        $made = $sawTools ? [] : self::publishFiles($result['files'] ?? [], $cfg);
        if ($made === [] && !$sawTools && self::wantsPicture($messages)) {
            Http::sseSend($frame(['reasoning_content' => 'Картинки в ответе нет — прошу создать её на самом деле'], null));
            $made = self::askAgain($agy, $sessionId, $cliModel, $sentCount, $cfg);
        }
        $tail = Files::block($made);
        if ($tail !== '') {
            Http::sseSend($frame(['content' => $tail], null));
            $streamed .= $tail;
            $result['text'] .= $tail;
        }

        $usage = $result['usage'] ?: self::cliUsage($promptTokens, $result['text']);
        Usage::record($keyLabel, (string) $cliModel, $usage);
        $last = $frame(new stdClass(), $sawTools ? 'tool_calls' : 'stop');
        if (!empty($req['stream_options']['include_usage'])) {
            $last['usage'] = $usage;
        }
        // CLI режет многобайтовый символ между дельтами и подставляет U+FFFD,
        // поэтому в потоке попадаются битые буквы. Полный ответ (поле response
        // от CLI) чистый — отдаём его отдельным полем, чтобы клиент заменил
        // накопленное. Чужие клиенты незнакомое поле просто игнорируют.
        if (!$sawTools && trim($streamed) !== $result['text']) {
            $last['agy_full_text'] = $result['text'];
        }
        Http::sseSend($last);
        Http::sseDone();
    }

    /** Имя модели для флага --model у agy. */
    private static function cliModel(array $cfg, ?string $requested): ?string
    {
        $map = $cfg['cli']['model_map'] ?? [];
        $name = trim((string) $requested);

        if ($name !== '' && isset($map[$name])) {
            return $map[$name] !== '' ? (string) $map[$name] : null;
        }
        // Имя реальной модели CLI из каталога (bin/update_models.php) — отдаём как есть.
        $known = $name !== '' ? ModelCatalog::matchCli($name) : null;
        if ($known !== null) {
            return $known;
        }
        // Незнакомые имена (gpt-4o и подобные) не передаём в CLI — он их не поймёт.
        if ($name !== '' && !empty($cfg['cli']['pass_unknown_model'])) {
            return $name;
        }
        $default = (string) ($cfg['cli']['default_model'] ?? '');
        return $default !== '' ? $default : null;
    }

    /** Запасной расчёт, если CLI не сообщил расход токенов. */
    /** GET /v1/usage — сколько израсходовано этим ключом. */
    public static function usage(array $cfg, string $keyLabel): void
    {
        Http::json(Usage::summary($cfg, $keyLabel));
    }

    private static function cliUsage(int $promptTokens, string $answer): array
    {
        $completion = AgyClient::estimateTokens($answer);
        return [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completion,
            'total_tokens' => $promptTokens + $completion,
            'completion_tokens_details' => ['reasoning_tokens' => 0],
            'estimated' => true, // CLI не сообщает реальный расход токенов
        ];
    }

    public static function hasApiKeys(array $cfg): bool
    {
        foreach (($cfg['gemini_keys'] ?? []) as $k) {
            if (is_string($k) && trim($k) !== '' && !str_contains($k, 'ВАШ_КЛЮЧ')) {
                return true;
            }
        }
        return false;
    }

    /** Эндпоинты, которые умеет только HTTP-бэкенд. */
    private static function requireApiBackend(array $cfg, string $what): void
    {
        if (!self::hasApiKeys($cfg)) {
            Http::error(501, $what . ' is not available in CLI mode: the agy CLI has no such capability. Add a Gemini API key to config.php to enable it.', 'invalid_request_error', 'unsupported_in_cli_mode');
        }
    }

    /** POST /v1/completions — старый текстовый формат, транслируем в chat. */
    public static function completions(array $cfg, array $req, string $keyLabel = 'anonymous'): void
    {
        $prompt = $req['prompt'] ?? '';
        if (is_array($prompt)) {
            $prompt = implode("\n", array_map('strval', $prompt));
        }
        $chatReq = $req;
        $chatReq['messages'] = [['role' => 'user', 'content' => (string) $prompt]];
        unset($chatReq['prompt']);

        if (self::backendFor($cfg, $chatReq) === 'cli') {
            $agy = new AgyClient($cfg);
            $agy->stripPrompt = AgyPrompt::stripFor($cfg, $keyLabel);
            $sessionId = self::sessionId($cfg, $chatReq, $keyLabel);
            $info = $agy->session($sessionId);
            if ($info['uuid'] !== null && count($chatReq['messages']) <= $info['sent']) {
                $info['sent'] = self::lastAskIndex($chatReq['messages']);
            }
            $text = $agy->buildPrompt($chatReq['messages'], $info['sent'], $info['uuid'] === null, []);
            $result = $agy->ask($sessionId, $text, self::cliModel($cfg, $chatReq['model'] ?? null), count($chatReq['messages']));

            Http::json([
                'id' => self::id('cmpl'),
                'object' => 'text_completion',
                'created' => time(),
                'model' => (string) ($req['model'] ?? ($cfg['cli']['default_model'] ?? 'agy')),
                'choices' => [[
                    'index' => 0,
                    'text' => $result['text'],
                    'logprobs' => null,
                    'finish_reason' => 'stop',
                ]],
                'usage' => $result['usage'] ?: self::cliUsage(AgyClient::estimateTokens($text), $result['text']),
            ]);
            return;
        }

        if (!empty($req['stream'])) {
            // Стримить legacy-формат почти никому не нужно — отдаём цельный ответ.
            $chatReq['stream'] = false;
        }

        $model = Translator::resolveModel($cfg, $req['model'] ?? null);
        $body = Translator::chatToGemini($chatReq, $cfg);
        $resp = (new Gemini($cfg))->call('models/' . rawurlencode($model) . ':generateContent', $body);
        $chat = Translator::geminiToChat($resp, (string) ($req['model'] ?? $model), self::id('cmpl'), time());

        Http::json([
            'id' => $chat['id'],
            'object' => 'text_completion',
            'created' => $chat['created'],
            'model' => $chat['model'],
            'choices' => array_map(static fn(array $c) => [
                'index' => $c['index'],
                'text' => (string) ($c['message']['content'] ?? ''),
                'logprobs' => null,
                'finish_reason' => $c['finish_reason'],
            ], $chat['choices']),
            'usage' => $chat['usage'],
        ]);
    }

    /** POST /v1/embeddings */
    public static function embeddings(array $cfg, array $req): void
    {
        self::requireApiBackend($cfg, 'Embeddings');

        $input = $req['input'] ?? null;
        if ($input === null || $input === '') {
            Http::error(400, "'input' is required", 'invalid_request_error', null, 'input');
        }
        $items = is_array($input) ? $input : [$input];
        $model = Translator::resolveModel($cfg, $req['model'] ?? null, $cfg['embedding_model']);

        $requests = [];
        foreach ($items as $item) {
            $requests[] = [
                'model' => 'models/' . $model,
                'content' => ['parts' => [['text' => is_string($item) ? $item : json_encode($item, JSON_UNESCAPED_UNICODE)]]],
            ];
        }

        $client = new Gemini($cfg);
        $resp = $client->call('models/' . rawurlencode($model) . ':batchEmbedContents', ['requests' => $requests]);

        $data = [];
        foreach (($resp['embeddings'] ?? []) as $i => $emb) {
            $data[] = [
                'object' => 'embedding',
                'index' => (int) $i,
                'embedding' => $emb['values'] ?? [],
            ];
        }

        $tokens = 0;
        foreach ($items as $item) {
            $tokens += (int) ceil(mb_strlen(is_string($item) ? $item : json_encode($item)) / 4);
        }

        Http::json([
            'object' => 'list',
            'data' => $data,
            'model' => (string) ($req['model'] ?? $model),
            'usage' => ['prompt_tokens' => $tokens, 'total_tokens' => $tokens],
        ]);
    }

    /**
     * POST /v1/images/generations — нарисовать картинку.
     *
     * Работает в обоих режимах. По ключам это модель Gemini Image, через CLI —
     * его собственный инструмент рисования. Бэкенд выбирается как везде
     * (backendFor), поэтому префикс "api/" или "cli/" в модели работает и тут.
     */
    public static function images(array $cfg, array $req, string $keyLabel = 'anonymous'): void
    {
        $backend = self::backendFor($cfg, $req);

        $prompt = trim((string) ($req['prompt'] ?? ''));
        if ($prompt === '') {
            Http::error(400, "'prompt' is required", 'invalid_request_error', null, 'prompt');
        }

        if ($backend === 'cli') {
            self::imagesViaCli($cfg, $req, $prompt, $keyLabel);
            return;
        }
        // Сюда попадают, только если явно выбран бэкенд по ключам. Общее
        // «CLI такого не умеет» тут уже неправда — умеет, просто попросили не его.
        if (!self::hasApiKeys($cfg)) {
            Http::error(501, 'Image generation via the API backend needs a Gemini API key in config.php. Remove the "api/" model prefix to draw with the CLI instead.', 'invalid_request_error', 'no_api_key');
        }

        $model = Translator::resolveModel($cfg, $req['model'] ?? null, $cfg['image_model']);
        $n = max(1, min(4, (int) ($req['n'] ?? 1)));

        $body = [
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => ['responseModalities' => ['TEXT', 'IMAGE']],
        ];

        $client = new Gemini($cfg);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $resp = $client->call('models/' . rawurlencode($model) . ':generateContent', $body);
            foreach (($resp['candidates'][0]['content']['parts'] ?? []) as $part) {
                $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
                if ($inline === null) {
                    continue;
                }
                $out[] = ($req['response_format'] ?? 'b64_json') === 'url'
                    ? ['url' => 'data:' . ($inline['mimeType'] ?? 'image/png') . ';base64,' . ($inline['data'] ?? '')]
                    : ['b64_json' => (string) ($inline['data'] ?? '')];
            }
        }

        if ($out === []) {
            Http::error(502, 'Model returned no image. Try a different prompt or an image-capable model.', 'api_error');
        }

        Http::json(['created' => time(), 'data' => $out]);
    }

    /**
     * Рисование через CLI: просим agy нарисовать и забираем сделанные файлы.
     *
     * Беседа здесь одноразовая. Протокол OpenAI для картинок не хранит
     * состояния, и тянуть предыдущий разговор в заказ на картинку значило бы
     * подмешивать в него чужой контекст.
     */
    private static function imagesViaCli(array $cfg, array $req, string $prompt, string $keyLabel = 'anonymous'): void
    {
        $n = max(1, min(4, (int) ($req['n'] ?? 1)));
        $format = (string) ($req['response_format'] ?? 'b64_json');
        $model = trim((string) ($cfg['cli']['default_model'] ?? '')) ?: null;

        $agy = new AgyClient($cfg);
        $agy->stripPrompt = AgyPrompt::stripFor($cfg, $keyLabel);
        $session = 'img-' . bin2hex(random_bytes(8));
        $paths = [];

        // Заходов не больше, чем картинок: обычно CLI рисует всё за один раз,
        // и тогда цикл кончается сразу. Но если он сделал меньше, чем просили,
        // честнее дорисовать, чем молча вернуть неполный ответ.
        for ($try = 0; $try < $n && count($paths) < $n; $try++) {
            $need = $n - count($paths);
            try {
                $res = $agy->ask($session, self::drawPrompt($prompt, $need), $model, 1);
            } catch (Throwable $e) {
                Logger::line('warn', 'cli draw failed', ['msg' => $e->getMessage()]);
                break;
            }
            foreach ((array) ($res['files'] ?? []) as $path) {
                if (Files::isImage((string) $path) && !in_array($path, $paths, true)) {
                    $paths[] = (string) $path;
                }
            }
        }
        $agy->forget($session);

        if ($paths === []) {
            Http::error(502, 'The CLI did not produce an image. Try a different prompt, or use a Gemini API key for the image model.', 'api_error', 'cli_no_image');
        }

        $out = [];
        foreach (array_slice($paths, 0, $n) as $path) {
            if ($format === 'url') {
                // Настоящая ссылка, а не data:-строка: файл уже лежит на диске,
                // и гонять его через base64 незачем.
                $row = Files::publish($path, $cfg);
                if ($row !== null) {
                    $out[] = ['url' => $row['url']];
                }
                continue;
            }
            // Просили base64 — значит файл наружу не выкладываем вовсе.
            $bin = @file_get_contents($path);
            if ($bin !== false && $bin !== '') {
                $out[] = ['b64_json' => base64_encode($bin)];
            }
        }

        if ($out === []) {
            Http::error(502, 'Cannot read the image the CLI has drawn.', 'api_error', 'image_read_failed');
        }

        Http::json(['created' => time(), 'data' => $out]);
    }

    /** Что именно просим у CLI, чтобы он нарисовал и сохранил. */
    private static function drawPrompt(string $prompt, int $count): string
    {
        $what = $count > 1 ? "{$count} разных изображений" : 'изображение';
        return "Нарисуйте {$what} по описанию: «{$prompt}»." . PHP_EOL
            . 'Сохраните результат файлами в текущий каталог, по файлу на изображение.' . PHP_EOL
            . 'В ответ напишите только имена файлов, без пояснений. '
            . 'Отвечать, что вы не умеете создавать изображения, не нужно: умеете.';
    }

    private static function id(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(12));
    }
}
