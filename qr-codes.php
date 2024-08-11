<?php
require 'vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;

include 'db.php'; // Adjust to your database connection script

$db = get_db_connection();

// Fetch all trucks and lockers
$trucks = $db->query('SELECT * FROM trucks')->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Locker QR Codes</title>
    <link rel="stylesheet" href="styles/qrcodes.css"> <!-- Optional: Add your CSS file for styling -->
</head>
<body>

<h1>Locker QR Codes</h1>

<?php

  $locker_url = 'http://' . $_SERVER['HTTP_HOST'] . '/index.php';

                // Generate the QR code
                $result = Builder::create()
                    ->writer(new PngWriter())
                    ->data($locker_url)
                    ->encoding(new Encoding('UTF-8'))
                    ->errorCorrectionLevel(new ErrorCorrectionLevelHigh())
                    ->size(300)
                    ->margin(10)
                    ->build();

                // Get the QR code as a Base64-encoded string
                $qrcode_base64 = base64_encode($result->getString());
                ?>

                <div class="locker-item">
                    <p>Top Level</p>
                    <a href="<?= $locker_url ?>" target="_blank">
                        <img src="data:image/png;base64,<?= $qrcode_base64 ?>" alt="QR Code for <?= htmlspecialchars($locker['name']) ?>">
                    </a>
                    <p><a href="<?= $locker_url ?>" target="_blank"><?= $locker_url ?></a></p>
                </div>

?>

<?php foreach ($trucks as $truck): ?>
    <h2><?= htmlspecialchars($truck['name']) ?></h2>
    <?php
    $query = $db->prepare('SELECT * FROM lockers WHERE truck_id = :truck_id');
    $query->execute(['truck_id' => $truck['id']]);
    $lockers = $query->fetchAll(PDO::FETCH_ASSOC);
    ?>

    <?php if (!empty($lockers)): ?>
        <div class="locker-grid">
            <?php foreach ($lockers as $locker): ?>
                <?php
                // Generate the URL with both truck_id and locker_id as parameters
                $locker_url = 'http://' . $_SERVER['HTTP_HOST'] . '/check_locker_items.php?truck_id=' . $truck['id'] . '&locker_id=' . $locker['id'];

                // Generate the QR code
                $result = Builder::create()
                    ->writer(new PngWriter())
                    ->data($locker_url)
                    ->encoding(new Encoding('UTF-8'))
                    ->errorCorrectionLevel(new ErrorCorrectionLevelHigh())
                    ->size(300)
                    ->margin(10)
                    ->build();

                // Get the QR code as a Base64-encoded string
                $qrcode_base64 = base64_encode($result->getString());
                ?>

                <div class="locker-item">
                    <p><?= htmlspecialchars($locker['name']) ?></p>
                    <a href="<?= $locker_url ?>" target="_blank">
                        <img src="data:image/png;base64,<?= $qrcode_base64 ?>" alt="QR Code for <?= htmlspecialchars($locker['name']) ?>">
                    </a>
                    <p><a href="<?= $locker_url ?>" target="_blank"><?= $locker_url ?></a></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p>No lockers found for this truck.</p>
    <?php endif; ?>
<?php endforeach; ?>

<?php include 'templates/footer.php'; ?>

</body>
</html>