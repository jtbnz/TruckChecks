<?php
include_once('auth.php');
include_once('config.php');
$station = requireStation();

// Require authentication and get user context
requireAuth();
$user = getCurrentUser();

include('db.php');
$db = get_db_connection();

// Handle AJAX requests FIRST, before any HTML output
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

include 'templates/header.php';

// Handle adding a new item
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_item'])) {
    $item_name = $_POST['item_name'];
    $locker_id = $_POST['locker_id'];
    if (!empty($item_name) && !empty($locker_id)) {
        $query = $db->prepare('INSERT INTO items (name, locker_id) VALUES (:name, :locker_id)');
        $query->execute(['name' => $item_name, 'locker_id' => $locker_id]);
    }
}

// Handle editing an item
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_item'])) {
    $item_id = $_POST['item_id'];
    $item_name = $_POST['item_name'];
    $locker_id = $_POST['locker_id'];
    if (!empty($item_name) && !empty($locker_id) && !empty($item_id)) {
        $query = $db->prepare('UPDATE items SET name = :name, locker_id = :locker_id WHERE id = :id');
        $query->execute(['name' => $item_name, 'locker_id' => $locker_id, 'id' => $item_id]);
    }
}

// Handle deleting an item
if (isset($_GET['delete_item_id'])) {
    $item_id = $_GET['delete_item_id'];
    $query = $db->prepare('DELETE FROM items WHERE id = :id');
    $query->execute(['id' => $item_id]);
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

// Fetch all trucks for the dropdown and filter
$trucks = $db->query('SELECT * FROM trucks ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

// Fetch all lockers for the dropdown
$lockers = $db->query('SELECT l.*, t.name as truck_name FROM lockers l JOIN trucks t ON l.truck_id = t.id ORDER BY t.name, l.name')->fetchAll(PDO::FETCH_ASSOC);

// Fetch lockers for the selected truck (for filter dropdown)
$filter_lockers = [];
if (!empty($truck_filter)) {
    $stmt = $db->prepare('SELECT l.*, t.name as truck_name FROM lockers l JOIN trucks t ON l.truck_id = t.id WHERE t.id = ? ORDER BY l.name');
    $stmt->execute([$truck_filter]);
    $filter_lockers = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch items with optional truck and locker filter
$items_query = 'SELECT i.*, l.name as locker_name, t.name as truck_name, t.id as truck_id, l.id as locker_id FROM items i JOIN lockers l ON i.locker_id = l.id JOIN trucks t ON l.truck_id = t.id';
$params = [];
$where_conditions = [];

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

<h1>Maintain Locker Items</h1>

<?php if ($edit_item): ?>
<h2>Edit Item</h2>
<form method="POST" class="edit-item-form">
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
        <button type="submit" name="edit_item" class="button touch-button">Update Item</button>
        <a href="maintain_locker_items.php<?= !empty($truck_filter) || !empty($locker_filter) ? '?' . http_build_query(array_filter(['truck_filter' => $truck_filter, 'locker_filter' => $locker_filter])) : '' ?>" class="button touch-button" style="background-color: #6c757d;">Cancel</a>
    </div>
</form>
<?php else: ?>
<h2>Add New Item</h2>
<form method="POST" class="add-item-form">

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
        <button type="submit" name="add_item" class="button touch-button">Add Item</button>
    </div>
</form>
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
            <strong>Showing:</strong> All trucks and lockers
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
                    <a href="?edit_id=<?= $item['id'] ?><?= !empty($truck_filter) || !empty($locker_filter) ? '&' . http_build_query(array_filter(['truck_filter' => $truck_filter, 'locker_filter' => $locker_filter])) : '' ?>">Edit</a> | 
                    <a href="?delete_item_id=<?= $item['id'] ?><?= !empty($truck_filter) || !empty($locker_filter) ? '&' . http_build_query(array_filter(['truck_filter' => $truck_filter, 'locker_filter' => $locker_filter])) : '' ?>" onclick="return confirm('Are you sure you want to delete this item?');">Delete</a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <div class="no-items" style="margin: 20px 0; padding: 15px; background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px;">
            <p>No items found<?= !empty($truck_filter) || !empty($locker_filter) ? ' for the selected filters' : '' ?>.</p>
        </div>
    <?php endif; ?>
</div>

<div class="button-container" style="margin-top: 20px;">
    <a href="admin.php" class="button touch-button">Admin Page</a>
</div>

<script>
// JavaScript for real-time filtering
function updateFilters() {
    const truckSelect = document.getElementById('truck_filter');
    const lockerSelect = document.getElementById('locker_filter');
    const selectedTruck = truckSelect.value;
    const selectedLocker = lockerSelect.value;
    
    console.log('updateFilters called - Truck:', selectedTruck, 'Locker:', selectedLocker);
    
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
    
    console.log('updateLockerDropdown called - Truck:', selectedTruck, 'Current Locker:', currentLocker);
    
    // Clear current locker options
    lockerSelect.innerHTML = '<option value="">ALL</option>';
    
    if (selectedTruck) {
        // Get lockers for selected truck via AJAX
        const xhr = new XMLHttpRequest();
        const url = window.location.pathname + '?ajax=get_lockers&truck_id=' + encodeURIComponent(selectedTruck);
        console.log('AJAX URL:', url);
        
        xhr.open('GET', url, true);
        xhr.onreadystatechange = function() {
            console.log('XHR State:', xhr.readyState, 'Status:', xhr.status);
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    console.log('Response:', xhr.responseText);
                    try {
                        const lockers = JSON.parse(xhr.responseText);
                        console.log('Parsed lockers:', lockers);
                        lockers.forEach(function(locker) {
                            const option = document.createElement('option');
                            option.value = locker.id;
                            option.textContent = locker.name;
                            if (locker.id == currentLocker) {
                                option.selected = true;
                            }
                            lockerSelect.appendChild(option);
                        });
                    } catch (e) {
                        console.error('Error parsing locker data:', e);
                        console.error('Response was:', xhr.responseText);
                    }
                } else {
                    console.error('AJAX Error - Status:', xhr.status, 'Response:', xhr.responseText);
                }
            }
        };
        xhr.send();
    } else {
        // Clear locker selection when no truck is selected
        lockerSelect.value = '';
    }
}

function updateItemsList(selectedTruck, selectedLocker) {
    const xhr = new XMLHttpRequest();
    const params = new URLSearchParams();
    params.append('ajax', 'get_items');
    if (selectedTruck) params.append('truck_filter', selectedTruck);
    if (selectedLocker) params.append('locker_filter', selectedLocker);
    
    const url = window.location.pathname + '?' + params.toString();
    console.log('Items AJAX URL:', url);
    
    xhr.open('GET', url, true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                const data = JSON.parse(xhr.responseText);
                console.log('Items data:', data);
                
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
                    filterStatus = '<strong>Showing:</strong> All trucks and lockers';
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
                        
                        const deleteParams = new URLSearchParams();
                        deleteParams.append('delete_item_id', item.id);
                        if (selectedTruck) deleteParams.append('truck_filter', selectedTruck);
                        if (selectedLocker) deleteParams.append('locker_filter', selectedLocker);
                        
                        html += '<li>' + 
                                item.name + ' (' + item.truck_name + ' - ' + item.locker_name + ') ' +
                                '<a href="?' + editParams.toString() + '">Edit</a> | ' +
                                '<a href="?' + deleteParams.toString() + '" onclick="return confirm(\'Are you sure you want to delete this item?\');">Delete</a>' +
                                '</li>';
                    });
                    html += '</ul>';
                    itemsList.innerHTML = html;
                } else {
                    const filterText = (selectedTruck || selectedLocker) ? ' for the selected filters' : '';
                    itemsList.innerHTML = '<div class="no-items" style="margin: 20px 0; padding: 15px; background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px;">' +
                                         '<p>No items found' + filterText + '.</p></div>';
                }
            } catch (e) {
                console.error('Error parsing items data:', e);
                console.error('Response was:', xhr.responseText);
            }
        }
    };
    xhr.send();
}

