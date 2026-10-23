<?php
// Includes for database connection and authentication

include_once '../includes/error_handler.php';
include_once '../includes/db_connect.php';
include_once '../includes/auth.php';

// Check permission
if (!can('download_gatepass_pdf')) {
    header('Content-Type: text/html');
    die('<div style="padding: 2rem; text-align: center; font-family: sans-serif; color: #555;">
            <h2>Permission Denied</h2>
            <p>You do not have permission to view this document.</p>
         </div>');
}

// Include the standard FPDF library
require('../fpdf/fpdf.php');

// --- HELPER FUNCTION: Fix the "Â" special character space issue ---
function cleanPDFText($text) {
    if ($text === null || $text === '') return '';
    // Replace UTF-8 non-breaking spaces (which cause the Â character) with standard spaces
    $text = str_replace("\xC2\xA0", ' ', $text);
    // Convert to FPDF's required encoding
    return iconv('UTF-8', 'windows-1252//TRANSLIT', $text);
}

// --- Database Query ---
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Error: No valid Gatepass ID provided in the URL.");
}
$gatepass_id = (int)$_GET['id'];

$stmt = $conn->prepare(
    "SELECT gp.*, v.vendor_name, v.contact_mobile, v.address 
     FROM gatepass_requests gp 
     LEFT JOIN gp_vendors v ON gp.vendor_id = v.id 
     WHERE gp.id = ?"
);
$stmt->bind_param("i", $gatepass_id);
$stmt->execute();
$gatepass = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$gatepass) {
    die("Gatepass with ID '{$gatepass_id}' not found.");
}

