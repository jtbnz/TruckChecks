# Cron Job for Executing a PHP Script Based on Auckland Time and Public Holidays

This repository contains a bash script and a PHP script that together allow you to execute a task (such as sending an email) at 7:30 PM Auckland time on Mondays or Tuesdays. The script also checks whether the day is a public holiday in Auckland and skips the task if it is (excluding non-Auckland Anniversary Days).

This is used as the server could be running in a different timezone. e.g. mine is in UTC

## Prerequisites

- PHP installed on the server.
- Cron jobs configured on the server.

## Files

- **check_holiday.php**: A PHP script that checks if a given date is a public holiday using the `public-holidays.nz` API.
- **email_checks.sh**: A bash script that checks the current time and day in Auckland timezone, determines if it's a public holiday, and if not, executes a cURL request to the specified URL.

## Setup Instructions

### 1. Configure the `check_holiday.php` Script

1. **API Key**: Replace the placeholder API key with your actual API key from `public-holidays.nz`.

    ```php
    $apikey = "your_actual_apikey_here"; // Replace with your API key
    ```

2. **Paths**: Ensure the PHP script is accessible to the bash script. The path to this script will be referenced in `email_checks.sh`.

3. **Changing the Public Holiday Anniversary Check**: By default, the script is set to ignore all "Anniversary Day" holidays except for "Auckland Anniversary Day". If you need to change this to a different anniversary, modify this line:

    ```php
    if (strpos($holiday_name, 'Anniversary Day') !== false && $holiday_name !== 'Auckland Anniversary Day') {
    ```

    - Replace `'Auckland Anniversary Day'` with the desired anniversary name.

### 2. Configure the `email_checks.sh` Script

1. **PHP Script Path**: Update the path to `check_holiday.php` in the bash script:

    ```bash
    PHP_SCRIPT="/path/to/check_holiday.php" # Update this to the correct path
    ```

2. **Log File Path**: Specify the path to the log file where the script outputs its logs:

    ```bash
    LOGFILE="/path/to/logfile.log" # Update this to the correct path
    ```

3. **URL for cURL Request**: Update the URL that the script will request using cURL:

    ```bash
    URL="https://yourserver/email_results.php" # Update 
    ```

### 3. Set Up the Cron Job

1. Open the crontab editor:

    ```bash
    crontab -e
    ```

2. Add the following cron job to run the bash script every hour from Monday to Friday:

    ```bash
    0 * * * 1-5 /path/to/cron_script.sh
    ```

    This cron job ensures that the script runs at the start of every hour during weekdays to check if it should execute the cURL request based on the conditions defined in the script. This should handle most server timezones.  The email will only be sent if it matches the time set above


### 4. Testing

To ensure everything is set up correctly:

1. Manually run the `email_checks.sh` script to verify it behaves as expected:

    ```bash
    /path/to/email_checks.sh
    ```

2. Check the log file to see the output and ensure the script is functioning properly.

## Logging

The bash script logs its activities to the specified log file. Check this log periodically to ensure the script is running as expected:

    ```bash
    tail -f /path/to/logfile.log
    ```

## Customization
**Different Public Holidays:*** Modify the logic in check_holiday.php if you need to account for different holidays or change the criteria for skipping tasks.
**Time Adjustments:*** If you need to adjust the time or days when the script runs, modify the corresponding logic in email_checks.sh.

## Acknowledgements
***https://www.public-holidays.nz/ API*** for providing the public holiday data.


### Summary:

- **API Key**: Instructions to replace the placeholder API key with your actual key.
- **Paths**: Clear instructions on where to update the paths for the PHP script and log file.
- **Public Holiday Logic**: Guidance on how to change the public holiday anniversary check if needed.
- **Cron Job Setup**: Steps to set up the cron job to run every minute.
- **Testing and Logging**: Instructions on how to test the setup and monitor logs.


