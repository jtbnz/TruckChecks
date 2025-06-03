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

// Handle adding a new locker
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_locker'])) {
    $locker_name = trim($_POST['locker_name']);
    $truck_id = $_POST['truck_id'];
    if (!empty($locker_name) && !empty($truck_id)) {
        try {
            // Verify truck belongs to current station
            $truck_check = $db->prepare('SELECT id FROM trucks WHERE id = :id AND station_id = :station_id');
            $truck_check->execute(['id' => $truck_id, 'station_id' => $station['id']]);
            
            if ($truck_check->fetch()) {
                $query = $db->prepare('INSERT INTO lockers (name, truck_id) VALUES (:name, :truck_id)');
                $query->execute(['name' => $locker_name, 'truck_id' => $truck_id]);
                $success_message = "Locker '{$locker_name}' added successfully.";
            } else {
                $error_message = "Selected truck not found or access denied.";
            }
        } catch (Exception $e) {
            $error_message = "Error adding locker: " . $e->getMessage();
        }
    } else {
        $error_message = "Locker name and truck selection are required.";
    }
}

// Handle editing a locker
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_locker'])) {
    $locker_id = $_POST['locker_id'];
    $locker_name = trim($_POST['locker_name']);
    $truck_id = $_POST['truck_id'];
    if (!empty($locker_name) && !empty($truck_id) && !empty($locker_id)) {
        try {
            // Verify both locker and truck belong to current station
            $check_query = $db->prepare('
                SELECT l.id 
                FROM lockers l 
                JOIN trucks t ON l.truck_id = t.id 
                WHERE l.id = :locker_id AND t.station_id = :station_id
            ');
            $check_query->execute(['locker_id' => $locker_id, 'station_id' => $station['id']]);
            
            if (!$check_query->fetch()) {
                $error_message = "Locker not found or access denied.";
            } else {
                // Verify new truck belongs to current station
                $truck_check = $db->prepare('SELECT id FROM trucks WHERE id = :id AND station_id = :station_id');
                $truck_check->execute(['id' => $truck_id, 'station_id' => $station['id']]);
                
                if ($truck_check->fetch()) {
                    $query = $db->prepare('UPDATE lockers SET name = :name, truck_id = :truck_id WHERE id = :id');
                    $query->execute(['name' => $locker_name, 'truck_id' => $truck_id, 'id' => $locker_id]);
                    $success_message = "Locker updated successfully.";
                } else {
                    $error_message = "Selected truck not found or access denied.";
                }
            }
        } catch (Exception $e) {
            $error_message = "Error updating locker: " . $e->getMessage();
        }
    } else {
        $error_message = "Locker name and truck selection are required.";
    }
}

// Handle deleting a locker
if (isset($_GET['delete_locker_id'])) {
    $locker_id = $_GET['delete_locker_id'];
    try {
        // First check if locker belongs to current station
        $check_query = $db->prepare('
            SELECT l.id 
            FROM lockers l 
            JOIN trucks t ON l.truck_id = t.id 
            WHERE l.id = :locker_id AND t.station_id = :station_id
        ');
        $check_query->execute(['locker_id' => $locker_id, 'station_id' => $station['id']]);
        
        if (!$check_query->fetch()) {
            $error_message = "Locker not found or access denied.";
        } else {
            // Check if locker has any items
            $item_check = $db->prepare('SELECT COUNT(*) FROM items WHERE locker_id = :locker_id');
            $item_check->execute(['locker_id' => $locker_id]);
            $item_count = $item_check->fetchColumn();
            
            if ($item_count > 0) {
                $error_message = "Cannot delete locker: This locker has {$item_count} item(s) assigned to it. Please delete all items first.";
            } else {
                $query = $db->prepare('DELETE FROM lockers WHERE id = :id');
                $query->execute(['id' => $locker_id]);
                $success_message = "Locker deleted successfully.";
            }
        }
    } catch (Exception $e) {
        $error_message = "Error deleting locker: " . $e->getMessage();
    }
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

    .locker-actions a,
    .locker-actions span {
        padding: 8px 16px;
        text-decoration: none;
        border-radius: 4px;
        font-size: 14px;
        font-weight: 500;
        text-align: center;
        min-width: 60px;
        display: inline-block;
        transition: background-color 0.3s;
        box-sizing: border-box;
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
            <p>You need to add trucks before you can create lockers. <a href="maintain_trucks.php">Go to Maintain Trucks</a> to add your first truck.</p>
        </div>
    <?php else: ?>
        <?php if ($edit_locker): ?>
            <div class="form-section">
                <h2>Edit Locker</h2>
                <form method="POST">
                    <input type="hidden" name="locker_id" value="<?= $edit_locker['id'] ?>">
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
                        <button type="submit" name="edit_locker" class="button">Update Locker</button>
                        <a href="maintain_lockers.php" class="button secondary">Cancel</a>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div class="form-section">
                <h2>Add New Locker</h2>
                <form method="POST">
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
                        <button type="submit" name="add_locker" class="button">Add Locker</button>
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
                        <a href="?edit_id=<?= $locker['id'] ?>" class="edit-link">Edit</a>
                        <?php if ($locker['item_count'] == 0): ?>
                            <a href="?delete_locker_id=<?= $locker['id'] ?>" 
                               class="delete-link"
                               onclick="return confirm('Are you sure you want to delete this locker?');">Delete</a>
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
</div>