$items_stmt = $conn->prepare("SELECT * FROM gatepass_items WHERE gatepass_id = ?");
$items_stmt->bind_param("i", $gatepass_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$items = [];
while ($item = $items_result->fetch_assoc()) {
    $items[] = $item;
}
$items_stmt->close();


// --- PDF Generation Class ---
class PDF extends FPDF
{
    // Page header
    function Header()
    {
        if (file_exists('../assets/images/favicon.png')) {
            $this->Image('../assets/images/favicon.png', 15, 13, 26);
        }

        $this->SetFont('Arial', 'BU', 15);
        $this->SetTextColor(0);
        $this->SetXY(10, 12);
        $this->Cell(190, 10, 'Sun Technology Integrators Pvt. Ltd.', 0, 1, 'C');
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(190, 6, 'No.496, 1st stage,4th Block, HBR Layout,Bangalore 560043.', 0, 1, 'C');
        $this->Ln(4);

        $this->SetDrawColor(0, 0, 0); 
        $this->SetLineWidth(0.2);
        $y = $this->GetY();
        $this->Line(10, $y, $this->w - 10, $y); 
        $this->SetLineWidth(0.3); 
        $this->Ln(2); 

        // Document Border (Draws automatically on every new page)
        $this->Rect(5, 5, 200, 287, 'D');
    }

    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->MultiCell(0, 4, "*Two copies will be presented, one of which should be given to security. While returning, the original copy must be collected back.", 0, 'C');
    }
    
    // Helper function to check for page breaks cleanly inside the class
    function CheckSpace($h)
    {
        if($this->GetY() + $h > $this->PageBreakTrigger) {
            $this->AddPage($this->CurOrientation);
            $this->Ln(2); // Adds 8mm padding at the top of the new page
        }
    }

    function DataSection($title, $data)
    {
        if (empty($data)) return;
        $this->SetFont('Arial', 'B', 11);
        $this->SetFillColor(230, 230, 230);
        $this->SetTextColor(0);
        $this->SetDrawColor(0, 0, 0);
        $this->Cell(190, 7, $title, 1, 1, 'L', true);
        $this->SetFont('Arial', '', 10);
        
        $wLabel = 60;
        $wValue = 130;
        
        foreach ($data as $label => $value) {
            $clean_value = cleanPDFText($value ?? 'N/A');
            
            // Calculate how many lines the text will take up
            $nb = $this->NbLines($wValue, $clean_value);
            
            // Standard height is 8mm, but if it wraps, expand the height
            $h = max(8, (5 * $nb) + 3); 
            
            // Push to next page if we run out of room
            $this->CheckSpace($h);
            
            $x = $this->GetX();
            $y = $this->GetY();
            
            // Draw the grid borders
            $this->Rect($x, $y, $wLabel, $h);
            $this->Rect($x + $wLabel, $y, $wValue, $h);
            
            // Print the Label (Vertically centered)
            $this->SetXY($x, $y);
            $this->Cell($wLabel, $h, $label, 0, 0, 'L');
            
            // Print the Value (MultiCell for text wrapping, vertically padded)
            $padding_top = ($h - (5 * $nb)) / 2;
            $this->SetXY($x + $wLabel, $y + $padding_top);
            $this->MultiCell($wValue, 5, $clean_value, 0, 'L');
            
            // Reset Y position to the bottom of this row
            $this->SetXY($x, $y + $h);
        }
        $this->Ln(5);
    }

    function NbLines($w, $txt)
    {
        if(!isset($this->CurrentFont))
            $this->Error('No font has been set');
        $cw = &$this->CurrentFont['cw'];
        if($w==0)
            $w = $this->w-$this->rMargin-$this->x;
        
        $cMargin = isset($this->cMargin) ? $this->cMargin : 1;
        $wmax = ($w-2*$cMargin)*1000/$this->FontSize;
        
        $s = str_replace("\r",'',(string)$txt);
        $nb = strlen($s);
        if($nb>0 && $s[$nb-1]=="\n")
            $nb--;
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while($i<$nb) {
            $c = $s[$i];
            if($c=="\n") {
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
                continue;
            }
            if($c==' ')
                $sep = $i;
            
            $char_width = isset($cw[$c]) ? $cw[$c] : 0;
            $l += $char_width;
            
            if($l>$wmax) {
                if($sep==-1) {
                    if($i==$j)
                        $i++;
                } else
                    $i = $sep+1;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
            } else
                $i++;
        }
        return $nl;
    }

    function ItemsTable($items)
    {
        $this->SetFont('Arial', 'B', 11);
        $this->SetFillColor(230, 230, 230);
        $this->SetDrawColor(0, 0, 0);
        $this->Cell(190, 7, 'IT Asset Details', 1, 1, 'L', true);
        
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(15, 7, 'Sl. No.', 1, 0, 'C', true);
        $this->Cell(95, 7, 'Product Description', 1, 0, 'C', true);
        $this->Cell(20, 7, 'Qty', 1, 0, 'C', true);
        $this->Cell(60, 7, 'Remarks', 1, 1, 'C', true);
        
        $this->SetFont('Arial', '', 10);
        
        if (empty($items)) {
            $this->Cell(190, 10, 'No items listed.', 1, 1, 'C');
        } else {
            $sl_no = 1;
            $wSl = 15;
            $wDesc = 95;
            $wQty = 20;
            $wRem = 60;
            
            foreach ($items as $item) {
                $desc = cleanPDFText($item['description'] ?? '');
                $remarks = cleanPDFText($item['remarks'] ?? '');
                $qty = $item['quantity'] ?? '1';

                $nbDesc = $this->NbLines($wDesc, $desc);
                $nbRem = $this->NbLines($wRem, $remarks);
                
                $nb = max($nbDesc, $nbRem, 1);
                $h = 6 * $nb;

                $this->CheckSpace($h);

                $x = $this->GetX();
                $y = $this->GetY();

                $this->Rect($x, $y, $wSl, $h);
                $this->Rect($x + $wSl, $y, $wDesc, $h);
                $this->Rect($x + $wSl + $wDesc, $y, $wQty, $h);
                $this->Rect($x + $wSl + $wDesc + $wQty, $y, $wRem, $h);

                $this->SetXY($x, $y);
                $this->Cell($wSl, $h, $sl_no++, 0, 0, 'C');

                $this->SetXY($x + $wSl, $y);
                $this->MultiCell($wDesc, 6, $desc, 0, 'L');

                $this->SetXY($x + $wSl + $wDesc, $y);
                $this->Cell($wQty, $h, $qty, 0, 0, 'C');

                $this->SetXY($x + $wSl + $wDesc + $wQty, $y);
                $this->MultiCell($wRem, 6, $remarks, 0, 'L');

                $this->SetXY($x, $y + $h);
            }
        }
         $this->Ln(5);
    }

    function SecurityTable($title, $time, $date, $person_name, $verified_by) {
        $this->Ln(4); 
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(93, 6, $title, 1, 1, 'L', false);
        $this->SetFont('Arial', '', 10);
        $line_height = 8;
        $label_width = 30;
        $value_width = 63;
        
        $person_name = cleanPDFText($person_name ?? '');
        $verified_by = cleanPDFText($verified_by ?? '');

        $this->Cell($label_width, $line_height, 'Time', 1, 0);
        $this->Cell($value_width, $line_height, $time ? date('h:i A', strtotime($time)) : '', 1, 1);
        $this->Cell($label_width, $line_height, 'Date', 1, 0);
        $this->Cell($value_width, $line_height, $date ? date('d/m/Y', strtotime($date)) : '', 1, 1);
        $this->Cell($label_width, $line_height, 'Staff Name', 1, 0);
        $this->Cell($value_width, $line_height, $person_name, 1, 1);
        $this->Cell($label_width, $line_height, 'Verified By', 1, 0);
        $this->Cell($value_width, $line_height, $verified_by, 1, 1);
        $this->Cell($label_width, $line_height, 'Signature', 1, 0);
        $this->Cell($value_width, $line_height, '', 1, 1);
    }
}

