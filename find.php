<?php
/* ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL); */

include('config.php');
include 'db.php';
include_once('auth.php');

// Get current station context (no authentication required for public view)
$stations = [];
$currentStation = null;

try {
    $db = get_db_connection();
    $stmt = $db->prepare("SELECT * FROM stations ORDER BY name");
    $stmt->execute();
    $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Stations table not found, using legacy mode: " . $e->getMessage());
}

// Handle station selection from dropdown
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['selected_station'])) {
    $stationId = (int)$_POST['selected_station'];
    
    setcookie('preferred_station', $stationId, time() + (365 * 24 * 60 * 60), "/");
    $_SESSION['current_station_id'] = $stationId;
    
    header('Location: find.php');
    exit;
}

// Get current station for filtering
if (!empty($stations)) {
    if (isset($_SESSION['current_station_id'])) {
        foreach ($stations as $station) {
            if ($station['id'] == $_SESSION['current_station_id']) {
                $currentStation = $station;
                break;
            }
        }
    }
    
    if (!$currentStation && isset($_COOKIE['preferred_station'])) {
        foreach ($stations as $station) {
            if ($station['id'] == $_COOKIE['preferred_station']) {
                $currentStation = $station;
                $_SESSION['current_station_id'] = $station['id'];
                break;
            }
        }
    }
    
    if (!$currentStation && count($stations) === 1) {
        $currentStation = $stations[0];
        $_SESSION['current_station_id'] = $currentStation['id'];
        setcookie('preferred_station', $currentStation['id'], time() + (365 * 24 * 60 * 60), "/");
    }
}

// Handle form submission to search for items
$searchstr = isset($_POST['searchQuery']) ? $_POST['searchQuery'] : null;
$report_data = [];

