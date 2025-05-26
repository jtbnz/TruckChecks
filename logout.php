<?php
include('config.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include 'templates/header.php';

// Remove the logged-in cookie with the correct name (includes DB_NAME)
setcookie('logged_in_' . DB_NAME, '', time() - 3600, "/"); // Expire the cookie

// Unset all of the session variables
$_SESSION = array();

// Also delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Redirect to login page
header('Location: login.php');
exit;
?>
