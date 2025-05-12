<?php
/* ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
 */
include('config.php');
include 'db.php';
include 'templates/header.php';

// Check if the user is logged in
if (isset($_COOKIE['logged_in_' . DB_NAME]) && $_COOKIE['logged_in_' . DB_NAME] == 'true') {
    header('Location: login.php');
    exit;
}


$db = get_db_connection();

// Fetch all trucks
$trucks = $db->query('SELECT * FROM trucks')->fetchAll(PDO::FETCH_ASSOC);

// Handle adding a new item to a locker
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_item'])) {
    $item_name = $_POST['item_name'];
    $locker_id = $_POST['locker_id'];
    if (!empty($item_name) && !empty($locker_id)) {
        $query = $db->prepare('INSERT INTO items (name, locker_id) VALUES (:name, :locker_id)');
        $query->execute(['name' => $item_name, 'locker_id' => $locker_id]);
    }
}

// Handle deleting an item from a locker
if (isset($_GET['delete_item_id'])) {
    $item_id = $_GET['delete_item_id'];
    $stmt = $db->prepare("
        SELECT t.name AS truck_name, l.name AS locker_name, i.name AS item_name
        FROM lockers l
        JOIN trucks t ON l.truck_id = t.id
        JOIN items i ON l.id = i.locker_id
        WHERE i.id = :item_id
    ");
    $stmt->bindParam(':item_id', $item_id, PDO::PARAM_INT);
    $stmt->execute();
    $itemDetails = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($itemDetails) {
        // Insert into the log table
        $logStmt = $db->prepare("
            INSERT INTO locker_item_deletion_log (truck_name, locker_name, item_name, deleted_at)
            VALUES (:truck_name, :locker_name, :item_name, NOW())
        ");
        $logStmt->bindParam(':truck_name', $itemDetails['truck_name'], PDO::PARAM_STR);
        $logStmt->bindParam(':locker_name', $itemDetails['locker_name'], PDO::PARAM_STR);
        $logStmt->bindParam(':item_name', $itemDetails['item_name'], PDO::PARAM_STR);
        $logStmt->execute();
    }


    $query = $db->prepare('DELETE FROM items WHERE id = :id');
    $query->execute(['id' => $item_id]);
}

// Handle editing an item in a locker
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_item'])) {
    $item_name = $_POST['item_name'];
    $item_id = $_POST['item_id'];


    if (!empty($item_name) && !empty($item_id)) {
        $query = $db->prepare('UPDATE items SET name = :name WHERE id = :id');
        $query->execute(['name' => $item_name, 'id' => $item_id]);

    }
}

// Handle moving an item to a different locker
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['move_item_submit'])) {
    $item_id = $_POST['item_id'];
    $new_locker_id = $_POST['new_locker_id'];

    if (!empty($item_id) && !empty($new_locker_id)) {
        $query = $db->prepare('UPDATE items SET locker_id = :locker_id WHERE id = :id');
        $query->execute(['locker_id' => $new_locker_id, 'id' => $item_id]);
        
        // Redirect back to the locker view
        $original_locker_id = $_POST['original_locker_id'];
        $original_truck_id = $_POST['original_truck_id'];
        header("Location: maintain_locker_items.php?truck_id=$original_truck_id&locker_id=$original_locker_id");
        exit;
    }
}

// Check if a truck has been selected
$selected_truck_id = isset($_GET['truck_id']) ? $_GET['truck_id'] : null;

if ($selected_truck_id) {
    // Fetch lockers for the selected truck
    $query = $db->prepare('SELECT * FROM lockers WHERE truck_id = :truck_id');
    $query->execute(['truck_id' => $selected_truck_id]);
    $lockers = $query->fetchAll(PDO::FETCH_ASSOC);

    $selected_locker_id = isset($_GET['locker_id']) ? $_GET['locker_id'] : null;

    if ($selected_locker_id) {
        // Fetch items for the selected locker
        $query = $db->prepare('SELECT * FROM items WHERE locker_id = :locker_id');
        $query->execute(['locker_id' => $selected_locker_id]);
        $items = $query->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $items = [];
    }
} else {
    $lockers = [];
    $items = [];
}
?>

<h1>Maintain Locker Items</h1>

