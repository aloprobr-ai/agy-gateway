<?php
declare(strict_types=1);

/**
 * OpenAI-совместимый шлюз к Google Gemini.
 * Точка входа: сюда nginx/apache отправляет все запросы.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_time_limit(0);
ignore_user_abort(false);

// Буфер нужен, чтобы случайный BOM или лишний перевод строки в config.php
// не попал в тело ответа и не сломал JSON у клиента.
ob_start();

$root = dirname(__DIR__);

require $root . '/src/Compat.php';
require $root . '/src/Platform.php';
require $root . '/src/Http.php';
require $root . '/src/Auth.php';
require $root . '/src/Logger.php';
require $root . '/src/Gemini.php';
require $root . '/src/AgyClient.php';
require $root . '/src/ModelCatalog.php';
require $root . '/src/Usage.php';
require $root . '/src/Releases.php';
require $root . '/src/Keys.php';
require $root . '/src/AgyPrompt.php';
require $root . '/src/Files.php';
require $root . '/src/Translator.php';
require $root . '/src/Endpoints.php';

if (!is_file($root . '/config.php')) {
    Http::error(500, 'config.php not found. Copy config.example.php to config.php and add your keys.', 'api_error', 'missing_config');
}
$cfg = require $root . '/config.php';

if (ob_get_length()) {
    ob_clean();
}

Logger::init($cfg);
Http::cors($cfg);

set_exception_handler(static function (Throwable $e): void {
    Logger::line('error', 'unhandled exception', ['msg' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine()]);
    if (!headers_sent()) {
        Http::error(500, 'Internal server error', 'api_error');
    }
});

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = '/' . trim($path, '/');
// Клиенты по-разному собирают base_url: /v1/..., /api/v1/..., /openai/v1/...
$path = preg_replace('#^/(api|openai)(?=/)#', '', $path) ?: '/';
$path = rtrim($path, '/') ?: '/';

Logger::request($method . ' ' . $path);

// --- Публичные маршруты (без ключа) ---
if ($path === '/' || $path === '/index.php') {
    header('Content-Type: text/html; charset=utf-8');
    // Страница динамическая: показывает модели, доступные текущему бэкенду.
    require __DIR__ . '/home.php';
    exit;
}

if ($path === '/up' && $method === 'GET') {
    Http::json(Releases::check($_GET['from'] ?? null));
    exit;
}

if (preg_match('#^/up/download/([^/]+)$#', $path, $m) === 1 && $method === 'GET') {
    Releases::send(rawurldecode($m[1]));
}

if ($path === '/archive' || $path === '/archive/publish') {
    $notice = null;
    if ($path === '/archive/publish' && $method === 'POST') {
        $res = Releases::publish($cfg, $_FILES['msi'] ?? [], $_POST);
        $notice = $res['ok']
            ? ['ok' => true, 'text' => 'Версия ' . $res['release']['version'] . ' выложена.']
            : ['ok' => false, 'text' => $res['error']];
    }
    header('Content-Type: text/html; charset=utf-8');
    require __DIR__ . '/archive.php';
    exit;
}

// Файлы, сделанные моделью. Имя случайное и длинное — ключ здесь не спросить:
// тег <img> в приложении не умеет слать заголовок Authorization.
if (str_starts_with($path, '/files/')) {
    Files::serve(rawurldecode(substr($path, 7)));
}

if ($path === '/keys' || str_starts_with($path, '/keys/')) {
    // Короткий сеанс только для этой страницы. Нужен, чтобы после отправки формы
    // увести браузер на обычный GET: иначе перезагрузка страницы повторяет POST
    // и молча выдаёт ещё один ключ.
    $hasSession = session_status() === PHP_SESSION_ACTIVE;
    if (!$hasSession) {
        session_name('agykeys');
        session_set_cookie_params([
            'path' => '/keys',
            'httponly' => true,
            'secure' => !empty($_SERVER['HTTPS']),
            'samesite' => 'Lax',
        ]);
        $hasSession = @session_start();
    }
    $notice = null;
    $fresh = null;
    if ($method === 'POST') {
        if (!Keys::isAdminIp($cfg)) {
            $notice = ['ok' => false, 'text' => 'Управлять ключами можно только с доверенного адреса.'];
        } elseif (!Keys::tokenOk($cfg)) {
            $notice = ['ok' => false, 'text' => 'Токен управления не подошёл. Это не ключ, который мы выдаём, '
                . 'а пароль страницы — строка admin.token из config.php, та же, что для выкладывания обновлений.'];
        } elseif ($path === '/keys/create') {
            $res = Keys::create((string) ($_POST['label'] ?? ''), (string) ($_POST['custom'] ?? ''), $cfg);
            if (!empty($res['ok'])) {
                $fresh = $res['key'];
                $notice = ['ok' => true, 'text' => 'Ключ выдан.'];
            } else {
                $notice = ['ok' => false, 'text' => (string) $res['error']];
            }
        } elseif ($path === '/keys/toggle') {
            $off = ($_POST['disabled'] ?? '0') === '1';
            $ok = Keys::setDisabled((string) ($_POST['key'] ?? ''), $off);
            $notice = ['ok' => $ok, 'text' => $ok
                ? ($off ? 'Ключ отключён.' : 'Ключ снова работает.')
                : 'Ключ не найден.'];
        } elseif ($path === '/keys/adopt') {
            $res = Keys::adopt((string) ($_POST['key'] ?? ''), $cfg);
            $notice = ['ok' => !empty($res['ok']), 'text' => !empty($res['ok'])
                ? 'Ключ «' . $res['label'] . '» перенесён из config.php. Значение то же, работает как раньше.'
                : (string) $res['error']];
        } elseif ($path === '/keys/delete') {
            $ok = Keys::delete((string) ($_POST['key'] ?? ''));
            $notice = ['ok' => $ok, 'text' => $ok ? 'Ключ удалён.' : 'Не удалось удалить.'];
        }
        if ($hasSession) {
            // Сообщение и сам ключ переживают редирект в сеансе и показываются один раз.
            $_SESSION['keysFlash'] = ['notice' => $notice, 'fresh' => $fresh];
            header('Location: /keys', true, 303);
            exit;
        }
    }
    if ($hasSession && $method !== 'POST') {
        $flash = $_SESSION['keysFlash'] ?? null;
        unset($_SESSION['keysFlash']);
        if (is_array($flash)) {
            $notice = $flash['notice'] ?? null;
            $fresh = $flash['fresh'] ?? null;
        }
    }
    header('Content-Type: text/html; charset=utf-8');
    require __DIR__ . '/keys.php';
    exit;
}

if ($path === '/health' || $path === '/healthz' || $path === '/v1/health') {
    $backend = ($cfg['backend'] ?? 'api') === 'cli' ? 'cli' : 'api';
    $health = [
        'status' => 'ok',
        'time' => date('c'),
        'php' => PHP_VERSION,
        'backend' => $backend,
        'keys_configured' => count(array_filter($cfg['gemini_keys'] ?? [], fn($k) => is_string($k) && trim($k) !== '' && !str_contains($k, 'ВАШ_КЛЮЧ'))),
        // Спрашиваем у самого Auth, а не у конфига: показывать надо то, что
        // произойдёт с запросом, а не то, что написано в одном из полей.
        'auth_required' => Auth::required($cfg),
    ];

    $catalogAt = ModelCatalog::generatedAt();
    $health['models_catalog'] = [
        'updated_at' => $catalogAt,
        'api_models' => count(ModelCatalog::api()),
        'cli_models' => count(ModelCatalog::cli()),
        'hint' => $catalogAt === null ? 'run bin/update_models.php to fill it' : null,
    ];

    $health['system'] = [
        'os' => Platform::name(),
        'php' => PHP_VERSION,
        'extensions' => [
            'curl' => extension_loaded('curl'),
            'mbstring' => extension_loaded('mbstring'),
            'openssl' => extension_loaded('openssl'),
        ],
    ];

    if ($backend === 'cli') {
        $cli = $cfg['cli'] ?? [];
        $home = trim((string) ($cli['home'] ?? '')) ?: Platform::home();
        $brain = trim((string) ($cli['brain_dir'] ?? ''))
            ?: Platform::join($home, '.gemini', 'antigravity-cli', 'brain');

        // Проверяем ровно то, что запустится: тот же поиск, что и при запросе.
        $prefix = Platform::agyCommand($cli);
        $command = $prefix[0] ?? '';

        // Песочница есть только в Linux. Если её нет, человек должен узнать об
        // этом здесь, а не решить, что она работает молча.
        $jail = $cli['jail'] ?? 'auto';
        $jailOn = !($jail === false || $jail === 'off' || $jail === '');
        $jailScript = is_string($jail) && !in_array($jail, ['auto', 'on'], true)
            ? Platform::normalize($jail)
            : Platform::jailScript();

        $health['cli'] = [
            'command' => implode(' ', $prefix),
            'command_found' => Platform::commandExists($prefix),
            'brain_dir' => $brain,
            'brain_dir_exists' => is_dir($brain),        // до первой беседы каталога может не быть
            'brain_dir_writable' => is_dir($brain) && is_writable($brain),
            'proc_open' => function_exists('proc_open'),
            'php_user' => function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : get_current_user(),
            'sandbox' => !$jailOn
                ? 'off'
                : (Platform::canJail() && is_file($jailScript) ? 'on' : 'unavailable'),
        ];
        if ($health['cli']['sandbox'] === 'unavailable') {
            $health['cli']['sandbox_note'] = Platform::isWindows()
                ? 'No sandbox on Windows: the CLI can reach the whole file system. Run the gateway under a limited account if that matters.'
                : 'unshare (util-linux) or bin/agy-jail is missing — the CLI runs without isolation.';
        }
        // Деградация — только то, без чего запрос гарантированно не выполнится.
        if (!$health['cli']['proc_open'] || !$health['cli']['command_found']) {
            $health['status'] = 'degraded';
        }
    }

    Http::json($health, $health['status'] === 'ok' ? 200 : 503);
    exit;
}

// --- Дальше нужен клиентский ключ ---
$keyLabel = Auth::check($cfg);

$req = in_array($method, ['POST', 'PUT', 'PATCH'], true) ? Http::jsonBody() : [];
if ($req !== []) {
    Logger::body('client.request', $req);
}

switch (true) {
    case $path === '/v1/chat/completions' && $method === 'POST':
        Logger::request('chat', ['key' => $keyLabel, 'model' => $req['model'] ?? null, 'stream' => !empty($req['stream'])]);
        Endpoints::chat($cfg, $req, $keyLabel);
        break;

    case $path === '/v1/completions' && $method === 'POST':
        Endpoints::completions($cfg, $req, $keyLabel);
        break;

    case $path === '/v1/embeddings' && $method === 'POST':
        Endpoints::embeddings($cfg, $req);
        break;

    case $path === '/v1/images/generations' && $method === 'POST':
        Endpoints::images($cfg, $req, $keyLabel);
        break;

    case $path === '/v1/agy-prompt' && ($method === 'GET' || $method === 'POST'):
        AgyPrompt::handle($cfg, $req, $keyLabel, $method);
        break;

    case $path === '/v1/usage' && $method === 'GET':
        Endpoints::usage($cfg, $keyLabel);
        break;

    case $path === '/v1/models' && $method === 'GET':
        Endpoints::models($cfg);
        break;

    case preg_match('#^/v1/models/(.+)$#', $path, $m) === 1 && $method === 'GET':
        Endpoints::model($cfg, $m[1]);
        break;

    default:
        Http::error(404, 'Unknown endpoint: ' . $method . ' ' . $path, 'invalid_request_error', 'unknown_url');
}
