@echo off
REM Startup script for PPF Ink Analyzer (Windows)
cd /d "%~dp0"

echo.
echo ╔════════════════════════════════════════════════════════╗
echo ║  Starting PPF Ink Analyzer...                          ║
echo ╚════════════════════════════════════════════════════════╝
echo.

REM Check if Python is installed
python --version >nul 2>&1
if %errorlevel% neq 0 (
    echo ERROR: Python not found! Please install Python 3.8+ first.
    pause
    exit /b 1
)

REM Check if virtual environment exists
if not exist "venv" (
    echo Creating virtual environment...
    python -m venv venv
    echo.
)

REM Activate virtual environment
call venv\Scripts\activate

REM Install dependencies
echo Installing/Updating dependencies...
pip install -q -r requirements.txt

REM Start the Flask app
echo.
echo ╔════════════════════════════════════════════════════════╗
echo ║  PPF Analyzer is starting on http://localhost:5000     ║
echo ║  Press Ctrl+C to stop                                  ║
echo ╚════════════════════════════════════════════════════════╝
echo.

python ppf_analyzer_web.py

pause
