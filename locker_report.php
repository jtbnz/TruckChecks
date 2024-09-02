<?php
// locker_check_report_with_date_picker.php

// Set default dates to the last 7 days
$default_from_date = date('Y-m-d', strtotime('-7 days'));
$default_to_date = date('Y-m-d');

$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : $default_from_date;
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : $default_to_date;
include 'db.php'; 

$db = get_db_connection();

// Check if session has not already been started
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

// Prepare and execute the query
$query = "
    WITH LatestChecks AS (
        SELECT 
            locker_id, 
            MAX(id) AS latest_check_id
        FROM checks
        WHERE DATE(check_date) BETWEEN :from_date AND :to_date
        GROUP BY locker_id
    )
    SELECT 
        t.name as truck_name, 
        l.name as locker_name, 
        i.name as item_name, 
        ci.is_present as checked, 
        c.check_date,
        c.checked_by,
        c.id as check_id
    FROM checks c
    JOIN LatestChecks lc ON c.id = lc.latest_check_id
    JOIN check_items ci ON c.id = ci.check_id
    JOIN lockers l ON c.locker_id = l.id
    JOIN trucks t ON l.truck_id = t.id
    JOIN items i ON ci.item_id = i.id
    ORDER BY t.name, l.name;
";

$stmt = $db->prepare($query);
$stmt->execute(['from_date' => $from_date, 'to_date' => $to_date]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Locker Check Report</title>
</head>
<body>
    <h1>Locker Check Report</h1>
    <form method="GET" action="">
        <label for="from_date">From Date:</label>
        <input type="date" id="from_date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>">
        <label for="to_date">To Date:</label>
        <input type="date" id="to_date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>">
        <button type="submit">Filter</button>
    </form>

    <table border="1">
        <thead>
            <tr>
                <th>Truck Name</th>
                <th>Locker Name</th>
                <th>Item Name</th>
                <th>Checked</th>
                <th>Check Date</th>
                <th>Checked By</th>
                <th>Check ID</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($results as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['truck_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['locker_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['item_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['checked']); ?></td>
                    <td><?php echo htmlspecialchars($row['check_date']); ?></td>
                    <td><?php echo htmlspecialchars($row['checked_by']); ?></td>
                    <td><?php echo htmlspecialchars($row['check_id']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>