@echo off
REM Build in Lombok - Listing Worker nightly run (docs/adr/0007)
REM Point Windows Task Scheduler at this file. Logs to worker\logs\.

cd /d "%~dp0"
if not exist logs mkdir logs
set STAMP=%date:~-4%-%date:~3,2%-%date:~0,2%
node listing-worker.js >> "logs\worker-%STAMP%.log" 2>&1
