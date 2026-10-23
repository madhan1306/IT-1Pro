<?php
// FILE: ajax_search.php

// Corrected path to point up one directory to /includes/
include_once '../includes/db_connect.php';
include_once '../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode([]);
    exit();
}

$searchType = $_GET['type'] ?? '';
$searchTerm = $_GET['term'] ?? '';
$results = [];

if (strlen($searchTerm) < 2 || empty($searchType)) {
    echo json_encode($results);
    exit;
}

$param = "%{$searchTerm}%";

switch ($searchType) {
    case 'employee':
        // FIXED: Query now targets the single, unified 'employees' table.
        $stmt = $conn->prepare(
            "SELECT id, emp_id, first_name, last_name, date_of_joining, position, department, location, email_id
             FROM employees 
             WHERE (CONCAT(first_name, ' ', last_name) LIKE ? OR emp_id LIKE ?)
             AND employee_status = 'Active'"
        );
        $stmt->bind_param("ss", $param, $param);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $fullName = trim($row['first_name'] . ' ' . $row['last_name']);
            
            // Base data structure for jQuery autocomplete
            $results[] = [
                'id'         => $row['id'],
                'label'      => $fullName . ' (' . ($row['emp_id'] ?? 'N/A') . ')',
                'name'       => $fullName, // 'name' is used by the select function
                'emp_id'     => $row['emp_id'] ?? '',
                'department' => $row['department'] ?? '',
                'position'   => $row['position'] ?? '',
                'doj'        => $row['date_of_joining'] ?? '',
                'location'   => $row['location'] ?? ''
                // The 'type' field is no longer needed and has been removed.
            ];
        }
        $stmt->close();
        break;

    case 'vendor':
        // Vendor Search Logic remains unchanged
        $stmt = $conn->prepare(
            "SELECT id, vendor_name, contact_mobile, address 
             FROM gp_vendors 
             WHERE vendor_name LIKE ? AND status = 'Active'"
        );
        $stmt->bind_param("s", $param);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $results[] = [
                'id'      => $row['id'],
                'label'   => $row['vendor_name'],
                'value'   => $row['vendor_name'],
                'mobile'  => $row['contact_mobile'],
                'address' => $row['address']
            ];
        }
        $stmt->close();
        break;
}

$conn->close();
echo json_encode($results);
?>