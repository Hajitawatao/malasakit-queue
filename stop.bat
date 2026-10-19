@echo off
rem Closes the Malasakit Queue server and voice worker windows
taskkill /fi "WINDOWTITLE eq Malasakit Queue - WEB :88*" /t /f >nul 2>&1
taskkill /fi "WINDOWTITLE eq Malasakit Queue - VOICE*" /t /f >nul 2>&1
echo Malasakit Queue stopped.
timeout /t 2 >nul
