<?php
/**
 * Exit Checklist PDF Generator (FPDF Version)
 * Final version with consolidated verification section and single-page optimization.
 */

// 1. SETUP & INCLUDES
// =============================================================================
include_once '../includes/error_handler.php';
require_once('../includes/db_connect.php');
include_once '../includes/auth.php';

if (!can('download_exit_checklist_pdf')) {
    header('Content-Type: text/html'); // Ensure the browser renders HTML
    die('<div style="padding: 2rem; text-align: center; font-family: sans-serif; color: #555;">
            <h2>Permission Denied</h2>
            <p>You do not have permission to view this document.</p>
         </div>');
}

require_once('../fpdf/fpdf.php'); 

// 2. DATA FETCHING & PREPARATION (Unchanged, as it's efficient)
// =============================================================================

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Error: No valid checklist ID provided.");
}
$checklist_id = (int)$_GET['id'];

$checklist_data = [];
$employee_data = ['full_name' => 'N/A', 'emp_id' => 'N/A', 'department' => 'N/A', 'location' => 'N/A'];
$items_by_task = [];

// Fetch main checklist data
// NOTE: Assuming $conn is available from db_connect.php
$stmt = $conn->prepare("SELECT * FROM checklists WHERE id = ? AND checklist_type = 'exit_checklist'");
$stmt->bind_param("i", $checklist_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $checklist_data = $result->fetch_assoc();
} else {
    die("Error: Exit Checklist not found.");
}
$stmt->close();

