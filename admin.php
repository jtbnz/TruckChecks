<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $_SESSION['color_blind_mode'] = isset($_POST['color_blind_mode']) ? true : false;
}

include 'templates/header.php';
?>

<h1>Admin Page</h1>

<form method="POST">
    <label>
        <input type="checkbox" name="color_blind_mode" <?= isset($_SESSION['color_blind_mode']) && $_SESSION['color_blind_mode'] ? 'checked' : '' ?>>
        Enable Color Blindness-Friendly Mode
    </label>
    <button type="submit" class="button touch-button">Save</button>
</form>

<div class="button-container" style="margin-top: 20px;">
    <a href="maintain_trucks.php" class="button touch-button">Maintain Trucks</a>
    <a href="maintain_lockers.php" class="button touch-button">Maintain Lockers</a>
    <a href="maintain_locker_items.php" class="button touch-button">Maintain Locker Items</a>
    <a href="reports.php" class="button touch-button">Reports</a>
</div>

<?php include 'templates/footer.php'; ?>