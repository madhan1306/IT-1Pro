<?php
// FILE: pages/edit_employee.php (Employee Profile Hub)

include_once '../includes/error_handler.php';
include_once '../includes/db_connect.php';
include_once '../includes/auth.php';

// Security and Session
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

$super_user_id = 1;
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_super_user = ($current_user_id === $super_user_id); 

// --- OWNERSHIP LOOKUP ---
$own_employee_id = null;
if (!empty($_SESSION['username'])) {
    $stmt_own = $conn->prepare("SELECT id FROM employees WHERE domain_username = ? LIMIT 1");
    if ($stmt_own) {
        $stmt_own->bind_param("s", $_SESSION['username']);
        $stmt_own->execute();
        $res_own = $stmt_own->get_result();
        if ($row_own = $res_own->fetch_assoc()) {
            $own_employee_id = (int)$row_own['id'];
        }
        $stmt_own->close();
    }
}

// Determine which employee profile to load
$employee_id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : $own_employee_id;

$is_own_profile = ($employee_id === $own_employee_id);

// --- PERMISSION CHECKS ---
$can_edit = can('edit_employees') || $is_super_user; 
$can_view = can('view_employees') || $is_super_user;
$can_view_system = can('view_system_readiness') || $is_super_user;
$can_user_view = can('user_view');
$can_manage = can('manage_employees') || $is_super_user; 
$can_set_preferences = can('show_department_preference') || $is_super_user;

$is_basic_employee = ($can_user_view && !$can_view && !$is_super_user);

// Kick out users who don't even have view access (unless viewing their own profile via user_view)
if (!$can_view && !$can_edit) {
    if (!($can_user_view && $is_own_profile)) {
        checkPermission('view_employees'); 
    }
}

// Ensure the profile exists securely
if (!$employee_id) {
    if ($is_basic_employee) {
        $pageTitle = "Profile Not Found";
        include_once '../includes/header.php';
        echo '<div class="container text-center mt-5 pt-5"><i data-feather="user-x" style="width: 64px; height: 64px; color: #64748b; margin-bottom: 20px;"></i><h3 class="fw-bold text-dark">Profile Not Found</h3><p class="text-muted">Your Active Directory username does not match any HR records in the system. Please contact IT Support.</p></div>';
        include_once '../includes/footer.php';
        exit;
    } else {
        $_SESSION['form_error'] = "Invalid Employee ID.";
        header("Location: " . APP_BASE_PATH . "index.php?route=manage_employees");
        exit;
    }
}


// --- HANDLE POST: UPDATE PROFILE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_employee'])) {
    if (!$can_edit) {
        $_SESSION['form_error'] = "You only have permission to view employee records, not edit them.";
        header("Location: " . APP_BASE_PATH . "index.php?route=edit_employee&id=" . $employee_id);
        exit;
    }
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Security Validation Failed.");
    }
    
    $emp_id_code = trim($_POST['emp_id'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $date_of_joining = trim($_POST['date_of_joining'] ?? ''); 
    $employee_status = trim($_POST['employee_status'] ?? 'Active'); 
    
    $domain_username = trim($_POST['domain_username'] ?? ''); 
    $domain_password = trim($_POST['domain_password'] ?? ''); 
    $email_id = trim($_POST['email_id'] ?? ''); 

    $stmt = $conn->prepare("UPDATE employees SET emp_id=?, first_name=?, last_name=?, department=?, location=?, position=?, date_of_joining=?, employee_status=?, domain_username=?, domain_password=?, email_id=?, updated_at=NOW() WHERE id=?");
    if ($stmt) {
        $stmt->bind_param("sssssssssssi", $emp_id_code, $first_name, $last_name, $department, $location, $position, $date_of_joining, $employee_status, $domain_username, $domain_password, $email_id, $employee_id);
        if ($stmt->execute()) {
            $_SESSION['form_success'] = "Employee profile updated successfully.";
            if (function_exists('log_system_event')) {
                log_system_event($conn, 'Employee Management', "Updated employee ID: $employee_id", $current_user_id);
            }
        } else {
            $_SESSION['form_error'] = "Database Error: " . $stmt->error;
        }
        $stmt->close();
    }
    header("Location: " . APP_BASE_PATH . "index.php?route=edit_employee&id=" . $employee_id);
    exit;
}

// --- HANDLE POST: UPDATE DEPARTMENT PREFERENCES ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_preferences'])) {
    if (!$can_set_preferences) {
        $_SESSION['form_error'] = "You do not have permission to set department preferences.";
    } else {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            die("Security Validation Failed.");
        }
        
        $target_user_id = (int)($_POST['target_user_id'] ?? 0);
        $selected_depts = $_POST['preferred_departments'] ?? [];
        $json_depts = json_encode($selected_depts);
        
        if ($target_user_id > 0) {
            try {
                $stmt_upd = $conn->prepare("UPDATE users SET preferred_departments = ? WHERE id = ?");
                if ($stmt_upd) {
                    $stmt_upd->bind_param("si", $json_depts, $target_user_id);
                    $stmt_upd->execute();
                    $stmt_upd->close();
                    
                    // If the updated preferences belong to the actively logged-in user, refresh their session immediately
                    if ($target_user_id === $current_user_id) {
                        $_SESSION['preferred_departments'] = $selected_depts;
                    }
                    
                    $_SESSION['form_success'] = "Department visibility preferences updated successfully.";
                }
            } catch (Exception $e) {
                $_SESSION['form_error'] = "Database Error: Please ensure you have run the ALTER TABLE command to add the 'preferred_departments' column.";
            }
        } else {
            $_SESSION['form_error'] = "Cannot save preferences: Invalid user account.";
        }
    }
    header("Location: " . APP_BASE_PATH . "index.php?route=edit_employee&id=" . $employee_id . "&tab=preferences");
    exit;
}

