<?php
declare(strict_types=1);

/**
 * Обновление шлюза из репозитория на GitHub.
 *
 *     php bin/update.php              посмотреть, есть ли что нового
 *     php bin/update.php --apply      обновиться
 *     php bin/update.php --force      разложить файлы заново, даже если
 *                                     версия та же — чинит испорченные файлы
 *
 * Если папка — клон git, обновление делается через `git pull --ff-only`.
 * Иначе скачивается срез репозитория и раскладывается поверх: config.php,
 * storage, logs и public/files при этом не трогаются, а всё заменённое
 * откладывается в storage/backups.
 *
 * Запускать нужно от того же пользователя, от которого работает шлюз, —
 * иначе обновлённые файлы окажутся чужими и шлюз потеряет к ним доступ:
 *
 *     sudo -u www php bin/update.php --apply
 */

$root = dirname(__DIR__);
require $root . '/src/Compat.php';
require $root . '/src/Platform.php';
require $root . '/src/Http.php';
require $root . '/src/Logger.php';
require $root . '/src/Updater.php';

if (!is_file($root . '/config.php')) {
    fwrite(STDERR, "Нет config.php — обновлять нечего, шлюз ещё не настроен.\n");
    exit(2);
}
$cfg = require $root . '/config.php';

$args = array_slice($argv, 1);
$force = in_array('--force', $args, true);
$apply = $force || in_array('--apply', $args, true);

echo "Репозиторий: " . Updater::repo($cfg) . ' (' . Updater::branch($cfg) . ")\n";

$res = Updater::check($cfg);
$cur = $res['current'];

$where = match ($cur['source']) {
    'git' => 'по git',
    'file' => 'по отметке прошлого обновления',
    default => '',
};
echo "Установлено: " . ($cur['sha'] !== ''
        ? substr($cur['sha'], 0, 8) . ($cur['date'] !== '' ? ' от ' . substr($cur['date'], 0, 10) : '')
          . ' (' . $where . ')'
        : "неизвестно — этот код сюда просто скопировали") . "\n";

if (!$res['ok']) {
    echo "\nНе получилось узнать, есть ли новое: " . ($res['error'] ?? '?') . "\n";
    exit(1);
}

$latest = $res['latest'];
echo "На GitHub:   " . substr($latest['sha'], 0, 8)
    . ($latest['date'] !== '' ? ' от ' . substr($latest['date'], 0, 10) : '')
    . ($latest['message'] !== '' ? "\n             " . $latest['message'] : '') . "\n\n";

if (!$res['behind'] && !$force) {
    echo "У вас последняя версия.\n";
    echo "Если файлы всё же испорчены, разложить их заново: php bin/update.php --force\n";
    exit(0);
}

if (!$res['behind']) {
    echo "Версия та же, но раскладываю заново — так просили.\n\n";
}

if ($cur['sha'] === '') {
    echo "Что именно у вас стоит, определить нечем, поэтому «новее» здесь\n";
    echo "означает лишь «не совпадает». Обновление разложит файлы из репозитория\n";
    echo "поверх ваших — свои правки в коде, если они были, пропадут.\n";
    echo "Заменённое останется в storage/backups.\n\n";
}

if (!$apply) {
    echo "Есть что обновить. Поставить:  php bin/update.php --apply\n";
    exit(0);
}

echo "Обновляюсь...\n";
$out = Updater::apply($cfg, static function (string $line): void {
    if (trim($line) !== '') {
        echo $line . "\n";
    }
});

if (!$out['ok']) {
    echo "\nНе вышло: " . ($out['error'] ?? '?') . "\n";
    exit(1);
}

echo "\nГотово.";
if ($out['changed'] > 0) {
    echo " Заменено файлов: " . $out['changed'] . '.';
}
if (!empty($out['note'])) {
    echo ' ' . $out['note'] . '.';
}
echo "\n";

// PHP держит разобранный код в памяти, и новый попадёт туда не сам.
echo "Перезапустите шлюз, чтобы новый код заработал:\n";
echo Platform::isWindows()
    ? "  окно с bin\\serve.ps1 — Ctrl+C и запустить снова\n"
    : "  bin/serve.sh — Ctrl+C и снова, либо sudo systemctl restart agy-gateway\n"
      . "  под nginx: sudo systemctl reload php8.3-fpm\n";
echo "И проверьте: php bin/doctor.php --quick\n";
