<?php
// admin_modules/email_admin.php

// Ensure session is started if not already
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Determine the correct base path for includes
$basePath = __DIR__ . '/../'; // From admin_modules/ to project root

// Include necessary core files
require_once $basePath . 'config.php'; // For EMAIL_ constants and other global settings
require_once $basePath . 'db.php';
require_once $basePath . 'auth.php';

// Initialize database connection
$pdo = get_db_connection();

// Authentication and User Context
// These are typically set by admin.php before including this module.
// If $user is not set, it means this script might be accessed directly or admin.php context is missing.
if (!isset($user) || !$user) {
    requireAuth(); // This will redirect to login if not authenticated
    $user = getCurrentUser(); // Fetch user details
}
$userRole = $user['role'] ?? null;


// Station determination logic (consistent with other modules)
// $currentStation is expected to be set by admin.php.
// For clarity within this module, we can use $station as an alias.
if (!isset($currentStation) || !$currentStation) {
    // This block tries to determine station if not already provided by admin.php
    // This is a fallback and ideally admin.php should always provide $currentStation
    $current_station_id_module = null;
    $current_station_name_module = "No station selected";

    if ($userRole === 'superuser') {
        $stationData = getCurrentStation(); // Uses $pdo internally
        if ($stationData && isset($stationData['id'])) {
            $currentStation = $stationData; // Set $currentStation if found
        }
    } elseif ($userRole === 'station_admin') {
        $userStationsForModule = [];
        try {
            if (isset($user['id'])) {
                $stmt_ua = $pdo->prepare("SELECT s.id, s.name, s.description FROM stations s JOIN user_stations us ON s.id = us.station_id WHERE us.user_id = ? ORDER BY s.name");
                $stmt_ua->execute([$user['id']]);
                $userStationsForModule = $stmt_ua->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) { /* log error */ }

        if (count($userStationsForModule) === 1) {
            $currentStation = $userStationsForModule[0];
             if (session_status() == PHP_SESSION_ACTIVE && (!isset($_SESSION['selected_station_id']) || $_SESSION['selected_station_id'] != $currentStation['id']) ) {
                $_SESSION['selected_station_id'] = $currentStation['id'];
            }
        } elseif (isset($_SESSION['selected_station_id'])) {
            foreach ($userStationsForModule as $s_mod) {
                if ($s_mod['id'] == $_SESSION['selected_station_id']) {
                    $currentStation = $s_mod;
                    break;
                }
            }
        }
    }
}
// Use $currentStation if available from admin.php, otherwise it might be null or set by above fallback
$station = $currentStation;


// Ensure composer autoload for PHPMailer is available
if (!class_exists(PHPMailer\PHPMailer\PHPMailer::class)) {
    if (file_exists($basePath . 'vendor/autoload.php')) {
        require_once $basePath . 'vendor/autoload.php';
    } else {
        echo "<div class='alert alert-danger'>PHPMailer library not found. Please run 'composer install'. Path tried: " . htmlspecialchars($basePath . 'vendor/autoload.php') . "</div>";
        return;
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!$station) {
    echo "<div class='notice notice-warning' style='margin:20px;'><p><i>⚠️</i> Please select a station to manage its email settings.</p>";
     if ($userRole === 'superuser') {
        echo "<p>As a superuser, you can select a station using the dropdown in the sidebar header.</p>";
    } elseif ($userRole === 'station_admin') {
         echo "<p>As a station admin, please ensure a single station is active or selected. If you manage multiple stations, pick one. If you manage none, please contact a superuser.</p>";
    }
    echo "</div>";
    return; // Exit if no station context
}

$station_id = $station['id'];
$station_name = $station['name'];

// Check for sub-actions (internal AJAX calls for preview and send_preview)
$sub_action = $_REQUEST['sub_action'] ?? null; // Comes from admin.php's GET/POST params

if ($sub_action === 'preview') {
    header('Content-Type: application/json');
    
    // Add basic error logging for debugging
    error_log("Email admin preview: Starting preview generation for station_id: " . ($station_id ?? 'null'));
    
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
        $latestCheckStmt = $pdo->prepare($latestCheckQuery);
        $latestCheckStmt->execute(['station_id' => $station_id]);
        $latestCheckResult = $latestCheckStmt->fetch(PDO::FETCH_ASSOC);
        $latestCheckDate = $latestCheckResult ? $latestCheckResult['the_date'] : 'No checks found';

        // Fetch missing items
        $checksQuery = "
            WITH LatestChecks AS (
                SELECT c.locker_id, MAX(c.id) AS latest_check_id
                FROM checks c
                JOIN lockers l ON c.locker_id = l.id
                JOIN trucks t ON l.truck_id = t.id
                WHERE c.check_date BETWEEN DATE_SUB(NOW(), INTERVAL 6 DAY) AND NOW() AND t.station_id = :station_id1
                GROUP BY c.locker_id
            )
            SELECT t.name as truck_name, l.name as locker_name, i.name as item_name, 
                   CONVERT_TZ(c.check_date, '+00:00', '+12:00') AS check_date,
                   cn.note as notes, c.checked_by
            FROM checks c
            JOIN LatestChecks lc ON c.id = lc.latest_check_id
            JOIN check_items ci ON c.id = ci.check_id
            JOIN lockers l ON c.locker_id = l.id
            JOIN trucks t ON l.truck_id = t.id
            JOIN items i ON ci.item_id = i.id
            LEFT JOIN check_notes cn ON c.id = cn.check_id
            WHERE ci.is_present = 0 AND t.station_id = :station_id2
            ORDER BY t.name, l.name";
        $checksStmt = $pdo->prepare($checksQuery);
        $checksStmt->execute(['station_id1' => $station_id, 'station_id2' => $station_id]);
        $checks = $checksStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $deletedItemsQuery = $pdo->prepare("
            SELECT truck_name, locker_name, item_name, CONVERT_TZ(deleted_at, '+00:00', '+12:00') AS deleted_at
            FROM locker_item_deletion_log WHERE deleted_at >= NOW() - INTERVAL 7 DAY AND station_id = :station_id ORDER BY deleted_at DESC");
        $deletedItemsQuery->execute(['station_id' => $station_id]);
        $deletedItems = $deletedItemsQuery->fetchAll(PDO::FETCH_ASSOC);

        $allNotesQuery = $pdo->prepare("
            SELECT t.name as truck_name, l.name as locker_name, cn.note as note_text,
                   CONVERT_TZ(c.check_date, '+00:00', '+12:00') AS check_date, c.checked_by
            FROM check_notes cn
            JOIN checks c ON cn.check_id = c.id
            JOIN lockers l ON c.locker_id = l.id
            JOIN trucks t ON l.truck_id = t.id
            WHERE c.check_date BETWEEN DATE_SUB(NOW(), INTERVAL 6 DAY) AND NOW() AND t.station_id = :station_id AND TRIM(cn.note) != ''
            ORDER BY t.name, l.name, c.check_date DESC");
        $allNotesQuery->execute(['station_id' => $station_id]);
        $allNotes = $allNotesQuery->fetchAll(PDO::FETCH_ASSOC);

        $emailQuery = "SELECT email FROM email_addresses WHERE station_id = :station_id OR station_id IS NULL";
        $emailStmt = $pdo->prepare($emailQuery);
        $emailStmt->execute(['station_id' => $station_id]);
        $emails = $emailStmt->fetchAll(PDO::FETCH_COLUMN);
        $adminEmailQuery = "SELECT setting_value FROM station_settings WHERE setting_key = 'admin_email' AND station_id = :station_id";
        $adminEmailStmt = $pdo->prepare($adminEmailQuery);
        $adminEmailStmt->execute(['station_id' => $station_id]);
        $adminEmail = $adminEmailStmt->fetchColumn();
        if ($adminEmail) $emails[] = $adminEmail;
        $emails = array_unique(array_filter($emails));

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'];
        $script_dir_for_email = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'); // Path to dir containing admin.php
        $web_root_url = $protocol . $host . $script_dir_for_email; 
        $system_access_url = $web_root_url . '/index.php?station_id=' . $station_id;

        $htmlContent = "<html><head><style>body { font-family: Arial, sans-serif; } .header { background-color: #12044C; color: white; padding: 10px; text-align: center; } .section { margin: 10px 0; padding: 10px; border: 1px solid #eee; } .missing-items { background-color: #fff3cd; } .deleted-items { background-color: #f8d7da; } .notes-section { background-color: #e7f3ff; }</style></head><body>";
        $htmlContent .= "<div class='header'><h1>TruckChecks Report - " . htmlspecialchars($station_name) . "</h1><p>Latest Check Date: " . htmlspecialchars($latestCheckDate) . "</p></div>";
        $plainContent = "TruckChecks Report - " . htmlspecialchars($station_name) . "\nLatest Check Date: " . htmlspecialchars($latestCheckDate) . "\n\n";

        if (!empty($checks)) {
            $htmlContent .= "<div class='section missing-items'><h2>Missing Items</h2>";
            $plainContent .= "MISSING ITEMS:\n";
            foreach ($checks as $check) {
                $htmlContent .= "<div>Truck: " . htmlspecialchars($check['truck_name']) . ", Locker: " . htmlspecialchars($check['locker_name']) . ", Item: " . htmlspecialchars($check['item_name']) . (empty(trim($check['notes'])) ? "" : ", Notes: ".htmlspecialchars(trim($check['notes']))) ."</div>";
                $plainContent .= "- Truck: " . htmlspecialchars($check['truck_name']) . ", Locker: " . htmlspecialchars($check['locker_name']) . ", Item: " . htmlspecialchars($check['item_name']) . (empty(trim($check['notes'])) ? "" : ", Notes: ".htmlspecialchars(trim($check['notes']))) ."\n";
            }
            $htmlContent .= "</div>";
        } else {
            $htmlContent .= "<div class='section'><p>No missing items found.</p></div>";
            $plainContent .= "No missing items found.\n";
        }
        if (!empty($deletedItems)) {
            $htmlContent .= "<div class='section deleted-items'><h2>Deleted Items</h2>";
            $plainContent .= "\nDELETED ITEMS:\n";
            foreach ($deletedItems as $item) {
                $htmlContent .= "<div>Truck: " . htmlspecialchars($item['truck_name']) . ", Locker: " . htmlspecialchars($item['locker_name']) . ", Item: " . htmlspecialchars($item['item_name']) . "</div>";
                $plainContent .= "- Truck: " . htmlspecialchars($item['truck_name']) . ", Locker: " . htmlspecialchars($item['locker_name']) . ", Item: " . htmlspecialchars($item['item_name']) . "\n";
            }
            $htmlContent .= "</div>";
        }
        if (!empty($allNotes)) {
            $htmlContent .= "<div class='section notes-section'><h2>All Locker Notes</h2>";
            $plainContent .= "\nALL LOCKER NOTES:\n";
            foreach ($allNotes as $note) {
                $htmlContent .= "<div>Truck: " . htmlspecialchars($note['truck_name']) . ", Locker: " . htmlspecialchars($note['locker_name']) . ", Note: " . htmlspecialchars(trim($note['note_text'])) . "</div>";
                $plainContent .= "- Truck: " . htmlspecialchars($note['truck_name']) . ", Locker: " . htmlspecialchars($note['locker_name']) . ", Note: " . htmlspecialchars(trim($note['note_text'])) . "\n";
            }
            $htmlContent .= "</div>";
        }
        $htmlContent .= "<div style='margin-top:20px; padding:10px; background-color:#f0f0f0; text-align:center;'><p><a href='" . htmlspecialchars($system_access_url) . "'>Access TruckChecks System</a></p><p>Report for station: " . htmlspecialchars($station_name) . "</p></div></body></html>";
        $plainContent .= "\nAccess System: " . htmlspecialchars($system_access_url) . "\nReport for station: " . htmlspecialchars($station_name) . "\n";

        echo json_encode([
            'success' => true,
            'htmlContent' => $htmlContent,
            'plainContent' => $plainContent,
            'emails' => array_values($emails),
            'subject' => "TruckChecks Report - " . $station_name . " - {$latestCheckDate}"
        ]);
    } catch (Exception $e) {
        error_log("Error in email_admin.php preview sub_action: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($sub_action === 'send_preview') {
    header('Content-Type: application/json');
    $previewEmail = $_POST['email'] ?? '';
    $htmlContent = $_POST['htmlContent'] ?? '';
    $plainContent = $_POST['plainContent'] ?? '';
    $subject = $_POST['subject'] ?? '';

    if (!empty($previewEmail) && filter_var($previewEmail, FILTER_VALIDATE_EMAIL)) {
        try {
            if (!defined('EMAIL_HOST') || !defined('EMAIL_USER') || !defined('EMAIL_PASS') || !defined('EMAIL_PORT')) {
                throw new Exception('Email configuration is not set up in config.php');
            }
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = EMAIL_HOST; 
            $mail->SMTPAuth = true;
            $mail->Username = EMAIL_USER; 
            $mail->Password = EMAIL_PASS; 
            $mail->SMTPSecure = defined('EMAIL_SMTP_SECURE') ? EMAIL_SMTP_SECURE : "ssl";
            $mail->Port = EMAIL_PORT;
            
            $from_email = filter_var(EMAIL_USER, FILTER_VALIDATE_EMAIL) ? EMAIL_USER : 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'truckchecks.com');
            $mail->setFrom($from_email, 'TruckChecks - ' . $station_name);
            $mail->addAddress($previewEmail);
            
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlContent;
            $mail->AltBody = $plainContent;
            
            $mail->send();
            echo json_encode(['success' => true, 'message' => "Preview email sent successfully to " . htmlspecialchars($previewEmail)]);
        } catch (Exception $e) {
            error_log("Error in email_admin.php send_preview sub_action: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => "Failed to send preview email: " . $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => "Please enter a valid email address."]);
    }
    exit;
}

// --- Main component logic for display and regular form submissions ---
// No need to include config.php again, it's done at the top.

// Handle adding multiple email addresses
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_emails'])) {
    if(defined('DEBUG') && DEBUG) { error_log("Email_admin.php: Processing add_emails. POST data: " . print_r($_POST, true)); }
    $email_list = $_POST['email_list'];
    if (!empty($email_list)) {
        try {
            $emails_to_add = preg_split('/[,\n\r]+/', $email_list);
            $valid_emails = []; $invalid_emails = [];
            foreach ($emails_to_add as $email_item) {
                $email_item = trim($email_item);
                if (!empty($email_item)) {
                    if (filter_var($email_item, FILTER_VALIDATE_EMAIL)) $valid_emails[] = $email_item;
                    else $invalid_emails[] = $email_item;
                }
            }
            if (!empty($valid_emails)) {
                $added_count = 0;
                foreach ($valid_emails as $email_item) {
                    $stmt_check = $pdo->prepare('SELECT COUNT(*) FROM email_addresses WHERE email = ? AND station_id = ?');
                    $stmt_check->execute([$email_item, $station_id]);
                    if ($stmt_check->fetchColumn() == 0) {
                        $stmt_insert = $pdo->prepare('INSERT INTO email_addresses (email, station_id) VALUES (?, ?)');
                        $stmt_insert->execute([$email_item, $station_id]);
                        $added_count++;
                    }
                }
                $success_message = "Added {$added_count} email address(es) successfully for " . htmlspecialchars($station_name) . "!";
                if (!empty($invalid_emails)) $success_message .= " Invalid emails skipped: " . implode(', ', array_map('htmlspecialchars', $invalid_emails));
                if(defined('DEBUG') && DEBUG) { error_log("Email_admin.php: add_emails success: " . $success_message); }
            } else {
                $error_message = "No valid email addresses found. Invalid emails: " . implode(', ', array_map('htmlspecialchars', $invalid_emails));
                if(defined('DEBUG') && DEBUG) { error_log("Email_admin.php: add_emails error (no valid emails): " . $error_message); }
            }
        } catch (Exception $e) { 
            $error_message = "Error adding email addresses: " . $e->getMessage(); 
            if(defined('DEBUG') && DEBUG) { error_log("Email_admin.php: add_emails exception: " . $e->getMessage()); }
        }
    } else { 
        $error_message = "Please enter at least one email address."; 
        if(defined('DEBUG') && DEBUG) { error_log("Email_admin.php: add_emails error (empty list): " . $error_message); }
    }
}

// Handle removing email address
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['remove_email'])) {
    if(defined('DEBUG') && DEBUG) { error_log("Email_admin.php: Processing remove_email. POST data: " . print_r($_POST, true)); }
    $email_to_remove = $_POST['email_to_remove'];
    try {
        $stmt = $pdo->prepare('DELETE FROM email_addresses WHERE email = ? AND station_id = ?');
        $stmt->execute([$email_to_remove, $station_id]);
        $success_message = "Email address '" . htmlspecialchars($email_to_remove) . "' removed successfully!";
        if(defined('DEBUG') && DEBUG) { error_log("Email_admin.php: remove_email success: " . $success_message); }
    } catch (Exception $e) { 
        $error_message = "Error removing email address: " . $e->getMessage(); 
        if(defined('DEBUG') && DEBUG) { error_log("Email_admin.php: remove_email exception: " . $e->getMessage()); }
    }
}

// Handle test email sending
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_test_email'])) {
    if(defined('DEBUG') && DEBUG) { error_log("Email_admin.php: Processing send_test_email. POST data: " . print_r($_POST, true)); }
    $test_email_addr = $_POST['test_email'];
    if (!empty($test_email_addr) && filter_var($test_email_addr, FILTER_VALIDATE_EMAIL)) {
        try {
            if (!defined('EMAIL_HOST') || !defined('EMAIL_USER') || !defined('EMAIL_PASS') || !defined('EMAIL_PORT')) {
                throw new Exception('Email configuration is not set up in config.php');
            }
            $mail = new PHPMailer(true);
            // if (defined('DEBUG') && DEBUG) { $mail->SMTPDebug = 2; $mail->Debugoutput = 'error_log'; } // Enable for deep PHPMailer debugging
            $mail->isSMTP();
            $mail->Host = EMAIL_HOST; $mail->SMTPAuth = true; $mail->Username = EMAIL_USER; $mail->Password = EMAIL_PASS;
            $mail->SMTPSecure = defined('EMAIL_SMTP_SECURE') ? EMAIL_SMTP_SECURE : "ssl"; $mail->Port = EMAIL_PORT;
            $from_email = filter_var(EMAIL_USER, FILTER_VALIDATE_EMAIL) ? EMAIL_USER : 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'truckchecks.com');
            $mail->setFrom($from_email, 'TruckChecks System');
            $mail->addAddress($test_email_addr);
            $mail->isHTML(true); $mail->Subject = 'TruckChecks Test Email - ' . $station_name;
            $mail->Body = '<h2>TruckChecks Test Email</h2><p>This is a test email for station: ' . htmlspecialchars($station_name) . '.</p><p>Sent by: ' . htmlspecialchars($user['username']) . '</p>';
            $mail->send();
            $test_success_message = "Test email sent successfully to " . htmlspecialchars($test_email_addr) . "!";
            if(defined('DEBUG') && DEBUG) { error_log("Email_admin.php: send_test_email success: " . $test_success_message); }
        } catch (Exception $e) { 
            $test_error_message = "Failed to send test email: " . $e->getMessage() . (isset($mail) ? " Mailer Error: " . $mail->ErrorInfo : ""); 
            if(defined('DEBUG') && DEBUG) { error_log("Email_admin.php: send_test_email exception: " . $test_error_message); }
        }
    } else { 
        $test_error_message = "Please enter a valid email address for testing."; 
        if(defined('DEBUG') && DEBUG) { error_log("Email_admin.php: send_test_email error (invalid address): " . $test_error_message); }
    }
}

// Handle setting admin email
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['set_admin_email'])) {
    if(defined('DEBUG') && DEBUG) { error_log("Email_admin.php: Processing set_admin_email. POST data: " . print_r($_POST, true)); }
    $admin_email_val = $_POST['admin_email'];
    if (!empty($admin_email_val) && filter_var($admin_email_val, FILTER_VALIDATE_EMAIL)) {
        try {
            $stmt_check = $pdo->prepare('SELECT COUNT(*) FROM station_settings WHERE setting_key = "admin_email" AND station_id = ?');
            $stmt_check->execute([$station_id]);
            if ($stmt_check->fetchColumn() > 0) {
                $stmt_update = $pdo->prepare('UPDATE station_settings SET setting_value = ? WHERE setting_key = "admin_email" AND station_id = ?');
                $stmt_update->execute([$admin_email_val, $station_id]);
            } else {
                $stmt_insert = $pdo->prepare('INSERT INTO station_settings (setting_key, setting_value, station_id, setting_type, description) VALUES ("admin_email", ?, ?, "string", "Admin email for station")');
                $stmt_insert->execute([$admin_email_val, $station_id]);
            }
            $success_message = "Admin email set successfully for " . htmlspecialchars($station_name) . "!";
            if(defined('DEBUG') && DEBUG) { error_log("Email_admin.php: set_admin_email success: " . $success_message); }
        } catch (Exception $e) { 
            $error_message = "Error setting admin email: " . $e->getMessage(); 
            if(defined('DEBUG') && DEBUG) { error_log("Email_admin.php: set_admin_email exception: " . $e->getMessage()); }
        }
    } else { 
        $error_message = "Please enter a valid admin email address."; 
        if(defined('DEBUG') && DEBUG) { error_log("Email_admin.php: set_admin_email error (invalid address): " . $error_message); }
    }
}

