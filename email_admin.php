<?php
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

// Check if user has permission to manage email settings
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

$db = get_db_connection();

// Handle AJAX request for email preview
if (isset($_GET['action']) && $_GET['action'] === 'preview' && $station) {
    header('Content-Type: application/json');
    
    try {
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
        $latestCheckStmt = $db->prepare($latestCheckQuery);
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
                        
        $checksStmt = $db->prepare($checksQuery);
        $checksStmt->execute(['station_id' => $station['id'], 'station_id2' => $station['id']]);
        $checks = $checksStmt->fetchAll(PDO::FETCH_ASSOC);

        // Query for deleted items in the last 7 days for this station
        $deletedItemsQuery = $db->prepare("
            SELECT truck_name, locker_name, item_name, CONVERT_TZ(deleted_at, '+00:00', '+12:00') AS deleted_at
            FROM locker_item_deletion_log
            WHERE deleted_at >= NOW() - INTERVAL 7 DAY
            AND station_id = :station_id
            ORDER BY deleted_at DESC
        ");
        $deletedItemsQuery->execute(['station_id' => $station['id']]);
        $deletedItems = $deletedItemsQuery->fetchAll(PDO::FETCH_ASSOC);

        // Fetch email addresses for this station
        $emailQuery = "SELECT email FROM email_addresses WHERE station_id = :station_id OR station_id IS NULL";
        $emailStmt = $db->prepare($emailQuery);
        $emailStmt->execute(['station_id' => $station['id']]);
        $emails = $emailStmt->fetchAll(PDO::FETCH_COLUMN);

        // Also get admin email for this station
        $adminEmailQuery = "SELECT setting_value FROM station_settings WHERE setting_key = 'admin_email' AND station_id = :station_id";
        $adminEmailStmt = $db->prepare($adminEmailQuery);
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

        $current_directory = dirname($_SERVER['REQUEST_URI']);
        $current_url = 'https://' . $_SERVER['HTTP_HOST'] . $current_directory .  '/index.php';

        $htmlContent .= '
            <div class="footer">
                <p><a href="' . htmlspecialchars($current_url) . '">Access TruckChecks System</a></p>
                <p>This report is for station: ' . htmlspecialchars($station['name']) . '</p>
                <p>Generated by: ' . htmlspecialchars($user['username']) . ' (' . htmlspecialchars($user['role']) . ')</p>
                <p>Generated on: ' . date('Y-m-d H:i:s') . '</p>
            </div>
        </body>
        </html>';

        // Prepare plain text version
        $plainContent = "Latest Missing Items Report for " . $station['name'] . "\n\n";
        $plainContent .= "These are the lockers that have missing items recorded in the last 7 days:\n\n";
        $plainContent .= "The last check was recorded: {$latestCheckDate}\n\n";

        if (!empty($checks)) {
            foreach ($checks as $check) {
                $plainContent .= "Truck: {$check['truck_name']}, Locker: {$check['locker_name']}, Item: {$check['item_name']}";
                if (!empty($check['notes']) && trim($check['notes']) !== '') {
                    $plainContent .= ", Notes: " . trim($check['notes']);
                }
                $plainContent .= ", Checked by {$check['checked_by']}, at {$check['check_date']}\n";
            } 
        } else {
            $plainContent .= "No missing items found in the last 7 days\n";
        }

        $plainContent .= "\nThe following items have been deleted in the last 7 days:\n";
        if (!empty($deletedItems)) {
            foreach ($deletedItems as $deletedItem) {
                $plainContent .= "Truck: {$deletedItem['truck_name']}, Locker: {$deletedItem['locker_name']}, Item: {$deletedItem['item_name']}, Deleted at {$deletedItem['deleted_at']}\n";
            }       
        } else {
            $plainContent .= "No items have been deleted in the last 7 days\n";
        }

        $plainContent .= "\nAccess the system: " . $current_url . "\n\n";
        $plainContent .= "This report is for station: " . $station['name'] . "\n";
        $plainContent .= "Generated by: " . $user['username'] . " (" . $user['role'] . ")\n";

        echo json_encode([
            'success' => true,
            'htmlContent' => $htmlContent,
            'plainContent' => $plainContent,
            'emails' => $emails,
            'subject' => "Missing Items Report - " . $station['name'] . " - {$latestCheckDate}"
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// Handle sending preview email via AJAX
if (isset($_POST['action']) && $_POST['action'] === 'send_preview' && $station) {
    header('Content-Type: application/json');
    
    $previewEmail = $_POST['email'] ?? '';
    $htmlContent = $_POST['htmlContent'] ?? '';
    $plainContent = $_POST['plainContent'] ?? '';
    $subject = $_POST['subject'] ?? '';
    
    if (!empty($previewEmail) && filter_var($previewEmail, FILTER_VALIDATE_EMAIL)) {
        try {
            // Check if email configuration is available
            if (!defined('EMAIL_HOST') || !defined('EMAIL_USER') || !defined('EMAIL_PASS')) {
                throw new Exception('Email configuration is not set up in config.php');
            }
            
            $mail = new PHPMailer(true);
            
            // Server settings
            $mail->isSMTP();
            $mail->Host = EMAIL_HOST; 
            $mail->SMTPAuth = true;
            $mail->Username = EMAIL_USER; 
            $mail->Password = EMAIL_PASS; 
            $mail->SMTPSecure = "ssl";
            $mail->Port = EMAIL_PORT;
            
            // Recipients
            $from_email = filter_var(EMAIL_USER, FILTER_VALIDATE_EMAIL) ? EMAIL_USER : 'noreply@' . $_SERVER['HTTP_HOST'];
            $mail->setFrom($from_email, 'TruckChecks - ' . $station['name']);
            $mail->addAddress($previewEmail);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlContent;
            $mail->AltBody = $plainContent;
            
            $mail->send();
            echo json_encode([
                'success' => true,
                'message' => "Preview email sent successfully to " . htmlspecialchars($previewEmail)
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => "Failed to send preview email: " . $e->getMessage()
            ]);
        }
    } else {
        echo json_encode([
            'success' => false,
            'error' => "Please enter a valid email address."
        ]);
    }
    exit;
}

// Handle adding multiple email addresses (only if station is selected)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_emails']) && $station) {
    $email_list = $_POST['email_list'];
    if (!empty($email_list)) {
        try {
            // Parse multiple emails (comma or newline separated)
            $emails = preg_split('/[,\n\r]+/', $email_list);
            $valid_emails = [];
            $invalid_emails = [];
            
            foreach ($emails as $email) {
                $email = trim($email);
                if (!empty($email)) {
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $valid_emails[] = $email;
                    } else {
                        $invalid_emails[] = $email;
                    }
                }
            }
            
            if (!empty($valid_emails)) {
                $added_count = 0;
                foreach ($valid_emails as $email) {
                    // Check if email already exists for this station
                    $stmt = $db->prepare('SELECT COUNT(*) FROM email_addresses WHERE email = ? AND (station_id = ? OR station_id IS NULL)');
                    $stmt->execute([$email, $station['id']]);
                    $exists = $stmt->fetchColumn();
                    
                    if (!$exists) {
                        // Insert new email for this station
                        $stmt = $db->prepare('INSERT INTO email_addresses (email, station_id) VALUES (?, ?)');
                        $stmt->execute([$email, $station['id']]);
                        $added_count++;
                    }
                }
                
                $success_message = "Added {$added_count} email address(es) successfully for " . htmlspecialchars($station['name']) . "!";
                if (!empty($invalid_emails)) {
                    $success_message .= " Invalid emails skipped: " . implode(', ', $invalid_emails);
                }
            } else {
                $error_message = "No valid email addresses found. Invalid emails: " . implode(', ', $invalid_emails);
            }
        } catch (Exception $e) {
            $error_message = "Error adding email addresses: " . $e->getMessage();
        }
    } else {
        $error_message = "Please enter at least one email address.";
    }
}

// Handle removing email address
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['remove_email']) && $station) {
    $email_to_remove = $_POST['email_to_remove'];
    try {
        $stmt = $db->prepare('DELETE FROM email_addresses WHERE email = ? AND station_id = ?');
        $stmt->execute([$email_to_remove, $station['id']]);
        $success_message = "Email address removed successfully!";
    } catch (Exception $e) {
        $error_message = "Error removing email address: " . $e->getMessage();
    }
}

// Handle test email sending (only if station is selected)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_test_email']) && $station) {
    $test_email = $_POST['test_email'];
    if (!empty($test_email) && filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
        try {
            // Check if email configuration is available
            if (!defined('EMAIL_HOST') || !defined('EMAIL_USER') || !defined('EMAIL_PASS')) {
                throw new Exception('Email configuration is not set up in config.php');
            }
            
            // Debug: Show email configuration being used (only if DEBUG is enabled)
            if (defined('DEBUG') && DEBUG) {
                echo '<script>console.log("=== EMAIL DEBUG INFO ===");</script>';
                echo '<script>console.log("EMAIL_HOST: ' . addslashes(defined('EMAIL_HOST') ? EMAIL_HOST : 'NOT DEFINED') . '");</script>';
                echo '<script>console.log("EMAIL_USER: ' . addslashes(defined('EMAIL_USER') ? EMAIL_USER : 'NOT DEFINED') . '");</script>';
                echo '<script>console.log("EMAIL_PASS: ' . addslashes(defined('EMAIL_PASS') ? (EMAIL_PASS ? '[SET - ' . strlen(EMAIL_PASS) . ' chars]' : '[EMPTY]') : 'NOT DEFINED') . '");</script>';
                echo '<script>console.log("EMAIL_PORT: ' . addslashes(defined('EMAIL_PORT') ? EMAIL_PORT : 'NOT DEFINED') . '");</script>';
                echo '<script>console.log("From Email (calculated): ' . addslashes(filter_var(EMAIL_USER, FILTER_VALIDATE_EMAIL) ? EMAIL_USER : 'noreply@' . $_SERVER['HTTP_HOST']) . '");</script>';
                echo '<script>console.log("Test Email To: ' . addslashes($test_email) . '");</script>';
                echo '<script>console.log("========================");</script>';
            }
            
            $mail = new PHPMailer(true);
            
            // Enable verbose debug output to console (only if DEBUG is enabled)
            if (defined('DEBUG') && DEBUG) {
                $mail->SMTPDebug = 2;
                $mail->Debugoutput = function($str, $level) {
                    // Properly escape for JavaScript and handle special characters
                    $escaped_str = json_encode($str, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                    echo '<script>console.log("PHPMailer DEBUG [' . $level . ']: " + ' . $escaped_str . ');</script>';
                };
                echo '<script>console.log("PHPMailer debug output enabled");</script>';
            }
            
            // Server settings - match email_results.php exactly
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
            if (defined('DEBUG') && DEBUG) {
                echo '<script>console.log("Setting From email to: ' . addslashes($from_email) . '");</script>';
            }
            $mail->setFrom($from_email, 'TruckChecks System');
            
            if (defined('DEBUG') && DEBUG) {
                echo '<script>console.log("Adding recipient: ' . addslashes($test_email) . '");</script>';
            }
            $mail->addAddress($test_email);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = 'TruckChecks Test Email - ' . $station['name'];
            $mail->Body = '
                <h2>TruckChecks Test Email</h2>
                <p>This is a test email from the TruckChecks system.</p>
                <p><strong>Station:</strong> ' . htmlspecialchars($station['name']) . '</p>
                <p><strong>Sent by:</strong> ' . htmlspecialchars($user['username']) . ' (' . htmlspecialchars($user['role']) . ')</p>
                <p><strong>Date/Time:</strong> ' . date('Y-m-d H:i:s') . '</p>
                <p>If you received this email, your email configuration is working correctly!</p>
            ';
            
            if (defined('DEBUG') && DEBUG) {
                echo '<script>console.log("About to send email...");</script>';
                echo '<script>console.log("Subject: ' . addslashes($mail->Subject) . '");</script>';
                echo '<script>console.log("SMTP Host: ' . addslashes($mail->Host) . '");</script>';
                echo '<script>console.log("SMTP Port: ' . addslashes($mail->Port) . '");</script>';
                echo '<script>console.log("SMTP Username: ' . addslashes($mail->Username) . '");</script>';
            }
            
            $mail->send();
            if (defined('DEBUG') && DEBUG) {
                echo '<script>console.log("Email sent successfully!");</script>';
            }
            $test_success_message = "Test email sent successfully to " . htmlspecialchars($test_email) . "!";
        } catch (Exception $e) {
            if (defined('DEBUG') && DEBUG) {
                echo '<script>console.log("Email send failed: ' . addslashes($e->getMessage()) . '");</script>';
            }
            $test_error_message = "Failed to send test email: " . $e->getMessage();
        }
    } else {
        $test_error_message = "Please enter a valid email address for testing.";
    }
}

// Handle form submission for setting admin email (only if station is selected)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['set_admin_email']) && $station) {
    $admin_email = $_POST['admin_email'];
    if (!empty($admin_email) && filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
        try {
            // Check if settings table exists and has an admin_email entry for this station
            $stmt = $db->prepare('SELECT COUNT(*) FROM station_settings WHERE setting_key = "admin_email" AND station_id = ?');
            $stmt->execute([$station['id']]);
            $exists = $stmt->fetchColumn();
            
            if ($exists) {
                // Update existing email
                $stmt = $db->prepare('UPDATE station_settings SET setting_value = ? WHERE setting_key = "admin_email" AND station_id = ?');
                $stmt->execute([$admin_email, $station['id']]);
            } else {
                // Insert new email
                $stmt = $db->prepare('INSERT INTO station_settings (setting_key, setting_value, station_id, setting_type, description) VALUES ("admin_email", ?, ?, "string", "Admin email address for this station")');
                $stmt->execute([$admin_email, $station['id']]);
            }
            
            $success_message = "Admin email has been set successfully for " . htmlspecialchars($station['name']) . "!";
        } catch (Exception $e) {
            $error_message = "Error setting admin email: " . $e->getMessage();
        }
    } else {
        $error_message = "Please enter a valid email address.";
    }
}

