<?php 

/* ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);  */

include('config.php');




include 'db.php';
include 'templates/header.php';



$pdo = get_db_connection();
//is_demo = isset($_SESSION['is_demo']) && $_SESSION['is_demo'] === true;
$current_directory = dirname($_SERVER['REQUEST_URI']);
$current_url = 'https://' . $_SERVER['HTTP_HOST'] . $current_directory .  '/index.php';

// Fetch the latest check date
$latestCheckQuery = "SELECT DISTINCT DATE(CONVERT_TZ(check_date, '+00:00', '+12:00')) as the_date FROM checks ORDER BY check_date DESC limit 1";
$latestCheckStmt = $pdo->prepare($latestCheckQuery);
$latestCheckStmt->execute();
$latestCheckDate = $latestCheckStmt->fetch(PDO::FETCH_ASSOC)['the_date'];

// Fetch the latest check data
$checksQuery = "WITH LatestChecks AS (
                    SELECT 
                        locker_id, 
                        MAX(id) AS latest_check_id
                    FROM checks
                    WHERE check_date BETWEEN DATE_SUB(NOW(), INTERVAL 6 DAY) AND NOW()
                    GROUP BY locker_id

                )
                SELECT 
                    t.name as truck_name, 
                    l.name as locker_name, 
                    i.name as item_name, 
                    ci.is_present as checked, 
                    CONVERT_TZ(check_date, '+00:00', '+12:00') AS check_date,
                    cn.note as notes,
                    c.checked_by,
                    c.id as check_id
                FROM checks c
                JOIN LatestChecks lc ON c.id = lc.latest_check_id
                JOIN check_items ci ON c.id = ci.check_id
                JOIN lockers l ON c.locker_id = l.id
                JOIN trucks t ON l.truck_id = t.id
                JOIN items i ON ci.item_id = i.id
                JOIN check_notes cn on ci.check_id = cn.check_id
                WHERE ci.is_present = 0
                ORDER BY t.name, l.name;";
                
$checksStmt = $pdo->prepare($checksQuery);
$checksStmt->execute();
$checks = $checksStmt->fetchAll(PDO::FETCH_ASSOC);

// Query for deleted items in the last 7 days
$deletedItemsQuery = $pdo->prepare("
    SELECT truck_name, locker_name, item_name, CONVERT_TZ(deleted_at, '+00:00', '+12:00') AS deleted_at
    FROM locker_item_deletion_log
    WHERE deleted_at >= NOW() - INTERVAL 7 DAY
    ORDER BY deleted_at DESC
");
$deletedItemsQuery->execute();
$deletedItems = $deletedItemsQuery->fetchAll(PDO::FETCH_ASSOC);


// Fetch email addresses
$emailQuery = "SELECT email FROM email_addresses";
$emailStmt = $pdo->prepare($emailQuery);
$emailStmt->execute();
$emails = $emailStmt->fetchAll(PDO::FETCH_COLUMN);

// Prepare email content
$emailContent = "Latest Missing Items Report\n\n These are the lockers that have missing items recorded in the last 7 days:\n\n";
$emailContent .= "The last check was recorded was {$latestCheckDate}\n\n";

if (!empty($checks)) {
    foreach ($checks as $check) {
        $emailContent .= "Truck: {$check['truck_name']}, Locker: {$check['locker_name']}, Item: {$check['item_name']}, Notes: {$check['notes']},  Checked by {$check['checked_by']}, at {$check['check_date']}\n";
    } 
} else {
        $emailContent .= "No missing items found in the last 7 days\n";
}




$emailContent .= "\nThe following items have been deleted in the last 7 days:\n";
if (!empty($deletedItems)) {
    foreach ($deletedItems as $deletedItem) {
        $emailContent .= "Truck: {$deletedItem['truck_name']}, Locker: {$deletedItem['locker_name']}, Item: {$deletedItem['item_name']}, Deleted at {$deletedItem['deleted_at']}\n";
    }       
} else {
    $emailContent .= "No items have been deleted in the last 7 days\n";
}
   


$emailContent .= $current_url ."\n\n";
echo "Message to send: " . $emailContent ;



// Send the email if there are email addresses
if (!empty($emails)) {
    $subject = "Missing Items Report - {$latestCheckDate}";
    $headers = "From: lockercheck@fireandemergency.nz";

    foreach ($emails as $email) {
        mail($email, $subject, $emailContent, $headers);
    }

    echo "Emails sent successfully!";
} else {
    echo "No email addresses to send to.";
}



?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Email Addresses</title>
</head>
<body class="<?php echo is_demo ? 'demo-mode' : ''; ?>">



    </ul>
    <?php include 'templates/footer.php'; ?>
</body>
</html>
