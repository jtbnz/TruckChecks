<?php
// This page is now a component loaded by admin.php
// It expects $pdo, $user, $userRole, $currentStation to be available.

// Ensure composer autoload is available
if (!class_exists(PHPMailer\PHPMailer\PHPMailer::class)) {
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
    } elseif (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
        require_once dirname(__DIR__) . '/vendor/autoload.php';
    } else {
        echo "<div class='alert alert-danger'>PHPMailer library not found. Please run 'composer install'.</div>";
        return;
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// $currentStation is provided by admin.php
if (!$currentStation) {
    echo "<div class='alert alert-warning'>Please select a station to view/send email results.</div>";
    return; 
}

$station_id = $currentStation['id'];
$station_name = $currentStation['name'];

// Ensure config.php is loaded for EMAIL_ constants
$config_path = __DIR__ . '/config.php';
if (file_exists($config_path)) {
    include_once($config_path);
}
$email_is_configured = defined('EMAIL_HOST') && defined('EMAIL_USER') && defined('EMAIL_PASS') && defined('EMAIL_PORT');

// Function to fetch data and prepare email content (to avoid duplication)
function prepare_email_data_and_content($pdo_conn, $current_station_details, $current_user_details) {
    $station_id_func = $current_station_details['id'];
    $station_name_func = $current_station_details['name'];

    // Fetch latest check date
    $latestCheckQuery = "SELECT DISTINCT DATE(CONVERT_TZ(c.check_date, '+00:00', '+12:00')) as the_date FROM checks c JOIN lockers l ON c.locker_id = l.id JOIN trucks t ON l.truck_id = t.id WHERE t.station_id = :station_id ORDER BY c.check_date DESC LIMIT 1";
    $latestCheckStmt = $pdo_conn->prepare($latestCheckQuery);
    $latestCheckStmt->execute(['station_id' => $station_id_func]);
    $latestCheckDate = ($res = $latestCheckStmt->fetch(PDO::FETCH_ASSOC)) ? $res['the_date'] : 'No checks found';

    // Fetch missing items
    $checksQuery = "
        WITH LatestChecks AS (
            SELECT c.locker_id, MAX(c.id) AS latest_check_id FROM checks c
            JOIN lockers l ON c.locker_id = l.id JOIN trucks t ON l.truck_id = t.id
            WHERE c.check_date BETWEEN DATE_SUB(NOW(), INTERVAL 6 DAY) AND NOW() AND t.station_id = :station_id1 GROUP BY c.locker_id
        )
        SELECT t.name as truck_name, l.name as locker_name, i.name as item_name, 
               CONVERT_TZ(c.check_date, '+00:00', '+12:00') AS check_date,
               cn.note as notes, c.checked_by
        FROM checks c JOIN LatestChecks lc ON c.id = lc.latest_check_id
        JOIN check_items ci ON c.id = ci.check_id JOIN lockers l ON c.locker_id = l.id
        JOIN trucks t ON l.truck_id = t.id JOIN items i ON ci.item_id = i.id
        LEFT JOIN check_notes cn on c.id = cn.check_id AND l.id = cn.locker_id
        WHERE ci.is_present = 0 AND t.station_id = :station_id2 ORDER BY t.name, l.name";
    $checksStmt = $pdo_conn->prepare($checksQuery);
    $checksStmt->execute(['station_id1' => $station_id_func, 'station_id2' => $station_id_func]);
    $missing_items_data = $checksStmt->fetchAll(PDO::FETCH_ASSOC);

    // Deleted items
    $deletedItemsQuery = $pdo_conn->prepare("SELECT truck_name, locker_name, item_name, CONVERT_TZ(deleted_at, '+00:00', '+12:00') AS deleted_at FROM locker_item_deletion_log WHERE deleted_at >= NOW() - INTERVAL 7 DAY AND station_id = :station_id ORDER BY deleted_at DESC");
    $deletedItemsQuery->execute(['station_id' => $station_id_func]);
    $deleted_items_data = $deletedItemsQuery->fetchAll(PDO::FETCH_ASSOC);

    // All notes
    $allNotesQuery = $pdo_conn->prepare("SELECT t.name as truck_name, l.name as locker_name, cn.note as note_text, CONVERT_TZ(c.check_date, '+00:00', '+12:00') AS check_date, c.checked_by FROM check_notes cn JOIN checks c ON cn.check_id = c.id JOIN lockers l ON c.locker_id = l.id JOIN trucks t ON l.truck_id = t.id WHERE c.check_date BETWEEN DATE_SUB(NOW(), INTERVAL 6 DAY) AND NOW() AND t.station_id = :station_id AND TRIM(cn.note) != '' ORDER BY t.name, l.name, c.check_date DESC");
    $allNotesQuery->execute(['station_id' => $station_id_func]);
    $all_notes_data = $allNotesQuery->fetchAll(PDO::FETCH_ASSOC);
    
    // Email recipients
    $emailQuery = "SELECT email FROM email_addresses WHERE station_id = :station_id OR station_id IS NULL"; // Includes global if station_id is NULL
    $emailStmt = $pdo_conn->prepare($emailQuery);
    $emailStmt->execute(['station_id' => $station_id_func]);
    $recipient_emails = $emailStmt->fetchAll(PDO::FETCH_COLUMN);
    $adminEmailQuery = "SELECT setting_value FROM station_settings WHERE setting_key = 'admin_email' AND station_id = :station_id";
    $adminEmailStmt = $pdo_conn->prepare($adminEmailQuery);
    $adminEmailStmt->execute(['station_id' => $station_id_func]);
    if ($adminEmail = $adminEmailStmt->fetchColumn()) $recipient_emails[] = $adminEmail;
    $recipient_emails = array_values(array_unique(array_filter($recipient_emails)));


    // Base URL for links in email
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $web_root_url = $protocol . $host;
    $system_access_url = $web_root_url . '/index.php?station_id=' . $station_id_func;

    // Generate HTML content
    $html = "<html><head><style>body{font-family:Arial,sans-serif;line-height:1.6;color:#333}.header{background-color:#12044C;color:white;padding:20px;text-align:center}.section{margin:20px 0;padding:15px;background-color:#f8f9fa;border-radius:5px}.missing-items{background-color:#fff3cd;border:1px solid #ffeaa7}.deleted-items{background-color:#f8d7da;border:1px solid #f5c6cb}.notes-section{background-color:#e7f3ff;border:1px solid #b3d9ff}.item-entry{margin:10px 0;padding:10px;background-color:white;border-radius:3px}.notes{font-style:italic;color:#666;margin-top:5px}.footer{margin-top:30px;padding:20px;background-color:#e9ecef;text-align:center;font-size:12px}.label{font-weight:bold;color:#12044C}</style></head><body>";
    $html .= "<div class='header'><h1>TruckChecks Report - " . htmlspecialchars($station_name_func) . "</h1><p>Latest Check Date: " . htmlspecialchars($latestCheckDate) . "</p></div>";
    
    // Plain text content
    $plain = "TruckChecks Report - " . htmlspecialchars($station_name_func) . "\nLatest Check Date: " . htmlspecialchars($latestCheckDate) . "\n\n";

    if (!empty($missing_items_data)) {
        $html .= "<div class='section missing-items'><h2>Missing Items (Last 7 Days)</h2>";
        $plain .= "MISSING ITEMS (Last 7 Days):\n";
        foreach ($missing_items_data as $item) {
            $html .= "<div class='item-entry'><div><span class='label'>Truck:</span> ".htmlspecialchars($item['truck_name'])."</div><div><span class='label'>Locker:</span> ".htmlspecialchars($item['locker_name'])."</div><div><span class='label'>Item:</span> ".htmlspecialchars($item['item_name'])."</div><div><span class='label'>Checked by:</span> ".htmlspecialchars($item['checked_by'])." at ".htmlspecialchars($item['check_date'])."</div>";
            if (!empty($item['notes']) && trim($item['notes']) !== '') {
                $html .= "<div class='notes'><span class='label'>Notes:</span> ".htmlspecialchars(trim($item['notes']))."</div>";
                $plain .= "  Notes: ".trim($item['notes'])."\n";
            }
            $html .= "</div>";
            $plain .= "- Truck: ".htmlspecialchars($item['truck_name']).", Locker: ".htmlspecialchars($item['locker_name']).", Item: ".htmlspecialchars($item['item_name']).", By: ".htmlspecialchars($item['checked_by'])." at ".htmlspecialchars($item['check_date'])."\n";
        }
        $html .= "</div>";
    } else {
        $html .= "<div class='section'><p>No missing items found in the last 7 days.</p></div>";
        $plain .= "No missing items found in the last 7 days.\n";
    }

    if (!empty($deleted_items_data)) {
        $html .= "<div class='section deleted-items'><h2>Deleted Items (Last 7 Days)</h2>";
        $plain .= "\nDELETED ITEMS (Last 7 Days):\n";
        foreach ($deleted_items_data as $item) {
            $html .= "<div class='item-entry'><div><span class='label'>Truck:</span> ".htmlspecialchars($item['truck_name'])."</div><div><span class='label'>Locker:</span> ".htmlspecialchars($item['locker_name'])."</div><div><span class='label'>Item:</span> ".htmlspecialchars($item['item_name'])."</div><div><span class='label'>Deleted at:</span> ".htmlspecialchars($item['deleted_at'])."</div></div>";
            $plain .= "- Truck: ".htmlspecialchars($item['truck_name']).", Locker: ".htmlspecialchars($item['locker_name']).", Item: ".htmlspecialchars($item['item_name']).", At: ".htmlspecialchars($item['deleted_at'])."\n";
        }
        $html .= "</div>";
    }

    if (!empty($all_notes_data)) {
        $html .= "<div class='section notes-section'><h2>Locker Check Notes (Last 7 Days)</h2>";
        $plain .= "\nLOCKER CHECK NOTES (Last 7 Days):\n";
        foreach ($all_notes_data as $note) {
            $html .= "<div class='item-entry'><div><span class='label'>Truck:</span> ".htmlspecialchars($note['truck_name'])."</div><div><span class='label'>Locker:</span> ".htmlspecialchars($note['locker_name'])."</div><div><span class='label'>Checked by:</span> ".htmlspecialchars($note['checked_by'])." at ".htmlspecialchars($note['check_date'])."</div><div class='notes'><span class='label'>Notes:</span> ".htmlspecialchars(trim($note['note_text']))."</div></div>";
            $plain .= "- Truck: ".htmlspecialchars($note['truck_name']).", Locker: ".htmlspecialchars($note['locker_name']).", By: ".htmlspecialchars($note['checked_by'])." at ".htmlspecialchars($note['check_date']).", Note: ".trim($note['note_text'])."\n";
        }
        $html .= "</div>";
    }

    $html .= "<div class='footer'><p><a href='".htmlspecialchars($system_access_url)."'>Access TruckChecks System</a></p><p>This report is for station: ".htmlspecialchars($station_name_func)."</p><p>Generated by: ".htmlspecialchars($current_user_details['username'])." (".htmlspecialchars($current_user_details['role']).")</p><p>Generated on: ".date('Y-m-d H:i:s')."</p></div></body></html>";
    $plain .= "\nAccess System: ".htmlspecialchars($system_access_url)."\nReport for station: ".htmlspecialchars($station_name_func)."\nGenerated by: ".htmlspecialchars($current_user_details['username'])." (".htmlspecialchars($current_user_details['role']).")\nGenerated on: ".date('Y-m-d H:i:s');

    return [
        'htmlContent' => $html,
        'plainContent' => $plain,
        'emails' => $recipient_emails,
        'subject' => "TruckChecks Report - " . $station_name_func . " - " . $latestCheckDate,
        'latestCheckDate' => $latestCheckDate // For display
    ];
}

$sub_action = $_REQUEST['sub_action'] ?? 'preview_page'; // Default to showing the preview page

if ($sub_action === 'send_final_report') {
    header('Content-Type: application/json');
    if (!$email_is_configured) {
        echo json_encode(['success' => false, 'error' => 'Email sending is not configured in config.php.']);
        exit;
    }

    $email_data = prepare_email_data_and_content($pdo, $currentStation, $user);

    if (empty($email_data['emails'])) {
        echo json_encode(['success' => false, 'error' => 'No recipients configured for this station.']);
        exit;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = EMAIL_HOST; 
        $mail->SMTPAuth = true;
        $mail->Username = EMAIL_USER; 
        $mail->Password = EMAIL_PASS; 
        $mail->SMTPSecure = defined('EMAIL_SMTP_SECURE') ? EMAIL_SMTP_SECURE : "ssl";
        $mail->Port = EMAIL_PORT;

        $from_email = filter_var(EMAIL_USER, FILTER_VALIDATE_EMAIL) ? EMAIL_USER : 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'truckchecks.com');
        $mail->setFrom($from_email, 'TruckChecks - ' . $station_name);
        foreach ($email_data['emails'] as $recipient_email) {
            $mail->addAddress($recipient_email);
        }

        $mail->isHTML(true);
        $mail->Subject = $email_data['subject'];
        $mail->Body = $email_data['htmlContent'];
        $mail->AltBody = $email_data['plainContent'];
        
        $mail->send();
        echo json_encode(['success' => true, 'message' => 'Report email sent successfully to ' . count($email_data['emails']) . ' recipient(s).']);
    } catch (Exception $e) {
        error_log("Mailer Error for station $station_id: " . $mail->ErrorInfo . " | Exception: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Message could not be sent. Mailer Error: ' . htmlspecialchars($mail->ErrorInfo)]);
    }
    exit;
}

// If not sending, then we are displaying the preview page.
$email_preview_data = prepare_email_data_and_content($pdo, $currentStation, $user);
$htmlContent_preview = $email_preview_data['htmlContent'];
$plainContent_preview = $email_preview_data['plainContent'];
$emails_preview = $email_preview_data['emails'];
$latestCheckDate_preview = $email_preview_data['latestCheckDate'];

?>
<div class="component-container email-results-container">
    <style>
        /* Styles specific to email_results.php component */
        .email-results-container { max-width: 800px; margin: 0 auto; padding: 20px; }
        .page-header-er { margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #ccc; }
        .page-title-er { color: #333; margin: 0; }
        .station-info-er { text-align: center; margin-bottom: 20px; padding: 10px; background-color: #f0f0f0; border-radius: 4px;}
        .email-preview-section { background-color: #f9f9f9; border: 1px solid #eee; border-radius: 4px; padding: 15px; margin-bottom: 20px; }
        .preview-tabs-er { display: flex; border-bottom: 1px solid #ccc; margin-bottom: 10px; }
        .preview-tab-er { padding: 8px 12px; cursor: pointer; border: 1px solid transparent; border-bottom: none; background-color: #e9ecef; }
        .preview-tab-er.active { background-color: #fff; border-color: #ccc; border-bottom-color: #fff; margin-bottom: -1px; }
        .preview-content-er { display: none; }
        .preview-content-er.active { display: block; }
        .preview-html-er { border:1px solid #ddd; padding:10px; min-height:200px; background:#fff; max-height: 400px; overflow-y: auto;}
        .preview-plain-er { white-space:pre-wrap; font-family:monospace; border:1px solid #ddd; padding:10px; min-height:200px; background:#fff; max-height: 400px; overflow-y: auto;}
        .recipients-list-er { margin-top:15px; padding:10px; background-color:#e7f3ff; border:1px solid #b3d9ff; font-size:0.9em; }
        .recipients-list-er h3 { margin-top:0; font-size:1.1em; }
        .button-er { padding: 10px 18px; background-color: #12044C; color:white; border:none; border-radius:4px; cursor:pointer; font-size:1em; }
        .button-er:hover { background-color: #0056b3; }
        .button-er:disabled { background-color: #6c757d; }
        .status-message-er { padding:10px; margin:15px 0; border-radius:4px; }
        .warning-er { background-color: #fff3cd; color: #856404; border:1px solid #ffeaa7; }
        .alert { padding: 15px; margin-bottom: 20px; border: 1px solid transparent; border-radius: .25rem; }
        .alert-warning { color: #856404; background-color: #fff3cd; border-color: #ffeeba; }
        .alert-danger { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; }
    </style>

    <div class="page-header-er">
        <h1 class="page-title-er">Email Check Results Report</h1>
    </div>

    <div class="station-info-er">
        <strong>Station: <?= htmlspecialchars($station_name) ?></strong>
        <br>
        <small>Report for checks around: <?= htmlspecialchars($latestCheckDate_preview) ?></small>
    </div>

    <?php if (!$email_is_configured): ?>
        <div class="status-message-er warning-er">
            <strong>Warning:</strong> Email sending is not configured in `config.php`. Please set EMAIL_HOST, EMAIL_USER, EMAIL_PASS, and EMAIL_PORT. The report cannot be sent.
        </div>
    <?php endif; ?>

    <div class="email-preview-section">
        <h2>Email Preview</h2>
        <div class="preview-tabs-er">
            <div class="preview-tab-er active" onclick="switchEmailResultsTab('html_er_tab')">HTML Version</div>
            <div class="preview-tab-er" onclick="switchEmailResultsTab('plain_er_tab')">Plain Text Version</div>
        </div>
        <div id="html_er_tab" class="preview-content-er active">
            <div class="preview-html-er"><?= $htmlContent_preview ?></div>
        </div>
        <div id="plain_er_tab" class="preview-content-er">
            <pre class="preview-plain-er"><?= htmlspecialchars($plainContent_preview) ?></pre>
        </div>
    </div>

    <?php if (!empty($emails_preview)): ?>
        <div class="recipients-list-er">
            <h3>Report will be sent to:</h3>
            <ul>
                <?php foreach ($emails_preview as $email_item): ?>
                    <li><?= htmlspecialchars($email_item) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div style="text-align:center; margin-top:20px;">
            <button id="sendFinalReportButton" class="button-er" onclick="sendFinalReportEmail()" <?= $email_is_configured ? '' : 'disabled' ?>>
                Send Final Report Email
            </button>
            <?php if (!$email_is_configured): ?>
                <p style="color:red; font-size:0.9em;">Sending disabled due to missing email configuration.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="status-message-er warning-er">
            No email addresses configured for this station. Please set up recipients in "Manage Email Settings".
        </div>
    <?php endif; ?>
    <div id="emailSendStatus" style="margin-top:15px; text-align:center;"></div>
</div>

<script>
function switchEmailResultsTab(tabIdToShow) {
    document.querySelectorAll('.email-results-container .preview-tab-er').forEach(tab => tab.classList.remove('active'));
    document.querySelectorAll('.email-results-container .preview-content-er').forEach(content => content.classList.remove('active'));
    
    document.querySelector('.email-results-container .preview-tab-er[onclick*="' + tabIdToShow + '"]').classList.add('active');
    document.getElementById(tabIdToShow).classList.add('active');
}

function sendFinalReportEmail() {
    const sendButton = document.getElementById('sendFinalReportButton');
    const statusDiv = document.getElementById('emailSendStatus');
    
    sendButton.disabled = true;
    sendButton.textContent = 'Sending...';
    statusDiv.innerHTML = '<p><em>Processing request...</em></p>';

    fetch('admin.php?ajax=1&page=email_results.php&sub_action=send_final_report', {
        method: 'POST' // Can be GET if no sensitive data, but POST is fine
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            statusDiv.innerHTML = '<p style="color:green;"><strong>Success:</strong> ' + (data.message || 'Email sent successfully!') + '</p>';
            // Optionally, disable button further or change text to "Sent"
            sendButton.textContent = 'Report Sent';
        } else {
            statusDiv.innerHTML = '<p style="color:red;"><strong>Error:</strong> ' + (data.error || 'Failed to send email.') + '</p>';
            sendButton.disabled = false;
            sendButton.textContent = 'Send Final Report Email';
        }
    })
    .catch(error => {
        statusDiv.innerHTML = '<p style="color:red;"><strong>Network Error:</strong> ' + error.message + '</p>';
        sendButton.disabled = false;
        sendButton.textContent = 'Send Final Report Email';
        console.error("Send final report error:", error);
    });
}
</script>
