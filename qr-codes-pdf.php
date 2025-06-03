<?php
// Ensure config.php is loaded first for any global settings or error reporting.
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

require_once('vendor/autoload.php');

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;
use TCPDF;

// These need to be included after vendor/autoload to ensure classes are available.
include_once 'db.php'; // For get_db_connection()
include_once 'auth.php'; // For requireAuth(), getCurrentUser()

// Require user to be authenticated
requireAuth();
$user = getCurrentUser(); // Get current user details, though not directly used in PDF content, good for auth.

// Get station_id from URL
$station_id_from_url = $_GET['station_id'] ?? null;

if (!$station_id_from_url) {
    // Handle error: No station ID provided
    // You might want to output a simple HTML error page or a basic PDF error.
    header("HTTP/1.1 400 Bad Request");
    echo "Error: Station ID is required.";
    exit;
}

$pdo = get_db_connection();

// Fetch station details based on station_id_from_url
try {
    $stmt_station = $pdo->prepare("SELECT * FROM stations WHERE id = :station_id");
    $stmt_station->execute([':station_id' => $station_id_from_url]);
    $station = $stmt_station->fetch(PDO::FETCH_ASSOC);

    if (!$station) {
        header("HTTP/1.1 404 Not Found");
        echo "Error: Station not found.";
        exit;
    }
} catch (Exception $e) {
    error_log("Error fetching station for PDF: " . $e->getMessage());
    header("HTTP/1.1 500 Internal Server Error");
    echo "Error fetching station details.";
    exit;
}

// Function to get a specific station setting (similar to one in qr-codes.php)
function get_station_setting_for_pdf($pdo_conn, $setting_key, $station_id_for_setting, $default_value = null) {
    try {
        $stmt = $pdo_conn->prepare('SELECT setting_value FROM station_settings WHERE station_id = :station_id AND setting_key = :setting_key');
        $stmt->execute([':station_id' => $station_id_for_setting, ':setting_key' => $setting_key]);
        $value = $stmt->fetchColumn();
        return ($value !== false) ? $value : $default_value;
    } catch (Exception $e) {
        error_log("Error fetching setting '$setting_key' for station '$station_id_for_setting' in PDF: " . $e->getMessage());
        return $default_value;
    }
}


// Fetch lockers filtered by the specific station
$lockers_query = $pdo->prepare('
    SELECT l.name as locker_name, l.id as locker_id, t.name as truck_name, t.id as truck_id 
    FROM lockers l 
    JOIN trucks t ON l.truck_id = t.id 
    WHERE t.station_id = :station_id
    ORDER BY t.name, l.name
');
$lockers_query->execute(['station_id' => $station['id']]);
$lockers = $lockers_query->fetchAll(PDO::FETCH_ASSOC);

// Base URL construction
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$web_root_url = $protocol . $host; // Assuming target scripts are at web root

// Clean output buffer before PDF generation
if (ob_get_level()) {
    ob_end_clean();
}

$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, 'A4', true, 'UTF-8', false);
$pdf->setPrintHeader(false); // No default TCPDF header
$pdf->SetMargins(7.375, 26, 7.375); // Margins for Avery L7124 might need adjustment based on actual label sheet
$pdf->SetAutoPageBreak(false); // Manual page breaks based on label count

$qrCodeSizeMm = 45; // QR code physical size in mm on the label
$gapMm = 5.08;      // Gap between labels in mm

$writer = new PngWriter();

$pdf->AddPage();

// Custom Header for each page
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 10, 'QR Codes for Station: ' . $station['name'], 0, 1, 'L', 0, '', 0, false, 'T', 'M');
if (!empty($station['description'])) {
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(0, 5, $station['description'], 0, 1, 'L', 0, '', 0, false, 'T', 'M');
}
$pdf->Ln(5); // Space after header


