<?php
include_once '../includes/db_connect.php';
include_once '../includes/auth.php';

checkPermission('view_system_readiness');

// =========================================================================================
// --- STICKY FILTERS & PAGINATION LOGIC ---
// =========================================================================================
if (isset($_GET['reset_filters'])) {
    unset($_SESSION['system_readiness_list_state']);
    header("Location: " . APP_BASE_PATH . "index.php?route=system_readiness");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $has_filters = false;
    foreach ($_GET as $key => $val) {
        if ($key !== 'route' && $key !== 'reset_filters') { $has_filters = true; break; }
    }

    $current_uri = $_SERVER['REQUEST_URI'];
    $saved_uri = $_SESSION['system_readiness_list_state'] ?? '';

    // If navigating to base URL and we have a saved state that is DIFFERENT, restore it
    if (!$has_filters && !empty($saved_uri) && $saved_uri !== $current_uri) {
        header("Location: " . $saved_uri);
        exit();
    }

    // Only save the state if the user actually applied filters or changed a page
    if ($has_filters) {
        $_SESSION['system_readiness_list_state'] = $current_uri;
    }
}
// =========================================================================================

$pageTitle = "System Readiness";
$error = '';
$message = '';

if (isset($_SESSION['form_success'])) {
    $message = $_SESSION['form_success'];
    unset($_SESSION['form_success']);
}
if (isset($_SESSION['form_error'])) {
    $error = $_SESSION['form_error'];
    unset($_SESSION['form_error']);
}

// --- Handle Delete Action ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_checklist') {
    if (!can('delete_system_readiness')) {
        $_SESSION['form_error'] = "You do not have permission to delete checklists.";
        header('Location: ' . APP_BASE_PATH . 'index.php?route=system_readiness');
        exit();
    }
    
    $checklist_id_to_delete = $_POST['checklist_id'] ?? null;
    if ($checklist_id_to_delete) {
        $conn->begin_transaction();
        try {
            $info_stmt = $conn->prepare("
                SELECT e.first_name, e.last_name
                FROM checklists c
                JOIN employees e ON c.employee_id = e.id
                WHERE c.id = ?
            ");
            if ($info_stmt) {
                $info_stmt->bind_param("i", $checklist_id_to_delete);
                $info_stmt->execute();
                $info = $info_stmt->get_result()->fetch_assoc();
                $info_stmt->close();
            }

            $full_name_for_log = trim(($info['first_name'] ?? '') . ' ' . ($info['last_name'] ?? 'Unknown Employee'));
            $system_log_description = "Deleted system readiness checklist #" . $checklist_id_to_delete . " for employee " . $full_name_for_log;
            
            if (function_exists('log_system_event')) {
                log_system_event($conn, 'System Readiness', $system_log_description);
            }

            $stmt_del_logs = $conn->prepare("DELETE FROM action_logs WHERE template_id = ?");
            $stmt_del_logs->bind_param("i", $checklist_id_to_delete);
            if (!$stmt_del_logs->execute()) throw new Exception("Failed to delete activity logs.");
            $stmt_del_logs->close();

            $stmt_del_items = $conn->prepare("DELETE FROM checklist_items WHERE template_id = ?");
            if ($stmt_del_items === false) {
                 throw new Exception("Failed to prepare delete items statement: " . $conn->error);
            }
            $stmt_del_items->bind_param("i", $checklist_id_to_delete);
            if (!$stmt_del_items->execute()) throw new Exception("Failed to delete checklist items.");
            $stmt_del_items->close();

            $stmt_del_tpl = $conn->prepare("DELETE FROM checklists WHERE id = ?");
            $stmt_del_tpl->bind_param("i", $checklist_id_to_delete);
            if (!$stmt_del_tpl->execute()) throw new Exception("Failed to delete checklist.");
            $stmt_del_tpl->close();

            $conn->commit();
            $_SESSION['form_success'] = "Checklist #" . $checklist_id_to_delete . " was permanently deleted.";

        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['form_error'] = "Error deleting checklist: " . $e->getMessage();
        }
    } else {
        $_SESSION['form_error'] = "Invalid checklist ID for deletion.";
    }
    header('Location: ' . APP_BASE_PATH . 'index.php?route=system_readiness');
    exit();
}

