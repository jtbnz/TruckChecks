<?php
session_start();

// Check if 24 hours have passed since the quiz started
if (time() - $_SESSION['quiz_start_time'] > 86400) {
    // Reset the scores and timestamp
    $_SESSION['correct_first'] = 0;
    $_SESSION['correct_second'] = 0;
    $_SESSION['correct_third'] = 0;
    $_SESSION['quiz_start_time'] = time(); // Reset the timestamp
}

// Calculate the total score
$total_score = ($_SESSION['correct_first'] * 3) + ($_SESSION['correct_second'] * 2) + ($_SESSION['correct_third'] * 1);

// Return the score and total score
echo "1st attempt: " . $_SESSION['correct_first'] . "\n";
echo "2nd attempt: " . $_SESSION['correct_second'] . "\n";
echo "3rd attempt: " . $_SESSION['correct_third'] . "\n";
echo "Total Score: " . $total_score;
?>