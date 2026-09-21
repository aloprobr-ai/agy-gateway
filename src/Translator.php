<?php
declare(strict_types=1);

/**
 * Перевод формата OpenAI <-> Gemini.
 */
final class Translator
{
    /** Имя модели из запроса -> реальная модель Gemini. */
    public static function resolveModel(array $cfg, ?string $requested, ?string $fallback = null): string
    {
        $name = trim((string) $requested);
        if ($name === '') {
            return $fallback ?? $cfg['default_model'];
        }
        // Клиенты часто шлют "models/gemini-2.5-flash"
        $name = preg_replace('#^models/#', '', $name);

        $map = $cfg['model_map'] ?? [];
        if (isset($map[$name])) {
            return $map[$name];
        }
        // Всё, что похоже на модель Gemini, пропускаем как есть.
        if (preg_match('#^(gemini|gemma|learnlm|text-embedding|embedding)#i', $name)) {
            return $name;
        }
        return $fallback ?? $cfg['default_model'];
    }

    /**
     * Тело /v1/chat/completions -> тело Gemini generateContent.
     * @return array<string,mixed>
     */
    public static function chatToGemini(array $req, array $cfg): array
    {
        $messages = $req['messages'] ?? null;
        if (!is_array($messages) || $messages === []) {
            Http::error(400, "'messages' is required and must be a non-empty array", 'invalid_request_error', null, 'messages');
        }

        $systemParts = [];
        $contents = [];
        $toolNames = [];   // tool_call_id -> имя функции

        foreach ($messages as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $role = (string) ($msg['role'] ?? 'user');

            if ($role === 'system' || $role === 'developer') {
                foreach (self::textOf($msg['content'] ?? '') as $t) {
                    $systemParts[] = ['text' => $t];
                }
                continue;
            }

            if ($role === 'tool' || $role === 'function') {
                $id = (string) ($msg['tool_call_id'] ?? '');
                $name = $msg['name'] ?? ($toolNames[$id] ?? 'tool');
                $raw = is_string($msg['content'] ?? null) ? $msg['content'] : json_encode($msg['content'] ?? null);
                $decoded = json_decode((string) $raw, true);
                self::push($contents, 'user', [[
                    'functionResponse' => [
                        'name' => (string) $name,
                        'response' => is_array($decoded) ? $decoded : ['result' => (string) $raw],
                    ],
                ]]);
                continue;
            }

            if ($role === 'assistant') {
                $parts = [];
                foreach (self::textOf($msg['content'] ?? '') as $t) {
                    if ($t !== '') {
                        $parts[] = ['text' => $t];
                    }
                }
                foreach (($msg['tool_calls'] ?? []) as $call) {
                    $fn = $call['function'] ?? [];
                    $args = json_decode((string) ($fn['arguments'] ?? '{}'), true);
                    $toolNames[(string) ($call['id'] ?? '')] = (string) ($fn['name'] ?? '');
                    $parts[] = ['functionCall' => [
                        'name' => (string) ($fn['name'] ?? ''),
                        'args' => is_array($args) ? $args : [],
                    ]];
                }
                if ($parts !== []) {
                    self::push($contents, 'model', $parts);
                }
                continue;
            }

            // user
            $parts = self::userParts($msg['content'] ?? '', $cfg);
            if ($parts !== []) {
                self::push($contents, 'user', $parts);
            }
        }

        if ($contents === []) {
            // Бывает, когда прислали только system — Gemini требует хотя бы один content.
            $contents[] = ['role' => 'user', 'parts' => [['text' => ' ']]];
        }

        $body = ['contents' => $contents];

        if ($systemParts !== []) {
            $body['systemInstruction'] = ['parts' => $systemParts];
        }

        $gen = [];
        if (isset($req['temperature']))       $gen['temperature'] = (float) $req['temperature'];
        if (isset($req['top_p']))             $gen['topP'] = (float) $req['top_p'];
        if (isset($req['top_k']))             $gen['topK'] = (int) $req['top_k'];
        if (isset($req['max_tokens']))        $gen['maxOutputTokens'] = (int) $req['max_tokens'];
        if (isset($req['max_completion_tokens'])) $gen['maxOutputTokens'] = (int) $req['max_completion_tokens'];
        if (isset($req['presence_penalty']))  $gen['presencePenalty'] = (float) $req['presence_penalty'];
        if (isset($req['frequency_penalty'])) $gen['frequencyPenalty'] = (float) $req['frequency_penalty'];
        if (isset($req['seed']))              $gen['seed'] = (int) $req['seed'];
        if (isset($req['n']) && (int) $req['n'] > 1) $gen['candidateCount'] = (int) $req['n'];

        if (isset($req['stop'])) {
            $stop = is_array($req['stop']) ? $req['stop'] : [$req['stop']];
            $gen['stopSequences'] = array_values(array_filter(array_map('strval', $stop), fn($s) => $s !== ''));
        }

        $format = $req['response_format'] ?? null;
        if (is_array($format)) {
            $ftype = (string) ($format['type'] ?? '');
            if ($ftype === 'json_object') {
                $gen['responseMimeType'] = 'application/json';
            } elseif ($ftype === 'json_schema') {
                $schema = $format['json_schema']['schema'] ?? null;
                $gen['responseMimeType'] = 'application/json';
                if (is_array($schema)) {
                    $gen['responseSchema'] = self::sanitizeSchema($schema);
                }
            }
        }

        // Управление "размышлениями" моделей 2.5
        if (isset($req['reasoning_effort'])) {
            $budget = match ((string) $req['reasoning_effort']) {
                'none', 'minimal' => 0,
                'low' => 1024,
                'medium' => 8192,
                'high' => 24576,
                default => null,
            };
            if ($budget !== null) {
                $gen['thinkingConfig'] = ['thinkingBudget' => $budget];
            }
        }

        if ($gen !== []) {
            $body['generationConfig'] = $gen;
        }

        // Инструменты
        $declarations = [];
        foreach (($req['tools'] ?? []) as $tool) {
            if (($tool['type'] ?? 'function') !== 'function') {
                continue;
            }
            $fn = $tool['function'] ?? [];
            if (empty($fn['name'])) {
                continue;
            }
            $declarations[] = self::declaration($fn);
        }
        foreach (($req['functions'] ?? []) as $fn) { // устаревший формат
            if (!empty($fn['name'])) {
                $declarations[] = self::declaration($fn);
            }
        }

        $choice = $req['tool_choice'] ?? $req['function_call'] ?? null;
        if ($declarations !== [] && $choice !== 'none') {
            $body['tools'] = [['functionDeclarations' => $declarations]];

            if ($choice === 'required' || $choice === 'any') {
                $body['toolConfig'] = ['functionCallingConfig' => ['mode' => 'ANY']];
            } elseif (is_array($choice)) {
                $name = $choice['function']['name'] ?? ($choice['name'] ?? null);
                if ($name) {
                    $body['toolConfig'] = ['functionCallingConfig' => [
                        'mode' => 'ANY',
                        'allowedFunctionNames' => [(string) $name],
                    ]];
                }
            }
        }

        $threshold = (string) ($cfg['safety_threshold'] ?? '');
        if ($threshold !== '') {
            $body['safetySettings'] = array_map(
                static fn(string $c) => ['category' => $c, 'threshold' => $threshold],
                [
                    'HARM_CATEGORY_HARASSMENT',
                    'HARM_CATEGORY_HATE_SPEECH',
                    'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                    'HARM_CATEGORY_DANGEROUS_CONTENT',
                ]
            );
        }

        return $body;
    }

