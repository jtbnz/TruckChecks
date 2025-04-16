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

// Group items by locker and calculate item count - completely rewritten to ensure no duplicates
$lockers = [];
$processed_locker_ids = []; // Track which lockers we've already processed

// First, get all unique lockers for this truck
$lockers_query = $db->prepare("
    SELECT 
        id,
        name
    FROM 
        lockers
    WHERE 
        truck_id = :truck_id
    ORDER BY 
        name
");
$lockers_query->execute(['truck_id' => $selected_truck_id]);
$all_lockers = $lockers_query->fetchAll(PDO::FETCH_ASSOC);

// Process each locker
foreach ($all_lockers as $locker) {
    // Get all items for this locker
    $items_query = $db->prepare("
        SELECT 
            name
        FROM 
            items
        WHERE 
            locker_id = :locker_id
        ORDER BY 
            name
    ");
    $items_query->execute(['locker_id' => $locker['id']]);
    $items = $items_query->fetchAll(PDO::FETCH_COLUMN);
    
    // Add this locker to our array
    $lockers[] = [
        'id' => $locker['id'],
        'name' => $locker['name'],
        'items' => $items,
        'item_count' => count($items)
    ];
}

// Sort lockers by item count (descending)
usort($lockers, function($a, $b) {
    return $b['item_count'] - $a['item_count'];
});

// Debug: Count lockers
$locker_count = count($lockers);
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(0, 5, "Total Lockers: $locker_count", 0, 1, 'R');
$pdf->Ln(2);

// Set up the layout for two columns
$pdf->SetFont('helvetica', '', 12);

// Calculate dimensions for the layout
$page_width = $pdf->getPageWidth() - 10; // Total usable width (A3 width minus margins)
$column_width = $page_width / 3 - 4; // Width for each column, with spacing between columns
$max_height = $pdf->getPageHeight() - 20; // Maximum height for content

// Advanced layout algorithm with better space utilization
$page_top_margin = 5;
$spacing_between_lockers = 5;
$spacing_between_columns = 5; // Spacing between columns

// Calculate column positions
$column1_x = 5; // Left column
$column2_x = $column1_x + $column_width + $spacing_between_columns; // Middle column
$column3_x = $column2_x + $column_width + $spacing_between_columns; // Right column

// Track available space in all three columns
$columns = [
    0 => ['x' => $column1_x, 'y' => $start_y_after_title, 'page' => 1],
    1 => ['x' => $column2_x, 'y' => $start_y_after_title, 'page' => 1],
    2 => ['x' => $column3_x, 'y' => $start_y_after_title, 'page' => 1]
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

// Completely redesigned layout algorithm to flow lockers across columns (horizontally)
// rather than down columns (vertically)

// Calculate column positions
$column_positions = [
    $column1_x,
    $column2_x,
    $column3_x
];

// Initialize the current row position
$current_row_y = $start_y_after_title;
$current_page = 1;
$pdf->setPage($current_page);

// Group lockers into rows of 3 (or fewer for the last row)
$locker_rows = array_chunk($lockers, 3);

// Completely different approach using HTML tables instead of MultiCell

// Start with a clean page
$pdf->AddPage();

// Create an HTML table for the entire layout
$html = '<table border="0" cellspacing="5" cellpadding="0" style="width:100%;">';

// Process lockers in groups of 3 (for 3 columns)
for ($i = 0; $i < count($lockers); $i += 3) {
    $html .= '<tr>';
    
    // Process up to 3 lockers for this row
    for ($j = 0; $j < 3; $j++) {
        $index = $i + $j;
        
        // Check if we have a locker at this index
        if ($index < count($lockers)) {
            $locker = $lockers[$index];
            
            $html .= '<td style="width:33%; vertical-align:top;">';
            $html .= '<div style="border:1px solid #000; padding:0; margin:0;">';
            $html .= '<h3 style="background-color:#f0f0f0; padding:5px; margin:0;">' . 
                     htmlspecialchars($locker['name']) . ' (ID: ' . $locker['id'] . ')</h3>';
            
            $html .= '<table border="0" cellpadding="3" style="width:100%;">';
            
            // Add items or a message if no items
            if (empty($locker['items'])) {
                $html .= '<tr><td style="text-align:center;"><i>No items in this locker</i></td></tr>';
            } else {
                foreach ($locker['items'] as $item) {
                    $html .= '<tr><td>' . htmlspecialchars($item) . '</td></tr>';
                }
            }
            
            $html .= '</table>';
            $html .= '</div>';
            $html .= '</td>';
        } else {
            // Empty cell for padding
            $html .= '<td style="width:33%;"></td>';
        }
    }
    
    $html .= '</tr>';
    
    // Add some spacing between rows
    $html .= '<tr><td colspan="3" style="height:5px;"></td></tr>';
}

$html .= '</table>';

// Write the HTML to the PDF
$pdf->writeHTML($html, true, false, true, false, '');

// Let's try a different approach to ensure no duplicates
// Output the PDF with a timestamp to prevent caching issues
$pdf->Output('locker_items_report_a3.pdf?t=' . time(), 'I');
?>
