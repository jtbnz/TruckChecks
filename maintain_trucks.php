<?php
include 'db.php';
include 'templates/header.php';

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
<form method="POST">
    <input type="text" name="truck_name" placeholder="Truck Name" required>
    <button type="submit" name="add_truck">Add Truck</button>
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

<?php include 'templates/footer.php'; ?>
