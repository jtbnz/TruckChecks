<?php
// TruckChecks V4 Installation and Upgrade System
// This page handles fresh installations and upgrades from previous versions

// Security check - only allow if config.php exists and has valid database credentials
if (!file_exists('config.php')) {
    die('<h1>Configuration Required</h1><p>Please create config.php from config_sample.php with your database credentials before running the installer.</p>');
}

include_once('config.php');

// Function to execute SQL file with proper parsing for MySQL triggers and procedures
function executeSqlFile($db, $filePath) {
    $sql = file_get_contents($filePath);
    if ($sql === false) {
        throw new Exception("Could not read SQL file: $filePath");
    }
    
    // Split by DELIMITER blocks first
    $parts = preg_split('/DELIMITER\s+(\S+)/i', $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
    
    $statements = [];
    $currentDelimiter = ';';
    
    for ($i = 0; $i < count($parts); $i++) {
        if ($i % 2 == 1) {
            // This is a delimiter definition
            $currentDelimiter = trim($parts[$i]);
            continue;
        }
        
        $content = trim($parts[$i]);
        if (empty($content)) {
            continue;
        }
        
        // Split content by current delimiter
        if ($currentDelimiter === ';') {
            // Standard semicolon delimiter - split by lines and process
            $lines = explode("\n", $content);
            $currentStatement = '';
            
            foreach ($lines as $line) {
                $line = trim($line);
                
                // Skip empty lines and comments
                if (empty($line) || strpos($line, '--') === 0) {
                    continue;
                }
                
                $currentStatement .= $line . "\n";
                
                // Check for statement end
                if (substr($line, -1) === ';') {
                    $stmt = trim($currentStatement);
                    if (!empty($stmt)) {
                        $statements[] = $stmt;
                    }
                    $currentStatement = '';
                }
            }
            
            // Add any remaining statement
            if (trim($currentStatement)) {
                $statements[] = trim($currentStatement);
            }
        } else {
            // Custom delimiter (like $$) - split by the delimiter
            $parts_custom = explode($currentDelimiter, $content);
            foreach ($parts_custom as $part) {
                $part = trim($part);
                if (!empty($part)) {
                    // Remove comments from the part
                    $lines = explode("\n", $part);
                    $cleanLines = [];
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (!empty($line) && strpos($line, '--') !== 0) {
                            $cleanLines[] = $line;
                        }
                    }
                    if (!empty($cleanLines)) {
                        $statements[] = implode("\n", $cleanLines);
                    }
                }
            }
        }
    }
    
    // Execute each statement
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement)) {
            try {
                $db->exec($statement);
            } catch (Exception $e) {
                throw new Exception("Error executing SQL statement: " . $e->getMessage() . "\nStatement: " . substr($statement, 0, 200) . "...");
            }
        }
    }
}

// Test database connection
try {
    $testDb = new PDO("mysql:host=" . DB_HOST, DB_USER, DB_PASS);
    $testDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die('<h1>Database Connection Failed</h1><p>Please check your database credentials in config.php. Error: ' . htmlspecialchars($e->getMessage()) . '</p>');
}

// Check if this is a fresh install or upgrade
$isUpgrade = false;
$currentVersion = 'Unknown';
$hasStations = false;

try {
    $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if trucks table exists (indicates existing installation)
    $stmt = $db->query("SHOW TABLES LIKE 'trucks'");
    if ($stmt->rowCount() > 0) {
        $isUpgrade = true;
        
        // Check if stations table exists (indicates V4)
        $stmt = $db->query("SHOW TABLES LIKE 'stations'");
        $hasStations = $stmt->rowCount() > 0;
        
        if ($hasStations) {
            $currentVersion = 'V4+';
        } else {
            $currentVersion = 'V3 or earlier';
        }
    }
} catch (Exception $e) {
    // Database doesn't exist or can't connect - fresh install
    $isUpgrade = false;
}

