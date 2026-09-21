<?php
declare(strict_types=1);

/**
 * Бэкенд "cli": вместо HTTP-запросов к Gemini запускает Antigravity CLI (`agy`)
 * и вычитывает ответ из transcript.jsonl — тот же приём, что в llm_client.py.
 *
 * Диалог живёт внутри CLI, поэтому шлюз хранит соответствие
 * «session_id клиента -> conversation UUID» и помнит, сколько сообщений
 * из истории уже отправлено, чтобы не слать их повторно.
 */
final class AgyClient
{
    private array $cfg;
    private array $cli;
    private string $stateFile;
    /** @var resource|null */
    private $proc = null;
    /** @var resource|null */
    private $stdout = null;
    /** Windows пишет вывод процесса в файл — см. spawn(). */
    private string $outFile = '';
    private int $outPos = 0;
    private array $tempFiles = [];
    /** Рабочий каталог этой беседы и время начала хода — по ним ищем новые файлы. */
    private string $workDir = '';
    private int $runStart = 0;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
        $this->cli = $cfg['cli'] ?? [];

        if (!function_exists('proc_open')) {
            Http::error(500, 'CLI backend requires proc_open(), but it is disabled in php.ini (disable_functions).', 'api_error', 'proc_open_disabled');
        }

        $dir = dirname(__DIR__) . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $this->stateFile = $dir . '/sessions.json';

