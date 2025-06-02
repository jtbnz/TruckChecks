<?php
/**
 * Automated Email Processor for TruckChecks
 * 
 * This script processes automated email sending for all stations based on their individual settings.
 * It checks each station's configuration for:
 * - Email automation enabled/disabled
 * - Send time matching current time
 * - Training nights configuration
 * - Public holiday handling with alternate training nights
 * 
 * Called by email_checks.sh via cron (hourly)
 */

// Set error reporting for debugging (can be disabled in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include required files
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

// Get timezone from environment variable set by shell script
$timezone = $_ENV['TIMEZONE'] ?? 'Pacific/Auckland';
date_default_timezone_set($timezone);

// Log file for debugging
$logFile = __DIR__ . '/email_automation.log';

/**
 * Log a message with timestamp
 */
function logMessage($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND | LOCK_EX);
}

/**
 * Check if a given date is a public holiday
 */
function isPublicHoliday($date) {
    // Use the existing holiday check logic
    $holidayScript = __DIR__ . '/check_holiday.php';
    if (!file_exists($holidayScript)) {
        logMessage("WARNING: Holiday check script not found at $holidayScript");
        return false;
    }
    
    $dateFormatted = date('d/m/Y', strtotime($date));
    $result = shell_exec("php $holidayScript $dateFormatted");
    $result = trim($result);
    
    logMessage("Holiday check for $dateFormatted: $result");
    
    return ($result === 'HOLIDAY');
}

/**
 * Generate and send email for a specific station
 */
function sendStationEmail($stationId, $db) {
    try {
        // Get station details
        $stationStmt = $db->prepare("SELECT * FROM stations WHERE id = ?");
        $stationStmt->execute([$stationId]);
        $station = $stationStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$station) {
            logMessage("ERROR: Station not found with ID: $stationId");
            return false;
        }
        
        logMessage("Generating email for station: {$station['name']} (ID: $stationId)");
        
        // Get email addresses for this station
        $emailQuery = "SELECT email FROM email_addresses WHERE station_id = ? OR station_id IS NULL";
        $emailStmt = $db->prepare($emailQuery);
        $emailStmt->execute([$stationId]);
        $emails = $emailStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Also get admin email for this station
        $adminEmailQuery = "SELECT setting_value FROM station_settings WHERE setting_key = 'admin_email' AND station_id = ?";
        $adminEmailStmt = $db->prepare($adminEmailQuery);
        $adminEmailStmt->execute([$stationId]);
        $adminEmail = $adminEmailStmt->fetchColumn();
        
        if ($adminEmail) {
            $emails[] = $adminEmail;
        }
        
        // Remove duplicates
        $emails = array_unique($emails);
        
        if (empty($emails)) {
            logMessage("WARNING: No email addresses configured for station: {$station['name']}");
            return false;
        }
        
        logMessage("Found " . count($emails) . " email addresses for station: {$station['name']}");
        
        // Generate email content using the same logic as email_results.php
        $emailContent = generateEmailContent($station, $db);
        
        if (!$emailContent) {
            logMessage("ERROR: Failed to generate email content for station: {$station['name']}");
            return false;
        }
        
        // Send emails using PHPMailer (same as email_results.php)
        $success = sendEmails($emails, $emailContent, $station);
        
        if ($success) {
            logMessage("SUCCESS: Emails sent for station: {$station['name']} to " . count($emails) . " recipients");
        } else {
            logMessage("ERROR: Failed to send emails for station: {$station['name']}");
        }
        
        return $success;
        
    } catch (Exception $e) {
        logMessage("ERROR: Exception in sendStationEmail for station ID $stationId: " . $e->getMessage());
        return false;
    }
}

/**
 * Generate email content for a station (extracted from email_results.php logic)
 */
