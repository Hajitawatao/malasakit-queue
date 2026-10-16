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

set "PHPX=-d date.timezone=Asia/Manila"
for %%d in ("%PHP%") do set "PHPDIR=%%~dpd"
if exist "%PHPDIR%ext" call set "PHPX=%%PHPX%% -d "extension_dir=%PHPDIR%ext""
set "PHPM=%TEMP%\malasakit_php_modules.txt"
"%PHP%" %PHPX% -m > "%PHPM%" 2>nul
for %%E in (zip fileinfo pdo_mysql mbstring openssl curl) do (
    findstr /i /x "%%E" "%PHPM%" >nul || call set "PHPX=%%PHPX%% -d extension=%%E"
)
del "%PHPM%" >nul 2>&1

exit /b 0