// Get current admin email and all email addresses for this station (only if station is selected)
$current_admin_email = null;
$current_emails = [];
if ($station) {
    try {
        $stmt = $db->prepare('SELECT setting_value FROM station_settings WHERE setting_key = "admin_email" AND station_id = ?');
        $stmt->execute([$station['id']]);
        $current_admin_email = $stmt->fetchColumn();
        
        // Get all email addresses for this station
        $stmt = $db->prepare('SELECT email FROM email_addresses WHERE station_id = ? OR station_id IS NULL ORDER BY email');
        $stmt->execute([$station['id']]);
        $current_emails = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $current_admin_email = null;
        $current_emails = [];
    }
}

// Check if email configuration is available
$email_configured = defined('EMAIL_HOST') && defined('EMAIL_USER') && defined('EMAIL_PASS') && defined('EMAIL_PORT');

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

    .info-section {
        margin: 20px 0;
        padding: 15px;
        border-radius: 5px;
    }

    .current-email-section {
        background-color: #e7f3ff;
        border: 1px solid #b3d9ff;
    }

    .help-section {
        background-color: #fff3cd;
        border: 1px solid #ffeaa7;
    }

    .test-email-section {
        background-color: #f8f9fa;
        border: 1px solid #dee2e6;
    }

    .form-section {
        background: white;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 30px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .form-section h2 {
        margin-top: 0;
        color: #12044C;
    }

    .input-container {
        margin-bottom: 15px;
    }

    .input-container label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
        color: #333;
    }

    .input-container input, .input-container textarea {
        width: 100%;
        padding: 10px;
        border: 1px solid #ccc;
        border-radius: 5px;
        font-size: 16px;
        box-sizing: border-box;
    }

    .input-container textarea {
        height: 100px;
        resize: vertical;
    }

    .button-container {
        text-align: center;
        margin-top: 20px;
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

    .button.test {
        background-color: #28a745;
    }

    .button.test:hover {
        background-color: #218838;
    }

    .button.danger {
        background-color: #dc3545;
    }

    .button.danger:hover {
        background-color: #c82333;
    }

    .button.preview {
        background-color: #17a2b8;
    }

    .button.preview:hover {
        background-color: #138496;
    }

    .button:disabled {
        background-color: #6c757d;
        cursor: not-allowed;
        opacity: 0.6;
    }

    .success-message {
        color: #155724;
        margin: 20px 0;
        padding: 15px;
        background-color: #d4edda;
        border: 1px solid #c3e6cb;
        border-radius: 5px;
    }

    .error-message {
        color: #721c24;
        margin: 20px 0;
        padding: 15px;
        background-color: #f8d7da;
        border: 1px solid #f5c6cb;
        border-radius: 5px;
    }

    .warning-message {
        color: #856404;
        margin: 20px 0;
        padding: 15px;
        background-color: #fff3cd;
        border: 1px solid #ffeaa7;
        border-radius: 5px;
    }

    .current-email-display {
        font-size: 18px;
        font-weight: bold;
        color: #12044C;
        margin: 10px 0;
    }

    .no-email-text {
        color: #666;
        font-style: italic;
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

    .email-list {
        list-style: none;
        padding: 0;
    }

    .email-list li {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px;
        margin: 5px 0;
        background-color: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 5px;
    }

    .email-address {
        font-family: monospace;
        font-size: 14px;
    }

    .remove-btn {
        padding: 5px 10px;
        font-size: 12px;
        margin: 0;
    }

    /* Modal styles */
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0,0,0,0.4);
    }

    .modal-content {
        background-color: #fefefe;
        margin: 5% auto;
        padding: 0;
        border: 1px solid #888;
        width: 90%;
        max-width: 800px;
        border-radius: 5px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    }

    .modal-header {
        padding: 20px;
        background-color: #12044C;
        color: white;
        border-radius: 5px 5px 0 0;
    }

    .modal-header h2 {
        margin: 0;
    }

    .modal-body {
        padding: 20px;
        max-height: 60vh;
        overflow-y: auto;
    }

    .modal-footer {
        padding: 20px;
        background-color: #f8f9fa;
        border-top: 1px solid #dee2e6;
        border-radius: 0 0 5px 5px;
        text-align: right;
    }

    .close {
        color: white;
        float: right;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
    }

    .close:hover,
    .close:focus {
        color: #f8f9fa;
        text-decoration: none;
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

    .preview-html {
        border: 1px solid #dee2e6;
        padding: 20px;
        background-color: #f8f9fa;
        border-radius: 5px;
    }

    .preview-plain {
        white-space: pre-wrap;
        font-family: monospace;
        background-color: #f8f9fa;
        padding: 20px;
        border: 1px solid #dee2e6;
        border-radius: 5px;
    }

    .email-recipients {
        margin-top: 20px;
        padding: 15px;
        background-color: #e7f3ff;
        border: 1px solid #b3d9ff;
        border-radius: 5px;
    }

    /* Mobile responsive */
    @media (max-width: 768px) {
        .email-container {
            padding: 10px;
        }
        
        .email-list li {
            flex-direction: column;
            align-items: flex-start;
        }
        
        .remove-btn {
            margin-top: 10px;
            align-self: flex-end;
        }

        .modal-content {
            width: 95%;
            margin: 2% auto;
        }
    }
</style>

<div class="email-container">
    <div class="page-header">
        <h1 class="page-title">Manage Email Addresses</h1>
    </div>

    <!-- Access Level Information -->
    <div class="access-info">
        <strong>Access Level:</strong> 
        <?php if ($user['role'] === 'superuser'): ?>
            <span class="role-badge role-superuser">Superuser</span> - Managing email settings for assigned station
        <?php elseif ($user['role'] === 'station_admin'): ?>
            <span class="role-badge role-station_admin">Station Admin</span> - Managing email settings for your station
        <?php endif; ?>
    </div>

    <?php if ($no_station_selected): ?>
        <div class="no-station-message">
            <h2>No Station Assigned</h2>
            <p>You don't have any stations assigned to your account.</p>
            <p>Please contact your administrator to assign you to a station before you can manage email settings.</p>
            <div class="button-container">
                <a href="admin.php" class="button">← Back to Admin</a>
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
        <div class="warning-message">
            <strong>Warning:</strong> Email configuration is not set up in config.php. Email functionality will not work until you configure the following settings:
            <ul>
                <li><strong>EMAIL_HOST</strong> - SMTP server hostname</li>
                <li><strong>EMAIL_USER</strong> - Email username</li>
                <li><strong>EMAIL_PASS</strong> - Email password</li>
                <li><strong>EMAIL_PORT</strong> - SMTP port number</li>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (isset($success_message)): ?>
        <div class="success-message">
            <?= htmlspecialchars($success_message) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($error_message)): ?>
        <div class="error-message">
            <?= htmlspecialchars($error_message) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($test_success_message)): ?>
        <div class="success-message">
            <?= htmlspecialchars($test_success_message) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($test_error_message)): ?>
        <div class="error-message">
            <?= htmlspecialchars($test_error_message) ?>
        </div>
    <?php endif; ?>

    <!-- Current Email Addresses -->
    <div class="info-section current-email-section">
        <h2>Current Email Addresses for <?= htmlspecialchars($station['name']) ?></h2>
        <?php if (!empty($current_emails)): ?>
            <ul class="email-list">
                <?php foreach ($current_emails as $email): ?>
                    <li>
                        <span class="email-address"><?= htmlspecialchars($email) ?></span>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="email_to_remove" value="<?= htmlspecialchars($email) ?>">
                            <button type="submit" name="remove_email" class="button danger remove-btn" 
                                    onclick="return confirm('Are you sure you want to remove this email address?')">Remove</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="no-email-text">No email addresses are currently configured for this station.</p>
        <?php endif; ?>
    </div>

    <!-- Add Multiple Email Addresses -->
    <div class="form-section">
        <h2>Add Email Addresses</h2>
        <p>Add multiple email addresses for receiving reports and notifications. You can enter multiple emails separated by commas or on separate lines.</p>
        <form method="POST">
            <div class="input-container">
                <label for="email_list">Email Addresses:</label>
                <textarea name="email_list" id="email_list" placeholder="admin@example.com, manager@example.com&#10;supervisor@example.com" required></textarea>
                <small style="color: #666;">Enter multiple emails separated by commas or on separate lines</small>
            </div>
            <div class="button-container">
                <button type="submit" name="add_emails" class="button">Add Email Addresses</button>
            </div>
        </form>
    </div>

    <!-- Admin Email (Legacy Support) -->
    <div class="form-section">
        <h2>Primary Admin Email</h2>
        <p>Set a primary admin email address for this station (this is in addition to the email addresses above).</p>
        <?php if ($current_admin_email): ?>
            <div class="current-email-display">
                Current: <?= htmlspecialchars($current_admin_email) ?>
            </div>
        <?php endif; ?>
        <form method="POST">
            <div class="input-container">
                <label for="admin_email">Primary Admin Email Address:</label>
                <input type="email" name="admin_email" id="admin_email" placeholder="admin@example.com" value="<?= htmlspecialchars($current_admin_email ?? '') ?>">
            </div>
            <div class="button-container">
                <button type="submit" name="set_admin_email" class="button">Set Primary Admin Email</button>
            </div>
        </form>
    </div>

    <!-- Email Preview Section -->
    <div class="form-section">
        <h2>Preview Email Report</h2>
        <p>Preview what the check results email will look like before sending it to all recipients.</p>
        <div class="button-container">
            <button type="button" class="button preview" onclick="showEmailPreview()" <?= $email_configured ? '' : 'disabled' ?>>
                Preview Email Report
            </button>
        </div>
    </div>

    <?php if ($email_configured): ?>
    <div class="form-section test-email-section">
        <h2>Test Email Configuration</h2>
        <p>Send a test email to verify that your email configuration is working correctly.</p>
        <?php if (defined('DEBUG') && DEBUG): ?>
            <div style="background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px 0; border-radius: 5px;">
                <strong>Debug Mode:</strong> Console debugging is enabled. Check browser console for detailed SMTP debug information.
            </div>
        <?php endif; ?>
        <form method="POST">
            <div class="input-container">
                <label for="test_email">Test Email Address:</label>
                <input type="email" name="test_email" id="test_email" placeholder="test@example.com" value="<?= htmlspecialchars($current_admin_email ?? '') ?>" required>
            </div>
            <div class="button-container">
                <button type="submit" name="send_test_email" class="button test">Send Test Email</button>
            </div>
        </form>
    </div>
    <?php else: ?>
    <div class="form-section test-email-section">
        <h2>Test Email Configuration</h2>
        <p>Email testing is not available because email configuration is not set up in config.php.</p>
        <div class="button-container">
            <button type="button" class="button test" disabled>Send Test Email (Disabled)</button>
        </div>
    </div>
    <?php endif; ?>

    <div class="info-section help-section">
        <h3>About Email Configuration</h3>
        <p>Email addresses configured here will receive reports and notifications from the TruckChecks system for <strong><?= htmlspecialchars($station['name']) ?></strong>.</p>
        <ul>
            <li><strong>Multiple Email Addresses:</strong> You can add multiple email addresses to receive reports</li>
            <li><strong>Station-Specific:</strong> Each station can have its own set of email addresses</li>
            <li><strong>Primary Admin Email:</strong> The primary admin email is used for system notifications</li>
            <li><strong>Weekly Reports:</strong> All configured emails will receive weekly check reports</li>
            <li><strong>Preview Feature:</strong> Preview the email report before sending to ensure all information is correct</li>
        </ul>
        <p>Required email settings in config.php:</p>
        <ul>
            <li><strong>EMAIL_HOST</strong> - SMTP server hostname</li>
            <li><strong>EMAIL_USER</strong> - Email username</li>
            <li><strong>EMAIL_PASS</strong> - Email password</li>
            <li><strong>EMAIL_PORT</strong> - SMTP port number</li>
        </ul>
        <?php if (defined('DEBUG')): ?>
            <p><strong>Debug Mode:</strong> Currently <?= DEBUG ? 'ENABLED' : 'DISABLED' ?></p>
        <?php endif; ?>
    </div>

    <div class="button-container">
        <a href="admin.php" class="button secondary">← Back to Admin</a>
    </div>

    <?php endif; ?>
