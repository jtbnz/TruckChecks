<?php
// Start the session
session_start();

// Include the database connection
include 'db.php';

// Initialize session variables for correct answers if not already set
if (!isset($_SESSION['correct_first'])) {
    $_SESSION['correct_first'] = 0;
    $_SESSION['correct_second'] = 0;
    $_SESSION['correct_third'] = 0;
}

// Function to fetch a random quiz question
function get_quiz_question($db) {
    // Get a random item and its lockers from the database
    $sql = "SELECT i.id as item_id, i.name as item_name, l.id as locker_id, l.name as locker_name
            FROM items i
            JOIN lockers l ON i.locker_id = l.id
            ORDER BY RAND()
            LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get 2 other random lockers from the same truck for the options
    $sql = "SELECT id, name FROM lockers WHERE id != :locker_id AND truck_id = 
            (SELECT truck_id FROM lockers WHERE id = :locker_id) ORDER BY RAND() LIMIT 2";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':locker_id', $item['locker_id']);
    $stmt->execute();
    $other_lockers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Combine the correct locker with the other options and shuffle them
    $options = array_merge([$item], $other_lockers);
    shuffle($options);

    return [
        'item_name' => $item['item_name'],
        'correct_locker_id' => $item['locker_id'],
        'options' => $options
    ];
}

// Generate a new quiz question
$quiz = get_quiz_question($db);
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
    </style>
</head>
<body>

<div class="quiz-container">
    <div class="quiz-question">
        Which locker contains the item: <strong><?php echo htmlspecialchars($quiz['item_name']); ?></strong>?
    </div>
    <div class="quiz-options">
        <?php foreach ($quiz['options'] as $option): ?>
            <button onclick="checkAnswer(this, <?php echo $option['id']; ?>, <?php echo $quiz['correct_locker_id']; ?>)">
                <?php echo htmlspecialchars($option['name']); ?>
            </button>
        <?php endforeach; ?>
    </div>
</div>

<script>
    let attemptCount = 0;

    function checkAnswer(button, selectedLockerId, correctLockerId) {
        attemptCount++;
        if (selectedLockerId === correctLockerId) {
            button.classList.add('correct');
            trackAttempts(attemptCount);
            setTimeout(showScorePopup, 500);
        } else {
            button.classList.add('wrong');
            if (attemptCount >= 3) {
                trackAttempts(attemptCount);
                setTimeout(showScorePopup, 500);
            }
        }
    }

    function trackAttempts(attempts) {
        let xhr = new XMLHttpRequest();
        xhr.open('POST', 'track_attempts.php', true);
        xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        xhr.send('attempts=' + attempts);
    }

    function showScorePopup() {
        let xhr = new XMLHttpRequest();
        xhr.open('GET', 'get_score.php', true);
        xhr.onload = function() {
            if (xhr.status === 200) {
                alert(xhr.responseText);
                window.location.reload(); // Reload to get a new question
            }
        };
        xhr.send();
    }
</script>

</body>
</html>
