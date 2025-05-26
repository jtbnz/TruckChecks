<?php
// Include password file
include('config.php');
include 'db.php';
include 'templates/header.php';

// Check if the user is logged in
if (!isset($_COOKIE['logged_in_' . DB_NAME]) || $_COOKIE['logged_in_' . DB_NAME] != 'true') {
    header('Location: login.php');
    exit;
}

$db = get_db_connection();

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
        <input type="text" name="item_name" placeholder="Item Name" required>
    </div>
    <div class="input-container">
        <select name="locker_id" required>
            <option value="">Select Locker</option>
            <?php foreach ($lockers as $locker): ?>
                <option value="<?= $locker['id'] ?>"><?= htmlspecialchars($locker['truck_name']) ?> - <?= htmlspecialchars($locker['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="button-container">
        <button type="submit" name="add_item" class="button touch-button">Add Item</button>
    </div>
</form>
<?php endif; ?>

<div class="filter-section" style="margin: 20px 0; padding: 15px; background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px;">
    <h2>Filter Items</h2>
    <form method="GET" style="display: flex; flex-wrap: wrap; gap: 15px; align-items: end;" id="filterForm">
        <?php if (isset($_GET['edit_id'])): ?>
            <input type="hidden" name="edit_id" value="<?= htmlspecialchars($_GET['edit_id']) ?>">
        <?php endif; ?>
        
        <div>
            <label for="truck_filter">Select Truck:</label>
            <select name="truck_filter" id="truck_filter" onchange="updateLockerFilter()">
                <option value="">All Trucks</option>
                <?php foreach ($trucks as $truck): ?>
                    <option value="<?= $truck['id'] ?>" <?= $truck_filter == $truck['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($truck['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div>
            <label for="locker_filter">Select Locker:</label>
            <select name="locker_filter" id="locker_filter">
                <option value="">All Lockers</option>
                <?php if (!empty($truck_filter)): ?>
                    <?php foreach ($filter_lockers as $locker): ?>
                        <option value="<?= $locker['id'] ?>" <?= $locker_filter == $locker['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($locker['name']) ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>
        
        <div>
            <button type="submit" class="button touch-button">Apply Filter</button>
            <a href="maintain_locker_items.php" class="button touch-button" style="background-color: #6c757d;">Show All</a>
        </div>
    </form>
</div>

<script>
// JavaScript to update locker dropdown based on selected truck
function updateLockerFilter() {
    const truckSelect = document.getElementById('truck_filter');
    const lockerSelect = document.getElementById('locker_filter');
    const selectedTruck = truckSelect.value;
    
    // Clear current locker options
    lockerSelect.innerHTML = '<option value="">All Lockers</option>';
    
    if (selectedTruck) {
        // Get lockers for selected truck via AJAX
        const xhr = new XMLHttpRequest();
        xhr.open('GET', '?ajax=get_lockers&truck_id=' + selectedTruck, true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && xhr.status === 200) {
                try {
                    const lockers = JSON.parse(xhr.responseText);
                    lockers.forEach(function(locker) {
                        const option = document.createElement('option');
                        option.value = locker.id;
                        option.textContent = locker.name;
                        lockerSelect.appendChild(option);
                    });
                } catch (e) {
                    console.error('Error parsing locker data:', e);
                }
            }
        };
        xhr.send();
    }
}

// Auto-submit form when truck changes (optional - remove if you prefer manual apply)
document.getElementById('truck_filter').addEventListener('change', function() {
    // Clear locker filter when truck changes
    document.getElementById('locker_filter').value = '';
});
</script>

<?php
// Handle AJAX request for lockers
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_lockers' && isset($_GET['truck_id'])) {
    $truck_id = $_GET['truck_id'];
    $stmt = $db->prepare('SELECT id, name FROM lockers WHERE truck_id = ? ORDER BY name');
    $stmt->execute([$truck_id]);
    $ajax_lockers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($ajax_lockers);
    exit;
}
?>

<div class="stats-section" style="margin: 20px 0; padding: 15px; background-color: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 5px;">
    <h2>Items Summary</h2>
    <p><strong>Total Items Shown:</strong> <?= count($items) ?></p>
    <?php if (!empty($truck_filter)): ?>
        <?php 
        $selected_truck = array_filter($trucks, function($truck) use ($truck_filter) {
            return $truck['id'] == $truck_filter;
        });
        $selected_truck = reset($selected_truck);
        ?>
        <p><strong>Filtered by Truck:</strong> <?= htmlspecialchars($selected_truck['name']) ?></p>
    <?php endif; ?>
    <?php if (!empty($locker_filter)): ?>
        <?php 
        $selected_locker = array_filter($filter_lockers, function($locker) use ($locker_filter) {
            return $locker['id'] == $locker_filter;
        });
        if (empty($selected_locker)) {
            // Fallback to all lockers if filter_lockers is empty
            $selected_locker = array_filter($lockers, function($locker) use ($locker_filter) {
                return $locker['id'] == $locker_filter;
            });
        }
        $selected_locker = reset($selected_locker);
        ?>
        <p><strong>Filtered by Locker:</strong> <?= htmlspecialchars($selected_locker['name']) ?></p>
    <?php endif; ?>
    <?php if (empty($truck_filter) && empty($locker_filter)): ?>
        <p><strong>Showing:</strong> All trucks and lockers</p>
    <?php endif; ?>
</div>

<h2>Existing Items</h2>
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

<div class="button-container" style="margin-top: 20px;">
    <a href="admin.php" class="button touch-button">Admin Page</a>
</div>

<?php include 'templates/footer.php'; ?>
