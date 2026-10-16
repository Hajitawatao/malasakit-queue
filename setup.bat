@echo off
setlocal
cd /d "%~dp0"
title Malasakit Queue - First-time Setup

echo ==========================================================
echo   MALASAKIT QUEUEING SYSTEM - SETUP (isang beses lang)
echo   Siguraduhing naka-START ang MySQL sa XAMPP Control Panel.
echo ==========================================================
echo.

call "%~dp0_php.bat" || goto :fail

rem ---- 0. Config file ----
if not exist .env (
    copy /y .env.example .env >nul
    echo [OK] Ginawa ang .env mula sa .env.example
)
echo [OK] PHP:
"%PHP%" -r "echo '     '.PHP_VERSION.PHP_EOL;"
if defined PHPX echo [i]  Extra extensions na ilo-load:%PHPX%

rem ---- 1. MySQL ----
"%MYSQL%" -u root -e "SELECT 1" >nul 2>&1
if errorlevel 1 (
    echo [ERROR] Hindi maka-connect sa MySQL. I-START ang MySQL sa XAMPP, saka ulitin.
    echo         Kung may password ang root, ilagay sa DB_PASSWORD sa .env at gawin ang database sa phpMyAdmin.
    goto :fail
)
"%MYSQL%" -u root -e "CREATE DATABASE IF NOT EXISTS malasakit_queue CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
echo [OK] Database malasakit_queue

rem ---- 2. Composer + PHP packages (kailangan ng internet) ----
if not exist composer.phar (
    echo [..] Dina-download ang Composer...
    "%PHP%" %PHPX% -r "copy('https://getcomposer.org/download/latest-stable/composer.phar','composer.phar') or exit(1);"
    if errorlevel 1 ( echo [ERROR] Hindi ma-download ang Composer. May internet ba? & goto :fail )
)
echo [..] Ini-install ang PHP packages (ilang minuto)...
"%PHP%" %PHPX% composer.phar install --no-dev --optimize-autoloader --no-interaction
if errorlevel 1 ( echo [ERROR] Pumalya ang composer install. & goto :fail )
echo [OK] PHP packages

rem ---- 3. App key ----
findstr /b /c:"APP_KEY=base64" .env >nul || "%PHP%" %PHPX% artisan key:generate --force
echo [OK] App key

rem ---- 4. Tables + default accounts ----
"%PHP%" %PHPX% artisan migrate --seed --force
if errorlevel 1 ( echo [ERROR] Pumalya ang migration. & goto :fail )
echo [OK] Database tables at accounts

rem ---- 5. Firewall (kailangan ng "Run as administrator") ----
netsh advfirewall firewall show rule name="Malasakit Queue 88" >nul 2>&1 || (
    netsh advfirewall firewall add rule name="Malasakit Queue 88" dir=in action=allow protocol=TCP localport=88 >nul 2>&1
    netsh advfirewall firewall add rule name="Malasakit Queue 6001" dir=in action=allow protocol=TCP localport=6001 >nul 2>&1
)
netsh advfirewall firewall show rule name="Malasakit Queue 88" >nul 2>&1 && (
    echo [OK] Firewall ports 88 at 6001
) || (
    echo [!]  Hindi nabuksan ang firewall. I-right-click ang setup.bat ^> Run as administrator
    echo      kung kailangang ma-access ng ibang PC.
)

echo.
echo ==========================================================
echo   TAPOS! Patakbuhin ang start.bat
echo   Counter logins (password: changeme):
echo     doh1  dswd1  phic1  pagcor1  admin
echo ==========================================================
pause
exit /b 0

:fail
echo.
echo Setup hindi natapos. I-screenshot ang error sa itaas.
pause
exit /b 1
