@echo off
setlocal
cd /d "%~dp0"

set "PHP_BIN=%~dp0..\..\php\php.exe"
if not exist "%PHP_BIN%" set "PHP_BIN=php"

if "%PHP_BIN%"=="php" (
    where php >nul 2>&1
    if errorlevel 1 (
        echo PHP was not found on PATH or in the XAMPP folder.
        pause
        exit /b 1
    )
)

echo Starting PHP WebSocket server on port 8080...
"%PHP_BIN%" ws-server.php
pause