if ($searchstr) {
    if ($currentStation) {
        // Search within current station only
        $report_query = $db->prepare("
            SELECT 
                t.name as truck_name, 
                l.name as locker_name, 
                i.name as item_name
            FROM items i
            JOIN lockers l ON i.locker_id = l.id
            JOIN trucks t ON t.id = l.truck_id
            WHERE i.name COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', :searchstr, '%')
            AND t.station_id = :station_id
            ORDER BY t.name, l.name
        ");
        $report_query->execute(['searchstr' => $searchstr, 'station_id' => $currentStation['id']]);
    } else {
        // Legacy behavior - search all items
        $report_query = $db->prepare("
            SELECT 
                t.name as truck_name, 
                l.name as locker_name, 
                i.name as item_name
            FROM items i
            JOIN lockers l ON i.locker_id = l.id
            JOIN trucks t ON t.id = l.truck_id
            WHERE i.name COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', :searchstr, '%')
            ORDER BY t.name, l.name
        ");
        $report_query->execute(['searchstr' => $searchstr]);
    }
    
    $report_data = $report_query->fetchAll(PDO::FETCH_ASSOC);
}

include 'templates/header.php';
?>

<style>
    .find-container {
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

    .station-selection {
        max-width: 500px;
        margin: 50px auto;
        padding: 30px;
        background-color: #f9f9f9;
        border-radius: 10px;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        text-align: center;
    }

    .station-dropdown {
        width: 100%;
        padding: 15px;
        font-size: 16px;
        border: 1px solid #ccc;
        border-radius: 5px;
        margin: 20px 0;
    }

    .search-form {
        background: white;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 30px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .search-form h2 {
        margin-top: 0;
        color: #12044C;
    }

    .search-input {
        width: 70%;
        padding: 12px;
        border: 1px solid #ccc;
        border-radius: 5px;
        font-size: 16px;
        margin-right: 10px;
    }

    .search-button {
        padding: 12px 24px;
        background-color: #12044C;
        color: white;
        border: none;
        border-radius: 5px;
        font-size: 16px;
        cursor: pointer;
        transition: background-color 0.3s;
    }

    .search-button:hover {
        background-color: #0056b3;
    }

    .results-table {
        background: white;
        border: 1px solid #ddd;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    th, td {
        padding: 15px;
        text-align: left;
        border-bottom: 1px solid #eee;
    }

    th {
        background-color: #12044C;
        color: white;
        font-weight: bold;
    }

    tr:hover {
        background-color: #f8f9fa;
    }

    .no-results {
        text-align: center;
        padding: 40px;
        color: #666;
        font-style: italic;
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

    .button-container {
        text-align: center;
        margin-top: 30px;
    }

    /* Mobile responsive */
    @media (max-width: 768px) {
        .find-container {
            padding: 10px;
        }

        .search-input {
            width: 100%;
            margin-bottom: 10px;
            margin-right: 0;
        }

        .search-button {
            width: 100%;
        }

        th, td {
            padding: 10px 8px;
            font-size: 14px;
        }
    }
</style>

<div class="find-container">
    <div class="page-header">
        <h1 class="page-title">Find an Item</h1>
    </div>

    <?php if (!empty($stations) && !$currentStation && count($stations) > 1): ?>
        <!-- Station Selection -->
        <div class="station-selection">
            <h2>Select Station</h2>
            <p>Please select a station to search for items:</p>
            
            <form method="post" action="">
                <select name="selected_station" class="station-dropdown" required>
                    <option value="">-- Select a Station --</option>
                    <?php foreach ($stations as $station): ?>
                        <option value="<?= $station['id'] ?>"><?= htmlspecialchars($station['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                
                <button type="submit" class="button">Select Station</button>
            </form>
        </div>
    <?php else: ?>
        <?php if ($currentStation): ?>
            <div class="station-info">
                <div class="station-name"><?= htmlspecialchars($currentStation['name']) ?></div>
                <?php if ($currentStation['description']): ?>
                    <div style="color: #666; margin-top: 5px;"><?= htmlspecialchars($currentStation['description']) ?></div>
                <?php endif; ?>
                <?php if (count($stations) > 1): ?>
                    <div style="margin-top: 10px;">
                        <a href="find.php" onclick="return changeStation()" style="color: #12044C; text-decoration: none; font-size: 14px;">
                            Change Station
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Search Form -->
        <div class="search-form">
            <h2>Search for Items</h2>
            <form method="POST" action="find.php">
                <input type="text" name="searchQuery" class="search-input" placeholder="Enter item name or description..." value="<?= htmlspecialchars($searchstr ?? '') ?>" required>
                <button type="submit" class="search-button">Search</button>
            </form>
        </div>

        <!-- Results -->
        <?php if ($searchstr): ?>
            <div class="results-table">
                <table>
                    <thead>
                        <tr>
                            <th>Truck Name</th>
                            <th>Locker Name</th>
                            <th>Item Name</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($report_data) > 0): ?>
                            <?php foreach ($report_data as $item): ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['truck_name']) ?></td>
                                    <td><?= htmlspecialchars($item['locker_name']) ?></td>
                                    <td><?= htmlspecialchars($item['item_name']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" class="no-results">
                                    No items found matching "<?= htmlspecialchars($searchstr) ?>"
                                    <?php if ($currentStation): ?>
                                        in <?= htmlspecialchars($currentStation['name']) ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (count($report_data) > 0): ?>
                <div style="margin-top: 20px; text-align: center; color: #666;">
                    Found <?= count($report_data) ?> item(s) matching "<?= htmlspecialchars($searchstr) ?>"
                    <?php if ($currentStation): ?>
                        in <?= htmlspecialchars($currentStation['name']) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="button-container">
            <a href="index.php" class="button secondary">← Back to Main</a>
        </div>
    <?php endif; ?>
</div>

<script>
function changeStation() {
    document.cookie = 'preferred_station=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
    
    if (typeof(Storage) !== "undefined") {
        sessionStorage.removeItem('current_station_id');
    }
    
    return true;
}
</script>

<?php include 'templates/footer.php'; ?>
