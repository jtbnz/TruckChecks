#!/bin/bash

# Define the timezone, PHP script, and target URL
TIMEZONE="Pacific/Auckland"
URL="https://yourserver/email_results.php"
PHP_SCRIPT="/scripts/check_holiday.php"
LOGFILE="/scripts/logfile.log"

# Get the current date, day of the week, and time in Auckland timezone
CURRENT_DATE=$(TZ=$TIMEZONE date +'%d/%m/%Y')
CURRENT_TIME=$(TZ=$TIMEZONE date +'%H:%M')
CURRENT_DAY=$(TZ=$TIMEZONE date +'%u') # %u gives day of the week (1=Monday, 7=Sunday)

# Function to check if today is a public holiday
is_public_holiday() {
    local date=$1
    local result=$(php $PHP_SCRIPT $date)
    
    if [[ "$result" == "HOLIDAY" ]]; then
        echo "Public holiday detected."
        return 0
    elif [[ "$result" == "IGNORE" ]]; then
        echo "Anniversary Day ignored."
        return 1
    else
        echo "No public holiday today."
        return 1
    fi
}

# Check if it's Monday or Tuesday
if [[ "$CURRENT_DAY" -eq 1 || "$CURRENT_DAY" -eq 2 ]]; then
  # On Monday or Tuesday, check if the time is 7:30 PM
  if [[ "$CURRENT_TIME" == "19:30" ]]; then
    # If it's Monday, check for a public holiday
    if [[ "$CURRENT_DAY" -eq 1 ]]; then
      if is_public_holiday "$CURRENT_DATE"; then
        # If it's a public holiday, skip the action and log it
        echo "Skipping Monday's action due to public holiday." >> $LOGFILE
        exit 0
      fi
    fi
    
    # If not a public holiday or it's Tuesday, execute the cURL request
    curl -s $URL >> $LOGFILE
    echo "Executed cURL request to $URL on $(date)" >> $LOGFILE
  fi
fi
