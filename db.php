<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include('config.php');

/**
 * Get application version from VERSION file
 */
function getVersion(): string {
    $versionFile = __DIR__ . '/VERSION';
    if (file_exists($versionFile)) {
        return trim(file_get_contents($versionFile));
    }
    return 'unknown';
}
if (isset($_GET['ajax'])) {
    // Never output errors directly during AJAX — they corrupt JSON responses
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(DEBUG ? E_ALL : 0);
} elseif (DEBUG) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    echo "Debug mode is on";
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
}

function get_db_connection() {

    $charset = 'utf8mb4';

    $dsn = "mysql:host=" .DB_HOST . ";dbname=" . DB_NAME . ";charset=$charset";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // Throw exceptions for errors
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // Fetch results as associative arrays
        PDO::ATTR_EMULATE_PREPARES   => false,                   // Use native prepared statements
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

        // Run database migrations to apply any missing schema changes
        require_once __DIR__ . '/db_migrate.php';
        run_migrations($pdo);

        return $pdo;
    } catch (PDOException $e) {
        // Log the error message to a file or another logging system
        error_log($e->getMessage(), 3, 'db_errors.log');

        if (isset($_GET['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Database connection failed']);
        } else {
            echo "<p>There was an error connecting to the database. Please try again later.</p>";
        }

        // Stop script execution
        exit;
    }
}
?>
