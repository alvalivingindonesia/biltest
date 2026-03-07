@echo off
REM Change to the repo's deploy directory
cd /d "C:\Users\User\Downloads\Build in Lombok\deploy"

REM Run git commands
git add .
git commit -m "Updates to Build In Lombok"
git push origin main

REM Keep window open so you can see output
echo.
echo Done. Press any key to close...
pause >nul