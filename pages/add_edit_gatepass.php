<?php
// add_edit_gatepass.php

include_once '../includes/error_handler.php';

// 1. Session & Security Headers
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

include_once '../includes/db_connect.php';
include_once '../includes/auth.php';

// --- AJAX HANDLER FOR EMPLOYEE VALIDATION ---
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'check_employee_checklist') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $emp_id = (int)$_POST['employee_user_id'];
    
    // 1. Check for incomplete system readiness checklist
    $sql = "SELECT id FROM checklists WHERE employee_id = ? AND checklist_type = 'system_readiness' AND overall_status != 'Completed' ORDER BY id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $emp_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($row = $res->fetch_assoc()) {
        echo json_encode(['status' => 'incomplete_checklist', 'checklist_id' => $row['id']]);
        $stmt->close();
        exit;
    }
    $stmt->close();

    // 2. Check for an existing Gatepass that is 'Pending Approval'
    $sql_gp = "SELECT gatepass_no FROM gatepass_requests WHERE employee_user_id = ? AND status = 'Pending Approval' LIMIT 1";
    $stmt_gp = $conn->prepare($sql_gp);
    $stmt_gp->bind_param("i", $emp_id);
    $stmt_gp->execute();
    $res_gp = $stmt_gp->get_result();

    if ($row_gp = $res_gp->fetch_assoc()) {
        echo json_encode(['status' => 'pending_gatepass', 'gatepass_no' => $row_gp['gatepass_no']]);
        $stmt_gp->close();
        exit;
    }
    $stmt_gp->close();

    echo json_encode(['status' => 'clear']);
    exit;
}

/**
 * Logs a general system action.
 */
