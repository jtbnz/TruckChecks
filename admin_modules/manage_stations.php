<?php
// This is a module file - it should only be included from admin.php
// No headers, footers, or standalone functionality

// Ensure we have required context from admin.php
if (!isset($pdo) || !isset($user)) {
    die('This module must be loaded through admin.php');
}

// Only superusers can manage stations
if ($user['role'] !== 'superuser') {
    echo '<div class="alert alert-error">Access denied. Only superusers can manage stations.</div>';
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
    error_log("manage_stations module: Started by user " . $user['username']);
}

// Handle AJAX form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    
    try {
        switch ($_POST['ajax_action']) {
            case 'add_station':
                $station_name = trim($_POST['station_name'] ?? '');
                $station_description = trim($_POST['station_description'] ?? '');
                
                if (empty($station_name)) {
                    throw new Exception('Station name is required');
                }
                
                // Check if station name already exists
                $check_query = $db->prepare('SELECT id FROM stations WHERE name = :name');
                $check_query->execute(['name' => $station_name]);
                
                if ($check_query->fetch()) {
                    throw new Exception('A station with this name already exists');
                }
                
                $query = $db->prepare('INSERT INTO stations (name, description) VALUES (:name, :description)');
                $query->execute(['name' => $station_name, 'description' => $station_description]);
                
                $response['success'] = true;
                $response['message'] = "Station '{$station_name}' added successfully.";
                
                if (DEBUG) {
                    error_log("manage_stations module: Station '{$station_name}' added successfully");
                }
                break;
                
            case 'edit_station':
                $station_id = $_POST['station_id'] ?? '';
                $station_name = trim($_POST['station_name'] ?? '');
                $station_description = trim($_POST['station_description'] ?? '');
                
                if (empty($station_id) || empty($station_name)) {
                    throw new Exception('Station ID and name are required');
                }
                
                // Check if new name conflicts with another station
                $check_query = $db->prepare('SELECT id FROM stations WHERE name = :name AND id != :id');
                $check_query->execute(['name' => $station_name, 'id' => $station_id]);
                
                if ($check_query->fetch()) {
                    throw new Exception('Another station with this name already exists');
                }
                
                $query = $db->prepare('UPDATE stations SET name = :name, description = :description WHERE id = :id');
                $query->execute(['name' => $station_name, 'description' => $station_description, 'id' => $station_id]);
                
                $response['success'] = true;
                $response['message'] = 'Station updated successfully.';
                
                if (DEBUG) {
                    error_log("manage_stations module: Station ID {$station_id} updated successfully");
                }
                break;
                
            case 'delete_station':
                $station_id = $_POST['station_id'] ?? '';
                
                if (empty($station_id)) {
                    throw new Exception('Station ID is required');
                }
                
                // Check if station has any trucks
                $truck_check = $db->prepare('SELECT COUNT(*) FROM trucks WHERE station_id = :station_id');
                $truck_check->execute(['station_id' => $station_id]);
                $truck_count = $truck_check->fetchColumn();
                
                if ($truck_count > 0) {
                    throw new Exception("Cannot delete station: This station has {$truck_count} truck(s) assigned to it.");
                }
                
                // Check if station has any users assigned
                $user_check = $db->prepare('SELECT COUNT(*) FROM user_stations WHERE station_id = :station_id');
                $user_check->execute(['station_id' => $station_id]);
                $user_count = $user_check->fetchColumn();
                
                if ($user_count > 0) {
                    throw new Exception("Cannot delete station: This station has {$user_count} user(s) assigned to it.");
                }
                
                $query = $db->prepare('DELETE FROM stations WHERE id = :id');
                $query->execute(['id' => $station_id]);
                
                $response['success'] = true;
                $response['message'] = 'Station deleted successfully.';
                
                if (DEBUG) {
                    error_log("manage_stations module: Station ID {$station_id} deleted successfully");
                }
                break;
                
            default:
                throw new Exception('Invalid action');
        }
    } catch (Exception $e) {
        $response['success'] = false;
        $response['message'] = $e->getMessage();
        
        if (DEBUG) {
            error_log("manage_stations module: Error - " . $e->getMessage());
        }
    }
    
    echo json_encode($response);
    exit;
}

// Get station to edit if edit_id is set
$edit_station = null;
if (isset($_GET['edit_id'])) {
    $edit_id = $_GET['edit_id'];
    try {
        $query = $db->prepare('SELECT * FROM stations WHERE id = :id');
        $query->execute(['id' => $edit_id]);
        $edit_station = $query->fetch(PDO::FETCH_ASSOC);
        
        if (!$edit_station) {
            $error_message = "Station not found.";
        }
    } catch (Exception $e) {
        $error_message = "Error loading station: " . $e->getMessage();
    }
}