// Function for Add New Item truck/locker filtering
function updateAddLockerDropdown() {
    const addTruckSelect = document.getElementById('add_truck_filter');
    const addLockerSelect = document.getElementById('locker_id');
    const selectedTruck = addTruckSelect.value;
    
    console.log('updateAddLockerDropdown called - Truck:', selectedTruck);
    
    // Clear current locker options
    addLockerSelect.innerHTML = '<option value="">Select Locker</option>';
    
    if (selectedTruck) {
        // Get lockers for selected truck via AJAX
        const xhr = new XMLHttpRequest();
        const url = window.location.pathname + '?ajax=get_lockers&truck_id=' + encodeURIComponent(selectedTruck);
        console.log('Add Item AJAX URL:', url);
        
        xhr.open('GET', url, true);
        xhr.onreadystatechange = function() {
            console.log('Add Item XHR State:', xhr.readyState, 'Status:', xhr.status);
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    console.log('Add Item Response:', xhr.responseText);
                    try {
                        const lockers = JSON.parse(xhr.responseText);
                        console.log('Add Item Parsed lockers:', lockers);
                        lockers.forEach(function(locker) {
                            const option = document.createElement('option');
                            option.value = locker.id;
                            option.textContent = locker.name;
                            addLockerSelect.appendChild(option);
                        });
                    } catch (e) {
                        console.error('Add Item Error parsing locker data:', e);
                        console.error('Add Item Response was:', xhr.responseText);
                    }
                } else {
                    console.error('Add Item AJAX Error - Status:', xhr.status, 'Response:', xhr.responseText);
                }
            }
        };
        xhr.send();
    } else {
        // Reset to default message when no truck is selected
        addLockerSelect.innerHTML = '<option value="">Select Truck First</option>';
    }
}

// Wait for DOM to be ready
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, setting up event listeners');
    
    // Clear locker filter when truck changes
    const truckSelect = document.getElementById('truck_filter');
    if (truckSelect) {
        truckSelect.addEventListener('change', function() {
            console.log('Truck changed to:', this.value);
            if (!this.value) {
                document.getElementById('locker_filter').value = '';
            }
        });
    }
});
</script>

<?php include 'templates/footer.php'; ?>
