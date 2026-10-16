@echo off
setlocal
cd /d "%~dp0"
title Malasakit Queue

set "WEB_PORT=88"

call "%~dp0_php.bat" || ( pause & exit /b 1 )

if not exist vendor\autoload.php (
    echo Not set up yet. Run setup.bat first.
    pause
    exit /b 1
)

"%PHP%" %PHPX% tools\db.php check || (
    echo.
    echo Fix the MySQL problem above, then run start.bat again.
    pause
    exit /b 1
)

rem ---- make sure nothing else is using port 88
netstat -ano | findstr /c:":%WEB_PORT% " | findstr /c:"LISTENING" >nul && (
    echo [ERROR] Port %WEB_PORT% is already in use by:
    for /f "tokens=5" %%I in ('netstat -ano ^| findstr /c:":%WEB_PORT% " ^| findstr /c:"LISTENING"') do (
        for /f "tokens=1" %%A in ('tasklist /fi "PID eq %%I" /nh') do echo     %%A  PID %%I
    )
    echo If that is Malasakit Queue from an earlier start, run stop.bat first.
    pause
    exit /b 1
)

rem Web server on port 88 (PHP built-in server, same as "artisan serve")
set "ROUTER=%~dp0vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php"
start "Malasakit Queue - WEB :%WEB_PORT%" /min cmd /k "cd /d "%~dp0public" && "%PHP%" %PHPX% -S 0.0.0.0:%WEB_PORT% "%ROUTER%""

for /f "tokens=2 delims==" %%K in ('findstr /b /c:"QUEUE_DEVICE_KEY=" .env') do set "KEY=%%K"

echo ==========================================================
echo   MALASAKIT QUEUE IS RUNNING (port %WEB_PORT%)
echo   Do not close the minimized server window.
echo.
echo   On this PC : http://localhost:%WEB_PORT%/display
for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /c:"IPv4"') do for /f "tokens=*" %%b in ("%%a") do (
    echo.
    echo   Kiosk   : http://%%b:%WEB_PORT%/kiosk?key=%KEY%
    echo   TV      : http://%%b:%WEB_PORT%/display
    echo   Counter : http://%%b:%WEB_PORT%/login
)
echo ==========================================================
timeout /t 3 >nul
start "" "http://localhost:%WEB_PORT%/display"
pause