if (empty($lockers)) {
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Ln(10);
    $pdf->Cell(0, 10, 'No lockers found for this station.', 0, 1, 'C');
    $pdf->Cell(0, 10, 'Please add trucks and lockers in the admin panel.', 0, 1, 'C');
    $pdf->Output('qrcodes_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $station['name']) . '.pdf', 'I');
    exit;
}

$all_qr_items = [];

// 1. Station Access QR Code
$station_access_url = $web_root_url . '/index.php?station_id=' . $station['id'];
$all_qr_items[] = [
    'type' => 'station_access',
    'label' => 'Access: ' . $station['name'],
    'url' => $station_access_url,
    'font_size' => 6,
    'font_style' => ''
];


// 2. Security QR Code (if configured)
$security_code = get_station_setting_for_pdf($pdo, 'security_code', $station['id'], '');
if (!empty($security_code)) {
    $security_url = $web_root_url . '/set_security_cookie.php?code=' . urlencode($security_code) . '&station_id=' . $station['id'] . '&station_name=' . urlencode($station['name']);
    $all_qr_items[] = [
        'type' => 'security',
        'label' => 'SECURITY - ' . $station['name'],
        'url' => $security_url,
        'font_size' => 7,
        'font_style' => 'B',
        'text_color' => [220, 53, 69] // Red
    ];
}

// 3. Locker QR Codes
foreach ($lockers as $locker) {
    $locker_check_url = $web_root_url . '/check_locker_items.php?truck_id=' . $locker['truck_id'] . '&locker_id=' . $locker['locker_id'] . '&station_id=' . $station['id'];
    $all_qr_items[] = [
        'type' => 'locker',
        'label' => $locker['truck_name'] . ' - ' . $locker['locker_name'],
        'url' => $locker_check_url,
        'font_size' => 6,
        'font_style' => ''
    ];
}

$labelsPerRow = 4;
$labelsPerColumn = 5; // Max labels per page vertically
$totalLabelsPerPage = $labelsPerRow * $labelsPerColumn;

$currentX = $pdf->GetX(); // Initial X, respecting left margin
$currentY = $pdf->GetY(); // Initial Y, after header

foreach ($all_qr_items as $index => $item_data) {
    if ($index > 0 && $index % $totalLabelsPerPage == 0) {
        $pdf->AddPage();
        // Custom Header for new page
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 10, 'QR Codes for Station: ' . $station['name'], 0, 1, 'L');
        if (!empty($station['description'])) {
            $pdf->SetFont('helvetica', '', 8);
            $pdf->Cell(0, 5, $station['description'], 0, 1, 'L');
        }
        $pdf->Ln(5);
        $currentY = $pdf->GetY(); // Reset Y for new page
    }

    // Calculate position for the current label
    $labelIndexOnPage = $index % $totalLabelsPerPage;
    $rowOnPage = floor($labelIndexOnPage / $labelsPerRow);
    $colOnPage = $labelIndexOnPage % $labelsPerRow;

    $xPos = $pdf->getMargins()['left'] + ($colOnPage * ($qrCodeSizeMm + $gapMm));
    $yPos = $currentY + ($rowOnPage * ($qrCodeSizeMm + $gapMm));


    // Generate QR Code
    $qrCodeInstance = Builder::create()
        ->writer(new PngWriter())
        ->writerOptions([])
        ->data($item_data['url'])
        ->encoding(new Encoding('UTF-8'))
        ->errorCorrectionLevel(new ErrorCorrectionLevelHigh())
        ->size(300) // Internal size for QR generation, actual display size is $qrCodeSizeMm
        ->margin(0) // No margin for the QR code image itself
        ->build();

    // Draw QR Code Image
    // The '@' tells TCPDF that the data is raw image data
    $pdf->Image('@' . $qrCodeInstance->getString(), $xPos, $yPos + 5, $qrCodeSizeMm, $qrCodeSizeMm, 'PNG');
    
    // Add Label Text above QR code
    $pdf->SetFont('freemono', $item_data['font_style'] ?? '', $item_data['font_size'] ?? 6);
    if (isset($item_data['text_color'])) {
        $pdf->SetTextColor($item_data['text_color'][0], $item_data['text_color'][1], $item_data['text_color'][2]);
    } else {
        $pdf->SetTextColor(0, 0, 0); // Default black
    }
    // Position text carefully above the QR code image
    $pdf->SetXY($xPos, $yPos); 
    $pdf->MultiCell($qrCodeSizeMm, 5, $item_data['label'], 0, 'C', 0, 1, '', '', true, 0, false, true, 5, 'T');

    // Reset text color for next item
    $pdf->SetTextColor(0, 0, 0);
}

$filename_safe_station_name = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $station['name']);
$pdf_filename = 'qrcodes_' . $filename_safe_station_name . '.pdf';
$pdf->Output($pdf_filename, 'I'); // 'I' for inline display in browser
exit;
?>
