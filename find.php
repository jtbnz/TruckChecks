<?php
// This page is now a component loaded by admin.php
// It expects $pdo, $user, $userRole, $currentStation, $userStations to be available.

// Handle form submission to search for items
// The search query might come from GET if the form is submitted via GET, or POST.
// For AJAX components, it's often simpler if the component reloads itself via admin.php's AJAX mechanism.
// So, the form should submit in a way that admin.php reloads this component with new parameters.
$searchstr = $_REQUEST['searchQuery'] ?? null; // Use $_REQUEST to catch GET or POST
$report_data = [];

if ($searchstr) {
    if ($currentStation) {
        // Search within current station only
        $report_query = $pdo->prepare("
            SELECT 
                t.name as truck_name, 
                l.name as locker_name, 
                i.name as item_name
            FROM items i
            JOIN lockers l ON i.locker_id = l.id
            JOIN trucks t ON t.id = l.truck_id
            WHERE i.name COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', :searchstr, '%')
            AND t.station_id = :station_id
            ORDER BY t.name, l.name
        ");
        $report_query->execute(['searchstr' => $searchstr, 'station_id' => $currentStation['id']]);
    } else {
        // If no specific station is selected (e.g. superuser hasn't picked one, or station admin has multiple and no default)
        // For now, let's prevent search if no station context for superuser.
        // Station admin without a single $currentStation might search across all their $userStations.
        if ($userRole === 'superuser' && !$currentStation) {
             echo "<div class='alert alert-info'>Please select a station from the sidebar to search for items.</div>";
             $searchstr = null; // Prevent further processing
        } elseif ($userRole === 'station_admin' && !empty($userStations) && !$currentStation) {
            // Search across all assigned stations for a station_admin if no single $currentStation is active
            $station_ids_placeholders = implode(',', array_fill(0, count($userStations), '?'));
            $station_ids = array_column($userStations, 'id');
            
            $sql = "
                SELECT 
                    t.name as truck_name, 
                    l.name as locker_name, 
                    i.name as item_name
                FROM items i
                JOIN lockers l ON i.locker_id = l.id
                JOIN trucks t ON t.id = l.truck_id
                WHERE i.name COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', ?, '%')
                AND t.station_id IN ($station_ids_placeholders)
                ORDER BY t.name, l.name
            ";
            $params = array_merge([$searchstr], $station_ids);
            $report_query = $pdo->prepare($sql);
            $report_query->execute($params);
        } else {
            // Fallback or if $currentStation is somehow not set when it should be.
            // This case should ideally not be hit if admin.php correctly sets $currentStation.
             echo "<div class='alert alert-warning'>Cannot determine station context for search.</div>";
             $searchstr = null; // Prevent further processing
        }
    }
    
    if ($searchstr && isset($report_query)) { // Ensure query was prepared
        $report_data = $report_query->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<div class="component-container find-container">
    <style>
        /* Styles specific to find.php component */
        .find-container {
            max-width: 800px;
            margin: 0 auto; /* Centered within the content-area of admin.php */
            padding: 20px;
        }

        .page-header {
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid #12044C;
        }

        .page-title {
            color: #12044C;
            margin: 0;
        }
        
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

        .search-form {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .search-form h2 {
            margin-top: 0;
            color: #12044C;
        }

        .search-input {
            width: calc(100% - 120px); /* Adjust width considering the button */
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 16px;
            margin-right: 10px;
            box-sizing: border-box;
        }

        .search-button {
            padding: 12px 24px;
            background-color: #12044C;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .search-button:hover {
            background-color: #0056b3;
        }

        .results-table-container { /* Added a container for better styling */
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden; /* For rounded corners on table */
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .results-table-container table { /* Ensure table is styled within container */
            width: 100%;
            border-collapse: collapse;
        }

        .results-table-container th, .results-table-container td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .results-table-container th {
            background-color: #12044C;
            color: white;
            font-weight: bold;
        }

        .results-table-container tr:hover {
            background-color: #f8f9fa;
        }

        .no-results {
            text-align: center;
            padding: 40px;
            color: #666;
            font-style: italic;
        }
        
        .current-station-info {
            text-align: center;
            margin-bottom: 15px;
            padding: 10px;
            background-color: #e9ecef;
            border-radius: 5px;
            font-size: 14px;
            color: #495057;
        }


        /* Mobile responsive */
        @media (max-width: 768px) {
            .find-container {
                padding: 10px;
            }

            .search-input {
                width: 100%;
                margin-bottom: 10px;
                margin-right: 0;
            }

            .search-button {
                width: 100%;
            }

            .results-table-container th, .results-table-container td {
                padding: 10px 8px;
                font-size: 14px;
            }
        }
    </style>

    <div class="page-header">
        <h1 class="page-title">Find an Item</h1>
    </div>

    <?php if ($currentStation): ?>
        <div class="current-station-info">
            Searching within: <strong><?= htmlspecialchars($currentStation['name']) ?></strong>
        </div>
    <?php elseif ($userRole === 'station_admin' && !empty($userStations)): ?>
        <div class="current-station-info">
            Searching across your assigned stations (<?= count($userStations) ?>)
        </div>
    <?php endif; ?>


    <!-- Search Form -->
    <div class="search-form">
        <h2>Search for Items</h2>
        <!-- Form action will reload the admin page with this component and search query -->
        <form method="GET" action="admin.php">
            <input type="hidden" name="ajax" value="1">
            <input type="hidden" name="page" value="find.php">
            <input type="text" name="searchQuery" class="search-input" placeholder="Enter item name or description..." value="<?= htmlspecialchars($searchstr ?? '') ?>" required>
            <button type="submit" class="search-button">Search</button>
        </form>
    </div>

    <!-- Results -->
    <?php if ($searchstr): ?>
        <div class="results-table-container">
            <table>
                <thead>
                    <tr>
                        <th>Truck Name</th>
                        <th>Locker Name</th>
                        <th>Item Name</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($report_data) > 0): ?>
                        <?php foreach ($report_data as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars($item['truck_name']) ?></td>
                                <td><?= htmlspecialchars($item['locker_name']) ?></td>
                                <td><?= htmlspecialchars($item['item_name']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" class="no-results">
                                No items found matching "<?= htmlspecialchars($searchstr) ?>"
                                <?php if ($currentStation): ?>
                                    in <?= htmlspecialchars($currentStation['name']) ?>.
                                <?php elseif ($userRole === 'station_admin' && !empty($userStations)): ?>
                                    across your assigned stations.
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if (count($report_data) > 0): ?>
            <div style="margin-top: 20px; text-align: center; color: #666;">
                Found <?= count($report_data) ?> item(s) matching "<?= htmlspecialchars($searchstr) ?>"
                <?php if ($currentStation): ?>
                    in <?= htmlspecialchars($currentStation['name']) ?>.
                <?php elseif ($userRole === 'station_admin' && !empty($userStations)): ?>
                    across your assigned stations.
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    
    <!-- Removed Back to Main button, navigation is via admin sidebar -->
</div>
