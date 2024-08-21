
<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include '../db.php';

$db = get_db_connection();

session_start();

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
    <link rel="stylesheet" href="styles/styles.css">
    <style>
        .quiz-container {
            text-align: center;
            margin-top: 50px;
        }
        .quiz-question {
            font-size: 24px;
            margin-bottom: 20px;
        }
        .quiz-options button {
            margin: 10px;
            padding: 10px 20px;
            font-size: 18px;
            cursor: pointer;
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
            margin-top: 30px;
            padding: 15px;
            box-shadow: 0px 4px 6px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            display: inline-block;
            background-color: #f9f9f9;
        }
    </style>
</head>
<body>

<div class="quiz-container">
    <div class="quiz-question">
        On <strong><?php echo htmlspecialchars($quiz['truck_name']); ?></strong>, where is <strong><?php echo htmlspecialchars($quiz['item_name']); ?></strong>?
    </div>
    <div class="quiz-options">
        <?php foreach ($quiz['options'] as $option): ?>
            <button onclick="checkAnswer(this, <?php echo $option['id']; ?>, <?php echo $quiz['correct_locker_id']; ?>)">
                <?php echo htmlspecialchars($option['name']); ?>
            </button>
        <?php endforeach; ?>
    </div>

    <div class="score-container" id="score-container">
        <p>Correct lockers selected:</p>
        <p>1st attempt: <span id="score-first"><?php echo $_SESSION['correct_first']; ?></span></p>
        <p>2nd attempt: <span id="score-second"><?php echo $_SESSION['correct_second']; ?></span></p>
        <p>3rd attempt: <span id="score-third"><?php echo $_SESSION['correct_third']; ?></span></p>
    </div>
</div>


<script>
 let attemptCount = 0;

 function checkAnswer(button, selectedLockerId, correctLockerId) {
    attemptCount++;
    
    if (selectedLockerId === correctLockerId) {
        button.classList.add('correct');
        alert('Correct!');
        trackAttempts(attemptCount);
        updateScore();
        disableAllButtons();

        // Reload the page to show a new quiz question
        setTimeout(function() {
            window.location.reload();
        }, 500);  // Delay to allow the user to see the "Correct!" message
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
