<?php
// Database connection (replace with your actual connection details)
$db = new PDO('mysql:host=your_host;dbname=your_db', 'username', 'password');

// Function to process words
function process_words($text, $max_length = 12, $reduce_font_threshold = 9) {
    // Split the text into words
    $words = explode(' ', $text);

    // Iterate through each word and apply the necessary transformations
    foreach ($words as &$word) {
        $word_length = strlen($word);

        if ($word_length > $max_length) {
            // Split the word if it is longer than the max_length
            $word = wordwrap($word, $max_length, '-', true);
        } elseif ($word_length >= $reduce_font_threshold) {
            // Reduce the font size if the word length is between 9 and 12 characters
            $word = '<span style="font-size: smaller;">' . htmlspecialchars($word) . '</span>';
        }
    }

    // Join the words back into a single string
    return implode(' ', $words);
}

// Fetch the last swap notes for the locker
$locker_id = $_GET['locker_id'] ?? 1; // Replace with actual locker ID
$last_notes = '';
$last_swap_query = $db->prepare("
    SELECT sn.note 
    FROM swap_notes sn
    JOIN swap s ON sn.swap_id = s.id
    WHERE s.locker_id = :locker_id
    ORDER BY s.swap_date DESC
    LIMIT 1
");
$last_swap_query->execute(['locker_id' => $locker_id]);
$last_note_result = $last_swap_query->fetch(PDO::FETCH_ASSOC);

if ($last_note_result) {
    $last_notes = $last_note_result['note'];
}

// Fetch the last swap items for the locker
$last_swap_items = [];
$last_swap_items_query = $db->prepare("
    SELECT si.item_id 
    FROM swap_items si
    JOIN swap s ON si.swap_id = s.id
    WHERE s.locker_id = :locker_id
    ORDER BY s.swap_date DESC
    LIMIT 1
");
$last_swap_items_query->execute(['locker_id' => $locker_id]);
$last_swap_items_result = $last_swap_items_query->fetchAll(PDO::FETCH_ASSOC);

foreach ($last_swap_items_result as $item) {
    $last_swap_items[] = $item['item_id'];
}

// Handle form submission to update the swap and swap_items tables
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['swap_items'])) {
    $locker_id = $_POST['locker_id'];
    $swapped_by = $_POST['swapped_by'];
    $notes = $_POST['notes']; // Get the notes input
    $swapped_items = isset($_POST['swapped_items']) ? $_POST['swapped_items'] : [];

    setcookie('prevName', $swapped_by, time() + (86400 * 120), "/"); 

    // Insert a new swap record
    $swap_query = $db->prepare("INSERT INTO swap (locker_id, swap_date, swapped_by) VALUES (:locker_id, NOW(), :swapped_by)");
    $swap_query->execute([
        'locker_id' => $locker_id,
        'swapped_by' => $swapped_by
    ]);

    // Get the ID of the newly inserted swap
    $swap_id = $db->lastInsertId();

    // Insert the note into the swap_notes table
    if (!empty($notes)) {
        $note_query = $db->prepare("INSERT INTO swap_notes (swap_id, note) VALUES (:swap_id, :note)");
        $note_query->execute(['swap_id' => $swap_id, 'note' => $notes]);
    }

    // Insert swap items (whether present or not)
    $items_query = $db->prepare('SELECT id FROM items WHERE locker_id = :locker_id');
    $items_query->execute(['locker_id' => $locker_id]);
    $items = $items_query->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $item) {
        $is_present = in_array($item['id'], $swapped_items) ? 1 : 0;
        $swap_item_query = $db->prepare('INSERT INTO swap_items (swap_id, item_id, is_present) VALUES (:swap_id, :item_id, :is_present)');
        $swap_item_query->execute([
            'swap_id' => $swap_id,
            'item_id' => $item['id'],
            'is_present' => $is_present
        ]);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Truck Change Over</title>
</head>
<body>
    <h1>Change Locker Items</h1>
    <form method="POST" action="">
        <input type="hidden" name="locker_id" value="<?php echo $locker_id; ?>">
        <label for="swapped_by">Swapped By:</label>
        <input type="text" id="swapped_by" name="swapped_by" value="<?php echo isset($_COOKIE['prevName']) ? htmlspecialchars($_COOKIE['prevName']) : ''; ?>" required>
        
        <label for="notes">Notes:</label>
        <textarea id="notes" name="notes"><?php echo htmlspecialchars($last_notes); ?></textarea>
        
        <label for="swapped_items">Items:</label>
        <?php
        // Fetch items for the locker
        $items_query = $db->prepare('SELECT id, name FROM items WHERE locker_id = :locker_id');
        $items_query->execute(['locker_id' => $locker_id]);
        $items = $items_query->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            $checked = in_array($item['id'], $last_swap_items) ? 'checked' : '';
            echo '<div>';
            echo '<input type="checkbox" id="item_' . $item['id'] . '" name="swapped_items[]" value="' . $item['id'] . '" ' . $checked . '>';
            echo '<label for="item_' . $item['id'] . '">' . htmlspecialchars($item['name']) . '</label>';
            echo '</div>';
        }
        ?>
        
        <button type="submit" name="swap_items">Submit</button>
    </form>
</body>
</html>