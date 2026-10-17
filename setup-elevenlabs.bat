@echo off
rem ==========================================================
rem  Connects the TV voice to ElevenLabs (voice: Kristen).
rem
rem  You need an ElevenLabs API key:
rem    elevenlabs.io > sign in > Developers > API Keys > Create
rem    (Text to Speech permission is enough.)
rem  Library voices like Kristen need a paid plan (Starter or higher).
rem
rem  Run again any time to change the key or voice.
rem  Leave the key empty to turn ElevenLabs off (Piper is used instead).
rem ==========================================================
setlocal
cd /d "%~dp0"
title Malasakit Queue - ElevenLabs Voice

call "%~dp0_php.bat" || ( pause & exit /b 1 )

if not exist vendor\autoload.php (
    echo Not set up yet. Run setup.bat first.
    pause & exit /b 1
)

echo.
set "EL_KEY="
set /p "EL_KEY=Paste your ElevenLabs API key (Enter = turn ElevenLabs off): "
"%PHP%" %PHPX% tools\set-env.php ELEVENLABS_API_KEY "%EL_KEY%" || goto :fail

set "EL_VOICE="
set /p "EL_VOICE=Voice ID (Enter = Kristen, dfeOmy6Uay63tNhyO99j): "
if not defined EL_VOICE set "EL_VOICE=dfeOmy6Uay63tNhyO99j"
"%PHP%" %PHPX% tools\set-env.php ELEVENLABS_VOICE_ID "%EL_VOICE%" || goto :fail

if not defined EL_KEY (
    echo [OK] ElevenLabs is off. The TV uses Piper or the browser voice.
    goto :done
)

rem XAMPP's PHP often has no list of trusted certificates, which breaks HTTPS calls
if not exist tools\cacert.pem (
    echo [..] Downloading the trusted certificate list...
    powershell -NoProfile -ExecutionPolicy Bypass -Command "$ProgressPreference='SilentlyContinue'; [Net.ServicePointManager]::SecurityProtocol='Tls12'; Invoke-WebRequest -Uri 'https://curl.se/ca/cacert.pem' -OutFile 'tools\cacert.pem'"
)

echo [..] Making a test announcement with ElevenLabs...
set "OUT=%TEMP%\malasakit_voice_test.txt"
"%PHP%" %PHPX% artisan voice:test > "%OUT%" 2>&1
set "RC=%errorlevel%"
type "%OUT%"
for /f "tokens=2* delims=:" %%a in ('findstr /b /c:"OK (" "%OUT%"') do set "SAMPLE=%%a:%%b"
del "%OUT%" >nul 2>&1

if "%RC%"=="0" (
    if defined SAMPLE start "" "%SAMPLE:~1%"
    echo.
    echo [OK] ElevenLabs works. Refresh the TV page ^(Ctrl+F5^).
    echo      Optional: run warm-voice.bat to pre-make numbers 1-30,
    echo      so the first calls of the day play instantly.
    goto :done
)

echo.
echo [!] ElevenLabs did not work, so the TV will use Piper. Common reasons:
echo     - wrong API key, or the key has no Text to Speech permission
echo     - Kristen is a library voice: the free plan cannot use it through the API
echo     - no credits left, or no internet on this PC
echo     The exact reason is at the bottom of storage\logs\laravel.log
goto :done

:fail
echo [ERROR] Could not save the settings.
:done
echo.
pause
