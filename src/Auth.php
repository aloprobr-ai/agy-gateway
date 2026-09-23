<?php
declare(strict_types=1);

/**
 * Проверка клиентского ключа + простой лимит запросов в минуту.
 */
final class Auth
{
    /**
     * Ключи, которые сейчас принимаются: встроенные из config.php плюс
     * выданные через /keys. Наружу не отдаётся — только для проверки.
     *
     * @return list<string>
     */
    private static function acceptedKeys(array $cfg): array
    {
        $keys = $cfg['api_keys'] ?? [];
        if (!is_array($keys)) {
            $keys = [];
        }
        // ключи, выданные через /keys, работают наравне с теми, что в конфиге
        return array_values(array_merge($keys, Keys::active()));
    }

    /**
     * Нужен ли клиентскому запросу ключ.
     *
     * Единственное место, где это решается: check() ниже отказывает ровно по
     * этому признаку, и /health показывает его же. Отдельного флага в конфиге
     * нет намеренно — иначе /health однажды разойдётся с тем, что происходит
     * на самом деле, и клиент решит, что ключ не нужен.
     *
     * everIssued() учитывается отдельно: если ключи выдавались, но все сейчас
     * отключены, защита остаётся включённой и не подойдёт ни один ключ —
     * выключить её можно только удалив ключи, а не отключив их.
     */
    public static function required(array $cfg): bool
    {
        return self::acceptedKeys($cfg) !== [] || Keys::everIssued();
    }

    /** @return string короткая метка ключа для логов */
    public static function check(array $cfg): string
    {
        if (!self::required($cfg)) {
            return 'anonymous'; // аутентификация отключена
        }
        $keys = self::acceptedKeys($cfg);

        $token = Http::bearerToken();
        if ($token === null || $token === '') {
            Http::error(401, 'Missing API key. Provide it as "Authorization: Bearer <key>".', 'invalid_request_error', 'invalid_api_key');
        }

        $ok = false;
        foreach ($keys as $known) {
            if (hash_equals((string) $known, $token)) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            Http::error(401, 'Incorrect API key provided.', 'invalid_request_error', 'invalid_api_key');
        }

        Keys::touch($token);   // чтобы в /keys было видно, живой ключ или нет

        $label = substr(hash('sha256', $token), 0, 12);
        self::rateLimit($cfg, $label);
        return $label;
    }

    private static function rateLimit(array $cfg, string $label): void
    {
        $limit = (int) ($cfg['rate_limit_per_min'] ?? 0);
        if ($limit <= 0) {
            return;
        }

        $dir = dirname(__DIR__) . '/logs/rate';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/' . $label . '.json';
        $now = time();

        $fh = @fopen($file, 'c+');
        if ($fh === false) {
            return; // не смогли открыть счётчик — не блокируем трафик
        }
        flock($fh, LOCK_EX);
        $raw = stream_get_contents($fh) ?: '';
        $state = json_decode($raw, true);
        if (!is_array($state) || ($state['window'] ?? 0) + 60 <= $now) {
            $state = ['window' => $now, 'count' => 0];
        }
        $state['count']++;
        $over = $state['count'] > $limit;

        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($state));
        flock($fh, LOCK_UN);
        fclose($fh);

        if ($over) {
            header('Retry-After: ' . max(1, 60 - ($now - (int) $state['window'])));
            Http::error(429, 'Rate limit exceeded: ' . $limit . ' requests per minute.', 'rate_limit_error', 'rate_limit_exceeded');
        }
    }
}
