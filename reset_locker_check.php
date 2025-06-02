<?php
include_once('config.php');
include_once('auth.php');
include_once('db.php');

// Ensure user is authenticated
requireAuth();
$user = getCurrentUser();
$db = get_db_connection();

// Authorize: only superuser or station_admin
if ($user['role'] !== 'superuser' && $user['role'] !== 'station_admin') {
    header('Location: admin.php?error=access_denied_reset_checks');
    exit;
}

$page_title = "Mark Locker Checks as Ignored";
$current_station_for_admin = null;
$stations_for_superuser = [];
$trucks_in_selected_station = [];
$selected_station_id_for_form = null; // For superuser pre-selection on POST
$selected_truck_ids_for_form = []; // For pre-selection on POST

// Determine station context and available stations/trucks
if ($user['role'] === 'station_admin') {
    $current_station_for_admin = requireStation(); // This ensures station admin has a station
    $selected_station_id_for_form = $current_station_for_admin['id'];
    // Get trucks for this station admin's current station
    $stmt_trucks = $db->prepare("SELECT id, name FROM trucks WHERE station_id = :station_id ORDER BY name");
    $stmt_trucks->execute(['station_id' => $current_station_for_admin['id']]);
    $trucks_in_selected_station = $stmt_trucks->fetchAll(PDO::FETCH_ASSOC);
} elseif ($user['role'] === 'superuser') {
    // Superuser: get all stations for selection
    $stmt_stations = $db->prepare("SELECT id, name FROM stations ORDER BY name");
    $stmt_stations->execute();
    $stations_for_superuser = $stmt_stations->fetchAll(PDO::FETCH_ASSOC);

    // If a station is selected by superuser (POST or GET for convenience after POST)
    if (isset($_REQUEST['selected_station_id']) && !empty($_REQUEST['selected_station_id'])) {
        $selected_station_id_for_form = (int)$_REQUEST['selected_station_id'];
        // Validate selected_station_id
        $is_valid_station = false;
        foreach ($stations_for_superuser as $s) {
            if ($s['id'] == $selected_station_id_for_form) {
                $is_valid_station = true;
                break;
            }
        }
        if ($is_valid_station) {
            $stmt_trucks = $db->prepare("SELECT id, name FROM trucks WHERE station_id = :station_id ORDER BY name");
            $stmt_trucks->execute(['station_id' => $selected_station_id_for_form]);
            $trucks_in_selected_station = $stmt_trucks->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $error_message = "Invalid station selected by superuser.";
            $selected_station_id_for_form = null; // Reset if invalid
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['mark_ignored'])) {
    $target_station_id = null;
    if ($user['role'] === 'station_admin') {
        $target_station_id = $current_station_for_admin['id'];
    } elseif ($user['role'] === 'superuser') {
        $target_station_id = isset($_POST['selected_station_id']) ? (int)$_POST['selected_station_id'] : null;
        // Re-populate selected trucks for form display on error/success
        $selected_station_id_for_form = $target_station_id; 
        if ($target_station_id) { // Re-fetch trucks if station was selected
             $stmt_trucks_post = $db->prepare("SELECT id, name FROM trucks WHERE station_id = :station_id ORDER BY name");
             $stmt_trucks_post->execute(['station_id' => $target_station_id]);
             $trucks_in_selected_station = $stmt_trucks_post->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    $target_truck_ids = isset($_POST['truck_ids']) ? array_map('intval', (array)$_POST['truck_ids']) : [];
    $selected_truck_ids_for_form = $target_truck_ids; // For pre-selection

    if (!$target_station_id) {
        $error_message = "No station selected. Please select a station.";
    } else {
        try {
            $db->beginTransaction();

            // Construct the subquery to find the IDs of the latest checks
            // for lockers in the target station and optionally target trucks.
            $subQuerySql = "
                SELECT c.id
                FROM checks c
                INNER JOIN (
                    SELECT l_inner.id as locker_id, MAX(c_inner.check_date) as max_date
                    FROM lockers l_inner
                    INNER JOIN trucks t_inner ON l_inner.truck_id = t_inner.id
                    INNER JOIN checks c_inner ON c_inner.locker_id = l_inner.id
                    WHERE t_inner.station_id = :target_station_id";
            
            $subQueryParams = [':target_station_id' => $target_station_id];

            if (!empty($target_truck_ids)) {
                $truck_placeholders = implode(',', array_fill(0, count($target_truck_ids), '?'));
                $subQuerySql .= " AND t_inner.id IN ($truck_placeholders)";
                // Add truck IDs to params carefully
                foreach ($target_truck_ids as $k => $tid) {
                    $subQueryParams[':truck_id_' . $k] = $tid; // Use named placeholders for truck IDs
                }
                // Adjust SQL to use named placeholders if array_merge is tricky with PDO
                // For simplicity, let's assume direct array merge works or adjust placeholders
                 $truck_id_param_index = 0;
                 foreach ($target_truck_ids as $truck_id_val) {
                     $subQueryParams['truck_id_placeholder_' . $truck_id_param_index] = $truck_id_val;
                     $truck_id_param_index++;
                 }
                 if (!empty($target_truck_ids)) {
                    $truck_named_placeholders = implode(',', array_map(function($i) { return ':truck_id_placeholder_'.$i; }, range(0, count($target_truck_ids)-1)));
                    $subQuerySql = str_replace("IN ($truck_placeholders)", "IN ($truck_named_placeholders)", $subQuerySql);
                 }

            }
            
            $subQuerySql .= "
                    GROUP BY l_inner.id
                ) latest_checks_for_lockers ON c.locker_id = latest_checks_for_lockers.locker_id 
                                            AND c.check_date = latest_checks_for_lockers.max_date
            ";
            
            // Main UPDATE query
            $updateSql = "UPDATE checks SET ignore_check = 1 WHERE id IN ($subQuerySql)";
            
            $stmt_update = $db->prepare($updateSql);
            $stmt_update->execute($subQueryParams);
            $affected_rows = $stmt_update->rowCount();

            $db->commit();
            $success_message = "Successfully marked $affected_rows latest check(s) as ignored for the selected scope.";

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error_message = "Error marking checks as ignored: " . $e->getMessage();
        }
    }
}

include 'templates/header.php';
?>

<style>
    .reset-checks-container { max-width: 800px; margin: 20px auto; padding: 20px; background-color: #f9f9f9; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
    .form-group select, .form-group button { width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ccc; box-sizing: border-box; }
    .form-group select[multiple] { height: 150px; }
    .button-danger { background-color: #dc3545; color: white; }
    .button-danger:hover { background-color: #c82333; }
    .message { padding: 10px; margin: 20px 0; border-radius: 5px; }
    .success-message { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .error-message { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    .info-message { background-color: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
    .station-info { font-weight: bold; margin-bottom: 15px; padding: 10px; background-color: #e9ecef; border-radius: 4px; }
</style>

<div class="reset-checks-container">
    <h1><?= htmlspecialchars($page_title) ?></h1>

    <?php if (isset($success_message)): ?>
        <div class="message success-message"><?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>
    <?php if (isset($error_message)): ?>
        <div class="message error-message"><?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <div class="message info-message">
        This action will mark the most recent check for selected lockers as "ignored". 
        These lockers will appear as unchecked (red) on the main status page until a new check is submitted. 
        Historical check data will be preserved.
    </div>

    <form method="POST" action="reset_locker_check.php" onsubmit="return confirm('Are you sure you want to mark these checks as ignored?');">
        <?php if ($user['role'] === 'superuser'): ?>
            <div class="form-group">
                <label for="selected_station_id">Select Station:</label>
                <select name="selected_station_id" id="selected_station_id" required onchange="this.form.submit()"> <!-- Submit form on change to load trucks -->
                    <option value="">-- Select a Station --</option>
                    <?php foreach ($stations_for_superuser as $station_opt): ?>
                        <option value="<?= $station_opt['id'] ?>" <?= ($selected_station_id_for_form == $station_opt['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($station_opt['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php elseif ($user['role'] === 'station_admin' && $current_station_for_admin): ?>
            <div class="station-info">
                Operating on Station: <?= htmlspecialchars($current_station_for_admin['name']) ?>
                <input type="hidden" name="selected_station_id" value="<?= $current_station_for_admin['id'] ?>">
            </div>
        <?php endif; ?>

        <?php if ($selected_station_id_for_form && !empty($trucks_in_selected_station)): ?>
            <div class="form-group">
                <label for="truck_ids">Select Trucks (Optional - leave blank for all trucks in station):</label>
                <select name="truck_ids[]" id="truck_ids" multiple>
                    <?php foreach ($trucks_in_selected_station as $truck_opt): ?>
                        <option value="<?= $truck_opt['id'] ?>" <?= in_array($truck_opt['id'], $selected_truck_ids_for_form) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($truck_opt['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <button type="submit" name="mark_ignored" class="button-danger">
                    Mark Selected Checks as Ignored
                </button>
            </div>
        <?php elseif ($selected_station_id_for_form && empty($trucks_in_selected_station)): ?>
            <p>No trucks found in the selected station.</p>
        <?php elseif ($user['role'] === 'superuser' && empty($selected_station_id_for_form)): ?>
            <p>Please select a station to see available trucks and options.</p>
        <?php endif; ?>
    </form>

    <div style="margin-top: 20px;">
        <a href="admin.php" class="button">Back to Admin Page</a>
    </div>
</div>

<?php include 'templates/footer.php'; ?>
