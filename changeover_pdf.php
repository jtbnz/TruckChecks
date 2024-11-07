<?php
require_once('vendor/autoload.php');
use TCPDF;

include 'db.php'; 

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



// Set document information
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, 'A4', true, 'UTF-8', false);
$pdf->setPrintHeader(false);
$pdf->SetMargins(7.375, 26, 7.375);
$pdf->SetAutoPageBreak(TRUE, 26);



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
$html = '';



    if ($selected_truck_id) {

        $truck_id = $selected_truck_id; 

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

        $html .=  "<table border='1' cellpadding='5' cellspacing='0' style='width: 100%;'>";
        $html .=  "<tbody>";
        
        $pdf->writeHTML($html, true, false, true, false, '');
        $html = '';
        
        foreach ($results as $row) {

            if ($prev_locker != $row['locker_name']) {
                $locker_total++;
  
                if ($locker_count == 2 && $locker_total > 1) {
                    $html .=   '<td style="background-color: ' . $cellbgcolour . '"' . "></td>";
                    $html .=   '<td style="background-color: ' . $cellbgcolour . '"' . "></td>";
                    $html .=   '<td style="background-color: ' . $cellbgcolour . '"' . "></td>";
                    $html .=  "</TR>";     
                    $locker_count = 1;     
                    $pdf->writeHTML($html, true, false, true, false, '');
                    $html = '';
                }
                $html .=  '<tr>' ;
                $html .=  "<td>";
                $html .= $row['locker_name'];
                $html .= "</td>";
                $html .= "<td>Relief</td><td>"; 
                $html .= $truck['name'] ;
                $html .=  "</td><td><strong>";
                $html .= $row['locker_name'];
                $html .= "</strong></td><td>Relief</td><td>";
                $html .= $truck['name'] ;
                $html .=  "</td><TR>";
                $html=utf8_encode($html);
                $pdf->writeHTML($html, true, false, true, false, '');
                $html = '';
                
                if ($locker_total % 2 == 0) {
                    $cellbgcolour = "#ffffff";
                 } else {
                     $cellbgcolour = "#f0f0f0";
                 }
            }




            if ($locker_count == 1) {
                        $html .=  '<tr>';    
                        $pdf->writeHTML($html, true, false, true, false, '');
                        $html = '';      
                
            }
            
            $html .=   '<td style="background-color: ' . $cellbgcolour . '">' . htmlspecialchars($row['item_name']) . "</td>";
            $html .=   '<td style="background-color: ' . $cellbgcolour . '"' . "></td>";
            $html .=   '<td style="background-color: ' . $cellbgcolour . '"' . "></td>";
            $pdf->writeHTML($html, true, false, true, false, '');
            $html = '';

            if ($locker_count == 2) {
                $html .=  "</tr>";
                $locker_count = 0;
                $pdf->writeHTML($html, true, false, true, false, '');
                $html = '';
                
            }
            
            $prev_locker = $row['locker_name'];

            $locker_count++;
            $pdf->writeHTML($html, true, false, true, false, '');
            $html = '';
        }

        $html .=  "<tbody></table>";
        $pdf->writeHTML($html, true, false, true, false, '');
        $html = '';

} else {
    $html .= '<p>Please select a truck to view its lockers and items.</p>';
}

// Output the HTML content
$pdf->writeHTML($html, true, false, true, false, '');

// Close and output PDF document
$pdf->Output('truck_changeover.pdf', 'I');


?>