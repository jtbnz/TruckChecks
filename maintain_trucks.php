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

// Handle adding a new truck
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_truck'])) {
    $truck_name = $_POST['truck_name'];
    if (!empty($truck_name)) {
        $query = $db->prepare('INSERT INTO trucks (name) VALUES (:name)');
        $query->execute(['name' => $truck_name]);
    }
}

// Handle editing a truck
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_truck'])) {
    $truck_id = $_POST['truck_id'];
    $truck_name = $_POST['truck_name'];
    if (!empty($truck_name) && !empty($truck_id)) {
        $query = $db->prepare('UPDATE trucks SET name = :name WHERE id = :id');
        $query->execute(['name' => $truck_name, 'id' => $truck_id]);
    }
}

// Handle deleting a truck
if (isset($_GET['delete_truck_id'])) {
    $truck_id = $_GET['delete_truck_id'];
    $query = $db->prepare('DELETE FROM trucks WHERE id = :id');
    $query->execute(['id' => $truck_id]);
}

// Get truck to edit if edit_id is set
$edit_truck = null;
if (isset($_GET['edit_id'])) {
    $edit_id = $_GET['edit_id'];
    $query = $db->prepare('SELECT * FROM trucks WHERE id = :id');
    $query->execute(['id' => $edit_id]);
    $edit_truck = $query->fetch(PDO::FETCH_ASSOC);
}

// Fetch all trucks
$trucks = $db->query('SELECT * FROM trucks')->fetchAll(PDO::FETCH_ASSOC);
?>

<h1>Maintain Trucks</h1>

<?php if ($edit_truck): ?>
<h2>Edit Truck</h2>
<form method="POST" class="edit-truck-form">
    <input type="hidden" name="truck_id" value="<?= $edit_truck['id'] ?>">
    <div class="input-container">
        <input type="text" name="truck_name" placeholder="Truck Name" value="<?= htmlspecialchars($edit_truck['name']) ?>" required>
    </div>
    <div class="button-container">
        <button type="submit" name="edit_truck" class="button touch-button">Update Truck</button>
        <a href="maintain_trucks.php" class="button touch-button" style="background-color: #6c757d;">Cancel</a>
    </div>
</form>
<?php else: ?>
<h2>Add New Truck</h2>
<form method="POST" class="add-truck-form">
    <div class="input-container">
        <input type="text" name="truck_name" placeholder="Truck Name" required>
    </div>
    <div class="button-container">
        <button type="submit" name="add_truck" class="button touch-button">Add Truck</button>
    </div>
</form>
<?php endif; ?>

<h2>Existing Trucks</h2>
<ul>
    <?php foreach ($trucks as $truck): ?>
        <li>
            <?= htmlspecialchars($truck['name']) ?> 
            <a href="?edit_id=<?= $truck['id'] ?>">Edit</a> | 
            <a href="?delete_truck_id=<?= $truck['id'] ?>" onclick="return confirm('Are you sure you want to delete this truck? This will also delete all associated lockers.');">Delete</a>
        </li>
    <?php endforeach; ?>
</ul>

<div class="button-container" style="margin-top: 20px;">
    <a href="admin.php" class="button touch-button">Admin Page</a>
</div>

<?php include 'templates/footer.php'; ?>
