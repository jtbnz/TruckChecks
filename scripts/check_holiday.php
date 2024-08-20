<?php
$apikey = "your api key from https://www.public-holidays.nz/";
$date = isset($argv[1]) ? $argv[1] : date('d/m/Y'); // Default to today if no date passed

// Fetch the public holiday data from the API
$api_url = "https://api.public-holidays.nz/v1/day?apikey=$apikey&date=$date";
$response = file_get_contents($api_url);
$data = json_decode($response, true);

if ($data && isset($data['HolidayName'])) {
    $holiday_name = $data['HolidayName'];
    if (strpos($holiday_name, 'Anniversary Day') !== false && $holiday_name !== 'Auckland Anniversary Day') {
        // If it's an Anniversary Day but not Auckland's, ignore it
        echo "IGNORE";
    } else {
        // It's a public holiday
        echo "HOLIDAY";
    }
} else {
    // Not a public holiday
    echo "NO_HOLIDAY";
}
?>
