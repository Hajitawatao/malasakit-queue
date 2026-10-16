@echo off
rem ==========================================================
rem  Installs the natural FEMALE voice for the TV announcements.
rem  Uses Piper (free, offline neural text-to-speech).
rem  Needs internet once (about 80 MB). Run it again to reinstall.
rem
rem  To use a different Piper voice, set VOICE_URL before running, e.g.
rem    set VOICE_URL=https://huggingface.co/rhasspy/piper-voices/resolve/main/en/en_GB/jenny_dioco/medium/en_GB-jenny_dioco-medium.onnx
rem ==========================================================
setlocal
cd /d "%~dp0"
title Malasakit Queue - Install Voice

set "DEST=%~dp0tools\piper"
set "PIPER_URL=https://github.com/rhasspy/piper/releases/download/2023.11.14-2/piper_windows_amd64.zip"
if not defined VOICE_URL set "VOICE_URL=https://huggingface.co/rhasspy/piper-voices/resolve/main/en/en_US/hfc_female/medium/en_US-hfc_female-medium.onnx"
set "FALLBACK_URL=https://github.com/rhasspy/piper/releases/download/v0.0.2/voice-en-us-amy-low.tar.gz"

if not exist "%DEST%" mkdir "%DEST%"

rem ---- 1. Piper engine ----
if exist "%DEST%\piper\piper.exe" goto :have_piper
echo [..] Downloading the Piper voice engine...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$ProgressPreference='SilentlyContinue'; [Net.ServicePointManager]::SecurityProtocol='Tls12'; Invoke-WebRequest -Uri '%PIPER_URL%' -OutFile '%DEST%\piper.zip'; Expand-Archive -Force -Path '%DEST%\piper.zip' -DestinationPath '%DEST%'; Remove-Item '%DEST%\piper.zip'"
if not exist "%DEST%\piper\piper.exe" goto :fail_piper
:have_piper
echo [OK] Piper engine

rem ---- 2. Female voice ----
if exist "%DEST%\voice.onnx" del /q "%DEST%\voice.onnx" "%DEST%\voice.onnx.json" >nul 2>&1
echo [..] Downloading the female voice...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$ProgressPreference='SilentlyContinue'; [Net.ServicePointManager]::SecurityProtocol='Tls12'; try { Invoke-WebRequest -Uri '%VOICE_URL%' -OutFile '%DEST%\voice.onnx'; Invoke-WebRequest -Uri '%VOICE_URL%.json' -OutFile '%DEST%\voice.onnx.json' } catch { Remove-Item -ErrorAction SilentlyContinue '%DEST%\voice.onnx','%DEST%\voice.onnx.json'; exit 1 }"
if exist "%DEST%\voice.onnx.json" goto :have_voice

echo [i]  Main voice download failed. Trying the backup voice from GitHub...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$ProgressPreference='SilentlyContinue'; [Net.ServicePointManager]::SecurityProtocol='Tls12'; Invoke-WebRequest -Uri '%FALLBACK_URL%' -OutFile '%DEST%\voice.tar.gz'"
if not exist "%DEST%\voice.tar.gz" goto :fail_voice
mkdir "%DEST%\voice-tmp" 2>nul
tar -xzf "%DEST%\voice.tar.gz" -C "%DEST%\voice-tmp"
move /y "%DEST%\voice-tmp\en-us-amy-low.onnx" "%DEST%\voice.onnx" >nul
move /y "%DEST%\voice-tmp\en-us-amy-low.onnx.json" "%DEST%\voice.onnx.json" >nul
rmdir /s /q "%DEST%\voice-tmp" >nul 2>&1
del /q "%DEST%\voice.tar.gz" >nul 2>&1
if not exist "%DEST%\voice.onnx.json" goto :fail_voice
:have_voice
echo [OK] Female voice

rem ---- 3. Clear old announcements so the new voice is used ----
if exist "%~dp0public\tts" del /q "%~dp0public\tts\*.wav" >nul 2>&1

rem ---- 4. Test ----
echo [..] Testing...
echo Now calling number R 1. Please proceed to D S W D, Window 1.| "%DEST%\piper\piper.exe" --model "%DEST%\voice.onnx" --espeak_data "%DEST%\piper\espeak-ng-data" --output_file "%DEST%\test.wav" >nul 2>&1
if not exist "%DEST%\test.wav" goto :fail_test
echo [OK] Voice works. Playing a sample...
start "" "%DEST%\test.wav"

echo.
echo ==========================================================
echo   DONE. The TV now uses the natural female voice.
echo   Refresh the TV page (Ctrl+F5). No restart needed.
echo ==========================================================
if /i not "%~1"=="/quiet" pause
exit /b 0

:fail_piper
echo [ERROR] Could not download or unpack Piper. Check the internet connection.
goto :end_fail
:fail_voice
echo [ERROR] Could not download a voice. Check the internet connection.
goto :end_fail
:fail_test
echo [ERROR] Piper is installed but could not speak. If Windows asks about
echo         VCRUNTIME140.dll, install the Microsoft Visual C++ Redistributable x64.
goto :end_fail
:end_fail
echo The TV keeps using the browser voice until this works.
if /i not "%~1"=="/quiet" pause
exit /b 1
