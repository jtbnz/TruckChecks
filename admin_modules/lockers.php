<?php
// admin_modules/lockers.php

// Ensure session is started if not already
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Determine the correct base path for includes, assuming this file is in admin_modules/
$basePath = __DIR__ . '/../';

// Include necessary core files
require_once $basePath . 'config.php'; // Defines DEBUG, etc.
require_once $basePath . 'db.php';     // Defines get_db_connection()
require_once $basePath . 'auth.php';   // Defines requireAuth(), getCurrentUser(), getCurrentStation()

// Initialize database connection
$pdo = get_db_connection();
$db = $pdo; // Keep $db alias if used later in this file

// For AJAX calls made directly to this script, $user might not be globally available yet.
// For HTML rendering when included by admin.php, $user should be set.
// We ensure authentication and user context here for self-sufficiency.
// Note: admin.php already calls requireAuth() and sets $user before including this module for HTML page loads.
// This check is primarily for direct AJAX calls to this script.
if (!isset($user) || !$user) { // If $user is not set or is falsy
    requireAuth(); // Ensure user is authenticated for this module
    $user = getCurrentUser();
}

$userRole = $user['role'] ?? null;
$userName = $user['username'] ?? null;

// $DEBUG is defined in config.php and might be used in catch blocks.
// It's typically made global if needed within functions, but top-level access is direct.

// Station determination logic
$current_station_id = null;
$current_station_name = "No station selected";

if ($userRole === 'superuser') {
    // For superuser, getCurrentStation() from auth.php gets the station from session
    $stationData = getCurrentStation(); // getCurrentStation() uses $pdo internally
    if ($stationData && isset($stationData['id'])) {
        $current_station_id = $stationData['id'];
        $current_station_name = $stationData['name'];
    }
} elseif ($userRole === 'station_admin') {
    $userStationsForModule = []; // Local variable
    try {
        // Ensure $user['id'] is available
        if (isset($user['id'])) {
            $stmt_ua = $pdo->prepare("SELECT s.id, s.name FROM stations s JOIN user_stations us ON s.id = us.station_id WHERE us.user_id = ? ORDER BY s.name");
            $stmt_ua->execute([$user['id']]);
            $userStationsForModule = $stmt_ua->fetchAll(PDO::FETCH_ASSOC);
        } else {
            error_log("User ID not available for station_admin in lockers.php");
        }
    } catch (PDOException $e) {
        error_log("Error fetching user stations in lockers.php: " . $e->getMessage());
    }

    if (count($userStationsForModule) === 1) {
        $current_station_id = $userStationsForModule[0]['id'];
        $current_station_name = $userStationsForModule[0]['name'];
        if (session_status() == PHP_SESSION_ACTIVE && (!isset($_SESSION['selected_station_id']) || $_SESSION['selected_station_id'] != $current_station_id) ) {
            $_SESSION['selected_station_id'] = $current_station_id;
        }
    } elseif (isset($_SESSION['selected_station_id'])) {
        $is_valid_selection = false;
        foreach ($userStationsForModule as $s) {
            if ($s['id'] == $_SESSION['selected_station_id']) {
                $current_station_id = $s['id'];
                $current_station_name = $s['name'];
                $is_valid_selection = true;
                break;
            }
        }
        if (!$is_valid_selection) {
            if (session_status() == PHP_SESSION_ACTIVE) unset($_SESSION['selected_station_id']);
            $current_station_id = null;
            $current_station_name = "No valid station selected";
        }
    } else {
         $current_station_id = null;
         $current_station_name = count($userStationsForModule) > 0 ? "Please select a station" : "No stations assigned";
    }
}
// For other roles, $current_station_id will remain null.

$error_message = '';
$success_message = '';

