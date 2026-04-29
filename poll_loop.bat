@echo off
setlocal EnableExtensions
cd /d "%~dp0"

set "PHP_EXE="
set "POLL_INTERVAL=15"
set "TARGETS_FILE=%~dp0config\targets.json"

if exist "%~dp0runtime\php\php.exe" set "PHP_EXE=%~dp0runtime\php\php.exe"
if not defined PHP_EXE if exist "C:\php\php.exe" set "PHP_EXE=C:\php\php.exe"
if not defined PHP_EXE (
    where php >nul 2>nul
    if not errorlevel 1 set "PHP_EXE=php"
)

if not defined PHP_EXE (
    echo monIT: PHP was not found.
    echo Install PHP for Windows, extract it to C:\php, or place it in runtime\php.
    pause
    exit /b 1
)

if not exist "%TARGETS_FILE%" (
    echo monIT: config\targets.json was not found.
    pause
    exit /b 1
)

:loop
echo [%date% %time%] Validating targets.json...

"%PHP_EXE%" -r "json_decode(file_get_contents($argv[1])); if (json_last_error() !== JSON_ERROR_NONE) { fwrite(STDERR, 'monIT: targets.json invalid: ' . json_last_error_msg() . PHP_EOL); exit(2); } echo 'monIT: targets.json valid.' . PHP_EOL;" "%TARGETS_FILE%"

if errorlevel 1 (
    echo [%date% %time%] Poll skipped because targets.json is invalid.
    echo Fix config\targets.json, then this loop will continue automatically.
    echo.
    timeout /t %POLL_INTERVAL% /nobreak >nul
    goto loop
)

echo [%date% %time%] Running monIT polling cycle...
"%PHP_EXE%" cli\poll.php

echo.
timeout /t %POLL_INTERVAL% /nobreak >nul
goto loop
