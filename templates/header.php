<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Truck Checklist</title>
    <link rel="stylesheet" href="styles/styles.css?id=V24">
</head>
<? $is_demo = isset($_SESSION['is_demo']) && $_SESSION['is_demo'] === true; ?>
<?
// Get the latest Git tag version
$version = trim(exec('git describe --tags $(git rev-list --tags --max-count=1)'));

// Set the session variable
$_SESSION['version'] = $version;
?>

<body class="<?php echo $is_demo ? 'demo-mode' : ''; ?>">
