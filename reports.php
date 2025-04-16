<?php

include('config.php');
include ('db.php');



if (isset($_SESSION['IS_DEMO']) && $_SESSION['IS_DEMO'] === true) {
    echo "<h1>Demo Mode</h1>";
    echo "<h2>Demo mode adds the background stripes and the word DEMO in the middle of the screen</h2>";
    echo "<h2>There is also the Delete Demo Checks Data button which will reset the checks but not the locker changes</h2>";
    echo "<h2>This message is not visible when demo mode is not enabled</h2>";
} else {
    echo "<!-- Not in Demo Mode -->";
}


include 'templates/header.php';

?>


<div class="button-container" style="margin-top: 20px;">
    <a href="locker_check_report.php" class="button touch-button">Locker Check Reports</a>
    <a href="deleted_items_report.php" class="button touch-button">Deleted Items</a>
    <a href="list_all_items_report.php" class="button touch-button">List All Items</a>
    <a href="list_all_items_report_a3.php" class="button touch-button">A3 Locker Items Report</a>
    <a href="find.php" class="button touch-button">Find an Item</a>

</div>

<?php include 'templates/footer.php'; ?>
