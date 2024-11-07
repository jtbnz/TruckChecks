<?php
//============================================================+
// File name   : example_048.php
// Begin       : 2009-03-20
// Last Update : 2013-05-14
//
// Description : Example 048 for TCPDF class
//               HTML tables and table headers
//
// Author: Nicola Asuni
//
// (c) Copyright:
//               Nicola Asuni
//               Tecnick.com LTD
//               www.tecnick.com
//               info@tecnick.com
//============================================================+

/**
 * Creates an example PDF TEST document using TCPDF
 * @package com.tecnick.tcpdf
 * @abstract TCPDF - Example: HTML tables and table headers
 * @author Nicola Asuni
 * @since 2009-03-20
 */
require_once('vendor/autoload.php');
use TCPDF;

// create new PDF document
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// set document information
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('Nicola Asuni');
$pdf->SetTitle('TCPDF Example 048');
$pdf->SetSubject('TCPDF Tutorial');
$pdf->SetKeywords('TCPDF, PDF, example, test, guide');

// set default header data
$pdf->SetHeaderData(PDF_HEADER_LOGO, PDF_HEADER_LOGO_WIDTH, PDF_HEADER_TITLE.' 048', PDF_HEADER_STRING);

// set header and footer fonts
$pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
$pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));

// set default monospaced font
$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

// set margins
$pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
$pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
$pdf->SetFooterMargin(PDF_MARGIN_FOOTER);

// set auto page breaks
$pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

// set image scale factor
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

// set some language-dependent strings (optional)
if (@file_exists(dirname(__FILE__).'/lang/eng.php')) {
    require_once(dirname(__FILE__).'/lang/eng.php');
    $pdf->setLanguageArray($l);
}

// ---------------------------------------------------------

// set font
$pdf->SetFont('helvetica', 'B', 20);

// add a page
$pdf->AddPage();

$pdf->Write(0, 'Example of HTML tables', '', 0, 'L', true, 0, false, false, 0);

$pdf->SetFont('helvetica', '', 8);

// -----------------------------------------------------------------------------

$tbl = <<<EOD
<table cellspacing="0" cellpadding="1" border="1">
    <tr>
        <td rowspan="3">COL 1 - ROW 1<br />COLSPAN 3</td>
        <td>COL 2 - ROW 1</td>
        <td>COL 3 - ROW 1</td>
    </tr>
    <tr>
        <td rowspan="2">COL 2 - ROW 2 - COLSPAN 2<br />text line<br />text line<br />text line<br />text line</td>
        <td>COL 3 - ROW 2</td>
    </tr>
    <tr>
       <td>COL 3 - ROW 3</td>
    </tr>

</table>
EOD;

$pdf->writeHTML($tbl, true, false, false, false, '');

// -----------------------------------------------------------------------------

$tbl = <<<EOD
<table cellspacing="0" cellpadding="1" border="1">
    <tr>
        <td rowspan="3">COL 1 - ROW 1<br />COLSPAN 3<br />text line<br />text line<br />text line<br />text line<br />text line<br />text line</td>
        <td>COL 2 - ROW 1</td>
        <td>COL 3 - ROW 1</td>
    </tr>
    <tr>
        <td rowspan="2">COL 2 - ROW 2 - COLSPAN 2<br />text line<br />text line<br />text line<br />text line</td>
         <td>COL 3 - ROW 2</td>
    </tr>
    <tr>
       <td>COL 3 - ROW 3</td>
    </tr>

</table>
EOD;

$pdf->writeHTML($tbl, true, false, false, false, '');

// -----------------------------------------------------------------------------

$tbl = <<<EOD
<table cellspacing="0" cellpadding="1" border="1">
    <tr>
        <td rowspan="3">COL 1 - ROW 1<br />COLSPAN 3<br />text line<br />text line<br />text line<br />text line<br />text line<br />text line</td>
        <td>COL 2 - ROW 1</td>
        <td>COL 3 - ROW 1</td>
    </tr>
    <tr>
        <td rowspan="2">COL 2 - ROW 2 - COLSPAN 2<br />text line<br />text line<br />text line<br />text line</td>
         <td>COL 3 - ROW 2<br />text line<br />text line</td>
    </tr>
    <tr>
       <td>COL 3 - ROW 3</td>
    </tr>

</table>
EOD;

$pdf->writeHTML($tbl, true, false, false, false, '');

// -----------------------------------------------------------------------------

