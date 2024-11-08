<?php
if (!file_exists('config.php')) {
    echo "<h1>Site is not configured, please see documentation on configuration.</h1>";
    exit;
}

session_start();
include 'db.php';


$version = trim(exec('git describe --tags $(git rev-list --tags --max-count=1)'));
//IS_DEMO = isset($_SESSION['IS_DEMO']) && $_SESSION['IS_DEMO'] === true;
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
$trucks = $db->query('SELECT * FROM trucks')->fetchAll(PDO::FETCH_ASSOC);

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
                    $locker_status = get_locker_status($locker['id'], $db, $colours);
                    $locker_url = 'check_locker_items.php?truck_id=' . $truck['id'] . '&locker_id=' . $locker['id'];
                    $background_color = $locker_status['status'];
                    $text_color = 'white';
                    $last_checked = $locker_status['check'] ? $locker_status['check']['check_date'] : 'Never';
                    $checked_by = $locker_status['check'] ? $locker_status['check']['checked_by'] : 'N/A';
                    $missing_items = $locker_status['missing_items'];
                    ?>
                    <div class="locker-cell" style="background-color: <?= $background_color ?>; color: <?= $text_color ?>;" 
                        onclick="showLockerInfo('<?= htmlspecialchars($locker['name']) ?>', '<?= convertToNZST($last_checked) ?>', '<?= $checked_by ?>', <?= htmlspecialchars(json_encode($missing_items)) ?>, '<?= $locker_url ?>')">
                        
                        <?= htmlspecialchars($locker['name']) ?>
                        
                        <?php if (!empty($missing_items)): ?>
                            <span class="badge">!</span>
                        <?php endif; ?>
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
        Version: <?php echo htmlspecialchars($version); ?>
    </div>   
</footer>

</body>
</html>