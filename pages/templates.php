<?php
// FILE: templates.php

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

include_once '../includes/db_connect.php';
include_once '../includes/auth.php';

checkPermission('view_templates_list');

// =========================================================================================
// --- STICKY FILTERS & PAGINATION LOGIC ---
// =========================================================================================
if (isset($_GET['reset_filters'])) {
    unset($_SESSION['templates_list_state']);
    header("Location: " . APP_BASE_PATH . "index.php?route=templates");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $has_filters = false;
    foreach ($_GET as $key => $val) {
        if ($key !== 'route' && $key !== 'reset_filters') { $has_filters = true; break; }
    }

    $current_uri = $_SERVER['REQUEST_URI'];
    $saved_uri = $_SESSION['templates_list_state'] ?? '';

    // If navigating to base URL and we have a saved state that is DIFFERENT, restore it
    if (!$has_filters && !empty($saved_uri) && $saved_uri !== $current_uri) {
        header("Location: " . $saved_uri);
        exit();
    }

    // Only save the state if the user actually applied filters or changed a page
    if ($has_filters) {
        $_SESSION['templates_list_state'] = $current_uri;
    }
}
// =========================================================================================

// --- HELPER FUNCTION FOR ACTIVITY LOGGING ---
if (!function_exists('log_template_activity')) {
    function log_template_activity($conn, $joiner_id, $performed_by, $action_description) {
        $action_type = 'Template Management';
        $sql = "INSERT INTO action_logs (joiner_id, performed_by, action_type, action_description) VALUES (?, ?, ?, ?)";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("isss", $joiner_id, $performed_by, $action_type, $action_description);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('log_system_event')) {
    function log_system_event($conn, $type, $description) {
        // Placeholder
    }
}

$search_term = '';
$employees = [];
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

// --- AJAX Delete Handling ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete_joiner') {
    $response = ['success' => false, 'message' => 'An unknown error occurred.'];
    header('Content-Type: application/json');

    // CSRF Validation Check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $response['message'] = 'Security validation failed.';
        echo json_encode($response);
        exit();
    }

    if (!can('delete_template')) {
        $response['message'] = 'You do not have permission to delete records.';
        echo json_encode($response);
        exit();
    }

    $employee_id_to_delete = $_POST['delete_id'];
    $conn->begin_transaction();
    try {
        $stmt_fetch_emp = $conn->prepare("SELECT emp_id, first_name, last_name FROM employees WHERE id = ?");
        $stmt_fetch_emp->bind_param("i", $employee_id_to_delete);
        $stmt_fetch_emp->execute();
        $emp_result = $stmt_fetch_emp->get_result();
        $employee_to_log = $emp_result->fetch_assoc();
        $stmt_fetch_emp->close();

        $stmt_find = $conn->prepare("SELECT id FROM checklists WHERE employee_id = ?");
        $stmt_find->bind_param("i", $employee_id_to_delete);
        $stmt_find->execute();
        $result = $stmt_find->get_result();
        $checklist_ids = [];
        while ($row = $result->fetch_assoc()) { $checklist_ids[] = $row['id']; }
        $stmt_find->close();

        if (!empty($checklist_ids)) {
            $placeholders = implode(',', array_fill(0, count($checklist_ids), '?'));
            $stmt_items = $conn->prepare("DELETE FROM checklist_items WHERE template_id IN ($placeholders)");
            $stmt_items->bind_param(str_repeat('i', count($checklist_ids)), ...$checklist_ids);
            $stmt_items->execute();
            $stmt_items->close();

            $stmt_checklists = $conn->prepare("DELETE FROM checklists WHERE employee_id = ?");
            $stmt_checklists->bind_param("i", $employee_id_to_delete);
            $stmt_checklists->execute();
            $stmt_checklists->close();
        }
        
        $stmt_employee = $conn->prepare("DELETE FROM employees WHERE id = ?");
        $stmt_employee->bind_param("i", $employee_id_to_delete);
        $stmt_employee->execute();
        
        if ($employee_to_log) {
            $emp_full_name = trim($employee_to_log['first_name'] . ' ' . $employee_to_log['last_name']);
            $log_description = "Deleted onboarding template for {$emp_full_name} ({$employee_to_log['emp_id']}).";
            log_system_event($conn, 'Template Management', $log_description);
        }
        
        $conn->commit();
        $response = ['success' => true, 'message' => 'Record deleted successfully.'];
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        $response['message'] = "Error deleting record: " . $e->getMessage();
    }
    
    echo json_encode($response);
    exit();
}

// --- Data Fetching ---
$new_requests_count_stmt = $conn->prepare("SELECT COUNT(id) FROM employees WHERE template_status = 'New Request' AND employee_type != 'existing_employee'");
$new_requests_count_stmt->execute();
$new_requests_count = $new_requests_count_stmt->get_result()->fetch_row()[0];
$new_requests_count_stmt->close();

