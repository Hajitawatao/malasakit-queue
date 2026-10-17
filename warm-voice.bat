@echo off
rem Pre-makes TV announcements for ticket numbers 1-30 (all lanes, all windows),
rem so the first calls of the day play instantly. Uses ElevenLabs credits
rem (about 60 per new announcement) when ElevenLabs is set up, otherwise Piper.
rem Change the range with: warm-voice.bat 1 50
setlocal
cd /d "%~dp0"
title Malasakit Queue - Pre-make announcements
call "%~dp0_php.bat" || ( pause & exit /b 1 )
set "FROM=%~1"
set "TO=%~2"
if not defined FROM set "FROM=1"
if not defined TO set "TO=30"
"%PHP%" %PHPX% artisan voice:warm --from=%FROM% --to=%TO%
pause