// Handle form submissions
$step = $_GET['step'] ?? 'welcome';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['create_database'])) {
        try {
            $dbName = trim($_POST['database_name']);
            if (empty($dbName)) {
                throw new Exception("Database name is required");
            }
            
            $db = new PDO("mysql:host=" . DB_HOST, DB_USER, DB_PASS);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Create database
            $db->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $success = "Database '$dbName' created successfully. Please update your config.php with this database name.";
            
        } catch (Exception $e) {
            $error = "Error creating database: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['install_fresh'])) {
        try {
            $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Execute setup.sql
            executeSqlFile($db, 'Docker/setup.sql');
            
            // Execute V4Changes.sql
            executeSqlFile($db, 'V4Changes.sql');
            
            $success = "Fresh installation completed successfully!";
            $step = 'create_superuser';
            
        } catch (Exception $e) {
            $error = "Installation failed: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['upgrade_v4'])) {
        try {
            $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Execute V4Changes.sql
            executeSqlFile($db, 'V4Changes.sql');
            
            $success = "Upgrade to V4 completed successfully!";
            $step = 'create_superuser';
            
        } catch (Exception $e) {
            $error = "Upgrade failed: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['import_data'])) {
        try {
            $sourceHost = trim($_POST['source_host']);
            $sourceDb = trim($_POST['source_database']);
            $sourceUser = trim($_POST['source_user']);
            $sourcePass = $_POST['source_pass'];
            
            if (empty($sourceHost) || empty($sourceDb) || empty($sourceUser)) {
                throw new Exception("All source database fields are required");
            }
            
            // Test source connection
            $sourceConn = new PDO("mysql:host=$sourceHost;dbname=$sourceDb", $sourceUser, $sourcePass);
            $sourceConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Read merge script and replace placeholder
            $mergeSql = file_get_contents('merge_database.sql');
            if ($mergeSql === false) {
                throw new Exception("Could not read merge_database.sql file");
            }
            
            // Replace source_db placeholder with actual database name
            $mergeSql = str_replace('source_db.', "`$sourceDb`.", $mergeSql);
            
            // Execute on target database
            $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Write temporary file and execute
            $tempFile = tempnam(sys_get_temp_dir(), 'merge_sql');
            file_put_contents($tempFile, $mergeSql);
            executeSqlFile($db, $tempFile);
            unlink($tempFile);
            
            $success = "Data import completed successfully!";
            
        } catch (Exception $e) {
            $error = "Import failed: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['create_superuser'])) {
        try {
            $username = trim($_POST['username']);
            $password = $_POST['password'];
            $email = trim($_POST['email']);
            
            if (empty($username) || empty($password)) {
                throw new Exception("Username and password are required");
            }
            
            if (strlen($password) < 6) {
                throw new Exception("Password must be at least 6 characters long");
            }
            
            $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Check if user already exists
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("Username already exists");
            }
            
            // Create superuser
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (username, password_hash, email, role) VALUES (?, ?, ?, 'superuser')");
            $stmt->execute([$username, $passwordHash, $email]);
            
            $success = "Superuser created successfully!";
            $step = 'manage_stations';
            
        } catch (Exception $e) {
            $error = "Error creating superuser: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['create_station'])) {
        try {
            $stationName = trim($_POST['station_name']);
            $stationDesc = trim($_POST['station_description']);
            
            if (empty($stationName)) {
                throw new Exception("Station name is required");
            }
            
            $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Create station
            $stmt = $db->prepare("INSERT INTO stations (name, description) VALUES (?, ?)");
            $stmt->execute([$stationName, $stationDesc]);
            
            $success = "Station '$stationName' created successfully!";
            
        } catch (Exception $e) {
            $error = "Error creating station: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['create_station_admin'])) {
        try {
            $username = trim($_POST['admin_username']);
            $password = $_POST['admin_password'];
            $email = trim($_POST['admin_email']);
            $stationIds = $_POST['station_ids'] ?? [];
            
            if (empty($username) || empty($password)) {
                throw new Exception("Username and password are required");
            }
            
            if (empty($stationIds)) {
                throw new Exception("At least one station must be selected");
            }
            
            $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Check if user already exists
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("Username already exists");
            }
            
            // Create station admin
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (username, password_hash, email, role) VALUES (?, ?, ?, 'station_admin')");
            $stmt->execute([$username, $passwordHash, $email]);
            $userId = $db->lastInsertId();
            
            // Assign to stations
            $stmt = $db->prepare("INSERT INTO user_stations (user_id, station_id, created_by) VALUES (?, ?, 1)");
            foreach ($stationIds as $stationId) {
                $stmt->execute([$userId, $stationId]);
            }
            
            $success = "Station admin '$username' created and assigned to " . count($stationIds) . " station(s)!";
            
        } catch (Exception $e) {
            $error = "Error creating station admin: " . $e->getMessage();
        }
    }
}

// Get available stations for admin assignment
$stations = [];
if ($step == 'manage_stations' || $step == 'create_admin') {
    try {
        $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $db->query("SELECT * FROM stations ORDER BY name");
        $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Ignore errors
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TruckChecks V4 Installation</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #12044C;
        }
        
        .header h1 {
            color: #12044C;
            margin: 0;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: bold;
            margin-top: 10px;
        }
        
        .status-fresh {
            background-color: #d4edda;
            color: #155724;
        }
        
        .status-upgrade {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .status-current {
            background-color: #cce7ff;
            color: #004085;
        }
        
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        
        .alert-success {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        
        .alert-error {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        
        .alert-info {
            background-color: #cce7ff;
            border: 1px solid #b3d7ff;
            color: #004085;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }
        
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 14px;
            box-sizing: border-box;
        }
        
        .form-group textarea {
            height: 80px;
            resize: vertical;
        }
        
        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        
        .checkbox-item {
            display: flex;
            align-items: center;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 5px;
        }
        
        .checkbox-item input[type="checkbox"] {
            width: auto;
            margin-right: 10px;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background-color: #12044C;
            color: white;
            text-decoration: none;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.3s;
            margin-right: 10px;
            margin-bottom: 10px;
        }
        
        .btn:hover {
            background-color: #0056b3;
        }
        
        .btn-secondary {
            background-color: #6c757d;
        }
        
        .btn-secondary:hover {
            background-color: #545b62;
        }
        
        .btn-danger {
            background-color: #dc3545;
        }
        
        .btn-danger:hover {
            background-color: #c82333;
        }
        
        .step-nav {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        
        .info-box {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .info-box h3 {
            margin-top: 0;
            color: #12044C;
        }
        
        .two-column {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 20px;
            }
            
            .two-column {
                grid-template-columns: 1fr;
            }
            
            .checkbox-group {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>TruckChecks V4 Installation</h1>
            <?php if ($isUpgrade): ?>
                <div class="status-badge <?= $hasStations ? 'status-current' : 'status-upgrade' ?>">
                    <?= $hasStations ? 'Current Version: ' . $currentVersion : 'Upgrade Available: ' . $currentVersion . ' → V4' ?>
                </div>
            <?php else: ?>
                <div class="status-badge status-fresh">Fresh Installation</div>
            <?php endif; ?>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if ($step == 'welcome'): ?>
            <div class="info-box">
                <h3>Welcome to TruckChecks V4</h3>
                <p>This installer will help you set up or upgrade your TruckChecks installation. V4 introduces:</p>
                <ul>
                    <li><strong>Station Hierarchy:</strong> Organize trucks by stations</li>
                    <li><strong>User Management:</strong> Role-based access control</li>
                    <li><strong>Enhanced Security:</strong> Token-based authentication</li>
                    <li><strong>Multi-tenancy:</strong> Station-specific administration</li>
                </ul>
            </div>

            <?php if ($isUpgrade): ?>
                <?php if ($hasStations): ?>
                    <div class="alert alert-info">
                        <strong>System Status:</strong> You already have V4 installed. You can use this installer to:
                        <ul>
                            <li>Create additional superusers</li>
                            <li>Manage stations and station admins</li>
                            <li>Import data from other TruckChecks instances</li>
                        </ul>
                    </div>
                    <div class="step-nav">
                        <a href="?step=create_superuser" class="btn">Manage Users</a>
                        <a href="?step=manage_stations" class="btn btn-secondary">Manage Stations</a>
                        <a href="?step=import" class="btn btn-secondary">Import Data</a>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <strong>Upgrade Available:</strong> Your system will be upgraded from <?= $currentVersion ?> to V4.
                        This will add station hierarchy and user management while preserving all existing data.
                    </div>
                    <form method="post">
                        <button type="submit" name="upgrade_v4" class="btn" onclick="return confirm('This will upgrade your database. Please ensure you have a backup. Continue?')">
                            Upgrade to V4
                        </button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <div class="two-column">
                    <div class="info-box">
                        <h3>Fresh Installation</h3>
                        <p>Install TruckChecks V4 in the current database (<?= DB_NAME ?>).</p>
                        <form method="post">
                            <button type="submit" name="install_fresh" class="btn" onclick="return confirm('This will create all tables in the database. Continue?')">
                                Install Fresh
                            </button>
                        </form>
                    </div>
                    
                    <div class="info-box">
                        <h3>Create New Database</h3>
                        <p>Create a new database for TruckChecks installation.</p>
                        <form method="post">
                            <div class="form-group">
                                <label for="database_name">Database Name:</label>
                                <input type="text" name="database_name" id="database_name" required>
                            </div>
                            <button type="submit" name="create_database" class="btn btn-secondary">
                                Create Database
                            </button>
                        </form>
                    </div>
                </div>
                
                <div class="step-nav">
                    <a href="?step=import" class="btn btn-secondary">Import from Existing Installation</a>
                </div>
            <?php endif; ?>

        <?php elseif ($step == 'create_superuser'): ?>
            <div class="info-box">
                <h3>Create Superuser Account</h3>
                <p>Superusers have full system access and can manage all stations and users.</p>
            </div>

            <form method="post">
                <div class="form-group">
                    <label for="username">Username:</label>
                    <input type="text" name="username" id="username" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" name="password" id="password" required minlength="6">
                    <small>Minimum 6 characters</small>
                </div>
                
                <div class="form-group">
                    <label for="email">Email (optional):</label>
                    <input type="email" name="email" id="email">
                </div>
                
                <button type="submit" name="create_superuser" class="btn">Create Superuser</button>
            </form>

            <div class="step-nav">
                <a href="?step=welcome" class="btn btn-secondary">← Back</a>
                <a href="?step=manage_stations" class="btn btn-secondary">Skip to Stations →</a>
            </div>

        <?php elseif ($step == 'manage_stations'): ?>
            <div class="info-box">
                <h3>Station Management</h3>
                <p>Create stations to organize your trucks. Each station can have its own administrators.</p>
            </div>

            <div class="two-column">
                <div>
                    <h4>Create New Station</h4>
                    <form method="post">
                        <div class="form-group">
                            <label for="station_name">Station Name:</label>
                            <input type="text" name="station_name" id="station_name" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="station_description">Description:</label>
                            <textarea name="station_description" id="station_description" placeholder="Optional description"></textarea>
                        </div>
                        
                        <button type="submit" name="create_station" class="btn">Create Station</button>
                    </form>
                </div>
                
                <div>
                    <h4>Existing Stations</h4>
                    <?php if (empty($stations)): ?>
                        <p><em>No stations created yet.</em></p>
                    <?php else: ?>
                        <ul style="list-style: none; padding: 0;">
                            <?php foreach ($stations as $station): ?>
                                <li style="padding: 10px; background-color: #f8f9fa; margin-bottom: 5px; border-radius: 5px;">
                                    <strong><?= htmlspecialchars($station['name']) ?></strong>
                                    <?php if ($station['description']): ?>
                                        <br><small><?= htmlspecialchars($station['description']) ?></small>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

            <div class="step-nav">
                <a href="?step=create_superuser" class="btn btn-secondary">← Users</a>
                <?php if (!empty($stations)): ?>
                    <a href="?step=create_admin" class="btn">Create Station Admin →</a>
                <?php endif; ?>
                <a href="?step=import" class="btn btn-secondary">Import Data</a>
                <a href="admin.php" class="btn">Finish Setup</a>
            </div>

        <?php elseif ($step == 'create_admin'): ?>
            <div class="info-box">
                <h3>Create Station Administrator</h3>
                <p>Station administrators can manage trucks, lockers, and items for their assigned stations only.</p>
            </div>

            <?php if (empty($stations)): ?>
                <div class="alert alert-error">
                    No stations available. Please create stations first.
                </div>
                <div class="step-nav">
                    <a href="?step=manage_stations" class="btn">← Create Stations</a>
                </div>
            <?php else: ?>
                <form method="post">
                    <div class="form-group">
                        <label for="admin_username">Username:</label>
                        <input type="text" name="admin_username" id="admin_username" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="admin_password">Password:</label>
                        <input type="password" name="admin_password" id="admin_password" required minlength="6">
                    </div>
                    
                    <div class="form-group">
                        <label for="admin_email">Email (optional):</label>
                        <input type="email" name="admin_email" id="admin_email">
                    </div>
                    
                    <div class="form-group">
                        <label>Assign to Stations:</label>
                        <div class="checkbox-group">
                            <?php foreach ($stations as $station): ?>
                                <div class="checkbox-item">
                                    <input type="checkbox" name="station_ids[]" value="<?= $station['id'] ?>" id="station_<?= $station['id'] ?>">
                                    <label for="station_<?= $station['id'] ?>"><?= htmlspecialchars($station['name']) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <button type="submit" name="create_station_admin" class="btn">Create Station Admin</button>
                </form>

                <div class="step-nav">
                    <a href="?step=manage_stations" class="btn btn-secondary">← Stations</a>
                    <a href="admin.php" class="btn">Finish Setup</a>
                </div>
            <?php endif; ?>

        <?php elseif ($step == 'import'): ?>
            <div class="info-box">
                <h3>Import Data from Existing Installation</h3>
                <p>Import trucks, lockers, items, and check history from another TruckChecks database on the same server.</p>
            </div>

            <div class="alert alert-info">
                <strong>Requirements:</strong>
                <ul>
                    <li>Source database must be on the same MySQL server</li>
                    <li>Current database must have V4 schema installed</li>
                    <li>Imported data will be assigned to a new "Merged Station"</li>
                </ul>
            </div>

            <form method="post">
                <div class="form-group">
                    <label for="source_host">Source Database Host:</label>
                    <input type="text" name="source_host" id="source_host" value="<?= DB_HOST ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="source_database">Source Database Name:</label>
                    <input type="text" name="source_database" id="source_database" required>
                </div>
                
                <div class="form-group">
                    <label for="source_user">Source Database Username:</label>
                    <input type="text" name="source_user" id="source_user" value="<?= DB_USER ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="source_pass">Source Database Password:</label>
                    <input type="password" name="source_pass" id="source_pass" required>
                </div>
                
                <button type="submit" name="import_data" class="btn" onclick="return confirm('This will import all data from the source database. Continue?')">
                    Import Data
                </button>
            </form>

            <div class="step-nav">
                <a href="?step=welcome" class="btn btn-secondary">← Back</a>
                <a href="admin.php" class="btn btn-secondary">Skip Import</a>
            </div>

        <?php endif; ?>
    </div>
</body>
</html>
