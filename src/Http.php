<?php
declare(strict_types=1);

/**
 * Мелкие помощники для ввода/вывода HTTP.
 */
final class Http
{
    public static function cors(array $cfg): void
    {
        $origins = $cfg['cors_origins'] ?? '*';
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if ($origins === '*') {
            header('Access-Control-Allow-Origin: *');
        } elseif (is_array($origins) && $origin !== '' && in_array($origin, $origins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, x-api-key, OpenAI-Organization, OpenAI-Beta');
        header('Access-Control-Max-Age: 86400');
    }

    /** @return array<string,mixed> */
    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            self::error(400, 'Invalid JSON in request body', 'invalid_request_error');
        }
        return $data;
    }

    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Ошибка в формате OpenAI. Завершает выполнение. */
    public static function error(
        int $status,
        string $message,
        string $type = 'api_error',
        ?string $code = null,
        ?string $param = null
    ): void {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'error' => [
                'message' => $message,
                'type'    => $type,
                'param'   => $param,
                'code'    => $code,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** Заголовки для Server-Sent Events + отключение буферизации. */
    public static function sseStart(): void
    {
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // важно для nginx в aaPanel
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(true);
    }

    public static function sseSend(array $payload): void
    {
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        if (function_exists('fastcgi_finish_request') === false) {
            flush();
        } else {
            flush();
        }
    }

    public static function sseDone(): void
    {
        echo "data: [DONE]\n\n";
        flush();
    }

    public static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if ($header === '' && function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = $value;
                    break;
                }
            }
        }

        if ($header !== '' && preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
            return trim($m[1]);
        }
        // Совместимость с клиентами, шлющими x-api-key (Anthropic-стиль) или ?key=
        if (!empty($_SERVER['HTTP_X_API_KEY'])) {
            return trim((string) $_SERVER['HTTP_X_API_KEY']);
        }
        if (!empty($_GET['key'])) {
            return trim((string) $_GET['key']);
        }
        return null;
    }

    /**
     * Адрес, по которому к шлюзу обратились: из самого запроса.
     *
     * Зашивать домен нельзя — шлюз ставят и на localhost, и в локальной сети,
     * и за чужим обратным прокси. Заголовки X-Forwarded-* читаем, потому что
     * иначе за прокси получится http вместо https и ссылки на картинки
     * перестанут открываться.
     */
    public static function baseUrl(array $cfg = []): string
    {
        $fixed = trim((string) ($cfg['base_url'] ?? ''));
        if ($fixed !== '') {
            return rtrim($fixed, '/');   // за хитрым прокси можно задать руками
        }

        $scheme = 'http';
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            $scheme = 'https';
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $proto = strtolower(trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
            $scheme = $proto === 'https' ? 'https' : 'http';
        } elseif ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            $scheme = 'https';
        }

        $host = trim((string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
        if ($host === '') {
            $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        }
        if ($host === '') {
            $host = trim((string) ($_SERVER['SERVER_NAME'] ?? '')) ?: 'localhost';
            $port = (int) ($_SERVER['SERVER_PORT'] ?? 0);
            if ($port > 0 && $port !== 80 && $port !== 443) {
                $host .= ':' . $port;
            }
        }
        // В Host лезет что угодно от клиента, а мы из него делаем ссылку.
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', explode(',', $host)[0]) ?? 'localhost';

        return $scheme . '://' . ($host !== '' ? $host : 'localhost');
    }

    public static function clientIp(): string
    {
        return (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '-');
    }
}
