<?php

include('config.php');
include 'templates/header.php';

// Read the cookie value
$colorBlindMode = isset($_COOKIE['color_blind_mode']) ? $_COOKIE['color_blind_mode'] : false;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $colorBlindMode = isset($_POST['color_blind_mode']) ? true : false;
    setcookie('color_blind_mode', $colorBlindMode, time() + (86400 * 180), '/'); // Set the cookie for 180 days
}


?>

<h1>Settings</h1>



<div class="button-container" style="margin-top: 20px;">
<form method="POST">
    <label>
        <h2><input type="checkbox" name="color_blind_mode" <?= $colorBlindMode ? 'checked' : '' ?>>
        Enable Color Blindness-Friendly Mode
    </label></h2>
    <p> <button type="submit" class="button touch-button">Save</button></p>
</form>

</div>

<?php include 'templates/footer.php'; ?>

