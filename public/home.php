<?php
/**
 * Главная страница шлюза. Подключается из index.php, поэтому здесь доступны
 * $cfg (конфиг) и уже загруженные классы. Список моделей берётся из каталога
 * storage/models.json, который обновляет bin/update_models.php.
 */
$backend = ($cfg['backend'] ?? 'api') === 'cli' ? 'cli' : 'api';
$baseUrl = Http::baseUrl($cfg);
$host = preg_replace('~^https?://~', '', $baseUrl);

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// Модели текущего бэкенда: id -> человекочитаемое имя.
$models = [];
if ($backend === 'cli') {
    $display = ModelCatalog::cliDisplay();
    foreach (ModelCatalog::cli() as $id) {
        $models[(string) $id] = (string) ($display[$id] ?? '');
    }
} else {
    foreach (ModelCatalog::api() as $id => $info) {
        if (in_array('generateContent', (array) ($info['methods'] ?? []), true)) {
            $models[(string) $id] = (string) ($info['display_name'] ?? '');
        }
    }
}

// Алиасы: имя для клиента -> реальная модель.
$aliases = $backend === 'cli'
    ? (array) ($cfg['cli']['model_map'] ?? [])
    : (array) ($cfg['model_map'] ?? []);
$aliases = array_filter($aliases, static fn($target) => (string) $target !== '');

$defaultModel = $backend === 'cli'
    ? (string) ($cfg['cli']['default_model'] ?? '')
    : (string) ($cfg['default_model'] ?? '');

$catalogAt = ModelCatalog::generatedAt();
$sampleModel = array_key_first($aliases) ?: (array_key_first($models) ?: 'agy');
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Gemini API Gateway</title>
<style>
  :root { color-scheme: dark; }
  body { margin:0; background:#0e1116; color:#d7dee8; font:15px/1.6 -apple-system,Segoe UI,Roboto,Arial,sans-serif; }
  .wrap { max-width:820px; margin:0 auto; padding:48px 20px 80px; }
  h1 { font-size:26px; margin:0 0 6px; color:#fff; }
  h2 { font-size:16px; margin:34px 0 10px; color:#8ab4f8; text-transform:uppercase; letter-spacing:.06em; }
  p.lead { color:#93a0b1; margin:0 0 28px; }
  code, pre { font-family:ui-monospace,SFMono-Regular,Consolas,monospace; font-size:13px; }
  pre { background:#161b22; border:1px solid #232a33; border-radius:8px; padding:14px 16px; overflow-x:auto; }
  code.inline { background:#161b22; border:1px solid #232a33; border-radius:4px; padding:1px 5px; }
  table { border-collapse:collapse; width:100%; }
  td, th { border-bottom:1px solid #232a33; padding:8px 6px; text-align:left; vertical-align:top; }
  th { color:#93a0b1; font-weight:600; font-size:13px; }
  .m { color:#7ee787; }
  .def { color:#7ee787; font-size:12px; margin-left:6px; }
  .note { color:#93a0b1; font-size:13px; }
  footer { margin-top:44px; color:#5d6b7c; font-size:13px; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Gemini API Gateway</h1>
  <p class="lead">OpenAI-совместимый API поверх Google Gemini. Работает с любым клиентом, который умеет ходить в OpenAI: достаточно подменить <code class="inline">base_url</code>.</p>

  <h2>Подключение</h2>
  <pre>base_url: <?= $e($baseUrl) ?>/v1
api_key:  ваш ключ, выданный владельцем сервиса</pre>

  <h2>Продолжение диалога</h2>
  <p>Сервис может держать диалог на своей стороне. Чтобы запросы попадали в одну беседу,
  передавайте заголовок <code class="inline">X-Session-Id: любая-строка</code> (либо поле
  <code class="inline">user</code> в теле). Без него сессия определяется по первому сообщению диалога.
  В ответе возвращаются <code class="inline">X-Session-Id</code> и <code class="inline">X-Conversation-Id</code>.</p>

  <h2>Доступные модели</h2>
<?php if ($models === [] && $aliases === []): ?>
  <p>Список пока не собран. Обновите каталог командой <code class="inline">php bin/update_models.php</code>.</p>
<?php else: ?>
  <?php if ($models !== []): ?>
  <table>
    <tr><th>Имя для запроса</th><th>Модель</th></tr>
    <?php foreach ($models as $id => $label): ?>
    <tr>
      <td><code class="inline"><?= $e($id) ?></code><?= $id === $defaultModel ? ' <span class="def">по умолчанию</span>' : '' ?></td>
      <td><?= $e($label !== '' ? $label : '—') ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>

  <?php if ($aliases !== []): ?>
  <h2>Короткие имена</h2>
  <p class="note">Удобные псевдонимы и совместимость с клиентами, где имя модели зашито намертво.</p>
  <table>
    <tr><th>Алиас</th><th>Ведёт на</th></tr>
    <?php foreach ($aliases as $alias => $target): ?>
    <tr><td><code class="inline"><?= $e($alias) ?></code></td><td><code class="inline"><?= $e($target) ?></code></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>

  <p class="note">
    Список отдаётся и по API: <code class="inline">GET /v1/models</code>.
    <?php if ($catalogAt !== null): ?>Обновлён: <?= $e(date('d.m.Y H:i', strtotime($catalogAt))) ?>.<?php endif; ?>
  </p>
<?php endif; ?>

  <h2>Эндпоинты</h2>
  <table>
    <tr><th>Метод</th><th>Путь</th><th>Назначение</th></tr>
    <tr><td class="m">POST</td><td>/v1/chat/completions</td><td>чат, стриминг, картинки на вход, function calling</td></tr>
    <tr><td class="m">POST</td><td>/v1/completions</td><td>устаревший текстовый формат</td></tr>
    <tr><td class="m">POST</td><td>/v1/embeddings</td><td>векторные представления</td></tr>
    <tr><td class="m">POST</td><td>/v1/images/generations</td><td>генерация изображений</td></tr>
    <tr><td class="m">GET</td><td>/v1/models</td><td>список моделей</td></tr>
    <tr><td class="m">GET</td><td>/health</td><td>проверка живости (без ключа)</td></tr>
  </table>

  <h2>Пример: текст</h2>
  <pre>curl <?= $e($baseUrl) ?>/v1/chat/completions \
  -H "Authorization: Bearer $KEY" \
  -H "Content-Type: application/json" \
  -d '{"model":"<?= $e($sampleModel) ?>","messages":[{"role":"user","content":"Привет!"}]}'</pre>

  <h2>Пример: картинка на вход</h2>
  <pre>{
  "model": "<?= $e($sampleModel) ?>",
  "messages": [{
    "role": "user",
    "content": [
      {"type": "text", "text": "Что на фото?"},
      {"type": "image_url", "image_url": {"url": "data:image/jpeg;base64,..."}}
    ]
  }]
}</pre>
  <p>Поддерживаются и <code class="inline">data:</code>-URI, и обычные http(s)-ссылки на изображение.</p>

  <h2>Python (openai SDK)</h2>
  <pre>from openai import OpenAI

client = OpenAI(api_key="ВАШ_КЛЮЧ", base_url="<?= $e($baseUrl) ?>/v1")
r = client.chat.completions.create(
    model="<?= $e($sampleModel) ?>",
    messages=[{"role": "user", "content": "Привет"}],
)
print(r.choices[0].message.content)</pre>

  <footer>Доступ по ключу. Все запросы логируются.</footer>
</div>
</body>
</html>
