<?php
// FILE: system_readiness_checklist.php

// --- FORCE PHP TIMEZONE TO IST ---
date_default_timezone_set('Asia/Kolkata');

// Enable error reporting for debugging (REMOVE IN PRODUCTION)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

include_once '../includes/error_handler.php';
include_once '../includes/db_connect.php';

// --- FORCE MYSQL TIMEZONE TO IST FOR THIS SESSION ---
// This ensures that any SQL queries using NOW() save the correct IST time.
if (isset($conn)) {
    $conn->query("SET time_zone = '+05:30'");
}

include_once '../includes/auth.php';
include_once '../classes/SystemReadinessService.php';

$is_modal_view = isset($_GET['modal']) && $_GET['modal'] == 'true';
$service = new SystemReadinessService($conn);

// --- AJAX HANDLER (Must be before any HTML output) ---
if (isset($_POST['ajax_action'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $response = ['status' => 'error', 'message' => 'Unknown action'];
    
    // Strict CSRF Validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        echo json_encode(['status' => 'error', 'message' => 'Security validation failed.']);
        exit;
    }
    
    try {
        if (!isset($_SESSION['username'])) throw new Exception("Unauthorized: Session expired or invalid.");
        
        $logged_in_username = $_SESSION['username'];
        $logged_in_user_fullname = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')) ?: $logged_in_username;

        if ($_POST['ajax_action'] === 'update_config') {
            $service->updateConfig((int)$_POST['checklist_id'], $_POST['field'], trim($_POST['value']), $logged_in_user_fullname);
            $response = [
                'status' => 'success', 
                'message' => 'Configuration saved',
                'updated_at' => date('M d, Y \a\t h:i A') // Added for live UI updating
            ];
        }
        elseif ($_POST['ajax_action'] === 'update_item') {
            // If item_id is 0, the auto-sync failed or UI is out of sync.
            if ((int)$_POST['item_id'] === 0) {
                throw new Exception("Error: This task is not properly synced with the database. Please refresh the page.");
            }
            $response = $service->updateChecklistItem((int)$_POST['item_id'], $_POST['field'], trim($_POST['value']), $logged_in_user_fullname);
            
            // Modify response to include correctly formatted date
            if ($response['status'] === 'success') {
                $response['updated_at'] = date('M d, Y \a\t h:i A');
            }
        }
    } catch (Exception $e) {
        $response = ['status' => 'error', 'message' => $e->getMessage()];
    }
    
    echo json_encode($response);
    exit;
}

checkPermission('view_system_readiness');

// --- 1. FETCH CHECKLIST ID & BASIC DATA ---
if (!isset($_GET['checklist_id']) || !is_numeric($_GET['checklist_id'])) {
    $_SESSION['form_error'] = "No checklist ID provided.";
    header('Location: ' . APP_BASE_PATH . 'index.php?route=system_readiness');
    exit();
}
$checklist_id = (int)$_GET['checklist_id'];

$stmt_checklist = $conn->prepare("SELECT * FROM checklists WHERE id = ? AND checklist_type = 'system_readiness'");
$stmt_checklist->bind_param("i", $checklist_id);
$stmt_checklist->execute();
$result_checklist = $stmt_checklist->get_result();
if ($result_checklist->num_rows > 0) { $checklist = $result_checklist->fetch_assoc(); } 
else { $_SESSION['form_error'] = "System readiness checklist not found."; header('Location: ' . APP_BASE_PATH . 'index.php?route=system_readiness'); exit(); }
$stmt_checklist->close();

// --- 2. FETCH EMPLOYEE DATA ---
$employee = ['emp_id' => 'N/A', 'full_name' => 'N/A', 'department' => 'N/A', 'email_id' => '', 'domain_username' => '', 'o365_edition' => '', 'date_of_joining' => '', 'position' => ''];
if (!empty($checklist['employee_id'])) {
    $stmt_employee = $conn->prepare("SELECT emp_id, first_name, last_name, department, domain_username, email_id, o365_edition, date_of_joining, position, created_by FROM employees WHERE id = ?");
    if ($stmt_employee) {
        $stmt_employee->bind_param("i", $checklist['employee_id']); 
        $stmt_employee->execute(); 
        $result_employee = $stmt_employee->get_result();
        if ($result_employee->num_rows > 0) {
            $employee_data = $result_employee->fetch_assoc();
            $employee['emp_id'] = $employee_data['emp_id'];
            $employee['full_name'] = $employee_data['first_name'] . ' ' . $employee_data['last_name'];
            $employee['department'] = $employee_data['department'];
            $employee['email_id'] = $employee_data['email_id']; 
            $employee['domain_username'] = $employee_data['domain_username'];
            $employee['o365_edition'] = $employee_data['o365_edition'];
            $employee['date_of_joining'] = $employee_data['date_of_joining'];
            $employee['position'] = $employee_data['position'];
            $employee['created_by'] = $employee_data['created_by'];
        }
        $stmt_employee->close();
    }
}

// --- 3. DEFINE MASTER CHECKLIST ITEMS ---
$master_checklist_items = [
    'USER ID CREATION' => ['Create AD Credentials','Are all the necessary fields entered','Change Password enable for Next Login','Add user to Default LDAP Group according to the department'],
    'EMAIL ID RELATED' => ['Email ID Creation in O365 with appropriate license','Update the user email ID to the respective Alias group' => 'mention all the Aliases & groups that user is added to'],
    'TEAMS' => ['India Office: Add IT Support, and send test message'],
    'VOICE SERVICES' => ['Arrange a DID & share VM Greeting setup doc','Allot IP Phone Headset & update GLPI','Verify IP Phone TimeZone settings' => 'US DIDs: EST Timezone Settings | PRI DIDs: India Timezone Settings','USREC : Update daily record template with new joiner details' => 'Applicable for US Staffing Team only','Send details to update IT Dashboard'],
    'PHYSICAL VERIFICATION' => ['Check Desktop/Laptop Physical Condition','Charging Port working Status','USB Ports (All) working Status' => 'Check Mouse & keyboard access','HDMI Port' => 'Connect external monitor','Ethernet Port working Status' => 'Connect LAN cable','Wi-Fi working Status (Laptop)','Keyboard working Status' => 'https://pctester.online','Touchpad working Status (Laptop)' => 'https://pctester.online','Battery Backup check (Laptop)','Mic working Status' => 'https://pctester.online or voice recorder','Webcam working Status' => 'https://pctester.online or Camera app','Speaker working Status'],
    'SYSTEM CONFIGURATION' => [
        'Provide appropriate Hostname for the PC' => 'Populate from DB(Hostname)',
        'Configure User Profile, IM & Outlook email configuration' => 'Populate from DB(Email including Domain)',
        'Install Basic Applications' => '7-Zip, OCS Inventory Agent, Adobe Reader, CiscoAMP Endpoint, Google Chrome, Mozila Firefox, MS Teams, TeamViewer, Umbrella Roaming Client, NS Client ++',
        'Install Department-wise Applications' => 'Install applications specific to the user department',
        'Change Offline settings on Outlook to Maximum',
        'Login to Edge browser with Sun email ID' => 'Login to Edge browser with Sun email ID',
        'MS Office Product Activation' => 'Activate product as per License assigned',
        'Move System to respective OU in AD','Enable RDP for Administrator account & User ID',
        'For Laptop whitelist MAC ID on respective SSID' => 'mention the MAC ID & SSID',
        'Set the Administrator password to default - IMPORTANT','Delete Admin ID if created - IMPORTANT',
        'Assign System in GLPI to user',
        'TIME ZONE SETTINGS' => ['Change system TimeZone settings to EST','Change system TimeZone settings to IST' => 'applicable to all other teams'],
        'NETWORK DRIVE MAPPING' => ['Verify HomeDrive availability' => 'Default applicable to all users except Gaming & US Office','US Office : Create & Map network drive with user\'s name' => 'Applicable for US Office Only','Map respective departmental Share drive, if approved' => 'For New Joiners it is on Approval'],
        'PRINTER INSTALLATION' => ['Install the Printer according to the division/ department','US Office : Install local printer & do a test print' => 'Applicable for US Office Only'],
        'DEFAULT LANGUAGE SETTINGS' => ['Make English-US as the default language'],
        'VPN CONFIGURATION' => ['Add user to VPN OU for SSL-VPN' => 'Default applicable only to IT-SMB','Move User to respective OU in AD','FortiToken Mobile Configuration' => 'Ensure MFA is enabled & configured'],
        'CAMERA & PC HEADSET' => ['Install & Configure Camera & Headphone and assign in GLPI' => 'Default applicable to Admin, Facility & Secretaries'],
    ],
    'BACKUP READINESS' => ['US OFFICE' => ['Configure Synology & Cobian backup for users' => 'Backup to be configured on gaming Synology Server'],'INDIA OFFICE [only Gaming]' => ['For approved managers only'],'INDIA OFFICE [all except Gaming]' => ['For Managers only']],
    'RECORD MAINTENANCE' => ['ASSET ISSUANCE' => ['Fill out the Asset Issuance Form' => 'Print, take user\'s sign and save a copy in server']]
];

$purposes_for_existing_employees = ['Asset Replacement', 'Department Change', 'Other'];
if (isset($checklist['checklist_purpose']) && in_array($checklist['checklist_purpose'], $purposes_for_existing_employees)) {
    unset($master_checklist_items['USER ID CREATION']);
    unset($master_checklist_items['EMAIL ID RELATED']);
    unset($master_checklist_items['TEAMS']);
    unset($master_checklist_items['VOICE SERVICES']);
}

// --- 4. LOGIC VARIABLES ---
$logged_in_username = $_SESSION['username'] ?? 'system';
$logged_in_user_fullname = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')) ?: $logged_in_username;
$logging_is_enabled = !empty($checklist['employee_id']);
$status = $checklist['overall_status'];
$assigned_users = !empty($checklist['assigned_to']) ? json_decode($checklist['assigned_to'], true) : [];
$assignment_history = !empty($checklist['assignment_history']) ? json_decode($checklist['assignment_history'], true) : [];
$is_assigned_to_current_user = in_array($logged_in_username, $assigned_users);
$can_manage_checklist = can('manage_system_readiness');
$can_verify_checklist = can('verify_system_readiness');
$can_edit_without_assign = can('edit_without_assign');
$can_verify_overall_status = $can_verify_checklist;
$can_upload_asset_form = $can_manage_checklist;
$is_editable_now = $can_edit_without_assign || ($can_manage_checklist && $status == 'Preparing' && $is_assigned_to_current_user) || ($can_verify_checklist && $status == 'Verification Pending');

// --- FETCH ASSIGNED USER FULL NAMES ---
$assigned_full_names = [];
if (!empty($assigned_users)) {
    $u_list = "'" . implode("','", array_map([$conn, 'real_escape_string'], $assigned_users)) . "'";
    $res_u = $conn->query("SELECT username, firstname, lastname FROM users WHERE username IN ($u_list)");
    while($ru = $res_u->fetch_assoc()) {
        $full_n = trim($ru['firstname'] . ' ' . $ru['lastname']);
        $assigned_full_names[] = !empty($full_n) ? $full_n : $ru['username'];
    }
}
$assigned_display_string = !empty($assigned_full_names) ? implode(', ', $assigned_full_names) : 'Unassigned';

// --- 5. INITIALIZE & SYNC CHECKLIST ITEMS ---
$current_checklist_data = [];
$sql_fetch_items = "SELECT id, checklist_category, task_description, status, remarks, updated_at, updated_by FROM checklist_items WHERE template_id = ?";
$stmt_fetch = $conn->prepare($sql_fetch_items);
if ($stmt_fetch) { 
    $stmt_fetch->bind_param("i", $checklist_id); 
    $stmt_fetch->execute(); 
    $result_fetch = $stmt_fetch->get_result(); 
    while ($row = $result_fetch->fetch_assoc()) { 
        $current_checklist_data[$row['checklist_category']][$row['task_description']] = $row; 
    } 
    $stmt_fetch->close();
}

if (empty($current_checklist_data) && ($checklist['overall_status'] == 'Not Started' || $checklist['overall_status'] === null)) {
    // Brand new checklist generation
    $conn->begin_transaction();
    try {
        $creator_username = $checklist['created_by'] ?? $logged_in_username;
        $creator_fullname = $creator_username;
        if (!empty($creator_username)) {
            $stmt_creator = $conn->prepare("SELECT firstname, lastname FROM users WHERE username = ?");
            $stmt_creator->bind_param("s", $creator_username); $stmt_creator->execute();
            if ($row_creator = $stmt_creator->get_result()->fetch_assoc()) { $creator_fullname = trim($row_creator['firstname'] . ' ' . $row_creator['lastname']); }
            $stmt_creator->close();
        }
        
        $service->insertInitialItems($master_checklist_items, $checklist_id, $creator_fullname, $employee);
        
        // --- ADD INITIALIZATION LOG FOR POPULATION ---
        $action_type = 'System Readiness';
        $action_desc = "System Readiness Checklist Initialized.";
        $log_stmt = $conn->prepare("INSERT INTO action_logs (joiner_id, template_id, performed_by, action_type, action_description) VALUES (?, ?, ?, ?, ?)");
        if ($log_stmt) {
            $log_stmt->bind_param("iisss", $checklist['employee_id'], $checklist_id, $creator_fullname, $action_type, $action_desc);
            $log_stmt->execute();
            $log_stmt->close();
        }
        // --------------------------------------------------

        $conn->commit();
        
        // Re-fetch items
        $current_checklist_data = [];
        $stmt_refetch = $conn->prepare($sql_fetch_items);
        $stmt_refetch->bind_param("i", $checklist_id); 
        $stmt_refetch->execute(); 
        $result_refetch = $stmt_refetch->get_result(); 
        while ($row = $result_refetch->fetch_assoc()) { 
            $current_checklist_data[$row['checklist_category']][$row['task_description']] = $row; 
        } 
        $stmt_refetch->close();
        
    } catch (Exception $e) { $conn->rollback(); }
} 

// --- 5.1 FETCH LAST UPDATED TIME ---
$last_updated_time = null;
$stmt_last = $conn->prepare("SELECT timestamp FROM action_logs WHERE template_id = ? AND action_type = 'System Readiness' ORDER BY timestamp DESC LIMIT 1");
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

// --- 5.5 PREPARE UI RENDER TREE ---
// We use the master list for structure (subcategories), but filter out tasks not in the DB to freeze old checklists in time.
function buildRenderTree($master_items, $db_data, $category_context = []) {
    $tree = [];
    foreach ($master_items as $key => $value) {
        if (is_array($value)) {
            $display_title = is_numeric($key) ? $value[0] : $key;
            $new_context = array_merge($category_context, [$display_title]);
            $sub_items = buildRenderTree($value, $db_data, $new_context);
            if (!empty($sub_items)) { // Only add category if it has existing tasks
                $tree[$key] = $sub_items;
            }
        } else {
            $actual_task = is_numeric($key) ? $value : $key;
            $top_level = !empty($category_context) ? $category_context[0] : 'UNKNOWN_CATEGORY';
            if (isset($db_data[$top_level][$actual_task])) {
                $tree[$key] = $value; // Task exists in DB, keep it for rendering
            }
        }
    }
    return $tree;
}

$render_checklist_items = buildRenderTree($master_checklist_items, $current_checklist_data);

// --- 6. POST HANDLING (With CSRF) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && !isset($_POST['ajax_action'])) {
    
    // Strict CSRF check for main form posts
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['form_error'] = "Security validation failed. Please try again.";
    } else {
        $action = $_POST['action']; $current_timestamp = date('Y-m-d H:i:s');
        $conn->begin_transaction();
        try {
            if ($action == 'reset_acceptance' && can('manage_system_readiness')) {
                $stmt_reset = $conn->prepare("UPDATE checklists SET user_acceptance_status = 'Pending', user_acceptance_date = NULL, user_acceptance_by = NULL, user_acceptance_comments = NULL, induction_it_remarks = NULL, induction_hr_remarks = NULL, induction_admin_remarks = NULL WHERE id = ?");
                $stmt_reset->bind_param("i", $checklist_id);
                if (!$stmt_reset->execute()) throw new Exception("Failed to reset checklist status.");
                $stmt_reset->close();
                $service->logActivity($checklist['employee_id'], $checklist_id, $logged_in_user_fullname, "Reset User Acceptance form for re-submission.");
                $_SESSION['form_success'] = "User Acceptance form reset successfully.";
            } elseif ($action == 'assign_checklist' && $can_manage_checklist) {
                $assignees = $_POST['assignees'] ?? []; if (empty($assignees)) { throw new Exception("You must select at least one IT user to assign."); }
                $assignees_json = json_encode($assignees); $new_status = 'Preparing'; 
                $history = !empty($checklist['assignment_history']) ? json_decode($checklist['assignment_history'], true) : [];
                $assignee_full_names = [];
                foreach($assignees as $user_name) {
                    $stmt_user = $conn->prepare("SELECT firstname, lastname FROM users WHERE username = ?");
                    $stmt_user->bind_param("s", $user_name); $stmt_user->execute(); $result_user = $stmt_user->get_result();
                    if($user_row = $result_user->fetch_assoc()) { $assignee_full_names[] = trim($user_row['firstname'] . ' ' . $user_row['lastname']); } $stmt_user->close();
                }
                $new_entry = ['assigned_by' => $logged_in_user_fullname, 'assigned_to' => $assignee_full_names, 'assigned_at' => $current_timestamp];
                $history[] = $new_entry; $history_json = json_encode($history);
                $stmt_assign = $conn->prepare("UPDATE checklists SET assigned_to = ?, overall_status = ?, assignment_history = ?, prepared_by = ?, prepared_at = ? WHERE id = ?");
                $stmt_assign->bind_param("sssssi", $assignees_json, $new_status, $history_json, $logged_in_user_fullname, $current_timestamp, $checklist_id);
                if (!$stmt_assign->execute()) throw new Exception($stmt_assign->error);
                if ($logging_is_enabled) { $service->logActivity($checklist['employee_id'], $checklist_id, $logged_in_user_fullname, "Checklist assigned to: " . implode(', ', $assignee_full_names)); }
                $_SESSION['form_success'] = "Checklist assigned successfully!";
            } elseif ($action == 'upload_asset_form' && $can_upload_asset_form) {
                 if (isset($_FILES['asset_form']) && $_FILES['asset_form']['error'] == 0) {
                    if ($_FILES['asset_form']['type'] == 'application/pdf') {
                        $upload_dir_physical = __DIR__ . '/../uploads/asset_forms/'; $upload_dir_db = 'uploads/asset_forms/';
                        if (!is_dir($upload_dir_physical)) { mkdir($upload_dir_physical, 0755, true); }
                        $file_name = preg_replace('/[^a-zA-Z0-9_-]/', '-', $employee['full_name'] ?? 'Emp') . "_Asset_Form_{$checklist_id}." . pathinfo(basename($_FILES['asset_form']['name']), PATHINFO_EXTENSION);
                        if (move_uploaded_file($_FILES['asset_form']['tmp_name'], $upload_dir_physical . $file_name)) {
                            $target_path_db = $upload_dir_db . $file_name;
                            $stmt_update_asset = $conn->prepare("UPDATE checklists SET asset_form_path = ?, asset_form_updated_by = ?, asset_form_updated_at = ? WHERE id = ?");
                            $stmt_update_asset->bind_param("sssi", $target_path_db, $logged_in_user_fullname, $current_timestamp, $checklist_id);
                            $stmt_update_asset->execute();
                            $stmt_upd_item = $conn->prepare("UPDATE checklist_items SET status = 'Completed', updated_by = ?, updated_at = NOW() WHERE template_id = ? AND task_description = 'Fill out the Asset Issuance Form'");
                            $stmt_upd_item->bind_param("si", $logged_in_user_fullname, $checklist_id); $stmt_upd_item->execute();
                            if ($logging_is_enabled) $service->logActivity($checklist['employee_id'], $checklist_id, $logged_in_user_fullname, "Asset Issuance Form was uploaded.");
                            $_SESSION['form_success'] = "Asset Issuance Form uploaded successfully!";
                        } else { throw new Exception("Failed to move uploaded file."); }
                    } else { throw new Exception("Invalid file type. Only PDF allowed."); }
                 } else { throw new Exception("File upload error."); }
            } elseif ($action == 'prepare_status' && $is_editable_now) {
                $hostname = trim($_POST['hostname'] ?? ''); $serial_number = trim($_POST['serial_number'] ?? '');
                if(empty($hostname) || empty($serial_number)){ throw new Exception("Hostname and Serial Number are required."); }
                
                $overall_status = 'Verification Pending';
                $stmt_update = $conn->prepare("UPDATE checklists SET overall_status = ?, prepared_by = ?, prepared_at = ? WHERE id = ?");
                $stmt_update->bind_param("sssi", $overall_status, $logged_in_user_fullname, $current_timestamp, $checklist_id);
                if (!$stmt_update->execute()) throw new Exception($stmt_update->error);
                if ($logging_is_enabled) $service->logActivity($checklist['employee_id'], $checklist_id, $logged_in_user_fullname, "Checklist submitted for verification.");
                $_SESSION['form_success'] = "Submitted for verification.";
            } elseif ($action == 'verify_status' && $can_verify_overall_status) {
                $overall_status = 'Completed';
                $stmt_update = $conn->prepare("UPDATE checklists SET overall_status = ?, approved_by = ?, approved_at = ? WHERE id = ?");
                $stmt_update->bind_param("sssi", $overall_status, $logged_in_user_fullname, $current_timestamp, $checklist_id);
                if (!$stmt_update->execute()) throw new Exception($stmt_update->error);
                if ($logging_is_enabled) $service->logActivity($checklist['employee_id'], $checklist_id, $logged_in_user_fullname, "Checklist verification completed.");
                $_SESSION['form_success'] = "Verification completed successfully!";
            } elseif ($action === 'reject_checklist' && $can_verify_checklist) {
                $remarks = trim($_POST['rejection_remarks'] ?? '');
                if (empty($remarks)) throw new Exception("Rejection remarks are required.");
                
                $prepared_by_name = $checklist['prepared_by'] ?? '';
                $prepared_by_username = '';
                
                if (!empty($prepared_by_name)) {
                     $stmt_find = $conn->prepare("SELECT username FROM users WHERE TRIM(CONCAT(firstname, ' ', lastname)) = ? LIMIT 1");
                     if ($stmt_find) {
                         $stmt_find->bind_param("s", $prepared_by_name);
                         $stmt_find->execute();
                         $res_find = $stmt_find->get_result();
                         if ($row_find = $res_find->fetch_assoc()) {
                             $prepared_by_username = $row_find['username'];
                         }
                         $stmt_find->close();
                     }
                }
                
                $sql = "UPDATE checklists SET overall_status = 'Preparing'";
                $types = ""; $params = [];
                
                if (!empty($prepared_by_username)) {
                    $new_assignees_json = json_encode([$prepared_by_username]);
                    $sql .= ", assigned_to = ?";
                    $types .= "s"; $params[] = $new_assignees_json;
                    
                    $history = !empty($checklist['assignment_history']) ? json_decode($checklist['assignment_history'], true) : [];
                    $history[] = ['assigned_by' => $logged_in_user_fullname, 'assigned_to' => [$prepared_by_name], 'assigned_at' => $current_timestamp, 'note' => 'Reassigned upon rejection'];
                    $sql .= ", assignment_history = ?";
                    $types .= "s"; $params[] = json_encode($history);
                }
                $sql .= " WHERE id = ?";
                $types .= "i"; $params[] = $checklist_id;
                
                $stmt = $conn->prepare($sql);
                if ($stmt) { $stmt->bind_param($types, ...$params); $stmt->execute(); $stmt->close(); }
                
                $service->logActivity($checklist['employee_id'], $checklist_id, $logged_in_user_fullname, "Checklist Rejected: " . $remarks);
                $_SESSION['form_rejection'] = "Checklist rejected and sent back to 'Preparing'.";
            } elseif ($action == 'handle_gatepass_decision') {
                $decision = $_POST['decision'] ?? '';
                if ($decision === 'no') {
                    $stmt = $conn->prepare("UPDATE checklists SET gatepass_id = -1 WHERE id = ?");
                    $stmt->bind_param("i", $checklist_id);
                    $stmt->execute();
                    $_SESSION['form_success'] = "Gatepass marked as not required.";
                    $service->logActivity($checklist['employee_id'], $checklist_id, $logged_in_user_fullname, "Decided Gatepass is not required.");
                }
            } elseif ($action == 'delete_checklist' && can('delete_system_readiness')) {
                 $_SESSION['form_success'] = "Checklist deleted.";
                 header("Location: " . APP_BASE_PATH . "index.php?route=system_readiness"); exit();
            }
            $conn->commit();
        } catch (Exception $e) { $conn->rollback(); $_SESSION['form_error'] = "An error occurred: " . $e->getMessage(); }
    }
    header("Location: " . APP_BASE_PATH . "index.php?route=system_readiness_checklist&checklist_id=" . $checklist_id . ($is_modal_view ? '&modal=true' : '') . ($is_editable_now ? '&edit=true' : ''));
    exit();
}

