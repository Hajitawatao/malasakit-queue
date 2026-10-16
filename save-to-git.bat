@echo off
rem ==========================================================
rem  Saves your changes and uploads them to GitHub.
rem  Just double-click it after you edit something.
rem  Requires: Git for Windows (git-scm.com)
rem ==========================================================
setlocal
cd /d "%~dp0"
title Malasakit Queue - Save to Git

where git >nul 2>&1 || (
    echo [ERROR] Git is not installed. Get Git for Windows: https://git-scm.com
    pause & exit /b 1
)

if not exist .git (
    echo [ERROR] This folder is not connected to GitHub yet.
    echo         Run:  git clone ^<repo-url^>  or ask Claude to set it up.
    pause & exit /b 1
)

rem Use the email linked to GitHub as the author, so commits count as contributions
git config user.email >nul 2>&1 || git config user.email "meliodas2327@gmail.com"
git config user.name  >nul 2>&1 || git config user.name "meliodas2327"

echo [..] Getting the latest version from GitHub...
git pull --rebase --autostash
if errorlevel 1 (
    echo [ERROR] Pull hit a conflict. Fix it first, or send a screenshot to Claude.
    pause & exit /b 1
)

git add -A
git diff --cached --quiet && (
    echo Nothing new to save.
    pause & exit /b 0
)

echo.
echo Changes:
git status --short
echo.
set "MSG="
set /p "MSG=What did you change? (press Enter for an automatic message): "
if not defined MSG for /f "tokens=*" %%d in ('powershell -NoProfile -Command "Get-Date -Format 'yyyy-MM-dd HH:mm'"') do set "MSG=Update %%d"

git commit -m "%MSG%"
git push
if errorlevel 1 (
    echo [ERROR] Push failed. Are you signed in to GitHub? A sign-in window appears on the first push.
    pause & exit /b 1
)

echo.
echo [OK] Uploaded to GitHub.
timeout /t 3 >nul
