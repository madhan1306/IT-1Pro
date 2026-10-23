<?php
// FILE: /pages/manage_employees.php - ROW CLICK VERSION

include_once '../includes/error_handler.php';
include_once '../includes/db_connect.php';
include_once '../includes/auth.php';

$pageTitle = "Manage Employees";
$showBackButton = false;

// --- PERMISSIONS SETUP ---
$super_user_id = 1;
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_super_user = ($current_user_id === $super_user_id); 

$can_view = can('view_employees') || $is_super_user;
$can_edit = can('edit_employees') || $is_super_user; 
$can_manage = can('manage_employees') || $is_super_user; 

if (!$is_super_user) {
    checkPermission('view_employees'); 
}
// -------------------------

// --- FETCH DROPDOWN DATA ---
$departments = [];
$locations = [];

// Fetch Departments
$dept_result = $conn->query("SELECT name FROM departments ORDER BY name ASC");
if ($dept_result) {
    while ($row = $dept_result->fetch_assoc()) {
        $departments[] = $row['name'];
    }
}

// Fetch Locations
$loc_result = $conn->query("SELECT name FROM locations ORDER BY name ASC");
if ($loc_result) {
    while ($row = $loc_result->fetch_assoc()) {
        $locations[] = $row['name'];
    }
}
// ---------------------------

