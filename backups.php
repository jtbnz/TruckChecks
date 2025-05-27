<?php
include_once('auth.php');
include_once('config.php');
include_once('db.php');

// Require authentication
requireAuth();

$user = getCurrentUser();
$userRole = $user['role'];
$currentStation = getCurrentStation();

$db = get_db_connection();

// Handle backup download
if (isset($_POST['download_backup'])) {
    try {
        $sql_dump = "-- TruckChecks Database Backup\n";
        $sql_dump .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n";
        $sql_dump .= "-- User: " . htmlspecialchars($user['username']) . " (" . $userRole . ")\n";
        
        if ($userRole === 'superuser') {
            $sql_dump .= "-- Backup Type: Full System Backup\n\n";
            $filename = 'truckChecks_full_backup_' . date('Y-m-d_H-i-s') . '.sql';
            
            // Full backup - all tables
            $tables = [];
            $result = $db->query("SHOW TABLES");
            while ($row = $result->fetch(PDO::FETCH_NUM)) {
                $tables[] = $row[0];
            }
            
            $sql_dump .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
            
            foreach ($tables as $table) {
                // Get table structure
                $result = $db->query("SHOW CREATE TABLE `$table`");
                $row = $result->fetch(PDO::FETCH_NUM);
                $sql_dump .= "DROP TABLE IF EXISTS `$table`;\n";
                $sql_dump .= $row[1] . ";\n\n";
                
                // Get table data
                $result = $db->query("SELECT * FROM `$table`");
                $rows = $result->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($rows)) {
                    $columns = array_keys($rows[0]);
                    $sql_dump .= "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES\n";
                    
                    $values = [];
                    foreach ($rows as $row) {
                        $escaped_values = [];
                        foreach ($row as $value) {
                            if ($value === null) {
                                $escaped_values[] = 'NULL';
                            } else {
                                $escaped_values[] = "'" . addslashes($value) . "'";
                            }
                        }
                        $values[] = "(" . implode(', ', $escaped_values) . ")";
                    }
                    
                    $sql_dump .= implode(",\n", $values) . ";\n\n";
                }
            }
            
            $sql_dump .= "SET FOREIGN_KEY_CHECKS = 1;\n";
            
        } else {
            // Station admin - station-specific backup
            if (!$currentStation) {
                throw new Exception("No station selected. Please select a station first.");
            }
            
            $stationId = $currentStation['id'];
            $stationName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $currentStation['name']);
            
            $sql_dump .= "-- Backup Type: Station Backup for " . htmlspecialchars($currentStation['name']) . "\n";
            $sql_dump .= "-- Station ID: " . $stationId . "\n\n";
            $filename = 'truckChecks_station_' . $stationName . '_backup_' . date('Y-m-d_H-i-s') . '.sql';
            
            $sql_dump .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
            
            // Station table
            $sql_dump .= "-- Station Information\n";
            $stmt = $db->prepare("SELECT * FROM stations WHERE id = ?");
            $stmt->execute([$stationId]);
            $station = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($station) {
                $sql_dump .= "INSERT INTO stations (id, name, description, created_at, updated_at) VALUES (";
                $sql_dump .= $station['id'] . ", ";
                $sql_dump .= "'" . addslashes($station['name']) . "', ";
                $sql_dump .= ($station['description'] ? "'" . addslashes($station['description']) . "'" : "NULL") . ", ";
                $sql_dump .= "'" . addslashes($station['created_at']) . "', ";
                $sql_dump .= "'" . addslashes($station['updated_at']) . "'";
                $sql_dump .= ");\n\n";
            }
            
            // Station settings
            $sql_dump .= "-- Station Settings\n";
            $stmt = $db->prepare("SELECT * FROM station_settings WHERE station_id = ?");
            $stmt->execute([$stationId]);
            $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($settings)) {
                foreach ($settings as $setting) {
                    $sql_dump .= "INSERT INTO station_settings (station_id, setting_key, setting_value, setting_type, created_at, updated_at) VALUES (";
                    $sql_dump .= $setting['station_id'] . ", ";
                    $sql_dump .= "'" . addslashes($setting['setting_key']) . "', ";
                    $sql_dump .= "'" . addslashes($setting['setting_value']) . "', ";
                    $sql_dump .= "'" . addslashes($setting['setting_type']) . "', ";
                    $sql_dump .= "'" . addslashes($setting['created_at']) . "', ";
                    $sql_dump .= "'" . addslashes($setting['updated_at']) . "'";
                    $sql_dump .= ");\n";
                }
                $sql_dump .= "\n";
            }
            
            // Trucks for this station
            $sql_dump .= "-- Trucks\n";
            $stmt = $db->prepare("SELECT * FROM trucks WHERE station_id = ?");
            $stmt->execute([$stationId]);
            $trucks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($trucks)) {
                foreach ($trucks as $truck) {
                    $sql_dump .= "INSERT INTO trucks (id, station_id, truck_name, created_at, updated_at) VALUES (";
                    $sql_dump .= $truck['id'] . ", ";
                    $sql_dump .= $truck['station_id'] . ", ";
                    $sql_dump .= "'" . addslashes($truck['truck_name']) . "', ";
                    $sql_dump .= "'" . addslashes($truck['created_at']) . "', ";
                    $sql_dump .= "'" . addslashes($truck['updated_at']) . "'";
                    $sql_dump .= ");\n";
                }
                $sql_dump .= "\n";
                
                // Get truck IDs for related data
                $truckIds = array_column($trucks, 'id');
                $truckIdsList = implode(',', $truckIds);
                
                // Lockers for these trucks
                $sql_dump .= "-- Lockers\n";
                $stmt = $db->prepare("SELECT * FROM lockers WHERE truck_id IN ($truckIdsList)");
                $stmt->execute();
                $lockers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($lockers)) {
                    foreach ($lockers as $locker) {
                        $sql_dump .= "INSERT INTO lockers (id, truck_id, locker_name, created_at, updated_at) VALUES (";
                        $sql_dump .= $locker['id'] . ", ";
                        $sql_dump .= $locker['truck_id'] . ", ";
                        $sql_dump .= "'" . addslashes($locker['locker_name']) . "', ";
                        $sql_dump .= "'" . addslashes($locker['created_at']) . "', ";
                        $sql_dump .= "'" . addslashes($locker['updated_at']) . "'";
                        $sql_dump .= ");\n";
                    }
                    $sql_dump .= "\n";
                    
                    // Get locker IDs for related data
                    $lockerIds = array_column($lockers, 'id');
                    $lockerIdsList = implode(',', $lockerIds);
                    
                    // Items for these lockers
                    $sql_dump .= "-- Locker Items\n";
                    $stmt = $db->prepare("SELECT * FROM locker_items WHERE locker_id IN ($lockerIdsList)");
                    $stmt->execute();
                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($items)) {
                        foreach ($items as $item) {
                            $sql_dump .= "INSERT INTO locker_items (id, locker_id, item_name, created_at, updated_at) VALUES (";
                            $sql_dump .= $item['id'] . ", ";
                            $sql_dump .= $item['locker_id'] . ", ";
                            $sql_dump .= "'" . addslashes($item['item_name']) . "', ";
                            $sql_dump .= "'" . addslashes($item['created_at']) . "', ";
                            $sql_dump .= "'" . addslashes($item['updated_at']) . "'";
                            $sql_dump .= ");\n";
                        }
                        $sql_dump .= "\n";
                    }
                    
                    // Checks for these lockers
                    $sql_dump .= "-- Locker Checks\n";
                    $stmt = $db->prepare("SELECT * FROM locker_checks WHERE locker_id IN ($lockerIdsList)");
                    $stmt->execute();
                    $checks = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($checks)) {
                        foreach ($checks as $check) {
                            $sql_dump .= "INSERT INTO locker_checks (id, locker_id, check_date, status, notes, created_at, updated_at) VALUES (";
                            $sql_dump .= $check['id'] . ", ";
                            $sql_dump .= $check['locker_id'] . ", ";
                            $sql_dump .= "'" . addslashes($check['check_date']) . "', ";
                            $sql_dump .= "'" . addslashes($check['status']) . "', ";
                            $sql_dump .= ($check['notes'] ? "'" . addslashes($check['notes']) . "'" : "NULL") . ", ";
                            $sql_dump .= "'" . addslashes($check['created_at']) . "', ";
                            $sql_dump .= "'" . addslashes($check['updated_at']) . "'";
                            $sql_dump .= ");\n";
                        }
                        $sql_dump .= "\n";
                    }
                }
            }
            
            $sql_dump .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        }
        
        // Send file for download
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($sql_dump));
        
        echo $sql_dump;
        exit;
        
    } catch (Exception $e) {
        $error_message = "Error creating backup: " . $e->getMessage();
    }
}

