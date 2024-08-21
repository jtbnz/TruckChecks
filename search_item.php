<?php
// Include the database connection
include 'db.php';

$db = get_db_connection();

if (isset($_POST['item'])) {
    $item_name = trim($_POST['item']);

    // Prepare a SQL query to search for the item in the database
    $sql = "SELECT i.name as item_name, t.name as truck_name, l.name as locker_name
            FROM items i
            JOIN lockers l ON i.locker_id = l.id
            JOIN trucks t ON l.truck_id = t.id
            WHERE i.name LIKE :item_name";
    $stmt = $db->prepare($sql);
    $stmt->execute(['item_name' => "%$item_name%"]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($results) {
        echo "<h3>Results for '$item_name':</h3>";
        foreach ($results as $result) {
            echo "<p>Item: <strong>" . htmlspecialchars($result['item_name']) . "</strong> 
                  is in Truck: <strong>" . htmlspecialchars($result['truck_name']) . "</strong>, 
                  Locker: <strong>" . htmlspecialchars($result['locker_name']) . "</strong></p>";
        }
    } else {
        echo "<p>No results found for '$item_name'.</p>";
    }
} else {
    echo "<p>Error: No item provided.</p>";
}
?>
