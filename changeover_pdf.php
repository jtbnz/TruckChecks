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
//$pdf->SetMargins(7.375, 26, 7.375);
$pdf->SetAutoPageBreak(TRUE, 26);



// Set header and footer fonts
//$pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
//$pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));

// Set default monospaced font
$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

// Set margins
$pdf->SetMargins(2,2,2,true);
//$pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
//$pdf->SetFooterMargin(PDF_MARGIN_FOOTER);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, 2);

// Set image scale factor
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

// Add a page
$pdf->AddPage();

// Set font
$pdf->SetFont('helvetica', '', 12);


// Fetch and display data
$html = '';



    if ($selected_truck_id) {

        $html .= '<table style="width: 100%;">';
        $html .= '    <TR>';
        $html .= '        <TD style="width: 25%;" >';
        $html .= '            <h1>Truck Change Over</h1>';
        $html .= '            Date:________________';
        $html .= '        </TD>';
        $html .= '        <TD style="width: 5%;" ></TD>';
        $html .= '        <TD style="width: 70%;border=1;" >';
        $html .= '            <h2>Notes:</h2>';
        $html .= '            <p>1. Officer keys.</p>';
        $html .= '            <p>2. Station Remotes - keys.</p>';
        $html .= '        </TD>';
        $html .= '    </TR>';
        $html .= '</table>';


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
        $rowcount = 1;
        $pagecount =1;
        $html .=  '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%;">';
        $html .=  "<tbody>";
        

        
        foreach ($results as $row) {

            if ($prev_locker != $row['locker_name']) {
                $locker_total++;

                if ($locker_count == 2 && $locker_total > 1) {
                    $html .=   '<td style="background-color: ' . $cellbgcolour . '"' . "></td>";
                    $html .=   '<td style="background-color: ' . $cellbgcolour . '"' . "></td>";
                    $html .=   '<td style="background-color: ' . $cellbgcolour . '"' . "></td>";
                    $html .=  "</tr>";     
                    $locker_count = 1;     
                }
                $rowcount++;
                $html .=  '<tr style="background-color: #A9A9A9">' ;
                $html .=  '<td style="width:30%">';
                $html .= $row['locker_name'];
                $html .= "</td>";
                $html .=  '<td style="width:10%">';
                $html .= "Relief</td>";
                $html .=  '<td style="width:10%">';
                $html .= $row['truck_name'] ;
                $html .=  '</td><td style="width:30%">';
                $html .= $row['locker_name'];
                $html .= "</td>";
                $html .=  '<td style="width:10%">';
                $html .= "Relief</td>";
                $html .=  '<td style="width:10%">';
                $html .= $row['truck_name'] ;
                $html .=  '</td></tr>';

                
                if ($locker_total % 2 == 0) {
                    $cellbgcolour = "#ffffff";
                 } else {
                     $cellbgcolour = "#f0f0f0";
                 }
            }
            if ($locker_count == 1) {
                        $html .=  '<tr>'; 
                        $rowcount++;
            }
            $html .=   '<td style="background-color: ' . $cellbgcolour . '">' . htmlspecialchars($row['item_name']) . "</td>";
            $html .=   '<td style="background-color: ' . $cellbgcolour . '"' . "></td>";
            $html .=   '<td style="background-color: ' . $cellbgcolour . '"' . "></td>";


            if ($locker_count == 2) {
                $html .=  "</tr>";
                
                $locker_count = 0;
                // chuck in a couple of blank rows
                if ($rowcount = 30) {

                    $html .=   '<tr><td style="background-color: ' . $cellbgcolour . '"' . "></td>";
                    $html .=   '<td style="background-color: ' . $cellbgcolour . '"' . "></td>";
                    $html .=   '<td style="background-color: ' . $cellbgcolour . '"' . "></td>";
                    $html .=   '<td style="background-color: ' . $cellbgcolour . '"' . "></td>";
                    $html .=   '<td style="background-color: ' . $cellbgcolour . '"' . "></td>";
                    $html .=   '<td style="background-color: ' . $cellbgcolour . '"' . "></td></tr>";
    
                }
            }


            $prev_locker = $row['locker_name'];
            $locker_count++;
        }

        $html .=  "</tbody></table>";


} else {
    $html .= '<p>Please select a truck to view its lockers and items.</p>';
}

// Output the HTML content
$pdf->writeHTML($html, true, false, false, false, '');


// Close and output PDF document
$pdf->Output('truck_changeover.pdf', 'I');


?>