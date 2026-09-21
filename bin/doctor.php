<?php
declare(strict_types=1);

/**
 * Проверка обстановки: что у шлюза есть всё нужное и что именно чинить, если нет.
 *
 *     php bin/doctor.php            полная проверка, включая пробный запуск CLI
 *     php bin/doctor.php --quick    без пробного запуска
 *
 * Запускать нужно от того же пользователя, от которого работает шлюз. Под
 * веб-сервером это не вы: там свой пользователь, свой HOME и свой PATH, и
 * проверка из-под вас ничего про него не докажет.
 *
 *     sudo -u www php bin/doctor.php        (nginx + PHP-FPM в Linux)
 *
 * Скрипт ничего не меняет — только смотрит и объясняет.
 */

$root = dirname(__DIR__);
require $root . '/src/Compat.php';
require $root . '/src/Platform.php';
require $root . '/src/Http.php';
require $root . '/src/Logger.php';

$quick = in_array('--quick', array_slice($argv, 1), true);
$win = Platform::isWindows();

$problems = 0;
$warnings = 0;

/** Строка отчёта: FAIL — работать не будет, ВНИМ — часть возможностей отпадёт. */
$say = static function (string $name, ?bool $good, string $detail = '', string $fix = '') use (&$problems, &$warnings): void {
    if ($good === false) {
        $problems++;
        $mark = 'FAIL';
    } elseif ($good === null) {
        $warnings++;
        $mark = 'ВНИМ';
    } else {
        $mark = ' OK ';
    }
    printf("%s  %s%s\n", $mark, $name, $detail !== '' ? ' — ' . $detail : '');
    if ($good !== true && $fix !== '') {
        foreach (explode("\n", $fix) as $line) {
            echo '      ' . $line . "\n";
        }
    }
};

$user = function_exists('posix_getpwuid')
    ? (posix_getpwuid(posix_geteuid())['name'] ?? '?')
    : get_current_user();

echo "Система:       " . Platform::name() . ' (' . PHP_OS . ")\n";
echo "PHP:           " . PHP_VERSION . ' (' . PHP_SAPI . ")\n";
echo "Пользователь:  {$user}\n";
echo "Дом:           " . Platform::home() . "\n";
echo "Проект:        " . Platform::root() . "\n\n";

// ------------------------------------------------------------------ конфиг
if (!is_file($root . '/config.php')) {
    $say('config.php на месте', false, 'файла нет',
        $win ? 'copy config.example.php config.php' : 'cp config.example.php config.php');
    echo "\nБез config.php дальше проверять нечего.\n";
    exit(2);
}
$cfg = require $root . '/config.php';
$cli = $cfg['cli'] ?? [];
$say('config.php на месте', true);

// --------------------------------------------------------------------- PHP
$say('PHP 8.1 или новее', PHP_VERSION_ID >= 80100, PHP_VERSION,
    'обновите PHP: на более старом шлюз не запустится');

$say('proc_open разрешена', function_exists('proc_open'), '',
    $win
        ? "уберите proc_open из disable_functions в php.ini"
        : "aaPanel -> PHP -> Настройки -> disable_functions: уберите proc_open и putenv,\nзатем перезапустите PHP-FPM");

// Расширения. Без них чат через CLI работает, а часть возможностей — нет,
// поэтому это предупреждение, а не отказ.
$iniPath = php_ini_loaded_file();
$howToIni = $win
    ? ("php.ini " . ($iniPath ? "лежит тут: {$iniPath}" : "не создан") . "\n"
        . ($iniPath
            ? "откройте его и уберите ; в начале нужной строки extension=..."
            : "в папке с php.exe скопируйте php.ini-development в php.ini,\n"
              . "найдите строку extension_dir и сделайте её extension_dir = \"ext\",\n"
              . "затем уберите ; перед нужными строками extension=...")
        . "\nпосле правки перезапустите шлюз")
    : "sudo apt install php-curl php-mbstring   # Debian, Ubuntu\n"
      . "sudo dnf install php-curl php-mbstring  # Fedora\n"
      . "затем перезапустите PHP-FPM";

$extNeeded = [
    'curl' => 'ключи Google, перевод, обновление списка моделей',
    'openssl' => 'запросы по https из самого шлюза',
    'mbstring' => 'работает и без него (есть своя подпорка), но с ним быстрее',
];
foreach ($extNeeded as $ext => $why) {
    $have = extension_loaded($ext);
    $say("расширение {$ext}", $have ? true : null, $have ? '' : "без него не будет: {$why}", $have ? '' : $howToIni);
}

// ------------------------------------------------------------------ бэкенд
$backend = ($cfg['backend'] ?? 'api') === 'cli' ? 'cli' : 'api';
$say("backend = {$backend}", true);

