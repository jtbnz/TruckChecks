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
requireAuth();
$user = getCurrentUser();
$station = null;
$no_station_selected = false;

// Check if user has permission to send email results
if ($user['role'] !== 'superuser' && $user['role'] !== 'station_admin') {
    header('Location: login.php');
    exit;
}

// Get station context - use their first assigned station
if ($user['role'] === 'station_admin') {
    // Station admins: get their first assigned station
    $user_stations = getUserStations($user['id']);
    if (!empty($user_stations)) {
        $station = $user_stations[0];
    } else {
        $no_station_selected = true;
    }
} elseif ($user['role'] === 'superuser') {
    // Superusers: get their first assigned station, or first available station
    $user_stations = getUserStations($user['id']);
    if (!empty($user_stations)) {
        $station = $user_stations[0];
    } else {
        $no_station_selected = true;
    }
}

$pdo = get_db_connection();
$IS_DEMO = isset($_SESSION['IS_DEMO']) && $_SESSION['IS_DEMO'] === true;

$current_directory = dirname($_SERVER['REQUEST_URI']);
$current_url = 'https://' . $_SERVER['HTTP_HOST'] . $current_directory .  '/index.php';

// Check if email configuration is available
$email_configured = defined('EMAIL_HOST') && defined('EMAIL_USER') && defined('EMAIL_PASS') && defined('EMAIL_PORT');

// Initialize variables
$latestCheckDate = 'No checks found';
$checks = [];
$deletedItems = [];
$allNotes = [];
$emails = [];
$emailContent = '';
$htmlContent = '';

