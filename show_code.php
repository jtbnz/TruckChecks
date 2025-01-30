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

require 'vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;


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



echo "<h1>Storing Protection Code</h1>";

$current_directory = dirname($_SERVER['REQUEST_URI']);
$url = 'https://' . $_SERVER['HTTP_HOST'] . $current_directory .  '/show_code.php';

$result = Builder::create()
->writer(new PngWriter())
->data($url)
->encoding(new Encoding('UTF-8'))
->errorCorrectionLevel(new ErrorCorrectionLevelHigh())
->size(600)
->margin(0)
->build();

// Get the QR code as a Base64-encoded string
$qrcode_base64 = base64_encode($result->getString());

?>
<div style="display: flex; justify-content: center; align-items: center; height: 100vh; flex-direction: column;">
    <h2>Use this code to store the Security key</h2>
    <a href="<?= $url ?>" target="_blank">
        <img src="data:image/png;base64,<?= $qrcode_base64 ?>" alt="QR Code for key storage">
    </a>
</div>
<?php
include 'templates/footer.php';
?>