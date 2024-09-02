<?php


include('config.php');
if (DEBUG) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    echo "Debug mode is on";
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
}
include ('db.php');

include 'templates/header.php';

if (isset($_SESSION['IS_DEMO']) && $_SESSION['IS_DEMO'] === true) {
    echo "<h1>Demo Mode</h1>";
    echo "<h2>Demo mode adds the background stripes and the word DEMO in the middle of the screen</h2>";
    echo "<h2>There is also the Delete Demo Checks Data button which will reset the checks but not the locker changes</h2>";
    echo "<h2>This message is not visible when demo mode is not enabled</h2>";
} else {
    echo "<!-- Not in Demo Mode -->";
}



// Check if the user is logged in
if (!isset($_COOKIE['logged_in']) || $_COOKIE['logged_in'] != 'true') {
    header('Location: login.php');
    exit;
}

$showButton = isset($_SESSION['IS_DEMO']) && $_SESSION['IS_DEMO'] === true;

// refresh the session variable every time in this page
// Get the latest Git tag version
$version = trim(exec('git describe --tags $(git rev-list --tags --max-count=1)'));

// Set the session variable
$_SESSION['version'] = $version;

?>

<h1>Admin Page</h1>

<!-- <img src="images/scania.png" alt="scania" class="truck-image"> -->

<div class="button-container" style="margin-top: 20px;">
    <a href="maintain_trucks.php" class="button touch-button">Maintain Trucks</a>
    <a href="maintain_lockers.php" class="button touch-button">Maintain Lockers</a>
    <a href="maintain_locker_items.php" class="button touch-button">Maintain Locker Items</a>
</div>
    <div class="button-container" style="margin-top: 20px;">    
    <a href="find.php" class="button touch-button">Find an item</a>
    <a href="reset_locker_check.php" class="button touch-button">Reset Locker Checks</a> 
    <a href="qr-codes.php" class="button touch-button">Generate QR Codes</a>
</div>
<div class="button-container" style="margin-top: 20px;">
    <a href="backups.php" class="button touch-button">Download a backup</a>
    <a href="email_admin.php" class="button touch-button">Manage Email address to send to</a>
    <a href="email_results.php" class="button touch-button">Email the last check missing items</a>
</div>
    <div class="button-container" style="margin-top: 20px;">    
    <a href="reports.php" class="button touch-button">Reports</a>
    <?php if ($showButton): ?>
        <a href="demo_clean_tables.php" class="button touch-button">Delete Demo Checks Data</a>
    <?php endif; ?>
</div>

<div class="button-container" style="margin-top: 20px;">
    <a href="logout.php" class="button touch-button">Logout</a>

</div>

<?php include 'templates/footer.php'; ?>