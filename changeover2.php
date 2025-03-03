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
    header("Location: changeover2.php?truck_id=" . $truck_id);
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
    <title>Truck Relief Management</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f0f0f0;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        h1, h2 {
            color: #333;
        }
        .truck-selection {
            margin-bottom: 20px;
        }
        .locker-section {
            background-color: #fff;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .locker-header {
            background-color: #f8f8f8;
            padding: 10px;
            margin: -15px -15px 15px -15px;
            border-radius: 8px 8px 0 0;
            border-bottom: 1px solid #ddd;
        }
        .item-row {
            display: flex;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        .item-name {
            flex-grow: 1;
        }
        .relief-status {
            width: 150px;
        }
        .truck-relief-toggle {
            margin: 20px 0;
            padding: 10px;
            background-color: #e9f5fd;
            border-radius: 8px;
            border: 1px solid #b8daff;
        }
        
        /* Switch styles */
        .switch-flat {
            position: relative;
            display: inline-block;
            width: 100px;
            height: 34px;
            padding: 0;
            background: #FFF;
            background-image: none;
        }
        .switch-flat .switch-input {
            display: none;
        }
        .switch-flat .switch-label {
            position: relative;
            display: block;
            height: inherit;
            font-size: 10px;
            text-transform: uppercase;
            background: #FFF;
            border: solid 2px #eceeef;
            border-radius: 4px;
            box-shadow: none;
        }
        .switch-flat .switch-label:before, .switch-flat .switch-label:after {
            position: absolute;
            top: 50%;
            margin-top: -.5em;
            line-height: 1;
            -webkit-transition: inherit;
            -moz-transition: inherit;
            -o-transition: inherit;
            transition: inherit;
        }
        .switch-flat .switch-label:before {
            content: attr(data-off);
            right: 11px;
            color: #000;
        }
        .switch-flat .switch-label:after {
            content: attr(data-on);
            left: 11px;
            color: #0088cc;
            opacity: 0;
        }
        .switch-flat .switch-handle {
            position: absolute;
            top: 6px;
            left: 6px;
            width: 22px;
            height: 22px;
            background: #dadada;
            border-radius: 4px;
            box-shadow: none;
            -webkit-transition: all 0.3s ease;
            -moz-transition: all 0.3s ease;
            -o-transition: all 0.3s ease;
            transition: all 0.3s ease;
        }
        .switch-flat .switch-handle:before {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            margin: -6px 0 0 -6px;
            width: 12px;
            height: 12px;
            background: #eceeef;
            border-radius: 6px;
        }
        .switch-flat .switch-input:checked ~ .switch-label {
            background: #FFF;
            border-color: #0088cc;
        }
        .switch-flat .switch-input:checked ~ .switch-label:before {
            opacity: 0;
        }
        .switch-flat .switch-input:checked ~ .switch-label:after {
            opacity: 1;
        }
        .switch-flat .switch-input:checked ~ .switch-handle {
            left: 72px;
            background: #0088cc;
            box-shadow: none;
        }
        
        .submit-button {
            background-color: #0088cc;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        .submit-button:hover {
            background-color: #006699;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Truck Relief Management</h1>
        
        <div class="truck-selection">
            <h2>Select a Truck</h2>
            <form method="GET" action="changeover2.php">
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
                
                <form method="POST" action="changeover2.php">
                    <input type="hidden" name="truck_id" value="<?= $selected_truck_id ?>">
                    <input type="hidden" name="relief_state" value="<?= $truck_relief_state ? '0' : '1' ?>">
                    <button type="submit" name="toggle_relief" class="submit-button">
                        Set <?= htmlspecialchars($selected_truck['name']) ?> to <?= $truck_relief_state ? 'Normal' : 'Relief Truck' ?>
                    </button>
                </form>
            </div>
            
            <?php if ($truck_relief_state): ?>
                <div class="relief-instructions">
                    <p>This truck is in Relief mode. Toggle the switches below to indicate which items should go on the relief truck.</p>
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
                                    <span>Regular truck item</span>
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
                xhr.open('POST', 'changeover2.php', true);
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