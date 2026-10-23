<?php
// FILE: /pages/get_employee_details.php

include_once '../includes/error_handler.php';
include_once '../includes/db_connect.php';
include_once '../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode([]);
    exit();
}

$term = trim($_GET['term'] ?? '');

if (strlen($term) < 2) {
    echo json_encode([]);
    exit();
}

$employees = [];
$search_param = "%" . $term . "%";

// FIXED: Added 'location' to the SELECT statement
$sql = "SELECT id, emp_id, first_name, last_name, department, position, email_id, location 
        FROM employees 
        WHERE (emp_id LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ?)
        AND employee_status = 'Active'
        LIMIT 10";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("ss", $search_param, $search_param);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $full_name = trim($row['first_name'] . ' ' . $row['last_name']);
        
        // FIXED: Assembling a universal output array that works for all search boxes
        $employees[] = [
            'id'         => $row['id'],
            'label'      => $full_name . ' (' . $row['emp_id'] . ')', // Required by jQuery UI Autocomplete
            'full_name'  => $full_name, // Used by the select function
            'emp_id'     => $row['emp_id'],
            'department' => $row['department'],
            'position'   => $row['position'],
            'email_id'   => $row['email_id'],
            'location'   => $row['location']
        ];
    }
    $stmt->close();
}

echo json_encode($employees);
?>