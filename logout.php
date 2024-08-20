<?php
// Start the session
include 'header.php';

// Remove the logged-in cookie
setcookie('logged_in', '', time() - 3600, "/"); // Expire the cookie
$_SESSION['version'] = null;
$_SESSION = array();
session_destroy();

// Redirect to login page
header('Location: login.php');
exit;
?>