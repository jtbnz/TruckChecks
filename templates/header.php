<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Truck Checklist</title>
    <link rel="stylesheet" href="styles/styles.css?id=V24">
</head>

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

$is_demo = isset($_SESSION['is_demo']) && $_SESSION['is_demo'] === true;

?>

<body class="<?php echo $is_demo ? 'demo-mode' : ''; ?>">
