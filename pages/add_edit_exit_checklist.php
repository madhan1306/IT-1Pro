<?php
// add_edit_exit_checklist.php

// --- FORCE PHP TIMEZONE TO IST ---
date_default_timezone_set('Asia/Kolkata');

// 1. Session & Security Headers
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

include_once '../includes/error_handler.php';
include_once '../includes/db_connect.php';

// --- FORCE MYSQL TIMEZONE TO IST FOR THIS SESSION ---
if (isset($conn)) {
    $conn->query("SET time_zone = '+05:30'");
}

include_once '../includes/auth.php';

// --- CENTRALIZED LOGGING FUNCTION ---
// Moved to the top so it is accessible by the AJAX handler below to prevent silent SQL insert failures
function log_exit_activity($conn, $employee_id, $checklist_id, $performed_by, $action_description, $field_changed = null, $old_value = null, $new_value = null) {
    $action_type = 'Exit Checklist';
    $sql = "INSERT INTO action_logs (joiner_id, template_id, performed_by, action_type, action_description, field_changed, old_value, new_value) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("iissssss", $employee_id, $checklist_id, $performed_by, $action_type, $action_description, $field_changed, $old_value, $new_value);
        $stmt->execute();
        $stmt->close();
    }
}

// --- AJAX HANDLER FOR AUTO-SAVE AND CHECKS ---
if (isset($_POST['ajax_action'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $response = ['status' => 'error', 'message' => 'Unknown action'];
    
    try {
        if (!isset($_SESSION['username'])) throw new Exception("Unauthorized: Session expired or invalid.");
        
        $logged_in_username = $_SESSION['username'];
        $logged_in_user_fullname = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')) ?: $logged_in_username;
        
        // 0. Instant Duplicate Check
        if ($_POST['ajax_action'] === 'check_duplicate') {
            $emp_id_to_check = (int)$_POST['employee_id'];
            $sql_check = "SELECT id FROM checklists WHERE employee_id = ? AND checklist_type = 'exit_checklist' AND overall_status != 'Completed'";
            $stmt_check_dup = $conn->prepare($sql_check);
            $stmt_check_dup->bind_param("i", $emp_id_to_check);
            $stmt_check_dup->execute();
            $res_dup = $stmt_check_dup->get_result();
            if ($res_dup->num_rows > 0) {
                $row = $res_dup->fetch_assoc();
                $response = ['status' => 'exists', 'checklist_id' => $row['id']];
            } else {
                $response = ['status' => 'clear'];
            }
            $stmt_check_dup->close();
            echo json_encode($response);
            exit;
        }

        // SECURITY: Block all AJAX saves if the checklist is already Completed, or if Not Assigned
        $checklist_id = (int)($_POST['checklist_id'] ?? 0);
        $employee_id = 0; 
        
        if ($checklist_id > 0) {
            $stmt_check = $conn->prepare("SELECT employee_id, overall_status, assigned_to FROM checklists WHERE id = ?");
            $stmt_check->bind_param("i", $checklist_id);
            $stmt_check->execute();
            $res_check = $stmt_check->get_result()->fetch_assoc();
            $stmt_check->close();
            
            if ($res_check) {
                $current_status = $res_check['overall_status'];
                $employee_id = $res_check['employee_id']; // Save employee_id for action_logs
                
                if ($current_status === 'Completed') {
                    throw new Exception("Checklist is locked (Completed). Changes cannot be saved.");
                }

                // Check if user is assigned or has global override permission
                $ajax_assigned_users = !empty($res_check['assigned_to']) ? json_decode($res_check['assigned_to'], true) : [];
                $ajax_is_assigned = is_array($ajax_assigned_users) && in_array($logged_in_username, $ajax_assigned_users);
                
                $ajax_can_edit_without_assign = can('edit_without_assign');
                $ajax_can_manage = can('manage_exit_checklist');
                $ajax_can_approve = can('approve_exit_checklist');
                
                $is_allowed_to_edit = false;
                if ($ajax_can_edit_without_assign) {
                    $is_allowed_to_edit = true;
                } elseif ($current_status === 'Preparing' && $ajax_is_assigned && $ajax_can_manage) {
                    $is_allowed_to_edit = true;
                } elseif (in_array($current_status, ['Verification Pending', 'Pending Approval']) && $ajax_can_approve) {
                    $is_allowed_to_edit = true;
                }
                
                if (!$is_allowed_to_edit) {
                    throw new Exception("You must be assigned to this checklist to make changes. (View-Only Mode)");
                }
            }
        }
        
        // 1. Update Config Fields (Last Working Day, Verified By fields)
        if ($_POST['ajax_action'] === 'update_config') {
            $field = $_POST['field']; 
            $value = trim($_POST['value']);
            
            $allowed_fields = ['last_working_day', 'asset_verified_by', 'backup_verified_by', 'voip_verified_by', 'email_sent_to_it_by'];
            if (!in_array($field, $allowed_fields)) throw new Exception("Invalid field.");

            $value_to_bind = ($value === '') ? null : $value;
            $stmt = $conn->prepare("UPDATE checklists SET $field = ? WHERE id = ?");
            $stmt->bind_param("si", $value_to_bind, $checklist_id);
            
            if ($stmt->execute()) {
                $action_desc = "Updated " . ucwords(str_replace('_', ' ', $field));
                // Call centralized function to avoid silent insert failures
                log_exit_activity($conn, $employee_id, $checklist_id, $logged_in_user_fullname, $action_desc, $field, null, $value_to_bind);
                
                $response = [
                    'status' => 'success', 
                    'message' => 'Details saved',
                    'updated_at' => date('M d, Y \a\t h:i A')
                ];
            } else {
                throw new Exception("Database update failed.");
            }
        }
        
        // 2. Update Checklist Items
        elseif ($_POST['ajax_action'] === 'update_item') {
            $item_id = (int)$_POST['item_id'];
            $field = $_POST['field']; // 'status' or 'remarks'
            $value = trim($_POST['value']);
            
            if (!in_array($field, ['status', 'remarks'])) throw new Exception("Invalid item field.");

            $stmt_get = $conn->prepare("SELECT id, task_description FROM checklist_items WHERE id = ?");
            $stmt_get->bind_param("i", $item_id);
            $stmt_get->execute();
            $res_get = $stmt_get->get_result();
            
            if ($res_get->num_rows > 0) {
                $item_row = $res_get->fetch_assoc();
                $task_desc = $item_row['task_description'];
                
                $stmt_upd = $conn->prepare("UPDATE checklist_items SET $field = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                $stmt_upd->bind_param("ssi", $value, $logged_in_user_fullname, $item_id);
                
                if ($stmt_upd->execute()) {
                    
                    $action_desc = "Updated " . ucwords($field) . " for task: " . $task_desc;
                    // Call centralized function to avoid silent insert failures
                    log_exit_activity($conn, $employee_id, $checklist_id, $logged_in_user_fullname, $action_desc, $field, null, $value);
                    
                    $response = [
                        'status' => 'success', 
                        'message' => 'Saved',
                        'updated_by' => $logged_in_user_fullname,
                        'updated_at' => date('M d, Y \a\t h:i A')
                    ];
                } else {
                    throw new Exception("Item update failed.");
                }
            } else {
                throw new Exception("Item not found.");
            }
        }
    } catch (Exception $e) {
        $response = ['status' => 'error', 'message' => $e->getMessage()];
    }
    echo json_encode($response);
    exit;
}

checkPermission('view_exit_checklist');

// --- INITIALIZE VARIABLES ---
$error = '';
$message = '';

if (isset($_SESSION['form_error'])) {
    $error = $_SESSION['form_error'];
    unset($_SESSION['form_error']);
}
if (isset($_SESSION['form_success'])) {
    $message = $_SESSION['form_success'];
    unset($_SESSION['form_success']);
}

// --- CONFIGURATION: MASTER CHECKLIST ---
$master_exit_checklist_items = [
    'DOMAIN LOGIN' => [
        ['task' => 'Disable AD logins and Move to "Disable Users" OU', 'critical' => true],
        ['task' => 'Remove from Domain Groups', 'critical' => true]
    ],
    'CLIENT LOGIN' => [
        ['task' => 'Disable Client credentials & save in TeamPass', 'critical' => true]
    ],
    'EMAIL' => [
        ['task' => 'Disable Email ID', 'critical' => false],
        ['task' => 'Remove from Departments Groups & Teams', 'critical' => false],
        ['task' => 'Remove the Mailbox License', 'critical' => false],
        ['task' => 'Enable email forwarding & auto reply (if required)', 'critical' => false]
    ],
    'IM' => [
        ['task' => 'Disable Teams', 'critical' => false],
        ['task' => 'Reset the Password and update in TeamPass', 'critical' => false]
    ],
    'SYSTEM' => [
        ['task' => 'Host Name', 'critical' => true],
        ['task' => 'Serial Number (S/N)', 'critical' => true],
        ['task' => 'Take back the System to Inventory', 'critical' => false],
        ['task' => 'Check for Physical damages', 'critical' => false],
        ['task' => 'Check for Pending assigned assets and update in GLPI', 'critical' => false],
        ['task' => 'Update to InStock in GLPI', 'critical' => false],
        ['task' => 'Move to InStock OU in AD', 'critical' => false],
        ['task' => 'Uninstall Cisco Endpoint Security', 'critical' => false],
        ['task' => 'Uninstall Cisco Umbrella - Laptop Only', 'critical' => false],
        ['task' => 'Delete the Hostname from Cisco Umbrella Console', 'critical' => false],
        ['task' => 'Delete the Computer in WSUS Server', 'critical' => false]
    ],
    'VOIP' => [
        ['task' => 'US Rec : Send details to remove in IT Dashboard', 'critical' => false],
        ['task' => 'Take VOIP Phone to Inventory', 'critical' => false],
        ['task' => 'Update to InStock in GLPI', 'critical' => false],
        ['task' => 'Disable the VOIP Extn in IPPBX', 'critical' => false],
        ['task' => 'US Rec & Marketing : Remove from Call report', 'critical' => false],
        ['task' => 'Update in VoIP Tracker', 'critical' => false],
        ['task' => 'US Rec/Others : Send details to remove in IT Dashboard', 'critical' => false]
    ],
    'FORTI TOKEN' => [
        ['task' => 'Remove user Id from SSLVPN group Id in AD', 'critical' => false],
        ['task' => 'Delete FortiToken from User Mobile', 'critical' => false],
        ['task' => 'Remove FortiToken from Firewall', 'critical' => false]
    ],
    'BACKUP' => [
        ['task' => 'Email Backup (PST) saved in Server', 'critical' => false],
        ['task' => 'User data backup saved in Server', 'critical' => false]
    ]
];

$verification_detail_fields = ['asset_verified_by', 'backup_verified_by', 'voip_verified_by', 'email_sent_to_it_by'];
$verification_field_labels = [
    'asset_verified_by'     => 'Asset Verified By',
    'backup_verified_by'    => 'Backup Verified By',
    'voip_verified_by'      => 'VoIP Verified By',
    'email_sent_to_it_by'   => 'Email Sent to IT By'
];

$checklist_id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
$edit_mode = ($checklist_id > 0);
$is_modal_view = isset($_GET['modal']) && $_GET['modal'] == 'true';

// --- FORM SUBMISSION LOGIC (For non-AJAX actions like Create, Assign, Submit, Approve, Reject) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && !isset($_POST['ajax_action'])) {
    
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Security Validation Failed: Invalid CSRF Token.");
    }

    $current_username = $_SESSION['username'];
    $current_fullname = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? ''));
    if (empty($current_fullname)) { $current_fullname = $current_username; }

    $current_timestamp = date('Y-m-d H:i:s');
    $action = $_POST['action'];

    $log_employee_id = 0;
    if ($edit_mode) {
        $stmt_e = $conn->prepare("SELECT employee_id FROM checklists WHERE id = ?");
        $stmt_e->bind_param("i", $checklist_id);
        $stmt_e->execute();
        if ($row_e = $stmt_e->get_result()->fetch_assoc()) { $log_employee_id = $row_e['employee_id']; }
        $stmt_e->close();
    }

    $conn->begin_transaction();
    try {
        if ($action === 'create_checklist' && !$edit_mode) {
            $employee_id = $_POST['employee_id'] ?? 0;
            $last_working_day = !empty($_POST['last_working_day']) ? $_POST['last_working_day'] : null;

            if (empty($employee_id)) { throw new Exception("An employee must be selected."); }
            if (empty($last_working_day)) { throw new Exception("Last Working Day must be specified."); }

            // --- CHECK FOR DUPLICATE / EXISTING INCOMPLETE CHECKLIST ---
            $sql_check = "SELECT id FROM checklists 
                          WHERE employee_id = ? 
                          AND checklist_type = 'exit_checklist' 
                          AND overall_status != 'Completed'";

            $stmt_check_duplicate = $conn->prepare($sql_check);
            $stmt_check_duplicate->bind_param("i", $employee_id);
            $stmt_check_duplicate->execute();
            $result_duplicate = $stmt_check_duplicate->get_result();

            if ($result_duplicate->num_rows > 0) {
                $existing_checklist = $result_duplicate->fetch_assoc();
                $existing_checklist_id = $existing_checklist['id'];
                
                throw new Exception(
                    "An active Exit Checklist already exists for this employee. A new one cannot be created until the existing one is completed. <a href='" . APP_BASE_PATH . "index.php?route=add_edit_exit_checklist&id={$existing_checklist_id}' class='alert-link text-decoration-underline'>Click here to view it (#{$existing_checklist_id}).</a>"
                );
            }
            $stmt_check_duplicate->close();
            // -------------------------------------------------------------

            $stmt = $conn->prepare("INSERT INTO checklists (employee_id, checklist_type, last_working_day, created_by, overall_status) VALUES (?, 'exit_checklist', ?, ?, 'Not Started')");
            $stmt->bind_param("iss", $employee_id, $last_working_day, $current_fullname);
            if (!$stmt->execute()) throw new Exception("Failed to create checklist: " . $stmt->error);
            $new_checklist_id = $stmt->insert_id;
            $stmt->close();

            foreach ($master_exit_checklist_items as $category => $tasks) {
                foreach ($tasks as $task_data) {
                    $stmt_item = $conn->prepare("INSERT INTO checklist_items (template_id, checklist_category, task_description, status) VALUES (?, ?, ?, 'Pending')");
                    $stmt_item->bind_param("iss", $new_checklist_id, $category, $task_data['task']);
                    $stmt_item->execute();
                    $stmt_item->close();
                }
            }

            if ($last_working_day === date('Y-m-d')) {
                $stmt_deactivate = $conn->prepare("UPDATE employees SET employee_status = 'Inactive' WHERE id = ?");
                $stmt_deactivate->bind_param("i", $employee_id);
                $stmt_deactivate->execute();
                $stmt_deactivate->close();
            }

            log_exit_activity($conn, $employee_id, $new_checklist_id, $current_fullname, "Exit checklist Initialized.");
            $_SESSION['form_success'] = "New exit checklist #$new_checklist_id created successfully.";
            $conn->commit();
            header('Location: ' . APP_BASE_PATH . 'index.php?route=add_edit_exit_checklist&id=' . $new_checklist_id);
            exit();

        } elseif ($action === 'assign_checklist' && $edit_mode) {
            $assignees = $_POST['assignees'] ?? [];
            if (empty($assignees)) throw new Exception("Select at least one user.");

            $names_q = implode(',', array_fill(0, count($assignees), '?'));
            $types = str_repeat('s', count($assignees));
            $n_stmt = $conn->prepare("SELECT username, firstname, lastname FROM users WHERE username IN ($names_q)");
            $n_stmt->bind_param($types, ...$assignees); $n_stmt->execute();
            $n_res = $n_stmt->get_result();
            $assignee_names = [];
            while ($r = $n_res->fetch_assoc()) { $assignee_names[] = trim($r['firstname'].' '.$r['lastname']); }
            $n_stmt->close();

            $h_stmt = $conn->prepare("SELECT assignment_history FROM checklists WHERE id = ?");
            $h_stmt->bind_param("i", $checklist_id); $h_stmt->execute();
            $curr_hist = json_decode($h_stmt->get_result()->fetch_assoc()['assignment_history'] ?? '[]', true);
            $curr_hist[] = ['assigned_by' => $current_fullname, 'assigned_to' => $assignee_names, 'assigned_at' => $current_timestamp];

            $upd = $conn->prepare("UPDATE checklists SET overall_status = 'Preparing', assigned_to = ?, assignment_history = ? WHERE id = ?");
            $aj = json_encode($assignees); $hj = json_encode($curr_hist);
            $upd->bind_param("ssi", $aj, $hj, $checklist_id);
            $upd->execute();
            
            log_exit_activity($conn, $log_employee_id, $checklist_id, $current_fullname, "Assigned to: " . implode(', ', $assignee_names));
            $_SESSION['form_success'] = "Checklist assigned successfully.";

        } elseif ($action === 'submit_for_approval' && $edit_mode) {
            $stmt = $conn->prepare("UPDATE checklists SET overall_status = 'Verification Pending', prepared_by = ?, prepared_at = ? WHERE id = ?");
            $stmt->bind_param("ssi", $current_fullname, $current_timestamp, $checklist_id);
            $stmt->execute();
            
            log_exit_activity($conn, $log_employee_id, $checklist_id, $current_fullname, "Submitted for approval.");
            $_SESSION['form_success'] = "Submitted successfully.";

        } elseif ($action === 'approve_checklist' && $edit_mode) {
            $approved_by = $_POST['approved_by'] ?? $current_fullname;
            $stmt = $conn->prepare("UPDATE checklists SET overall_status = 'Completed', approved_by = ?, approved_at = ? WHERE id = ?");
            $stmt->bind_param("ssi", $approved_by, $current_timestamp, $checklist_id);
            $stmt->execute();
            
            log_exit_activity($conn, $log_employee_id, $checklist_id, $current_fullname, "Approved and completed.");
            $_SESSION['form_success'] = "Approved successfully.";

        } elseif ($action === 'reject_checklist' && $edit_mode) {
            $remarks = trim($_POST['rejection_remarks'] ?? '');
            if (empty($remarks)) throw new Exception("Rejection remarks are required.");

            // Re-assign to submitter logic
            $stmt_prep = $conn->prepare("SELECT prepared_by, assignment_history FROM checklists WHERE id = ?");
            $stmt_prep->bind_param("i", $checklist_id); $stmt_prep->execute();
            $prep_data = $stmt_prep->get_result()->fetch_assoc();
            $prepared_by_name = $prep_data['prepared_by'] ?? '';
            $stmt_prep->close();

            $prepared_by_username = '';
            if (!empty($prepared_by_name)) {
                 $stmt_find = $conn->prepare("SELECT username FROM users WHERE TRIM(CONCAT(firstname, ' ', lastname)) = ? LIMIT 1");
                 if ($stmt_find) {
                     $stmt_find->bind_param("s", $prepared_by_name);
                     $stmt_find->execute();
                     if ($row_find = $stmt_find->get_result()->fetch_assoc()) {
                         $prepared_by_username = $row_find['username'];
                     }
                     $stmt_find->close();
                 }
            }

            $sql = "UPDATE checklists SET overall_status = 'Preparing'";
            $types = ""; $params = [];
            
            if (!empty($prepared_by_username)) {
                $sql .= ", assigned_to = ?";
                $types .= "s";
                $params[] = json_encode([$prepared_by_username]);
                
                $history = !empty($prep_data['assignment_history']) ? json_decode($prep_data['assignment_history'], true) : [];
                $history[] = [
                    'assigned_by' => $current_fullname,
                    'assigned_to' => [$prepared_by_name],
                    'assigned_at' => $current_timestamp,
                    'note' => 'Reassigned upon rejection'
                ];
                $sql .= ", assignment_history = ?";
                $types .= "s";
                $params[] = json_encode($history);
            }
            
            $sql .= " WHERE id = ?";
            $types .= "i";
            $params[] = $checklist_id;
            
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $stmt->close();
            }

            log_exit_activity($conn, $log_employee_id, $checklist_id, $current_fullname, "Checklist Rejected: " . $remarks);
            $_SESSION['form_rejection'] = "Checklist rejected and sent back to 'Preparing'.";
             if (!empty($prepared_by_username)) {
                $_SESSION['form_rejection'] .= " Reassigned to " . htmlspecialchars($prepared_by_name) . ".";
            }
        }

        $conn->commit();
        header('Location: ' . APP_BASE_PATH . 'index.php?route=add_edit_exit_checklist&id=' . $checklist_id);
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['form_error'] = $e->getMessage();
        header('Location: ' . APP_BASE_PATH . 'index.php?route=add_edit_exit_checklist' . ($edit_mode ? "&id=$checklist_id" : ""));
        exit();
    }
}