function log_action($conn, $action_type, $description) {
    $performed_by = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? ''));
    if (empty($performed_by)) {
        $performed_by = $_SESSION['username'] ?? 'System/Unknown';
    }
    try {
        $stmt = $conn->prepare("INSERT INTO system_logs (performed_by, action_type, description) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $performed_by, $action_type, $description);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {}
}

/**
 * Logs activity back to a specific checklist's timeline.
 */
function log_checklist_activity($conn, $template_id, $performed_by, $action_description, $field_changed = 'Gatepass Status', $old_value = null, $new_value = null) {
    $stmt_get_parent = $conn->prepare("SELECT employee_id FROM checklists WHERE id = ?");
    if (!$stmt_get_parent) return;

    $stmt_get_parent->bind_param("i", $template_id);
    $stmt_get_parent->execute();
    $result = $stmt_get_parent->get_result();
    if ($row = $result->fetch_assoc()) {
        $joiner_id = $row['employee_id'];
        if (!empty($joiner_id)) {
            $sql = "INSERT INTO action_logs (joiner_id, template_id, performed_by, action_description, field_changed, old_value, new_value) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt_log = $conn->prepare($sql);
            if ($stmt_log) {
                $stmt_log->bind_param("iisssss", $joiner_id, $template_id, $performed_by, $action_description, $field_changed, $old_value, $new_value);
                $stmt_log->execute();
                $stmt_log->close();
            } 
        } 
    }
    $stmt_get_parent->close();
}

// --- GATEPASS DELETION LOGIC ---
if (isset($_GET['delete_id']) && is_numeric($_GET['delete_id']) && can('delete_gatepass')) {
    $gatepass_id_to_delete = (int)$_GET['delete_id'];
    $logged_in_user_fullname_delete = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')) ?: ($_SESSION['username'] ?? 'System');

    $stmt_find_checklist = $conn->prepare("SELECT id FROM checklists WHERE gatepass_id = ?");
    $stmt_find_checklist->bind_param("i", $gatepass_id_to_delete);
    $stmt_find_checklist->execute();
    $checklist_result = $stmt_find_checklist->get_result();
    $checklist_id_to_log = null;
    if ($checklist_row = $checklist_result->fetch_assoc()) {
        $checklist_id_to_log = $checklist_row['id'];
    }
    $stmt_find_checklist->close();

    $conn->begin_transaction();
    try {
        $stmt_get_no = $conn->prepare("SELECT gatepass_no FROM gatepass_requests WHERE id = ?");
        $stmt_get_no->bind_param("i", $gatepass_id_to_delete);
        $stmt_get_no->execute();
        $gatepass_no_to_log = $stmt_get_no->get_result()->fetch_assoc()['gatepass_no'] ?? 'ID ' . $gatepass_id_to_delete;
        $stmt_get_no->close();

        $stmt_del_items = $conn->prepare("DELETE FROM gatepass_items WHERE gatepass_id = ?");
        $stmt_del_items->bind_param("i", $gatepass_id_to_delete);
        $stmt_del_items->execute();
        $stmt_del_items->close();

        $stmt_del_gp = $conn->prepare("DELETE FROM gatepass_requests WHERE id = ?");
        $stmt_del_gp->bind_param("i", $gatepass_id_to_delete);
        $stmt_del_gp->execute();
        $stmt_del_gp->close();
        
        log_action($conn, 'Gatepass', "Deleted Gatepass: " . $gatepass_no_to_log);
        
        if ($checklist_id_to_log) {
            $stmt_update_checklist = $conn->prepare("UPDATE checklists SET gatepass_id = NULL WHERE id = ?");
            $stmt_update_checklist->bind_param("i", $checklist_id_to_log);
            $stmt_update_checklist->execute();
            $stmt_update_checklist->close();

            $log_description = "Gatepass " . $gatepass_no_to_log . " was deleted.";
            log_checklist_activity($conn, $checklist_id_to_log, $logged_in_user_fullname_delete, $log_description, 'Gatepass Status', 'Created', 'Deleted');
        }

        $conn->commit();
        $_SESSION['page_message'] = ['type' => 'success', 'text' => 'Gatepass deleted successfully!', 'context' => 'gatepass'];
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['page_message'] = ['type' => 'danger', 'text' => "An error occurred while deleting: " . $e->getMessage(), 'context' => 'gatepass'];
    }
    
    header('Location: ' . APP_BASE_PATH . 'index.php?route=gatepass');
    exit();
}


// --- POST REQUEST HANDLING (SAVE, APPROVE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // CSRF Check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Security Validation Failed: Invalid CSRF Token.");
    }

    $conn->begin_transaction();
    $logged_in_user_fullname = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')) ?: ($_SESSION['username'] ?? 'System');
    
    try {
        $gatepass_id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        
        // This is only populated if creating a brand new gatepass actively from the checklist
        $from_checklist_id = isset($_POST['from_checklist_id']) && is_numeric($_POST['from_checklist_id']) ? (int)$_POST['from_checklist_id'] : null;

        // Fetch checklist_id for logging if we are editing an existing gatepass
        $checklist_id_to_update = null;
        if ($gatepass_id) {
            $stmt_find_link = $conn->prepare("SELECT id FROM checklists WHERE gatepass_id = ?");
            if ($stmt_find_link) {
                $stmt_find_link->bind_param("i", $gatepass_id);
                $stmt_find_link->execute();
                if ($link_row = $stmt_find_link->get_result()->fetch_assoc()) {
                    $checklist_id_to_update = $link_row['id'];
                }
                $stmt_find_link->close();
            }
        }

        if (isset($_POST['approve_gatepass']) && $gatepass_id && can('approve_gatepass')) {
            $approved_at = date('Y-m-d H:i:s');
            $approve_stmt = $conn->prepare("UPDATE gatepass_requests SET status = 'Approved', approved_by = ?, approved_at = ? WHERE id = ?");
            $approve_stmt->bind_param("ssi", $logged_in_user_fullname, $approved_at, $gatepass_id);
            $approve_stmt->execute();
            $approve_stmt->close();

            $stmt_get_no = $conn->prepare("SELECT gatepass_no FROM gatepass_requests WHERE id = ?");
            $stmt_get_no->bind_param("i", $gatepass_id);
            $stmt_get_no->execute();
            $gatepass_no_to_log = $stmt_get_no->get_result()->fetch_assoc()['gatepass_no'] ?? 'ID ' . $gatepass_id;
            $stmt_get_no->close();
            log_action($conn, 'Gatepass', "Approved Gatepass: " . $gatepass_no_to_log);
            
            if ($checklist_id_to_update) {
                $log_description = "Gatepass " . $gatepass_no_to_log . " has been approved.";
                log_checklist_activity($conn, $checklist_id_to_update, $logged_in_user_fullname, $log_description, 'Gatepass Status', 'Pending Approval', 'Approved');
            }
            
            $_SESSION['page_message'] = ['type' => 'success', 'text' => 'Gatepass approved successfully!', 'context' => 'gatepass'];

        } elseif (isset($_POST['save_gatepass'])) {
            $gatepass_type = $_POST['gatepass_type'];
            $ticket_no = trim($_POST['ticket_no'] ?? null);
            $reason = $_POST['reason'] ?? null;
            $prepared_by = $_POST['prepared_by'] ?? null;
            $approved_by = $_POST['approved_by'] ?? null;
            
            $department = $employee_name = $employee_user_id = $date_of_joining = $position = $vendor_id = $pickup_person = null;
            $from_location = $to_location = $requested_by = $received_by = null;

            if ($gatepass_type === 'Employee') {
                $purpose = 'Employee Asset - WFH';
                $department = $_POST['department'] ?? null;
                $employee_name = trim($_POST['employee_name'] ?? '');
                $employee_user_id = !empty($_POST['employee_user_id']) ? (int)$_POST['employee_user_id'] : null;
                $date_of_joining = !empty($_POST['date_of_joining']) ? $_POST['date_of_joining'] : null;
                $position = $_POST['employee_position'] ?? null;

                // FIX: Look up Employee ID if it was lost in the Checklist Redirect transition
                if (!$employee_user_id && $from_checklist_id) {
                    $stmt_emp = $conn->prepare("SELECT employee_id FROM checklists WHERE id = ?");
                    $stmt_emp->bind_param("i", $from_checklist_id);
                    $stmt_emp->execute();
                    if ($row_emp = $stmt_emp->get_result()->fetch_assoc()) {
                        $employee_user_id = $row_emp['employee_id'];
                    }
                    $stmt_emp->close();
                }

                // Fallback: Look up by employee name
                if (!$employee_user_id && !empty($employee_name)) {
                    $stmt_emp = $conn->prepare("SELECT id FROM employees WHERE TRIM(CONCAT(first_name, ' ', last_name)) = ? LIMIT 1");
                    $stmt_emp->bind_param("s", $employee_name);
                    $stmt_emp->execute();
                    if ($row_emp = $stmt_emp->get_result()->fetch_assoc()) {
                        $employee_user_id = $row_emp['id'];
                    }
                    $stmt_emp->close();
                }

                // Strictly enforce employee ID resolution to prevent bad inserts
                if (!$employee_user_id) {
                    throw new Exception("Error: Could not link to a valid Employee Profile. Please use the search dropdown to select the employee.");
                }
                
                // --- STRICT SERVER-SIDE VALIDATION: Block if incomplete checklist exists ---
                $check_sql = "SELECT id FROM checklists WHERE employee_id = ? AND checklist_type = 'system_readiness' AND overall_status != 'Completed' LIMIT 1";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("i", $employee_user_id);
                $check_stmt->execute();
                if ($check_stmt->get_result()->num_rows > 0) {
                    throw new Exception("Cannot process Gatepass: The selected employee currently has an incomplete System Readiness Checklist.");
                }
                $check_stmt->close();
                
                // --- STRICT SERVER-SIDE VALIDATION: Block if they already have a Pending Gatepass ---
                if (!$gatepass_id) { // Only run duplicate check if creating a brand NEW gatepass
                    $check_gp_sql = "SELECT gatepass_no FROM gatepass_requests WHERE employee_user_id = ? AND status = 'Pending Approval' LIMIT 1";
                    $check_gp_stmt = $conn->prepare($check_gp_sql);
                    $check_gp_stmt->bind_param("i", $employee_user_id);
                    $check_gp_stmt->execute();
                    $gp_res = $check_gp_stmt->get_result();
                    if ($gp_res->num_rows > 0) {
                        $existing_gp = $gp_res->fetch_assoc()['gatepass_no'];
                        throw new Exception("Cannot create Gatepass: This employee already has a Gatepass ({$existing_gp}) that is Pending Approval.");
                    }
                    $check_gp_stmt->close();
                }
                
            } elseif ($gatepass_type === 'Vendor') {
                $purpose = 'Repair/Service';
                $vendor_id = !empty($_POST['vendor_id']) ? (int)$_POST['vendor_id'] : null;
                $pickup_person = $_POST['pickup_person'] ?? null;
                $vendor_name = trim($_POST['vendor_name'] ?? '');
                if ($vendor_id) { 
                    $update_vendor_stmt = $conn->prepare("UPDATE gp_vendors SET vendor_name = ?, contact_mobile = ?, address = ?, pickup_person = ? WHERE id = ?");
                    if ($update_vendor_stmt === false) { throw new Exception("Failed to prepare vendor update statement: " . $conn->error); }
                    $update_vendor_stmt->bind_param("ssssi", $_POST['vendor_name'], $_POST['vendor_mobile'], $_POST['vendor_address'], $pickup_person, $vendor_id);
                    $update_vendor_stmt->execute();
                    $update_vendor_stmt->close();
                } elseif (!empty($vendor_name)) {
                    $check_stmt = $conn->prepare("SELECT id FROM gp_vendors WHERE vendor_name = ?");
                    $check_stmt->bind_param("s", $vendor_name);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    if ($check_result->num_rows > 0) {
                        $existing_vendor = $check_result->fetch_assoc();
                        $vendor_id = $existing_vendor['id'];
                    } else {
                        $new_vendor_stmt = $conn->prepare("INSERT INTO gp_vendors (vendor_name, contact_mobile, address, pickup_person) VALUES (?, ?, ?, ?)");
                        if ($new_vendor_stmt === false) { throw new Exception("Failed to prepare vendor insert statement: " . $conn->error); }
                        $new_vendor_stmt->bind_param("ssss", $_POST['vendor_name'], $_POST['vendor_mobile'], $_POST['vendor_address'], $pickup_person);
                        $new_vendor_stmt->execute();
                        $vendor_id = $conn->insert_id;
                        $new_vendor_stmt->close();
                    }
                    $check_stmt->close();
                }
            } elseif ($gatepass_type === 'Site-to-Site') {
                $purpose = 'Internal Asset Movement';
                $from_location = $_POST['from_location'] ?? null;
                $to_location = $_POST['to_location'] ?? null;
                $requested_by = $_POST['requested_by'] ?? null;
                $received_by = $_POST['received_by'] ?? null;
            }

            $gatepass_no = 'N/A';
            if ($gatepass_id) { // --- UPDATE ---
                $main_sql = "UPDATE gatepass_requests SET gatepass_type=?, ticket_no=?, department=?, employee_name=?, employee_user_id=?, date_of_joining=?, `position`=?, vendor_id=?, pickup_person=?, reason=?, purpose=?, prepared_by=?, approved_by=?, from_location=?, to_location=?, requested_by=?, received_by=? WHERE id=?";
                $stmt_main = $conn->prepare($main_sql);
                if ($stmt_main === false) { throw new Exception("Failed to prepare main update statement: " . $conn->error); }
                $stmt_main->bind_param("ssssississsssssssi", $gatepass_type, $ticket_no, $department, $employee_name, $employee_user_id, $date_of_joining, $position, $vendor_id, $pickup_person, $reason, $purpose, $prepared_by, $approved_by, $from_location, $to_location, $requested_by, $received_by, $gatepass_id);
                $stmt_main->execute();
                $stmt_main->close();

                if (can('edit_security_fields')) {
                    if ($gatepass_type === 'Site-to-Site') {
                        $security_out_time = !empty($_POST['security_out_time_s2s']) ? $_POST['security_out_time_s2s'] : null;
                        $security_out_date = !empty($_POST['security_out_date_s2s']) ? $_POST['security_out_date_s2s'] : null;
                        $security_person_name = $_POST['security_person_name_s2s'] ?? null;
                        $security_verified_by = $_POST['security_verified_by_s2s'] ?? null;
                        $security_in_time = !empty($_POST['security_in_time']) ? $_POST['security_in_time'] : null;
                        $security_in_date = !empty($_POST['security_in_date']) ? $_POST['security_in_date'] : null;
                        $security_in_person_name = $_POST['security_in_person_name'] ?? null;
                        $security_in_verified_by = $_POST['security_in_verified_by'] ?? null;
                    } else {
                        $security_out_time = !empty($_POST['security_out_time']) ? $_POST['security_out_time'] : null;
                        $security_out_date = !empty($_POST['security_out_date']) ? $_POST['security_out_date'] : null;
                        $security_person_name = $_POST['security_person_name'] ?? null;
                        $security_verified_by = $_POST['security_verified_by'] ?? null;
                        $security_in_time = $security_in_date = $security_in_person_name = $security_in_verified_by = null;
                    }
                    
                    $security_sql = "UPDATE gatepass_requests SET security_out_time=?, security_out_date=?, security_person_name=?, security_verified_by=?, security_in_time=?, security_in_date=?, security_in_person_name=?, security_in_verified_by=? WHERE id=?";
                    $stmt_security = $conn->prepare($security_sql);
                    if ($stmt_security === false) { throw new Exception("Failed to prepare security update statement: " . $conn->error); }
                    $stmt_security->bind_param("ssssssssi", $security_out_time, $security_out_date, $security_person_name, $security_verified_by, $security_in_time, $security_in_date, $security_in_person_name, $security_in_verified_by, $gatepass_id);
                    $stmt_security->execute();
                    $stmt_security->close();
                }
                
                $stmt_get_no = $conn->prepare("SELECT gatepass_no FROM gatepass_requests WHERE id = ?");
                $stmt_get_no->bind_param("i", $gatepass_id);
                $stmt_get_no->execute();
                $gatepass_no = $stmt_get_no->get_result()->fetch_assoc()['gatepass_no'] ?? 'ID ' . $gatepass_id;
                $stmt_get_no->close();
                log_action($conn, 'Gatepass', "Edited Gatepass: " . $gatepass_no);

                if ($checklist_id_to_update) {
                    $log_description = "Gatepass " . $gatepass_no . " details were updated.";
                    log_checklist_activity($conn, $checklist_id_to_update, $logged_in_user_fullname, $log_description, 'Gatepass Details', 'Updated', 'Updated');
                }

            } else { // --- INSERT ---
                $year = date('Y');
                $stmt_num = $conn->prepare("SELECT MAX(CAST(SUBSTRING(gatepass_no, 10) AS UNSIGNED)) as max_num FROM gatepass_requests WHERE gatepass_no LIKE ?");
                $prefix = "GP-$year-";
                $prefix_like = $prefix . '%';
                $stmt_num->bind_param("s", $prefix_like);
                $stmt_num->execute();
                $result = $stmt_num->get_result()->fetch_assoc();
                $stmt_num->close();
                $next_num = ($result['max_num'] ?? 0) + 1;
                $gatepass_no = $prefix . str_pad($next_num, 4, '0', STR_PAD_LEFT);

                $sql = "INSERT INTO gatepass_requests (gatepass_no, gatepass_type, ticket_no, department, employee_name, employee_user_id, date_of_joining, `position`, vendor_id, pickup_person, reason, purpose, created_by, prepared_by, approved_by, from_location, to_location, requested_by, received_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                if ($stmt === false) { throw new Exception("Failed to prepare insert statement: " . $conn->error); }
                $stmt->bind_param("sssssisssisssssssss", $gatepass_no, $gatepass_type, $ticket_no, $department, $employee_name, $employee_user_id, $date_of_joining, $position, $vendor_id, $pickup_person, $reason, $purpose, $logged_in_user_fullname, $prepared_by, $approved_by, $from_location, $to_location, $requested_by, $received_by);
                $stmt->execute();
                $gatepass_id = $conn->insert_id;
                $stmt->close();
                
                log_action($conn, 'Gatepass', "Created Gatepass: " . $gatepass_no);

                // If explicitly requested from a checklist
                if ($from_checklist_id && $gatepass_id) {
                    $update_checklist_stmt = $conn->prepare("UPDATE checklists SET gatepass_id = ? WHERE id = ?");
                    $update_checklist_stmt->bind_param("ii", $gatepass_id, $from_checklist_id);
                    $update_checklist_stmt->execute();
                    $update_checklist_stmt->close();

                    $log_description = "Gatepass " . $gatepass_no . " created. Status: Pending Approval";
                    log_checklist_activity($conn, $from_checklist_id, $logged_in_user_fullname, $log_description, 'Gatepass Status', 'Not Created', 'Created');
                }
            }
            
            if (!isset($_POST['is_locked']) || $_POST['is_locked'] != '1' || can('edit_security_fields')) {
                $delete_items_stmt = $conn->prepare("DELETE FROM gatepass_items WHERE gatepass_id = ?");
                $delete_items_stmt->bind_param("i", $gatepass_id);
                $delete_items_stmt->execute();
                $delete_items_stmt->close();
                
                if (isset($_POST['items']) && is_array($_POST['items'])) {
                    $item_sql = "INSERT INTO gatepass_items (gatepass_id, description, quantity, remarks) VALUES (?, ?, ?, ?)";
                    $item_stmt = $conn->prepare($item_sql);
                    if ($item_stmt === false) { throw new Exception("Failed to prepare item insert statement: " . $conn->error); }
                    foreach ($_POST['items'] as $item) {
                        if (!empty($item['description'])) {
                            $qty = !empty($item['quantity']) ? (int)$item['quantity'] : 1;
                            $remarks = $item['remarks'] ?? '';
                            $item_stmt->bind_param("isss", $gatepass_id, $item['description'], $qty, $remarks);
                            $item_stmt->execute();
                        }
                    }
                    $item_stmt->close();
                }
            }
            
            $_SESSION['page_message'] = ['type' => 'success', 'text' => 'Gatepass saved successfully!', 'context' => 'gatepass'];
        }

        $conn->commit();
        
        // --- REDIRECT LOGIC ---
        if (isset($_POST['create_shipment_after']) && $_POST['create_shipment_after'] === 'true' && !empty($gatepass_id)) {
            header('Location: ' . APP_BASE_PATH . 'index.php?route=add_edit_shipment&from_gatepass_id=' . $gatepass_id);
        } elseif ($from_checklist_id) {
            // ONLY redirect to checklist if explicitly CREATED from the checklist
            $_SESSION['form_success'] = $_SESSION['page_message']['text'] ?? 'Gatepass saved successfully!';
            unset($_SESSION['page_message']);
            
            $stmt_chk_type = $conn->prepare("SELECT checklist_type FROM checklists WHERE id = ?");
            $stmt_chk_type->bind_param("i", $from_checklist_id);
            $stmt_chk_type->execute();
            $chk_type_res = $stmt_chk_type->get_result()->fetch_assoc();
            $stmt_chk_type->close();
            
            if ($chk_type_res && $chk_type_res['checklist_type'] == 'exit_checklist') {
                header('Location: ' . APP_BASE_PATH . 'index.php?route=add_edit_exit_checklist&id=' . $from_checklist_id);
            } else {
                header('Location: ' . APP_BASE_PATH . 'index.php?route=system_readiness_checklist&checklist_id=' . $from_checklist_id);
            }
        } else {
            // Standard edit/save
            header('Location: ' . APP_BASE_PATH . 'index.php?route=gatepass');
        }
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['page_message'] = ['type' => 'danger', 'text' => $e->getMessage(), 'context' => 'gatepass'];
        header("Location: " . APP_BASE_PATH . "index.php?route=add_edit_gatepass" . ($gatepass_id ? "&id=$gatepass_id" : ""));
        exit();
    }
}

