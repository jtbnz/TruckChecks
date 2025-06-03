<?php
include_once('auth.php');
include_once('config.php');
include_once('db.php');

// Require authentication and station context
$station = requireStation();
$user = getCurrentUser();

$db = get_db_connection();
$error_message = '';
$success_message = '';

// Handle adding a new truck
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_truck'])) {
    $truck_name = trim($_POST['truck_name']);
    if (!empty($truck_name)) {
        try {
            $query = $db->prepare('INSERT INTO trucks (name, station_id) VALUES (:name, :station_id)');
            $query->execute(['name' => $truck_name, 'station_id' => $station['id']]);
            $success_message = "Truck '{$truck_name}' added successfully.";
        } catch (Exception $e) {
            $error_message = "Error adding truck: " . $e->getMessage();
        }
    } else {
        $error_message = "Truck name cannot be empty.";
    }
}

// Handle editing a truck
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_truck'])) {
    $truck_id = $_POST['truck_id'];
    $truck_name = trim($_POST['truck_name']);
    if (!empty($truck_name) && !empty($truck_id)) {
        try {
            // Verify truck belongs to current station
            $check_query = $db->prepare('SELECT id FROM trucks WHERE id = :id AND station_id = :station_id');
            $check_query->execute(['id' => $truck_id, 'station_id' => $station['id']]);
            
            if ($check_query->fetch()) {
                $query = $db->prepare('UPDATE trucks SET name = :name WHERE id = :id AND station_id = :station_id');
                $query->execute(['name' => $truck_name, 'id' => $truck_id, 'station_id' => $station['id']]);
                $success_message = "Truck updated successfully.";
            } else {
                $error_message = "Truck not found or access denied.";
            }
        } catch (Exception $e) {
            $error_message = "Error updating truck: " . $e->getMessage();
        }
    } else {
        $error_message = "Truck name cannot be empty.";
    }
}

// Handle deleting a truck
if (isset($_GET['delete_truck_id'])) {
    $truck_id = $_GET['delete_truck_id'];
    try {
        // First check if truck belongs to current station
        $check_query = $db->prepare('SELECT id FROM trucks WHERE id = :id AND station_id = :station_id');
        $check_query->execute(['id' => $truck_id, 'station_id' => $station['id']]);
        
        if (!$check_query->fetch()) {
            $error_message = "Truck not found or access denied.";
        } else {
            // Check if truck has any lockers
            $locker_check = $db->prepare('SELECT COUNT(*) FROM lockers WHERE truck_id = :truck_id');
            $locker_check->execute(['truck_id' => $truck_id]);
            $locker_count = $locker_check->fetchColumn();
            
            if ($locker_count > 0) {
                $error_message = "Cannot delete truck: This truck has {$locker_count} locker(s) assigned to it. Please delete all lockers first.";
            } else {
                $query = $db->prepare('DELETE FROM trucks WHERE id = :id AND station_id = :station_id');
                $query->execute(['id' => $truck_id, 'station_id' => $station['id']]);
                $success_message = "Truck deleted successfully.";
            }
        }
    } catch (Exception $e) {
        $error_message = "Error deleting truck: " . $e->getMessage();
    }
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

    .truck-actions a {
        padding: 6px 12px;
        text-decoration: none;
        border-radius: 3px;
        font-size: 14px;
        transition: background-color 0.3s;
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

    <?php if ($edit_truck): ?>
        <div class="form-section">
            <h2>Edit Truck</h2>
            <form method="POST">
                <input type="hidden" name="truck_id" value="<?= $edit_truck['id'] ?>">
                <div class="input-container">
                    <input type="text" name="truck_name" placeholder="Truck Name" value="<?= htmlspecialchars($edit_truck['name']) ?>" required>
                </div>
                <div class="button-container">
                    <button type="submit" name="edit_truck" class="button">Update Truck</button>
                    <a href="maintain_trucks.php" class="button secondary">Cancel</a>
                </div>
            </form>
        </div>
    <?php else: ?>
        <div class="form-section">
            <h2>Add New Truck</h2>
            <form method="POST">
                <div class="input-container">
                    <input type="text" name="truck_name" placeholder="Truck Name" required>
                </div>
                <div class="button-container">
                    <button type="submit" name="add_truck" class="button">Add Truck</button>
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
                        <a href="?edit_id=<?= $truck['id'] ?>" class="edit-link">Edit</a>
                        <?php if ($truck['locker_count'] == 0): ?>
                            <a href="?delete_truck_id=<?= $truck['id'] ?>" 
                               class="delete-link"
                               onclick="return confirm('Are you sure you want to delete this truck?');">Delete</a>
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
</div>