$records_per_page = 15;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;
$status_filter = $_GET['status_filter'] ?? 'approved';
$sr_filter = $_GET['sr_filter'] ?? 'all';
$it_filter = $_GET['it_filter'] ?? 'all';
$hr_filter = $_GET['hr_filter'] ?? 'all';
$facility_filter = $_GET['facility_filter'] ?? 'all';
$emp_type_filter = $_GET['employee_type'] ?? 'all';
$emp_status_filter = $_GET['employee_status'] ?? 'all';

// --- Department Checkbox Array Handling (With User Preference Fallback) ---
if (isset($_GET['department_filter'])) {
    $dept_filter_raw = $_GET['department_filter']; 
} elseif (!empty($_SESSION['preferred_departments'])) {
    $dept_filter_raw = $_SESSION['preferred_departments'];
} else {
    $dept_filter_raw = [];
}

$dept_filter_array = is_array($dept_filter_raw) ? $dept_filter_raw : [$dept_filter_raw];
// Remove "all" or empty values from the array
$dept_filter_array = array_filter($dept_filter_array, function($v) { return $v !== 'all' && trim($v) !== ''; });

$base_sql = "FROM employees AS e 
              LEFT JOIN (
                  SELECT employee_id, MAX(id) AS latest_checklist_id 
                  FROM checklists WHERE checklist_type = 'system_readiness' 
                  GROUP BY employee_id
              ) AS latest_c_info ON e.id = latest_c_info.employee_id
              LEFT JOIN checklists AS c ON latest_c_info.latest_checklist_id = c.id
              LEFT JOIN gatepass_requests AS g ON c.gatepass_id = g.id";

$params = []; $types = ''; $sql_where_clause = [];
if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
    $search_term = trim($_GET['search']);
    $sql_where_clause[] = "(e.emp_id LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ?)";
    $search_param = "%" . $search_term . "%";
    array_push($params, $search_param, $search_param, $search_param);
    $types .= "sss";
}

if ($status_filter === 'new') { 
    $sql_where_clause[] = "e.template_status = ?"; $params[] = 'New Request'; $types .= "s"; 
    $sql_where_clause[] = "e.employee_type != 'existing_employee'";
} else { 
    $sql_where_clause[] = "e.template_status = ?"; $params[] = 'Approved'; $types .= "s"; 
}

if ($sr_filter !== 'all') { if ($sr_filter === 'not_started') { $sql_where_clause[] = "(c.overall_status IS NULL OR c.overall_status = 'Not Started')"; } else { $sql_where_clause[] = "c.overall_status = ?"; $params[] = $sr_filter; $types .= "s"; } }
if ($it_filter !== 'all') { $sql_where_clause[] = "e.it_induction_status = ?"; $params[] = $it_filter; $types .= "s"; }
if ($hr_filter !== 'all') { $sql_where_clause[] = "e.hr_induction_status = ?"; $params[] = $hr_filter; $types .= "s"; }
if ($facility_filter !== 'all') { $sql_where_clause[] = "e.facility_induction_status = ?"; $params[] = $facility_filter; $types .= "s"; }
if ($emp_type_filter !== 'all') { $sql_where_clause[] = "e.employee_type = ?"; $params[] = $emp_type_filter; $types .= "s"; }
if ($emp_status_filter !== 'all') { $sql_where_clause[] = "e.employee_status = ?"; $params[] = $emp_status_filter; $types .= "s"; }

// --- Apply dynamic IN clause for Department Checkboxes ---
if (!empty($dept_filter_array)) {
    $placeholders = implode(',', array_fill(0, count($dept_filter_array), '?'));
    $sql_where_clause[] = "e.department IN ($placeholders)";
    foreach ($dept_filter_array as $dept_val) {
        $params[] = $dept_val;
        $types .= "s";
    }
}

$where_sql = !empty($sql_where_clause) ? " WHERE " . implode(" AND ", $sql_where_clause) : "";
$count_sql = "SELECT COUNT(DISTINCT e.id) " . $base_sql . $where_sql;
$count_stmt = $conn->prepare($count_sql);
if ($count_stmt) { if (!empty($params)) { $count_stmt->bind_param($types, ...$params); } $count_stmt->execute(); $total_records = $count_stmt->get_result()->fetch_row()[0]; $count_stmt->close(); } else { $total_records = 0; }
$total_pages = ceil($total_records / $records_per_page);

