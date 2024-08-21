<?php
/* ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL); */

include '../db.php';

$db = get_db_connection();


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if the version session variable is not set
if (!isset($_SESSION['version'])) {
    // Get the latest Git tag version
    $version = trim(exec('git describe --tags $(git rev-list --tags --max-count=1)'));

    // Set the session variable
    $_SESSION['version'] = $version;
} else {
    // Use the already set session variable
    $version = $_SESSION['version'];
}

// Check if the timestamp is already set
if (!isset($_SESSION['quiz_start_time'])) {
    $_SESSION['quiz_start_time'] = time();
}

// Initialize score session variables if not already set
if (!isset($_SESSION['correct_first'])) {
    $_SESSION['correct_first'] = 0;
    $_SESSION['correct_second'] = 0;
    $_SESSION['correct_third'] = 0;
}

// Function to reset the quiz scores and timestamp after 24 hours
function reset_quiz_scores() {
    $_SESSION['correct_first'] = 0;
    $_SESSION['correct_second'] = 0;
    $_SESSION['correct_third'] = 0;
    $_SESSION['quiz_start_time'] = time(); // Reset the timestamp
}

// Check if 24 hours have passed
if (time() - $_SESSION['quiz_start_time'] > 86400) { // 86400 seconds = 24 hours
    reset_quiz_scores();
}

$is_demo = isset($_SESSION['is_demo']) && $_SESSION['is_demo'] === true;

// Function to fetch a random quiz question
function get_quiz_question($db) {
    $sql = "SELECT i.id as item_id, i.name as item_name, l.id as locker_id, l.name as locker_name, t.name as truck_name, l.truck_id
            FROM items i
            JOIN lockers l ON i.locker_id = l.id
            JOIN trucks t ON l.truck_id = t.id
            ORDER BY RAND()
            LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        return null; 
    }

    $sql = "SELECT id, name FROM lockers WHERE id != :locker_id AND truck_id = :truck_id ORDER BY RAND() LIMIT 2";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':locker_id', $item['locker_id'], PDO::PARAM_INT);
    $stmt->bindValue(':truck_id', $item['truck_id'], PDO::PARAM_INT);
    $stmt->execute();

    $other_lockers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $options = array_merge([['id' => $item['locker_id'], 'name' => $item['locker_name']]], $other_lockers);
    shuffle($options);

    return [
        'item_name' => $item['item_name'],
        'truck_name' => $item['truck_name'],
        'correct_locker_id' => $item['locker_id'],
        'options' => $options
    ];
}

$quiz = get_quiz_question($db);

