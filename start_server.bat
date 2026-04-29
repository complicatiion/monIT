@echo off
setlocal
cd /d "%~dp0"

set "PHP_EXE="

if exist "%~dp0runtime\php\php.exe" set "PHP_EXE=%~dp0runtime\php\php.exe"
if not defined PHP_EXE if exist "C:\php\php.exe" set "PHP_EXE=C:\php\php.exe"
if not defined PHP_EXE (
    where php >nul 2>nul
    if not errorlevel 1 set "PHP_EXE=php"
)

if not exist public\index.php (
    echo monIT: public\index.php was not found.
    pause
    exit /b 1
)

if not defined PHP_EXE (
    echo monIT: PHP was not found.
    echo Install PHP for Windows, extract it to C:\php, or place it in runtime\php.
    pause
    exit /b 1
)

echo Starting monIT on http://127.0.0.1:8080
echo PHP executable: %PHP_EXE%
echo PHP max_execution_time: 120 seconds
echo PHP default_socket_timeout: 3 seconds
echo.
echo Press CTRL+C to stop the server.
echo.

"%PHP_EXE%" ^
  -d max_execution_time=120 ^
  -d default_socket_timeout=3 ^
  -d memory_limit=256M ^
  -S 127.0.0.1:8080 ^
  -t public

endlocal