// Fetch users for modals
$it_users = [];
$assign_users_stmt = $conn->prepare("SELECT DISTINCT u.username, u.firstname, u.lastname FROM users u JOIN roles r ON u.role_id = r.id JOIN role_permissions rp ON r.id = rp.role_id JOIN permissions p ON rp.permission_id = p.id WHERE p.action_key = 'manage_system_readiness' ORDER BY u.firstname ASC");
if ($assign_users_stmt) { $assign_users_stmt->execute(); $res = $assign_users_stmt->get_result(); while ($r = $res->fetch_assoc()) { $it_users[$r['username']] = trim($r['firstname'].' '.$r['lastname']) ?: $r['username']; } $assign_users_stmt->close(); }

// Last rejection
$last_rejection_data = null;
if ($status === 'Preparing') {
    $stmt_rej = $conn->prepare("SELECT action_description, performed_by, timestamp FROM action_logs WHERE template_id = ? AND action_type = 'System Readiness' AND action_description LIKE 'Checklist Rejected:%' ORDER BY id DESC LIMIT 1");
    if ($stmt_rej) {
        $stmt_rej->bind_param("i", $checklist_id); $stmt_rej->execute(); $res_rej = $stmt_rej->get_result();
        if ($rej_row = $res_rej->fetch_assoc()) {
            $last_rejection_data = [
                'reason' => str_replace("Checklist Rejected: ", "", $rej_row['action_description']),
                'by' => $rej_row['performed_by'],
                'date' => date('M d, Y \a\t h:i A', strtotime($rej_row['timestamp']))
            ];
        }
        $stmt_rej->close();
    }
}

