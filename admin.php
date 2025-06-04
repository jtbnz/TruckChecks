<?php
ob_start(); // Start output buffering at the very beginning

// Suppress all errors immediately to prevent any debug output from included files
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

include('config.php'); // config.php might define DEBUG
include('db.php');
include('auth.php');

// If DEBUG is explicitly enabled in config.php, re-enable error reporting for non-AJAX requests
if (defined('DEBUG') && DEBUG && (!isset($_GET['ajax']) || $_GET['ajax'] !== '1') && (!isset($_POST['ajax_action']) && !isset($_POST['action']))) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

// Handle AJAX station change request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_station') {
    ob_clean(); // Discard any buffered output to prevent JSON corruption
    header('Content-Type: application/json');
    
    try {
        requireAuth();
        $user = getCurrentUser();
        
        if ($user['role'] !== 'superuser') {
            throw new Exception('Only superusers can change stations');
        }
        
        $stationId = $_POST['station_id'] ?? '';
        if (empty($stationId)) {
            throw new Exception('Station ID is required');
        }
        
        if (setCurrentStation($stationId)) {
            ob_end_clean(); // Ensure no other output
            echo json_encode(['success' => true]);
        } else {
            throw new Exception('Failed to set station');
        }
        
    } catch (Exception $e) {
        ob_end_clean(); // Ensure no other output
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle AJAX GET requests for JSON data (e.g., for dropdowns, filters)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] !== '1') { // Exclude ajax=1 which is for HTML content
    // Suppress all errors for AJAX responses to prevent JSON corruption
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);

    requireAuth();
    
    // Setup context for the included page
    $pdo = get_db_connection();
    $user = getCurrentUser();
    $userRole = $user['role'];
    $userName = $user['username'];

    if ($userRole === 'superuser') {
        $station = getCurrentStation();
    } elseif ($userRole === 'station_admin') {
        try {
            $stmt_stations = $pdo->prepare("SELECT s.* FROM stations s JOIN user_stations us ON s.id = us.station_id WHERE us.user_id = ? ORDER BY s.name");
            $stmt_stations->execute([$user['id']]);
            $userStations = $stmt_stations->fetchAll();
            if (count($userStations) === 1) {
                $station = $userStations[0];
            } else {
                $station = null;
            }
        } catch (Exception $e) {
            error_log('Error getting user stations in AJAX: ' . $e->getMessage());
            $station = null;
        }
    } else {
        $station = null;
    }

    $ajax_action = $_GET['ajax'];
    
    // Route to specific modules for JSON output
    if ($ajax_action === 'get_lockers' || $ajax_action === 'get_items') {
        include('admin_modules/maintain_locker_items.php');
        // The included file is expected to handle JSON output and exit
    } else {
        // If it's an unknown AJAX GET request, return an error
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid AJAX GET action']);
        exit;
    }
    exit; // Ensure no further output after handling AJAX GET
}

