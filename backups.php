<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Check if the user is logged in

if (!isset($_COOKIE['logged_in']) || $_COOKIE['logged_in'] != 'true') {
    header('Location: login.php');
    exit;
}

include 'db.php'; // Include the database connection file



// Paths and filenames
$backup_dir = 'backups';
//is_demo = isset($_SESSION['is_demo']) && $_SESSION['is_demo'] === true;


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Only run the backup creation when the form is submitted
    $backup_file = $backup_dir . '/' .  . '_' . date('Y-m-d_H-i-s') . '.sql';
    $zip_file = $backup_dir . '/' .  . '_' . date('Y-m-d_H-i-s') . '.zip';

    // Create backups directory if not exists
    if (!file_exists($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }

    // Step 1: Dump the database into a SQL file
    $command = "mysqldump --user=db_user --password=db_pass  > $backup_file";
    $output = null;
    $return_var = null;
    exec($command, $output, $return_var);

    // Check if the command was successful
    if ($return_var !== 0) {
        die("Failed to execute mysqldump: " . implode("\n", $output));
    }

    // Step 2: Create a zip file containing the SQL dump
    $zip = new ZipArchive();
    if ($zip->open($zip_file, ZipArchive::CREATE) === TRUE) {
        $zip->addFile($backup_file, basename($backup_file));
        $zip->close();
        // Remove the SQL file after creating the zip
        unlink($backup_file);
    } else {
        die('Failed to create ZIP file');
    }

    // Step 3: Force download the ZIP file
    if (file_exists($zip_file)) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename=' . basename($zip_file));
        header('Content-Length: ' . filesize($zip_file));
        ob_clean(); // Clean the output buffer
        flush(); // Flush the system output buffer
        readfile($zip_file);
        unlink($zip_file); // Optionally, delete the zip file after download
        exit;
    } else {
        die('Failed to create backup');
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup Database</title>
    <link rel="stylesheet" href="styles/styles.css?id=V7"> <!-- Link to your stylesheet if any -->
</head>
<body class="<?php echo is_demo ? 'demo-mode' : ''; ?>">

<h1>Backup Database</h1>

<p>Click the button below to download a backup of the database.</p>

<form method="post">
    <button type="submit">Download Backup</button>
</form>

<?php include 'templates/footer.php'; ?>
</body>
</html>