// --- PAGE LOAD LOGIC (GET REQUEST) ---
$is_editing = isset($_GET['id']) && is_numeric($_GET['id']);
$is_from_checklist = isset($_GET['from_checklist_id']) && is_numeric($_GET['from_checklist_id']);
$linked_checklist_id = null;

$gatepass = [
    'id' => null, 'gatepass_no' => 'Auto Generated', 'gatepass_type' => 'Vendor', 'ticket_no' => '', 'employee_name' => '', 'employee_user_id' => null,
    'department' => '', 'date_of_joining' => '', 'position' => '', 'vendor_id' => null,
    'purpose' => '', 'reason' => '', 'prepared_by' => trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')), 'approved_by' => '', 'status' => 'Pending Approval', 'approved_at' => null,
    'security_out_time' => '', 'security_out_date' => '', 'security_person_name' => '', 'security_verified_by' => '',
    'pickup_person' => '',
    'from_location' => '', 'to_location' => '', 'requested_by' => '', 'received_by' => '',
    'security_in_time' => '', 'security_in_date' => '', 'security_in_person_name' => '', 'security_in_verified_by' => ''
];
$gatepass_items = [];
$vendor_details = ['vendor_name' => '', 'contact_mobile' => '', 'address' => '', 'pickup_person' => ''];

