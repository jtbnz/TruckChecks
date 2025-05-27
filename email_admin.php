<?php
include('config.php');
include 'db.php';
include_once('auth.php');

// Require authentication and station context
$station = requireStation();
$user = getCurrentUser();

$db = get_db_connection();

// Handle form submission for setting email
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['set_email'])) {
    $admin_email = $_POST['admin_email'];
    if (!empty($admin_email) && filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
        try {
            // Check if settings table exists and has an admin_email entry for this station
            $stmt = $db->prepare('SELECT COUNT(*) FROM settings WHERE setting_name = "admin_email" AND station_id = ?');
            $stmt->execute([$station['id']]);
            $exists = $stmt->fetchColumn();
            
            if ($exists) {
                // Update existing email
                $stmt = $db->prepare('UPDATE settings SET setting_value = ? WHERE setting_name = "admin_email" AND station_id = ?');
                $stmt->execute([$admin_email, $station['id']]);
            } else {
                // Insert new email
                $stmt = $db->prepare('INSERT INTO settings (setting_name, setting_value, station_id) VALUES ("admin_email", ?, ?)');
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

// Get current admin email for this station
try {
    $stmt = $db->prepare('SELECT setting_value FROM settings WHERE setting_name = "admin_email" AND station_id = ?');
    $stmt->execute([$station['id']]);
    $current_email = $stmt->fetchColumn();
} catch (Exception $e) {
    $current_email = null;
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

    .input-container input {
        width: 100%;
        padding: 10px;
        border: 1px solid #ccc;
        border-radius: 5px;
        font-size: 16px;
        box-sizing: border-box;
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

    /* Mobile responsive */
    @media (max-width: 768px) {
        .email-container {
            padding: 10px;
        }
    }
</style>

<div class="email-container">
    <div class="page-header">
        <h1 class="page-title">Manage Admin Email Address</h1>
    </div>

    <div class="station-info">
        <div class="station-name"><?= htmlspecialchars($station['name']) ?></div>
        <?php if ($station['description']): ?>
            <div style="color: #666; margin-top: 5px;"><?= htmlspecialchars($station['description']) ?></div>
        <?php endif; ?>
    </div>

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

    <div class="info-section current-email-section">
        <h2>Current Admin Email</h2>
        <?php if ($current_email): ?>
            <div class="current-email-display">
                <?= htmlspecialchars($current_email) ?>
            </div>
        <?php else: ?>
            <p class="no-email-text">No admin email is currently set for this station.</p>
        <?php endif; ?>
    </div>

    <div class="form-section">
        <h2>Set Admin Email Address</h2>
        <form method="POST">
            <div class="input-container">
                <label for="admin_email">Admin Email Address:</label>
                <input type="email" name="admin_email" id="admin_email" placeholder="admin@example.com" value="<?= htmlspecialchars($current_email ?? '') ?>" required>
            </div>
            <div class="button-container">
                <button type="submit" name="set_email" class="button">Set Admin Email</button>
            </div>
        </form>
    </div>

    <div class="info-section help-section">
        <h3>About Admin Email</h3>
        <p>This email address will be used to send reports and notifications from the TruckChecks system for <strong><?= htmlspecialchars($station['name']) ?></strong>. Make sure to configure your email settings in the config.php file for email functionality to work properly.</p>
        <p>Required email settings in config.php:</p>
        <ul>
            <li><strong>EMAIL_HOST</strong> - SMTP server hostname</li>
            <li><strong>EMAIL_USER</strong> - Email username</li>
            <li><strong>EMAIL_PASS</strong> - Email password</li>
            <li><strong>EMAIL_PORT</strong> - SMTP port number</li>
        </ul>
        <p><strong>Note:</strong> Each station can have its own admin email address for receiving station-specific reports and notifications.</p>
    </div>

    <div class="button-container">
        <a href="admin.php" class="button secondary">← Back to Admin</a>
    </div>
</div>

<?php include 'templates/footer.php'; ?>
