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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reset_checks'])) {
    try {
        // Reset all locker checks by deleting all check records
        $db->exec('DELETE FROM checks');
        
        // Reset auto-increment counter
        $db->exec('ALTER TABLE checks AUTO_INCREMENT = 1');
        
        $success_message = "All locker checks have been reset successfully!";
    } catch (Exception $e) {
        $error_message = "Error resetting locker checks: " . $e->getMessage();
    }
}
?>

<h1>Reset Locker Checks</h1>

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

<div class="warning-message" style="color: #856404; margin: 20px 0; padding: 15px; background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px;">
    <strong>Warning:</strong> This will reset all locker check records, allowing all lockers to be checked again.
    <br><br>
    This action will delete all existing check history and cannot be undone.
</div>

<form method="POST" onsubmit="return confirm('Are you sure you want to reset all locker checks? This will delete all check history.');">
    <div class="button-container">
        <button type="submit" name="reset_checks" class="button touch-button" style="background-color: #dc3545;">
            Reset All Locker Checks
        </button>
    </div>
</form>

<div class="button-container" style="margin-top: 20px;">
    <a href="admin.php" class="button touch-button">Back to Admin</a>
</div>

<?php include 'templates/footer.php'; ?>
