@echo off
cd /d C:\monIT

start "monIT Web Server" "%~dp0start_server.bat"

timeout /t 3 /nobreak >nul

start "monIT Poll Loop" "%~dp0poll_loop.bat"

timeout /t 5 /nobreak >nul

start "" "C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk http://127.0.0.1:8080 --disable-pinch --overscroll-history-navigation=0