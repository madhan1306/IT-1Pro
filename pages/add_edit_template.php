<?php
// FILE: add_edit_template.php

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

include_once '../includes/db_connect.php';
include_once '../includes/auth.php';
include_once '../classes/TemplateService.php';

$service = new TemplateService($conn);

// -------------------------------------------------------------------------
// 1. AJAX ENDPOINT FOR REAL-TIME VALIDATION
// -------------------------------------------------------------------------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'check_unique') {
    header('Content-Type: application/json');
    
    // Validate CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        echo json_encode(['taken' => false, 'error' => 'CSRF validation failed']);
        exit;
    }

    $field = $_POST['field'] ?? '';
    $value = $_POST['value'] ?? '';
    $excludeId = $_POST['exclude_id'] ?? null;
    
    // Auto format email if local part is sent
    if ($field === 'email_local_part') {
        $field = 'email_id';
        $value = $value . '@suntechnologies.com';
    }

    $isTaken = $service->isFieldTaken($field, $value, $excludeId);
    echo json_encode(['taken' => $isTaken]);
    exit;
}

// -------------------------------------------------------------------------
// 2. PERMISSIONS & INIT
// -------------------------------------------------------------------------
$is_editing = isset($_GET['id']) && is_numeric($_GET['id']);
$is_view_only = isset($_GET['view_only']) && $_GET['view_only'] === 'true';
$logged_in_user_fullname = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')) ?: ($_SESSION['username'] ?? '');

$perm_request   = can('request_template');
$perm_approve   = can('approve_template'); 
$perm_edit_basic = can('edit_template_basic');
$perm_edit_it    = can('edit_induction_it');
$perm_edit_fac   = can('edit_induction_facility');
$perm_edit_hr    = can('edit_induction_hr');
$perm_view_basic  = can('view_employee_details');
$perm_view_tech   = can('view_domain_details');
$perm_view_induct = can('view_induction_details');
$perm_view_timeline = can('view_activity_timeline'); 

$can_access_page = false;
if (!$is_editing) {
    if ($perm_request || $perm_approve) $can_access_page = true;
} else {
    if ($perm_request || $perm_approve || $perm_edit_basic || $perm_edit_it || $perm_edit_fac || $perm_edit_hr || $perm_view_basic || $perm_view_induct || $is_view_only) {
        $can_access_page = true;
    }
}

if (!$can_access_page) {
    $_SESSION['form_error'] = "You do not have permission to access this page.";
    header('Location: ' . APP_BASE_PATH . 'index.php?route=templates');
    exit();
}

// -------------------------------------------------------------------------
// 3. FETCH DATA
// -------------------------------------------------------------------------
$joiner = [
    'id' => null, 'emp_id' => '', 'first_name' => '', 'last_name' => '', 'date_of_joining' => '', 
    'location' => '', 'department' => '', 'position' => '', 'workstation' => '', 
    'asset_type' => '', 'voice_service' => '', 'o365_edition' => '', 'email_preference' => '', 'remarks' => '',
    'domain_username' => '', 'domain_password' => '', 'email_id' => '', 'email_password' => '', 'did' => '', 'ms_teams' => '', 'network_drives' => '', 
    'template_status' => '', 'updated_at' => null, 'employee_status' => 'Yet to Join',
    'requested_by' => '', 'requested_at' => null, 'created_by' => '', 'created_at' => null, 
    'it_induction_conducted_by' => '', 'it_induction_status' => 'Pending', 
    'facility_induction_conducted_by' => '', 'facility_induction_status' => 'Pending', 
    'hr_induction_conducted_by' => '', 'hr_induction_status' => 'Pending', 
    'checklist_id' => null, 'induction_it_remarks' => '', 'induction_hr_remarks' => '', 'induction_facility_remarks' => '',
    'hostname' => '', 'serial_number' => '', 'system_model' => '', 'processor_ram_hdd_ssd' => '', 'operating_system' => '', 'ms_office_version' => ''
];

$old_data = $joiner; 

if ($is_editing) {
    $dbData = $service->getJoinerById($_GET['id']);
    if ($dbData) {
        $joiner = array_merge($joiner, $dbData);
        $old_data = $joiner;
    } else {
        $_SESSION['form_error'] = "Employee template not found.";
        header('Location: ' . APP_BASE_PATH . 'index.php?route=templates');
        exit();
    }
}

// -------------------------------------------------------------------------
// 4. EDITABILITY LOGIC & STATUS FEATURE
// -------------------------------------------------------------------------
$status = $joiner['template_status'];
$is_approved = ($status === 'Approved');

// Employee MUST be Active for induction to be editable
$is_active = (($joiner['employee_status'] ?? '') === 'Active');

$can_edit_basic_section = false;
if ($perm_approve || $perm_edit_basic) $can_edit_basic_section = true;
elseif (!$is_approved && $perm_request) $can_edit_basic_section = true;

$can_edit_tech_section = ($perm_approve);

// INDUCTION EDITABILITY: Strictly requires the employee to be 'Active'.
$can_edit_it_induction = $is_active && ($perm_approve || $perm_edit_it);
$can_edit_fac_induction = $is_active && ($perm_approve || $perm_edit_fac);
$can_edit_hr_induction = $is_active && ($perm_approve || $perm_edit_hr);

$show_tech_section = ($perm_approve || $perm_view_tech);
$show_induction_section = ($perm_approve || $is_approved || $perm_view_induct || $perm_edit_it || $perm_edit_fac || $perm_edit_hr);

