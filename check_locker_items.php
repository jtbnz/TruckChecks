<?php
include_once('auth.php');

// Define CHECKPROTECT constant if not already defined
if (!defined('CHECKPROTECT')) {
    define('CHECKPROTECT', true); // Enable security code protection by default
}

// Get current station for settings (if available)
$current_station = null;
try {
    // Try to get station context if user is authenticated
    $current_station = getCurrentStation();
} catch (Exception $e) {
    // Continue without station context for backward compatibility
    error_log("Station context error: " . $e->getMessage());
}

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

// Get station-specific settings or use defaults
$RANDORDER = $current_station ? getStationSetting('randomize_order', RANDORDER) : RANDORDER;
$IS_DEMO = $current_station ? getStationSetting('is_demo', IS_DEMO) : IS_DEMO;

$db = get_db_connection();

function is_code_valid($db, $code, $station_id = null) {
    if ($station_id) {
        // Check station-specific security code
        $query = $db->prepare("SELECT COUNT(*) FROM station_settings WHERE station_id = :station_id AND setting_key = 'security_code' AND setting_value = :code");
        $query->execute(['station_id' => $station_id, 'code' => $code]);
        return $query->fetchColumn() > 0;
    } else {
        // Fallback to old protection_codes table for backward compatibility
        // No fallback - only use station-specific security codes
        return false; // No station context means no valid code
        $query->execute(['code' => $code]);
        return $query->fetchColumn() > 0;
    }
}

if (CHECKPROTECT && isset($_GET['validate_code'])) {
    $code = $_GET['validate_code'];
    $station_id = $current_station ? $current_station['id'] : null;
    $is_valid = is_code_valid($db, $code, $station_id);
    echo json_encode(['valid' => $is_valid]);
    exit;
}

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
    $check_query = $db->prepare("INSERT INTO checks (locker_id, check_date, checked_by, ignore_check) VALUES (:locker_id, NOW(), :checked_by, 0 )");
    $check_query->execute([
        'locker_id' => $locker_id,
        'checked_by' => $checked_by
    ]);

    // Get the ID of the newly inserted check
    $check_id = $db->lastInsertId();

    // Insert the note into the check_notes table
    $note_query = $db->prepare("INSERT INTO check_notes (check_id, note) VALUES (:check_id, :note)");
    $note_query->execute(['check_id' => $check_id, 'note' => $notes]);

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

