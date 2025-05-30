<?php
include('config.php');
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
            
            require_once 'vendor/autoload.php';
            
            // Debug: Show email configuration being used
            error_log('=== EMAIL DEBUG INFO ===');
            error_log('EMAIL_HOST: ' . (defined('EMAIL_HOST') ? EMAIL_HOST : 'NOT DEFINED'));
            error_log('EMAIL_USER: ' . (defined('EMAIL_USER') ? EMAIL_USER : 'NOT DEFINED'));
            error_log('EMAIL_PASS: ' . (defined('EMAIL_PASS') ? (EMAIL_PASS ? '[SET - ' . strlen(EMAIL_PASS) . ' chars]' : '[EMPTY]') : 'NOT DEFINED'));
            error_log('EMAIL_PORT: ' . (defined('EMAIL_PORT') ? EMAIL_PORT : 'NOT DEFINED'));
            error_log('From Email (calculated): ' . (filter_var(EMAIL_USER, FILTER_VALIDATE_EMAIL) ? EMAIL_USER : 'noreply@' . $_SERVER['HTTP_HOST']));
            error_log('Test Email To: ' . $test_email);
            error_log('========================');
            
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            // Enable verbose debug output
            $mail->SMTPDebug = 2; // Enable verbose debug output
            $mail->Debugoutput = function($str, $level) {
                error_log("PHPMailer DEBUG [$level]: $str");
            };
            error_log('PHPMailer debug output enabled');
            
            // Server settings
            $mail->isSMTP();
            $mail->Host = EMAIL_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = EMAIL_USER;
            $mail->Password = EMAIL_PASS;
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = EMAIL_PORT;
            
            // Recipients
            // Validate EMAIL_USER is a proper email format
            $from_email = filter_var(EMAIL_USER, FILTER_VALIDATE_EMAIL) ? EMAIL_USER : 'noreply@' . $_SERVER['HTTP_HOST'];
            $mail->setFrom($from_email, 'TruckChecks System');
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
            
            $mail->send();
            $test_success_message = "Test email sent successfully to " . htmlspecialchars($test_email) . "!";
        } catch (Exception $e) {
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

    <?php if ($email_configured): ?>
    <div class="form-section test-email-section">
        <h2>Test Email Configuration</h2>
        <p>Send a test email to verify that your email configuration is working correctly.</p>
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
        </ul>
        <p>Required email settings in config.php:</p>
        <ul>
            <li><strong>EMAIL_HOST</strong> - SMTP server hostname</li>
            <li><strong>EMAIL_USER</strong> - Email username</li>
            <li><strong>EMAIL_PASS</strong> - Email password</li>
            <li><strong>EMAIL_PORT</strong> - SMTP port number</li>
        </ul>
    </div>

    <div class="button-container">
        <a href="admin.php" class="button secondary">← Back to Admin</a>
    </div>

    <?php endif; ?>
</div>

<?php include 'templates/footer.php'; ?>