// --- FETCH EMPLOYEE DATA ---
$stmt = $conn->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$emp = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$emp) {
    if ($is_basic_employee) {
        $pageTitle = "Profile Not Found";
        include_once '../includes/header.php';
        echo '<div class="container text-center mt-5 pt-5"><i data-feather="user-x" style="width: 64px; height: 64px; color: #64748b; margin-bottom: 20px;"></i><h3 class="fw-bold text-dark">Profile Not Found</h3><p class="text-muted">The requested profile could not be loaded.</p></div>';
        include_once '../includes/footer.php';
        exit;
    } else {
        $_SESSION['form_error'] = "Employee not found.";
        header("Location: " . APP_BASE_PATH . "index.php?route=manage_employees");
        exit;
    }
}

$full_name = trim($emp['first_name'] . ' ' . $emp['last_name']);

// --- FETCH ASSOCIATED USER RECORD FOR PREFERENCES ---
$emp_user_id = null;
$pref_depts = [];
$missing_column_error = false;

if ($can_set_preferences && !empty($emp['domain_username'])) {
    try {
        $stmt_u = $conn->prepare("SELECT id, preferred_departments FROM users WHERE LOWER(username) = LOWER(?)");
        if ($stmt_u) {
            $stmt_u->bind_param("s", $emp['domain_username']);
            $stmt_u->execute();
            $res_u = $stmt_u->get_result();
            if ($row_u = $res_u->fetch_assoc()) {
                $emp_user_id = (int)$row_u['id'];
                $pref_depts = json_decode($row_u['preferred_departments'] ?? '[]', true) ?: [];
            }
            $stmt_u->close();
        }
    } catch (Exception $e) {
        // If the query fails, it's highly likely the column is missing in the database.
        $missing_column_error = true;
    }
}

