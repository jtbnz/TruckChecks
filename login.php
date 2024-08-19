<?php
include('password.php');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


// Check if the user is already logged in
if (isset($_COOKIE['logged_in']) && $_COOKIE['logged_in'] == 'true') {
    header('Location: admin.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($_POST['password'] === PASSWORD) {
        // Set a cookie to remember the user for 90 days
        setcookie('logged_in', 'true', time() + (90 * 24 * 60 * 60), "/");

        // Redirect to a protected page
        header('Location: admin.php');
        exit;
    } else {
        $error = "Incorrect password.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Login</title>
</head>
<body>
    <h2>Login</h2>
    <?php if (isset($error)) echo "<p style='color:red;'>$error</p>"; ?>
    <form method="post" action="">
        <label for="password">Password:</label>
        <input type="password" name="password" id="password" required>
        <input type="submit" value="Login">
    </form>
    <?php include 'templates/footer.php'; ?>    
</body>
</html>