    private static function declaration(array $fn): array
    {
        $decl = ['name' => (string) $fn['name']];
        if (!empty($fn['description'])) {
            $decl['description'] = (string) $fn['description'];
        }
        $params = $fn['parameters'] ?? null;
        if (is_array($params) && !empty($params['properties'])) {
            $decl['parameters'] = self::sanitizeSchema($params);
        }
        return $decl;
    }

    /** Склеиваем подряд идущие блоки одной роли — Gemini этого требует. */
    private static function push(array &$contents, string $role, array $parts): void
    {
        $last = $contents[count($contents) - 1] ?? null;
        if ($last !== null && $last['role'] === $role) {
            $contents[count($contents) - 1]['parts'] = array_merge($last['parts'], $parts);
            return;
        }
        $contents[] = ['role' => $role, 'parts' => $parts];
    }

    /** @return string[] */
    private static function textOf($content): array
    {
        if (is_string($content)) {
            return [$content];
        }
        $out = [];
        if (is_array($content)) {
            foreach ($content as $part) {
                if (is_string($part)) {
                    $out[] = $part;
                } elseif (is_array($part) && isset($part['text'])) {
                    $out[] = (string) $part['text'];
                }
            }
        }
        return $out;
    }

    /** Части user-сообщения: текст + картинки + аудио/файлы в base64. */
    private static function userParts($content, array $cfg): array
    {
        if (is_string($content)) {
            return $content === '' ? [] : [['text' => $content]];
        }
        if (!is_array($content)) {
            return [];
        }

        $parts = [];
        foreach ($content as $part) {
            if (is_string($part)) {
                if ($part !== '') {
                    $parts[] = ['text' => $part];
                }
                continue;
            }
            if (!is_array($part)) {
                continue;
            }
            $type = (string) ($part['type'] ?? '');

            if ($type === 'text' || $type === 'input_text' || isset($part['text'])) {
                $text = (string) ($part['text'] ?? '');
                if ($text !== '') {
                    $parts[] = ['text' => $text];
                }
                continue;
            }

            if ($type === 'image_url' || $type === 'input_image') {
                $url = $part['image_url']['url'] ?? ($part['image_url'] ?? ($part['url'] ?? null));
                if (is_string($url) && $url !== '') {
                    $parts[] = self::imagePart($url, $cfg);
                }
                continue;
            }

            // Совместимость с Anthropic-стилем: {type:image, source:{type:base64,...}}
            if ($type === 'image' && isset($part['source']['data'])) {
                $parts[] = ['inline_data' => [
                    'mime_type' => (string) ($part['source']['media_type'] ?? 'image/png'),
                    'data' => (string) $part['source']['data'],
                ]];
                continue;
            }

            // Аудио / произвольные файлы в base64 (OpenAI input_audio, file)
            if ($type === 'input_audio' && isset($part['input_audio']['data'])) {
                $fmt = (string) ($part['input_audio']['format'] ?? 'wav');
                $parts[] = ['inline_data' => [
                    'mime_type' => 'audio/' . $fmt,
                    'data' => (string) $part['input_audio']['data'],
                ]];
            }
        }
        return $parts;
    }