// Fetch associated employee data
if (!empty($checklist_data['employee_id'])) {
    $stmt = $conn->prepare("SELECT emp_id, first_name, last_name, department, location FROM employees WHERE id = ?");
    $stmt->bind_param("i", $checklist_data['employee_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $employee_data['full_name'] = trim($row['first_name'] . ' ' . $row['last_name']);
        $employee_data['emp_id'] = $row['emp_id'];
        $employee_data['department'] = $row['department'];
        $employee_data['location'] = $row['location'];
    }
    $stmt->close();
}

// Fetch all checklist items
$stmt = $conn->prepare("SELECT task_description, status, remarks FROM checklist_items WHERE template_id = ?");
$stmt->bind_param("i", $checklist_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $items_by_task[$row['task_description']] = $row;
}
$stmt->close();

// Master checklist structure
$master_exit_checklist_items = [
    'DOMAIN LOGIN' => ['Disable AD logins and Move to "Disable Users" OU', 'Remove from Domain Groups'],
    'CLIENT LOGIN' => ['Disable Client credentials & save in TeamPass'],
    'EMAIL' => ['Disable Email ID', 'Remove from Departments Groups & Teams', 'Remove the Mailbox License', 'Enable email forwarding & auto reply (if required)'],
    'IM' => ['Disable Teams/Skype/Chime ID', 'Reset the Password and update in TeamPass'],
    'SYSTEM' => ['Host Name', 'Serial Number (S/N)', 'Take back the System to Inventory', 'Check for Physical damages', 'Check for Pending assigned assets and update in GLPI', 'Update to InStock in GLPI', 'Move to InStock OU in AD', 'Uninstall Cisco Endpoint Security', 'Uninstall Cisco Umbrella - Laptop Only', 'Delete the Hostname from Cisco Umbrella Console', 'Delete the Computer in WSUS Server'],
    'VOIP' => ['US Rec : Send details to remove in IT Dashboard', 'Take VOIP Phone to Inventory', 'Update to InStock in GLPI', 'Disable the VOIP Extn in IPPBX', 'US Rec & Marketing : Remove from Call report', 'Update in VoIP Tracker', 'US Rec/Others : Send details to remove in IT Dashboard'],
    'FORTI TOKEN' => ['Remove user Id from SSLVPN group Id in AD', 'Delete FortiToken from User Mobile', 'Remove FortiToken from Firewall'],
    'BACKUP' => ['Email Backup (PST) saved in Server', 'User data backup saved in Server']
];

// 3. PDF GENERATION CLASS & LOGIC
// =============================================================================

class PDF extends FPDF
{
    // Page header
    function Header()
    {
        // Company Logo
        if (file_exists('../assets/images/favicon.png')) {
            $this->Image('../assets/images/favicon.png', 15, 13, 26); // x, y, width
        }

        // Company Name & Address
        $this->SetFont('Times', 'BU', 15);
        $this->SetTextColor(0); // Black text
        $this->SetXY(10, 12);
        $this->Cell(190, 10, 'Sun Technology Integrators Pvt. Ltd.', 0, 1, 'C');
        $this->SetFont('Times', 'B', 8);
        $this->Cell(190, 6, 'No.496, 1st stage,4th Block, HBR Layout,Bangalore 560043.', 0, 1, 'C');
        $this->Ln(4);

        // Faded line below header
        $this->SetDrawColor(0, 0, 0); 
        $this->SetLineWidth(0.2);
        $y = $this->GetY();
        $this->Line(10, $y, $this->w - 10, $y); // Line from margin to margin
        $this->SetDrawColor(0, 0, 0); // Restore black draw color
        $this->SetLineWidth(0.3); // Restore default line width
        $this->Ln(2); // Space between line and title

        // Title
        $this->SetFont('Times', 'BU', 12);
        $this->Cell(0, 9, 'Employee Exit Checklist', 0, 1, 'C');
        $this->Ln(1); // REDUCED: Reduced line break to create space between the title and the content
        
        // ** The cursor is now positioned correctly for the start of the body **
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Times', 'I', 8); // Consistent Times font
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }

    function CheckPageBreak($h) {
        if ($this->GetY() + $h > $this->PageBreakTrigger) {
            $this->AddPage($this->CurOrientation);
            return true;
        }
        return false;
    }

    function NbLines($w, $txt) {
        // Standard FPDF line calculation logic
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0) $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n") $nb--;
        $sep = -1; $i = 0; $j = 0; $l = 0; $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") { $i++; $sep = -1; $j = $i; $l = 0; $nl++; continue; }
            if ($c == ' ') $sep = $i;
            $l += $cw[$c];
            if ($l > $wmax) {
                if ($sep == -1) { if ($i == $j) $i++; } else $i = $sep + 1;
                $sep = -1; $j = $i; $l = 0; $nl++;
            } else $i++;
        }
        return $nl;
    }

    /**
     * Draws the dynamic height checklist table.
     */
    function DrawChecklistTable($master_items, $items_data)
    {
        $w_cat = 35;
        $w_task = 115;
        $w_stat = 40;
        $line_height = 5.5; // Height per line of text
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.2);

        foreach ($master_items as $category => $tasks) {
            $total_category_height = 0;
            $row_heights = [];
            $this->SetFont('Times', '', 8.5);

            // 1. Calculate Heights for all tasks in the current category
            foreach ($tasks as $task) {
                $item_data_for_height = $items_data[$task] ?? ['remarks' => ''];
                $task_display = $task;
                // Append remarks if it's Hostname or S/N
                if (($task === 'Host Name' || $task === 'Serial Number (S/N)') && !empty($item_data_for_height['remarks'])) {
                    $task_display = $task . ' : ' . $item_data_for_height['remarks'];
                }
                $clean_task = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $task_display);
                $num_lines = $this->NbLines($w_task, $clean_task);
                $row_height = $num_lines * $line_height;
                $row_heights[] = $row_height;
                $total_category_height += $row_height;
            }

            // Check for page break BEFORE drawing the whole section
            if ($this->CheckPageBreak($total_category_height)) {
                $this->SetFont('Times', 'B', 9); // Re-apply font after header
            }

            $start_x = $this->GetX();
            $start_y = $this->GetY();
            $current_y = $start_y;

            // 2. Draw Task Rows and Statuses
            foreach ($tasks as $index => $task) {
                $height = $row_heights[$index];
                $item_data = $items_data[$task] ?? ['status' => 'N/A', 'remarks' => ''];
                
                $status = $item_data['status'];
                $status_text = 'NA';
                if ($status === 'Completed') $status_text = 'Completed';
                else if ($status === 'Pending') $status_text = 'Pending';
                
                $task_display = $task;
                if (($task === 'Host Name' || $task === 'Serial Number (S/N)') && !empty($item_data['remarks'])) {
                    $task_display = $task . ' : ' . $item_data['remarks'];
                }
                $clean_task = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $task_display);

                // Task Cell (Content)
                $this->SetXY($start_x + $w_cat, $current_y);
                $this->Rect($start_x + $w_cat, $current_y, $w_task, $height, 'D'); // Draw Task Cell Border
                $this->MultiCell($w_task, $line_height, $clean_task, 0, 'L');
                
                // Status Cell (with colored background)
                $this->SetXY($start_x + $w_cat + $w_task, $current_y);
                // Set fill color based on status
                if ($status === 'Completed' || $status === 'N/A') { $this->SetFillColor(220, 255, 220); } // Greenish for done/NA
                elseif ($status === 'Pending') { $this->SetFillColor(255, 220, 220); } // Reddish for pending
                else { $this->SetFillColor(255, 255, 255); }
                $this->Cell($w_stat, $height, $status_text, 1, 0, 'C', true); // Draw Status Cell Border/Fill

                $current_y += $height;
            }

            // 3. Draw Category Cell (spans all rows) and Text
            $this->SetXY($start_x, $start_y);
            $this->SetFont('Times', 'B', 9);
            $this->Rect($start_x, $start_y, $w_cat, $total_category_height, 'D'); // Draw Category Cell Border

            // Center the Category Text Vertically
            $clean_category = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $category);
            // Calculate height of the category text
            $this->SetFont('Times', 'B', 9);
            $text_lines = $this->NbLines($w_cat, $clean_category);
            $text_height = $text_lines * 5; // Assuming a 5mm height for this font size/line height
            $padding_top = ($total_category_height - $text_height) / 2;
            
            $this->SetY($start_y + $padding_top);
            // Draw category text without cell borders
            $this->MultiCell($w_cat, 5, $clean_category, 0, 'C'); 

            // Move cursor to the start of the next category
            $this->SetY($start_y + $total_category_height);
        }
        $this->Ln(3);
    }
}

