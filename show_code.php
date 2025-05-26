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

// Handle form submission for setting new code
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['set_code'])) {
    $new_code = $_POST['security_code'];
    if (!empty($new_code)) {
        try {
            // Check if settings table exists and has a security_code entry
            $stmt = $db->prepare('SELECT COUNT(*) FROM settings WHERE setting_name = "security_code"');
            $stmt->execute();
            $exists = $stmt->fetchColumn();
            
            if ($exists) {
                // Update existing code
                $stmt = $db->prepare('UPDATE settings SET setting_value = ? WHERE setting_name = "security_code"');
                $stmt->execute([$new_code]);
            } else {
                // Insert new code
                $stmt = $db->prepare('INSERT INTO settings (setting_name, setting_value) VALUES ("security_code", ?)');
                $stmt->execute([$new_code]);
            }
            
            $success_message = "Security code has been set successfully!";
        } catch (Exception $e) {
            $error_message = "Error setting security code: " . $e->getMessage();
        }
    }
}

// Get current security code
try {
    $stmt = $db->prepare('SELECT setting_value FROM settings WHERE setting_name = "security_code"');
    $stmt->execute();
    $current_code = $stmt->fetchColumn();
} catch (Exception $e) {
    $current_code = null;
}
?>

<h1>Security Code Management</h1>

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
    <h2>Current Security Code</h2>
    <?php if ($current_code): ?>
        <div style="font-size: 24px; font-weight: bold; color: #12044C; margin: 10px 0;">
            <?= htmlspecialchars($current_code) ?>
        </div>
    <?php else: ?>
        <p style="color: #666;">No security code is currently set.</p>
    <?php endif; ?>
</div>

<div class="form-section">
    <h2>Set New Security Code</h2>
    <form method="POST">
        <div class="input-container">
            <label for="security_code">New Security Code:</label>
            <input type="text" name="security_code" id="security_code" placeholder="Enter new security code" required>
        </div>
        <div class="button-container">
            <button type="submit" name="set_code" class="button touch-button">Set Security Code</button>
        </div>
    </form>
</div>

<div class="info-section" style="margin: 20px 0; padding: 15px; background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px;">
    <h3>About Security Codes</h3>
    <p>Security codes can be used for additional verification when accessing certain features of the system. The code is stored in the database and can be updated as needed.</p>
</div>

<div class="button-container" style="margin-top: 20px;">
    <a href="admin.php" class="button touch-button">Back to Admin</a>
</div>

<?php include 'templates/footer.php'; ?>
