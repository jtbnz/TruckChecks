<?php
// Check if session has not already been started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$version = getVersion();

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
