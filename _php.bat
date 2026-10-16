@echo off
rem ==========================================================
rem  Finds PHP and loads any missing extensions on the command
rem  line, so php.ini is never edited.
rem  Called by setup.bat and start.bat. Do not run it directly.
rem ==========================================================

set "PHP="
if defined PHP_BIN if exist "%PHP_BIN%" set "PHP=%PHP_BIN%"
if not defined PHP if exist "C:\xampp\php\php.exe" set "PHP=C:\xampp\php\php.exe"
if not defined PHP for /f "delims=" %%p in ('where php.exe 2^>nul') do if not defined PHP set "PHP=%%p"
if not defined PHP if exist "C:\php\php.exe" set "PHP=C:\php\php.exe"
if not defined PHP (
    echo [ERROR] PHP was not found. Install XAMPP, or set PHP_BIN to your php.exe.
    exit /b 1
)

"%PHP%" -r "exit(PHP_VERSION_ID>=80200?0:1);" >nul 2>&1
if errorlevel 1 (
    echo [ERROR] PHP 8.2 or newer is required. Found: %PHP%
    "%PHP%" -v
    echo Update XAMPP, or set PHP_BIN to a newer php.exe.
    exit /b 1
)

rem Never print PHP warnings into web pages / API responses
set "PHPX=-d date.timezone=Asia/Manila -d display_errors=0 -d display_startup_errors=0 -d log_errors=1"
for %%d in ("%PHP%") do set "PHPDIR=%%~dpd"
if exist "%PHPDIR%ext" call set "PHPX=%%PHPX%% -d "extension_dir=%PHPDIR%ext""

rem Ask PHP itself which extensions are missing (only those get -d extension=...)
set "EXTRA="
for /f "usebackq delims=" %%x in (`""%PHP%" -d display_startup_errors=0 -r "foreach (['zip','fileinfo','pdo_mysql','mbstring','openssl','curl'] as $e) if (!extension_loaded($e)) echo ' -d extension='.$e;""`) do set "EXTRA=%%x"
if defined EXTRA call set "PHPX=%%PHPX%%%%EXTRA%%"

exit /b 0