// Only process data if station is selected
if ($station) {
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
        LEFT JOIN check_notes cn on ci.check_id = cn.check_id
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

    // Query for all locker check notes in the last 7 days for this station
    $allNotesQuery = $pdo->prepare("
        SELECT
            t.name as truck_name,
            l.name as locker_name,
            cn.note as note_text,
            CONVERT_TZ(c.check_date, '+00:00', '+12:00') AS check_date,
            c.checked_by
        FROM check_notes cn
        JOIN checks c ON cn.check_id = c.id
        JOIN lockers l ON c.locker_id = l.id
        JOIN trucks t ON l.truck_id = t.id
        WHERE c.check_date BETWEEN DATE_SUB(NOW(), INTERVAL 6 DAY) AND NOW()
          AND t.station_id = :station_id
          AND TRIM(cn.note) != ''
        ORDER BY t.name, l.name, c.check_date DESC
    ");
    $allNotesQuery->execute(['station_id' => $station['id']]);
    $allNotes = $allNotesQuery->fetchAll(PDO::FETCH_ASSOC);

    // Fetch email addresses for this station
    $emailQuery = "SELECT email FROM email_addresses WHERE station_id = :station_id OR station_id IS NULL";
    $emailStmt = $pdo->prepare($emailQuery);
    $emailStmt->execute(['station_id' => $station['id']]);
    $emails = $emailStmt->fetchAll(PDO::FETCH_COLUMN);

    // Also get admin email for this station
    $adminEmailQuery = "SELECT setting_value FROM station_settings WHERE setting_key = 'admin_email' AND station_id = :station_id";
    $adminEmailStmt = $pdo->prepare($adminEmailQuery);
    $adminEmailStmt->execute(['station_id' => $station['id']]);
    $adminEmail = $adminEmailStmt->fetchColumn();

    if ($adminEmail) {
        $emails[] = $adminEmail;
    }

    // Remove duplicates
    $emails = array_unique($emails);

    // Generate HTML email content
    $htmlContent = '
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .header { background-color: #12044C; color: white; padding: 20px; text-align: center; }
            .section { margin: 20px 0; padding: 15px; background-color: #f8f9fa; border-radius: 5px; }
            .missing-items { background-color: #fff3cd; border: 1px solid #ffeaa7; }
            .deleted-items { background-color: #f8d7da; border: 1px solid #f5c6cb; }
            .item-entry { margin: 10px 0; padding: 10px; background-color: white; border-radius: 3px; }
            .notes { font-style: italic; color: #666; margin-top: 5px; }
            .footer { margin-top: 30px; padding: 20px; background-color: #e9ecef; text-align: center; font-size: 12px; }
            .label { font-weight: bold; color: #12044C; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>TruckChecks Report - ' . htmlspecialchars($station['name']) . '</h1>
            <p>Latest Check Date: ' . htmlspecialchars($latestCheckDate) . '</p>
        </div>';

    if (!empty($checks)) {
        $htmlContent .= '
        <div class="section missing-items">
            <h2>Missing Items (Last 7 Days)</h2>';
        
        foreach ($checks as $check) {
            $htmlContent .= '
            <div class="item-entry">
                <div><span class="label">Truck:</span> ' . htmlspecialchars($check['truck_name']) . '</div>
                <div><span class="label">Locker:</span> ' . htmlspecialchars($check['locker_name']) . '</div>
                <div><span class="label">Item:</span> ' . htmlspecialchars($check['item_name']) . '</div>
                <div><span class="label">Checked by:</span> ' . htmlspecialchars($check['checked_by']) . ' at ' . htmlspecialchars($check['check_date']) . '</div>';
            
            // Include notes if they exist and have content after trimming
            if (!empty($check['notes']) && trim($check['notes']) !== '') {
                $htmlContent .= '<div class="notes"><span class="label">Notes:</span> ' . htmlspecialchars(trim($check['notes'])) . '</div>';
            }
            
            $htmlContent .= '</div>';
        }
        
        $htmlContent .= '</div>';
    } else {
        $htmlContent .= '
        <div class="section">
            <p>No missing items found in the last 7 days.</p>
        </div>';
    }

    if (!empty($deletedItems)) {
        $htmlContent .= '
        <div class="section deleted-items">
            <h2>Deleted Items (Last 7 Days)</h2>';
        
        foreach ($deletedItems as $item) {
            $htmlContent .= '
            <div class="item-entry">
                <div><span class="label">Truck:</span> ' . htmlspecialchars($item['truck_name']) . '</div>
                <div><span class="label">Locker:</span> ' . htmlspecialchars($item['locker_name']) . '</div>
                <div><span class="label">Item:</span> ' . htmlspecialchars($item['item_name']) . '</div>
                <div><span class="label">Deleted at:</span> ' . htmlspecialchars($item['deleted_at']) . '</div>
            </div>';
        }
        
        $htmlContent .= '</div>';
    } else {
        $htmlContent .= '
        <div class="section">
            <p>No items have been deleted in the last 7 days.</p>
        </div>';
    }

    // Add all locker check notes section
    if (!empty($allNotes)) {
        $htmlContent .= '
        <div class="section" style="background-color: #e7f3ff; border: 1px solid #b3d9ff;">
            <h2>Locker Check Notes (Last 7 Days)</h2>';
        
        foreach ($allNotes as $note) {
            $htmlContent .= '
            <div class="item-entry">
                <div><span class="label">Truck:</span> ' . htmlspecialchars($note['truck_name']) . '</div>
                <div><span class="label">Locker:</span> ' . htmlspecialchars($note['locker_name']) . '</div>
                <div><span class="label">Checked by:</span> ' . htmlspecialchars($note['checked_by']) . ' at ' . htmlspecialchars($note['check_date']) . '</div>
                <div class="notes"><span class="label">Notes:</span> ' . htmlspecialchars(trim($note['note_text'])) . '</div>
            </div>';
        }
        
        $htmlContent .= '</div>';
    } else {
        $htmlContent .= '
        <div class="section">
            <p>No locker check notes found in the last 7 days.</p>
        </div>';
    }

    $htmlContent .= '
        <div class="footer">
            <p><a href="' . htmlspecialchars($current_url) . '">Access TruckChecks System</a></p>
            <p>This report is for station: ' . htmlspecialchars($station['name']) . '</p>
            <p>Generated by: ' . htmlspecialchars($user['username']) . ' (' . htmlspecialchars($user['role']) . ')</p>
            <p>Generated on: ' . date('Y-m-d H:i:s') . '</p>
        </div>
    </body>
    </html>';

    // Prepare plain text email content (for fallback)
    $emailContent = "Latest Missing Items Report for " . $station['name'] . "\n\n";
    $emailContent .= "These are the lockers that have missing items recorded in the last 7 days:\n\n";
    $emailContent .= "The last check was recorded: {$latestCheckDate}\n\n";

    if (!empty($checks)) {
        foreach ($checks as $check) {
            $emailContent .= "Truck: {$check['truck_name']}, Locker: {$check['locker_name']}, Item: {$check['item_name']}";
            if (!empty($check['notes']) && trim($check['notes']) !== '') {
                $emailContent .= ", Notes: " . trim($check['notes']);
            }
            $emailContent .= ", Checked by {$check['checked_by']}, at {$check['check_date']}\n";
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

    // Add all locker check notes to plain text
    $emailContent .= "\nLocker Check Notes (Last 7 Days):\n";
    if (!empty($allNotes)) {
        foreach ($allNotes as $note) {
            $emailContent .= "Truck: {$note['truck_name']}, Locker: {$note['locker_name']}, Checked by {$note['checked_by']} at {$note['check_date']}, Notes: " . trim($note['note_text']) . "\n";
        }
    } else {
        $emailContent .= "No locker check notes found in the last 7 days\n";
    }

    $emailContent .= "\nAccess the system: " . $current_url . "\n\n";
    $emailContent .= "This report is for station: " . $station['name'] . "\n";
    $emailContent .= "Generated by: " . $user['username'] . " (" . $user['role'] . ")\n";
}

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

    .no-station-message {
        background-color: #fff3cd;
        border: 1px solid #ffeaa7;
        padding: 20px;
        border-radius: 5px;
        margin: 20px 0;
        text-align: center;
    }

    .email-preview {
        background-color: #f8f9fa;
        border: 1px solid #ddd;
        border-radius: 5px;
        padding: 20px;
        margin: 20px 0;
    }

    .email-preview-html {
        border: 1px solid #dee2e6;
        padding: 20px;
        background-color: white;
        border-radius: 5px;
        margin-bottom: 20px;
    }

    .email-preview-plain {
        white-space: pre-line;
        font-family: monospace;
        font-size: 14px;
        background-color: #f8f9fa;
        padding: 20px;
        border: 1px solid #dee2e6;
        border-radius: 5px;
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

    .preview-tabs {
        display: flex;
        border-bottom: 2px solid #dee2e6;
        margin-bottom: 20px;
    }

    .preview-tab {
        padding: 10px 20px;
        cursor: pointer;
        background-color: #f8f9fa;
        border: 1px solid #dee2e6;
        border-bottom: none;
        margin-right: 5px;
        border-radius: 5px 5px 0 0;
    }

    .preview-tab.active {
        background-color: white;
        border-bottom: 2px solid white;
        margin-bottom: -2px;
    }

    .preview-content {
        display: none;
    }

    .preview-content.active {
        display: block;
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
            <span class="role-badge role-superuser">Superuser</span> - Sending email results for assigned station
        <?php elseif ($user['role'] === 'station_admin'): ?>
            <span class="role-badge role-station_admin">Station Admin</span> - Sending email results for your station
        <?php endif; ?>
    </div>

    <?php if ($no_station_selected): ?>
        <div class="no-station-message">
            <h2>No Station Assigned</h2>
            <p>You don't have any stations assigned to your account.</p>
            <p>Please contact your administrator to assign you to a station before you can send email results.</p>
            <div class="button-container">
                <a href="javascript:parent.location.href='admin.php?page=dashboard'" class="button">← Back to Admin</a>
            </div>
        </div>
    <?php else: ?>

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
    <div class="email-preview">
        <div class="preview-tabs">
            <div class="preview-tab active" onclick="switchTab('html')">HTML Version</div>
            <div class="preview-tab" onclick="switchTab('plain')">Plain Text Version</div>
        </div>
        <div id="htmlPreview" class="preview-content active">
            <div class="email-preview-html"><?= $htmlContent ?></div>
        </div>
        <div id="plainPreview" class="preview-content">
            <div class="email-preview-plain"><?= htmlspecialchars($emailContent) ?></div>
        </div>
    </div>

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
                // Validate EMAIL_USER is a proper email format
                $from_email = filter_var(EMAIL_USER, FILTER_VALIDATE_EMAIL) ? EMAIL_USER : 'noreply@' . $_SERVER['HTTP_HOST'];
                $mail->setFrom($from_email, 'TruckChecks - ' . $station['name']);
                foreach ($emails as $email) {
                    $mail->addAddress($email);
                }

                // Content
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body = $htmlContent;
                $mail->AltBody = $emailContent;
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
        <a href="javascript:parent.location.href='admin.php?page=dashboard'" class="button secondary">← Back to Admin</a>
    </div>

    <?php endif; ?>
</div>

<script>
function switchTab(tab) {
    // Update tab active states
    document.querySelectorAll('.preview-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.preview-content').forEach(c => c.classList.remove('active'));
    
    if (tab === 'html') {
        document.querySelector('.preview-tab:first-child').classList.add('active');
        document.getElementById('htmlPreview').classList.add('active');
    } else {
        document.querySelector('.preview-tab:last-child').classList.add('active');
        document.getElementById('plainPreview').classList.add('active');
    }
}
</script>

<?php include 'templates/footer.php'; ?>