// -------------------------------------------------------------------------
// 5. POST HANDLING
// -------------------------------------------------------------------------
$error = '';
if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['ajax_action'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Security validation failed. Please try again.";
    } else {
        $action = $_POST['form_action'] ?? 'save'; 
        $joiner_id_from_post = $_POST['id'] ?? null;
        $is_update = !empty($joiner_id_from_post);
        $post_data = $_POST;

        // Ensure email formatting
        if ($can_edit_tech_section && !empty($post_data['email_local_part'])) {
            $post_data['email_id'] = $post_data['email_local_part'] . '@suntechnologies.com';
        } else {
            if (!$is_update) $post_data['email_id'] = null;
        }

        // Logic for Employee Status (Status Feature)
        if (!$is_update) {
            $post_data['employee_status'] = 'Yet to Join';
        } else {
            if (isset($post_data['emp_status_input'])) {
                $post_data['employee_status'] = $post_data['emp_status_input'];
            } else {
                $post_data['employee_status'] = $old_data['employee_status'] ?? 'Yet to Join';
            }
        }

        $required_fields = [];
        if ($can_edit_basic_section) $required_fields = array_merge($required_fields, ['emp_id', 'first_name', 'last_name', 'location', 'department', 'asset_type', 'voice_service', 'o365_edition', 'date_of_joining']);
        if ($action === 'approve') array_push($required_fields, 'domain_username', 'email_local_part');

        foreach ($required_fields as $field) {
            if (empty($post_data[$field]) && (array_key_exists($field, $post_data) || !$is_update)) {
                $error = "Missing required field: " . ucwords(str_replace('_', ' ', $field));
                break;
            }
        }

        if (!empty($error)) {
            $joiner = array_merge($joiner, $post_data);
            if ($is_update) { $joiner['id'] = $joiner_id_from_post; }
        } else {
            try {
                // Call TemplateService to save standard details
                $result = $service->saveTemplate($joiner_id_from_post, $post_data, $logged_in_user_fullname, $action);
                $final_id = $result['id'];
                
                // --- MANUAL STATUS UPDATE & CHECKLIST CREATION LOGIC ---
                $emp_status_to_save = $post_data['employee_status'] ?? 'Yet to Join';
                
                $stmt_status = $conn->prepare("UPDATE employees SET employee_status = ? WHERE id = ?");
                $stmt_status->bind_param("si", $emp_status_to_save, $final_id);
                $stmt_status->execute();
                $stmt_status->close();
                
                if ($emp_status_to_save === 'Active') {
                    $chk = $conn->query("SELECT id FROM checklists WHERE employee_id = $final_id AND checklist_type = 'system_readiness'");
                    if ($chk->num_rows == 0) {
                        // Added checklist_purpose = 'New Joiner' to ensure the timeline catches it natively
                        $c_sql = "INSERT INTO checklists (employee_id, checklist_type, checklist_purpose, overall_status, created_by) VALUES (?, 'system_readiness', 'New Joiner', 'Not Started', ?)";
                        $c_stmt = $conn->prepare($c_sql);
                        $c_stmt->bind_param("is", $final_id, $logged_in_user_fullname);
                        $c_stmt->execute();
                        $new_checklist_id = $c_stmt->insert_id; // Get the newly created checklist ID
                        $c_stmt->close();

                        // --- FIXED: ADD INITIALIZATION LOG FOR TIMELINE ---
                        $action_type = 'System Readiness';
                        $action_desc = "System Readiness Checklist Initialized.";
                        $log_stmt = $conn->prepare("INSERT INTO action_logs (joiner_id, template_id, performed_by, action_type, action_description) VALUES (?, ?, ?, ?, ?)");
                        if ($log_stmt) {
                            $log_stmt->bind_param("iisss", $final_id, $new_checklist_id, $logged_in_user_fullname, $action_type, $action_desc);
                            $log_stmt->execute();
                            $log_stmt->close();
                        }
                        // --------------------------------------------------
                    }
                }
                // -------------------------------------------------------

                $_SESSION['form_success'] = $result['message'];
                header("Location: " . APP_BASE_PATH . "index.php?route=add_edit_template&id=" . $final_id);
                exit();
            } catch (Exception $e) {
                $error = "System Error: " . $e->getMessage();
                $joiner = array_merge($joiner, $post_data);
                if ($is_update) { $joiner['id'] = $joiner_id_from_post; }
            }
        }
    }
}

// -------------------------------------------------------------------------
// 6. DROPDOWNS & DEFAULTS
// -------------------------------------------------------------------------
$departments = []; $locations = []; $it_users = []; $fac_users = []; $hr_users = [];
$q = $conn->query("SELECT name FROM departments ORDER BY name ASC");
if($q) while ($r = $q->fetch_assoc()) $departments[] = $r['name'];
$q = $conn->query("SELECT name FROM locations ORDER BY name ASC");
if($q) while ($r = $q->fetch_assoc()) $locations[] = $r['name'];
$q = $conn->query("SELECT username, firstname, lastname, department FROM users ORDER BY firstname ASC");
if($q) while ($r = $q->fetch_assoc()) {
    $n = trim($r['firstname'].' '.$r['lastname']) ?: $r['username'];
    if (stripos($r['department'], 'it support')!==false) $it_users[$r['username']] = $n;
    if (stripos($r['department'], 'facility')!==false) $fac_users[$r['username']] = $n;
    if (stripos($r['department'], 'hr')!==false || stripos($r['department'], 'human')!==false) $hr_users[$r['username']] = $n;
}

if (!$is_editing) {
    $res = $conn->query("SELECT MAX(CAST(SUBSTRING(emp_id, 4) AS UNSIGNED)) AS m FROM employees WHERE emp_id LIKE 'EMP%'");
    $next = ($res && $res->num_rows > 0) ? ($res->fetch_assoc()['m'] + 1) : 1;
    $joiner['emp_id'] = 'EMP' . str_pad($next, 3, '0', STR_PAD_LEFT);
}

// --- EXACT 8-CHARACTER STRONG PASSWORD GENERATOR ---
if ($show_tech_section && empty($joiner['domain_password'])) {
    $upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $lower = 'abcdefghijklmnopqrstuvwxyz';
    $numbers = '0123456789';
    $special = '@#&!'; 
    
    $pwd = $upper[random_int(0, strlen($upper) - 1)] . 
           $lower[random_int(0, strlen($lower) - 1)] . 
           $numbers[random_int(0, strlen($numbers) - 1)] . 
           $special[random_int(0, strlen($special) - 1)];
           
    $all = $upper . $lower . $numbers . $special;
    for ($i = 0; $i < 4; $i++) {
        $pwd .= $all[random_int(0, strlen($all) - 1)];
    }
    
    $joiner['domain_password'] = str_shuffle($pwd);
    $joiner['email_password'] = 'Pre-Configured';
    $joiner['ms_teams'] = 'Pre-Configured';
}

