@echo off
rem ==========================================================
rem  Hinahanap ang PHP at nilo-load ang kulang na extensions
rem  sa command line (hindi ginagalaw ang php.ini).
rem  Tinatawag ng setup.bat at start.bat. Huwag patakbuhin mag-isa.
rem ==========================================================

set "PHP="
if defined PHP_BIN if exist "%PHP_BIN%" set "PHP=%PHP_BIN%"
if not defined PHP if exist "C:\xampp\php\php.exe" set "PHP=C:\xampp\php\php.exe"
if not defined PHP for /f "delims=" %%p in ('where php.exe 2^>nul') do if not defined PHP set "PHP=%%p"
if not defined PHP if exist "C:\php\php.exe" set "PHP=C:\php\php.exe"
if not defined PHP (
    echo [ERROR] Hindi makita ang PHP. I-install ang XAMPP, o i-set ang PHP_BIN.
    exit /b 1
)

"%PHP%" -r "exit(PHP_VERSION_ID>=80200?0:1);" >nul 2>&1
if errorlevel 1 (
    echo [ERROR] Kailangan ng PHP 8.2 o mas bago. Ito ang nakita: %PHP%
    "%PHP%" -v
    echo I-update ang XAMPP, o i-set ang PHP_BIN sa mas bagong php.exe.
    exit /b 1
)

set "PHPX=-d date.timezone=Asia/Manila"
for %%d in ("%PHP%") do set "PHPDIR=%%~dpd"
if exist "%PHPDIR%ext" call set "PHPX=%%PHPX%% -d "extension_dir=%PHPDIR%ext""
for %%E in (zip fileinfo pdo_mysql mbstring openssl curl) do (
    call "%%PHP%%" %%PHPX%% -m 2>nul | findstr /i /x "%%E" >nul || call set "PHPX=%%PHPX%% -d extension=%%E"
)

set "MYSQL=C:\xampp\mysql\bin\mysql.exe"
if not exist "%MYSQL%" set "MYSQL=mysql"

exit /b 0
