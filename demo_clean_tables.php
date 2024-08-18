<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include the database connection
include 'db.php';
$db = get_db_connection();

// Check if the session variable 'is_demo' is set and true
$showButton = isset($_SESSION['is_demo']) && $_SESSION['is_demo'] === true;

// Handle the delete request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    if ($_POST['confirm_delete'] === 'yes') {
        // Delete all records from 'checks' and 'check_items' tables
        try {
            $db->beginTransaction();
            $db->exec("DELETE FROM check_items");
            $db->exec("DELETE FROM checks");
            $db->commit();
            echo "<script>alert('All checks have been deleted successfully.');</script>";
        } catch (Exception $e) {
            $db->rollBack();
            echo "<script>alert('Failed to delete checks: " . $e->getMessage() . "');</script>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delete Checks</title>
    <style>
        .delete-button {
            background-color: red;
            color: white;
            padding: 10px 20px;
            border: none;
            cursor: pointer;
            font-size: 16px;
        }
        .popup {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color: white;
            padding: 20px;
            box-shadow: 0px 0px 10px rgba(0, 0, 0, 0.5);
        }
        .popup button {
            margin: 5px;
            padding: 10px 20px;
            cursor: pointer;
        }
    </style>
</head>
<body>

<?php if ($showButton): ?>
    <form method="POST" action="">
        <button type="button" class="delete-button" onclick="showPopup()">Delete Checks</button>
    </form>
<?php endif; ?>

<div id="popup" class="popup">
    <p>Are you sure? This will delete all existing checks from the database.</p>
    <form method="POST" action="">
        <button type="submit" name="confirm_delete" value="yes">Yes, Delete</button>
        <button type="button" onclick="hidePopup()">No</button>
    </form>
</div>

<script>
    function showPopup() {
        document.getElementById('popup').style.display = 'block';
    }

    function hidePopup() {
        document.getElementById('popup').style.display = 'none';
    }
</script>
<?php include 'templates/footer.php'; ?>
</body>
</html>
