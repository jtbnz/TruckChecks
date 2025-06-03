#!/bin/bash
# filepath: scripts/debug.sh

# Get the directory where the script is located
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
# Go up one directory to the project root where config.php should be
CONFIG_FILE="$SCRIPT_DIR/../config.php"

# Check if config file exists
if [ ! -f "$CONFIG_FILE" ]; then
    echo "Error: config.php not found at $CONFIG_FILE"
    exit 1
fi

# Check current DEBUG setting
if grep -q "define('DEBUG'[[:space:]]*,[[:space:]]*false)" "$CONFIG_FILE"; then
    # If DEBUG is currently false, change it to true
    sed -i '' 's/define('"'"'DEBUG'"'"'[[:space:]]*,[[:space:]]*false)/define('"'"'DEBUG'"'"', true)/g' "$CONFIG_FILE"
    echo "Debug mode: ON"
elif grep -q "define('DEBUG'[[:space:]]*,[[:space:]]*true)" "$CONFIG_FILE"; then
    # If DEBUG is currently true, change it to false
    sed -i '' 's/define('"'"'DEBUG'"'"'[[:space:]]*,[[:space:]]*true)/define('"'"'DEBUG'"'"', false)/g' "$CONFIG_FILE"
    echo "Debug mode: OFF"
else
    echo "Could not find DEBUG setting in config.php"
    exit 1
fi