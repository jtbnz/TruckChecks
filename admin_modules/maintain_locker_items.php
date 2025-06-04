<?php
// This file is intended to be included as an AJAX module within admin.php
// It assumes auth.php, config.php, and db.php have already been included
// and $pdo, $user, $userRole, $userName, $station, DEBUG are available.

// Ensure necessary variables are available from admin.php
global $pdo, $user, $userRole, $userName, $station, $DEBUG;

$db = $pdo; // Use the PDO connection from admin.php
$error_message = '';
$success_message = '';

// Check if we're handling an AJAX action from a form submission
$isAjaxAction = isset($_POST['ajax_action']) || (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'delete_item');

// Handle adding a new item
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_item'])) {
    $item_name = trim($_POST['item_name']);
    $locker_id = $_POST['locker_id'];

    if (!empty($item_name) && !empty($locker_id)) {
        try {
            // Verify locker belongs to current station
            $locker_check = $db->prepare('SELECT l.id FROM lockers l JOIN trucks t ON l.truck_id = t.id WHERE l.id = :locker_id AND t.station_id = :station_id');
            $locker_check->execute(['locker_id' => $locker_id, 'station_id' => $station['id']]);

            if ($locker_check->fetch()) {
                $query = $db->prepare('INSERT INTO items (name, locker_id) VALUES (:name, :locker_id)');
                $query->execute(['name' => $item_name, 'locker_id' => $locker_id]);
                $success_message = "Item '{$item_name}' added successfully.";
            } else {
                $error_message = "Selected locker not found or access denied.";
            }
        } catch (Exception $e) {
            $error_message = "Error adding item: " . $e->getMessage();
        }
    } else {
        $error_message = "Item name and locker selection are required.";
    }
    // For AJAX actions, return JSON response
    if ($isAjaxAction) {
        header('Content-Type: application/json');
        echo json_encode(['success' => empty($error_message), 'message' => $success_message ?: $error_message]);
        exit;
    }
}

// Handle editing an item
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_item'])) {
    $item_id = $_POST['item_id'];
    $item_name = trim($_POST['item_name']);
    $locker_id = $_POST['locker_id'];

    if (!empty($item_name) && !empty($locker_id) && !empty($item_id)) {
        try {
            // Verify item and new locker belong to current station
            $item_check = $db->prepare('SELECT i.id FROM items i JOIN lockers l ON i.locker_id = l.id JOIN trucks t ON l.truck_id = t.id WHERE i.id = :item_id AND t.station_id = :station_id');
            $item_check->execute(['item_id' => $item_id, 'station_id' => $station['id']]);

            if (!$item_check->fetch()) {
                $error_message = "Item not found or access denied.";
            } else {
                $new_locker_check = $db->prepare('SELECT l.id FROM lockers l JOIN trucks t ON l.truck_id = t.id WHERE l.id = :locker_id AND t.station_id = :station_id');
                $new_locker_check->execute(['locker_id' => $locker_id, 'station_id' => $station['id']]);

                if ($new_locker_check->fetch()) {
                    $query = $db->prepare('UPDATE items SET name = :name, locker_id = :locker_id WHERE id = :id');
                    $query->execute(['name' => $item_name, 'locker_id' => $locker_id, 'id' => $item_id]);
                    $success_message = "Item updated successfully.";
                } else {
                    $error_message = "Selected new locker not found or access denied.";
                }
            }
        } catch (Exception $e) {
            $error_message = "Error updating item: " . $e->getMessage();
        }
    } else {
        $error_message = "Item name, locker selection, and item ID are required.";
    }
    // For AJAX actions, return JSON response
    if ($isAjaxAction) {
        header('Content-Type: application/json');
        echo json_encode(['success' => empty($error_message), 'message' => $success_message ?: $error_message]);
        exit;
    }
}

