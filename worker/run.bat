@echo off
REM Build in Lombok - Listing Worker nightly run (docs/adr/0007)
REM Point Windows Task Scheduler at this file. Logs to worker\logs\.

cd /d "%~dp0"
if not exist logs mkdir logs
set STAMP=%date:~-4%-%date:~3,2%-%date:~0,2%
REM --use-system-ca: trust the Windows cert store so Node's fetch() works behind
REM HTTPS-inspecting antivirus/firewalls (same reason npm install needed it).
set NODE_OPTIONS=--use-system-ca
REM --headful: OLX's anti-bot blocks the headless browser; a real (visible)
REM window gets through. Works for the other portals too. The scheduled task
REM must run in an interactive (logged-on) session for the window to open.
node listing-worker.js --headful >> "logs\worker-%STAMP%.log" 2>&1
