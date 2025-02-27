<?php

    include 'db.php';


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


    $db = get_db_connection();

    ob_end_clean();


    $tables_check = "
        CREATE TABLE IF NOT EXISTS `truck_changeovers` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `timestamp` DATETIME NOT NULL,
            `truck_name` VARCHAR(255) NOT NULL,
            `state` ENUM('Relief', 'Normal') NOT NULL DEFAULT 'Normal'
        );

        CREATE TABLE IF NOT EXISTS `truck_changeover_items` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `changeover_id` INT NOT NULL,
            `locker_name` VARCHAR(255) NOT NULL,
            `item_name` VARCHAR(255) NOT NULL,
            `is_relief` BOOLEAN DEFAULT 0,
            FOREIGN KEY (`changeover_id`) REFERENCES `truck_changeovers`(`id`) ON DELETE CASCADE
        );";

    $db->exec($tables_check);

    // Add the state handling
    if (isset($_POST['reset_state'])) {
        // Create new changeover with Normal state
        $stmt = $db->prepare("INSERT INTO truck_changeovers (timestamp, truck_name, state) VALUES (NOW(), :truck_name, 'Normal')");
        $stmt->execute(['truck_name' => $_POST['truck_name']]);
        $changeover_id = $db->lastInsertId();
        
        // Set all items to truck state (is_relief = 0)
        foreach ($_POST['items'] as $item) {
            $stmt = $db->prepare("INSERT INTO truck_changeover_items (changeover_id, locker_name, item_name, is_relief) VALUES (:changeover_id, :locker_name, :item_name, 0)");
            $stmt->execute([
                'changeover_id' => $changeover_id,
                'locker_name' => $item['locker'],
                'item_name' => $item['name'],
            ]);
        }
    }

    // Get current state and items
    $current_state = 'Normal';
    $current_items = [];
    if ($selected_truck_id) {
        $stmt = $db->prepare("
            SELECT tc.state, tci.* 
            FROM truck_changeovers tc 
            JOIN truck_changeover_items tci ON tc.id = tci.changeover_id 
            WHERE tc.truck_name = (SELECT name FROM trucks WHERE id = :truck_id) 
            ORDER BY tc.timestamp DESC LIMIT 1
        ");
        $stmt->execute(['truck_id' => $selected_truck_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $current_state = $result['state'];
            $current_items = $result;
        }
    }

    // Fetch all trucks
    $trucks = $db->query('SELECT * FROM trucks')->fetchAll(PDO::FETCH_ASSOC);

    // Check if a truck has been selected
    $selected_truck_id = isset($_GET['truck_id']) ? $_GET['truck_id'] : null;

    if ($selected_truck_id) {
        // Fetch lockers for the selected truck
        $query = $db->prepare('SELECT * FROM lockers WHERE truck_id = :truck_id');
        $query->execute(['truck_id' => $selected_truck_id]);
        $lockers = $query->fetchAll(PDO::FETCH_ASSOC);

        $selected_locker_id = isset($_GET['locker_id']) ? $_GET['locker_id'] : null;

    }    


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Truck Change Over">
    <link rel="stylesheet" href="styles/changeover.css?id=<?php  echo $version;  ?> ">
    <title>Check Locker Items</title>


</head>
<body>
<table  style='width: 100%;'>
    <TR>
        <TD style='width: 25%;' >
            <h1>Truck Change Over</h1>

            <!-- Truck Selection Form -->
            <form method="GET">
                <label for="truck_id">Select a Truck:</label>
                <select name="truck_id" id="truck_id" onchange="this.form.submit()">
                    <option value="">-- Select Truck --</option>
                    <?php foreach ($trucks as $truck): ?>
                        <option value="<?= $truck['id'] ?>" <?= $selected_truck_id == $truck['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($truck['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </TD>
        <TD style='width: 5%;' ></TD>
        <TD style='width: 70%;border=1;' >
            <h2>Notes:</h2>
            <p>1. Officer keys.</p>
            <p>2. Station Remotes - keys.</p>
        </TD>
    </TR>
</table>


<?php

    if ($selected_truck_id) {

        $truck_id = $selected_truck_id; 
        echo '<p><a href="changeover_pdf.php?truck_id=' . $truck_id . '" class="button touch-button">Generate PDF</a></p>';


        $query = $db->prepare("
            SELECT 
                t.name AS truck_name,
                l.name AS locker_name,
                i.name AS item_name
            FROM 
                trucks t
            JOIN 
                lockers l ON t.id = l.truck_id
            JOIN 
                items i ON l.id = i.locker_id
            WHERE 
                t.id = :truck_id
            ORDER BY 
                l.name, i.name
        ");
        $query->execute(['truck_id' => $truck_id]);
        $results = $query->fetchAll(PDO::FETCH_ASSOC);


        $locker_count = 1;
        $cellbgcolour = "#f0f0f0";
        $locker_total = 0;
        $prev_locker = "";

        echo "<div style='margin-bottom: 20px;'>";
        echo "<label class='toggle-switch'>";
        echo "<input type='checkbox' id='truck_state' " . ($current_state == 'Normal' ? 'checked' : '') . ">";
        echo "<span class='slider'></span>";
        echo "</label>";
        echo "<span style='margin-left: 10px;'>Relief / " . htmlspecialchars($row['truck_name']) . "</span>";
        echo "</div>";
        
        echo "<form method='POST'>";
        echo "<input type='hidden' name='truck_name' value='" . htmlspecialchars($row['truck_name']) . "'>";
        echo "<button type='submit' name='reset_state' class='button touch-button'>Reset State</button>";
        
        echo "<table border='1' cellpadding='5' cellspacing='0' style='width: 100%;'>";
        
        
        foreach ($results as $row) {
            if ($prev_locker != $row['locker_name']) {
                echo "<TR><td rowspan=2 >" .htmlspecialchars($row['locker_name']) . "</td><tr>";
            }
            echo '<tr>';

        
            $is_relief = isset($current_items[$row['item_name']]) ? $current_items[$row['item_name']]['is_relief'] : false;
            
            echo "<td style='background-color: {$cellbgcolour}'>";
            echo htmlspecialchars($row['item_name']);
            echo "<label class='toggle-switch'>";
            echo "<input type='checkbox' name='items[{$row['item_name']}][state]' " . ($is_relief ? '' : 'checked') . ">";
            echo "<span class='slider'></span>";
            echo "</label>";
            echo "<input type='hidden' name='items[{$row['item_name']}][name]' value='" . htmlspecialchars($row['item_name']) . "'>";
            echo "<input type='hidden' name='items[{$row['item_name']}][locker]' value='" . htmlspecialchars($row['locker_name']) . "'>";
            echo "</td></TR>";

            
            $prev_locker = $row['locker_name'];

        }

   

        echo "</table>";

        echo '<p><a href="changeover_pdf.php?truck_id=' . $truck_id . '" class="button touch-button">Generate PDF</a></p>';
        
    } else {
        echo "<p>Please select a truck to view its lockers and items.</p>";
    }

?>

<footer>

    <p><a href="index.php" class="button touch-button">Return to Home</a></p>
    <p id="last-refreshed" style="margin-top: 10px;"></p> 

</footer>
</body>
</html>
