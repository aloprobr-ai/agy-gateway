#!/bin/sh
# Перехватчик между agy и покупным прокси (см. strip.py и README, раздел
# «Без промпта agy»). Покупной прокси берётся из config.php шлюза —
# cli.upstream_proxy, — так что при его смене правится одно место
# и перезапускается служба: systemctl restart agy-mitm.
HERE=$(dirname "$(readlink -f "$0")")
# Где шлюз и чем запускать PHP — задаются в agy-mitm.service.
export GATEWAY=${GATEWAY:-/var/www/agy-gateway}
PHP=${PHP:-php}

P=$("$PHP" -r '$c = require getenv("GATEWAY") . "/config.php"; echo $c["cli"]["upstream_proxy"] ?? "";' 2>/dev/null)
if [ -z "$P" ]; then
    echo "cli.upstream_proxy в $GATEWAY/config.php пуст" >&2
    exit 1
fi
AUTH=$(echo "$P" | sed -nE 's#^https?://([^@/]+)@.*#\1#p')
HOSTPORT=$(echo "$P" | sed -E 's#^https?://([^@/]+@)?##; s#/.*##')

# Расшифровываем только трафик к модели и журнал событий Google (его
# глушит strip.py, см. block_analytics); остальное идёт насквозь.
# http2=false: поток ответа модели через HTTP/2 mitmproxy закрывал с опозданием
# на минуту, а agy до закрытия не заканчивает ход.
exec "$HERE/venv/bin/mitmdump" --listen-host 127.0.0.1 -p "${PORT:-18080}" \
    --set confdir="$HERE/conf" --set http2=false \
    --mode "upstream:http://$HOSTPORT" ${AUTH:+--upstream-auth "$AUTH"} \
    --allow-hosts 'cloudcode-pa\.googleapis\.com|^play\.googleapis\.com' \
    -s "$HERE/strip.py" -q
