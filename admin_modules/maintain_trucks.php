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
    error_log("maintain_trucks module: Started. Station ID: " . $station['id']);
}

// Handle AJAX form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    
    try {
        switch ($_POST['ajax_action']) {
            case 'add_truck':
                $truck_name = trim($_POST['truck_name'] ?? '');
                
                if (empty($truck_name)) {
                    throw new Exception('Truck name cannot be empty');
                }
                
                $query = $db->prepare('INSERT INTO trucks (name, station_id) VALUES (:name, :station_id)');
                $query->execute(['name' => $truck_name, 'station_id' => $station['id']]);
                
                $response['success'] = true;
                $response['message'] = "Truck '{$truck_name}' added successfully.";
                
                if (DEBUG) {
                    error_log("maintain_trucks module: Truck '{$truck_name}' added successfully");
                }
                break;
                
            case 'edit_truck':
                $truck_id = $_POST['truck_id'] ?? '';
                $truck_name = trim($_POST['truck_name'] ?? '');
                
                if (empty($truck_id) || empty($truck_name)) {
                    throw new Exception('Truck ID and name are required');
                }
                
                // Verify truck belongs to current station
                $check_query = $db->prepare('SELECT id FROM trucks WHERE id = :id AND station_id = :station_id');
                $check_query->execute(['id' => $truck_id, 'station_id' => $station['id']]);
                
                if (!$check_query->fetch()) {
                    throw new Exception('Truck not found or access denied');
                }
                
                $query = $db->prepare('UPDATE trucks SET name = :name WHERE id = :id AND station_id = :station_id');
                $query->execute(['name' => $truck_name, 'id' => $truck_id, 'station_id' => $station['id']]);
                
                $response['success'] = true;
                $response['message'] = 'Truck updated successfully.';
                
                if (DEBUG) {
                    error_log("maintain_trucks module: Truck ID {$truck_id} updated successfully");
                }
                break;
                
            case 'delete_truck':
                $truck_id = $_POST['truck_id'] ?? '';
                
                if (empty($truck_id)) {
                    throw new Exception('Truck ID is required');
                }
                
                // Verify truck belongs to current station
                $check_query = $db->prepare('SELECT id FROM trucks WHERE id = :id AND station_id = :station_id');
                $check_query->execute(['id' => $truck_id, 'station_id' => $station['id']]);
                
                if (!$check_query->fetch()) {
                    throw new Exception('Truck not found or access denied');
                }
                
                // Check if truck has any lockers
                $locker_check = $db->prepare('SELECT COUNT(*) FROM lockers WHERE truck_id = :truck_id');
                $locker_check->execute(['truck_id' => $truck_id]);
                $locker_count = $locker_check->fetchColumn();
                
                if ($locker_count > 0) {
                    throw new Exception("Cannot delete truck: This truck has {$locker_count} locker(s) assigned to it. Please delete all lockers first.");
                }
                
                $query = $db->prepare('DELETE FROM trucks WHERE id = :id AND station_id = :station_id');
                $query->execute(['id' => $truck_id, 'station_id' => $station['id']]);
                
                $response['success'] = true;
                $response['message'] = 'Truck deleted successfully.';
                
                if (DEBUG) {
                    error_log("maintain_trucks module: Truck ID {$truck_id} deleted successfully");
                }
                break;
                
            default:
                throw new Exception('Invalid action');
        }
    } catch (Exception $e) {
        $response['success'] = false;
        $response['message'] = $e->getMessage();
        
        if (DEBUG) {
            error_log("maintain_trucks module: Error - " . $e->getMessage());
        }
    }
    
    echo json_encode($response);
    exit;
}

// Get truck to edit if edit_id is set
$edit_truck = null;
if (isset($_GET['edit_id'])) {
    $edit_id = $_GET['edit_id'];
    try {
        $query = $db->prepare('SELECT * FROM trucks WHERE id = :id AND station_id = :station_id');
        $query->execute(['id' => $edit_id, 'station_id' => $station['id']]);
        $edit_truck = $query->fetch(PDO::FETCH_ASSOC);
        
        if (!$edit_truck) {
            $error_message = "Truck not found or access denied.";
        }
    } catch (Exception $e) {
        $error_message = "Error loading truck: " . $e->getMessage();
    }
}