</div>

<!-- Email Preview Modal -->
<div id="emailPreviewModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span class="close" onclick="closeEmailPreview()">&times;</span>
            <h2>Email Report Preview</h2>
        </div>
        <div class="modal-body">
            <div class="preview-tabs">
                <div class="preview-tab active" onclick="switchPreviewTab('html')">HTML Version</div>
                <div class="preview-tab" onclick="switchPreviewTab('plain')">Plain Text Version</div>
            </div>
            <div id="htmlPreview" class="preview-content active">
                <div class="preview-html"></div>
            </div>
            <div id="plainPreview" class="preview-content">
                <div class="preview-plain"></div>
            </div>
            <div class="email-recipients">
                <h3>Recipients:</h3>
                <div id="recipientsList"></div>
            </div>
        </div>
        <div class="modal-footer">
            <input type="email" id="previewEmail" placeholder="Send preview to email..." style="padding: 10px; width: 300px; margin-right: 10px;">
            <button class="button test" onclick="sendPreviewEmail()">Send Preview</button>
            <button class="button secondary" onclick="closeEmailPreview()">Close</button>
        </div>
    </div>
</div>

<script>
let currentPreviewData = null;

function showEmailPreview() {
    // Show loading state
    const modal = document.getElementById('emailPreviewModal');
    modal.style.display = 'block';
    
    // Reset tabs
    switchPreviewTab('html');
    
    // Clear previous content
    document.querySelector('.preview-html').innerHTML = '<p>Loading preview...</p>';
    document.querySelector('.preview-plain').textContent = 'Loading preview...';
    document.getElementById('recipientsList').innerHTML = '';
    
    // Fetch preview data
    fetch('email_admin.php?action=preview')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                currentPreviewData = data;
                
                // Update HTML preview
                document.querySelector('.preview-html').innerHTML = data.htmlContent;
                
                // Update plain text preview
                document.querySelector('.preview-plain').textContent = data.plainContent;
                
                // Update recipients list
                const recipientsList = document.getElementById('recipientsList');
                if (data.emails && data.emails.length > 0) {
                    recipientsList.innerHTML = data.emails.map(email => 
                        `<div style="padding: 5px; background-color: #f8f9fa; margin: 5px 0; border-radius: 3px;">${email}</div>`
                    ).join('');
                } else {
                    recipientsList.innerHTML = '<p style="color: #666; font-style: italic;">No recipients configured</p>';
                }
                
                // Set default preview email
                const previewEmailInput = document.getElementById('previewEmail');
                if (data.emails && data.emails.length > 0) {
                    previewEmailInput.value = data.emails[0];
                }
            } else {
                alert('Error loading preview: ' + (data.error || 'Unknown error'));
                closeEmailPreview();
            }
        })
        .catch(error => {
            alert('Error loading preview: ' + error.message);
            closeEmailPreview();
        });
}

