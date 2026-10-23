<?php
// generate_acceptance_pdf.php
// Generates the signed User Acceptance Form in PDF format

include_once '../includes/error_handler.php';
include_once '../includes/db_connect.php';
include_once '../includes/auth.php';

if (!can('download_user_acceptance_pdf')) {
    header('Content-Type: text/html'); // Ensure the browser renders HTML
    die('<div style="padding: 2rem; text-align: center; font-family: sans-serif; color: #555;">
            <h2>Permission Denied</h2>
            <p>You do not have permission to view this document.</p>
         </div>');
}

require('../fpdf/fpdf.php');

if (!isset($_GET['checklist_id']) || !is_numeric($_GET['checklist_id'])) {
    die("Invalid Checklist ID.");
}

$checklist_id = $_GET['checklist_id'];

// Fetch Data
$sql = "SELECT 
            c.*, 
            e.emp_id, e.first_name, e.last_name, e.department, e.position 
        FROM checklists c 
        JOIN employees e ON c.employee_id = e.id 
        WHERE c.id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $checklist_id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$data) die("Record not found.");
if ($data['user_acceptance_status'] !== 'Accepted') die("Form has not been accepted yet.");

class PDF extends FPDF
{
    private $W_LABEL = 60;
    private $W_VALUE = 130;
    private $H_LINE = 8;

    function Header()
    {
        if (file_exists('../assets/images/favicon.png')) {
            $this->Image('../assets/images/favicon.png', 15, 13, 26);
        }
        $this->SetFont('Times', 'BU', 15);
        $this->SetXY(10, 12);
        $this->Cell(190, 10, 'Sun Technology Integrators Pvt. Ltd.', 0, 1, 'C');
        $this->SetFont('Times', 'B', 8);
        $this->Cell(190, 6, 'No.496, 1st stage,4th Block, HBR Layout,Bangalore 560043.', 0, 1, 'C');
        $this->Ln(4);

        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.2);
        $y = $this->GetY();
        $this->Line(10, $y, $this->w - 10, $y);
        $this->Ln(5);

        $this->SetFont('Times', 'BU', 12);
        $this->Cell(0, 10, 'USER ACCEPTANCE & INDUCTION FORM', 0, 1, 'C');
        $this->Ln(5);
    }

    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Times', 'I', 8);
        $this->Cell(0, 10, 'Generated via IT-One Portal - Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }

    // Helper for table rows
    function DataRow($label, $value)
    {
        $this->SetFont('Times', 'B', 10);
        $this->SetFillColor(245, 245, 245);
        $this->Cell($this->W_LABEL, $this->H_LINE, ' ' . $label, 1, 0, 'L', true);
        
        $this->SetFont('Times', '', 10);
        $this->Cell($this->W_VALUE, $this->H_LINE, ' ' . $value, 1, 1, 'L', false);
    }

    function SectionTitle($title) {
        $this->Ln(5);
        $this->SetFont('Times', 'B', 11);
        $this->SetFillColor(200, 200, 200);
        $this->Cell(190, 8, $title, 1, 1, 'L', true);
    }
}

$pdf = new PDF();
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 15);

// 1. Employee Details (Employee ID Removed)
$pdf->SectionTitle("1. Employee Details");
$pdf->DataRow("Full Name", $data['first_name'] . ' ' . $data['last_name']);
// Employee ID line removed
$pdf->DataRow("Department", $data['department']);
$pdf->DataRow("Position", $data['position']);

// 2. System Information
$pdf->SectionTitle("2. System Information");
$pdf->DataRow("Hostname", $data['hostname']);
$pdf->DataRow("Serial Number", $data['serial_number']);
$pdf->DataRow("Model", $data['system_model']);
$pdf->DataRow("Operating System", $data['operating_system']);

// Check checklist purpose to determine if Induction block is needed
$purposes_to_exclude_induction = ['Asset Replacement', 'Department Change', 'Other', 'Others'];
$is_new_joiner = !in_array($data['checklist_purpose'] ?? '', $purposes_to_exclude_induction);

$section_counter = 3;

// 3. Induction Remarks (ONLY for New Joiners)
if ($is_new_joiner) {
    $pdf->SectionTitle($section_counter . ". Induction Remarks");
    $pdf->DataRow("IT Induction", $data['induction_it_remarks'] ?: 'N/A');
    $pdf->DataRow("HR Induction", $data['induction_hr_remarks'] ?: 'N/A');
    $pdf->DataRow("Facility", $data['induction_admin_remarks'] ?: 'N/A');
    $section_counter++;
}

// 4. (or 3.) Acknowledgement
$pdf->SectionTitle($section_counter . ". Acknowledgement");
$pdf->SetFont('Times', '', 11);

$pdf->Ln(6);
$text1 = "I acknowledge that the above-mentioned asset has been handed over to me in good working condition. I agree to handle the asset with due care and accept full responsibility for any loss, theft, or damage while it is in my custody, in accordance with the policies of ";

$pdf->Write(6, $text1);

$pdf->SetFont('Times', 'BI', 11);
$pdf->Write(6, "Sun Technology Integrators Pvt. Ltd.");

$pdf->SetFont('Times', 'I', 11);
$pdf->Write(6, ".");
$pdf->Ln(6);

// 5. Digital Signature
$pdf->Ln(15);
$pdf->SetDrawColor(12, 93, 88); // Deep Green
$pdf->SetLineWidth(0.5);
$pdf->SetFillColor(238, 251, 249); // Light Green tint

$x = $pdf->GetX();
$y = $pdf->GetY();

$pdf->Rect($x, $y, 190, 40, 'DF');

$pdf->SetXY($x + 5, $y + 5);
$pdf->SetFont('Times', 'B', 10);
$pdf->SetTextColor(12, 93, 88);
$pdf->Cell(100, 6, "DIGITALLY ACCEPTED", 0, 1);

$pdf->SetTextColor(0);
$pdf->SetFont('Times', '', 10);

$pdf->SetX($x + 5);
$pdf->Cell(30, 6, "Signed By:", 0, 0);
$pdf->SetFont('Times', 'B', 10);
$pdf->Cell(100, 6, $data['user_acceptance_by'], 0, 1);

$pdf->SetX($x + 5);
$pdf->SetFont('Times', '', 10);
$pdf->Cell(30, 6, "Date & Time:", 0, 0);
$pdf->SetFont('Times', 'B', 10);
$pdf->Cell(100, 6, date('d-M-Y H:i:s', strtotime($data['user_acceptance_date'])), 0, 1);

$pdf->SetX($x + 5);
$pdf->SetFont('Times', '', 10);
$pdf->Cell(30, 6, "IP Address:", 0, 0);
$pdf->SetFont('Times', 'B', 10);
$pdf->Cell(100, 6, $data['user_acceptance_ip'], 0, 1);

$file_name = 'User_Acceptance_' . ($data['emp_id'] ?? 'Employee') . '.pdf';
$pdf->Output('I', $file_name);
?>