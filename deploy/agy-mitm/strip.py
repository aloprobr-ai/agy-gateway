"""
Вырезает системный промпт agy из запросов к модели.

Лицензия: MIT с условием Commons Clause — пользоваться и править можно,
продавать нельзя. Полный текст — LICENSE рядом.

agy перед каждым ходом шлёт в streamGenerateContent свой промпт агента
(<identity>, <skills>, <artifacts>, ... — десятки тысяч знаков) и описания
всех инструментов. Здесь запрос переписывается на лету:

* systemInstruction — строка base_system (только когда остались инструменты)
  и правила из <user_rules>: туда agy кладёт GEMINI.md, а шлюз пишет в
  GEMINI.md системное сообщение клиента. Ничего нет — поле убирается;
* tools — только перечисленные в keep_tools;
* текст пользователя — без обёртки <USER_REQUEST> и служебных вставок
  про время и выбор модели.

Всё это — только когда шлюз пометил запрос (выключатель AgyPrompt: общий
и по ключам, команда agy-prompt, /agy в Gemini Desktop) или стоит
"strip_prompt": true; по умолчанию промпт agy идёт как есть. Телеметрия agy
глушится независимо от этого (block_analytics).

Служебные вызовы модели (заголовок беседы и т. п.) промпта агента не несут
и проходят как есть. Настройки читаются из strip.json на каждый запрос —
перезапуск службы после их правки не нужен.
"""
import json
import os
import re
import time

from mitmproxy import http

HERE = os.path.dirname(os.path.abspath(__file__))
CONF = os.path.join(HERE, "strip.json")
LOG = os.path.join(HERE, "strip.log")
DUMP = os.path.join(HERE, "dump")

# Пометка шлюза «вырезать» (AgyClient::STRIP_MARKER).
MARKER = "<!-- agy-gateway: strip-agy-prompt -->"

RULE_RE = re.compile(r"<RULE\[[^\]]*\]>\n?(.*?)\n?</RULE\[[^\]]*\]>", re.S)
REQ_RE = re.compile(r"<USER_REQUEST>\n?(.*?)\n?</USER_REQUEST>", re.S)
EXTRA_RE = re.compile(r"\n?<(ADDITIONAL_METADATA|USER_SETTINGS_CHANGE)>.*?</\1>\n?", re.S)


def conf():
    try:
        with open(CONF, encoding="utf-8") as f:
            return json.load(f)
    except Exception:
        return {}


def log(line):
    try:
        with open(LOG, "a", encoding="utf-8") as f:
            f.write(time.strftime("%Y-%m-%d %H:%M:%S ") + line + "\n")
    except Exception:
        pass


def dump(flow, tag, data):
    """Отладка: dump=true в strip.json сохраняет запросы и ответы модели."""
    os.makedirs(DUMP, exist_ok=True)
    name = "%d-%d-%s" % (time.time() * 1000, id(flow) % 100000, tag)
    with open(os.path.join(DUMP, name), "wb") as f:
        f.write(data or b"")


def is_generate(flow):
    return "GenerateContent" in flow.request.path


def system_text(req):
    parts = (req.get("systemInstruction") or {}).get("parts") or []
    return "".join(p.get("text", "") for p in parts if isinstance(p, dict))


def unwrap(text):
    """«<USER_REQUEST>вопрос</USER_REQUEST><ADDITIONAL_METADATA>…» -> «вопрос»."""
    m = REQ_RE.search(text)
    if not m:
        return EXTRA_RE.sub("\n", text).strip() or text
    rest = EXTRA_RE.sub("\n", REQ_RE.sub("", text)).strip()
    return m.group(1).strip() + ("\n\n" + rest if rest else "")


def filter_tools(req, keep):
    """Оставляет инструменты из keep; возвращает, сколько осталось."""
    keep = set(keep)
    new_tools = []
    for t in req.get("tools") or []:
        decls = t.get("functionDeclarations")
        if not decls:
            new_tools.append(t)          # не функции (поиск и т. п.) — не трогаем
            continue
        decls = [d for d in decls if d.get("name") in keep]
        if decls:
            new_tools.append({**t, "functionDeclarations": decls})
    if new_tools:
        req["tools"] = new_tools
    else:
        req.pop("tools", None)
        req.pop("toolConfig", None)
    return sum(len(t.get("functionDeclarations") or []) for t in new_tools)


