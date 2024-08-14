<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include 'db.php'; // Include your database connection

$db = get_db_connection();

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
        SELECT 
            t.name as truck_name, 
            l.name as locker_name, 
            i.name as item_name, 
            ci.is_present as checked, 
            c.check_date,
            c.id as check_id
        FROM checks c
        JOIN check_items ci ON c.id = ci.check_id
        JOIN lockers l ON c.locker_id = l.id
        JOIN trucks t ON l.truck_id = t.id
        JOIN items i ON ci.item_id = i.id
        WHERE DATE(c.check_date) = :selected_date
        AND c.check_date = (
            SELECT MAX(inner_c.check_date) 
            FROM checks inner_c 
            WHERE inner_c.locker_id = c.locker_id 
            AND DATE(inner_c.check_date) = :selected_date_inner
        )
        ORDER BY t.name, l.name
    ");
    
    // Bind the parameter for both the main query and the subquery
    $report_query->bindParam(':selected_date', $selected_date);
    $report_query->bindParam(':selected_date_inner', $selected_date);
    
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
    fputcsv($output, ['Truck', 'Locker', 'Item', 'Checked', 'Check Date']);
    foreach ($report_data as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Locker Check Reports</title>
    <link rel="stylesheet" href="styles/reports.css"> <!-- Link to your CSS -->
</head>
<body>

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
        foreach ($report_data as $entry):
            if ($current_truck !== $entry['truck_name']):
                if ($current_truck !== null):
                    echo '</div>'; // Close previous truck section
                endif;
                $current_truck = $entry['truck_name'];
                $total_checked_items = countItemsChecked($current_truck, $report_data); // Calculate total checked items
        ?>
            <div class="truck-section">
                <h2 class="truck-name" onclick="toggleVisibility('truck-<?= md5($current_truck) ?>')">
                    <?= htmlspecialchars($current_truck) ?> (Total Items Checked: <?= $total_checked_items ?>)
                </h2>
                <div id="truck-<?= md5($current_truck) ?>" class="locker-section" style="display: none;">
        <?php
            endif;
        ?>
                    <div class="locker-item">
                        <p><strong>Locker:</strong> <?= htmlspecialchars($entry['locker_name']) ?></p>
                        <p><strong>Item:</strong> <?= htmlspecialchars($entry['item_name']) ?></p>
                        <p><strong>Checked:</strong> <?= $entry['checked'] ? 'Yes' : 'No' ?></p>
                        <p><strong>Check Date:</strong> <?= htmlspecialchars($entry['check_date']) ?></p>
                    </div>
        <?php
        endforeach;
        if ($current_truck !== null):
            echo '</div>'; // Close last truck section
        endif;
        ?>
    </div>
<?php elseif ($selected_date): ?>
    <p>No report data available for the selected date.</p>
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
