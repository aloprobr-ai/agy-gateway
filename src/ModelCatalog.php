<?php
declare(strict_types=1);

/**
 * Кэш списка моделей: storage/models.json, обновляется скриптом
 * bin/update_models.php (вручную или по cron).
 *
 * Конфиг остаётся источником алиасов, а каталог — источником реальных
 * имён моделей, которые сейчас доступны у Gemini и у CLI.
 */
final class ModelCatalog
{
    private static ?array $cache = null;

    public static function file(): string
    {
        return dirname(__DIR__) . '/storage/models.json';
    }

    public static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $file = self::file();
        if (!is_file($file)) {
            return self::$cache = [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return self::$cache = is_array($data) ? $data : [];
    }

    /** Модели Gemini API: ['gemini-2.5-flash' => ['methods'=>[...], ...]] */
    public static function api(): array
    {
        $data = self::load();
        return is_array($data['api'] ?? null) ? $data['api'] : [];
    }

    /** Имена моделей CLI, как их понимает agy --model. */
    public static function cli(): array
    {
        $data = self::load();
        return is_array($data['cli'] ?? null) ? array_values($data['cli']) : [];
    }

    /** Человекочитаемые названия моделей CLI: ['gemini-3.5-flash-medium' => 'Gemini 3.5 Flash (Medium)'] */
    public static function cliDisplay(): array
    {
        $data = self::load();
        return is_array($data['cli_display'] ?? null) ? $data['cli_display'] : [];
    }

    /**
     * Когда каждая модель впервые попала в каталог: ['gemini-3.8-flash-low' => 1789900000].
     * Нужно, чтобы клиент мог отличить новинку от той, что была всегда.
     */
    public static function firstSeen(): array
    {
        $data = self::load();
        return is_array($data['first_seen'] ?? null) ? $data['first_seen'] : [];
    }

    /** Сколько секунд назад собирали каталог; null — каталога ещё нет. */
    public static function ageSeconds(): ?int
    {
        $at = self::generatedAt();
        if ($at === null) {
            return null;
        }
        $ts = strtotime($at);
        return $ts === false ? null : max(0, time() - $ts);
    }

    public static function generatedAt(): ?string
    {
        $data = self::load();
        return isset($data['generated_at']) ? (string) $data['generated_at'] : null;
    }

    /**
     * Идентификатор модели CLI по её id или по отображаемому имени.
     * «gemini-3.5-flash-medium» и «Gemini 3.5 Flash (Medium)» дают один результат.
     */
    public static function matchCli(string $name): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        foreach (self::cli() as $known) {
            if (strcasecmp((string) $known, $name) === 0) {
                return (string) $known;
            }
        }
        foreach (self::cliDisplay() as $id => $display) {
            if (strcasecmp((string) $display, $name) === 0) {
                return (string) $id;
            }
        }
        return null;
    }

    /**
     * Старые отметки сохраняем, новым моделям ставим текущее время.
     * Исчезнувшие модели из списка убираем: вернётся — будет считаться новой.
     */
    private static function mergeFirstSeen(array $data): array
    {
        $old = is_array($data['first_seen'] ?? null) ? $data['first_seen'] : self::firstSeen();
        $now = time();
        $fresh = [];
        $ids = array_merge(
            array_values((array) ($data['cli'] ?? [])),
            array_keys((array) ($data['api'] ?? []))
        );
        foreach ($ids as $id) {
            $id = (string) $id;
            if ($id === '') {
                continue;
            }
            $fresh[$id] = (int) ($old[$id] ?? $now);
        }
        return $fresh;
    }

    public static function save(array $data): bool
    {
        $data['first_seen'] = self::mergeFirstSeen($data);
        $file = self::file();
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $ok = @file_put_contents(
            $file,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
        self::$cache = $ok !== false ? $data : self::$cache;
        return $ok !== false;
    }
}