// Handle AJAX content loading request
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    // Suppress all errors for AJAX responses to prevent JSON corruption
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);

    requireAuth();
    $page = $_GET['page'] ?? '';

    // Setup context for the included page
    $pdo = get_db_connection();
    $user = getCurrentUser();
    $userRole = $user['role'];
    $userName = $user['username'];

    $currentStation = null; 
    $userStations = []; 

    if ($userRole === 'superuser') {
        $station = getCurrentStation(); // Assign to global $station
    } elseif ($userRole === 'station_admin') {
        try {
            $stmt_stations = $pdo->prepare("SELECT s.* FROM stations s JOIN user_stations us ON s.id = us.station_id WHERE us.user_id = ? ORDER BY s.name");
            $stmt_stations->execute([$user['id']]);
            $userStations = $stmt_stations->fetchAll();
            if (count($userStations) === 1) {
                $station = $userStations[0]; // Assign to global $station
            } else {
                // If station admin has multiple stations, and no specific station is selected,
                // or if the current station is not valid for them, default to null or redirect.
                // For now, we'll ensure $station is null if not explicitly set to a single station.
                $station = null; 
            }
        } catch (Exception $e) {
            error_log('Error getting user stations in AJAX: ' . $e->getMessage());
            $station = null;
        }
    } else {
        $station = null; // Ensure $station is null for other roles or unhandled cases
    }
    
    // Security: Only allow specific pages
    $allowedPages = [
        'admin_modules/maintain_trucks.php',
        'admin_modules/maintain_lockers.php', 
        'admin_modules/maintain_locker_items.php',
        'find.php',
        'reset_locker_check.php',
        'qr-codes.php',
        'email_admin.php',
        'email_results.php',
        'locker_check_report.php',
        'list_all_items_report.php',
        'list_all_items_report_a3.php',
        'deleted_items_report.php',
        'backups.php',
        'login_logs.php',
        'admin_modules/manage_stations.php',
        'manage_users.php',
        'show_code.php',
        'station_settings.php',
        'demo_clean_tables.php'
    ];
    
    // Check if we should load from admin_modules directory
    $modulePath = 'admin_modules/' . $page;
    $legacyPath = $page;
    
    if (in_array($page, $allowedPages)) {
        // Capture the output
        ob_start();
        
        // Try module path first, then legacy path
        if (file_exists($modulePath)) {
            include($modulePath);
        } elseif (file_exists($legacyPath)) {
            include($legacyPath);
        } else {
            echo '<div style="padding: 20px; text-align: center; color: #666;">Page not found.</div>';
        }
        
        $content = ob_get_clean();
        
        // If the content includes full HTML structure, extract just the body content
        if (strpos($content, '<!DOCTYPE') !== false) {
            // Extract content between <body> and </body> tags
            if (preg_match('/<body[^>]*>(.*?)<\/body>/s', $content, $matches)) {
                echo $matches[1];
            } else {
                // Fallback: remove everything before <body> and after </body>
                $content = preg_replace('/.*?<body[^>]*>/s', '', $content);
                $content = preg_replace('/<\/body>.*$/s', '', $content);
                echo $content;
            }
        } else {
            // Content is already a fragment, output as-is
            echo $content;
        }
    } else {
        echo '<div style="padding: 20px; text-align: center; color: #666;">Page not found or access denied.</div>';
    }
    exit;
}

// Handle AJAX requests from modules
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    // Suppress all errors for AJAX responses to prevent JSON corruption
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);

    ob_clean(); // Discard any buffered output to prevent JSON corruption
    requireAuth();
    
    // Setup context for the module
    $pdo = get_db_connection();
    $user = getCurrentUser();
    $userRole = $user['role'];
    $userName = $user['username'];
    
    if ($userRole === 'superuser') {
        $station = getCurrentStation(); // Assign to global $station
    } elseif ($userRole === 'station_admin') {
        try {
            $stmt_stations = $pdo->prepare("SELECT s.* FROM stations s JOIN user_stations us ON s.id = us.station_id WHERE us.user_id = ? ORDER BY s.name");
            $stmt_stations->execute([$user['id']]);
            $userStations = $stmt_stations->fetchAll();
            if (count($userStations) === 1) {
                $station = $userStations[0]; // Assign to global $station
            } else {
                $station = null;
            }
        } catch (Exception $e) {
            error_log('Error getting user stations in module POST: ' . $e->getMessage());
            $station = null;
        }
    } else {
        $station = null;
    }
    
    // Determine which module to load based on the ajax_action
    $module = '';
    $action = $_POST['ajax_action'];
    
    if (in_array($action, ['add_truck', 'edit_truck', 'delete_truck'])) {
        $module = 'maintain_trucks.php';
    } elseif (in_array($action, ['add_locker', 'edit_locker', 'delete_locker'])) {
        $module = 'maintain_lockers.php';
    } elseif (in_array($action, ['add_item', 'edit_item', 'delete_item'])) {
        $module = 'maintain_locker_items.php';
    } elseif (in_array($action, ['add_station', 'edit_station', 'delete_station'])) {
        $module = 'manage_stations.php';
    }
    
    if ($module && file_exists('admin_modules/' . $module)) {
        include('admin_modules/' . $module);
    } else {
        ob_end_clean(); // Ensure no other output
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    exit;
}

