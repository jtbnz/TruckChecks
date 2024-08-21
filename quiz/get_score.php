<?php
session_start();

$scoreMessage = "Correct lockers selected:\n";
$scoreMessage .= "1st attempt: " . $_SESSION['correct_first'] . "\n";
$scoreMessage .= "2nd attempt: " . $_SESSION['correct_second'] . "\n";
$scoreMessage .= "3rd attempt: " . $_SESSION['correct_third'];

echo $scoreMessage;
?>
