<?php


include 'db.php';


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




// Fetch all trucks
$trucks = $db->query('SELECT * FROM trucks')->fetchAll(PDO::FETCH_ASSOC);

// Check if a truck has been selected
$selected_truck_id = isset($_GET['truck_id']) ? $_GET['truck_id'] : null;

if ($selected_truck_id) {
    // Fetch lockers for the selected truck
    $query = $db->prepare('SELECT * FROM lockers WHERE truck_id = :truck_id');
    $query->execute(['truck_id' => $selected_truck_id]);
    $lockers = $query->fetchAll(PDO::FETCH_ASSOC);

    $selected_locker_id = isset($_GET['locker_id']) ? $_GET['locker_id'] : null;

    


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Check lockers for missing items">
    <title>Check Locker Items</title>
    <link rel="stylesheet" href="styles/check_locker_items.css?id=<?php  echo $version;  ?> ">
    <link rel="stylesheet" href="styles/styles.css?id=<?php  echo $version;  ?> ">

</head>
<body class="<?php echo IS_DEMO ? 'demo-mode' : ''; ?>">

<h1>Truck Change Over</h1>

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

<?php

if ($selected_truck_id) {

    $truck_id = $selected_truck_id; 

    $query = $db->prepare("
        SELECT 
            t.name AS truck_name,
            l.name AS locker_name,
            i.name AS item_name
        FROM 
            trucks t
        JOIN 
            lockers l ON t.id = l.truck_id
        JOIN 
            items i ON l.id = i.locker_id
        WHERE 
            t.id = :truck_id
        ORDER BY 
            l.name, i.name
    ");
    $query->execute(['truck_id' => $truck_id]);
    $results = $query->fetchAll(PDO::FETCH_ASSOC);

    $current_locker = '';
    $locker_count = 0;

    echo "<table border='1' cellpadding='5' cellspacing='0' style='width: 100%;'>";
    echo "<tr><th>Locker</th><th>Item</th><th>Relief</th><th>Stays</th><th>Locker</th><th>Item</th><th>Relief</th><th>Stays</th></tr>";

    foreach ($results as $row) {
        if ($current_locker != $row['locker_name']) {
            if ($current_locker != '') {
                echo "</table>";
                $locker_count++;
                if ($locker_count % 2 == 0) {
                    echo "<tr></tr>";
                }
                echo "<table border='1' cellpadding='5' cellspacing='0' style='width: 100%;'>";
            }
            $current_locker = $row['locker_name'];
            echo "<tr><td colspan='4'><strong>Locker: " . htmlspecialchars($current_locker) . "</strong></td></tr>";
        }
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['locker_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['item_name']) . "</td>";
        echo "<td><input type='checkbox'></td>";
        echo "<td><input type='checkbox'></td>";
        echo "</tr>";
    }
    if ($current_locker != '') {
        echo "</table>";
    }
    echo "</table>";

    echo '<p><a href="changeover_pdf.php?truck_id=<?= $truck_id ?>" class="button touch-button">Generate PDF</a></p>';
} else {
    echo "<p>Please select a truck to view its lockers and items.</p>";
}
?>

<footer>
    <? $version = $_SESSION['version']; ?>
    <p><a href="index.php" class="button touch-button">Return to Home</a></p>
    <p id="last-refreshed" style="margin-top: 10px;"></p> 
    <div class="version-number">
        Version: <?php echo htmlspecialchars($version); ?>
    </div>   
</footer>
</body>
</html>
