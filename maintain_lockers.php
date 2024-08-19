<?php
// Include password file
include('password.php');
// Start the session
session_start();

// Check if the user is logged in
if (!isset($_COOKIE['logged_in']) || $_COOKIE['logged_in'] != 'true') {
    header('Location: login.php');
    exit;
}
include 'db.php';
include 'templates/header.php';

$db = get_db_connection();

// Fetch all trucks
$trucks = $db->query('SELECT * FROM trucks')->fetchAll(PDO::FETCH_ASSOC);

// Handle adding or editing lockers
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $locker_name = $_POST['locker_name'];
    $locker_notes = $_POST['locker_notes'];
    $locker_id = $_POST['locker_id'];
    $truck_id = $_POST['truck_id'];

    if (!empty($locker_name)) {
        if ($locker_id) {
            // Update locker
            $query = $db->prepare('UPDATE lockers SET name = :name, notes = :notes WHERE id = :id');
            $query->execute(['name' => $locker_name, 'notes' => $locker_notes, 'id' => $locker_id]);
        } else {
            // Add new locker
            $query = $db->prepare('INSERT INTO lockers (name, truck_id, notes) VALUES (:name, :truck_id, :notes)');
            $query->execute(['name' => $locker_name, 'truck_id' => $truck_id, 'notes' => $locker_notes]);
        }
    }
}

// Handle deleting lockers
if (isset($_GET['delete_locker_id'])) {
    $locker_id = $_GET['delete_locker_id'];
    $query = $db->prepare('DELETE FROM lockers WHERE id = :id');
    $query->execute(['id' => $locker_id]);
}

// Check if a truck has been selected
$selected_truck_id = isset($_GET['truck_id']) ? $_GET['truck_id'] : null;

if ($selected_truck_id) {
    // Fetch lockers for the selected truck
    $query = $db->prepare('SELECT * FROM lockers WHERE truck_id = :truck_id');
    $query->execute(['truck_id' => $selected_truck_id]);
    $lockers = $query->fetchAll(PDO::FETCH_ASSOC);
} else {
    $lockers = [];
}
?>

<h1>Maintain Lockers</h1>

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
    <h2>Add or Edit Locker</h2>
    <form method="POST" class="add-truck-form">
    <div class="input-container">
        <input type="hidden" name="locker_id" value="<?= isset($locker['id']) ? $locker['id'] : '' ?>">
        <input type="hidden" name="truck_id" value="<?= $selected_truck_id ?>">
        
        <label for="locker_name">Locker Name:</label>
        <input type="text" name="locker_name" id="locker_name" required value="<?= isset($locker['name']) ? htmlspecialchars($locker['name']) : '' ?>">
                <label for="locker_notes">Locker Notes:</label>
        <textarea name="locker_notes" id="locker_notes"><?= isset($locker['notes']) ? htmlspecialchars($locker['notes']) : '' ?></textarea>
    </div>
    <div class="button-container">
        <button class="button touch-button" type="submit"><?= isset($locker['id']) ? 'Update Locker' : 'Add Locker' ?></button>
    </div>
    </form>

    <h2>Existing Lockers</h2>
    <ul>
        <?php foreach ($lockers as $locker): ?>
            <li>

                <form method="POST" class="add-truck-form">
                <div class="input-container">
                    <input type="hidden" name="locker_id" value="<?= $locker['id'] ?>">
                    <input type="hidden" name="truck_id" value="<?= $selected_truck_id ?>">
                    <input type="text" name="locker_name" value="<?= htmlspecialchars($locker['name']) ?>" required>
                    <textarea name="locker_notes"><?= htmlspecialchars($locker['notes'] ?? '') ?></textarea>
            </div>
            <div class="button-container">
                    <button class="button touch-button" type="submit">Edit</button>
                    </div>
                </form>

                <a href="?delete_locker_id=<?= $locker['id'] ?>&truck_id=<?= $selected_truck_id ?>" onclick="return confirm('Are you sure you want to delete this locker?');">Delete</a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php else: ?>
    <p>Please select a truck to manage its lockers.</p>
<?php endif; ?>


<div class="button-container" style="margin-top: 20px;">
    <a href="admin.php" class="button touch-button">Admin Page</a>

</div>

<?php include 'templates/footer.php'; ?>
