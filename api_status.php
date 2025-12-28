<?php
// API endpoint for status page data
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

if (!file_exists('config.php')) {
    http_response_code(500);
    echo json_encode(['error' => 'Site not configured']);
    exit;
}

session_start();
include 'db.php';

// Read the cookie value
$colorBlindMode = isset($_COOKIE['color_blind_mode']) ? $_COOKIE['color_blind_mode'] : false;

if ($colorBlindMode) {
    $colours = [
        'green' => '#0072b2',
        'orange' => '#e69f00',
        'red' => '#d55e00',
    ];
} else {
    $colours = [
        'green' => '#28a745',
        'orange' => '#ff8800',
        'red' => '#dc3545',
    ];
}

$db = get_db_connection();
$trucks = $db->query('SELECT id, name, relief FROM trucks')->fetchAll(PDO::FETCH_ASSOC);

function get_locker_status($locker_id, $db, $colours) {
    // Fetch the most recent check for the locker
    $query = $db->prepare('SELECT * FROM checks WHERE locker_id = :locker_id ORDER BY check_date DESC LIMIT 1');
    $query->execute(['locker_id' => $locker_id]);
    $check = $query->fetch(PDO::FETCH_ASSOC);

    if (!$check) {
        return ['status' => $colours['red'], 'check' => null, 'missing_items' => []];
    }

    // Check if the locker was checked in the last 7 days
    $recent_check = (new DateTime())->diff(new DateTime($check['check_date']))->days < 6 && !$check['ignore_check'];

    // Fetch missing items from the last check
    $query = $db->prepare('SELECT items.name FROM check_items INNER JOIN items ON check_items.item_id = items.id WHERE check_items.check_id = :check_id AND check_items.is_present = 0');
    $query->execute(['check_id' => $check['id']]);
    $missing_items = $query->fetchAll(PDO::FETCH_COLUMN);

    if ($recent_check && empty($missing_items)) {
        return ['status' => $colours['green'], 'check' => $check, 'missing_items' => []];
    } elseif ($recent_check && !empty($missing_items)) {
        return ['status' => $colours['orange'], 'check' => $check, 'missing_items' => $missing_items];
    } else {
        return ['status' => $colours['red'], 'check' => $check, 'missing_items' => $missing_items];
    }
}

// Function to convert UTC to NZST
function convertToNZST($utcDate) {
    $date = new DateTime($utcDate, new DateTimeZone('UTC'));
    $date->setTimezone(new DateTimeZone('Pacific/Auckland')); // NZST timezone
    return $date->format('Y-m-d H:i:s');
}

$data = [
    'trucks' => [],
    'timestamp' => date('c'),
];

foreach ($trucks as $truck) {
    $query = $db->prepare('SELECT * FROM lockers WHERE truck_id = :truck_id order by name');
    $query->execute(['truck_id' => $truck['id']]);
    $lockers = $query->fetchAll(PDO::FETCH_ASSOC);

    $truckData = [
        'id' => $truck['id'],
        'name' => $truck['name'],
        'relief' => $truck['relief'],
        'lockers' => [],
    ];

    foreach ($lockers as $locker) {
        $locker_status = get_locker_status($locker['id'], $db, $colours);
        $background_color = $truck['relief'] ? '#808080' : $locker_status['status'];
        $last_checked = $locker_status['check'] ? $locker_status['check']['check_date'] : 'Never';
        $last_checked_display = $last_checked !== 'Never' ? convertToNZST($last_checked) : $last_checked;
        $checked_by = $locker_status['check'] ? $locker_status['check']['checked_by'] : 'N/A';

        $truckData['lockers'][] = [
            'id' => $locker['id'],
            'name' => $locker['name'],
            'background_color' => $background_color,
            'last_checked' => $last_checked_display,
            'checked_by' => $checked_by,
            'missing_items' => $locker_status['missing_items'],
            'has_missing_items' => !empty($locker_status['missing_items']),
        ];
    }

    $data['trucks'][] = $truckData;
}

echo json_encode($data);
