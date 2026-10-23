<?php
// FILE: includes/daily_deactivation_check.php

// CORRECTED: The path should be in the same directory, not one level up.
include_once __DIR__ . '/db_connect.php';

$today = date('Y-m-d');

$sql_select = "SELECT c.employee_id
               FROM checklists c
               JOIN employees e ON c.employee_id = e.id
               WHERE c.checklist_type IN ('exit', 'exit_checklist')
                 AND c.last_working_day <= ?
                 AND e.employee_status = 'Active'";

$stmt_select = $conn->prepare($sql_select);
$stmt_select->bind_param("s", $today);
$stmt_select->execute();
$result = $stmt_select->get_result();
$employees_to_deactivate = $result->fetch_all(MYSQLI_ASSOC);
$stmt_select->close();

if (!empty($employees_to_deactivate)) {
    $sql_update = "UPDATE employees SET employee_status = 'Inactive' WHERE id = ?";
    $stmt_update = $conn->prepare($sql_update);
    foreach ($employees_to_deactivate as $employee) {
        $stmt_update->bind_param("i", $employee['employee_id']);
        $stmt_update->execute();
    }
    $stmt_update->close();
}
// NOTE: We do not close the connection or echo anything in this script.
?>