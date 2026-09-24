<#
  Запуск шлюза на этом компьютере (Windows).

      powershell -ExecutionPolicy Bypass -File bin\serve.ps1
      powershell -ExecutionPolicy Bypass -File bin\serve.ps1 -Port 9000 -Host 0.0.0.0

  Это встроенный сервер PHP: он обслуживает один запрос за раз, и этого хватает,
  когда шлюзом пользуется один человек. Если к нему будут ходить несколько
  клиентов сразу — ставьте nginx или IIS с PHP-FPM, см. README.
#>
param(
    [int]$Port = 8080,
    [string]$Listen = "127.0.0.1"
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot

# --- PHP на месте?
$php = (Get-Command php -ErrorAction SilentlyContinue).Source
if (-not $php) {
    Write-Host "PHP не найден." -ForegroundColor Red
    Write-Host "Скачайте сборку для Windows (Non Thread Safe, x64) с https://windows.php.net/download/"
    Write-Host "и добавьте папку с php.exe в PATH."
    exit 1
}

# --- config.php: без него шлюз не поднимется, поэтому заводим сразу
$config = Join-Path $root "config.php"
if (-not (Test-Path $config)) {
    Copy-Item (Join-Path $root "config.example.php") $config
    Write-Host "Создан config.php из образца — впишите туда свой ключ." -ForegroundColor Yellow
}

# --- пароль страницы /keys: если его ещё нет, придумываем и показываем один раз.
# Не вышло записать — шлюз всё равно поднимаем, выдачу ключей можно наладить потом.
& $php (Join-Path $root "bin\admin-token.php") --quiet

# --- расширения, без которых часть шлюза работать не будет
$missing = @()
foreach ($ext in @("curl", "mbstring", "openssl")) {
    $has = & $php -r "echo extension_loaded('$ext') ? '1' : '0';"
    if ($has -ne "1") { $missing += $ext }
}
if ($missing.Count -gt 0) {
    Write-Host "В php.ini не включены расширения: $($missing -join ', ')" -ForegroundColor Yellow
    Write-Host "Чат через CLI будет работать, а вот ключи Google, перевод и картинки — нет."
    Write-Host "Как включить: php bin\doctor.php"
}

New-Item -ItemType Directory -Force -Path (Join-Path $root "logs"), (Join-Path $root "storage") | Out-Null

Write-Host ""
Write-Host "Шлюз слушает http://${Listen}:${Port}" -ForegroundColor Green
Write-Host "  адрес для приложения:  http://${Listen}:${Port}/v1"
Write-Host "  проверка:              http://${Listen}:${Port}/health"
Write-Host "  ключи:                 http://${Listen}:${Port}/keys"
Write-Host "Остановить — Ctrl+C."
Write-Host ""

& $php -S "${Listen}:${Port}" -t (Join-Path $root "public") (Join-Path $root "public\router.php")