$tbl = <<<EOD
<table border="1">
<tr>
<th rowspan="3">Left column</th>
<th colspan="5">Heading Column Span 5</th>
<th colspan="9">Heading Column Span 9</th>
</tr>
<tr>
<th rowspan="2">Rowspan 2<br />This is some text that fills the table cell.</th>
<th colspan="2">span 2</th>
<th colspan="2">span 2</th>
<th rowspan="2">2 rows</th>
<th colspan="8">Colspan 8</th>
</tr>
<tr>
<th>1a</th>
<th>2a</th>
<th>1b</th>
<th>2b</th>
<th>1</th>
<th>2</th>
<th>3</th>
<th>4</th>
<th>5</th>
<th>6</th>
<th>7</th>
<th>8</th>
</tr>
</table>
EOD;

$pdf->writeHTML($tbl, true, false, false, false, '');

// -----------------------------------------------------------------------------

// Table with rowspans and THEAD
$tbl = <<<EOD
<table border='1' cellpadding='5' cellspacing='0' >
<tr>
	<th><strong>Cab</strong></th><th>Relief</th><th>5529-DEV</th><th><strong>Cab</strong></th><th>Relief</th><th>5529-DEV</th><TR>
<tr>
	<td style="background-color: #f0f0f0">Antibacterial wipes</td>
	<td style="background-color: #f0f0f0"><center><input type='checkbox'></center></td>
	<td style="background-color: #f0f0f0"><center><input type='checkbox'></center></td>
	<td style="background-color: #f0f0f0">Blanket</td>
	<td style="background-color: #f0f0f0"><center><input type='checkbox'></center></td>
	<td style="background-color: #f0f0f0"><center><input type='checkbox'></center></td>
</tr>
<tr>
	<td style="background-color: #f0f0f0">ECO Board</td>
	<td style="background-color: #f0f0f0"><center><input type='checkbox'></center></td>
	<td style="background-color: #f0f0f0"><center><input type='checkbox'></center></td>
	<td style="background-color: #f0f0f0">Flare Torches X4</td>
	<td style="background-color: #f0f0f0"><center><input type='checkbox'></center></td>
	<td style="background-color: #f0f0f0"><center><input type='checkbox'></center></td>
</tr>
<tr>
	<td style="background-color: #f0f0f0">Gloves - XXL - XL - L - M - S</td>
	<td style="background-color: #f0f0f0"><center><input type='checkbox'></center></td>
	<td style="background-color: #f0f0f0"><center><input type='checkbox'></center></td>
	<td style="background-color: #f0f0f0">Masks</td>
	<td style="background-color: #f0f0f0"><center><input type='checkbox'></center></td>
	<td style="background-color: #f0f0f0"><center><input type='checkbox'></center></td>
</tr>
<tr>
	<td style="background-color: #f0f0f0">Nominal Role Tally</td>
	<td style="background-color: #f0f0f0"><center><input type='checkbox'></center></td>
	<td style="background-color: #f0f0f0"><center><input type='checkbox'></center></td>
	<td style="background-color: #f0f0f0">Officers Radio</td>
	<td style="background-color: #f0f0f0"><center><input type='checkbox'></center></td>
	<td style="background-color: #f0f0f0"><center><input type='checkbox'></center></td>
</tr>
<tr>
	<td style="background-color: #f0f0f0">OIC Jerkin</td>
	<td style="background-color: #f0f0f0"><center><input type='checkbox'></center></td>
	<td style="background-color: #f0f0f0"><center><input type='checkbox'></center></td>
	<td style="background-color: #f0f0f0">Radios X4</td>
	<td style="background-color: #f0f0f0"><center><input type='checkbox'></center></td>
	<td style="background-color: #f0f0f0"><center><input type='checkbox'></center></td>
</tr>
<tr>
	<td style="background-color: #f0f0f0">Safety Jerkins X4</td>
	<td style="background-color: #f0f0f0"><center><input type='checkbox'></center></td>
	<td style="background-color: #f0f0f0"><center><input type='checkbox'></center></td>
	<td style="background-color: #f0f0f0">TIC</td>
	<td style="background-color: #f0f0f0"><center><input type='checkbox'></center></td>
	<td style="background-color: #f0f0f0"><center><input type='checkbox'></center></td>
</tr>
<tr style="background-color: #A9A9A9">
	<th><strong>Coffin</strong></th><th>Relief</th><th>5529-DEV</th><th><strong>Coffin</strong></th><th>Relief</th><th>5529-DEV</th><TR>
