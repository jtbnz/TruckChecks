<?php
// This is a module file - it should only be included from admin.php
// No headers, footers, or standalone functionality

// Ensure we have required context from admin.php
if (!isset($pdo) || !isset($user) || !isset($currentStation)) {
    die('This module must be loaded through admin.php');
}

// Use the station from admin context
$station = $currentStation;
if (!$station) {
    echo '<div class="alert alert-error">No station selected. Please select a station first.</div>';
    return;
}

$db = $pdo; // Use the PDO connection from admin.php
$error_message = '';
$success_message = '';

// Initialize DEBUG if not defined
if (!defined('DEBUG')) {
    define('DEBUG', false);
}

if (DEBUG) {
    error_log("maintain_locker_items module: Started. Station ID: " . $station['id']);
}

// Handle AJAX form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    
    try {
        switch ($_POST['ajax_action']) {
            case 'add_item':
                $item_name = trim($_POST['item_name'] ?? '');
                $locker_id = $_POST['locker_id'] ?? '';
                $quantity = intval($_POST['quantity'] ?? 1);
                
                if (empty($item_name) || empty($locker_id)) {
                    throw new Exception('Item name and locker are required');
                }
                
                if ($quantity < 1) {
                    throw new Exception('Quantity must be at least 1');
                }
                
                // Verify locker belongs to current station
                $check_query = $db->prepare('
                    SELECT l.id 
                    FROM lockers l 
                    JOIN trucks t ON l.truck_id = t.id 
                    WHERE l.id = :id AND t.station_id = :station_id
                ');
                $check_query->execute(['id' => $locker_id, 'station_id' => $station['id']]);
                
                if (!$check_query->fetch()) {
                    throw new Exception('Invalid locker selection');
                }
                
                $query = $db->prepare('INSERT INTO locker_items (name, locker_id, quantity) VALUES (:name, :locker_id, :quantity)');
                $query->execute(['name' => $item_name, 'locker_id' => $locker_id, 'quantity' => $quantity]);
                
                $response['success'] = true;
                $response['message'] = "Item '{$item_name}' added successfully.";
                
                if (DEBUG) {
                    error_log("maintain_locker_items module: Item '{$item_name}' added successfully");
                }
                break;
                
            case 'edit_item':
                $item_id = $_POST['item_id'] ?? '';
                $item_name = trim($_POST['item_name'] ?? '');
                $locker_id = $_POST['locker_id'] ?? '';
                $quantity = intval($_POST['quantity'] ?? 1);
                
                if (empty($item_id) || empty($item_name) || empty($locker_id)) {
                    throw new Exception('All fields are required');
                }
                
                if ($quantity < 1) {
                    throw new Exception('Quantity must be at least 1');
                }
                
                // Verify item belongs to current station
                $check_query = $db->prepare('
                    SELECT li.id 
                    FROM locker_items li 
                    JOIN lockers l ON li.locker_id = l.id 
                    JOIN trucks t ON l.truck_id = t.id 
                    WHERE li.id = :id AND t.station_id = :station_id
                ');
                $check_query->execute(['id' => $item_id, 'station_id' => $station['id']]);
                
                if (!$check_query->fetch()) {
                    throw new Exception('Item not found or access denied');
                }
                
                // Verify new locker belongs to current station
                $locker_check = $db->prepare('
                    SELECT l.id 
                    FROM lockers l 
                    JOIN trucks t ON l.truck_id = t.id 
                    WHERE l.id = :id AND t.station_id = :station_id
                ');
                $locker_check->execute(['id' => $locker_id, 'station_id' => $station['id']]);
                
                if (!$locker_check->fetch()) {
                    throw new Exception('Invalid locker selection');
                }
                
                $query = $db->prepare('UPDATE locker_items SET name = :name, locker_id = :locker_id, quantity = :quantity WHERE id = :id');
                $query->execute(['name' => $item_name, 'locker_id' => $locker_id, 'quantity' => $quantity, 'id' => $item_id]);
                
                $response['success'] = true;
                $response['message'] = 'Item updated successfully.';
                
                if (DEBUG) {
                    error_log("maintain_locker_items module: Item ID {$item_id} updated successfully");
                }
                break;
                
            case 'delete_item':
                $item_id = $_POST['item_id'] ?? '';
                
                if (empty($item_id)) {
                    throw new Exception('Item ID is required');
                }
                
                // Verify item belongs to current station
                $check_query = $db->prepare('
                    SELECT li.id 
                    FROM locker_items li 
                    JOIN lockers l ON li.locker_id = l.id 
                    JOIN trucks t ON l.truck_id = t.id 
                    WHERE li.id = :id AND t.station_id = :station_id
                ');
                $check_query->execute(['id' => $item_id, 'station_id' => $station['id']]);
                
                if (!$check_query->fetch()) {
                    throw new Exception('Item not found or access denied');
                }
                
                $query = $db->prepare('DELETE FROM locker_items WHERE id = :id');
                $query->execute(['id' => $item_id]);
                
                $response['success'] = true;
                $response['message'] = 'Item deleted successfully.';
                
                if (DEBUG) {
                    error_log("maintain_locker_items module: Item ID {$item_id} deleted successfully");
                }
                break;
                
            default:
                throw new Exception('Invalid action');
        }
    } catch (Exception $e) {
        $response['success'] = false;
        $response['message'] = $e->getMessage();
        
        if (DEBUG) {
            error_log("maintain_locker_items module: Error - " . $e->getMessage());
        }
    }
    
    echo json_encode($response);
    exit;
}

// Get item to edit if edit_id is set
$edit_item = null;
if (isset($_GET['edit_id'])) {
    $edit_id = $_GET['edit_id'];
    try {
        $query = $db->prepare('
            SELECT li.* 
            FROM locker_items li 
            JOIN lockers l ON li.locker_id = l.id 
            JOIN trucks t ON l.truck_id = t.id 
            WHERE li.id = :id AND t.station_id = :station_id
        ');
        $query->execute(['id' => $edit_id, 'station_id' => $station['id']]);
        $edit_item = $query->fetch(PDO::FETCH_ASSOC);
        
        if (!$edit_item) {
            $error_message = "Item not found or access denied.";
        }
    } catch (Exception $e) {
        $error_message = "Error loading item: " . $e->getMessage();
    }
}

// Get all lockers for current station grouped by truck
try {
    $lockers_query = $db->prepare('
        SELECT l.*, t.name as truck_name 
        FROM lockers l 
        JOIN trucks t ON l.truck_id = t.id 
        WHERE t.station_id = :station_id 
        ORDER BY t.name, l.name
    ');
    $lockers_query->execute(['station_id' => $station['id']]);
    $lockers = $lockers_query->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $lockers = [];
}

// Fetch all items for current station
try {
    $items_query = $db->prepare('
        SELECT li.*, l.name as locker_name, t.name as truck_name
        FROM locker_items li 
        JOIN lockers l ON li.locker_id = l.id
        JOIN trucks t ON l.truck_id = t.id
        WHERE t.station_id = :station_id 
        ORDER BY t.name, l.name, li.name
    ');
    $items_query->execute(['station_id' => $station['id']]);
    $items = $items_query->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_message = "Error loading items: " . $e->getMessage();
    $items = [];
}
?>

<style>
    .maintain-container {
        max-width: 900px;
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

    .input-container input, .input-container select {
        width: 100%;
        padding: 10px;
        border: 1px solid #ccc;
        border-radius: 5px;
        font-size: 16px;
        box-sizing: border-box;
    }

    .input-container input[type="number"] {
        width: 150px;
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

    .items-list {
        background: white;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .items-list h2 {
        margin-top: 0;
        color: #12044C;
    }

    .item-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px;
        border-bottom: 1px solid #eee;
        background-color: #f8f9fa;
        margin-bottom: 10px;
        border-radius: 5px;
    }

    .item-item:last-child {
        margin-bottom: 0;
    }

    .item-info {
        flex-grow: 1;
    }

    .item-name {
        font-weight: bold;
        color: #12044C;
        font-size: 16px;
    }

    .item-details {
        color: #666;
        font-size: 14px;
        margin-top: 5px;
    }

    .item-actions {
        display: flex;
        gap: 10px;
    }

    .item-actions button {
        padding: 6px 12px;
        text-decoration: none;
        border-radius: 3px;
        font-size: 14px;
        transition: background-color 0.3s;
        border: none;
        cursor: pointer;
    }

    .edit-link {
        background-color: #007bff;
        color: white;
    }

    .edit-link:hover {
        background-color: #0056b3;
    }

    .delete-link {
        background-color: #dc3545;
        color: white;
    }

    .delete-link:hover {
        background-color: #c82333;
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

    .quantity-badge {
        background-color: #007bff;
        color: white;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: bold;
        margin-left: 10px;
    }

    /* Mobile responsive */
    @media (max-width: 768px) {
        .maintain-container {
            padding: 10px;
        }

        .item-item {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }

        .item-actions {
            width: 100%;
            justify-content: flex-end;
        }
    }
</style>

<div class="maintain-container">
    <div class="page-header">
        <h1 class="page-title">Maintain Locker Items</h1>
    </div>

    <div class="station-info">
        <div class="station-name"><?= htmlspecialchars($station['name']) ?></div>
        <?php if (!empty($station['description'])): ?>
            <div style="color: #666; margin-top: 5px;"><?= htmlspecialchars($station['description']) ?></div>
        <?php endif; ?>
    </div>

    <div id="message-container"></div>

    <?php if (empty($lockers)): ?>
        <div class="alert alert-error">
            No lockers found for this station. Please add lockers first before adding items.
        </div>
    <?php else: ?>
        <?php if ($edit_item): ?>
            <div class="form-section">
                <h2>Edit Item</h2>
                <form id="edit-item-form" data-item-id="<?= $edit_item['id'] ?>">
                    <div class="input-container">
                        <input type="text" name="item_name" placeholder="Item Name" value="<?= htmlspecialchars($edit_item['name']) ?>" required>
                    </div>
                    <div class="input-container">
                        <select name="locker_id" required>
                            <option value="">Select Locker</option>
                            <?php 
                            $current_truck = '';
                            foreach ($lockers as $locker): 
                                if ($current_truck != $locker['truck_name']): 
                                    if ($current_truck != '') echo '</optgroup>';
                                    $current_truck = $locker['truck_name'];
                                    echo '<optgroup label="' . htmlspecialchars($locker['truck_name']) . '">';
                                endif;
                            ?>
                                <option value="<?= $locker['id'] ?>" <?= $locker['id'] == $edit_item['locker_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($locker['name']) ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if ($current_truck != '') echo '</optgroup>'; ?>
                        </select>
                    </div>
                    <div class="input-container">
                        <label>Quantity:</label>
                        <input type="number" name="quantity" min="1" value="<?= $edit_item['quantity'] ?>" required>
                    </div>
                    <div class="button-container">
                        <button type="submit" class="button">Update Item</button>
                        <button type="button" onclick="loadPage('maintain_locker_items.php')" class="button secondary">Cancel</button>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div class="form-section">
                <h2>Add New Item</h2>
                <form id="add-item-form">
                    <div class="input-container">
                        <input type="text" name="item_name" placeholder="Item Name" required>
                    </div>
                    <div class="input-container">
                        <select name="locker_id" required>
                            <option value="">Select Locker</option>
                            <?php 
                            $current_truck = '';
                            foreach ($lockers as $locker): 
                                if ($current_truck != $locker['truck_name']): 
                                    if ($current_truck != '') echo '</optgroup>';
                                    $current_truck = $locker['truck_name'];
                                    echo '<optgroup label="' . htmlspecialchars($locker['truck_name']) . '">';
                                endif;
                            ?>
                                <option value="<?= $locker['id'] ?>"><?= htmlspecialchars($locker['name']) ?></option>
                            <?php endforeach; ?>
                            <?php if ($current_truck != '') echo '</optgroup>'; ?>
                        </select>
                    </div>
                    <div class="input-container">
                        <label>Quantity:</label>
                        <input type="number" name="quantity" min="1" value="1" required>
                    </div>
                    <div class="button-container">
                        <button type="submit" class="button">Add Item</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="items-list">
        <h2>Existing Items</h2>
        
        <?php if (empty($items)): ?>
            <div class="empty-state">
                <h3>No Items Found</h3>
                <p>No items have been added to this station yet. Add your first item using the form above.</p>
            </div>
        <?php else: ?>
            <?php foreach ($items as $item): ?>
                <div class="item-item">
                    <div class="item-info">
                        <div class="item-name">
                            <?= htmlspecialchars($item['name']) ?>
                            <?php if ($item['quantity'] > 1): ?>
                                <span class="quantity-badge">Qty: <?= $item['quantity'] ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="item-details">
                            Truck: <?= htmlspecialchars($item['truck_name']) ?> | 
                            Locker: <?= htmlspecialchars($item['locker_name']) ?>
                        </div>
                    </div>
                    <div class="item-actions">
                        <button onclick="loadPage('maintain_locker_items.php?edit_id=<?= $item['id'] ?>')" class="edit-link">Edit</button>
                        <button onclick="deleteItem(<?= $item['id'] ?>, '<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>')" class="delete-link">Delete</button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
// Module-specific functions - defined immediately in global scope
function showMessage(message, isError = false) {
    const container = document.getElementById('message-container');
    container.innerHTML = `<div class="alert ${isError ? 'alert-error' : 'alert-success'}">${message}</div>`;
    
    // Auto-hide success messages after 3 seconds
    if (!isError) {
        setTimeout(() => {
            container.innerHTML = '';
        }, 3000);
    }
}

function deleteItem(itemId, itemName) {
    if (!confirm(`Are you sure you want to delete item "${itemName}"?`)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('ajax_action', 'delete_item');
    formData.append('item_id', itemId);
    
    fetch('admin_modules/maintain_locker_items.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(data.message);
            // Reload the module
            setTimeout(() => {
                if (window.loadPage) {
                    window.loadPage('maintain_locker_items.php');
                }
            }, 1000);
        } else {
            showMessage(data.message, true);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMessage('An error occurred while deleting the item.', true);
    });
}

// Ensure functions are available globally
if (typeof window !== 'undefined') {
    window.showMessage = showMessage;
    window.deleteItem = deleteItem;
}

// Set up form handlers - use immediate execution instead of DOMContentLoaded
// since the module is loaded via AJAX after DOM is ready
(function() {
    // Handle add item form
    const addForm = document.getElementById('add-item-form');
    if (addForm) {
        addForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('ajax_action', 'add_item');
            
            fetch('admin_modules/maintain_locker_items.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage(data.message);
                    // Clear the form
                    this.reset();
                    // Reload the module
                    setTimeout(() => {
                        if (window.loadPage) {
                            window.loadPage('maintain_locker_items.php');
                        }
                    }, 1000);
                } else {
                    showMessage(data.message, true);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('An error occurred while adding the item.', true);
            });
        });
    }
    
    // Handle edit item form
    const editForm = document.getElementById('edit-item-form');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('ajax_action', 'edit_item');
            formData.append('item_id', this.dataset.itemId);
            
            fetch('admin_modules/maintain_locker_items.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage(data.message);
                    // Reload the module
                    setTimeout(() => {
                        if (window.loadPage) {
                            window.loadPage('maintain_locker_items.php');
                        }
                    }, 1000);
                } else {
                    showMessage(data.message, true);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('An error occurred while updating the item.', true);
            });
        });
    }
    
    <?php if (DEBUG): ?>
    console.log('Maintain Locker Items module loaded');
    console.log('Station:', <?= json_encode($station['name']) ?>);
    console.log('Items count:', <?= count($items) ?>);
    console.log('Lockers count:', <?= count($lockers) ?>);
    console.log('deleteItem function available:', typeof deleteItem !== 'undefined');
    <?php endif; ?>
})();
</script>