// Previous checklists
$previous_checklists = [];
if ($logging_is_enabled) {
    $stmt_history = $conn->prepare("SELECT id, checklist_purpose, approved_at FROM checklists WHERE employee_id = ? AND checklist_type = 'system_readiness' AND id != ? AND overall_status = 'Completed' ORDER BY approved_at DESC");
    if($stmt_history) {
        $stmt_history->bind_param("ii", $checklist['employee_id'], $checklist_id); $stmt_history->execute(); $result_history = $stmt_history->get_result();
        while($row = $result_history->fetch_assoc()) { $previous_checklists[] = $row; }
        $stmt_history->close();
    }
}

function renderChecklistItems($items, $current_checklist_data, $is_editable_now, $employee, $checklist, $can_upload_asset_form, $is_assigned_to_current_user, $category_context = [], $is_modal_view) {
    foreach ($items as $task_description_key => $value) {
        if (is_array($value)) {
            $display_title = is_numeric($task_description_key) ? $value[0] : $task_description_key;
            echo '<tr><td colspan="4" class="category-header"><div class="d-flex align-items-center gap-2"><div class="category-indicator"></div>' . htmlspecialchars($display_title) . '</div></td></tr>';
            $new_category_context = array_merge($category_context, [$display_title]);
            renderChecklistItems($value, $current_checklist_data, $is_editable_now, $employee, $checklist, $can_upload_asset_form, $is_assigned_to_current_user, $new_category_context, $is_modal_view);
        } else {
            $actual_task_description = is_numeric($task_description_key) ? $value : $task_description_key;
            $top_level_category = !empty($category_context) ? $category_context[0] : 'UNKNOWN_CATEGORY';
            $item_data = $current_checklist_data[$top_level_category][$actual_task_description] ?? [];
            $item_db_id = $item_data['id'] ?? 0;
            $current_status_from_db = $item_data['status'] ?? 'Pending';
            $current_remarks_db = $item_data['remarks'] ?? '';
            $updated_by_fullname = $item_data['updated_by'] ?? '';
            $updated_at_ts = $item_data['updated_at'] ?? null;
            $disable_individual_fields = !$is_editable_now;
            $display_remarks_value = $current_remarks_db;
            $final_status = $current_status_from_db;
            $is_readonly = ($updated_by_fullname === 'System');

            // --- AUTO-POPULATE LOGIC ---
            if (empty($display_remarks_value)) {
                if ($actual_task_description === 'Create AD Credentials') {
                    $display_remarks_value = $employee['domain_username'] ?? '';
                } elseif ($actual_task_description === 'Email ID Creation in O365 with appropriate license') {
                    $display_remarks_value = $employee['o365_edition'] ?? '';
                } elseif ($actual_task_description === 'Configure User Profile, IM & Outlook email configuration') {
                    $display_remarks_value = $employee['email_id'] ?? '';
                }
            }

            // Specific read-only logic for Hostname
            $is_hostname_field = ($actual_task_description === 'Provide appropriate Hostname for the PC');
            if ($is_hostname_field) {
                $display_remarks_value = $checklist['hostname'] ?? ''; 
                $is_readonly = true;
                $final_status = !empty($display_remarks_value) ? 'Completed' : 'Pending';
            }

            // --- TOOLTIP LOGIC ---
            $tooltip_attr = '';
            $wrapper_start = '';
            $wrapper_end = '';
            
            if ($disable_individual_fields || $is_hostname_field) {
                $msg = "Editing disabled";
                if ($is_hostname_field) { $msg = "Auto-populated from System Config section."; } 
                elseif ($checklist['overall_status'] === 'Completed') { $msg = "Checklist is locked (Completed)."; } 
                elseif (!$is_assigned_to_current_user && $checklist['overall_status'] === 'Preparing') { $msg = "You must be assigned to this checklist to make changes."; } 
                elseif ($checklist['overall_status'] === 'Verification Pending') { $msg = "Checklist is pending verification."; }
                
                $wrapper_start = '<span class="d-inline-block" tabindex="0" data-bs-toggle="tooltip" title="' . htmlspecialchars($msg) . '">';
                $wrapper_end = '</span>';
                $tooltip_attr = 'data-bs-toggle="tooltip" title="' . htmlspecialchars($msg) . '"';
            }
            ?>
            <tr class="item-row">
                <td style="width: 40%; padding-left: 1.25rem;">
                    <div class="task-title"><?php echo htmlspecialchars($actual_task_description); ?></div>
                </td>
                <td class="text-center" style="width: 15%;">
                    <?php echo $wrapper_start; ?>
                    <select name="status_<?php echo md5($actual_task_description); ?>" 
                        class="form-select status-select status-<?php echo strtolower($final_status); ?> ajax-update-item" 
                        data-item-id="<?php echo $item_db_id; ?>"
                        data-field="status"
                        data-category="<?php echo htmlspecialchars($top_level_category); ?>" 
                        <?php echo ($disable_individual_fields || $is_hostname_field || $actual_task_description === 'Fill out the Asset Issuance Form') ? 'disabled' : ''; ?>>
                        <option value="Pending" <?php echo ($final_status == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                        <option value="Completed" <?php echo ($final_status == 'Completed') ? 'selected' : ''; ?>>Completed</option>
                        <option value="N/A" <?php echo ($final_status == 'N/A') ? 'selected' : ''; ?>>N/A</option>
                    </select>
                    <?php echo $wrapper_end; ?>
                </td>
                
                <?php if ($actual_task_description == 'Fill out the Asset Issuance Form') : ?>
                    <td>
                        <input type="text" class="form-control form-control-flush" value="Managed via User Acceptance section above." readonly style="font-style: italic; color: #94a3b8;">
                    </td>
                    <td class="pe-3 updated-by-cell" style="width: 180px;">
                        <?php if (in_array($final_status, ['Completed', 'N/A']) && !empty($updated_by_fullname)): ?>
                            <div class="lh-sm">
                                <div class="fw-bold text-dark" style="font-size: 0.8rem;"><?php echo htmlspecialchars($updated_by_fullname); ?></div>
                                <?php if ($updated_at_ts): ?>
                                <div class="text-muted" style="font-size: 0.7rem;"><?php echo date('M d, Y \a\t h:i A', strtotime($updated_at_ts)); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                <?php else : ?>
                    <td>
                        <input type="text" name="remarks_<?php echo md5($actual_task_description); ?>" 
                            class="form-control form-control-flush ajax-update-item" 
                            data-item-id="<?php echo $item_db_id; ?>"
                            data-field="remarks"
                            placeholder="<?php echo htmlspecialchars(is_string($value) ? $value : ''); ?>" 
                            value="<?php echo htmlspecialchars($display_remarks_value); ?>" 
                            <?php echo $tooltip_attr; ?>
                            <?php echo ($disable_individual_fields || $is_readonly) ? 'readonly' : ''; ?>>
                    </td>
                    <td class="pe-3 updated-by-cell" style="width: 180px;">
                        <?php if ( in_array($final_status, ['Completed', 'N/A']) && !empty($updated_by_fullname)): ?>
                            <div class="lh-sm">
                                <div class="fw-bold text-dark" style="font-size: 0.8rem;"><?php echo htmlspecialchars($updated_by_fullname); ?></div>
                                <?php if ($updated_at_ts): ?>
                                <div class="text-muted" style="font-size: 0.7rem;"><?php echo date('M d, Y \a\t h:i A', strtotime($updated_at_ts)); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                <?php endif; ?>
            </tr>
            <?php
        }
    }
}

if (!$is_modal_view) { include_once '../includes/header.php'; }
?>

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
    
    .modern-card { background: var(--card-bg); border-radius: var(--radius); box-shadow: var(--card-shadow); border: 1px solid var(--border-color); overflow: hidden; margin-bottom: 24px; }
    .modern-card-header { padding: 16px 20px; background: #ffffff; border-bottom: 1px solid var(--border-color); font-weight: 600; color: var(--primary-color); display: flex; align-items: center; gap: 8px; font-size: 0.95rem; }
    .modern-card-body { padding: 20px; }
    
    .nav-tabs { border-bottom: 1px solid var(--border-color); gap: 10px; margin-bottom: 20px; }
    .nav-tabs .nav-link { color: var(--text-light); border: none; border-bottom: 2px solid transparent; font-weight: 500; padding: 10px 16px; transition: all 0.2s; }
    .nav-tabs .nav-link:hover { color: var(--primary-color); background: #f1f5f9; border-radius: 6px 6px 0 0; }
    .nav-tabs .nav-link.active { color: var(--primary-color); border-bottom: 2px solid var(--primary-color); background: transparent; font-weight: 600; }

    .table-custom { width: 100%; border-collapse: separate; border-spacing: 0; }
    .table-custom th { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-light); font-weight: 600; padding: 12px 16px; border-bottom: 1px solid var(--border-color); background: #f8fafc; }
    .category-header { background-color: #f1f5f9; color: #0f172a; font-weight: 600; padding: 10px 16px; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.03em; border-bottom: 1px solid var(--border-color); border-top: 1px solid var(--border-color); }
    .category-indicator { width: 4px; height: 14px; background-color: var(--primary-color); border-radius: 2px; }
    
    .item-row td { padding: 10px 12px; vertical-align: middle; border-bottom: 1px solid #f1f5f9; color: var(--text-main); }
    .item-row:hover td { background-color: #f8fafc; }
    
    .form-label { font-weight: 600; font-size: 0.75rem; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.02em; margin-bottom: 6px; }
    .form-control, .form-select { border-radius: 8px; padding: 8px 12px; font-size: 0.875rem; border: 1px solid #cbd5e1; transition: all 0.2s; }
    .form-control:focus, .form-select:focus { border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1); }
    .form-control[readonly] { background-color: #f8fafc; color: #64748b; border-color: #e2e8f0; }
    
    .form-control-flush { border: none; border-bottom: 1px solid transparent; background: transparent; padding: 6px 0; border-radius: 0; }
    .form-control-flush:focus { box-shadow: none; border-bottom-color: var(--primary-color); background: #fff; }

    .status-select { border-radius: 20px; font-size: 0.75rem; font-weight: 600; padding: 4px 28px 4px 12px; border: 1px solid transparent; cursor: pointer; width: auto; display: inline-block; }
    .status-pending { background-color: #f1f5f9; color: #64748b; }
    .status-completed { background-color: #dcfce7; color: #15803d; border-color: #bbf7d0; }
    .status-n\/a { background-color: #f3f4f6; color: #9ca3af; }

    .btn-primary-modern { background-color: var(--primary-color); color: white; border: none; border-radius: 20px; padding: 6px 20px; font-weight: 600; font-size: 0.875rem; transition: all 0.2s; }
    .btn-primary-modern:hover { background-color: var(--primary-hover); transform: translateY(-1px); }
    
    .status-badge { padding: 6px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; letter-spacing: 0.02em; text-transform: uppercase; }

    .employee-card-gradient { background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); }
    .avatar-box { width: 64px; height: 64px; background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-hover) 100%); color: white; font-size: 1.75rem; border-radius: 12px; box-shadow: 0 4px 10px rgba(15, 118, 110, 0.25); }
    .info-label { font-size: 0.7rem; text-transform: uppercase; color: #94a3b8; font-weight: 600; letter-spacing: 0.03em; margin-bottom: 2px; }
    .info-value { color: #334155; font-weight: 500; font-size: 0.9rem; }

    .toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; }
</style>

<div class="toast-container" id="toastContainer"></div>

<div class="container-xl py-4">
    <?php if (!$is_modal_view): ?>
    <div class="mb-4">
        <a href="<?= APP_BASE_PATH ?>index.php?route=system_readiness" onclick="if(document.referrer !== '') { window.history.back(); return false; }" class="text-decoration-none text-muted small fw-bold"><i data-feather="arrow-left" style="width:14px;" class="me-1"></i> BACK TO LIST</a>        <div class="d-flex justify-content-between align-items-end mt-2">
            <h2 class="fw-bold m-0 text-dark">System Readiness Checklist</h2>
            <div class="text-muted small">ID: #<?php echo $checklist_id; ?></div>
        </div>
    </div>
    <?php endif; ?>

    <ul class="nav nav-tabs">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#checklist-pane">Checklist Details</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#timeline-pane">Activity Timeline</button></li>
        <?php if (!empty($previous_checklists)): ?>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#history-pane">History</button></li>
        <?php endif; ?>
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

            <form id="mainForm" method="POST" action="<?= APP_BASE_PATH ?>index.php?route=system_readiness_checklist&checklist_id=<?php echo $checklist_id; ?><?php echo $is_modal_view ? '&modal=true' : ''; ?>">
                <input type="hidden" name="action" id="mainFormAction" value="prepare_status">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="row g-4 mb-4">
                    <div class="col-lg-5">
                        <div class="modern-card h-100 mb-0 employee-card-gradient">
                            <div class="modern-card-header border-bottom-0 pb-0 bg-transparent justify-content-between">
                                <div class="d-flex align-items-center gap-2">
                                    <i data-feather="user" style="width:16px;"></i> Employee Details
                                </div>
                                <div class="position-relative" style="z-index: 2;">
                                    <span class="badge bg-white border text-primary shadow-sm rounded-pill px-3 py-2 d-flex align-items-center gap-2">
                                        <i data-feather="crosshair" style="width:12px;"></i>
                                        <?php echo htmlspecialchars($checklist['checklist_purpose'] ?? 'N/A'); ?>
                                    </span>
                                    
                                    <?php 
                                    // Change 'purpose_remarks' to the actual DB column name if different
                                    $other_remarks_column = 'purpose_remarks'; 
                                    
                                    if (($checklist['checklist_purpose'] ?? '') === 'Other' && !empty($checklist[$other_remarks_column])): 
                                    ?>
                                        <div class="position-absolute end-0 text-muted text-end pe-2 lh-sm" style="font-size: 0.75rem; top: 100%; margin-top: 6px; min-width: 200px; word-wrap: break-word; pointer-events: none;">
                                            Reason: <?php echo htmlspecialchars($checklist[$other_remarks_column]); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="modern-card-body pt-3">
                                <div class="d-flex align-items-center mb-4">
                                    <div class="avatar-box d-flex align-items-center justify-content-center fw-bold me-3">
                                        <?php echo strtoupper(substr($employee['full_name'], 0, 1)); ?>
                                    </div>
                                    <div class="overflow-hidden">
                                        <h5 class="fw-bold text-dark m-0 text-truncate" title="<?php echo htmlspecialchars($employee['full_name']); ?>">
                                            <?php echo htmlspecialchars($employee['full_name']); ?>
                                        </h5>
                                        <div class="text-secondary fw-medium fs-6 mt-1"><?php echo htmlspecialchars($employee['position'] ?: 'Position N/A'); ?></div>
                                    </div>
                                </div>

                                <div class="row g-3">
                                    <div class="col-6">
                                        <div class="p-2 rounded bg-white border h-100">
                                            <div class="info-label">Department</div>
                                            <div class="info-value text-truncate"><?php echo htmlspecialchars($employee['department']); ?></div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="p-2 rounded bg-white border h-100">
                                            <div class="info-label">Joining Date</div>
                                            <div class="info-value">
                                                <?php echo !empty($employee['date_of_joining']) ? date('M d, Y', strtotime($employee['date_of_joining'])) : 'N/A'; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="p-2 rounded bg-white border d-flex align-items-center gap-2">
                                            <div class="bg-light rounded-circle p-1 d-flex justify-content-center align-items-center" style="width: 24px; height: 24px;">
                                                <i data-feather="mail" class="text-muted" style="width:12px;"></i>
                                            </div>
                                            <div>
                                                <div class="info-label">Email Address</div>
                                                <div class="info-value text-break" style="font-size: 0.85rem;">
                                                    <?php echo htmlspecialchars($employee['email_id'] ?: 'No Email Assigned'); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-7">
                        <div class="modern-card h-100 mb-0">
                            <div class="modern-card-header justify-content-between">
                                <div><i data-feather="monitor" style="width:16px;"></i> System Configuration</div>
                                <?php if ($last_updated_time): ?>
                                    <div class="text-muted fw-normal" style="font-size: 0.75rem; letter-spacing: 0;">
                                        <i data-feather="clock" style="width:12px; margin-top:-2px;" class="me-1"></i> Last updated: <span id="globalLastUpdated"><?php echo date('M d, Y \a\t h:i A', strtotime($last_updated_time)); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="modern-card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Hostname <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control fw-bold ajax-update-config" name="hostname" id="hostname" value="<?php echo htmlspecialchars($checklist['hostname'] ?? ''); ?>" <?php echo $is_editable_now ? '' : 'readonly'; ?> required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Serial Number <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control ajax-update-config" name="serial_number" id="serial_number" value="<?php echo htmlspecialchars($checklist['serial_number'] ?? ''); ?>" <?php echo $is_editable_now ? '' : 'readonly'; ?> required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">System Model</label>
                                        <input type="text" class="form-control ajax-update-config" name="system_model" value="<?php echo htmlspecialchars($checklist['system_model'] ?? ''); ?>" <?php echo $is_editable_now ? '' : 'readonly'; ?>>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Processor | RAM | HDD/SSD</label>
                                        <input type="text" class="form-control ajax-update-config" name="processor_ram_hdd_ssd" value="<?php echo htmlspecialchars($checklist['processor_ram_hdd_ssd'] ?? ''); ?>" <?php echo $is_editable_now ? '' : 'readonly'; ?> placeholder="CPU / RAM / Storage">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">OS</label>
                                        <input type="text" class="form-control ajax-update-config" name="operating_system" value="<?php echo htmlspecialchars($checklist['operating_system'] ?? ''); ?>" <?php echo $is_editable_now ? '' : 'readonly'; ?>>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">MS Office Version</label>
                                        <input type="text" class="form-control ajax-update-config" name="ms_office_version" value="<?php echo htmlspecialchars($checklist['ms_office_version'] ?? ''); ?>" <?php echo $is_editable_now ? '' : 'readonly'; ?>>
                                    </div>
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
                                    if ($checklist['overall_status'] == 'Completed') echo 'bg-success-subtle text-success border border-success-subtle';
                                    else if ($checklist['overall_status'] == 'Verification Pending') echo 'bg-warning-subtle text-warning-emphasis border border-warning-subtle';
                                    else if ($checklist['overall_status'] == 'Preparing') echo 'bg-info-subtle text-info-emphasis border border-info-subtle';
                                    else echo 'bg-secondary-subtle text-secondary border border-secondary-subtle';
                                ?>">
                                    <?php echo htmlspecialchars($checklist['overall_status'] ?: 'Not Started'); ?>
                                </span>
                            </div>

                            <div class="fs-6 fw-bold text-dark">
                                <span class="text-muted fw-normal small me-1 text-uppercase" style="font-size: 0.7rem;">Assigned To:</span>
                                <span><?php echo htmlspecialchars($assigned_display_string); ?></span>
                            </div>

                            <div class="vr d-none d-md-block text-muted opacity-25" style="height: 24px;"></div>

                            <div>
                                <?php 
                                    if ($can_manage_checklist && $status == 'Preparing' && $is_assigned_to_current_user) {
                                        echo '<button type="button" class="btn btn-primary-modern btn-sm" data-bs-toggle="modal" data-bs-target="#submitVerificationConfirmModal">Submit for Verification</button>';
                                    } elseif ($status == 'Verification Pending' && $can_verify_checklist) {
                                        echo '<button type="button" class="btn btn-success text-white btn-sm rounded-pill me-2 px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#completeVerificationConfirmModal">Approve</button>';
                                        echo '<button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#rejectModal">Reject</button>';
                                    } elseif ($status == 'Not Started' || $status === null || $status == 'Preparing') {
                                        echo '<button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#assignModal">Assign Users</button>';
                                    }
                                ?>
                            </div>
                        </div>

                        <div class="d-flex align-items-center gap-3">
                            <div class="text-end me-2 d-none d-md-block">
                                <div class="text-xs fw-bold text-muted text-uppercase ls-1">Gatepass</div>
                            </div>
                            <?php if ($checklist['gatepass_id'] > 0): ?>
                                <button type="button" class="btn btn-outline-primary btn-sm rounded-pill fw-bold view-gatepass-link" data-gatepass-id="<?php echo $checklist['gatepass_id']; ?>">
                                    <i data-feather="truck" style="width:14px;" class="me-1"></i> View #<?php echo $checklist['gatepass_id']; ?>
                                </button>
                            <?php elseif ($checklist['gatepass_id'] == -1): ?>
                                <span class="badge bg-secondary-subtle text-muted border border-secondary-subtle rounded-pill">Not Required</span>
                            <?php else: ?>
                                <?php if (can('create_gatepass')): ?>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="form-check m-0" title="Confirm creation">
                                            <input class="form-check-input" type="checkbox" id="gatepass_required_check" style="cursor: pointer;">
                                        </div>
                                        <button type="button" id="proceed_with_gatepass_btn" class="btn btn-dark btn-sm rounded-pill fw-bold px-3">
                                            <i data-feather="plus" style="width:14px;" class="me-1"></i> Create
                                        </button>
                                        <button type="submit" name="decision" value="no" class="btn btn-link text-muted p-0 ms-1" title="Mark as Not Required" onclick="document.getElementById('mainFormAction').value='handle_gatepass_decision';"><i data-feather="x-circle" style="width:16px;"></i></button>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted small fst-italic">No Permission</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                    </div>
                    
                    <hr class="my-3 text-muted opacity-25">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div class="fw-bold text-dark d-flex align-items-center gap-2">
                            <i data-feather="user-check" style="width:18px;" class="text-primary"></i> 
                            User Acceptance:
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <?php 
                            $ua_status = $checklist['user_acceptance_status'] ?? 'Pending';
                            if ($ua_status == 'Pending'): 
                            ?>
                                <a href="<?= APP_BASE_PATH ?>index.php?route=user_acceptance&checklist_id=<?php echo $checklist_id; ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold">Open Form Link</a>
                                <?php if (!$is_modal_view): ?>
                                    <button type="button" class="btn btn-sm btn-light text-dark border rounded-pill px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#assetUploadModal" <?php echo !$can_upload_asset_form ? 'disabled' : ''; ?>><i data-feather="upload" style="width:14px;" class="me-1"></i> Upload Signed PDF</button>
                                <?php endif; ?>
                            <?php elseif ($ua_status == 'Accepted'): ?>
                                <span class="badge bg-success-subtle text-success-emphasis rounded-pill px-3 py-2 border border-success-subtle"><i data-feather="check-circle" class="me-1" style="width:14px;"></i> Accepted</span>
                                <a href="<?= APP_BASE_PATH ?>index.php?route=generate_acceptance_pdf&checklist_id=<?php echo $checklist_id; ?>" target="_blank" class="btn btn-sm btn-outline-success rounded-pill px-3 fw-bold"><i data-feather="download" style="width:14px;" class="me-1"></i> Download PDF</a>
                            <?php elseif ($ua_status == 'Rejected'): ?>
                                <span class="badge bg-danger-subtle text-danger-emphasis rounded-pill px-3 py-2 border border-danger-subtle"><i data-feather="x-circle" class="me-1" style="width:14px;"></i> Rejected</span>
                                <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-bold ms-2" data-bs-toggle="modal" data-bs-target="#resetAcceptanceModal"><i data-feather="refresh-cw" style="width:14px;" class="me-1"></i> Reset Form</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    </div>

                <div class="modern-card">
                    <div class="modern-card-header">
                        <div class="d-flex align-items-center gap-2">
                            <i data-feather="check-square" style="width:16px;"></i> Checklist Tasks
                        </div>
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
                                <?php if (!empty($render_checklist_items)) renderChecklistItems($render_checklist_items, $current_checklist_data, $is_editable_now, $employee, $checklist, $can_upload_asset_form, $is_assigned_to_current_user, [], $is_modal_view); ?>
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
                    $timeline_parent_id = $checklist['employee_id']; 
                    $timeline_action_type = 'System Readiness'; 
                    if(file_exists('../includes/timeline_partial.php')) include '../includes/timeline_partial.php'; 
                    else echo '<div class="text-muted small p-3">Timeline not available.</div>';
                    ?>
                </div>
            </div>
        </div>
        
        <?php if (!empty($previous_checklists)): ?>
        <div class="tab-pane fade" id="history-pane">
            <div class="modern-card">
                <div class="modern-card-header">Checklist History</div>
                <div class="table-responsive">
                    <table class="table-custom">
                        <thead><tr><th class="ps-4">Verified Date</th><th>Checklist No.</th><th>Purpose</th></tr></thead>
                        <tbody>
                            <?php foreach($previous_checklists as $h): ?>
                            <tr>
                                <td class="ps-4"><?php echo date('M d, Y \a\t h:i A', strtotime($h['approved_at'])); ?></td>
                                <td><a href="<?= APP_BASE_PATH ?>index.php?route=system_readiness_checklist&checklist_id=<?php echo $h['id']; ?>" class="fw-bold text-primary text-decoration-none">#<?php echo $h['id']; ?></a></td>
                                <td><?php echo htmlspecialchars($h['checklist_purpose']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="assignModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow"><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><div class="modal-header border-bottom-0"><h5 class="modal-title fw-bold">Assign Checklist</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="action" value="assign_checklist"><div class="mb-3"><label class="form-label">Select User</label><select name="assignees[]" class="form-select" multiple required style="height: 150px;"><?php foreach ($it_users as $u => $n) echo "<option value='$u' ".($u===$logged_in_username?'selected':'').">$n</option>"; ?></select></div></div><div class="modal-footer border-top-0"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary-modern">Assign</button></div></form></div></div></div>

<div class="modal fade" id="viewGatepassModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-xl"><div class="modal-content border-0 shadow"><div class="modal-header"><h5 class="modal-title fw-bold">Gatepass Preview</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body p-0" style="height: 80vh;"><iframe id="gatepass_iframe" src="" style="width:100%; height:100%; border:0;"></iframe></div></div></div></div>

<div class="modal fade" id="alertModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow"><div class="modal-header border-bottom-0"><h5 class="modal-title fw-bold text-danger" id="alertModalLabel"><i data-feather="alert-circle" class="me-2"></i>Attention</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="alertModalBody"></div><div class="modal-footer border-top-0"><button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Close</button></div></div></div></div>

<div class="modal fade" id="submitVerificationConfirmModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow"><div class="modal-body text-center p-4"><div class="mb-3 text-primary"><i data-feather="send" style="width: 40px; height: 40px;"></i></div><h5 class="fw-bold mb-2">Submit for Verification?</h5><p class="text-muted small mb-4">This will lock the checklist and notify the manager. Ensure all details are correct.</p><div class="d-flex justify-content-center gap-2"><button class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary-modern px-4" id="confirmPrepareSubmitBtn">Confirm Submit</button></div></div></div></div></div>

<div class="modal fade" id="completeVerificationConfirmModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow"><div class="modal-body text-center p-4"><div class="mb-3 text-success"><i data-feather="check-circle" style="width: 40px; height: 40px;"></i></div><h5 class="fw-bold mb-2">Approve System Readiness?</h5><p class="text-muted small mb-4">This confirms the system is fully ready. This action cannot be undone.</p><div class="d-flex justify-content-center gap-2"><button class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success text-white px-4 rounded-pill" id="confirmCompleteBtn">Yes, Approve</button></div></div></div></div></div>

<div class="modal fade" id="rejectModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow"><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><div class="modal-header border-bottom-0"><h5 class="modal-title fw-bold text-danger">Reject Checklist</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="action" value="reject_checklist"><div class="mb-3"><label class="form-label">Reason for Rejection</label><textarea name="rejection_remarks" class="form-control" rows="3" required placeholder="Describe what needs to be fixed..."></textarea></div></div><div class="modal-footer border-top-0"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger rounded-pill">Reject</button></div></form></div></div></div>

<div class="modal fade" id="assetUploadModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow"><form id="assetUploadForm" method="POST" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><div class="modal-header border-bottom-0"><h5 class="modal-title fw-bold">Upload Asset Issuance Form</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><p class="small text-muted mb-3">Please upload the PDF file signed by the user.</p><input type="hidden" name="action" value="upload_asset_form"><input type="file" name="asset_form" class="form-control" accept=".pdf" required></div><div class="modal-footer border-top-0"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary-modern">Upload File</button></div></form></div></div></div>

<div class="modal fade" id="resetAcceptanceModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow"><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><div class="modal-header bg-warning-subtle border-bottom-0"><h5 class="modal-title fw-bold text-dark">Reset User Acceptance?</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><p>This will reset the User Acceptance status to <strong>Pending</strong>.</p><input type="hidden" name="action" value="reset_acceptance"></div><div class="modal-footer border-top-0"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning text-dark fw-bold rounded-pill">Confirm Reset</button></div></form></div></div></div>

<?php if (!$is_modal_view) { include_once '../includes/footer.php'; } ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof feather !== 'undefined') feather.replace();
    
    const csrfToken = "<?php echo $_SESSION['csrf_token']; ?>";
    
    // Initialize Bootstrap Tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Toast Logic
    const phpMessage = <?php echo json_encode($_SESSION['form_success'] ?? null); unset($_SESSION['form_success']); ?>;
    const phpError = <?php echo json_encode($_SESSION['form_error'] ?? null); unset($_SESSION['form_error']); ?>;
    
    // Pass PHP Data to JS for Validation
    <?php
    $purposes_for_existing_employees_json = json_encode(['Asset Replacement', 'Department Change', 'Other']);
    echo "const existingEmployeePurposes = {$purposes_for_existing_employees_json};\n";
    echo "const currentPurpose = " . json_encode($checklist['checklist_purpose'] ?? '') . ";\n";
    ?>
    
    const employeeData = <?php echo json_encode([
        'name' => $employee['full_name'],
        'id' => $employee['emp_id'],
        'dept' => $employee['department'],
        'email' => $employee['email_id'],
        'doj' => $employee['date_of_joining'],
        'position' => $employee['position']
    ]); ?>;
    
    function showAlertModal(msg) {
        const el = document.getElementById('alertModal');
        if(!el) return;
        document.getElementById('alertModalBody').innerHTML = msg;
        new bootstrap.Modal(el).show();
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
    if (phpError) showToast(phpError, 'error');
    
    // Update color of selects on change
    document.querySelectorAll('.status-select').forEach(sel => {
        sel.addEventListener('change', function() {
            this.classList.remove('status-pending', 'status-completed', 'status-n/a');
            this.classList.add('status-' + this.value.toLowerCase().replace('/', '\\/'));
        });
    });
    
    // --- AJAX AUTO-SAVE LOGIC ---
    function sendAjaxUpdate(data, triggerElement = null) {
        data.append('checklist_id', '<?php echo $checklist_id; ?>');
        data.append('csrf_token', csrfToken); // ADD CSRF TOKEN

        fetch('<?= APP_BASE_PATH ?>index.php?route=system_readiness_checklist&checklist_id=<?php echo $checklist_id; ?>', {
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
                    const cell = row.querySelector('.updated-by-cell');
                    if (cell) {
                        cell.innerHTML = `<div class="lh-sm"><div class="fw-bold text-dark" style="font-size: 0.8rem;">${res.updated_by}</div><div class="text-muted" style="font-size: 0.7rem;">${res.updated_at}</div></div>`;
                    }
                }
            } else { showToast(res.message, 'error'); }
        }).catch(err => { console.error(err); showToast('Connection error', 'error'); });
    }

    // Config Listeners
    document.querySelectorAll('.ajax-update-config').forEach(input => {
        input.addEventListener('change', function() {
            if (this.hasAttribute('readonly')) return; 
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
            const fd = new FormData();
            fd.append('ajax_action', 'update_item');
            fd.append('item_id', this.dataset.itemId);
            fd.append('field', this.dataset.field); 
            fd.append('value', this.value);
            sendAjaxUpdate(fd, this);
        });
    });

    document.querySelectorAll('.form-control').forEach(input => {
        input.addEventListener('input', function() {
            if(this.value.trim() !== '') this.classList.remove('is-invalid');
        });
    });
    
    // Gatepass Initialization Restored
    const proceedBtn = document.getElementById('proceed_with_gatepass_btn');
    if(proceedBtn) {
        proceedBtn.addEventListener('click', function() {
            const gpCheck = document.getElementById('gatepass_required_check');
            if(gpCheck && !gpCheck.checked) { showAlertModal("Please check the box to confirm Gatepass creation."); return; }
            
            const host = document.getElementById('hostname')?.value;
            const serial = document.getElementById('serial_number')?.value;
            if(!host || !serial) { showAlertModal("Hostname & Serial Number are required to create a gatepass."); return; }
            
            const items = [{ description: `Hostname: ${host}, Serial: ${serial}`, quantity: 1 }];
            
            const params = new URLSearchParams({
                from_checklist_id: "<?php echo $checklist_id; ?>",
                employee_name: employeeData.name, 
                department: employeeData.dept,    
                date_of_joining: employeeData.doj,
                position: employeeData.position,  
                items: JSON.stringify(items)
            });
            
            window.location.href = `<?= APP_BASE_PATH ?>index.php?route=add_edit_gatepass&${params.toString()}`;
        });
    }

    // Modal Form Submit Triggers
    const confirmPrepareBtn = document.getElementById('confirmPrepareSubmitBtn');
    if(confirmPrepareBtn) {
        confirmPrepareBtn.addEventListener('click', () => {
            const mainForm = document.getElementById('mainForm');
            let pendingTasks = [];
            
            mainForm.querySelectorAll('.status-select').forEach(sel => {
                const taskText = sel.closest('tr').querySelector('.task-title').textContent.trim();
                // Ignore Asset Issuance Form validation
                if (sel.value === 'Pending' && taskText !== 'Fill out the Asset Issuance Form') { 
                    pendingTasks.push(`<li>${taskText}</li>`); 
                }
            });
            
            if (pendingTasks.length > 0) {
                bootstrap.Modal.getInstance(document.getElementById('submitVerificationConfirmModal')).hide();
                showAlertModal("All checklist items must be 'Completed' or 'N/A' before submitting. <br>The following tasks are still Pending:<ul class='mt-2 text-danger small'>" + pendingTasks.join('') + "</ul>");
                return;
            }
            
            document.getElementById('mainFormAction').value = 'prepare_status';
            mainForm.submit();
        });
    }

    const confirmVerifyBtn = document.getElementById('confirmCompleteBtn');
    if(confirmVerifyBtn) {
        confirmVerifyBtn.addEventListener('click', () => {
            const mainForm = document.getElementById('mainForm');
            document.getElementById('mainFormAction').value = 'verify_status';
            mainForm.submit();
        });
    }
    
    // View Gatepass Restored
    document.querySelectorAll('.view-gatepass-link').forEach(l => {
        l.addEventListener('click', e => {
            e.preventDefault();
            document.getElementById('gatepass_iframe').src = `<?= APP_BASE_PATH ?>index.php?route=generate_gatepass_pdf&id=${l.dataset.gatepassId}`;
            new bootstrap.Modal(document.getElementById('viewGatepassModal')).show();
        });
    });
});
</script>