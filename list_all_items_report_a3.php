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

// Group items by locker
$lockers = [];
foreach ($results as $row) {
    if (!isset($lockers[$row['locker_id']])) {
        $lockers[$row['locker_id']] = [
            'name' => $row['locker_name'],
            'items' => []
        ];
    }
    
    if (!empty($row['item_name'])) {
        $lockers[$row['locker_id']]['items'][] = $row['item_name'];
    }
}

// Set up the layout for two columns
$pdf->SetFont('helvetica', '', 12);

// Calculate dimensions for the layout
$page_width = $pdf->getPageWidth() - 10; // Total usable width (A3 width minus margins)
$column_width = $page_width / 2 - 3; // Width for each column, with less spacing
$max_height = $pdf->getPageHeight() - 20; // Maximum height for content

// Variables to track position
$current_x = 10;
$current_y = $pdf->GetY();
$column_start_y = $current_y;
$max_y_in_row = $current_y;
$locker_count = 0;
$column = 0;

// Loop through each locker and create a box
foreach ($lockers as $locker) {
    // Calculate if we need to move to next column or page
    if ($locker_count > 0 && $locker_count % 2 == 0) {
        // Reset to left column but on a new row
        $current_x = 5;
        $current_y = $max_y_in_row + 5; // Reduced spacing between rows from 10 to 5
        $column = 0;
        
        // Check if we need a new page
        if ($current_y + 50 > $max_height) { // 50 is a minimum box height estimate
            $pdf->AddPage();
            $current_y = 10;
            $max_y_in_row = $current_y;
        }
    }
    
    // Set position for this locker box
    $pdf->SetXY($current_x, $current_y);
    
    // Start capturing content for this locker - removed the div wrapper to eliminate extra line
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
    // Removed closing div tag
    
    // Create a cell with border for the locker box
    $pdf->MultiCell($column_width, 0, $locker_content, 1, 'L', false, 1, $current_x, $current_y, true, 0, true);
    
    // Update position tracking
    $end_y = $pdf->GetY();
    $max_y_in_row = max($max_y_in_row, $end_y);
    
    // Move to next column
    $column++;
    if ($column < 2) {
        $current_x = $current_x + $column_width + 6; // Reduced spacing between columns from 10 to 6
    }
    
    $locker_count++;
}

// Output the PDF
$pdf->Output('locker_items_report_a3.pdf', 'I');
?>
