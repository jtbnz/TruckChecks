<?php
require 'vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;

include 'db.php';
include_once('auth.php');

// Require authentication and station context
$station = requireStation();
$user = getCurrentUser();

$db = get_db_connection();

// Check if session has not already been started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if the version session variable is not set
if (!isset($_SESSION['version'])) {
    // Get the latest Git tag version
    $version = trim(exec('git describe --tags $(git rev-list --tags --max-count=1)'));

    // Set the session variable
    $_SESSION['version'] = $version;
} else {
    // Use the already set session variable
    $version = $_SESSION['version'];
}

$IS_DEMO = isset($_SESSION['IS_DEMO']) && $_SESSION['IS_DEMO'] === true;

// Fetch trucks filtered by current station
$trucks_query = $db->prepare('SELECT * FROM trucks WHERE station_id = ? ORDER BY name');
$trucks_query->execute([$station['id']]);
$trucks = $trucks_query->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Locker QR Codes</title>
    <link rel="stylesheet" href="styles/styles.css?id=<?php  echo $version;  ?> ">
    <link rel="stylesheet" href="styles/qrcodes.css?id=<?php  echo $version;  ?> "> 
    

</head>
<body class="<?php echo $IS_DEMO ? 'demo-mode' : ''; ?>">

<style>
    .station-info {
        text-align: center;
        margin-bottom: 30px;
        padding: 15px;
        background-color: #f8f9fa;
        border-radius: 5px;
    }

    .station-name {
        font-size: 18px;
        font-weight: bold;
        color: #12044C;
    }
</style>

<div class="station-info">
    <div class="station-name"><?= htmlspecialchars($station['name']) ?></div>
    <?php if ($station['description']): ?>
        <div style="color: #666; margin-top: 5px;"><?= htmlspecialchars($station['description']) ?></div>
    <?php endif; ?>
</div>

<h1>Locker QR Codes</h1>
<div class="button-container" style="margin-top: 20px;">
    <a href="qr-codes-pdf.php" class="button touch-button">A4 PDF of QR Codes</a>
</div>
<div class="msg-container">
<div class="msg-msg">
    This pdf can be used to print out 45mm labels. <br>
    Sized for Avery L7124 Glossy Square Labels. <br>
</div>
</div>

<?php
$current_directory = dirname($_SERVER['REQUEST_URI']);

$locker_url = 'https://' . $_SERVER['HTTP_HOST'] . $current_directory .  '/index.php';

// Generate the QR code
$result = Builder::create()
    ->writer(new PngWriter())
    ->data($locker_url)
    ->encoding(new Encoding('UTF-8'))
    ->errorCorrectionLevel(new ErrorCorrectionLevelHigh())
    ->size(300)
    ->margin(0)
    ->build();

// Get the QR code as a Base64-encoded string
$qrcode_base64 = base64_encode($result->getString());
?>

<div class="locker-item">
    <p>Top Level - <?= htmlspecialchars($station['name']) ?></p>
    <a href="<?= $locker_url ?>" target="_blank">
        <img src="data:image/png;base64,<?= $qrcode_base64 ?>" alt="QR Code for Top Level">
    </a>
    <!-- <p><a href="<?= $locker_url ?>" target="_blank"><?= $locker_url ?></a></p> -->
</div>

<?php if (empty($trucks)): ?>
    <div style="text-align: center; padding: 40px; color: #666;">
        <h3>No Trucks Found</h3>
        <p>No trucks are configured for this station. Please add trucks in the admin panel.</p>
    </div>
<?php else: ?>
    <?php foreach ($trucks as $truck): ?>
        <h2><?= htmlspecialchars($truck['name']) ?></h2>
        <?php
        $query = $db->prepare('SELECT * FROM lockers WHERE truck_id = :truck_id ORDER BY name');
        $query->execute(['truck_id' => $truck['id']]);
        $lockers = $query->fetchAll(PDO::FETCH_ASSOC);
        ?>

        <?php if (!empty($lockers)): ?>
            <div class="locker-grid">
                <?php foreach ($lockers as $locker): ?>
                    <?php
                    // Generate the URL with both truck_id and locker_id as parameters
                    $locker_url = 'https://' . $_SERVER['HTTP_HOST'] . $current_directory . '/check_locker_items.php?truck_id=' . $truck['id'] . '&locker_id=' . $locker['id'];

                    // Generate the QR code
                    $result = Builder::create()
                        ->writer(new PngWriter())
                        ->data($locker_url)
                        ->encoding(new Encoding('UTF-8'))
                        ->errorCorrectionLevel(new ErrorCorrectionLevelHigh())
                        ->size(300)
                        ->margin(0)
                        ->build();

                    // Get the QR code as a Base64-encoded string
                    $qrcode_base64 = base64_encode($result->getString());
                    ?>

                    <div class="locker-item">
                        <p><?= htmlspecialchars($locker['name']) ?></p>
                        <a href="<?= $locker_url ?>" target="_blank">
                            <img src="data:image/png;base64,<?= $qrcode_base64 ?>" alt="QR Code for <?= htmlspecialchars($locker['name']) ?>">
                        </a>
                        <!-- <p><a href="<?= $locker_url ?>" target="_blank"><?= $locker_url ?></a></p> -->
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p>No lockers found for this truck.</p>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>

<div class="button-container" style="margin-top: 30px;">
    <a href="admin.php" class="button touch-button">← Back to Admin</a>
</div>

<?php include 'templates/footer.php'; ?>

</body>
</html>
