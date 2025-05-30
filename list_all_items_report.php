<?php
/* ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL); */

include('config.php');
include_once('auth.php');
include_once('db.php');

// Require authentication and station context
$station = requireStation();
$user = getCurrentUser();

$db = get_db_connection();

// Query to retrieve all items for current station, including truck name and locker name, sorted by truck name and locker name
$report_query = $db->prepare("
    SELECT 
        t.name as truck_name, 
        l.name as locker_name, 
        i.name as item_name
    FROM items i
    JOIN lockers l ON i.locker_id = l.id
    JOIN trucks t ON t.id = l.truck_id
    WHERE t.station_id = :station_id

    ORDER BY t.name, l.name
");

$report_query->execute(['station_id' => $station['id']]);
$report_data = $report_query->fetchAll(PDO::FETCH_ASSOC);

include 'templates/header.php';
?>

<style>
    .station-info {
        text-align: center;
        margin-bottom: 30px;
        padding: 15px;
        background-color: #f8f9fa;
        border-radius: 5px;
    }

    .station-name {
        font-size: 18px;
        font-weight: bold;
        color: #12044C;
    }

    .truck-listing {
        margin: 20px auto;
        max-width: 1000px;
    }

    .truck-listing table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 20px;
    }

    .truck-listing th, .truck-listing td {
        border: 1px solid #ddd;
        padding: 8px;
        text-align: left;
    }

    .truck-listing th {
        background-color: #12044C;
        color: white;
        font-weight: bold;
    }

    .truck-listing tr:nth-child(even) {
        background-color: #f2f2f2;
    }

    .back-button {
        margin: 20px 0;
        text-align: center;
    }

    .back-button a {
        background-color: #12044C;
        color: white;
        padding: 10px 20px;
        text-decoration: none;
        border-radius: 5px;
    }

    .back-button a:hover {
        background-color: #0056b3;
    }
</style>

<div class="station-info">
    <div class="station-name"><?= htmlspecialchars($station['name']) ?></div>
    <?php if ($station['description']): ?>
        <div style="color: #666; margin-top: 5px;"><?= htmlspecialchars($station['description']) ?></div>
    <?php endif; ?>
</div>

<h1>All Items Report</h1>

<div class="truck-listing">
    <?php if (!empty($report_data)): ?>
        <table>
            <tr>
                <th>Truck Name</th>
                <th>Locker Name</th>
                <th>Item Name</th>
            </tr>
            <?php foreach ($report_data as $item): ?>
                <tr>
                    <td><?= htmlspecialchars($item['truck_name']) ?></td>
                    <td><?= htmlspecialchars($item['locker_name']) ?></td>
                    <td><?= htmlspecialchars($item['item_name']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        
        <p><strong>Total Items:</strong> <?= count($report_data) ?></p>
    <?php else: ?>
        <div style="text-align: center; padding: 40px; color: #666;">
            <h3>No Items Found</h3>
            <p>No items found for this station.</p>
        </div>
    <?php endif; ?>
</div>

<div class="back-button">
    <a href="admin.php">← Back to Admin</a>
</div>

<?php include 'templates/footer.php'; ?>
