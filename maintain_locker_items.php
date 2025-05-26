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

// Fetch all lockers for the dropdown
$lockers = $db->query('SELECT l.*, t.name as truck_name FROM lockers l JOIN trucks t ON l.truck_id = t.id')->fetchAll(PDO::FETCH_ASSOC);

// Fetch all items with locker and truck names
$items = $db->query('SELECT i.*, l.name as locker_name, t.name as truck_name FROM items i JOIN lockers l ON i.locker_id = l.id JOIN trucks t ON l.truck_id = t.id ORDER BY t.name, l.name, i.name')->fetchAll(PDO::FETCH_ASSOC);
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
        <a href="maintain_locker_items.php" class="button touch-button" style="background-color: #6c757d;">Cancel</a>
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

<h2>Existing Items</h2>
<ul>
    <?php foreach ($items as $item): ?>
        <li>
            <?= htmlspecialchars($item['name']) ?> (<?= htmlspecialchars($item['truck_name']) ?> - <?= htmlspecialchars($item['locker_name']) ?>) 
            <a href="?edit_id=<?= $item['id'] ?>">Edit</a> | 
            <a href="?delete_item_id=<?= $item['id'] ?>" onclick="return confirm('Are you sure you want to delete this item?');">Delete</a>
        </li>
    <?php endforeach; ?>
</ul>

<div class="button-container" style="margin-top: 20px;">
    <a href="admin.php" class="button touch-button">Admin Page</a>
</div>

<?php include 'templates/footer.php'; ?>