// Fetch all trucks for current station with locker counts
try {
    $trucks_query = $db->prepare('
        SELECT t.*, 
               COUNT(l.id) as locker_count
        FROM trucks t 
        LEFT JOIN lockers l ON t.id = l.truck_id 
        WHERE t.station_id = :station_id 
        GROUP BY t.id 
        ORDER BY t.name
    ');
    $trucks_query->execute(['station_id' => $station['id']]);
    $trucks = $trucks_query->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_message = "Error loading trucks: " . $e->getMessage();
    $trucks = [];
    if (DEBUG) {
        error_log("maintain_trucks module: Error loading trucks: " . $e->getMessage());
    }
}

if (DEBUG) {
    error_log("maintain_trucks module: Rendering page. Trucks count: " . count($trucks) . ". Edit truck ID: " . ($edit_truck['id'] ?? 'None'));
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

    .input-container input {
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

    .button.danger {
        background-color: #dc3545;
    }

    .button.danger:hover {
        background-color: #c82333;
    }

    .button:disabled {
        background-color: #6c757d;
        cursor: not-allowed;
        opacity: 0.6;
    }

    .trucks-list {
        background: white;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .trucks-list h2 {
        margin-top: 0;
        color: #12044C;
    }

    .truck-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px;
        border-bottom: 1px solid #eee;
        background-color: #f8f9fa;
        margin-bottom: 10px;
        border-radius: 5px;
    }

    .truck-item:last-child {
        margin-bottom: 0;
    }

    .truck-info {
        flex-grow: 1;
    }

    .truck-name {
        font-weight: bold;
        color: #12044C;
        font-size: 16px;
    }

    .truck-details {
        color: #666;
        font-size: 14px;
        margin-top: 5px;
    }

    .truck-actions {
        display: flex;
        gap: 10px;
    }

    .truck-actions button {
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

    .delete-link.disabled {
        background-color: #6c757d;
        cursor: not-allowed;
        opacity: 0.6;
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

    /* Mobile responsive */
    @media (max-width: 768px) {
        .maintain-container {
            padding: 10px;
        }

        .truck-item {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }

        .truck-actions {
            width: 100%;
            justify-content: flex-end;
        }
    }
</style>

<div class="maintain-container">
    <div class="page-header">
        <h1 class="page-title">Maintain Trucks</h1>
    </div>

    <div class="station-info">
        <div class="station-name"><?= htmlspecialchars($station['name']) ?></div>
        <?php if (!empty($station['description'])): ?>
            <div style="color: #666; margin-top: 5px;"><?= htmlspecialchars($station['description']) ?></div>
        <?php endif; ?>
    </div>

    <div id="message-container"></div>

    <?php if ($edit_truck): ?>
        <div class="form-section">
            <h2>Edit Truck</h2>
            <form id="edit-truck-form" data-truck-id="<?= $edit_truck['id'] ?>">
                <div class="input-container">
                    <input type="text" name="truck_name" placeholder="Truck Name" value="<?= htmlspecialchars($edit_truck['name']) ?>" required>
                </div>
                <div class="button-container">
                    <button type="submit" class="button">Update Truck</button>
                    <button type="button" onclick="loadPage('maintain_trucks.php')" class="button secondary">Cancel</button>
                </div>
            </form>
        </div>
    <?php else: ?>
        <div class="form-section">
            <h2>Add New Truck</h2>
            <form id="add-truck-form">
                <div class="input-container">
                    <input type="text" name="truck_name" placeholder="Truck Name" required>
                </div>
                <div class="button-container">
                    <button type="submit" class="button">Add Truck</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div class="trucks-list">
        <h2>Existing Trucks</h2>
        
        <?php if (empty($trucks)): ?>
            <div class="empty-state">
                <h3>No Trucks Found</h3>
                <p>No trucks have been added to this station yet. Add your first truck using the form above.</p>
            </div>
        <?php else: ?>
            <?php foreach ($trucks as $truck): ?>
                <div class="truck-item">
                    <div class="truck-info">
                        <div class="truck-name"><?= htmlspecialchars($truck['name']) ?></div>
                        <div class="truck-details">
                            <?= $truck['locker_count'] ?> locker(s)
                            <?php if ($truck['locker_count'] > 0): ?>
                                | <span style="color: #dc3545;">Cannot delete - has lockers</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="truck-actions">
                        <button onclick="loadPage('maintain_trucks.php?edit_id=<?= $truck['id'] ?>')" class="edit-link">Edit</button>
                        <?php if ($truck['locker_count'] == 0): ?>
                            <button onclick="deleteTruck(<?= $truck['id'] ?>, '<?= htmlspecialchars($truck['name'], ENT_QUOTES) ?>')" class="delete-link">Delete</button>
                        <?php else: ?>
                            <span class="delete-link disabled" 
                                  title="Cannot delete truck with lockers">Delete</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
(function() { // Start IIFE
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

function deleteTruck(truckId, truckName) {
    if (!confirm(`Are you sure you want to delete truck "${truckName}"?`)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('ajax_action', 'delete_truck');
    formData.append('truck_id', truckId);
    
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
                    window.loadPage('maintain_trucks.php');
                }
            }, 1000);
        } else {
            showMessage(data.message, true);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMessage('An error occurred while deleting the truck.', true);
    });
}

// Ensure functions are available globally
if (typeof window !== 'undefined') {
    window.showMessage = showMessage;
    window.deleteTruck = deleteTruck;
}

// Set up form handlers - use immediate execution instead of DOMContentLoaded
// since the module is loaded via AJAX after DOM is ready
    // Handle add truck form
    const addForm = document.getElementById('add-truck-form');
    if (addForm) {
        addForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('ajax_action', 'add_truck');
            
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
                            window.loadPage('maintain_trucks.php');
                        }
                    }, 1000);
                } else {
                    showMessage(data.message, true);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('An error occurred while adding the truck.', true);
            });
        });
    }
    
    // Handle edit truck form
    const editForm = document.getElementById('edit-truck-form');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('ajax_action', 'edit_truck');
            formData.append('truck_id', this.dataset.truckId);
            
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
                            window.loadPage('maintain_trucks.php');
                        }
                    }, 1000);
                } else {
                    showMessage(data.message, true);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('An error occurred while updating the truck.', true);
            });
        });
    }
    
    <?php if (DEBUG): ?>
    console.log('Maintain Trucks module loaded');
    console.log('Station:', <?= json_encode($station['name']) ?>);
    console.log('Trucks count:', <?= count($trucks) ?>);
    console.log('deleteTruck function available:', typeof deleteTruck !== 'undefined');
    <?php endif; ?>
})(); // End IIFE
</script>
