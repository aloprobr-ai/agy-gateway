<?php
declare(strict_types=1);

/**
 * Тонкий HTTP-клиент к Google Generative Language API
 * с ротацией ключей и ретраями на 429/5xx.
 */
final class Gemini
{
    private array $cfg;
    /** @var string[] */
    private array $keys;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
        $keys = array_values(array_filter(
            $cfg['gemini_keys'] ?? [],
            static fn($k) => is_string($k) && trim($k) !== '' && !str_contains($k, 'ВАШ_КЛЮЧ')
        ));
        if ($keys === []) {
            Http::error(500, 'Server misconfigured: no Gemini API keys in config.php', 'api_error', 'no_upstream_key');
        }
        // Стартуем с произвольного ключа, чтобы размазать нагрузку между процессами.
        $start = random_int(0, count($keys) - 1);
        $this->keys = array_merge(array_slice($keys, $start), array_slice($keys, 0, $start));
    }

    private function url(string $path, string $key, array $query = []): string
    {
        $query['key'] = $key;
        return rtrim($this->cfg['gemini_base'], '/')
            . '/' . trim($this->cfg['gemini_api_version'], '/')
            . '/' . ltrim($path, '/')
            . '?' . http_build_query($query);
    }

    /**
     * Обычный (не потоковый) вызов. Возвращает разобранный JSON.
     * @return array<string,mixed>
     */
    public function call(string $path, ?array $body = null, array $query = [], string $method = 'POST'): array
    {
        $attempts = min(count($this->keys), 1 + max(0, (int) $this->cfg['max_retries']));
        $lastStatus = 0;
        $lastBody = '';

        for ($i = 0; $i < $attempts; $i++) {
            $key = $this->keys[$i % count($this->keys)];
            $ch = curl_init($this->url($path, $key, $query));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_TIMEOUT        => (int) $this->cfg['timeout'],
                CURLOPT_CONNECTTIMEOUT => (int) $this->cfg['connect_timeout'],
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            $resp = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($resp === false) {
                $lastStatus = 502;
                $lastBody = 'curl: ' . $err;
                Logger::line('error', 'upstream curl error', ['err' => $err]);
                continue;
            }

            if ($status >= 200 && $status < 300) {
                $data = json_decode((string) $resp, true);
                if (!is_array($data)) {
                    Http::error(502, 'Malformed response from Gemini', 'api_error');
                }
                Logger::body('gemini.response', $resp);
                return $data;
            }

            $lastStatus = $status;
            $lastBody = (string) $resp;
            Logger::line('warn', 'upstream error', ['status' => $status, 'body' => mb_substr($lastBody, 0, 500)]);

            // Пробуем следующий ключ только на «переживаемых» ошибках.
            if (!in_array($status, [429, 500, 502, 503, 504], true)) {
                break;
            }
            usleep(300_000);
        }

        $this->failFromUpstream($lastStatus, $lastBody);
    }

    /**
     * Потоковый вызов (alt=sse). $onChunk получает каждый распакованный JSON-объект.
     * Возвращает true, если поток стартовал успешно.
     */
    public function stream(string $path, array $body, callable $onChunk): bool
    {
        $attempts = min(count($this->keys), 1 + max(0, (int) $this->cfg['max_retries']));
        $lastStatus = 0;
        $lastBody = '';

        for ($i = 0; $i < $attempts; $i++) {
            $key = $this->keys[$i % count($this->keys)];
            $buffer = '';
            $status = 0;
            $errorBody = '';

            $ch = curl_init($this->url($path, $key, ['alt' => 'sse']));
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: text/event-stream'],
                CURLOPT_TIMEOUT        => (int) $this->cfg['timeout'],
                CURLOPT_CONNECTTIMEOUT => (int) $this->cfg['connect_timeout'],
                CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$status) {
                    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                        $status = (int) $m[1];
                    }
                    return strlen($header);
                },
                CURLOPT_WRITEFUNCTION  => function ($ch, $data) use (&$buffer, &$status, &$errorBody, $onChunk) {
                    if ($status >= 400 || $status === 0) {
                        $errorBody .= $data;   // тело ошибки — не SSE
                        return strlen($data);
                    }
                    $buffer .= $data;
                    while (($pos = strpos($buffer, "\n")) !== false) {
                        $line = rtrim(substr($buffer, 0, $pos), "\r");
                        $buffer = substr($buffer, $pos + 1);
                        if ($line === '' || str_starts_with($line, ':')) {
                            continue;
                        }
                        if (str_starts_with($line, 'data:')) {
                            $json = trim(substr($line, 5));
                            if ($json === '' || $json === '[DONE]') {
                                continue;
                            }
                            $decoded = json_decode($json, true);
                            if (is_array($decoded)) {
                                $onChunk($decoded);
                            }
                        }
                    }
                    return strlen($data);
                },
            ]);

            $ok = curl_exec($ch);
            $curlErr = curl_error($ch);
            if ($status === 0) {
                $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            }
            curl_close($ch);

            if ($ok !== false && $status >= 200 && $status < 300) {
                return true;
            }

            $lastStatus = $status ?: 502;
            $lastBody = $errorBody !== '' ? $errorBody : ('curl: ' . $curlErr);
            Logger::line('warn', 'upstream stream error', ['status' => $lastStatus, 'body' => mb_substr($lastBody, 0, 500)]);

            if (!in_array($lastStatus, [429, 500, 502, 503, 504], true)) {
                break;
            }
        }

        $this->failFromUpstream($lastStatus, $lastBody);
    }

    /** Преобразует ошибку Gemini в ошибку OpenAI-формата и завершает запрос. */
    private function failFromUpstream(int $status, string $body): void
    {
        $decoded = json_decode($body, true);
        $message = $decoded['error']['message'] ?? ($body !== '' ? mb_substr($body, 0, 500) : 'Upstream request failed');
        $reason = (string) ($decoded['error']['details'][0]['reason'] ?? '');

        // Проблема с ключом Gemini — это неисправность сервера, а не запроса клиента.
        // Gemini отдаёт её как 400 API_KEY_INVALID, поэтому распознаём отдельно.
        $keyProblem = in_array($status, [401, 403], true)
            || $reason === 'API_KEY_INVALID'
            || stripos($message, 'API key not valid') !== false
            || stripos($message, 'API_KEY_INVALID') !== false;

        if ($keyProblem) {
            Http::error(502, 'Gateway is misconfigured: upstream rejected the server API key (' . $message . ')', 'api_error', 'upstream_auth_failed');
        }

        $type = match (true) {
            $status === 429 => 'rate_limit_error',
            $status >= 400 && $status < 500 => 'invalid_request_error',
            default => 'api_error',
        };
        Http::error($status ?: 502, 'Gemini: ' . $message, $type);
    }
}