// Handle AJAX requests to modules (legacy support)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['REQUEST_URI'], '/admin_modules/') !== false) {
    // Suppress all errors for AJAX responses to prevent JSON corruption
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);

    ob_clean(); // Discard any buffered output to prevent JSON corruption
    requireAuth();
    
    // Setup context for the module
    $pdo = get_db_connection();
    $user = getCurrentUser();
    $userRole = $user['role'];
    $userName = $user['username'];
    
    $currentStation = null;
    if ($userRole === 'superuser') {
        $currentStation = getCurrentStation();
    } elseif ($userRole === 'station_admin') {
        try {
            $stmt_stations = $pdo->prepare("SELECT s.* FROM stations s JOIN user_stations us ON s.id = us.station_id WHERE us.user_id = ? ORDER BY s.name");
            $stmt_stations->execute([$user['id']]);
            $userStations = $stmt_stations->fetchAll();
            if (count($userStations) === 1) {
                $currentStation = $userStations[0];
            }
        } catch (Exception $e) {
            error_log('Error getting user stations in module POST: ' . $e->getMessage());
        }
    }
    
    // Extract module name from URL
    preg_match('/admin_modules\/([^\/]+\.php)/', $_SERVER['REQUEST_URI'], $matches);
    if (isset($matches[1])) {
        $module = $matches[1];
        $allowedModules = [
            'maintain_trucks.php',
            'maintain_lockers.php',
            'maintain_locker_items.php',
            'manage_stations.php'
        ];
        
        if (in_array($module, $allowedModules) && file_exists('admin_modules/' . $module)) {
            include('admin_modules/' . $module);
        } else {
            ob_end_clean(); // Ensure no other output
            header('HTTP/1.1 404 Not Found');
            echo json_encode(['success' => false, 'message' => 'Module not found']);
        }
    }
    exit;
}

// Ensure user is authenticated
requireAuth();

// Get user information
$user = getCurrentUser();
$userRole = $user['role'];
$userName = $user['username'];