// Handle deleting an item (now via POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_item_id']) && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_item') {
    $item_id = $_POST['delete_item_id'];
    $response_success = false;
    $response_message = '';

    if (!$station || !isset($station['id'])) {
        $response_message = "Error: Current station not set or invalid. Cannot delete item.";
    } else {
        try {
            // Verify item belongs to current station before deleting
            $item_check = $db->prepare('SELECT i.id FROM items i JOIN lockers l ON i.locker_id = l.id JOIN trucks t ON l.truck_id = t.id WHERE i.id = :item_id AND t.station_id = :station_id');
            $item_check->execute(['item_id' => $item_id, 'station_id' => $station['id']]);

            if ($item_check->fetch()) {
                $query = $db->prepare('DELETE FROM items WHERE id = :id');
                $query->execute(['id' => $item_id]);
                $response_success = true;
                $response_message = "Item deleted successfully.";
            } else {
                $response_message = "Item not found or access denied for deletion.";
            }
        } catch (Exception $e) {
            $response_message = "Error deleting item: " . $e->getMessage();
        }
    }
    // Always return JSON response for delete action
    header('Content-Type: application/json');
    echo json_encode(['success' => $response_success, 'message' => $response_message]);
    exit;
}

// Handle AJAX requests for getting lockers or items (GET requests)
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax'] === 'get_lockers' && isset($_GET['truck_id'])) {
        $truck_id = $_GET['truck_id'];
        if (empty($truck_id)) {
            echo json_encode([]);
        } else {
            $stmt = $db->prepare('SELECT id, name FROM lockers WHERE truck_id = ? ORDER BY name');
            $stmt->execute([$truck_id]);
            $ajax_lockers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($ajax_lockers);
        }
        exit;
    }
    
    if ($_GET['ajax'] === 'get_items') {
        $ajax_truck_filter = $_GET['truck_filter'] ?? '';
        $ajax_locker_filter = $_GET['locker_filter'] ?? '';
        
        $ajax_items_query = 'SELECT i.*, l.name as locker_name, t.name as truck_name, t.id as truck_id, l.id as locker_id FROM items i JOIN lockers l ON i.locker_id = l.id JOIN trucks t ON l.truck_id = t.id';
        $ajax_params = [];
        $ajax_where_conditions = [];

        // Always filter by station if available
        if ($station) {
            $ajax_where_conditions[] = 't.station_id = ?';
            $ajax_params[] = $station['id'];
        }

        if (!empty($ajax_truck_filter)) {
            $ajax_where_conditions[] = 't.id = ?';
            $ajax_params[] = $ajax_truck_filter;
        }

        if (!empty($ajax_locker_filter)) {
            $ajax_where_conditions[] = 'l.id = ?';
            $ajax_params[] = $ajax_locker_filter;
        }

        if (!empty($ajax_where_conditions)) {
            $ajax_items_query .= ' WHERE ' . implode(' AND ', $ajax_where_conditions);
        }

        $ajax_items_query .= ' ORDER BY t.name, l.name, i.name';

        $ajax_stmt = $db->prepare($ajax_items_query);
        $ajax_stmt->execute($ajax_params);
        $ajax_items = $ajax_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get filter names for display
        $truck_name = '';
        $locker_name = '';
        
        if (!empty($ajax_truck_filter)) {
            $truck_stmt = $db->prepare('SELECT name FROM trucks WHERE id = ?');
            $truck_stmt->execute([$ajax_truck_filter]);
            $truck_name = $truck_stmt->fetchColumn();
        }
        
        if (!empty($ajax_locker_filter)) {
            $locker_stmt = $db->prepare('SELECT name FROM lockers WHERE id = ?');
            $locker_stmt->execute([$ajax_locker_filter]);
            $locker_name = $locker_stmt->fetchColumn();
        }
        
        echo json_encode([
            'items' => $ajax_items,
            'count' => count($ajax_items),
            'truck_name' => $truck_name,
            'locker_name' => $locker_name
        ]);
        exit;
    }
}


// Get item to edit if edit_id is set
$edit_item = null;
if (isset($_GET['edit_id'])) {
    $edit_id = $_GET['edit_id'];
    $query = $db->prepare('SELECT * FROM items WHERE id = :id');
    $query->execute(['id' => $edit_id]);
    $edit_item = $query->fetch(PDO::FETCH_ASSOC);
}

// Get filter parameters
$truck_filter = $_GET['truck_filter'] ?? '';
$locker_filter = $_GET['locker_filter'] ?? '';

// Fetch trucks for the current station only
if ($station) {
    $trucks_stmt = $db->prepare('SELECT * FROM trucks WHERE station_id = ? ORDER BY name');
    $trucks_stmt->execute([$station['id']]);
    $trucks = $trucks_stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $trucks = []; // Should not happen if station is required
}

