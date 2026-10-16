@echo off
setlocal
cd /d "%~dp0"
title Malasakit Queue - First-time Setup

echo ==========================================================
echo   MALASAKIT QUEUEING SYSTEM - SETUP (run once)
echo   Make sure MySQL is STARTED in the XAMPP Control Panel.
echo ==========================================================
echo.

call "%~dp0_php.bat" || goto :fail

rem ---- 0. Config file ----
if not exist .env (
    copy /y .env.example .env >nul
    echo [OK] Created .env from .env.example
)
echo [OK] PHP:
"%PHP%" -r "echo '     '.PHP_VERSION.PHP_EOL;"
if defined PHPX echo [i]  Extra PHP flags:%PHPX%

rem ---- 1. MySQL (uses DB_* from .env) ----
"%PHP%" %PHPX% tools\db.php create
if errorlevel 1 goto :fail

rem ---- 2. Composer + PHP packages (needs internet) ----
if not exist composer.phar (
    echo [..] Downloading Composer...
    "%PHP%" %PHPX% -r "copy('https://getcomposer.org/download/latest-stable/composer.phar','composer.phar') or exit(1);"
    if errorlevel 1 ( echo [ERROR] Could not download Composer. Is this PC online? & goto :fail )
)
echo [..] Installing PHP packages (this takes a few minutes)...
"%PHP%" %PHPX% composer.phar install --no-dev --optimize-autoloader --no-interaction
if errorlevel 1 ( echo [ERROR] composer install failed. & goto :fail )
echo [OK] PHP packages

rem ---- 3. App key ----
findstr /b /c:"APP_KEY=base64" .env >nul || "%PHP%" %PHPX% artisan key:generate --force
echo [OK] App key

rem ---- 4. Tables + default accounts ----
"%PHP%" %PHPX% artisan migrate --seed --force
if errorlevel 1 ( echo [ERROR] Database migration failed. & goto :fail )
echo [OK] Database tables and accounts

rem ---- 5. Natural female voice for the TV (optional, needs internet) ----
call "%~dp0install-voice.bat" /quiet || echo [!]  Voice not installed. The TV will use the browser voice. Run install-voice.bat later.

rem ---- 6. Firewall (needs "Run as administrator") ----
netsh advfirewall firewall show rule name="Malasakit Queue 88" >nul 2>&1 || (
    netsh advfirewall firewall add rule name="Malasakit Queue 88" dir=in action=allow protocol=TCP localport=88 >nul 2>&1
)
netsh advfirewall firewall show rule name="Malasakit Queue 88" >nul 2>&1 && (
    echo [OK] Firewall port 88
) || (
    echo [!]  Could not open the firewall. Right-click setup.bat ^> Run as administrator
    echo      if other PCs need to reach this one.
)

echo.
echo ==========================================================
echo   DONE! Now run start.bat
echo   Counter logins (password: changeme):
echo     doh1  dswd1  phic1  pagcor1  admin
echo ==========================================================
pause
exit /b 0

:fail
echo.
echo Setup did not finish. Take a screenshot of the error above.
pause
exit /b 1
