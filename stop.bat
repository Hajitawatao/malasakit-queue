@echo off
rem Isinasara ang web at reverb windows ng Malasakit Queue
taskkill /fi "WINDOWTITLE eq Malasakit Queue - WEB :88*" /t /f >nul 2>&1
taskkill /fi "WINDOWTITLE eq Malasakit Queue - REVERB :6001*" /t /f >nul 2>&1
echo Malasakit Queue stopped.
timeout /t 2 >nul
