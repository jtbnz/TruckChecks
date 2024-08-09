<?php
include 'db.php';
include 'templates/header.php';

session_start();

$db = get_db_connection();

// Fetch the last 105 unique check dates
$check_dates_query = $db->query('SELECT DISTINCT DATE(check_date) as check_date FROM checks ORDER BY check_date DESC LIMIT 105');
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

function array_to_csv_download($array, $filename = "export.csv", $delimiter = ",") {
    // Open raw memory as file, no need for temp files
    $f = fopen('php://memory', 'w'); 

    // Add UTF-8 BOM for Excel compatibility
    fputs($f, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF)));

    // Loop over the input array
    foreach ($array as $line) { 
        // Generate CSV lines from the inner arrays
        fputcsv($f, $line, $delimiter); 
    }

    // Rewind the "file" with the csv lines
    fseek($f, 0);

    // Tell the browser it's going to be a csv file
    header('Content-Type: application/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '";');

    // Output all remaining data on the file pointer
    fpassthru($f);
}

if (isset($_POST['download_csv']) && !empty($reports)) {
    $csv_data = [["Truck", "Locker", "Check Date", "Checked By", "Item", "Present"]];
    
    foreach ($reports as $report) {
        $csv_data[] = [
            $report['truck_name'],
            $report['locker_name'],
            $report['check_date'],
            $report['checked_by'],
            $report['item_name'],
            $report['is_present'] ? 'Yes' : 'No'
        ];
    }
    
    array_to_csv_download($csv_data, "report_{$selected_date}.csv");
    exit;
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
        function convertToLocalDate(utcDateString) {
            const utcDate = new Date(utcDateString + 'Z'); // Ensures the string is treated as UTC
            return utcDate.toLocaleDateString(); // Only the date part, in local timezone
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Update dropdown options to local date
            const dropdownOptions = document.querySelectorAll('#check_date option');
            dropdownOptions.forEach(function(option) {
                const utcDate = option.value;
                if (utcDate) {
                    option.textContent = convertToLocalDate(utcDate);
                }
            });

            // Update report timestamps to local date and time
            const timeElements = document.querySelectorAll('.utc-time');
            timeElements.forEach(function(element) {
                element.textContent = convertToLocalDate(element.getAttribute('data-utc-time')) + ' ' + new Date(element.getAttribute('data-utc-time') + 'Z').toLocaleTimeString();
            });

            // Handle expand/collapse
            const truckHeaders = document.querySelectorAll('.truck-header');
            truckHeaders.forEach(function(header) {
                header.addEventListener('click', function() {
                    const truckSection = header.nextElementSibling;
                    truckSection.classList.toggle('hidden');
                });
            });

            const lockerHeaders = document.querySelectorAll('.locker-header');
            lockerHeaders.forEach(function(header) {
                header.addEventListener('click', function() {
                    const lockerSection = header.nextElementSibling;
                    lockerSection.classList.toggle('hidden');
                });
            });
        });
    </script>
    <style>
        .hidden {
            display: none;
        }
        .truck-header, .locker-header {
            cursor: pointer;
            font-weight: bold;
            padding: 10px;
            background-color: #f1f1f1;
            border: 1px solid #ccc;
            margin-top: 10px;
        }
        .truck-section, .locker-section {
            padding-left: 20px;
            margin-bottom: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
        }
    </style>
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
    <?php 
    $current_truck = '';
    $current_locker = '';
    ?>
    <?php foreach ($reports as $report): ?>
        <?php if ($current_truck !== $report['truck_name']): ?>
            <?php if ($current_truck !== ''): ?>
                </div> <!-- Close previous truck section -->
            <?php endif; ?>
            <div class="truck-header"><?= htmlspecialchars($report['truck_name']) ?></div>
            <div class="truck-section hidden">
            <?php $current_truck = $report['truck_name']; ?>
        <?php endif; ?>

        <?php if ($current_locker !== $report['locker_name']): ?>
            <?php if ($current_locker !== ''): ?>
                </div> <!-- Close previous locker section -->
            <?php endif; ?>
            <div class="locker-header"><?= htmlspecialchars($report['locker_name']) ?></div>
            <div class="locker-section hidden">
            <?php $current_locker = $report['locker_name']; ?>
        <?php endif; ?>

        <table>
            <tr>
                <td><span class="utc-time" data-utc-time="<?= $report['check_date'] ?>"></span></td>
                <td><?= htmlspecialchars($report['checked_by']) ?></td>
                <td><?= htmlspecialchars($report['item_name']) ?></td>
                <td><?= $report['is_present'] ? 'Yes' : 'No' ?></td>
            </tr>
        </table>
    <?php endforeach; ?>
    </div> <!-- Close last locker section -->
    </div> <!-- Close last truck section -->

    <form method="POST" style="text-align: center; margin-top: 20px;">
        <input type="hidden" name="download_csv" value="1">
        <button type="submit" class="button touch-button">Download as CSV</button>
    </form>
<?php elseif ($selected_date): ?>
    <p>No checks found for this date.</p>
<?php endif; ?>

<?php include 'templates/footer.php'; ?>

</body>
</html>
