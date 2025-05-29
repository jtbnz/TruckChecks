<?php
require_once('vendor/autoload.php');

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use TCPDF;

include 'db.php';
include_once('auth.php');

// Require authentication and station context
$station = requireStation();
$user = getCurrentUser();

$db = get_db_connection();

ob_end_clean();

// Fetch lockers filtered by current station
$lockers_query = $db->prepare('
    SELECT l.name as locker_name, l.id as locker_id, t.name as truck_name, l.truck_id 
    FROM lockers l 
    JOIN trucks t ON l.truck_id = t.id 
    WHERE t.station_id = :station_id
    ORDER BY t.name, l.name
');
$lockers_query->execute(['station_id' => $station['id']]);
$lockers = $lockers_query->fetchAll(PDO::FETCH_ASSOC);

$current_directory = dirname($_SERVER['REQUEST_URI']);

$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, 'A4', true, 'UTF-8', false);
$pdf->setPrintHeader(false);
$pdf->SetMargins(7.375, 26, 7.375);
$pdf->SetAutoPageBreak(TRUE, 26);

$qrCodeSize = 45; // in mm
$qrCodeSizeInPixels = $qrCodeSize * 3.779;  // 1mm is approximately 3.779 pixels
$gap = 5.08; // in mm
$labelsPerRow = 4;
$labelsPerColumn = 5;

$writer = new PngWriter();
$pdf->SetAutoPageBreak(false, 26);
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
$pdf->AddPage();

// Add station header to first page
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Text(10, 10, 'QR Codes for Station: ' . $station['name']);
$pdf->SetFont('helvetica', '', 8);
if ($station['description']) {
    $pdf->Text(10, 16, $station['description']);
}

// Check if there are any lockers for this station
if (empty($lockers)) {
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Text(10, 50, 'No lockers found for this station.');
    $pdf->Text(10, 65, 'Please add trucks and lockers in the admin panel.');
    $pdf->Output('qrcodes_' . preg_replace('/[^a-zA-Z0-9]/', '_', $station['name']) . '.pdf', 'I');
    exit;
}

// Generate Security QR Code first
$security_code = getStationSetting('security_code', '');
$all_items = [];

if (!empty($security_code)) {
    $security_url = 'https://' . $_SERVER['HTTP_HOST'] . $current_directory . '/set_security_cookie.php?code=' . urlencode($security_code) . '&station_id=' . $station['id'] . '&station_name=' . urlencode($station['name']);
    $all_items[] = [
        'type' => 'security',
        'label' => 'SECURITY',
        'url' => $security_url
    ];
}

// Add all lockers to the items array
foreach ($lockers as $locker) {

    $all_items[] = [
        'type' => 'locker',
        'label' => $locker['truck_name'] . ' ' . $locker['locker_name'],
        'url' => 'https://' . $_SERVER['HTTP_HOST'] . $current_directory . '/check_locker_items.php?truck_id=' . $locker['truck_id'] . '&locker_id=' . $locker['locker_id']
    ];
}

foreach ($all_items as $index => $item) {
    if ($index != 0 && $index % ($labelsPerRow * $labelsPerColumn) == 0) {
        $pdf->AddPage();
        
        // Add station header to each new page
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Text(10, 10, 'QR Codes for Station: ' . $station['name']);
        $pdf->SetFont('helvetica', '', 8);
        if ($station['description']) {
            $pdf->Text(10, 16, $station['description']);
        }
    }

    $row = floor(($index % ($labelsPerRow * $labelsPerColumn)) / $labelsPerRow);
    $col = $index % $labelsPerRow;

    $x = $pdf->getMargins()['left'] + $col * ($qrCodeSize + $gap);
    $y = $pdf->getMargins()['top'] + $row * ($qrCodeSize + $gap);

    $locker_url = 'https://' . $_SERVER['HTTP_HOST'] . $current_directory . '/check_locker_items.php?truck_id=' . $locker['truck_id'] . '&locker_id=' . $locker['locker_id'];

    $qrCode = QrCode::create($item['url'])
        ->setSize($qrCodeSizeInPixels)
        ->setMargin(0);

    $pdf->SetFont('freemono', '', 6);
    // Special styling for security QR code
    if ($item['type'] === 'security') {
        $pdf->SetTextColor(220, 53, 69); // Red color for security
        $pdf->SetFont('freemono', 'B', 7); // Bold and slightly larger
    } else {
        $pdf->SetTextColor(0, 0, 0); // Black color for regular items
        $pdf->SetFont('freemono', '', 6);
    }
    
    $pdf->Text($x + 6, $y - 3, $item['label']);
    
    // Reset text color
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Image('@' . $writer->write($qrCode)->getString(), $x, $y, $qrCodeSize, $qrCodeSize, 'PNG');
}

// Generate filename with station name
$filename = 'qrcodes_' . preg_replace('/[^a-zA-Z0-9]/', '_', $station['name']) . '.pdf';
$pdf->Output($filename, 'I');
?>
