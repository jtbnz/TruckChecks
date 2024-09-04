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

// IS_DEMO = isset($_SESSION['IS_DEMO']) && $_SESSION['IS_DEMO'] === true;

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Check lockers for missing items">
    <title>Truck Checklist</title>
    <link rel="stylesheet" href="styles/styles.css?id=<?php  echo $version;  ?> ">
</head>
<body class="<?php echo IS_DEMO ? 'demo-mode' : ''; ?>">