$sql = "SELECT e.id, e.emp_id, e.first_name, e.last_name, e.date_of_joining, 
               e.department, e.employee_status, e.requested_at, e.it_induction_status, e.hr_induction_status, 
               e.facility_induction_status, e.requested_by, e.created_by, e.created_at, e.template_status as status,
               c.id as system_readiness_checklist_id, c.overall_status AS system_readiness_status,
               c.gatepass_id, g.status as gatepass_status
        " . $base_sql . $where_sql . " GROUP BY e.id ORDER BY e.emp_id DESC LIMIT ? OFFSET ?";

$params[] = $records_per_page; $params[] = $offset; $types .= "ii";
$stmt = $conn->prepare($sql);
if ($stmt) { if (!empty($params)) { $stmt->bind_param($types, ...$params); } $stmt->execute(); $result = $stmt->get_result(); while ($row = $result->fetch_assoc()) { $employees[] = $row; } $stmt->close(); }

// --- Fetch Unique Departments for Checkboxes ---
$departments = [];
$dept_res = $conn->query("SELECT name FROM departments ORDER BY name ASC");
if ($dept_res) {
    while ($d_row = $dept_res->fetch_assoc()) {
        $d_name = $d_row['name'];
        if (empty($_SESSION['preferred_departments']) || in_array($d_name, $_SESSION['preferred_departments'])) {
            $departments[] = $d_name;
        }
    }
}

$pageTitle = "Templates";

function generateFilterUrl($param_to_change, $next_value) {
    $params = $_GET;
    $params['route'] = 'templates'; // Force route parameter for clean URL bypass
    $params[$param_to_change] = $next_value;
    if($param_to_change !== 'page') { unset($params['page']); }
    return APP_BASE_PATH . 'index.php?' . http_build_query($params);
}

include_once '../includes/header.php';

// --- Permissions & Flags ---
$show_add_joiner_button = can('request_template') || can('create_template');
$can_delete = can('delete_template');

// Column Visibility
$show_requested_by = can('view_column_requested_by'); 
$show_created_by   = can('view_column_created_by');   
$show_system_readiness = can('view_column_system_readiness');
$show_it_induction = can('view_column_it_induction');
$show_hr_induction = can('view_column_hr_induction');
$show_facility_induction = can('view_column_facility_induction');

// Download Permissions
$can_download_template = can('download_template_pdf');
$can_download_checklist = can('download_checklist_pdf');
$can_download_gatepass = can('download_gatepass_pdf');
$can_download_acceptance = can('download_user_acceptance_pdf'); 

// Calculate Colspan
$colspan = 5; 
if ($show_requested_by) $colspan++;
if ($show_created_by) $colspan++;
if ($show_system_readiness) $colspan++;
if ($show_it_induction) $colspan++;
if ($show_hr_induction) $colspan++;
if ($show_facility_induction) $colspan++;
$colspan++; 

