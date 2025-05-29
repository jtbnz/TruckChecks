<?php

include 'db.php';
include_once('auth.php');

// Define CHECKPROTECT constant if not already defined
if (!defined('CHECKPROTECT')) {
    define('CHECKPROTECT', true); // Enable security code protection by default
}

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

// Check if the version session variable is not set
if (!isset($_SESSION['version'])) {
    // Get the latest Git tag version
    $version = trim(exec('git describe --tags $(git rev-list --tags --max-count=1)'));
    
    // Set the session variable
    $_SESSION['version'] = $version;
} else {
    // Use the already set session variable
    $version = $_SESSION['version'];
}

// Handle station selection from dropdown
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['selected_station'])) {
    $stationId = (int)$_POST['selected_station'];
    
    setcookie('preferred_station', $stationId, time() + (365 * 24 * 60 * 60), "/");
    $_SESSION['current_station_id'] = $stationId;
    
    header('Location: changeover.php');
    exit;
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

// Security code validation function
function is_code_valid($db, $code, $station_id = null) {
    if ($station_id) {
        // Check station-specific security code
        $query = $db->prepare("SELECT COUNT(*) FROM station_settings WHERE station_id = :station_id AND setting_key = 'security_code' AND setting_value = :code");
        $query->execute(['station_id' => $station_id, 'code' => $code]);
        return $query->fetchColumn() > 0;
    }
    // No station context means no valid code
    return false;
}

// Handle security code validation AJAX request
if (CHECKPROTECT && isset($_GET['validate_code'])) {
    $code = $_GET['validate_code'];
    $station_id = $currentStation ? $currentStation['id'] : null;
    $is_valid = is_code_valid($db, $code, $station_id);
    echo json_encode(['valid' => $is_valid]);
    exit;
}

// Check if relief_items table exists, if not create it
$result = $db->query("SHOW TABLES LIKE 'relief_items'");
if ($result->rowCount() == 0) {
    $db->exec("CREATE TABLE relief_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        truck_name VARCHAR(255) NOT NULL,
        locker_name VARCHAR(255) NOT NULL,
        item_name VARCHAR(255) NOT NULL,
        item_id INT NOT NULL,
        relief BOOLEAN DEFAULT FALSE
    )");
}

// Fetch trucks filtered by current station
if ($currentStation) {
    $trucks_query = $db->prepare('SELECT id, name, relief FROM trucks WHERE station_id = ? ORDER BY name');
    $trucks_query->execute([$currentStation['id']]);
} else {
    // Legacy behavior - show all trucks
    $trucks_query = $db->query('SELECT id, name, relief FROM trucks ORDER BY name');
}
$trucks = $trucks_query->fetchAll(PDO::FETCH_ASSOC);

// Check if a truck has been selected
$selected_truck_id = isset($_GET['truck_id']) ? $_GET['truck_id'] : null;
$selected_truck = null;
$truck_relief_state = false;

// Handle relief state toggle
if (isset($_POST['toggle_relief'])) {
    $truck_id = $_POST['truck_id'];
    $new_state = $_POST['relief_state'] == '1' ? 1 : 0;
    
    // Verify truck belongs to current station (if stations are enabled)
    if ($currentStation) {
        $verify_query = $db->prepare('SELECT id FROM trucks WHERE id = ? AND station_id = ?');
        $verify_query->execute([$truck_id, $currentStation['id']]);
        if (!$verify_query->fetch()) {
            header('Location: changeover.php');
            exit;
        }
    }
    
    $update_query = $db->prepare('UPDATE trucks SET relief = ? WHERE id = ?');
    $update_query->execute([$new_state, $truck_id]);
    
    // If turning relief on, insert items into relief_items table if they don't exist
    if ($new_state == 1) {
        // Get truck name
        $truck_query = $db->prepare('SELECT name FROM trucks WHERE id = ?');
        $truck_query->execute([$truck_id]);
        $truck_name = $truck_query->fetchColumn();
        
        // Get all items for this truck
        $items_query = $db->prepare("
            SELECT i.id as item_id, i.name as item_name, l.name as locker_name, t.name as truck_name 
            FROM items i
            JOIN lockers l ON i.locker_id = l.id
            JOIN trucks t ON l.truck_id = t.id
            WHERE t.id = ?
        ");
        $items_query->execute([$truck_id]);
        $items = $items_query->fetchAll(PDO::FETCH_ASSOC);
        
        // Create an array of current item IDs
        $current_item_ids = array_column($items, 'item_id');
        
        // Delete items from relief_items table that no longer exist in the truck
        if (count($current_item_ids) > 0) {
            $placeholders = implode(',', array_fill(0, count($current_item_ids), '?'));
            $delete_query = $db->prepare("DELETE FROM relief_items WHERE truck_name = ? AND item_id NOT IN ($placeholders)");
            $params = array_merge([$truck_name], $current_item_ids);
            $delete_query->execute($params);
        } else {
            // If no items exist for the truck, delete all relief_items entries for this truck
            $delete_query = $db->prepare("DELETE FROM relief_items WHERE truck_name = ?");
            $delete_query->execute([$truck_name]);
        }
        
        // Insert items into relief_items if they don't exist
        $check_query = $db->prepare("SELECT COUNT(*) FROM relief_items WHERE truck_name = ? AND item_id = ?");
        $insert_query = $db->prepare("
            INSERT INTO relief_items (truck_name, locker_name, item_name, item_id, relief) 
            VALUES (?, ?, ?, ?, FALSE)
        ");
        
        foreach ($items as $item) {
            $check_query->execute([$truck_name, $item['item_id']]);
            $exists = $check_query->fetchColumn();
            
            if ($exists == 0) {
                $insert_query->execute([
                    $truck_name,
                    $item['locker_name'],
                    $item['item_name'],
                    $item['item_id'],
                ]);
            }
        }
    }
    
    // Redirect to prevent form resubmission
    header("Location: changeover.php?truck_id=" . $truck_id);
    exit;
}

// Handle AJAX request to update item relief status
if (isset($_POST['action']) && $_POST['action'] == 'update_item_relief') {
    $item_id = $_POST['item_id'];
    $relief_status = $_POST['relief_status'] == 'true' ? 1 : 0;
    $truck_name = $_POST['truck_name'];
    
    $update_query = $db->prepare("UPDATE relief_items SET relief = ? WHERE truck_name = ? AND item_id = ?");
    $update_query->execute([$relief_status, $truck_name, $item_id]);
    
    echo json_encode(['success' => true]);
    exit;
}

// Get selected truck details if one is selected
if ($selected_truck_id) {
    if ($currentStation) {
        // Verify truck belongs to current station
        $truck_query = $db->prepare('SELECT name, relief FROM trucks WHERE id = ? AND station_id = ?');
        $truck_query->execute([$selected_truck_id, $currentStation['id']]);
    } else {
        // Legacy behavior
        $truck_query = $db->prepare('SELECT name, relief FROM trucks WHERE id = ?');
        $truck_query->execute([$selected_truck_id]);
    }
    
    $selected_truck = $truck_query->fetch(PDO::FETCH_ASSOC);
    
    if ($selected_truck) {
        $truck_relief_state = $selected_truck['relief'];
        
        // Get lockers for this truck
        $lockers_query = $db->prepare("
            SELECT l.id, l.name
            FROM lockers l
            WHERE l.truck_id = ?
            ORDER BY l.name
        ");
        $lockers_query->execute([$selected_truck_id]);
        $lockers = $lockers_query->fetchAll(PDO::FETCH_ASSOC);
        
        // Get items for each locker
        $items_by_locker = [];
        $relief_items = [];
        
        if ($truck_relief_state) {
            // Get relief status for each item
            $relief_query = $db->prepare("
                SELECT item_id, relief
                FROM relief_items
                WHERE truck_name = ?
            ");
            $relief_query->execute([$selected_truck['name']]);
            
            while ($row = $relief_query->fetch(PDO::FETCH_ASSOC)) {
                $relief_items[$row['item_id']] = $row['relief'];
            }
        }
        
        foreach ($lockers as $locker) {
            $items_query = $db->prepare("
                SELECT i.id, i.name
                FROM items i
                WHERE i.locker_id = ?
                ORDER BY i.name
            ");
            $items_query->execute([$locker['id']]);
            $items = $items_query->fetchAll(PDO::FETCH_ASSOC);
            
            $items_by_locker[$locker['id']] = [
                'locker_name' => $locker['name'],
                'items' => $items
            ];
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Truck Change Over Relief Mode">
    <link rel="stylesheet" href="styles/styles.css?id=<?php echo $version; ?>">
    <title>Relief Truck Management</title>
    <script>
        function getCookie(name) {
            const value = `; ${document.cookie}`;
            const parts = value.split(`; ${name}=`);
            if (parts.length === 2) return parts.pop().split(';').shift();
            return null;
        }

        function checkProtection() {
            const CHECKPROTECT = <?php 
                // Check if security code is in session as fallback
                $has_session_code = $currentStation && isset($_SESSION['security_code_station_' . $currentStation['id']]);
                echo (CHECKPROTECT && $currentStation) ? 'true' : 'false'; 
            ?>;
            
            if (CHECKPROTECT) {
                let code = null;
                
                // First try to get station-specific security code from cookie
                <?php if ($currentStation): ?>
                // First try localStorage (persists across browser restarts)
                let stationCode = localStorage.getItem('security_code_station_<?= $currentStation['id'] ?>');
                if (!stationCode) {
                    // Fallback to cookie
                    stationCode = getCookie('security_code_station_<?= $currentStation['id'] ?>');
                }
                if (stationCode) {
                    code = stationCode;
                }
                <?php endif; ?>
                
                // Fallback to general security code cookie
                if (!code) {
                    code = getCookie('security_code');
                }
                
                // Fallback to localStorage for backward compatibility
                if (!code) {
                    code = localStorage.getItem('protection_code');
                }
                
                if (!code) {
                    alert('Access denied. Missing security code. Please scan the QR code from your station admin.');
                    window.location.href = 'index.php';
                } else {
                    fetch('changeover.php?validate_code=' + code)
                        .then(response => response.json())
                        .then(data => {
                            if (!data.valid) {
                                alert('Access denied. Invalid security code. Please scan the QR code from your station admin.');
                                window.location.href = 'index.php';
                            }
                        })
                        .catch(error => {
                            console.error('Security validation error:', error);
                            alert('Security validation failed. Please try again.');
                            window.location.href = 'index.php';
                        });
                }
            }
        }
        window.onload = checkProtection;
    </script>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f0f0f0;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        h1, h2 {
            color: #333;
        }
        
        .station-info {
            text-align: center;
            margin-bottom: 30px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 5px;
        }

        .station-name {
            font-size: 18px;
            font-weight: bold;
            color: #12044C;
        }

        .station-selection {
            max-width: 500px;
            margin: 50px auto;
            padding: 30px;
            background-color: #f9f9f9;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .station-dropdown {
            width: 100%;
            padding: 15px;
            font-size: 16px;
            border: 1px solid #ccc;
            border-radius: 5px;
            margin: 20px 0;
        }
        
        .truck-selection {
            margin-bottom: 20px;
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .truck-selection select {
            width: 100%;
            padding: 12px;
            font-size: 16px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }
        
        .locker-section {
            background-color: #fff;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .locker-header {
            background-color: #f8f8f8;
            padding: 10px;
            margin: -15px -15px 15px -15px;
            border-radius: 8px 8px 0 0;
            border-bottom: 1px solid #ddd;
        }
        .item-row {
            display: flex;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        .item-name {
            flex-grow: 1;
        }
        .relief-status {
            width: 150px;
        }
        .truck-relief-toggle {
            margin: 20px 0;
            padding: 20px;
            background-color: #e9f5fd;
            border-radius: 8px;
            border: 1px solid #b8daff;
        }
        
        /* Switch styles */
        .switch-flat {
            position: relative;
            display: inline-block;
            width: 100px;
            height: 34px;
            padding: 0;
            background: #FFF;
            background-image: none;
        }
        .switch-flat .switch-input {
            display: none;
        }
        .switch-flat .switch-label {
            position: relative;
            display: block;
            height: inherit;
            font-size: 10px;
            text-transform: uppercase;
            background: #FFF;
            border: solid 2px #eceeef;
            border-radius: 4px;
            box-shadow: none;
        }
        .switch-flat .switch-label:before, .switch-flat .switch-label:after {
            position: absolute;
            top: 50%;
            margin-top: -.5em;
            line-height: 1;
            -webkit-transition: inherit;
            -moz-transition: inherit;
            -o-transition: inherit;
            transition: inherit;
        }
        .switch-flat .switch-label:before {
            content: attr(data-off);
            right: 11px;
            color: #000;
        }
        .switch-flat .switch-label:after {
            content: attr(data-on);
            left: 11px;
            color: #0088cc;
            opacity: 0;
        }
        .switch-flat .switch-handle {
            position: absolute;
            top: 6px;
            left: 6px;
            width: 22px;
            height: 22px;
            background: #dadada;
            border-radius: 4px;
            box-shadow: none;
            -webkit-transition: all 0.3s ease;
            -moz-transition: all 0.3s ease;
            -o-transition: all 0.3s ease;
            transition: all 0.3s ease;
        }
        .switch-flat .switch-handle:before {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            margin: -6px 0 0 -6px;
            width: 12px;
            height: 12px;
            background: #eceeef;
            border-radius: 6px;
        }
        .switch-flat .switch-input:checked ~ .switch-label {
            background: #FFF;
            border-color: #0088cc;
        }
        .switch-flat .switch-input:checked ~ .switch-label:before {
            opacity: 0;
        }
        .switch-flat .switch-input:checked ~ .switch-label:after {
            opacity: 1;
        }
        .switch-flat .switch-input:checked ~ .switch-handle {
            left: 72px;
            background: #0088cc;
            box-shadow: none;
        }
        
        .submit-button {
            background-color: #12044C;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.3s;
        }
        .submit-button:hover {
            background-color: #0056b3;
        }
        
        .button {
            display: inline-block;
            padding: 12px 24px;
            background-color: #12044C;
            color: white;
            text-decoration: none;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            transition: background-color 0.3s;
            margin: 5px;
        }

        .button:hover {
            background-color: #0056b3;
        }

        .button.secondary {
            background-color: #6c757d;
        }

        .button.secondary:hover {
            background-color: #545b62;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Relief Truck Management</h1>
        
        <?php if (!empty($stations) && !$currentStation && count($stations) > 1): ?>
            <!-- Station Selection -->
            <div class="station-selection">
                <h2>Select Station</h2>
                <p>Please select a station to manage relief trucks:</p>
                
                <form method="post" action="">
                    <select name="selected_station" class="station-dropdown" required>
                        <option value="">-- Select a Station --</option>
                        <?php foreach ($stations as $station): ?>
                            <option value="<?= $station['id'] ?>"><?= htmlspecialchars($station['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    
                    <button type="submit" class="submit-button">Select Station</button>
                </form>
            </div>
        <?php else: ?>
            <?php if ($currentStation): ?>
                <div class="station-info">
                    <div class="station-name"><?= htmlspecialchars($currentStation['name']) ?></div>
                    <?php if ($currentStation['description']): ?>
                        <div style="color: #666; margin-top: 5px;"><?= htmlspecialchars($currentStation['description']) ?></div>
                    <?php endif; ?>
                    <?php if (count($stations) > 1): ?>
                        <div style="margin-top: 10px;">
                            <a href="changeover.php" onclick="return changeStation()" style="color: #12044C; text-decoration: none; font-size: 14px;">
                                Change Station
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <div class="truck-selection">
                <h2>Select a Truck</h2>
                <form method="GET" action="changeover.php">
                    <select name="truck_id" id="truck_id" onchange="this.form.submit()">
                        <option value="">-- Select Truck --</option>
                        <?php foreach ($trucks as $truck): ?>
                            <option value="<?= $truck['id'] ?>" <?= $selected_truck_id == $truck['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($truck['name']) ?> <?= $truck['relief'] ? '(Relief)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                
                <?php if (empty($trucks)): ?>
                    <p style="color: #666; margin-top: 15px;">
                        No trucks found for this station. Please add trucks in the admin panel.
                    </p>
                <?php endif; ?>
            </div>
            
            <?php if ($selected_truck): ?>
                <div class="truck-relief-toggle">
                    <h2>Truck Status</h2>
                    <p>Current Status: <?= $truck_relief_state ? 'Relief Truck' : 'Normal' ?></p>
                    
                    <form method="POST" action="changeover.php">
                        <input type="hidden" name="truck_id" value="<?= $selected_truck_id ?>">
                        <input type="hidden" name="relief_state" value="<?= $truck_relief_state ? '0' : '1' ?>">
                        <button type="submit" name="toggle_relief" class="submit-button">
                            Set <?= htmlspecialchars($selected_truck['name']) ?> to <?= $truck_relief_state ? 'Normal' : 'Relief Truck' ?>
                        </button>
                    </form>
                </div>
                
                <?php if ($truck_relief_state): ?>
                    <div class="relief-instructions" style="background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px; padding: 15px; margin: 20px 0;">
                        <p>This truck is in Relief mode. Toggle the switches below to indicate which items have been moved to the relief truck.</p>
                        <p>Don't forget non tracked items such as Station keys, Door remotes, tablets etc.</p> 
                    </div>
                <?php endif; ?>
                
                <?php if (isset($items_by_locker)): ?>
                    <?php foreach ($items_by_locker as $locker_id => $locker_data): ?>
                        <div class="locker-section">
                            <div class="locker-header">
                                <h3><?= htmlspecialchars($locker_data['locker_name']) ?></h3>
                            </div>
                            
                            <?php foreach ($locker_data['items'] as $item): ?>
                                <div class="item-row">
                                    <div class="item-name"><?= htmlspecialchars($item['name']) ?></div>
                                    <div class="relief-status">
                                        <?php if ($truck_relief_state): ?>
                                            <?php 
                                            $is_relief = isset($relief_items[$item['id']]) ? $relief_items[$item['id']] : false;
                                            ?>
                                            <label class="switch switch-flat">
                                                <input class="switch-input item-toggle" type="checkbox" 
                                                       data-item-id="<?= $item['id'] ?>" 
                                                       data-truck-name="<?= htmlspecialchars($selected_truck['name']) ?>" 
                                                       <?= $is_relief ? 'checked' : '' ?> />
                                                <span class="switch-label" data-on="Relief" data-off="<?= htmlspecialchars($selected_truck['name']) ?>"></span> 
                                                <span class="switch-handle"></span> 
                                            </label>
                                        <?php else: ?>
                                            <span>On <?= htmlspecialchars($selected_truck['name']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <footer style="text-align: center; margin-top: 40px;">
        <a href="index.php" class="button secondary">Return to Home</a>
    </footer>
    
    <script>
    function changeStation() {
        document.cookie = 'preferred_station=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
        
        if (typeof(Storage) !== "undefined") {
            sessionStorage.removeItem('current_station_id');
        }
        
        return true;
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        // Get all item toggles
        var toggles = document.querySelectorAll('.item-toggle');
        
        // Add event listener to each toggle
        toggles.forEach(function(toggle) {
            toggle.addEventListener('change', function() {
                var itemId = this.getAttribute('data-item-id');
                var truckName = this.getAttribute('data-truck-name');
                var isChecked = this.checked;
                
                // Send AJAX request to update item relief status
                var xhr = new XMLHttpRequest();
                xhr.open('POST', 'changeover.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        var response = JSON.parse(xhr.responseText);
                        if (!response.success) {
                            alert('Failed to update item status');
                        }
                    } else {
                        alert('Failed to update item status');
                    }
                };
                xhr.send('action=update_item_relief&item_id=' + itemId + '&truck_name=' + encodeURIComponent(truckName) + '&relief_status=' + isChecked);
            });
        });
    });
    </script>
</body>
</html>