// 4. PDF INITIALIZATION & CONTENT GENERATION
// =============================================================================
$pdf = new PDF();
$pdf->AliasNbPages();
$pdf->SetMargins(10, 10, 10);
$pdf->SetTopMargin(16);
$pdf->AddPage();

// Add overall page border
$pdf->SetLineWidth(0.5); 
$pdf->SetDrawColor(0, 0, 0);
$pdf->Rect(5, 5, 200, 287, 'D');
$pdf->SetLineWidth(.3); // Reset to a default for other lines if needed

// ** REMOVED: $pdf->SetY(38); ** to allow natural flow after Header
$pdf->Ln(0.5); // REDUCED: Minimal break after the header section

// Employee Details Section
$pdf->SetFont('Times', 'B', 10);
$pdf->Cell(190, 6, 'EMPLOYEE DETAILS', 1, 1, 'L');
$pdf->SetFont('Times', 'B', 8.5);
$pdf->Cell(30, 6, 'Name:', 1, 0, 'L');
$pdf->SetFont('Times', '', 8.5);
$pdf->Cell(65, 6, $employee_data['full_name'], 1, 0, 'L');
$pdf->SetFont('Times', 'B', 8.5);
$pdf->Cell(30, 6, 'EMP ID:', 1, 0, 'L');
$pdf->SetFont('Times', '', 8.5);
$pdf->Cell(65, 6, $employee_data['emp_id'], 1, 1, 'L');
$pdf->SetFont('Times', 'B', 8.5);
$pdf->Cell(30, 6, 'Department:', 1, 0, 'L');
$pdf->SetFont('Times', '', 8.5);
$pdf->Cell(65, 6, $employee_data['department'], 1, 0, 'L');
$pdf->SetFont('Times', 'B', 8.5);
$pdf->Cell(30, 6, 'Location:', 1, 0, 'L');
$pdf->SetFont('Times', '', 8.5);
$pdf->Cell(65, 6, $employee_data['location'], 1, 1, 'L');
$pdf->Ln(3);

