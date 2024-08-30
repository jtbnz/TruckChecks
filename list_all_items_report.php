<?php
/* ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL); */

include('config.php');
include 'templates/header.php';
// Include the database connection file
require_once 'db.php';
$db = get_db_connection();
// Query to retrieve all items, including truck name and locker name, sorted by truck name and locker name

$report_query = $db->prepare("


        SELECT 
            t.name as truck_name, 
            l.name as locker_name, 
            i.name as item_name

        FROM items i
            JOIN lockers l ON i.locker_id = l.id
            JOIN trucks t ON t.id = l.truck_id
            ORDER BY t.name, l.name;
    ");
    

$report_query->execute();
$report_data = $report_query->fetchAll(PDO::FETCH_ASSOC);

echo "<table><tr><th>Truck Name</th><th>Locker Name</th><th>Item Name</th></tr>";
 foreach ($report_data as $item): 

        echo "<tr><td>" . htmlspecialchars($item['truck_name']) . "</td>"; 
        echo "<TD>" . htmlspecialchars($item['locker_name']) . "</td>";
        echo "<TD>" . htmlspecialchars($item['item_name']) . "</td></tr>"; 

 endforeach; 
echo "</table>";
include 'templates/footer.php';
?>