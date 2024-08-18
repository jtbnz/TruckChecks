
<?php
session_start();

$_SESSION['is_demo'] = false;
function get_db_connection() {
    $host = 'localhost';
    $db   = 'database_name';
    $user = 'database_user';
    $pass = 'database_password';
    $charset = 'utf8mb4';

    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // Throw exceptions for errors
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // Fetch results as associative arrays
        PDO::ATTR_EMULATE_PREPARES   => false,                   // Use native prepared statements
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
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