$sr_states = ['all' => 'All Statuses', 'not_started' => 'Not Started', 'Preparing' => 'Preparing', 'Verification Pending' => 'Pending Approval', 'Completed' => 'Completed'];
$induction_states = ['all' => 'All Statuses', 'Pending' => 'Pending', 'Completed' => 'Completed'];
$emp_states = [
    'all' => 'All Statuses', 
    'Yet to Join' => 'Yet to Join', 
    'Active' => 'Active', 
    'Not Joined' => 'Not Joined', 
    'Inactive' => 'Inactive', 
    'Resigned' => 'Resigned'
];
?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
    :root { --primary-color: #0f766e; --bg-color: #f3f4f6; --card-bg: #ffffff; --border-color: #e5e7eb; --radius: 12px; }
    body { font-family: 'Inter', sans-serif; background-color: var(--bg-color) !important; color: #1f2937; min-height: 100vh; display: flex; flex-direction: column; }
    .main-content-wrapper { flex: 1; } 
    .pro-card { background: var(--card-bg); border-radius: var(--radius); box-shadow: 0 2px 4px rgba(0,0,0,0.05); border: 1px solid var(--border-color); margin-bottom: 24px; }
    .table-responsive { overflow: visible !important; } /* Fix for dropdown clipping */
    .nav-tabs { border-bottom: 1px solid var(--border-color); gap: 8px; }
    .nav-tabs .nav-link { color: #4b5563; border: none; border-bottom: 2px solid transparent; font-weight: 500; padding: 8px 14px; transition: all 0.2s; background: none; font-size: 0.9rem; }
    .nav-tabs .nav-link:hover { color: var(--primary-color); }
    .nav-tabs .nav-link.active { color: var(--primary-color); border-bottom: 2px solid var(--primary-color); background: none; font-weight: 600; }
    .table th { font-weight: 600; color: #4b5563; border-bottom: 1px solid #e5e7eb; background: #f9fafb; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; padding: 10px 12px; }
    .table td { padding: 10px 12px; vertical-align: middle; font-size: 0.8rem; color: #374151; border-bottom: 1px solid #f3f4f6; }
    .table-hover tbody tr:hover { background-color: #f9fafb; cursor: pointer; }
    .btn-primary-pro { background-color: var(--primary-color); color: white; border: none; padding: 6px 14px; border-radius: 6px; font-weight: 500; font-size: 0.85rem; }
    .btn-primary-pro:hover { background-color: #0d9488; color: white; }
    .btn-outline-secondary { border-radius: 6px; font-size: 0.8rem; padding: 6px 12px; }
    .status-badge { padding: 4px 8px; border-radius: 6px; font-size: 0.7rem; font-weight: 600; }
    .filter-link { color: #4b5563; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; font-size: 0.8rem; }
    .filter-link:hover { color: var(--primary-color); }
    .form-control-search { border-radius: 6px; border: 1px solid #d1d5db; padding: 6px 10px; font-size: 0.85rem; height: 34px; }
    .form-control-search:focus { border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1); }
    .modal-elegant .modal-content { border: none; border-radius: 12px; box-shadow: 0 15px 35px rgba(0,0,0,0.1); border-left: 3px solid #dc2626; }
    .modal-elegant .modal-header { border-bottom: 0; padding: 15px 20px; background: #fff; border-radius: 0 12px 0 0; display: flex; align-items: center; }
    .modal-elegant .modal-title { font-size: 1rem; font-weight: 700; color: #dc2626; }
    .modal-elegant .modal-body { padding: 0 20px 15px; color: #4b5563; font-size: 0.9rem; }
    .modal-elegant .modal-footer { border-top: 0; padding: 0 20px 20px; }
    .pagination .page-link { color: #374151; border: 1px solid #e5e7eb; margin: 0 2px; border-radius: 6px; font-size: 0.85rem; padding: 6px 10px; }
    .pagination .page-item.active .page-link { background-color: var(--primary-color); border-color: var(--primary-color); color: white; }
    .small-meta { font-size: 0.7rem; color: #6b7280; }
    
    .dropdown-menu-checkboxes .form-check { margin-bottom: 0.5rem; }
    .dropdown-menu-checkboxes .form-check-label { font-size: 0.8rem; cursor: pointer; color: #374151; }
    .dropdown-menu-checkboxes .form-check-input { cursor: pointer; }
    .dropdown-menu-checkboxes .form-check-input:checked { background-color: var(--primary-color); border-color: var(--primary-color); }
    
    .filter-indicator { width: 6px; height: 6px; background-color: var(--primary-color); border-radius: 50%; display: inline-block; }
</style>

<div class="main-content-wrapper container-fluid pt-4 px-4">
    <div class="toast-container position-fixed top-0 end-0 p-3">
        <div id="formToast" class="toast align-items-center text-white bg-success border-0 shadow" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex"><div class="toast-body" id="toastBody"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>
        </div>
    </div>

    <?php if (!empty($error)): ?><div class="alert alert-danger shadow-sm border-0 rounded-3 mb-4 py-2 fs-6"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if (!empty($message)): ?><div class="alert alert-success shadow-sm border-0 rounded-3 mb-4 py-2 fs-6"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>

    <div class="d-flex justify-content-between align-items-end mb-3">
        <div>
            <ul class="nav nav-tabs">
                <li class="nav-item"><a class="nav-link <?php if($status_filter === 'approved') echo 'active'; ?>" href="<?php echo generateFilterUrl('status_filter', 'approved'); ?>">Approved Templates</a></li>
                <li class="nav-item"><a class="nav-link <?php if($status_filter === 'new') echo 'active'; ?>" href="<?php echo generateFilterUrl('status_filter', 'new'); ?>">New Requests <?php if ($new_requests_count > 0): ?><span class="badge bg-danger rounded-pill ms-1" style="font-size:0.7em;"><?php echo $new_requests_count; ?></span><?php endif; ?></a></li>
            </ul>
        </div>
        
        <form action="<?= APP_BASE_PATH ?>index.php" method="GET" class="d-flex align-items-center gap-2 pb-2">
            <input type="hidden" name="route" value="templates">
            <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter); ?>">
            <input type="text" class="form-control form-control-search" name="search" placeholder="Search ID or Name..." value="<?php echo htmlspecialchars($search_term); ?>" style="width: 250px;">
            <button type="submit" class="btn btn-primary-pro">Search</button>
            
            <a href="<?= APP_BASE_PATH ?>index.php?route=templates&reset_filters=1" class="btn btn-outline-secondary">Clear</a>
            
            <?php if ($show_add_joiner_button): ?>
                <a href="<?= APP_BASE_PATH ?>index.php?route=add_edit_template" class="btn btn-primary-pro d-flex align-items-center"><i data-feather="plus" style="width:14px;" class="me-1"></i> New</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="pro-card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>EMP ID</th>
                        <th>Full Name</th>
                        <th>DOJ</th>
                        
                        <th>
                            <div class="dropdown">
                                <a href="#" class="filter-link dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside">
                                    Department
                                    <?php if (!empty($dept_filter_array)): ?>
                                        <span class="badge bg-primary rounded-pill ms-1" style="font-size:0.6rem; padding: 2px 5px;"><?= count($dept_filter_array); ?></span>
                                    <?php endif; ?>
                                </a>
                                <div class="dropdown-menu shadow border-0 p-3 dropdown-menu-checkboxes" style="min-width: 220px; max-height: 350px; overflow-y: auto;">
                                    <form action="<?= APP_BASE_PATH ?>index.php" method="GET">
                                        <input type="hidden" name="route" value="templates">
                                        <input type="hidden" name="status_filter" value="<?= htmlspecialchars($status_filter); ?>">
                                        <?php if (!empty($search_term)): ?><input type="hidden" name="search" value="<?= htmlspecialchars($search_term); ?>"><?php endif; ?>
                                        <input type="hidden" name="sr_filter" value="<?= htmlspecialchars($sr_filter); ?>">
                                        <input type="hidden" name="it_filter" value="<?= htmlspecialchars($it_filter); ?>">
                                        <input type="hidden" name="hr_filter" value="<?= htmlspecialchars($hr_filter); ?>">
                                        <input type="hidden" name="facility_filter" value="<?= htmlspecialchars($facility_filter); ?>">
                                        <input type="hidden" name="employee_type" value="<?= htmlspecialchars($emp_type_filter); ?>">
                                        <input type="hidden" name="employee_status" value="<?= htmlspecialchars($emp_status_filter); ?>">

                                        <div class="mb-2 border-bottom pb-2">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="deptAllCheck" <?= empty($dept_filter_array) ? 'checked' : ''; ?> onchange="document.querySelectorAll('.dept-check').forEach(c => c.checked = false); if(this.checked) this.form.submit();">
                                                <label class="form-check-label fw-bold" for="deptAllCheck">All Departments</label>
                                            </div>
                                        </div>
                                        <?php foreach ($departments as $dept): ?>
                                            <div class="form-check">
                                                <input class="form-check-input dept-check" type="checkbox" name="department_filter[]" value="<?= htmlspecialchars($dept); ?>" id="dept_<?= md5($dept); ?>" <?= in_array($dept, $dept_filter_array) ? 'checked' : ''; ?> onchange="document.getElementById('deptAllCheck').checked = false;">
                                                <label class="form-check-label" for="dept_<?= md5($dept); ?>"><?= htmlspecialchars($dept); ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                        <div class="mt-3">
                                            <button type="submit" class="btn btn-primary-pro btn-sm w-100">Apply Filter</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </th>

                        <th>
                            <div class="dropdown">
                                <a href="#" class="filter-link dropdown-toggle" data-bs-toggle="dropdown">
                                    Status
                                    <?php if ($emp_status_filter !== 'all') echo '<span class="filter-indicator ms-1" title="Filter Applied"></span>'; ?>
                                </a>
                                <ul class="dropdown-menu shadow border-0">
                                    <?php foreach ($emp_states as $key => $value): ?>
                                        <li>
                                            <a class="dropdown-item <?php if($emp_status_filter == $key) echo 'active'; ?>" href="<?php echo generateFilterUrl('employee_status', $key); ?>">
                                                <?php echo htmlspecialchars($value); ?>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </th>
                        
                        <?php if ($show_requested_by): ?><th>Requested By</th><?php endif; ?>
                        <?php if ($show_created_by): ?><th>Created By</th><?php endif; ?>

                        <?php if ($show_system_readiness): ?> 
                            <th class="text-center">
                                <div class="dropdown">
                                    <a href="#" class="filter-link dropdown-toggle" data-bs-toggle="dropdown">
                                        Sys. Readiness
                                        <?php if ($sr_filter !== 'all') echo '<span class="filter-indicator ms-1" title="Filter Applied"></span>'; ?>
                                    </a>
                                    <ul class="dropdown-menu shadow border-0">
                                        <?php foreach ($sr_states as $key => $value): ?>
                                            <li><a class="dropdown-item <?php if($sr_filter == $key) echo 'active'; ?>" href="<?php echo generateFilterUrl('sr_filter', $key); ?>"><?php echo htmlspecialchars($value); ?></a></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </th>
                        <?php endif; ?>
                        
                        <?php if ($show_it_induction): ?> 
                            <th class="text-center">
                                <div class="dropdown">
                                    <a href="#" class="filter-link dropdown-toggle" data-bs-toggle="dropdown">
                                        IT Induction
                                        <?php if ($it_filter !== 'all') echo '<span class="filter-indicator ms-1" title="Filter Applied"></span>'; ?>
                                    </a>
                                    <ul class="dropdown-menu shadow border-0">
                                        <?php foreach ($induction_states as $key => $value): ?>
                                            <li><a class="dropdown-item <?php if($it_filter == $key) echo 'active'; ?>" href="<?php echo generateFilterUrl('it_filter', $key); ?>"><?php echo htmlspecialchars($value); ?></a></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </th>
                        <?php endif; ?>
                        
                        <?php if ($show_hr_induction): ?> 
                            <th class="text-center">
                                <div class="dropdown">
                                    <a href="#" class="filter-link dropdown-toggle" data-bs-toggle="dropdown">
                                        HR Induction
                                        <?php if ($hr_filter !== 'all') echo '<span class="filter-indicator ms-1" title="Filter Applied"></span>'; ?>
                                    </a>
                                    <ul class="dropdown-menu shadow border-0">
                                        <?php foreach ($induction_states as $key => $value): ?>
                                            <li><a class="dropdown-item <?php if($hr_filter == $key) echo 'active'; ?>" href="<?php echo generateFilterUrl('hr_filter', $key); ?>"><?php echo htmlspecialchars($value); ?></a></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </th>
                        <?php endif; ?>
                        
                        <?php if ($show_facility_induction): ?>
                            <th class="text-center">
                                <div class="dropdown">
                                    <a href="#" class="filter-link dropdown-toggle" data-bs-toggle="dropdown">
                                        Fac. Induction
                                        <?php if ($facility_filter !== 'all') echo '<span class="filter-indicator ms-1" title="Filter Applied"></span>'; ?>
                                    </a>
                                    <ul class="dropdown-menu shadow border-0">
                                        <?php foreach ($induction_states as $key => $value): ?>
                                            <li><a class="dropdown-item <?php if($facility_filter == $key) echo 'active'; ?>" href="<?php echo generateFilterUrl('facility_filter', $key); ?>"><?php echo htmlspecialchars($value); ?></a></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </th>
                        <?php endif; ?>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($employees)) : ?>
                        <tr><td colspan="<?php echo $colspan; ?>" class="text-center text-muted py-5"><i data-feather="inbox" style="width:32px; height:32px; opacity:0.3;"></i><br>No records found.</td></tr>
                    <?php else : ?>
                        <?php foreach ($employees as $employee) : ?>
                            <tr class="clickable-row" data-href="<?= APP_BASE_PATH ?>index.php?route=add_edit_template&id=<?php echo $employee['id']; ?>">
                                <td class="fw-bold text-primary"><?php echo htmlspecialchars($employee['emp_id']); ?></td>
                                <td class="fw-bold"><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></td>
                                <td><?php echo htmlspecialchars(date('d M Y', strtotime($employee['date_of_joining']))); ?></td>
                                <td><?php echo htmlspecialchars($employee['department']); ?></td>
                                
                                <td>
                                    <?php 
                                    $est = $employee['employee_status'] ?? 'Yet to Join';
                                    $e_badge = 'bg-secondary-subtle text-secondary-emphasis';
                                    if($est == 'Active') $e_badge = 'bg-success-subtle text-success-emphasis';
                                    elseif($est == 'Not Joined') $e_badge = 'bg-danger-subtle text-danger-emphasis';
                                    elseif(stripos($est, 'Inactive') !== false || stripos($est, 'Resigned') !== false) $e_badge = 'bg-dark-subtle text-dark-emphasis border border-dark-subtle';
                                    else $e_badge = 'bg-info-subtle text-info-emphasis';
                                    ?>
                                    <span class="status-badge <?php echo $e_badge; ?>"><?php echo htmlspecialchars($est); ?></span>
                                </td>

                                <?php if ($show_requested_by): ?>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($employee['requested_by'] ?? 'N/A'); ?></div>
                                        <div class="small-meta">
                                            <?php echo ($employee['requested_at']) ? htmlspecialchars(date('d M, h:i A', strtotime($employee['requested_at']))) : '-'; ?>
                                        </div>
                                    </td>
                                <?php endif; ?>

                                <?php if ($show_created_by): ?>
                                    <td>
                                        <?php if ($employee['status'] === 'Approved'): ?>
                                            <div class="fw-bold text-success"><?php echo htmlspecialchars($employee['created_by'] ?? 'N/A'); ?></div>
                                            <div class="small-meta">
                                                <?php echo ($employee['created_at']) ? htmlspecialchars(date('d M, h:i A', strtotime($employee['created_at']))) : '-'; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                
                                <?php if ($show_system_readiness): ?>
                                <td class="text-center">
                                    <?php 
                                    $sr_status = $employee['system_readiness_status'] ?? 'Not Started'; 
                                    $sr_class = 'bg-secondary-subtle text-secondary-emphasis'; 
                                    if(stripos($sr_status, 'completed') !== false) $sr_class = 'bg-success-subtle text-success-emphasis'; 
                                    elseif(stripos($sr_status, 'preparing') !== false) $sr_class = 'bg-info-subtle text-info-emphasis';
                                    elseif(stripos($sr_status, 'pending') !== false) $sr_class = 'bg-warning-subtle text-warning-emphasis';
                                    ?>
                                    <span class="status-badge <?php echo $sr_class; ?>"><?php echo htmlspecialchars($sr_status); ?></span>
                                </td>
                                <?php endif; ?>

                                <?php foreach (['it', 'hr', 'facility'] as $dept): 
                                    $perm_var = "show_{$dept}_induction";
                                    if ($$perm_var):
                                        $st = $employee[$dept.'_induction_status'] ?? 'Pending';
                                        $cls = ($st == 'Completed') ? 'bg-success-subtle text-success-emphasis' : 'bg-warning-subtle text-warning-emphasis';
                                ?>
                                    <td class="text-center"><span class="status-badge <?php echo $cls; ?>"><?php echo htmlspecialchars($st); ?></span></td>
                                <?php endif; endforeach; ?>

                                <td class="text-center">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light border dropdown-toggle py-0" type="button" data-bs-toggle="dropdown" data-bs-display="static"><i data-feather="more-horizontal" style="width:14px;"></i></button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow border-0">
        
                                            <?php 
                                            // 1. TEMPLATE PDF
                                            if ($can_download_template && $employee['status'] === 'Approved') {
                                                echo '<li><a class="dropdown-item" href="'.APP_BASE_PATH.'index.php?route=generate_template_pdf&id='.$employee['id'].'" target="_blank"><i data-feather="file-text" style="width:14px;" class="me-2"></i> Template PDF</a></li>';
                                            } else {
                                                $tooltip = !$can_download_template ? 'Permission Denied' : 'Template not yet approved';
                                                echo '<li data-bs-toggle="tooltip" data-bs-placement="left" title="'.$tooltip.'"><a class="dropdown-item disabled" href="#"><i data-feather="file-text" style="width:14px;" class="me-2"></i> Template PDF</a></li>';
                                            }

                                            // 2. CHECKLIST PDF
                                            if ($can_download_checklist && !empty($employee['system_readiness_checklist_id']) && stripos($employee['system_readiness_status'] ?? '', 'Completed') !== false) {
                                                echo '<li><a class="dropdown-item" href="'.APP_BASE_PATH.'index.php?route=generate_checklist_pdf&checklist_id='.$employee['system_readiness_checklist_id'].'" target="_blank"><i data-feather="check-square" style="width:14px;" class="me-2"></i> Checklist PDF</a></li>';
                                            } else {
                                                $tooltip = !$can_download_checklist ? 'Permission Denied' : 'Checklist not yet completed';
                                                if (empty($employee['system_readiness_checklist_id'])) { $tooltip = 'Checklist not created'; }
                                                echo '<li data-bs-toggle="tooltip" data-bs-placement="left" title="'.$tooltip.'"><a class="dropdown-item disabled" href="#"><i data-feather="check-square" style="width:14px;" class="me-2"></i> Checklist PDF</a></li>';
                                            }

                                            // 3. ACCEPTANCE PDF
                                            if ($can_download_acceptance && !empty($employee['system_readiness_checklist_id']) && stripos($employee['system_readiness_status'] ?? '', 'Completed') !== false) {
                                                echo '<li><a class="dropdown-item" href="'.APP_BASE_PATH.'index.php?route=generate_acceptance_pdf&checklist_id='.$employee['system_readiness_checklist_id'].'" target="_blank"><i data-feather="user-check" style="width:14px;" class="me-2"></i> Acceptance PDF</a></li>';
                                            } else {
                                                $tooltip = !$can_download_acceptance ? 'Permission Denied' : 'Checklist not yet completed';
                                                if (empty($employee['system_readiness_checklist_id'])) { $tooltip = 'Checklist not created'; }
                                                echo '<li data-bs-toggle="tooltip" data-bs-placement="left" title="'.$tooltip.'"><a class="dropdown-item disabled" href="#"><i data-feather="user-check" style="width:14px;" class="me-2"></i> Acceptance PDF</a></li>';
                                            }

                                            // 4. GATEPASS PDF
                                            if ($can_download_gatepass && !empty($employee['gatepass_id']) && $employee['gatepass_id'] > 0 && isset($employee['gatepass_status']) && stripos($employee['gatepass_status'] ?? '', 'Completed') !== false) {
                                                echo '<li><a class="dropdown-item" href="'.APP_BASE_PATH.'index.php?route=generate_gatepass_pdf&id='.$employee['gatepass_id'].'" target="_blank"><i data-feather="map" style="width:14px;" class="me-2"></i> Gatepass PDF</a></li>';
                                            } else {
                                                $tooltip = !$can_download_gatepass ? 'Permission Denied' : 'Gatepass not yet approved';
                                                if (empty($employee['gatepass_id'])) { $tooltip = 'Gatepass not created'; }
                                                echo '<li data-bs-toggle="tooltip" data-bs-placement="left" title="'.$tooltip.'"><a class="dropdown-item disabled" href="#"><i data-feather="map" style="width:14px;" class="me-2"></i> Gatepass PDF</a></li>';
                                            }
                                            ?>

                                            <?php if ($can_delete): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li><a class="dropdown-item text-danger delete-joiner-btn" href="#" data-joiner-id="<?php echo $employee['id']; ?>"><i data-feather="trash-2" style="width:14px;" class="me-2"></i> Delete</a></li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div class="d-flex justify-content-between align-items-center p-3 border-top bg-light card-footer-pagination">
            <small class="text-muted">Showing <?php echo ($total_records > 0) ? $offset + 1 : 0; ?> to <?php echo min($offset + $records_per_page, $total_records); ?> of <?php echo $total_records; ?> entries</small>
            <?php if ($total_pages > 1): ?>
            <nav>
                <ul class="pagination mb-0">
                    <li class="page-item <?php if($current_page <= 1) echo 'disabled'; ?>"><a class="page-link" href="<?php echo generateFilterUrl('page', 1); ?>">&laquo;</a></li>
                    <li class="page-item <?php if($current_page <= 1) echo 'disabled'; ?>"><a class="page-link" href="<?php echo generateFilterUrl('page', $current_page - 1); ?>">&lsaquo;</a></li>
                    <li class="page-item active"><span class="page-link"><?php echo $current_page; ?></span></li>
                    <li class="page-item <?php if($current_page >= $total_pages) echo 'disabled'; ?>"><a class="page-link" href="<?php echo generateFilterUrl('page', $current_page + 1); ?>">&rsaquo;</a></li>
                    <li class="page-item <?php if($current_page >= $total_pages) echo 'disabled'; ?>"><a class="page-link" href="<?php echo generateFilterUrl('page', $total_pages); ?>">&raquo;</a></li>
                </ul>
            </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal fade modal-elegant" id="deleteConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i data-feather="alert-triangle" style="width:18px;" class="me-2"></i> Confirm Delete</h5>
            </div>
            <div class="modal-body">
                Are you sure? This action cannot be undone and will delete all associated checklists.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-danger rounded-pill px-4" id="confirmDeleteBtn">Delete</button>
            </div>
        </div>
    </div>
</div>

<?php include_once '../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof feather !== 'undefined') feather.replace();

    // Initialize Bootstrap Tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    })

    document.querySelector('.table tbody').addEventListener('click', function(event) {
        const row = event.target.closest('tr.clickable-row');
        if (!row || event.target.closest('a, button, .dropdown, input, label')) return; // Avoid triggering on inputs/dropdowns
        if (row.dataset.href) window.location.href = row.dataset.href;
    });

    const deleteConfirmModalEl = document.getElementById('deleteConfirmModal');
    if(deleteConfirmModalEl) {
        const deleteConfirmModal = new bootstrap.Modal(deleteConfirmModalEl);
        const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
        let joinerIdToDelete = null;
        let rowToDelete = null;

        document.querySelectorAll('.delete-joiner-btn').forEach(button => {
            button.addEventListener('click', function(event) {
                event.preventDefault();
                event.stopPropagation();
                joinerIdToDelete = this.dataset.joinerId;
                rowToDelete = this.closest('tr');
                deleteConfirmModal.show();
            });
        });

        confirmDeleteBtn.addEventListener('click', () => {
            if (joinerIdToDelete) {
                const formData = new FormData();
                formData.append('action', 'delete_joiner');
                formData.append('delete_id', joinerIdToDelete);
                formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>'); 
                
                // FIXED AJAX URL HERE
                fetch('<?= APP_BASE_PATH ?>index.php?route=templates', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    deleteConfirmModal.hide();
                    if (data.success) {
                        if (rowToDelete) rowToDelete.remove();
                        const toastBody = document.getElementById('toastBody');
                        toastBody.textContent = data.message;
                        const toast = new bootstrap.Toast(document.getElementById('formToast'));
                        toast.show();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(err => {
                    deleteConfirmModal.hide();
                    alert('An error occurred.');
                });
            }
        });
    }
});
</script>