// --- DATA FETCHING ---
$checklist = null; $employee = null; $items_by_task = []; $it_users = []; $approvers = [];
$last_rejection_data = null;

if ($edit_mode) {
    $stmt = $conn->prepare("SELECT c.*, e.emp_id, e.first_name, e.last_name, e.department, e.location, e.email_id FROM checklists c JOIN employees e ON c.employee_id = e.id WHERE c.id = ?");
    $stmt->bind_param("i", $checklist_id); $stmt->execute(); 
    $res = $stmt->get_result();
    if ($res->num_rows > 0) { 
        $checklist = $res->fetch_assoc(); 
        $checklist['full_name'] = trim($checklist['first_name'] . ' ' . $checklist['last_name']);
        $employee = $checklist; 
    } else { 
        $_SESSION['form_error'] = "Checklist not found."; 
        header('Location: ' . APP_BASE_PATH . 'index.php?route=exit_checklist'); 
        exit(); 
    }
    $stmt->close();

    $stmt_i = $conn->prepare("SELECT * FROM checklist_items WHERE template_id = ?");
    $stmt_i->bind_param("i", $checklist_id); $stmt_i->execute(); 
    $res_i = $stmt_i->get_result();
    while ($row = $res_i->fetch_assoc()) { 
        $items_by_task[$row['checklist_category']][$row['task_description']] = $row; 
    }
    $stmt_i->close();

    if ($checklist['overall_status'] === 'Preparing') {
        $stmt_rej = $conn->prepare("SELECT action_description, performed_by, timestamp FROM action_logs WHERE template_id = ? AND action_type = 'Exit Checklist' AND action_description LIKE 'Checklist Rejected:%' ORDER BY id DESC LIMIT 1");
        if ($stmt_rej) {
            $stmt_rej->bind_param("i", $checklist_id);
            $stmt_rej->execute();
            $res_rej = $stmt_rej->get_result();
            if ($rej_row = $res_rej->fetch_assoc()) {
                $last_rejection_data = [
                    'reason' => str_replace("Checklist Rejected: ", "", $rej_row['action_description']),
                    'by' => $rej_row['performed_by'],
                    'date' => date('d-m-Y H:i', strtotime($rej_row['timestamp']))
                ];
            }
            $stmt_rej->close();
        }
    }
}