if ($quiz === null) {
    echo "No quiz available.";
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Truck Item Quiz</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../styles/styles.css?id=<?php  echo $version;  ?> ">   
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
            margin: 0;
            text-align: center;
        }

        .quiz-container {
            margin-top: 1vh;
            padding: 0 5vw;
        }

        .quiz-question {
            font-size: 5vw; /* Scales with viewport width */
            margin-bottom: 2vh;
        }

        .quiz-options {
            display: flex;
            flex-direction: column; /* Stack buttons vertically */
            align-items: center;
        }

        .quiz-options button {
            margin: 1vh 0;
            padding: 1vh 0;
            font-size: 5vw; /* Scales with viewport width */
            cursor: pointer;
            width: 40vw; /* Use most of the width on mobile */
            max-width: 150px; /* Limit the width on larger screens */
            height: 40vw; /* Square shape on mobile */
            max-height: 150px; /* Limit the height on larger screens */
            border: none;
            border-radius: 10px; /* Optional: Rounded corners */
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .quiz-options button.correct {
            background-color: green;
            color: white;
        }

        .quiz-options button.wrong {
            background-color: red;
            color: white;
        }

        .score-container {
            margin-top: 2vh;
            padding: 1vh;
            box-shadow: 0px 4px 6px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            display: inline-block;
            background-color: #f9f9f9;
            font-size: 5vw; /* Scales with viewport width */
            width: 80vw; /* Use most of the width on mobile */
            max-width: 300px; /* Limit the width on larger screens */
            text-align: left;
        }

        /* Adjustments for larger screens */
        @media (min-width: 768px) {
            .quiz-question {
                font-size: 2rem; /* 2 rem units for desktop */
            }

            .quiz-options button {
                font-size: 1.5rem; /* Adjusted font size for desktop */
                width: 300px; /* Fixed width for desktop */
                height: 300px; /* Fixed height for desktop */
            }

            .score-container {
                font-size: 1.5rem; /* Adjusted font size for desktop */
                width: 300px; /* Fixed width for desktop */
            }
        }

        /* Adjustments for even larger screens */
        @media (min-width: 1200px) {
            .quiz-question {
                font-size: 2.5rem; /* Slightly larger for very large screens */
            }

            .quiz-options button {
                font-size: 2rem; /* Larger font for very large screens */
                width: 350px; /* Larger width */
                height: 350px; /* Larger height */
            }

            .score-container {
                font-size: 2rem; /* Larger font for very large screens */
                width: 350px; /* Larger width */
            }
        }
    </style>

</head>
<body class="<?php echo $is_demo ? 'demo-mode' : ''; ?>">
    <div class="quiz-container">
    <div class="quiz-question">
        On <strong><?php echo htmlspecialchars($quiz['truck_name']); ?></strong>, where would you find the <br><strong><?php echo htmlspecialchars($quiz['item_name']); ?></strong>?
    </div>
    <div class="quiz-options">
        <?php foreach ($quiz['options'] as $option): ?>
            <button onclick="checkAnswer(this, <?php echo $option['id']; ?>, <?php echo $quiz['correct_locker_id']; ?>)">
                <?php echo htmlspecialchars($option['name']); ?>
            </button>
        <?php endforeach; ?>
    </div>

    <div class="score-container" id="score-container">
        <p><strong>Score: </strong><span id="total-score"><?php echo ($_SESSION['correct_first'] * 3) + ($_SESSION['correct_second'] * 2) + ($_SESSION['correct_third'] * 1); ?></span></strong></p>
        <p>1st attempt: <span id="score-first"><?php echo $_SESSION['correct_first']; ?></span></p>
        <p>2nd attempt: <span id="score-second"><?php echo $_SESSION['correct_second']; ?></span></p>
        <p>3rd attempt: <span id="score-third"><?php echo $_SESSION['correct_third']; ?></span></p>
    </div>
</div>

<div class="button-container" style="margin-top: 20px;">
    <p><a href="../index.php" class="button touch-button">Return to Home</a></p>
</div>
<script>
    let attemptCount = 0;

    function checkAnswer(button, selectedLockerId, correctLockerId) {
        attemptCount++;
        
        if (selectedLockerId === correctLockerId) {
            button.classList.add('correct');
            // alert('Correct!');
            trackAttempts(attemptCount);
            updateScore();
            disableAllButtons();
            setTimeout(function() {
                window.location.reload();
            }, 700);
        } else {
            button.classList.add('wrong');
        }
    }

    function trackAttempts(attempts) {
        let xhr = new XMLHttpRequest();
        xhr.open('POST', 'track_attempts.php', true);
        xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        xhr.send('attempts=' + attempts);
    }

    function updateScore() {
    let xhr = new XMLHttpRequest();
    xhr.open('GET', 'get_score.php', true);
    xhr.onload = function() {
        if (xhr.status === 200) {
            let scores = xhr.responseText.split("\n");
            document.getElementById('score-first').innerText = scores[0].split(": ")[1];
            document.getElementById('score-second').innerText = scores[1].split(": ")[1];
            document.getElementById('score-third').innerText = scores[2].split(": ")[1];
            document.getElementById('total-score').innerText = scores[3].split(": ")[1];
        }
    };
    xhr.send();
}

    function disableAllButtons() {
        let buttons = document.querySelectorAll('.quiz-options button');
        buttons.forEach(button => {
            button.disabled = true; // Disable the button to prevent further clicks
        });
    }
</script>

</body>
</html>