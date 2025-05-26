<?php
// Include password file
include('config.php');
include 'db.php';
include 'templates/header.php';

// Check if the user is logged in
if (!isset($_COOKIE['logged_in_' . DB_NAME]) || $_COOKIE['logged_in_' . DB_NAME] != 'true') {
    header('Location: login.php');
    exit;
}

$db = get_db_connection();

// Handle form submission for setting email
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['set_email'])) {
    $admin_email = $_POST['admin_email'];
    if (!empty($admin_email) && filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
        try {
            // Check if settings table exists and has an admin_email entry
            $stmt = $db->prepare('SELECT COUNT(*) FROM settings WHERE setting_name = "admin_email"');
            $stmt->execute();
            $exists = $stmt->fetchColumn();
            
            if ($exists) {
                // Update existing email
                $stmt = $db->prepare('UPDATE settings SET setting_value = ? WHERE setting_name = "admin_email"');
                $stmt->execute([$admin_email]);
            } else {
                // Insert new email
                $stmt = $db->prepare('INSERT INTO settings (setting_name, setting_value) VALUES ("admin_email", ?)');
                $stmt->execute([$admin_email]);
            }
            
            $success_message = "Admin email has been set successfully!";
        } catch (Exception $e) {
            $error_message = "Error setting admin email: " . $e->getMessage();
        }
    } else {
        $error_message = "Please enter a valid email address.";
    }
}

// Get current admin email
try {
    $stmt = $db->prepare('SELECT setting_value FROM settings WHERE setting_name = "admin_email"');
    $stmt->execute();
    $current_email = $stmt->fetchColumn();
} catch (Exception $e) {
    $current_email = null;
}
?>

<h1>Manage Admin Email Address</h1>

<?php if (isset($success_message)): ?>
    <div class="success-message" style="color: green; margin: 20px 0; padding: 10px; background-color: #d4edda; border: 1px solid #c3e6cb; border-radius: 5px;">
        <?= htmlspecialchars($success_message) ?>
    </div>
<?php endif; ?>

<?php if (isset($error_message)): ?>
    <div class="error-message" style="color: red; margin: 20px 0; padding: 10px; background-color: #f8d7da; border: 1px solid #f5c6cb; border-radius: 5px;">
        <?= htmlspecialchars($error_message) ?>
    </div>
<?php endif; ?>

<div class="info-section" style="margin: 20px 0; padding: 15px; background-color: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 5px;">
    <h2>Current Admin Email</h2>
    <?php if ($current_email): ?>
        <div style="font-size: 18px; font-weight: bold; color: #12044C; margin: 10px 0;">
            <?= htmlspecialchars($current_email) ?>
        </div>
    <?php else: ?>
        <p style="color: #666;">No admin email is currently set.</p>
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
            <button type="submit" name="set_email" class="button touch-button">Set Admin Email</button>
        </div>
    </form>
</div>

<div class="info-section" style="margin: 20px 0; padding: 15px; background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px;">
    <h3>About Admin Email</h3>
    <p>This email address will be used to send reports and notifications from the TruckChecks system. Make sure to configure your email settings in the config.php file for email functionality to work properly.</p>
    <p>Required email settings in config.php:</p>
    <ul>
        <li>EMAIL_HOST - SMTP server hostname</li>
        <li>EMAIL_USER - Email username</li>
        <li>EMAIL_PASS - Email password</li>
        <li>EMAIL_PORT - SMTP port number</li>
    </ul>
</div>

<div class="button-container" style="margin-top: 20px;">
    <a href="admin.php" class="button touch-button">Back to Admin</a>
</div>

<?php include 'templates/footer.php'; ?>
