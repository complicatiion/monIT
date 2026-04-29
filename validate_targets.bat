@echo off
setlocal
cd /d "%~dp0"

set "PHP_EXE="
if exist "runtime\php\php.exe" set "PHP_EXE=%CD%\runtime\php\php.exe"
if not defined PHP_EXE if exist "C:\php\php.exe" set "PHP_EXE=C:\php\php.exe"
if not defined PHP_EXE set "PHP_EXE=php"

"%PHP_EXE%" -r "json_decode(file_get_contents('config/targets.json')); if (json_last_error() !== JSON_ERROR_NONE) { fwrite(STDERR, 'Invalid targets.json: ' . json_last_error_msg() . PHP_EOL); exit(1); } echo 'targets.json is valid JSON.' . PHP_EOL;"

pause
endlocal
