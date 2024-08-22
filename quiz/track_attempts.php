<?php
session_start();

if (isset($_POST['attempts'])) {
    $attempts = intval($_POST['attempts']);

    if ($attempts === 1) {
        $_SESSION['correct_first'] += 1;
    } elseif ($attempts === 2) {
        $_SESSION['correct_second'] += 1;
    } elseif ($attempts === 3) {
        $_SESSION['correct_third'] += 1;
    }
}
?>
