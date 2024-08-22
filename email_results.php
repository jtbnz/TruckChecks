<?php
/* ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);  */

include('password.php');



use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Check if the user is logged in
if (!isset($_COOKIE['logged_in']) || $_COOKIE['logged_in'] != 'true') {
    header('Location: login.php');
    exit;
}
include 'db.php';
include 'templates/header.php';



$pdo = get_db_connection();
//is_demo = isset($_SESSION['is_demo']) && $_SESSION['is_demo'] === true;


// Fetch the latest check date
$latestCheckQuery = "SELECT DISTINCT DATE(check_date) as the_date FROM checks ORDER BY check_date DESC limit 1";
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
                    c.check_date,
                    c.checked_by,
                    c.id as check_id
                FROM checks c
                JOIN LatestChecks lc ON c.id = lc.latest_check_id
                JOIN check_items ci ON c.id = ci.check_id
                JOIN lockers l ON c.locker_id = l.id
                JOIN trucks t ON l.truck_id = t.id
                JOIN items i ON ci.item_id = i.id
                WHERE ci.is_present = 0
                ORDER BY t.name, l.name;";
                
$checksStmt = $pdo->prepare($checksQuery);
$checksStmt->execute();
$checks = $checksStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch email addresses
$emailQuery = "SELECT email FROM email_addresses";
$emailStmt = $pdo->prepare($emailQuery);
$emailStmt->execute();
$emails = $emailStmt->fetchAll(PDO::FETCH_COLUMN);

// Prepare email content
$emailContent = "Latest Missing Items Report\n\n These are the lockers that have missing items recorded in the last 7 days:\n\n";
$emailContent .= "The last check was recorded was {$latestCheckDate}\n\n";
foreach ($checks as $check) {
    $emailContent .= "Truck: {$check['truck_name']}, Locker: {$check['locker_name']}, Item: {$check['item_name']}, Checked by {$check['checked_by']}, at {$check['check_date']}\n\n";
}

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

// Handling form submissions for managing email addresses
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_email'])) {
        $newEmail = $_POST['email'];
        $addEmailQuery = "INSERT INTO email_addresses (email) VALUES (?)";
        $addEmailStmt = $pdo->prepare($addEmailQuery);
        $addEmailStmt->execute([$newEmail]);
    } elseif (isset($_POST['delete_email'])) {
        $emailId = $_POST['email_id'];
        $deleteEmailQuery = "DELETE FROM email_addresses WHERE id = ?";
        $deleteEmailStmt = $pdo->prepare($deleteEmailQuery);
        $deleteEmailStmt->execute([$emailId]);
    } elseif (isset($_POST['update_email'])) {
        $emailId = $_POST['email_id'];
        $updatedEmail = $_POST['email'];
        $updateEmailQuery = "UPDATE email_addresses SET email = ? WHERE id = ?";
        $updateEmailStmt = $pdo->prepare($updateEmailQuery);
        $updateEmailStmt->execute([$updatedEmail, $emailId]);
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Email Addresses</title>
</head>
<body class="<?php echo is_demo ? 'demo-mode' : ''; ?>">
    <h2>Email Addresses</h2>
    <form method="post">
        <label for="email">Add Email:</label>
        <input type="email" name="email" required>
        <button type="submit" name="add_email">Add</button>
    </form>

    <h2>Existing Emails</h2>
    <ul>
        <?php
        $fetchEmailsQuery = "SELECT id, email FROM email_addresses";
        $fetchEmailsStmt = $pdo->prepare($fetchEmailsQuery);
        $fetchEmailsStmt->execute();
        $emailAddresses = $fetchEmailsStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($emailAddresses as $emailAddress) {
            echo "<li>";
            echo "<form method='post' style='display:inline;'>";
            echo "<input type='hidden' name='email_id' value='{$emailAddress['id']}'>";
            echo "<input type='email' name='email' value='{$emailAddress['email']}' required>";
            echo "<button type='submit' name='update_email'>Update</button>";
            echo "<button type='submit' name='delete_email'>Delete</button>";
            echo "</form>";
            echo "</li>";
        }
        ?>
    </ul>
    <?php include 'templates/footer.php'; ?>
</body>
</html>
