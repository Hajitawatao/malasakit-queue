@echo off
rem ==========================================================
rem  I-save at i-upload sa GitHub ang mga binago mo.
rem  Double-click lang pagkatapos mag-edit.
rem  Kailangan: Git for Windows (git-scm.com)
rem ==========================================================
setlocal
cd /d "%~dp0"
title Malasakit Queue - Save to Git

where git >nul 2>&1 || (
    echo [ERROR] Walang Git. I-install ang Git for Windows: https://git-scm.com
    pause & exit /b 1
)

if not exist .git (
    echo [ERROR] Hindi pa naka-connect sa GitHub ang folder na ito.
    echo         Patakbuhin muna ang:  git clone ^<repo-url^>  o hingin kay Claude ang setup.
    pause & exit /b 1
)

rem Siguraduhing ang email na naka-link sa GitHub ang author (para mabilang sa contributions)
git config user.email >nul 2>&1 || git config user.email "meliodas2327@gmail.com"
git config user.name  >nul 2>&1 || git config user.name "meliodas2327"

echo [..] Kinukuha ang pinakabagong version mula GitHub...
git pull --rebase --autostash
if errorlevel 1 (
    echo [ERROR] May conflict sa pull. Ayusin muna, o i-screenshot at ipadala kay Claude.
    pause & exit /b 1
)

git add -A
git diff --cached --quiet && (
    echo Walang bagong binago. Wala nang ise-save.
    pause & exit /b 0
)

echo.
echo Mga binago:
git status --short
echo.
set "MSG="
set /p "MSG=Ano ang binago mo? (Enter = auto message): "
if not defined MSG for /f "tokens=*" %%d in ('powershell -NoProfile -Command "Get-Date -Format 'yyyy-MM-dd HH:mm'"') do set "MSG=Update %%d"

git commit -m "%MSG%"
git push
if errorlevel 1 (
    echo [ERROR] Hindi na-push. Naka-login ka ba sa GitHub? (Lalabas ang login window sa unang push.)
    pause & exit /b 1
)

echo.
echo [OK] Na-upload sa GitHub.
timeout /t 3 >nul
