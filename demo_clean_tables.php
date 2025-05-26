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
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['clean_tables'])) {
    try {
        // Delete all records from checks table
        $db->exec('DELETE FROM checks');
        
        // Reset auto-increment counter
        $db->exec('ALTER TABLE checks AUTO_INCREMENT = 1');
        
        $success_message = "Demo data has been cleaned successfully!";
    } catch (Exception $e) {
        $error_message = "Error cleaning demo data: " . $e->getMessage();
    }
}
?>

<h1>Clean Demo Data</h1>

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
    <strong>Warning:</strong> This will delete all check records from the database. This action cannot be undone.
    <br><br>
    This is intended for demo environments only to reset check data while preserving truck, locker, and item configurations.
</div>

<form method="POST" onsubmit="return confirm('Are you sure you want to delete all check records? This action cannot be undone.');">
    <div class="button-container">
        <button type="submit" name="clean_tables" class="button touch-button" style="background-color: #dc3545;">
            Clean Demo Check Data
        </button>
    </div>
</form>

<div class="button-container" style="margin-top: 20px;">
    <a href="admin.php" class="button touch-button">Back to Admin</a>
</div>

<?php include 'templates/footer.php'; ?>
