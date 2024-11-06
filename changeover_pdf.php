<?php
require_once('vendor/autoload.php');

use TCPDF;
include 'db.php';
$db = get_db_connection();

$truck_id = $_GET['truck_id'];

// Fetch lockers and items for the selected truck
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

$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->SetMargins(10, 10, 10);
$pdf->AddPage();

$html = '<h1>Truck: ' . htmlspecialchars($results[0]['truck_name']) . '</h1>';
$html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%;">';
$html .= '<tr><th>Locker</th><th>Item</th><th>Relief</th><th>Stays</th><th>Locker</th><th>Item</th><th>Relief</th><th>Stays</th></tr>';

$current_locker = '';
$locker_count = 0;

foreach ($results as $row) {
    if ($current_locker != $row['locker_name']) {
        if ($current_locker != '') {
            $html .= '</table>';
            $locker_count++;
            if ($locker_count % 2 == 0) {
                $html .= '<tr></tr>';
            }
            $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%;">';
        }
        $current_locker = $row['locker_name'];
        $html .= '<tr><td colspan="4"><strong>Locker: ' . htmlspecialchars($current_locker) . '</strong></td></tr>';
    }
    $html .= '<tr>';
    $html .= '<td>' . htmlspecialchars($row['locker_name']) . '</td>';
    $html .= '<td>' . htmlspecialchars($row['item_name']) . '</td>';
    $html .= '<td></td>';
    $html .= '<td></td>';
    $html .= '</tr>';
}
if ($current_locker != '') {
    $html .= '</table>';
}
$html .= '</table>';

$pdf->writeHTML($html, true, false, true, false, '');

$pdf->Output('truck_lockers.pdf', 'I');
?>