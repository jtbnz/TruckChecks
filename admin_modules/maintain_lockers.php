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
    error_log("maintain_lockers module: Started. Station ID: " . $station['id']);
}

// Handle AJAX form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    
    try {
        switch ($_POST['ajax_action']) {
            case 'add_locker':
                $locker_name = trim($_POST['locker_name'] ?? '');
                $truck_id = $_POST['truck_id'] ?? '';
                
                if (empty($locker_name) || empty($truck_id)) {
                    throw new Exception('Locker name and truck selection are required');
                }
                
                // Verify truck belongs to current station
                $truck_check = $db->prepare('SELECT id FROM trucks WHERE id = :id AND station_id = :station_id');
                $truck_check->execute(['id' => $truck_id, 'station_id' => $station['id']]);
                
                if (!$truck_check->fetch()) {
                    throw new Exception('Selected truck not found or access denied');
                }
                
                $query = $db->prepare('INSERT INTO lockers (name, truck_id) VALUES (:name, :truck_id)');
                $query->execute(['name' => $locker_name, 'truck_id' => $truck_id]);
                
                $response['success'] = true;
                $response['message'] = "Locker '{$locker_name}' added successfully.";
                
                if (DEBUG) {
                    error_log("maintain_lockers module: Locker '{$locker_name}' added successfully");
                }
                break;
                
            case 'edit_locker':
                $locker_id = $_POST['locker_id'] ?? '';
                $locker_name = trim($_POST['locker_name'] ?? '');
                $truck_id = $_POST['truck_id'] ?? '';
                
                if (empty($locker_id) || empty($locker_name) || empty($truck_id)) {
                    throw new Exception('All fields are required');
                }
                
                // Verify both locker and truck belong to current station
                $check_query = $db->prepare('
                    SELECT l.id 
                    FROM lockers l 
                    JOIN trucks t ON l.truck_id = t.id 
                    WHERE l.id = :locker_id AND t.station_id = :station_id
                ');
                $check_query->execute(['locker_id' => $locker_id, 'station_id' => $station['id']]);
                
                if (!$check_query->fetch()) {
                    throw new Exception('Locker not found or access denied');
                }
                
                // Verify new truck belongs to current station
                $truck_check = $db->prepare('SELECT id FROM trucks WHERE id = :id AND station_id = :station_id');
                $truck_check->execute(['id' => $truck_id, 'station_id' => $station['id']]);
                
                if (!$truck_check->fetch()) {
                    throw new Exception('Selected truck not found or access denied');
                }
                
                $query = $db->prepare('UPDATE lockers SET name = :name, truck_id = :truck_id WHERE id = :id');
                $query->execute(['name' => $locker_name, 'truck_id' => $truck_id, 'id' => $locker_id]);
                
                $response['success'] = true;
                $response['message'] = 'Locker updated successfully.';
                
                if (DEBUG) {
                    error_log("maintain_lockers module: Locker ID {$locker_id} updated successfully");
                }
                break;
                
            case 'delete_locker':
                $locker_id = $_POST['locker_id'] ?? '';
                
                if (empty($locker_id)) {
                    throw new Exception('Locker ID is required');
                }
                
                // Verify locker belongs to current station
                $check_query = $db->prepare('
                    SELECT l.id 
                    FROM lockers l 
                    JOIN trucks t ON l.truck_id = t.id 
                    WHERE l.id = :locker_id AND t.station_id = :station_id
                ');
                $check_query->execute(['locker_id' => $locker_id, 'station_id' => $station['id']]);
                
                if (!$check_query->fetch()) {
                    throw new Exception('Locker not found or access denied');
                }
                
                // Check if locker has any items
                $item_check = $db->prepare('SELECT COUNT(*) FROM items WHERE locker_id = :locker_id');
                $item_check->execute(['locker_id' => $locker_id]);
                $item_count = $item_check->fetchColumn();
                
                if ($item_count > 0) {
                    throw new Exception("Cannot delete locker: This locker has {$item_count} item(s) assigned to it. Please delete all items first.");
                }
                
                $query = $db->prepare('DELETE FROM lockers WHERE id = :id');
                $query->execute(['id' => $locker_id]);
                
                $response['success'] = true;
                $response['message'] = 'Locker deleted successfully.';
                
                if (DEBUG) {
                    error_log("maintain_lockers module: Locker ID {$locker_id} deleted successfully");
                }
                break;
                
            default:
                throw new Exception('Invalid action');
        }
    } catch (Exception $e) {
        $response['success'] = false;
        $response['message'] = $e->getMessage();
        
        if (DEBUG) {
            error_log("maintain_lockers module: Error - " . $e->getMessage());
        }
    }
    
    echo json_encode($response);
    exit;
}

// Get locker to edit if edit_id is set
$edit_locker = null;
if (isset($_GET['edit_id'])) {
    $edit_id = $_GET['edit_id'];
    try {
        $query = $db->prepare('
            SELECT l.* 
            FROM lockers l 
            JOIN trucks t ON l.truck_id = t.id 
            WHERE l.id = :id AND t.station_id = :station_id
        ');
        $query->execute(['id' => $edit_id, 'station_id' => $station['id']]);
        $edit_locker = $query->fetch(PDO::FETCH_ASSOC);
        
        if (!$edit_locker) {
            $error_message = "Locker not found or access denied.";
        }
    } catch (Exception $e) {
        $error_message = "Error loading locker: " . $e->getMessage();
    }
}

// Fetch all trucks for current station for the dropdown
try {
    $trucks_query = $db->prepare('SELECT * FROM trucks WHERE station_id = :station_id ORDER BY name');
    $trucks_query->execute(['station_id' => $station['id']]);
    $trucks = $trucks_query->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_message = "Error loading trucks: " . $e->getMessage();
    $trucks = [];
}

// Fetch all lockers for current station with truck names and item counts
try {
    $lockers_query = $db->prepare('
        SELECT l.*, 
               t.name as truck_name,
               COUNT(i.id) as item_count
        FROM lockers l 
        JOIN trucks t ON l.truck_id = t.id 
        LEFT JOIN items i ON l.id = i.locker_id
        WHERE t.station_id = :station_id 
        GROUP BY l.id 
        ORDER BY t.name, l.name
    ');
    $lockers_query->execute(['station_id' => $station['id']]);
    $lockers = $lockers_query->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_message = "Error loading lockers: " . $e->getMessage();
    $lockers = [];
    if (DEBUG) {
        error_log("maintain_lockers module: Error loading lockers: " . $e->getMessage());
    }
}

if (DEBUG) {
    error_log("maintain_lockers module: Rendering page. Lockers count: " . count($lockers) . ". Edit locker ID: " . ($edit_locker['id'] ?? 'None'));
}
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

    .lockers-list {
        background: white;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .lockers-list h2 {
        margin-top: 0;
        color: #12044C;
    }

    .locker-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px;
        border-bottom: 1px solid #eee;
        background-color: #f8f9fa;
        margin-bottom: 10px;
        border-radius: 5px;
    }

    .locker-item:last-child {
        margin-bottom: 0;
    }

    .locker-info {
        flex-grow: 1;
    }

    .locker-name {
        font-weight: bold;
        color: #12044C;
        font-size: 16px;
    }

    .locker-details {
        color: #666;
        font-size: 14px;
        margin-top: 5px;
    }

    .locker-actions {
        display: flex;
        gap: 10px;
    }

    .locker-actions button {
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
        min-width: 70px;
    }

    .delete-link:hover {
        background-color: #c82333;
    }

    .delete-link.disabled {
        background-color: #6c757d;
        cursor: not-allowed;
        opacity: 0.6;
        min-width: 70px;
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

    .no-trucks-warning {
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

        .locker-item {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }

        .locker-actions {
            width: 100%;
            justify-content: flex-end;
        }
    }
</style>

<div class="maintain-container">
    <div class="page-header">
        <h1 class="page-title">Maintain Lockers</h1>
    </div>

    <div class="station-info">
        <div class="station-name"><?= htmlspecialchars($station['name']) ?></div>
        <?php if (!empty($station['description'])): ?>
            <div style="color: #666; margin-top: 5px;"><?= htmlspecialchars($station['description']) ?></div>
        <?php endif; ?>
    </div>

    <div id="message-container"></div>

    <?php if (empty($trucks)): ?>
        <div class="no-trucks-warning">
            <h3>No Trucks Available</h3>
            <p>You need to add trucks before you can create lockers. <button onclick="loadPage('maintain_trucks.php')" class="button secondary">Go to Maintain Trucks</button> to add your first truck.</p>
        </div>
    <?php else: ?>
        <?php if ($edit_locker): ?>
            <div class="form-section">
                <h2>Edit Locker</h2>
                <form id="edit-locker-form" data-locker-id="<?= $edit_locker['id'] ?>">
                    <div class="input-container">
                        <input type="text" name="locker_name" placeholder="Locker Name" value="<?= htmlspecialchars($edit_locker['name']) ?>" required>
                    </div>
                    <div class="input-container">
                        <select name="truck_id" required>
                            <option value="">Select Truck</option>
                            <?php foreach ($trucks as $truck): ?>
                                <option value="<?= $truck['id'] ?>" <?= $truck['id'] == $edit_locker['truck_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($truck['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="button-container">
                        <button type="submit" class="button">Update Locker</button>
                        <button type="button" onclick="loadPage('maintain_lockers.php')" class="button secondary">Cancel</button>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div class="form-section">
                <h2>Add New Locker</h2>
                <form id="add-locker-form">
                    <div class="input-container">
                        <input type="text" name="locker_name" placeholder="Locker Name" required>
                    </div>
                    <div class="input-container">
                        <select name="truck_id" required>
                            <option value="">Select Truck</option>
                            <?php foreach ($trucks as $truck): ?>
                                <option value="<?= $truck['id'] ?>"><?= htmlspecialchars($truck['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="button-container">
                        <button type="submit" class="button">Add Locker</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="lockers-list">
        <h2>Existing Lockers</h2>
        
        <?php if (empty($lockers)): ?>
            <div class="empty-state">
                <h3>No Lockers Found</h3>
                <p>No lockers have been added to this station yet. <?= empty($trucks) ? 'Add trucks first, then' : 'Add your first locker using the form above.' ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($lockers as $locker): ?>
                <div class="locker-item">
                    <div class="locker-info">
                        <div class="locker-name"><?= htmlspecialchars($locker['name']) ?></div>
                        <div class="locker-details">
                            Truck: <?= htmlspecialchars($locker['truck_name']) ?> | 
                            <?= $locker['item_count'] ?> item(s)
                            <?php if ($locker['item_count'] > 0): ?>
                                | <span style="color: #dc3545;">Cannot delete - has items</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="locker-actions">
                        <button onclick="loadPage('maintain_lockers.php?edit_id=<?= $locker['id'] ?>')" class="edit-link">Edit</button>
                        <?php if ($locker['item_count'] == 0): ?>
                            <button onclick="deleteLocker(<?= $locker['id'] ?>, '<?= htmlspecialchars($locker['name'], ENT_QUOTES) ?>')" class="delete-link">Delete</button>
                        <?php else: ?>
                            <span class="delete-link disabled" 
                                  title="Cannot delete locker with items">Delete</span>
                        <?php endif; ?>
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

function deleteLocker(lockerId, lockerName) {
    if (!confirm(`Are you sure you want to delete locker "${lockerName}"?`)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('ajax_action', 'delete_locker');
    formData.append('locker_id', lockerId);
    
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(data.message);
            // Reload the module
            setTimeout(() => {
                if (window.loadPage) {
                    window.loadPage('maintain_lockers.php');
                }
            }, 1000);
        } else {
            showMessage(data.message, true);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMessage('An error occurred while deleting the locker.', true);
    });
}

// Ensure functions are available globally
if (typeof window !== 'undefined') {
    window.showMessage = showMessage;
    window.deleteLocker = deleteLocker;
}

// Set up form handlers - use immediate execution instead of DOMContentLoaded
// since the module is loaded via AJAX after DOM is ready
(function() {
    // Handle add locker form
    const addForm = document.getElementById('add-locker-form');
    if (addForm) {
        addForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('ajax_action', 'add_locker');
            
            fetch('admin.php', {
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
                            window.loadPage('maintain_lockers.php');
                        }
                    }, 1000);
                } else {
                    showMessage(data.message, true);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('An error occurred while adding the locker.', true);
            });
        });
    }
    
    // Handle edit locker form
    const editForm = document.getElementById('edit-locker-form');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('ajax_action', 'edit_locker');
            formData.append('locker_id', this.dataset.lockerId);
            
            fetch('admin.php', {
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
                            window.loadPage('maintain_lockers.php');
                        }
                    }, 1000);
                } else {
                    showMessage(data.message, true);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('An error occurred while updating the locker.', true);
            });
        });
    }
    
    <?php if (DEBUG): ?>
    console.log('Maintain Lockers module loaded');
    console.log('Station:', <?= json_encode($station['name']) ?>);
    console.log('Lockers count:', <?= count($lockers) ?>);
    console.log('deleteLocker function available:', typeof deleteLocker !== 'undefined');
    <?php endif; ?>
})();
</script>
