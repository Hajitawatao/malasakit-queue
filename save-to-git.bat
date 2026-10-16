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
    echo [..] This folder is not a git repo yet. Creating one...
    git init -b main >nul || ( echo [ERROR] git init failed. & pause & exit /b 1 )
)

rem Use the email linked to GitHub as the author, so commits count as contributions
git config user.email >nul 2>&1 || git config user.email "meliodas2327@gmail.com"
git config user.name  >nul 2>&1 || git config user.name "meliodas2327"

rem ---- remote: ask for the GitHub URL once
git remote get-url origin >nul 2>&1 || (
    echo This folder is not linked to GitHub yet.
    set /p "REPO_URL=Paste the GitHub repo URL (e.g. https://github.com/you/malasakit-queue.git): "
    call git remote add origin "%%REPO_URL%%" || ( echo [ERROR] Could not add the remote. & pause & exit /b 1 )
)

for /f "delims=" %%b in ('git rev-parse --abbrev-ref HEAD 2^>nul') do set "BRANCH=%%b"
if not defined BRANCH set "BRANCH=main"
if "%BRANCH%"=="HEAD" set "BRANCH=main"

rem ---- pull first, but only if GitHub already has this branch
echo [..] Getting the latest version from GitHub...
git ls-remote --exit-code --heads origin %BRANCH% >nul 2>&1
set "RC=%errorlevel%"
if "%RC%"=="2" (
    echo [i]  GitHub has no "%BRANCH%" branch yet. It will be created on push.
) else if not "%RC%"=="0" (
    echo [ERROR] Cannot reach the GitHub repo. Check the URL and your internet:
    git remote get-url origin
    pause & exit /b 1
) else (
    git rev-parse --verify --quiet HEAD >nul 2>&1 || (
        rem Fresh repo: adopt GitHub's history but keep the files in this folder as they are
        git fetch origin %BRANCH% && git reset --mixed FETCH_HEAD >nul && git branch -u origin/%BRANCH% >nul 2>&1
    )
    git pull --rebase --autostash origin %BRANCH%
    if errorlevel 1 (
        echo [ERROR] Could not merge the GitHub version with yours. Send a screenshot to Claude.
        pause & exit /b 1
    )
)

git add -A
git diff --cached --quiet
if errorlevel 1 goto :commit

rem Nothing new to commit: push only if there are commits GitHub does not have yet
git rev-parse --verify --quiet origin/%BRANCH% >nul 2>&1 || goto :push
set "AHEAD=0"
for /f %%n in ('git rev-list --count origin/%BRANCH%..HEAD') do set "AHEAD=%%n"
if "%AHEAD%"=="0" (
    echo Nothing new to save.
    pause & exit /b 0
)
goto :push

:commit
echo.
echo Changes:
git status --short
echo.
set "MSG="
set /p "MSG=What did you change? (press Enter for an automatic message): "
if not defined MSG for /f "tokens=*" %%d in ('powershell -NoProfile -Command "Get-Date -Format 'yyyy-MM-dd HH:mm'"') do set "MSG=Update %%d"
git commit -m "%MSG%"

:push
echo [..] Uploading to GitHub...
git push -u origin %BRANCH%
if errorlevel 1 (
    echo [ERROR] Push failed. Are you signed in to GitHub? A sign-in window appears on the first push.
    pause & exit /b 1
)

echo.
echo [OK] Uploaded to GitHub (branch %BRANCH%).
timeout /t 3 >nul
