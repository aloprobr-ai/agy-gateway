<?php
/**
 * /archive — список выпусков Gemini Desktop.
 * Со своего адреса тут же форма выкладывания новой версии.
 */
$releases = Releases::all();
$mine = Releases::isPublisherIp($cfg);
$notice = $notice ?? null;

$human = static function (int $bytes): string {
    if ($bytes <= 0) {
        return '—';
    }
    $mb = $bytes / 1048576;
    return $mb >= 1 ? number_format($mb, 1, ',', ' ') . ' МБ'
                    : number_format($bytes / 1024, 0, ',', ' ') . ' КБ';
};
$when = static function (string $iso): string {
    $ts = strtotime($iso);
    return $ts ? date('d.m.Y H:i', $ts) : '—';
};
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gemini Desktop — выпуски</title>
<style>
  :root {
    --bg: #131314; --raised: #1e1f20; --border: #2d2f31;
    --text: #e3e3e3; --dim: #9aa0a6; --accent: #8ab4f8; --warn: #f28b82;
    --font: "Segoe UI", system-ui, sans-serif;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; padding: 40px 20px; background: var(--bg); color: var(--text);
    font: 15px/1.6 var(--font);
  }
  .wrap { max-width: 860px; margin: 0 auto; }
  h1 { font-size: 26px; font-weight: 500; margin: 0 0 6px; }
  .sub { color: var(--dim); margin: 0 0 28px; }
  .rel {
    border: 1px solid var(--border); border-radius: 14px; background: var(--raised);
    padding: 16px 18px; margin-bottom: 12px;
  }
  .rel.newest { border-color: var(--accent); }
  .rel-head { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; }
  .ver { font-size: 19px; font-weight: 500; }
  .tag {
    font-size: 12px; padding: 2px 9px; border-radius: 999px;
    border: 1px solid var(--border); color: var(--dim);
  }
  .tag.now { border-color: var(--accent); color: var(--accent); }
  .tag.imp { border-color: var(--warn); color: var(--warn); }
  .meta { margin-left: auto; color: var(--dim); font-size: 13px; }
  .notes { color: var(--dim); margin: 8px 0 0; white-space: pre-wrap; }
  .sum { color: var(--dim); font-size: 12px; margin-top: 8px; word-break: break-all; }
  a.dl {
    display: inline-block; margin-top: 12px; padding: 8px 16px; border-radius: 999px;
    background: var(--accent); color: #17181a; text-decoration: none; font-weight: 500;
  }
  .empty { color: var(--dim); }
  form.pub {
    border: 1px dashed var(--border); border-radius: 14px; padding: 18px;
    margin-top: 32px;
  }
  form.pub h2 { font-size: 17px; font-weight: 500; margin: 0 0 4px; }
  label { display: block; margin-top: 12px; color: var(--dim); font-size: 13px; }
  input[type=text], input[type=password], textarea, input[type=file] {
    width: 100%; margin-top: 5px; padding: 9px 11px; border-radius: 9px;
    border: 1px solid var(--border); background: var(--bg); color: var(--text);
    font: 14px var(--font);
  }
  textarea { min-height: 76px; resize: vertical; }
  .row { display: flex; gap: 14px; align-items: center; margin-top: 14px; }
  button {
    padding: 9px 20px; border-radius: 999px; border: none; cursor: pointer;
    background: var(--accent); color: #17181a; font: 500 14px var(--font);
  }
  .note { padding: 11px 14px; border-radius: 10px; margin-bottom: 18px; }
  .note.ok { background: #1d3a24; color: #b6f0c2; }
  .note.err { background: #3a1d1d; color: #ffb4ab; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Gemini Desktop</h1>
  <p class="sub">Все выпуски. Приложение само проверяет обновления при запуске.</p>

  <?php if ($notice): ?>
    <div class="note <?= $notice['ok'] ? 'ok' : 'err' ?>"><?= htmlspecialchars($notice['text'], ENT_QUOTES) ?></div>
  <?php endif; ?>

  <?php if (!$releases): ?>
    <p class="empty">Пока ни одной версии не выложено.</p>
  <?php endif; ?>

  <?php foreach ($releases as $i => $r): ?>
    <div class="rel<?= $i === 0 ? ' newest' : '' ?>">
      <div class="rel-head">
        <span class="ver"><?= htmlspecialchars((string) $r['version'], ENT_QUOTES) ?></span>
        <?php if ($i === 0): ?><span class="tag now">текущая</span><?php endif; ?>
        <?php if (!empty($r['important'])): ?><span class="tag imp">важное</span><?php endif; ?>
        <span class="meta"><?= $when((string) ($r['publishedAt'] ?? '')) ?> · <?= $human((int) ($r['size'] ?? 0)) ?></span>
      </div>
      <?php if (!empty($r['notes'])): ?>
        <p class="notes"><?= htmlspecialchars((string) $r['notes'], ENT_QUOTES) ?></p>
      <?php endif; ?>
      <div class="sum">SHA-256: <?= htmlspecialchars((string) ($r['sha256'] ?? ''), ENT_QUOTES) ?></div>
      <a class="dl" href="/up/download/<?= rawurlencode((string) $r['version']) ?>">Скачать .msi</a>
    </div>
  <?php endforeach; ?>

  <?php if ($mine): ?>
    <form class="pub" method="post" action="/archive/publish" enctype="multipart/form-data">
      <h2>Выложить новую версию</h2>
      <p class="sub" style="margin:0">Видно только с вашего адреса. Нужен ещё токен из config.php.</p>
      <label>Версия
        <input type="text" name="version" placeholder="1.0.1" required pattern="\d+\.\d+\.\d+">
      </label>
      <label>Что изменилось
        <textarea name="notes" placeholder="Коротко, это увидит пользователь в окне обновления"></textarea>
      </label>
      <label>Файл установщика (.msi)
        <input type="file" name="msi" accept=".msi" required>
      </label>
      <label>Токен публикации
        <input type="password" name="token" required autocomplete="off">
      </label>
      <div class="row">
        <label style="margin:0"><input type="checkbox" name="important" value="1"> важное обновление</label>
        <button type="submit">Выложить</button>
      </div>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