if(defined('DEBUG') && DEBUG && $_SERVER['REQUEST_METHOD'] == 'POST') {
    error_log("Email_admin.php: Finished all POST processing blocks.");
}

// Get current email settings for display
$current_admin_email_display = null;
$current_station_emails_display = [];
try {
    $stmt_admin = $pdo->prepare('SELECT setting_value FROM station_settings WHERE setting_key = "admin_email" AND station_id = ?');
    $stmt_admin->execute([$station_id]);
    $current_admin_email_display = $stmt_admin->fetchColumn();
    
    $stmt_list = $pdo->prepare('SELECT email FROM email_addresses WHERE station_id = ? ORDER BY email');
    $stmt_list->execute([$station_id]);
    $current_station_emails_display = $stmt_list->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) { /* Ignore for display */ }

$email_is_configured = defined('EMAIL_HOST') && defined('EMAIL_USER') && defined('EMAIL_PASS') && defined('EMAIL_PORT');

?>
<div class="component-container email-admin-container">
    <style>
        /* Styles specific to email_admin.php component */
        .email-admin-container { max-width: 800px; margin: 0 auto; padding: 20px; }
        /* ... (rest of CSS from original file) ... */
        .modal { display: none; position: fixed; z-index: 1050; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4); }
        .modal-content { background-color: #fefefe; margin: 5% auto; padding: 0; border: 1px solid #888; width: 90%; max-width: 800px; border-radius: 5px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .modal-header { padding: 15px 20px; background-color: #12044C; color: white; border-bottom: 1px solid #dee2e6; border-radius: 5px 5px 0 0;}
        .modal-header h2 { margin: 0; font-size: 1.3em; }
        .modal-body { padding: 20px; max-height: 60vh; overflow-y: auto; }
        .modal-footer { padding: 15px 20px; background-color: #f8f9fa; border-top: 1px solid #dee2e6; text-align: right; border-radius: 0 0 5px 5px;}
        .close-btn-modal { color: white; float: right; font-size: 24px; font-weight: bold; cursor: pointer; }
        .preview-tabs { display: flex; border-bottom: 1px solid #ccc; margin-bottom: 10px; }
        .preview-tab { padding: 8px 12px; cursor: pointer; border: 1px solid transparent; border-bottom: none; }
        .preview-tab.active { border-color: #ccc; border-bottom-color: white; background-color: white; margin-bottom: -1px; }
        .preview-content { display: none; } .preview-content.active { display: block; }
        .preview-html { border:1px solid #eee; padding:10px; min-height:200px; background:#fff; }
        .preview-plain { white-space:pre-wrap; font-family:monospace; border:1px solid #eee; padding:10px; min-height:200px; background:#fff; }
        .email-recipients-modal { margin-top:15px; padding:10px; background-color:#f0f8ff; border:1px solid #ddeeff; font-size:0.9em; }
    </style>

    <div class="page-header-ea">
        <h1 class="page-title-ea">Manage Email Settings</h1>
    </div>

    <div class="station-info-ea">
        <strong>Station: <?= htmlspecialchars($station_name) ?></strong>
    </div>

    <?php if (!$email_is_configured): ?>
        <div class="message-ea warning-message-ea">
            <strong>Warning:</strong> Email sending is not configured in `config.php`. Please set EMAIL_HOST, EMAIL_USER, EMAIL_PASS, and EMAIL_PORT.
        </div>
    <?php endif; ?>

    <?php if (isset($success_message)): ?><div class="message-ea success-message-ea"><?= htmlspecialchars($success_message) ?></div><?php endif; ?>
    <?php if (isset($error_message)): ?><div class="message-ea error-message-ea"><?= htmlspecialchars($error_message) ?></div><?php endif; ?>
    <?php if (isset($test_success_message)): ?><div class="message-ea success-message-ea"><?= htmlspecialchars($test_success_message) ?></div><?php endif; ?>
    <?php if (isset($test_error_message)): ?><div class="message-ea error-message-ea"><?= htmlspecialchars($test_error_message) ?></div><?php endif; ?>

    <div class="form-section-ea">
        <h2>Email Recipients for Reports</h2>
        <?php if (!empty($current_station_emails_display)): ?>
            <ul class="email-list-ea">
                <?php foreach ($current_station_emails_display as $email_disp): ?>
                    <li>
                        <span><?= htmlspecialchars($email_disp) ?></span>
                        <form method="POST" action="admin.php?page=admin_modules/email_admin.php" style="display: inline;">
                            <input type="hidden" name="remove_email" value="1">
                            <input type="hidden" name="email_to_remove" value="<?= htmlspecialchars($email_disp) ?>">
                            <button type="submit" class="button-ea danger remove-btn" onclick="return confirm('Remove this email?')">Remove</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p>No specific email addresses configured for this station's reports yet.</p>
        <?php endif; ?>
        
        <form method="POST" action="admin.php?page=admin_modules/email_admin.php" style="margin-top:15px;">
            <input type="hidden" name="add_emails" value="1">
            <div class="input-container-ea">
                <div><label for="email_list_input">Add Email Addresses (comma or new line separated):</label></div>
                <div><textarea name="email_list" id="email_list_input" placeholder="e.g., manager@example.com, team@example.com" style="width: 100%; min-height: 80px;"></textarea></div>
            </div>
            <button type="submit" class="button-ea">Add Addresses</button>
        </form>
    </div>

    <div class="form-section-ea">
        <h2>Primary Admin Email for Station</h2>
        <p>This email might be used for critical station-specific alerts (if implemented).</p>
        Current: <strong><?= htmlspecialchars($current_admin_email_display ?? 'Not Set') ?></strong>
        <form method="POST" action="admin.php?page=admin_modules/email_admin.php" style="margin-top:10px;">
            <input type="hidden" name="set_admin_email" value="1">
            <div class="input-container-ea">
                <label for="admin_email_input">Set/Update Primary Admin Email:</label>
                <input type="email" name="admin_email" id="admin_email_input" value="<?= htmlspecialchars($current_admin_email_display ?? '') ?>" placeholder="station.admin@example.com">
            </div>
            <button type="submit" class="button-ea">Save Admin Email</button>
        </form>
    </div>
    
    <div class="form-section-ea">
        <h2>Preview & Test Email Report</h2>
        <button type="button" class="button-ea preview-btn" onclick="showEmailPreviewModal()" <?= $email_is_configured ? '' : 'disabled' ?>>Preview Report Email</button>
        <p style="font-size:0.9em; color:#666;">Generates a preview of the standard check results email for this station.</p>
    </div>

    <?php if ($email_is_configured): ?>
    <div class="form-section-ea">
        <h2>Test SMTP Configuration</h2>
        <form method="POST" action="admin.php?page=admin_modules/email_admin.php">
            <input type="hidden" name="send_test_email" value="1">
            <div class="input-container-ea">
                <label for="test_email_input">Send Test Email To:</label>
                <input type="email" name="test_email" id="test_email_input" value="<?= htmlspecialchars($user['email'] ?? $current_admin_email_display ?? '') ?>" required>
            </div>
            <button type="submit" class="button-ea test-btn">Send Test SMTP Email</button>
        </form>
    </div>
    <?php endif; ?>

    <div id="emailPreviewModalComponent" class="modal">
        <!-- ... (modal HTML from original file) ... -->
         <div class="modal-content">
            <div class="modal-header">
                <span class="close-btn-modal" onclick="closeEmailPreviewModal()">&times;</span>
                <h2>Email Report Preview</h2>
            </div>
            <div class="modal-body">
                <div class="preview-tabs">
                    <div id="tab_html" class="preview-tab active" onclick="switchPreviewTabModal('html')">HTML</div>
                    <div id="tab_plain" class="preview-tab" onclick="switchPreviewTabModal('plain')">Plain Text</div>
                </div>
                <div id="content_html" class="preview-content active"><div class="preview-html">Loading HTML...</div></div>
                <div id="content_plain" class="preview-content"><pre class="preview-plain">Loading Plain Text...</pre></div>
                <div class="email-recipients-modal">
                    <strong>Intended Recipients (for actual report):</strong>
                    <div id="recipientsListModal" style="font-size:0.9em; max-height:100px; overflow-y:auto;">Loading...</div>
                </div>
            </div>
            <div class="modal-footer">
                <input type="email" id="previewSendToEmailInput" placeholder="Send this preview to..." style="padding: 8px; width: 250px; margin-right: 10px; border:1px solid #ccc; border-radius:3px;">
                <button class="button-ea test-btn" onclick="sendPreviewEmailModal()">Send This Preview</button>
                <button class="button-ea" onclick="closeEmailPreviewModal()" style="background-color:#6c757d;">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
let emailPreviewModalData = null; // Keep this global for the component instance

function showEmailPreviewModal() {
    document.getElementById('emailPreviewModalComponent').style.display = 'block';
    // ... (rest of showEmailPreviewModal logic) ...
    // IMPORTANT: Update fetch URL
    fetch('admin.php?ajax=1&page=admin_modules/email_admin.php&sub_action=preview')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                emailPreviewModalData = data; // Store data globally for this component instance
                document.querySelector('#emailPreviewModalComponent .preview-html').innerHTML = data.htmlContent;
                document.querySelector('#emailPreviewModalComponent .preview-plain').textContent = data.plainContent;
                const recipientsDiv = document.getElementById('recipientsListModal');
                recipientsDiv.innerHTML = data.emails && data.emails.length > 0 ? data.emails.join('<br>') : '<em>No recipients.</em>';
                document.getElementById('previewSendToEmailInput').value = '<?= htmlspecialchars($user['email'] ?? '') ?>' || (data.emails && data.emails.length > 0 ? data.emails[0] : '');
            } else { /* ... error handling ... */ }
        })
        .catch(error => { /* ... error handling ... */ });
}

function closeEmailPreviewModal() {
    document.getElementById('emailPreviewModalComponent').style.display = 'none';
}

function switchPreviewTabModal(tabName) {
    // ... (switchPreviewTabModal logic) ...
}

function sendPreviewEmailModal() {
    const emailToSendTo = document.getElementById('previewSendToEmailInput').value;
    if (!emailToSendTo || !emailPreviewModalData) { /* ... alert ... */ return; }
    const sendButton = event.target;
    sendButton.disabled = true; sendButton.textContent = 'Sending...';

    const formData = new FormData();
    formData.append('sub_action', 'send_preview');
    formData.append('email', emailToSendTo);
    formData.append('htmlContent', emailPreviewModalData.htmlContent);
    formData.append('plainContent', emailPreviewModalData.plainContent);
    formData.append('subject', emailPreviewModalData.subject + " (PREVIEW)");

    // IMPORTANT: Update fetch URL
    fetch('admin.php?ajax=1&page=admin_modules/email_admin.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => { /* ... success/error handling ... */ })
    .catch(error => { /* ... error handling ... */ })
    .finally(() => { sendButton.disabled = false; sendButton.textContent = 'Send This Preview'; });
}

document.addEventListener('keydown', function(event) {
    if (event.key === "Escape") {
        if (document.getElementById('emailPreviewModalComponent').style.display === 'block') {
            closeEmailPreviewModal();
        }
    }
});
</script>
