#!/bin/bash


# Check current DEBUG setting
if grep -q "define('DEBUG'[[:space:]]*,[[:space:]]*false)" config.php; then
    # If DEBUG is currently false, change it to true
    sed -i '' "s/define('DEBUG'[[:space:]]*,[[:space:]]*false);/define('DEBUG', true);/g" config.php
    echo "Debug mode: ON"
elif grep -q "define('DEBUG'[[:space:]]*,[[:space:]]*true)" config.php; then
    # If DEBUG is currently true, change it to false
    sed -i '' "s/define('DEBUG'[[:space:]]*,[[:space:]]*true);/define('DEBUG', false);/g" config.php
    echo "Debug mode: OFF"
else
    echo "Could not find DEBUG setting in config.php"
    exit 1
fi