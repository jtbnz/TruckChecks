<?php
if (!file_exists('config.php')) {
    echo "<h1>Site is not configured, please see documentation on configuration.</h1>";
    exit;
}

include_once('auth.php');

// Check if we need to show station selection
$stations = [];
$currentStation = null;

try {
    // Get all stations for dropdown
    $db = get_db_connection();
    $stmt = $db->prepare("SELECT * FROM stations ORDER BY name");
    $stmt->execute();
    $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // If stations table doesn't exist yet, continue with legacy behavior
    error_log("Stations table not found, using legacy mode: " . $e->getMessage());
}

// Handle station selection from dropdown
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['selected_station'])) {
    $stationId = (int)$_POST['selected_station'];
    
    // Set station preference in cookie (no auth required for public view)
    setcookie('preferred_station', $stationId, time() + (365 * 24 * 60 * 60), "/");
    $_SESSION['current_station_id'] = $stationId;
    
    // Redirect to refresh page with selected station
    header('Location: index.php');
    exit;
}

// Get current station for filtering
if (!empty($stations)) {
    // Check session first
    if (isset($_SESSION['current_station_id'])) {
        foreach ($stations as $station) {
            if ($station['id'] == $_SESSION['current_station_id']) {
                $currentStation = $station;
                break;
            }
        }
    }
    
    // Check cookie preference
    if (!$currentStation && isset($_COOKIE['preferred_station'])) {
        foreach ($stations as $station) {
            if ($station['id'] == $_COOKIE['preferred_station']) {
                $currentStation = $station;
                $_SESSION['current_station_id'] = $station['id'];
                break;
            }
        }
    }
    
    // If no station selected and multiple stations exist, show selection
    if (!$currentStation && count($stations) > 1) {
        // Show station selection interface
        include 'templates/header.php';
        ?>
        <style>
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
            
            .select-station-btn {
                width: 100%;
                padding: 15px;
                background-color: #12044C;
                color: white;
                border: none;
                border-radius: 5px;
                font-size: 16px;
                cursor: pointer;
            }
            
            .select-station-btn:hover {
                background-color: #0056b3;
            }
        </style>
        
        <div class="station-selection">
            <h2>Select Station</h2>
            <p>Please select a station to view truck checks:</p>
            
            <form method="post" action="">
                <select name="selected_station" class="station-dropdown" required>
                    <option value="">-- Select a Station --</option>
                    <?php foreach ($stations as $station): ?>
                        <option value="<?= $station['id'] ?>"><?= htmlspecialchars($station['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                
                <button type="submit" class="select-station-btn">View Station</button>
            </form>
        </div>
        
        <?php include 'templates/footer.php'; ?>
        <?php exit; ?>
        <?php
    } elseif (!$currentStation && count($stations) === 1) {
        // Auto-select single station
        $currentStation = $stations[0];
        $_SESSION['current_station_id'] = $currentStation['id'];
        setcookie('preferred_station', $currentStation['id'], time() + (365 * 24 * 60 * 60), "/");
    }
}

$version = trim(exec('git describe --tags $(git rev-list --tags --max-count=1)'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Overview of current locker Status Check">
    <title>Truck Checks</title>
    <link rel="stylesheet" href="styles/styles.css?id=<?php  echo $version;  ?> ">
    <script>
        // Automatically refresh the page using the REFRESH interval in config.php
        setTimeout(function(){
            window.location.reload(1);
        }, <?php echo REFRESH; ?>); 

        // Function to display the last refreshed time in local browser time zone
        function displayLastRefreshed() {
            const now = new Date();
            const formattedTime = now.toLocaleString(); // Local time string
            document.getElementById('last-refreshed').textContent = 'Last refreshed at: ' + formattedTime;
        }

        // Run the function when the page loads
        window.onload = displayLastRefreshed;
    </script>
</head>
<body class="<?php echo IS_DEMO ? 'demo-mode' : ''; ?>">


<?php
;
//include 'templates/header.php';

// Read the cookie value
$colorBlindMode = isset($_COOKIE['color_blind_mode']) ? $_COOKIE['color_blind_mode'] : false;

if ($colorBlindMode) {
    $colours = [
        'green' => '#0072b2',
        'orange' => '#e69f00',
        'red' => '#d55e00',
    ];
} else {
    $colours = [
        'green' => '#28a745',
        'orange' => '#ff8800',
        'red' => '#dc3545',
    ];
}



$db = get_db_connection();

// Filter trucks by current station if stations are enabled
if ($currentStation) {
    $stmt = $db->prepare('SELECT id, name, relief, station_id FROM trucks WHERE station_id = ? ORDER BY name');
    $stmt->execute([$currentStation['id']]);
    $trucks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Legacy behavior - show all trucks
    $trucks = $db->query('SELECT id, name, relief FROM trucks ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
}

function get_locker_status($locker_id, $db, $colours) {
    // Fetch the most recent check for the locker
    $query = $db->prepare('SELECT * FROM checks WHERE locker_id = :locker_id ORDER BY check_date DESC LIMIT 1');
    $query->execute(['locker_id' => $locker_id]);
    $check = $query->fetch(PDO::FETCH_ASSOC);

    if (!$check) {
        return ['status' => $colours['red'], 'check' => null, 'missing_items' => []];
    }

    // Check if the locker was checked in the last 7 days
    $recent_check = (new DateTime())->diff(new DateTime($check['check_date']))->days < 6 && !$check['ignore_check'];

    // Fetch missing items from the last check
    $query = $db->prepare('SELECT items.name FROM check_items INNER JOIN items ON check_items.item_id = items.id WHERE check_items.check_id = :check_id AND check_items.is_present = 0');
    $query->execute(['check_id' => $check['id']]);
    $missing_items = $query->fetchAll(PDO::FETCH_COLUMN);

    if ($recent_check && empty($missing_items)) {
        return ['status' => $colours['green'], 'check' => $check, 'missing_items' => []];
    } elseif ($recent_check && !empty($missing_items)) {
        return ['status' => $colours['orange'], 'check' => $check, 'missing_items' => $missing_items];
    } else {
        return ['status' => $colours['red'], 'check' => $check, 'missing_items' => $missing_items];
    }
}

// Function to convert UTC to NZST
function convertToNZST($utcDate) {
    $date = new DateTime($utcDate, new DateTimeZone('UTC'));
    $date->setTimezone(new DateTimeZone('Pacific/Auckland')); // NZST timezone
    return $date->format('Y-m-d H:i:s');
}

?>

<?php if ($currentStation && count($stations) > 1): ?>
    <!-- Station Indicator and Change Option -->
<!--     <div style="text-align: center; margin: 20px 0; padding: 15px; background-color: #f8f9fa; border-radius: 5px;">
        <strong>Current Station: <?= htmlspecialchars($currentStation['name']) ?></strong>
        <br>
        <a href="index.php" onclick="return changeStation()" style="color: #12044C; text-decoration: none; font-size: 14px;">
            Change Station
        </a>
    </div> -->
    
    <script>
    function changeStation() {
        // Clear the current station preference
        document.cookie = 'preferred_station=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
        
        // Clear session storage if available
        if (typeof(Storage) !== "undefined") {
            sessionStorage.removeItem('current_station_id');
        }
        
        return true; // Allow the link to proceed
    }
    </script>
<?php elseif ($currentStation): ?>
    <!-- Single Station Indicator -->
    <div style="text-align: center; margin: 20px 0; padding: 10px; background-color: #e9ecef; border-radius: 5px;">
        <strong><?= htmlspecialchars($currentStation['name']) ?></strong>
    </div>
<?php endif; ?>

<?php foreach ($trucks as $truck): ?>
    <div class="truck-listing">
        <a href="check_locker_items.php?truck_id=<?= $truck['id'] ?>" class="truck-button">
            <?= htmlspecialchars($truck['name']) ?> - Locker Checks
        </a>

        <?php
        $query = $db->prepare('SELECT * FROM lockers WHERE truck_id = :truck_id order by name');
        $query->execute(['truck_id' => $truck['id']]);
        $lockers = $query->fetchAll(PDO::FETCH_ASSOC);
        ?>

        <?php if (!empty($lockers)): ?>
            <div class="locker-container">
                <?php if ($truck['relief']): ?>
                    <div class="relief-truck-indicator" onclick="window.location.href='changeover.php'" style="cursor: pointer;" title="Click to go to Relief Change Over">
                        Relief Truck - Click for Change Over
                    </div>
                <?php endif; ?>
                <div class="locker-grid">
                    <?php foreach ($lockers as $locker): ?>
                        <?php
                        $locker_status = get_locker_status($locker['id'], $db, $colours);
                        $locker_url = 'check_locker_items.php?truck_id=' . $truck['id'] . '&locker_id=' . $locker['id'];
                        // Override background color if truck is relief
                        $background_color = $truck['relief'] ? '#808080' : $locker_status['status'];
                        $text_color = 'white';
                        $last_checked = $locker_status['check'] ? $locker_status['check']['check_date'] : 'Never';
                        $last_checked_display = $last_checked !== 'Never' ? convertToNZST($last_checked) : $last_checked;
                        $checked_by = $locker_status['check'] ? $locker_status['check']['checked_by'] : 'N/A';
                        $missing_items = $locker_status['missing_items'];
                        ?>
                        <div class="locker-cell" style="background-color: <?= $background_color ?>; color: <?= $text_color ?>;" 
                            onclick="showLockerInfo('<?= htmlspecialchars($locker['name']) ?>', '<?= $last_checked_display ?>', '<?= $checked_by ?>', <?= htmlspecialchars(json_encode($missing_items)) ?>, '<?= $locker_url ?>')">
                            
                            <?= htmlspecialchars($locker['name']) ?>
                            
                            <?php if (!empty($missing_items)): ?>
                                <span class="badge">!</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <p>No lockers found for this truck.</p>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<!-- Admin Button -->
<div style="text-align: center; margin-top: 40px;">
    <a href="find.php" class="button touch-button">Find an item</a>
    <a href="changeover.php" class="button touch-button">Relief Change Over</a>
    <a href="quiz/quiz.php" class="button touch-button">Quiz</a> 
    <a href="reports.php" class="button touch-button">Reports</a>
    <a href="login.php" class="button touch-button">Admin</a>
    <a href="settings.php" class="button touch-button">Settings</a> 
</div>

<!-- Modal -->
<div id="lockerInfoModal" class="modal">
    <div class="modal-content">
        <span class="close-button" onclick="closeModal()">&times;</span>
        <h2 id="lockerName">Locker Info</h2>
        <p>Last Checked: <span id="lastChecked">N/A</span></p>
        <p>Checked By: <span id="checkedBy">N/A</span></p>
        <p>Missing Items: <span id="missingItems">None</span></p>
        <button class="button touch-button" onclick="openUrl(document.getElementById('lockerUrl').innerText)">Check Locker</button>
        <p id="lockerUrl" style="display: none;">
        <!-- <p>Locker URL: <span id="lockerUrl">N/A</span></p> -->
        <!-- <a href="" id="lockerUrl" target="_blank" class="button touch-button">Check <span id="lockerName">< /span> Locker 1</a>
        <a href="#" id="lockerUrl" target="_blank" class="button touch-button">Check <span id="lockerName"></span> Locker 2</a> -->
        <button class="button touch-button" onclick="closeModal()">Close</button>
    </div>
</div>
<script>

function openUrl(url) {
    window.open(url, '_blank');
}


function showLockerInfo(lockerName, lastChecked, checkedBy, missingItems, lockerUrl) {
    document.getElementById('lockerName').innerText = lockerName;

    if (lastChecked !== 'Never') {
        document.getElementById('lastChecked').innerText = lastChecked;
    } else {
        document.getElementById('lastChecked').innerText = lastChecked;
    }

    document.getElementById('checkedBy').innerText = checkedBy;

    if (missingItems.length > 0) {
        document.getElementById('missingItems').innerHTML = missingItems.join(', ');
    } else {
        document.getElementById('missingItems').innerText = 'None';
    }

    document.getElementById('lockerInfoModal').style.display = 'block';
    document.getElementById('lockerUrl').innerHTML = `<a href="${lockerUrl}" target="_blank">${lockerUrl}</a>`;

}

function closeModal() {
    document.getElementById('lockerInfoModal').style.display = 'none';
}
</script>

<!-- Footer section -->
<footer>

    <p id="last-refreshed" style="margin-top: 10px;"></p> 
    <div class="version-number">
        Version <?php echo htmlspecialchars($version); ?>
    </div>   
</footer>

</body>
</html>