<tr>
	<td style="background-color: #ffffff">Basket Strainer</td>
	<td style="background-color: #ffffff"><center><input type='checkbox'></center></td>
	<td style="background-color: #ffffff"><center><input type='checkbox'></center></td>
	<td style="background-color: #ffffff">Ground Monitor</td>
	<td style="background-color: #ffffff"><center><input type='checkbox'></center></td>
	<td style="background-color: #ffffff"><center><input type='checkbox'></center></td>
</tr>
<tr>
	<td style="background-color: #ffffff">Hall Runner</td>
	<td style="background-color: #ffffff"><center><input type='checkbox'></center></td>
	<td style="background-color: #ffffff"><center><input type='checkbox'></center></td>
	<td style="background-color: #ffffff">Heights Kit</td>
	<td style="background-color: #ffffff"><center><input type='checkbox'></center></td>
	<td style="background-color: #ffffff"><center><input type='checkbox'></center></td>
</tr>
<tr>
	<td style="background-color: #ffffff">Shovel X2</td>
	<td style="background-color: #ffffff"><center><input type='checkbox'></center></td>
	<td style="background-color: #ffffff"><center><input type='checkbox'></center></td>
	<td style="background-color: #ffffff">Squeegee X2</td>
	<td style="background-color: #ffffff"><center><input type='checkbox'></center></td>
	<td style="background-color: #ffffff"><center><input type='checkbox'></center></td>
</tr>
<tr>
	<td style="background-color: #ffffff">Suction Line</td>
	<td style="background-color: #ffffff"><center><input type='checkbox'></center></td>
	<td style="background-color: #ffffff"><center><input type='checkbox'></center></td>
	<td style="background-color: #ffffff"></td>
	<td style="background-color: #ffffff"></td>
	<td style="background-color: #ffffff"></td>
</TR>
<tr style="background-color: #A9A9A9">
	<th><strong>Nearside Front</strong></th><th>Relief</th><th>5529-DEV</th><th><strong>Nearside Front</strong></th><th>Relief</th><th>5529-DEV</th><TR>
<tr>
	<td style="background-color: #f0f0f0">Absorbent Bucket</td>
	<td style="background-color: #f0f0f0"><center><input type='checkbox'></center></td>
	<td style="background-color: #f0f0f0"><center><input type='checkbox'></center></td>
	<td style="background-color: #f0f0f0">Absorbent Kit</td>
	<td style="background-color: #f0f0f0"><center><input type='checkbox'></center></td>
	<td style="background-color: #f0f0f0"><center><input type='checkbox'></center></td>
</tr>
</table>
EOD;

$pdf->writeHTML($tbl, true, false, false, false, '');

$pdf->writeHTML($tbl, true, false, false, false, '');

// -----------------------------------------------------------------------------

// NON-BREAKING TABLE (nobr="true")

$tbl = <<<EOD
<table border="1" cellpadding="2" cellspacing="2" nobr="true">
 <tr>
  <th colspan="3" align="center">NON-BREAKING TABLE</th>
 </tr>
 <tr>
  <td>1-1</td>
  <td>1-2</td>
  <td>1-3</td>
 </tr>
 <tr>
  <td>2-1</td>
  <td>3-2</td>
  <td>3-3</td>
 </tr>
 <tr>
  <td>3-1</td>
  <td>3-2</td>
  <td>3-3</td>
 </tr>
</table>
EOD;

$pdf->writeHTML($tbl, true, false, false, false, '');

// -----------------------------------------------------------------------------

// NON-BREAKING ROWS (nobr="true")

$tbl = <<<EOD
<table border="1" cellpadding="2" cellspacing="2" align="center">
 <tr nobr="true">
  <th colspan="3">NON-BREAKING ROWS</th>
 </tr>
 <tr nobr="true">
  <td>ROW 1<br />COLUMN 1</td>
  <td>ROW 1<br />COLUMN 2</td>
  <td>ROW 1<br />COLUMN 3</td>
 </tr>
 <tr nobr="true">
  <td>ROW 2<br />COLUMN 1</td>
  <td>ROW 2<br />COLUMN 2</td>
  <td>ROW 2<br />COLUMN 3</td>
 </tr>
 <tr nobr="true">
  <td>ROW 3<br />COLUMN 1</td>
  <td>ROW 3<br />COLUMN 2</td>
  <td>ROW 3<br />COLUMN 3</td>
 </tr>
</table>
EOD;

$pdf->writeHTML($tbl, true, false, false, false, '');

// -----------------------------------------------------------------------------

//Close and output PDF document
$pdf->Output('example_048.pdf', 'I');

//============================================================+
// END OF FILE
//============================================================+