// --- FETCH SYSTEM READINESS CHECKLISTS ---
$checklists = [];
$stmt_chk = $conn->prepare("
    SELECT id, created_at, overall_status, hostname, serial_number, system_model, processor_ram_hdd_ssd, operating_system, ms_office_version, user_acceptance_status, user_acceptance_date, user_acceptance_ip, user_acceptance_by, induction_it_remarks, induction_hr_remarks, induction_facility_remarks 
    FROM checklists 
    WHERE employee_id = ? AND checklist_type = 'system_readiness' 
    ORDER BY id DESC
");
$stmt_chk->bind_param("i", $employee_id);
$stmt_chk->execute();
$res_chk = $stmt_chk->get_result();
while ($row = $res_chk->fetch_assoc()) {
    $checklists[] = $row;
}
$stmt_chk->close();

$recent_checklist = $checklists[0] ?? null;
$previous_checklists = array_slice($checklists, 1);

// Default Tab Logic
$active_tab = $_GET['tab'] ?? 'details';

// Fetch Dropdowns for the Form
$departments = [];
$dept_result = $conn->query("SELECT name FROM departments ORDER BY name ASC");
if ($dept_result) { while ($row = $dept_result->fetch_assoc()) { $departments[] = $row['name']; } }

$locations = [];
$loc_result = $conn->query("SELECT name FROM locations ORDER BY name ASC");
if ($loc_result) { while ($row = $loc_result->fetch_assoc()) { $locations[] = $row['name']; } }

$pageTitle = "Employee Profile: " . htmlspecialchars($full_name);
include_once '../includes/header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    :root {
        --primary-color: #0f766e;
        --primary-hover: #0d9488;
        --bg-color: #f8fafc;
        --card-bg: #ffffff;
        --text-main: #334155;
        --text-light: #64748b;
        --border-color: #e2e8f0;
        --radius: 12px;
        --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
    }
    
    body { font-family: 'Inter', sans-serif; background-color: var(--bg-color) !important; color: var(--text-main); font-size: 0.875rem; padding-bottom: 60px; }
    h1, h2, h3, h4, h5, h6 { font-weight: 600; color: #1e293b; }
    
    .modern-card { background: var(--card-bg); border-radius: var(--radius); box-shadow: var(--card-shadow); border: 1px solid var(--border-color); overflow: hidden; margin-bottom: 24px; }
    .modern-card-header { padding: 16px 20px; background: #ffffff; border-bottom: 1px solid var(--border-color); font-weight: 600; color: var(--primary-color); display: flex; align-items: center; gap: 8px; font-size: 0.95rem; }
    .modern-card-body { padding: 24px; }
    
    .form-label { font-weight: 600; font-size: 0.75rem; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.02em; margin-bottom: 6px; }
    .form-control, .form-select { border-radius: 8px; padding: 10px 14px; font-size: 0.875rem; border: 1px solid #cbd5e1; transition: all 0.2s; }
    .form-control:focus, .form-select:focus { border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1); }
    .form-control:read-only { background-color: #f1f5f9; color: #475569; }

    .btn-primary-modern { background-color: var(--primary-color); color: white; border: none; border-radius: 20px; padding: 8px 24px; font-weight: 600; font-size: 0.875rem; transition: all 0.2s; }
    .btn-primary-modern:hover { background-color: var(--primary-hover); transform: translateY(-1px); color: white;}

    /* COMPACT Profile Header */
    .profile-header { background: linear-gradient(135deg, var(--primary-color) 0%, #115e59 100%); color: white; padding: 20px 30px; border-radius: var(--radius) var(--radius) 0 0; }
    .profile-avatar { width: 60px; height: 60px; background: rgba(255,255,255,0.2); border: 2px solid rgba(255,255,255,0.4); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; font-weight: 700; color: white; }
    
    /* Modern Tabs */
    .nav-tabs-modern { border-bottom: 1px solid var(--border-color); background: #ffffff; padding: 0 20px; }
    .nav-tabs-modern .nav-link { color: var(--text-light); border: none; border-bottom: 3px solid transparent; font-weight: 600; padding: 14px 18px; transition: all 0.2s; border-radius: 0; background: transparent; font-size: 0.85rem; }
    .nav-tabs-modern .nav-link:hover { color: var(--primary-color); }
    .nav-tabs-modern .nav-link.active { color: var(--primary-color); border-bottom: 3px solid var(--primary-color); }

    /* Info Grid for System Details */
    .info-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 20px; }
    .info-item { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; }
    .info-label { font-size: 0.75rem; font-weight: 600; color: var(--text-light); text-transform: uppercase; margin-bottom: 4px; }
    .info-value { font-size: 0.95rem; font-weight: 600; color: var(--text-main); word-break: break-word; }

    /* Status Badge */
    .badge-status { font-size: 0.75rem; padding: 4px 10px; border-radius: 20px; font-weight: 600; letter-spacing: 0.02em; text-transform: uppercase; }
    
    /* Table for details */
    .detail-table { width: 100%; border-collapse: separate; border-spacing: 0; }
    .detail-table th, .detail-table td { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; font-size: 0.875rem; }
    .detail-table th { font-weight: 600; color: var(--text-light); width: 35%; background: #f8fafc; border-right: 1px solid #f1f5f9; }
    .detail-table td { color: var(--text-main); font-weight: 500; }
    .detail-table tr:last-child th, .detail-table tr:last-child td { border-bottom: none; }

    /* Custom Checkbox Cards for Preferences */
    .custom-checkbox-card { transition: all 0.2s; position: relative; user-select: none; }
    .custom-checkbox-card:hover { border-color: var(--primary-color) !important; background-color: #f0fdfa; }
    .custom-checkbox-card:has(input:checked) { 
        border-color: var(--primary-color) !important; 
        background-color: #f0fdfa; 
        box-shadow: 0 0 0 1px var(--primary-color) !important; 
    }
</style>

<div class="container-xl pt-4 pb-5">
    
    <?php if (isset($_SESSION['form_success'])): ?>
        <div class="alert alert-success shadow-sm border-0 rounded-3 d-flex align-items-start gap-3 mb-4">
            <i data-feather="check-circle" class="mt-1 flex-shrink-0"></i>
            <div><?php echo htmlspecialchars($_SESSION['form_success']); unset($_SESSION['form_success']); ?></div>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['form_error'])): ?>
        <div class="alert alert-danger shadow-sm border-0 rounded-3 d-flex align-items-start gap-3 mb-4">
            <i data-feather="alert-circle" class="mt-1 flex-shrink-0"></i>
            <div><?php echo htmlspecialchars($_SESSION['form_error']); unset($_SESSION['form_error']); ?></div>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <?php if ($can_view || can('manage_employees')): ?>
            <a href="<?= APP_BASE_PATH ?>index.php?route=manage_employees" class="text-decoration-none text-muted small fw-bold d-inline-flex align-items-center transition-colors hover-primary mb-2">
                <i data-feather="arrow-left" style="width:14px;" class="me-1"></i> BACK TO DIRECTORY
            </a>
            <?php endif; ?>
        </div>
        
        <?php if ($can_manage): ?>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-danger d-flex align-items-center shadow-sm rounded-pill fw-bold" data-bs-toggle="modal" data-bs-target="#deleteEmployeeModal" style="font-size: 0.85rem;">
                <i data-feather="trash-2" style="width:14px;" class="me-2"></i> Delete Record
            </button>
        </div>
        <?php endif; ?>
    </div>

    <div class="modern-card shadow-sm">
        
        <div class="profile-header d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="profile-avatar shadow-sm">
                    <?php echo strtoupper(substr($emp['first_name'] ?? '', 0, 1) . substr($emp['last_name'] ?? '', 0, 1)); ?>
                </div>
                <div>
                    <h4 class="fw-bold mb-1 d-flex align-items-center gap-2">
                        <?php echo htmlspecialchars($full_name); ?>
                        <?php if(($emp['employee_status'] ?? '') === 'Active'): ?>
                            <span class="badge bg-success text-white border border-light badge-status">Active</span>
                        <?php else: ?>
                            <span class="badge bg-danger text-white border border-light badge-status">Inactive</span>
                        <?php endif; ?>
                    </h4>
                    <div class="opacity-75 d-flex flex-wrap gap-3 mt-1" style="font-size: 0.85rem;">
                        <span><i data-feather="hash" style="width:12px; margin-top:-2px;" class="me-1"></i> <?php echo htmlspecialchars($emp['emp_id'] ?? 'N/A'); ?></span>
                        <span><i data-feather="briefcase" style="width:12px; margin-top:-2px;" class="me-1"></i> <?php echo htmlspecialchars($emp['department'] ?? 'N/A'); ?></span>
                        <span><i data-feather="mail" style="width:12px; margin-top:-2px;" class="me-1"></i> <?php echo htmlspecialchars($emp['email_id'] ?? 'N/A'); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <ul class="nav nav-tabs-modern flex-nowrap overflow-auto" id="profileTabs" role="tablist" style="scrollbar-width: none;">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $active_tab === 'details' ? 'active' : ''; ?> d-flex align-items-center" id="details-tab" data-bs-toggle="tab" data-bs-target="#details-pane" type="button">
                    <i data-feather="user" style="width:14px;" class="me-2"></i> User Profile
                </button>
            </li>
            
            <?php if ($can_set_preferences): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $active_tab === 'preferences' ? 'active' : ''; ?> d-flex align-items-center" id="preferences-tab" data-bs-toggle="tab" data-bs-target="#preferences-pane" type="button">
                    <i data-feather="sliders" style="width:14px;" class="me-2"></i> Dept Preferences
                </button>
            </li>
            <?php endif; ?>

            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $active_tab === 'system' ? 'active' : ''; ?> d-flex align-items-center" id="system-tab" data-bs-toggle="tab" data-bs-target="#system-pane" type="button">
                    <i data-feather="monitor" style="width:14px;" class="me-2"></i> System Details
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $active_tab === 'acceptance' ? 'active' : ''; ?> d-flex align-items-center" id="acceptance-tab" data-bs-toggle="tab" data-bs-target="#acceptance-pane" type="button">
                    <i data-feather="file-text" style="width:14px;" class="me-2"></i> Acceptance Form
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $active_tab === 'induction' ? 'active' : ''; ?> d-flex align-items-center" id="induction-tab" data-bs-toggle="tab" data-bs-target="#induction-pane" type="button">
                    <i data-feather="check-square" style="width:14px;" class="me-2"></i> Induction Details
                </button>
            </li>
        </ul>

        <div class="tab-content p-4">
            
            <div class="tab-pane fade <?php echo $active_tab === 'details' ? 'show active' : ''; ?>" id="details-pane" role="tabpanel">
                <form method="POST" action="<?= APP_BASE_PATH ?>index.php?route=edit_employee&id=<?php echo $employee_id; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="update_employee" value="1">
                    
                    <fieldset <?php if (!$can_edit) echo 'disabled'; ?>>
                        <div class="row g-4">
                            <div class="col-12"><h6 class="fw-bold text-dark border-bottom pb-2 mb-0">Basic Details</h6></div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Employee ID <span class="text-danger">*</span></label>
                                <input type="text" class="form-control bg-light font-monospace fw-bold" name="emp_id" value="<?php echo htmlspecialchars($emp['emp_id'] ?? ''); ?>" required readonly>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="first_name" value="<?php echo htmlspecialchars($emp['first_name'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="last_name" value="<?php echo htmlspecialchars($emp['last_name'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Department</label>
                                <select class="form-select" name="department">
                                    <option value="">Select...</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo htmlspecialchars($dept); ?>" <?php if (($emp['department'] ?? '') === $dept) echo 'selected'; ?>><?php echo htmlspecialchars($dept); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Position / Job Title</label>
                                <input type="text" class="form-control" name="position" value="<?php echo htmlspecialchars($emp['position'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Location</label>
                                <select class="form-select" name="location">
                                    <option value="">Select...</option>
                                    <?php foreach ($locations as $loc): ?>
                                        <option value="<?php echo htmlspecialchars($loc); ?>" <?php if (($emp['location'] ?? '') === $loc) echo 'selected'; ?>><?php echo htmlspecialchars($loc); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Date of Joining</label>
                                <input type="date" class="form-control" name="date_of_joining" value="<?php echo htmlspecialchars($emp['date_of_joining'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Current Status</label>
                                <select class="form-select fw-bold <?php echo ($emp['employee_status'] ?? '') === 'Active' ? 'text-success' : 'text-danger'; ?>" name="employee_status">
                                    <option value="Active" class="text-success" <?php if (($emp['employee_status'] ?? '') === 'Active') echo 'selected'; ?>>Active</option>
                                    <option value="Inactive" class="text-danger" <?php if (($emp['employee_status'] ?? '') === 'Inactive') echo 'selected'; ?>>Inactive / Resigned</option>
                                    <option value="Not Joined" class="text-danger" <?php if (($emp['employee_status'] ?? '') === 'Inactive') echo 'selected'; ?>>Not Joined</option>
                                </select>
                            </div>

                            <div class="col-12 mt-4"><h6 class="fw-bold text-dark border-bottom pb-2 mb-0">Domain Details</h6></div>

                            <div class="col-md-4">
                                <label class="form-label">Domain Username</label>
                                <input type="text" class="form-control font-monospace text-primary" name="domain_username" value="<?php echo htmlspecialchars($emp['domain_username'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">
    				Domain Password <span class="text-danger">*</span> 
    				<span class="text-muted fw-normal" style="text-transform: none; letter-spacing: normal; font-size: 0.65rem;">(User has to change on next logon)</span>
				</label>                                
				<input type="text" class="form-control font-monospace" name="domain_password" value="<?php echo htmlspecialchars($emp['domain_password'] ?? ''); ?>" placeholder="Enter default password...">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Email ID</label>
                                <input type="email" class="form-control" name="email_id" value="<?php echo htmlspecialchars($emp['email_id'] ?? ''); ?>">
                            </div>
                        </div>

                        <?php if ($can_edit): ?>
                        <div class="mt-5 pt-3 border-top text-end">
                            <button type="submit" class="btn btn-primary-modern shadow-sm d-inline-flex align-items-center">
                                <i data-feather="save" class="me-2" style="width:16px;"></i> Save Profile Changes
                            </button>
                        </div>
                        <?php endif; ?>
                        
                    </fieldset>
                    <?php if (!$can_edit): ?>
                        <div class="mt-4 pt-3 border-top text-end">
                            <span class="text-muted small"><i data-feather="lock" style="width:14px;" class="me-1"></i> You have read-only access to this profile.</span>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
            
            <?php if ($can_set_preferences): ?>
            <div class="tab-pane fade <?php echo $active_tab === 'preferences' ? 'show active' : ''; ?>" id="preferences-pane" role="tabpanel">
                <div class="mb-4 border-bottom pb-3">
                    <h5 class="fw-bold m-0 text-dark d-flex align-items-center">
                        <i data-feather="sliders" class="me-2 text-primary"></i> Department Visibility Preferences
                    </h5>
                    <p class="text-muted mt-2 mb-0" style="font-size: 0.85rem;">Select the departments you want this user to see by default across the Dashboard, Templates, System Readiness, and Exit Checklists. If none are selected, all departments will be visible.</p>
                </div>
                
                <?php if ($missing_column_error): ?>
                    <div class="alert alert-danger border border-danger shadow-sm rounded-3">
                        <div class="d-flex align-items-center mb-2">
                            <i data-feather="database" class="me-2 text-danger"></i>
                            <strong class="text-dark">Database Update Required</strong>
                        </div>
                        <p class="mb-0 small text-dark">The department preferences feature requires a database update. Please run the following SQL command in your database manager:</p>
                        <code class="d-block bg-white p-2 rounded mt-2 text-danger font-monospace border">ALTER TABLE users ADD COLUMN preferred_departments TEXT DEFAULT NULL;</code>
                    </div>
                <?php elseif (empty($emp['domain_username']) || empty($emp_user_id)): ?>
                    <div class="alert alert-warning border border-warning-subtle shadow-sm rounded-3">
                        <div class="d-flex align-items-center mb-2">
                            <i data-feather="alert-circle" class="me-2 text-warning"></i>
                            <strong class="text-dark">User Account Missing</strong>
                        </div>
                        <p class="mb-0 small text-muted">Cannot set preferences. This employee record is not linked to an active portal user account. Ensure the <strong>Domain Username</strong> on the User Profile tab matches an active user in the system.</p>
                    </div>
                <?php else: ?>
                    <form method="POST" action="<?= APP_BASE_PATH ?>index.php?route=edit_employee&id=<?php echo $employee_id; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="update_preferences" value="1">
                        <input type="hidden" name="target_user_id" value="<?php echo $emp_user_id; ?>">
                        
                        <div class="d-flex justify-content-end mb-3 gap-2">
                            <button type="button" class="btn btn-sm btn-light border rounded-pill text-muted fw-bold shadow-sm" style="font-size: 0.75rem;" onclick="document.querySelectorAll('.dept-pref-checkbox').forEach(el => el.checked = true);">Select All</button>
                            <button type="button" class="btn btn-sm btn-light border rounded-pill text-muted fw-bold shadow-sm" style="font-size: 0.75rem;" onclick="document.querySelectorAll('.dept-pref-checkbox').forEach(el => el.checked = false);">Clear All</button>
                        </div>
                        
                        <div class="row g-3 mb-4">
                            <?php foreach ($departments as $dept): ?>
                                <div class="col-md-4 col-lg-3">
                                    <div class="form-check custom-checkbox-card p-3 border rounded shadow-sm">
                                        <input class="form-check-input ms-1 mt-1 dept-pref-checkbox" type="checkbox" name="preferred_departments[]" value="<?php echo htmlspecialchars($dept); ?>" id="pref_dept_<?php echo md5($dept); ?>" <?php echo in_array($dept, $pref_depts) ? 'checked' : ''; ?>>
                                        <label class="form-check-label ms-2 fw-medium text-dark stretched-link" style="cursor:pointer;" for="pref_dept_<?php echo md5($dept); ?>">
                                            <?php echo htmlspecialchars($dept); ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="border-top pt-3 text-end">
                            <button type="submit" class="btn btn-primary-modern shadow-sm d-inline-flex align-items-center">
                                <i data-feather="save" class="me-2" style="width:16px;"></i> Save Preferences
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="tab-pane fade <?php echo $active_tab === 'system' ? 'show active' : ''; ?>" id="system-pane" role="tabpanel">
                <?php if (empty($recent_checklist)): ?>
                    <div class="text-center py-5 text-muted">
                        <i data-feather="monitor" style="width:48px; height:48px; opacity:0.2;" class="mb-3"></i>
                        <h5 class="fw-bold text-dark">No System Data Found</h5>
                        <p>This employee does not have a System Readiness Checklist assigned yet.</p>
                    </div>
                <?php elseif ($recent_checklist['overall_status'] !== 'Completed'): ?>
                    <div class="text-center py-5 text-muted">
                        <i data-feather="clock" style="width:48px; height:48px; opacity:0.2;" class="mb-3 text-warning"></i>
                        <h5 class="fw-bold text-dark">Deployment Pending</h5>
                        <p>Hardware specifications will be visible once the current System Readiness Checklist is fully completed.</p>
                        <?php if ($can_view_system): ?>
                            <a href="<?= APP_BASE_PATH ?>index.php?route=system_readiness_checklist&checklist_id=<?php echo $recent_checklist['id']; ?>" class="btn btn-outline-primary rounded-pill px-4 mt-2">View Ongoing Checklist</a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="fw-bold m-0 text-dark d-flex align-items-center">
                            <i data-feather="cpu" class="me-2 text-primary"></i> Current Assigned Asset
                        </h5>
                        <?php if ($can_view_system): ?>
                            <a href="<?= APP_BASE_PATH ?>index.php?route=system_readiness_checklist&checklist_id=<?php echo $recent_checklist['id']; ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3">
                                View Full Checklist
                            </a>
                        <?php endif; ?>
                    </div>
                    
                    <div class="info-grid mb-5">
                        <div class="info-item"><div class="info-label">Hostname / Asset ID</div><div class="info-value text-primary font-monospace"><?php echo htmlspecialchars($recent_checklist['hostname'] ?? 'N/A'); ?></div></div>
                        <div class="info-item"><div class="info-label">Serial Number</div><div class="info-value font-monospace"><?php echo htmlspecialchars($recent_checklist['serial_number'] ?? 'N/A'); ?></div></div>
                        <div class="info-item"><div class="info-label">System Model</div><div class="info-value"><?php echo htmlspecialchars($recent_checklist['system_model'] ?? 'N/A'); ?></div></div>
                        <div class="info-item"><div class="info-label">Hardware Specs</div><div class="info-value"><?php echo htmlspecialchars($recent_checklist['processor_ram_hdd_ssd'] ?? 'N/A'); ?></div></div>
                        <div class="info-item"><div class="info-label">Operating System</div><div class="info-value"><?php echo htmlspecialchars($recent_checklist['operating_system'] ?? 'N/A'); ?></div></div>
                        <div class="info-item"><div class="info-label">MS Office Version</div><div class="info-value"><?php echo htmlspecialchars($recent_checklist['ms_office_version'] ?? 'N/A'); ?></div></div>
                        <div class="info-item"><div class="info-label">Deployed On</div><div class="info-value"><?php echo date('d M Y', strtotime($recent_checklist['created_at'])); ?></div></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($previous_checklists)): ?>
                    <h6 class="fw-bold text-muted border-bottom pb-2 mb-3 mt-4">Previous System Assignments (<?php echo count($previous_checklists); ?>)</h6>
                    <div class="accordion shadow-sm border rounded" id="previousSystemsAccordion">
                        <?php foreach ($previous_checklists as $index => $old_chk): 
                            $oldDate = !empty($old_chk['created_at']) ? date('M d, Y', strtotime($old_chk['created_at'])) : 'Unknown';
                        ?>
                            <div class="accordion-item border-0 border-bottom">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed bg-light fw-medium text-dark" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOld<?php echo $index; ?>">
                                        <i data-feather="archive" style="width:14px;" class="me-2 text-muted"></i> 
                                        Hostname: <?php echo htmlspecialchars($old_chk['hostname'] ?? 'Unknown'); ?> &mdash; <span class="text-muted ms-2 fw-normal small">Deployed: <?php echo $oldDate; ?></span>
                                    </button>
                                </h2>
                                <div id="collapseOld<?php echo $index; ?>" class="accordion-collapse collapse" data-bs-parent="#previousSystemsAccordion">
                                    <div class="accordion-body bg-white p-4">
                                        <div class="info-grid">
                                            <div class="info-item bg-transparent border-0 p-0"><div class="info-label">Hostname / Asset ID</div><div class="info-value text-primary font-monospace small"><?php echo htmlspecialchars($old_chk['hostname'] ?? 'N/A'); ?></div></div>
                                            <div class="info-item bg-transparent border-0 p-0"><div class="info-label">Serial Number</div><div class="info-value font-monospace small"><?php echo htmlspecialchars($old_chk['serial_number'] ?? 'N/A'); ?></div></div>
                                            <div class="info-item bg-transparent border-0 p-0"><div class="info-label">System Model</div><div class="info-value small"><?php echo htmlspecialchars($old_chk['system_model'] ?? 'N/A'); ?></div></div>
                                            <div class="info-item bg-transparent border-0 p-0"><div class="info-label">Hardware Specs</div><div class="info-value small"><?php echo htmlspecialchars($old_chk['processor_ram_hdd_ssd'] ?? 'N/A'); ?></div></div>
                                            <div class="info-item bg-transparent border-0 p-0"><div class="info-label">Operating System</div><div class="info-value small"><?php echo htmlspecialchars($old_chk['operating_system'] ?? 'N/A'); ?></div></div>
                                            <div class="info-item bg-transparent border-0 p-0"><div class="info-label">MS Office Version</div><div class="info-value small"><?php echo htmlspecialchars($old_chk['ms_office_version'] ?? 'N/A'); ?></div></div>
                                            
                                            <div class="info-item bg-transparent border-0 p-0">
                                                <div class="info-label">UAC Form</div>
                                                <div class="info-value">
                                                    <?php if (($old_chk['user_acceptance_status'] ?? '') === 'Accepted'): ?>
                                                        <a href="<?= APP_BASE_PATH ?>index.php?route=generate_acceptance_pdf&checklist_id=<?php echo $old_chk['id']; ?>" target="_blank" class="text-success small fw-bold"><i data-feather="download" style="width:14px;"></i> View PDF</a>
                                                    <?php else: ?>
                                                        <span class="text-warning small fw-bold">Pending</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <div class="info-item bg-transparent border-0 p-0">
                                                <div class="info-label">Action</div>
                                                <div class="info-value">
                                                    <?php if ($can_view_system): ?>
                                                        <a href="<?= APP_BASE_PATH ?>index.php?route=system_readiness_checklist&checklist_id=<?php echo $old_chk['id']; ?>" class="text-primary small fw-bold">View Full Record</a>
                                                    <?php else: ?>
                                                        <span class="text-muted small"><i data-feather="lock" style="width:12px;" class="me-1"></i>No Access</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="tab-pane fade <?php echo $active_tab === 'acceptance' ? 'show active' : ''; ?>" id="acceptance-pane" role="tabpanel">
                <?php if (empty($recent_checklist) || ($recent_checklist['overall_status'] ?? '') !== 'Completed'): ?>
                    <div class="text-center py-5 text-muted">
                        <i data-feather="file-minus" style="width:48px; height:48px; opacity:0.2;" class="mb-3"></i>
                        <h5 class="fw-bold text-dark">No Form Available</h5>
                        <p>A System Readiness deployment must be fully completed to generate an acceptance form.</p>
                    </div>
                <?php else: ?>
                    <div class="row justify-content-center pt-3">
                        <div class="col-md-8 col-lg-7">
                            <div class="card border-0 shadow-sm" style="background: #f8fafc; border-radius: 16px;">
                                <div class="card-body text-center p-5">
                                    
                                    <?php if (($recent_checklist['user_acceptance_status'] ?? '') === 'Accepted'): ?>
                                        <div class="bg-success-subtle text-success rounded-circle d-inline-flex align-items-center justify-content-center mb-3 shadow-sm border border-success-subtle" style="width: 64px; height: 64px;">
                                            <i data-feather="check-circle" style="width: 32px; height: 32px;"></i>
                                        </div>
                                        <h4 class="fw-bold text-dark mb-2">Form Signed & Accepted</h4>
                                        <div class="bg-white p-3 rounded border mb-4 text-start shadow-sm mx-auto" style="max-width: 350px;">
                                            <div class="d-flex justify-content-between mb-2 border-bottom pb-2">
                                                <span class="text-muted small fw-bold text-uppercase">Signed By:</span>
                                                <span class="text-dark fw-medium small"><?php echo htmlspecialchars($recent_checklist['user_acceptance_by'] ?? 'N/A'); ?></span>
                                            </div>
                                            <div class="d-flex justify-content-between mb-2 border-bottom pb-2">
                                                <span class="text-muted small fw-bold text-uppercase">Date:</span>
                                                <span class="text-dark fw-medium small"><?php echo !empty($recent_checklist['user_acceptance_date']) ? date('d M Y, h:i A', strtotime($recent_checklist['user_acceptance_date'])) : 'N/A'; ?></span>
                                            </div>
                                            <div class="d-flex justify-content-between">
                                                <span class="text-muted small fw-bold text-uppercase">IP Address:</span>
                                                <span class="text-dark fw-medium small font-monospace"><?php echo htmlspecialchars($recent_checklist['user_acceptance_ip'] ?? 'N/A'); ?></span>
                                            </div>
                                        </div>
                                        
                                        <a href="<?= APP_BASE_PATH ?>index.php?route=generate_acceptance_pdf&checklist_id=<?php echo $recent_checklist['id']; ?>" target="_blank" class="btn btn-outline-success rounded-pill px-4 fw-bold">
                                            <i data-feather="download" class="me-2" style="width:16px;"></i> View Read-Only PDF
                                        </a>
                                        
                                    <?php else: ?>
                                        <div class="bg-warning-subtle text-warning-emphasis rounded-circle d-inline-flex align-items-center justify-content-center mb-3 shadow-sm border border-warning-subtle" style="width: 64px; height: 64px;">
                                            <i data-feather="edit-3" style="width: 32px; height: 32px;"></i>
                                        </div>
                                        <h4 class="fw-bold text-dark mb-2">Pending Signature</h4>
                                        <p class="text-muted mb-4">The assigned hardware deployment has been completed. It is now waiting for the employee to review and accept the IT usage policy.</p>
                                        
                                        <?php if ($can_user_view && $is_own_profile): ?>
                                            <a href="<?= APP_BASE_PATH ?>index.php?route=user_acceptance&checklist_id=<?php echo $recent_checklist['id']; ?>" class="btn btn-primary-modern shadow-sm px-4 py-2" style="font-size: 0.95rem;">
                                                <i data-feather="external-link" class="me-2" style="width:18px;"></i> Open Acceptance Form
                                            </a>
                                        <?php else: ?>
                                            <div class="px-4 py-2 bg-white border rounded shadow-sm d-inline-block">
                                                <span class="text-muted small fw-medium d-flex align-items-center"><i data-feather="lock" style="width:14px;" class="me-2"></i> Waiting for the employee to log in and sign.</span>
                                            </div>
                                        <?php endif; ?>
                                        
                                    <?php endif; ?>
                                    
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="tab-pane fade <?php echo $active_tab === 'induction' ? 'show active' : ''; ?>" id="induction-pane" role="tabpanel">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold m-0 text-dark d-flex align-items-center">
                        <i data-feather="check-square" class="me-2 text-primary"></i> Induction Completion Status
                    </h5>
                </div>
                <div class="table-responsive border rounded bg-white">
                    <table class="detail-table mb-0" style="font-size: 0.875rem;">
                        <thead class="bg-light">
                            <tr>
                                <th style="width: 25%; font-weight: 600; color: #4b5563; text-transform: uppercase; font-size: 0.75rem;">Department</th>
                                <th style="width: 15%; font-weight: 600; color: #4b5563; text-transform: uppercase; font-size: 0.75rem;">Status</th>
                                <th style="font-weight: 600; color: #4b5563; text-transform: uppercase; font-size: 0.75rem;">Remarks / Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                                // Safe handling if $recent_checklist is empty
                                $it_rem = $recent_checklist ? ($recent_checklist['induction_it_remarks'] ?? '') : '';
                                $hr_rem = $recent_checklist ? ($recent_checklist['induction_hr_remarks'] ?? '') : '';
                                $fac_rem = $recent_checklist ? ($recent_checklist['induction_facility_remarks'] ?? '') : '';
                                
                                $inductions = [
                                    ['name' => 'IT Induction', 'status' => $emp['it_induction_status'] ?? 'Pending', 'remarks' => $it_rem],
                                    ['name' => 'HR Induction', 'status' => $emp['hr_induction_status'] ?? 'Pending', 'remarks' => $hr_rem],
                                    ['name' => 'Facility Induction', 'status' => $emp['facility_induction_status'] ?? 'Pending', 'remarks' => $fac_rem]
                                ];
                                
                                foreach ($inductions as $ind):
                                    $is_completed = (strtolower($ind['status'] ?? '') === 'completed');
                                    $badge_class = $is_completed ? 'bg-success-subtle text-success-emphasis border-success-subtle' : 'bg-warning-subtle text-warning-emphasis border-warning-subtle';
                                    $status_text = htmlspecialchars($ind['status'] ?: 'Pending');
                            ?>
                            <tr>
                                <td class="fw-bold text-dark align-middle"><i data-feather="corner-down-right" class="text-muted me-2" style="width:14px;"></i> <?php echo $ind['name']; ?></td>
                                <td class="align-middle"><span class="badge border rounded-pill px-3 <?php echo $badge_class; ?>"><?php echo $status_text; ?></span></td>
                                <td class="text-muted align-middle"><?php echo !empty($ind['remarks']) ? nl2br(htmlspecialchars($ind['remarks'])) : '<em class="opacity-50">No remarks recorded yet.</em>'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<?php if ($can_manage): ?>
<div class="modal fade" id="deleteEmployeeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; border-left: 4px solid #dc2626;">
            <div class="modal-header bg-white border-0 pb-0">
                <h5 class="modal-title fw-bold text-danger d-flex align-items-center">
                    <i data-feather="alert-triangle" class="me-2 text-danger" style="width:18px;"></i> Confirm Deletion
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-muted pt-3 pb-4 text-center">
                <p class="mb-0">Are you sure you want to permanently delete the record for <strong><?php echo htmlspecialchars(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? '')); ?></strong>?</p>
                <p class="small text-danger mt-2 mb-0">This action cannot be undone.</p>
            </div>
            <div class="modal-footer border-0 bg-light justify-content-center">
                <form method="POST" action="<?= APP_BASE_PATH ?>index.php?route=edit_employee&id=<?= $employee_id ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <button type="button" class="btn btn-light border px-4 rounded-pill fw-medium me-2" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger px-4 rounded-pill shadow-sm fw-bold">Yes, Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include_once '../includes/footer.php'; ?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof feather !== 'undefined') feather.replace();
    });
</script>