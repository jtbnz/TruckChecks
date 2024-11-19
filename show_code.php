<?php 
include('config.php');
if (DEBUG) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    echo "Debug mode is on";
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
}



include 'db.php';
include 'templates/header.php';

// Check if the user is logged in
if (isset($_COOKIE['logged_in_' . DB_NAME]) && $_COOKIE['logged_in_' . DB_NAME] == 'true') {
    header('Location: login.php');
    exit;
}

$pdo = get_db_connection();


// Fetch the latest check date
$codeQuery = "select code from protection_codes order by id desc limit 1";
$codeStmt = $pdo->prepare($codeQuery);
$codeStmt->execute();
$code = $codeStmt->fetch(PDO::FETCH_ASSOC)['code'];


echo "<script>
    const storedCode = localStorage.getItem('protection_code');
    const newCode = '$code';
    if (storedCode === newCode) {
        alert('The code is already set.');
    } else {
        localStorage.setItem('protection_code', newCode);
        alert('Code stored successfully.');
    }
</script>";

echo '<a href="index.php">Return</a>';

include 'templates/footer.php';
?>