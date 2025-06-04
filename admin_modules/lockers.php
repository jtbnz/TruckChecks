<?php
// admin_modules/lockers.php
// Assumes auth.php, config.php, db.php are included via admin.php
// and $pdo, $user, $userRole, $userName, $station (can be null) are available.

global $pdo, $user, $userRole, $userName, $station, $DEBUG, $userStations; // $userStations is available in admin.php context

// Ensure session is started if not already (admin.php should handle this, but as a fallback for direct AJAX)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$db = $pdo; // Use the PDO connection from admin.php
$error_message = '';
$success_message = '';

$current_station_id = null;
$current_station_name = "No station selected";

// Attempt to get station from global $station variable first (set by admin.php for normal loads)
if (isset($station) && is_array($station) && isset($station['id'])) {
    $current_station_id = $station['id'];
    $current_station_name = $station['name'];
} 
// If $station wasn't set (e.g., direct AJAX call not fully bootstrapping admin.php environment), 
// try to load selected station from session.
else if (isset($_SESSION['selected_station_id']) && $pdo) { // Ensure $pdo is available
    try {
        $stmt_session_station = $pdo->prepare("SELECT id, name FROM stations WHERE id = ?");
        $stmt_session_station->execute([$_SESSION['selected_station_id']]);
        $session_station_data = $stmt_session_station->fetch(PDO::FETCH_ASSOC);
        if ($session_station_data) {
            $current_station_id = $session_station_data['id'];
            $current_station_name = $session_station_data['name'];
            // Optionally, re-assign to global $station if other parts of this script might expect $station to be populated
            // $station = $session_station_data; 
        } else {
            // Selected station ID in session doesn't exist in DB, clear it to prevent issues
            unset($_SESSION['selected_station_id']);
        }
    } catch (PDOException $e) {
        error_log("Error fetching station from session for AJAX in lockers.php: " . $e->getMessage());
        // $current_station_id will remain null, subsequent checks will handle it.
    }
}
// Fallback if $current_station_id is STILL null, but $userStations indicates a single station for a station_admin
else if (!$current_station_id && isset($userRole) && $userRole === 'station_admin' && isset($userStations) && count($userStations) === 1) {
    $single_station_keys = array_keys($userStations);
    $current_station_id = $single_station_keys[0];
    $current_station_name = $userStations[$current_station_id];
     if (isset($_SESSION)) { // Ensure session is available before trying to set
        $_SESSION['selected_station_id'] = $current_station_id; // Persist this auto-selection
    }
    // $station = ['id' => $current_station_id, 'name' => $current_station_name]; // Also update $station global
}

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
                $response['message'] = 'Database error adding truck. ' . ($DEBUG ? $e->getMessage() : '');
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
                $response['message'] = 'Database error adding locker. ' . ($DEBUG ? $e->getMessage() : '');
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
                $response['message'] = 'Database error adding item. ' . ($DEBUG ? $e->getMessage() : '');
            }
        } else if (empty($item_name)) {
            $response['message'] = 'Item name cannot be empty.';
        } else if (empty($locker_id)) {
            $response['message'] = 'Please select a locker to assign the item to.';
        } else {
            $response['message'] = 'Station not selected or invalid item/locker details.';
        }
    }
    // TODO: Implement other CUD operations (edit, delete etc.)

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
                $response['message'] = 'Database error fetching lockers. ' . ($DEBUG ? $e->getMessage() : '');
            }
        } else if (!$current_station_id) {
             $response['message'] = 'Please select a station first.';
        } else {
            $response['message'] = 'Truck ID missing or invalid context.';
        }
    }
    // TODO: Implement other GET AJAX actions if needed (e.g., get_items_for_locker_or_truck)
    
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
</style>

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
                                if (isset($trucks_for_list) && count($trucks_for_list) > 0) {
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
                            try {
                                // Corrected: Filter by t.station_id
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
                                if (isset($trucks_for_list) && count($trucks_for_list) > 0) {
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
                        <select id="filter-items-by-truck" name="filter_truck_id_for_items" onchange="loadItemsList(this.value)">
                            <option value="">All Trucks</option>
                             <?php
                                if (isset($trucks_for_list) && count($trucks_for_list) > 0) {
                                    foreach ($trucks_for_list as $truck_item) {
                                        echo '<option value="' . htmlspecialchars($truck_item['id'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($truck_item['name'], ENT_QUOTES, 'UTF-8') . '</option>';
                                    }
                                }
                            ?>
                        </select>
                    </div>
                    <div class="scrollable-list-container">
                        <ul id="items-list" class="entity-list">
                            <?php
                            try {
                                $stmt_items = $db->prepare("
                                    SELECT li.id, li.name AS item_name, l.name AS locker_name, t.name AS truck_name
                                    FROM items li
                                    JOIN lockers l ON li.locker_id = l.id
                                    JOIN trucks t ON l.truck_id = t.id
                                    WHERE li.station_id = ? 
                                    ORDER BY t.name, l.name, li.name
                                ");
                                $stmt_items->execute([$current_station_id]);
                                $items_list_data = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
                                if (count($items_list_data) > 0) {
                                    foreach ($items_list_data as $item_entry) {
                                        echo '<li><span>' . htmlspecialchars($item_entry['item_name'], ENT_QUOTES, 'UTF-8') . 
                                             ' (Locker: ' . htmlspecialchars($item_entry['locker_name'], ENT_QUOTES, 'UTF-8') . 
                                             ', Truck: ' . htmlspecialchars($item_entry['truck_name'], ENT_QUOTES, 'UTF-8') . ')</span>'.
                                             ' <span class="actions"><!-- Edit/Delete buttons here --></span></li>';
                                    }
                                } else {
                                    echo '<li>No items found for this station.</li>';
                                }
                            } catch (PDOException $e) {
                                echo '<li>Error fetching items: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</li>';
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
            <?php elseif ($userRole === 'station_admin' && (!isset($userStations) || count($userStations) !== 1)): ?>
                 <p>As a station admin, please ensure a single station is active or selected. If you manage multiple stations, pick one. If you manage none, please contact a superuser.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function handleAjaxResponse(response, successCallback, errorCallback) {
    if (response.success) {
        if (successCallback) successCallback(response);
        console.log('Success:', response.message || 'Operation successful.');
    } else {
        if (errorCallback) errorCallback(response);
        alert('Error: ' + (response.message || 'An unknown error occurred.'));
        console.error('Error:', response.message);
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
        handleAjaxResponse(data, function(res) {
            alert(res.message || 'Truck added successfully!'); 
            form.reset();
            loadPage('admin_modules/lockers.php'); 
        });
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
        handleAjaxResponse(data, function(res) {
            alert(res.message || 'Locker added successfully!');
            form.reset();
            loadPage('admin_modules/lockers.php'); 
        });
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
        handleAjaxResponse(data, function(res) {
            alert(res.message || 'Item added successfully!');
            form.reset();
            document.getElementById('select-locker-for-item').innerHTML = '<option value="">-- Select Truck First --</option>'; 
            loadPage('admin_modules/lockers.php'); 
        });
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

function loadItemsList(truckIdFilter = '') {
    console.log('loadItemsList called with truck ID:', truckIdFilter);
    // Placeholder for future dynamic client-side filtering.
    // For now, PHP handles initial load and full page reload updates lists.
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
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeLockersModule);
} else {
    initializeLockersModule();
}

</script>