if ($is_editing) {
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM gatepass_requests WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 1) {
        $gatepass = $result->fetch_assoc();
        
        $stmt_find_link = $conn->prepare("SELECT id FROM checklists WHERE gatepass_id = ?");
        $stmt_find_link->bind_param("i", $id);
        $stmt_find_link->execute();
        if ($link_row = $stmt_find_link->get_result()->fetch_assoc()) {
            $linked_checklist_id = $link_row['id'];
        }
        $stmt_find_link->close();

        if ($gatepass['gatepass_type'] === 'Vendor' && !empty($gatepass['vendor_id'])) {
            $vendor_stmt = $conn->prepare("SELECT vendor_name, contact_mobile, address, pickup_person FROM gp_vendors WHERE id = ?");
            $vendor_stmt->bind_param("i", $gatepass['vendor_id']);
            $vendor_stmt->execute();
            if ($vendor_result = $vendor_stmt->get_result()->fetch_assoc()) {
                $vendor_details = $vendor_result;
                if (empty($gatepass['pickup_person'])) {
                    $gatepass['pickup_person'] = $vendor_details['pickup_person'];
                }
            }
            $vendor_stmt->close();
        }
        
        $items_stmt = $conn->prepare("SELECT * FROM gatepass_items WHERE gatepass_id = ?");
        $items_stmt->bind_param("i", $id);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();
        while($item = $items_result->fetch_assoc()) {
            $gatepass_items[] = $item;
        }
        $items_stmt->close();
    } else {
        $_SESSION['page_message'] = ['type' => 'danger', 'text' => 'Gatepass not found.', 'context' => 'gatepass'];
        header("Location: " . APP_BASE_PATH . "index.php?route=gatepass");
        exit;
    }
    $stmt->close();
} elseif ($is_from_checklist) {
    $linked_checklist_id = (int)$_GET['from_checklist_id'];
    
    // FETCH employee_id from the checklist to prevent bypass
    $stmt_get_emp = $conn->prepare("SELECT employee_id FROM checklists WHERE id = ?");
    $stmt_get_emp->bind_param("i", $linked_checklist_id);
    $stmt_get_emp->execute();
    $res_emp = $stmt_get_emp->get_result();
    if ($row_emp = $res_emp->fetch_assoc()) {
        $gatepass['employee_user_id'] = $row_emp['employee_id'];
    }
    $stmt_get_emp->close();

    $gatepass['gatepass_type'] = 'Employee';
    $gatepass['employee_name'] = $_GET['employee_name'] ?? '';
    $gatepass['department'] = $_GET['department'] ?? '';
    $gatepass['date_of_joining'] = $_GET['date_of_joining'] ?? '';
    $gatepass['position'] = $_GET['position'] ?? '';
    $gatepass['purpose'] = 'Employee Asset - WFH';
    $gatepass_items = json_decode($_GET['items'] ?? '[]', true);
}

if (!$is_editing) {
    $year = date('Y');
    $stmt_num = $conn->prepare("SELECT MAX(CAST(SUBSTRING(gatepass_no, 10) AS UNSIGNED)) as max_num FROM gatepass_requests WHERE gatepass_no LIKE ?");
    $prefix = "GP-$year-";
    $prefix_like = $prefix . '%';
    $stmt_num->bind_param("s", $prefix_like);
    $stmt_num->execute();
    $result = $stmt_num->get_result()->fetch_assoc();
    $stmt_num->close();
    $next_num = ($result['max_num'] ?? 0) + 1;
    $gatepass['gatepass_no'] = $prefix . str_pad($next_num, 4, '0', STR_PAD_LEFT);
}

$pageTitle = $is_editing ? "Edit Gatepass" : "New Gatepass";
$is_locked = ($is_editing && ($gatepass['status'] ?? '') === 'Approved' && !can('approve_gatepass'));
$disabled_attr = $is_locked ? 'disabled' : '';
$security_disabled_attr = can('edit_security_fields') ? '' : 'disabled';

// --- Data for dropdowns ---
$departments = []; 
$dept_result = $conn->query("SELECT name FROM departments ORDER BY name ASC");
while($dept_row = $dept_result->fetch_assoc()){ $departments[] = $dept_row['name']; }

$it_users = [];
$it_stmt = $conn->prepare("SELECT DISTINCT u.firstname, u.lastname FROM users u JOIN roles r ON u.role_id = r.id JOIN role_permissions rp ON r.id = rp.role_id JOIN permissions p ON rp.permission_id = p.id WHERE p.action_key = 'create_gatepass' ORDER BY u.firstname");
$it_stmt->execute();
$it_result = $it_stmt->get_result();
while ($user = $it_result->fetch_assoc()) { $it_users[] = trim($user['firstname'] . ' ' . $user['lastname']); }
$it_stmt->close();

$approvers = [];
$approvers_stmt = $conn->prepare("SELECT u.firstname, u.lastname FROM users u JOIN roles r ON u.role_id = r.id JOIN role_permissions rp ON r.id = rp.role_id JOIN permissions p ON rp.permission_id = p.id WHERE p.action_key = 'approve_gatepass' ORDER BY u.firstname");
$approvers_stmt->execute();
$approvers_result = $approvers_stmt->get_result();
while ($user = $approvers_result->fetch_assoc()) { $approvers[] = trim($user['firstname'] . ' ' . $user['lastname']); }
$approvers_stmt->close();

$locations = [];
$loc_result = $conn->query("SELECT name FROM locations ORDER BY name ASC");
while($loc_row = $loc_result->fetch_assoc()){ $locations[] = $loc_row['name']; }

