@echo off
rem ==========================================================
rem  Installs the Kokoro voice for the TV announcements.
rem  Kokoro is a free, offline neural voice (no account, no credits).
rem  Runs with sherpa-onnx, so Python is NOT needed.
rem  Needs internet once (about 155 MB). Run it again to reinstall.
rem
rem  Female voices: af_heart (default), af_bella, af_sarah, af_nicole,
rem  af_nova, bf_emma (British). Change it with KOKORO_VOICE in .env.
rem ==========================================================
setlocal
cd /d "%~dp0"
title Malasakit Queue - Install Kokoro Voice

set "DEST=%~dp0tools\kokoro"
set "SHERPA=sherpa-onnx-v1.13.8-win-x64-shared-MD-Release"
set "SHERPA_URL=https://github.com/k2-fsa/sherpa-onnx/releases/download/v1.13.8/%SHERPA%.tar.bz2"
set "MODEL=kokoro-int8-multi-lang-v1_0"
set "MODEL_URL=https://github.com/k2-fsa/sherpa-onnx/releases/download/tts-models/%MODEL%.tar.bz2"

if not exist "%DEST%\bin" mkdir "%DEST%\bin"

rem ---- 1. Engine (sherpa-onnx, about 20 MB) ----
if exist "%DEST%\bin\sherpa-onnx-offline-tts.exe" goto :have_engine
echo [..] Downloading the voice engine (about 20 MB)...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$ProgressPreference='SilentlyContinue'; [Net.ServicePointManager]::SecurityProtocol='Tls12'; Invoke-WebRequest -Uri '%SHERPA_URL%' -OutFile '%DEST%\engine.tar.bz2'"
if not exist "%DEST%\engine.tar.bz2" goto :fail_download
tar -xjf "%DEST%\engine.tar.bz2" -C "%DEST%" "%SHERPA%/bin/sherpa-onnx-offline-tts.exe" "%SHERPA%/bin/onnxruntime.dll" "%SHERPA%/bin/onnxruntime_providers_shared.dll"
move /y "%DEST%\%SHERPA%\bin\*" "%DEST%\bin\" >nul
rmdir /s /q "%DEST%\%SHERPA%" >nul 2>&1
del /q "%DEST%\engine.tar.bz2" >nul 2>&1
if not exist "%DEST%\bin\sherpa-onnx-offline-tts.exe" goto :fail_unpack
:have_engine
echo [OK] Voice engine

rem ---- 2. Kokoro model (about 135 MB) ----
if exist "%DEST%\model\voices.bin" goto :have_model
echo [..] Downloading the Kokoro voice model (about 135 MB, a few minutes)...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$ProgressPreference='SilentlyContinue'; [Net.ServicePointManager]::SecurityProtocol='Tls12'; Invoke-WebRequest -Uri '%MODEL_URL%' -OutFile '%DEST%\model.tar.bz2'"
if not exist "%DEST%\model.tar.bz2" goto :fail_download
echo [..] Unpacking...
tar -xjf "%DEST%\model.tar.bz2" -C "%DEST%"
if exist "%DEST%\model" rmdir /s /q "%DEST%\model"
move "%DEST%\%MODEL%" "%DEST%\model" >nul
del /q "%DEST%\model.tar.bz2" >nul 2>&1
if not exist "%DEST%\model\voices.bin" goto :fail_unpack
:have_model
echo [OK] Kokoro model

rem ---- 3. Test ----
echo [..] Testing (the first run can take a few seconds)...
call :speak
if exist "%DEST%\test.wav" goto :test_ok

echo [i]  The engine did not start. Installing the Microsoft Visual C++ runtime it needs...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$ProgressPreference='SilentlyContinue'; [Net.ServicePointManager]::SecurityProtocol='Tls12'; Invoke-WebRequest -Uri 'https://aka.ms/vs/17/release/vc_redist.x64.exe' -OutFile '%TEMP%\vc_redist.x64.exe'"
if exist "%TEMP%\vc_redist.x64.exe" "%TEMP%\vc_redist.x64.exe" /install /passive /norestart
call :speak
if not exist "%DEST%\test.wav" goto :fail_test

:test_ok
echo [OK] Kokoro works. Playing a sample...
start "" "%DEST%\test.wav"

echo.
echo ==========================================================
echo   DONE. The TV now uses the Kokoro voice.
echo   Restart the system (stop.bat, then start.bat) so the
echo   voice worker picks it up, then refresh the TV (Ctrl+F5).
echo   Optional: warm-voice.bat pre-makes numbers 1-30.
echo ==========================================================
if /i not "%~1"=="/quiet" pause
exit /b 0

:speak
if exist "%DEST%\test.wav" del /q "%DEST%\test.wav"
"%DEST%\bin\sherpa-onnx-offline-tts.exe" --kokoro-model="%DEST%\model\model.int8.onnx" --kokoro-voices="%DEST%\model\voices.bin" --kokoro-tokens="%DEST%\model\tokens.txt" --kokoro-data-dir="%DEST%\model\espeak-ng-data" --kokoro-lexicon="%DEST%\model\lexicon-us-en.txt" --kokoro-lang=en --num-threads=4 --sid=3 --output-filename="%DEST%\test.wav" "Now calling number R 1. Please proceed to D S W D, Window 1." >nul 2>&1
exit /b 0

:fail_download
echo [ERROR] Download failed. Check the internet connection, then run this again.
goto :end_fail
:fail_unpack
echo [ERROR] Could not unpack the download. Run this again; if it keeps failing,
echo         delete the tools\kokoro folder first.
goto :end_fail
:fail_test
echo [ERROR] Kokoro is installed but could not speak. Send a screenshot to Claude.
goto :end_fail
:end_fail
echo The TV keeps using the current voice until this works.
if /i not "%~1"=="/quiet" pause
exit /b 1