function generateEmailContent($station, $db) {
    try {
        $current_directory = dirname($_SERVER['REQUEST_URI'] ?? '/');
        $current_url = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $current_directory . '/index.php';
        
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

        // Fetch the latest check data for this station (missing items)
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

        // Query for all locker check notes in the last 7 days for this station
        $allNotesQuery = $db->prepare("
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

        // Generate HTML email content (same structure as email_results.php)
        $htmlContent = generateHtmlContent($station, $latestCheckDate, $checks, $deletedItems, $allNotes, $current_url);
        
        // Generate plain text content
        $plainContent = generatePlainContent($station, $latestCheckDate, $checks, $deletedItems, $allNotes, $current_url);
        
        return [
            'html' => $htmlContent,
            'plain' => $plainContent,
            'subject' => "Missing Items Report - " . $station['name'] . " - {$latestCheckDate}"
        ];
        
    } catch (Exception $e) {
        logMessage("ERROR: Exception in generateEmailContent: " . $e->getMessage());
        return false;
    }
}

/**
 * Generate HTML email content
 */
function generateHtmlContent($station, $latestCheckDate, $checks, $deletedItems, $allNotes, $current_url) {
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
            <p>Generated automatically on: ' . date('Y-m-d H:i:s') . '</p>
        </div>
    </body>
    </html>';

    return $htmlContent;
}

/**
 * Generate plain text email content
 */
function generatePlainContent($station, $latestCheckDate, $checks, $deletedItems, $allNotes, $current_url) {
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
    $emailContent .= "Generated automatically on: " . date('Y-m-d H:i:s') . "\n";

    return $emailContent;
}

/**
 * Send emails using PHPMailer
 */
function sendEmails($emails, $emailContent, $station) {
    // Check if email configuration is available
    if (!defined('EMAIL_HOST') || !defined('EMAIL_USER') || !defined('EMAIL_PASS') || !defined('EMAIL_PORT')) {
        logMessage("ERROR: Email configuration not set up in config.php");
        return false;
    }

    require_once __DIR__ . '/../vendor/autoload.php';
    
    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\Exception;

    try {
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
        $from_email = filter_var(EMAIL_USER, FILTER_VALIDATE_EMAIL) ? EMAIL_USER : 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $mail->setFrom($from_email, 'TruckChecks - ' . $station['name']);
        
        foreach ($emails as $email) {
            $mail->addAddress($email);
        }

        // Content
        $mail->isHTML(true);
        $mail->Subject = $emailContent['subject'];
        $mail->Body = $emailContent['html'];
        $mail->AltBody = $emailContent['plain'];
        
        $mail->send();
        
        logMessage("SUCCESS: Email sent to " . count($emails) . " recipients for station: {$station['name']}");
        return true;
        
    } catch (Exception $e) {
        logMessage("ERROR: Failed to send email for station {$station['name']}: " . $e->getMessage());
        return false;
    }
}

// Main execution starts here
logMessage("=== Starting automated email processor ===");

try {
    $db = get_db_connection();
    
    // Get current time and day
    $currentTime = date('H:i');
    $currentDay = date('N'); // 1=Monday, 7=Sunday
    $currentDate = date('Y-m-d');
    
    logMessage("Current time: $currentTime, Current day: $currentDay (1=Mon, 7=Sun), Date: $currentDate");
    
    // Get all stations with their email automation settings
    $stationsQuery = "
        SELECT 
            s.*,
            ss_time.setting_value as send_email_check_time,
            ss_nights.setting_value as training_nights,
            ss_alt.setting_value as alternate_training_night,
            ss_enabled.setting_value as email_automation_enabled
        FROM stations s
        LEFT JOIN station_settings ss_time ON s.id = ss_time.station_id AND ss_time.setting_key = 'send_email_check_time'
        LEFT JOIN station_settings ss_nights ON s.id = ss_nights.station_id AND ss_nights.setting_key = 'training_nights'
        LEFT JOIN station_settings ss_alt ON s.id = ss_alt.station_id AND ss_alt.setting_key = 'alternate_training_night'
        LEFT JOIN station_settings ss_enabled ON s.id = ss_enabled.station_id AND ss_enabled.setting_key = 'email_automation_enabled'
        ORDER BY s.name
    ";
    
    $stationsStmt = $db->prepare($stationsQuery);
    $stationsStmt->execute();
    $stations = $stationsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    logMessage("Found " . count($stations) . " stations to process");
    
    $emailsSent = 0;
    
    foreach ($stations as $station) {
        $stationName = $station['name'];
        $stationId = $station['id'];
        
        // Check if email automation is enabled for this station
        $emailEnabled = ($station['email_automation_enabled'] ?? 'true') === 'true';
        if (!$emailEnabled) {
            logMessage("SKIP: Email automation disabled for station: $stationName");
            continue;
        }
        
        // Check if current time matches the station's send time
        $sendTime = $station['send_email_check_time'] ?? '19:30';
        if ($currentTime !== $sendTime) {
            logMessage("SKIP: Time mismatch for station $stationName. Current: $currentTime, Required: $sendTime");
            continue;
        }
        
        logMessage("TIME MATCH: Processing station $stationName at $currentTime");
        
        // Get training nights and alternate night
        $trainingNights = $station['training_nights'] ?? '1,2'; // Default to Monday,Tuesday
        $alternateNight = $station['alternate_training_night'] ?? '2'; // Default to Tuesday
        
        $trainingNightArray = array_map('trim', explode(',', $trainingNights));
        
        logMessage("Station $stationName - Training nights: $trainingNights, Alternate: $alternateNight");
        
        // Check if today is a training night
        $isTrainingNight = in_array($currentDay, $trainingNightArray);
        
        if (!$isTrainingNight) {
            logMessage("SKIP: Today ($currentDay) is not a training night for station $stationName");
            continue;
        }
        
        logMessage("TRAINING NIGHT: Today is a training night for station $stationName");
        
        // Check if today is a public holiday
        $isHoliday = isPublicHoliday($currentDate);
        
        if ($isHoliday) {
            logMessage("HOLIDAY DETECTED: Today is a public holiday");
            
            // Check if today is the alternate training night
            if ($currentDay != $alternateNight) {
                logMessage("SKIP: Today ($currentDay) is not the alternate training night ($alternateNight) for station $stationName");
                continue;
            }
            
            logMessage("ALTERNATE NIGHT: Using alternate training night for station $stationName due to holiday");
        }
        
        // All conditions met - send email
        logMessage("SENDING EMAIL: All conditions met for station $stationName");
        
        $success = sendStationEmail($stationId, $db);
        if ($success) {
            $emailsSent++;
        }
    }
    
    logMessage("=== Email processing complete. Emails sent for $emailsSent stations ===");
    
} catch (Exception $e) {
    logMessage("FATAL ERROR: " . $e->getMessage());
    exit(1);
}

exit(0);
?>
