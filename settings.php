<?php
include_once('config.php');
include_once('auth.php'); // Include V4 auth
include_once('db.php'); // Include for consistency, though not strictly used here yet

// It's good practice to ensure user is authenticated for settings pages,
// even if settings are client-side cookies.
// For now, we'll allow access without full auth for these simple cookie settings,
// but for future user-specific settings, requireAuth() would be used.
// $user = getCurrentUser(); // Example if we needed user-specific settings

include 'templates/header.php';

// Read cookie values
$colorBlindMode = isset($_COOKIE['color_blind_mode']) ? $_COOKIE['color_blind_mode'] === 'true' : false; // Ensure boolean
$checkedByName = isset($_COOKIE['prevName']) ? htmlspecialchars($_COOKIE['prevName']) : '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Handle Color Blind Mode
    $colorBlindMode = isset($_POST['color_blind_mode']) ? true : false;
    setcookie('color_blind_mode', $colorBlindMode ? 'true' : 'false', time() + (86400 * 180), "/"); // Store as 'true'/'false' strings

    // Handle Checked By Name
    if (isset($_POST['checked_by_name'])) {
        $newCheckedByName = trim($_POST['checked_by_name']);
        if (!empty($newCheckedByName)) {
            setcookie('prevName', $newCheckedByName, time() + (86400 * 120), "/"); // 120 days, matches check_locker_items.php
            $checkedByName = htmlspecialchars($newCheckedByName); // Update for display
            // Also update localStorage for consistency with check_locker_items.js
            echo "<script>localStorage.setItem('lastCheckedBy', '" . addslashes($newCheckedByName) . "');</script>";
            $success_message = "Settings saved successfully!";
        } else {
            // Optionally handle empty name submission, e.g., by clearing or ignoring
            // For now, we'll just not update if it's empty, effectively keeping the old one.
            // Or clear it:
            // setcookie('prevName', '', time() - 3600, "/"); // Clear cookie
            // echo "<script>localStorage.removeItem('lastCheckedBy');</script>";
            // $checkedByName = '';
            // $success_message = "Checked By name cleared.";
        }
    }
     if(empty($success_message)) { // Avoid overwriting if name was saved
        $success_message = "Color blind mode setting saved!";
    }
    // Refresh page to see changes immediately if only color blind mode was changed
    if (!isset($_POST['checked_by_name'])) {
        header("Location: settings.php"); // Redirect to clear POST data and show updated cookie value
        exit;
    }
}
?>

<style>
    .settings-container { max-width: 600px; margin: 20px auto; padding: 20px; background-color: #f9f9f9; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 8px; font-weight: bold; }
    .form-group input[type="text"], .form-group input[type="checkbox"] { margin-right: 5px; }
    .form-group input[type="text"] { width: calc(100% - 22px); padding: 10px; border-radius: 4px; border: 1px solid #ccc; }
    .button { background-color: #12044C; color: white; padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; }
    .button:hover { background-color: #0056b3; }
    .success-message { padding: 10px; margin-bottom: 20px; background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; border-radius: 5px; }
</style>

<div class="settings-container">
    <h1>Settings</h1>

    <?php if ($success_message): ?>
        <div class="success-message"><?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <form method="POST" action="settings.php">
        <div class="form-group">
            <label for="checked_by_name_input">Your Name for "Checked By" field:</label>
            <input type="text" id="checked_by_name_input" name="checked_by_name" value="<?= $checkedByName ?>" placeholder="Enter your name">
            <p style="font-size: 0.9em; color: #666;">This name is used to pre-fill the 'Checked by' field when you perform locker checks.</p>
        </div>

        <div class="form-group">
            <label>
                <input type="checkbox" name="color_blind_mode" <?= $colorBlindMode ? 'checked' : '' ?>>
                Enable Color Blindness-Friendly Mode
            </label>
            <p style="font-size: 0.9em; color: #666;">This changes the status colors on the main page to be more accessible.</p>
        </div>
        
        <button type="submit" class="button">Save Settings</button>
    </form>

    <div style="margin-top: 30px;">
        <a href="index.php" class="button">Back to Home</a>
        <?php 
        // Show admin link if user is authenticated (even if legacy)
        // This part can be enhanced with proper role checks from $user if requireAuth() was enforced
        if (isset($_COOKIE['logged_in_' . DB_NAME]) || isset($_SESSION['user_id'])) {
            echo ' <a href="admin.php" class="button">Back to Admin</a>';
        }
        ?>
    </div>
</div>

<?php include 'templates/footer.php'; ?>
