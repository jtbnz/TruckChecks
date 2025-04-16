<?php
require_once('vendor/autoload.php');
use TCPDF;

include('config.php');
include 'db.php'; 

$db = get_db_connection();

// Clear output buffer to prevent issues with PDF generation
ob_end_clean();

// Fetch all trucks for the dropdown selection
$trucks_query = $db->query('SELECT * FROM trucks ORDER BY name');
$trucks = $trucks_query->fetchAll(PDO::FETCH_ASSOC);

// Check if a truck has been selected
$selected_truck_id = isset($_GET['truck_id']) ? $_GET['truck_id'] : null;

// If no truck is selected, display the selection form
if (!$selected_truck_id) {
    include 'templates/header.php';
    ?>
    <link rel="stylesheet" href="styles/reports.css">
    <div class="container">
        <h1>Locker Items Report (A3)</h1>
        <p>This report will generate an A3-sized PDF showing all items in each locker for the selected truck.</p>
        <form method="get" action="list_all_items_report_a3.php">
            <div class="form-group" style="margin-bottom: 20px;">
                <label for="truck_id" style="display: block; margin-bottom: 5px; font-weight: bold;">Select Truck:</label>
                <select name="truck_id" id="truck_id" style="padding: 8px; width: 300px; border-radius: 4px; border: 1px solid #ccc;">
                    <option value="">-- Select a Truck --</option>
                    <?php foreach ($trucks as $truck): ?>
                        <option value="<?php echo $truck['id']; ?>"><?php echo htmlspecialchars($truck['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit">Generate Report</button>
        </form>
    </div>
    <?php
    include 'templates/footer.php';
    exit;
}

// If a truck is selected, generate the PDF
// Set document information
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, 'A3', true, 'UTF-8', false);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetAutoPageBreak(TRUE, 10);

// Set default monospaced font
$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

// Set margins - reduced from 10 to 5
$pdf->SetMargins(5, 5, 5, true);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, 5);

// Set image scale factor
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

// Add a page
$pdf->AddPage();

// Set font
$pdf->SetFont('helvetica', 'B', 16);

// Get truck name
$truck_query = $db->prepare("SELECT name FROM trucks WHERE id = :truck_id");
$truck_query->execute(['truck_id' => $selected_truck_id]);
$truck_name = $truck_query->fetchColumn();

// Add title
$pdf->Cell(0, 10, 'Locker Items Report - ' . $truck_name, 0, 1, 'C');
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(0, 5, 'Generated on: ' . date('Y-m-d H:i:s'), 0, 1, 'C');
$pdf->Ln(5);

// Store the Y position after the title for use in column positioning
$start_y_after_title = $pdf->GetY();