// Fetch lockers for the current station only
if ($station) {
    $lockers_stmt = $db->prepare('SELECT l.*, t.name as truck_name FROM lockers l JOIN trucks t ON l.truck_id = t.id WHERE t.station_id = ? ORDER BY t.name, l.name');
    $lockers_stmt->execute([$station['id']]);
    $lockers = $lockers_stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $lockers = []; // Should not happen if station is required
}

// Fetch lockers for the selected truck (for filter dropdown)
$filter_lockers = [];
if (!empty($truck_filter)) {
    $stmt = $db->prepare('SELECT l.*, t.name as truck_name FROM lockers l JOIN trucks t ON l.truck_id = t.id WHERE t.id = ? AND t.station_id = ? ORDER BY l.name');
    $stmt->execute([$truck_filter, $station['id']]);
    $filter_lockers = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch items with optional truck and locker filter (station-filtered)
$items_query = 'SELECT i.*, l.name as locker_name, t.name as truck_name, t.id as truck_id, l.id as locker_id FROM items i JOIN lockers l ON i.locker_id = l.id JOIN trucks t ON l.truck_id = t.id';
$params = [];
$where_conditions = [];

// Always filter by station if available
if ($station) {
    $where_conditions[] = 't.station_id = ?';
    $params[] = $station['id'];
}

if (!empty($truck_filter)) {
    $where_conditions[] = 't.id = ?';
    $params[] = $truck_filter;
}

if (!empty($locker_filter)) {
    $where_conditions[] = 'l.id = ?';
    $params[] = $locker_filter;
}

if (!empty($where_conditions)) {
    $items_query .= ' WHERE ' . implode(' AND ', $where_conditions);
}

$items_query .= ' ORDER BY t.name, l.name, i.name';

$stmt = $db->prepare($items_query);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    .maintain-container {
        max-width: 800px;
        margin: 20px auto;
        padding: 20px;
    }

    .page-header {
        margin-bottom: 30px;
        padding-bottom: 15px;
        border-bottom: 2px solid #12044C;
    }

    .page-title {
        color: #12044C;
        margin: 0;
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

    .form-section {
        background: white;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 30px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .form-section h2 {
        margin-top: 0;
        color: #12044C;
    }

    .input-container {
        margin-bottom: 15px;
    }

    .input-container input,
    .input-container select {
        width: 100%;
        padding: 10px;
        border: 1px solid #ccc;
        border-radius: 5px;
        font-size: 16px;
        box-sizing: border-box;
    }

    .button-container {
        text-align: center;
        margin-top: 20px;
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

    .items-list ul {
        list-style: none;
        padding: 0;
    }

    .items-list li {
        background-color: #f8f9fa;
        border: 1px solid #eee;
        border-radius: 5px;
        padding: 15px;
        margin-bottom: 10px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
    }

    .items-list li a {
        margin-left: 10px;
        color: #007bff;
        text-decoration: none;
    }

    .items-list li a:hover {
        text-decoration: underline;
    }

    .alert {
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 5px;
    }

    .alert-success {
        background-color: #d4edda;
        border: 1px solid #c3e6cb;
        color: #155724;
    }

    .alert-error {
        background-color: #f8d7da;
        border: 1px solid #f5c6cb;
        color: #721c24;
    }

    .empty-state {
        text-align: center;
        padding: 40px;
        color: #666;
    }

    .empty-state h3 {
        color: #12044C;
        margin-bottom: 10px;
    }

    .no-trucks-warning, .no-lockers-warning {
        background-color: #fff3cd;
        border: 1px solid #ffeaa7;
        border-radius: 5px;
        padding: 15px;
        margin-bottom: 20px;
        color: #856404;
    }

    /* Mobile responsive */
    @media (max-width: 768px) {
        .maintain-container {
            padding: 10px;
        }

        .items-list li {
            flex-direction: column;
            align-items: flex-start;
            gap: 5px;
        }
    }
</style>

<div class="maintain-container">
    <div class="page-header">
        <h1 class="page-title">Maintain Locker Items</h1>
    </div>

    <div class="station-info">
        <div class="station-name"><?= htmlspecialchars($station['name']) ?></div>
        <?php if ($station['description']): ?>
            <div style="color: #666; margin-top: 5px;"><?= htmlspecialchars($station['description']) ?></div>
        <?php endif; ?>
    </div>

    <?php if ($error_message): ?>
        <div class="alert alert-error">
            <?= htmlspecialchars($error_message) ?>
        </div>
    <?php endif; ?>

    <?php if ($success_message): ?>
        <div class="alert alert-success">
            <?= htmlspecialchars($success_message) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($trucks)): ?>
        <div class="no-trucks-warning">
            <h3>No Trucks Available</h3>
            <p>You need to add trucks before you can create lockers or items. <a href="#" onclick="event.preventDefault(); if(window.parent && typeof window.parent.loadPage === 'function'){ window.parent.loadPage('maintain_trucks.php'); }">Go to Maintain Trucks</a> to add your first truck.</p>
        </div>
    <?php elseif (empty($lockers)): ?>
        <div class="no-lockers-warning">
            <h3>No Lockers Available</h3>
            <p>You need to add lockers before you can create items. <a href="#" onclick="event.preventDefault(); if(window.parent && typeof window.parent.loadPage === 'function'){ window.parent.loadPage('maintain_lockers.php'); }">Go to Maintain Lockers</a> to add your first locker.</p>
        </div>
    <?php else: ?>
        <?php if ($edit_item): ?>
            <div class="form-section">
                <h2>Edit Item</h2>
                <form method="POST" action="admin.php" id="edit-item-form">
                    <input type="hidden" name="ajax_action" value="edit_item">
                    <input type="hidden" name="item_id" value="<?= $edit_item['id'] ?>">
                    <div class="input-container">
                        <input type="text" name="item_name" placeholder="Item Name" value="<?= htmlspecialchars($edit_item['name']) ?>" required>
                    </div>
                    <div class="input-container">
                        <select name="locker_id" required>
                            <option value="">Select Locker</option>
                            <?php foreach ($lockers as $locker): ?>
                                <option value="<?= $locker['id'] ?>" <?= $locker['id'] == $edit_item['locker_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($locker['truck_name']) ?> - <?= htmlspecialchars($locker['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="button-container">
                        <button type="submit" name="edit_item" class="button">Update Item</button>
                        <a href="#" onclick="event.preventDefault(); if(window.parent && typeof window.parent.loadPage === 'function'){ window.parent.loadPage('maintain_locker_items.php<?= !empty($truck_filter) || !empty($locker_filter) ? '?' . http_build_query(array_filter(['truck_filter' => $truck_filter, 'locker_filter' => $locker_filter])) : '' ?>'); }" class="button secondary">Cancel</a>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div class="form-section">
                <h2>Add New Item</h2>
                <form method="POST" action="admin.php" id="add-item-form">
                    <input type="hidden" name="ajax_action" value="add_item">
                    <div class="input-container">
                        <label for="add_truck_filter">Select Truck:</label>
                        <select id="add_truck_filter" onchange="updateAddLockerDropdown()">
                            <option value="">Select Truck First</option>
                            <?php foreach ($trucks as $truck): ?>
                                <option value="<?= $truck['id'] ?>"><?= htmlspecialchars($truck['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-container">
                        <label for="locker_id">Select Locker:</label>
                        <select name="locker_id" id="locker_id" required>
                            <option value="">Select Truck First</option>
                        </select>
                    </div>
                    <div class="input-container">
                        <input type="text" name="item_name" placeholder="Item Name" required>
                    </div>
                    <div class="button-container">
                        <button type="submit" name="add_item" class="button">Add Item</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="filter-section" style="margin: 20px 0; padding: 15px; background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px;">
        <h2>Filter Items</h2>
        <div style="display: flex; flex-wrap: wrap; gap: 15px; align-items: end;">
            <div>
                <label for="truck_filter">Select Truck:</label>
                <select name="truck_filter" id="truck_filter" onchange="updateFilters()">
                    <option value="">ALL</option>
                    <?php foreach ($trucks as $truck): ?>
                        <option value="<?= $truck['id'] ?>" <?= $truck_filter == $truck['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($truck['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label for="locker_filter">Select Locker:</label>
                <select name="locker_filter" id="locker_filter" onchange="updateFilters()">
                    <option value="">ALL</option>
                    <?php if (!empty($truck_filter)): ?>
                        <?php foreach ($filter_lockers as $locker): ?>
                            <option value="<?= $locker['id'] ?>" <?= $locker_filter == $locker['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($locker['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
        </div>
    </div>

    <div class="stats-section" id="stats-section" style="margin: 20px 0; padding: 15px; background-color: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 5px;">
        <h2>Items Summary</h2>
        <p><strong>Total Items Shown:</strong> <span id="item-count"><?= count($items) ?></span></p>
        <p id="filter-status">
            <?php if (!empty($truck_filter)): ?>
                <?php 
                $selected_truck = array_filter($trucks, function($truck) use ($truck_filter) {
                    return $truck['id'] == $truck_filter;
                });
                $selected_truck = reset($selected_truck);
                ?>
                <strong>Filtered by Truck:</strong> <?= htmlspecialchars($selected_truck['name']) ?>
                <?php if (!empty($locker_filter)): ?>
                    <?php 
                    $selected_locker = array_filter($filter_lockers, function($locker) use ($locker_filter) {
                        return $locker['id'] == $locker_filter;
                    });
                    if (empty($selected_locker)) {
                        $selected_locker = array_filter($lockers, function($locker) use ($locker_filter) {
                            return $locker['id'] == $locker_filter;
                        });
                    }
                    $selected_locker = reset($selected_locker);
                    ?>
                    <br><strong>Filtered by Locker:</strong> <?= htmlspecialchars($selected_locker['name']) ?>
                <?php endif; ?>
            <?php else: ?>
                <strong>Showing:</strong> All trucks and lockers for <?= htmlspecialchars($station['name']) ?>
            <?php endif; ?>
        </p>
    </div>

    <h2>Existing Items</h2>
    <div id="items-list">
        <?php if (!empty($items)): ?>
            <ul>
                <?php foreach ($items as $item): ?>
                    <li>
                        <?= htmlspecialchars($item['name']) ?> (<?= htmlspecialchars($item['truck_name']) ?> - <?= htmlspecialchars($item['locker_name']) ?>) 
                        <a href="#" onclick="event.preventDefault(); if(window.parent && typeof window.parent.loadPage === 'function'){ window.parent.loadPage('maintain_locker_items.php?edit_id=<?= $item['id'] ?><?= !empty($truck_filter) || !empty($locker_filter) ? '&' . http_build_query(array_filter(['truck_filter' => $truck_filter, 'locker_filter' => $locker_filter])) : '' ?>'); }">Edit</a> | 
                        <a href="#" onclick="event.preventDefault(); deleteItem(<?= $item['id'] ?>, '<?= $truck_filter ?>', '<?= $locker_filter ?>');" >Delete</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <div class="no-items" style="margin: 20px 0; padding: 15px; background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px;">
                <p>No items found<?= !empty($truck_filter) || !empty($locker_filter) ? ' for the selected filters' : '' ?> for <?= htmlspecialchars($station['name']) ?> station.</p>
            </div>
        <?php endif; ?>
    </div>

</div>
<script>
// JavaScript for real-time filtering
function updateFilters() {
    const truckSelect = document.getElementById('truck_filter');
    const lockerSelect = document.getElementById('locker_filter');
    const selectedTruck = truckSelect.value;
    const selectedLocker = lockerSelect.value;
    
    // Update locker dropdown based on selected truck
    updateLockerDropdown(selectedTruck, selectedLocker);
    
    // Update items list and summary
    updateItemsList(selectedTruck, selectedLocker);
    
    // Update URL without page reload
    try {
        const url = new URL(window.location);
        if (selectedTruck) {
            url.searchParams.set('truck_filter', selectedTruck);
        } else {
            url.searchParams.delete('truck_filter');
        }
        
        if (selectedLocker) {
            url.searchParams.set('locker_filter', selectedLocker);
        } else {
            url.searchParams.delete('locker_filter');
        }
        
        window.history.replaceState({}, '', url);
    } catch (e) {
        console.error('Error updating URL:', e);
    }
}

function updateLockerDropdown(selectedTruck, currentLocker = '') {
    const lockerSelect = document.getElementById('locker_filter');
    
    // Clear current locker options
    lockerSelect.innerHTML = '<option value="">ALL</option>';
    
    if (selectedTruck) {
        // Get lockers for selected truck via AJAX
        fetch(window.location.pathname + '?ajax=get_lockers&truck_id=' + encodeURIComponent(selectedTruck))
            .then(response => response.json())
            .then(lockers => {
                lockers.forEach(function(locker) {
                    const option = document.createElement('option');
                    option.value = locker.id;
                    option.textContent = locker.name;
                    if (locker.id == currentLocker) {
                        option.selected = true;
                    }
                    lockerSelect.appendChild(option);
                });
            })
            .catch(error => console.error('Error fetching lockers:', error));
    } else {
        // Clear locker selection when no truck is selected
        lockerSelect.value = '';
    }
}

function updateItemsList(selectedTruck, selectedLocker) {
    const params = new URLSearchParams();
    params.append('ajax', 'get_items');
    if (selectedTruck) params.append('truck_filter', selectedTruck);
    if (selectedLocker) params.append('locker_filter', selectedLocker);
    
    fetch(window.location.pathname + '?' + params.toString())
        .then(response => response.json())
        .then(data => {
            // Update item count
            document.getElementById('item-count').textContent = data.count;
            
            // Update filter status
            let filterStatus = '';
            if (data.truck_name) {
                filterStatus += '<strong>Filtered by Truck:</strong> ' + data.truck_name;
                if (data.locker_name) {
                    filterStatus += '<br><strong>Filtered by Locker:</strong> ' + data.locker_name;
                }
            } else {
                filterStatus = '<strong>Showing:</strong> All trucks and lockers for <?= htmlspecialchars($station['name']) ?>';
            }
            document.getElementById('filter-status').innerHTML = filterStatus;
            
            // Update items list
            const itemsList = document.getElementById('items-list');
            if (data.items.length > 0) {
                let html = '<ul>';
                data.items.forEach(function(item) {
                    const editParams = new URLSearchParams();
                    editParams.append('edit_id', item.id);
                    if (selectedTruck) editParams.append('truck_filter', selectedTruck);
                    if (selectedLocker) editParams.append('locker_filter', selectedLocker);
                    
                    html += '<li>' + 
                            item.name + ' (' + item.truck_name + ' - ' + item.locker_name + ') ' +
                            '<a href="#" onclick="event.preventDefault(); if(window.parent && typeof window.parent.loadPage === \'function\'){ window.parent.loadPage(\'maintain_locker_items.php?' + editParams.toString() + '\'); }">Edit</a> | ' +
                            '<a href="#" onclick="event.preventDefault(); deleteItem(' + item.id + ', \'' + selectedTruck + '\', \'' + selectedLocker + '\');" >Delete</a>' +
                            '</li>';
                });
                html += '</ul>';
                itemsList.innerHTML = html;
            } else {
                const filterText = (selectedTruck || selectedLocker) ? ' for the selected filters' : '';
                itemsList.innerHTML = '<div class="no-items" style="margin: 20px 0; padding: 15px; background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px;">' +
                                     '<p>No items found' + filterText + ' for <?= htmlspecialchars($station['name']) ?> station.</p></div>';
            }
        })
        .catch(error => console.error('Error fetching items:', error));
}

// Function for Add New Item truck/locker filtering
function updateAddLockerDropdown() {
    const addTruckSelect = document.getElementById('add_truck_filter');
    const addLockerSelect = document.getElementById('locker_id');
    const selectedTruck = addTruckSelect.value;
    
    // Clear current locker options
    addLockerSelect.innerHTML = '<option value="">Select Locker</option>';
    
    if (selectedTruck) {
        // Get lockers for selected truck via AJAX
        fetch(window.location.pathname + '?ajax=get_lockers&truck_id=' + encodeURIComponent(selectedTruck))
            .then(response => response.json())
            .then(lockers => {
                lockers.forEach(function(locker) {
                    const option = document.createElement('option');
                    option.value = locker.id;
                    option.textContent = locker.name;
                    addLockerSelect.appendChild(option);
                });
            })
            .catch(error => console.error('Error fetching add item lockers:', error));
    } else {
        // Reset to default message when no truck is selected
        addLockerSelect.innerHTML = '<option value="">Select Truck First</option>';
    }
}

// New function to handle item deletion via AJAX
function deleteItem(itemId, truckFilter, lockerFilter) {
    if (confirm('Are you sure you want to delete this item?')) {
        const formData = new FormData();
        formData.append('delete_item_id', itemId);
        formData.append('ajax_action', 'delete_item'); // This will be caught by admin.php's POST handler
        
        fetch('admin.php', { // Send POST request to admin.php
            method: 'POST', // Explicitly set method to POST
            body: formData,
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (window.parent && typeof window.parent.loadPage === 'function') {
                    // Reload the page to show updated list, preserving filters
                    let reloadParams = '';
                    if (truckFilter) reloadParams += `truck_filter=${truckFilter}`;
                    if (lockerFilter) reloadParams += `${reloadParams ? '&' : ''}locker_filter=${lockerFilter}`;
                    window.parent.loadPage('maintain_locker_items.php' + (reloadParams ? '?' + reloadParams : ''));
                }
            } else {
                alert('Error deleting item: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error deleting item:', error);
            alert('Error deleting item. Please try again.');
        });
    }
}


// Handle form submissions via AJAX
document.addEventListener('DOMContentLoaded', function() {
    // Handle add item form
    const addForm = document.getElementById('add-item-form');
    if (addForm) {
        addForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('ajax_action', 'add_item'); // Indicate AJAX action
            
            fetch('admin.php', { // Submit to admin.php
                method: 'POST',
                body: formData
            })
            .then(response => response.json()) // Expect JSON response
            .then(data => {
                if (data.success) {
                    if (window.parent && typeof window.parent.loadPage === 'function') {
                        // Reload the page to show updated list, preserving filters
                        const currentUrl = new URL(window.location);
                        const truckFilter = currentUrl.searchParams.get('truck_filter');
                        const lockerFilter = currentUrl.searchParams.get('locker_filter');
                        let reloadParams = '';
                        if (truckFilter) reloadParams += `truck_filter=${truckFilter}`;
                        if (lockerFilter) reloadParams += `${reloadParams ? '&' : ''}locker_filter=${lockerFilter}`;
                        window.parent.loadPage('maintain_locker_items.php' + (reloadParams ? '?' + reloadParams : ''));
                    }
                } else {
                    alert('Error adding item: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error submitting add item form:', error);
                alert('Error adding item. Please try again.');
            });
        });
    }
    
    // Handle edit item form
    const editForm = document.getElementById('edit-item-form');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('ajax_action', 'edit_item'); // Indicate AJAX action
            
            fetch('admin.php', { // Submit to admin.php
                method: 'POST',
                body: formData
            })
            .then(response => response.json()) // Expect JSON response
            .then(data => {
                if (data.success) {
                    if (window.parent && typeof window.parent.loadPage === 'function') {
                        // Reload the page to show updated list, preserving filters
                        const currentUrl = new URL(window.location);
                        const truckFilter = currentUrl.searchParams.get('truck_filter');
                        const lockerFilter = currentUrl.searchParams.get('locker_filter');
                        let reloadParams = '';
                        if (truckFilter) reloadParams += `truck_filter=${truckFilter}`;
                        if (lockerFilter) reloadParams += `${reloadParams ? '&' : ''}locker_filter=${lockerFilter}`;
                        window.parent.loadPage('maintain_locker_items.php' + (reloadParams ? '?' + reloadParams : ''));
                    }
                } else {
                    alert('Error updating item: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error submitting edit item form:', error);
                alert('Error updating item. Please try again.');
            });
        });
    }

    // Initial update of locker dropdown for "Add New Item" form if a truck is pre-selected
    updateAddLockerDropdown();

    // Initial update of filter dropdowns and items list based on current URL parameters
    const currentUrl = new URL(window.location);
    const initialTruckFilter = currentUrl.searchParams.get('truck_filter') || '';
    const initialLockerFilter = currentUrl.searchParams.get('locker_filter') || '';
    
    // Set initial values for filter dropdowns
    document.getElementById('truck_filter').value = initialTruckFilter;
    updateLockerDropdown(initialTruckFilter, initialLockerFilter); // Pass initialLockerFilter to select it
    updateItemsList(initialTruckFilter, initialLockerFilter);
});
</script>
