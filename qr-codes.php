<?php
// This page is now a component loaded by admin.php
// It expects $pdo, $user, $userRole, $currentStation, $userStations to be available.

// Ensure composer autoload is available
if (!class_exists(Endroid\QrCode\Builder\Builder::class)) {
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
    } elseif (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
        require_once dirname(__DIR__) . '/vendor/autoload.php';
    }
}

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;

if (!$currentStation) {
    echo "<div class='alert alert-warning'>Please select a station to generate QR codes.</div>";
    return; // Exit if no station context
}

$station_id = $currentStation['id'];
$station_name = $currentStation['name'];
$station_description = $currentStation['description'] ?? '';

// $version and $IS_DEMO would typically be managed by admin.php or passed if needed.
// For simplicity, if styles.css and qrcodes.css are general, they might be loaded by admin.php's main template.
// If they are very specific to this component, they can be included here or linked.
// $version = $_SESSION['version'] ?? '1.0'; // Example, if needed for cache busting
// $IS_DEMO = isset($_SESSION['IS_DEMO']) && $_SESSION['IS_DEMO'] === true;


// Fetch trucks filtered by current station
$trucks_query = $pdo->prepare('SELECT * FROM trucks WHERE station_id = ? ORDER BY name');
$trucks_query->execute([$station_id]);
$trucks = $trucks_query->fetchAll(PDO::FETCH_ASSOC);

// Function to get a specific station setting (could be moved to a helper if used elsewhere)
function get_station_setting_for_qr($pdo_conn, $setting_key, $station_id_for_setting, $default_value = null) {
    try {
        $stmt = $pdo_conn->prepare('SELECT setting_value FROM station_settings WHERE station_id = :station_id AND setting_key = :setting_key');
        $stmt->execute([':station_id' => $station_id_for_setting, ':setting_key' => $setting_key]);
        $value = $stmt->fetchColumn();
        return ($value !== false) ? $value : $default_value;
    } catch (Exception $e) {
        // Log error or handle as needed
        error_log("Error fetching setting '$setting_key' for station '$station_id_for_setting': " . $e->getMessage());
        return $default_value;
    }
}


// Base URL construction - needs to be robust
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
// Assuming admin.php is in the root, and other target scripts (index.php, set_security_cookie.php, check_locker_items.php) are also in the root.
// Adjust if file structure is different.
$base_app_url = $protocol . $host . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'); // Path to current script (admin.php)
if ($base_app_url === $protocol . $host) { // If admin.php is in root
    $base_app_url = $protocol . $host; // Keep it clean
}
// If admin.php is in a subdir like /admin, and target scripts are in root, adjust:
// Example: $app_root_url = preg_replace('/\/admin$/', '', $base_app_url);
// For now, assume target scripts are relative to where admin.php is, or at root.
// Let's assume target scripts (index.php, set_security_cookie.php, check_locker_items.php) are at the web root.
$web_root_url = $protocol . $host;