// --- FETCH LAST UPDATED TIME ---
$last_updated_time = null;
if ($edit_mode) {
    $stmt_last = $conn->prepare("SELECT timestamp FROM action_logs WHERE template_id = ? AND action_type = 'Exit Checklist' ORDER BY timestamp DESC LIMIT 1");
    if ($stmt_last) {
        $stmt_last->bind_param("i", $checklist_id);
        $stmt_last->execute();
        $res_last = $stmt_last->get_result();
        if ($row_last = $res_last->fetch_assoc()) {
            $last_updated_time = $row_last['timestamp'];
        }
        $stmt_last->close();
    }
    if (!$last_updated_time) {
        $last_updated_time = $checklist['updated_at'] ?? $checklist['created_at'] ?? null;
    }
}

$stmt_it = $conn->prepare("SELECT u.username, u.firstname, u.lastname FROM users u JOIN roles r ON u.role_id = r.id JOIN role_permissions rp ON r.id = rp.role_id JOIN permissions p ON rp.permission_id = p.id WHERE p.action_key = 'manage_exit_checklist'");
$stmt_it->execute(); $res_it = $stmt_it->get_result();
while ($r = $res_it->fetch_assoc()) { $it_users[$r['username']] = trim($r['firstname'] . ' ' . $r['lastname']); }

