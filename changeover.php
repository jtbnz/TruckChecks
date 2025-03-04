<?php

    include 'db.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if the version session variable is not set
if (!isset($_SESSION['version'])) {
    // Get the latest Git tag version
    $version = trim(exec('git describe --tags $(git rev-list --tags --max-count=1)'));
    
    // Set the session variable
    $_SESSION['version'] = $version;
} else {
    // Use the already set session variable
    $version = $_SESSION['version'];
}

$db = get_db_connection();

// Check if relief_items table exists, if not create it
$result = $db->query("SHOW TABLES LIKE 'relief_items'");
if ($result->rowCount() == 0) {
    $db->exec("CREATE TABLE relief_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        truck_name VARCHAR(255) NOT NULL,
        locker_name VARCHAR(255) NOT NULL,
        item_name VARCHAR(255) NOT NULL,
        item_id INT NOT NULL,
        relief BOOLEAN DEFAULT FALSE
    )");
}

// Fetch all trucks
$trucks_query = $db->query('SELECT id, name, relief FROM trucks');
$trucks = $trucks_query->fetchAll(PDO::FETCH_ASSOC);

// Check if a truck has been selected
$selected_truck_id = isset($_GET['truck_id']) ? $_GET['truck_id'] : null;
$selected_truck = null;
$truck_relief_state = false;

