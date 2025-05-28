<?php
// Include the database connection
include 'db.php';
include_once('auth.php');

// Get current station context (no authentication required for public view)
$stations = [];
$currentStation = null;

try {
    $db = get_db_connection();
    $stmt = $db->prepare("SELECT * FROM stations ORDER BY name");
    $stmt->execute();
    $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Stations table not found, using legacy mode: " . $e->getMessage());
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get current station for filtering
if (!empty($stations)) {
    if (isset($_SESSION['current_station_id'])) {
        foreach ($stations as $station) {
            if ($station['id'] == $_SESSION['current_station_id']) {
                $currentStation = $station;
                break;
            }
        }
    }
    
    if (!$currentStation && isset($_COOKIE['preferred_station'])) {
        foreach ($stations as $station) {
            if ($station['id'] == $_COOKIE['preferred_station']) {
                $currentStation = $station;
                $_SESSION['current_station_id'] = $station['id'];
                break;
            }
        }
    }
    
    if (!$currentStation && count($stations) === 1) {
        $currentStation = $stations[0];
        $_SESSION['current_station_id'] = $currentStation['id'];
        setcookie('preferred_station', $currentStation['id'], time() + (365 * 24 * 60 * 60), "/");
    }
}

if (isset($_POST['item'])) {
    $item_name = trim($_POST['item']);

    // Prepare a SQL query to search for the item in the database
    if ($currentStation) {
        // Filter by current station
        $sql = "SELECT i.name as item_name, t.name as truck_name, l.name as locker_name
                FROM items i
                JOIN lockers l ON i.locker_id = l.id
                JOIN trucks t ON l.truck_id = t.id
                WHERE i.name LIKE :item_name AND t.station_id = :station_id
                ORDER BY t.name, l.name";
        $stmt = $db->prepare($sql);
        $stmt->execute(['item_name' => "%$item_name%", 'station_id' => $currentStation['id']]);
    } else {
        // Legacy behavior - search all items
        $sql = "SELECT i.name as item_name, t.name as truck_name, l.name as locker_name
                FROM items i
                JOIN lockers l ON i.locker_id = l.id
                JOIN trucks t ON l.truck_id = t.id
                WHERE i.name LIKE :item_name
                ORDER BY t.name, l.name";
        $stmt = $db->prepare($sql);
        $stmt->execute(['item_name' => "%$item_name%"]);
    }
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($results) {
        echo "<h3>Results for '$item_name'";
        if ($currentStation) {
            echo " in " . htmlspecialchars($currentStation['name']);
        }
        echo ":</h3>";
        
        foreach ($results as $result) {
            echo "<p>Item: <strong>" . htmlspecialchars($result['item_name']) . "</strong> 
                  is in Truck: <strong>" . htmlspecialchars($result['truck_name']) . "</strong>, 
                  Locker: <strong>" . htmlspecialchars($result['locker_name']) . "</strong></p>";
        }
    } else {
        echo "<p>No results found for '$item_name'";
        if ($currentStation) {
            echo " in " . htmlspecialchars($currentStation['name']);
        }
        echo ".</p>";
    }
} else {
    echo "<p>Error: No item provided.</p>";
}
?>
