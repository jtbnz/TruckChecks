<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Truck Checklist</title>
    <link rel="stylesheet" href="styles/styles.css?id=V7">
</head>
<?$is_demo = isset($_SESSION['is_demo']) && $_SESSION['is_demo'] === true; ?>
<body class="<?php echo $is_demo ? 'demo-mode' : ''; ?>">
