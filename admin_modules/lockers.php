<?php
// admin_modules/lockers.php

// Ensure session is started if not already
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Determine the correct base path for includes, assuming this file is in admin_modules/
$basePath = __DIR__ . '/../';

// Include necessary core files
require_once $basePath . 'config.php'; // Defines DEBUG, etc.
require_once $basePath . 'db.php';     // Defines get_db_connection()
require_once $basePath . 'auth.php';   // Defines requireAuth(), getCurrentUser(), getCurrentStation()

// Initialize database connection
$pdo = get_db_connection();
$db = $pdo; // Keep $db alias if used later in this file

// For AJAX calls made directly to this script, $user might not be globally available yet.
// For HTML rendering when included by admin.php, $user should be set.
// We ensure authentication and user context here for self-sufficiency.
// Note: admin.php already calls requireAuth() and sets $user before including this module for HTML page loads.
// This check is primarily for direct AJAX calls to this script.
if (!isset($user) || !$user) { // If $user is not set or is falsy
    requireAuth(); // Ensure user is authenticated for this module
    $user = getCurrentUser();
}

$userRole = $user['role'] ?? null;
$userName = $user['username'] ?? null;

// $DEBUG is defined in config.php and might be used in catch blocks.
// It's typically made global if needed within functions, but top-level access is direct.

// Station determination logic
$current_station_id = null;
$current_station_name = "No station selected";

if ($userRole === 'superuser') {
    // For superuser, getCurrentStation() from auth.php gets the station from session
    $stationData = getCurrentStation(); // getCurrentStation() uses $pdo internally
    if ($stationData && isset($stationData['id'])) {
        $current_station_id = $stationData['id'];
        $current_station_name = $stationData['name'];
    }
} elseif ($userRole === 'station_admin') {
    $userStationsForModule = []; // Local variable
    try {
        // Ensure $user['id'] is available
        if (isset($user['id'])) {
            $stmt_ua = $pdo->prepare("SELECT s.id, s.name FROM stations s JOIN user_stations us ON s.id = us.station_id WHERE us.user_id = ? ORDER BY s.name");
            $stmt_ua->execute([$user['id']]);
            $userStationsForModule = $stmt_ua->fetchAll(PDO::FETCH_ASSOC);
        } else {
            error_log("User ID not available for station_admin in lockers.php");
        }
    } catch (PDOException $e) {
        error_log("Error fetching user stations in lockers.php: " . $e->getMessage());
    }

    if (count($userStationsForModule) === 1) {
        $current_station_id = $userStationsForModule[0]['id'];
        $current_station_name = $userStationsForModule[0]['name'];
        if (session_status() == PHP_SESSION_ACTIVE && (!isset($_SESSION['selected_station_id']) || $_SESSION['selected_station_id'] != $current_station_id) ) {
            $_SESSION['selected_station_id'] = $current_station_id;
        }
    } elseif (isset($_SESSION['selected_station_id'])) {
        $is_valid_selection = false;
        foreach ($userStationsForModule as $s) {
            if ($s['id'] == $_SESSION['selected_station_id']) {
                $current_station_id = $s['id'];
                $current_station_name = $s['name'];
                $is_valid_selection = true;
                break;
            }
        }
        if (!$is_valid_selection) {
            if (session_status() == PHP_SESSION_ACTIVE) unset($_SESSION['selected_station_id']);
            $current_station_id = null;
            $current_station_name = "No valid station selected";
        }
    } else {
         $current_station_id = null;
         $current_station_name = count($userStationsForModule) > 0 ? "Please select a station" : "No stations assigned";
    }
}
// For other roles, $current_station_id will remain null.

$error_message = '';
$success_message = '';