include_once '../includes/header.php';
?>
<link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
    :root {
        --primary-color: #0f766e;
        --primary-hover: #0d9488;
        --bg-color: #f8fafc;
        --card-bg: #ffffff;
        --border-color: #e2e8f0;
        --text-main: #334155;
        --text-light: #64748b;
        --radius: 12px;
        --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
    }
    
    body { font-family: 'Inter', sans-serif; background-color: var(--bg-color) !important; color: var(--text-main); font-size: 0.875rem; padding-bottom: 60px; }
    h1, h2, h3, h4, h5, h6 { font-weight: 600; color: #1e293b; }
    
    .modern-card { background: var(--card-bg); border-radius: var(--radius); box-shadow: var(--card-shadow); border: 1px solid var(--border-color); overflow: hidden; margin-bottom: 24px; }
    .modern-card-header { padding: 16px 24px; background: #ffffff; border-bottom: 1px solid var(--border-color); font-weight: 600; color: #1e293b; display: flex; align-items: center; justify-content: space-between; font-size: 1rem; }
    .modern-card-body { padding: 24px; }
    
    .form-label { font-weight: 600; font-size: 0.75rem; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.02em; margin-bottom: 6px; }
    .form-control, .form-select { border-radius: 8px; padding: 10px 14px; font-size: 0.875rem; border: 1px solid #cbd5e1; transition: all 0.2s; }
    .form-control:focus, .form-select:focus { border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1); }
    .form-control[readonly], .form-select:disabled, .form-control:disabled { background-color: #f1f5f9; color: #475569; border-color: #e2e8f0; cursor: not-allowed; }

    .btn-primary-modern { background-color: var(--primary-color); color: white; border: none; border-radius: 20px; padding: 8px 24px; font-weight: 600; font-size: 0.875rem; transition: all 0.2s; }
    .btn-primary-modern:hover { background-color: var(--primary-hover); color: white; transform: translateY(-1px); }
    .btn-primary-modern:disabled { background-color: #94a3b8; cursor: not-allowed; transform: none; }

    .btn-outline-primary-modern { color: var(--primary-color); border: 1px solid var(--primary-color); border-radius: 20px; padding: 6px 20px; font-weight: 600; font-size: 0.8rem; transition: all 0.2s; background: transparent;}
    .btn-outline-primary-modern:hover { background-color: var(--primary-color); color: white; }

    .table-custom { width: 100%; border-collapse: separate; border-spacing: 0; }
    .table-custom th { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-light); font-weight: 600; padding: 12px 16px; border-bottom: 1px solid var(--border-color); background: #f8fafc; }
    .table-custom td { padding: 10px 12px; vertical-align: middle; border-bottom: 1px solid #f1f5f9; }

    .status-badge { padding: 6px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; letter-spacing: 0.02em; text-transform: uppercase; display: inline-block; }

    .ui-autocomplete {
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05) !important;
        border-radius: 8px !important;
        border: 1px solid var(--border-color) !important;
        background: #ffffff !important;
        z-index: 1050 !important;
        padding: 4px 0 !important;
        max-height: 250px;
        overflow-y: auto;
        overflow-x: hidden;
    }
    .ui-menu-item-wrapper { padding: 10px 16px !important; font-family: 'Inter', sans-serif !important; font-size: 0.875rem !important; color: var(--text-main) !important; border: none !important; background: transparent !important; transition: background-color 0.15s; cursor: pointer !important; }
    .ui-menu-item-wrapper.ui-state-active { background-color: #f0fdfa !important; color: var(--primary-color) !important; margin: 0 !important; }
</style>

<div class="container-xl pt-4">

    <div class="mb-4">
        <a href="<?= APP_BASE_PATH ?>index.php?route=gatepass" class="text-decoration-none text-muted small fw-bold d-inline-flex align-items-center transition-colors hover-primary mb-2 check-unsaved">
            <i data-feather="arrow-left" style="width:14px;" class="me-1"></i> BACK TO LIST
        </a>
        <div class="d-flex justify-content-between align-items-end mt-2">
            <div>
                <h4 class="fw-bold m-0 text-dark"><?php echo $pageTitle; ?></h4>
                <div class="text-muted small mt-1"><?php echo $is_editing ? "Manage details and items for this request" : "Fill out the details to generate a new gatepass"; ?></div>
            </div>
            
            <?php if ($is_editing): ?>
                <div class="text-end">
                    <?php
                        $status_badge_class = match ($gatepass['status']) {
                            'Approved' => 'bg-success-subtle text-success-emphasis border border-success-subtle',
                            'Pending Approval' => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
                            'Returned' => 'bg-primary-subtle text-primary-emphasis border border-primary-subtle',
                            default => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
                        };
                    ?>
                    <span class="status-badge <?php echo $status_badge_class; ?>"><?php echo htmlspecialchars($gatepass['status']); ?></span>
                    <?php if ($gatepass['status'] == 'Approved' && !empty($gatepass['approved_by'])): ?>
                        <div class="text-muted mt-1" style="font-size: 0.7rem;">
                            Approved by <strong><?php echo htmlspecialchars($gatepass['approved_by']); ?></strong>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <form id="gatepassForm" action="<?= APP_BASE_PATH ?>index.php?route=add_edit_gatepass<?php echo $is_editing ? '&id='.$gatepass['id'] : ''; ?>" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="id" value="<?php echo htmlspecialchars($gatepass['id'] ?? ''); ?>">
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($gatepass['status'] ?? ''); ?>">
        
        <?php if ($is_locked) { echo '<input type="hidden" name="is_locked" value="1">'; } ?>
        <?php if ($is_from_checklist && $linked_checklist_id) { echo '<input type="hidden" name="from_checklist_id" value="' . htmlspecialchars($linked_checklist_id) . '">'; } ?>
        <?php if (isset($_GET['create_shipment_after']) && $_GET['create_shipment_after'] === 'true'): ?>
            <input type="hidden" name="create_shipment_after" value="true">
        <?php endif; ?>

        <div class="modern-card">
            <div class="modern-card-header bg-light">
                <div class="d-flex align-items-center"><i data-feather="file-text" style="width:16px;" class="me-2 text-primary"></i> Basic Information</div>
            </div>
            <div class="modern-card-body">
                <div class="row g-4">
                    <div class="col-md-4">
                        <label class="form-label">Gatepass No.</label>
                        <input type="text" class="form-control fw-bold font-monospace" name="gatepass_no" value="<?php echo htmlspecialchars($gatepass['gatepass_no'] ?? ''); ?>" readonly>
                    </div>
                    <div class="col-md-4">
                        <label for="gatepass_type" class="form-label">Gatepass Type <span class="text-danger">*</span></label>
                        <select class="form-select" id="gatepass_type" name="gatepass_type" <?php if ($is_editing || $is_from_checklist) echo 'disabled'; ?>>
                            <option value="Employee" <?php if(($gatepass['gatepass_type'] ?? '') == 'Employee') echo 'selected'; ?>>Employee</option>
                            <option value="Vendor" <?php if(($gatepass['gatepass_type'] ?? '') == 'Vendor') echo 'selected'; ?>>Vendor</option>
                            <option value="Site-to-Site" <?php if(($gatepass['gatepass_type'] ?? '') == 'Site-to-Site') echo 'selected'; ?>>Site-to-Site</option>
                        </select>
                        <?php if ($is_editing || $is_from_checklist): ?>
                            <input type="hidden" name="gatepass_type" value="<?php echo htmlspecialchars($gatepass['gatepass_type']); ?>">
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label for="ticket_no" class="form-label">Ticket No. <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="ticket_no" name="ticket_no" value="<?php echo htmlspecialchars($gatepass['ticket_no'] ?? ''); ?>" <?php echo $disabled_attr; ?> required>
                    </div>
                </div>

                <div class="mt-4 pt-4 border-top">
                    
                    <div id="employee_fields" class="bg-light p-4 rounded-3 border">
                        <div class="d-flex align-items-center mb-3">
                            <i data-feather="user" class="text-muted me-2" style="width: 16px;"></i>
                            <h6 class="fw-bold m-0 text-muted" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;">Employee Details</h6>
                        </div>
                        <fieldset <?php echo $disabled_attr; ?>>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="employee_search" class="form-label">Search Employee <span class="text-danger">*</span></label>
                                    <input type="text" id="employee_search" name="employee_name" class="form-control" placeholder="Type name or ID..." value="<?php echo htmlspecialchars($gatepass['employee_name']); ?>" required autocomplete="off">
                                    <input type="hidden" name="employee_user_id" id="employee_user_id" value="<?php echo htmlspecialchars($gatepass['employee_user_id']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="department" class="form-label">Department</label>
                                    <input type="text" class="form-control" id="department" name="department" value="<?php echo htmlspecialchars($gatepass['department']); ?>" readonly required>
                                </div>
                                <div class="col-md-6">
                                    <label for="doj" class="form-label">Date of Joining</label>
                                    <input type="date" id="doj" name="date_of_joining" class="form-control" value="<?php echo htmlspecialchars($gatepass['date_of_joining']); ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label for="employee_position" class="form-label">Position</label>
                                    <input type="text" id="employee_position" name="employee_position" class="form-control" value="<?php echo htmlspecialchars($gatepass['position']); ?>" readonly>
                                </div>
                            </div>
                        </fieldset>
                    </div>
                    
                    <div id="vendor_fields" class="bg-light p-4 rounded-3 border" style="display: none;">
                        <div class="d-flex align-items-center mb-3">
                            <i data-feather="truck" class="text-muted me-2" style="width: 16px;"></i>
                            <h6 class="fw-bold m-0 text-muted" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;">Vendor Details</h6>
                        </div>
                        <fieldset <?php echo $disabled_attr; ?>>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="vendor_search" class="form-label">Vendor Name <span class="text-danger">*</span></label>
                                    <input type="text" name="vendor_name" id="vendor_search" class="form-control" value="<?php echo htmlspecialchars($vendor_details['vendor_name']); ?>" placeholder="Search or type to add..." required autocomplete="off">
                                    <input type="hidden" name="vendor_id" id="vendor_id" value="<?php echo htmlspecialchars($gatepass['vendor_id']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="pickup_person" class="form-label">Pickup Person Name</label>
                                    <input type="text" class="form-control" name="pickup_person" id="pickup_person" value="<?php echo htmlspecialchars($gatepass['pickup_person'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="vendor_mobile" class="form-label">Mobile No.</label>
                                    <input type="text" name="vendor_mobile" id="vendor_mobile" class="form-control" value="<?php echo htmlspecialchars($vendor_details['contact_mobile']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="vendor_address" class="form-label">Address</label>
                                    <textarea class="form-control" name="vendor_address" id="vendor_address" rows="1"><?php echo htmlspecialchars($vendor_details['address']); ?></textarea>
                                </div>
                            </div>
                        </fieldset>
                    </div>
                    
                    <div id="site_to_site_fields" style="display: none;">
                        <div class="bg-light p-4 rounded-3 border mb-3">
                            <div class="d-flex align-items-center mb-3">
                                <i data-feather="map-pin" class="text-muted me-2" style="width: 16px;"></i>
                                <h6 class="fw-bold m-0 text-muted" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;">Movement Details</h6>
                            </div>
                            <fieldset <?php echo $disabled_attr; ?>>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="from_location" class="form-label">From Site / Location <span class="text-danger">*</span></label>
                                        <select class="form-select" id="from_location" name="from_location" required>
                                            <option value="" disabled selected>Select Location...</option>
                                            <?php foreach($locations as $loc): ?>
                                                <option value="<?php echo htmlspecialchars($loc); ?>" <?php if (($gatepass['from_location'] ?? '') == $loc) echo 'selected'; ?>><?php echo htmlspecialchars($loc); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="to_location" class="form-label">To Site / Location <span class="text-danger">*</span></label>
                                        <select class="form-select" id="to_location" name="to_location" required>
                                            <option value="" disabled selected>Select Location...</option>
                                            <?php foreach($locations as $loc): ?>
                                                <option value="<?php echo htmlspecialchars($loc); ?>" <?php if (($gatepass['to_location'] ?? '') == $loc) echo 'selected'; ?>><?php echo htmlspecialchars($loc); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </fieldset>
                        </div>
                        
                        <div class="bg-light p-4 rounded-3 border">
                            <div class="d-flex align-items-center mb-3">
                                <i data-feather="users" class="text-muted me-2" style="width: 16px;"></i>
                                <h6 class="fw-bold m-0 text-muted" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;">Personnel Involved</h6>
                            </div>
                            <fieldset <?php echo $disabled_attr; ?>>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="requested_by" class="form-label">Requested By <span class="text-danger">*</span></label>
                                        <select class="form-select" id="requested_by" name="requested_by" required>
                                            <option value="" disabled selected>Select User...</option>
                                            <?php foreach($it_users as $user): ?>
                                                <option value="<?php echo htmlspecialchars($user); ?>" <?php if (($gatepass['requested_by'] ?? '') == $user) echo 'selected'; ?>><?php echo htmlspecialchars($user); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="received_by" class="form-label">Received By</label>
                                        <select class="form-select" id="received_by" name="received_by">
                                            <option value="" selected>Select User (Optional)...</option>
                                            <?php foreach($it_users as $user): ?>
                                                <option value="<?php echo htmlspecialchars($user); ?>" <?php if (($gatepass['received_by'] ?? '') == $user) echo 'selected'; ?>><?php echo htmlspecialchars($user); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </fieldset>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <div class="modern-card">
            <div class="modern-card-header bg-light">
                <div class="d-flex align-items-center"><i data-feather="box" style="width:16px;" class="me-2 text-primary"></i> IT Asset Details</div>
                <?php if (!$is_locked || can('edit_security_fields')): ?>
                    <button type="button" id="add_item_btn" class="btn btn-outline-primary-modern d-flex align-items-center"><i data-feather="plus" style="width:14px;" class="me-1"></i> Add Row</button>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <fieldset <?php if ($is_locked && !can('edit_security_fields')) echo 'disabled'; ?>>
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th class="text-center" style="width:5%;">#</th>
                            <th>Product Description <span class="text-danger">*</span></th>
                            <th style="width:12%;">Qty <span class="text-danger">*</span></th>
                            <th>Remarks</th>
                            <th style="width:6%;"></th>
                        </tr>
                    </thead>
                    <tbody id="item_list">
                        <?php if (empty($gatepass_items)): ?>
                        <tr>
                            <td class="text-center text-muted fw-bold">1</td>
                            <td><input type="text" name="items[0][description]" class="form-control border-0 bg-transparent px-0 shadow-none" placeholder="Enter item details..." required></td>
                            <td><input type="number" name="items[0][quantity]" class="form-control" value="1" min="1" required></td>
                            <td><input type="text" name="items[0][remarks]" class="form-control border-0 bg-transparent px-0 shadow-none" placeholder="Optional"></td>
                            <td class="text-center"><button type="button" class="btn btn-link text-danger p-0 remove-item-btn"><i data-feather="trash-2" style="width:16px;"></i></button></td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($gatepass_items as $index => $item): ?>
                            <tr>
                                <td class="text-center text-muted fw-bold"><?php echo $index + 1; ?></td>
                                <td><input type="text" name="items[<?php echo $index; ?>][description]" class="form-control border-0 bg-transparent px-0 shadow-none" value="<?php echo htmlspecialchars($item['description']); ?>" required></td>
                                <td><input type="number" name="items[<?php echo $index; ?>][quantity]" class="form-control" value="<?php echo htmlspecialchars($item['quantity']); ?>" min="1" required></td>
                                <td><input type="text" name="items[<?php echo $index; ?>][remarks]" class="form-control border-0 bg-transparent px-0 shadow-none" value="<?php echo htmlspecialchars($item['remarks'] ?? ''); ?>"></td>
                                <td class="text-center"><button type="button" class="btn btn-link text-danger p-0 remove-item-btn"><i data-feather="trash-2" style="width:16px;"></i></button></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                </fieldset>
            </div>
            <div class="modern-card-body bg-light border-top">
                <fieldset <?php if ($is_locked && !can('edit_security_fields')) echo 'disabled'; ?>>
                    <label for="reason" class="form-label">Gatepass Reason <span class="text-danger">*</span></label>
                    <textarea class="form-control" name="reason" id="reason" rows="2" placeholder="Briefly describe the reason for this gatepass..." required><?php echo htmlspecialchars($gatepass['reason'] ?? ''); ?></textarea>
                </fieldset>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-6">
                <div class="modern-card h-100 mb-0">
                    <div class="modern-card-header bg-light">
                        <div class="d-flex align-items-center"><i data-feather="check-square" style="width:16px;" class="me-2 text-primary"></i> Authorization</div>
                    </div>
                    <div class="modern-card-body">
                        <fieldset <?php echo $disabled_attr; ?>>
                        <div class="row g-3">
                            <div class="col-12">
                                <label for="prepared_by" class="form-label">Prepared By</label>
                                <select class="form-select" name="prepared_by">
                                    <?php
                                    $prepared_by_val = $gatepass['prepared_by'];
                                    $user_in_list = false;
                                    foreach($it_users as $user) {
                                        $selected = ($prepared_by_val == $user) ? 'selected' : '';
                                        if ($selected) $user_in_list = true;
                                        echo "<option value=\"".htmlspecialchars($user)."\" $selected>".htmlspecialchars($user)."</option>";
                                    }
                                    if (!$user_in_list && !empty($prepared_by_val)) {
                                        echo "<option value=\"".htmlspecialchars($prepared_by_val)."\" selected>".htmlspecialchars($prepared_by_val)."</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label for="approved_by" class="form-label">Approved By</label>
                                <select class="form-select" name="approved_by">
                                    <option value="" disabled selected>Select Approver...</option>
                                    <?php foreach($approvers as $user): ?>
                                        <option value="<?php echo htmlspecialchars($user); ?>" <?php if(($gatepass['approved_by'] ?? '') == $user) echo 'selected'; ?>><?php echo htmlspecialchars($user); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        </fieldset>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="modern-card h-100 mb-0">
                    <div class="modern-card-header bg-light">
                        <div class="d-flex align-items-center"><i data-feather="shield" style="width:16px;" class="me-2 text-primary"></i> Security Desk</div>
                    </div>
                    <div class="modern-card-body">
                        <fieldset <?php echo $security_disabled_attr; ?>>
                            
                            <div id="security_fields_standard">
                                <div class="row g-3">
                                    <div class="col-6"><label class="form-label">Out Time</label><input type="time" name="security_out_time" class="form-control" value="<?php echo htmlspecialchars($gatepass['security_out_time'] ?? ''); ?>"></div>
                                    <div class="col-6"><label class="form-label">Date</label><input type="date" name="security_out_date" class="form-control" value="<?php echo htmlspecialchars($gatepass['security_out_date'] ?? ''); ?>"></div>
                                    <div class="col-12"><label class="form-label">Security Name</label><input type="text" name="security_person_name" class="form-control" value="<?php echo htmlspecialchars($gatepass['security_person_name'] ?? ''); ?>"></div>
                                    <div class="col-12"><label class="form-label">Verified By</label><input type="text" name="security_verified_by" class="form-control" value="<?php echo htmlspecialchars($gatepass['security_verified_by'] ?? ''); ?>"></div>
                                </div>
                            </div>
                            
                            <div id="security_fields_s2s" style="display: none;">
                                <h6 class="fw-bold m-0 text-muted mb-3" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em;"><i data-feather="arrow-up-right" class="me-1 text-danger" style="width: 14px;"></i> From Site (OUT)</h6>
                                <div class="row g-2 mb-4">
                                    <div class="col-6"><label class="form-label">Out Time</label><input type="time" name="security_out_time_s2s" class="form-control" value="<?php echo htmlspecialchars($gatepass['security_out_time'] ?? ''); ?>"></div>
                                    <div class="col-6"><label class="form-label">Date</label><input type="date" name="security_out_date_s2s" class="form-control" value="<?php echo htmlspecialchars($gatepass['security_out_date'] ?? ''); ?>"></div>
                                    <div class="col-6"><label class="form-label">Security Name</label><input type="text" name="security_person_name_s2s" class="form-control" value="<?php echo htmlspecialchars($gatepass['security_person_name'] ?? ''); ?>"></div>
                                    <div class="col-6"><label class="form-label">Verified By</label><input type="text" name="security_verified_by_s2s" class="form-control" value="<?php echo htmlspecialchars($gatepass['security_verified_by'] ?? ''); ?>"></div>
                                </div>
                                <h6 class="fw-bold m-0 text-muted mb-3" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em;"><i data-feather="arrow-down-left" class="me-1 text-success" style="width: 14px;"></i> To Site (IN)</h6>
                                <div class="row g-2">
                                    <div class="col-6"><label class="form-label">In Time</label><input type="time" name="security_in_time" class="form-control" value="<?php echo htmlspecialchars($gatepass['security_in_time'] ?? ''); ?>"></div>
                                    <div class="col-6"><label class="form-label">Date</label><input type="date" name="security_in_date" class="form-control" value="<?php echo htmlspecialchars($gatepass['security_in_date'] ?? ''); ?>"></div>
                                    <div class="col-6"><label class="form-label">Security Name</label><input type="text" name="security_in_person_name" class="form-control" value="<?php echo htmlspecialchars($gatepass['security_in_person_name'] ?? ''); ?>"></div>
                                    <div class="col-6"><label class="form-label">Verified By</label><input type="text" name="security_in_verified_by" class="form-control" value="<?php echo htmlspecialchars($gatepass['security_in_verified_by'] ?? ''); ?>"></div>
                                </div>
                            </div>
                        </fieldset>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end align-items-center gap-2 border-top pt-4">
            <a href="<?= APP_BASE_PATH ?>index.php?route=gatepass" class="btn btn-light border rounded-pill px-4 fw-medium text-muted check-unsaved">Cancel</a>
            
            <?php if ($is_editing && $gatepass['status'] == 'Pending Approval' && can('approve_gatepass')): ?>
                <button type="submit" name="approve_gatepass" value="1" class="btn btn-success rounded-pill px-4 shadow-sm d-flex align-items-center">
                    <i data-feather="check-circle" class="me-2" style="width:16px;"></i> Approve Request
                </button>
            <?php endif; ?>

            <button type="submit" name="save_gatepass" value="1" class="btn btn-primary-modern shadow-sm d-flex align-items-center" <?php if ($is_locked && !can('edit_security_fields')) echo 'disabled'; ?>>
                <i data-feather="save" class="me-2" style="width:16px;"></i> Save Gatepass
            </button>
        </div>
    </form>
</div>

<div class="modal fade" id="unsavedChangesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header bg-warning-subtle border-0 pb-0">
                <h5 class="modal-title fw-bold text-warning-emphasis d-flex align-items-center">
                    <i data-feather="alert-triangle" class="me-2"></i> Unsaved Changes
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-4">
                <p class="mb-0 text-dark">You have unsaved changes on this form. Are you sure you want to discard them and leave?</p>
            </div>
            <div class="modal-footer border-0 bg-light">
                <button type="button" class="btn btn-white border px-4 rounded-pill fw-medium" data-bs-dismiss="modal">Stay</button>
                <button type="button" class="btn btn-danger px-4 rounded-pill fw-medium shadow-sm" id="discardChangesBtn">Discard & Leave</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="alertModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; border-left: 4px solid var(--primary-color);">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-dark d-flex align-items-center" id="alertModalTitle">
                    <i data-feather="info" class="me-2 text-primary"></i> Notice
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-muted pt-3 pb-4" id="alertModalBody"></div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-primary-modern rounded-pill px-4" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<?php include_once '../includes/footer.php'; ?>

<script>
$(function() {
    if (typeof feather !== 'undefined') feather.replace();

    function showAlertModal(msg) {
        document.getElementById('alertModalBody').innerHTML = msg;
        new bootstrap.Modal(document.getElementById('alertModal')).show();
    }

    function clearEmployeeFields() {
        $("#employee_search").val('');
        $("#employee_user_id").val('');
        $("#doj").val('');
        $("#employee_position").val('');
        $("#department").val('');
    }

    let formChanged = false;
    let intendedUrl = null;
    const unsavedModal = new bootstrap.Modal(document.getElementById('unsavedChangesModal'));

    $('#gatepassForm').on('change keyup', 'input, textarea, select', function() { formChanged = true; });
    $('#gatepassForm').on('submit', function() { formChanged = false; });

    $(document).on('click', 'a.nav-link, a.check-unsaved, a.dropdown-item', function(e) {
        if ($(this).attr('data-bs-toggle') === 'dropdown' || $(this).attr('target') === '_blank') return;
        
        if (formChanged && !$(this).hasClass('active')) {
            e.preventDefault();
            intendedUrl = $(this).attr('href');
            unsavedModal.show();
        }
    });

    $('#discardChangesBtn').on('click', function() {
        formChanged = false; 
        if (intendedUrl) { window.location.href = intendedUrl; }
    });

    $("#employee_search").autocomplete({
        source: "<?= APP_BASE_PATH ?>index.php?route=ajax_search&type=employee",
        minLength: 2,
        select: function(event, ui) {
            event.preventDefault();
            
            // Instantly check if this employee has an incomplete checklist or pending gatepass
            const fd = new FormData();
            fd.append('ajax_action', 'check_employee_checklist');
            fd.append('employee_user_id', ui.item.id);

            fetch('<?= APP_BASE_PATH ?>index.php?route=add_edit_gatepass', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'incomplete_checklist') {
                    showAlertModal(`Cannot select <strong>${ui.item.name}</strong>.<br><br>This employee has an incomplete System Readiness Checklist (ID: <span class="text-primary fw-bold">#${data.checklist_id}</span>). <br><br>Please <a href="<?= APP_BASE_PATH ?>index.php?route=system_readiness_checklist&checklist_id=${data.checklist_id}" class="text-decoration-underline fw-bold text-primary">complete the checklist</a> to proceed.`);
                    clearEmployeeFields();
                } else if (data.status === 'pending_gatepass') {
                    showAlertModal(`Cannot select <strong>${ui.item.name}</strong>.<br><br>This employee already has a gatepass (<span class="text-primary fw-bold">${data.gatepass_no}</span>) that is currently <strong>Pending Approval</strong>.<br><br>Please approve, complete, or reject the existing gatepass before creating a new one.`);
                    clearEmployeeFields();
                } else {
                    $("#employee_search").val(ui.item.name);
                    $("#employee_user_id").val(ui.item.id);
                    $("#doj").val(ui.item.doj);
                    $("#employee_position").val(ui.item.position);
                    $("#department").val(ui.item.department);
                    formChanged = true;
                    
                    // Highlight effect
                    const $inputs = [$("#doj"), $("#employee_position"), $("#department")];
                    $inputs.forEach($el => {
                        $el.css({transition: 'background-color 0.3s ease', backgroundColor: '#e0f2fe'});
                        setTimeout(() => $el.css('backgroundColor', ''), 600);
                    });
                }
            }).catch(e => {
                console.error(e);
                showAlertModal("An error occurred verifying the employee's status.");
            });
        }
    });

    $("#vendor_search").autocomplete({
        source: "<?= APP_BASE_PATH ?>index.php?route=ajax_search&type=vendor",
        minLength: 2,
        select: function(event, ui) {
            event.preventDefault();
            $("#vendor_search").val(ui.item.value);
            $("#vendor_id").val(ui.item.id);
            $("#vendor_mobile").val(ui.item.mobile);
            $("#vendor_address").val(ui.item.address).trigger('change');
            $("#pickup_person").val(ui.item.pickup_person);
            
            // Highlight effect
            const $inputs = [$("#vendor_mobile"), $("#vendor_address"), $("#pickup_person")];
            $inputs.forEach($el => {
                $el.css({transition: 'background-color 0.3s ease', backgroundColor: '#e0f2fe'});
                setTimeout(() => $el.css('backgroundColor', ''), 600);
            });
        }
    }).on('input', function() { $('#vendor_id').val(''); });

    $('#add_item_btn').on('click', function() {
        const tableBody = $('#item_list');
        const rowCount = tableBody.find('tr').length;
        const newRow = `<tr>
            <td class="text-center text-muted fw-bold">${rowCount + 1}</td>
            <td><input type="text" name="items[${rowCount}][description]" class="form-control border-0 bg-transparent px-0 shadow-none" placeholder="Enter item details..." required></td>
            <td><input type="number" name="items[${rowCount}][quantity]" class="form-control" value="1" min="1" required></td>
            <td><input type="text" name="items[${rowCount}][remarks]" class="form-control border-0 bg-transparent px-0 shadow-none" placeholder="Optional"></td>
            <td class="text-center"><button type="button" class="btn btn-link text-danger p-0 remove-item-btn"><i data-feather="trash-2" style="width:16px;"></i></button></td>
        </tr>`;
        tableBody.append(newRow);
        if (typeof feather !== 'undefined') feather.replace();
        formChanged = true;
    });
    
    $('#item_list').on('click', '.remove-item-btn', function() {
        const tableBody = $(this).closest('tbody');
        if (tableBody.find('tr').length > 1) {
            $(this).closest('tr').remove();
            tableBody.find('tr').each(function(index) {
                $(this).find('td:first').text(index + 1);
                $(this).find(':input').each(function() {
                    if (this.name) { this.name = this.name.replace(/\[\d+\]/, '[' + index + ']'); }
                });
            });
            formChanged = true;
        } else {
            alert('At least one item is required.');
        }
    });
    
    function toggleFormFields() {
        let gatepassType = $('#gatepass_type').val();

        $('#employee_fields, #other_details_fields').hide();
        $('#vendor_fields, #site_to_site_fields').hide();

        $('#security_fields_standard').show();
        $('#security_fields_s2s').hide();

        $('#employee_fields :input, #vendor_fields :input, #site_to_site_fields :input').prop('disabled', true);
        
        $('#security_fields_standard :input').prop('disabled', false);
        $('#security_fields_s2s :input').prop('disabled', true);
        
        $('#employee_search, #vendor_search, #from_location, #to_location, #requested_by').prop('required', false);

        if (gatepassType === 'Employee') {
            $('#employee_fields').show();
            $('#employee_fields').find('input, select, textarea').prop('disabled', false);
            $('#employee_search').prop('required', true);
        } else {
            $('#other_details_fields').show();
            if (gatepassType === 'Vendor') {
                $('#vendor_fields').show().find('input, select, textarea').prop('disabled', false);
                $('#vendor_search').prop('required', true);
            } else if (gatepassType === 'Site-to-Site') {
                $('#site_to_site_fields').show().find('input, select, textarea').prop('disabled', false);
                $('#from_location, #to_location, #requested_by').prop('required', true);
                
                $('#security_fields_standard').hide().find(':input').prop('disabled', true);
                $('#security_fields_s2s').show().find(':input').prop('disabled', false);
            }
        }

        const reasonTextarea = $('#reason');
        const currentReason = reasonTextarea.val().trim();
        const defaultReasons = ['Employee Asset - WFH', 'Repair/Service', 'Internal Asset Movement', ''];
        if (defaultReasons.includes(currentReason) || currentReason === '') {
            if (gatepassType === 'Employee') reasonTextarea.val('Employee Asset - WFH');
            else if (gatepassType === 'Vendor') reasonTextarea.val('Repair/Service');
            else if (gatepassType === 'Site-to-Site') reasonTextarea.val('Internal Asset Movement');
        }
    }
    
    $('#gatepass_type').on('change', toggleFormFields);
    
    // Add info tip for 'Employee' gatepass creation
    $('#gatepass_type').on('change', function(e) {
        if (e.originalEvent && $(this).val() === 'Employee') {
            showAlertModal("<strong>Info:</strong> Initiate the Gatepass from the <em>System Readiness Checklist</em> to automatically pull the Employee & system details for gatepass creation.");
        }
    });

    toggleFormFields();

    function validateTimeDatePair() {
        const pairs = [
            { time: 'security_out_time', date: 'security_out_date' },
            { time: 'security_out_time_s2s', date: 'security_out_date_s2s' },
            { time: 'security_in_time', date: 'security_in_date' }
        ];

        pairs.forEach(pair => {
            const timeInput = $(`input[name="${pair.time}"]`);
            const dateInput = $(`input[name="${pair.date}"]`);

            const checkAndSetRequired = () => {
                const timeVal = timeInput.val();
                const dateVal = dateInput.val();

                if (timeVal || dateVal) {
                    timeInput.prop('required', true);
                    dateInput.prop('required', true);
                } else {
                    timeInput.prop('required', false);
                    dateInput.prop('required', false);
                }
            };

            timeInput.add(dateInput).on('input', checkAndSetRequired);
            checkAndSetRequired();
        });
    }

    validateTimeDatePair();
});
</script>