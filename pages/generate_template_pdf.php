<?php
include_once '../includes/error_handler.php';

// Includes for database connection and authentication
include_once '../includes/db_connect.php';
include_once '../includes/auth.php';

if (!can('download_template_pdf')) {
    header('Content-Type: text/html'); // Ensure the browser renders HTML
    die('<div style="padding: 2rem; text-align: center; font-family: sans-serif; color: #555;">
            <h2>Permission Denied</h2>
            <p>You do not have permission to view this document.</p>
         </div>');
}

// Include the FPDF library
require('../fpdf/fpdf.php'); // Adjust path if necessary

// Extend FPDF to add custom header and footer
class PDF extends FPDF
{
    // Widths for the two columns in the data section
    private $W_LABEL = 60;
    private $W_VALUE = 130;
    private $H_LINE = 8; // Standard line height

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
        $this->SetDrawColor(0, 0, 0); // Light grey for a faded look
        $this->SetLineWidth(0.2);
        $y = $this->GetY();
        $this->Line(10, $y, $this->w - 10, $y); // Line from margin to margin
        $this->SetDrawColor(0, 0, 0); // Restore black draw color
        $this->SetLineWidth(0.3); // Restore default line width
        $this->Ln(2); // Space between line and title

        // Title
        $this->SetFont('Times', 'BU', 12);
        $this->Cell(0, 10, 'New Joiner Template', 0, 1, 'C');
        $this->Ln(3); // Line break to create space between the title and the content
    }

    // Page footer
    function Footer()
    {
        $this->SetY(-15); // Position at 1.5 cm from bottom
        $this->SetFont('Times', 'I', 8); // Changed to Times for consistency
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }

    // Helper function to calculate number of lines for a MultiCell (Original logic preserved)
    function NbLines($w, $txt)
    {
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0) {
            $w = $this->w - $this->rMargin - $this->x;
        }
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n") {
            $nb--;
        }
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") {
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
                continue;
            }
            if ($c == ' ') {
                $sep = $i;
            }
            $l += $cw[$c];
            if ($l > $wmax) {
                if ($sep == -1) {
                    if ($i == $j) {
                        $i++;
                    }
                } else {
                    $i = $sep + 1;
                }
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
            } else {
                $i++;
            }
        }
        return $nl;
    }

    // New helper to draw a single row with correct multi-line height, borders, and coloring
    function DrawDataRow($label, $value, $fill)
    {
        // Standard FPDF encoding fix
        $clean_label = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $label ?? 'N/A');

        // Check for special format to calculate lines correctly
        if (is_array($value) && isset($value['special_format'])) {
            $value_lines = 1; // Assume 1 line for the special case
            $clean_value = ''; // Skip cleaning now, will handle text later
        } else {
            $clean_value = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $value ?? 'N/A');
            $value_lines = $this->NbLines($this->W_VALUE, $clean_value);
        }

        $label_lines = $this->NbLines($this->W_LABEL, $clean_label);
        
        // Calculate the actual height needed
        $row_height = max($label_lines, $value_lines) * $this->H_LINE;

        // Ensure we don't start a row that exceeds the page bottom
        if ($this->GetY() + $row_height > $this->PageBreakTrigger) {
            $this->AddPage($this->CurOrientation);
        }

        // --- Drawing and Text Placement ---

        $x = $this->GetX();
        $y = $this->GetY();

        // 1. Draw the entire background and outer borders for the row
        $this->SetFillColor($fill ? 240 : 255, $fill ? 248 : 255, $fill ? 255 : 255);
        $this->Cell($this->W_LABEL + $this->W_VALUE, $row_height, '', 'LR', 0, 'L', $fill);

        // 2. Manually draw the center divider line
        $this->Line($x + $this->W_LABEL, $y, $x + $this->W_LABEL, $y + $row_height);

        // 3. Go back to the start of the row to write the label text (Left Cell)
        $this->SetXY($x, $y);
        $this->MultiCell($this->W_LABEL, $this->H_LINE, $clean_label, 0, 'L');

        // 4. Set position for value text (Right Cell)
        $this->SetXY($x + $this->W_LABEL, $y);

        if (is_array($value) && isset($value['special_format'])) {
            // Special handling for formatted text (e.g., password with hint)
            $this->SetXY($x + $this->W_LABEL + 2, $y + 2); // Set cursor position with padding

            // Main Text (black, bold)
            $this->SetTextColor(0, 0, 0);
            $this->SetFont('Times', 'B', 10); // Consistent Times font
            $this->Write(5, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $value['main_text']));

            // Light Text (gray, italic, smaller)
            $this->SetTextColor(150, 150, 150);
            $this->SetFont('Times', 'I', 9); // Consistent Times font
            $this->Write(5, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', ' ' . $value['light_text']));

            // Reset styles for the next row
            $this->SetTextColor(0, 0, 0);
            $this->SetFont('Times', '', 10);
        } else {
            // 5. Write standard value text
            $this->MultiCell($this->W_VALUE, $this->H_LINE, $clean_value, 0, 'L');
        }

        // 6. Move cursor to the start of the next line
        $this->SetXY($x, $y + $row_height);
    }

    // Data Section function now delegates row drawing to the helper
    function DataSection($title, $data)
    {
        if (empty($data)) return;

        // Section Header
        $this->SetFont('Times', 'B', 11);
        $this->SetFillColor(230, 230, 230);
        $this->SetTextColor(0);
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(.2);
        $this->Cell($this->W_LABEL + $this->W_VALUE, 7, $title, 1, 1, 'L', true);

        $this->SetFont('Times', '', 10);
        $fill = false;

        foreach ($data as $label => $value) {
            $this->DrawDataRow($label, $value, $fill);
            $fill = !$fill;
        }

        // Draw the bottom border of the section
        $this->Cell($this->W_LABEL + $this->W_VALUE, 0, '', 'T');
        $this->Ln(5); // Space after the section
    }
}