// Handle AJAX actions (POST for CUD operations)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $response = ['success' => false, 'message' => 'Invalid action or station not selected.'];

    if (!$current_station_id) { // Most POST actions require a station context
        echo json_encode($response);
        exit;
    }

    if ($action === 'add_truck') {
        $truck_name = trim($_POST['truck_name'] ?? '');
        if (!empty($truck_name) && $current_station_id) {
            try {
                $stmt_check = $pdo->prepare("SELECT id FROM trucks WHERE name = ? AND station_id = ?");
                $stmt_check->execute([$truck_name, $current_station_id]);
                if ($stmt_check->fetch()) {
                    $response = ['success' => false, 'message' => 'A truck with this name already exists for this station.'];
                } else {
                    $stmt = $pdo->prepare("INSERT INTO trucks (name, station_id) VALUES (?, ?)");
                    $stmt->execute([$truck_name, $current_station_id]);
                    $truck_id = $pdo->lastInsertId();
                    $response = ['success' => true, 'message' => 'Truck added successfully.', 'truck_id' => $truck_id, 'truck_name' => $truck_name];
                }
            } catch (PDOException $e) {
                error_log("Error adding truck: " . $e->getMessage());
                $response['message'] = 'Database error adding truck. ' . (isset($DEBUG) && $DEBUG ? $e->getMessage() : '');
            }
        } else if (empty($truck_name)) {
            $response['message'] = 'Truck name cannot be empty.';
        } else {
            $response['message'] = 'Station not selected or invalid truck name.';
        }
    } elseif ($action === 'add_locker') {
        $locker_name = trim($_POST['locker_name'] ?? '');
        $truck_id = $_POST['truck_id_for_locker'] ?? '';

        if (!empty($locker_name) && !empty($truck_id) && $current_station_id) {
            try {
                // Verify the truck belongs to the current station
                $stmt_truck_check = $pdo->prepare("SELECT id FROM trucks WHERE id = ? AND station_id = ?");
                $stmt_truck_check->execute([$truck_id, $current_station_id]);
                if (!$stmt_truck_check->fetch()) {
                    $response = ['success' => false, 'message' => 'Selected truck does not belong to this station.'];
                } else {
                    // Check if locker with the same name already exists for this truck
                    $stmt_check = $pdo->prepare("SELECT id FROM lockers WHERE name = ? AND truck_id = ?");
                    $stmt_check->execute([$locker_name, $truck_id]);
                    if ($stmt_check->fetch()) {
                        $response = ['success' => false, 'message' => 'A locker with this name already exists for this truck.'];
                    } else {
                        // Corrected: Remove station_id from lockers insert, it's derived from truck
                        $stmt = $pdo->prepare("INSERT INTO lockers (name, truck_id) VALUES (?, ?)");
                        $stmt->execute([$locker_name, $truck_id]);
                        $locker_id = $pdo->lastInsertId();
                        $response = ['success' => true, 'message' => 'Locker added successfully.', 'locker_id' => $locker_id, 'locker_name' => $locker_name, 'truck_id' => $truck_id];
                    }
                }
            } catch (PDOException $e) {
                error_log("Error adding locker: " . $e->getMessage());
                $response['message'] = 'Database error adding locker. ' . (isset($DEBUG) && $DEBUG ? $e->getMessage() : '');
            }
        } else if (empty($locker_name)) {
            $response['message'] = 'Locker name cannot be empty.';
        } else if (empty($truck_id)) {
            $response['message'] = 'Please select a truck to assign the locker to.';
        } else {
            $response['message'] = 'Station not selected or invalid locker/truck details.';
        }
    } elseif ($action === 'add_item') {
        $item_name = trim($_POST['item_name'] ?? '');
        $locker_id = $_POST['locker_id_for_item'] ?? '';

        if (!empty($item_name) && !empty($locker_id) && $current_station_id) {
            try {
                // Corrected: Verify the locker (via its truck) belongs to the current station
                $stmt_locker_station_check = $pdo->prepare("
                    SELECT l.id 
                    FROM lockers l
                    JOIN trucks t ON l.truck_id = t.id
                    WHERE l.id = ? AND t.station_id = ?
                ");
                $stmt_locker_station_check->execute([$locker_id, $current_station_id]);

                if (!$stmt_locker_station_check->fetch()) {
                    $response = ['success' => false, 'message' => 'Selected locker does not belong to the current station or does not exist.'];
                } else {
                    // Check if item with the same name already exists in this locker
                    $stmt_check_item_exists = $pdo->prepare("SELECT id FROM items WHERE name = ? AND locker_id = ?");
                    $stmt_check_item_exists->execute([$item_name, $locker_id]);
                    if ($stmt_check_item_exists->fetch()) {
                        $response = ['success' => false, 'message' => 'An item with this name already exists in this locker.'];
                    } else {
                        $user_id_to_log = isset($user['id']) ? $user['id'] : null; 
                        // items table DOES have station_id, so $current_station_id is correct here.
                        $stmt_insert_item = $pdo->prepare("INSERT INTO items (name, locker_id, station_id, quantity, is_present, last_checked_by, last_checked_timestamp) VALUES (?, ?, ?, 1, 1, ?, NOW())");
                        $stmt_insert_item->execute([$item_name, $locker_id, $current_station_id, $user_id_to_log]);
                        $item_id = $pdo->lastInsertId();
                        $response = ['success' => true, 'message' => 'Item added successfully.', 'item_id' => $item_id, 'item_name' => $item_name, 'locker_id' => $locker_id];
                    }
                }
            } catch (PDOException $e) {
                error_log("Error adding item: " . $e->getMessage());
                $response['message'] = 'Database error adding item. ' . (isset($DEBUG) && $DEBUG ? $e->getMessage() : '');
            }
        } else if (empty($item_name)) {
            $response['message'] = 'Item name cannot be empty.';
        } else if (empty($locker_id)) {
            $response['message'] = 'Please select a locker to assign the item to.';
        } else {
            $response['message'] = 'Station not selected or invalid item/locker details.';
        }
    } elseif ($action === 'edit_item') {
        $item_id = $_POST['item_id'] ?? null;
        $new_item_name = trim($_POST['item_name'] ?? '');
        $new_locker_id = $_POST['locker_id'] ?? null;
        // $new_truck_id = $_POST['truck_id'] ?? null; // Truck ID from form, used to validate locker

        if (empty($item_id) || empty($new_item_name) || empty($new_locker_id)) {
            $response = ['success' => false, 'message' => 'Item ID, name, and locker are required.'];
        } elseif (!$current_station_id) {
            $response = ['success' => false, 'message' => 'Current station context is missing. Cannot edit item.'];
        } else {
            try {
                // Verify the new locker (via its truck) belongs to the current station
                $stmt_locker_check = $pdo->prepare("
                    SELECT l.id 
                    FROM lockers l
                    JOIN trucks t ON l.truck_id = t.id
                    WHERE l.id = ? AND t.station_id = ?
                ");
                $stmt_locker_check->execute([$new_locker_id, $current_station_id]);
                if (!$stmt_locker_check->fetch()) {
                    $response = ['success' => false, 'message' => 'The selected new locker does not belong to the current station or does not exist.'];
                } else {
                    // Check if an item with the new name already exists in the new locker (excluding the current item being edited)
                    $stmt_item_exists = $pdo->prepare("SELECT id FROM items WHERE name = ? AND locker_id = ? AND id != ?");
                    $stmt_item_exists->execute([$new_item_name, $new_locker_id, $item_id]);
                    if ($stmt_item_exists->fetch()) {
                        $response = ['success' => false, 'message' => 'Another item with this name already exists in the selected locker.'];
                    } else {
                        // Update the item. The station_id for the item should remain $current_station_id
                        // as the locker (and its truck) has been verified to be in this station.
                        $stmt_update = $pdo->prepare("UPDATE items SET name = ?, locker_id = ?, station_id = ? WHERE id = ? AND station_id = ?");
                        // We also ensure the item being updated belongs to the current station for security.
                        $stmt_update->execute([$new_item_name, $new_locker_id, $current_station_id, $item_id, $current_station_id]);
                        
                        if ($stmt_update->rowCount() > 0) {
                            $response = ['success' => true, 'message' => 'Item updated successfully.'];
                        } else {
                            // This could happen if the item ID didn't exist or didn't belong to the station,
                            // or if no actual data changed.
                            $response = ['success' => false, 'message' => 'Item not updated. It might not exist, not belong to this station, or no changes were made.'];
                        }
                    }
                }
            } catch (PDOException $e) {
                error_log("Error editing item: " . $e->getMessage());
                // Always include the specific PDO exception message for better debugging via AJAX response
                $response = ['success' => false, 'message' => 'Database error editing item: ' . $e->getMessage()];
            }
        }
    }

    echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Handle GET AJAX actions (e.g., fetching lists for dynamic updates)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_GET['ajax_action'];
    $response = ['success' => false, 'message' => 'Invalid action.', 'data' => []];

    if ($action === 'get_lockers_for_truck') {
        $truck_id_filter = $_GET['truck_id'] ?? null;
        // $current_station_id is crucial here to ensure security and context
        if ($truck_id_filter && $current_station_id) {
            try {
                // Corrected: Ensure the truck itself belongs to the current station before fetching its lockers
                $stmt = $pdo->prepare("
                    SELECT l.id, l.name 
                    FROM lockers l
                    JOIN trucks t ON l.truck_id = t.id
                    WHERE l.truck_id = ? AND t.station_id = ? 
                    ORDER BY l.name
                ");
                $stmt->execute([$truck_id_filter, $current_station_id]);
                $lockers_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $response = ['success' => true, 'data' => $lockers_data];
            } catch (PDOException $e) {
                error_log("Error fetching lockers for truck: " . $e->getMessage());
                $response['message'] = 'Database error fetching lockers. ' . (isset($DEBUG) && $DEBUG ? $e->getMessage() : '');
            }
        } else if (!$current_station_id) {
             $response['message'] = 'Please select a station first.';
        } else { // $truck_id_filter is missing but $current_station_id is set
            $response['message'] = 'Truck ID missing for filtering lockers.';
        }
    } elseif ($action === 'get_filtered_items') {
        if (!$current_station_id) {
            $response = ['success' => false, 'message' => 'Station not selected.', 'data' => []];
        } else {
            $truck_id_filter = $_GET['truck_id'] ?? null;
            $locker_id_filter = $_GET['locker_id'] ?? null;

            $sql_items = "
                SELECT li.id, li.name AS item_name, l.id AS locker_id, l.name AS locker_name, t.id AS truck_id, t.name AS truck_name
                FROM items li
                JOIN lockers l ON li.locker_id = l.id
                JOIN trucks t ON l.truck_id = t.id
                WHERE t.station_id = :station_id"; // Always filter by current station

            $params = [':station_id' => $current_station_id];

            if (!empty($truck_id_filter)) {
                $sql_items .= " AND t.id = :truck_id";
                $params[':truck_id'] = $truck_id_filter;
            }
            if (!empty($locker_id_filter)) {
                $sql_items .= " AND l.id = :locker_id";
                $params[':locker_id'] = $locker_id_filter;
            }
            $sql_items .= " ORDER BY t.name, l.name, li.name";

            try {
                $stmt_items = $db->prepare($sql_items);
                $stmt_items->execute($params);
                $items_data = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
                $response = ['success' => true, 'data' => $items_data];
            } catch (PDOException $e) {
                error_log("Error fetching filtered items: " . $e->getMessage());
                $response = ['success' => false, 'message' => 'Database error fetching items. ' . (isset($DEBUG) && $DEBUG ? $e->getMessage() : ''), 'data' => []];
            }
        }
    }
    
    echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

?>

<style>
    .lockers-page-container {
        padding: 20px;
        font-family: Arial, sans-serif;
    }
    .station-context-info {
        font-size: 1.1em;
        margin-bottom: 20px;
        padding: 10px;
        background-color: #e9ecef;
        border-left: 4px solid #12044C; /* Accent color */
        border-radius: 4px;
    }
    .notice {
        padding: 15px;
        margin-bottom: 20px;
        border: 1px solid transparent;
        border-radius: 4px;
    }
    .notice-warning {
        color: #856404;
        background-color: #fff3cd;
        border-color: #ffeeba;
    }
    .notice i { /* For Font Awesome icons if used */
        margin-right: 8px;
    }

    /* Dashboard-like grid and cards */
    .lockers-dashboard-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); /* Adjusted minmax for potentially wider cards */
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .lockers-dashboard-card {
        background: white;
        border-radius: 10px;
        padding: 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        border-left: 4px solid #12044C; /* Primary accent color */
        display: flex;
        flex-direction: column; /* Ensure cards can grow if content is large */
    }
    
    .lockers-dashboard-card h3 {
        margin: 0 0 15px 0;
        color: #12044C;
        font-size: 18px;
        border-bottom: 1px solid #eee;
        padding-bottom: 10px;
    }
    .lockers-dashboard-card h3 i { /* Emoji styling */
        margin-right: 8px;
        font-style: normal; /* Prevent italics for emojis */
    }
    
    .lockers-dashboard-card h4 {
        margin-top: 20px;
        margin-bottom: 10px;
        font-size: 16px;
        color: #333;
    }
    .lockers-dashboard-card p {
        color: #666;
        margin-bottom: 15px;
        line-height: 1.5;
    }

    .form-group {
        margin-bottom: 15px;
    }
    .form-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
        color: #555;
    }
    .form-group input[type="text"],
    .form-group select {
        width: 100%;
        padding: 10px;
        border: 1px solid #ccc;
        border-radius: 4px;
        box-sizing: border-box;
        font-size: 14px;
    }
    .button, button { /* General button styling */
        padding: 10px 15px;
        background-color: #12044C;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
        transition: background-color 0.3s;
    }
    .button:hover, button:hover {
        background-color: #0056b3; /* Darker shade on hover */
    }
    .button.secondary {
        background-color: #6c757d;
    }
    .button.secondary:hover {
        background-color: #545b62;
    }

    .entity-list {
        list-style-type: none;
        padding: 0;
        max-height: 300px; /* Add scroll for long lists */
        overflow-y: auto;
        border: 1px solid #eee;
        border-radius: 4px;
    }
    .entity-list li {
        padding: 10px;
        border-bottom: 1px solid #eee;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .entity-list li:last-child {
        border-bottom: none;
    }
    .entity-list .actions button {
        margin-left: 5px;
        padding: 5px 8px;
        font-size: 12px;
    }
    .scrollable-list-container { /* Wrapper for lists that might get long */
        max-height: 250px;
        overflow-y: auto;
        border: 1px solid #e0e0e0;
        border-radius: 4px;
        padding: 0; /* Reset padding if ul has its own */
        margin-top: 10px;
    }
    .scrollable-list-container .entity-list {
        max-height: none; /* Disable max-height on ul if parent has it */
        border: none;
    }

    /* Modal Styles */
    .modal {
        display: none; 
        position: fixed; 
        z-index: 1000; 
        left: 0;
        top: 0;
        width: 100%; 
        height: 100%; 
        overflow: auto; 
        background-color: rgba(0,0,0,0.4); 
    }
    .modal-content {
        background-color: #fefefe;
        margin: 10% auto; 
        padding: 20px;
        border: 1px solid #888;
        width: 80%; 
        max-width: 500px;
        border-radius: 8px;
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    }
    .modal-header {
        padding-bottom: 10px;
        border-bottom: 1px solid #eee;
        margin-bottom: 20px;
    }
    .modal-header h4 {
        margin: 0;
        font-size: 1.2em;
        color: #333;
    }
    .close-button {
        color: #aaa;
        float: right;
        font-size: 28px;
        font-weight: bold;
    }
    .close-button:hover,
    .close-button:focus {
        color: black;
        text-decoration: none;
        cursor: pointer;
    }
    .modal-footer {
        padding-top: 10px;
        border-top: 1px solid #eee;
        margin-top: 20px;
        text-align: right;
    }
    .modal-footer .button {
        margin-left: 10px;
    }

</style>

<!-- Edit Item Modal -->
<div id="edit-item-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span class="close-button" onclick="closeEditItemModal()">&times;</span>
            <h4>Edit Item</h4>
        </div>
        <form id="edit-item-form">
            <input type="hidden" id="edit-item-id" name="item_id">
            <div class="form-group">
                <label for="edit-item-name">Item Name:</label>
                <input type="text" id="edit-item-name" name="item_name" required>
            </div>
            <div class="form-group">
                <label for="edit-item-truck-id">Truck:</label>
                <select id="edit-item-truck-id" name="truck_id" required onchange="loadLockersForEditModal(this.value)">
                    <option value="">-- Select Truck --</option>
                    <?php
                    // Populate with trucks available for the current station
                    // This $trucks_for_list variable needs to be populated by the new bootstrapping logic if $current_station_id is set
                    if ($current_station_id) {
                        try {
                            $stmt_trucks_modal = $db->prepare("SELECT id, name FROM trucks WHERE station_id = ? ORDER BY name");
                            $stmt_trucks_modal->execute([$current_station_id]);
                            $trucks_for_modal_list = $stmt_trucks_modal->fetchAll(PDO::FETCH_ASSOC);
                            if (count($trucks_for_modal_list) > 0) {
                                foreach ($trucks_for_modal_list as $truck_item_modal) {
                                    echo '<option value="' . htmlspecialchars($truck_item_modal['id'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($truck_item_modal['name'], ENT_QUOTES, 'UTF-8') . '</option>';
                                }
                            }
                        } catch (PDOException $e_modal_trucks) {
                            // Error fetching trucks for modal, log or handle
                            error_log("Error fetching trucks for edit modal dropdown: " . $e_modal_trucks->getMessage());
                        }
                    }
                    ?>
                </select>
            </div>
            <div class="form-group">
                <label for="edit-item-locker-id">Locker:</label>
                <select id="edit-item-locker-id" name="locker_id" required>
                    <option value="">-- Select Truck First --</option>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="button secondary" onclick="closeEditItemModal()">Cancel</button>
                <button type="button" class="button" id="save-edit-item-button">Save Changes</button>
            </div>
        </form>
    </div>
</div>


<div class="lockers-page-container">
    <h1>Lockers & Items Management</h1>

    <?php if ($current_station_id): ?>
        <p class="station-context-info">Managing for Station: <strong><?= htmlspecialchars($current_station_name, ENT_QUOTES, 'UTF-8') ?></strong></p>
        
        <div class="lockers-dashboard-grid">
            
            <!-- Trucks Card -->
            <div class="lockers-dashboard-card" id="trucks-management-card">
                <h3><i>🚛</i> Trucks</h3>
                <div id="add-truck-form-container">
                    <h4>Add New Truck</h4>
                    <form id="add-truck-form">
                        <input type="hidden" name="station_id" value="<?= htmlspecialchars($current_station_id, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="form-group">
                            <label for="new-truck-name">Truck Name:</label>
                            <input type="text" id="new-truck-name" name="truck_name" required>
                        </div>
                        <button type="submit" class="button">Add Truck</button>
                    </form>
                </div>
                <div id="trucks-list-container">
                    <h4>Existing Trucks</h4>
                    <div class="scrollable-list-container">
                        <ul id="trucks-list" class="entity-list">
                            <?php
                            // This $trucks_for_list variable is used by other dropdowns on the page.
                            // It should be populated here based on the $current_station_id.
                            $trucks_for_list = []; // Initialize
                            if ($current_station_id) {
                                try {
                                    $stmt_trucks_list = $db->prepare("SELECT id, name FROM trucks WHERE station_id = ? ORDER BY name");
                                    $stmt_trucks_list->execute([$current_station_id]);
                                    $trucks_for_list = $stmt_trucks_list->fetchAll(PDO::FETCH_ASSOC); 
                                    if (count($trucks_for_list) > 0) {
                                        foreach ($trucks_for_list as $truck_item) {
                                            echo '<li><span>' . htmlspecialchars($truck_item['name'], ENT_QUOTES, 'UTF-8') . '</span> <span class="actions"><!-- Edit/Delete buttons here --></span></li>';
                                        }
                                    } else {
                                        echo '<li>No trucks found for this station.</li>';
                                    }
                                } catch (PDOException $e) {
                                    echo '<li>Error fetching trucks: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</li>';
                                }
                            } else {
                                 echo '<li>Select a station to view trucks.</li>';
                            }
                            ?>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Lockers Card -->
            <div class="lockers-dashboard-card" id="lockers-management-card">
                <h3><i>🗄️</i> Lockers</h3>
                 <div id="add-locker-form-container">
                    <h4>Add New Locker</h4>
                    <form id="add-locker-form">
                        <div class="form-group">
                            <label for="select-truck-for-locker">Assign to Truck:</label>
                            <select id="select-truck-for-locker" name="truck_id_for_locker" required>
                                <option value="">-- Select Truck --</option>
                                <?php
                                if (count($trucks_for_list) > 0) { // Use $trucks_for_list populated above
                                    foreach ($trucks_for_list as $truck_item) {
                                        echo '<option value="' . htmlspecialchars($truck_item['id'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($truck_item['name'], ENT_QUOTES, 'UTF-8') . '</option>';
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="new-locker-name">Locker Name:</label>
                            <input type="text" id="new-locker-name" name="locker_name" required>
                        </div>
                        <button type="submit" class="button">Add Locker</button>
                    </form>
                </div>
                <div id="lockers-list-container">
                    <h4>Existing Lockers</h4>
                    <div class="scrollable-list-container">
                        <ul id="lockers-list" class="entity-list">
                            <?php
                            if ($current_station_id) {
                                try {
                                    $stmt_lockers = $db->prepare("
                                        SELECT l.id, l.name AS locker_name, t.name AS truck_name 
                                        FROM lockers l
                                        JOIN trucks t ON l.truck_id = t.id
                                        WHERE t.station_id = ? 
                                        ORDER BY t.name, l.name
                                    ");
                                    $stmt_lockers->execute([$current_station_id]);
                                    $lockers_list_data = $stmt_lockers->fetchAll(PDO::FETCH_ASSOC);
                                    if (count($lockers_list_data) > 0) {
                                        foreach ($lockers_list_data as $locker_item) {
                                            echo '<li><span>' . htmlspecialchars($locker_item['locker_name'], ENT_QUOTES, 'UTF-8') . ' (Truck: ' . htmlspecialchars($locker_item['truck_name'], ENT_QUOTES, 'UTF-8') . ')</span> <span class="actions"><!-- Edit/Delete buttons here --></span></li>';
                                        }
                                    } else {
                                        echo '<li>No lockers found for this station.</li>';
                                    }
                                } catch (PDOException $e) {
                                    echo '<li>Error fetching lockers: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</li>';
                                }
                            } else {
                                echo '<li>Select a station to view lockers.</li>';
                            }
                            ?>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Items Card -->
            <div class="lockers-dashboard-card" id="items-management-card">
                <h3><i>📦</i> Items</h3>
                <div id="add-item-form-container">
                    <h4>Add New Item</h4>
                    <form id="add-item-form">
                        <div class="form-group">
                            <label for="select-truck-for-item">Select Truck:</label>
                            <select id="select-truck-for-item" name="truck_id_for_item" required onchange="loadLockersForItemDropdown(this.value)">
                                <option value="">-- Select Truck --</option>
                                <?php
                                if (count($trucks_for_list) > 0) { // Use $trucks_for_list populated above
                                    foreach ($trucks_for_list as $truck_item) {
                                        echo '<option value="' . htmlspecialchars($truck_item['id'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($truck_item['name'], ENT_QUOTES, 'UTF-8') . '</option>';
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="select-locker-for-item">Select Locker:</label>
                            <select id="select-locker-for-item" name="locker_id_for_item" required>
                                <option value="">-- Select Truck First --</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="new-item-name">Item Name:</label>
                            <input type="text" id="new-item-name" name="item_name" required>
                        </div>
                        <button type="submit" class="button">Add Item</button>
                    </form>
                </div>

                <div id="items-list-container">
                    <h4>Existing Items</h4>
                    <div class="form-group">
                        <label for="filter-items-by-truck">Filter by Truck:</label>
                        <select id="filter-items-by-truck" name="filter_truck_id_for_items" onchange="handleTruckFilterChange(this.value)">
                            <option value="">All Trucks</option>
                             <?php
                                if (count($trucks_for_list) > 0) { // Use $trucks_for_list populated above
                                    foreach ($trucks_for_list as $truck_item) {
                                        echo '<option value="' . htmlspecialchars($truck_item['id'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($truck_item['name'], ENT_QUOTES, 'UTF-8') . '</option>';
                                    }
                                }
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="filter-items-by-locker">Filter by Locker:</label>
                        <select id="filter-items-by-locker" name="filter_locker_id_for_items" onchange="loadItemsList(document.getElementById('filter-items-by-truck').value, this.value)">
                            <option value="">All Lockers</option>
                            <!-- Populated by JS -->
                        </select>
                    </div>
                    <div class="scrollable-list-container">
                        <ul id="items-list" class="entity-list">
                            <?php
                            if ($current_station_id) {
                                try {
                                    // Initial load of items for the current station (no truck/locker filter yet from JS)
                                    $stmt_items = $db->prepare("
                                        SELECT li.id, li.name AS item_name, l.id AS locker_id, l.name AS locker_name, t.id AS truck_id, t.name AS truck_name
                                        FROM items li
                                        JOIN lockers l ON li.locker_id = l.id
                                        JOIN trucks t ON l.truck_id = t.id
                                        WHERE t.station_id = ?
                                        ORDER BY t.name, l.name, li.name
                                    ");
                                    $stmt_items->execute([$current_station_id]);
                                    $items_list_data = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
                                    if (count($items_list_data) > 0) {
                                        foreach ($items_list_data as $item_entry) {
                                            // JS will populate this list dynamically on filter changes.
                                            // This initial PHP loop is mostly for non-JS scenarios or initial state.
                                            // The JS loadItemsList will overwrite this.
                                            echo '<li><span>' . htmlspecialchars($item_entry['item_name'], ENT_QUOTES, 'UTF-8') . 
                                                 ' (Locker: ' . htmlspecialchars($item_entry['locker_name'], ENT_QUOTES, 'UTF-8') . 
                                                 ', Truck: ' . htmlspecialchars($item_entry['truck_name'], ENT_QUOTES, 'UTF-8') . ')</span>'.
                                                 ' <span class="actions"><button class="button secondary" style="padding:3px 6px; font-size:10px;" onclick="openEditItemModal('.$item_entry['id'].', \''.htmlspecialchars(addslashes($item_entry['item_name']), ENT_QUOTES).'\', '.$item_entry['locker_id'].', '.$item_entry['truck_id'].')">Edit</button></span></li>';
                                        }
                                    } else {
                                        echo '<li>No items found for this station.</li>';
                                    }
                                } catch (PDOException $e) {
                                    echo '<li>Error fetching items: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</li>';
                                }
                            } else {
                                 echo '<li>Select a station to view items.</li>';
                            }
                            ?>
                        </ul>
                    </div>
                </div>
            </div>

        </div>

    <?php else: ?>
        <div class="notice notice-warning">
            <p><i>⚠️</i> Please select a station to manage its trucks, lockers, and items.</p>
            <?php if ($userRole === 'superuser'): ?>
                <p>As a superuser, you can select a station using the dropdown in the sidebar header.</p>
            <?php elseif ($userRole === 'station_admin' && (!isset($userStationsForModule) || count($userStationsForModule) !== 1)): ?>
                 <p>As a station admin, please ensure a single station is active or selected. If you manage multiple stations, pick one. If you manage none, please contact a superuser.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function handleAjaxResponse(response, operationName = 'Operation') {
    if (response.success) {
        alert(response.message || `${operationName} successful.`);
        console.log('Success:', response.message || `${operationName} successful.`);
        return true; // Indicate success
    } else {
        alert(`Operation Notice: ${response.message || `An issue occurred with ${operationName}.`}`);
        console.error('Operation Notice/Error:', response.message || `An issue occurred with ${operationName}.`);
        return false; // Indicate failure/issue
    }
}

function handleAddTruck(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    formData.append('ajax_action', 'add_truck'); 

    const truckName = formData.get('truck_name').trim();
    if (!truckName) {
        alert('Truck name cannot be empty.');
        return;
    }
    
    fetch('admin_modules/lockers.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (handleAjaxResponse(data, 'Truck addition')) {
            form.reset();
            if (typeof loadPage === 'function') {
                loadPage('admin_modules/lockers.php');
            } else {
                window.location.reload();
            }
        }
    })
    .catch(error => {
        console.error('Network error:', error);
        alert('Network error. Could not add truck.');
    });
}

function handleAddLocker(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    formData.append('ajax_action', 'add_locker');

    const lockerName = formData.get('locker_name').trim();
    const truckId = formData.get('truck_id_for_locker');

    if (!lockerName || !truckId) {
        alert('Truck and Locker name are required.');
        return;
    }

    fetch('admin_modules/lockers.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (handleAjaxResponse(data, 'Locker addition')) {
            form.reset();
            if (typeof loadPage === 'function') {
                loadPage('admin_modules/lockers.php');
            } else {
                window.location.reload();
            }
        }
    })
    .catch(error => {
        console.error('Network error:', error);
        alert('Network error. Could not add locker.');
    });
}

function handleAddItem(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    formData.append('ajax_action', 'add_item');

    const itemName = formData.get('item_name').trim();
    const lockerId = formData.get('locker_id_for_item');

    if (!itemName || !lockerId) {
        alert('Locker and Item name are required.');
        return;
    }
    fetch('admin_modules/lockers.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (handleAjaxResponse(data, 'Item addition')) {
            form.reset();
            document.getElementById('select-locker-for-item').innerHTML = '<option value="">-- Select Truck First --</option>'; 
            if (typeof loadPage === 'function') {
                loadPage('admin_modules/lockers.php');
            } else {
                window.location.reload();
            }
        }
    })
    .catch(error => {
        console.error('Network error:', error);
        alert('Network error. Could not add item.');
    });
}

function loadLockersForItemDropdown(truckId) {
    const lockerSelect = document.getElementById('select-locker-for-item');
    lockerSelect.innerHTML = '<option value="">Loading lockers...</option>';

    if (!truckId) {
        lockerSelect.innerHTML = '<option value="">-- Select Truck First --</option>';
        return;
    }

    fetch(`admin_modules/lockers.php?ajax_action=get_lockers_for_truck&truck_id=${truckId}`)
    .then(response => response.json())
    .then(data => {
        if (data.success && data.data) {
            lockerSelect.innerHTML = '<option value="">-- Select Locker --</option>';
            if (data.data.length > 0) {
                data.data.forEach(locker => {
                    const option = document.createElement('option');
                    option.value = locker.id;
                    option.textContent = locker.name;
                    lockerSelect.appendChild(option);
                });
            } else {
                lockerSelect.innerHTML = '<option value="">No lockers found for this truck</option>';
            }
        } else {
            lockerSelect.innerHTML = '<option value="">Error loading lockers</option>';
            console.error('Error fetching lockers:', data.message || 'No specific message from server.');
        }
    })
    .catch(error => {
        lockerSelect.innerHTML = '<option value="">Error loading lockers</option>';
        console.error('Network error fetching lockers:', error);
    });
}

function loadItemsList(truckIdFilter = '', lockerIdFilter = '') {
    console.log('loadItemsList called with Truck ID:', truckIdFilter, 'Locker ID:', lockerIdFilter);
    const itemsListUl = document.getElementById('items-list');
    itemsListUl.innerHTML = '<li>Loading items...</li>';

    let fetchUrl = `admin_modules/lockers.php?ajax_action=get_filtered_items`;
    if (truckIdFilter) {
        fetchUrl += `&truck_id=${encodeURIComponent(truckIdFilter)}`;
    }
    if (lockerIdFilter) {
        fetchUrl += `&locker_id=${encodeURIComponent(lockerIdFilter)}`;
    }

    fetch(fetchUrl)
    .then(response => response.json())
    .then(data => {
        itemsListUl.innerHTML = ''; // Clear previous items
        if (data.success && data.data) {
            if (data.data.length > 0) {
                data.data.forEach(item => {
                    const li = document.createElement('li');
                    const editButton = `<button class="button secondary" style="padding:3px 6px; font-size:10px;" onclick="openEditItemModal(${item.id}, '${escapeHTML(item.item_name)}', ${item.locker_id || 'null'}, ${item.truck_id || 'null'})">Edit</button>`;
                    li.innerHTML = `<span>${escapeHTML(item.item_name)} (Locker: ${escapeHTML(item.locker_name)}, Truck: ${escapeHTML(item.truck_name)})</span> <span class="actions">${editButton}</span>`;
                    itemsListUl.appendChild(li);
                });
            } else {
                itemsListUl.innerHTML = '<li>No items found matching your criteria.</li>';
            }
        } else {
            itemsListUl.innerHTML = `<li>Error loading items: ${escapeHTML(data.message || 'Unknown error')}</li>`;
            console.error('Error fetching items:', data.message);
        }
    })
    .catch(error => {
        itemsListUl.innerHTML = '<li>Network error loading items.</li>';
        console.error('Network error fetching items:', error);
    });
}

function handleTruckFilterChange(truckId) {
    loadLockersForFilterDropdown(truckId);
    loadItemsList(truckId, ''); 
}

function loadLockersForFilterDropdown(truckId) {
    const lockerFilterSelect = document.getElementById('filter-items-by-locker');
    lockerFilterSelect.innerHTML = '<option value="">Loading lockers...</option>';

    if (!truckId) {
        lockerFilterSelect.innerHTML = '<option value="">All Lockers</option>'; 
        return;
    }

    fetch(`admin_modules/lockers.php?ajax_action=get_lockers_for_truck&truck_id=${encodeURIComponent(truckId)}`)
    .then(response => response.json())
    .then(data => {
        if (data.success && data.data) {
            lockerFilterSelect.innerHTML = '<option value="">All Lockers</option>'; 
            if (data.data.length > 0) {
                data.data.forEach(locker => {
                    const option = document.createElement('option');
                    option.value = locker.id;
                    option.textContent = locker.name;
                    lockerFilterSelect.appendChild(option);
                });
            } else {
                 lockerFilterSelect.innerHTML = '<option value="">No lockers for this truck</option>';
            }
        } else {
            lockerFilterSelect.innerHTML = '<option value="">Error loading lockers</option>';
            console.error('Error fetching lockers for filter:', data.message || 'No specific message');
        }
    })
    .catch(error => {
        lockerFilterSelect.innerHTML = '<option value="">Error loading lockers</option>';
        console.error('Network error fetching lockers for filter:', error);
    });
}

function escapeHTML(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, function (match) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        }[match];
    });
}

let currentEditItemId = null;

function openEditItemModal(itemId, itemName, currentLockerId, currentTruckId) {
    currentEditItemId = itemId;
    document.getElementById('edit-item-id').value = itemId;
    document.getElementById('edit-item-name').value = itemName;

    const truckSelect = document.getElementById('edit-item-truck-id');
    truckSelect.value = currentTruckId || ''; 
    
    loadLockersForEditModal(currentTruckId, function() {
        document.getElementById('edit-item-locker-id').value = currentLockerId || '';
    });

    document.getElementById('edit-item-modal').style.display = 'block';
}

function closeEditItemModal() {
    document.getElementById('edit-item-modal').style.display = 'none';
    document.getElementById('edit-item-form').reset();
    currentEditItemId = null;
}

function loadLockersForEditModal(truckId, callback) {
    const lockerSelect = document.getElementById('edit-item-locker-id');
    lockerSelect.innerHTML = '<option value="">Loading lockers...</option>';

    if (!truckId) {
        lockerSelect.innerHTML = '<option value="">-- Select Truck First --</option>';
        if (callback) callback();
        return;
    }

    fetch(`admin_modules/lockers.php?ajax_action=get_lockers_for_truck&truck_id=${encodeURIComponent(truckId)}`)
    .then(response => response.json())
    .then(data => {
        if (data.success && data.data) {
            lockerSelect.innerHTML = '<option value="">-- Select Locker --</option>';
            if (data.data.length > 0) {
                data.data.forEach(locker => {
                    const option = document.createElement('option');
                    option.value = locker.id;
                    option.textContent = locker.name;
                    lockerSelect.appendChild(option);
                });
            } else {
                lockerSelect.innerHTML = '<option value="">No lockers for this truck</option>';
            }
        } else {
            lockerSelect.innerHTML = '<option value="">Error loading lockers</option>';
            console.error('Error fetching lockers for edit modal:', data.message || 'No specific message');
        }
        if (callback) callback(); 
    })
    .catch(error => {
        lockerSelect.innerHTML = '<option value="">Error loading lockers</option>';
        console.error('Network error fetching lockers for edit modal:', error);
        if (callback) callback();
    });
}

// Renamed and modified: No longer takes 'event', gets form by ID.
function handleEditItemSubmit() { 
    const form = document.getElementById('edit-item-form');
    const formData = new FormData(form);
    formData.append('ajax_action', 'edit_item');

    // Basic client-side validation
    const itemName = formData.get('item_name') ? formData.get('item_name').trim() : '';
    const lockerId = formData.get('locker_id'); // ID, so no trim
    const itemId = formData.get('item_id');

    if (!itemId || !itemName || !lockerId) {
        alert('Item Name and Locker selection are required to save changes.');
        return;
    }

    fetch('admin_modules/lockers.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (handleAjaxResponse(data, 'Item update')) {
            closeEditItemModal();
            const currentTruckFilter = document.getElementById('filter-items-by-truck').value;
            const currentLockerFilter = document.getElementById('filter-items-by-locker').value;
            loadItemsList(currentTruckFilter, currentLockerFilter);
        }
        // If handleAjaxResponse returns false, it has already alerted the user.
    })
    .catch(error => {
        console.error('Network error updating item:', error);
        alert('Network error. Could not update item.');
    });
}

function initializeLockersModule() {
    console.log('Lockers module initialized via JS.');
    const addTruckForm = document.getElementById('add-truck-form');
    if (addTruckForm) {
        addTruckForm.onsubmit = handleAddTruck; 
    }
    const addLockerForm = document.getElementById('add-locker-form');
    if (addLockerForm) {
        addLockerForm.onsubmit = handleAddLocker;
    }
    const addItemForm = document.getElementById('add-item-form');
    if (addItemForm) {
        addItemForm.onsubmit = handleAddItem;
    }

    // Attach to the new button's click event for editing items
    const saveEditItemButton = document.getElementById('save-edit-item-button');
    if (saveEditItemButton) {
        saveEditItemButton.onclick = handleEditItemSubmit; // No 'event' passed, function adapted
    }
    
    window.onclick = function(event) {
        const modal = document.getElementById('edit-item-modal');
        if (event.target == modal) {
            closeEditItemModal();
        }
    }
    // Initial load of items if a station is selected
    // The $current_station_id PHP variable is available here if script is included by admin.php
    // For direct AJAX calls, this JS runs in browser, PHP already determined station.
    // The initial item list is populated by PHP. JS loadItemsList is for dynamic filtering.
    // However, if filters are pre-selected (e.g. from URL params in future), could call loadItemsList here.
    // For now, the PHP loop populates the initial list.
    // If filter dropdowns have initial values, trigger their change handlers.
    const initialTruckFilter = document.getElementById('filter-items-by-truck').value;
    if (initialTruckFilter) {
        handleTruckFilterChange(initialTruckFilter); // This will load lockers and then items
    } else {
        // If no truck filter, load all items for the current station (if one is selected)
        // This is already handled by the PHP loop that populates the #items-list initially.
        // If $current_station_id is null, PHP shows "Select a station".
        // If $current_station_id is set, PHP shows items for that station.
        // No explicit JS call needed here for the very first page load without filters.
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeLockersModule);
} else {
    initializeLockersModule();
}

</script>
