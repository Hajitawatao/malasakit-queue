@echo off
rem Isinasara ang web window ng Malasakit Queue
taskkill /fi "WINDOWTITLE eq Malasakit Queue - WEB :88*" /t /f >nul 2>&1
echo Malasakit Queue stopped.
timeout /t 2 >nul