def http_connect(flow: http.HTTPFlow):
    if conf().get("log_hosts"):
        log("CONNECT %s:%s" % (flow.request.host, flow.request.port))


def block_analytics(flow, c):
    """
    Телеметрия agy: recordTrajectoryAnalytics шлёт беседу целиком, остальное —
    метрики и журнал событий. Отвечаем «принято», чтобы agy не повторял.
    """
    if c.get("block_analytics", True) is False:
        return False
    host, path = flow.request.host, flow.request.path
    hit = any(path.startswith(p) for p in c.get("analytics_paths") or []) \
        or host in (c.get("analytics_hosts") or [])
    if not hit:
        return False
    if host.endswith("cloudcode-pa.googleapis.com"):
        flow.response = http.Response.make(200, b"{}", {"Content-Type": "application/json"})
    else:
        flow.response = http.Response.make(200, b"", {"Content-Type": "text/plain"})
    if c.get("log", True):
        log("заблокировано: %s%s, %d байт" % (host, path.split("?")[0], len(flow.request.content or b"")))
    return True


def request(flow: http.HTTPFlow):
    c = conf()
    if c.get("log_hosts"):
        log("REQ %s %s %d" % (flow.request.host, flow.request.path[:80], len(flow.request.content or b"")))
    if block_analytics(flow, c):
        return
    if not is_generate(flow):
        return
    try:
        body = json.loads(flow.request.content or b"{}")
    except Exception:
        return
    req = body.get("request")
    if not isinstance(req, dict):
        return
    sys_old = system_text(req)
    if "<identity>" not in sys_old:
        return  # не ход агента — служебный вызов, пропускаем как есть

    if c.get("dump"):
        dump(flow, "in.json", flow.request.content)
    # Решает шлюз — для каждого ключа свой выключатель (AgyPrompt, команда
    # agy-prompt, /agy в Gemini Desktop) — и оставляет пометку в GEMINI.md,
    # а agy кладёт её в свой промпт. strip_prompt — принудительно для всего
    # трафика agy, даже не от шлюза. Иначе промпт agy идёт как есть.
    if MARKER not in sys_old and c.get("strip_prompt") is not True:
        return
    before = len(flow.request.content or b"")

    keep = c.get("keep_tools")
    tools_left = None if keep is None else filter_tools(req, keep)

    # Своя строка вместо промпта агента — только при инструментах: без неё
    # модель изредка кончает ход пустым ответом после вызова инструмента,
    # и agy ждёт продолжения до таймаута.
    rules = [r.replace(MARKER, "").strip() for r in RULE_RE.findall(sys_old)]
    rules = [r for r in rules if r]
    base = (c.get("base_system") or "").strip() if req.get("tools") else ""
    sys_new = "\n\n".join(([base] if base else []) + rules)
    if sys_new:
        req["systemInstruction"] = {"role": "user", "parts": [{"text": sys_new}]}
    else:
        req.pop("systemInstruction", None)

    if c.get("unwrap_user", True):
        for msg in req.get("contents") or []:
            if msg.get("role") != "user":
                continue
            for part in msg.get("parts") or []:
                text = part.get("text")
                if isinstance(text, str) and ("<USER_REQUEST>" in text or "<ADDITIONAL_METADATA>" in text):
                    part["text"] = unwrap(text)

    flow.request.content = json.dumps(body, ensure_ascii=False).encode("utf-8")
    if c.get("dump"):
        dump(flow, "out.json", flow.request.content)
    if c.get("log", True):
        log("%s: %d -> %d байт, системное: %s, инструментов: %s" % (
            body.get("model", "?"), before, len(flow.request.content),
            ("%d зн." % len(sys_new)) if sys_new else "нет",
            "все" if tools_left is None else tools_left))


def responseheaders(flow: http.HTTPFlow):
    # Ответ модели — поток SSE. Без этого mitmproxy копит его целиком, и текст
    # доходит до agy разом в конце. При dump копим — чтобы было что сохранить.
    c = conf()
    if is_generate(flow) and c.get("stream", True) and not c.get("dump"):
        flow.response.stream = True


def response(flow: http.HTTPFlow):
    if is_generate(flow) and conf().get("dump"):
        dump(flow, "resp.txt", flow.response.content)