        // Если клиент отвалился или скрипт упал — не оставляем висеть процесс CLI.
        register_shutdown_function([$this, 'cleanup']);
    }

    public function cleanup(): void
    {
        if (is_resource($this->stdout)) {
            fclose($this->stdout);
            $this->stdout = null;
        }
        if (is_resource($this->proc)) {
            $status = proc_get_status($this->proc);
            if ($status['running'] ?? false) {
                proc_terminate($this->proc);
            }
            proc_close($this->proc);
            $this->proc = null;
        }
        $this->outFile = '';
        $this->outPos = 0;
        foreach ($this->tempFiles as $f) {
            @unlink($f);
        }
        $this->tempFiles = [];
    }

    // ---------------------------------------------------------------- сессии

    private function brainDir(): string
    {
        $dir = trim((string) ($this->cli['brain_dir'] ?? ''));
        if ($dir === '') {
            $home = trim((string) ($this->cli['home'] ?? '')) ?: Platform::home();
            $dir = Platform::join($home, '.gemini', 'antigravity-cli', 'brain');
        }
        return Platform::normalize($dir);
    }

    /**
     * Что шлюз уже знает об этой сессии.
     * @return array{uuid:?string,sent:int}
     */
    public function session(string $sessionId): array
    {
        $state = $this->loadState();
        $row = $state[$sessionId] ?? null;
        return [
            'uuid' => isset($row['uuid']) ? (string) $row['uuid'] : null,
            'sent' => (int) ($row['sent'] ?? 0),
        ];
    }

    /** Забыть сессию — следующий запрос начнёт новую беседу в CLI. */
    public function forget(string $sessionId): void
    {
        $fh = @fopen($this->stateFile, 'c+');
        if ($fh === false) {
            return;
        }
        flock($fh, LOCK_EX);
        $state = json_decode(stream_get_contents($fh) ?: '', true);
        if (is_array($state)) {
            unset($state[$sessionId]);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    /** @return array<string,array{uuid:string,sent:int,updated:int}> */
    private function loadState(): array
    {
        if (!is_file($this->stateFile)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($this->stateFile), true);
        return is_array($data) ? $data : [];
    }

    private function saveSession(string $sessionId, string $uuid, int $sent): void
    {
        $fh = @fopen($this->stateFile, 'c+');
        if ($fh === false) {
            Logger::line('warn', 'cannot write session state', ['file' => $this->stateFile]);
            return;
        }
        flock($fh, LOCK_EX);
        $raw = stream_get_contents($fh) ?: '';
        $state = json_decode($raw, true);
        if (!is_array($state)) {
            $state = [];
        }
        $state[$sessionId] = ['uuid' => $uuid, 'sent' => $sent, 'updated' => time()];

        // Чистим протухшие записи, чтобы файл не рос бесконечно.
        $ttl = (int) ($this->cli['session_ttl'] ?? 86400 * 7);
        if ($ttl > 0) {
            $now = time();
            $state = array_filter($state, static fn($s) => ($s['updated'] ?? 0) + $ttl > $now);
        }

        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    // ------------------------------------------------- файлы, сделанные моделью

    /**
     * Свой каталог на беседу: иначе файлы разных людей лежали бы вперемешку
     * и «покажи, что тут есть» показывало бы чужое.
     */
    private function prepareWorkdir(string $sessionId): string
    {
        $base = trim((string) ($this->cli['workdir'] ?? ''));
        if ($base === '') {
            // По умолчанию — внутри проекта: так каталог точно есть и пишется,
            // и человеку не приходится заводить его руками при первом запуске.
            $base = Platform::join(Platform::root(), 'storage', 'work');
        }
        $name = preg_replace('/[^A-Za-z0-9_.\-]/', '', $sessionId);
        $dir = Platform::ensureDir(Platform::join($base, $name !== '' ? $name : 'default'));
        if ($dir === '') {
            return '';
        }
        if (random_int(1, 20) === 1) {
            $this->pruneWorkdirs($base);
        }
        return $dir;
    }

    /** Каталоги беседы живут столько же, сколько сама сессия. */
    private function pruneWorkdirs(string $base): void
    {
        $ttl = (int) ($this->cli['session_ttl'] ?? 86400 * 7);
        if ($ttl <= 0) {
            return;
        }
        $now = time();
        foreach ((array) glob(Platform::join($base, '*'), GLOB_ONLYDIR) as $dir) {
            if ($now - (int) @filemtime($dir) <= $ttl) {
                continue;
            }
            foreach ((array) glob($dir . '/*') as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($dir);
        }
    }

    /**
     * Что модель сохранила за этот ход.
     *
     * Смотрим два места: рабочий каталог беседы и scratch самого CLI —
     * встроенный generate_image кладёт картинку именно туда, а не в cwd.
     * Отбираем по времени изменения, иначе прицепится всё старое.
     *
     * @return string[] пути к файлам
     */
    private function collectFiles(): array
    {
        $dirs = [];
        if ($this->workDir !== '') {
            $dirs[] = $this->workDir . '/*';
            $dirs[] = $this->workDir . '/*/*';
        }
        $scratch = trim((string) ($this->cli['scratch_dir'] ?? ''));
        if ($scratch === '') {
            $home = (string) ($this->cli['home'] ?? '');
            $scratch = $home !== '' ? rtrim($home, '/') . '/.gemini/antigravity-cli/scratch' : '';
        }
        if ($scratch !== '') {
            $dirs[] = rtrim($scratch, '/') . '/*';
        }

        $since = $this->runStart - 2;   // запас на расхождение часов внутри хода
        $found = [];
        foreach ($dirs as $mask) {
            foreach ((array) glob($mask) as $file) {
                if (!is_file($file) || (int) @filemtime($file) < $since) {
                    continue;
                }
                // Один и тот же снимок CLI кладёт и в рабочий каталог, и в scratch,
                // причём имена бывают разные. Сверяем содержимое, иначе человек
                // получит одну картинку дважды.
                $key = (string) @sha1_file($file);
                if ($key === '') {
                    $key = basename($file) . ':' . (int) @filesize($file);
                }
                $found[$key] = $file;
            }
        }

        $max = (int) ($this->cli['max_files'] ?? 6);
        return array_slice(array_values($found), 0, $max);
    }

    // --------------------------------------------------------------- запуск

    /** @param string[] $args */
    private function spawn(array $args): void
    {
        // Где лежит agy: путь из конфига, префикс запуска или поиск по системе.
        $prefix = Platform::agyCommand($this->cli);
        if ($prefix === []) {
            Http::error(502, 'Antigravity CLI (agy) not found. Install it, or set "cli.command" in config.php to the full path.', 'api_error', 'cli_not_found');
        }
        $prefix = $this->withJail($prefix);
        $cmd = array_merge($prefix, $args, array_values((array) ($this->cli['extra_args'] ?? ['--dangerously-skip-permissions'])));

        $logDir = Platform::ensureDir(Platform::join(Platform::root(), 'logs'));
        $logFile = Platform::join($logDir !== '' ? $logDir : Platform::tempDir(), 'agy-stderr.log');

        // stdout — поток событий stream-json, его читаем сами; stderr — в лог.
        //
        // Труба годится только в Linux. На Windows неблокирующее чтение из трубы
        // процесса не поддерживается: fread ждёт, пока наберётся целый буфер или
        // процесс завершится, и ответ вместо того, чтобы идти словами, приходит
        // разом в конце. Поэтому там просим процесс писать во временный файл,
        // а сами дочитываем его по мере роста — так поступает и Symfony Process.
        if (Platform::isWindows()) {
            $this->outFile = Platform::join(Platform::tempDir(), 'agy-out-' . bin2hex(random_bytes(8)) . '.jsonl');
            $this->outPos = 0;
            $this->tempFiles[] = $this->outFile;
            $out = ['file', $this->outFile, 'w'];
        } else {
            $this->outFile = '';
            $out = ['pipe', 'w'];
        }

        $desc = [
            0 => ['pipe', 'r'],
            1 => $out,
            2 => ['file', $logFile, 'a'],
        ];

        $env = self::envFor($this->cli);

        $cwd = $this->workDir !== ''
            ? $this->workDir
            : (!empty($this->cli['workdir']) ? (string) $this->cli['workdir'] : null);

        Logger::line('info', 'spawning agy', ['args' => array_slice($cmd, 0, 3)]);
        $proc = @proc_open($cmd, $desc, $pipes, $cwd, $env);

        if (!is_resource($proc)) {
            Http::error(502, 'Failed to start CLI process "' . implode(' ', $prefix) . '". Check the "cli.command" path and PHP permissions.', 'api_error', 'cli_spawn_failed');
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]); // CLI не должен ждать ввода
        }
        if ($this->outFile === '') {
            stream_set_blocking($pipes[1], false);
            $this->stdout = $pipes[1];
        }
        $this->proc = $proc;
    }

    /**
     * Очередной кусок вывода CLI. Пустая строка — «пока ничего нового».
     *
     * В Linux читаем трубу, в Windows — файл, в который пишет процесс. Позицию
     * помним сами и каждый раз перематываем к ней: иначе отметка «файл кончился»
     * прилипает к дескриптору и дочитать выросший файл уже не выходит.
     */
    private function readChunk(): string
    {
        if ($this->outFile !== '') {
            if (!is_resource($this->stdout)) {
                if (!is_file($this->outFile)) {
                    return '';   // процесс ещё не успел создать файл
                }
                $fh = @fopen($this->outFile, 'rb');
                if (!is_resource($fh)) {
                    return '';
                }
                $this->stdout = $fh;
            }
            clearstatcache(false, $this->outFile);
            if (@fseek($this->stdout, $this->outPos) !== 0) {
                return '';
            }
            $chunk = @fread($this->stdout, 65536);
            if (!is_string($chunk) || $chunk === '') {
                return '';
            }
            $this->outPos += strlen($chunk);
            return $chunk;
        }

        if (!is_resource($this->stdout)) {
            return '';
        }
        $chunk = fread($this->stdout, 65536);
        return is_string($chunk) ? $chunk : '';
    }

    /**
     * Окружение дочернего процесса.
     *
     * Отдельный метод из-за Windows: там имена переменных нечувствительны
     * к регистру, и если оставить рядом Path из системы и наш PATH, какой из
     * двух увидит процесс — угадать нельзя. Поэтому переписываемое имя сначала
     * вычищаем во всех написаниях.
     *
     * @return array<string,string>
     */
    private static function envFor(array $cli): array
    {
        $env = [];
        foreach (getenv() as $k => $v) {
            $env[(string) $k] = (string) $v;
        }

        $set = static function (string $name, string $value) use (&$env): void {
            foreach (array_keys($env) as $have) {
                if (strcasecmp((string) $have, $name) === 0) {
                    unset($env[$have]);
                }
            }
            $env[$name] = $value;
        };

        $home = trim((string) ($cli['home'] ?? ''));
        if ($home !== '') {
            $home = Platform::normalize($home);
            $set('HOME', $home);
            $set('USERPROFILE', $home);
        }
        $path = trim((string) ($cli['path'] ?? ''));
        if ($path !== '') {
            $set('PATH', $path);
        }
        foreach ((array) ($cli['env'] ?? []) as $k => $v) {
            $set((string) $k, (string) $v);
        }
        return $env;
    }

    /**
     * Обёртка-песочница перед командой, если она включена и доступна.
     *
     * Песочница есть только в Linux: bin/agy-jail прячет системные каталоги
     * под пустые tmpfs внутри своего пространства имён. В Windows такого
     * средства нет, и молча притворяться, что запуск изолирован, нельзя —
     * возвращаем команду как есть, а /health показывает, что защиты нет.
     *
     * @param string[] $prefix
     * @return string[]
     */
    private function withJail(array $prefix): array
    {
        if ($this->jailActive()) {
            return array_merge([$this->jailScript()], $prefix);
        }
        // 'on' — человек потребовал песочницу; если её нет, честнее отказать,
        // чем выполнить запрос без защиты, на которую он рассчитывает.
        $jail = $this->cli['jail'] ?? 'auto';
        if ($jail === true || $jail === 'on') {
            Http::error(500, 'cli.jail is enabled, but the sandbox is unavailable on this system (needs Linux and unshare from util-linux).', 'api_error', 'jail_unavailable');
        }
        return $prefix;
    }

    private function processRunning(): bool
    {
        if (!is_resource($this->proc)) {
            return false;
        }
        $status = proc_get_status($this->proc);
        return (bool) ($status['running'] ?? false);
    }

    // ------------------------------------------------------------ публичный API

    /**
     * Отправляет промпт в CLI и возвращает ответ.
     *
     * Читается поток событий CLI (--output-format stream-json): оттуда приходят
     * id беседы, куски текста по мере генерации и реальный расход токенов.
     * Это надёжнее разбора transcript.jsonl, где финального текста может не быть
     * вовсе — например, когда модель по пути вызывала инструменты.
     *
     * $onDelta (если задан) вызывается по мере появления текста — для стриминга.
     *
     * @return array{text:string,uuid:string,usage:array}
     */
    public function ask(string $sessionId, string $prompt, ?string $model, int $sentCount, ?callable $onDelta = null, ?callable $onStep = null): array
    {
        $state = $this->loadState();
        $uuid = $state[$sessionId]['uuid'] ?? null;

        $args = [];
        if ($uuid !== null && $uuid !== '') {
            $args[] = '--conversation';
            $args[] = $uuid;
        }
        array_push($args, '-p', $prompt, '--output-format', 'stream-json');
        if ($model !== null && $model !== '') {
            $args[] = trim((string) ($this->cli['model_flag'] ?? '')) ?: '--model';
            $args[] = $model;
        }

        $this->workDir = $this->prepareWorkdir($sessionId);
        $this->runStart = time();

        $this->spawn($args);
        $result = $this->readEventStream($sessionId, $uuid, $sentCount, $onDelta, $onStep);
        $this->cleanup();

        $result['files'] = $this->collectFiles();

        return $result;
    }

    /**
     * Разбирает поток JSON-событий CLI.
     * @return array{text:string,uuid:string,usage:array}
     */
    /**
     * Человекочитаемая строка про служебный шаг CLI.
     *
     * Наружу уходит как reasoning_content: клиент показывает это в свёрнутом
     * блоке «Размышления», пока идёт генерация. Параметры инструментов
     * укорачиваем — целиком они бывают на сотни строк.
     */
    private static function describeStep(array $step): string
    {
        $type = (string) ($step['step_type'] ?? '');
        $state = (string) ($step['state'] ?? '');

        if ($type === 'checkpoint') {
            return $state === 'DONE' ? "Обдумываю задачу\n" : '';
        }

        if ($type === 'tool') {
            if ($state !== 'ACTIVE') {
                return ''; // о завершении не пишем, иначе список вдвое длиннее
            }
            return self::toolPhrase((string) ($step['tool_name'] ?? '')) . "\n";
        }

        return ''; // user_input и незнакомые шаги показывать нечего
    }

    /**
     * Человеческое название шага.
     *
     * Раньше сюда попадали и параметры: команды целиком, пути на сервере,
     * адреса. Человеку это ничего не объясняет, зато показывает чужие
     * внутренности. Оставляем только действие.
     */
    private static function toolPhrase(string $name): string
    {
        $name = strtolower($name);
        $map = [
            'run_command' => 'Выполняю команду',
            'run_terminal_command' => 'Выполняю команду',
            'view_file' => 'Читаю файл',
            'read_file' => 'Читаю файл',
            'open_file' => 'Читаю файл',
            'write_file' => 'Пишу файл',
            'create_file' => 'Пишу файл',
            'edit_file' => 'Правлю файл',
            'replace_file_content' => 'Правлю файл',
            'list_dir' => 'Смотрю, что в папке',
            'find_files' => 'Ищу файлы',
            'grep_search' => 'Ищу по тексту',
            'codebase_search' => 'Ищу по тексту',
            'generate_image' => 'Рисую картинку',
            'view_image' => 'Рассматриваю картинку',
            'web_search' => 'Ищу в интернете',
            'read_url_content' => 'Читаю страницу',
            'browser_navigate' => 'Открываю страницу',
            'create_memory' => 'Запоминаю',
        ];
        if (isset($map[$name])) {
            return $map[$name];
        }
        return 'Работаю'; // незнакомый инструмент: лучше молча, чем его именем
    }

    private function readEventStream(string $sessionId, ?string $knownUuid, int $sentCount, ?callable $onDelta, ?callable $onStep = null): array
    {
        $timeout = (float) ($this->cli['timeout'] ?? 180);
        $deadline = microtime(true) + $timeout;

        $uuid = $knownUuid;
        $buffer = '';
        $text = '';
        $usage = [];
        $status = null;
        $finished = false;
        $exited = false;
        $sessionSaved = false;

        while (microtime(true) < $deadline) {
            $chunk = $this->readChunk();

            if ($chunk === '') {
                if ($finished || $exited) {
                    break;
                }
                if (!$this->processRunning()) {
                    $exited = true; // остаток вывода добираем на следующем витке
                }
                usleep(50_000);
                continue;
            }

            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if ($line === '' || $line[0] !== '{') {
                    continue;
                }
                $event = json_decode($line, true);
                if (!is_array($event)) {
                    continue;
                }

                switch ($event['event'] ?? '') {
                    case 'init':
                        $uuid = (string) ($event['init']['conversation_id'] ?? ($event['conversation_id'] ?? $uuid));
                        // Беседа известна — фиксируем привязку сразу, чтобы обрыв
                        // соединения не заставил следующий запрос начать заново.
                        if ($uuid !== '' && !$sessionSaved) {
                            $this->saveSession($sessionId, $uuid, $sentCount);
                            $sessionSaved = true;
                        }
                        break;

                    case 'step_update':
                        $step = $event['step_update'] ?? [];
                        if (($step['step_type'] ?? '') !== 'agent_response') {
                            // Сам текст служебных шагов в ответ не попадает,
                            // но клиенту полезно видеть, чем модель занята.
                            if ($onStep !== null) {
                                $line = self::describeStep($step);
                                if ($line !== '') {
                                    $onStep($line);
                                }
                            }
                            break;
                        }
                        $delta = (string) ($step['text_delta'] ?? '');
                        if ($delta === '') {
                            // Шаг без текста — это размышление перед вызовом
                            // инструмента: показываем его как ход мысли.
                            $thinking = (int) ($step['usage']['thinking_tokens'] ?? 0);
                            if ($onStep !== null && $thinking > 0 && ($step['state'] ?? '') === 'DONE') {
                                $onStep("Обдумываю следующий шаг\n");
                            }
                            break;
                        }
                        $text .= $delta;
                        if ($onDelta !== null) {
                            $onDelta($delta);
                        }
                        break;

                    case 'result':
                        $res = $event['result'] ?? [];
                        $status = (string) ($res['status'] ?? '');
                        $usage = self::mapUsage($res['usage'] ?? []);
                        $uuid = (string) ($res['conversation_id'] ?? $uuid);
                        $final = (string) ($res['response'] ?? '');

                        // Финальный ответ — источник истины: дошлём то, что не попало в дельты.
                        if ($final !== '' && $final !== $text) {
                            if ($onDelta !== null && $text !== '' && str_starts_with($final, $text)) {
                                $onDelta(substr($final, strlen($text)));
                            } elseif ($onDelta !== null && $text === '') {
                                $onDelta($final);
                            }
                            $text = $final;
                        }
                        $finished = true;
                        break;
                }
            }
        }

        if ($uuid === null || $uuid === '') {
            $this->cleanup();
            // Чаще всего одно из двух: CLI не авторизован под этим
            // пользователем, или он слишком старый и не знает stream-json.
            // Обе причины видны в логе, поэтому сразу отправляем туда.
            Http::error(502, 'CLI did not report a conversation id. Check that "' . $this->commandLabel()
                . '" is installed, up to date (needs --output-format stream-json: run "agy update")'
                . ' and authorized for the PHP user — details in logs/agy-stderr.log.',
                'api_error', 'cli_no_conversation');
        }

        $this->saveSession($sessionId, $uuid, $sentCount);

        if (trim($text) === '') {
            $this->cleanup();
            if (!$finished) {
                $hint = $exited
                    ? 'CLI exited without an answer — see logs/agy-stderr.log.'
                    : 'CLI did not answer within ' . (int) $timeout . 's.';
                Http::error($exited ? 502 : 504, $hint, 'api_error', $exited ? 'cli_failed' : 'cli_timeout');
            }
            Http::error(502, 'CLI returned status ' . ($status ?: 'UNKNOWN') . ' without an answer — see logs/agy-stderr.log.', 'api_error', 'cli_failed');
        }

        return ['text' => trim($text), 'uuid' => $uuid, 'usage' => $usage];
    }

    /** Расход токенов CLI -> формат usage OpenAI. */
    private static function mapUsage(array $u): array
    {
        if ($u === []) {
            return [];
        }
        $prompt = (int) ($u['input_tokens'] ?? 0);
        $output = (int) ($u['output_tokens'] ?? 0);
        $thinking = (int) ($u['thinking_tokens'] ?? 0);

        return [
            'prompt_tokens' => $prompt,
            'completion_tokens' => $output,
            'total_tokens' => (int) ($u['total_tokens'] ?? ($prompt + $output)),
            'completion_tokens_details' => ['reasoning_tokens' => $thinking],
            'prompt_tokens_details' => ['cached_tokens' => (int) ($u['cache_read_tokens'] ?? 0)],
        ];
    }

    private function commandLabel(): string
    {
        // Именно то, что запускалось: в конфиге путь может быть пустым
        // («найди сам»), и показывать человеку пустые кавычки бессмысленно.
        $prefix = Platform::agyCommand($this->cli);
        return $prefix === [] ? 'agy' : implode(' ', $prefix);
    }

    // ------------------------------------------------------------- промпт

    /**
     * Собирает текст промпта из сообщений OpenAI.
     * $from — индекс, с которого сообщения ещё не отправлялись в эту сессию.
     * Картинки сохраняются во временные файлы, их пути передаются модели.
     */
    public function buildPrompt(array $messages, int $from, bool $freshSession, array $tools = []): string
    {
        $lines = [];

        foreach ($messages as $i => $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $role = (string) ($msg['role'] ?? 'user');

            // Системные сообщения повторяем только при создании сессии.
            if ($role === 'system' || $role === 'developer') {
                if ($freshSession) {
                    $lines[] = "СИСТЕМНАЯ ИНСТРУКЦИЯ:\n" . $this->flatten($msg['content'] ?? '');
                }
                continue;
            }
            if ($i < $from) {
                continue; // уже уехало в CLI на прошлых ходах
            }

            if ($role === 'assistant') {
                $text = $this->flatten($msg['content'] ?? '');
                foreach (($msg['tool_calls'] ?? []) as $call) {
                    $fn = $call['function'] ?? [];
                    $text .= "\n[вызов инструмента " . ($fn['name'] ?? '') . ' ' . ($fn['arguments'] ?? '{}') . ']';
                }
                if (trim($text) !== '') {
                    $lines[] = "АССИСТЕНТ (ваш прошлый ответ):\n" . trim($text);
                }
                continue;
            }

            if ($role === 'tool' || $role === 'function') {
                $lines[] = "РЕЗУЛЬТАТ ИНСТРУМЕНТА " . (string) ($msg['name'] ?? '') . ":\n" . $this->flatten($msg['content'] ?? '');
                continue;
            }

            $lines[] = "ПОЛЬЗОВАТЕЛЬ:\n" . $this->flatten($msg['content'] ?? '');
        }

        $prompt = implode("\n\n", array_filter($lines, static fn($l) => trim($l) !== ''));

        if ($tools !== []) {
            $prompt .= "\n\n" . $this->toolInstruction($tools);
        }

        $prompt .= "\n\nОтветьте на последнее сообщение пользователя. "
            . "Выводите только сам ответ, без служебных пояснений и без markdown-обёрток вокруг всего текста."
            . "\n\nЕсли просят картинку, схему, график или файл — сделайте их сами своими "
            . "обычными средствами и сохраните в текущий каталог. Сохранённое попадёт "
            . "пользователю автоматически, ссылку подставит шлюз. Отвечать, что вы не умеете "
            . "создавать изображения или файлы, не нужно: умеете.";

        return $prompt;
    }

    /** Текст сообщения + картинки, выгруженные во временные файлы. */
    private function flatten($content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }

        $out = [];
        foreach ($content as $part) {
            if (is_string($part)) {
                $out[] = $part;
                continue;
            }
            if (!is_array($part)) {
                continue;
            }
            $type = (string) ($part['type'] ?? '');

            if ($type === 'text' || $type === 'input_text' || isset($part['text'])) {
                $out[] = (string) ($part['text'] ?? '');
                continue;
            }

            if ($type === 'image_url' || $type === 'input_image') {
                $url = $part['image_url']['url'] ?? ($part['image_url'] ?? ($part['url'] ?? null));
                if (!is_string($url) || $url === '') {
                    continue;
                }
                if (preg_match('#^https?://#i', $url)) {
                    $out[] = '[изображение по ссылке: ' . $url . ' — открой его своими инструментами]';
                } else {
                    $file = $this->dumpImage($url);
                    if ($file !== null) {
                        $out[] = '[изображение сохранено в файл: "' . $file . '" — прочитай его своими инструментами]';
                    }
                }
                continue;
            }

            if ($type === 'image' && isset($part['source']['data'])) {
                $file = $this->dumpImage('data:' . ($part['source']['media_type'] ?? 'image/png') . ';base64,' . $part['source']['data']);
                if ($file !== null) {
                    $out[] = '[изображение сохранено в файл: "' . $file . '" — прочитай его своими инструментами]';
                }
            }
        }

        return implode("\n", array_filter($out, static fn($s) => trim((string) $s) !== ''));
    }

    /** data:URI -> файл на диске, путь к которому получит CLI. */
    private function dumpImage(string $dataUri): ?string
    {
        if (!preg_match('#^data:([^;,]+);base64,(.*)$#is', $dataUri, $m)) {
            return null;
        }
        $mime = strtolower(trim($m[1]));
        $bin = base64_decode(preg_replace('/\s+/', '', $m[2]) ?? '', true);
        if ($bin === false || $bin === '') {
            return null;
        }
        $max = (int) ($this->cfg['max_image_bytes'] ?? 12 * 1024 * 1024);
        if (strlen($bin) > $max) {
            Http::error(413, 'Image is too large: limit is ' . round($max / 1048576, 1) . ' MB', 'invalid_request_error', null, 'image_url');
        }

        $dir = Platform::ensureDir($this->imageDir());
        if ($dir === '') {
            Http::error(500, 'Cannot store the image for the CLI: directory "' . $this->imageDir() . '" is not writable.', 'api_error', 'image_store_failed');
        }

        $ext = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/heic' => 'heic',
            default => 'jpg',
        };
        $file = Platform::join($dir, 'img-' . bin2hex(random_bytes(8)) . '.' . $ext);
        if (@file_put_contents($file, $bin) === false) {
            Http::error(500, 'Cannot write the image file for the CLI: ' . $file, 'api_error', 'image_store_failed');
        }
        @chmod($file, 0644);

        // Файлы нужны CLI только на время запроса.
        if (!empty($this->cli['keep_images'])) {
            return $file;
        }
        $this->tempFiles[] = $file;
        return $file;
    }

    /**
     * Каталог, куда кладутся присланные картинки, чтобы CLI их прочитал.
     *
     * Обычно это storage/images внутри проекта. Но когда включена песочница,
     * каталог проекта внутри неё не виден, и файл по такому пути для модели
     * не существует: она получит имя, ничего не найдёт и начнёт выдумывать,
     * что на снимке. Поэтому под песочницей кладём в общий каталог в доме CLI.
     */
    private function imageDir(): string
    {
        $dir = trim((string) ($this->cli['image_dir'] ?? ''));
        if ($dir !== '') {
            return Platform::normalize($dir);
        }
        if ($this->jailActive()) {
            $home = trim((string) ($this->cli['home'] ?? '')) ?: Platform::home();
            return Platform::join($home, 'agy-share', 'images');
        }
        return Platform::join(Platform::root(), 'storage', 'images');
    }

    /** Действительно ли запуск будет обёрнут в песочницу. */
    private function jailActive(): bool
    {
        $jail = $this->cli['jail'] ?? 'auto';
        if ($jail === false || $jail === 'off' || $jail === '') {
            return false;
        }
        return Platform::canJail() && is_file($this->jailScript());
    }

    /** Путь к скрипту песочницы: свой из конфига или встроенный. */
    private function jailScript(): string
    {
        $jail = $this->cli['jail'] ?? 'auto';
        return is_string($jail) && !in_array($jail, ['auto', 'on', 'off', ''], true)
            ? Platform::normalize($jail)
            : Platform::jailScript();
    }

    private function toolInstruction(array $tools): string
    {
        $list = [];
        foreach ($tools as $tool) {
            $fn = $tool['function'] ?? $tool;
            if (empty($fn['name'])) {
                continue;
            }
            $list[] = '- ' . $fn['name'] . ': ' . (string) ($fn['description'] ?? '')
                . ' | параметры: ' . json_encode($fn['parameters'] ?? new stdClass(), JSON_UNESCAPED_UNICODE);
        }
        if ($list === []) {
            return '';
        }

        return "ИНСТРУМЕНТЫ НА КОМПЬЮТЕРЕ ПОЛЬЗОВАТЕЛЯ:\n" . implode("\n", $list) . "\n"
            . "Если нужен один из них, верните ТОЛЬКО JSON вида "
            . '{"tool_calls":[{"name":"имя","args":{...}}]} без каких-либо пояснений. '
            . 'Если инструмент не нужен, отвечайте обычным текстом. '
            . 'Этот список — только про компьютер пользователя; ваши собственные '
            . 'возможности он не отменяет.';
    }

    /**
     * Достаёт из ответа модели вызовы инструментов, если они есть.
     * @return array{text:string,tool_calls:array}
     */
    public static function extractToolCalls(string $text): array
    {
        $candidate = trim($text);
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $candidate, $m)) {
            $candidate = $m[1];
        }
        if ($candidate === '' || $candidate[0] !== '{') {
            return ['text' => $text, 'tool_calls' => []];
        }

        $data = json_decode($candidate, true);
        if (!is_array($data) || empty($data['tool_calls']) || !is_array($data['tool_calls'])) {
            return ['text' => $text, 'tool_calls' => []];
        }

        $calls = [];
        foreach ($data['tool_calls'] as $i => $call) {
            $name = (string) ($call['name'] ?? ($call['function']['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $args = $call['args'] ?? ($call['arguments'] ?? ($call['function']['arguments'] ?? []));
            if (is_string($args)) {
                $decoded = json_decode($args, true);
                $args = is_array($decoded) ? $decoded : ['input' => $args];
            }
            $calls[] = [
                'id' => 'call_' . substr(md5($name . json_encode($args) . $i), 0, 22),
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'arguments' => json_encode($args ?: new stdClass(), JSON_UNESCAPED_UNICODE),
                ],
            ];
        }

        if ($calls === []) {
            return ['text' => $text, 'tool_calls' => []];
        }
        return ['text' => (string) ($data['reply_text'] ?? ''), 'tool_calls' => $calls];
    }

    /** Грубая оценка токенов — CLI не сообщает реальный расход. */
    public static function estimateTokens(string $text): int
    {
        return (int) max(1, ceil(mb_strlen($text) / 3.5));
    }
}
