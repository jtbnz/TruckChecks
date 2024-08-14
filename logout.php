<?php
// Start the session
session_start();

// Remove the logged-in cookie
setcookie('logged_in', '', time() - 3600, "/"); // Expire the cookie

// Redirect to login page
header('Location: login.php');
exit;
?>