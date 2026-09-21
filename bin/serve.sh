#!/bin/sh
#
# Запуск шлюза на этом компьютере (Linux, macOS).
#
#     ./bin/serve.sh
#     PORT=9000 LISTEN=0.0.0.0 ./bin/serve.sh
#
# Это встроенный сервер PHP. Он поднимает несколько рабочих процессов
# (PHP_CLI_SERVER_WORKERS), поэтому длинный ответ в чате не мешает открыть
# страницу ключей. Для постоянной работы всё же лучше nginx + PHP-FPM,
# см. deploy/nginx.conf.
set -eu

root=$(cd "$(dirname "$0")/.." && pwd)
port=${PORT:-8080}
listen=${LISTEN:-127.0.0.1}

if ! command -v php >/dev/null 2>&1; then
    echo "PHP не найден. Установите php-cli 8.1 или новее:" >&2
    echo "  sudo apt install php-cli php-curl php-mbstring   # Debian, Ubuntu" >&2
    echo "  sudo dnf install php-cli php-curl php-mbstring   # Fedora" >&2
    exit 1
fi

if [ ! -f "$root/config.php" ]; then
    cp "$root/config.example.php" "$root/config.php"
    echo "Создан config.php из образца — впишите туда свой ключ."
fi

missing=""
for ext in curl mbstring openssl; do
    php -r "exit(extension_loaded('$ext') ? 0 : 1);" || missing="$missing $ext"
done
if [ -n "$missing" ]; then
    echo "Не включены расширения PHP:$missing"
    echo "Чат через CLI будет работать, остальное — нет. Подробности: php bin/doctor.php"
fi

mkdir -p "$root/logs" "$root/storage"

# Несколько обработчиков: иначе один потоковый ответ занимает сервер целиком.
PHP_CLI_SERVER_WORKERS=${WORKERS:-4}
export PHP_CLI_SERVER_WORKERS

echo
echo "Шлюз слушает http://$listen:$port"
echo "  адрес для приложения:  http://$listen:$port/v1"
echo "  проверка:              http://$listen:$port/health"
echo "  ключи:                 http://$listen:$port/keys"
echo "Остановить — Ctrl+C."
echo

exec php -S "$listen:$port" -t "$root/public" "$root/public/router.php"
