<?php

include('config.php');
include ('db.php');

//include 'templates/header.php';





<?php
// Check if session has not already been started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if the version session variable is not set
if (!isset($_SESSION['version'])) {
    // Get the latest Git tag version
    $version = trim(exec('git describe --tags $(git rev-list --tags --max-count=1)'));

    // Set the session variable
    $_SESSION['version'] = $version;
} else {
    // Use the already set session variable
    $version = $_SESSION['version'];
}



?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Truck Checklist</title>
    <link rel="stylesheet" href="styles/styles.css?id=<?php  echo $version;  ?> ">
    <style>
        body::before {
            content: "";
            display: block;
            position: fixed;
            top: 0;
            left: 0;
            width: 75vw; /* 75% of the viewport width */
            height: 100vh; /* 100% of the viewport height */
            background: url(images/scania.png) no-repeat center center fixed; 
            background-size: cover;
            z-index: -1;
            opacity: 0.7; /* 30% greyed out */
        }
    </style>
</head>
<body class="<?php echo IS_DEMO ? 'demo-mode' : ''; ?>">

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

<img src="images/scania.png" alt="scania" class="truck-image">

<div class="button-container" style="margin-top: 20px;">
    <a href="maintain_trucks.php" class="button touch-button">Maintain Trucks</a>
    <a href="maintain_lockers.php" class="button touch-button">Maintain Lockers</a>
    <a href="maintain_locker_items.php" class="button touch-button">Maintain Locker Items</a>
    <a href="deleted_items_report.php" class="button touch-button">Deleted Items Report</a>
    <a href="reset_locker_check.php" class="button touch-button">Reset Locker Checks</a> 
</div>
<div class="button-container" style="margin-top: 20px;">
    <a href="qr-codes.php" class="button touch-button">Generate QR Codes</a>
    <a href="backups.php" class="button touch-button">Download a backup</a>
    <a href="email_admin.php" class="button touch-button">Manage Email address to send to</a>
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