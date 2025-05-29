<?php 
/* 
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);  

echo "debug is on";
 */
include('config.php');
require '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

include 'db.php';
include_once('auth.php');

// Require authentication
$user = requireAuth();
$station = null;

// Check if user has permission to send email results
if ($user['role'] !== 'superuser' && $user['role'] !== 'station_admin') {
    header('Location: login.php');
    exit;
}

// Get station context
if ($user['role'] === 'station_admin') {
    $station = requireStation();
} elseif ($user['role'] === 'superuser') {
    $station = getCurrentStation();
    if (!$station) {
        header('Location: select_station.php?redirect=email_results.php');
        exit;
    }
}

$pdo = get_db_connection();
$IS_DEMO = isset($_SESSION['IS_DEMO']) && $_SESSION['IS_DEMO'] === true;

$current_directory = dirname($_SERVER['REQUEST_URI']);
$current_url = 'https://' . $_SERVER['HTTP_HOST'] . $current_directory .  '/index.php';

// Check if email configuration is available
$email_configured = defined('EMAIL_HOST') && defined('EMAIL_USER') && defined('EMAIL_PASS') && defined('EMAIL_PORT');

// Fetch the latest check date for this station
$latestCheckQuery = "
    SELECT DISTINCT DATE(CONVERT_TZ(c.check_date, '+00:00', '+12:00')) as the_date 
    FROM checks c
    JOIN lockers l ON c.locker_id = l.id
    JOIN trucks t ON l.truck_id = t.id
    WHERE t.station_id = :station_id
    ORDER BY c.check_date DESC 
    LIMIT 1
";
$latestCheckStmt = $pdo->prepare($latestCheckQuery);
$latestCheckStmt->execute(['station_id' => $station['id']]);
$latestCheckResult = $latestCheckStmt->fetch(PDO::FETCH_ASSOC);
$latestCheckDate = $latestCheckResult ? $latestCheckResult['the_date'] : 'No checks found';

// Fetch the latest check data for this station
$checksQuery = "
    WITH LatestChecks AS (
        SELECT 
            c.locker_id, 
            MAX(c.id) AS latest_check_id
        FROM checks c
        JOIN lockers l ON c.locker_id = l.id
        JOIN trucks t ON l.truck_id = t.id
        WHERE c.check_date BETWEEN DATE_SUB(NOW(), INTERVAL 6 DAY) AND NOW()
        AND t.station_id = :station_id
        GROUP BY c.locker_id
    )
    SELECT 
        t.name as truck_name, 
        l.name as locker_name, 
        i.name as item_name, 
        ci.is_present as checked, 
        CONVERT_TZ(c.check_date, '+00:00', '+12:00') AS check_date,
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
    AND t.station_id = :station_id2
    ORDER BY t.name, l.name
";
                
$checksStmt = $pdo->prepare($checksQuery);
$checksStmt->execute(['station_id' => $station['id'], 'station_id2' => $station['id']]);
$checks = $checksStmt->fetchAll(PDO::FETCH_ASSOC);

