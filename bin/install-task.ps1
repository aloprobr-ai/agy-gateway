<#
  Автозапуск шлюза вместе с входом в Windows.

      powershell -ExecutionPolicy Bypass -File bin\install-task.ps1
      powershell -ExecutionPolicy Bypass -File bin\install-task.ps1 -Remove

  Заводит задачу в планировщике, которая поднимает шлюз при входе в систему
  и держит его окном без рамки — в панели задач он не мозолит глаза.

  Задача создаётся от вашего имени и только для вас: agy хранит авторизацию
  в вашем домашнем каталоге, и из-под другой учётной записи он не войдёт.
  Прав администратора не требуется.
#>
param(
    [int]$Port = 8080,
    [string]$Listen = "127.0.0.1",
    [switch]$Remove
)

$ErrorActionPreference = "Stop"
$taskName = "AgyGateway"
$root = Split-Path -Parent $PSScriptRoot

if ($Remove) {
    if (Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue) {
        Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
        Write-Host "Задача «$taskName» удалена. Шлюз больше не поднимается при входе." -ForegroundColor Green
    } else {
        Write-Host "Задачи «$taskName» и не было."
    }
    exit 0
}

$php = (Get-Command php -ErrorAction SilentlyContinue).Source
if (-not $php) {
    Write-Host "PHP не найден — сначала поставьте его и добавьте в PATH." -ForegroundColor Red
    exit 1
}

# Берём php.exe без консольного окна, чтобы при каждом входе не выскакивало
# чёрное окно. Оно всё равно ничего не показывает: логи пишутся в logs\.
$phpw = Join-Path (Split-Path -Parent $php) "php-win.exe"
$exe = if (Test-Path $phpw) { $phpw } else { $php }

$args = @(
    "-S", "${Listen}:${Port}",
    "-t", (Join-Path $root "public"),
    (Join-Path $root "public\router.php")
)

$action = New-ScheduledTaskAction -Execute $exe -Argument ($args -join " ") -WorkingDirectory $root
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -ExecutionTimeLimit ([TimeSpan]::Zero)

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Force | Out-Null

Write-Host "Готово. Шлюз будет подниматься при входе в Windows." -ForegroundColor Green
Write-Host "  адрес для приложения:  http://${Listen}:${Port}/v1"
Write-Host "  запустить прямо сейчас: Start-ScheduledTask -TaskName $taskName"
Write-Host "  убрать из автозапуска:  powershell -File bin\install-task.ps1 -Remove"
