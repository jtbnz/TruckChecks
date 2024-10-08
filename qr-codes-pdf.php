<?php
require_once('vendor/autoload.php');

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;


use TCPDF;

include 'db.php'; 
$db = get_db_connection();

ob_end_clean();

$lockers = $db->query('select l.name as locker_name,l.id as locker_id,t.name as truck_name,l.truck_id from lockers l JOIN trucks t on l.truck_id= t.id order by t.id')->fetchAll(PDO::FETCH_ASSOC);
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




foreach ($lockers as $index => $locker) {
    if ($index != 0 && $index % ($labelsPerRow * $labelsPerColumn) == 0) {
        $pdf->AddPage();

    }

    $row = floor(($index % ($labelsPerRow * $labelsPerColumn)) / $labelsPerRow);
    $col = $index % $labelsPerRow;

    $x = $pdf->getMargins()['left'] + $col * ($qrCodeSize + $gap);
    $y = $pdf->getMargins()['top'] + $row * ($qrCodeSize + $gap);

    $locker_url = 'https://' . $_SERVER['HTTP_HOST'] . $current_directory . '/check_locker_items.php?truck_id=' . $locker['truck_id'] . '&locker_id=' . $locker['locker_id'] ;

    $qrCode = QrCode::create($locker_url)
        ->setSize($qrCodeSizeInPixels)
        ->setMargin(0);

    //$pdf->SetFont('helvetica', '', 6);
    $pdf->SetFont('freemono', '', 6);
    $pdf->Text($x + 6, $y - 3, $locker['truck_name'] . ' ' . $locker['locker_name'] );
    $pdf->Image('@' . $writer->write($qrCode)->getString(), $x, $y, $qrCodeSize, $qrCodeSize, 'PNG');
    //echo "Index: $index Row: $row, Col: $col, x: $x , y: $y " . $locker['truck_name'] . ' ' . $locker['locker_name'] . "<br>";   
   
}

$pdf->Output('qrcodes.pdf', 'I');
?>