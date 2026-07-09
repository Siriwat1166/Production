#!/bin/bash
# Startup script for PPF Ink Analyzer (Linux/Mac)
cd "$(dirname "$0")"

echo ""
echo "╔════════════════════════════════════════════════════════╗"
echo "║  Starting PPF Ink Analyzer...                          ║"
echo "╚════════════════════════════════════════════════════════╝"
echo ""

# Check if Python is installed
if ! command -v python3 &> /dev/null; then
    echo "ERROR: Python 3 not found! Please install Python 3.8+ first."
    exit 1
fi

# Check if virtual environment exists
if [ ! -d "venv" ]; then
    echo "Creating virtual environment..."
    python3 -m venv venv
    echo ""
fi

# Activate virtual environment
source venv/bin/activate

# Install dependencies
echo "Installing/Updating dependencies..."
pip install -q -r requirements.txt

# Start the Flask app
echo ""
echo "╔════════════════════════════════════════════════════════╗"
echo "║  PPF Analyzer is starting on http://localhost:5000     ║"
echo "║  Press Ctrl+C to stop                                  ║"
echo "╚════════════════════════════════════════════════════════╝"
echo ""

python3 ppf_analyzer_web.py
