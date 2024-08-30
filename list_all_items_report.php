<?php

// Include the database connection file
require_once 'db.php';
$db = get_db_connection();
// Query to retrieve all items, including truck name and locker name, sorted by truck name and locker name
$query = "SELECT items.*, trucks.name AS truck_name, lockers.name AS locker_name
          FROM items
          INNER JOIN trucks ON items.truck_id = trucks.id
          INNER JOIN lockers ON items.locker_id = lockers.id
          ORDER BY trucks.name, lockers.name";

// Execute the query
$result = mysqli_query($db, $query);

// Check if the query was successful
if ($result) {
    // Fetch all rows from the result set
    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);

    // Output the list of items
    foreach ($rows as $row) {
        echo "Item ID: " . $row['id'] . "\n";
        echo "Item Name: " . $row['name'] . "\n";
        echo "Truck Name: " . $row['truck_name'] . "\n";
        echo "Locker Name: " . $row['locker_name'] . "\n";
        echo "\n";
    }

    // Free the result set
    mysqli_free_result($result);
} else {
    // Handle the error if the query fails
    echo "Error: " . mysqli_error($db);
}

// Close the database connection
mysqli_close($db);

?>