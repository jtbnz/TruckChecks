<?php
// This page is now a component loaded by admin.php
// It expects $pdo, $user, $userRole, $currentStation, $userStations to be available.

$page_title = "Mark Locker Checks as Ignored";
$trucks_in_current_station = [];
$selected_truck_ids_for_form = []; // For pre-selection on POST/reload

// Determine station context and available trucks
// $currentStation is provided by admin.php
// For station_admin, $currentStation will be their single station, or null if they have multiple and none is 'active' via admin.php logic
// For superuser, $currentStation is the one selected in admin.php's dropdown.

$station_to_operate_on = null;

if ($userRole === 'station_admin') {
    if ($currentStation) { // If admin.php determined a single active station for them
        $station_to_operate_on = $currentStation;
    } elseif (count($userStations) === 1) { // Or if they only have one assigned station
        $station_to_operate_on = $userStations[0];
    } else {
        // Station admin has multiple stations, and none is specifically active.
        // This component might need a way for them to pick one of their $userStations if action is station-specific.
        // For now, let's prevent action if no single station context.
        echo "<div class='alert alert-info'>Station administrators with multiple assigned stations should select a station context if applicable, or this tool might operate on a default or require selection. (Feature enhancement needed for multi-station admin context here)</div>";
        // For this specific tool, it might be reasonable to allow selection from their $userStations.
        // However, the original logic for superuser had a station selector that reloaded the page.
        // To keep it simple for componentization, we'll rely on $currentStation from admin.php.
        // If $currentStation is null for a superuser, they need to select one in admin.php.
    }
} elseif ($userRole === 'superuser') {
    $station_to_operate_on = $currentStation; // Superuser operates on the globally selected $currentStation
}


if ($station_to_operate_on) {
    $stmt_trucks = $pdo->prepare("SELECT id, name FROM trucks WHERE station_id = :station_id ORDER BY name");
    $stmt_trucks->execute(['station_id' => $station_to_operate_on['id']]);
    $trucks_in_current_station = $stmt_trucks->fetchAll(PDO::FETCH_ASSOC);
}


// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['mark_ignored'])) {
    // The station_id for operation is now derived from $station_to_operate_on
    $target_station_id = $station_to_operate_on ? $station_to_operate_on['id'] : null;
    
    $target_truck_ids = isset($_POST['truck_ids']) ? array_map('intval', (array)$_POST['truck_ids']) : [];
    $selected_truck_ids_for_form = $target_truck_ids; // For pre-selection on error/success

    if (!$target_station_id) {
        $error_message = "No station context. Please select a station via the admin panel sidebar if you are a superuser.";
    } else {
        try {
            $pdo->beginTransaction();

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
                $truck_named_placeholders = [];
                foreach ($target_truck_ids as $k => $tid) {
                    $placeholder = ':truck_id_' . $k;
                    $truck_named_placeholders[] = $placeholder;
                    $subQueryParams[$placeholder] = $tid;
                }
                $subQuerySql .= " AND t_inner.id IN (" . implode(',', $truck_named_placeholders) . ")";
            }
            
            $subQuerySql .= "
                    GROUP BY l_inner.id
                ) latest_checks_for_lockers ON c.locker_id = latest_checks_for_lockers.locker_id 
                                            AND c.check_date = latest_checks_for_lockers.max_date
            ";
            
            $updateSql = "UPDATE checks SET ignore_check = 1 WHERE id IN ($subQuerySql)";
            
            $stmt_update = $pdo->prepare($updateSql);
            $stmt_update->execute($subQueryParams);
            $affected_rows = $stmt_update->rowCount();

            $pdo->commit();
            $success_message = "Successfully marked $affected_rows latest check(s) as ignored for station '" . htmlspecialchars($station_to_operate_on['name']) . "'.";

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_message = "Error marking checks as ignored: " . $e->getMessage();
        }
    }
}
?>

<div class="component-container reset-checks-container">
    <style>
        /* Styles specific to reset_locker_check.php component */
        .reset-checks-container { max-width: 800px; margin: 0 auto; padding: 20px; background-color: #f9f9f9; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group select, .form-group button { width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ccc; box-sizing: border-box; }
        .form-group select[multiple] { height: 150px; }
        .button-danger { background-color: #dc3545; color: white; border:none; cursor:pointer; } /* Ensure button styles */
        .button-danger:hover { background-color: #c82333; }
        .message { padding: 10px; margin: 20px 0; border-radius: 5px; }
        .success-message { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error-message { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .info-message { background-color: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .station-info-display { font-weight: bold; margin-bottom: 15px; padding: 10px; background-color: #e9ecef; border-radius: 4px; }
         .alert { /* General purpose alert for component level */
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid transparent;
            border-radius: .25rem;
        }
        .alert-info {
            color: #0c5460;
            background-color: #d1ecf1;
            border-color: #bee5eb;
        }
        .alert-warning {
            color: #856404;
            background-color: #fff3cd;
            border-color: #ffeeba;
        }
    </style>

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

    <?php if (!$station_to_operate_on && $userRole === 'superuser'): ?>
        <div class="alert alert-warning">Superusers: Please select a station from the main admin sidebar to manage its checks.</div>
    <?php elseif (!$station_to_operate_on && $userRole === 'station_admin' && count($userStations) > 1): ?>
        <div class="alert alert-warning">Station Admins: This tool currently operates on a single station context. If you manage multiple stations, a default or specific selection mechanism might be needed in the future. For now, ensure admin.php provides a $currentStation.</div>
    <?php elseif ($station_to_operate_on): ?>
        <form method="POST" action="admin.php?ajax=1&page=reset_locker_check.php" onsubmit="return confirm('Are you sure you want to mark these checks as ignored for station <?= htmlspecialchars($station_to_operate_on['name']) ?>?');">
            <input type="hidden" name="mark_ignored" value="1">
            <!-- selected_station_id is implicitly $station_to_operate_on['id'] now, no need for hidden field if logic uses $station_to_operate_on -->

            <div class="station-info-display">
                Operating on Station: <?= htmlspecialchars($station_to_operate_on['name']) ?>
            </div>

            <?php if (!empty($trucks_in_current_station)): ?>
                <div class="form-group">
                    <label for="truck_ids">Select Trucks (Optional - leave blank for all trucks in station):</label>
                    <select name="truck_ids[]" id="truck_ids" multiple>
                        <?php foreach ($trucks_in_current_station as $truck_opt): ?>
                            <option value="<?= $truck_opt['id'] ?>" <?= in_array($truck_opt['id'], $selected_truck_ids_for_form) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($truck_opt['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <button type="submit" class="button-danger">
                        Mark Selected Checks as Ignored
                    </button>
                </div>
            <?php elseif ($station_to_operate_on): // Station selected, but no trucks in it ?>
                <p>No trucks found in station '<?= htmlspecialchars($station_to_operate_on['name']) ?>'. Cannot reset checks.</p>
            <?php endif; ?>
        </form>
    <?php else: ?>
        <div class="alert alert-info">Please ensure a station context is active to use this tool. Superusers should select a station from the sidebar. Station admins should have an active station context.</div>
    <?php endif; ?>

    <!-- Removed Back to Admin button -->
</div>
