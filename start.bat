@echo off
setlocal
cd /d "%~dp0"
title Malasakit Queue

set "WEB_PORT=88"

call "%~dp0_php.bat" || ( pause & exit /b 1 )

if not exist vendor\autoload.php (
    echo Hindi pa naka-setup. Patakbuhin muna ang setup.bat
    pause
    exit /b 1
)

"%MYSQL%" -u root -e "SELECT 1" >nul 2>&1 || (
    echo [!] Mukhang hindi naka-START ang MySQL sa XAMPP. I-start muna.
    pause
)

rem ---- siguraduhing walang ibang app sa port 88
netstat -ano | findstr /c:":%WEB_PORT% " | findstr /c:"LISTENING" >nul && (
    echo [ERROR] May gumagamit na ng port %WEB_PORT%:
    for /f "tokens=5" %%I in ('netstat -ano ^| findstr /c:":%WEB_PORT% " ^| findstr /c:"LISTENING"') do (
        for /f "tokens=1" %%A in ('tasklist /fi "PID eq %%I" /nh') do echo     %%A  PID %%I
    )
    echo Kung Malasakit Queue iyan mula sa naunang start, patakbuhin muna ang stop.bat
    pause
    exit /b 1
)

rem Web server sa port 88 (PHP built-in server, katumbas ng "artisan serve")
set "ROUTER=%~dp0vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php"
start "Malasakit Queue - WEB :%WEB_PORT%" /min cmd /k "cd /d "%~dp0public" && "%PHP%" %PHPX% -S 0.0.0.0:%WEB_PORT% "%ROUTER%""

for /f "tokens=2 delims==" %%K in ('findstr /b /c:"QUEUE_DEVICE_KEY=" .env') do set "KEY=%%K"

echo ==========================================================
echo   MALASAKIT QUEUE - TUMATAKBO NA (port %WEB_PORT%)
echo   Huwag isara ang naka-minimize na window.
echo.
echo   Sa PC na ito : http://localhost:%WEB_PORT%/display
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
