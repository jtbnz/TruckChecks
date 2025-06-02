#!/bin/bash

# TruckChecks Automated Email System
# This script is called by cron hourly to process automated emails for all stations
# Each station has its own configuration for send times, training nights, and holiday handling

# Define the timezone and paths
TIMEZONE="Pacific/Auckland"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_PROCESSOR="$SCRIPT_DIR/automated_email_processor.php"
LOGFILE="$SCRIPT_DIR/email_automation.log"

# Export timezone for PHP script
export TIMEZONE

# Log the execution
echo "[$(TZ=$TIMEZONE date +'%Y-%m-%d %H:%M:%S')] Starting automated email check" >> $LOGFILE

# Check if the PHP processor exists
if [[ ! -f "$PHP_PROCESSOR" ]]; then
    echo "[$(TZ=$TIMEZONE date +'%Y-%m-%d %H:%M:%S')] ERROR: PHP processor not found at $PHP_PROCESSOR" >> $LOGFILE
    exit 1
fi

# Execute the PHP processor
php "$PHP_PROCESSOR"
EXIT_CODE=$?

# Log the result
if [[ $EXIT_CODE -eq 0 ]]; then
    echo "[$(TZ=$TIMEZONE date +'%Y-%m-%d %H:%M:%S')] Automated email processor completed successfully" >> $LOGFILE
else
    echo "[$(TZ=$TIMEZONE date +'%Y-%m-%d %H:%M:%S')] ERROR: Automated email processor failed with exit code $EXIT_CODE" >> $LOGFILE
fi

exit $EXIT_CODE