if ($backend === 'api') {
    $keys = (array) ($cfg['gemini_keys'] ?? []);
    $say('есть ключи Gemini', $keys !== [], count($keys) . ' шт.',
        "впишите ключ из Google AI Studio в 'gemini_keys' в config.php");
} else {
    // ------------------------------------------------------------- сам CLI
    $prefix = Platform::agyCommand($cli);
    $bin = $prefix[0] ?? '';
    $say('agy найден', Platform::commandExists($prefix),
        $prefix === [] ? 'не найден ни в PATH, ни в привычных местах' : implode(' ', $prefix),
        $win
            ? "поставьте Antigravity CLI и добавьте его папку в PATH,\nлибо впишите полный путь в 'cli.command' в config.php"
            : "which agy — и впишите полный путь в 'cli.command' в config.php\n"
              . "(под веб-сервером PATH другой, поэтому короткого имени мало)");

    if ($prefix !== []) {
        // Версия и, главное, умеет ли CLI поток событий: без stream-json шлюз
        // не разберёт ответ, а ошибка вышла бы невнятная.
        // Спрашиваем всю команду целиком: в 'cli.command' может стоять префикс
        // ('sudo -u agent agy' или 'php tests/fake_agy.php'), и версия одного
        // первого слова рассказала бы про sudo, а не про CLI.
        $run = implode(' ', array_map('escapeshellarg', $prefix));
        $help = @shell_exec($run . ' --help 2>&1') ?: '';
        $version = trim(strtok(trim((string) @shell_exec($run . ' --version 2>&1')), "\n") ?: '');
        $streamOk = str_contains($help, '--output-format');
        $say('CLI умеет --output-format stream-json', $streamOk,
            $version !== '' ? "версия {$version}" : '',
            "обновите CLI: agy update");
    }

    $home = trim((string) ($cli['home'] ?? '')) ?: Platform::home();
    $say('домашний каталог существует', is_dir($home), $home,
        $win ? '' : "создайте его для {$user}: sudo mkdir -p {$home} && sudo chown {$user} {$home}");

    $auth = Platform::join($home, '.gemini');
    $say('CLI авторизован', is_dir($auth), $auth . (is_dir($auth) ? '' : ' (нет)'),
        $win
            ? "запустите agy в консоли и войдите в свою учётную запись"
            : "войдите ИМЕННО под пользователем шлюза: sudo -u {$user} -H agy");

    $brain = trim((string) ($cli['brain_dir'] ?? '')) ?: Platform::join($home, '.gemini', 'antigravity-cli', 'brain');
    if (is_dir($brain)) {
        $say('каталог бесед пишется', is_writable($brain), $brain,
            $win ? '' : "sudo chown -R {$user} " . dirname(dirname($brain)));
    } else {
        $say('каталог бесед появится при первом запросе', true, $brain . ' (пока нет)');
    }

    // ---------------------------------------------------------- песочница
    $jail = $cli['jail'] ?? 'auto';
    if ($jail === false || $jail === 'off' || $jail === '') {
        $say('песочница', null, 'выключена в config.php',
            "CLI запускается с --dangerously-skip-permissions и видит весь диск.\n"
            . "Это осознанный выбор — просто знайте о нём.");
    } elseif (Platform::canJail() && is_file(Platform::jailScript())) {
        $say('песочница', true, 'unshare + ' . Platform::jailScript());
        if (!is_executable(Platform::jailScript())) {
            $say('bin/agy-jail исполняем', false, '', 'chmod +x bin/agy-jail');
        }
    } else {
        $say('песочница', null, $win ? 'в Windows недоступна' : 'нет unshare или bin/agy-jail',
            $win
                ? "CLI запускается с --dangerously-skip-permissions и видит весь диск.\n"
                  . "Если это важно — заведите отдельную учётную запись Windows с урезанными\n"
                  . "правами и запускайте шлюз от неё."
                : "sudo apt install util-linux  (нужен unshare)\n"
                  . "и проверьте: sysctl kernel.unprivileged_userns_clone — должно быть 1");
    }
}

// -------------------------------------------------------------- каталоги
foreach (['storage', 'logs'] as $d) {
    $path = Platform::join($root, $d);
    $exists = is_dir($path);
    $say("каталог {$d} пишется", $exists ? is_writable($path) : is_writable($root),
        $path . ($exists ? '' : ' (будет создан)'),
        $win ? "дайте пользователю шлюза право записи в эту папку"
             : "sudo mkdir -p {$path} && sudo chown -R {$user} {$path}");
}

// ---------------------------------------------------------- пробный запуск
if (!$quick && $backend === 'cli' && $problems === 0) {
    echo "\nПробный запрос к CLI (до " . (int) ($cli['timeout'] ?? 180) . " с)...\n";
    require $root . '/src/Usage.php';
    require $root . '/src/AgyClient.php';

    $started = microtime(true);
    try {
        $agy = new AgyClient($cfg);
        $session = 'doctor-' . bin2hex(random_bytes(4));
        $model = trim((string) ($cli['default_model'] ?? '')) ?: null;
        $res = $agy->ask($session, 'Ответьте одним словом: OK', $model, 1);
        $elapsed = round(microtime(true) - $started, 1);
        $say('CLI ответил', trim((string) $res['text']) !== '',
            "{$elapsed} с: " . mb_substr(trim((string) $res['text']), 0, 60));
        $agy->forget($session);
    } catch (Throwable $e) {
        $say('CLI ответил', false, $e->getMessage(),
            'подробности процесса — в logs/agy-stderr.log');
    }
}

echo "\n";
if ($problems > 0) {
    echo "Мешает работать: {$problems}. Сначала почините это.\n";
} elseif ($warnings > 0) {
    echo "Всё главное на месте. Замечаний: {$warnings} — часть возможностей будет недоступна.\n";
} else {
    echo "Всё готово к работе.\n";
}
exit($problems === 0 ? 0 : 1);