// Handle AJAX actions (POST for CUD operations)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $response = ['success' => false, 'message' => 'Invalid action or station not selected.'];

    if (!$current_station_id) { // Most POST actions require a station context
        echo json_encode($response);
        exit;
    }

    if ($action === 'add_truck') {
        $truck_name = trim($_POST['truck_name'] ?? '');
        if (!empty($truck_name) && $current_station_id) {
            try {
                // Check if a truck with this name already exists for the current station
                $stmt_check = $pdo->prepare("SELECT id FROM trucks WHERE name = ? AND station_id = ?");
                $stmt_check->execute([$truck_name, $current_station_id]);
                if ($stmt_check->fetch()) {
                    $response = ['success' => false, 'message' => 'A truck with this name already exists for this station.'];
                } else {
                    // Insert truck with station_id
                    $stmt = $pdo->prepare("INSERT INTO trucks (name, station_id) VALUES (?, ?)");
                    $stmt->execute([$truck_name, $current_station_id]);
                    $truck_id = $pdo->lastInsertId();
                    $response = ['success' => true, 'message' => 'Truck added successfully.', 'truck_id' => $truck_id, 'truck_name' => $truck_name];
                }
            } catch (PDOException $e) {
                error_log("Error adding truck: " . $e->getMessage());
                $response['message'] = 'Database error adding truck. ' . (isset($DEBUG) && $DEBUG ? $e->getMessage() : '');
            }
        } else if (empty($truck_name)) {
            $response['message'] = 'Truck name cannot be empty.';
        } else {
            $response['message'] = 'Station not selected or invalid truck name.';
        }
    } elseif ($action === 'add_locker') {
        $locker_name = trim($_POST['locker_name'] ?? '');
        $truck_id = $_POST['truck_id_for_locker'] ?? '';

        if (!empty($locker_name) && !empty($truck_id) && $current_station_id) {
            try {
                // Verify the truck exists and belongs to the current station
                $stmt_truck_check = $pdo->prepare("SELECT id FROM trucks WHERE id = ? AND station_id = ?");
                $stmt_truck_check->execute([$truck_id, $current_station_id]);
                if (!$stmt_truck_check->fetch()) {
                    $response = ['success' => false, 'message' => 'Selected truck does not exist or does not belong to this station.'];
                } else {
                    // Check if locker with the same name already exists for this truck
                    $stmt_check = $pdo->prepare("SELECT id FROM lockers WHERE name = ? AND truck_id = ?");
                    $stmt_check->execute([$locker_name, $truck_id]);
                    if ($stmt_check->fetch()) {
                        $response = ['success' => false, 'message' => 'A locker with this name already exists for this truck.'];
                    } else {
                        // Insert locker
                        $stmt = $pdo->prepare("INSERT INTO lockers (name, truck_id) VALUES (?, ?)");
                        $stmt->execute([$locker_name, $truck_id]);
                        $locker_id = $pdo->lastInsertId();
                        $response = ['success' => true, 'message' => 'Locker added successfully.', 'locker_id' => $locker_id, 'locker_name' => $locker_name, 'truck_id' => $truck_id];
                    }
                }
            } catch (PDOException $e) {
                error_log("Error adding locker: " . $e->getMessage());
                $response['message'] = 'Database error adding locker. ' . (isset($DEBUG) && $DEBUG ? $e->getMessage() : '');
            }
        } else if (empty($locker_name)) {
            $response['message'] = 'Locker name cannot be empty.';
        } else if (empty($truck_id)) {
            $response['message'] = 'Please select a truck to assign the locker to.';
        } else {
            $response['message'] = 'Station not selected or invalid locker/truck details.';
        }
    } elseif ($action === 'add_item') {
        $item_name = trim($_POST['item_name'] ?? '');
        $locker_id = $_POST['locker_id_for_item'] ?? '';

        if (!empty($item_name) && !empty($locker_id) && $current_station_id) {
            try {
                // Since trucks table doesn't have station_id, we can't verify station ownership
                // We'll just verify the locker exists
                $stmt_locker_station_check = $pdo->prepare("SELECT id FROM lockers WHERE id = ?");
                $stmt_locker_station_check->execute([$locker_id]);

                if (!$stmt_locker_station_check->fetch()) {
                    $response = ['success' => false, 'message' => 'Selected locker does not exist.'];
                } else {
                    // Check if item with the same name already exists in this locker
                    $stmt_check_item_exists = $pdo->prepare("SELECT id FROM items WHERE name = ? AND locker_id = ?");
                    $stmt_check_item_exists->execute([$item_name, $locker_id]);
                    if ($stmt_check_item_exists->fetch()) {
                        $response = ['success' => false, 'message' => 'An item with this name already exists in this locker.'];
                    } else {
                        // items table only has id, name, and locker_id columns based on setup.sql
                        $stmt_insert_item = $pdo->prepare("INSERT INTO items (name, locker_id) VALUES (?, ?)");
                        $stmt_insert_item->execute([$item_name, $locker_id]);
                        $item_id = $pdo->lastInsertId();
                        $response = ['success' => true, 'message' => 'Item added successfully.', 'item_id' => $item_id, 'item_name' => $item_name, 'locker_id' => $locker_id];
                    }
                }
            } catch (PDOException $e) {
                error_log("Error adding item: " . $e->getMessage());
                $response['message'] = 'Database error adding item. ' . (isset($DEBUG) && $DEBUG ? $e->getMessage() : '');
            }
        } else if (empty($item_name)) {
            $response['message'] = 'Item name cannot be empty.';
        } else if (empty($locker_id)) {
            $response['message'] = 'Please select a locker to assign the item to.';
        } else {
            $response['message'] = 'Station not selected or invalid item/locker details.';
        }
    } elseif ($action === 'edit_item') {
        $item_id = $_POST['item_id'] ?? null;
        $new_item_name = trim($_POST['item_name'] ?? '');
        $new_locker_id = $_POST['locker_id'] ?? null;

        if (empty($item_id) || empty($new_item_name) || empty($new_locker_id)) {
            $response = ['success' => false, 'message' => 'Item ID, name, and locker are required.'];
        } elseif (!$current_station_id) {
            $response = ['success' => false, 'message' => 'Current station context is missing. Cannot edit item.'];
        } else {
            try {
                // Since trucks table doesn't have station_id, we can't verify station ownership
                // We'll just verify the locker exists
                $stmt_locker_check = $pdo->prepare("SELECT id FROM lockers WHERE id = ?");
                $stmt_locker_check->execute([$new_locker_id]);
                if (!$stmt_locker_check->fetch()) {
                    $response = ['success' => false, 'message' => 'The selected new locker does not exist.'];
                } else {
                    // Check if an item with the new name already exists in the new locker (excluding the current item being edited)
                    $stmt_item_exists = $pdo->prepare("SELECT id FROM items WHERE name = ? AND locker_id = ? AND id != ?");
                    $stmt_item_exists->execute([$new_item_name, $new_locker_id, $item_id]);
                    if ($stmt_item_exists->fetch()) {
                        $response = ['success' => false, 'message' => 'Another item with this name already exists in the selected locker.'];
                    } else {
                        $stmt_update = $pdo->prepare("UPDATE items SET name = ?, locker_id = ? WHERE id = ?");
                        $stmt_update->execute([$new_item_name, $new_locker_id, $item_id]);
                        
                        if ($stmt_update->rowCount() > 0) {
                            $response = ['success' => true, 'message' => 'Item updated successfully.'];
                        } else {
                            $stmt_check_item = $pdo->prepare("SELECT id FROM items WHERE id = ?");
                            $stmt_check_item->execute([$item_id]);
                            if ($stmt_check_item->fetch()) {
                                $response = ['success' => true, 'message' => 'Item details remain unchanged. No update performed.'];
                            } else {
                                $response = ['success' => false, 'message' => 'Item not updated. It might not exist.'];
                            }
                        }
                    }
                }
            } catch (PDOException $e) {
                error_log("Error editing item: " . $e->getMessage());
                $response = ['success' => false, 'message' => 'Database error editing item: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'delete_item') {
        $item_id = $_POST['item_id'] ?? null;
        
        if (empty($item_id)) {
            $response = ['success' => false, 'message' => 'Item ID is required.'];
        } elseif (!$current_station_id) {
            $response = ['success' => false, 'message' => 'Current station context is missing. Cannot delete item.'];
        } else {
            try {
                // Get item details for logging before deletion
                // Since trucks table doesn't have station_id, we can't verify station ownership
                $stmt_get_item = $pdo->prepare("
                    SELECT i.name as item_name, l.name as locker_name, t.name as truck_name
                    FROM items i
                    JOIN lockers l ON i.locker_id = l.id
                    JOIN trucks t ON l.truck_id = t.id
                    WHERE i.id = ?
                ");
                $stmt_get_item->execute([$item_id]);
                $item_details = $stmt_get_item->fetch(PDO::FETCH_ASSOC);
                
                if (!$item_details) {
                    $response = ['success' => false, 'message' => 'Item does not exist.'];
                } else {
                    $stmt_delete = $pdo->prepare("DELETE FROM items WHERE id = ?");
                    $stmt_delete->execute([$item_id]);
                    
                    if ($stmt_delete->rowCount() > 0) {
                        // Log the deletion
                        $stmt_log = $pdo->prepare("
                            INSERT INTO locker_item_deletion_log (truck_name, locker_name, item_name, deleted_at) 
                            VALUES (?, ?, ?, NOW())
                        ");
                        $stmt_log->execute([
                            $item_details['truck_name'],
                            $item_details['locker_name'],
                            $item_details['item_name']
                        ]);
                        
                        $response = ['success' => true, 'message' => 'Item deleted successfully.'];
                    } else {
                        $response = ['success' => false, 'message' => 'Item could not be deleted.'];
                    }
                }
            } catch (PDOException $e) {
                error_log("Error deleting item: " . $e->getMessage());
                $response = ['success' => false, 'message' => 'Database error deleting item: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'edit_locker') {
        $locker_id = $_POST['locker_id'] ?? null;
        $new_locker_name = trim($_POST['locker_name'] ?? '');
        $new_truck_id = $_POST['truck_id'] ?? null;

        if (empty($locker_id) || empty($new_locker_name) || empty($new_truck_id)) {
            $response = ['success' => false, 'message' => 'Locker ID, name, and truck are required.'];
        } elseif (!$current_station_id) {
            $response = ['success' => false, 'message' => 'Current station context is missing. Cannot edit locker.'];
        } else {
            try {
                // Since trucks table doesn't have station_id, we can't verify station ownership
                // We'll just verify the truck exists
                $stmt_truck_check = $pdo->prepare("SELECT id FROM trucks WHERE id = ?");
                $stmt_truck_check->execute([$new_truck_id]);
                if (!$stmt_truck_check->fetch()) {
                    $response = ['success' => false, 'message' => 'The selected truck does not exist.'];
                } else {
                    // Check if a locker with the new name already exists for the new truck (excluding current locker)
                    $stmt_locker_exists = $pdo->prepare("SELECT id FROM lockers WHERE name = ? AND truck_id = ? AND id != ?");
                    $stmt_locker_exists->execute([$new_locker_name, $new_truck_id, $locker_id]);
                    if ($stmt_locker_exists->fetch()) {
                        $response = ['success' => false, 'message' => 'Another locker with this name already exists for the selected truck.'];
                    } else {
                        $stmt_update = $pdo->prepare("UPDATE lockers SET name = ?, truck_id = ? WHERE id = ?");
                        $stmt_update->execute([$new_locker_name, $new_truck_id, $locker_id]);
                        
                        if ($stmt_update->rowCount() > 0) {
                            $response = ['success' => true, 'message' => 'Locker updated successfully.'];
                        } else {
                            $stmt_check_locker = $pdo->prepare("SELECT id FROM lockers WHERE id = ?");
                            $stmt_check_locker->execute([$locker_id]);
                            if ($stmt_check_locker->fetch()) {
                                $response = ['success' => true, 'message' => 'Locker details remain unchanged. No update performed.'];
                            } else {
                                $response = ['success' => false, 'message' => 'Locker not updated. It might not exist.'];
                            }
                        }
                    }
                }
            } catch (PDOException $e) {
                error_log("Error editing locker: " . $e->getMessage());
                $response = ['success' => false, 'message' => 'Database error editing locker: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'delete_locker') {
        $locker_id = $_POST['locker_id'] ?? null;
        
        if (empty($locker_id)) {
            $response = ['success' => false, 'message' => 'Locker ID is required.'];
        } elseif (!$current_station_id) {
            $response = ['success' => false, 'message' => 'Current station context is missing. Cannot delete locker.'];
        } else {
            try {
                // Get locker details for logging before deletion
                // Since trucks table doesn't have station_id, we can't verify station ownership
                $stmt_get_locker = $pdo->prepare("
                    SELECT l.name as locker_name, t.name as truck_name
                    FROM lockers l
                    JOIN trucks t ON l.truck_id = t.id
                    WHERE l.id = ?
                ");
                $stmt_get_locker->execute([$locker_id]);
                $locker_details = $stmt_get_locker->fetch(PDO::FETCH_ASSOC);
                
                if (!$locker_details) {
                    $response = ['success' => false, 'message' => 'Locker does not exist.'];
                } else {
                    // Check if locker has any items
                    $stmt_check_items = $pdo->prepare("SELECT COUNT(*) as item_count FROM items WHERE locker_id = ?");
                    $stmt_check_items->execute([$locker_id]);
                    $item_count = $stmt_check_items->fetch(PDO::FETCH_ASSOC)['item_count'];
                    
                    if ($item_count > 0) {
                        $response = ['success' => false, 'message' => "Cannot delete locker. It contains {$item_count} item(s). Please remove all items first."];
                    } else {
                        $stmt_delete = $pdo->prepare("DELETE FROM lockers WHERE id = ?");
                        $stmt_delete->execute([$locker_id]);
                        
                        if ($stmt_delete->rowCount() > 0) {
                            // Log the locker deletion
                            $stmt_log = $pdo->prepare("
                                INSERT INTO locker_item_deletion_log (truck_name, locker_name, item_name, deleted_at) 
                                VALUES (?, ?, ?, NOW())
                            ");
                            $stmt_log->execute([
                                $locker_details['truck_name'],
                                $locker_details['locker_name'],
                                '[LOCKER DELETED]'
                            ]);
                            
                            $response = ['success' => true, 'message' => 'Locker deleted successfully.'];
                        } else {
                            $response = ['success' => false, 'message' => 'Locker could not be deleted.'];
                        }
                    }
                }
            } catch (PDOException $e) {
                error_log("Error deleting locker: " . $e->getMessage());
                $response = ['success' => false, 'message' => 'Database error deleting locker: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'edit_truck') {
        $truck_id = $_POST['truck_id'] ?? null;
        $new_truck_name = trim($_POST['truck_name'] ?? '');

        if (empty($truck_id) || empty($new_truck_name)) {
            $response = ['success' => false, 'message' => 'Truck ID and name are required.'];
        } elseif (!$current_station_id) {
            $response = ['success' => false, 'message' => 'Current station context is missing. Cannot edit truck.'];
        } else {
            try {
                // Since trucks table doesn't have station_id, we can't verify station ownership
                // We'll just verify the truck exists
                $stmt_verify = $pdo->prepare("SELECT id FROM trucks WHERE id = ?");
                $stmt_verify->execute([$truck_id]);
                if (!$stmt_verify->fetch()) {
                    $response = ['success' => false, 'message' => 'Truck does not exist.'];
                } else {
                    // Since trucks table doesn't have station_id, we can't check for duplicates per station
                    // We'll check for global duplicates (excluding current truck)
                    $stmt_truck_exists = $pdo->prepare("SELECT id FROM trucks WHERE name = ? AND id != ?");
                    $stmt_truck_exists->execute([$new_truck_name, $truck_id]);
                    if ($stmt_truck_exists->fetch()) {
                        $response = ['success' => false, 'message' => 'Another truck with this name already exists.'];
                    } else {
                        $stmt_update = $pdo->prepare("UPDATE trucks SET name = ? WHERE id = ?");
                        $stmt_update->execute([$new_truck_name, $truck_id]);
                        
                        if ($stmt_update->rowCount() > 0) {
                            $response = ['success' => true, 'message' => 'Truck updated successfully.'];
                        } else {
                            $stmt_check_truck = $pdo->prepare("SELECT id FROM trucks WHERE id = ?");
                            $stmt_check_truck->execute([$truck_id]);
                            if ($stmt_check_truck->fetch()) {
                                $response = ['success' => true, 'message' => 'Truck details remain unchanged. No update performed.'];
                            } else {
                                $response = ['success' => false, 'message' => 'Truck not updated. It might not exist.'];
                            }
                        }
                    }
                }
            } catch (PDOException $e) {
                error_log("Error editing truck: " . $e->getMessage());
                $response = ['success' => false, 'message' => 'Database error editing truck: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'delete_truck') {
        $truck_id = $_POST['truck_id'] ?? null;
        
        if (empty($truck_id)) {
            $response = ['success' => false, 'message' => 'Truck ID is required.'];
        } elseif (!$current_station_id) {
            $response = ['success' => false, 'message' => 'Current station context is missing. Cannot delete truck.'];
        } else {
            try {
                // Get truck details for logging before deletion
                // Since trucks table doesn't have station_id, we can't verify station ownership
                $stmt_get_truck = $pdo->prepare("SELECT name as truck_name FROM trucks WHERE id = ?");
                $stmt_get_truck->execute([$truck_id]);
                $truck_details = $stmt_get_truck->fetch(PDO::FETCH_ASSOC);
                
                if (!$truck_details) {
                    $response = ['success' => false, 'message' => 'Truck does not exist.'];
                } else {
                    // Check if truck has any lockers
                    $stmt_check_lockers = $pdo->prepare("SELECT COUNT(*) as locker_count FROM lockers WHERE truck_id = ?");
                    $stmt_check_lockers->execute([$truck_id]);
                    $locker_count = $stmt_check_lockers->fetch(PDO::FETCH_ASSOC)['locker_count'];
                    
                    if ($locker_count > 0) {
                        $response = ['success' => false, 'message' => "Cannot delete truck. It contains {$locker_count} locker(s). Please remove all lockers first."];
                    } else {
                        $stmt_delete = $pdo->prepare("DELETE FROM trucks WHERE id = ?");
                        $stmt_delete->execute([$truck_id]);
                        
                        if ($stmt_delete->rowCount() > 0) {
                            // Log the truck deletion
                            $stmt_log = $pdo->prepare("
                                INSERT INTO locker_item_deletion_log (truck_name, locker_name, item_name, deleted_at) 
                                VALUES (?, ?, ?, NOW())
                            ");
                            $stmt_log->execute([
                                $truck_details['truck_name'],
                                '[TRUCK DELETED]',
                                '[TRUCK DELETED]'
                            ]);
                            
                            $response = ['success' => true, 'message' => 'Truck deleted successfully.'];
                        } else {
                            $response = ['success' => false, 'message' => 'Truck could not be deleted.'];
                        }
                    }
                }
            } catch (PDOException $e) {
                error_log("Error deleting truck: " . $e->getMessage());
                $response = ['success' => false, 'message' => 'Database error deleting truck: ' . $e->getMessage()];
            }
        }
    }

    echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Handle GET AJAX actions (e.g., fetching lists for dynamic updates)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_GET['ajax_action'];
    $response = ['success' => false, 'message' => 'Invalid action.', 'data' => []];

    if ($action === 'get_lockers_for_truck') {
        $truck_id_filter = $_GET['truck_id'] ?? null;
        if ($truck_id_filter) {
            try {
                // Since trucks table doesn't have station_id, we can't verify station ownership
                // We'll just get lockers for the truck
                $stmt = $pdo->prepare("
                    SELECT l.id, l.name 
                    FROM lockers l
                    WHERE l.truck_id = ? 
                    ORDER BY l.name
                ");
                $stmt->execute([$truck_id_filter]);
                $lockers_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $response = ['success' => true, 'data' => $lockers_data];
            } catch (PDOException $e) {
                error_log("Error fetching lockers for truck: " . $e->getMessage());
                $response['message'] = 'Database error fetching lockers. ' . (isset($DEBUG) && $DEBUG ? $e->getMessage() : '');
            }
        } else {
            $response['message'] = 'Truck ID missing for filtering lockers.';
        }
    } elseif ($action === 'get_filtered_items') {
        $truck_id_filter = $_GET['truck_id'] ?? null;
        $locker_id_filter = $_GET['locker_id'] ?? null;

        $sql_items = "
            SELECT li.id, li.name AS item_name, l.id AS locker_id, l.name AS locker_name, t.id AS truck_id, t.name AS truck_name
            FROM items li
            JOIN lockers l ON li.locker_id = l.id
            JOIN trucks t ON l.truck_id = t.id
            WHERE t.station_id = :station_id";

        $params = [':station_id' => $current_station_id];

        if (!empty($truck_id_filter)) {
            $sql_items .= " AND t.id = :truck_id";
            $params[':truck_id'] = $truck_id_filter;
        }
        if (!empty($locker_id_filter)) {
            $sql_items .= " AND l.id = :locker_id";
            $params[':locker_id'] = $locker_id_filter;
        }
        $sql_items .= " ORDER BY t.name, l.name, li.name";

        try {
            $stmt_items = $db->prepare($sql_items);
            $stmt_items->execute($params);
            $items_data = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
            $response = ['success' => true, 'data' => $items_data];
        } catch (PDOException $e) {
            error_log("Error fetching filtered items: " . $e->getMessage());
            $response = ['success' => false, 'message' => 'Database error fetching items. ' . (isset($DEBUG) && $DEBUG ? $e->getMessage() : ''), 'data' => []];
        }
    }
    
    echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

?>

<style>
    .lockers-page-container {
        padding: 20px;
        font-family: Arial, sans-serif;
    }
    .station-context-info {
        font-size: 1.1em;
        margin-bottom: 20px;
        padding: 10px;
        background-color: #e9ecef;
        border-left: 4px solid #12044C; /* Accent color */
        border-radius: 4px;
    }
    .notice {
        padding: 15px;
        margin-bottom: 20px;
        border: 1px solid transparent;
        border-radius: 4px;
    }
    .notice-warning {
        color: #856404;
        background-color: #fff3cd;
        border-color: #ffeeba;
    }
    .notice i { /* For Font Awesome icons if used */
        margin-right: 8px;
    }

    /* Dashboard-like grid and cards */
    .lockers-dashboard-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); /* Adjusted minmax for potentially wider cards */
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .lockers-dashboard-card {
        background: white;
        border-radius: 10px;
        padding: 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        border-left: 4px solid #12044C; /* Primary accent color */
        display: flex;
        flex-direction: column; /* Ensure cards can grow if content is large */
    }
    
    .lockers-dashboard-card h3 {
        margin: 0 0 15px 0;
        color: #12044C;
        font-size: 18px;
        border-bottom: 1px solid #eee;
        padding-bottom: 10px;
    }
    .lockers-dashboard-card h3 i { /* Emoji styling */
        margin-right: 8px;
        font-style: normal; /* Prevent italics for emojis */
    }
    
    .lockers-dashboard-card h4 {
        margin-top: 20px;
        margin-bottom: 10px;
        font-size: 16px;
        color: #333;
    }
    .lockers-dashboard-card p {
        color: #666;
        margin-bottom: 15px;
        line-height: 1.5;
    }

    .form-group {
        margin-bottom: 15px;
    }
    .form-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
        color: #555;
    }
    .form-group input[type="text"],
    .form-group select {
        width: 100%;
        padding: 10px;
        border: 1px solid #ccc;
        border-radius: 4px;
        box-sizing: border-box;
        font-size: 14px;
    }
    .button, button { /* General button styling */
        padding: 10px 15px;
        background-color: #12044C;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
        transition: background-color 0.3s;
    }
    .button:hover, button:hover {
        background-color: #0056b3; /* Darker shade on hover */
    }
    .button.secondary {
        background-color: #6c757d;
    }
    .button.secondary:hover {
        background-color: #545b62;
    }

    .entity-list {
        list-style-type: none;
        padding: 0;
        max-height: 300px; /* Add scroll for long lists */
        overflow-y: auto;
        border: 1px solid #eee;
        border-radius: 4px;
    }
    .entity-list li {
        padding: 10px;
        border-bottom: 1px solid #eee;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .entity-list li:last-child {
        border-bottom: none;
    }
    .entity-list .actions button {
        margin-left: 5px;
        padding: 5px 8px;
        font-size: 12px;
    }
    .scrollable-list-container { /* Wrapper for lists that might get long */
        max-height: 250px;
        overflow-y: auto;
        border: 1px solid #e0e0e0;
        border-radius: 4px;
        padding: 0; /* Reset padding if ul has its own */
        margin-top: 10px;
    }
    .scrollable-list-container .entity-list {
        max-height: none; /* Disable max-height on ul if parent has it */
        border: none;
    }

    /* Modal Styles */
    .modal {
        display: none; 
        position: fixed; 
        z-index: 1000; 
        left: 0;
        top: 0;
        width: 100%; 
        height: 100%; 
        overflow: auto; 
        background-color: rgba(0,0,0,0.4); 
    }
    .modal-content {
        background-color: #fefefe;
        margin: 10% auto; 
        padding: 20px;
        border: 1px solid #888;
        width: 80%; 
        max-width: 500px;
        border-radius: 8px;
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    }
    .modal-header {
        padding-bottom: 10px;
        border-bottom: 1px solid #eee;
        margin-bottom: 20px;
    }
    .modal-header h4 {
        margin: 0;
        font-size: 1.2em;
        color: #333;
    }
    .close-button {
        color: #aaa;
        float: right;
        font-size: 28px;
        font-weight: bold;
    }
    .close-button:hover,
    .close-button:focus {
        color: black;
        text-decoration: none;
        cursor: pointer;
    }
    .modal-footer {
        padding-top: 10px;
        border-top: 1px solid #eee;
        margin-top: 20px;
        text-align: right;
    }
    .modal-footer .button {
        margin-left: 10px;
    }

</style>

<!-- Edit Item Modal -->
<div id="edit-item-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span class="close-button" onclick="closeEditItemModal()">&times;</span>
            <h4>Edit Item</h4>
        </div>
        <form id="edit-item-form">
            <input type="hidden" id="edit-item-id" name="item_id">
            <div class="form-group">
                <label for="edit-item-name">Item Name:</label>
                <input type="text" id="edit-item-name" name="item_name" required>
            </div>
            <div class="form-group">
                <label for="edit-item-truck-id">Truck:</label>
                <select id="edit-item-truck-id" name="truck_id" required onchange="loadLockersForEditModal(this.value)">
                    <option value="">-- Select Truck --</option>
                    <?php
                    // Filter trucks by current station for edit modal
                    if ($current_station_id) {
                        try {
                            $stmt_trucks_modal = $db->prepare("SELECT id, name FROM trucks WHERE station_id = ? ORDER BY name");
                            $stmt_trucks_modal->execute([$current_station_id]);
                            $trucks_for_modal_list = $stmt_trucks_modal->fetchAll(PDO::FETCH_ASSOC);
                            if (count($trucks_for_modal_list) > 0) {
                                foreach ($trucks_for_modal_list as $truck_item_modal) {
                                    echo '<option value="' . htmlspecialchars($truck_item_modal['id'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($truck_item_modal['name'], ENT_QUOTES, 'UTF-8') . '</option>';
                                }
                            }
                        } catch (PDOException $e_modal_trucks) {
                            error_log("Error fetching trucks for edit modal dropdown: " . $e_modal_trucks->getMessage());
                        }
                    }
                    ?>
                </select>
            </div>
            <div class="form-group">
                <label for="edit-item-locker-id">Locker:</label>
                <select id="edit-item-locker-id" name="locker_id" required>
                    <option value="">-- Select Truck First --</option>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="button secondary" onclick="closeEditItemModal()">Cancel</button>
                <button type="button" class="button" id="save-edit-item-button">Save Changes</button>
                <button type="button" class="button" style="background-color: #dc3545;" onclick="deleteItem()">Delete Item</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Locker Modal -->
<div id="edit-locker-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span class="close-button" onclick="closeEditLockerModal()">&times;</span>
            <h4>Edit Locker</h4>
        </div>
        <form id="edit-locker-form">
            <input type="hidden" id="edit-locker-id" name="locker_id">
            <div class="form-group">
                <label for="edit-locker-name">Locker Name:</label>
                <input type="text" id="edit-locker-name" name="locker_name" required>
            </div>
            <div class="form-group">
                <label for="edit-locker-truck-id">Truck:</label>
                <select id="edit-locker-truck-id" name="truck_id" required>
                    <option value="">-- Select Truck --</option>
                    <?php
                    if ($current_station_id) {
                        try {
                            $stmt_trucks_locker_modal = $db->prepare("SELECT id, name FROM trucks WHERE station_id = ? ORDER BY name");
                            $stmt_trucks_locker_modal->execute([$current_station_id]);
                            $trucks_for_locker_modal_list = $stmt_trucks_locker_modal->fetchAll(PDO::FETCH_ASSOC);
                            if (count($trucks_for_locker_modal_list) > 0) {
                                foreach ($trucks_for_locker_modal_list as $truck_locker_modal) {
                                    echo '<option value="' . htmlspecialchars($truck_locker_modal['id'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($truck_locker_modal['name'], ENT_QUOTES, 'UTF-8') . '</option>';
                                }
                            }
                        } catch (PDOException $e_modal_trucks_locker) {
                            error_log("Error fetching trucks for locker edit modal dropdown: " . $e_modal_trucks_locker->getMessage());
                        }
                    }
                    ?>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="button secondary" onclick="closeEditLockerModal()">Cancel</button>
                <button type="button" class="button" id="save-edit-locker-button">Save Changes</button>
                <button type="button" class="button" style="background-color: #dc3545;" onclick="deleteLocker()">Delete Locker</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Truck Modal -->
<div id="edit-truck-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span class="close-button" onclick="closeEditTruckModal()">&times;</span>
            <h4>Edit Truck</h4>
        </div>
        <form id="edit-truck-form">
            <input type="hidden" id="edit-truck-id" name="truck_id">
            <div class="form-group">
                <label for="edit-truck-name">Truck Name:</label>
                <input type="text" id="edit-truck-name" name="truck_name" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="button secondary" onclick="closeEditTruckModal()">Cancel</button>
                <button type="button" class="button" id="save-edit-truck-button">Save Changes</button>
                <button type="button" class="button" style="background-color: #dc3545;" onclick="deleteTruck()">Delete Truck</button>
            </div>
        </form>
    </div>
</div>


<div class="lockers-page-container">
    <h1>Lockers & Items Management</h1>

    <?php if ($current_station_id): ?>
        <p class="station-context-info">Managing for Station: <strong><?= htmlspecialchars($current_station_name, ENT_QUOTES, 'UTF-8') ?></strong></p>
        
        <?php
        // Populate $trucks_for_list early as it's used by multiple cards/dropdowns
        $trucks_for_list = []; 
        if ($current_station_id) {
            try {
                // Filter trucks by current station
                $stmt_trucks_list_data = $db->prepare("SELECT id, name FROM trucks WHERE station_id = ? ORDER BY name");
                $stmt_trucks_list_data->execute([$current_station_id]);
                $trucks_for_list = $stmt_trucks_list_data->fetchAll(PDO::FETCH_ASSOC); 
            } catch (PDOException $e_truck_fetch) {
                error_log("Error fetching trucks for lists in lockers.php: " . $e_truck_fetch->getMessage());
                // $trucks_for_list remains empty, subsequent checks for count($trucks_for_list) will handle this
            }
        }
        ?>

        <div class="lockers-dashboard-grid">
            
            <!-- Items Card -->
            <div class="lockers-dashboard-card" id="items-management-card">
                <h3><i>📦</i> Items</h3>
                <div id="add-item-form-container">
                    <h4>Add New Item</h4>
                    <form id="add-item-form">
                        <div class="form-group">
                            <label for="select-truck-for-item">Select Truck:</label>
                            <select id="select-truck-for-item" name="truck_id_for_item" required onchange="loadLockersForItemDropdown(this.value); validateAddItemForm();">
                                <option value="">-- Select Truck --</option>
                                <?php
                                if (count($trucks_for_list) > 0) {
                                    foreach ($trucks_for_list as $truck_item) {
                                        echo '<option value="' . htmlspecialchars($truck_item['id'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($truck_item['name'], ENT_QUOTES, 'UTF-8') . '</option>';
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="select-locker-for-item">Select Locker:</label>
                            <select id="select-locker-for-item" name="locker_id_for_item" required onchange="validateAddItemForm();">
                                <option value="">-- Select Truck First --</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="new-item-name">Item Name:</label>
                            <input type="text" id="new-item-name" name="item_name" required oninput="validateAddItemForm();">
                        </div>
                        <button type="submit" class="button" id="add-item-button" disabled>Add Item</button>
                    </form>
                </div>

                <div id="items-list-container">
                    <h4>Existing Items</h4>
                    <div class="form-group">
                        <label for="filter-items-by-truck">Filter by Truck:</label>
                        <select id="filter-items-by-truck" name="filter_truck_id_for_items" onchange="handleTruckFilterChange(this.value)">
                            <option value="">All Trucks</option>
                             <?php
                                if (count($trucks_for_list) > 0) {
                                    foreach ($trucks_for_list as $truck_item) {
                                        echo '<option value="' . htmlspecialchars($truck_item['id'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($truck_item['name'], ENT_QUOTES, 'UTF-8') . '</option>';
                                    }
                                }
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="filter-items-by-locker">Filter by Locker:</label>
                        <select id="filter-items-by-locker" name="filter_locker_id_for_items" onchange="loadItemsList(document.getElementById('filter-items-by-truck').value, this.value)">
                            <option value="">All Lockers</option>
                            <!-- Populated by JS -->
                        </select>
                    </div>
                    <div class="scrollable-list-container">
                        <ul id="items-list" class="entity-list">
                            <?php
                            if ($current_station_id) {
                                try {
                                    // Filter items by current station through truck relationship
                                    $stmt_items = $db->prepare("
                                        SELECT li.id, li.name AS item_name, l.id AS locker_id, l.name AS locker_name, t.id AS truck_id, t.name AS truck_name
                                        FROM items li
                                        JOIN lockers l ON li.locker_id = l.id
                                        JOIN trucks t ON l.truck_id = t.id
                                        WHERE t.station_id = ?
                                        ORDER BY t.name, l.name, li.name
                                    ");
                                    $stmt_items->execute([$current_station_id]);
                                    $items_list_data = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
                                    if (count($items_list_data) > 0) {
                                        foreach ($items_list_data as $item_entry) {
                                            echo '<li><span>' . htmlspecialchars($item_entry['item_name'], ENT_QUOTES, 'UTF-8') . 
                                                 ' (Locker: ' . htmlspecialchars($item_entry['locker_name'], ENT_QUOTES, 'UTF-8') . 
                                                 ', Truck: ' . htmlspecialchars($item_entry['truck_name'], ENT_QUOTES, 'UTF-8') . ')</span>'.
                                                 ' <span class="actions"><button class="button secondary" style="padding:3px 6px; font-size:10px;" onclick="openEditItemModal('.$item_entry['id'].', \''.htmlspecialchars(addslashes($item_entry['item_name']), ENT_QUOTES).'\', '.$item_entry['locker_id'].', '.$item_entry['truck_id'].')">Edit</button></span></li>';
                                        }
                                    } else {
                                        echo '<li>No items found.</li>';
                                    }
                                } catch (PDOException $e) {
                                    echo '<li>Error fetching items: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</li>';
                                }
                            } else {
                                 echo '<li>Select a station to view items.</li>';
                            }
                            ?>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Lockers Card -->
            <div class="lockers-dashboard-card" id="lockers-management-card">
                <h3><i>🗄️</i> Lockers</h3>
                 <div id="add-locker-form-container">
                    <h4>Add New Locker</h4>
                    <form id="add-locker-form">
                        <div class="form-group">
                            <label for="select-truck-for-locker">Assign to Truck:</label>
                            <select id="select-truck-for-locker" name="truck_id_for_locker" required>
                                <option value="">-- Select Truck --</option>
                                <?php
                                if (count($trucks_for_list) > 0) { 
                                    foreach ($trucks_for_list as $truck_item) {
                                        echo '<option value="' . htmlspecialchars($truck_item['id'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($truck_item['name'], ENT_QUOTES, 'UTF-8') . '</option>';
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="new-locker-name">Locker Name:</label>
                            <input type="text" id="new-locker-name" name="locker_name" required>
                        </div>
                        <button type="submit" class="button">Add Locker</button>
                    </form>
                </div>
                <div id="lockers-list-container">
                    <h4>Existing Lockers</h4>
                    <div class="scrollable-list-container">
                        <ul id="lockers-list" class="entity-list">
                            <?php
                            if ($current_station_id) {
                                try {
                                    // Filter lockers by current station through truck relationship
                                    $stmt_lockers = $db->prepare("
                                        SELECT l.id, l.name AS locker_name, t.name AS truck_name 
                                        FROM lockers l
                                        JOIN trucks t ON l.truck_id = t.id
                                        WHERE t.station_id = ?
                                        ORDER BY t.name, l.name
                                    ");
                                    $stmt_lockers->execute([$current_station_id]);
                                    $lockers_list_data = $stmt_lockers->fetchAll(PDO::FETCH_ASSOC);
                                    if (count($lockers_list_data) > 0) {
                                        foreach ($lockers_list_data as $locker_item) {
                                            echo '<li><span>' . htmlspecialchars($locker_item['locker_name'], ENT_QUOTES, 'UTF-8') . ' (Truck: ' . htmlspecialchars($locker_item['truck_name'], ENT_QUOTES, 'UTF-8') . ')</span>'.
                                                 ' <span class="actions"><button class="button secondary" style="padding:3px 6px; font-size:10px;" onclick="openEditLockerModal('.$locker_item['id'].', \''.htmlspecialchars(addslashes($locker_item['locker_name']), ENT_QUOTES).'\', \''.htmlspecialchars(addslashes($locker_item['truck_name']), ENT_QUOTES).'\')">Edit</button></span></li>';
                                        }
                                    } else {
                                        echo '<li>No lockers found.</li>';
                                    }
                                } catch (PDOException $e) {
                                    echo '<li>Error fetching lockers: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</li>';
                                }
                            } else {
                                echo '<li>Select a station to view lockers.</li>';
                            }
                            ?>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Trucks Card -->
            <div class="lockers-dashboard-card" id="trucks-management-card">
                <h3><i>🚛</i> Trucks</h3>
                <div id="add-truck-form-container">
                    <h4>Add New Truck</h4>
                    <form id="add-truck-form">
                        <input type="hidden" name="station_id" value="<?= htmlspecialchars($current_station_id, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="form-group">
                            <label for="new-truck-name">Truck Name:</label>
                            <input type="text" id="new-truck-name" name="truck_name" required>
                        </div>
                        <button type="submit" class="button">Add Truck</button>
                    </form>
                </div>
                <div id="trucks-list-container">
                    <h4>Existing Trucks</h4>
                    <div class="scrollable-list-container">
                        <ul id="trucks-list" class="entity-list">
                            <?php
                            if ($current_station_id) {
                                if (count($trucks_for_list) > 0) {
                                    foreach ($trucks_for_list as $truck_item) {
                                        echo '<li><span>' . htmlspecialchars($truck_item['name'], ENT_QUOTES, 'UTF-8') . '</span>'.
                                             ' <span class="actions"><button class="button secondary" style="padding:3px 6px; font-size:10px;" onclick="openEditTruckModal('.$truck_item['id'].', \''.htmlspecialchars(addslashes($truck_item['name']), ENT_QUOTES).'\')">Edit</button></span></li>';
                                    }
                                } else {
                                    echo '<li>No trucks found.</li>';
                                }
                            } else {
                                 echo '<li>Select a station to view trucks.</li>';
                            }
                            ?>
                        </ul>
                    </div>
                </div>
            </div>

        </div>

    <?php else: ?>
        <div class="notice notice-warning">
            <p><i>⚠️</i> Please select a station to manage its trucks, lockers, and items.</p>
            <?php if ($userRole === 'superuser'): ?>
                <p>As a superuser, you can select a station using the dropdown in the sidebar header.</p>
            <?php elseif ($userRole === 'station_admin' && (!isset($userStationsForModule) || count($userStationsForModule) !== 1)): ?>
                 <p>As a station admin, please ensure a single station is active or selected. If you manage multiple stations, pick one. If you manage none, please contact a superuser.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function handleAjaxResponse(response, operationName = 'Operation') {
    if (response.success) {
        alert(response.message || `${operationName} successful.`);
        console.log('Success:', response.message || `${operationName} successful.`);
        return true; // Indicate success
    } else {
        alert(`Operation Notice: ${response.message || `An issue occurred with ${operationName}.`}`);
        console.error('Operation Notice/Error:', response.message || `An issue occurred with ${operationName}.`);
        return false; // Indicate failure/issue
    }
}

function handleAddTruck(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    formData.append('ajax_action', 'add_truck'); 

    const truckName = formData.get('truck_name').trim();
    if (!truckName) {
        alert('Truck name cannot be empty.');
        return;
    }
    
    fetch('admin_modules/lockers.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (handleAjaxResponse(data, 'Truck addition')) {
            form.reset();
            if (typeof loadPage === 'function') {
                loadPage('admin_modules/lockers.php');
            } else {
                window.location.reload();
            }
        }
    })
    .catch(error => {
        console.error('Network error:', error);
        alert('Network error. Could not add truck.');
    });
}

function handleAddLocker(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    formData.append('ajax_action', 'add_locker');

    const lockerName = formData.get('locker_name').trim();
    const truckId = formData.get('truck_id_for_locker');

    if (!lockerName || !truckId) {
        alert('Truck and Locker name are required.');
        return;
    }

    fetch('admin_modules/lockers.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (handleAjaxResponse(data, 'Locker addition')) {
            form.reset();
            if (typeof loadPage === 'function') {
                loadPage('admin_modules/lockers.php');
            } else {
                window.location.reload();
            }
        }
    })
    .catch(error => {
        console.error('Network error:', error);
        alert('Network error. Could not add locker.');
    });
}

function handleAddItem(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    formData.append('ajax_action', 'add_item');

    const itemName = formData.get('item_name').trim();
    const lockerId = formData.get('locker_id_for_item');

    if (!itemName || !lockerId) {
        alert('Locker and Item name are required.');
        return;
    }
    fetch('admin_modules/lockers.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (handleAjaxResponse(data, 'Item addition')) {
            form.reset();
            document.getElementById('select-locker-for-item').innerHTML = '<option value="">-- Select Truck First --</option>'; 
            if (typeof loadPage === 'function') {
                loadPage('admin_modules/lockers.php');
            } else {
                window.location.reload();
            }
        }
    })
    .catch(error => {
        console.error('Network error:', error);
        alert('Network error. Could not add item.');
    });
}

function loadLockersForItemDropdown(truckId) {
    const lockerSelect = document.getElementById('select-locker-for-item');
    lockerSelect.innerHTML = '<option value="">Loading lockers...</option>';

    if (!truckId) {
        lockerSelect.innerHTML = '<option value="">-- Select Truck First --</option>';
        return;
    }

    fetch(`admin_modules/lockers.php?ajax_action=get_lockers_for_truck&truck_id=${truckId}`)
    .then(response => response.json())
    .then(data => {
        if (data.success && data.data) {
            lockerSelect.innerHTML = '<option value="">-- Select Locker --</option>';
            if (data.data.length > 0) {
                data.data.forEach(locker => {
                    const option = document.createElement('option');
                    option.value = locker.id;
                    option.textContent = locker.name;
                    lockerSelect.appendChild(option);
                });
            } else {
                lockerSelect.innerHTML = '<option value="">No lockers found for this truck</option>';
            }
        } else {
            lockerSelect.innerHTML = '<option value="">Error loading lockers</option>';
            console.error('Error fetching lockers:', data.message || 'No specific message from server.');
        }
    })
    .catch(error => {
        lockerSelect.innerHTML = '<option value="">Error loading lockers</option>';
        console.error('Network error fetching lockers:', error);
    });
}

function loadItemsList(truckIdFilter = '', lockerIdFilter = '') {
    console.log('loadItemsList called with Truck ID:', truckIdFilter, 'Locker ID:', lockerIdFilter);
    const itemsListUl = document.getElementById('items-list');
    itemsListUl.innerHTML = '<li>Loading items...</li>';

    let fetchUrl = `admin_modules/lockers.php?ajax_action=get_filtered_items`;
    if (truckIdFilter) {
        fetchUrl += `&truck_id=${encodeURIComponent(truckIdFilter)}`;
    }
    if (lockerIdFilter) {
        fetchUrl += `&locker_id=${encodeURIComponent(lockerIdFilter)}`;
    }

    fetch(fetchUrl)
    .then(response => response.json())
    .then(data => {
        itemsListUl.innerHTML = ''; // Clear previous items
        if (data.success && data.data) {
            if (data.data.length > 0) {
                data.data.forEach(item => {
                    const li = document.createElement('li');
                    const editButton = `<button class="button secondary" style="padding:3px 6px; font-size:10px;" onclick="openEditItemModal(${item.id}, '${escapeHTML(item.item_name)}', ${item.locker_id || 'null'}, ${item.truck_id || 'null'})">Edit</button>`;
                    li.innerHTML = `<span>${escapeHTML(item.item_name)} (Locker: ${escapeHTML(item.locker_name)}, Truck: ${escapeHTML(item.truck_name)})</span> <span class="actions">${editButton}</span>`;
                    itemsListUl.appendChild(li);
                });
            } else {
                itemsListUl.innerHTML = '<li>No items found matching your criteria.</li>';
            }
        } else {
            itemsListUl.innerHTML = `<li>Error loading items: ${escapeHTML(data.message || 'Unknown error')}</li>`;
            console.error('Error fetching items:', data.message);
        }
    })
    .catch(error => {
        itemsListUl.innerHTML = '<li>Network error loading items.</li>';
        console.error('Network error fetching items:', error);
    });
}

function handleTruckFilterChange(truckId) {
    loadLockersForFilterDropdown(truckId);
    loadItemsList(truckId, ''); 
}

function loadLockersForFilterDropdown(truckId) {
    const lockerFilterSelect = document.getElementById('filter-items-by-locker');
    lockerFilterSelect.innerHTML = '<option value="">Loading lockers...</option>';

    if (!truckId) {
        lockerFilterSelect.innerHTML = '<option value="">All Lockers</option>'; 
        return;
    }

    fetch(`admin_modules/lockers.php?ajax_action=get_lockers_for_truck&truck_id=${encodeURIComponent(truckId)}`)
    .then(response => response.json())
    .then(data => {
        if (data.success && data.data) {
            lockerFilterSelect.innerHTML = '<option value="">All Lockers</option>'; 
            if (data.data.length > 0) {
                data.data.forEach(locker => {
                    const option = document.createElement('option');
                    option.value = locker.id;
                    option.textContent = locker.name;
                    lockerFilterSelect.appendChild(option);
                });
            } else {
                 lockerFilterSelect.innerHTML = '<option value="">No lockers for this truck</option>';
            }
        } else {
            lockerFilterSelect.innerHTML = '<option value="">Error loading lockers</option>';
            console.error('Error fetching lockers for filter:', data.message || 'No specific message');
        }
    })
    .catch(error => {
        lockerFilterSelect.innerHTML = '<option value="">Error loading lockers</option>';
        console.error('Network error fetching lockers for filter:', error);
    });
}

function escapeHTML(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, function (match) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        }[match];
    });
}

// Use window object to avoid redeclaration errors
if (typeof window.currentEditItemId === 'undefined') {
    window.currentEditItemId = null;
}

function openEditItemModal(itemId, itemName, currentLockerId, currentTruckId) {
    currentEditItemId = itemId;
    document.getElementById('edit-item-id').value = itemId;
    document.getElementById('edit-item-name').value = itemName;

    const truckSelect = document.getElementById('edit-item-truck-id');
    truckSelect.value = currentTruckId || ''; 
    
    loadLockersForEditModal(currentTruckId, function() {
        document.getElementById('edit-item-locker-id').value = currentLockerId || '';
    });

    document.getElementById('edit-item-modal').style.display = 'block';
}

function closeEditItemModal() {
    document.getElementById('edit-item-modal').style.display = 'none';
    document.getElementById('edit-item-form').reset();
    currentEditItemId = null;
}

function loadLockersForEditModal(truckId, callback) {
    const lockerSelect = document.getElementById('edit-item-locker-id');
    lockerSelect.innerHTML = '<option value="">Loading lockers...</option>';

    if (!truckId) {
        lockerSelect.innerHTML = '<option value="">-- Select Truck First --</option>';
        if (callback) callback();
        return;
    }

    fetch(`admin_modules/lockers.php?ajax_action=get_lockers_for_truck&truck_id=${encodeURIComponent(truckId)}`)
    .then(response => response.json())
    .then(data => {
        if (data.success && data.data) {
            lockerSelect.innerHTML = '<option value="">-- Select Locker --</option>';
            if (data.data.length > 0) {
                data.data.forEach(locker => {
                    const option = document.createElement('option');
                    option.value = locker.id;
                    option.textContent = locker.name;
                    lockerSelect.appendChild(option);
                });
            } else {
                lockerSelect.innerHTML = '<option value="">No lockers for this truck</option>';
            }
        } else {
            lockerSelect.innerHTML = '<option value="">Error loading lockers</option>';
            console.error('Error fetching lockers for edit modal:', data.message || 'No specific message');
        }
        if (callback) callback(); 
    })
    .catch(error => {
        lockerSelect.innerHTML = '<option value="">Error loading lockers</option>';
        console.error('Network error fetching lockers for edit modal:', error);
        if (callback) callback();
    });
}

function handleEditItemSubmit() { 
    const form = document.getElementById('edit-item-form');
    const formData = new FormData(form);
    formData.append('ajax_action', 'edit_item');

    const itemName = formData.get('item_name') ? formData.get('item_name').trim() : '';
    const lockerId = formData.get('locker_id');
    const itemId = formData.get('item_id');

    if (!itemId || !itemName || !lockerId) {
        alert('Item Name and Locker selection are required to save changes.');
        return;
    }

    fetch('admin_modules/lockers.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (handleAjaxResponse(data, 'Item update')) {
            closeEditItemModal();
            const currentTruckFilter = document.getElementById('filter-items-by-truck').value;
            const currentLockerFilter = document.getElementById('filter-items-by-locker').value;
            loadItemsList(currentTruckFilter, currentLockerFilter);
        }
    })
    .catch(error => {
        console.error('Network error updating item:', error);
        alert('Network error. Could not update item.');
    });
}

// Use window object to avoid redeclaration errors
if (typeof window.currentEditLockerId === 'undefined') {
    window.currentEditLockerId = null;
}

function openEditLockerModal(lockerId, lockerName, truckName) {
    currentEditLockerId = lockerId;
    document.getElementById('edit-locker-id').value = lockerId;
    document.getElementById('edit-locker-name').value = lockerName;
    
    const truckSelect = document.getElementById('edit-locker-truck-id');
    for (let option of truckSelect.options) {
        if (option.text === truckName) {
            option.selected = true;
            break;
        }
    }
    
    document.getElementById('edit-locker-modal').style.display = 'block';
}

function closeEditLockerModal() {
    document.getElementById('edit-locker-modal').style.display = 'none';
    document.getElementById('edit-locker-form').reset();
    currentEditLockerId = null;
}

function handleEditLockerSubmit() {
    const form = document.getElementById('edit-locker-form');
    const formData = new FormData(form);
    formData.append('ajax_action', 'edit_locker');

    const lockerName = formData.get('locker_name') ? formData.get('locker_name').trim() : '';
    const truckId = formData.get('truck_id');
    const lockerId = formData.get('locker_id');

    if (!lockerId || !lockerName || !truckId) {
        alert('Locker Name and Truck selection are required to save changes.');
        return;
    }

    fetch('admin_modules/lockers.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (handleAjaxResponse(data, 'Locker update')) {
            closeEditLockerModal();
            if (typeof loadPage === 'function') {
                loadPage('admin_modules/lockers.php');
            } else {
                window.location.reload();
            }
        }
    })
    .catch(error => {
        console.error('Network error updating locker:', error);
        alert('Network error. Could not update locker.');
    });
}

function deleteLocker() {
    if (!currentEditLockerId) {
        alert('No locker selected for deletion.');
        return;
    }
    
    if (!confirm('Are you sure you want to delete this locker? This action cannot be undone.')) {
        return;
    }

    const formData = new FormData();
    formData.append('ajax_action', 'delete_locker');
    formData.append('locker_id', currentEditLockerId);

    fetch('admin_modules/lockers.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (handleAjaxResponse(data, 'Locker deletion')) {
            closeEditLockerModal();
            if (typeof loadPage === 'function') {
                loadPage('admin_modules/lockers.php');
            } else {
                window.location.reload();
            }
        }
    })
    .catch(error => {
        console.error('Network error deleting locker:', error);
        alert('Network error. Could not delete locker.');
    });
}

// Use window object to avoid redeclaration errors
if (typeof window.currentEditTruckId === 'undefined') {
    window.currentEditTruckId = null;
}

function openEditTruckModal(truckId, truckName) {
    currentEditTruckId = truckId;
    document.getElementById('edit-truck-id').value = truckId;
    document.getElementById('edit-truck-name').value = truckName;
    
    document.getElementById('edit-truck-modal').style.display = 'block';
}

function closeEditTruckModal() {
    document.getElementById('edit-truck-modal').style.display = 'none';
    document.getElementById('edit-truck-form').reset();
    currentEditTruckId = null;
}

function handleEditTruckSubmit() {
    const form = document.getElementById('edit-truck-form');
    const formData = new FormData(form);
    formData.append('ajax_action', 'edit_truck');

    const truckName = formData.get('truck_name') ? formData.get('truck_name').trim() : '';
    const truckId = formData.get('truck_id');

    if (!truckId || !truckName) {
        alert('Truck Name is required to save changes.');
        return;
    }

    fetch('admin_modules/lockers.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (handleAjaxResponse(data, 'Truck update')) {
            closeEditTruckModal();
            if (typeof loadPage === 'function') {
                loadPage('admin_modules/lockers.php');
            } else {
                window.location.reload();
            }
        }
    })
    .catch(error => {
        console.error('Network error updating truck:', error);
        alert('Network error. Could not update truck.');
    });
}

function deleteTruck() {
    if (!currentEditTruckId) {
        alert('No truck selected for deletion.');
        return;
    }
    
    if (!confirm('Are you sure you want to delete this truck? This action cannot be undone.')) {
        return;
    }

    const formData = new FormData();
    formData.append('ajax_action', 'delete_truck');
    formData.append('truck_id', currentEditTruckId);

    fetch('admin_modules/lockers.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (handleAjaxResponse(data, 'Truck deletion')) {
            closeEditTruckModal();
            if (typeof loadPage === 'function') {
                loadPage('admin_modules/lockers.php');
            } else {
                window.location.reload();
            }
        }
    })
    .catch(error => {
        console.error('Network error deleting truck:', error);
        alert('Network error. Could not delete truck.');
    });
}

function deleteItem() {
    if (!currentEditItemId) {
        alert('No item selected for deletion.');
        return;
    }
    
    if (!confirm('Are you sure you want to delete this item? This action cannot be undone.')) {
        return;
    }

    const formData = new FormData();
    formData.append('ajax_action', 'delete_item');
    formData.append('item_id', currentEditItemId);

    fetch('admin_modules/lockers.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (handleAjaxResponse(data, 'Item deletion')) {
            closeEditItemModal();
            const currentTruckFilter = document.getElementById('filter-items-by-truck').value;
            const currentLockerFilter = document.getElementById('filter-items-by-locker').value;
            loadItemsList(currentTruckFilter, currentLockerFilter);
        }
    })
    .catch(error => {
        console.error('Network error deleting item:', error);
        alert('Network error. Could not delete item.');
    });
}

function validateAddItemForm() {
    const truckSelect = document.getElementById('select-truck-for-item');
    const lockerSelect = document.getElementById('select-locker-for-item');
    const itemNameInput = document.getElementById('new-item-name');
    const addItemButton = document.getElementById('add-item-button');
    
    const truckSelected = truckSelect && truckSelect.value !== '';
    const lockerSelected = lockerSelect && lockerSelect.value !== '';
    const itemNameFilled = itemNameInput && itemNameInput.value.trim() !== '';
    
    const allFieldsValid = truckSelected && lockerSelected && itemNameFilled;
    
    if (addItemButton) {
        addItemButton.disabled = !allFieldsValid;
        if (allFieldsValid) {
            addItemButton.style.opacity = '1';
            addItemButton.style.cursor = 'pointer';
        } else {
            addItemButton.style.opacity = '0.6';
            addItemButton.style.cursor = 'not-allowed';
        }
    }
}

function initializeLockersModule() {
    console.log('Lockers module initialized via JS.');
    const addTruckForm = document.getElementById('add-truck-form');
    if (addTruckForm) {
        addTruckForm.onsubmit = handleAddTruck; 
    }
    const addLockerForm = document.getElementById('add-locker-form');
    if (addLockerForm) {
        addLockerForm.onsubmit = handleAddLocker;
    }
    const addItemForm = document.getElementById('add-item-form');
    if (addItemForm) {
        addItemForm.onsubmit = handleAddItem;
    }

    const saveEditItemButton = document.getElementById('save-edit-item-button');
    if (saveEditItemButton) {
        saveEditItemButton.onclick = handleEditItemSubmit;
    }
    
    const saveEditLockerButton = document.getElementById('save-edit-locker-button');
    if (saveEditLockerButton) {
        saveEditLockerButton.onclick = handleEditLockerSubmit;
    }
    
    const saveEditTruckButton = document.getElementById('save-edit-truck-button');
    if (saveEditTruckButton) {
        saveEditTruckButton.onclick = handleEditTruckSubmit;
    }
    
    // Initialize form validation on page load
    validateAddItemForm();
    
    window.onclick = function(event) {
        const itemModal = document.getElementById('edit-item-modal');
        const lockerModal = document.getElementById('edit-locker-modal');
        const truckModal = document.getElementById('edit-truck-modal');
        
        if (event.target == itemModal) {
            closeEditItemModal();
        } else if (event.target == lockerModal) {
            closeEditLockerModal();
        } else if (event.target == truckModal) {
            closeEditTruckModal();
        }
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeLockersModule);
} else {
    initializeLockersModule();
}

</script>
