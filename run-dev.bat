@echo off
setlocal

set "PROJECT_DIR=%~dp0"
set "PHP_EXE=%PROJECT_DIR%.runtime\php-8.5.10\php.exe"
set "FRONTEND_DIR=%PROJECT_DIR%frontend-ticketing"
cd /d "%PROJECT_DIR%"

if /i "%~1"=="firewall" goto firewall

if not exist "%PHP_EXE%" (
    echo PHP 8.5.10 was not found at:
    echo %PHP_EXE%
    pause
    exit /b 1
)

"%PHP_EXE%" artisan config:clear --no-interaction
if errorlevel 1 (
    echo Could not refresh Laravel configuration.
    pause
    exit /b 1
)

if /i "%~1"=="backend" goto backend
if /i "%~1"=="frontend" goto frontend
if /i "%~1"=="reverb" goto reverb
if /i "%~1"=="scheduler" goto scheduler
if /i "%~1"=="worker" goto worker

if not exist "%FRONTEND_DIR%\package.json" (
    echo Vue frontend was not found at:
    echo %FRONTEND_DIR%
    pause
    exit /b 1
)

where npm.cmd >nul 2>&1
if not errorlevel 1 set "FRONTEND_PM=npm"
if not defined FRONTEND_PM (
    where pnpm.cmd >nul 2>&1
    if not errorlevel 1 set "FRONTEND_PM=pnpm"
)
if not defined FRONTEND_PM (
    echo Could not find npm or pnpm. Install Node.js and the frontend dependencies first.
    pause
    exit /b 1
)

start "Laravel API - port 8001" /D "%PROJECT_DIR%" "%PHP_EXE%" artisan serve --host=0.0.0.0 --port=8001 --tries=1
start "Vue frontend - port 5001" /D "%FRONTEND_DIR%" "%ComSpec%" /k "%FRONTEND_PM% run dev"
start "Laravel Reverb - port 8081" /D "%PROJECT_DIR%" "%PHP_EXE%" artisan reverb:start --host=0.0.0.0 --port=8081
start "Laravel Scheduler" /D "%PROJECT_DIR%" "%PHP_EXE%" artisan schedule:work
start "Laravel Queue Worker" /D "%PROJECT_DIR%" "%PHP_EXE%" artisan queue:work --tries=3 --timeout=90

for /f "usebackq tokens=1,* delims==" %%A in ("%PROJECT_DIR%.env") do (
    if "%%A"=="APP_URL" set "APP_ADDRESS=%%B"
    if "%%A"=="FRONTEND_URL" set "FRONTEND_ADDRESS=%%B"
    if "%%A"=="REVERB_HOST" set "REVERB_ADDRESS=%%B"
)
echo Frontend: %FRONTEND_ADDRESS%
echo Backend:  %APP_ADDRESS%
echo Reverb:   ws://%REVERB_ADDRESS%:8081
echo Open the frontend address from a device on the same network.
exit /b 0

:backend
cd /d "%PROJECT_DIR%"
echo Starting Laravel API on port 8001...
"%PHP_EXE%" artisan serve --host=0.0.0.0 --port=8001 --tries=1
pause
exit /b

:frontend
cd /d "%PROJECT_DIR%frontend-ticketing"
echo Starting Vue frontend...
where npm.cmd >nul 2>&1
if not errorlevel 1 goto frontend_npm
where pnpm.cmd >nul 2>&1
if not errorlevel 1 goto frontend_pnpm
echo Could not find npm or pnpm. Install Node.js and the frontend dependencies first.
pause
exit /b

:frontend_npm
call npm run dev -- --host 0.0.0.0 --port 5001 --strictPort
pause
exit /b

:frontend_pnpm
call pnpm run dev --host 0.0.0.0 --port 5001 --strictPort
pause
exit /b

:reverb
cd /d "%PROJECT_DIR%"
echo Starting Laravel Reverb on port 8081...
"%PHP_EXE%" artisan reverb:start --host=0.0.0.0 --port=8081
pause
exit /b

:scheduler
cd /d "%PROJECT_DIR%"
"%PHP_EXE%" artisan schedule:work
pause
exit /b

:worker
cd /d "%PROJECT_DIR%"
"%PHP_EXE%" artisan queue:work --tries=3 --timeout=90
pause
exit /b

:firewall
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%PROJECT_DIR%scripts\allow-lan.ps1"
pause
exit /b
