<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
/* ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL); */


include 'config.php';

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
        return $pdo;
    } catch (PDOException $e) {
        // Log the error message to a file or another logging system
        error_log($e->getMessage(), 3, 'db_errors.log');

        // Display a user-friendly error message (or redirect to an error page)
        echo "<p>There was an error connecting to the database. Please try again later.</p>";

        // Stop script execution
        exit;
    }
}
?>
