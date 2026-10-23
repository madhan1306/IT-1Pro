<?php
/**
 * System Readiness Checklist PDF Generator (FPDF Version)
 * --- FINAL PRODUCTION VERSION (REVISED for new schema) ---
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

// 2. DATA FETCHING & PREPARATION
// =============================================================================

if (!isset($_GET['checklist_id']) || !is_numeric($_GET['checklist_id'])) {
    die("Error: No valid checklist ID provided.");
}
$checklist_id = (int)$_GET['checklist_id'];

$checklist_data = [];
$employee_data = ['full_name' => 'N/A', 'emp_id' => 'N/A', 'joining_date' => 'N/A', 'department' => 'N/A', 'position' => 'N/A', 'domain_username' => '', 'email_id' => '', 'o365_edition' => ''];
$technician_data = ['prepared_by' => 'N/A', 'prepared_at' => 'N/A', 'verified_by' => 'N/A', 'verified_at' => 'N/A'];

$stmt = $conn->prepare("SELECT * FROM checklists WHERE id = ? AND checklist_type = 'system_readiness'");
$stmt->bind_param("i", $checklist_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $checklist_data = $result->fetch_assoc();
} else {
    die("Error: Checklist not found.");
}
$stmt->close();

if (!empty($checklist_data['employee_id'])) {
    $stmt = $conn->prepare("SELECT emp_id, first_name, last_name, date_of_joining, department, position, domain_username, email_id, o365_edition FROM employees WHERE id = ?");
    $stmt->bind_param("i", $checklist_data['employee_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $employee_data['full_name'] = $row['first_name'] . ' ' . $row['last_name'];
        $employee_data['emp_id'] = $row['emp_id'];
        $employee_data['joining_date'] = !empty($row['date_of_joining']) ? date('d-m-Y', strtotime($row['date_of_joining'])) : 'N/A';
        $employee_data['department'] = $row['department'];
        $employee_data['position'] = $row['position'];
        $employee_data['domain_username'] = $row['domain_username'];
        $employee_data['email_id'] = $row['email_id'];
        $employee_data['o365_edition'] = $row['o365_edition'];
    }
    $stmt->close();
}

if (!empty($checklist_data['prepared_by'])) {
    $technician_data['prepared_by'] = $checklist_data['prepared_by'];
    $technician_data['prepared_at'] = !empty($checklist_data['prepared_at']) ? date('d-m-Y H:i', strtotime($checklist_data['prepared_at'])) : 'N/A';
}
if (!empty($checklist_data['approved_by'])) {
    $technician_data['verified_by'] = $checklist_data['approved_by']; 
    $technician_data['verified_at'] = !empty($checklist_data['approved_at']) ? date('d-m-Y H:i', strtotime($checklist_data['approved_at'])) : 'N/A';
}

$checklist_items_data = [];
$stmt = $conn->prepare("SELECT checklist_category, task_description, status, remarks FROM checklist_items WHERE template_id = ?");
$stmt->bind_param("i", $checklist_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $checklist_items_data[$row['checklist_category']][$row['task_description']] = [
        'status' => $row['status'],
        'remarks' => $row['remarks']
    ];
}
$stmt->close();

$master_checklist_items = [
    'USER ID CREATION' => ['Create AD Credentials','Are all the necessary fields entered','Change Password enable for Next Login','Add user to Default LDAP Group according to the department'],
    'EMAIL ID RELATED' => ['Email ID Creation in O365 with appropriate license','Update the user email ID to the respective Alias group' => 'mention all the Aliases & groups that user is added to'],
    'TEAMS' => ['India Office: Add IT Support, and send test message'],
    'VOICE SERVICES' => ['Arrange a DID & share VM Greeting setup doc','Allot IP Phone Headset & update GLPI','Verify IP Phone TimeZone settings' => 'US DIDs: EST Timezone Settings | PRI DIDs: India Timezone Settings','USREC : Update daily record template with new joiner details' => 'Applicable for US Staffing Team only','Send details to update IT Dashboard'],
    'SYSTEM CONFIGURATION' => [
        'Provide appropriate Hostname for the PC' => 'Populate from DB(Hostname)',
        'Configure User Profile, IM & Outlook email configuration' => 'Populate from DB(Email including Domain)',
        'Install Basic Applications' => '7-Zip, OCS Inventory Agent, Adobe Reader, CiscoAMP Endpoint, Google Chrome, Mozila Firefox, MS Teams, TeamViewer, Umbrella Roaming Client, NS Client ++',
        'Install Department-wise Applications' => 'Install applications specific to the user department',
        'Change Offline settings on Outlook to Maximum',
        'For F3 Only: Configure Edge browser for emails' => 'Applicable only to F3 License O365',
        'MS Office Product Activation' => 'Activate product as per License assigned',
        'Move System to respective OU in AD','Enable RDP for Administrator account & User ID',
        'For Laptop whitelist MAC ID on respective SSID' => 'mention the MAC ID & SSID',
        'Set the Administrator password to default - IMPORTANT','Delete Admin ID if created - IMPORTANT',
        'Assign System in GLPI to user',
        'TIME ZONE SETTINGS' => ['Change system TimeZone settings to EST','Change system TimeZone settings to IST' => 'applicable to all other teams'],
        'NETWORK DRIVE MAPPING' => ['Verify HomeDrive availability' => 'Default applicable to all users except Gaming & US Office','US Office : Create & Map network drive with user\'s name' => 'Applicable for US Office Only','Map respective departmental Share drive, if approved' => 'For New Joiners it is on Approval'],
        'PRINTER INSTALLATION' => ['Install the Printer according to the division/ department','US Office : Install local printer & do a test print' => 'Applicable for US Office Only'],
        'DEFAULT LANGUAGE SETTINGS' => ['Make English-US as the default language','Install Grammarly'],
        'VPN CONFIGURATION' => ['Add user to VPN OU for SSL-VPN' => 'Default applicable only to IT-SMB','Move User to respective OU in AD','FortiToken Mobile Configuration' => 'Ensure MFA is enabled & configured'],
        'CAMERA & PC HEADSET' => ['Install & Configure Camera & Headphone and assign in GLPI' => 'Default applicable to Admin, Facility & Secretaries']
    ],
    'BACKUP READINESS' => ['US OFFICE' => ['Configure Synology & Cobian backup for users' => 'Backup to be configured on gaming Synology Server'],'INDIA OFFICE [only Gaming]' => ['For approved managers only'],'INDIA OFFICE [all except Gaming]' => ['HomeDrive & Share Drive by default']],
    'RECORD MAINTENANCE' => ['ASSET ISSUANCE' => ['Fill out the Asset Issuance Form' => 'Print, take user\'s sign and save a copy in server']]
];

$final_checklist_items = $master_checklist_items;
$purposes_to_filter = ['Asset Replacement', 'Department Change', 'Other'];

if (isset($checklist_data['checklist_purpose']) && in_array(trim($checklist_data['checklist_purpose']), $purposes_to_filter)) {
    unset($final_checklist_items['USER ID CREATION']);
    unset($final_checklist_items['EMAIL ID RELATED']);
    unset($final_checklist_items['TEAMS']);
    unset($final_checklist_items['VOICE SERVICES']);
}


// 3. PDF GENERATION CLASS & LOGIC
// =============================================================================

class PDF extends FPDF {
    function Header() {
        $this->SetLineWidth(0.4);
        $this->SetDrawColor(0, 0, 0);
        $this->Rect(5, 5, 200, 287, 'D');

        if ($this->PageNo() == 1) {
            $logo_path = '../assets/images/favicon.png';
            if (file_exists($logo_path)) {
                $this->Image($logo_path, 15, 8, 26); 
            }
            
            $this->SetFont('Times', 'BU', 16);
            $this->SetTextColor(0);
            $this->Cell(0, 8, 'Sun Technology Integrators Pvt Ltd', 0, 1, 'C');
            $this->SetFont('Times', '', 9);
            $this->Cell(0, 5, 'No.496, 1st stage,4th Block, HBR Layout,Bangalore 560043.', 0, 1, 'C');
            $this->Ln(2);

            $line_y_pos = $this->GetY();
            $this->Line(15, $line_y_pos, 195, $line_y_pos);
            $this->Ln(2);

            $this->SetFont('Arial', 'BU', 12);
            $this->Cell(0, 10, 'System Readiness Checklist', 0, 1, 'C');
            $this->Ln(0); 
        }
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 7);
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
}

$pdf = new PDF();
$pdf->AliasNbPages();

// Increase usable width and center everything using margins
$pdf->SetMargins(7, 10, 7); 
$pdf->SetTopMargin(10); 
$pdf->AddPage();
$pdf->SetY(40); 

// Helper function to draw dynamic height multi-column rows safely
function drawFourColRow($pdf, $lbl1, $val1, $lbl2, $val2, $w) {
    $pdf->SetFont('Arial', '', 9);
    
    // Cast variables to string to prevent any NULL errors
    $s_lbl1 = (string)$lbl1; $s_val1 = (string)$val1;
    $s_lbl2 = (string)$lbl2; $s_val2 = (string)$val2;

    $nb1 = $pdf->NbLines($w[1], $s_val1);
    $nb2 = $pdf->NbLines($w[3], $s_val2);
    $nb3 = $pdf->NbLines($w[0], $s_lbl1);
    $nb4 = $pdf->NbLines($w[2], $s_lbl2);
    
    $nb = max(1, $nb1, $nb2, $nb3, $nb4);
    $h = 6 * $nb;

    $pdf->CheckPageBreak($h);

    $x = $pdf->GetX();
    $y = $pdf->GetY();

    $pdf->Rect($x, $y, $w[0], $h);
    $pdf->Rect($x + $w[0], $y, $w[1], $h);
    $pdf->Rect($x + $w[0] + $w[1], $y, $w[2], $h);
    $pdf->Rect($x + $w[0] + $w[1] + $w[2], $y, $w[3], $h);

    // Cell 1
    $pdf->SetXY($x, $y);
    $pdf->SetFont('Arial', 'B', 9);
    if ($nb3 > 1) { $pdf->MultiCell($w[0], 6, $s_lbl1, 0, 'L'); } else { $pdf->Cell($w[0], $h, $s_lbl1, 0, 0, 'L'); }

    // Cell 2
    $pdf->SetXY($x + $w[0], $y);
    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell($w[1], 6, $s_val1, 0, 'L');

    // Cell 3
    $pdf->SetXY($x + $w[0] + $w[1], $y);
    $pdf->SetFont('Arial', 'B', 9);
    if ($nb4 > 1) { $pdf->MultiCell($w[2], 6, $s_lbl2, 0, 'L'); } else { $pdf->Cell($w[2], $h, $s_lbl2, 0, 0, 'L'); }

    // Cell 4
    $pdf->SetXY($x + $w[0] + $w[1] + $w[2], $y);
    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell($w[3], 6, $s_val2, 0, 'L');

    $pdf->SetXY($x, $y + $h);
}

// Set up table bounds
$pdf->SetFillColor(255, 255, 255);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetDrawColor(128, 0, 0); 
$pdf->SetLineWidth(.3);

// Increased table width to 196 (from 190) and heavily redistributed column widths 
// to make room for very long text in Value columns like "MS Office Version"
$w_details = [46, 56, 32, 62]; 

// Employee Details Table
drawFourColRow($pdf, 'Employee Name:', $employee_data['full_name'], 'Employee ID:', $employee_data['emp_id'], $w_details);
drawFourColRow($pdf, 'Effective Date:', $employee_data['joining_date'], 'Department:', $employee_data['department'], $w_details);
drawFourColRow($pdf, 'Position:', $employee_data['position'], 'Purpose:', $checklist_data['checklist_purpose'], $w_details);
$pdf->Ln(5);

// System and Technician Details Table
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(230, 230, 230);
$pdf->Cell($w_details[0] + $w_details[1], 7, 'System Details', 1, 0, 'C', true);
$pdf->Cell($w_details[2] + $w_details[3], 7, 'Technician Details', 1, 1, 'C', true);

drawFourColRow($pdf, 'Hostname', $checklist_data['hostname'], 'Prepared By', $technician_data['prepared_by'], $w_details);
drawFourColRow($pdf, 'Serial Number', $checklist_data['serial_number'], 'Date', $technician_data['prepared_at'], $w_details);
drawFourColRow($pdf, 'System Model', $checklist_data['system_model'], 'Verified By', $technician_data['verified_by'], $w_details);
drawFourColRow($pdf, 'Processor | RAM | HDD/SSD', $checklist_data['processor_ram_hdd_ssd'], 'Date', $technician_data['verified_at'], $w_details);
drawFourColRow($pdf, 'Operating System', $checklist_data['operating_system'], 'MS Office Version', $checklist_data['ms_office_version'], $w_details);
$pdf->Ln(5);

// Checklist Table
$header = array('Related Task', 'Status', 'Remarks');
$w = array(92, 28, 76); // Adjusted to sum to exactly 196 like the top tables

function drawTableHeader($pdf, $header, $w) {
    $pdf->SetFillColor(230, 230, 230);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetDrawColor(128, 0, 0);
    for($i=0; $i<count($header); $i++) {
        $pdf->Cell($w[$i], 7, $header[$i], 1, 0, 'C', true);
    }
    $pdf->Ln();
}

drawTableHeader($pdf, $header, $w);

function drawChecklistRows($pdf, $items, $db_items, $w, $category_context = [], $checklist_row = [], $employee_row = []) {
    foreach ($items as $key => $value) {
        if (is_array($value)) {
            $category_name = is_numeric($key) ? $value[0] : $key;
            $new_context = array_merge($category_context, [$category_name]);
            if ($pdf->CheckPageBreak(8 + 6)) { 
                drawTableHeader($pdf, ['Related Task', 'Status', 'Remarks'], $w);
            }
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetFillColor(240, 240, 240);
            
            // LEFT ALIGNED CATEGORY
            $pdf->Cell(array_sum($w), 8, '  ' . $category_name, 1, 1, 'L', true); 
            
            drawChecklistRows($pdf, $value, $db_items, $w, $new_context, $checklist_row, $employee_row);
        } else {
            $task_desc = is_numeric($key) ? $value : $key;
            $top_level_category = !empty($category_context) ? $category_context[0] : 'UNKNOWN_CATEGORY';
            
            $status = $db_items[$top_level_category][$task_desc]['status'] ?? 'Pending';
            $default_remark = is_numeric($key) ? '' : $value;
            $remarks = $db_items[$top_level_category][$task_desc]['remarks'] ?? '';

            if (empty($remarks)) {
                if ($task_desc === 'Create AD Credentials' && !empty($employee_row['domain_username'])) {
                    $remarks = $employee_row['domain_username'];
                    $status = 'Completed';
                } elseif ($task_desc === 'Email ID Creation in O365 with appropriate license' && !empty($employee_row['o365_edition'])) {
                    $remarks = $employee_row['o365_edition'];
                    $status = 'Completed';
                } elseif ($task_desc === 'Configure User Profile, IM & Outlook email configuration' && !empty($employee_row['email_id'])) {
                    $remarks = $employee_row['email_id'];
                    $status = 'Completed';
                } else {
                    $remarks = $default_remark;
                }
            }

            // System Config Explicit Override
            if ($task_desc === 'Provide appropriate Hostname for the PC') {
                $remarks = $checklist_row['hostname'] ?? ''; 
                $status = !empty($remarks) ? 'Completed' : 'Pending';
            }

            // --- NEW: ASSET ISSUANCE FORM OVERRIDE ---
            if ($task_desc === 'Fill out the Asset Issuance Form') {
                if (isset($checklist_row['user_acceptance_status']) && $checklist_row['user_acceptance_status'] === 'Accepted') {
                    $status = 'Completed';
                    $ua_by = $checklist_row['user_acceptance_by'] ?? 'User';
                    $ua_date = !empty($checklist_row['user_acceptance_date']) ? date('d-M-Y h:i A', strtotime($checklist_row['user_acceptance_date'])) : '';
                    $remarks = "Digitally Accepted by User\n" . $ua_by . "\n" . $ua_date;
                } else {
                    $status = 'Pending';
                    $remarks = "Waiting for User to Complete the Digital Sign.";
                }
            }

            $pdf->SetFont('Arial', '', 9);

            // Calculate height based on Task Description and Remarks
            $nb_task = $pdf->NbLines($w[0], $task_desc);
            $nb_remarks = $pdf->NbLines($w[2], $remarks);
            
            $nb = max($nb_task, $nb_remarks, 1);
            $h = 6 * $nb; 

            if ($pdf->CheckPageBreak($h)) {
                drawTableHeader($pdf, ['Related Task', 'Status', 'Remarks'], $w);
                $pdf->SetFont('Arial', '', 9);
            }

            $x = $pdf->GetX();
            $y = $pdf->GetY();
            
            $pdf->Rect($x, $y, $w[0], $h);
            $pdf->Rect($x + $w[0], $y, $w[1], $h);
            $pdf->Rect($x + $w[0] + $w[1], $y, $w[2], $h);
            
            $pdf->SetXY($x, $y);
            $pdf->MultiCell($w[0], 6, $task_desc, 0, 'L');
            
            $pdf->SetXY($x + $w[0], $y);
            $pdf->Cell($w[1], $h, $status, 0, 0, 'C');
            
            $pdf->SetXY($x + $w[0] + $w[1], $y);
            $pdf->MultiCell($w[2], 6, $remarks, 0, 'L');
            
            $pdf->SetXY($x, $y + $h);
        }
    }
}

drawChecklistRows($pdf, $final_checklist_items, $checklist_items_data, $w, [], $checklist_data, $employee_data);

$pdf->Output('I', 'System_Readiness_Checklist_' . preg_replace('/[^a-zA-Z0-9]/', '_', $employee_data['full_name']) . '.pdf');
?>