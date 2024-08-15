<?php
// Include password file
include('password.php');
// Start the session
session_start();

// Check if the user is logged in
if (!isset($_COOKIE['logged_in']) || $_COOKIE['logged_in'] != 'true') {
    header('Location: login.php');
    exit;
}



include 'templates/header.php';
?>

<h1>Admin Page</h1>



<div class="button-container" style="margin-top: 20px;">
    <a href="maintain_trucks.php" class="button touch-button">Maintain Trucks</a>
    <a href="maintain_lockers.php" class="button touch-button">Maintain Lockers</a>
    <a href="maintain_locker_items.php" class="button touch-button">Maintain Locker Items</a>
    <a href="qr-codes.php" class="button touch-button">Generate QR Codes</a>
    <a href="backups.php" class="button touch-button">Download a backup</a>
    <a href="email_results.php" class="button touch-button">Email the last check missing items</a>
    <a href="reports.php" class="button touch-button">Reports</a>
</div>

<div class="button-container" style="margin-top: 20px;">
    <a href="logout.php" class="button touch-button">Logout</a>

</div>

<?php include 'templates/footer.php'; ?>