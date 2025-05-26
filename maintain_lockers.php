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

// Handle adding a new locker
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_locker'])) {
    $locker_name = $_POST['locker_name'];
    $truck_id = $_POST['truck_id'];
    if (!empty($locker_name) && !empty($truck_id)) {
        $query = $db->prepare('INSERT INTO lockers (name, truck_id) VALUES (:name, :truck_id)');
        $query->execute(['name' => $locker_name, 'truck_id' => $truck_id]);
    }
}

// Handle editing a locker
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_locker'])) {
    $locker_id = $_POST['locker_id'];
    $locker_name = $_POST['locker_name'];
    $truck_id = $_POST['truck_id'];
    if (!empty($locker_name) && !empty($truck_id) && !empty($locker_id)) {
        $query = $db->prepare('UPDATE lockers SET name = :name, truck_id = :truck_id WHERE id = :id');
        $query->execute(['name' => $locker_name, 'truck_id' => $truck_id, 'id' => $locker_id]);
    }
}

// Handle deleting a locker
if (isset($_GET['delete_locker_id'])) {
    $locker_id = $_GET['delete_locker_id'];
    $query = $db->prepare('DELETE FROM lockers WHERE id = :id');
    $query->execute(['id' => $locker_id]);
}

// Get locker to edit if edit_id is set
$edit_locker = null;
if (isset($_GET['edit_id'])) {
    $edit_id = $_GET['edit_id'];
    $query = $db->prepare('SELECT * FROM lockers WHERE id = :id');
    $query->execute(['id' => $edit_id]);
    $edit_locker = $query->fetch(PDO::FETCH_ASSOC);
}

// Fetch all trucks for the dropdown
$trucks = $db->query('SELECT * FROM trucks')->fetchAll(PDO::FETCH_ASSOC);

// Fetch all lockers with truck names
$lockers = $db->query('SELECT l.*, t.name as truck_name FROM lockers l JOIN trucks t ON l.truck_id = t.id')->fetchAll(PDO::FETCH_ASSOC);
?>

<h1>Maintain Lockers</h1>

<?php if ($edit_locker): ?>
<h2>Edit Locker</h2>
<form method="POST" class="edit-locker-form">
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
        <button type="submit" name="edit_locker" class="button touch-button">Update Locker</button>
        <a href="maintain_lockers.php" class="button touch-button" style="background-color: #6c757d;">Cancel</a>
    </div>
</form>
<?php else: ?>
<h2>Add New Locker</h2>
<form method="POST" class="add-locker-form">
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
        <button type="submit" name="add_locker" class="button touch-button">Add Locker</button>
    </div>
</form>
<?php endif; ?>

<h2>Existing Lockers</h2>
<ul>
    <?php foreach ($lockers as $locker): ?>
        <li>
            <?= htmlspecialchars($locker['name']) ?> (Truck: <?= htmlspecialchars($locker['truck_name']) ?>) 
            <a href="?edit_id=<?= $locker['id'] ?>">Edit</a> | 
            <a href="?delete_locker_id=<?= $locker['id'] ?>" onclick="return confirm('Are you sure you want to delete this locker? This will also delete all associated items.');">Delete</a>
        </li>
    <?php endforeach; ?>
</ul>

<div class="button-container" style="margin-top: 20px;">
    <a href="admin.php" class="button touch-button">Admin Page</a>
</div>

<?php include 'templates/footer.php'; ?>