// Fetch all stations with counts
try {
    $stations_query = $db->prepare('
        SELECT s.*, 
               COUNT(DISTINCT t.id) as truck_count,
               COUNT(DISTINCT us.user_id) as user_count
        FROM stations s 
        LEFT JOIN trucks t ON s.id = t.station_id
        LEFT JOIN user_stations us ON s.id = us.station_id
        GROUP BY s.id 
        ORDER BY s.name
    ');
    $stations_query->execute();
    $stations = $stations_query->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_message = "Error loading stations: " . $e->getMessage();
    $stations = [];
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

    .input-container input, .input-container textarea {
        width: 100%;
        padding: 10px;
        border: 1px solid #ccc;
        border-radius: 5px;
        font-size: 16px;
        box-sizing: border-box;
    }

    .input-container textarea {
        resize: vertical;
        min-height: 80px;
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

    .stations-list {
        background: white;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .stations-list h2 {
        margin-top: 0;
        color: #12044C;
    }

    .station-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px;
        border-bottom: 1px solid #eee;
        background-color: #f8f9fa;
        margin-bottom: 10px;
        border-radius: 5px;
    }

    .station-item:last-child {
        margin-bottom: 0;
    }

    .station-info {
        flex-grow: 1;
    }

    .station-name {
        font-weight: bold;
        color: #12044C;
        font-size: 18px;
    }

    .station-description {
        color: #666;
        font-size: 14px;
        margin-top: 5px;
    }

    .station-details {
        color: #666;
        font-size: 14px;
        margin-top: 8px;
    }

    .station-actions {
        display: flex;
        gap: 10px;
    }

    .station-actions button {
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

    .stat-badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: bold;
        margin-right: 10px;
    }

    .stat-badge.trucks {
        background-color: #e3f2fd;
        color: #1976d2;
    }

    .stat-badge.users {
        background-color: #f3e5f5;
        color: #7b1fa2;
    }

    /* Mobile responsive */
    @media (max-width: 768px) {
        .maintain-container {
            padding: 10px;
        }

        .station-item {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }

        .station-actions {
            width: 100%;
            justify-content: flex-end;
        }
    }
</style>

<div class="maintain-container">
    <div class="page-header">
        <h1 class="page-title">Manage Stations</h1>
    </div>

    <div id="message-container"></div>

    <?php if ($edit_station): ?>
        <div class="form-section">
            <h2>Edit Station</h2>
            <form id="edit-station-form" data-station-id="<?= $edit_station['id'] ?>">
                <div class="input-container">
                    <input type="text" name="station_name" placeholder="Station Name" value="<?= htmlspecialchars($edit_station['name']) ?>" required>
                </div>
                <div class="input-container">
                    <textarea name="station_description" placeholder="Station Description (optional)"><?= htmlspecialchars($edit_station['description'] ?? '') ?></textarea>
                </div>
                <div class="button-container">
                    <button type="submit" class="button">Update Station</button>
                    <button type="button" onclick="loadPage('manage_stations.php')" class="button secondary">Cancel</button>
                </div>
            </form>
        </div>
    <?php else: ?>
        <div class="form-section">
            <h2>Add New Station</h2>
            <form id="add-station-form">
                <div class="input-container">
                    <input type="text" name="station_name" placeholder="Station Name" required>
                </div>
                <div class="input-container">
                    <textarea name="station_description" placeholder="Station Description (optional)"></textarea>
                </div>
                <div class="button-container">
                    <button type="submit" class="button">Add Station</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div class="stations-list">
        <h2>Existing Stations</h2>
        
        <?php if (empty($stations)): ?>
            <div class="empty-state">
                <h3>No Stations Found</h3>
                <p>No stations have been added yet. Add your first station using the form above.</p>
            </div>
        <?php else: ?>
            <?php foreach ($stations as $station): ?>
                <div class="station-item">
                    <div class="station-info">
                        <div class="station-name"><?= htmlspecialchars($station['name']) ?></div>
                        <?php if (!empty($station['description'])): ?>
                            <div class="station-description"><?= htmlspecialchars($station['description']) ?></div>
                        <?php endif; ?>
                        <div class="station-details">
                            <span class="stat-badge trucks"><?= $station['truck_count'] ?> truck(s)</span>
                            <span class="stat-badge users"><?= $station['user_count'] ?> user(s)</span>
                            <?php if ($station['truck_count'] > 0 || $station['user_count'] > 0): ?>
                                <span style="color: #dc3545; font-weight: bold;">Cannot delete - has associated data</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="station-actions">
                        <button onclick="loadPage('manage_stations.php?edit_id=<?= $station['id'] ?>')" class="edit-link">Edit</button>
                        <?php if ($station['truck_count'] == 0 && $station['user_count'] == 0): ?>
                            <button onclick="deleteStation(<?= $station['id'] ?>, '<?= htmlspecialchars($station['name'], ENT_QUOTES) ?>')" class="delete-link">Delete</button>
                        <?php else: ?>
                            <span class="delete-link disabled" title="Cannot delete station with trucks or users">Delete</span>
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

function deleteStation(stationId, stationName) {
    if (!confirm(`Are you sure you want to delete station "${stationName}"?`)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('ajax_action', 'delete_station');
    formData.append('station_id', stationId);
    
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
                    window.loadPage('manage_stations.php');
                }
            }, 1000);
        } else {
            showMessage(data.message, true);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMessage('An error occurred while deleting the station.', true);
    });
}

// Ensure functions are available globally
if (typeof window !== 'undefined') {
    window.showMessage = showMessage;
    window.deleteStation = deleteStation;
}

// Set up form handlers - use immediate execution instead of DOMContentLoaded
// since the module is loaded via AJAX after DOM is ready
(function() {
    // Handle add station form
    const addForm = document.getElementById('add-station-form');
    if (addForm) {
        addForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('ajax_action', 'add_station');
            
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
                            window.loadPage('manage_stations.php');
                        }
                    }, 1000);
                } else {
                    showMessage(data.message, true);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('An error occurred while adding the station.', true);
            });
        });
    }
    
    // Handle edit station form
    const editForm = document.getElementById('edit-station-form');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('ajax_action', 'edit_station');
            formData.append('station_id', this.dataset.stationId);
            
            fetch('admin_modules/manage_stations.php', {
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
                            window.loadPage('manage_stations.php');
                        }
                    }, 1000);
                } else {
                    showMessage(data.message, true);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('An error occurred while updating the station.', true);
            });
        });
    }
    
    <?php if (DEBUG): ?>
    console.log('Manage Stations module loaded');
    console.log('Stations count:', <?= count($stations) ?>);
    console.log('deleteStation function available:', typeof deleteStation !== 'undefined');
    <?php endif; ?>
})();
</script>
