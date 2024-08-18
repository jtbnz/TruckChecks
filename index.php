<?php
session_start();
include 'db.php';

// Read the version number from the version.txt file
$version = trim(exec('git describe --tags $(git rev-list --tags --max-count=1)'));
$is_demo = isset($_SESSION['is_demo']) && $_SESSION['is_demo'] === true;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Truck Checks</title>
    <link rel="stylesheet" href="styles/styles.css?id=V16">
    <script>
        // Automatically refresh the page every 30 seconds
        setTimeout(function(){
            window.location.reload(1);
        }, 30000); // 30 seconds

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
<body class="<?php echo $is_demo ? 'demo-mode' : ''; ?>">


<?php
;
//include 'templates/header.php';



// Default colors
$colors = [
    'green' => '#28a745',
    'orange' => '#ff8800',
    'red' => '#dc3545',
];

// Check if the color blindness-friendly option is enabled
if (isset($_SESSION['color_blind_mode']) && $_SESSION['color_blind_mode']) {
    $colors = [
        'green' => '#0072b2',
        'orange' => '#e69f00',
        'red' => '#d55e00',
    ];
}

$db = get_db_connection();
$trucks = $db->query('SELECT * FROM trucks')->fetchAll(PDO::FETCH_ASSOC);

function get_locker_status($locker_id, $db, $colors) {
    // Fetch the most recent check for the locker
    $query = $db->prepare('SELECT * FROM checks WHERE locker_id = :locker_id ORDER BY check_date DESC LIMIT 1');
    $query->execute(['locker_id' => $locker_id]);
    $check = $query->fetch(PDO::FETCH_ASSOC);

    if (!$check) {
        return ['status' => $colors['red'], 'check' => null, 'missing_items' => []];
    }

    // Check if the locker was checked in the last 7 days
    $recent_check = (new DateTime())->diff(new DateTime($check['check_date']))->days < 6;

    // Fetch missing items from the last check
    $query = $db->prepare('SELECT items.name FROM check_items INNER JOIN items ON check_items.item_id = items.id WHERE check_items.check_id = :check_id AND check_items.is_present = 0');
    $query->execute(['check_id' => $check['id']]);
    $missing_items = $query->fetchAll(PDO::FETCH_COLUMN);

    if ($recent_check && empty($missing_items)) {
        return ['status' => $colors['green'], 'check' => $check, 'missing_items' => []];
    } elseif ($recent_check && !empty($missing_items)) {
        return ['status' => $colors['orange'], 'check' => $check, 'missing_items' => $missing_items];
    } else {
        return ['status' => $colors['red'], 'check' => $check, 'missing_items' => $missing_items];
    }
}
?>

<?php foreach ($trucks as $truck): ?>
    <div class="truck-listing">
        <a href="check_locker_items.php?truck_id=<?= $truck['id'] ?>" class="truck-button">
            <?= htmlspecialchars($truck['name']) ?> - Locker Checks
        </a>

        <?php
        $query = $db->prepare('SELECT * FROM lockers WHERE truck_id = :truck_id');
        $query->execute(['truck_id' => $truck['id']]);
        $lockers = $query->fetchAll(PDO::FETCH_ASSOC);
        ?>

        <?php if (!empty($lockers)): ?>
            <div class="locker-grid">
                <?php foreach ($lockers as $locker): ?>
                    <?php
                    $locker_status = get_locker_status($locker['id'], $db, $colors);
                    $background_color = $locker_status['status'];
                    $text_color = 'white';
                    $last_checked = $locker_status['check'] ? $locker_status['check']['check_date'] : 'Never';
                    $checked_by = $locker_status['check'] ? $locker_status['check']['checked_by'] : 'N/A';
                    $missing_items = $locker_status['missing_items'];
                    ?>
                    <div class="locker-cell" style="background-color: <?= $background_color ?>; color: <?= $text_color ?>;" 
                        onclick="showLockerInfo('<?= htmlspecialchars($locker['name']) ?>', '<?= $last_checked ?>', '<?= $checked_by ?>', <?= htmlspecialchars(json_encode($missing_items)) ?>)">
                        <?= htmlspecialchars($locker['name']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p>No lockers found for this truck.</p>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<!-- Admin Button -->
<div style="text-align: center; margin-top: 40px;">
    <a href="login.php" class="button touch-button">Admin</a>
    <a href="settings.php" class="button touch-button">Settings</a> 
    <a href="reports.php" class="button touch-button">Reports</a>
</div>

<!-- Modal -->
<div id="lockerInfoModal" class="modal">
    <div class="modal-content">
        <span class="close-button" onclick="closeModal()">&times;</span>
        <h2 id="lockerName">Locker Info</h2>
        <p>Last Checked: <span id="lastChecked">N/A</span></p>
        <p>Checked By: <span id="checkedBy">N/A</span></p>
        <p>Missing Items: <span id="missingItems">None</span></p>
    </div>
</div>

<script>


function showLockerInfo(lockerName, lastChecked, checkedBy, missingItems) {
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
}

function closeModal() {
    document.getElementById('lockerInfoModal').style.display = 'none';
}
</script>

<!-- Footer section -->
<footer>

    <p id="last-refreshed" style="margin-top: 10px;"></p> <!-- Last refreshed time will appear here -->
    <div class="version-number">
        Version: <?php echo htmlspecialchars($version); ?>

    </div>   
</footer>

</body>
</html>