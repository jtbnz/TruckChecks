<?php
// Include password file
include('config.php');
include 'db.php';
include 'templates/header.php';

// Check if the user is logged in
if (!isset($_COOKIE['logged_in']) || $_COOKIE['logged_in'] != 'true') {
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

// Handle deleting a truck
if (isset($_GET['delete_truck_id'])) {
    $truck_id = $_GET['delete_truck_id'];
    $query = $db->prepare('DELETE FROM trucks WHERE id = :id');
    $query->execute(['id' => $truck_id]);
}

// Fetch all trucks
$trucks = $db->query('SELECT * FROM trucks')->fetchAll(PDO::FETCH_ASSOC);
?>

<h1>Maintain Trucks</h1>

<h2>Add New Truck</h2>
<form method="POST" class="add-truck-form">
    <div class="input-container">
        <input type="text" name="truck_name" placeholder="Truck Name" required>
    </div>
    <div class="button-container">
        <button type="submit" name="add_truck" class="button touch-button">Add Truck</button>
    </div>
</form>

<h2>Existing Trucks</h2>
<ul>
    <?php foreach ($trucks as $truck): ?>
        <li>
            <?= htmlspecialchars($truck['name']) ?> 
            <a href="?delete_truck_id=<?= $truck['id'] ?>" onclick="return confirm('Are you sure you want to delete this truck? This will also delete all associated lockers.');">Delete</a>
        </li>
    <?php endforeach; ?>
</ul>

<div class="button-container" style="margin-top: 20px;">
    <a href="admin.php" class="button touch-button">Admin Page</a>

</div>

<?php include 'templates/footer.php'; ?>
