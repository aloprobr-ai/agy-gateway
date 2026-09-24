<?php
/**
 * /keys — выдача и отзыв клиентских ключей.
 * Видна только со своего адреса, действия требуют токена.
 */
$rows = Keys::all();
$mine = Keys::isAdminIp($cfg);
$host = preg_replace('~^https?://~', '', Http::baseUrl($cfg));
$notice = $notice ?? null;
$fresh = $fresh ?? null;   // только что выданный ключ — показываем один раз

$when = static function (?string $iso): string {
    if (!$iso) {
        return '—';
    }
    $ts = strtotime($iso);
    return $ts ? date('d.m.Y H:i', $ts) : '—';
};
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ключи — <?= htmlspecialchars((string) $host, ENT_QUOTES, 'UTF-8') ?></title>
<style>
  :root {
    --bg: #131314; --raised: #1e1f20; --border: #2d2f31;
    --text: #e3e3e3; --dim: #9aa0a6; --accent: #8ab4f8; --warn: #f28b82;
    --font: "Segoe UI", system-ui, sans-serif;
    --mono: ui-monospace, Consolas, monospace;
  }
  * { box-sizing: border-box; }
  body { margin: 0; padding: 40px 20px; background: var(--bg); color: var(--text); font: 15px/1.6 var(--font); }
  .wrap { max-width: 820px; margin: 0 auto; }
  h1 { font-size: 26px; font-weight: 500; margin: 0 0 6px; }
  .sub { color: var(--dim); margin: 0 0 26px; }
  .key {
    border: 1px solid var(--border); border-radius: 14px; background: var(--raised);
    padding: 14px 16px; margin-bottom: 10px;
  }
  .key.off { opacity: .55; }
  .key-head { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
  .key-label { font-weight: 500; }
  .key-val { font: 13px var(--mono); color: var(--dim); }
  .key-meta { margin-left: auto; color: var(--dim); font-size: 12.5px; }
  .key-actions { display: flex; gap: 8px; margin-top: 10px; }
  button, .btn {
    padding: 7px 15px; border-radius: 999px; border: 1px solid var(--border);
    background: none; color: var(--text); cursor: pointer; font: 14px var(--font);
  }
  button.primary { background: var(--accent); color: #17181a; border-color: var(--accent); font-weight: 500; }
  button.warn { color: var(--warn); border-color: var(--warn); }
  form.inline { display: inline; }
  .new {
    border: 1px solid var(--accent); border-radius: 14px; padding: 16px;
    margin-bottom: 22px; background: color-mix(in srgb, var(--accent) 10%, transparent);
  }
  .new code { display: block; font: 15px var(--mono); margin: 10px 0; word-break: break-all; }
  .make { border: 1px dashed var(--border); border-radius: 14px; padding: 18px; margin-top: 28px; }
  .make h2 { font-size: 17px; font-weight: 500; margin: 0 0 4px; }
  label { display: block; margin-top: 12px; color: var(--dim); font-size: 13px; }
  input[type=text], input[type=password] {
    width: 100%; margin-top: 5px; padding: 9px 11px; border-radius: 9px;
    border: 1px solid var(--border); background: var(--bg); color: var(--text); font: 14px var(--font);
  }
  .note { padding: 11px 14px; border-radius: 10px; margin-bottom: 18px; }
  .note.ok { background: #1d3a24; color: #b6f0c2; }
  .note.err { background: #3a1d1d; color: #ffb4ab; }
  .empty { color: var(--dim); }
  .hint { display: block; margin-top: 5px; color: var(--dim); font-size: 12.5px; line-height: 1.5; }
  .hint code { font: 12px var(--mono); }
  .opt { color: var(--dim); font-size: 12px; font-weight: 400; }
  .section { font-size: 15px; font-weight: 500; color: var(--dim); margin: 26px 0 10px; }
  .section:first-of-type { margin-top: 0; }
  .key.builtin { border-style: dashed; }
  a { color: var(--accent); }
</style>
</head>
<body>
<div class="wrap">
  <h1>Ключи доступа</h1>
  <p class="sub">Ключи для <code>Authorization: Bearer …</code>. Ключи из config.php здесь не показываются
    и остаются рабочими. <a href="/archive">Выпуски приложения</a></p>

  <?php if ($notice): ?>
    <div class="note <?= $notice['ok'] ? 'ok' : 'err' ?>"><?= htmlspecialchars($notice['text'], ENT_QUOTES) ?></div>
  <?php endif; ?>

  <?php if ($fresh): ?>
    <div class="new">
      <b>Ключ выдан. Скопируйте его сейчас — больше он показан не будет.</b>
      <code><?= htmlspecialchars($fresh, ENT_QUOTES) ?></code>
      <span class="sub">Дальше в списке будет видна только середина в виде точек.</span>
    </div>
  <?php endif; ?>

  <?php if (!$mine): ?>
    <p class="empty">Управление ключами доступно только с доверенного адреса.</p>
  <?php else: ?>

    <?php if (!Keys::hasToken($cfg)): ?>
      <div class="note err">Токен управления ещё не задан, поэтому выдавать и отзывать ключи нельзя.
        Он появляется при первом запуске <code>bin/serve.sh</code> или <code>bin/serve.ps1</code>,
        либо выполните <code>php bin/admin-token.php</code>.</div>
    <?php endif; ?>

    <h2 class="section">Выданные здесь</h2>
    <?php if (!$rows): ?>
      <p class="empty">Пока ни одного. Форма выдачи внизу страницы.</p>
    <?php endif; ?>

    <?php foreach ($rows as $r): $k = (string) $r['key']; ?>
      <div class="key<?= !empty($r['disabled']) ? ' off' : '' ?>">
        <div class="key-head">
          <span class="key-label"><?= htmlspecialchars((string) $r['label'], ENT_QUOTES) ?></span>
          <span class="key-val"><?= htmlspecialchars(Keys::mask($k), ENT_QUOTES) ?></span>
          <span class="key-meta">
            создан <?= $when($r['createdAt'] ?? null) ?> ·
            <?= !empty($r['lastUsedAt']) ? 'был в деле ' . $when($r['lastUsedAt']) : 'ни разу не использован' ?>
            <?= !empty($r['disabled']) ? ' · отключён' : '' ?>
          </span>
        </div>
        <div class="key-actions">
          <form class="inline" method="post" action="/keys/toggle">
            <input type="hidden" name="key" value="<?= htmlspecialchars($k, ENT_QUOTES) ?>">
            <input type="hidden" name="disabled" value="<?= !empty($r['disabled']) ? '0' : '1' ?>">
            <input type="password" name="token" placeholder="токен" required autocomplete="off"
                   style="width:150px;display:inline-block;margin:0 6px 0 0;padding:6px 10px">
            <button type="submit"><?= !empty($r['disabled']) ? 'Включить' : 'Отключить' ?></button>
          </form>
          <form class="inline" method="post" action="/keys/delete"
                onsubmit="return confirm('Удалить ключ насовсем?')">
            <input type="hidden" name="key" value="<?= htmlspecialchars($k, ENT_QUOTES) ?>">
            <input type="password" name="token" placeholder="токен" required autocomplete="off"
                   style="width:150px;display:inline-block;margin:0 6px 0 0;padding:6px 10px">
            <button type="submit" class="warn">Удалить</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>

    <?php $builtin = Keys::builtin($cfg); ?>
    <?php if ($builtin): ?>
      <h2 class="section">Прописанные в config.php</h2>
      <p class="hint" style="margin:-4px 0 12px">Работают всегда, пока строка есть в файле, поэтому отключить
        их отсюда нельзя — кнопка врала бы. Перенос оставляет ключ прежним и убирает строку из конфига,
        после чего его можно отключать и удалять как обычный.</p>
      <?php foreach ($builtin as $r): $k = (string) $r['key']; ?>
        <div class="key builtin">
          <div class="key-head">
            <span class="key-label"><?= htmlspecialchars((string) $r['label'], ENT_QUOTES) ?></span>
            <span class="key-val"><?= htmlspecialchars(Keys::mask($k), ENT_QUOTES) ?></span>
            <span class="key-meta">
              из config.php ·
              <?= !empty($r['lastUsedAt']) ? 'был в деле ' . $when($r['lastUsedAt']) : 'ни разу не использован' ?>
            </span>
          </div>
          <div class="key-actions">
            <form class="inline" method="post" action="/keys/adopt">
              <input type="hidden" name="key" value="<?= htmlspecialchars($k, ENT_QUOTES) ?>">
              <input type="password" name="token" placeholder="токен" required autocomplete="off"
                     style="width:150px;display:inline-block;margin:0 6px 0 0;padding:6px 10px">
              <button type="submit">Перенести сюда</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <form class="make" method="post" action="/keys/create">
      <h2>Выдать новый ключ</h2>
      <p class="sub" style="margin:0">Ключ показывается один раз, сразу после создания.</p>
      <label>Кому или для чего
        <input type="text" name="label" placeholder="ноутбук, телефон, бот в телеграме" required>
        <span class="hint">Просто пометка для списка, на доступ не влияет.</span>
      </label>
      <label>Свой ключ <span class="opt">не обязательно</span>
        <input type="text" name="custom" placeholder="оставьте пустым — придумаю сам" autocomplete="off">
        <span class="hint">Если хочется запоминающийся: латиница, цифры, точка, дефис, подчёркивание,
          от 8 до 80 знаков. Например <code>sk-stas-1234</code>.</span>
      </label>
      <label>Токен управления
        <input type="password" name="token" required autocomplete="off">
        <span class="hint"><b>Это не тот ключ, который мы создаём.</b> Это пароль самой страницы —
          строка <code>admin.token</code> из <code>config.php</code>; если её придумал шлюз, она
          начинается с <code>tk-</code>. Показать: <code>php bin/admin-token.php --show</code>.
          У выпусков на <a href="/archive">/archive</a> токен свой — <code>releases.publish_token</code>.</span>
      </label>
      <p><button type="submit" class="primary">Создать</button></p>
    </form>

  <?php endif; ?>
</div>
</body>
</html>
