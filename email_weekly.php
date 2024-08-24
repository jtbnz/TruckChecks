<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php'; // Ensure PHPMailer is included
require 'config.php'; // Include your configuration file

// Database connection
include 'db.php';

// Query for missing items (adjust the query as needed)
$missingItemsQuery = $db->query("SELECT * FROM missing_items");
$missingItems = $missingItemsQuery->fetchAll(PDO::FETCH_ASSOC);

// Query for deleted items in the last 7 days
$deletedItemsQuery = $db->prepare("
    SELECT truck_name, locker_name, item_name, CONVERT_TZ(deleted_at, '+00:00', 'Pacific/Auckland') AS deleted_at
    FROM locker_item_deletion_log
    WHERE deleted_at >= NOW() - INTERVAL 7 DAY
    ORDER BY deleted_at DESC
");
$deletedItemsQuery->execute();
$deletedItems = $deletedItemsQuery->fetchAll(PDO::FETCH_ASSOC);

// Query to fetch email addresses
$fetchEmailsQuery = "SELECT id, email FROM email_addresses";
$emailResult = $db->query($fetchEmailsQuery);
$emails = $emailResult->fetchAll(PDO::FETCH_ASSOC);

// Initialize PHPMailer
$mail = new PHPMailer(true);

try {
    // Server settings
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;        // Use defined SMTP server
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USERNAME;    // Use defined SMTP username
    $mail->Password   = SMTP_PASSWORD;    // Use defined SMTP password
    $mail->SMTPSecure = SMTP_SECURE;      // Use defined encryption method
    $mail->Port       = SMTP_PORT;        // Use defined SMTP port

    // Set from address
    $mail->setFrom(FROM_EMAIL, FROM_NAME);

    // Add recipients from the database
    foreach ($emails as $email) {
        $mail->addAddress($email['email']); // Add each email from the query result
    }

    // Content
    $mail->isHTML(true);
    $mail->Subject = 'Inventory Report';

    // Start building the email body
    $emailBody = "<h1>Inventory Report</h1>";

    // Missing Items Table
    $emailBody .= "<h2>Missing Items</h2>";
    $emailBody .= "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse; width: 100%;'>";
    $emailBody .= "<thead><tr><th>Item Name</th><th>Quantity</th><th>Location</th></tr></thead><tbody>";

    if (!empty($missingItems)) {
        foreach ($missingItems as $item) {
            $emailBody .= "<tr>
                            <td>{$item['item_name']}</td>
                            <td>{$item['quantity']}</td>
                            <td>{$item['location']}</td>
                           </tr>";
        }
    } else {
        $emailBody .= "<tr><td colspan='3'>No missing items.</td></tr>";
    }

    $emailBody .= "</tbody></table>";

    // Deleted Items Table
    $emailBody .= "<h2>Deleted Items (Last 7 Days)</h2>";
    $emailBody .= "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse; width: 100%;'>";
    $emailBody .= "<thead><tr><th>Truck Name</th><th>Locker Name</th><th>Item Name</th><th>Date Deleted</th></tr></thead><tbody>";

    if (!empty($deletedItems)) {
        foreach ($deletedItems as $item) {
            $emailBody .= "<tr>
                            <td>{$item['truck_name']}</td>
                            <td>{$item['locker_name']}</td>
                            <td>{$item['item_name']}</td>
                            <td>{$item['deleted_at']}</td>
                           </tr>";
        }
    } else {
        $emailBody .= "<tr><td colspan='4'>No items deleted in the last 7 days.</td></tr>";
    }

    $emailBody .= "</tbody></table>";

    // Set email body
    $mail->Body = $emailBody;

    // Send the email
    $mail->send();
    echo 'Message has been sent';
} catch (Exception $e) {
    echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
}