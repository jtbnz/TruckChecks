<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Check if the user is logged in

if (!isset($_COOKIE['logged_in']) || $_COOKIE['logged_in'] != 'true') {
    header('Location: login.php');
    exit;
}

include('config.php');
include 'templates/header.php';
include 'db.php'; // Include the database connection file

$db = get_db_connection();

// Define the number of log entries per page
$limit = 200;

// Get the current page number from the query string (default is 1)
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

// Calculate the offset for the query
$offset = ($page - 1) * $limit;

// Get the total number of log entries
$totalQuery = $db->query("SELECT COUNT(*) FROM locker_item_deletion_log");
$totalRows = $totalQuery->fetchColumn();

// Calculate the total number of pages
$totalPages = ceil($totalRows / $limit);

// Fetch the log entries for the current page
$stmt = $db->prepare("
    SELECT truck_name, locker_name, item_name, CONVERT_TZ(deleted_at, '+00:00', '+12:00') AS local_time
    FROM locker_item_deletion_log
    ORDER BY deleted_at DESC
    LIMIT :limit OFFSET :offset
");
$stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
$stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
//$stmt->bindParam(':tz_offset', TZ_OFFSET, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Deleted Items Report</title>
    <style>
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background-color: #f2f2f2;
        }
        .pagination {
            margin: 20px 0;
            text-align: center;
        }
        .pagination a {
            margin: 0 5px;
            padding: 8px 16px;
            text-decoration: none;
            color: #007bff;
            border: 1px solid #ddd;
        }
        .pagination a.active {
            background-color: #007bff;
            color: white;
            border: 1px solid #007bff;
        }
        .pagination a:hover {
            background-color: #ddd;
        }
    </style>
</head>
<body>

<h1>Deleted Items Report</h1>

<table>
    <thead>
        <tr>
            <th>Truck Name</th>
            <th>Locker Name</th>
            <th>Item Name</th>
            <th>Date Deleted</th>
        </tr>
    </thead>
    <tbody>
        <?php if (count($logs) > 0): ?>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?php echo htmlspecialchars($log['truck_name']); ?></td>
                    <td><?php echo htmlspecialchars($log['locker_name']); ?></td>
                    <td><?php echo htmlspecialchars($log['item_name']); ?></td>
                    <td><?php echo htmlspecialchars($log['deleted_at']); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="4">No log entries found.</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<div class="pagination">
    <?php if ($page > 1): ?>
        <a href="?page=<?php echo $page - 1; ?>">&laquo; Previous</a>
    <?php endif; ?>

    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <a href="?page=<?php echo $i; ?>" class="<?php if ($i == $page) echo 'active'; ?>"><?php echo $i; ?></a>
    <?php endfor; ?>

    <?php if ($page < $totalPages): ?>
        <a href="?page=<?php echo $page + 1; ?>">Next &raquo;</a>
    <?php endif; ?>
</div>
<div class="button-container" style="margin-top: 20px;">
    <a href="admin.php" class="button touch-button">Admin Page</a>

</div>

<?php include 'templates/footer.php'; ?>