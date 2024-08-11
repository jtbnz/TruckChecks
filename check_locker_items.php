<?php
include 'db.php';
include 'templates/header.php';

session_start();

$db = get_db_connection();

// Handle form submission to update the checks and check_items tables
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_items'])) {
    $locker_id = $_POST['locker_id'];
    $checked_by = $_POST['checked_by'];
    $checked_items = isset($_POST['checked_items']) ? $_POST['checked_items'] : [];

    // Insert a new check record
    $check_query = $db->prepare('INSERT INTO checks (locker_id, check_date, checked_by) VALUES (:locker_id, NOW(), :checked_by)');
    $check_query->execute([
        'locker_id' => $locker_id,
        'checked_by' => $checked_by
    ]);

    // Get the ID of the newly inserted check
    $check_id = $db->lastInsertId();

    // Insert check items (whether present or not)
    $items_query = $db->prepare('SELECT id FROM items WHERE locker_id = :locker_id');
    $items_query->execute(['locker_id' => $locker_id]);
    $items = $items_query->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $item) {
        $is_present = in_array($item['id'], $checked_items) ? 1 : 0;
        $check_item_query = $db->prepare('INSERT INTO check_items (check_id, item_id, is_present) VALUES (:check_id, :item_id, :is_present)');
        $check_item_query->execute([
            'check_id' => $check_id,
            'item_id' => $item['id'],
            'is_present' => $is_present
        ]);
    }
}

// Fetch all trucks
$trucks = $db->query('SELECT * FROM trucks')->fetchAll(PDO::FETCH_ASSOC);

// Check if a truck has been selected
$selected_truck_id = isset($_GET['truck_id']) ? $_GET['truck_id'] : null;

if ($selected_truck_id) {
    // Fetch lockers for the selected truck
    $query = $db->prepare('SELECT * FROM lockers WHERE truck_id = :truck_id');
    $query->execute(['truck_id' => $selected_truck_id]);
    $lockers = $query->fetchAll(PDO::FETCH_ASSOC);

    $selected_locker_id = isset($_GET['locker_id']) ? $_GET['locker_id'] : null;

    if ($selected_locker_id) {
        // Fetch items for the selected locker
        $query = $db->prepare('SELECT * FROM items WHERE locker_id = :locker_id');
        $query->execute(['locker_id' => $selected_locker_id]);
        $items = $query->fetchAll(PDO::FETCH_ASSOC);

        // Fetch locker notes
        $locker_query = $db->prepare('SELECT notes FROM lockers WHERE id = :locker_id');
        $locker_query->execute(['locker_id' => $selected_locker_id]);
        $locker_notes = $locker_query->fetchColumn();

        // Fetch last check date and checked_by
        $last_check_query = $db->prepare('SELECT check_date, checked_by FROM checks WHERE locker_id = :locker_id ORDER BY check_date DESC LIMIT 1');
        $last_check_query->execute(['locker_id' => $selected_locker_id]);
        $last_check = $last_check_query->fetch(PDO::FETCH_ASSOC);

        if ($last_check) {
            $last_check_date = new DateTime($last_check['check_date']);
            $today = new DateTime();
            $days_since_last_check = $today->diff($last_check_date)->days;

            if ($days_since_last_check == 0) {
                $days_since_last_check_text = "Locker has been checked today by " . htmlspecialchars($last_check['checked_by']);
            } else {
                $days_since_last_check_text = "Days since last check: " . $days_since_last_check  ;
                $last_check_text = " (" . htmlspecialchars($last_check['checked_by']) . ")";
            }
        } else {
            $days_since_last_check_text = "Never Checked";
            $last_check_text = "";
        }
    } else {
        $items = [];
        $locker_notes = '';
        $days_since_last_check_text = "Never Checked";
        $last_check_text = "";
    }
} else {
    $lockers = [];
    $items = [];
    $locker_notes = '';
    $days_since_last_check_text = "Never Checked";
    $last_check_text = "";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check Locker Items</title>
    <link rel="stylesheet" href="styles/check_locker_items.css?id=V2">
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Load the last checked-by name from localStorage
            const lastCheckedBy = localStorage.getItem('lastCheckedBy');
            const checkedByInput = document.getElementById('checked_by');

            if (lastCheckedBy) {
                checkedByInput.value = lastCheckedBy;
            }

            // Save the checked-by name to localStorage when the form is submitted
            document.querySelector('form').addEventListener('submit', function() {
                const checkedBy = checkedByInput.value;
                localStorage.setItem('lastCheckedBy', checkedBy);
            });
        });

        function toggleCheck(card) {
            const checkbox = card.querySelector('.hidden-checkbox');
            if (checkbox.checked) {
                checkbox.checked = false;
                card.classList.remove('checked');
            } else {
                checkbox.checked = true;
                card.classList.add('checked');
            }
        }
    </script>
</head>
<body>

<h1>Check Locker Items</h1>

<!-- Truck Selection Form -->
<form method="GET">
    <label for="truck_id">Select a Truck:</label>
    <select name="truck_id" id="truck_id" onchange="this.form.submit()">
        <option value="">-- Select Truck --</option>
        <?php foreach ($trucks as $truck): ?>
            <option value="<?= $truck['id'] ?>" <?= $selected_truck_id == $truck['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($truck['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>

<?php if ($selected_truck_id): ?>
    <?php if (!empty($lockers)): ?>
        <!-- Locker Selection Form -->
        <form method="GET">
            <input type="hidden" name="truck_id" value="<?= $selected_truck_id ?>">
            <label for="locker_id">Select a Locker:</label>
            <select name="locker_id" id="locker_id" onchange="this.form.submit()">
                <option value="">-- Select Locker --</option>
                <?php foreach ($lockers as $locker): ?>
                    <option value="<?= $locker['id'] ?>" <?= $selected_locker_id == $locker['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($locker['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if ($selected_locker_id): ?>
            <div>
                <h3>Locker: <?= htmlspecialchars($lockers[array_search($selected_locker_id, array_column($lockers, 'id'))]['name']) ?></h3>
                <div class="center-container">
                    <span class='days-since-check'>
                        <?= htmlspecialchars($days_since_last_check_text) ?> 
                        <?= htmlspecialchars($last_check_text) ?>
                    </span>
                </div>

                 <?php
                    if (!empty($locker_notes)) { 
                        echo "<BR>";
                        echo "<div class='center-container'>";
                        echo "<span class='days-since-check'>";
                        echo  htmlspecialchars($locker_notes);
                        echo "</span>";
                        echo "</div>";
                    };
                 ?> 

                <form method="POST">
                    <input type="hidden" name="locker_id" value="<?= $selected_locker_id ?>">
                    <div class="item-grid">
                        <?php foreach ($items as $item): ?>
                            <div class="item-card" onclick="toggleCheck(this)">
                                <input type="checkbox" name="checked_items[]" value="<?= $item['id'] ?>" class="hidden-checkbox">
                                <div class="item-content"><?= htmlspecialchars($item['name']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <label for="checked_by">Checked by:</label>
                    <input type="text" name="checked_by" id="checked_by" required value="">
                    <button type="submit" name="check_items" class="submit-button">Submit Checks</button>
                </form>
            </div>
        <?php else: ?>
            <p>Please select a locker to check its items.</p>
        <?php endif; ?>
    <?php else: ?>
        <p>No lockers found for this truck.</p>
    <?php endif; ?>
<?php else: ?>
    <p>Please select a truck to view its lockers and items.</p>
<?php endif; ?>

<?php include 'templates/footer.php'; ?>

</body>
</html>