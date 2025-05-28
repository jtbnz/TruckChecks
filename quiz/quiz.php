<?php
/* ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL); */

include '../db.php';
include_once('../auth.php');

// Get current station context (no authentication required for public view)
$stations = [];
$currentStation = null;

try {
    $db = get_db_connection();
    $stmt = $db->prepare("SELECT * FROM stations ORDER BY name");
    $stmt->execute();
    $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Stations table not found, using legacy mode: " . $e->getMessage());
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Handle station selection from dropdown
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['selected_station'])) {
    $stationId = (int)$_POST['selected_station'];
    
    setcookie('preferred_station', $stationId, time() + (365 * 24 * 60 * 60), "/");
    $_SESSION['current_station_id'] = $stationId;
    
    header('Location: quiz.php');
    exit;
}

// Get current station for filtering
if (!empty($stations)) {
    if (isset($_SESSION['current_station_id'])) {
        foreach ($stations as $station) {
            if ($station['id'] == $_SESSION['current_station_id']) {
                $currentStation = $station;
                break;
            }
        }
    }
    
    if (!$currentStation && isset($_COOKIE['preferred_station'])) {
        foreach ($stations as $station) {
            if ($station['id'] == $_COOKIE['preferred_station']) {
                $currentStation = $station;
                $_SESSION['current_station_id'] = $station['id'];
                break;
            }
        }
    }
    
    if (!$currentStation && count($stations) === 1) {
        $currentStation = $stations[0];
        $_SESSION['current_station_id'] = $currentStation['id'];
        setcookie('preferred_station', $currentStation['id'], time() + (365 * 24 * 60 * 60), "/");
    }
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

//IS_DEMO = isset($_SESSION['IS_DEMO']) && $_SESSION['IS_DEMO'] === true;

// Function to fetch a random quiz question
function get_quiz_question($db, $currentStation) {
    if ($currentStation) {
        // Filter by current station
        $sql = "SELECT i.id as item_id, i.name as item_name, l.id as locker_id, l.name as locker_name, t.name as truck_name, l.truck_id
                FROM items i
                JOIN lockers l ON i.locker_id = l.id
                JOIN trucks t ON l.truck_id = t.id
                WHERE t.station_id = :station_id
                ORDER BY RAND()
                LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute(['station_id' => $currentStation['id']]);
    } else {
        // Legacy behavior - all items
        $sql = "SELECT i.id as item_id, i.name as item_name, l.id as locker_id, l.name as locker_name, t.name as truck_name, l.truck_id
                FROM items i
                JOIN lockers l ON i.locker_id = l.id
                JOIN trucks t ON l.truck_id = t.id
                ORDER BY RAND()
                LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute();
    }
    
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

// Check if we need to show station selection first
if (!empty($stations) && !$currentStation && count($stations) > 1) {
    // Show station selection interface
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Select Station - Quiz</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="stylesheet" href="../styles/styles.css?id=<?php echo $version; ?>">
        <style>
            .station-selection {
                max-width: 500px;
                margin: 50px auto;
                padding: 30px;
                background-color: #f9f9f9;
                border-radius: 10px;
                box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
                text-align: center;
            }
            
            .station-dropdown {
                width: 100%;
                padding: 15px;
                font-size: 16px;
                border: 1px solid #ccc;
                border-radius: 5px;
                margin: 20px 0;
            }
            
            .select-station-btn {
                width: 100%;
                padding: 15px;
                background-color: #12044C;
                color: white;
                border: none;
                border-radius: 5px;
                font-size: 16px;
                cursor: pointer;
            }
            
            .select-station-btn:hover {
                background-color: #0056b3;
            }
        </style>
    </head>
    <body>
        <div class="station-selection">
            <h2>Select Station for Quiz</h2>
            <p>Please select a station to start the quiz:</p>
            
            <form method="post" action="">
                <select name="selected_station" class="station-dropdown" required>
                    <option value="">-- Select a Station --</option>
                    <?php foreach ($stations as $station): ?>
                        <option value="<?= $station['id'] ?>"><?= htmlspecialchars($station['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                
                <button type="submit" class="select-station-btn">Start Quiz</button>
            </form>
            
            <div style="margin-top: 30px;">
                <a href="../index.php" class="button touch-button">← Back to Home</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$quiz = get_quiz_question($db, $currentStation);

if ($quiz === null) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Quiz Not Available</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="stylesheet" href="../styles/styles.css?id=<?php echo $version; ?>">
    </head>
    <body>
        <div style="text-align: center; padding: 50px;">
            <h2>Quiz Not Available</h2>
            <p>No quiz questions are available for this station.</p>
            <?php if ($currentStation): ?>
                <p>Station: <?= htmlspecialchars($currentStation['name']) ?></p>
            <?php endif; ?>
            <div style="margin-top: 30px;">
                <a href="../index.php" class="button touch-button">← Back to Home</a>
            </div>
        </div>
    </body>
    </html>
    <?php
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

        .station-info {
            text-align: center;
            margin-bottom: 20px;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 5px;
            font-size: 14px;
        }

        .station-name {
            font-weight: bold;
            color: #12044C;
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
            width: 35vw; /* Use most of the width on mobile */
            max-width: 130px; /* Limit the width on larger screens */
            height: 35vw; /* Square shape on mobile */
            max-height: 130px; /* Limit the height on larger screens */
            border: none;
            border-radius: 10px; /* Optional: Rounded corners */
            display: flex;
            justify-content: center;
            align-items: center;
            box-shadow: 5px 5px 10px rgba(0, 0, 0, 0.3);
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
            width: 40vw; /* Use most of the width on mobile */
            max-width: 200px; /* Limit the width on larger screens */
            text-align: left;
        }
        .score-container p {
            margin: 0.1em 0;
            padding: 0;
            font-size: 0.8em; /* Reduce font size */
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
<body class="<?php echo IS_DEMO ? 'demo-mode' : ''; ?>">
    <?php if ($currentStation): ?>
        <div class="station-info">
            <div class="station-name"><?= htmlspecialchars($currentStation['name']) ?></div>
            <?php if ($currentStation['description']): ?>
                <div style="color: #666; margin-top: 5px;"><?= htmlspecialchars($currentStation['description']) ?></div>
            <?php endif; ?>
            <?php if (count($stations) > 1): ?>
                <div style="margin-top: 5px;">
                    <a href="quiz.php" onclick="return changeStation()" style="color: #12044C; text-decoration: none; font-size: 12px;">
                        Change Station
                    </a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

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
        <p><strong>Todays Score: </strong><span id="total-score"><?php echo ($_SESSION['correct_first'] * 3) + ($_SESSION['correct_second'] * 2) + ($_SESSION['correct_third'] * 1); ?></span></strong></p>
        <p>1<sup>st</sup> attempt: <span id="score-first"><?php echo $_SESSION['correct_first']; ?></span></p>
        <p>2<sup>nd</sup> attempt: <span id="score-second"><?php echo $_SESSION['correct_second']; ?></span></p>
        <p>3<sup>rd</sup> attempt: <span id="score-third"><?php echo $_SESSION['correct_third']; ?></span></p>
    </div>
</div>

<div class="button-container" style="margin-top: 20px;">
    <p><a href="../index.php" class="button touch-button">Return to Home</a></p>
</div>

<script>
    let attemptCount = 0;

    function changeStation() {
        document.cookie = 'preferred_station=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
        
        if (typeof(Storage) !== "undefined") {
            sessionStorage.removeItem('current_station_id');
        }
        
        return true;
    }

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