// Main Checklist Table (Using the new method)
$pdf->DrawChecklistTable($master_exit_checklist_items, $items_by_task);

// Consolidated Verification Details Section
$pdf->SetFont('Times', 'B', 10);
$pdf->Cell(190, 6, 'VERIFICATION DETAILS', 1, 1, 'L');

$cell_width_label = 47.5;
$cell_width_value = 47.5;
$cell_height = 6;

// Row 1
$pdf->SetFont('Times', 'B', 8.5);
$pdf->Cell($cell_width_label, $cell_height, 'Prepared By:', 1, 0, 'L');
$pdf->SetFont('Times', '', 8.5);
$pdf->Cell($cell_width_value, $cell_height, $checklist_data['prepared_by'] ?? '', 1, 0, 'L');
$pdf->SetFont('Times', 'B', 8.5);
$pdf->Cell($cell_width_label, $cell_height, 'Prepared Date:', 1, 0, 'L');
$pdf->SetFont('Times', '', 8.5);
$pdf->Cell($cell_width_value, $cell_height, !empty($checklist_data['prepared_at']) ? date('d-m-Y', strtotime($checklist_data['prepared_at'])) : '', 1, 1, 'L');

// Row 2
$pdf->SetFont('Times', 'B', 8.5);
$pdf->Cell($cell_width_label, $cell_height, 'Approved By:', 1, 0, 'L');
$pdf->SetFont('Times', '', 8.5);
$pdf->Cell($cell_width_value, $cell_height, $checklist_data['approved_by'] ?? '', 1, 0, 'L');
$pdf->SetFont('Times', 'B', 8.5);
$pdf->Cell($cell_width_label, $cell_height, 'Approved Date:', 1, 0, 'L');
$pdf->SetFont('Times', '', 8.5);
$pdf->Cell($cell_width_value, $cell_height, !empty($checklist_data['approved_at']) ? date('d-m-Y', strtotime($checklist_data['approved_at'])) : '', 1, 1, 'L');

// Row 3
$pdf->SetFont('Times', 'B', 8.5);
$pdf->Cell($cell_width_label, $cell_height, 'Asset Verified By:', 1, 0, 'L');
$pdf->SetFont('Times', '', 8.5);
$pdf->Cell($cell_width_value, $cell_height, $checklist_data['asset_verified_by'] ?? '', 1, 0, 'L');
$pdf->SetFont('Times', 'B', 8.5);
$pdf->Cell($cell_width_label, $cell_height, 'Backup Verified By:', 1, 0, 'L');
$pdf->SetFont('Times', '', 8.5);
$pdf->Cell($cell_width_value, $cell_height, $checklist_data['backup_verified_by'] ?? '', 1, 1, 'L');

// Row 4
$pdf->SetFont('Times', 'B', 8.5);
$pdf->Cell($cell_width_label, $cell_height, 'VoIP Verified By:', 1, 0, 'L');
$pdf->SetFont('Times', '', 8.5);
$pdf->Cell($cell_width_value, $cell_height, $checklist_data['voip_verified_by'] ?? '', 1, 0, 'L');
$pdf->SetFont('Times', 'B', 8.5);
$pdf->Cell($cell_width_label, $cell_height, 'Email Sent to IT:', 1, 0, 'L');
$pdf->SetFont('Times', '', 8.5);
$pdf->Cell($cell_width_value, $cell_height, $checklist_data['email_sent_to_it_by'] ?? '', 1, 1, 'L');

// 5. OUTPUT THE PDF
// =============================================================================
$filename = 'Exit_Checklist_' . preg_replace('/[^a-zA-Z0-9]/', '_', $employee_data['full_name']) . '_' . $checklist_id . '.pdf';
$pdf->Output('I', $filename);

?>