?>
<div class="component-container qr-codes-page-container">
    <!-- Link component-specific CSS if not already loaded by admin.php -->
    <!-- <link rel="stylesheet" href="styles/qrcodes.css?id=<?= ''//$version ?> "> -->
    <style>
        /* Minimal styles for qr-codes component, assuming admin.php provides global styles */
        .qr-codes-page-container { padding: 20px; }
        .station-info-qr { /* Renamed to avoid conflict if admin.php has .station-info */
            text-align: center;
            margin-bottom: 30px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 5px;
        }
        .station-name-qr { /* Renamed */
            font-size: 18px;
            font-weight: bold;
            color: #12044C;
        }
        .qr-codes-page-container h1, .qr-codes-page-container h2 { color: #12044C; margin-bottom: 15px; }
        .button-container-qr { /* Renamed */
             text-align: center; margin-bottom: 20px;
        }
        .msg-container-qr { /* Renamed */
            text-align: center; margin-bottom:20px; padding:10px; background-color:#eef; border-radius:5px;
        }
        .locker-grid { display: flex; flex-wrap: wrap; gap: 20px; justify-content: center; }
        .locker-item {
            border: 1px solid #ddd;
            padding: 15px;
            text-align: center;
            width: 200px; /* Adjust as needed */
            border-radius: 8px;
            background-color: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .locker-item p { margin: 0 0 10px 0; font-weight: bold; }
        .locker-item img { max-width: 100%; height: auto; border: 1px solid #eee; }
        .alert { padding: 15px; margin-bottom: 20px; border: 1px solid transparent; border-radius: .25rem; }
        .alert-warning { color: #856404; background-color: #fff3cd; border-color: #ffeeba; }
        .button.touch-button { /* Ensure styles from admin.php apply or define here */
            display: inline-block;
            padding: 10px 20px;
            background-color: #12044C;
            color: white;
            text-decoration: none;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        .button.touch-button:hover { background-color: #0056b3; }

    </style>

    <div class="station-info-qr">
        <div class="station-name-qr"><?= htmlspecialchars($station_name) ?></div>
        <?php if ($station_description): ?>
            <div style="color: #666; margin-top: 5px;"><?= htmlspecialchars($station_description) ?></div>
        <?php endif; ?>
    </div>

    <h1>Locker QR Codes</h1>
    <div class="button-container-qr">
        <!-- The PDF link might need to be adjusted if qr-codes-pdf.php also becomes a component or needs station context -->
        <a href="qr-codes-pdf.php?station_id=<?= $station_id ?>" class="button touch-button" target="_blank">A4 PDF of QR Codes</a>
    </div>
    <div class="msg-container-qr">
        <div>
            This PDF can be used to print out 45mm labels. <br>
            Sized for Avery L7124 Glossy Square Labels. <br>
        </div>
    </div>

    <div class="locker-grid">
        <?php
        $top_level_locker_url = $base_app_url . '/index.php?station_id=' . $station_id; // Using $base_app_url

        $result_top = Builder::create()
            ->writer(new PngWriter())
            ->data($top_level_locker_url)
            ->encoding(new Encoding('UTF-8'))
            ->errorCorrectionLevel(new ErrorCorrectionLevelHigh())
            ->size(150)->margin(5)->build(); // Smaller size for grid display
        $qrcode_base64_top = base64_encode($result_top->getString());
        ?>
        <div class="locker-item">
            <p>Station Access: <?= htmlspecialchars($station_name) ?></p>
            <a href="<?= $top_level_locker_url ?>" target="_blank">
                <img src="data:image/png;base64,<?= $qrcode_base64_top ?>" alt="QR Code for Station <?= htmlspecialchars($station_name) ?>">
            </a>
        </div>

        <?php
        // Generate Security QR Code
        $security_code = get_station_setting_for_qr($pdo, 'security_code', $station_id, '');
        if (!empty($security_code)) {
            $security_url = $base_app_url . '/set_security_cookie.php?code=' . urlencode($security_code) . '&station_id=' . $station_id . '&station_name=' . urlencode($station_name); // Using $base_app_url
            
            $security_result_qr = Builder::create()
                ->writer(new PngWriter())->data($security_url)->encoding(new Encoding('UTF-8'))
                ->errorCorrectionLevel(new ErrorCorrectionLevelHigh())->size(150)->margin(5)->build();
            $security_qrcode_base64 = base64_encode($security_result_qr->getString());
        ?>
        <div class="locker-item" style="border: 2px solid #dc3545; background-color: #f8d7da;">
            <p style="color: #721c24; font-weight: bold;">SECURITY - <?= htmlspecialchars($station_name) ?></p>
            <a href="<?= $security_url ?>" target="_blank">
                <img src="data:image/png;base64,<?= $security_qrcode_base64 ?>" alt="Security QR Code for <?= htmlspecialchars($station_name) ?>">
            </a>
            <p style="font-size: 11px; color: #721c24; margin-top: 5px;">Scan to enable security</p>
        </div>
        <?php } ?>
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
            $query_lockers = $pdo->prepare('SELECT * FROM lockers WHERE truck_id = :truck_id ORDER BY name');
            $query_lockers->execute(['truck_id' => $truck['id']]);
            $lockers = $query_lockers->fetchAll(PDO::FETCH_ASSOC);
            ?>

            <?php if (!empty($lockers)): ?>
                <div class="locker-grid">
                    <?php foreach ($lockers as $locker): ?>
                        <?php
                        $locker_check_url = $base_app_url . '/check_locker_items.php?truck_id=' . $truck['id'] . '&locker_id=' . $locker['id'] . '&station_id=' . $station_id; // Using $base_app_url
                        $result_locker_qr = Builder::create()
                            ->writer(new PngWriter())->data($locker_check_url)->encoding(new Encoding('UTF-8'))
                            ->errorCorrectionLevel(new ErrorCorrectionLevelHigh())->size(150)->margin(5)->build();
                        $qrcode_base64_locker = base64_encode($result_locker_qr->getString());
                        ?>
                        <div class="locker-item">
                            <p><?= htmlspecialchars($locker['name']) ?></p>
                            <a href="<?= $locker_check_url ?>" target="_blank">
                                <img src="data:image/png;base64,<?= $qrcode_base64_locker ?>" alt="QR Code for <?= htmlspecialchars($locker['name']) ?>">
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="text-align:center; color:#666;">No lockers found for this truck.</p>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Removed Back to Admin button -->
</div>
