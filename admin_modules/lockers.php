<?php
// admin_modules/lockers.php
// This file is intended to be included as an AJAX module within admin.php
// It assumes auth.php, config.php, and db.php have already been included
// and $pdo, $user, $userRole, $userName, $station, DEBUG are available.

// Ensure necessary variables are available from admin.php
global $pdo, $user, $userRole, $userName, $station, $DEBUG;

if (!isset($pdo) || !isset($user) || !isset($station)) {
    // If $station is not set, it might mean the user needs to select one first.
    // Or, if this module is accessible without a station, this check might need adjustment.
    // For now, assume $station is required as per most other modules.
    die('This module must be loaded through admin.php and a station must be selected.');
}

$db = $pdo; // Use the PDO connection from admin.php
$error_message = '';
$success_message = '';
$current_station_id = $station['id'];
$current_station_name = $station['name'];

// Handle AJAX actions (will be expanded significantly)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $response = ['success' => false, 'message' => 'Invalid action.'];

    // Placeholder for actions
    // switch ($action) {
    //     case 'add_truck':
    //         // ... logic ...
    //         break;
    //     // ... other cases ...
    // }

    echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Handle GET AJAX actions (e.g., fetching lists)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_GET['ajax_action'];
    $response = ['success' => false, 'message' => 'Invalid action.', 'data' => []];

    // Placeholder for actions
    // switch ($action) {
    //     case 'list_items':
    //         // ... logic ...
    //         break;
    //     // ... other cases ...
    // }
    
    echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

?>

<style>
    /* Basic styling for the Lockers module */
    .lockers-page-container {
        padding: 20px;
        font-family: Arial, sans-serif;
    }
    .lockers-section {
        margin-bottom: 30px;
        padding: 20px;
        border: 1px solid #ddd;
        border-radius: 5px;
        background-color: #f9f9f9;
    }
    .lockers-section h2 {
        margin-top: 0;
        border-bottom: 1px solid #ccc;
        padding-bottom: 10px;
    }
    .form-group {
        margin-bottom: 15px;
    }
    .form-group label {
        display: block;
        margin-bottom: 5px;
    }
    .form-group input[type="text"],
    .form-group select {
        width: 100%;
        padding: 8px;
        border: 1px solid #ccc;
        border-radius: 4px;
        box-sizing: border-box;
    }
    .button {
        padding: 10px 15px;
        background-color: #12044C;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
    }
    .button:hover {
        background-color: #0056b3;
    }
    .item-list ul {
        list-style-type: none;
        padding: 0;
    }
    .item-list li {
        padding: 8px;
        border-bottom: 1px solid #eee;
    }
    .item-list li:last-child {
        border-bottom: none;
    }
    .filter-controls {
        margin-bottom: 20px;
    }
</style>

<div class="lockers-page-container">
    <h1>Lockers Management (Station: <?= htmlspecialchars($current_station_name, ENT_QUOTES, 'UTF-8') ?>)</h1>
    <p>This new module will consolidate management of Trucks, Lockers, and Items.</p>

    <!-- Section for Adding/Managing Trucks -->
    <div class="lockers-section" id="trucks-management-section">
        <h2>Trucks</h2>
        <!-- Add Truck Form -->
        <form id="add-truck-form" class="form-group">
            <label for="new-truck-name">Truck Name:</label>
            <input type="text" id="new-truck-name" name="truck_name" required>
            <button type="submit" class="button">Add Truck</button>
        </form>
        <div id="trucks-list">
            <!-- Trucks will be listed here -->
        </div>
    </div>

    <!-- Section for Adding/Managing Lockers -->
    <div class="lockers-section" id="lockers-management-section">
        <h2>Lockers</h2>
        <!-- Add Locker Form -->
        <form id="add-locker-form" class="form-group">
            <label for="select-truck-for-locker">Select Truck:</label>
            <select id="select-truck-for-locker" name="truck_id_for_locker" required>
                <!-- Options populated by JS -->
            </select>
            <label for="new-locker-name">Locker Name:</label>
            <input type="text" id="new-locker-name" name="locker_name" required>
            <button type="submit" class="button">Add Locker</button>
        </form>
        <div id="lockers-list">
            <!-- Lockers will be listed here -->
        </div>
    </div>

    <!-- Section for Adding/Managing Items -->
    <div class="lockers-section" id="items-management-section">
        <h2>Items</h2>
        <!-- Add Item Form -->
        <form id="add-item-form" class="form-group">
            <label for="select-truck-for-item">Select Truck:</label>
            <select id="select-truck-for-item" name="truck_id_for_item" required>
                <!-- Options populated by JS -->
            </select>
            <label for="select-locker-for-item">Select Locker:</label>
            <select id="select-locker-for-item" name="locker_id_for_item" required>
                <!-- Options populated by JS -->
            </select>
            <label for="new-item-name">Item Name:</label>
            <input type="text" id="new-item-name" name="item_name" required>
            <button type="submit" class="button">Add Item</button>
        </form>
        
        <h3>Existing Items</h3>
        <div class="filter-controls form-group">
            <label for="filter-items-by-truck">Filter by Truck:</label>
            <select id="filter-items-by-truck" name="filter_truck_id">
                <option value="">All Trucks</option>
                <!-- Options populated by JS -->
            </select>
        </div>
        <div id="items-list" class="item-list">
            <ul><!-- Items will be listed here --></ul>
        </div>
    </div>

</div>

<script>
// JavaScript for the Lockers module will go here.
// This will handle AJAX submissions, dynamic population of dropdowns,
// listing entities, handling edits, deletes, and moves.

document.addEventListener('DOMContentLoaded', function() {
    // This event listener might not fire correctly if loaded via AJAX into admin.php
    // We will need to ensure JS is initialized correctly when the module loads.
    // For now, we'll assume admin.php's loadPage function might re-evaluate scripts or we'll call an init function.
    console.log('Lockers module script loaded.');
    initializeLockersPage();
});

function initializeLockersPage() {
    // Initial data loading (trucks for dropdowns, items list)
    // loadTrucksForDropdowns();
    // loadItemsList();

    // Add event listeners for forms
    // document.getElementById('add-truck-form').addEventListener('submit', handleAddTruck);
    // ... etc.
    console.log('Lockers page initialized.');
}

// Placeholder functions for AJAX calls and DOM manipulation
// function loadTrucksForDropdowns() { /* ... */ }
// function loadItemsList(truckFilterId = '') { /* ... */ }
// function handleAddTruck(event) { /* event.preventDefault(); ... */ }

</script>
