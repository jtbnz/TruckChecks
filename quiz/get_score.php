<?php
session_start();


// Check if 24 hours have passed since the quiz started
if (time() - $_SESSION['quiz_start_time'] > 86400) {
    // Reset the scores and timestamp
    $_SESSION['correct_first'] = 0;
    $_SESSION['correct_second'] = 0;
    $_SESSION['correct_third'] = 0;
    $_SESSION['quiz_start_time'] = time(); // Reset the timestamp

    echo "Scores have been reset after 24 hours.";
} else {
    // Display the current score
    $scoreMessage = "Correct lockers selected:\n";
    $scoreMessage .= "1st attempt: " . $_SESSION['correct_first'] . "\n";
    $scoreMessage .= "2nd attempt: " . $_SESSION['correct_second'] . "\n";
    $scoreMessage .= "3rd attempt: " . $_SESSION['correct_third'];

    echo $scoreMessage;
}
$scoreMessage = "Correct lockers selected:\n";
$scoreMessage .= "1st attempt: " . $_SESSION['correct_first'] . "\n";
$scoreMessage .= "2nd attempt: " . $_SESSION['correct_second'] . "\n";
$scoreMessage .= "3rd attempt: " . $_SESSION['correct_third'];

echo $scoreMessage;
?>