// --- PDF Generation ---

$pdf = new PDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
// SHRINK BOTTOM MARGIN TO 15mm TO PREVENT PREMATURE PAGE BREAKS
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();

$pdf->SetLineWidth(0.2);
$pdf->SetDrawColor(0, 0, 0);

$pdf->SetFont('Arial', 'BU', 12);
$doc_title = '';
if ($gatepass['gatepass_type'] == 'Employee') { $doc_title = 'Gatepass for IT Materials (Employee Asset)'; }
elseif ($gatepass['gatepass_type'] == 'Vendor') { $doc_title = 'Gatepass for IT Materials - Vendor (for Service)'; }
elseif ($gatepass['gatepass_type'] == 'Site-to-Site') { $doc_title = 'Gatepass for IT Materials (Internal Asset Movement)'; }
$pdf->Cell(0, 8, $doc_title, 0, 1, 'C');
$pdf->Ln(2);

$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(130, 8, 'Gate Pass No: ' . htmlspecialchars($gatepass['gatepass_no'] ?? ''), 0, 0, 'L');
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(60, 8, 'Date: ' . date('d/m/Y', strtotime($gatepass['created_at'])), 0, 1, 'R');
$pdf->Ln(2);

if ($gatepass['gatepass_type'] == 'Employee') {
    $details_data = [ 'Name' => $gatepass['employee_name'] ?? '', 'Department' => $gatepass['department'] ?? '', 'Position' => $gatepass['position'] ?? '', 'Date of Joining' => $gatepass['date_of_joining'] ? date('d-M-Y', strtotime($gatepass['date_of_joining'])) : 'N/A' ];
    $pdf->DataSection('Employee Details', $details_data);
} elseif ($gatepass['gatepass_type'] == 'Vendor') {
    $details_data = [ 'Name' => $gatepass['vendor_name'] ?? '', 'Mobile' => $gatepass['contact_mobile'] ?? '', 'Address' => $gatepass['address'] ?? '', 'Pickup Person' => $gatepass['pickup_person'] ?? 'N/A' ];
    $pdf->DataSection('Vendor Details', $details_data);
} elseif ($gatepass['gatepass_type'] == 'Site-to-Site') {
    $movement_data = [ 'From Site / Location' => $gatepass['from_location'] ?? '', 'To Site / Location' => $gatepass['to_location'] ?? '' ];
    $pdf->DataSection('Movement Details', $movement_data);
    $people_data = [ 'Requested By' => $gatepass['requested_by'] ?? '', 'Received By' => $gatepass['received_by'] ?? '' ];
    $pdf->DataSection('People Involved', $people_data);
}

$pdf->ItemsTable($items);


