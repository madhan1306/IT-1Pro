<?php
// FILE: generate_report.php

// Suppress errors for clean CSV output
include_once '../includes/error_handler.php';
include_once '../includes/db_connect.php';
include_once '../includes/auth.php';

// Use the standard permission check.
checkPermission('view_reports');

// --- Main Script Logic ---
if ($_SERVER['REQUEST_METHOD'] !== 'GET' || !isset($_GET['report_type'])) {
    die("Invalid request: Report type is missing.");
}

$report_type = $_GET['report_type'];
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$date_type = $_GET['date_type'] ?? 'created_date';
$department_filter = $_GET['department_filter'] ?? '';
$status_filter = $_GET['status_filter'] ?? '';

// Set headers for CSV download
$filename = $report_type . "_report_" . date('Ymd_His') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '";');

$output = fopen('php://output', 'w');

// Add UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

$sql = '';
$whereClauses = []; 
$params = []; 
$types = "";

switch ($report_type) {
    case 'template_details':
        // ENHANCED: Headers matching the new UI columns
        fputcsv($output, ['Emp ID', 'First Name', 'Last Name', 'Department', 'DOJ', 'Status', 'Requested By', 'Requested At', 'Approved By', 'Approved At']);
        
        // SQL matching the Reports UI logic
        $sql = "SELECT emp_id, first_name, last_name, department, date_of_joining, employee_status, 
                       requested_by, requested_at, created_by as approved_by, created_at as approved_at 
                FROM employees";
        
        $date_column = ($date_type === 'joining_date') ? 'date_of_joining' : 'created_at';

        if (!empty($start_date) && !empty($end_date)) { $whereClauses[] = "$date_column BETWEEN ? AND ?"; $types .= "ss"; array_push($params, $start_date, $end_date . ' 23:59:59'); }
        if (!empty($department_filter)) { $whereClauses[] = "department = ?"; $types .= "s"; $params[] = $department_filter; }
        if (!empty($status_filter)) { $whereClauses[] = "employee_status = ?"; $types .= "s"; $params[] = $status_filter; }
        
        $sql .= (!empty($whereClauses) ? " WHERE " . implode(" AND ", $whereClauses) : "") . " ORDER BY created_at DESC";
        break;

    case 'system_readiness':
        fputcsv($output, ['Emp ID', 'Full Name', 'Department', 'Date of Joining', 'Overall Status', 'Assigned To', 'Created At']);

        $sql = "SELECT e.emp_id, CONCAT(e.first_name, ' ', e.last_name) as full_name, e.department, e.date_of_joining, c.overall_status, c.assigned_to, c.created_at 
                FROM checklists c 
                JOIN employees e ON c.employee_id = e.id";
        $whereClauses[] = "c.checklist_type = 'system_readiness'";
        $date_column = ($date_type === 'joining_date') ? 'e.date_of_joining' : 'c.created_at';

        if (!empty($start_date) && !empty($end_date)) { $whereClauses[] = "$date_column BETWEEN ? AND ?"; $types .= "ss"; array_push($params, $start_date, $end_date . ' 23:59:59'); }
        if (!empty($department_filter)) { $whereClauses[] = "e.department = ?"; $types .= "s"; $params[] = $department_filter; }
        if (!empty($status_filter)) { $whereClauses[] = "c.overall_status = ?"; $types .= "s"; $params[] = $status_filter; }

        $sql .= " WHERE " . implode(" AND ", $whereClauses) . " ORDER BY c.created_at DESC";
        break;

    case 'exit_checklist':
        fputcsv($output, ['Emp ID', 'Full Name', 'Department', 'Last Working Day', 'Asset Verified By', 'Approved By', 'Overall Status', 'Created At']);

        $sql = "SELECT e.emp_id, CONCAT(e.first_name, ' ', e.last_name) as full_name, e.department, c.last_working_day, c.asset_verified_by, c.approved_by, c.overall_status, c.created_at 
                FROM checklists c 
                JOIN employees e ON c.employee_id = e.id";
        $whereClauses[] = "c.checklist_type = 'exit_checklist'";
        $date_column = ($date_type === 'lwd_date') ? 'c.last_working_day' : 'c.created_at';

        if (!empty($start_date) && !empty($end_date)) { $whereClauses[] = "$date_column BETWEEN ? AND ?"; $types .= "ss"; array_push($params, $start_date, $end_date . ' 23:59:59'); }
        if (!empty($department_filter)) { $whereClauses[] = "e.department = ?"; $types .= "s"; $params[] = $department_filter; }
        if (!empty($status_filter)) { $whereClauses[] = "c.overall_status = ?"; $types .= "s"; $params[] = $status_filter; }

        $sql .= " WHERE " . implode(" AND ", $whereClauses) . " ORDER BY c.created_at DESC";
        break;

    case 'gatepass_report':
        fputcsv($output, ['Gatepass No', 'Type', 'Name', 'Department', 'Purpose', 'Status', 'Out Date', 'Created By', 'Created At']);
        
        $sql = "SELECT gp.gatepass_no, gp.gatepass_type, IF(gp.gatepass_type = 'Vendor', v.vendor_name, gp.employee_name) as name, gp.department, gp.purpose, gp.status, gp.security_out_date, gp.created_by, gp.created_at 
                FROM gatepass_requests gp 
                LEFT JOIN gp_vendors v ON gp.vendor_id = v.id";
        $date_column = 'gp.created_at';

        if (!empty($start_date) && !empty($end_date)) { $whereClauses[] = "$date_column BETWEEN ? AND ?"; $types .= "ss"; array_push($params, $start_date, $end_date . ' 23:59:59'); }
        if (!empty($department_filter)) { $whereClauses[] = "gp.department = ?"; $types .= "s"; $params[] = $department_filter; }
        if (!empty($status_filter)) { $whereClauses[] = "gp.status = ?"; $types .= "s"; $params[] = $status_filter; }

        $sql .= (!empty($whereClauses) ? " WHERE " . implode(" AND ", $whereClauses) : "") . " ORDER BY gp.created_at DESC";
        break;
    
    default:
        fputcsv($output, ['Error: Unknown report type.']);
        exit();
}

// Execute the query and write to CSV
if (!empty($sql)) {
    if ($stmt = $conn->prepare($sql)) {
        if (!empty($types)) { 
            $stmt->bind_param($types, ...$params); 
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            // Handle JSON decoding for assigned users in readiness report
            if ($report_type === 'system_readiness' && isset($row['assigned_to'])) {
                $assigned_users = json_decode($row['assigned_to'], true);
                $row['assigned_to'] = is_array($assigned_users) ? implode(', ', $assigned_users) : '';
            }
            fputcsv($output, $row);
        }
        $stmt->close();
    } else {
        fputcsv($output, ['Error preparing database query: ' . $conn->error]);
    }
}

fclose($output);
exit();