<?php

include('config.php');
include 'templates/header.php';


if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $_SESSION['color_blind_mode'] = isset($_POST['color_blind_mode']) ? true : false;
}


?>

<h1>Settings</h1>



<div class="button-container" style="margin-top: 20px;">
<form method="POST">
    <label>
        <h2><input type="checkbox" name="color_blind_mode" <?= isset($_SESSION['color_blind_mode']) && $_SESSION['color_blind_mode'] ? 'checked' : '' ?>>
        Enable Color Blindness-Friendly Mode
    </label></h2>
    <button type="submit" class="button touch-button">Save</button>
</form>

</div>

<?php include 'templates/footer.php'; ?>