// Get user's stations if station admin
$userStations = [];
if ($userRole === 'station_admin') {
    // Use direct database query to avoid redirect loops
    try {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare("
            SELECT s.* 
            FROM stations s 
            JOIN user_stations us ON s.id = us.station_id 
            WHERE us.user_id = ? 
            ORDER BY s.name
        ");
        $stmt->execute([$user['id']]);
        $userStations = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log('Error getting user stations: ' . $e->getMessage());
        $userStations = [];
    }
}

$showButton = isset($_SESSION['IS_DEMO']) && $_SESSION['IS_DEMO'] === true;

// Get the latest Git tag version
$version = trim(exec('git describe --tags $(git rev-list --tags --max-count=1)'));
$_SESSION['version'] = $version;

// Get current page from URL parameter
$currentPage = $_GET['page'] ?? 'dashboard';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TruckChecks Admin - <?= ucfirst($userRole) ?></title>
    <link rel="stylesheet" href="styles/styles.css">
    <style>
        .admin-container {
            display: flex;
            min-height: 100vh;
            background-color: #f5f5f5;
        }
        
        .sidebar {
            width: 280px;
            background-color: #12044C;
            color: white;
            padding: 0;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 100; /* Ensure sidebar is above content */
        }
        
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            background-color: rgba(0,0,0,0.2);
            display: flex; /* For hamburger icon */
            justify-content: space-between; /* For hamburger icon */
            align-items: center; /* For hamburger icon */
        }
        
        .sidebar-header h2 {
            margin: 0 0 5px 0;
            font-size: 18px;
            color: white;
        }

        .hamburger {
            display: none; /* Hidden by default, shown on mobile */
            font-size: 24px;
            cursor: pointer;
            color: white;
            background: none;
            border: none;
            padding: 0;
        }
        
        .user-info {
            font-size: 14px;
            color: rgba(255,255,255,0.8);
        }
        
        .role-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            margin-top: 5px;
        }
        
        .role-superuser {
            background-color: #dc3545;
            color: white;
        }
        
        .role-station-admin {
            background-color: #28a745;
            color: white;
        }
        
        .sidebar-nav {
            padding: 0;
        }
        
        .nav-section {
            margin-bottom: 10px;
        }
        
        .nav-section-title {
            padding: 15px 20px 10px 20px;
            font-size: 12px;
            font-weight: bold;
            color: rgba(255,255,255,0.6);
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .nav-item {
            display: block;
            padding: 12px 20px;
            color: rgba(255,255,255,0.9);
            text-decoration: none;
            border-left: 3px solid transparent;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .nav-item:hover {
            background-color: rgba(255,255,255,0.1);
            color: white;
            border-left-color: #007bff;
        }
        
        .nav-item.active {
            background-color: rgba(255,255,255,0.15);
            color: white;
            border-left-color: #007bff;
        }
        
        .nav-item i {
            width: 20px;
            margin-right: 10px;
        }
        
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 0;
            min-height: 100vh;
            background-color: white;
        }
        
        .content-area {
            width: 100%;
            min-height: 100vh;
            background-color: white;
        }
        
        .loading {
            display: none;
            text-align: center;
            padding: 50px;
            color: #666;
        }
        
        .dashboard-content {
            padding: 30px;
            background-color: white;
            min-height: 100vh;
        }
        
        .dashboard-header {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #12044C;
        }
        
        .dashboard-header h1 {
            margin: 0;
            color: #12044C;
            font-size: 28px;
        }
        
        .dashboard-header .subtitle {
            color: #666;
            margin-top: 5px;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .dashboard-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #12044C;
        }
        
        .dashboard-card h3 {
            margin: 0 0 15px 0;
            color: #12044C;
            font-size: 18px;
        }
        
        .dashboard-card p {
            color: #666;
            margin-bottom: 15px;
            line-height: 1.5;
        }
        
        .card-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .card-button {
            display: inline-block;
            padding: 8px 16px;
            background-color: #12044C;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 14px;
            transition: background-color 0.3s;
            cursor: pointer;
            border: none;
        }
        
        .card-button:hover {
            background-color: #0056b3;
        }
        
        .card-button.secondary {
            background-color: #6c757d;
        }
        
        .card-button.secondary:hover {
            background-color: #545b62;
        }
        
        .demo-notice {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
            color: #856404;
        }
        
        .demo-notice h3 {
            margin: 0 0 10px 0;
            color: #856404;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 250px; /* Fixed width for mobile sidebar when open */
                position: fixed;
                height: 100vh;
                top: 0;
                left: -250px; /* Hidden by default */
                transition: left 0.3s ease;
                box-shadow: 2px 0 5px rgba(0,0,0,0.2);
            }

            .sidebar.sidebar-visible {
                left: 0; /* Slide in */
            }
            
            .main-content {
                margin-left: 0;
                width: 100%;
            }

            .hamburger {
                display: block; /* Show hamburger on mobile */
            }

            .sidebar-header {
                padding-right: 10px; /* Adjust padding for hamburger */
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>TruckChecks Admin</h2>
                <button class="hamburger" id="hamburger-menu">☰</button>
                <div class="user-info">
                    Welcome, <?= htmlspecialchars($userName) ?>
                    <div class="role-badge role-<?= str_replace('_', '-', $userRole) ?>">
                        <?= ucfirst(str_replace('_', ' ', $userRole)) ?>
                    </div>
                    <?php if ($userRole === 'superuser'): ?>
                        <?php 
                        $currentStation = getCurrentStation();
                        // Get all stations for superuser - use direct query to avoid redirect loops
                        try {
                            $pdo = get_db_connection();
                            $stmt = $pdo->prepare("SELECT * FROM stations ORDER BY name");
                            $stmt->execute();
                            $allStations = $stmt->fetchAll();
                        } catch (Exception $e) {
                            error_log('Error getting all stations: ' . $e->getMessage());
                            $allStations = [];
                        }
                        ?>
                        <div style="margin-top: 10px;">
                            <select id="station-selector" onchange="changeStation()" style="width: 100%; padding: 5px; border-radius: 3px; border: 1px solid rgba(255,255,255,0.3); background: rgba(255,255,255,0.1); color: white;">
                                <option value="">Select Station...</option>
                                <?php foreach ($allStations as $station): ?>
                                    <option value="<?= $station['id'] ?>" <?= ($currentStation && $currentStation['id'] == $station['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($station['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <nav class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Main</div>
                    <a href="javascript:void(0)" onclick="showDashboard()" class="nav-item <?= $currentPage === 'dashboard' ? 'active' : '' ?>" id="dashboard-link">
                        <i>🏠</i> Dashboard
                    </a>
                    <a href="index.php" class="nav-item" target="_blank">
                        <i>📊</i> Status Page
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Management</div>
                    <a href="javascript:void(0)" onclick="loadPage('admin_modules/maintain_trucks.php')" class="nav-item">
                        <i>🚛</i> Maintain Trucks
                    </a>
                    <a href="javascript:void(0)" onclick="loadPage('admin_modules/maintain_lockers.php')" class="nav-item">
                        <i>🗄️</i> Maintain Lockers
                    </a>
                    <a href="javascript:void(0)" onclick="loadPage('admin_modules/maintain_locker_items.php')" class="nav-item">
                        <i>📦</i> Maintain Locker Items
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Tools</div>
                    <a href="javascript:void(0)" onclick="loadPage('find.php')" class="nav-item">
                        <i>🔍</i> Find an Item
                    </a>
                    <a href="javascript:void(0)" onclick="loadPage('reset_locker_check.php')" class="nav-item">
                        <i>🔄</i> Reset Locker Checks
                    </a>
                    <a href="javascript:void(0)" onclick="loadPage('qr-codes.php')" class="nav-item">
                        <i>📱</i> Generate QR Codes
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Communication</div>
                    <a href="javascript:void(0)" onclick="loadPage('email_admin.php')" class="nav-item">
                        <i>📧</i> Manage Email Settings
                    </a>
                    <a href="javascript:void(0)" onclick="loadPage('email_results.php')" class="nav-item">
                        <i>📤</i> Email Check Results
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Reports & Data</div>
                    <a href="javascript:void(0)" onclick="loadPage('locker_check_report.php')" class="nav-item">
                        <i>📄</i> Locker Check Reports
                    </a>
                    <a href="javascript:void(0)" onclick="loadPage('list_all_items_report.php')" class="nav-item">
                        <i>📋</i> List All Items Report
                    </a>
                    <a href="javascript:void(0)" onclick="loadPage('list_all_items_report_a3.php')" class="nav-item">
                        <i>📄</i> A3 Locker Items Report
                    </a>
                    <a href="javascript:void(0)" onclick="loadPage('deleted_items_report.php')" class="nav-item">
                        <i>🗑️</i> Deleted Items Report
                    </a>
                    <a href="javascript:void(0)" onclick="loadPage('backups.php')" class="nav-item">
                        <i>💾</i> Download Backup
                    </a>
                    <a href="javascript:void(0)" onclick="loadPage('login_logs.php')" class="nav-item">
                        <i>📋</i> View Login Logs
                    </a>
                </div>
                
                <?php if ($userRole === 'superuser'): ?>
                <div class="nav-section">
                    <div class="nav-section-title">System Administration</div>
                    <a href="javascript:void(0)" onclick="loadPage('admin_modules/manage_stations.php')" class="nav-item">
                        <i>🏢</i> Manage Stations
                    </a>
                    <a href="manage_users.php" target="_blank" class="nav-item">
                        <i>👥</i> Manage Users
                    </a>
                    <a href="install.php" class="nav-item" target="_blank">
                        <i>⚙️</i> System Installer
                    </a>
                </div>
                <?php endif; ?>
                
                <?php if ($userRole === 'station_admin'): ?>
                <div class="nav-section">
                    <div class="nav-section-title">Station Administration</div>
                    <a href="javascript:void(0)" onclick="loadPage('manage_users.php')" class="nav-item">
                        <i>👥</i> Manage Station Users
                    </a>
                </div>
                <?php endif; ?>
                
                <div class="nav-section">
                    <div class="nav-section-title">Settings</div>
                    <a href="javascript:void(0)" onclick="loadPage('show_code.php')" class="nav-item">
                        <i>🔐</i> Security Code
                    </a>
                    <a href="javascript:void(0)" onclick="loadPage('station_settings.php')" class="nav-item">
                        <i>⚙️</i> Station Settings
                    </a>
                    <?php if ($showButton): ?>
                    <a href="javascript:void(0)" onclick="loadPage('demo_clean_tables.php')" class="nav-item">
                        <i>🧹</i> Delete Demo Data
                    </a>
                    <?php endif; ?>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Account</div>
                    <a href="logout.php" class="nav-item">
                        <i>🚪</i> Logout
                    </a>
                </div>
            </nav>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="loading" id="loading">
                <p>Loading...</p>
            </div>
            
            <div class="content-area" id="content-area">
                <?php if ($currentPage === 'dashboard'): ?>
                    <div class="dashboard-content">
                        <?php if (isset($_SESSION['IS_DEMO']) && $_SESSION['IS_DEMO'] === true): ?>
                            <div class="demo-notice">
                                <h3>Demo Mode Active</h3>
                                <p>Demo mode adds background stripes and the word DEMO in the middle of the screen. There is also the Delete Demo Checks Data button which will reset the checks but not the locker changes. This message is not visible when demo mode is not enabled.</p>
                            </div>
                        <?php endif; ?>
                        
                        <div class="dashboard-header">
                            <h1>TruckChecks Administration</h1>
                            <div class="subtitle">
                                Logged in as <?= htmlspecialchars($userName) ?> (<?= ucfirst(str_replace('_', ' ', $userRole)) ?>)
                                <?php if ($userRole === 'station_admin' && !empty($userStations)): ?>
                                    - Managing <?= count($userStations) ?> station(s)
                                <?php elseif ($userRole === 'superuser'): ?>
                                    <?php $currentStation = getCurrentStation(); ?>
                                    <?php if ($currentStation): ?>
                                        - Current Station: <?= htmlspecialchars($currentStation['name']) ?>
                                    <?php else: ?>
                                        - No station selected
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="dashboard-grid">
                            <div class="dashboard-card">
                                <h3>Fleet Management</h3>
                                <p>Manage your trucks, lockers, and items. Add new vehicles, organize storage compartments, and maintain inventory lists.</p>
                                <div class="card-buttons">
                                    <button onclick="loadPage('admin_modules/maintain_trucks.php')" class="card-button">Trucks</button>
                                    <button onclick="loadPage('admin_modules/maintain_lockers.php')" class="card-button">Lockers</button>
                                    <button onclick="loadPage('admin_modules/maintain_locker_items.php')" class="card-button">Items</button>
                                </div>
                            </div>
                            
                            <div class="dashboard-card">
                                <h3>Operations</h3>
                                <p>Daily operational tools for managing checks, finding items, and generating QR codes for easy access.</p>
                                <div class="card-buttons">
                                    <button onclick="loadPage('find.php')" class="card-button">Find Items</button>
                                    <button onclick="loadPage('reset_locker_check.php')" class="card-button secondary">Reset Checks</button>
                                    <button onclick="loadPage('qr-codes.php')" class="card-button">QR Codes</button>
                                </div>
                            </div>
                            
                            <div class="dashboard-card">
                                <h3>Reports & Analytics</h3>
                                <p>Generate comprehensive reports, view check history, and analyze fleet performance data.</p>
                                <div class="card-buttons">
                                    <button onclick="loadPage('locker_check_report.php')" class="card-button">Locker Reports</button>
                                    <button onclick="loadPage('deleted_items_report.php')" class="card-button">Deleted Items</button>
                                    <button onclick="loadPage('login_logs.php')" class="card-button secondary">Login Logs</button>
                                </div>
                            </div>
                            
                            <div class="dashboard-card">
                                <h3>Communication</h3>
                                <p>Configure email notifications and send check results to relevant personnel.</p>
                                <div class="card-buttons">
                                    <button onclick="loadPage('email_admin.php')" class="card-button">Email Settings</button>
                                    <button onclick="loadPage('email_results.php')" class="card-button">Send Results</button>
                                </div>
                            </div>
                            
                            <?php if ($userRole === 'superuser'): ?>
                            <div class="dashboard-card">
                                <h3>System Administration</h3>
                                <p>Manage stations, users, and system-wide settings. Access installation and upgrade tools.</p>
                                <div class="card-buttons">
                                    <button onclick="loadPage('admin_modules/manage_stations.php')" class="card-button">Stations</button>
                                    <a href="manage_users.php" target="_blank" class="card-button">Users</a>
                                    <a href="install.php" class="card-button secondary" target="_blank">Installer</a>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($userRole === 'station_admin'): ?>
                            <div class="dashboard-card">
                                <h3>Station Administration</h3>
                                <p>Manage users for your assigned stations and configure station-specific settings.</p>
                                <div class="card-buttons">
                                    <a href="manage_users.php" target="_blank" class="card-button">Station Users</a>
                                    <button onclick="loadPage('station_settings.php')" class="card-button">Settings</button>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="dashboard-card">
                                <h3>Data Management</h3>
                                <p>Backup your data, manage security settings, and configure system preferences.</p>
                                <div class="card-buttons">
                                    <button onclick="loadPage('backups.php')" class="card-button">Backup</button>
                                    <button onclick="loadPage('show_code.php')" class="card-button secondary">Security</button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        // Load page content via AJAX
        function loadPage(page, navElement = null) {
            // Show loading indicator
            document.getElementById('loading').style.display = 'block';
            document.getElementById('content-area').style.display = 'none';
            
            // Remove active class from all nav items
            document.querySelectorAll('.nav-item').forEach(item => {
                item.classList.remove('active');
            });
            
            // Add active class to clicked item or find by page name
            if (navElement) {
                navElement.classList.add('active');
            } else {
                // Find the nav item based on the page name
                const navItems = document.querySelectorAll('.sidebar-nav .nav-item');
                navItems.forEach(item => {
                    const onclickAttr = item.getAttribute('onclick');
                    if (onclickAttr && onclickAttr.includes(`loadPage('${page}')`)) {
                        item.classList.add('active');
                    }
                });
            }
            
            // Close sidebar on mobile after navigation
            if (window.innerWidth <= 768) {
                document.getElementById('sidebar').classList.remove('sidebar-visible');
            }

            // Load content via AJAX
            fetch(`admin.php?ajax=1&page=${encodeURIComponent(page)}`)
                .then(response => response.text())
                .then(html => {
                    const contentArea = document.getElementById('content-area');
                    contentArea.innerHTML = html;
                    
                    // Execute any scripts that were loaded
                    const scripts = contentArea.getElementsByTagName('script');
                    for (let i = 0; i < scripts.length; i++) {
                        const script = scripts[i];
                        const newScript = document.createElement('script');
                        
                        // Copy attributes
                        for (let j = 0; j < script.attributes.length; j++) {
                            const attr = script.attributes[j];
                            newScript.setAttribute(attr.name, attr.value);
                        }
                        
                        // Copy content
                        newScript.textContent = script.textContent;
                        
                        // Replace old script with new one to execute it
                        script.parentNode.replaceChild(newScript, script);
                    }
                    
                    document.getElementById('loading').style.display = 'none';
                    document.getElementById('content-area').style.display = 'block';
                    
                    // Update URL without page reload
                    const url = new URL(window.location);
                    url.searchParams.set('page', page); // Keep full filename for history
                    window.history.pushState({}, '', url);
                })
                .catch(error => {
                    console.error('Error loading page:', error);
                    document.getElementById('content-area').innerHTML = '<div style="padding: 20px; text-align: center; color: #dc3545;">Error loading page. Please try again.</div>';
                    document.getElementById('loading').style.display = 'none';
                    document.getElementById('content-area').style.display = 'block';
                });
        }
        
        // Modify existing onclicks to pass 'this' (the element itself)
        document.querySelectorAll('.nav-item').forEach(item => {
            const onclickAttr = item.getAttribute('onclick');
            if (onclickAttr && onclickAttr.includes('loadPage(') && !onclickAttr.includes(', this)')) {
                item.setAttribute('onclick', onclickAttr.replace(')', ', this)'));
            }
        });
        
        // Show dashboard
        function showDashboard() {
            // Remove active class from all nav items
            document.querySelectorAll('.nav-item').forEach(item => {
                item.classList.remove('active');
            });
            
            // Add active class to dashboard
            document.getElementById('dashboard-link').classList.add('active');
            
            // Close sidebar on mobile after navigation
            if (window.innerWidth <= 768) {
                document.getElementById('sidebar').classList.remove('sidebar-visible');
            }

            // Redirect to dashboard
            window.location.href = '?page=dashboard';
        }
        
        // Handle station change for superusers
        function changeStation() {
            const stationId = document.getElementById('station-selector').value;
            if (stationId) {
                // Send AJAX request to change station
                fetch('admin.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=change_station&station_id=' + encodeURIComponent(stationId)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Reload the page to reflect the station change
                        window.location.reload();
                    } else {
                        alert('Failed to change station: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to change station');
                });
            }
        }

        // Mobile sidebar toggle
        const hamburgerMenu = document.getElementById('hamburger-menu');
        const sidebar = document.getElementById('sidebar');

        if (hamburgerMenu && sidebar) {
            hamburgerMenu.addEventListener('click', () => {
                sidebar.classList.toggle('sidebar-visible');
            });
        }
    </script>