function closeEmailPreview() {
    document.getElementById('emailPreviewModal').style.display = 'none';
}

function switchPreviewTab(tab) {
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

function sendPreviewEmail() {
    const email = document.getElementById('previewEmail').value;
    if (!email || !currentPreviewData) {
        alert('Please enter a valid email address');
        return;
    }
    
    // Disable button to prevent multiple sends
    const button = event.target;
    button.disabled = true;
    button.textContent = 'Sending...';
    
    // Send preview email
    const formData = new FormData();
    formData.append('action', 'send_preview');
    formData.append('email', email);
    formData.append('htmlContent', currentPreviewData.htmlContent);
    formData.append('plainContent', currentPreviewData.plainContent);
    formData.append('subject', currentPreviewData.subject);
    
    fetch('email_admin.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
        } else {
            alert('Error: ' + (data.error || 'Failed to send preview email'));
        }
    })
    .catch(error => {
        alert('Error sending preview: ' + error.message);
    })
    .finally(() => {
        button.disabled = false;
        button.textContent = 'Send Preview';
    });
}

// Close modal when clicking outside of it
window.onclick = function(event) {
    const modal = document.getElementById('emailPreviewModal');
    if (event.target == modal) {
        closeEmailPreview();
    }
}
</script>

<?php include 'templates/footer.php'; ?>