<!-- Truck Selection Form -->
<form method="GET">
    <label for="truck_id">Select a Truck:</label>
    <select name="truck_id" id="truck_id" onchange="this.form.submit()">
        <option value="">-- Select Truck --</option>
        <?php foreach ($trucks as $truck): ?>
            <option value="<?= $truck['id'] ?>" <?= $selected_truck_id == $truck['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($truck['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>

<?php if ($selected_truck_id): ?>
    <?php if (!empty($lockers)): ?>
        <!-- Locker Selection Form -->
        <form method="GET">
            <input type="hidden" name="truck_id" value="<?= $selected_truck_id ?>">
            <label for="locker_id">Select a Locker:</label>
            <select name="locker_id" id="locker_id" onchange="this.form.submit()">
                <option value="">-- Select Locker --</option>
                <?php foreach ($lockers as $locker): ?>
                    <option value="<?= $locker['id'] ?>" <?= $selected_locker_id == $locker['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($locker['name'] ?? '') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if ($selected_locker_id): ?>
            <h2>Add Item to <?= htmlspecialchars($lockers[array_search($selected_locker_id, array_column($lockers, 'id'))]['name']) ?></h2>
            <form method="POST"  class="add-locker-form">
            <div class="input-container">                
                <input type="text" name="item_name" placeholder="Item Name" required>
                <input type="hidden" name="locker_id" value="<?= $selected_locker_id ?>">
            </div>
            <div class="button-container">
                <button class="button touch-button" type="submit" name="add_item">Add Item</button>
            </div>                
            </form>


            <h2>Existing Items</h2>
            <ul>
                <?php foreach ($items as $item): ?>
                    <li>
                    <form method="POST" class="add-locker-form">
                    <div class="input-container">
                            <input type="text" name="item_name" value="<?= htmlspecialchars($item['name']) ?>" required>
                            <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                    </div>
                    <div class="button-container">
                        <button class="button touch-button"  name="edit_item" type="submit">Edit</button>
                    
                    </form>
                        <a href="?delete_item_id=<?= $item['id'] ?>&locker_id=<?= $selected_locker_id ?>&truck_id=<?= $selected_truck_id ?>" onclick="return confirm('Are you sure you want to delete this item?');">Delete</a>
                        <a href="?move_item_id=<?= $item['id'] ?>&locker_id=<?= $selected_locker_id ?>&truck_id=<?= $selected_truck_id ?>">Move</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p>Please select a locker to manage its items.</p>
        <?php endif; ?>
    <?php else: ?>
        <p>No lockers found for this truck.</p>
    <?php endif; ?>
<?php else: ?>
    <p>Please select a truck to manage its lockers and items.</p>
<?php endif; ?>

<?php
// Handle the move item functionality
if (isset($_GET['move_item_id'])) {
    $move_item_id = $_GET['move_item_id'];
    $original_locker_id = $_GET['locker_id'];
    $original_truck_id = $_GET['truck_id'];
    
    // Get the item details
    $query = $db->prepare('SELECT * FROM items WHERE id = :id');
    $query->execute(['id' => $move_item_id]);
    $move_item = $query->fetch(PDO::FETCH_ASSOC);
    
    // If we're selecting a new truck for the move
    if (isset($_GET['move_select_truck'])) {
        $move_truck_id = $_GET['move_truck_id'];
        
        // Fetch lockers for the selected truck
        $query = $db->prepare('SELECT * FROM lockers WHERE truck_id = :truck_id');
        $query->execute(['truck_id' => $move_truck_id]);
        $move_lockers = $query->fetchAll(PDO::FETCH_ASSOC);
        
        // Display locker selection form
        echo '<h2>Select New Locker for "' . htmlspecialchars($move_item['name']) . '"</h2>';
        echo '<form method="POST">';
        echo '<input type="hidden" name="item_id" value="' . $move_item_id . '">';
        echo '<input type="hidden" name="original_locker_id" value="' . $original_locker_id . '">';
        echo '<input type="hidden" name="original_truck_id" value="' . $original_truck_id . '">';
        echo '<select name="new_locker_id" required>';
        echo '<option value="">-- Select New Locker --</option>';
        foreach ($move_lockers as $locker) {
            echo '<option value="' . $locker['id'] . '">' . htmlspecialchars($locker['name']) . '</option>';
        }
        echo '</select>';
        echo '<button type="submit" name="move_item_submit" class="button touch-button">Move Item</button>';
        echo '</form>';
        echo '<a href="maintain_locker_items.php?truck_id=' . $original_truck_id . '&locker_id=' . $original_locker_id . '" class="button touch-button">Cancel</a>';
    } else {
        // Display truck selection form
        echo '<h2>Select Truck for Moving "' . htmlspecialchars($move_item['name']) . '"</h2>';
        echo '<form method="GET">';
        echo '<input type="hidden" name="move_item_id" value="' . $move_item_id . '">';
        echo '<input type="hidden" name="locker_id" value="' . $original_locker_id . '">';
        echo '<input type="hidden" name="truck_id" value="' . $original_truck_id . '">';
        echo '<input type="hidden" name="move_select_truck" value="1">';
        echo '<select name="move_truck_id" required>';
        echo '<option value="">-- Select Truck --</option>';
        foreach ($trucks as $truck) {
            echo '<option value="' . $truck['id'] . '">' . htmlspecialchars($truck['name']) . '</option>';
        }
        echo '</select>';
        echo '<button type="submit" class="button touch-button">Next</button>';
        echo '</form>';
        echo '<a href="maintain_locker_items.php?truck_id=' . $original_truck_id . '&locker_id=' . $original_locker_id . '" class="button touch-button">Cancel</a>';
    }
}
?>

<div class="button-container" style="margin-top: 20px;">
    <a href="admin.php" class="button touch-button">Admin Page</a>

</div>
<?php include 'templates/footer.php'; ?>
