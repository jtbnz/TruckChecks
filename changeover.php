<?php


//session_start();
include 'db.php';
//include 'templates/header.php';

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

// Read the cookie value
$colorBlindMode = isset($_COOKIE['color_blind_mode']) ? $_COOKIE['color_blind_mode'] : false;



//IS_DEMO = isset($_SESSION['IS_DEMO']) && $_SESSION['IS_DEMO'] === true;

$db = get_db_connection();

function process_words($text, $max_length = 12, $reduce_font_threshold = 9) {
    // Split the text into words
    $words = explode(' ', $text);

    // Iterate through each word and apply the necessary transformations
    foreach ($words as &$word) {
        $word_length = strlen($word);

        if ($word_length > $max_length) {
            // Split the word if it is longer than the max_length
            $word = wordwrap($word, $max_length, '-', true);
        } elseif ($word_length >= $reduce_font_threshold) {
            // Reduce the font size if the word length is between 9 and 12 characters
            $word = '<span style="font-size: smaller;">' . htmlspecialchars($word) . '</span>';
        }
    }

    // Join the words back into a single string
    return implode(' ', $words);
}

// Handle form submission to update the checks and check_items tables
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_items'])) {
    $locker_id = $_POST['locker_id'];
    $checked_by = $_POST['checked_by'];
    $notes = $_POST['notes']; // Get the notes input
    $checked_items = isset($_POST['checked_items']) ? $_POST['checked_items'] : [];

    setcookie('prevName', $checked_by, time() + (86400 * 120), "/"); 

    // Insert a new check record
    // take out the timezone conversion
    // $check_query = $db->prepare("INSERT INTO checks (locker_id, check_date, checked_by, ignore_check) VALUES (:locker_id,CONVERT_TZ(NOW(),'+00:00', '+12:00'), :checked_by, 0 )");
    $check_query = $db->prepare("INSERT INTO checks (locker_id, check_date, checked_by, ignore_check) VALUES (:locker_id,NOW(), :checked_by, 0 )");
    $check_query->execute([
        'locker_id' => $locker_id,
        'checked_by' => $checked_by
    ]);

    
    // Get the ID of the newly inserted check
    $check_id = $db->lastInsertId();

    // Insert the note into the check_notes table removed the empty check so that it can be deleted
    //if (!empty($notes)) {
    $note_query = $db->prepare("INSERT INTO check_notes (check_id, note) VALUES (:check_id, :note)");
    $note_query->execute(['check_id' => $check_id, 'note' => $notes]);
    //}

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
        if (RANDORDER) {
            $query = $db->prepare('SELECT * FROM items WHERE locker_id = :locker_id ORDER BY RAND()');
            echo "<!-- Random order -->";
            //echo "<!-- " . $query   . " -->";
        } else {
            $query = $db->prepare('SELECT * FROM items WHERE locker_id = :locker_id ORDER BY id');
            echo "<!-- Not Random order -->";
            //echo "<!-- " . $query   . " -->";
        }
        $query->execute(['locker_id' => $selected_locker_id]);
        $items = $query->fetchAll(PDO::FETCH_ASSOC);

        // Fetch locker notes
        $locker_query = $db->prepare('SELECT notes FROM lockers WHERE id = :locker_id');
        $locker_query->execute(['locker_id' => $selected_locker_id]);
        $locker_notes = $locker_query->fetchColumn();

        $last_notes = '';
        $last_check_query = $db->prepare("
            SELECT cn.note 
            FROM check_notes cn
            JOIN checks c ON cn.check_id = c.id
            WHERE c.locker_id = :locker_id
            ORDER BY c.check_date DESC
            LIMIT 1
        ");
        $last_check_query->execute(['locker_id' => $selected_locker_id]);
        $last_notes = $last_check_query->fetchColumn();
        
     


        // Fetch last check date and checked_by
       // $last_check_query = $db->prepare('SELECT check_date, checked_by FROM checks WHERE locker_id = :locker_id ORDER BY check_date DESC LIMIT 1');
       
        $last_check_query = $db->prepare("SELECT CONVERT_TZ(check_date,'+00:00', '+12:00') as check_date, checked_by FROM checks WHERE locker_id = :locker_id ORDER BY check_date DESC LIMIT 1");
        $last_check_query->execute(['locker_id' => $selected_locker_id]);
        $last_check = $last_check_query->fetch(PDO::FETCH_ASSOC);
        $last_check_border = '<div class="item-grid" style="border: 2px solid lightgrey; padding: 10px; border-radius: 10px;">';
        if ($last_check) {
            $last_check_date = new DateTime($last_check['check_date']);
            date_default_timezone_set('Pacific/Auckland');
            $today = new DateTime();
            $current_datetime2 = date('Y-m-d H:i:s');
           
            $interval = $today->diff($last_check_date);
            echo "\n<!-- Interval " . $interval->format('%R%a days') . " -->";
            echo "\n<!-- Today " . $today->format('Y-m-d H:i:s') . " -->";
            echo "\n<!-- last check " . $last_check_date->format('Y-m-d H:i:s') . " -->";
            $days_since_last_check = $interval->days;



            if ($days_since_last_check == 0) {
                $days_since_last_check_text = "<span style='color: green;'>Locker has been checked in the last 24hours " . htmlspecialchars($last_check['checked_by']) . "</span>";
                $last_check_text = "";
                $last_check_border = '<div class="item-grid" style="border: 2px solid green; padding: 10px; background-color: green; border-radius: 10px;">';
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
    <meta name="description" content="Check lockers for missing items">
    <title>Check Locker Items</title>
    <link rel="stylesheet" href="styles/check_locker_items.css?id=<?php  echo $version;  ?> ">
    <link rel="stylesheet" href="styles/styles.css?id=<?php  echo $version;  ?> ">
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
                <?php if ($colorBlindMode): ?>
                    card.classList.remove('checkedCB');
                <?php else: ?>
                    card.classList.remove('checked');
                <?php endif; ?>

            } else {
                checkbox.checked = true;
                <?php if ($colorBlindMode): ?>
                    card.classList.add('checkedCB');
                <?php else: ?>
                    card.classList.add('checked');
                <?php endif; ?>
                
            }
        }



    </script>
</head>
<body class="<?php echo IS_DEMO ? 'demo-mode' : ''; ?>">

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

<?php

if ($selected_truck_id) {

    $truck_id = $selected_truck_id; 

    $query = $db->prepare("
        SELECT 
            t.name AS truck_name,
            l.name AS locker_name,
            i.name AS item_name
        FROM 
            trucks t
        JOIN 
            lockers l ON t.id = l.truck_id
        JOIN 
            items i ON l.id = i.locker_id
        WHERE 
            t.id = :truck_id
        ORDER BY 
            l.name, i.name
    ");
    $query->execute(['truck_id' => $truck_id]);
    $results = $query->fetchAll(PDO::FETCH_ASSOC);

    $current_locker = '';
    $locker_count = 0;

    echo "<table border='1' cellpadding='5' cellspacing='0' style='width: 100%;'>";
    echo "<tr><th>Locker</th><th>Item</th><th>Relief</th><th>Stays</th><th>Locker</th><th>Item</th><th>Relief</th><th>Stays</th></tr>";

    foreach ($results as $row) {
        if ($current_locker != $row['locker_name']) {
            if ($current_locker != '') {
                echo "</table>";
                $locker_count++;
                if ($locker_count % 2 == 0) {
                    echo "<tr></tr>";
                }
                echo "<table border='1' cellpadding='5' cellspacing='0' style='width: 100%;'>";
            }
            $current_locker = $row['locker_name'];
            echo "<tr><td colspan='4'><strong>Locker: " . htmlspecialchars($current_locker) . "</strong></td></tr>";
        }
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['locker_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['item_name']) . "</td>";
        echo "<td><input type='checkbox'></td>";
        echo "<td><input type='checkbox'></td>";
        echo "</tr>";
    }
    if ($current_locker != '') {
        echo "</table>";
    }
    echo "</table>";

    echo '<p><a href="changeover_pdf.php?truck_id=<?= $truck_id ?>" class="button touch-button">Generate PDF</a></p>';
} else {
    echo "<p>Please select a truck to view its lockers and items.</p>";
}
?>

<footer>
    <? $version = $_SESSION['version']; ?>
    <p><a href="index.php" class="button touch-button">Return to Home</a></p>
    <p><a href="quiz/quiz.php" class="button touch-button">Locker Quiz</a> </p>
    <p id="last-refreshed" style="margin-top: 10px;"></p> 
    <div class="version-number">
        Version: <?php echo htmlspecialchars($version); ?>
    </div>   
</footer>
</body>
</html>
