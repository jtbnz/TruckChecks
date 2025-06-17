<?php
// admin_modules/qr_codes.php

// Ensure session is started if not already
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Determine the correct base path for includes
$basePath = __DIR__ . '/../'; // From admin_modules/ to project root

// Include necessary core files
require_once $basePath . 'config.php';
require_once $basePath . 'db.php';
require_once $basePath . 'auth.php';

// Initialize database connection
$pdo = get_db_connection();

// Authentication and User Context
if (!isset($user) || !$user) {
    requireAuth();
    $user = getCurrentUser();
}

$userRole = $user['role'] ?? null;

// Station determination logic (consistent with lockers.php)
$current_station_id = null;
$current_station_name = "No station selected";
$current_station_description = ""; // Initialize

if ($userRole === 'superuser') {
    $stationData = getCurrentStation(); // Uses $pdo internally
    if ($stationData && isset($stationData['id'])) {
        $current_station_id = $stationData['id'];
        $current_station_name = $stationData['name'];
        // Fetch description if available - assuming 'stations' table has 'description'
        try {
            $stmt_desc = $pdo->prepare("SELECT description FROM stations WHERE id = ?");
            $stmt_desc->execute([$current_station_id]);
            $current_station_description = $stmt_desc->fetchColumn() ?: '';
        } catch (PDOException $e) { /* ignore, description is optional */ }
    }
} elseif ($userRole === 'station_admin') {
    $userStationsForModule = [];
    try {
        if (isset($user['id'])) {
            $stmt_ua = $pdo->prepare("SELECT s.id, s.name, s.description FROM stations s JOIN user_stations us ON s.id = us.station_id WHERE us.user_id = ? ORDER BY s.name");
            $stmt_ua->execute([$user['id']]);
            $userStationsForModule = $stmt_ua->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log("Error fetching user stations in qr_codes.php: " . $e->getMessage());
    }

    if (count($userStationsForModule) === 1) {
        $current_station_id = $userStationsForModule[0]['id'];
        $current_station_name = $userStationsForModule[0]['name'];
        $current_station_description = $userStationsForModule[0]['description'] ?? '';
        if (session_status() == PHP_SESSION_ACTIVE && (!isset($_SESSION['selected_station_id']) || $_SESSION['selected_station_id'] != $current_station_id) ) {
            $_SESSION['selected_station_id'] = $current_station_id;
        }
    } elseif (isset($_SESSION['selected_station_id'])) {
        $is_valid_selection = false;
        foreach ($userStationsForModule as $s) {
            if ($s['id'] == $_SESSION['selected_station_id']) {
                $current_station_id = $s['id'];
                $current_station_name = $s['name'];
                $current_station_description = $s['description'] ?? '';
                $is_valid_selection = true;
                break;
            }
        }
        if (!$is_valid_selection) {
            if (session_status() == PHP_SESSION_ACTIVE) unset($_SESSION['selected_station_id']);
            $current_station_id = null;
            $current_station_name = "No valid station selected";
        }
    } else {
         $current_station_id = null;
         $current_station_name = count($userStationsForModule) > 0 ? "Please select a station" : "No stations assigned";
    }
}


// Ensure composer autoload for QR Code library is available
// Adjusted paths to be relative from the project root, assuming vendor is at project root
if (!class_exists(Endroid\QrCode\Builder\Builder::class)) {
    if (file_exists($basePath . 'vendor/autoload.php')) {
        require_once $basePath . 'vendor/autoload.php';
    } else {
        // Fallback if script is run from a different context or vendor is elsewhere
        // This might indicate a setup issue if not found.
        echo "<div class='alert alert-danger'>QR Code library not found. Please run composer install.</div>";
        return;
    }
}

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;

if (!$current_station_id) {
    echo "<div class='notice notice-warning' style='margin:20px;'><p><i>⚠️</i> Please select a station to generate QR codes.</p>";
    if ($userRole === 'superuser') {
        echo "<p>As a superuser, you can select a station using the dropdown in the sidebar header.</p>";
    } elseif ($userRole === 'station_admin' && (!isset($userStationsForModule) || count($userStationsForModule) !== 1)) {
         echo "<p>As a station admin, please ensure a single station is active or selected. If you manage multiple stations, pick one. If you manage none, please contact a superuser.</p>";
    }
    echo "</div>";
    return; // Exit if no station context
}

// Fetch trucks filtered by current station
$trucks_query = $pdo->prepare('SELECT * FROM trucks WHERE station_id = ? ORDER BY name');
$trucks_query->execute([$current_station_id]);
$trucks = $trucks_query->fetchAll(PDO::FETCH_ASSOC);

// Function to get a specific station setting
function get_station_setting_for_qr_module($pdo_conn, $setting_key, $station_id_for_setting, $default_value = null) {
    try {
        $stmt = $pdo_conn->prepare('SELECT setting_value FROM station_settings WHERE station_id = :station_id AND setting_key = :setting_key');
        $stmt->execute([':station_id' => $station_id_for_setting, ':setting_key' => $setting_key]);
        $value = $stmt->fetchColumn();
        return ($value !== false) ? $value : $default_value;
    } catch (Exception $e) {
        error_log("Error fetching setting '$setting_key' for station '$station_id_for_setting': " . $e->getMessage());
        return $default_value;
    }
}

// Base URL construction
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
// $_SERVER['SCRIPT_NAME'] will be /admin.php (or whatever loads this module)
// We want the path to the application root.
// If admin.php is at /admin/admin.php, dirname gives /admin. If admin.php is at /admin.php, dirname gives /.
$script_dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
// If admin.php is in a subdirectory (e.g., /admin), $script_dir will be /admin. We need to go up one level.
// If admin.php is at the root, $script_dir will be empty or /.
// A common pattern is to define APP_ROOT_PATH or BASE_URL in config.php
// For now, assuming target scripts (index.php, etc.) are at the web root relative to $host.
$base_app_url = $protocol . $host . $script_dir; // This should point to the directory containing admin.php
// If index.php is in the same dir as admin.php, this is fine.
// If index.php is one level up from admin.php (e.g. admin.php is in /admin subdir), adjust:
// $base_app_url = $protocol . $host . rtrim(dirname($script_dir), '/\\');

// Let's assume index.php, set_security_cookie.php, check_locker_items.php are in the project root ($basePath from includes)
// So, if SCRIPT_NAME is /admin/admin.php, dirname is /admin. We need to construct URL to project root.
// A robust way is to define a base URL in config.php.
// For now, if admin.php is in a subdir, this might need adjustment.
// Let's assume admin.php is at the root for simplicity of URL generation for now.
// If admin.php is in /admin/, then $script_dir is /admin.
// $app_root_url = $protocol . $host . (str_replace('/admin', '', $script_dir)); // Example if admin is in /admin/
// This is tricky. Let's use a simpler assumption: target scripts are at the same level or one level up.
// The original qr-codes.php used $base_app_url = $protocol . $host . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
// This means URLs like $base_app_url . '/index.php'. If admin.php is in root, this is $protocol.$host.'/index.php'.
// If admin.php is in /admin/admin.php, this is $protocol.$host.'/admin/index.php' which is likely wrong if index.php is at root.

// Let's assume the target scripts (index.php, etc.) are at the web root.
// $web_root_url = $protocol . $host; // Simplest assumption for now.
// The original file's $base_app_url logic:
$app_path_segment = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($app_path_segment === '/' || $app_path_segment === '') { // If admin.php is in root
    $base_app_url_for_qr = $protocol . $host;
} else { // If admin.php is in a subdirectory like /admin
    // We need to construct URLs to the application root, not relative to the admin subdir for target scripts.
    // This assumes target scripts like index.php are at the actual web root, not inside the /admin subdir.
    // A more robust solution would be a BASE_URL defined in config.php.
    // For now, let's assume the original logic was trying to point to the directory of admin.php
    $base_app_url_for_qr = $protocol . $host . $app_path_segment;
    // If target scripts are actually at the web root, and admin.php is in /admin, then:
    // $base_app_url_for_qr = $protocol . $host; // Overwrite to point to web root.
    // This needs to be confirmed based on actual deployment structure.
    // Given the original file, it seems it expected target scripts to be relative to its own location.
    // Let's stick to the original file's interpretation of $base_app_url for now.
}
// The original file had: $base_app_url = $protocol . $host . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
// This means if SCRIPT_NAME is /admin.php, dirname is /, rtrim is empty, so $protocol.$host
// If SCRIPT_NAME is /admin/admin.php, dirname is /admin, rtrim is /admin, so $protocol.$host/admin
// This seems to imply target scripts are relative to the location of the script itself.
// Let's use this, but rename to avoid conflict if admin.php defines $base_app_url differently.
$qr_code_target_base_url = $protocol . $host . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');


?>
<div class="component-container qr-codes-page-container">
    <style>
        .qr-codes-page-container { padding: 20px; }
        .station-info-qr {
            text-align: center;
            margin-bottom: 30px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 5px;
            border-left: 4px solid #12044C;
        }
        .station-name-qr {
            font-size: 1.2em; /* Adjusted from 18px to be relative */
            font-weight: bold;
            color: #12044C;
        }
        .qr-codes-page-container h1, .qr-codes-page-container h2 { color: #12044C; margin-bottom: 15px; }
        .button-container-qr { text-align: center; margin-bottom: 20px; }
        .msg-container-qr { text-align: center; margin-bottom:20px; padding:10px; background-color:#eef; border-radius:5px; }
        .locker-grid { display: flex; flex-wrap: wrap; gap: 20px; justify-content: center; }
        .locker-item {
            border: 1px solid #ddd;
            padding: 15px;
            text-align: center;
            width: 200px;
            border-radius: 8px;
            background-color: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .locker-item p { margin: 0 0 10px 0; font-weight: bold; }
        .locker-item img { max-width: 100%; height: auto; border: 1px solid #eee; }
        .alert { padding: 15px; margin-bottom: 20px; border: 1px solid transparent; border-radius: .25rem; }
        .alert-warning { color: #856404; background-color: #fff3cd; border-color: #ffeeba; }
        /* Assuming .button styles are globally available from admin.php's main CSS */
    </style>

    <div class="station-info-qr">
        <div class="station-name-qr"><?= htmlspecialchars($current_station_name) ?></div>
        <?php if (!empty($current_station_description)): ?>
            <div style="color: #666; margin-top: 5px;"><?= htmlspecialchars($current_station_description) ?></div>
        <?php endif; ?>
    </div>

    <h1>Locker QR Codes</h1>
    <div class="button-container-qr">
        <a href="<?= htmlspecialchars($qr_code_target_base_url . '/qr-codes-pdf.php?station_id=' . $current_station_id) ?>" class="button" target="_blank">A4 PDF of QR Codes</a>
    </div>
    <div class="msg-container-qr">
        <div>
            This PDF can be used to print out 45mm labels. <br>
            Sized for Avery L7124 Glossy Square Labels. <br>
        </div>
    </div>

    <div class="locker-grid">
        <?php
        // URL for station access QR code (points to index.php at the application root)
        $top_level_locker_url = $qr_code_target_base_url . '/index.php?station_id=' . $current_station_id;

        $result_top = Builder::create()
            ->writer(new PngWriter())
            ->data($top_level_locker_url)
            ->encoding(new Encoding('UTF-8'))
            ->errorCorrectionLevel(new ErrorCorrectionLevelHigh())
            ->size(150)->margin(5)->build();
        $qrcode_base64_top = base64_encode($result_top->getString());
        ?>
        <div class="locker-item">
            <p>Station Access: <?= htmlspecialchars($current_station_name) ?></p>
            <a href="<?= htmlspecialchars($top_level_locker_url) ?>" target="_blank">
                <img src="data:image/png;base64,<?= $qrcode_base64_top ?>" alt="QR Code for Station <?= htmlspecialchars($current_station_name) ?>">
            </a>
        </div>

        <?php
        // Generate Security QR Code
        $security_code = get_station_setting_for_qr_module($pdo, 'security_code', $current_station_id, '');
        if (!empty($security_code)) {
            // URL for security cookie (points to set_security_cookie.php at the application root)
            $security_url = $qr_code_target_base_url . '/set_security_cookie.php?code=' . urlencode($security_code) . '&station_id=' . $current_station_id . '&station_name=' . urlencode($current_station_name);
            
            $security_result_qr = Builder::create()
                ->writer(new PngWriter())->data($security_url)->encoding(new Encoding('UTF-8'))
                ->errorCorrectionLevel(new ErrorCorrectionLevelHigh())->size(150)->margin(5)->build();
            $security_qrcode_base64 = base64_encode($security_result_qr->getString());
        ?>
        <div class="locker-item" style="border: 2px solid #dc3545; background-color: #f8d7da;">
            <p style="color: #721c24; font-weight: bold;">SECURITY - <?= htmlspecialchars($current_station_name) ?></p>
            <a href="<?= htmlspecialchars($security_url) ?>" target="_blank">
                <img src="data:image/png;base64,<?= $security_qrcode_base64 ?>" alt="Security QR Code for <?= htmlspecialchars($current_station_name) ?>">
            </a>
            <p style="font-size: 11px; color: #721c24; margin-top: 5px;">Scan to enable security</p>
        </div>
        <?php } ?>
    </div>


    <?php if (empty($trucks)): ?>
        <div style="text-align: center; padding: 40px; color: #666;">
            <h3>No Trucks Found</h3>
            <p>No trucks are configured for this station. Please add trucks in the "Lockers & Items" management page.</p>
        </div>
    <?php else: ?>
        <?php foreach ($trucks as $truck): ?>
            <h2><?= htmlspecialchars($truck['name']) ?></h2>
            <?php
            $query_lockers = $pdo->prepare('SELECT * FROM lockers WHERE truck_id = :truck_id ORDER BY name');
            $query_lockers->execute(['truck_id' => $truck['id']]);
            $lockers = $query_lockers->fetchAll(PDO::FETCH_ASSOC);
            ?>

            <div class="locker-grid">
                <?php
                // Add truck-specific QR code for direct access to truck
                $truck_check_url = $qr_code_target_base_url . '/check_locker_items.php?truck_id=' . $truck['id'];
                $result_truck_qr = Builder::create()
                    ->writer(new PngWriter())->data($truck_check_url)->encoding(new Encoding('UTF-8'))
                    ->errorCorrectionLevel(new ErrorCorrectionLevelHigh())->size(150)->margin(5)->build();
                $qrcode_base64_truck = base64_encode($result_truck_qr->getString());
                ?>
                <div class="locker-item" style="border: 2px solid #28a745; background-color: #d4edda;">
                    <p style="color: #155724; font-weight: bold;">TRUCK ACCESS - <?= htmlspecialchars($truck['name']) ?></p>
                    <a href="<?= htmlspecialchars($truck_check_url) ?>" target="_blank">
                        <img src="data:image/png;base64,<?= $qrcode_base64_truck ?>" alt="Truck QR Code for <?= htmlspecialchars($truck['name']) ?>">
                    </a>
                    <p style="font-size: 11px; color: #155724; margin-top: 5px;">Scan to access truck</p>
                </div>

                <?php if (!empty($lockers)): ?>
                    <?php foreach ($lockers as $locker): ?>
                        <?php
                        // URL for individual locker check (points to check_locker_items.php at the application root)
                        $locker_check_url = $qr_code_target_base_url . '/check_locker_items.php?truck_id=' . $truck['id'] . '&locker_id=' . $locker['id'] . '&station_id=' . $current_station_id;
                        $result_locker_qr = Builder::create()
                            ->writer(new PngWriter())->data($locker_check_url)->encoding(new Encoding('UTF-8'))
                            ->errorCorrectionLevel(new ErrorCorrectionLevelHigh())->size(150)->margin(5)->build();
                        $qrcode_base64_locker = base64_encode($result_locker_qr->getString());
                        ?>
                        <div class="locker-item">
                            <p><?= htmlspecialchars($locker['name']) ?></p>
                            <a href="<?= htmlspecialchars($locker_check_url) ?>" target="_blank">
                                <img src="data:image/png;base64,<?= $qrcode_base64_locker ?>" alt="QR Code for <?= htmlspecialchars($locker['name']) ?>">
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="text-align:center; color:#666;">No lockers found for this truck.</p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