// =======================================================================
// PAGE BREAK CHECK: IT APPROVAL BLOCK
// EXACT CALCULATION OF HEIGHT TO PREVENT UNNECESSARY PAGE BREAKS
// =======================================================================
$clean_reason = cleanPDFText($gatepass['reason'] ?? 'N/A');
$reason_lines = $pdf->NbLines(145, $clean_reason);
$reason_height = max(7, 7 * $reason_lines);

// Reason Height + Gap(5) + Header(7) + Msg(10) + Names(6) + Stamps(6) + Gap(5) = height + 39mm 
$approvalSpaceNeeded = $reason_height + 39; 
$pdf->CheckSpace($approvalSpaceNeeded);

$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(45, 7, 'Reason for Gate Pass:', 0, 0, 'L');
$pdf->SetFont('Arial', '', 10);
$pdf->MultiCell(145, 7, $clean_reason, 'B', 'L');
$pdf->Ln(5);

$pdf->SetFont('Arial', 'B', 11);
$pdf->SetFillColor(230, 230, 230);
$pdf->Cell(190, 7, 'IT Approval', 1, 1, 'L', true);
$pdf->SetFont('Arial', '', 10);

$approval_message = '';
if ($gatepass['gatepass_type'] == 'Employee') { $approval_message = "The above-mentioned asset(s) has/have been verified by me and is/are being sent out, on a returnable basis, for Work From Home. Kindly allow the employee to take the asset(s)."; }
elseif ($gatepass['gatepass_type'] == 'Vendor') { $approval_message = "The above-mentioned asset(s) has/have been verified by me and is/are being sent out, on a returnable basis, for service/repair. Kindly allow the asset(s) to be taken out."; }
elseif ($gatepass['gatepass_type'] == 'Site-to-Site') { $approval_message = "The above-mentioned asset(s) has/have been verified by me and is/are being sent for internal transfer. Kindly allow the asset(s) to be moved for the said purpose."; }
$pdf->MultiCell(190, 5, $approval_message, 'LR', 'L');
$pdf->Cell(190, 5, '', 'LR', 1);

// --- DIGITAL SIGNATURE BLOCK ---
$prepared_name = cleanPDFText($gatepass['prepared_by'] ?? '');
$prepared_date = $gatepass['created_at'] ? date('d-M-Y H:i:s', strtotime($gatepass['created_at'])) : 'N/A';

$approved_by_text = $gatepass['approved_by'] ?? '';
if ($gatepass['status'] === 'Pending Approval') {
    $approved_by_text = 'Pending Approval';
} elseif (empty($approved_by_text)) {
    $approved_by_text = 'Email Approval';
} else {
    $approved_by_text = cleanPDFText($approved_by_text);
}
$approved_date = $gatepass['approved_at'] ? date('d-M-Y H:i:s', strtotime($gatepass['approved_at'])) : 'N/A';

$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(95, 6, ' Prepared By: ' . $prepared_name, 'L', 0, 'L');
$pdf->Cell(95, 6, ' Approved By: ' . $approved_by_text, 'R', 1, 'L');

$pdf->SetFont('Arial', 'I', 9);
// Prepared Stamp (Teal)
$pdf->SetTextColor(12, 93, 88); 
$pdf->Cell(95, 6, ' [ Digitally Signed on: ' . $prepared_date . ' ]', 'L', 0, 'L');

// Approved Stamp (Teal if approved, Red if pending)
if ($gatepass['status'] === 'Approved' && !empty($gatepass['approved_at'])) {
    $pdf->SetTextColor(12, 93, 88);
    $pdf->Cell(95, 6, ' [ Digitally Signed on: ' . $approved_date . ' ]', 'R', 1, 'L');
} else {
    $pdf->SetTextColor(220, 53, 69);
    $pdf->Cell(95, 6, ' [ Signature Pending ]', 'R', 1, 'L');
}

// Reset color and close the box
$pdf->SetTextColor(0, 0, 0); 
$pdf->Cell(190, 4, '', 'LBR', 1); 
$pdf->Ln(5);
// --- END DIGITAL SIGNATURE BLOCK ---


// =======================================================================
// PAGE BREAK CHECK: SECURITY BLOCK
// EXACT TIGHTENED SPACE REQUIRED
// =======================================================================
$securitySpaceNeeded = ($gatepass['gatepass_type'] == 'Site-to-Site') ? 58 : 38;
$pdf->CheckSpace($securitySpaceNeeded);