$pageTitle = $is_editing ? "Edit Onboarding Template" : "New Onboarding Request";
include_once '../includes/header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<style>
    :root { --primary-color: #0f766e; --bg-color: #f3f4f6; --card-bg: #ffffff; --border-color: #e5e7eb; --radius: 12px; }
    body { font-family: 'Inter', sans-serif; background-color: var(--bg-color) !important; color: #1f2937; padding-bottom: 100px; font-size: 0.9rem; }
    h3 { font-size: 1.25rem; }
    .pro-card { background: var(--card-bg); border-radius: var(--radius); box-shadow: 0 2px 4px rgba(0,0,0,0.05); border: 1px solid var(--border-color); margin-bottom: 24px; overflow: hidden; }
    .pro-card-header { background: #fff; padding: 16px 24px; border-bottom: 1px solid var(--border-color); font-weight: 600; color: var(--primary-color); display: flex; align-items: center; gap: 8px; }
    .pro-card-body { padding: 24px; }
    .form-label { font-weight: 500; font-size: 0.9rem; color: #374151; margin-bottom: 6px; }
    .form-control, .form-select { border-radius: 8px; padding: 10px; font-size: 0.95rem; border: 1px solid #d1d5db; transition: all 0.2s;}
    .form-control:focus, .form-select:focus { border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1); }
    .form-control.is-invalid { border-color: #dc3545; box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.1); }
    .form-control[readonly], .form-select:disabled { background-color: #f9fafb; color: #6b7280; cursor: not-allowed; }
    .btn-primary-pro { background-color: white; color: var(--primary-color); border: 1px solid var(--primary-color); padding: 10px 24px; border-radius: 8px; font-weight: 500; }
    .btn-primary-pro:hover { background-color: #f0fdfa; }
    .status-badge { padding: 6px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; }
    .status-new { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
    .status-approved { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
    .status-draft { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
    .nav-tabs .nav-link { color: #4b5563; border: none; border-bottom: 2px solid transparent; font-weight: 500; padding: 12px 16px; transition: all 0.2s; }
    .nav-tabs .nav-link:hover { color: var(--primary-color); }
    .nav-tabs .nav-link.active { color: var(--primary-color); border-bottom: 2px solid var(--primary-color); background: none; }
    .action-bar { position: fixed; bottom: 0; left: 0; right: 0; background: rgba(255,255,255,0.95); backdrop-filter: blur(10px); border-top: 1px solid var(--border-color); padding: 16px 40px; box-shadow: 0 -4px 10px rgba(0,0,0,0.05); z-index: 1050; display: flex; justify-content: space-between; align-items: center; }
    
    .strength-meter { height: 6px; border-radius: 4px; margin-top: 6px; background: #e5e7eb; overflow: hidden; display: flex; }
    .strength-meter div { height: 100%; transition: width 0.3s ease-in-out; width: 0; }
    .strength-text { font-size: 0.75rem; font-weight: 600; margin-top: 4px; text-align: right;}
    .strength-meter .strength-weak { background: #ef4444; width: 33.3%; }
    .strength-meter .strength-medium { background: #f59e0b; width: 66.6%; }
    .strength-meter .strength-strong { background: #10b981; width: 100%; }

    .stepper { display: flex; justify-content: space-between; position: relative; margin-bottom: 30px; padding: 0 20px; }
    .stepper::before { content: ''; position: absolute; top: 15px; left: 40px; right: 40px; height: 2px; background: #e5e7eb; z-index: 1; }
    .step { position: relative; z-index: 2; text-align: center; flex: 1; }
    .step-icon { width: 32px; height: 32px; border-radius: 50%; background: #fff; border: 2px solid #e5e7eb; color: #9ca3af; display: flex; align-items: center; justify-content: center; margin: 0 auto 8px; font-weight: bold; font-size: 0.85rem; }
    .step.active .step-icon { border-color: var(--primary-color); background: var(--primary-color); color: #fff; }
    .step.completed .step-icon { border-color: #10b981; background: #10b981; color: #fff; }
    .step-label { font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; }
    .step.active .step-label { color: var(--primary-color); }
</style>

<div class="container-xl py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <a href="<?= APP_BASE_PATH ?>index.php?route=templates" class="text-decoration-none text-muted mb-1 d-inline-block check-unsaved"><i data-feather="arrow-left" style="width:16px;"></i> Back to Templates</a>
        </div>
        <div>
            <?php if ($status === 'Approved'): ?>
                <span class="status-badge status-approved"><i data-feather="check-circle"></i> Approved</span>
            <?php elseif ($status === 'New Request'): ?>
                <span class="status-badge status-new"><i data-feather="inbox"></i> New Request</span>
            <?php else: ?>
                <span class="status-badge status-draft"><i data-feather="edit-2"></i> Draft Mode</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="stepper d-none d-md-flex">
        <div class="step <?= $is_editing ? 'completed' : 'active' ?>">
            <div class="step-icon"><?= $is_editing ? '<i data-feather="check" style="width:14px;"></i>' : '1' ?></div>
            <div class="step-label">Basic Info</div>
        </div>
        <div class="step <?= $is_approved ? 'completed' : ($is_editing ? 'active' : '') ?>">
            <div class="step-icon"><?= $is_approved ? '<i data-feather="check" style="width:14px;"></i>' : '2' ?></div>
            <div class="step-label">Credentials</div>
        </div>
        <div class="step <?= ($joiner['it_induction_status']=='Completed' && $joiner['hr_induction_status']=='Completed') ? 'completed' : ($is_approved ? 'active' : '') ?>">
            <div class="step-icon"><?= ($joiner['it_induction_status']=='Completed' && $joiner['hr_induction_status']=='Completed') ? '<i data-feather="check" style="width:14px;"></i>' : '3' ?></div>
            <div class="step-label">Inductions</div>
        </div>
        <div class="step <?= ($joiner['checklist_id']) ? 'completed' : '' ?>">
            <div class="step-icon"><?= ($joiner['checklist_id']) ? '<i data-feather="check" style="width:14px;"></i>' : '4' ?></div>
            <div class="step-label">System Readiness</div>
        </div>
    </div>

    <?php if ($error): ?><div class="alert alert-danger shadow-sm border-0 rounded-3 mb-4"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form id="templateForm" method="POST" action="<?= APP_BASE_PATH ?>index.php?route=add_edit_template<?= $is_editing ? '&id='.$joiner['id'] : '' ?>">
        <input type="hidden" name="id" id="joiner_id" value="<?= htmlspecialchars($joiner['id'] ?? '') ?>">
        <input type="hidden" name="form_action" id="form_action" value="save">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

        <?php 
        // Hidden Inputs for disabled sections to preserve data
        if (!$can_edit_basic_section) {
            $basic = ['emp_id', 'date_of_joining', 'first_name', 'last_name', 'department', 'location', 'position', 'workstation', 'asset_type', 'voice_service', 'o365_edition', 'email_preference', 'remarks', 'employee_status'];
            foreach($basic as $f) echo '<input type="hidden" name="'.$f.'" value="'.htmlspecialchars($joiner[$f] ?? '').'">';
        }
        if ($show_tech_section && !$can_edit_tech_section) {
            $tech = ['domain_username', 'domain_password', 'email_password', 'did', 'ms_teams', 'network_drives'];
            $lp = str_replace('@suntechnologies.com','',$joiner['email_id']);
            echo '<input type="hidden" name="email_local_part" value="'.htmlspecialchars($lp).'">';
            foreach($tech as $f) echo '<input type="hidden" name="'.$f.'" value="'.htmlspecialchars($joiner[$f] ?? '').'">';
        }
        if ($show_induction_section) {
            if (!$can_edit_it_induction) { 
                echo '<input type="hidden" name="it_induction_conducted_by" value="'.htmlspecialchars($joiner['it_induction_conducted_by']).'">';
                echo '<input type="hidden" name="it_induction_status" value="'.htmlspecialchars($joiner['it_induction_status']).'">'; 
                echo '<input type="hidden" name="induction_it_remarks" value="'.htmlspecialchars($joiner['induction_it_remarks']).'">';
            }
            if (!$can_edit_fac_induction) { 
                echo '<input type="hidden" name="facility_induction_conducted_by" value="'.htmlspecialchars($joiner['facility_induction_conducted_by']).'">';
                echo '<input type="hidden" name="facility_induction_status" value="'.htmlspecialchars($joiner['facility_induction_status']).'">'; 
                echo '<input type="hidden" name="induction_facility_remarks" value="'.htmlspecialchars($joiner['induction_facility_remarks']).'">';
            }
            if (!$can_edit_hr_induction) { 
                echo '<input type="hidden" name="hr_induction_conducted_by" value="'.htmlspecialchars($joiner['hr_induction_conducted_by']).'">';
                echo '<input type="hidden" name="hr_induction_status" value="'.htmlspecialchars($joiner['hr_induction_status']).'">'; 
                echo '<input type="hidden" name="induction_hr_remarks" value="'.htmlspecialchars($joiner['induction_hr_remarks']).'">';
            }
        }
        ?>

        <ul class="nav nav-tabs mb-4 align-items-center">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#details" type="button">Onboarding Details</button></li>
            <?php if ($is_editing && $perm_view_timeline): ?>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#timeline" type="button">Activity Timeline</button></li>
            <?php endif; ?>
            
            <li class="ms-auto pb-1">
                <div class="d-flex align-items-center gap-2">
                    <label class="fw-bold small text-muted mb-0">Status:</label>
                    <?php 
                    $disable_status_dd = false;
                    if (!$can_edit_basic_section) $disable_status_dd = true;
                    if (($joiner['employee_status'] ?? '') === 'Active') $disable_status_dd = true;
                    if (($joiner['employee_status'] ?? '') === 'Not Joined') $disable_status_dd = true;
                    ?>
                    
                    <?php if (($joiner['employee_status'] ?? '') === 'Active'): ?>
                        <span class="badge bg-success rounded-pill px-3 py-2">Active</span>
                        <input type="hidden" name="emp_status_input" value="Active">
                    <?php elseif (($joiner['employee_status'] ?? '') === 'Not Joined'): ?>
                        <span class="badge bg-danger rounded-pill px-3 py-2">Not Joined</span>
                        <input type="hidden" name="emp_status_input" value="Not Joined">
                    <?php else: ?>
                        <select class="form-select form-select-sm border-secondary-subtle" name="emp_status_input" style="width: auto; min-width: 120px;" <?= ($disable_status_dd)?'disabled':'' ?>>
                            <option value="Yet to Join" <?= (!isset($joiner['employee_status']) || $joiner['employee_status'] === 'Yet to Join') ? 'selected' : '' ?>>Yet to Join</option>
                            <option value="Active" <?= (($joiner['employee_status'] ?? '') === 'Active') ? 'selected' : '' ?>>Active</option>
                            <option value="Not Joined" <?= (($joiner['employee_status'] ?? '') === 'Not Joined') ? 'selected' : '' ?>>Not Joined</option>
                        </select>
                        <?php if ($disable_status_dd): ?>
                            <input type="hidden" name="emp_status_input" value="<?= htmlspecialchars($joiner['employee_status'] ?? 'Yet to Join') ?>">
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </li>
        </ul>

        <div class="tab-content">
            <div class="tab-pane fade show active" id="details">
                <div class="row">
                    <div class="<?= ($is_approved) ? 'col-lg-8' : 'col-lg-12' ?>">
                        
                        <?php if ($is_editing): ?>
                        <div class="pro-card mb-4" style="background: linear-gradient(to right, #f8f9fa, #ffffff); border-left: 4px solid var(--primary-color);">
                            <div class="pro-card-body py-3">
                                <div class="row align-items-center">
                                    <div class="col-md-6 border-end">
                                        <div class="text-uppercase text-muted fw-bold" style="font-size: 0.7rem; letter-spacing: 0.5px;">Requested By</div>
                                        <div class="fs-6 fw-bold text-dark mt-1"><?= htmlspecialchars($joiner['requested_by'] ?? 'System') ?></div>
                                        <div class="text-muted small"><i data-feather="calendar" style="width:12px; height:12px;" class="me-1"></i><?= ($joiner['requested_at']) ? date('M d, Y \a\t h:i A', strtotime($joiner['requested_at'])) : 'N/A' ?></div>
                                    </div>
                                    <div class="col-md-6 ps-md-4">
                                        <div class="text-uppercase text-muted fw-bold" style="font-size: 0.7rem; letter-spacing: 0.5px;">Approved By</div>
                                        <?php if ($is_approved): ?>
                                            <div class="fs-6 fw-bold text-success mt-1"><?= htmlspecialchars($joiner['created_by'] ?? 'N/A') ?></div>
                                            <div class="text-muted small"><i data-feather="check-circle" style="width:12px; height:12px;" class="me-1"></i><?= ($joiner['created_at']) ? date('M d, Y \a\t h:i A', strtotime($joiner['created_at'])) : 'N/A' ?></div>
                                        <?php else: ?>
                                            <div class="text-muted fst-italic mt-1">Pending Approval</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="pro-card">
                            <div class="pro-card-header"><i data-feather="user"></i> Employee Information</div>
                            <div class="pro-card-body">
                                <div class="row g-3">
                                    <div class="col-md-6"><label class="form-label">Employee ID <span class="text-danger">*</span></label><input type="text" class="form-control" name="emp_id" value="<?= htmlspecialchars($joiner['emp_id']) ?>" <?= (!$can_edit_basic_section)?'readonly':'' ?>></div>
                                    <div class="col-md-6"><label class="form-label">Date of Joining <span class="text-danger">*</span></label><input type="text" class="form-control bg-white" id="date_of_joining" name="date_of_joining" value="<?= htmlspecialchars($joiner['date_of_joining']) ?>" <?= (!$can_edit_basic_section)?'disabled':'' ?>></div>
                                    <div class="col-md-6"><label class="form-label">First Name <span class="text-danger">*</span></label><input type="text" class="form-control" name="first_name" value="<?= htmlspecialchars($joiner['first_name']) ?>" <?= (!$can_edit_basic_section)?'readonly':'' ?>></div>
                                    <div class="col-md-6"><label class="form-label">Last Name <span class="text-danger">*</span></label><input type="text" class="form-control" name="last_name" value="<?= htmlspecialchars($joiner['last_name']) ?>" <?= (!$can_edit_basic_section)?'readonly':'' ?>></div>
                                    <div class="col-md-6"><label class="form-label">Department <span class="text-danger">*</span></label><select class="form-select" name="department" <?= (!$can_edit_basic_section)?'disabled':'' ?>><option value="">Select</option><?php foreach ($departments as $d): ?><option value="<?=$d?>" <?= ($joiner['department']==$d)?'selected':'' ?>><?=$d?></option><?php endforeach; ?></select></div>
                                    <div class="col-md-6"><label class="form-label">Location <span class="text-danger">*</span></label><select class="form-select" name="location" <?= (!$can_edit_basic_section)?'disabled':'' ?>><option value="">Select</option><?php foreach ($locations as $l): ?><option value="<?=$l?>" <?= ($joiner['location']==$l)?'selected':'' ?>><?=$l?></option><?php endforeach; ?></select></div>
                                    <div class="col-md-6"><label class="form-label">Position</label><input type="text" class="form-control" name="position" value="<?= htmlspecialchars($joiner['position']) ?>" <?= (!$can_edit_basic_section)?'readonly':'' ?>></div>
                                    <div class="col-md-6"><label class="form-label">Workstation</label><input type="text" class="form-control" name="workstation" value="<?= htmlspecialchars($joiner['workstation']) ?>" <?= (!$can_edit_basic_section)?'readonly':'' ?>></div>
                                </div>
                            </div>
                        </div>

                        <div class="pro-card">
                            <div class="pro-card-header"><i data-feather="monitor"></i> Asset & Voice Configuration</div>
                            <div class="pro-card-body">
                                <div class="row g-3">
                                    <div class="col-md-6"><label class="form-label">Asset Type <span class="text-danger">*</span></label><select class="form-select" name="asset_type" <?= (!$can_edit_basic_section)?'disabled':'' ?>><option value="">Select</option><option value="Laptop" <?=($joiner['asset_type']=='Laptop')?'selected':''?>>Laptop</option><option value="Desktop" <?=($joiner['asset_type']=='Desktop')?'selected':''?>>Desktop</option></select></div>
                                    <div class="col-md-6"><label class="form-label">Voice Service <span class="text-danger">*</span></label><select class="form-select" name="voice_service" <?= (!$can_edit_basic_section)?'disabled':'' ?>><option value="">Select</option><option value="International" <?=($joiner['voice_service']=='International')?'selected':''?>>International</option><option value="Domestic" <?=($joiner['voice_service']=='Domestic')?'selected':''?>>Domestic</option><option value="Both" <?=($joiner['voice_service']=='Both')?'selected':''?>>Both</option><option value="NA" <?=($joiner['voice_service']=='NA')?'selected':''?>>NA</option></select></div>
                                    <div class="col-md-6"><label class="form-label">O365 Edition <span class="text-danger">*</span></label><select class="form-select" name="o365_edition" <?= (!$can_edit_basic_section)?'disabled':'' ?>><option value="">Select</option><option value="O365 Business Standard" <?=($joiner['o365_edition']=='O365 Business Standard')?'selected':''?>>O365 Business Standard</option><option value="O365 Business Basic" <?=($joiner['o365_edition']=='O365 Business Basic')?'selected':''?>>O365 Business Basic</option><option value="O365 Apps for business" <?=($joiner['o365_edition']=='O365 Apps for business')?'selected':''?>>O365 Apps for Business</option><option value="O365 F3" <?=($joiner['o365_edition']=='O365 F3')?'selected':''?>>O365 F3</option><option value="NA" <?=($joiner['o365_edition']=='NA' || empty($joiner['o365_edition']))?'selected':''?>>NA</option></select></div>                                    
                                    <div class="col-md-6"><label class="form-label">Email ID Preference</label><input type="text" class="form-control" name="email_preference" value="<?= htmlspecialchars($joiner['email_preference']) ?>" <?= (!$can_edit_basic_section)?'readonly':'' ?>></div>
                                    <div class="col-12"><label class="form-label">Remarks</label><textarea class="form-control" name="remarks" rows="2" <?= (!$can_edit_basic_section)?'readonly':'' ?>><?= htmlspecialchars($joiner['remarks']) ?></textarea></div>
                                </div>
                            </div>
                        </div>

                        <?php if ($show_tech_section): ?>
                        <div class="pro-card border-primary" style="border-left-width: 4px;">
                            <div class="pro-card-header text-dark"><i data-feather="shield"></i> Domain & Account Credentials</div>
                            <div class="pro-card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Domain Username <span class="text-danger">*</span></label>
                                        <div class="position-relative">
                                            <input type="text" class="form-control font-monospace ajax-validate" data-field="domain_username" id="domain_username" name="domain_username" value="<?= htmlspecialchars($joiner['domain_username']) ?>" <?= (!$can_edit_tech_section)?'readonly':'' ?>>
                                            <div class="invalid-feedback small mt-1">Username is already taken.</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
    					                <label class="form-label">
        				                    Domain Password <span class="text-danger">*</span> 
        				                    <span class="text-muted fw-normal" style="text-transform: none; letter-spacing: normal; font-size: 0.65rem;">(User has to change on next logon)</span>
    					                </label>
    					                <div class="input-group">
        				                    <input type="password" class="form-control font-monospace" id="domain_password" name="domain_password" value="<?= htmlspecialchars($joiner['domain_password']) ?>" <?= (!$can_edit_tech_section)?'readonly':'' ?>>
        				                    <button class="btn btn-outline-secondary py-0" type="button" onclick="togglePassword()"><i data-feather="eye"></i></button>
    					                </div>
                                        <div class="strength-meter mt-2"><div id="pwd_meter" class="strength-weak"></div></div>
                                        <div id="pwd_text" class="strength-text text-danger">Weak</div>
				                    </div>                                    
				                    <div class="col-md-6">
                                        <label class="form-label">Email Address <span class="text-danger">*</span></label>
                                        <div class="input-group position-relative">
                                            <input type="text" class="form-control font-monospace ajax-validate" data-field="email_local_part" id="email_local_part" name="email_local_part" value="<?= htmlspecialchars(str_replace('@suntechnologies.com','',$joiner['email_id'])) ?>" <?= (!$can_edit_tech_section)?'readonly':'' ?>>
                                            <span class="input-group-text bg-light text-muted small py-0">@suntechnologies.com</span>
                                        </div>
                                        <div class="invalid-feedback small mt-1 d-block" id="email_feedback" style="display:none;"></div>
                                    </div>
                                    <div class="col-md-6"><label class="form-label">MS Teams</label><input type="text" class="form-control" name="ms_teams" value="<?= htmlspecialchars($joiner['ms_teams']) ?>" <?= (!$can_edit_tech_section)?'readonly':'' ?>></div>
                                    <div class="col-md-4"><label class="form-label">Network Drives</label><select class="form-select" name="network_drives" <?= (!$can_edit_tech_section)?'disabled':'' ?>><option value="">None</option><option value="H" <?=($joiner['network_drives']=='H')?'selected':''?>>H</option><option value="H, S" <?=($joiner['network_drives']=='H, S')?'selected':''?>>H, S</option></select></div>
                                    <div class="col-md-4"><label class="form-label">Email Password</label><input type="text" class="form-control" name="email_password" value="<?= htmlspecialchars($joiner['email_password']) ?>" <?= (!$can_edit_tech_section)?'readonly':'' ?>></div>
                                    <div class="col-md-4"><label class="form-label">DID</label><input type="text" class="form-control" name="did" value="<?= htmlspecialchars($joiner['did']) ?>" <?= (!$can_edit_tech_section)?'readonly':'' ?>></div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($show_induction_section): ?>
                        <div class="pro-card">
                            <div class="pro-card-header"><i data-feather="clipboard"></i> Induction Status</div>
                            <div class="pro-card-body">
                                <div class="row g-3">
                                    <div class="col-md-4"><div class="p-3 bg-light rounded border h-100" <?= !$is_active ? 'title="Employee Status is not Active."' : '' ?>>
                                        <h6 class="text-primary fw-bold mb-3">HR Induction</h6>
                                        <label class="form-label small text-muted">Conducted By</label>
                                        <select class="form-select mb-2" id="hr_induction_conducted_by" name="hr_induction_conducted_by" <?= (!$can_edit_hr_induction)?'disabled':'' ?>><option value="">Select User</option><?php foreach ($hr_users as $u => $n): ?><option value="<?= htmlspecialchars($u) ?>" <?= ($joiner['hr_induction_conducted_by'] == $u) ? 'selected' : '' ?>><?= htmlspecialchars($n) ?></option><?php endforeach; ?></select>
                                        <label class="form-label small text-muted">Status</label>
                                        <select class="form-select mb-2" id="hr_induction_status" name="hr_induction_status" <?= (!$can_edit_hr_induction)?'disabled':'' ?>><option value="Pending" <?= ($joiner['hr_induction_status'] == 'Pending') ? 'selected' : '' ?>>Pending</option><option value="Completed" <?= ($joiner['hr_induction_status'] == 'Completed') ? 'selected' : '' ?>>Completed</option></select>
                                        <label class="form-label small text-muted">Employee Feedback</label><textarea class="form-control" name="induction_hr_remarks" rows="2" <?= (!$can_edit_hr_induction)?'readonly':'' ?>><?= htmlspecialchars($joiner['induction_hr_remarks']) ?></textarea>
                                    </div></div>
                                    <div class="col-md-4"><div class="p-3 bg-light rounded border h-100" <?= !$is_active ? 'title="Employee Status is not Active."' : '' ?>>
                                        <h6 class="text-primary fw-bold mb-3">IT Induction</h6>
                                        <label class="form-label small text-muted">Conducted By</label>
                                        <select class="form-select mb-2" id="it_induction_conducted_by" name="it_induction_conducted_by" <?= (!$can_edit_it_induction)?'disabled':'' ?>><option value="">Select User</option><?php foreach ($it_users as $u => $n): ?><option value="<?= htmlspecialchars($u) ?>" <?= ($joiner['it_induction_conducted_by'] == $u) ? 'selected' : '' ?>><?= htmlspecialchars($n) ?></option><?php endforeach; ?></select>
                                        <label class="form-label small text-muted">Status</label>
                                        <select class="form-select mb-2" id="it_induction_status" name="it_induction_status" <?= (!$can_edit_it_induction)?'disabled':'' ?>><option value="Pending" <?= ($joiner['it_induction_status'] == 'Pending') ? 'selected' : '' ?>>Pending</option><option value="Completed" <?= ($joiner['it_induction_status'] == 'Completed') ? 'selected' : '' ?>>Completed</option></select>
                                        <label class="form-label small text-muted">Employee Feedback</label><textarea class="form-control" name="induction_it_remarks" rows="2" <?= (!$can_edit_it_induction)?'readonly':'' ?>><?= htmlspecialchars($joiner['induction_it_remarks']) ?></textarea>
                                    </div></div>
                                    <div class="col-md-4"><div class="p-3 bg-light rounded border h-100" <?= !$is_active ? 'title="Employee Status is not Active."' : '' ?>>
                                        <h6 class="text-primary fw-bold mb-3">Facility Induction</h6>
                                        <label class="form-label small text-muted">Conducted By</label>
                                        <select class="form-select mb-2" id="facility_induction_conducted_by" name="facility_induction_conducted_by" <?= (!$can_edit_fac_induction)?'disabled':'' ?>><option value="">Select User</option><?php foreach ($fac_users as $u => $n): ?><option value="<?= htmlspecialchars($u) ?>" <?= ($joiner['facility_induction_conducted_by'] == $u) ? 'selected' : '' ?>><?= htmlspecialchars($n) ?></option><?php endforeach; ?></select>
                                        <label class="form-label small text-muted">Status</label>
                                        <select class="form-select mb-2" id="facility_induction_status" name="facility_induction_status" <?= (!$can_edit_fac_induction)?'disabled':'' ?>><option value="Pending" <?= ($joiner['facility_induction_status'] == 'Pending') ? 'selected' : '' ?>>Pending</option><option value="Completed" <?= ($joiner['facility_induction_status'] == 'Completed') ? 'selected' : '' ?>>Completed</option></select>
                                        <label class="form-label small text-muted">Employee Feedback</label><textarea class="form-control" name="induction_facility_remarks" rows="2" <?= (!$can_edit_fac_induction)?'readonly':'' ?>><?= htmlspecialchars($joiner['induction_facility_remarks']) ?></textarea>
                                    </div></div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($is_approved): ?>
                    <div class="col-lg-4">
                        <div class="pro-card sticky-top" style="top: 20px;">
                            <div class="pro-card-header"><div class="pro-card-title"><i data-feather="hard-drive"></i> System Info</div></div>
                            <div class="pro-card-body">
                                <div class="mb-3"><label class="section-label mb-1 text-muted small fw-bold">Hostname</label><div class="fs-5 fw-bold text-dark"><?= htmlspecialchars($joiner['hostname'] ?? 'Pending') ?></div></div>
                                <div class="mb-3"><label class="section-label mb-1 text-muted small fw-bold">Serial Number</label><div class="font-monospace bg-light p-2 rounded border"><?= htmlspecialchars($joiner['serial_number'] ?? 'N/A') ?></div></div>
                                <div class="mb-3"><label class="section-label mb-1 text-muted small fw-bold">Model</label><div><?= htmlspecialchars($joiner['system_model'] ?? 'N/A') ?></div></div>
                                <div class="mb-3"><label class="section-label mb-1 text-muted small fw-bold">Specs</label><div><?= htmlspecialchars($joiner['processor_ram_hdd_ssd'] ?? 'N/A') ?></div></div>
                                <div class="mb-3"><label class="section-label mb-1 text-muted small fw-bold">OS</label><div><?= htmlspecialchars($joiner['operating_system'] ?? 'N/A') ?></div></div>
                                <div class="mb-3"><label class="section-label mb-1 text-muted small fw-bold">Office Ver.</label><div><?= htmlspecialchars($joiner['ms_office_version'] ?? 'N/A') ?></div></div>
                                <hr>
                                <?php if ($perm_approve && $joiner['checklist_id']): ?>
                                <div class="d-grid"><a href="<?= APP_BASE_PATH ?>index.php?route=system_readiness_checklist&checklist_id=<?= $joiner['checklist_id'] ?>" class="btn btn-outline-secondary btn-sm">View Readiness Checklist</a></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($is_editing && $perm_view_timeline): ?>
            <div class="tab-pane fade" id="timeline">
                <div class="pro-card">
                    <div class="pro-card-header">Activity History</div>
                    <div class="pro-card-body">
                        <?php 
                        $timeline_parent_id = $joiner['id']; 
                        $timeline_action_type = 'Template Management';
                        if(file_exists('../includes/timeline_partial.php')) include '../includes/timeline_partial.php'; 
                        ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="modal fade modal-compact" id="unsavedChangesModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-sm">
                <div class="modal-content modal-elegant" style="border-left: 3px solid #6b7280;">
                    <div class="modal-header"><h5 class="modal-title" style="color:#374151;">Unsaved Changes</h5></div>
                    <div class="modal-body">You have unsaved changes. Discard them?</div>
                    <div class="modal-footer"><button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-sm btn-danger rounded-pill px-3" id="confirmLeaveBtn">Discard</button></div>
                </div>
            </div>
        </div>

        <div class="modal fade modal-compact" id="alertModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-sm">
                <div class="modal-content modal-elegant border-left-3px solid #dc2626;">
                    <div class="modal-header"><h5 class="modal-title"><i data-feather="alert-circle" style="width:18px;"></i> Action Required</h5></div>
                    <div class="modal-body py-4" id="alertModalBody"></div>
                    <div class="modal-footer"><button type="button" class="btn btn-sm btn-secondary rounded-pill px-4" data-bs-dismiss="modal">OK</button></div>
                </div>
            </div>
        </div>

        <div class="action-bar">
            <div class="text-muted small"><?php if ($is_editing): ?>Last updated: <strong><?= date('M d, Y \a\t h:i A', strtotime($joiner['updated_at'] ?? $joiner['created_at'])) ?></strong><?php else: ?>New Entry<?php endif; ?></div>
            <div class="d-flex gap-2">
                <a href="<?= APP_BASE_PATH ?>index.php?route=templates" class="btn btn-outline-secondary check-unsaved">Cancel</a>
                <button type="button" onclick="submitAction('save')" class="btn btn-primary-pro" id="saveBtn"><?= ($is_approved) ? 'Update Details' : (($is_editing) ? 'Save Changes' : 'Submit Request') ?></button>
                <?php if ($perm_approve && !$is_approved): ?>
                    <button type="button" id="approveBtn" onclick="submitAction('approve')" class="btn btn-success text-white fw-bold shadow-sm" disabled><i data-feather="check-circle" class="me-1"></i> Approve & Finish</button>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
    const csrfToken = "<?= $_SESSION['csrf_token'] ?>";

    let isFormDirty = false;
    let targetUrl = '';

    document.addEventListener('DOMContentLoaded', function() {
        if (typeof feather !== 'undefined') feather.replace();
        flatpickr("#date_of_joining", { dateFormat: "Y-m-d", allowInput: true });

        // Unsaved Changes Tracking
        document.querySelectorAll('input, select, textarea').forEach(input => {
            input.addEventListener('change', () => isFormDirty = true);
            input.addEventListener('input', () => isFormDirty = true);
        });

        window.addEventListener('beforeunload', function (e) {
            if (isFormDirty) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        document.querySelectorAll('a.check-unsaved, .nav-link').forEach(link => {
            link.addEventListener('click', function(e) {
                const href = this.getAttribute('href');
                if (isFormDirty && !this.getAttribute('target') && href && !href.startsWith('#') && !href.startsWith('javascript:')) {
                    e.preventDefault();
                    targetUrl = this.href;
                    new bootstrap.Modal(document.getElementById('unsavedChangesModal')).show();
                }
            });
        });

        document.getElementById('confirmLeaveBtn').addEventListener('click', function() {
            isFormDirty = false; 
            window.location.href = targetUrl;
        });

        // ----------------------------------------------------
        // AJAX VALIDATION (Real-time unique check)
        // ----------------------------------------------------
        let debounceTimer;
        document.querySelectorAll('.ajax-validate').forEach(input => {
            input.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                if (this.hasAttribute('readonly')) return;
                
                debounceTimer = setTimeout(() => {
                    const field = this.dataset.field;
                    const value = this.value;
                    const joinerId = document.getElementById('joiner_id').value;
                    
                    if(value.trim() === '') {
                        this.classList.remove('is-invalid');
                        updateApproveBtnState();
                        return;
                    }

                    const fd = new FormData();
                    fd.append('ajax_action', 'check_unique');
                    fd.append('field', field);
                    fd.append('value', value);
                    fd.append('csrf_token', csrfToken); 
                    if (joinerId) fd.append('exclude_id', joinerId);

                    // FIXED AJAX URL HERE
                    fetch('<?= APP_BASE_PATH ?>index.php?route=add_edit_template', { method: 'POST', body: fd })
                        .then(res => res.json())
                        .then(data => {
                            if (data.taken) {
                                this.classList.add('is-invalid');
                                if (field === 'email_local_part') {
                                    document.getElementById('email_feedback').textContent = "Email address is already in use.";
                                    document.getElementById('email_feedback').style.display = 'block';
                                }
                            } else {
                                this.classList.remove('is-invalid');
                                if (field === 'email_local_part') document.getElementById('email_feedback').style.display = 'none';
                            }
                            updateApproveBtnState();
                        });
                }, 500); 
            });
        });

        // ----------------------------------------------------
        // PASSWORD STRENGTH METER
        // ----------------------------------------------------
        const pwdInput = document.getElementById('domain_password');
        const meter = document.getElementById('pwd_meter');
        const meterText = document.getElementById('pwd_text');
        
        if (pwdInput && !pwdInput.hasAttribute('readonly')) {
            const checkStrength = function() {
                const val = pwdInput.value;
                let strength = 0;
                if (val.length >= 8) strength += 1;
                if (val.match(/[a-z]+/)) strength += 1;
                if (val.match(/[A-Z]+/)) strength += 1;
                if (val.match(/[0-9]+/)) strength += 1;
                if (val.match(/[$@#&!]+/)) strength += 1;

                if (val.length === 0) {
                    meter.className = ''; meterText.textContent = '';
                } else if (strength < 3) {
                    meter.className = 'strength-weak'; meterText.textContent = 'Weak'; meterText.className = 'strength-text text-danger';
                } else if (strength >= 3 && strength < 5) {
                    meter.className = 'strength-medium'; meterText.textContent = 'Medium'; meterText.className = 'strength-text text-warning';
                } else {
                    meter.className = 'strength-strong'; meterText.textContent = 'Strong'; meterText.className = 'strength-text text-success';
                }
            };
            pwdInput.addEventListener('input', checkStrength);
            if (pwdInput.value !== '') checkStrength();
        }

        // Induction Validation Logic
        const setupInductionLogic = (prefix) => {
            const userSelect = document.getElementById(`${prefix}_induction_conducted_by`);
            const statusSelect = document.getElementById(`${prefix}_induction_status`);
            const alertModalEl = document.getElementById('alertModal');
            const alertModal = new bootstrap.Modal(alertModalEl);
            const alertBody = document.getElementById('alertModalBody');

            if (userSelect && statusSelect) {
                statusSelect.addEventListener('change', function() {
                    if (this.value === "Completed") {
                        if (userSelect.value === "") {
                            alertBody.textContent = "Please select the user who conducted the induction first.";
                            alertModal.show();
                            this.value = "Pending";
                            alertModalEl.addEventListener('hidden.bs.modal', function () {
                                userSelect.focus();
                            }, { once: true });
                        }
                    } else if (this.value === "Pending") {
                        userSelect.value = "";
                    }
                });
                userSelect.addEventListener('change', function() {
                    if (this.value !== "") statusSelect.value = "Completed";
                    else statusSelect.value = "Pending";
                });
            }
        };

        setupInductionLogic('it'); setupInductionLogic('facility'); setupInductionLogic('hr');
        
        updateApproveBtnState();
    });

    function updateApproveBtnState() {
        const domUser = document.getElementById('domain_username');
        const emailLocal = document.getElementById('email_local_part');
        const appBtn = document.getElementById('approveBtn');
        const saveBtn = document.getElementById('saveBtn');
        
        const invalidFields = document.querySelectorAll('.is-invalid').length;
        if (saveBtn) saveBtn.disabled = (invalidFields > 0);

        if (domUser && emailLocal && appBtn) {
            if (domUser.value.trim() !== '' && emailLocal.value.trim() !== '' && invalidFields === 0) {
                appBtn.disabled = false;
            } else {
                appBtn.disabled = true;
            }
        }
    }

    function submitAction(action) {
        if (document.querySelectorAll('.is-invalid').length > 0) {
            document.getElementById('alertModalBody').textContent = "Please resolve the errors highlighted in red before saving.";
            new bootstrap.Modal(document.getElementById('alertModal')).show();
            return false;
        }

        isFormDirty = false; 
        document.getElementById('form_action').value = action;
        
        if (action === 'approve') {
            const u = document.querySelector('input[name="domain_username"]').value;
            const e = document.querySelector('input[name="email_local_part"]').value;
            if (!u || !e) { 
                document.getElementById('alertModalBody').textContent = "Domain Username & Email are required to Approve.";
                new bootstrap.Modal(document.getElementById('alertModal')).show();
                return false; 
            }
        }
        document.getElementById('templateForm').submit();
    }

    function togglePassword() {
        const x = document.getElementById("domain_password");
        x.type = (x.type === "password") ? "text" : "password";
    }
</script>
<?php include_once '../includes/footer.php'; ?>