$stmt_app = $conn->prepare("SELECT u.username, u.firstname, u.lastname FROM users u JOIN roles r ON u.role_id = r.id JOIN role_permissions rp ON r.id = rp.role_id JOIN permissions p ON rp.permission_id = p.id WHERE p.action_key = 'approve_exit_checklist'");
$stmt_app->execute(); $res_app = $stmt_app->get_result();
while ($r = $res_app->fetch_assoc()) { $approvers[$r['username']] = trim($r['firstname'] . ' ' . $r['lastname']); }

$status = $checklist['overall_status'] ?? 'Not Started';
$assigned_users = !empty($checklist['assigned_to']) ? json_decode($checklist['assigned_to'], true) : [];
$is_assigned_to_current_user = in_array($_SESSION['username'], $assigned_users);

// Permissions
$can_manage = can('manage_exit_checklist');
$can_assign_others = can('approve_exit_checklist'); 
$can_approve = can('approve_exit_checklist');
$can_edit_without_assign = can('edit_without_assign'); 

// STRICT LOCKING LOGIC
$is_editable_now = ($status !== 'Completed') && (
                   $can_edit_without_assign ||
                   ($can_manage && $status === 'Preparing' && $is_assigned_to_current_user) ||
                   ($can_approve && ($status === 'Pending Approval' || $status === 'Verification Pending'))
);

// Helper to fetch assigned full names
$assigned_full_names = [];
if (!empty($assigned_users)) {
    foreach ($assigned_users as $u) {
        $assigned_full_names[] = $it_users[$u] ?? $u;
    }
}
$assigned_display_string = !empty($assigned_full_names) ? implode(', ', $assigned_full_names) : 'Unassigned';