$pdf->SetFont('Arial', 'B', 11);
$pdf->SetFillColor(230, 230, 230);
$pdf->Cell(190, 7, 'For Use Of Security Staff', 1, 1, 'L', true);

$sec_out_name = cleanPDFText($gatepass['security_person_name'] ?? '');
$sec_in_name = cleanPDFText($gatepass['security_in_person_name'] ?? '');
$sec_out_veri = cleanPDFText($gatepass['security_verified_by'] ?? '');
$sec_in_veri = cleanPDFText($gatepass['security_in_verified_by'] ?? '');

if ($gatepass['gatepass_type'] == 'Site-to-Site') {
    $pdf->Ln(4);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(93, 6, 'From Site (Out)', 1, 0, 'L', false);
    $pdf->Cell(4, 6, '', 0, 0);
    $pdf->Cell(93, 6, 'To Site (In)', 1, 1, 'L', false);
    $pdf->SetFont('Arial', '', 10);
    $line_height = 8;
    // Data Rows
    $pdf->Cell(30, $line_height, 'Time', 1, 0);
    $pdf->Cell(63, $line_height, $gatepass['security_out_time'] ? date('h:i A', strtotime($gatepass['security_out_time'])) : '', 1, 0);
    $pdf->Cell(4, $line_height, '', 0, 0);
    $pdf->Cell(30, $line_height, 'Time', 1, 0);
    $pdf->Cell(63, $line_height, $gatepass['security_in_time'] ? date('h:i A', strtotime($gatepass['security_in_time'])) : '', 1, 1);
    $pdf->Cell(30, $line_height, 'Date', 1, 0);
    $pdf->Cell(63, $line_height, $gatepass['security_out_date'] ? date('d/m/Y', strtotime($gatepass['security_out_date'])) : '', 1, 0);
    $pdf->Cell(4, $line_height, '', 0, 0);
    $pdf->Cell(30, $line_height, 'Date', 1, 0);
    $pdf->Cell(63, $line_height, $gatepass['security_in_date'] ? date('d/m/Y', strtotime($gatepass['security_in_date'])) : '', 1, 1);
    $pdf->Cell(30, $line_height, 'Staff Name', 1, 0);
    $pdf->Cell(63, $line_height, $sec_out_name, 1, 0);
    $pdf->Cell(4, $line_height, '', 0, 0);
    $pdf->Cell(30, $line_height, 'Staff Name', 1, 0);
    $pdf->Cell(63, $line_height, $sec_in_name, 1, 1);
    $pdf->Cell(30, $line_height, 'Verified By', 1, 0);
    $pdf->Cell(63, $line_height, $sec_out_veri, 1, 0);
    $pdf->Cell(4, $line_height, '', 0, 0);
    $pdf->Cell(30, $line_height, 'Verified By', 1, 0);
    $pdf->Cell(63, $line_height, $sec_in_veri, 1, 1);
    $pdf->Cell(30, $line_height, 'Signature', 1, 0);
    $pdf->Cell(63, $line_height, '', 1, 0);
    $pdf->Cell(4, $line_height, '', 0, 0);
    $pdf->Cell(30, $line_height, 'Signature', 1, 0);
    $pdf->Cell(63, $line_height, '', 1, 1);
} else {
    $pdf->SecurityTable('Check-out Details', $gatepass['security_out_time'], $gatepass['security_out_date'], $gatepass['security_person_name'], $gatepass['security_verified_by']);
}

$file_name_subject = '';
if ($gatepass['gatepass_type'] == 'Employee') { $file_name_subject = $gatepass['employee_name'] ?? 'Employee'; }
elseif ($gatepass['gatepass_type'] == 'Vendor') { $file_name_subject = $gatepass['vendor_name'] ?? 'Vendor'; }
elseif ($gatepass['gatepass_type'] == 'Site-to-Site') { $file_name_subject = 'Site_Movement'; }
$file_name = 'Gatepass_' . str_replace(' ', '_', $file_name_subject) . '_' . $gatepass['id'] . '.pdf';

$pdf->Output('I', $file_name);
?>