// --- POST HANDLING & DATABASE LOGIC ---
$upload_report = [
    'inserted' => 0,
    'skipped_existing' => [],
    'skipped_empty' => 0,
    'errors' => []
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'create_single' && $can_manage) {
        
        // Sanitize and prepare data
        $emp_id = trim($_POST['emp_id'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $email_id = trim($_POST['email_id'] ?? ''); 
        $domain_username = trim($_POST['domain_username'] ?? ''); 
        $department = trim($_POST['department'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $position = trim($_POST['position'] ?? '');
        $date_of_joining = trim($_POST['date_of_joining'] ?? ''); 
        $employee_type = 'existing_employee'; // Fixed Type
        $employee_status = 'Active'; 
        $creator_username = $_SESSION['username'] ?? 'System';
        
        if (empty($emp_id) || empty($first_name) || empty($last_name)) {
            $_SESSION['form_error'] = "Employee ID, First Name, and Last Name are required.";
        } else {
            // Check for duplicate emp_id
            $stmt_check = $conn->prepare("SELECT id FROM employees WHERE emp_id = ?"); 
            $stmt_check->bind_param("s", $emp_id);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows > 0) {
                $_SESSION['form_error'] = "Error: Employee ID '$emp_id' already exists. Record was skipped.";
                $stmt_check->close();
            } else {
                $stmt_check->close();

                // Prepare INSERT statement 
                $sql = "INSERT INTO employees 
                        (emp_id, first_name, last_name, email_id, domain_username, department, location, position, date_of_joining, employee_type, employee_status, created_by, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, NOW(), NOW())";
                
                $stmt = $conn->prepare($sql);
                
                if ($stmt) {
                    $stmt->bind_param("sssssssssss", 
                        $emp_id, $first_name, $last_name, $email_id, $domain_username, $department, $location, $position, $date_of_joining, $employee_type, $creator_username);
                    
                    if ($stmt->execute()) {
                        $_SESSION['form_success'] = "Employee " . htmlspecialchars($first_name . ' ' . $last_name) . " created successfully.";
                        if (function_exists('log_system_event')) {
                             log_system_event($conn, 'Employee Management', "Created new employee: ID $emp_id, Name: $first_name $last_name.");
                        }
                    } else {
                        $_SESSION['form_error'] = "Database Error: " . $stmt->error;
                        error_log("Employee Creation Failed: " . $stmt->error);
                    }
                    $stmt->close();
                } else {
                    $_SESSION['form_error'] = "System Error: Failed to prepare statement.";
                }
            }
        }
        header("Location: " . APP_BASE_PATH . "index.php?route=manage_employees");
        exit;

    } elseif (isset($_POST['action']) && $_POST['action'] === 'bulk_upload' && $can_manage && isset($_FILES['employee_file'])) {
        
        $file = $_FILES['employee_file'];
        $tmp_name = $file['tmp_name'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($file_ext === 'csv' && is_uploaded_file($tmp_name)) {
            
            $handle = fopen($tmp_name, "r"); 
            if ($handle === FALSE) {
                 $_SESSION['form_error'] = "Error opening the uploaded file.";
            } else {
                // === ROBUST BOM & DELIMITER DETECTION ===
                $bom = "\xef\xbb\xbf";
                $delimiter = ','; 
                $is_bom = false;

                $first_chunk = fread($handle, 1024);
                if (strpos($first_chunk, $bom) === 0) {
                    $is_bom = true;
                    $first_chunk = substr($first_chunk, 3); 
                }

                $first_line_end = strpos($first_chunk, "\n");
                $first_line = $first_chunk;
                if ($first_line_end !== false) {
                    $first_line = substr($first_chunk, 0, $first_line_end);
                }

                if (substr_count($first_line, ';') > substr_count($first_line, ',')) {
                    $delimiter = ';';
                }

                rewind($handle);
                if ($is_bom) { fread($handle, 3); }
                // === END ROBUST DETECTION ===
                
                $header_row = fgetcsv($handle, 1000, $delimiter); 
                $header_map = [];
                $required_headers = ['emp_id', 'first_name', 'last_name', 'email_id', 'domain_username', 'department', 'location', 'position', 'date_of_joining'];
                
                if (is_array($header_row) && count($header_row) > 0) {
                    foreach ($header_row as $index => $header) {
                        $clean_header = preg_replace('/[^\w\s]/u', '', (string)$header); 
                        $normalized_header = strtolower(trim($clean_header));
                        
                        if (in_array($normalized_header, $required_headers)) {
                            $header_map[$normalized_header] = $index;
                        }
                    }

                    $missing_headers = array_diff($required_headers, array_keys($header_map));
                    if (!empty($missing_headers)) {
                        $_SESSION['form_error'] = "Missing required headers in CSV: " . implode(', ', $missing_headers) . ". Please check your file format.";
                    } else {
                        $creator_username = $_SESSION['username'] ?? 'System';
                        $employee_type = 'existing_employee';
                        $employee_status = 'Active'; 

                        $conn->begin_transaction();
                        
                        $sql = "INSERT INTO employees (emp_id, first_name, last_name, email_id, domain_username, department, location, position, date_of_joining, employee_type, employee_status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                        $stmt_insert = $conn->prepare($sql);
                        
                        $sql_check = "SELECT id FROM employees WHERE emp_id = ?";
                        $stmt_check = $conn->prepare($sql_check);
                        
                        if ($stmt_insert && $stmt_check) {
                            $row_num = 1;
                            while (($data = fgetcsv($handle, 1000, $delimiter)) !== FALSE) {
                                $row_num++;

                                if (count($data) === 1 && empty(trim($data[0]))) continue;
                                
                                if (count($data) < count($header_map)) {
                                    $upload_report['errors'][] = "Row $row_num: Skipped due to malformed data (too few columns).";
                                    continue; 
                                }
                                
                                $emp_id = trim($data[$header_map['emp_id']] ?? '');
                                if (empty($emp_id)) {
                                    $upload_report['skipped_empty']++;
                                    continue; 
                                }
                                
                                if (function_exists('mb_convert_encoding')) $emp_id = mb_convert_encoding($emp_id, 'UTF-8', 'auto');

                                $stmt_check->bind_param("s", $emp_id);
                                $stmt_check->execute();
                                if ($stmt_check->get_result()->num_rows > 0) {
                                    $upload_report['skipped_existing'][] = $emp_id;
                                    continue; 
                                }

                                $first_name = trim($data[$header_map['first_name']] ?? '');
                                $last_name = trim($data[$header_map['last_name']] ?? '');
                                $email_id = trim($data[$header_map['email_id']] ?? '');
                                $domain_username = trim($data[$header_map['domain_username']] ?? '');
                                $department = trim($data[$header_map['department']] ?? '');
                                $location = trim($data[$header_map['location']] ?? '');
                                $position = trim($data[$header_map['position']] ?? '');
                                
                                // === DATE FORMATTING FIX ===
                                $date_of_joining_raw = trim($data[$header_map['date_of_joining']] ?? '');
                                $date_of_joining_sql = null; 
                                if (!empty($date_of_joining_raw)) {
                                    $date_obj = DateTime::createFromFormat('d-m-Y', $date_of_joining_raw);
                                    if (!$date_obj) $date_obj = DateTime::createFromFormat('Y-m-d', $date_of_joining_raw);
                                    if ($date_obj) {
                                        $date_of_joining_sql = $date_obj->format('Y-m-d'); 
                                    } else {
                                        $upload_report['errors'][] = "Row $row_num (ID: $emp_id): Date '{$date_of_joining_raw}' is invalid. Inserted NULL.";
                                    }
                                }
                                
                                if (function_exists('mb_convert_encoding')) {
                                    $first_name = mb_convert_encoding($first_name, 'UTF-8', 'auto');
                                    $last_name = mb_convert_encoding($last_name, 'UTF-8', 'auto');
                                    $email_id = mb_convert_encoding($email_id, 'UTF-8', 'auto');
                                    $domain_username = mb_convert_encoding($domain_username, 'UTF-8', 'auto');
                                    $department = mb_convert_encoding($department, 'UTF-8', 'auto');
                                    $location = mb_convert_encoding($location, 'UTF-8', 'auto');
                                    $position = mb_convert_encoding($position, 'UTF-8', 'auto');
                                }

                                $stmt_insert->bind_param("ssssssssssss", $emp_id, $first_name, $last_name, $email_id, $domain_username, $department, $location, $position, $date_of_joining_sql, $employee_type, $employee_status, $creator_username);
                                
                                if ($stmt_insert->execute()) {
                                    $upload_report['inserted']++;
                                } else {
                                     $upload_report['errors'][] = "Row $row_num (ID: $emp_id): Database Insert Failed: " . $stmt_insert->error;
                                }
                            }
                            
                            $conn->commit();
                            $stmt_insert->close();
                            $stmt_check->close();
                            
                            $total_skipped = count($upload_report['skipped_existing']) + $upload_report['skipped_empty'] + count($upload_report['errors']);
                            
                            $_SESSION['form_success'] = "Bulk upload complete. Inserted: {$upload_report['inserted']}. Skipped/Failed: $total_skipped.";
                            if (function_exists('log_system_event')) {
                                log_system_event($conn, 'Employee Management', "Bulk upload completed. Inserted: {$upload_report['inserted']}, Skipped/Failed: $total_skipped.");
                            }

                        } else {
                            $conn->rollback();
                            $_SESSION['form_error'] = "Database Error: Could not prepare statements for bulk insert.";
                        }
                    }
                } else {
                    $_SESSION['form_error'] = "File is empty or header row could not be parsed.";
                }
                fclose($handle);
            }
        } else {
            $_SESSION['form_error'] = "Invalid file type or file upload error. Only CSV files are supported.";
        }

        $_SESSION['upload_report'] = $upload_report; 
        header("Location: " . APP_BASE_PATH . "index.php?route=manage_employees&report=1");
        exit;
    }
}
// --- END POST HANDLING ---

// --- FILTER SETUP ---
$employee_status_filter = $_GET['status'] ?? 'Active'; 
$show_report = isset($_GET['report']);

// --- Employee Fetching (Server-side fetch ALL filtered records for client-side search/pagination) ---
$employees = [];
$total_records = 0;
$filter_sql = '';
$params = [];
$types = '';

if ($employee_status_filter !== 'All') {
    $filter_sql = " WHERE employee_status = ?";
    $params[] = $employee_status_filter;
    $types .= 's';
}

$count_sql = "SELECT COUNT(*) AS total FROM employees" . $filter_sql;
$stmt_count = $conn->prepare($count_sql);
if ($stmt_count) {
    if (!empty($params)) $stmt_count->bind_param($types, ...$params);
    $stmt_count->execute();
    $total_records = $stmt_count->get_result()->fetch_assoc()['total'];
    $stmt_count->close();
}

// FETCH ALL RECORDS ALONG WITH INDUCTION STATUSES
$sql = "SELECT id, emp_id, first_name, last_name, department, position, date_of_joining, employee_status, 
               it_induction_status, hr_induction_status, facility_induction_status 
        FROM employees" . $filter_sql . " 
        ORDER BY ID DESC"; 
        
$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
    $stmt->close();
}
// --- END Employee Fetching ---

include_once '../includes/header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    :root { 
        --primary-color: #0f766e; 
        --primary-hover: #0d9488;
        --bg-color: #f3f4f6; 
        --card-bg: #ffffff; 
        --border-color: #e5e7eb; 
        --radius: 12px; 
    }
    
    html, body { height: 100%; margin: 0; }

    body {
        font-family: 'Inter', sans-serif;
        background-color: var(--bg-color) !important;
        color: #1f2937;
        display: flex;
        flex-direction: column;
        font-size: 0.8rem;
    }
    
    h1, h2, h3, h4, h5, h6 { font-weight: 600; color: #1e293b; }
    
    .main-content-wrapper { 
        flex: 1 0 auto;
        display: flex; 
        flex-direction: column;
        min-height: calc(100vh - 160px); 
        padding-bottom: 20px;
    } 
    
    .pro-card { 
        flex: 1; 
        display: flex; 
        flex-direction: column;
        background: var(--card-bg); 
        border-radius: var(--radius); 
        box-shadow: 0 2px 4px rgba(0,0,0,0.05); 
        border: 1px solid var(--border-color); 
        overflow: hidden; 
        margin-bottom: 24px; 
    }
    
    .table-responsive { flex: 1; overflow: visible; }

    .card-footer-pagination {
        margin-top: auto; 
        background-color: #f8f9fa;
        border-top: 1px solid var(--border-color);
    }
    
    .btn-primary-pro { 
        background-color: var(--primary-color); 
        color: white; 
        border: none; 
        padding: 6px 14px; 
        border-radius: 6px; 
        font-weight: 500; 
        font-size: 0.8rem; 
        transition: all 0.2s;
    }
    .btn-primary-pro:hover { background-color: var(--primary-hover); color: white; }
    
    .btn-outline-secondary { border-radius: 6px; font-size: 0.8rem; padding: 6px 12px; }
    .form-control-search { border-radius: 6px; border: 1px solid #d1d5db; padding: 6px 10px 6px 30px; font-size: 0.8rem; height: 34px; }
    .form-control-search:focus { border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1); }

    /* Compact Table Styling */
    .table-custom { width: 100%; border-collapse: separate; border-spacing: 0; margin-bottom: 0; }
    .table-custom th {
        font-weight: 600; 
        color: #4b5563; 
        border-bottom: 1px solid #e5e7eb; 
        background: #f9fafb; 
        font-size: 0.75rem; 
        text-transform: uppercase; 
        letter-spacing: 0.5px; 
        padding: 10px 12px;
        vertical-align: middle;
        text-align: center;
    }
    .table-custom th:first-child, .table-custom td:first-child { text-align: left; padding-left: 20px; }
    .table-custom td {
        padding: 10px 12px; 
        vertical-align: middle; 
        font-size: 0.85rem; 
        color: #374151; 
        border-bottom: 1px solid #f3f4f6;
        text-align: center;
    }
    
    /* Dropdown Filters */
    .filter-link { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
    .filter-link:hover { color: var(--primary-color); }
    .dropdown-toggle::after { display: none; }
    
    .filter-dropdown {
        max-height: 350px;
        overflow-y: auto;
        padding-top: 0;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05) !important;
        border-radius: 8px;
    }
    .dropdown-search-wrapper {
        position: sticky;
        top: 0;
        background: #ffffff;
        z-index: 10;
        padding: 8px 12px;
        border-bottom: 1px solid #e5e7eb;
        margin-bottom: 4px;
    }
    .filter-dropdown .dropdown-item { font-size: 0.8rem; padding: 6px 12px; font-weight: 500; color: #475569; }
    .filter-dropdown .dropdown-item:hover { background-color: #f1f5f9; color: var(--primary-color); }
    .filter-dropdown .dropdown-item.active { background-color: var(--primary-color); color: #ffffff; }

    /* Clickable Row Styles */
    .clickable-row { transition: background-color 0.2s; cursor: pointer; }
    .clickable-row:hover td { background-color: #f0fdfa !important; color: var(--primary-color); }

    /* Pagination */
    .pagination .page-link { color: #374151; border: 1px solid #e5e7eb; margin: 0 2px; border-radius: 6px !important; font-size: 0.8rem; padding: 6px 10px; cursor: pointer; }
    .pagination .page-item.active .page-link { background-color: var(--primary-color); border-color: var(--primary-color); color: white; }
    .pagination .page-item.disabled .page-link { background-color: #f8fafc; color: #cbd5e1; border-color: #e5e7eb; cursor: not-allowed; }
</style>

<div class="main-content-wrapper container-fluid pt-4 px-4">

    <?php 
    if ($show_report && isset($_SESSION['upload_report'])) {
        $report = $_SESSION['upload_report'];
        $has_issues = !empty($report['skipped_existing']) || !empty($report['errors']) || $report['skipped_empty'] > 0;
        $alert_class = $has_issues ? 'alert-warning border-warning' : 'alert-success border-success';
        $icon = $has_issues ? 'alert-triangle' : 'check-circle';
        
        echo '<div class="alert ' . $alert_class . ' mb-4 shadow-sm border" style="border-radius: 12px;">';
        echo '<h6 class="alert-heading fw-bold d-flex align-items-center mb-3"><i data-feather="' . $icon . '" class="me-2" style="width: 18px;"></i>Bulk Upload Summary</h6>';
        echo '<p class="mb-2 text-dark small"><strong>Records Inserted:</strong> <span class="badge bg-success ms-1">' . $report['inserted'] . '</span></p>';

        if (!empty($report['skipped_existing'])) {
            echo '<p class="mb-1 text-dark small"><strong>Skipped (Existing ID):</strong> <span class="badge bg-danger ms-1">' . count($report['skipped_existing']) . ' records</span></p>';
            echo '<p class="small text-danger ms-3 mb-2" style="font-size: 0.75rem;">IDs: ' . htmlspecialchars(implode(', ', array_slice($report['skipped_existing'], 0, 10))) . 
                 (count($report['skipped_existing']) > 10 ? ' and ' . (count($report['skipped_existing']) - 10) . ' more.' : '') . '</p>';
        }

        if ($report['skipped_empty'] > 0) {
            echo '<p class="text-dark small mb-2"><strong>Skipped (Empty Row):</strong> <span class="badge bg-warning text-dark ms-1">' . $report['skipped_empty'] . ' rows</span></p>';
        }

        if (!empty($report['errors'])) {
            echo '<p class="mb-1 text-dark small"><strong>Errors/Failures:</strong> <span class="badge bg-danger ms-1">' . count($report['errors']) . ' rows</span></p>';
            echo '<div style="max-height: 150px; overflow-y: auto; background: #ffffff; padding: 8px; border-radius: 6px; border: 1px solid #e5e7eb;" class="small mt-2">';
            foreach ($report['errors'] as $error) {
                echo '<div class="text-danger mb-1" style="font-size: 0.75rem;"><i data-feather="x" style="width: 10px;" class="me-1"></i>' . htmlspecialchars($error) . '</div>';
            }
            echo '</div>';
        }
        
        unset($_SESSION['upload_report']);
        echo '</div>';
    }
    ?>

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end mb-3 gap-3">
        <div>
            <h4 class="fw-bold m-0 text-dark">Manage Employees</h4>
            <div class="text-muted mt-1" style="font-size: 0.8rem;">Add, update, and manage employee records and system assignments</div>
        </div>
        <div class="d-flex gap-2">
            <?php if ($can_manage): ?>
                <button type="button" class="btn btn-outline-secondary bg-white d-flex align-items-center shadow-sm" data-bs-toggle="modal" data-bs-target="#bulkUploadModal">
                    <i data-feather="upload" style="width:14px;" class="me-2"></i> Bulk Upload
                </button>
                <button type="button" class="btn btn-primary-pro d-flex align-items-center shadow-sm" data-bs-toggle="modal" data-bs-target="#createEmployeeModal">
                    <i data-feather="user-plus" style="width:14px;" class="me-2"></i> Add Employee
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="pro-card">
        
        <div class="p-3 border-bottom bg-light d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div class="position-relative" style="width: 300px;">
                <i data-feather="search" class="position-absolute text-muted" style="left: 10px; top: 50%; transform: translateY(-50%); width: 14px;"></i>
                <input type="text" class="form-control form-control-search w-100 shadow-none" id="employeeSearch" placeholder="Search by ID or Name...">
            </div>
            <div class="text-muted small">
                Showing: <strong><?php echo htmlspecialchars($employee_status_filter); ?></strong> Employees
            </div>
        </div>

        <div class="table-responsive" style="overflow: visible;">
            <table class="table-custom table-hover" id="employeeTable">
                <thead>
                    <tr>
                        <th>Employee Name</th>
                        
                        <th>
                            <div class="dropdown d-inline-block">
                                <a href="#" class="filter-link dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" title="Filter Department">
                                    Department <i data-feather="filter" style="width:12px; height:12px;"></i>
                                </a>
                                <ul class="dropdown-menu filter-dropdown border-0" id="deptFilterMenu">
                                    <div class="dropdown-search-wrapper">
                                        <input type="text" class="form-control form-control-sm shadow-none" id="deptSearchInput" placeholder="Type to search...">
                                    </div>
                                    <li><a class="dropdown-item active dept-item" href="#" data-val="">All Departments</a></li>
                                    <?php foreach ($departments as $dept): ?>
                                        <li><a class="dropdown-item dept-item" href="#" data-val="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></a></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </th>
                        
                        <th>Position</th>
                        <th>Join Date</th>

                        <th>
                            <div class="dropdown d-inline-block">
                                <a href="#" class="filter-link dropdown-toggle" data-bs-toggle="dropdown" title="Filter Status">
                                    Status <i data-feather="filter" style="width:12px; height:12px;"></i>
                                </a>
                                <ul class="dropdown-menu filter-dropdown shadow border-0">
                                    <?php 
                                    $statuses = ['Active', 'Inactive', 'Yet to Join', 'Not Joined', 'All'];
                                    foreach ($statuses as $st): 
                                    ?>
                                        <li><a class="dropdown-item <?php echo ($employee_status_filter === $st) ? 'active' : ''; ?>" href="<?= APP_BASE_PATH ?>index.php?route=manage_employees&status=<?php echo urlencode($st); ?>"><?php echo $st; ?></a></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </th>
                        
                        <th>
                            <div class="dropdown d-inline-block">
                                <a href="#" class="filter-link dropdown-toggle" data-bs-toggle="dropdown" title="Filter Induction">
                                    Induction <i data-feather="filter" style="width:12px; height:12px;"></i>
                                </a>
                                <ul class="dropdown-menu filter-dropdown shadow border-0" id="indFilterMenu">
                                    <li><a class="dropdown-item active" href="#" data-val="">All</a></li>
                                    <li><a class="dropdown-item" href="#" data-val="Completed">Completed</a></li>
                                    <li><a class="dropdown-item" href="#" data-val="Pending">Pending</a></li>
                                </ul>
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody id="employeeTableBody">
                    <?php if (empty($employees)): ?>
                        <tr id="no-records-row">
                            <td colspan="6" class="text-center text-muted py-5">
                                <i data-feather="users" class="mb-2" style="width:32px; height:32px; opacity:0.3;"></i><br>
                                No employee records found for the selected status.
                            </td>
                        </tr>
                    <?php endif; ?>
                    
                    <?php foreach ($employees as $emp): 
                        $full_name = htmlspecialchars(trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? '')));
                        $emp_id = htmlspecialchars($emp['emp_id'] ?? '');
                        
                        $pending_depts = [];
                        if (($emp['it_induction_status'] ?? 'Pending') !== 'Completed') $pending_depts[] = 'IT';
                        if (($emp['hr_induction_status'] ?? 'Pending') !== 'Completed') $pending_depts[] = 'HR';
                        if (($emp['facility_induction_status'] ?? 'Pending') !== 'Completed') $pending_depts[] = 'Fac';
                        
                        $induction_state = empty($pending_depts) ? 'Completed' : 'Pending';
                    ?>
                    <tr class="employee-row clickable-row" 
                        data-href="<?= APP_BASE_PATH ?>index.php?route=edit_employee&id=<?php echo $emp['id']; ?>"
                        data-department="<?php echo htmlspecialchars($emp['department'] ?? ''); ?>"
                        data-induction="<?php echo $induction_state; ?>">
                        
                        <td data-search-value="<?php echo strtolower($emp_id . ' ' . $full_name); ?>">
                            <div class="fw-bold text-dark text-start"><?php echo $full_name; ?></div>
                            <div class="text-muted text-start mt-1" style="font-size: 0.7rem; letter-spacing: 0.02em;">ID: <?php echo $emp_id; ?></div>
                        </td>
                        
                        <td><span class="badge bg-light text-secondary border rounded-pill px-2"><?php echo htmlspecialchars($emp['department'] ?? 'N/A'); ?></span></td>
                        <td><?php echo htmlspecialchars($emp['position'] ?? 'N/A'); ?></td>
                        
                        <td>
                            <div class="fw-medium text-dark">
                                <?php echo !empty($emp['date_of_joining']) ? date('d M Y', strtotime($emp['date_of_joining'])) : 'N/A'; ?>
                            </div>
                        </td>
                        
                        <td>
                            <?php 
                                $status = htmlspecialchars($emp['employee_status'] ?? '');
                                $badge_class = ($status === 'Active') ? 'bg-success-subtle text-success-emphasis border border-success-subtle' : 'bg-danger-subtle text-danger-emphasis border border-danger-subtle';
                                echo "<span class='badge rounded-pill px-3 $badge_class'>$status</span>";
                            ?>
                        </td>

                        <td>
                            <?php
                                if ($induction_state === 'Completed') {
                                    echo "<span class='badge rounded-pill px-3 bg-success-subtle text-success-emphasis border border-success-subtle'>Completed</span>";
                                } else {
                                    echo "<span class='badge rounded-pill px-3 bg-warning-subtle text-warning-emphasis border border-warning-subtle'>Pending</span>";
                                    echo "<div class='mt-1 text-muted' style='font-size: 0.65rem; font-weight: 500;'>Awaiting: " . htmlspecialchars(implode(', ', $pending_depts)) . "</div>";
                                }
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <tr id="no-search-results" style="display: none;">
                         <td colspan="6" class="text-center text-muted py-5">
                            <i data-feather="filter" class="mb-2" style="width:32px; height:32px; opacity:0.3;"></i><br>
                            No employees match your active filters.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="d-flex justify-content-between align-items-center p-3 card-footer-pagination">
            <div id="pagination-info"><small class="text-muted">Loading...</small></div>
            <nav>
                <ul class="pagination mb-0" id="pagination-controls"></ul>
            </nav>
        </div>
    </div>
</div>

<?php if ($can_manage): ?>
<div class="modal fade" id="createEmployeeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px;">
            <div class="modal-header bg-light border-bottom-0 pb-3">
                <h5 class="modal-title fw-bold text-dark d-flex align-items-center">
                    <i data-feather="user-plus" class="me-2 text-primary" style="width:18px;"></i> Add New Employee
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="<?= APP_BASE_PATH ?>index.php?route=manage_employees">
                <div class="modal-body pt-0">
                    <input type="hidden" name="action" value="create_single">
                    <input type="hidden" name="employee_type" value="existing_employee"> 
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" style="font-size:0.75rem; font-weight:600; color:#64748b; text-transform:uppercase;">Employee ID <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="emp_id" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" style="font-size:0.75rem; font-weight:600; color:#64748b; text-transform:uppercase;">Domain Username</label>
                            <input type="text" class="form-control" name="domain_username">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" style="font-size:0.75rem; font-weight:600; color:#64748b; text-transform:uppercase;">First Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="first_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" style="font-size:0.75rem; font-weight:600; color:#64748b; text-transform:uppercase;">Last Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="last_name" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label" style="font-size:0.75rem; font-weight:600; color:#64748b; text-transform:uppercase;">Email ID</label>
                            <input type="email" class="form-control" name="email_id">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" style="font-size:0.75rem; font-weight:600; color:#64748b; text-transform:uppercase;">Department</label>
                            <select class="form-select" name="department">
                                <option value="">Select Department...</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" style="font-size:0.75rem; font-weight:600; color:#64748b; text-transform:uppercase;">Location</label>
                            <select class="form-select" name="location">
                                <option value="">Select Location...</option>
                                <?php foreach ($locations as $loc): ?>
                                    <option value="<?php echo htmlspecialchars($loc); ?>"><?php echo htmlspecialchars($loc); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" style="font-size:0.75rem; font-weight:600; color:#64748b; text-transform:uppercase;">Position</label>
                            <input type="text" class="form-control" name="position">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" style="font-size:0.75rem; font-weight:600; color:#64748b; text-transform:uppercase;">Date of Joining</label>
                            <input type="date" class="form-control" name="date_of_joining">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-light border px-4 rounded-pill fw-medium" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-pro px-4 rounded-pill shadow-sm d-flex align-items-center">
                        <i data-feather="save" class="me-2" style="width:16px;"></i> Save Employee
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="bulkUploadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px;">
            <form method="POST" action="<?= APP_BASE_PATH ?>index.php?route=manage_employees" enctype="multipart/form-data">
                <input type="hidden" name="action" value="bulk_upload">
                <div class="modal-header bg-success-subtle border-bottom-0 pb-3">
                    <h5 class="modal-title fw-bold text-success-emphasis d-flex align-items-center">
                        <i data-feather="upload" class="me-2 text-success" style="width:18px;"></i> Bulk Employee Upload
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body pt-2">
                    <p class="text-muted small mb-3">Upload a CSV file containing employee records. Existing Employee IDs will be automatically skipped.</p>
                    
                    <h6 class="fw-bold" style="font-size:0.8rem; color:#475569;">Required Headers (Case-insensitive):</h6>
                    <div class="bg-white border rounded p-2 mb-4 font-monospace text-primary" style="font-size:0.75rem; word-break: break-all;">
                        emp_id, first_name, last_name, email_id, domain_username, department, location, position, date_of_joining
                    </div>

                    <div class="mb-3">
                        <label for="employee_file" class="form-label" style="font-size:0.75rem; font-weight:600; color:#64748b; text-transform:uppercase;">Select CSV File <span class="text-danger">*</span></label>
                        <input class="form-control" type="file" id="employee_file" name="employee_file" accept=".csv" required>
                    </div>
                    
                    <div class="text-end">
                        <a href="javascript:void(0)" onclick="downloadCsvTemplate()" class="small text-decoration-none fw-bold d-inline-flex align-items-center hover-primary">
                            <i data-feather="download" style="width:14px;" class="me-1"></i> Download CSV Template
                        </a>
                    </div>

                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-light border px-4 rounded-pill fw-medium" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success px-4 rounded-pill shadow-sm d-flex align-items-center">
                        <i data-feather="upload-cloud" class="me-2" style="width:16px;"></i> Process Upload
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include_once '../includes/footer.php'; ?>

<script>
    function downloadCsvTemplate() {
        const headers = [
            "emp_id", "first_name", "last_name", "email_id", "domain_username", 
            "department", "location", "position", "date_of_joining"
        ];
        
        const exampleRow = [
            "E1001", "John", "Doe", "john.doe@company.com", "jdoe", 
            "IT Support", "Mumbai", "IT Technician", "2024-01-15"
        ];
        
        let csvContent = headers.join(",") + "\n" + exampleRow.join(",") + "\n";
        
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        
        const link = document.createElement("a");
        link.setAttribute("href", url);
        link.setAttribute("download", "employee_upload_template.csv");
        
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    // =========================================================================
    // CLIENT-SIDE SEARCH AND FILTER LOGIC
    // =========================================================================

    document.addEventListener('DOMContentLoaded', function() {
        if (typeof feather !== 'undefined') { feather.replace(); }

        const rowsPerPage = 15;
        let currentPage = 1;
        let activeDeptFilter = '';
        let activeIndFilter = '';

        const $tableBody = $('#employeeTableBody');
        const $noRecordsRow = $('#no-records-row');
        const $noSearchResults = $('#no-search-results');
        const $paginationControls = $('#pagination-controls');
        const $paginationInfo = $('#pagination-info');
        const $employeeRows = $tableBody.find('.employee-row');
        
        if ($employeeRows.length > 0 && $noRecordsRow.length) {
            $noRecordsRow.hide();
        }

        // Dropdown Search logic for Departments
        $('#deptSearchInput').on('keyup', function() {
            const searchTerm = $(this).val().toLowerCase().trim();
            $('#deptFilterMenu .dept-item').each(function() {
                const itemText = $(this).text().toLowerCase();
                // Always show the "All Departments" option, hide others if they don't match
                if ($(this).attr('data-val') === '' || itemText.indexOf(searchTerm) > -1) {
                    $(this).parent().show();
                } else {
                    $(this).parent().hide();
                }
            });
        });

        // Handle Dropdown Filter Selections
        $('#deptFilterMenu .dept-item').on('click', function(e) {
            e.preventDefault();
            $('#deptFilterMenu .dept-item').removeClass('active');
            $(this).addClass('active');
            
            // Using .attr() instead of .data() ensures "R&D" doesn't get decoded incorrectly
            activeDeptFilter = $(this).attr('data-val') || ''; 
            filterTable(1);
            
            // Manually close the dropdown menu
            let toggleBtn = $(this).closest('.dropdown').find('.dropdown-toggle')[0];
            let bsDropdown = bootstrap.Dropdown.getInstance(toggleBtn);
            if (bsDropdown) bsDropdown.hide();
        });

        $('#indFilterMenu .dropdown-item').on('click', function(e) {
            e.preventDefault();
            $('#indFilterMenu .dropdown-item').removeClass('active');
            $(this).addClass('active');
            
            activeIndFilter = $(this).attr('data-val') || '';
            filterTable(1);
            
            // Manually close the dropdown menu
            let toggleBtn = $(this).closest('.dropdown').find('.dropdown-toggle')[0];
            let bsDropdown = bootstrap.Dropdown.getInstance(toggleBtn);
            if (bsDropdown) bsDropdown.hide();
        });

        // Handle Row Click Navigation
        $tableBody.on('click', '.clickable-row', function(e) {
            if (!$(e.target).closest('a, button, .dropdown').length) {
                window.location.href = $(this).data('href');
            }
        });

        function filterTable(startPage = 1) {
            const searchQuery = $('#employeeSearch').val().toLowerCase().trim();
            let visibleRows = [];

            $employeeRows.each(function() {
                const $row = $(this);
                const searchData = $row.find('td:first').attr('data-search-value') || '';
                
                // Using .attr() to match EXACTLY with the filter attribute value
                const rowDept = $row.attr('data-department') || '';
                const rowInd = $row.attr('data-induction') || '';
                
                let matchesSearch = searchData.includes(searchQuery);
                let matchesDept = activeDeptFilter === '' || rowDept === activeDeptFilter;
                let matchesInd = activeIndFilter === '' || rowInd === activeIndFilter;

                if (matchesSearch && matchesDept && matchesInd) {
                    $row.show();
                    visibleRows.push($row);
                } else {
                    $row.hide();
                }
            });

            if (visibleRows.length === 0) {
                $noSearchResults.show();
                $paginationInfo.html('<small class="text-muted">No employees match your active filters.</small>');
                $paginationControls.empty(); 
            } else {
                $noSearchResults.hide();
                setupPagination(visibleRows, startPage);
            }
        }
        
        function setupPagination(rows, page) {
            const totalRows = rows.length;
            const totalPages = Math.ceil(totalRows / rowsPerPage);
            currentPage = Math.min(Math.max(1, page), totalPages); 
            
            const start = (currentPage - 1) * rowsPerPage;
            const end = start + rowsPerPage;

            rows.forEach((row, index) => {
                if (index >= start && index < end) {
                    row.show();
                } else {
                    row.hide();
                }
            });
            
            $paginationControls.empty();
            if (totalPages > 1) {
                $paginationControls.append(
                    `<li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
                        <a class="page-link" href="#" data-page="1">&laquo;</a>
                    </li>
                     <li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
                        <a class="page-link" href="#" data-page="${currentPage - 1}">&lsaquo;</a>
                    </li>`
                );

                let startPage = Math.max(1, currentPage - 2);
                let endPage = Math.min(totalPages, currentPage + 2);

                if (currentPage - 2 < 1) { endPage = Math.min(totalPages, 5); }
                if (currentPage + 2 > totalPages) { startPage = Math.max(1, totalPages - 4); }
                
                if (startPage > 1) $paginationControls.append('<li class="page-item disabled"><span class="page-link">...</span></li>');

                for (let i = startPage; i <= endPage; i++) {
                    $paginationControls.append(
                        `<li class="page-item ${i === currentPage ? 'active' : ''}">
                            <a class="page-link ${i === currentPage ? 'text-white' : ''}" href="#" data-page="${i}">${i}</a>
                        </li>`
                    );
                }
                
                if (endPage < totalPages) $paginationControls.append('<li class="page-item disabled"><span class="page-link">...</span></li>');

                $paginationControls.append(
                    `<li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
                        <a class="page-link" href="#" data-page="${currentPage + 1}">&rsaquo;</a>
                    </li>
                     <li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
                        <a class="page-link" href="#" data-page="${totalPages}">&raquo;</a>
                    </li>`
                );
            }
            
            const startRecord = totalRows > 0 ? start + 1 : 0;
            const endRecord = Math.min(end, totalRows);
            $paginationInfo.html(`<small class="text-muted">Showing ${startRecord} to ${endRecord} of ${totalRows} entries</small>`);
        }

        $('#employeeSearch').on('keyup', function() {
            filterTable(1); 
        });
        
        $paginationControls.on('click', 'a.page-link', function(e) {
            e.preventDefault();
            const newPage = parseInt($(this).data('page'));
            if (!isNaN(newPage)) {
                filterTable(newPage);
            }
        });
        
        if ($employeeRows.length > 0) {
            filterTable(1);
        } else if ($noRecordsRow.length) {
             $paginationInfo.html('<small class="text-muted">Showing 0 to 0 of 0 entries</small>');
        }
        
    });
</script>