$ErrorActionPreference = 'Stop'
function Test-TicketingPort([int] $Port, [string] $HostName = '127.0.0.1') {
    $client = [Net.Sockets.TcpClient]::new()
    try {
        $pending = $client.ConnectAsync($HostName, $Port)
        return ($pending.Wait(1000) -and $client.Connected)
    } catch { return $false }
    finally { $client.Dispose() }
}
$ticketingRoot = Split-Path $PSScriptRoot -Parent
Set-Location $ticketingRoot
. (Join-Path $PSScriptRoot 'use-php.ps1')
$ticketingRuntime = Join-Path $ticketingRoot '.runtime'
$ticketingEnv = Get-Content (Join-Path $ticketingRoot '.env')
function Get-TicketingSetting([string] $Name, [string] $Default) {
    $setting = $ticketingEnv | Where-Object { $_ -match ('^' + [regex]::Escape($Name) + '=') } | Select-Object -Last 1
    if (-not $setting) { return $Default }
    return ($setting -split '=', 2)[1].Trim().Trim('"').Trim("'")
}
$ticketingAppUrl = Get-TicketingSetting 'APP_URL' 'http://192.168.100.51:8001'
$ticketingFrontendUrl = Get-TicketingSetting 'FRONTEND_URL' 'http://192.168.100.51:5001'
$ticketingAppUri = [uri] $ticketingAppUrl
$ticketingFrontendUri = [uri] $ticketingFrontendUrl
$ticketingReverbHost = Get-TicketingSetting 'REVERB_HOST' $ticketingAppUri.Host
$ticketingReverbPort = [int] (Get-TicketingSetting 'REVERB_PORT' '8081')
$ticketingDatabaseHost = Get-TicketingSetting 'DB_HOST' '127.0.0.1'
$ticketingDatabasePort = [int] (Get-TicketingSetting 'DB_PORT' '3306')
$ticketingMysql = Join-Path $ticketingRuntime 'mysql/mysql-8.4.11-winx64/bin/mysqld.exe'
if ($ticketingDatabasePort -eq 3307 -and $ticketingDatabaseHost -in @('127.0.0.1', 'localhost') -and -not (Test-TicketingPort 3307)) {
    if (-not (Test-Path $ticketingMysql)) {
        throw 'Local MySQL runtime is missing. Follow README.md for database setup.'
    }
    Start-Process -FilePath $ticketingMysql -ArgumentList "--defaults-file=`"$ticketingRuntime/my.ini`"" -WindowStyle Hidden | Out-Null
    $ticketingReady = $false
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        if (Test-TicketingPort 3307) {
            $ticketingReady = $true
            break
        }
        Start-Sleep -Seconds 1
    }
    if (-not $ticketingReady) { throw 'MySQL did not start. Check .runtime/mysql-error.log.' }
}
& php artisan config:clear --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'Could not refresh Laravel configuration.' }
& php artisan migrate --force
if ($LASTEXITCODE -ne 0) { throw 'Database connection or migration failed.' }
if (-not (Test-TicketingPort $ticketingAppUri.Port $ticketingAppUri.Host)) {
    Start-Process -FilePath (Join-Path $ticketingRuntime 'php-8.5.10/php.exe') -ArgumentList "artisan serve --host=0.0.0.0 --port=$($ticketingAppUri.Port) --tries=1" -WorkingDirectory $ticketingRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $ticketingRuntime 'web.log') -RedirectStandardError (Join-Path $ticketingRuntime 'web-error.log') | Out-Null
}
Write-Output "Backend API: $ticketingAppUrl"
if (-not (Test-TicketingPort $ticketingFrontendUri.Port $ticketingFrontendUri.Host)) {
    $ticketingNode = (Get-Command node -ErrorAction Stop).Source
    $ticketingVite = Join-Path $ticketingRoot 'frontend-ticketing/node_modules/vite/bin/vite.js'
    if (-not (Test-Path $ticketingVite)) { throw 'Install the Vue frontend dependencies first.' }
    Start-Process -FilePath $ticketingNode -ArgumentList "`"$ticketingVite`" --host=0.0.0.0 --port=$($ticketingFrontendUri.Port) --strictPort" -WorkingDirectory (Join-Path $ticketingRoot 'frontend-ticketing') -WindowStyle Hidden -RedirectStandardOutput (Join-Path $ticketingRuntime 'frontend.log') -RedirectStandardError (Join-Path $ticketingRuntime 'frontend-error.log') | Out-Null
}
Write-Output "Vue frontend: $ticketingFrontendUrl"
if (-not (Test-TicketingPort $ticketingReverbPort $ticketingReverbHost)) {
    $ticketingReverb = Start-Process -FilePath (Join-Path $ticketingRuntime 'php-8.5.10/php.exe') -ArgumentList "artisan reverb:start --host=0.0.0.0 --port=$ticketingReverbPort" -WorkingDirectory $ticketingRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $ticketingRuntime 'reverb.log') -RedirectStandardError (Join-Path $ticketingRuntime 'reverb-error.log') -PassThru
    @{ id = $ticketingReverb.Id; started = $ticketingReverb.StartTime.ToUniversalTime().Ticks } | ConvertTo-Json | Set-Content (Join-Path $ticketingRuntime 'reverb-process.json')
}
Write-Output "Reverb WebSocket: ws://${ticketingReverbHost}:$ticketingReverbPort. Logs: .runtime/reverb.log"
$ticketingSchedulerState = Join-Path $ticketingRuntime 'scheduler.json'
$ticketingSchedulerRunning = $false
if (Test-Path $ticketingSchedulerState) {
    $ticketingSavedScheduler = Get-Content $ticketingSchedulerState -Raw | ConvertFrom-Json
    $ticketingExistingScheduler = Get-Process -Id $ticketingSavedScheduler.id -ErrorAction SilentlyContinue
    if ($ticketingExistingScheduler -and $ticketingExistingScheduler.StartTime.ToUniversalTime().Ticks -eq $ticketingSavedScheduler.started) {
        $ticketingSchedulerRunning = $true
    }
}
if (-not $ticketingSchedulerRunning) {
    $ticketingScheduler = Start-Process -FilePath (Join-Path $ticketingRuntime 'php-8.5.10/php.exe') -ArgumentList 'artisan schedule:work' -WorkingDirectory $ticketingRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $ticketingRuntime 'scheduler.log') -RedirectStandardError (Join-Path $ticketingRuntime 'scheduler-error.log') -PassThru
    @{ id = $ticketingScheduler.Id; started = $ticketingScheduler.StartTime.ToUniversalTime().Ticks } | ConvertTo-Json | Set-Content $ticketingSchedulerState
}
Write-Output 'SLA scheduler: running every minute. Logs: .runtime/scheduler.log'
$ticketingWorkerState = Join-Path $ticketingRuntime 'worker.json'
$ticketingWorkerRunning = $false
if (Test-Path $ticketingWorkerState) {
    $ticketingSavedWorker = Get-Content $ticketingWorkerState -Raw | ConvertFrom-Json
    $ticketingExistingWorker = Get-Process -Id $ticketingSavedWorker.id -ErrorAction SilentlyContinue
    if ($ticketingExistingWorker -and $ticketingExistingWorker.StartTime.ToUniversalTime().Ticks -eq $ticketingSavedWorker.started) {
        $ticketingWorkerRunning = $true
    }
}
if (-not $ticketingWorkerRunning) {
    $ticketingWorker = Start-Process -FilePath (Join-Path $ticketingRuntime 'php-8.5.10/php.exe') -ArgumentList 'artisan queue:work --tries=3 --timeout=90' -WorkingDirectory $ticketingRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $ticketingRuntime 'worker.log') -RedirectStandardError (Join-Path $ticketingRuntime 'worker-error.log') -PassThru
    @{ id = $ticketingWorker.Id; started = $ticketingWorker.StartTime.ToUniversalTime().Ticks } | ConvertTo-Json | Set-Content $ticketingWorkerState
}
Write-Output 'Queue worker: running. Logs: .runtime/worker.log'
