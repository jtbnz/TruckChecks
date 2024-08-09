<?php
include 'db.php';
include 'templates/header.php';

session_start();

$db = get_db_connection();

// Fetch all unique check dates
$check_dates_query = $db->query('SELECT DISTINCT DATE(check_date) as check_date FROM checks ORDER BY check_date DESC');
$check_dates = $check_dates_query->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission for date selection
$selected_date = isset($_GET['check_date']) ? $_GET['check_date'] : null;
$reports = [];

if ($selected_date) {
    $reports_query = $db->prepare('
        SELECT trucks.name as truck_name, lockers.name as locker_name, checks.check_date, checks.checked_by, items.name as item_name, check_items.is_present 
        FROM checks
        INNER JOIN lockers ON checks.locker_id = lockers.id
        INNER JOIN trucks ON lockers.truck_id = trucks.id
        INNER JOIN check_items ON checks.id = check_items.check_id
        INNER JOIN items ON check_items.item_id = items.id
        WHERE DATE(checks.check_date) = :check_date
        ORDER BY trucks.name, lockers.name, items.name
    ');
    $reports_query->execute(['check_date' => $selected_date]);
    $reports = $reports_query->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports</title>
    <link rel="stylesheet" href="styles/reports.css">
    <script>
        function convertToLocalTime(utcDateString) {
            const utcDate = new Date(utcDateString + ' UTC');
            return utcDate.toLocaleString();
        }

        document.addEventListener('DOMContentLoaded', function() {
            const timeElements = document.querySelectorAll('.utc-time');
            timeElements.forEach(function(element) {
                element.textContent = convertToLocalTime(element.getAttribute('data-utc-time'));
            });
        });
    </script>
</head>
<body>

<h1>Reports</h1>

<form method="GET">
    <label for="check_date">Select a Check Date:</label>
    <select name="check_date" id="check_date" onchange="this.form.submit()">
        <option value="">-- Select Date --</option>
        <?php foreach ($check_dates as $date): ?>
            <option value="<?= $date['check_date'] ?>" <?= $selected_date == $date['check_date'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($date['check_date']) ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>

<?php if ($selected_date && !empty($reports)): ?>
    <h2>Report for <?= htmlspecialchars($selected_date) ?></h2>
    <table>
        <thead>
            <tr>
                <th>Truck</th>
                <th>Locker</th>
                <th>Check Date & Time (Local)</th>
                <th>Checked By</th>
                <th>Item</th>
                <th>Present</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($reports as $report): ?>
                <tr>
                    <td><?= htmlspecialchars($report['truck_name']) ?></td>
                    <td><?= htmlspecialchars($report['locker_name']) ?></td>
                    <td><span class="utc-time" data-utc-time="<?= $report['check_date'] ?>"></span></td>
                    <td><?= htmlspecialchars($report['checked_by']) ?></td>
                    <td><?= htmlspecialchars($report['item_name']) ?></td>
                    <td><?= $report['is_present'] ? 'Yes' : 'No' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php elseif ($selected_date): ?>
    <p>No checks found for this date.</p>
<?php endif; ?>

<?php include 'templates/footer.php'; ?>

</body>
</html>