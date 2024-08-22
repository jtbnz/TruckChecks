<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include 'db.php'; // Include your database connection

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

//is_demo = isset($_SESSION['is_demo']) && $_SESSION['is_demo'] === true;



// Fetch unique check dates for the dropdown
$dates_query = $db->query('SELECT DISTINCT DATE(check_date) as last_checked FROM checks ORDER BY check_date DESC');
$dates = $dates_query->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission to filter by date
$selected_date = isset($_POST['check_date']) ? $_POST['check_date'] : null;

$report_data = [];
$missing_items = [];

if ($selected_date) {
    // Fetch the most recent check for each locker on the selected date
    $report_query = $db->prepare("

        WITH LatestChecks AS (
            SELECT 
                locker_id, 
                MAX(id) AS latest_check_id
            FROM checks
            WHERE DATE(check_date) = :selected_date
            GROUP BY locker_id
        )
        SELECT 
            t.name as truck_name, 
            l.name as locker_name, 
            i.name as item_name, 
            ci.is_present as checked, 
            c.check_date,
            c.checked_by,
            c.id as check_id
        FROM checks c
        JOIN LatestChecks lc ON c.id = lc.latest_check_id
        JOIN check_items ci ON c.id = ci.check_id
        JOIN lockers l ON c.locker_id = l.id
        JOIN trucks t ON l.truck_id = t.id
        JOIN items i ON ci.item_id = i.id
        ORDER BY t.name, l.name;
    ");
    

    $report_query->bindParam(':selected_date', $selected_date);

    
    $report_query->execute();
    $report_data = $report_query->fetchAll(PDO::FETCH_ASSOC);

    // Identify missing items for the selected date
    foreach ($report_data as $entry) {
        if (!$entry['checked']) {
            $missing_items[] = $entry;
        }
    }
}

function countItemsChecked($truck_name, $report_data) {
    $count = 0;
    foreach ($report_data as $entry) {
        if ($entry['truck_name'] === $truck_name) {
            $count++;
        }
    }
    return $count;
}

// Handle CSV export
if (isset($_POST['export_csv'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename=report_' . $selected_date . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Truck', 'Locker', 'Item', 'Checked', 'Check Date', 'Checked By']);
    foreach ($report_data as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Locker Check Reports</title>
    <link rel="stylesheet" href="styles/reports.css?id=V9"> 


</head>
<body class="<?php echo is_demo ? 'demo-mode' : ''; ?>">

<h1>Locker Check Reports</h1>

<!-- Dropdown form to select a check date -->
<form method="post" action="">
    <label for="check_date">Select Check Date:</label>
    <select name="check_date" id="check_date" required>
        <option value="">-- Select a Date --</option>
        <?php foreach ($dates as $date): ?>
            <option value="<?= htmlspecialchars($date['last_checked']) ?>" <?= $selected_date == $date['last_checked'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($date['last_checked']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit">View Report</button>
    <?php if ($selected_date): ?>
        <button type="submit" name="export_csv">Export as CSV</button>
    <?php endif; ?>
</form>

<!-- Missing items section -->
<?php if ($selected_date && !empty($missing_items)): ?>
    <div class="missing-items">
        <h2>Missing Items for <?= htmlspecialchars($selected_date) ?></h2>
        <ul>
            <?php foreach ($missing_items as $item): ?>
                <li>
                    <strong><?= htmlspecialchars($item['item_name']) ?></strong> 
                    in Locker <strong><?= htmlspecialchars($item['locker_name']) ?></strong> 
                    on Truck <strong><?= htmlspecialchars($item['truck_name']) ?></strong>
                    Checked by <?= htmlspecialchars($item['checked_by']) ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Report data -->
<?php if ($selected_date && !empty($report_data)): ?>
    <div class="report">
        <?php
        $current_truck = null;
        $current_locker = null;
        foreach ($report_data as $entry):
            if ($current_truck !== $entry['truck_name']):
                if ($current_truck !== null):
                    echo '</div> <!-- Close previous truck section -->'; // Close previous truck section
                endif;
                $current_truck = $entry['truck_name'];
                $total_checked_items = countItemsChecked($current_truck, $report_data); // Calculate total checked items
                $truck_id = md5($current_truck); // Generate a unique ID for the truck
        ?>
            <div class="truck-section">
                <h2 class="truck-name" >
                    <?= htmlspecialchars($current_truck) ?> (Total Items Checked: <?= $total_checked_items ?>)
                </h2></div>
            <div id="truck-<?= $truck_id ?>" class="locker-section" style="display: block;">
        <?php
            endif;

            if ($current_locker !== $entry['locker_name']):
                if ($current_locker !== null):
                    echo '</div></div><!-- Close previous locker section -->'; // Close previous locker section
                endif;
                $current_locker = $entry['locker_name'];
                $locker_id = md5($current_truck . $current_locker); // Generate a unique ID for the locker
        ?>
                <div class="locker-subsection">
                    <h3 class="locker-name" onclick="toggleVisibility('locker-<?= $locker_id ?>')">
                        Locker: <?= htmlspecialchars($current_locker) ?> (Checked By: <?= htmlspecialchars($entry['checked_by']) ?> <?= htmlspecialchars($entry['check_date']) ?>)
                    </h3>
                    <div id="locker-<?= $locker_id ?>" class="items-section" style="display: none;">
        <?php
            endif;
        ?>
                        <div class="locker-item">
                                <table ><TR>
                                <td > <?= htmlspecialchars($entry['item_name']) ?> </td>
                                <td> <?= $entry['checked'] ? 'Yes' : 'No' ?></td>
                            </tr></table>
                        </div>
        <?php
        endforeach;
        if ($current_locker !== null):
            echo '</div> <!-- Close last locker section -->'; // Close last locker section
        endif;
        if ($current_truck !== null):
            echo '</div> <!-- Close last truck section -->'; // Close last truck section
        endif;
        ?>
    </div>

<?php endif; ?>


<!-- JavaScript to handle expand/collapse functionality -->
<script>
    function toggleVisibility(id) {
        const element = document.getElementById(id);
        if (element.style.display === 'none') {
            element.style.display = 'block';
        } else {
            element.style.display = 'none';
        }
    }
</script>
<?php include 'templates/footer.php'; ?>
</body>
</html>
