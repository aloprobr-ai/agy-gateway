<?php
declare(strict_types=1);

/**
 * Счётчик расхода: сколько запросов и токенов потратил каждый ключ.
 *
 * Квоты у Antigravity нет — CLI её не отдаёт, поэтому «сколько осталось»
 * взять неоткуда. Зато можно честно считать израсходованное.
 * Данные лежат в storage/usage/<ГГГГ-ММ>.json: ключ -> день -> счётчики.
 * Ключ хранится только меткой (12 символов sha256), сам ключ никуда не пишется.
 */
final class Usage
{
    private const ZERO = ['requests' => 0, 'prompt' => 0, 'completion' => 0, 'total' => 0];

    private static function dir(): string
    {
        $dir = dirname(__DIR__) . '/storage/usage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private static function file(string $month): string
    {
        return self::dir() . '/' . $month . '.json';
    }

    /** Записать расход одного запроса. Ошибки молча глотаем: учёт важнее не сломать ответ. */
    public static function record(string $keyLabel, string $model, array $usage): void
    {
        $day = date('Y-m-d');
        $fh = @fopen(self::file(date('Y-m')), 'c+');
        if ($fh === false) {
            return;
        }
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            return;
        }

        $data = json_decode((string) stream_get_contents($fh), true);
        if (!is_array($data)) {
            $data = [];
        }

        $row = $data[$keyLabel][$day] ?? self::ZERO + ['models' => []];
        $row['requests'] = (int) ($row['requests'] ?? 0) + 1;
        $row['prompt'] = (int) ($row['prompt'] ?? 0) + (int) ($usage['prompt_tokens'] ?? 0);
        $row['completion'] = (int) ($row['completion'] ?? 0) + (int) ($usage['completion_tokens'] ?? 0);
        $row['total'] = (int) ($row['total'] ?? 0) + (int) ($usage['total_tokens'] ?? 0);
        if ($model !== '') {
            $models = is_array($row['models'] ?? null) ? $row['models'] : [];
            $models[$model] = (int) ($models[$model] ?? 0) + 1;
            $row['models'] = $models;
        }
        $data[$keyLabel][$day] = $row;

        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    /** Сводка по ключу: сегодня, за месяц и остаток минутного лимита. */
    public static function summary(array $cfg, string $keyLabel): array
    {
        $day = date('Y-m-d');
        $data = json_decode((string) @file_get_contents(self::file(date('Y-m'))), true);
        $rows = (is_array($data) && is_array($data[$keyLabel] ?? null)) ? $data[$keyLabel] : [];

        $today = self::ZERO;
        $month = self::ZERO;
        $models = [];
        foreach ($rows as $date => $row) {
            foreach (self::ZERO as $field => $_) {
                $month[$field] += (int) ($row[$field] ?? 0);
                if ($date === $day) {
                    $today[$field] += (int) ($row[$field] ?? 0);
                }
            }
            foreach ((array) ($row['models'] ?? []) as $name => $count) {
                $models[$name] = (int) ($models[$name] ?? 0) + (int) $count;
            }
        }
        arsort($models);

        return [
            'key' => $keyLabel,
            'today' => $today,
            'month' => $month,
            'models' => $models,
            'since' => date('Y-m-01'),
            'rate_limit' => self::rateLimit($cfg, $keyLabel),
            'quota' => null, // Antigravity остаток не сообщает — показывать нечего
        ];
    }

    /** Состояние минутного лимита, если он вообще включён. */
    private static function rateLimit(array $cfg, string $keyLabel): array
    {
        $limit = (int) ($cfg['rate_limit_per_min'] ?? 0);
        if ($limit <= 0) {
            return ['enabled' => false];
        }

        $file = dirname(__DIR__) . '/logs/rate/' . $keyLabel . '.json';
        $state = json_decode((string) @file_get_contents($file), true);
        $now = time();
        $used = 0;
        $resetIn = 0;
        if (is_array($state) && (int) ($state['window'] ?? 0) + 60 > $now) {
            $used = (int) ($state['count'] ?? 0);
            $resetIn = max(0, 60 - ($now - (int) $state['window']));
        }

        return [
            'enabled' => true,
            'limit_per_min' => $limit,
            'used' => $used,
            'left' => max(0, $limit - $used),
            'reset_in' => $resetIn,
        ];
    }
}
