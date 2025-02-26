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
    <link rel="stylesheet" href="styles/styles.css?id=<?php  echo $version;  ?> ">
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

        echo "<table border='1' cellpadding='5' cellspacing='0' style='width: 100%;'>\n";
        
        
        foreach ($results as $row) {

            if ($prev_locker != $row['locker_name']) {
                $locker_total++;
  
                if ($locker_count == 2 && $locker_total > 1) {
                    echo "\t" . '<td style="background-color: ' . $cellbgcolour . '"' . "></td>\n";
                    echo "\t" . '<td style="background-color: ' . $cellbgcolour . '"' . "></td>\n";
                    echo "\t" . '<td style="background-color: ' . $cellbgcolour . '"' . "></td>\n";
                    echo "</TR>\n";     
                    $locker_count = 1;     
                }

                echo '<tr style="background-color: #A9A9A9">' . "\n";
                echo "\t<th><strong>" . htmlspecialchars($row['locker_name']) . "</strong></th><th>Relief</th><th>" . htmlspecialchars($row['truck_name']) . "</th><th><strong>" . htmlspecialchars($row['locker_name']) . "</strong></th><th>Relief</th><th>" .$truck['name'] . "</th><TR>\n";
                
                
                if ($locker_total % 2 == 0) {
                    $cellbgcolour = "#ffffff";
                 } else {
                     $cellbgcolour = "#f0f0f0";
                 }
            }




            if ($locker_count == 1) {
                        echo '<tr>' . "\n";          
                
            }
            // echo "\t" . '<td style="background-color: ' . $cellbgcolour . '">' . htmlspecialchars($row['locker_name']) .  "</td>\n";
            echo "\t" . '<td style="background-color: ' . $cellbgcolour . '">' . htmlspecialchars($row['item_name']) . "</td>\n";
            echo "\t" . '<td style="background-color: ' . $cellbgcolour . '"' . "><center><input type='checkbox'></center></td>\n";
            echo "\t" . '<td style="background-color: ' . $cellbgcolour . '"' . "><center><input type='checkbox'></center></td>\n";
   

            if ($locker_count == 2) {
                echo "</tr>\n";
                $locker_count = 0;

                
            }
            
            $prev_locker = $row['locker_name'];

            $locker_count++;
        }

        if ($locker_count == 2 && $locker_total > 1) {
            $html .=   '<td style="background-color: ' . $cellbgcolour . '"' . "></td>";
            $html .=   '<td style="background-color: ' . $cellbgcolour . '"' . "></td>";
            $html .=   '<td style="background-color: ' . $cellbgcolour . '"' . "></td>";
            $html .=  "</tr>";     
            $locker_count = 1;     
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