// Fetch trucks - filter by station if available
if ($current_station) {
    $trucks_query = $db->prepare('SELECT * FROM trucks WHERE station_id = ? ORDER BY name');
    $trucks_query->execute([$current_station['id']]);
    $trucks = $trucks_query->fetchAll(PDO::FETCH_ASSOC);
} else {
    $trucks = $db->query('SELECT * FROM trucks ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
}

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
        if ($RANDORDER) {
            $query = $db->prepare('SELECT * FROM items WHERE locker_id = :locker_id ORDER BY RAND()');
            echo "<!-- Random order -->";
        } else {
            $query = $db->prepare('SELECT * FROM items WHERE locker_id = :locker_id ORDER BY id');
            echo "<!-- Not Random order -->";
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

        // Fetch the last 5 checks for the current locker
        $last_five_checks_query = $db->prepare("
            SELECT checked_by, CONVERT_TZ(check_date,'+00:00', '+12:00') as check_date 
            FROM checks 
            WHERE locker_id = :locker_id 
            ORDER BY check_date DESC 
            LIMIT 5
        ");
        $last_five_checks_query->execute(['locker_id' => $selected_locker_id]);
        $last_five_checks = $last_five_checks_query->fetchAll(PDO::FETCH_ASSOC);
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
    <link rel="stylesheet" href="styles/check_locker_items.css?id=<?php echo $version; ?>">
    <link rel="stylesheet" href="styles/styles.css?id=<?php echo $version; ?>">
    <script>
        function getCookie(name) {
            const value = `; ${document.cookie}`;
            const parts = value.split(`; ${name}=`);
            if (parts.length === 2) return parts.pop().split(';').shift();
            return null;
        }

        function checkProtection() {
            const CHECKPROTECT = <?php echo (CHECKPROTECT && $current_station) ? 'true' : 'false'; ?>;
            if (CHECKPROTECT) {
                let code = null;
                
                // First try to get station-specific security code from cookie
                <?php if ($current_station): ?>
                const stationCode = getCookie('security_code_station_<?= $current_station['id'] ?>');
                if (stationCode) {
                    code = stationCode;
                }
                <?php endif; ?>
                
                // Fallback to general security code cookie
                if (!code) {
                    code = getCookie('security_code');
                }
                
                // Fallback to localStorage for backward compatibility
                if (!code) {
                    code = localStorage.getItem('protection_code');
                }
                
                if (!code) {
                    alert('Access denied. Missing security code. Please scan the QR code from your station admin.');
                    window.location.href = 'index.php';
                } else {
                    fetch('check_locker_items.php?validate_code=' + code)
                        .then(response => response.json())
                        .then(data => {
                            if (!data.valid) {
                                alert('Access denied. Invalid security code. Please scan the QR code from your station admin.');
                                window.location.href = 'index.php';
                            }
                        })
                        .catch(error => {
                            console.error('Security validation error:', error);
                            alert('Security validation failed. Please try again.');
                            window.location.href = 'index.php';
                        });
                }
            }
        }
        window.onload = checkProtection;
    </script>
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
    <script>
        function checkFormSubmission(event) {
            var checkboxes = document.querySelectorAll('input[name="checked_items[]"]:checked');
            if (checkboxes.length === 0) {
                var confirmSubmit = confirm("No items are selected. Do you want to continue?");
                if (!confirmSubmit) {
                    event.preventDefault(); // Prevent form submission
                }
            }
        }
    </script>
</head>
<body class="<?php echo $IS_DEMO ? 'demo-mode' : ''; ?>">

<?php if ($current_station): ?>
    <div style="text-align: center; background-color: #f8f9fa; padding: 10px; margin-bottom: 20px; border-radius: 5px;">
        <strong>Station:</strong> <?= htmlspecialchars($current_station['name']) ?>
        <?php if (function_exists('getCurrentUser') && getCurrentUser()): ?>
            | <a href="select_station.php?redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>" style="color: #12044C;">Change Station</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

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
                <h2>Locker: <?= htmlspecialchars($lockers[array_search($selected_locker_id, array_column($lockers, 'id'))]['name']) ?></h2>
                <div class="center-container">
                    <span class='days-since-check'>
                        <?= $days_since_last_check_text ?> 
                        <?= htmlspecialchars($last_check_text) ?>
                    </span>
                </div>

                <?php if (!empty($locker_notes)): ?>
                    <br>
                    <div class='center-container'>
                        <span class='days-since-check'>
                            <?= htmlspecialchars($locker_notes) ?>
                        </span>
                    </div>
                <?php endif; ?>

                <form method="POST" onsubmit="checkFormSubmission(event)">
                    <input type="hidden" name="locker_id" value="<?= $selected_locker_id ?>">
                    <?= $last_check_border ?>
                        <?php foreach ($items as $item): ?>
                            <?php $split_name = process_words($item['name']); ?>
                            <div class="item-card" onclick="toggleCheck(this)">
                                <input type="checkbox" name="checked_items[]" value="<?= $item['id'] ?>" class="hidden-checkbox">
                                <div class="item-content"><?= $split_name ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <label for="checked_by">Checked by:</label>
                    <input type="text" name="checked_by" id="checked_by" required value="<?= isset($_COOKIE['prevName']) ? htmlspecialchars($_COOKIE['prevName']) : '' ?>">
                    
                    <!-- New notes input field -->
                    <label for="notes">Any notes for this check?</label>
                    <textarea id="notes" name="notes"><?php echo htmlspecialchars($last_notes); ?></textarea>

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

<footer>
    <?php $version = $_SESSION['version']; ?>
    <p><a href="index.php" class="button touch-button">Return to Home</a></p>

    <!-- Display the last 5 checks -->
    <?php if (!empty($last_five_checks)): ?>
        <div style="color: grey; font-size: small;">
            <h3>Last 5 Checks:</h3>
            <?php foreach ($last_five_checks as $check): ?>
                <?= htmlspecialchars($check['checked_by']) ?> - <?= htmlspecialchars($check['check_date']) ?><br>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <p id="last-refreshed" style="margin-top: 10px;"></p>
    <div class="version-number">
        Version: <?php echo htmlspecialchars($version); ?>
    </div>
</footer>
</body>
</html>