include 'templates/header.php';
?>

<style>
    .backup-container {
        max-width: 800px;
        margin: 20px auto;
        padding: 20px;
    }

    .page-header {
        margin-bottom: 30px;
        padding-bottom: 15px;
        border-bottom: 2px solid #12044C;
    }

    .page-title {
        color: #12044C;
        margin: 0;
    }

    .info-section {
        margin: 20px 0;
        padding: 15px;
        border-radius: 5px;
    }

    .info-section.primary {
        background-color: #e7f3ff;
        border: 1px solid #b3d9ff;
    }

    .info-section.warning {
        background-color: #fff3cd;
        border: 1px solid #ffeaa7;
    }

    .info-section.station {
        background-color: #f8f9fa;
        border: 1px solid #dee2e6;
    }

    .error-message {
        color: #721c24;
        margin: 20px 0;
        padding: 15px;
        background-color: #f8d7da;
        border: 1px solid #f5c6cb;
        border-radius: 5px;
    }

    .backup-form {
        text-align: center;
        margin: 30px 0;
    }

    .button {
        display: inline-block;
        padding: 12px 24px;
        background-color: #12044C;
        color: white;
        text-decoration: none;
        border: none;
        border-radius: 5px;
        font-size: 16px;
        cursor: pointer;
        transition: background-color 0.3s;
        margin: 5px;
    }

    .button:hover {
        background-color: #0056b3;
    }

    .button.secondary {
        background-color: #6c757d;
    }

    .button.secondary:hover {
        background-color: #545b62;
    }

    .station-info {
        text-align: center;
        margin-bottom: 20px;
    }

    .station-name {
        font-size: 18px;
        font-weight: bold;
        color: #12044C;
    }

    .backup-type {
        font-size: 16px;
        font-weight: bold;
        margin-bottom: 10px;
    }

    .backup-type.full {
        color: #dc3545;
    }

    .backup-type.station {
        color: #28a745;
    }