// Query for deleted items in the last 7 days for this station
$deletedItemsQuery = $pdo->prepare("
    SELECT truck_name, locker_name, item_name, CONVERT_TZ(deleted_at, '+00:00', '+12:00') AS deleted_at
    FROM locker_item_deletion_log
    WHERE deleted_at >= NOW() - INTERVAL 7 DAY
    AND station_id = :station_id
    ORDER BY deleted_at DESC
");
$deletedItemsQuery->execute(['station_id' => $station['id']]);
$deletedItems = $deletedItemsQuery->fetchAll(PDO::FETCH_ASSOC);

// Fetch email addresses for this station
$emailQuery = "SELECT email FROM email_addresses WHERE station_id = :station_id";
$emailStmt = $pdo->prepare($emailQuery);
$emailStmt->execute(['station_id' => $station['id']]);
$emails = $emailStmt->fetchAll(PDO::FETCH_COLUMN);

// Also get admin email for this station
$adminEmailQuery = "SELECT setting_value FROM settings WHERE setting_name = 'admin_email' AND station_id = :station_id";
$adminEmailStmt = $pdo->prepare($adminEmailQuery);
$adminEmailStmt->execute(['station_id' => $station['id']]);
$adminEmail = $adminEmailStmt->fetchColumn();

if ($adminEmail) {
    $emails[] = $adminEmail;
}

// Remove duplicates
$emails = array_unique($emails);

// Prepare email content
$emailContent = "Latest Missing Items Report for " . $station['name'] . "\n\n";
$emailContent .= "These are the lockers that have missing items recorded in the last 7 days:\n\n";
$emailContent .= "The last check was recorded: {$latestCheckDate}\n\n";

if (!empty($checks)) {
    foreach ($checks as $check) {
        $emailContent .= "Truck: {$check['truck_name']}, Locker: {$check['locker_name']}, Item: {$check['item_name']}, Notes: {$check['notes']}, Checked by {$check['checked_by']}, at {$check['check_date']}\n";
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

$emailContent .= "\nAccess the system: " . $current_url . "\n\n";
$emailContent .= "This report is for station: " . $station['name'] . "\n";
$emailContent .= "Generated by: " . $user['username'] . " (" . $user['role'] . ")\n";

include 'templates/header.php';
?>

<style>
    .email-container {
        max-width: 800px;
        margin: 20px auto;
        padding: 20px;
    }

    .page-header {
        margin-bottom: 30px;
        padding-bottom: 15px;
        border-bottom: 2px solid #12044C;
    }

    .page-title {
        color: #12044C;
        margin: 0;
    }

    .access-info {
        background-color: #e7f3ff;
        border: 1px solid #b3d9ff;
        padding: 15px;
        border-radius: 5px;
        margin-bottom: 20px;
    }

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

    .email-preview {
        background-color: #f8f9fa;
        border: 1px solid #ddd;
        border-radius: 5px;
        padding: 20px;
        margin: 20px 0;
        white-space: pre-line;
        font-family: monospace;
        font-size: 14px;
    }

    .email-list {
        background-color: #e7f3ff;
        border: 1px solid #b3d9ff;
        border-radius: 5px;
        padding: 15px;
        margin: 20px 0;
    }

    .status-message {
        padding: 15px;
        border-radius: 5px;
        margin: 20px 0;
        font-weight: bold;
    }

    .success {
        background-color: #d4edda;
        border: 1px solid #c3e6cb;
        color: #155724;
    }

    .error {
        background-color: #f8d7da;
        border: 1px solid #f5c6cb;
        color: #721c24;
    }

    .warning {
        background-color: #fff3cd;
        border: 1px solid #ffeaa7;
        color: #856404;
    }

    .button {
        display: inline-block;
        padding: 12px 24px;
        background-color: #12044C;
        color: white;
        text-decoration: none;
        border: none;
        border-radius: 5px;
        font-size: 16px;
        cursor: pointer;
        transition: background-color 0.3s;
        margin: 5px;
    }

    .button:hover {
        background-color: #0056b3;
    }

    .button.secondary {
        background-color: #6c757d;
    }

    .button.secondary:hover {
        background-color: #545b62;
    }

    .button-container {
        text-align: center;
        margin-top: 30px;
    }

    .role-badge {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 11px;
        font-weight: bold;
        text-transform: uppercase;
    }

    .role-superuser {
        background-color: #dc3545;
        color: white;
    }

    .role-station_admin {
        background-color: #28a745;
        color: white;
    }
</style>

<div class="email-container">
    <div class="page-header">
        <h1 class="page-title">Email Check Results</h1>
    </div>

    <!-- Access Level Information -->
    <div class="access-info">
        <strong>Access Level:</strong> 
        <?php if ($user['role'] === 'superuser'): ?>
            <span class="role-badge role-superuser">Superuser</span> - Sending email results for selected station
        <?php elseif ($user['role'] === 'station_admin'): ?>
            <span class="role-badge role-station_admin">Station Admin</span> - Sending email results for your station
        <?php endif; ?>
    </div>

    <div class="station-info">
        <div class="station-name"><?= htmlspecialchars($station['name']) ?></div>
        <?php if ($station['description']): ?>
            <div style="color: #666; margin-top: 5px;"><?= htmlspecialchars($station['description']) ?></div>
        <?php endif; ?>
    </div>

    <?php if (!$email_configured): ?>
        <div class="status-message warning">
            <strong>Warning:</strong> Email configuration is not set up in config.php. Email functionality will not work until you configure the following settings:
            <ul>
                <li><strong>EMAIL_HOST</strong> - SMTP server hostname</li>
                <li><strong>EMAIL_USER</strong> - Email username</li>
                <li><strong>EMAIL_PASS</strong> - Email password</li>
                <li><strong>EMAIL_PORT</strong> - SMTP port number</li>
            </ul>
        </div>
    <?php endif; ?>

    <h2>Email Preview</h2>
    <div class="email-preview"><?= htmlspecialchars($emailContent) ?></div>

    <?php if (!empty($emails)): ?>
        <div class="email-list">
            <h3>Emails to send to:</h3>
            <ul>
                <?php foreach ($emails as $email): ?>
                    <li><?= htmlspecialchars($email) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <?php if ($email_configured): ?>
            <?php
            // Send the email if there are email addresses and email is configured
            $subject = "Missing Items Report - " . $station['name'] . " - {$latestCheckDate}";
            $mail = new PHPMailer(true);

            try {
                // Server settings
                $mail->isSMTP();
                $mail->Host = EMAIL_HOST; 
                $mail->SMTPAuth = true;
                $mail->Username = EMAIL_USER; 
                $mail->Password = EMAIL_PASS; 
                $mail->SMTPSecure = "ssl";
                $mail->Port = EMAIL_PORT;

                // Recipients
                $mail->setFrom(EMAIL_USER, 'TruckChecks - ' . $station['name']);
                foreach ($emails as $email) {
                    $mail->addAddress($email);
                }

                // Content
                $mail->isHTML(false);
                $mail->Subject = $subject;
                $mail->Body = $emailContent;
                $mail->send();
                
                echo '<div class="status-message success">Emails sent successfully to ' . count($emails) . ' recipient(s)!</div>';
            } catch (Exception $e) {
                echo '<div class="status-message error">Message could not be sent. Mailer Error: ' . htmlspecialchars($mail->ErrorInfo) . '</div>';
            }
            ?>
        <?php else: ?>
            <div class="status-message error">
                Cannot send emails because email configuration is not set up in config.php.
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="status-message warning">
            No email addresses configured for this station. Please set up email addresses in the admin panel or configure an admin email address.
        </div>
    <?php endif; ?>

    <div class="button-container">
        <a href="email_admin.php" class="button">Manage Email Settings</a>
        <a href="admin.php" class="button secondary">← Back to Admin</a>
    </div>
</div>

<?php include 'templates/footer.php'; ?>
