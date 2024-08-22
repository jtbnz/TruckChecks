<?php

include('config.php');
include ('db.php');

include 'templates/header.php';

if (isset($_SESSION['IS_DEMO'])) {
    
    if($_SESSION['IS_DEMO'] === true) {
        echo "<h1>Demo Mode</h2> ";
        echo "<h2>Demo mode adds the background stripes and the word DEMO in the middle of the screen</h2>";
        echo "<h2>There is also the Delete Demo Checks Data button which will reset the checks but not the locker changes</h2>";
        echo "<h2>This message is not visible when demo mode is not enabled</h2>";
    } else{
        echo "<!--Not in Demo Mode-->";}
    }


// Check if the user is logged in
if (!isset($_COOKIE['logged_in']) || $_COOKIE['logged_in'] != 'true') {
    header('Location: login.php');
    exit;
}

$showButton = isset($_SESSION['IS_DEMO']) && $_SESSION['IS_DEMO'] === true;


?>

<h1>Admin Page</h1>



<div class="button-container" style="margin-top: 20px;">
    <a href="maintain_trucks.php" class="button touch-button">Maintain Trucks</a>
    <a href="maintain_lockers.php" class="button touch-button">Maintain Lockers</a>
    <a href="maintain_locker_items.php" class="button touch-button">Maintain Locker Items</a>
    <a href="reset_locker_check.php" class="button touch-button">Reset Locker Checks</a> 
</div>
<div class="button-container" style="margin-top: 20px;">
    <a href="qr-codes.php" class="button touch-button">Generate QR Codes</a>
    <a href="backups.php" class="button touch-button">Download a backup</a>
    <a href="email_results.php" class="button touch-button">Email the last check missing items</a>
    <a href="reports.php" class="button touch-button">Reports</a>
    <?php if ($showButton): ?>
        <a href="demo_clean_tables.php" class="button touch-button">Delete Demo Checks Data</a>
    <?php endif; ?>
</div>

<div class="button-container" style="margin-top: 20px;">
    <a href="logout.php" class="button touch-button">Logout</a>

</div>

<?php include 'templates/footer.php'; ?>