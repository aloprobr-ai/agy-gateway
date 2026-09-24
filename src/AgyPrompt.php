<?php
declare(strict_types=1);

/**
 * Выключатель промпта agy: для всех ключей сразу и для каждого отдельно.
 *
 * Сам промпт вырезает перехватчик deploy/agy-mitm — шлюз только решает,
 * нужно ли это для запроса, и передаёт решение пометкой в GEMINI.md беседы
 * (AgyClient::$stripPrompt). Без перехватчика выключить промпт нечем, и
 * «off» не принимается, чтобы клиент не думал, что что-то поменялось.
 *
 * Хранится в storage/agy-prompt.json:
 *   {"all": "on", "keys": {"<метка ключа>": "off"}}
 * «on» — промпт agy идёт как есть, «off» — вырезается. Своя строка ключа
 * главнее общей; нет строки — действует общая. Метка ключа — та же, что в
 * логах (Auth::check): начало sha256, сам ключ сюда не пишется.
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

    /** @return array{all:string,keys:array<string,string>} */
    public static function load(): array
    {
        $data = json_decode((string) @file_get_contents(self::file()), true);
        $all = ($data['all'] ?? 'on') === 'off' ? 'off' : 'on';
        $keys = [];
        foreach ((array) ($data['keys'] ?? []) as $label => $mode) {
            if (in_array($mode, ['on', 'off'], true)) {
                $keys[(string) $label] = $mode;
            }
        }
        return ['all' => $all, 'keys' => $keys];
    }

    private static function save(array $state): bool
    {
        $state['keys'] = (object) $state['keys'];   // пустой — {}, а не []
        $json = (string) json_encode($state,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
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

    /** Вырезать ли промпт agy в запросах этого ключа. */
    public static function stripFor(array $cfg, string $keyLabel): bool
    {
        if (!self::available($cfg)) {
            return false;
        }
        $state = self::load();
        return ($state['keys'][$keyLabel] ?? $state['all']) === 'off';
    }

    /**
     * Все известные ключи: имя (как на странице /keys) и метка.
     * @return list<array{name:string,label:string}>
     */
    private static function knownKeys(array $cfg): array
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
        // Одинаковые имена («из config.php» у всех ключей из конфига) иначе
        // не различить — к таким добавляем хвост ключа, как на странице /keys.
        $out = [];
        foreach ($rows as [$name, $key]) {
            if ($seen[$name] > 1) {
                $name .= ' …' . substr($key, -4);
            }
            $out[] = ['name' => $name, 'label' => self::labelOf($key)];
        }
        return $out;
    }

    private static function view(array $cfg, string $keyLabel, bool $admin): array
    {
        $state = self::load();
        $names = [];
        foreach (self::knownKeys($cfg) as $k) {
            $names[$k['label']] = $k['name'];
        }
        $row = static fn(string $label, string $name) => [
            'name' => $name,
            'agy_prompt' => $state['keys'][$label] ?? 'default',
            'effective' => $state['keys'][$label] ?? $state['all'],
        ];
        $out = [
            'object' => 'agy_prompt',
            'interceptor' => self::available($cfg),
            'all' => $state['all'],
            'self' => $row($keyLabel, $names[$keyLabel] ?? $keyLabel),
            'admin' => $admin,
        ];
        if ($admin) {
            $out['keys'] = [];
            foreach ($names as $label => $name) {
                $out['keys'][] = $row($label, $name);
            }
        }
        return $out;
    }

    /**
     * GET  /v1/agy-prompt — что сейчас.
     * POST /v1/agy-prompt {"agy_prompt": "on"|"off"|"default", "keys": ...}
     *   без keys — свой ключ; "all" — общее значение; ["имя", ...] — эти ключи.
     *   "default" снимает у ключа своё значение: он снова следует общему.
     * Общее значение и чужие ключи меняет только управляющий: свой адрес и
     * токен управления в X-Admin-Token — те же, что у страницы /keys.
     */
    public static function handle(array $cfg, array $req, string $keyLabel, string $method): void
    {
        $admin = Keys::mayManage($cfg);
        if ($method === 'GET') {
            Http::json(self::view($cfg, $keyLabel, $admin));
            return;
        }

        $mode = strtolower(trim((string) ($req['agy_prompt'] ?? '')));
        if (!in_array($mode, ['on', 'off', 'default'], true)) {
            Http::error(400, '"agy_prompt" must be "on", "off" or "default".', 'invalid_request_error', 'invalid_agy_prompt');
        }
        $target = $req['keys'] ?? null;
        $self = $target === null || $target === [] || $target === 'self';
        // Сначала права, потом всё остальное: чужому незачем знать, как тут
        // устроено, — даже то, есть ли перехватчик.
        if (!$self && !$admin) {
            Http::error(403, Keys::isAdminIp($cfg)
                ? 'Changing other keys needs the admin token (X-Admin-Token) — the same as for the /keys page.'
                : 'Changing other keys is allowed only from the trusted address, with the admin token.',
                'permission_error', 'not_admin');
        }
        if ($mode === 'off' && !self::available($cfg)) {
            Http::error(409, 'The agy prompt cannot be switched off: the interceptor (deploy/agy-mitm) is not set up — cli.upstream_proxy is empty.', 'invalid_request_error', 'no_interceptor');
        }

        $state = self::load();
        if ($self) {
            $labels = [$keyLabel];
        } else {
            if ($target === 'all' || $target === ['all']) {
                if ($mode === 'default') {
                    Http::error(400, 'Use "on" or "off" for all keys.', 'invalid_request_error', 'invalid_agy_prompt');
                }
                $state['all'] = $mode;
                self::store($state);
                Http::json(self::view($cfg, $keyLabel, $admin));
                return;
            }
            // Имя без учёта регистра, и кириллица тоже: «стёпа» = «Стёпа».
            // Сравнивает PCRE с /iu — mbstring в PHP для Windows нет.
            $known = self::knownKeys($cfg);
            $labels = [];
            $unknown = [];
            foreach ((array) $target as $name) {
                $name = trim((string) $name);
                $re = '/^' . preg_quote($name, '/') . '$/iu';
                $found = array_filter($known, static fn($k) => $name !== '' && preg_match($re, $k['name']) === 1);
                if ($found === []) {
                    $unknown[] = $name;
                } else {
                    array_push($labels, ...array_column($found, 'label'));
                }
            }
            if ($unknown !== []) {
                Http::error(404, 'Unknown keys: ' . implode(', ', $unknown) . '. Names are the ones shown on the /keys page.', 'invalid_request_error', 'unknown_key');
            }
        }

        foreach ($labels as $label) {
            if ($mode === 'default') {
                unset($state['keys'][$label]);
            } else {
                $state['keys'][$label] = $mode;
            }
        }
        self::store($state);
        Http::json(self::view($cfg, $keyLabel, $admin));
    }

    private static function store(array $state): void
    {
        if (!self::save($state)) {
            Http::error(500, 'Could not save storage/agy-prompt.json — no write access to storage/.', 'api_error', 'storage_not_writable');
        }
        Logger::line('info', 'agy prompt switched', ['all' => $state['all'], 'keys' => count($state['keys'])]);
    }
}
