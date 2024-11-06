<?php
require_once('vendor/autoload.php');
require_once('db.php');

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

// Create new PDF document
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('Your Name');
$pdf->SetTitle('Truck Change Over');
$pdf->SetSubject('Truck Change Over');
$pdf->SetKeywords('TCPDF, PDF, example, test, guide');

// Set default header data
$pdf->SetHeaderData(PDF_HEADER_LOGO, PDF_HEADER_LOGO_WIDTH, PDF_HEADER_TITLE, PDF_HEADER_STRING);

// Set header and footer fonts
$pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
$pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));

// Set default monospaced font
$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

// Set margins
$pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
$pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
$pdf->SetFooterMargin(PDF_MARGIN_FOOTER);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

// Set image scale factor
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

// Add a page
$pdf->AddPage();

// Set font
$pdf->SetFont('helvetica', '', 12);

// Title
$pdf->Cell(0, 10, 'Truck Change Over', 0, 1, 'C');

// Fetch and display data
$html = '<h2>Truck Change Over</h2>';

if ($selected_truck_id) {
    $html .= '<h3>Truck: ' . htmlspecialchars($trucks[array_search($selected_truck_id, array_column($trucks, 'id'))]['name']) . '</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%;">';
    $html .= '<tr><th>Locker</th><th>Item</th><th>Relief</th><th>Stays</th></tr>';

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
    $query->execute(['truck_id' => $selected_truck_id]);
    $results = $query->fetchAll(PDO::FETCH_ASSOC);

    $current_locker = '';
    foreach ($results as $row) {
        if ($current_locker != $row['locker_name']) {
            if ($current_locker != '') {
                $html .= '</table>';
            }
            $current_locker = $row['locker_name'];
            $html .= '<tr><td colspan="4"><strong>Locker: ' . htmlspecialchars($current_locker) . '</strong></td></tr>';
        }
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($row['locker_name']) . '</td>';
        $html .= '<td>' . htmlspecialchars($row['item_name']) . '</td>';
        $html .= '<td><input type="checkbox" disabled></td>';
        $html .= '<td><input type="checkbox" disabled></td>';
        $html .= '</tr>';
    }
    if ($current_locker != '') {
        $html .= '</table>';
    }
} else {
    $html .= '<p>Please select a truck to view its lockers and items.</p>';
}

// Output the HTML content
//$pdf->writeHTML($html, true, false, true, false, '');

// Close and output PDF document
//$pdf->Output('truck_changeover.pdf', 'I');
?>