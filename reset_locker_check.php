<?php


// Include the database connection
include 'db.php';
include 'templates/header.php';

$db = get_db_connection();

// Initialize variables for feedback
$message = "";
$rows_updated = 0;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['ignore_recent_checks'])) {
        // SQL statement to update ignore_check to true for checks within the last 6 days
        $sql = "UPDATE checks SET ignore_check = true WHERE check_date >= NOW() - INTERVAL 6 DAY";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $rows_updated = $stmt->rowCount();
        $message = "Updated $rows_updated rows to ignore recent checks.";
    } elseif (isset($_POST['reset_ignore_recent_checks'])) {
        // SQL statement to reset ignore_check to false for checks within the last 6 days
        $sql = "UPDATE checks SET ignore_check = false WHERE check_date >= NOW() - INTERVAL 6 DAY";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $rows_updated = $stmt->rowCount();
        $message = "Reset ignore recent checks for $rows_updated rows.";
    }
}

?>



    <h1>Reset Locker Checks</h1>
    <p>This page allows you to manage the status of recent locker checks. You can either choose to ignore all checks performed within the last 6 days, or reset this status.</p>
    
    <form method="POST" action="">
        <button type="submit" name="ignore_recent_checks" class="button">Ignore Recent Checks</button>
        <button type="submit" name="reset_ignore_recent_checks" class="button">Reset Ignore Recent Checks</button>
    </form>
    
    <?php if (!empty($message)): ?>
        <p><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>

    <div class="button-container" style="margin-top: 20px;">
    <a href="admin.php" class="button touch-button">Admin Page</a>

</div>
<?php include 'templates/footer.php'; ?>


</body>
</html>
