<?php
// Start the session
include 'header.php';

// Remove the logged-in cookie
setcookie('logged_in', '', time() - 3600, "/"); // Expire the cookie

$_SESSION = array();
// Redirect to login page
header('Location: login.php');
exit;
?>