// Query to retrieve all lockers and items for the selected truck
$query = $db->prepare("
    SELECT 
        l.id as locker_id,
        l.name as locker_name,
        i.name as item_name
    FROM 
        lockers l
    LEFT JOIN 
        items i ON l.id = i.locker_id
    WHERE 
        l.truck_id = :truck_id
    ORDER BY 
        l.name, i.name
");
$query->execute(['truck_id' => $selected_truck_id]);
$results = $query->fetchAll(PDO::FETCH_ASSOC);

// Group items by locker and calculate item count
$lockers = [];
foreach ($results as $row) {
    if (!isset($lockers[$row['locker_id']])) {
        $lockers[$row['locker_id']] = [
            'name' => $row['locker_name'],
            'items' => [],
            'item_count' => 0
        ];
    }
    
    if (!empty($row['item_name'])) {
        $lockers[$row['locker_id']]['items'][] = $row['item_name'];
        $lockers[$row['locker_id']]['item_count']++;
    }
}

// Sort lockers by item count (descending) to optimize layout
// This helps balance columns and minimize page count
usort($lockers, function($a, $b) {
    return $b['item_count'] - $a['item_count'];
});

// Set up the layout for two columns
$pdf->SetFont('helvetica', '', 12);

// Calculate dimensions for the layout
$page_width = $pdf->getPageWidth() - 10; // Total usable width (A3 width minus margins)
$column_width = $page_width / 2 - 3; // Width for each column, with less spacing
$max_height = $pdf->getPageHeight() - 20; // Maximum height for content

// Advanced layout algorithm with better space utilization
$page_top_margin = 5;
$spacing_between_lockers = 5;
$left_column_x = 5;
$right_column_x = $left_column_x + $column_width + 6; // 6mm spacing between columns

// Track available space in both columns
$columns = [
    0 => ['x' => $left_column_x, 'y' => $start_y_after_title, 'page' => 1],
    1 => ['x' => $right_column_x, 'y' => $start_y_after_title, 'page' => 1]
];
$current_column = 0;

// Pre-calculate estimated heights for all lockers
foreach ($lockers as &$locker) {
    $locker['estimated_height'] = 30; // Base height for header
    if (!empty($locker['items'])) {
        $locker['estimated_height'] += count($locker['items']) * 10; // Estimate 10mm per item
    } else {
        $locker['estimated_height'] += 10; // Height for "No items" message
    }
}

// Function to find the best column for a locker
function findBestColumn($columns, $locker_height, $max_height, $pdf, $start_y_after_title) {
    $best_fit = -1;
    $min_waste = PHP_INT_MAX;
    
    foreach ($columns as $idx => $col) {
        // Calculate remaining space in this column
        $remaining_space = $max_height - $col['y'];
        
        // If locker fits in this column
        if ($remaining_space >= $locker_height) {
            $waste = $remaining_space - $locker_height;
            // Choose column with least waste
            if ($waste < $min_waste) {
                $min_waste = $waste;
                $best_fit = $idx;
            }
        }
    }
    
    return $best_fit;
}

// Process each locker
foreach ($lockers as $locker) {
    // Find the best column for this locker
    $best_column = findBestColumn($columns, $locker['estimated_height'], $max_height, $pdf, $start_y_after_title);
    
    // If no column has enough space, create a new page
    if ($best_column == -1) {
        $pdf->AddPage();
        $columns[0]['y'] = $page_top_margin;
        $columns[0]['page'] = $pdf->getPage();
        $columns[1]['y'] = $page_top_margin;
        $columns[1]['page'] = $pdf->getPage();
        $best_column = 0; // Start with left column on new page
    }
    
    // Set position for this locker box
    $current_x = $columns[$best_column]['x'];
    $current_y = $columns[$best_column]['y'];
    
    // If we're on the first page and in the right column, ensure we're below the title
    if ($columns[$best_column]['page'] == 1 && $best_column == 1 && $current_y < $start_y_after_title) {
        $current_y = $start_y_after_title;
        $columns[$best_column]['y'] = $current_y;
    }
    
    $pdf->setPage($columns[$best_column]['page']);
    $pdf->SetXY($current_x, $current_y);
    
    // Start capturing content for this locker
    $locker_content = '<h3 style="background-color:#f0f0f0; padding:5px; margin:0;">' . htmlspecialchars($locker['name']) . '</h3>';
    $locker_content .= '<table border="0" cellpadding="3" style="width:100%;">';
    
    // Add items or a message if no items
    if (empty($locker['items'])) {
        $locker_content .= '<tr><td style="text-align:center;"><i>No items in this locker</i></td></tr>';
    } else {
        foreach ($locker['items'] as $item) {
            $locker_content .= '<tr><td>' . htmlspecialchars($item) . '</td></tr>';
        }
    }
    
    $locker_content .= '</table>';
    
    // Create a cell with border for the locker box
    $pdf->MultiCell($column_width, 0, $locker_content, 1, 'L', false, 1, $current_x, $current_y, true, 0, true);
    
    // Update position for next locker in this column
    $columns[$best_column]['y'] = $pdf->GetY() + $spacing_between_lockers;
}

// Output the PDF
$pdf->Output('locker_items_report_a3.pdf', 'I');
?>