</style>

<div class="backup-container">
    <div class="page-header">
        <h1 class="page-title">Database Backup</h1>
    </div>

    <?php if (isset($error_message)): ?>
        <div class="error-message">
            <?= htmlspecialchars($error_message) ?>
        </div>
    <?php endif; ?>

    <?php if ($userRole === 'superuser'): ?>
        <div class="info-section primary">
            <div class="backup-type full">Full System Backup</div>
            <p>As a superuser, you can download a complete backup of the entire TruckChecks database including:</p>
            <ul>
                <li>All stations and their settings</li>
                <li>All users and permissions</li>
                <li>All trucks, lockers, and items across all stations</li>
                <li>All check history and audit logs</li>
                <li>Complete system configuration</li>
            </ul>
        </div>
    <?php else: ?>
        <?php if ($currentStation): ?>
            <div class="station-info">
                <div class="station-name"><?= htmlspecialchars($currentStation['name']) ?></div>
                <?php if ($currentStation['description']): ?>
                    <div style="color: #666; margin-top: 5px;"><?= htmlspecialchars($currentStation['description']) ?></div>
                <?php endif; ?>
            </div>
            
            <div class="info-section primary">
                <div class="backup-type station">Station-Specific Backup</div>
                <p>As a station admin, you can download a backup of your station's data including:</p>
                <ul>
                    <li>Station information and settings</li>
                    <li>All trucks assigned to <?= htmlspecialchars($currentStation['name']) ?></li>
                    <li>All lockers and items for your trucks</li>
                    <li>Check history for your station's equipment</li>
                </ul>
                <p><strong>Note:</strong> This backup only includes data for your assigned station.</p>
            </div>
        <?php else: ?>
            <div class="info-section warning">
                <h3>No Station Selected</h3>
                <p>Please select a station first before creating a backup.</p>
                <a href="select_station.php" class="button">Select Station</a>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($userRole === 'superuser' || $currentStation): ?>
        <form method="POST" class="backup-form">
            <button type="submit" name="download_backup" class="button">
                <?= $userRole === 'superuser' ? 'Download Full System Backup' : 'Download Station Backup' ?>
            </button>
        </form>
    <?php endif; ?>

    <div class="info-section warning">
        <h3>Backup Information</h3>
        <ul>
            <li>The backup file will be downloaded as an SQL file</li>
            <li>Backup files can be imported using phpMyAdmin or MySQL command line</li>
            <li>Regular backups are recommended before making significant changes</li>
            <li>Store backup files in a secure location</li>
            <?php if ($userRole === 'station_admin'): ?>
                <li>Station backups contain only your station's data for security</li>
            <?php endif; ?>
        </ul>
    </div>

    <div style="text-align: center; margin-top: 30px;">
        <a href="admin.php" class="button secondary">← Back to Admin</a>
    </div>
</div>

<?php include 'templates/footer.php'; ?>
