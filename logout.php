<?php

include 'header.php';

// Remove the logged-in cookie
setcookie('logged_in', '', time() - 3600, "/"); // Expire the cookie
// Unset all of the session variables
$_SESSION = array();

//  also delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// destroy the session
session_destroy();

// Redirect to login page
header('Location: login.php');
exit;
?>