    /** data:URI или http(s) ссылка -> inline_data для Gemini. */
    private static function imagePart(string $url, array $cfg): array
    {
        if (preg_match('#^data:([^;,]+);base64,(.*)$#is', $url, $m)) {
            $mime = strtolower(trim($m[1]));
            $data = preg_replace('/\s+/', '', $m[2]);
            self::assertImageSize(strlen((string) $data) * 3 / 4, $cfg);
            return ['inline_data' => ['mime_type' => $mime, 'data' => $data]];
        }

        if (!preg_match('#^https?://#i', $url)) {
            Http::error(400, 'Unsupported image_url: expected a data: URI or an http(s) URL', 'invalid_request_error', null, 'image_url');
        }
        if (empty($cfg['allow_remote_images'])) {
            Http::error(400, 'Remote image URLs are disabled on this server; send the image as a base64 data: URI', 'invalid_request_error', null, 'image_url');
        }

        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $ip = gethostbyname($host);
        if (filter_var($ip, FILTER_VALIDATE_IP) && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            Http::error(400, 'Refusing to fetch image from a private network address', 'invalid_request_error', null, 'image_url');
        }

        $max = (int) ($cfg['max_image_bytes'] ?? 12 * 1024 * 1024);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT      => 'gemini-openai-gateway/1.0',
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => static function ($ch, $dlTotal, $dlNow) use ($max) {
                return ($dlTotal > $max || $dlNow > $max) ? 1 : 0;
            },
        ]);
        $data = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mime = (string) (curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '');
        curl_close($ch);

        if ($data === false || $status < 200 || $status >= 300) {
            Http::error(400, 'Failed to download image from ' . $url . ' (HTTP ' . $status . ')', 'invalid_request_error', null, 'image_url');
        }
        self::assertImageSize(strlen((string) $data), $cfg);

        $mime = trim(explode(';', $mime)[0]);
        if ($mime === '' || !str_contains($mime, '/')) {
            $info = @getimagesizefromstring((string) $data);
            $mime = $info['mime'] ?? 'image/jpeg';
        }

        return ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode((string) $data)]];
    }

    private static function assertImageSize(float $bytes, array $cfg): void
    {
        $max = (int) ($cfg['max_image_bytes'] ?? 12 * 1024 * 1024);
        if ($bytes > $max) {
            Http::error(413, 'Image is too large: limit is ' . round($max / 1048576, 1) . ' MB', 'invalid_request_error', null, 'image_url');
        }
    }

    /**
     * Gemini принимает лишь подмножество JSON Schema — выкидываем всё лишнее,
     * иначе прилетает 400 INVALID_ARGUMENT.
     */
    public static function sanitizeSchema(array $schema): array
    {
        static $allowed = [
            'type', 'format', 'description', 'nullable', 'enum', 'items',
            'properties', 'required', 'minItems', 'maxItems', 'anyOf',
            'propertyOrdering', 'minimum', 'maximum',
        ];

        $out = [];
        foreach ($schema as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            if ($key === 'type') {
                // JSON Schema допускает ["string","null"] — Gemini нет.
                if (is_array($value)) {
                    $types = array_values(array_filter($value, fn($t) => $t !== 'null'));
                    $out['type'] = strtoupper((string) ($types[0] ?? 'string'));
                    if (count($value) !== count($types)) {
                        $out['nullable'] = true;
                    }
                } else {
                    $out['type'] = strtoupper((string) $value);
                }
                continue;
            }
            if ($key === 'properties' && is_array($value)) {
                $props = [];
                foreach ($value as $name => $sub) {
                    $props[$name] = is_array($sub) ? self::sanitizeSchema($sub) : [];
                }
                $out['properties'] = $props ?: new stdClass();
                continue;
            }
            if ($key === 'items' && is_array($value)) {
                $out['items'] = self::sanitizeSchema($value);
                continue;
            }
            if ($key === 'anyOf' && is_array($value)) {
                $out['anyOf'] = array_map(fn($s) => is_array($s) ? self::sanitizeSchema($s) : [], $value);
                continue;
            }
            $out[$key] = $value;
        }

        if (!isset($out['type']) && isset($out['properties'])) {
            $out['type'] = 'OBJECT';
        }
        return $out;
    }

    /** Ответ Gemini -> объект chat.completion. */
    public static function geminiToChat(array $resp, string $model, string $id, int $created): array
    {
        $choices = [];
        foreach (($resp['candidates'] ?? []) as $i => $cand) {
            $text = '';
            $toolCalls = [];
            $images = [];

            foreach (($cand['content']['parts'] ?? []) as $part) {
                if (isset($part['text']) && empty($part['thought'])) {
                    $text .= (string) $part['text'];
                } elseif (isset($part['functionCall'])) {
                    $toolCalls[] = [
                        'id' => 'call_' . substr(md5(json_encode($part['functionCall']) . count($toolCalls)), 0, 22),
                        'type' => 'function',
                        'function' => [
                            'name' => (string) ($part['functionCall']['name'] ?? ''),
                            'arguments' => json_encode($part['functionCall']['args'] ?? new stdClass(), JSON_UNESCAPED_UNICODE),
                        ],
                    ];
                } elseif (isset($part['inlineData']) || isset($part['inline_data'])) {
                    $inline = $part['inlineData'] ?? $part['inline_data'];
                    $images[] = 'data:' . ($inline['mimeType'] ?? $inline['mime_type'] ?? 'image/png')
                        . ';base64,' . ($inline['data'] ?? '');
                }
            }

            // Картинки от image-моделей отдаём markdown-ссылкой — так их видит любой OpenAI-клиент.
            foreach ($images as $img) {
                $text .= ($text === '' ? '' : "\n\n") . '![image](' . $img . ')';
            }

            $message = ['role' => 'assistant', 'content' => $text === '' ? null : $text];
            if ($toolCalls !== []) {
                $message['tool_calls'] = $toolCalls;
            }

            $choices[] = [
                'index' => (int) $i,
                'message' => $message,
                'logprobs' => null,
                'finish_reason' => self::finishReason((string) ($cand['finishReason'] ?? 'STOP'), $toolCalls !== []),
            ];
        }

        if ($choices === []) {
            $blocked = $resp['promptFeedback']['blockReason'] ?? null;
            $choices[] = [
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => ''],
                'logprobs' => null,
                'finish_reason' => $blocked ? 'content_filter' : 'stop',
            ];
        }

        return [
            'id' => $id,
            'object' => 'chat.completion',
            'created' => $created,
            'model' => $model,
            'choices' => $choices,
            'usage' => self::usage($resp),
        ];
    }

    public static function usage(array $resp): array
    {
        $u = $resp['usageMetadata'] ?? [];
        $prompt = (int) ($u['promptTokenCount'] ?? 0);
        $completion = (int) ($u['candidatesTokenCount'] ?? 0);
        $thoughts = (int) ($u['thoughtsTokenCount'] ?? 0);

        return [
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion + $thoughts,
            'total_tokens' => (int) ($u['totalTokenCount'] ?? ($prompt + $completion + $thoughts)),
            'completion_tokens_details' => ['reasoning_tokens' => $thoughts],
        ];
    }

    public static function finishReason(string $reason, bool $hasToolCalls): string
    {
        if ($hasToolCalls) {
            return 'tool_calls';
        }
        return match (strtoupper($reason)) {
            'MAX_TOKENS' => 'length',
            'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII' => 'content_filter',
            default => 'stop',
        };
    }
}