// Use 'id' from URL for consistency
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Error: No valid Employee ID provided in the URL.");
}

$joiner_id = $_GET['id'];

// Fetch employee details from the correct table 'new_employee_details'
$stmt = $conn->prepare("SELECT * FROM employees WHERE id = ?");
if (!$stmt) {
    die("Database query preparation error: " . $conn->error);
}

// NOTE: We assume $conn is available from db_connect.php
// If running this code in isolation, $conn would need to be defined first.

$stmt->bind_param("i", $joiner_id);
$stmt->execute();
$result = $stmt->get_result();
$employee = $result->fetch_assoc();
$stmt->close();

if (!$employee) {
    die("Employee with ID '{$joiner_id}' not found.");
}

// --- PDF Generation ---

$pdf = new PDF();
$pdf->AliasNbPages();
$pdf->AddPage();

// Add overall page border
$pdf->SetLineWidth(0.5); // Reduced border thickness
$pdf->SetDrawColor(0, 0, 0);
$pdf->Rect(5, 5, 200, 287, 'D');
$pdf->SetLineWidth(.3); // Reset to a default for other lines if needed

// Section 1: Personal & Employment Details
$data_employment = [
    'Full Name' => $employee['first_name'] . ' ' . $employee['last_name'],
    'Date of Joining' => !empty($employee['date_of_joining']) ? date('d-m-Y', strtotime($employee['date_of_joining'])) : 'N/A',
    'Department' => ucwords(strtolower($employee['department'])),
    'Position' => $employee['position'],
    'Location' => $employee['location'],
];
$pdf->DataSection('Employment Details', $data_employment);

// Section 2: Domain & Account Details
$data_account = [
    'Domain Username' => 'sti\\'. $employee['domain_username'],
    // Using the same array structure for special formatting
    'Domain Password' => [
        'special_format' => true,
        'main_text'      => $employee['domain_password'],
        'light_text'     => '(User has to change password on first logon)'
    ],
    'Email ID' => $employee['email_id'],
    'Email Password' => !empty($employee['email_password']) ? $employee['email_password'] : 'Pre-Configured',
    'O365 License' => $employee['o365_edition'],
];
$pdf->DataSection('Domain & Account Details', $data_account);

// Section 3: System & Network Details
$data_system = [
    'Workstation' => $employee['workstation'],
    'Network Drives' => $employee['network_drives'],
    'DID' => $employee['did'],
    'MS Teams' => $employee['ms_teams'],
];
$pdf->DataSection('System & Network Details', $data_system);

// Section 4: Technical Assistance and Support Portals (Static Information)
$data_tech_portals = [
    'Support Email' => 'itsupport@suntechnologies.com',
    'Support Phone' => '+1 (678) 203 2484 / +91 9900294928',
    'HRM Portal' => 'http://hrm.sti.com',
    'Helpdesk Portal' => 'https://helpdesk.sti.com',
    'Intranet Portal' => 'https://intranet.sti.com',
];
$pdf->DataSection('Technical Assistance and Support Portals', $data_tech_portals);

// Final PDF Output
$file_name = 'New_Joiner_Template_' . $employee['first_name'] . '_' . $employee['last_name'] . '.pdf';
$pdf->Output('I', $file_name);
?>