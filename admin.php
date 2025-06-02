<?php
include('config.php');
if (DEBUG) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    echo "Debug mode is on";
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
}

include('db.php');
include('auth.php');

// Handle AJAX station change request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_station') {
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
            echo json_encode(['success' => true]);
        } else {
            throw new Exception('Failed to set station');
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
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
        }
        
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            background-color: rgba(0,0,0,0.2);
        }
        
        .sidebar-header h2 {
            margin: 0 0 5px 0;
            font-size: 18px;
            color: white;
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
        }
        
        .content-frame {
            width: 100%;
            height: 100vh;
            border: none;
            background-color: white;
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
                width: 100%;
                position: relative;
                height: auto;
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>TruckChecks Admin</h2>
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
                    <a href="?page=dashboard" class="nav-item <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                        <i>🏠</i> Dashboard
                    </a>
                    <a href="index.php" class="nav-item" target="content-frame">
                        <i>📊</i> Status Page
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Management</div>
                    <a href="maintain_trucks.php" class="nav-item" target="content-frame">
                        <i>🚛</i> Maintain Trucks
                    </a>
                    <a href="maintain_lockers.php" class="nav-item" target="content-frame">
                        <i>🗄️</i> Maintain Lockers
                    </a>
                    <a href="maintain_locker_items.php" class="nav-item" target="content-frame">
                        <i>📦</i> Maintain Locker Items
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Tools</div>
                    <a href="find.php" class="nav-item" target="content-frame">
                        <i>🔍</i> Find an Item
                    </a>
                    <a href="reset_locker_check.php" class="nav-item" target="content-frame">
                        <i>🔄</i> Reset Locker Checks
                    </a>
                    <a href="qr-codes.php" class="nav-item" target="content-frame">
                        <i>📱</i> Generate QR Codes
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Communication</div>
                    <a href="email_admin.php" class="nav-item" target="content-frame">
                        <i>📧</i> Manage Email Settings
                    </a>
                    <a href="email_results.php" class="nav-item" target="content-frame">
                        <i>📤</i> Email Check Results
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Reports & Data</div>
                    <a href="reports.php" class="nav-item" target="content-frame">
                        <i>📊</i> Reports
                    </a>
                    <a href="deleted_items_report.php" class="nav-item" target="content-frame">
                        <i>🗑️</i> Deleted Items Report
                    </a>
                    <a href="backups.php" class="nav-item" target="content-frame">
                        <i>💾</i> Download Backup
                    </a>
                    <a href="login_logs.php" class="nav-item" target="content-frame">
                        <i>📋</i> View Login Logs
                    </a>
                </div>
                
                <?php if ($userRole === 'superuser'): ?>
                <div class="nav-section">
                    <div class="nav-section-title">System Administration</div>
                    <a href="manage_stations.php" class="nav-item" target="content-frame">
                        <i>🏢</i> Manage Stations
                    </a>
                    <a href="manage_users.php" class="nav-item" target="content-frame">
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
                    <a href="manage_users.php" class="nav-item" target="content-frame">
                        <i>👥</i> Manage Station Users
                    </a>
                </div>
                <?php endif; ?>
                
                <div class="nav-section">
                    <div class="nav-section-title">Settings</div>
                    <a href="show_code.php" class="nav-item" target="content-frame">
                        <i>🔐</i> Security Code
                    </a>
                    <a href="station_settings.php" class="nav-item" target="content-frame">
                        <i>⚙️</i> Station Settings
                    </a>
                    <?php if ($showButton): ?>
                    <a href="demo_clean_tables.php" class="nav-item" target="content-frame">
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
                                <a href="maintain_trucks.php" class="card-button" target="content-frame">Trucks</a>
                                <a href="maintain_lockers.php" class="card-button" target="content-frame">Lockers</a>
                                <a href="maintain_locker_items.php" class="card-button" target="content-frame">Items</a>
                            </div>
                        </div>
                        
                        <div class="dashboard-card">
                            <h3>Operations</h3>
                            <p>Daily operational tools for managing checks, finding items, and generating QR codes for easy access.</p>
                            <div class="card-buttons">
                                <a href="find.php" class="card-button" target="content-frame">Find Items</a>
                                <a href="reset_locker_check.php" class="card-button secondary" target="content-frame">Reset Checks</a>
                                <a href="qr-codes.php" class="card-button" target="content-frame">QR Codes</a>
                            </div>
                        </div>
                        
                        <div class="dashboard-card">
                            <h3>Reports & Analytics</h3>
                            <p>Generate comprehensive reports, view check history, and analyze fleet performance data.</p>
                            <div class="card-buttons">
                                <a href="reports.php" class="card-button" target="content-frame">View Reports</a>
                                <a href="deleted_items_report.php" class="card-button" target="content-frame">Deleted Items</a>
                                <a href="login_logs.php" class="card-button secondary" target="content-frame">Login Logs</a>
                            </div>
                        </div>
                        
                        <div class="dashboard-card">
                            <h3>Communication</h3>
                            <p>Configure email notifications and send check results to relevant personnel.</p>
                            <div class="card-buttons">
                                <a href="email_admin.php" class="card-button" target="content-frame">Email Settings</a>
                                <a href="email_results.php" class="card-button" target="content-frame">Send Results</a>
                            </div>
                        </div>
                        
                        <?php if ($userRole === 'superuser'): ?>
                        <div class="dashboard-card">
                            <h3>System Administration</h3>
                            <p>Manage stations, users, and system-wide settings. Access installation and upgrade tools.</p>
                            <div class="card-buttons">
                                <a href="manage_stations.php" class="card-button" target="content-frame">Stations</a>
                                <a href="manage_users.php" class="card-button" target="content-frame">Users</a>
                                <a href="install.php" class="card-button secondary" target="_blank">Installer</a>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($userRole === 'station_admin'): ?>
                        <div class="dashboard-card">
                            <h3>Station Administration</h3>
                            <p>Manage users for your assigned stations and configure station-specific settings.</p>
                            <div class="card-buttons">
                                <a href="manage_users.php" class="card-button" target="content-frame">Station Users</a>
                                <a href="station_settings.php" class="card-button" target="content-frame">Settings</a>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="dashboard-card">
                            <h3>Data Management</h3>
                            <p>Backup your data, manage security settings, and configure system preferences.</p>
                            <div class="card-buttons">
                                <a href="backups.php" class="card-button" target="content-frame">Backup</a>
                                <a href="show_code.php" class="card-button secondary" target="content-frame">Security</a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <iframe name="content-frame" class="content-frame" src="about:blank"></iframe>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Detect if device is mobile
        function isMobile() {
            return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) || window.innerWidth <= 768;
        }
        
        // Handle navigation clicks
        document.querySelectorAll('.nav-item[target="content-frame"]').forEach(link => {
            link.addEventListener('click', function(e) {
                // Remove active class from all nav items
                document.querySelectorAll('.nav-item').forEach(item => {
                    item.classList.remove('active');
                });
                
                // Add active class to clicked item
                this.classList.add('active');
                
                // Update URL without page reload
                const url = new URL(window.location);
                url.searchParams.set('page', 'content');
                window.history.pushState({}, '', url);
            });
        });
        
        // Handle dashboard link
        document.querySelector('.nav-item[href="?page=dashboard"]').addEventListener('click', function(e) {
            e.preventDefault();
            
            // Remove active class from all nav items
            document.querySelectorAll('.nav-item').forEach(item => {
                item.classList.remove('active');
            });
            
            // Add active class to dashboard
            this.classList.add('active');
            
            // Redirect to dashboard
            window.location.href = '?page=dashboard';
        });
        
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
    </script>
</body>
</html>