function renderChecklistItems($master_items, $items_by_task, $is_editable_now, $is_assigned_to_current_user, $checklist_status) {
    foreach ($items_by_task as $category => $tasks) {
        echo '<tr><td colspan="4" class="category-header"><div class="d-flex align-items-center gap-2"><div class="category-indicator"></div>' . htmlspecialchars($category) . '</div></td></tr>';
        
        foreach ($tasks as $task_desc => $item) {
            
            $is_critical = false;
            if (isset($master_items[$category])) {
                foreach ($master_items[$category] as $master_task) {
                    if ($master_task['task'] === $task_desc) {
                        $is_critical = $master_task['critical'] ?? false;
                        break;
                    }
                }
            }
            
            $item_db_id = $item['id'] ?? 0;
            $current_status = $item['status'] ?? 'Pending';
            $current_remarks = $item['remarks'] ?? '';
            $updated_by_fullname = $item['updated_by'] ?? '';
            $updated_at_ts = $item['updated_at'] ?? '';
            
            $disable_individual_fields = !$is_editable_now;

            $tooltip_attr = '';
            $wrapper_start = '';
            $wrapper_end = '';
            
            if ($disable_individual_fields) {
                $msg = "Editing disabled";
                if ($checklist_status === 'Completed') {
                    $msg = "Checklist is locked (Completed).";
                } elseif (!$is_assigned_to_current_user && $checklist_status === 'Preparing') {
                    $msg = "You must be assigned to this checklist to make changes.";
                } elseif ($checklist_status === 'Verification Pending' || $checklist_status === 'Pending Approval') {
                    $msg = "Checklist is pending verification.";
                }
                $wrapper_start = '<span class="d-inline-block" tabindex="0" data-bs-toggle="tooltip" title="' . htmlspecialchars($msg) . '">';
                $wrapper_end = '</span>';
                $tooltip_attr = 'data-bs-toggle="tooltip" title="' . htmlspecialchars($msg) . '"';
            }

            ?>
            <tr class="item-row">
                <td style="width: 40%; padding-left: 1.25rem;">
                    <div class="task-title d-flex align-items-center">
                        <?php echo htmlspecialchars($task_desc); ?>
                        <?php if($is_critical): ?><span class="text-danger ms-1 fw-bold critical-task" title="Critical Task">*</span><?php endif; ?>
                    </div>
                </td>
                <td class="text-center" style="width: 15%;">
                    <?php echo $wrapper_start; ?>
                    <select name="status_<?php echo $item_db_id; ?>" 
                        class="form-select status-select status-<?php echo strtolower(str_replace('/', '\/', $current_status)); ?> ajax-update-item" 
                        data-item-id="<?php echo $item_db_id; ?>"
                        data-field="status"
                        <?php echo $disable_individual_fields ? 'disabled' : ''; ?>>
                        <option value="Pending" <?php if ($current_status === 'Pending') echo 'selected'; ?>>Pending</option>
                        <option value="Completed" <?php if ($current_status === 'Completed') echo 'selected'; ?>>Completed</option>
                        <option value="N/A" <?php if ($current_status === 'N/A') echo 'selected'; ?>>N/A</option>
                    </select>
                    <?php echo $wrapper_end; ?>
                </td>
                <td>
                    <input type="text" name="remarks_<?php echo $item_db_id; ?>" 
                        class="form-control form-control-flush ajax-update-item" 
                        data-item-id="<?php echo $item_db_id; ?>"
                        data-field="remarks"
                        placeholder="Add remarks..." 
                        value="<?php echo htmlspecialchars($current_remarks); ?>" 
                        <?php echo $tooltip_attr; ?>
                        <?php echo $disable_individual_fields ? 'readonly' : ''; ?>>
                </td>
                <td class="pe-3 updated-by-cell" style="width: 180px;">
                    <?php if (in_array($current_status, ['Completed', 'N/A']) && !empty($updated_by_fullname)): ?>
                        <div class="lh-sm">
                            <div class="fw-bold text-dark" style="font-size: 0.8rem;"><?php echo htmlspecialchars($updated_by_fullname); ?></div>
                            <div class="text-muted" style="font-size: 0.7rem;"><?php echo !empty($updated_at_ts) ? date('M d, Y \a\t h:i A', strtotime($updated_at_ts)) : ''; ?></div>
                        </div>
                    <?php endif; ?>
                </td>
            </tr>
            <?php
        }
    }
}

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
    
    body { font-family: 'Inter', sans-serif; background-color: var(--bg-color) !important; color: var(--text-main); padding-bottom: 100px; font-size: 0.875rem; }
    h1, h2, h3, h4, h5, h6 { font-weight: 600; color: #1e293b; }
    
    /* Modern Cards */
    .modern-card { background: var(--card-bg); border-radius: var(--radius); box-shadow: var(--card-shadow); border: 1px solid var(--border-color); overflow: hidden; margin-bottom: 24px; }
    .modern-card-header { padding: 16px 20px; background: #ffffff; border-bottom: 1px solid var(--border-color); font-weight: 600; color: var(--primary-color); display: flex; align-items: center; gap: 8px; font-size: 0.95rem; }
    .modern-card-body { padding: 20px; }
    
    /* Tabs */
    .nav-tabs { border-bottom: 1px solid var(--border-color); gap: 10px; margin-bottom: 20px; }
    .nav-tabs .nav-link { color: var(--text-light); border: none; border-bottom: 2px solid transparent; font-weight: 500; padding: 10px 16px; transition: all 0.2s; }
    .nav-tabs .nav-link:hover { color: var(--primary-color); background: #f1f5f9; border-radius: 6px 6px 0 0; }
    .nav-tabs .nav-link.active { color: var(--primary-color); border-bottom: 2px solid var(--primary-color); background: transparent; font-weight: 600; }

    /* Table Styling */
    .table-custom { width: 100%; border-collapse: separate; border-spacing: 0; }
    .table-custom th { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-light); font-weight: 600; padding: 12px 16px; border-bottom: 1px solid var(--border-color); background: #f8fafc; }
    .category-header { background-color: #f1f5f9; color: #0f172a; font-weight: 600; padding: 10px 16px; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.03em; border-bottom: 1px solid var(--border-color); border-top: 1px solid var(--border-color); }
    .category-indicator { width: 4px; height: 14px; background-color: var(--primary-color); border-radius: 2px; }
    .item-row td { padding: 10px 12px; vertical-align: middle; border-bottom: 1px solid #f1f5f9; color: var(--text-main); }
    .item-row:hover td { background-color: #f8fafc; }
    
    /* Form Elements */
    .form-label { font-weight: 600; font-size: 0.75rem; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.02em; margin-bottom: 6px; }
    .form-control, .form-select { border-radius: 8px; padding: 8px 12px; font-size: 0.875rem; border: 1px solid #cbd5e1; transition: all 0.2s; }
    .form-control:focus, .form-select:focus { border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1); }
    .form-control[readonly] { background-color: #f8fafc; color: #64748b; border-color: #e2e8f0; }
    
    .form-control-flush { border: none; border-bottom: 1px solid transparent; background: transparent; padding: 6px 0; border-radius: 0; }
    .form-control-flush:focus { box-shadow: none; border-bottom-color: var(--primary-color); background: #fff; }

    /* Status Pills */
    .status-select { border-radius: 20px; font-size: 0.75rem; font-weight: 600; padding: 4px 28px 4px 12px; border: 1px solid transparent; cursor: pointer; width: auto; display: inline-block; }
    .status-pending { background-color: #f1f5f9; color: #64748b; }
    .status-completed { background-color: #dcfce7; color: #15803d; border-color: #bbf7d0; }
    .status-n\/a { background-color: #f3f4f6; color: #9ca3af; }

    .btn-primary-modern { background-color: var(--primary-color); color: white; border: none; border-radius: 20px; padding: 6px 20px; font-weight: 600; font-size: 0.875rem; transition: all 0.2s; }
    .btn-primary-modern:hover { background-color: var(--primary-hover); transform: translateY(-1px); color: white; }
    .btn-primary-modern:disabled { background-color: #94a3b8; cursor: not-allowed; transform: none; }
    
    .status-badge { padding: 6px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; letter-spacing: 0.02em; text-transform: uppercase; }

    /* Employee Card Styles */
    .employee-card-gradient { background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); }

    /* Custom Search Dropdown Styling mapped over default jQuery UI */
    .ui-autocomplete {
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05) !important;
        border-radius: 8px !important;
        border: 1px solid var(--border-color) !important;
        background: #ffffff !important;
        z-index: 1050 !important;
        padding: 4px 0 !important;
        max-height: 300px;
        overflow-y: auto;
        overflow-x: hidden;
    }
    .ui-menu-item-wrapper {
        padding: 10px 16px !important;
        font-family: 'Inter', sans-serif !important;
        font-size: 0.875rem !important;
        color: var(--text-main) !important;
        border: none !important;
        background: transparent !important;
        transition: background-color 0.15s;
        cursor: pointer !important;
    }
    .ui-menu-item-wrapper.ui-state-active {
        background-color: #f0fdfa !important;
        color: var(--primary-color) !important;
        margin: 0 !important;
    }

    /* Toast Container */
    .toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; }
</style>

<div class="toast-container" id="toastContainer"></div>

<div class="container-xl py-4">

    <?php if (!$edit_mode): ?>
        <div class="row justify-content-center">
            <div class="col-lg-8">
                
                <?php if (!$is_modal_view): ?>
                <div class="mb-4">
                    <a href="<?= APP_BASE_PATH ?>index.php?route=exit_checklist" onclick="if(document.referrer !== '') { window.history.back(); return false; }" class="text-decoration-none text-muted small fw-bold d-inline-flex align-items-center transition-colors hover-primary mb-2">
    			<i data-feather="arrow-left" style="width:14px;" class="me-1"></i> BACK TO LIST
		    </a>                    
	  	    <div>
                        <h4 class="fw-bold m-0 text-dark">Initialize Exit Checklist</h4>
                        <div class="text-muted small mt-1">Select an employee to begin</div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger shadow-sm border-0 rounded-3 d-flex align-items-start gap-3 mb-4">
                        <i data-feather="alert-circle" class="mt-1 flex-shrink-0"></i>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    </div>
                <?php endif; ?>

                <div class="modern-card shadow-sm">
                    <div class="modern-card-header bg-white border-bottom">
                        <i data-feather="user-minus" style="width:18px;"></i> Employee Selection
                    </div>
                    <div class="modern-card-body">
                        <form id="createChecklistForm" method="POST" action="<?= APP_BASE_PATH ?>index.php?route=add_edit_exit_checklist">
                            <input type="hidden" name="action" value="create_checklist">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="employee_id" id="employee_id">

                            <div class="row g-4">
                                <div class="col-12">
                                    <label class="form-label">Search Employee <span class="text-danger">*</span></label>
                                    <div class="position-relative">
                                        <i data-feather="search" class="position-absolute text-muted" style="left: 14px; top: 50%; transform: translateY(-50%); width: 16px;"></i>
                                        <input type="text" id="employee_search" class="form-control ps-5 py-2 fw-medium" placeholder="Type name or Emp ID to search..." autocomplete="off" required>
                                    </div>
                                    <div class="form-text text-muted small mt-1">Select from the dropdown to auto-fill details.</div>
                                </div>
                                
                                <div class="col-md-12">
                                    <label class="form-label">Last Working Day <span class="text-danger">*</span></label>
                                    <input type="date" name="last_working_day" class="form-control" required>
                                </div>

                                <div class="col-12 mt-4">
                                    <div class="p-4 bg-light rounded-3 border">
                                        <div class="d-flex align-items-center mb-3">
                                            <i data-feather="info" class="text-muted me-2" style="width: 14px;"></i>
                                            <h6 class="fw-bold m-0 text-muted" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;">Auto-Filled Details</h6>
                                        </div>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label">Employee ID</label>
                                                <input type="text" id="emp_id_display" class="form-control fw-medium" readonly>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Department</label>
                                                <input type="text" id="department_display" class="form-control" readonly>
                                            </div>
                                            <div class="col-md-12">
                                                <label class="form-label">Location</label>
                                                <input type="text" id="location_display" class="form-control" readonly>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                                <a href="<?= APP_BASE_PATH ?>index.php?route=exit_checklist" onclick="if(document.referrer !== '') { window.history.back(); return false; }" class="btn btn-light border rounded-pill px-4 fw-medium text-muted">Cancel</a>
                                <button type="submit" id="save_button" class="btn btn-primary-modern d-flex align-items-center shadow-sm" disabled>
                                    Generate Checklist <i data-feather="arrow-right" class="ms-2" style="width:16px;"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>

    <?php if (!$is_modal_view): ?>
    <div class="mb-4">
        <a href="<?= APP_BASE_PATH ?>index.php?route=exit_checklist" onclick="if(document.referrer !== '') { window.history.back(); return false; }" class="text-decoration-none text-muted small fw-bold d-inline-flex align-items-center transition-colors hover-primary mb-2">
            <i data-feather="arrow-left" style="width:14px;" class="me-1"></i> BACK TO LIST
        </a>
        <div class="d-flex justify-content-between align-items-end mt-2">
            <h4 class="fw-bold m-0 text-dark">Exit Checklist Details</h4>
            <div class="text-muted small">ID: #<?php echo $checklist_id; ?></div>
        </div>
    </div>
    <?php endif; ?>

    <ul class="nav nav-tabs">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#checklist-pane">Checklist Details</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#timeline-pane">Activity Timeline</button></li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="checklist-pane">
            
            <?php if ($last_rejection_data): ?>
            <div class="alert alert-danger shadow-sm mb-4 border-0 rounded-3 d-flex gap-3 align-items-start">
                <i data-feather="alert-circle" class="mt-1"></i>
                <div>
                    <h6 class="fw-bold mb-1">Checklist Returned</h6>
                    <div class="small mb-1"><?php echo nl2br(htmlspecialchars($last_rejection_data['reason'])); ?></div>
                    <div class="text-muted text-xs">By <?php echo htmlspecialchars($last_rejection_data['by']); ?> on <?php echo $last_rejection_data['date']; ?></div>
                </div>
            </div>
            <?php endif; ?>

            <form id="mainForm" method="POST" action="<?= APP_BASE_PATH ?>index.php?route=add_edit_exit_checklist&id=<?php echo $checklist_id; ?>">
                <input type="hidden" name="action" id="formAction" value="">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="row g-4 mb-4 d-flex align-items-stretch">
                    <div class="col-lg-5 d-flex">
                        <div class="modern-card w-100 mb-0 employee-card-gradient">
                            <div class="modern-card-header border-bottom-0 pb-0 bg-transparent">
                                <i data-feather="user-minus" style="width:16px;"></i> Exiting Employee Details
                            </div>
                            <div class="modern-card-body pt-3">
                                <div class="d-flex align-items-center mb-4">
                                    <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold me-3 shadow" style="width:58px; height:58px; font-size:1.6rem; flex-shrink: 0;">
                                        <?php echo strtoupper(substr($employee['full_name'] ?? 'A', 0, 1)); ?>
                                    </div>
                                    <div class="flex-grow-1 overflow-hidden">
                                        <h5 class="fw-bold text-dark m-0 text-truncate" title="<?php echo htmlspecialchars($employee['full_name'] ?? ''); ?>">
                                            <?php echo htmlspecialchars($employee['full_name'] ?? ''); ?>
                                        </h5>
                                        <div class="text-secondary fw-medium fs-6 mt-1"><?php echo htmlspecialchars($employee['department'] ?? 'N/A'); ?></div>
                                    </div>
                                </div>

                                <div class="row g-3">
                                    <div class="col-6">
                                        <div class="p-2 rounded bg-white border h-100">
                                            <div class="text-xs text-uppercase text-muted fw-bold mb-1" style="font-size: 0.7rem; letter-spacing: 0.03em;">Employee ID</div>
                                            <div class="fw-medium text-dark font-monospace"><?php echo htmlspecialchars($employee['emp_id'] ?? ''); ?></div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="p-2 rounded bg-white border h-100">
                                            <div class="text-xs text-uppercase text-muted fw-bold mb-1" style="font-size: 0.7rem; letter-spacing: 0.03em;">Location</div>
                                            <div class="fw-medium text-dark text-truncate"><?php echo htmlspecialchars($employee['location'] ?? 'N/A'); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-7 d-flex">
                        <div class="modern-card w-100 mb-0">
                            <div class="modern-card-header justify-content-between">
                                <div><i data-feather="settings" style="width:16px;"></i> Exit Checklist Configuration</div>
                                <?php if ($last_updated_time): ?>
                                    <div class="text-muted fw-normal" style="font-size: 0.75rem; letter-spacing: 0;">
                                        <i data-feather="clock" style="width:12px; margin-top:-2px;" class="me-1"></i> Last updated: <span id="globalLastUpdated"><?php echo date('M d, Y \a\t h:i A', strtotime($last_updated_time)); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="modern-card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Last Working Day <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control fw-bold ajax-update-config" name="last_working_day" value="<?php echo htmlspecialchars($checklist['last_working_day'] ?? ''); ?>" <?php echo $is_editable_now ? '' : 'readonly'; ?> required>
                                    </div>
                                    
                                    <?php foreach($verification_detail_fields as $db_col):
                                        $lbl = $verification_field_labels[$db_col];
                                        $curr = $checklist[$db_col] ?? '';
                                    ?>
                                    <div class="col-md-6">
                                        <label class="form-label"><?php echo htmlspecialchars($lbl); ?> <?php if($db_col === 'asset_verified_by') echo '<span class="text-danger">*</span>'; ?></label>
                                        <select name="<?php echo $db_col; ?>" class="form-select ajax-update-config" <?php if (!$is_editable_now) echo 'disabled'; ?>>
                                            <option value="">-- Select User --</option>
                                            <?php foreach ($it_users as $u => $f): ?>
                                                <option value="<?php echo htmlspecialchars($f); ?>" <?php if ($curr === $f) echo 'selected'; ?>><?php echo htmlspecialchars($f); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modern-card mb-4 p-3 bg-white border">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div class="d-flex align-items-center gap-4 flex-wrap">
                            <div>
                                <span class="status-badge <?php 
                                    if ($status == 'Completed') echo 'bg-success-subtle text-success border border-success-subtle';
                                    else if ($status == 'Verification Pending' || $status == 'Pending Approval') echo 'bg-warning-subtle text-warning-emphasis border border-warning-subtle';
                                    else if ($status == 'Preparing') echo 'bg-info-subtle text-info-emphasis border border-info-subtle';
                                    else echo 'bg-secondary-subtle text-secondary border border-secondary-subtle';
                                ?>">
                                    <?php echo htmlspecialchars($status); ?>
                                </span>
                            </div>

                            <div class="fs-6 fw-bold text-dark">
                                <span class="text-muted fw-normal small me-1 text-uppercase" style="font-size: 0.7rem;">Assigned To:</span>
                                <span><?php echo htmlspecialchars($assigned_display_string); ?></span>
                            </div>

                            <div class="vr d-none d-md-block text-muted opacity-25" style="height: 24px;"></div>

                            <div>
                                <?php 
                                    if ($status === 'Preparing' && $is_assigned_to_current_user && $can_manage) {
                                        echo '<button type="button" class="btn btn-primary-modern btn-sm" id="btnSubmitApproval">Submit for Approval</button>';
                                    } elseif (($status === 'Not Started' || $status === 'Preparing') && ($can_manage || $can_approve)) {
                                        if ($can_assign_others) {
                                            $assign_txt = ($status === 'Preparing') ? 'Re-assign' : 'Assign';
                                            echo '<button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#assignModal">'.$assign_txt.'</button>';
                                        } else {
                                            echo '<button type="submit" form="assignSelfForm" class="btn btn-outline-primary btn-sm rounded-pill px-3">Assign to Me</button>';
                                        }
                                    } elseif (($status === 'Pending Approval' || $status === 'Verification Pending') && $can_approve) {
                                        echo '<div class="d-flex align-items-center gap-2">';
                                        echo '<button type="button" class="btn btn-success text-white btn-sm rounded-pill px-4 fw-bold" data-bs-toggle="modal" data-bs-target="#approveModal">Approve</button>';
                                        echo '<button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#rejectModal">Reject</button>';
                                        echo '</div>';
                                    }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modern-card">
                    <div class="modern-card-header">
                        <i data-feather="check-square" style="width:16px;"></i> Checklist Tasks
                    </div>
                    <div class="table-responsive">
                        <table class="table-custom">
                            <thead>
                                <tr>
                                    <th style="padding-left: 1.25rem;">Task Description</th>
                                    <th class="text-center" style="width: 140px;">Status</th>
                                    <th>Remarks / Details</th>
                                    <th class="pe-3" style="width: 180px;">Updated By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php renderChecklistItems($master_exit_checklist_items, $items_by_task, $is_editable_now, $is_assigned_to_current_user, $status); ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </form>
        </div>

        <div class="tab-pane fade" id="timeline-pane">
            <div class="modern-card">
                <div class="modern-card-header">Activity Timeline</div>
                <div class="modern-card-body">
                    <?php 
                    // FIXED: Reverted to use employee_id so the logs correctly match the DB query
                    $timeline_parent_id = $checklist['employee_id']; 
                    $timeline_action_type = 'Exit Checklist'; 
                    if(file_exists('../includes/timeline_partial.php')) include '../includes/timeline_partial.php'; 
                    else echo '<div class="text-muted small p-3">Timeline not available.</div>';
                    ?>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="assignModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow"><form method="POST" action="<?= APP_BASE_PATH ?>index.php?route=add_edit_exit_checklist&id=<?php echo $checklist_id; ?>"><div class="modal-header border-bottom-0"><h5 class="modal-title fw-bold">Assign Checklist</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="action" value="assign_checklist"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><div class="mb-3"><label class="form-label">Select Users</label><select name="assignees[]" class="form-select" multiple required style="height: 150px;"><?php 
    $curr_user = $_SESSION['username'];
    if (array_key_exists($curr_user, $it_users)) {
        $curr_val = $it_users[$curr_user]; unset($it_users[$curr_user]); $it_users = [$curr_user => $curr_val] + $it_users;
    }
    foreach ($it_users as $u => $f) { echo '<option value="'.htmlspecialchars($u).'" '.($u === $curr_user ?'selected':'').'>'.htmlspecialchars($f).'</option>'; } 
    ?></select></div></div><div class="modal-footer border-top-0"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary-modern">Assign</button></div></form></div></div></div>

    <div class="modal fade" id="rejectModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow"><form method="POST" action="<?= APP_BASE_PATH ?>index.php?route=add_edit_exit_checklist&id=<?php echo $checklist_id; ?>"><div class="modal-header border-bottom-0"><h5 class="modal-title fw-bold text-danger">Reject Checklist</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="action" value="reject_checklist"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><div class="mb-3"><label class="form-label">Reason for Rejection</label><textarea name="rejection_remarks" class="form-control" rows="3" required placeholder="Describe what needs to be fixed..."></textarea></div></div><div class="modal-footer border-top-0"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger rounded-pill px-4">Reject</button></div></form></div></div></div>

    <div class="modal fade" id="approveModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow"><form method="POST" action="<?= APP_BASE_PATH ?>index.php?route=add_edit_exit_checklist&id=<?php echo $checklist_id; ?>"><div class="modal-header border-bottom-0"><h5 class="modal-title fw-bold text-success">Approve Checklist</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="action" value="approve_checklist"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><p class="text-muted small">This confirms all exit procedures are complete.</p><div class="mb-3"><label class="form-label">Approved By</label><select name="approved_by" class="form-select" required><?php foreach ($approvers as $u => $f) { echo '<option value="'.htmlspecialchars($f).'" '.($_SESSION['username']==$u?'selected':'').'>'.htmlspecialchars($f).'</option>'; } ?></select></div></div><div class="modal-footer border-top-0"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success text-white rounded-pill px-4">Confirm Approval</button></div></form></div></div></div>

    <form id="assignSelfForm" method="POST" action="<?= APP_BASE_PATH ?>index.php?route=add_edit_exit_checklist&id=<?php echo $checklist_id; ?>" style="display:none;"><input type="hidden" name="action" value="assign_checklist"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="assignees[]" value="<?php echo $_SESSION['username']; ?>"></form>

    <?php endif; ?>
    
    <div class="modal fade" id="alertModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow"><div class="modal-header border-bottom-0"><h5 class="modal-title fw-bold text-danger" id="alertModalLabel"><i data-feather="alert-circle" class="me-2"></i>Attention</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="alertModalBody"></div><div class="modal-footer border-top-0"><button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Close</button></div></div></div></div>

</div>

<?php include_once '../includes/footer.php'; ?>

<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.js"></script>

<script>
$(document).ready(function() {
    if (typeof feather !== 'undefined') feather.replace();
    
    const csrfToken = "<?php echo $_SESSION['csrf_token']; ?>";
    
    // Initialize tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Row click navigation (FIXED to not crash on New Checklist screen)
    const tableBody = document.querySelector('.table-custom > tbody');
    if (tableBody) {
        tableBody.addEventListener('click', function(event) {
            const row = event.target.closest('tr.clickable-row');
            if (!row) return;

            if (event.target.closest('.action-btn-container, a, input, label, [data-bs-toggle="tooltip"]')) {
                return;
            }
            
            const checklistId = row.dataset.id;
            if (checklistId) {
                window.location.href = `<?= APP_BASE_PATH ?>index.php?route=add_edit_exit_checklist&id=${checklistId}`;
            }
        });
    }

    // Delete Modal logic
    const deleteModalEl = document.getElementById('deleteConfirmModal');
    if (deleteModalEl) {
        deleteModalEl.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            document.getElementById('checklistIdToDelete').value = button.dataset.checklistId;
            document.getElementById('employeeNameInModal').textContent = button.dataset.employeeName;
        });
    }

    // Elegant Messages via Toast/Modal
    const phpMessage = <?php echo json_encode($message ?: null); ?>;
    const phpError = <?php echo json_encode($error ?: null); ?>;
    
    function showAlertModal(msg) {
        document.getElementById('alertModalBody').innerHTML = msg;
        new bootstrap.Modal(document.getElementById('alertModal')).show();
    }

    function showToast(msg, type = 'success') {
        const toastContainer = document.getElementById('toastContainer');
        const bgClass = type === 'success' ? 'text-bg-success' : 'text-bg-danger';
        const toastEl = document.createElement('div');
        toastEl.className = `toast align-items-center ${bgClass} border-0 show`;
        toastEl.role = 'alert';
        toastEl.innerHTML = `<div class="d-flex"><div class="toast-body">${msg}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
        toastContainer.appendChild(toastEl);
        setTimeout(() => { toastEl.remove(); }, 3000);
    }

    if (phpMessage) showToast(phpMessage);
    if (phpError) showAlertModal(phpError);

    // Update Status Select Colors
    document.querySelectorAll('.status-select').forEach(sel => {
        sel.addEventListener('change', function() {
            this.classList.remove('status-pending', 'status-completed', 'status-n/a');
            this.classList.add('status-' + this.value.toLowerCase().replace('/', '\\/'));
        });
    });

    // --- AJAX AUTO-SAVE LOGIC ---
    function sendAjaxUpdate(data, triggerElement = null) {
        data.append('ajax_action', data.get('ajax_action'));
        data.append('checklist_id', '<?php echo $checklist_id; ?>');

        fetch('<?= APP_BASE_PATH ?>index.php?route=add_edit_exit_checklist&id=<?php echo $checklist_id; ?>', {
            method: 'POST',
            body: data
        })
        .then(response => response.json())
        .then(res => {
            if (res.status === 'success') {
                showToast(res.message);
                
                // Live update the global last updated timer
                const globalTimeEl = document.getElementById('globalLastUpdated');
                if (globalTimeEl && res.updated_at) {
                    globalTimeEl.textContent = res.updated_at;
                }

                if (triggerElement && res.updated_by) {
                    const row = triggerElement.closest('tr');
                    if (row) {
                        const cell = row.querySelector('.updated-by-cell');
                        const statusSelect = row.querySelector('.status-select');
                        
                        if (cell) {
                            if (statusSelect && statusSelect.value === 'Pending') {
                                cell.innerHTML = '';
                            } else {
                                cell.innerHTML = `
                                    <div class="lh-sm">
                                        <div class="fw-bold text-dark" style="font-size: 0.8rem;">${res.updated_by}</div>
                                        <div class="text-muted" style="font-size: 0.7rem;">${res.updated_at}</div>
                                    </div>`;
                            }
                        }
                    }
                }
            } else {
                showToast(res.message, 'error');
                if (res.message.includes('locked') || res.message.includes('assigned')) {
                    setTimeout(() => window.location.reload(), 1500);
                }
            }
        }).catch(err => { console.error(err); showToast('Connection error', 'error'); });
    }

    // Config Listeners
    document.querySelectorAll('.ajax-update-config').forEach(input => {
        input.addEventListener('change', function() {
            if (this.hasAttribute('readonly') || this.hasAttribute('disabled')) return;
            const fd = new FormData();
            fd.append('ajax_action', 'update_config');
            fd.append('field', this.name);
            fd.append('value', this.value);
            sendAjaxUpdate(fd, this);
        });
    });

    // Item Listeners
    document.querySelectorAll('.ajax-update-item').forEach(input => {
        input.addEventListener('change', function() {
            if (this.hasAttribute('readonly') || this.hasAttribute('disabled')) return;
            
            const row = this.closest('tr');
            const taskTitleEl = row.querySelector('.task-title');
            
            if (taskTitleEl) {
                const taskName = taskTitleEl.textContent.replace('*', '').trim();
                
                // --- VALIDATION LOGIC FOR HOSTNAME & SERIAL NUMBER ---
                if (taskName === 'Host Name' || taskName === 'Serial Number (S/N)') {
                    const statusDropdown = row.querySelector('select[data-field="status"]');
                    const remarksInput = row.querySelector('input[data-field="remarks"]');
                    
                    if (this.dataset.field === 'status' && this.value === 'Completed') {
                        if (remarksInput && remarksInput.value.trim() === '') {
                            showAlertModal(`<b>Remarks are required!</b><br>Please add the details in the remarks field for <b>${taskName}</b> before marking it as Completed.`);
                            this.value = 'Pending';
                            this.classList.remove('status-completed', 'status-n/a');
                            this.classList.add('status-pending');
                            remarksInput.focus();
                            return; 
                        }
                    }
                    
                    if (this.dataset.field === 'remarks' && this.value.trim() === '') {
                        if (statusDropdown && statusDropdown.value === 'Completed') {
                            showAlertModal(`<b>Remarks cannot be empty!</b><br><b>${taskName}</b> is marked as Completed, so remarks are required. The status has been reverted to Pending.`);
                            
                            statusDropdown.value = 'Pending';
                            statusDropdown.classList.remove('status-completed', 'status-n/a');
                            statusDropdown.classList.add('status-pending');
                            
                            const fdStatus = new FormData();
                            fdStatus.append('ajax_action', 'update_item');
                            fdStatus.append('item_id', statusDropdown.dataset.itemId);
                            fdStatus.append('field', 'status');
                            fdStatus.append('value', 'Pending');
                            sendAjaxUpdate(fdStatus, statusDropdown);
                        }
                    }
                }
            }

            const fd = new FormData();
            fd.append('ajax_action', 'update_item');
            fd.append('item_id', this.dataset.itemId);
            fd.append('field', this.dataset.field);
            fd.append('value', this.value);
            sendAjaxUpdate(fd, this);
        });
    });

    // Client-side Validation for Submit
    const btnSubmit = document.getElementById('btnSubmitApproval');
    if (btnSubmit) {
        btnSubmit.addEventListener('click', function() {
            let pendingTasks = [];
            const mainForm = document.getElementById('mainForm');
            
            const assetVerified = mainForm.querySelector('select[name="asset_verified_by"]').value;
            if(!assetVerified) {
                showAlertModal("<b>Asset Verified By</b> must be selected before submitting.");
                return;
            }

            mainForm.querySelectorAll('.status-select').forEach(sel => {
                if (sel.value === 'Pending') {
                    const row = sel.closest('tr');
                    const taskText = row.querySelector('.task-title').textContent.replace('*', '').trim();
                    pendingTasks.push(`<li>${taskText}</li>`);
                }
            });

            if (pendingTasks.length > 0) {
                showAlertModal("All tasks must be 'Completed' or 'N/A' before submitting.<br>The following tasks are still Pending:<ul class='mt-2 mb-0 text-danger small'>" + pendingTasks.join('') + "</ul>");
                return;
            }

            document.getElementById('formAction').value = 'submit_for_approval';
            mainForm.submit();
        });
    }

    // --- INSTANT AJAX DUPLICATE CHECK IN JQUERY UI AUTOCOMPLETE ---
    <?php if (!$edit_mode): ?>
    $("#employee_search").autocomplete({
        source: function(request, response) {
            $.ajax({
                url: "<?= APP_BASE_PATH ?>index.php?route=get_employee_details",
                dataType: "json",
                data: { term: request.term },
                success: function(data) {
                    if (!data.length) {
                        response([{ label: 'No active employees found', value: '', id: null }]);
                    } else {
                        response(data);
                    }
                },
                error: function() { response([{ label: 'Error fetching data', value: '', id: null }]); }
            });
        },
        minLength: 2,
        select: function(event, ui) {
            if (ui.item && ui.item.id) {
                event.preventDefault(); 
                
                const fd = new FormData();
                fd.append('ajax_action', 'check_duplicate');
                fd.append('employee_id', ui.item.id);

                fetch('<?= APP_BASE_PATH ?>index.php?route=add_edit_exit_checklist', {
                    method: 'POST',
                    body: fd
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'exists') {
                        showAlertModal("An active Exit Checklist already exists for this employee. <br><br><a href='<?= APP_BASE_PATH ?>index.php?route=add_edit_exit_checklist&id=" + data.checklist_id + "' class='fw-bold text-decoration-underline'>Click here to view Checklist #" + data.checklist_id + "</a>");
                        
                        $("#employee_search").val('');
                        $("#emp_id_display").val('');
                        $("#department_display").val('');
                        $("#location_display").val('');
                        $("#employee_id").val('');
                        $("#save_button").prop('disabled', true);
                    } 
                    else if (data.status === 'clear') {
                        let empName = ui.item.full_name || ui.item.value || ui.item.label;
                        if (empName.includes('(')) { empName = empName.split('(')[0].trim(); }
                        
                        $("#employee_search").val(ui.item.label || ui.item.value);
                        $("#emp_id_display").val(ui.item.emp_id || '');
                        $("#department_display").val(ui.item.department || '');
                        $("#location_display").val(ui.item.location || '');
                        $("#employee_id").val(ui.item.id);
                        $("#save_button").prop('disabled', false);
                        
                        const inputsToHighlight = [$('#emp_id_display'), $('#department_display'), $('#location_display')];
                        inputsToHighlight.forEach($input => {
                            $input.css({transition: 'background-color 0.3s ease', backgroundColor: '#e0f2fe'});
                            setTimeout(() => $input.css('backgroundColor', ''), 600);
                        });
                    }
                })
                .catch(err => {
                    console.error("Duplicate check failed:", err);
                    showAlertModal("An error occurred while verifying employee status.");
                });
                
            } else {
                event.preventDefault();
                $("#employee_search").val('');
            }
        }
    });
    <?php endif; ?>
});
</script>