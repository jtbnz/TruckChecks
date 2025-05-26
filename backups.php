<?php
// Include password file
include('config.php');
include 'db.php';
include 'templates/header.php';

// Check if the user is logged in
if (!isset($_COOKIE['logged_in_' . DB_NAME]) || $_COOKIE['logged_in_' . DB_NAME] != 'true') {
    header('Location: login.php');
    exit;
}

$db = get_db_connection();

// Handle backup download
if (isset($_POST['download_backup'])) {
    try {
        // Get all tables
        $tables = [];
        $result = $db->query("SHOW TABLES");
        while ($row = $result->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        
        // Start building SQL dump
        $sql_dump = "-- TruckChecks Database Backup\n";
        $sql_dump .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n\n";
        $sql_dump .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
        
        foreach ($tables as $table) {
            // Get table structure
            $result = $db->query("SHOW CREATE TABLE `$table`");
            $row = $result->fetch(PDO::FETCH_NUM);
            $sql_dump .= "DROP TABLE IF EXISTS `$table`;\n";
            $sql_dump .= $row[1] . ";\n\n";
            
            // Get table data
            $result = $db->query("SELECT * FROM `$table`");
            $rows = $result->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($rows)) {
                $columns = array_keys($rows[0]);
                $sql_dump .= "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES\n";
                
                $values = [];
                foreach ($rows as $row) {
                    $escaped_values = [];
                    foreach ($row as $value) {
                        if ($value === null) {
                            $escaped_values[] = 'NULL';
                        } else {
                            $escaped_values[] = "'" . addslashes($value) . "'";
                        }
                    }
                    $values[] = "(" . implode(', ', $escaped_values) . ")";
                }
                
                $sql_dump .= implode(",\n", $values) . ";\n\n";
            }
        }
        
        $sql_dump .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        
        // Send file for download
        $filename = 'truckChecks_backup_' . date('Y-m-d_H-i-s') . '.sql';
        
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($sql_dump));
        
        echo $sql_dump;
        exit;
        
    } catch (Exception $e) {
        $error_message = "Error creating backup: " . $e->getMessage();
    }
}
?>

<h1>Database Backup</h1>

<?php if (isset($error_message)): ?>
    <div class="error-message" style="color: red; margin: 20px 0; padding: 10px; background-color: #f8d7da; border: 1px solid #f5c6cb; border-radius: 5px;">
        <?= htmlspecialchars($error_message) ?>
    </div>
<?php endif; ?>

<div class="info-section" style="margin: 20px 0; padding: 15px; background-color: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 5px;">
    <h2>Database Backup</h2>
    <p>This will create a complete backup of your TruckChecks database including all tables and data.</p>
    <p>The backup file will be downloaded as an SQL file that can be imported to restore your database.</p>
</div>

<form method="POST">
    <div class="button-container">
        <button type="submit" name="download_backup" class="button touch-button">
            Download Database Backup
        </button>
    </div>
</form>

<div class="info-section" style="margin: 20px 0; padding: 15px; background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px;">
    <h3>Backup Information</h3>
    <ul>
        <li>The backup includes all tables: trucks, lockers, items, checks, login_log, audit_log, and settings</li>
        <li>All data and table structures are preserved</li>
        <li>The backup file can be imported using phpMyAdmin or MySQL command line</li>
        <li>Regular backups are recommended before making significant changes</li>
    </ul>
</div>

<div class="button-container" style="margin-top: 20px;">
    <a href="admin.php" class="button touch-button">Back to Admin</a>
</div>

<?php include 'templates/footer.php'; ?>