$search_term = '';
$checklists = []; 
$status_filter = $_GET['status_filter'] ?? 'all';
$records_per_page = 15; 
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// --- Department Checkbox Array Handling (With User Preference Fallback) ---
if (isset($_GET['department_filter'])) {
    $dept_filter_raw = $_GET['department_filter']; 
} elseif (!empty($_SESSION['preferred_departments'])) {
    $dept_filter_raw = $_SESSION['preferred_departments'];
} else {
    $dept_filter_raw = [];
}

$dept_filter_array = is_array($dept_filter_raw) ? $dept_filter_raw : [$dept_filter_raw];
$dept_filter_array = array_filter($dept_filter_array, function($v) { return $v !== 'all' && trim($v) !== ''; });

$from_and_joins = "
    FROM checklists c
    LEFT JOIN employees e ON c.employee_id = e.id
    LEFT JOIN users u_prepared ON c.prepared_by = u_prepared.firstname
    LEFT JOIN users u_approved ON c.approved_by = u_approved.firstname
    LEFT JOIN gatepass_requests gp ON c.gatepass_id = gp.id
    LEFT JOIN shipments s ON c.gatepass_id = s.gatepass_id
";

// Added e.department to the SELECT query
$sql = "SELECT c.id, c.employee_id, c.gatepass_id, c.overall_status,
               COALESCE(gp.status, IF(c.gatepass_id = -1, 'Not Required', 'Not Created')) AS gatepass_status,
               s.shipment_status,
               c.prepared_by, c.approved_by, c.prepared_at, c.approved_at,
               c.user_acceptance_status, c.user_acceptance_date,
               e.emp_id, e.first_name, e.last_name, e.department
        {$from_and_joins}";

$sql_where_clause = ["c.checklist_type = 'system_readiness'"];
$params = [];
$types = '';

if (isset($_GET['search_term']) && !empty(trim($_GET['search_term']))) {
    $search_term = trim($_GET['search_term']);
    $search_like = "%" . $search_term . "%";
    $sql_where_clause[] = "(e.emp_id LIKE ? OR CONCAT(e.first_name, ' ', e.last_name) LIKE ? OR c.id LIKE ?)";
    $params = [$search_like, $search_like, $search_like];
    $types = "sss";
}

if ($status_filter !== 'all') {
    if ($status_filter === 'not_started') {
        $sql_where_clause[] = "(c.overall_status IS NULL OR c.overall_status = 'Not Started')";
    } else {
        $sql_where_clause[] = "c.overall_status = ?";
        $params[] = $status_filter;
        $types .= "s";
    }
}

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

$count_sql = "SELECT COUNT(DISTINCT c.id) " . $from_and_joins . $where_sql;
$count_stmt = $conn->prepare($count_sql);
if (!$count_stmt) {
    $error = "Error preparing record count query.";
    $total_records = 0;
} else {
    if (!empty($params)) {
        $count_stmt->bind_param($types, ...$params);
    }
    $count_stmt->execute();
    $total_records = $count_stmt->get_result()->fetch_row()[0];
    $count_stmt->close();
}

$total_pages = ceil($total_records / $records_per_page);

$sql .= $where_sql . " ORDER BY c.created_at DESC, c.id DESC LIMIT ? OFFSET ?";
$params[] = $records_per_page;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    $error = "Error fetching checklists.";
} else {
    if (count($params) > 0) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $checklists[] = $row;
    }
    $stmt->close();
}

// --- Fetch Unique Departments for Checkboxes (RESPECTING PREFERENCES) ---
$departments = [];
$dept_res = $conn->query("SELECT name FROM departments ORDER BY name ASC");
if ($dept_res) {
    while ($d_row = $dept_res->fetch_assoc()) {
        $d_name = $d_row['name'];
        // Only push to the dropdown array if no preferences are set, OR if it's explicitly in the user's preferences
        if (empty($_SESSION['preferred_departments']) || in_array($d_name, $_SESSION['preferred_departments'])) {
            $departments[] = $d_name;
        }
    }
}

