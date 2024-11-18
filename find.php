<?php
/* ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL); */


include('config.php');
include 'templates/header.php';
include 'db.php'; // Include the database connection file

$db = get_db_connection();

// Handle form submission to filter by date
$searchstr = isset($_POST['searchQuery']) ? $_POST['searchQuery'] : null;

;

if ($searchstr) {
    // Fetch the most recent check for each locker on the selected date
    $report_query = $db->prepare("


        SELECT 
            t.name as truck_name, 
            l.name as locker_name, 
            i.name as item_name

        FROM items i
            JOIN lockers l ON i.locker_id = l.id
            JOIN trucks t ON t.id = l.truck_id
            WHERE i.name LIKE CONCAT(\'%\', :searchstr, \'%\')
            ORDER BY t.name, l.name;
    ");
    

    $report_query->bindParam(':searchstr', $searchstr);

    
    $report_query->execute();
    $report_data = $report_query->fetchAll(PDO::FETCH_ASSOC);



}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Find Items</title>
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



<table>
    <thead>
        <tr>
            <th>Truck Name</th>
            <th>Locker Name</th>
            <th>Item Name</th>

        </tr>
    </thead>
    <tbody>
    <?php if (count($report_data) > 0): ?>
            <?php foreach ($report_data as $item): ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['truck_name']); ?></td>
                    <td><?php echo htmlspecialchars($item['locker_name']); ?></td>
                    <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: if ($searchstr) { ?>
            <tr>
                <td colspan="4">Nothing found.</td>
            </tr>
        <?php } ?>
        <?php endif; ?>
    </tbody>
</table>




<h1>Find an Item</h1>




<form method="POST" action="find.php">
    <input type="text" name="searchQuery" placeholder="Search item descriptions">
    <input type="submit" value="Search">
</form>

<?php include 'templates/footer.php'; ?>
</body>
</html>