// Handle relief state toggle
if (isset($_POST['toggle_relief'])) {
    $truck_id = $_POST['truck_id'];
    $new_state = $_POST['relief_state'] == '1' ? 1 : 0;
    
    $update_query = $db->prepare('UPDATE trucks SET relief = ? WHERE id = ?');
    $update_query->execute([$new_state, $truck_id]);
    
    // If turning relief on, insert items into relief_items table if they don't exist
    if ($new_state == 1) {
        // Get truck name
        $truck_query = $db->prepare('SELECT name FROM trucks WHERE id = ?');
        $truck_query->execute([$truck_id]);
        $truck_name = $truck_query->fetchColumn();
        
        // Get all items for this truck
        $items_query = $db->prepare("
            SELECT i.id as item_id, i.name as item_name, l.name as locker_name, t.name as truck_name 
            FROM items i
            JOIN lockers l ON i.locker_id = l.id
            JOIN trucks t ON l.truck_id = t.id
            WHERE t.id = ?
        ");
        $items_query->execute([$truck_id]);
        $items = $items_query->fetchAll(PDO::FETCH_ASSOC);
        
        // Create an array of current item IDs
        $current_item_ids = array_column($items, 'item_id');
        
        // Delete items from relief_items table that no longer exist in the truck
        if (count($current_item_ids) > 0) {
            $placeholders = implode(',', array_fill(0, count($current_item_ids), '?'));
            $delete_query = $db->prepare("DELETE FROM relief_items WHERE truck_name = ? AND item_id NOT IN ($placeholders)");
            $params = array_merge([$truck_name], $current_item_ids);
            $delete_query->execute($params);
        } else {
            // If no items exist for the truck, delete all relief_items entries for this truck
            $delete_query = $db->prepare("DELETE FROM relief_items WHERE truck_name = ?");
            $delete_query->execute([$truck_name]);
        }
        
        // Insert items into relief_items if they don't exist
        $check_query = $db->prepare("SELECT COUNT(*) FROM relief_items WHERE truck_name = ? AND item_id = ?");
        $insert_query = $db->prepare("
            INSERT INTO relief_items (truck_name, locker_name, item_name, item_id, relief) 
            VALUES (?, ?, ?, ?, FALSE)
        ");
        
        foreach ($items as $item) {
            $check_query->execute([$truck_name, $item['item_id']]);
            $exists = $check_query->fetchColumn();
            
            if ($exists == 0) {
                $insert_query->execute([
                    $truck_name,
                    $item['locker_name'],
                    $item['item_name'],
                    $item['item_id'],
                ]);
            }
        }
    }
    
    // Redirect to prevent form resubmission
    header("Location: changeover.php?truck_id=" . $truck_id);
    exit;
}

// Handle AJAX request to update item relief status
if (isset($_POST['action']) && $_POST['action'] == 'update_item_relief') {
    $item_id = $_POST['item_id'];
    $relief_status = $_POST['relief_status'] == 'true' ? 1 : 0;
    $truck_name = $_POST['truck_name'];
    
    $update_query = $db->prepare("UPDATE relief_items SET relief = ? WHERE truck_name = ? AND item_id = ?");
    $update_query->execute([$relief_status, $truck_name, $item_id]);
    
    echo json_encode(['success' => true]);
    exit;
}

// Get selected truck details if one is selected
if ($selected_truck_id) {
    $truck_query = $db->prepare('SELECT name, relief FROM trucks WHERE id = ?');
    $truck_query->execute([$selected_truck_id]);
    $selected_truck = $truck_query->fetch(PDO::FETCH_ASSOC);
    $truck_relief_state = $selected_truck['relief'];
    
    // Get lockers for this truck
    $lockers_query = $db->prepare("
        SELECT l.id, l.name
        FROM lockers l
        WHERE l.truck_id = ?
        ORDER BY l.name
    ");
    $lockers_query->execute([$selected_truck_id]);
    $lockers = $lockers_query->fetchAll(PDO::FETCH_ASSOC);
    
    // Get items for each locker
    $items_by_locker = [];
    $relief_items = [];
    
    if ($truck_relief_state) {
        // Get relief status for each item
        $relief_query = $db->prepare("
            SELECT item_id, relief
            FROM relief_items
            WHERE truck_name = ?
        ");
        $relief_query->execute([$selected_truck['name']]);
        
        while ($row = $relief_query->fetch(PDO::FETCH_ASSOC)) {
            $relief_items[$row['item_id']] = $row['relief'];
        }
    }
    
    foreach ($lockers as $locker) {
        $items_query = $db->prepare("
            SELECT i.id, i.name
            FROM items i
            WHERE i.locker_id = ?
            ORDER BY i.name
        ");
        $items_query->execute([$locker['id']]);
        $items = $items_query->fetchAll(PDO::FETCH_ASSOC);
        
        $items_by_locker[$locker['id']] = [
            'locker_name' => $locker['name'],
            'items' => $items
        ];
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Truck Change Over Relief Mode">
    <link rel="stylesheet" href="styles/styles.css?id=<?php echo $version; ?>">
    <link rel="stylesheet" href="styles/changeover.css?id=<?php echo $version; ?>">
    <title>Relief Truck Management</title>

</head>
<body>
    <div class="container">
        <h1>Relief Truck Management</h1>
        
        <div class="truck-selection">
            <h2>Select a Truck</h2>
            <form method="GET" action="changeover.php">
                <select name="truck_id" id="truck_id" onchange="this.form.submit()">
                    <option value="">-- Select Truck --</option>
                    <?php foreach ($trucks as $truck): ?>
                        <option value="<?= $truck['id'] ?>" <?= $selected_truck_id == $truck['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($truck['name']) ?> <?= $truck['relief'] ? '(Relief)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        
        <?php if ($selected_truck): ?>
            <div class="truck-relief-toggle">
                <h2>Truck Status</h2>
                <p>Current Status: <?= $truck_relief_state ? 'Relief Truck' : 'Normal' ?></p>
                
                <form method="POST" action="changeover.php">
                    <input type="hidden" name="truck_id" value="<?= $selected_truck_id ?>">
                    <input type="hidden" name="relief_state" value="<?= $truck_relief_state ? '0' : '1' ?>">
                    <button type="submit" name="toggle_relief" class="submit-button">
                        Set <?= htmlspecialchars($selected_truck['name']) ?> to <?= $truck_relief_state ? 'Normal' : 'Relief Truck' ?>
                    </button>
                </form>
            </div>
            
            <?php if ($truck_relief_state): ?>
                <div class="relief-instructions">
                    <p>This truck is in Relief mode. Toggle the switches below to indicate which items have been moved to the relief truck.</p>
                    <p>Don't forget non tracked items such as Station keys, Door remotes, tablets etc.</p> 
                </div>
            <?php endif; ?>
            
            <?php foreach ($items_by_locker as $locker_id => $locker_data): ?>
                <div class="locker-section">
                    <div class="locker-header">
                        <h3><?= htmlspecialchars($locker_data['locker_name']) ?></h3>
                    </div>
                    
                    <?php foreach ($locker_data['items'] as $item): ?>
                        <div class="item-row">
                            <div class="item-name"><?= htmlspecialchars($item['name']) ?></div>
                            <div class="relief-status">
                                <?php if ($truck_relief_state): ?>
                                    <?php 
                                    $is_relief = isset($relief_items[$item['id']]) ? $relief_items[$item['id']] : false;
                                    ?>
                                    <label class="switch switch-flat">
                                        <input class="switch-input item-toggle" type="checkbox" 
                                               data-item-id="<?= $item['id'] ?>" 
                                               data-truck-name="<?= htmlspecialchars($selected_truck['name']) ?>" 
                                               <?= $is_relief ? 'checked' : '' ?> />
                                        <span class="switch-label" data-on="Relief" data-off="<?= htmlspecialchars($selected_truck['name']) ?>"></span> 
                                        <span class="switch-handle"></span> 
                                    </label>
                                <?php else: ?>
                                    <span>On <?= htmlspecialchars($selected_truck['name']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <footer>
        <p><a href="index.php" class="button touch-button">Return to Home</a></p>
    </footer>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Get all item toggles
        var toggles = document.querySelectorAll('.item-toggle');
        
        // Add event listener to each toggle
        toggles.forEach(function(toggle) {
            toggle.addEventListener('change', function() {
                var itemId = this.getAttribute('data-item-id');
                var truckName = this.getAttribute('data-truck-name');
                var isChecked = this.checked;
                
                // Send AJAX request to update item relief status
                var xhr = new XMLHttpRequest();
                xhr.open('POST', 'changeover.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        var response = JSON.parse(xhr.responseText);
                        if (!response.success) {
                            alert('Failed to update item status');
                        }
                    } else {
                        alert('Failed to update item status');
                    }
                };
                xhr.send('action=update_item_relief&item_id=' + itemId + '&truck_name=' + encodeURIComponent(truckName) + '&relief_status=' + isChecked);
            });
        });
    });
    </script>
</body>
</html>