include_once '../includes/header.php';

function generatePageUrl($param_overrides = []) {
    $params = array_merge($_GET, $param_overrides);
    $params['route'] = 'system_readiness'; // Force explicitly
    if (isset($param_overrides['status_filter']) && !isset($param_overrides['page'])) {
        unset($params['page']);
    }
    return APP_BASE_PATH . 'index.php?' . http_build_query($params);
}

$status_states = ['all' => 'All Statuses', 'not_started' => 'Not Started', 'Preparing' => 'Preparing', 'Verification Pending' => 'Verification Pending', 'Completed' => 'Completed'];
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
    
    html, body {
        height: 100%;
        margin: 0;
    }

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
    
    .table-responsive {
        flex: 1; 
    }

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

    .form-control-search { border-radius: 6px; border: 1px solid #d1d5db; padding: 6px 10px; font-size: 0.8rem; height: 34px; }
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
    .table-custom th:first-child, .table-custom td:first-child { text-align: left; padding-left: 16px; }
    .table-custom td {
        padding: 8px 12px; 
        vertical-align: middle; 
        font-size: 0.8rem; 
        color: #374151; 
        border-bottom: 1px solid #f3f4f6;
        text-align: center;
    }
    .clickable-row { transition: background-color 0.2s; }
    .clickable-row:hover td { background-color: #f9fafb; cursor: pointer; }

    /* Compact Badges */
    .status-badge {
        padding: 3px 8px; 
        border-radius: 6px; 
        font-size: 0.7rem; 
        font-weight: 600;
        display: inline-block;
    }

    /* Action Dropdown */
    .action-btn-container .dropdown-toggle { background: none; border: none; color: #94a3b8; border-radius: 6px; padding: 2px 6px; }
    .action-btn-container .dropdown-toggle::after { display: none; }
    .action-btn-container .dropdown-toggle:hover { background-color: #f1f5f9; color: var(--primary-color);}
    .dropdown-menu { border: none; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05); border-radius: 8px; padding: 8px; }
    .dropdown-item { border-radius: 6px; display: flex; align-items: center; gap: 0.75rem; font-size: 0.8rem; font-weight: 500; padding: 6px 12px; color: #475569;}
    .dropdown-item:hover { background-color: #f1f5f9; color: var(--primary-color); }
    .dropdown-item.text-danger:hover { background-color: #fef2f2; color: #dc2626; }

    /* Filters */
    .filter-link { text-decoration: none; display: inline-flex; align-items: center; gap: 0.25rem; font-weight: 600; color: inherit; }
    .filter-link:hover { color: var(--primary-color); }
    .filter-dropdown .dropdown-item.active { background-color: var(--primary-color); color: #ffffff !important; }

    /* Pagination */
    .pagination .page-link { color: #374151; border: 1px solid #e5e7eb; margin: 0 2px; border-radius: 6px !important; font-size: 0.8rem; padding: 6px 10px; }
    .pagination .page-item.active .page-link { background-color: var(--primary-color); border-color: var(--primary-color); color: white; }
    .pagination .page-item.disabled .page-link { background-color: #f8fafc; color: #cbd5e1; border-color: #e5e7eb; }
    
    .small-meta { font-size: 0.7rem; color: #6b7280; }

    /* Custom Checkbox Dropdown styling */
    .dropdown-menu-checkboxes .form-check { margin-bottom: 0.5rem; }
    .dropdown-menu-checkboxes .form-check-label { font-size: 0.8rem; cursor: pointer; color: #374151; }
    .dropdown-menu-checkboxes .form-check-input { cursor: pointer; }
    .dropdown-menu-checkboxes .form-check-input:checked { background-color: var(--primary-color); border-color: var(--primary-color); }
</style>

<div class="main-content-wrapper container-fluid pt-4 px-4">

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end mb-3 gap-3">
        <div>
            <h5 class="fw-bold m-0 text-dark">Manage System Readiness</h5>
            <div class="text-muted small-meta mt-1">Track and update employee PC setups and handover forms</div>
        </div>
        
        <form action="<?= APP_BASE_PATH ?>index.php" method="GET" class="d-flex align-items-center gap-2 pb-2">
            <input type="hidden" name="route" value="system_readiness">
            <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter); ?>">
            <?php foreach ($dept_filter_array as $d): ?>
                <input type="hidden" name="department_filter[]" value="<?= htmlspecialchars($d); ?>">
            <?php endforeach; ?>
            <input type="text" class="form-control form-control-search" name="search_term" placeholder="Search ID or Name..." value="<?php echo htmlspecialchars($search_term); ?>" style="width: 250px;">
            <button type="submit" class="btn btn-primary-pro">Search</button>
            
            <a href="<?= APP_BASE_PATH ?>index.php?route=system_readiness&reset_filters=1" class="btn btn-outline-secondary">Clear</a>
            
            <?php if (can('manage_system_readiness')): ?>
                <a href="<?= APP_BASE_PATH ?>index.php?route=create_new_checklist" class="btn btn-primary-pro d-flex align-items-center">
                    <i data-feather="plus" style="width:14px;" class="me-1"></i> New
                </a>
            <?php endif; ?>
        </form>
    </div>

    <div class="pro-card">
        <div class="table-responsive overflow-visible">
            <table class="table-custom table-hover">
                <thead>
                    <tr>
                        <th>Checklist No.</th>
                        <th style="text-align: left;">Employee Details</th>
                        
                        <th>
                            <div class="dropdown d-inline-block">
                                <a href="#" class="filter-link dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside">
                                    Department
                                    <?php if (!empty($dept_filter_array)): ?>
                                        <span class="badge bg-primary rounded-pill ms-1" style="font-size:0.6rem; padding: 2px 5px;"><?= count($dept_filter_array); ?></span>
                                    <?php endif; ?>
                                </a>
                                <div class="dropdown-menu shadow border-0 p-3 dropdown-menu-checkboxes" style="min-width: 220px; max-height: 350px; overflow-y: auto;">
                                    <form action="<?= APP_BASE_PATH ?>index.php" method="GET">
                                        <input type="hidden" name="route" value="system_readiness">
                                        <input type="hidden" name="status_filter" value="<?= htmlspecialchars($status_filter); ?>">
                                        <?php if (!empty($search_term)): ?><input type="hidden" name="search_term" value="<?= htmlspecialchars($search_term); ?>"><?php endif; ?>

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

                        <th>Prepared By</th>
                        <th>Approved By</th>
                        <th>
                            <div class="dropdown d-inline-block">
                                <a href="#" class="filter-link dropdown-toggle" data-bs-toggle="dropdown">
                                    Status
                                    <i data-feather="filter" style="width:12px; height:12px;"></i>
                                </a>
                                <ul class="dropdown-menu dropdown-menu-end filter-dropdown">
                                    <?php foreach ($status_states as $key => $value): ?>
                                    <li><a class="dropdown-item <?php if($status_filter == $key) echo 'active'; ?>" href="<?php echo generatePageUrl(['status_filter' => $key]); ?>"><?php echo $value; ?></a></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </th>
                        <th>User Acceptance</th>
                        <th>Gatepass</th>
                        <th>Shipment</th>
                        <th style="width: 60px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($checklists)) : ?>
                        <tr><td colspan="10" class="text-center text-muted py-5"><i data-feather="inbox" class="mb-2" style="width:32px; height:32px; opacity:0.3;"></i><br>No checklists found matching your criteria.</td></tr>
                    <?php else : ?>
                        <?php foreach ($checklists as $checklist) :
                            $full_name = htmlspecialchars(trim($checklist['first_name'] . ' ' . $checklist['last_name']));
                        ?>
                            <tr class="clickable-row" data-id="<?php echo $checklist['id']; ?>">
                                <td class="fw-bold text-primary">
                                    <a href="<?= APP_BASE_PATH ?>index.php?route=system_readiness_checklist&checklist_id=<?php echo $checklist['id']; ?>" class="text-decoration-none">
                                        #<?php echo $checklist['id']; ?>
                                    </a>
                                </td>
                                <td style="text-align: left;">
                                    <div class="fw-bold text-dark mb-1"><?php echo $full_name; ?></div>
                                    <div class="small-meta">ID: <?php echo htmlspecialchars($checklist['emp_id']); ?></div>
                                </td>
                                
                                <td><?php echo htmlspecialchars($checklist['department'] ?? 'N/A'); ?></td>
                                
                                <td>
                                    <div class="fw-medium text-dark"><?php echo htmlspecialchars($checklist['prepared_by'] ?? 'N/A'); ?></div>
                                    <?php if (!empty($checklist['prepared_at'])): ?>
                                        <div class="small-meta"><?php echo htmlspecialchars(date('d M Y, H:i', strtotime($checklist['prepared_at']))); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-medium text-dark"><?php echo htmlspecialchars($checklist['approved_by'] ?? 'N/A'); ?></div>
                                    <?php if (!empty($checklist['approved_at'])): ?>
                                        <div class="small-meta"><?php echo htmlspecialchars(date('d M Y, H:i', strtotime($checklist['approved_at']))); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                        $status = $checklist['overall_status'] ?? 'Not Started';
                                        if ($status === 'Not Started' || $status === null) $status = 'Not Started';
                                        $badgeClass = match (strtolower($status)) {
                                            'completed' => 'bg-success-subtle text-success-emphasis',
                                            'preparing' => 'bg-info-subtle text-info-emphasis',
                                            'verification pending', 'pending approval' => 'bg-warning-subtle text-warning-emphasis',
                                            default => 'bg-secondary-subtle text-secondary-emphasis',
                                        };
                                        echo "<span class=\"status-badge {$badgeClass}\">" . htmlspecialchars($status) . "</span>";
                                    ?>
                                </td>
                                <td>
                                    <?php
                                        $ua_status = $checklist['user_acceptance_status'] ?? 'Pending';
                                        $uaBadgeClass = match (strtolower($ua_status)) {
                                            'accepted' => 'bg-success-subtle text-success-emphasis',
                                            'rejected' => 'bg-danger-subtle text-danger-emphasis',
                                            'pending' => 'bg-warning-subtle text-warning-emphasis',
                                            default => 'bg-secondary-subtle text-secondary-emphasis',
                                        };
                                        echo "<span class=\"status-badge {$uaBadgeClass} mb-1\">" . htmlspecialchars($ua_status) . "</span>";
                                        
                                        if (!empty($checklist['user_acceptance_date'])) {
                                            echo "<div class=\"small-meta\">" . htmlspecialchars(date('d M Y, H:i', strtotime($checklist['user_acceptance_date']))) . "</div>";
                                        }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                        $gatepass_status = $checklist['gatepass_status'];
                                        $gatepassBadgeClass = match (strtolower($gatepass_status)) {
                                            'approved' => 'bg-success-subtle text-success-emphasis',
                                            'pending approval' => 'bg-warning-subtle text-warning-emphasis',
                                            'returned' => 'bg-primary-subtle text-primary-emphasis',
                                            'rejected' => 'bg-danger-subtle text-danger-emphasis',
                                            'created' => 'bg-info-subtle text-info-emphasis',
                                            'not required' => 'bg-light text-muted border',
                                            'not created' => 'bg-secondary-subtle text-secondary-emphasis',
                                            default => 'bg-secondary-subtle text-secondary-emphasis',
                                        };
                                        $badgeHTML = "<span class=\"status-badge {$gatepassBadgeClass}\">" . htmlspecialchars($gatepass_status) . "</span>";
                                        
                                        if ($checklist['gatepass_id'] > 0) {
                                            echo "<a href='" . APP_BASE_PATH . "index.php?route=add_edit_gatepass&id={$checklist['gatepass_id']}' class='text-decoration-none'>{$badgeHTML}</a>";
                                        } else {
                                            echo "<span data-bs-toggle='tooltip' data-bs-placement='top' title='No gatepass linked'>{$badgeHTML}</span>";
                                        }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                        $shipment_status_text = '';
                                        $can_create_shipment = isset($checklist['gatepass_id']) && $checklist['gatepass_id'] > 0;

                                        if (!$can_create_shipment) {
                                            $shipment_status_text = 'Not Required';
                                        } elseif (!empty($checklist['shipment_status'])) {
                                            $shipment_status_text = $checklist['shipment_status'];
                                        } else {
                                            $shipment_status_text = 'Not Created';
                                        }

                                        $shipmentBadgeClass = match (strtolower($shipment_status_text)) {
                                            'delivered' => 'bg-success-subtle text-success-emphasis',
                                            'shipped', 'in transit' => 'bg-info-subtle text-info-emphasis',
                                            'new' => 'bg-primary-subtle text-primary-emphasis',
                                            'cancelled' => 'bg-danger-subtle text-danger-emphasis',
                                            'not created' => 'bg-secondary-subtle text-secondary-emphasis',
                                            default => 'bg-light text-muted border', 
                                        };
                                        $shipmentBadgeHTML = "<span class=\"status-badge {$shipmentBadgeClass}\">" . htmlspecialchars($shipment_status_text) . "</span>";

                                        if ($can_create_shipment) {
                                            echo "<a href='" . APP_BASE_PATH . "index.php?route=add_edit_shipment&from_gatepass_id={$checklist['gatepass_id']}' class='text-decoration-none'>{$shipmentBadgeHTML}</a>";
                                        } else {
                                            $tooltip_text = ($checklist['gatepass_id'] == -1) 
                                                ? 'Shipment is not applicable as no gatepass is required.' 
                                                : 'A shipment cannot be created until a gatepass is created.';
                                            echo "<span data-bs-toggle='tooltip' data-bs-placement='top' title='{$tooltip_text}'>{$shipmentBadgeHTML}</span>";
                                        }
                                    ?>
                                </td>
                                <td>
                                    <div class="dropdown action-btn-container text-center">
                                        <button class="btn dropdown-toggle border" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i data-feather="more-horizontal" style="width:14px; height:14px;"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <?php if (can('download_checklist_pdf')): ?>
                                            <?php 
                                                $isChecklistCompleted = ($checklist['overall_status'] ?? '') === 'Completed';
                                                $li_attrs_checklist = !$isChecklistCompleted ? 'data-bs-toggle="tooltip" data-bs-placement="left" title="PDF available after completion."' : '';
                                            ?>
                                            <li <?php echo $li_attrs_checklist; ?>>
                                                <a class="dropdown-item <?php if (!$isChecklistCompleted) echo 'disabled text-muted'; ?>" 
                                                   href="<?php echo $isChecklistCompleted ? APP_BASE_PATH . 'index.php?route=generate_checklist_pdf&checklist_id=' . $checklist['id'] : '#'; ?>" 
                                                   target="_blank">
                                                    <i data-feather="file-text" style="width:14px;" class="text-primary"></i> Checklist PDF
                                                </a>
                                            </li>
                                            <li <?php echo $li_attrs_checklist; ?>>
                                                <a class="dropdown-item <?php if (!$isChecklistCompleted) echo 'disabled text-muted'; ?>" 
                                                   href="<?php echo $isChecklistCompleted ? APP_BASE_PATH . 'index.php?route=generate_acceptance_pdf&checklist_id=' . $checklist['id'] : '#'; ?>" 
                                                   target="_blank">
                                                    <i data-feather="check-square" style="width:14px;" class="text-success"></i> Acceptance PDF
                                                </a>
                                            </li>

                                            <?php endif; ?>
                                            <?php if (can('delete_system_readiness')): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" href="#" data-bs-toggle="modal" data-bs-target="#deleteConfirmModal" data-checklist-id="<?php echo $checklist['id']; ?>" data-employee-name="<?php echo $full_name; ?>">
                                                    <i data-feather="trash-2" style="width:14px;"></i> Delete
                                                </a>
                                            </li>
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

        <div class="d-flex justify-content-between align-items-center p-3 card-footer-pagination">
            <small class="text-muted">Showing <?php echo ($total_records > 0) ? $offset + 1 : 0; ?> to <?php echo min($offset + $records_per_page, $total_records); ?> of <?php echo $total_records; ?> entries</small>
            <?php if ($total_pages > 1): ?>
            <nav>
                <ul class="pagination mb-0">
                    <li class="page-item <?php if($current_page <= 1) echo 'disabled'; ?>"><a class="page-link" href="<?php echo generatePageUrl(['page' => 1]); ?>">&laquo;</a></li>
                    <li class="page-item <?php if($current_page <= 1) echo 'disabled'; ?>"><a class="page-link" href="<?php echo generatePageUrl(['page' => $current_page - 1]); ?>">&lsaquo;</a></li>
                    <li class="page-item active"><span class="page-link"><?php echo $current_page; ?></span></li>
                    <li class="page-item <?php if($current_page >= $total_pages) echo 'disabled'; ?>"><a class="page-link" href="<?php echo generatePageUrl(['page' => $current_page + 1]); ?>">&rsaquo;</a></li>
                    <li class="page-item <?php if($current_page >= $total_pages) echo 'disabled'; ?>"><a class="page-link" href="<?php echo generatePageUrl(['page' => $total_pages]); ?>">&raquo;</a></li>
                </ul>
            </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow" style="border-radius: 12px; border-left: 3px solid #dc2626;">
            <div class="modal-header border-0 bg-white" style="border-radius: 12px 12px 0 0;">
                <h5 class="modal-title fw-bold text-danger" style="font-size: 1rem;"><i data-feather="alert-triangle" style="width:18px;" class="me-2"></i> Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-muted" style="font-size: 0.9rem;">
                Are you sure you want to delete the checklist for <strong id="employeeNameInModal" class="text-dark"></strong>?<br><br>This action cannot be undone.
            </div>
            <div class="modal-footer border-0">
                <form id="deleteForm" method="POST" action="<?= APP_BASE_PATH ?>index.php?route=system_readiness" class="w-100 d-flex justify-content-end gap-2 m-0">
                    <input type="hidden" name="action" value="delete_checklist">
                    <input type="hidden" name="checklist_id" id="checklistIdToDelete">
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-danger rounded-pill px-4">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3">
    <div id="formToast" class="toast align-items-center text-white bg-success border-0 shadow" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body" id="toastBody"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<div class="modal fade" id="messageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px;">
            <div class="modal-header border-0"><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body text-center pb-4" id="messageModalBody"></div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof feather !== 'undefined') {
        feather.replace();
    }
    
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    const deleteModalEl = document.getElementById('deleteConfirmModal');
    if (deleteModalEl) {
        deleteModalEl.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const checklistId = button.dataset.checklistId;
            const employeeName = button.dataset.employeeName;
            
            document.getElementById('checklistIdToDelete').value = checklistId;
            document.getElementById('employeeNameInModal').textContent = employeeName;
        });
    }

    document.querySelector('.table-custom > tbody').addEventListener('click', function(event) {
        const row = event.target.closest('tr.clickable-row');
        if (!row) return;

        // Don't trigger if clicking a button, link, or dropdown or filter elements
        if (event.target.closest('.action-btn-container, a, input, label, [data-bs-toggle="tooltip"]')) {
            return;
        }

        const checklistId = row.dataset.id;
        if (checklistId) {
            window.location.href = `<?= APP_BASE_PATH ?>index.php?route=system_readiness_checklist&checklist_id=${checklistId}`;
        }
    });

    // Elegant Messages via Toast/Modal
    const phpMessage = <?php echo json_encode($message); ?>;
    const phpError = <?php echo json_encode($error); ?>;
    if (phpMessage || phpError) {
        if (phpMessage) {
            const toastBody = document.getElementById('toastBody');
            toastBody.textContent = phpMessage;
            const toast = new bootstrap.Toast(document.getElementById('formToast'));
            toast.show();
        } else {
            const messageModal = new bootstrap.Modal(document.getElementById('messageModal'));
            const messageModalBody = document.getElementById('messageModalBody');
            messageModalBody.innerHTML = `
                <div class="text-danger mb-3"><i data-feather="x-circle" style="width:48px; height:48px;"></i></div>
                <h5 class="fw-bold mb-2">Error</h5>
                <p class="text-muted m-0">${phpError}</p>
            `;
            feather.replace();
            messageModal.show();
        }
    }
});
</script>

<?php
include_once '../includes/footer.php'; 
?>