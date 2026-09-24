<?php
declare(strict_types=1);

/**
 * Промпт agy по желанию клиента — но только там, где это разрешил сервер.
 *
 * Разрешения раздаёт хозяин шлюза командой на сервере (bin/agy-prompt.php,
 * она же agy-prompt): всем ключам разом или отдельным. Клиент с разрешением
 * сам выключает и включает промпт agy для своего ключа — через
 * /v1/agy-prompt, в Gemini Desktop это команда /agy. Без разрешения клиент
 * промпт не трогает вовсе, и /v1/agy-prompt честно говорит, что нельзя.
 *
 * Сам промпт вырезает перехватчик deploy/agy-mitm — шлюз только решает,
 * нужно ли это, и передаёт решение пометкой в GEMINI.md беседы
 * (AgyClient::$stripPrompt). Нет перехватчика — нет и разрешений.
 *
 * storage/agy-prompt.json:
 *   {"all": false, "keys": {"<метка>": true|false}, "off": {"<метка>": true}}
 *   all  — разрешено ли всем ключам;
 *   keys — своё разрешение ключа, главнее общего;
 *   off  — ключи, чей клиент промпт выключил.
 * Метка ключа — та же, что в логах (Auth::check): начало sha256.
 */
final class AgyPrompt
{
    private static function file(): string
    {
        $dir = dirname(__DIR__) . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . '/agy-prompt.json';
    }

    /** @return array{all:bool,keys:array<string,bool>,off:array<string,bool>} */
    public static function load(): array
    {
        $data = json_decode((string) @file_get_contents(self::file()), true);
        $keys = [];
        foreach ((array) ($data['keys'] ?? []) as $label => $allowed) {
            if (is_bool($allowed)) {
                $keys[(string) $label] = $allowed;
            }
        }
        $off = [];
        foreach ((array) ($data['off'] ?? []) as $label => $yes) {
            if ($yes === true) {
                $off[(string) $label] = true;
            }
        }
        return ['all' => ($data['all'] ?? false) === true, 'keys' => $keys, 'off' => $off];
    }

    public static function save(array $state): bool
    {
        $state['keys'] = (object) $state['keys'];   // пустое — {}, а не []
        $state['off'] = (object) $state['off'];
        $json = (string) json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        // Через временный файл: читают его на каждый запрос, недописанным
        // он не должен попасться никому.
        $tmp = self::file() . '.tmp';
        return @file_put_contents($tmp, $json . "\n") !== false && @rename($tmp, self::file());
    }

    /** Метка ключа — как у Auth::check. */
    public static function labelOf(string $key): string
    {
        return substr(hash('sha256', $key), 0, 12);
    }

    /** Есть ли перехватчик, который умеет вырезать промпт. */
    public static function available(array $cfg): bool
    {
        return trim((string) ($cfg['cli']['upstream_proxy'] ?? '')) !== '';
    }

    public static function allowed(array $state, string $label): bool
    {
        return $state['keys'][$label] ?? $state['all'];
    }

    /** Вырезать ли промпт agy в запросах этого ключа. */
    public static function stripFor(array $cfg, string $keyLabel): bool
    {
        if (!self::available($cfg)) {
            return false;
        }
        $state = self::load();
        return self::allowed($state, $keyLabel) && !empty($state['off'][$keyLabel]);
    }

    /**
     * Все известные ключи: имя (как на странице /keys), сам ключ и метка.
     * У одинаковых имён («из config.php») — хвост ключа, чтобы их различать.
     * @return list<array{name:string,key:string,label:string}>
     */
    public static function knownKeys(array $cfg): array
    {
        $rows = [];
        $seen = [];
        foreach (array_merge(Keys::all(), Keys::builtin($cfg)) as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $name = (string) ($row['label'] ?? '');
            $rows[] = [$name, $key];
            $seen[$name] = ($seen[$name] ?? 0) + 1;
        }
        $out = [];
        foreach ($rows as [$name, $key]) {
            if ($seen[$name] > 1) {
                $name .= ' …' . substr($key, -4);
            }
            $out[] = ['name' => $name, 'key' => $key, 'label' => self::labelOf($key)];
        }
        return $out;
    }

    /**
     * GET  /v1/agy-prompt — можно ли этому ключу и что сейчас.
     * POST /v1/agy-prompt {"agy_prompt": "on"|"off"} — для своего ключа,
     * только с разрешением сервера. Чужие ключи отсюда не трогаются вовсе.
     */
    public static function handle(array $cfg, array $req, string $keyLabel, string $method): void
    {
        $state = self::load();
        $allowed = self::available($cfg) && self::allowed($state, $keyLabel);

        if ($method === 'POST') {
            if (!$allowed) {
                Http::error(403, 'Switching the agy prompt is not allowed for this key. The gateway owner allows it on the server: agy-prompt on all | agy-prompt on <key>.', 'permission_error', 'agy_prompt_not_allowed');
            }
            if (array_key_exists('keys', $req)) {
                Http::error(400, 'Only your own key can be switched here; other keys are managed on the server.', 'invalid_request_error', 'invalid_agy_prompt');
            }
            $mode = strtolower(trim((string) ($req['agy_prompt'] ?? '')));
            if (!in_array($mode, ['on', 'off'], true)) {
                Http::error(400, '"agy_prompt" must be "on" or "off".', 'invalid_request_error', 'invalid_agy_prompt');
            }
            if ($mode === 'off') {
                $state['off'][$keyLabel] = true;
            } else {
                unset($state['off'][$keyLabel]);
            }
            if (!self::save($state)) {
                Http::error(500, 'Could not save storage/agy-prompt.json — no write access to storage/.', 'api_error', 'storage_not_writable');
            }
            Logger::line('info', 'agy prompt switched by client', ['key' => $keyLabel, 'agy_prompt' => $mode]);
        }

        Http::json([
            'object' => 'agy_prompt',
            'allowed' => $allowed,
            'agy_prompt' => $allowed && !empty($state['off'][$keyLabel]) ? 'off' : 'on',
        ]);
    }
}
