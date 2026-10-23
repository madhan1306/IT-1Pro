<?php
/**
 * exit_checklist.php
 *
 * This file displays a list of employee exit checklists.
 * It has been corrected to use the new unified 'checklists' and 'employees' tables.
 */

include_once '../includes/db_connect.php';
include_once '../includes/auth.php';

checkPermission('view_exit_checklist'); 

// =========================================================================================
// --- STICKY FILTERS & PAGINATION LOGIC ---
// =========================================================================================
if (isset($_GET['reset_filters'])) {
    unset($_SESSION['exit_checklist_list_state']);
    header("Location: " . APP_BASE_PATH . "index.php?route=exit_checklist");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $has_filters = false;
    foreach ($_GET as $key => $val) {
        if ($key !== 'route' && $key !== 'reset_filters') { $has_filters = true; break; }
    }

    $current_uri = $_SERVER['REQUEST_URI'];
    $saved_uri = $_SESSION['exit_checklist_list_state'] ?? '';

    // If navigating to base URL and we have a saved state that is DIFFERENT, restore it
    if (!$has_filters && !empty($saved_uri) && $saved_uri !== $current_uri) {
        header("Location: " . $saved_uri);
        exit();
    }

    // Only save the state if the user actually applied filters or changed a page
    if ($has_filters) {
        $_SESSION['exit_checklist_list_state'] = $current_uri;
    }
}
// =========================================================================================


$pageTitle = "Employee Exit Checklist";
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
    if (!can('delete_exit_checklist')) {
        $_SESSION['form_error'] = "You do not have permission to delete checklists.";
        header('Location: ' . APP_BASE_PATH . 'index.php?route=exit_checklist');
        exit();
    }
    
    $checklist_id_to_delete = $_POST['checklist_id'] ?? null;
    if ($checklist_id_to_delete) {
        $conn->begin_transaction();
        try {
            $stmt_del_items = $conn->prepare("DELETE FROM checklist_items WHERE template_id = ?");
            $stmt_del_items->bind_param("i", $checklist_id_to_delete);
            if (!$stmt_del_items->execute()) throw new Exception("Failed to delete checklist items.");
            $stmt_del_items->close();

            $stmt_del = $conn->prepare("DELETE FROM checklists WHERE id = ? AND checklist_type = 'exit_checklist'");
            $stmt_del->bind_param("i", $checklist_id_to_delete);
            if (!$stmt_del->execute()) throw new Exception("Failed to delete main checklist.");
            $stmt_del->close();

            $conn->commit();
            $_SESSION['form_success'] = "Checklist #" . htmlspecialchars($checklist_id_to_delete) . " and all its items were successfully deleted.";
            log_system_event($conn, 'Exit Checklist', "Deleted checklist #" . $checklist_id_to_delete);
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['form_error'] = "Error deleting checklist: " . $e->getMessage();
        }
    } else {
        $_SESSION['form_error'] = "Invalid checklist ID for deletion.";
    }
    header('Location: ' . APP_BASE_PATH . 'index.php?route=exit_checklist');
    exit();
}

$search_term = $_GET['search_term'] ?? '';
$checklists = []; 
$status_filter = $_GET['status_filter'] ?? 'all';

$records_per_page = 15; // Aligned with templates.php records per page
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
// Remove "all" or empty values from the array
$dept_filter_array = array_filter($dept_filter_array, function($v) { return $v !== 'all' && trim($v) !== ''; });

$from_and_joins = "
    FROM checklists c
    LEFT JOIN employees e ON c.employee_id = e.id
";

$sql_where_clause = ["c.checklist_type = 'exit_checklist'"];
$params = [];
$types = '';

if (!empty(trim($search_term))) {
    $search_like = "%" . trim($search_term) . "%";
    $sql_where_clause[] = "(e.emp_id LIKE ? OR CONCAT(e.first_name, ' ', e.last_name) LIKE ? OR c.id LIKE ?)";
    $params = [$search_like, $search_like, $search_like];
    $types = "sss";
}

if ($status_filter !== 'all') {
    if ($status_filter === 'Not Started') {
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
$total_records = 0;
if ($count_stmt = $conn->prepare($count_sql)) {
    if (!empty($params)) {
        $count_stmt->bind_param($types, ...$params);
    }
    if ($count_stmt->execute()) {
        $result = $count_stmt->get_result();
        $total_records = $result->fetch_row()[0] ?? 0;
    } else { $error = "Error executing count query: " . $count_stmt->error; }
    $count_stmt->close();
} else { $error = "Error preparing record count query: " . $conn->error; }

$total_pages = $total_records > 0 ? ceil($total_records / $records_per_page) : 1;

// Add e.department to the SELECT statement
$sql = "SELECT c.id, c.employee_id, c.overall_status, c.last_working_day,
               c.approved_by, c.asset_verified_by,
               e.emp_id, e.first_name, e.last_name, e.department
        {$from_and_joins}";

$sql .= $where_sql . " ORDER BY c.created_at DESC, c.id DESC LIMIT ? OFFSET ?";
$params[] = $records_per_page;
$params[] = $offset;
$types .= "ii";

if ($stmt = $conn->prepare($sql)) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $checklists[] = $row;
        }
    } else { $error = "Error executing main query: " . $stmt->error; }
    $stmt->close();
} else { $error = "Error preparing main query: " . $conn->error; }

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
    $params['route'] = 'exit_checklist'; // Force routing explicitly
    if (isset($param_overrides['status_filter']) && !isset($param_overrides['page'])) {
        unset($params['page']);
    }
    return APP_BASE_PATH . 'index.php?' . http_build_query($params);
}

$status_states = ['all' => 'All Statuses', 'Not Started' => 'Not Started', 'Preparing' => 'Preparing', 'Verification Pending' => 'Pending Approval', 'Completed' => 'Completed'];
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
            <h5 class="fw-bold m-0 text-dark">Manage Exit Checklists</h5>
            <div class="text-muted small-meta mt-1">Track and manage offboarding workflows and asset recovery</div>
        </div>
        
        <form action="<?= APP_BASE_PATH ?>index.php" method="GET" class="d-flex align-items-center gap-2 pb-2">
            <input type="hidden" name="route" value="exit_checklist">
            <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter); ?>">
            <!-- Keep selected departments active when searching -->
            <?php foreach ($dept_filter_array as $d): ?>
                <input type="hidden" name="department_filter[]" value="<?= htmlspecialchars($d); ?>">
            <?php endforeach; ?>
            <input type="text" class="form-control form-control-search" name="search_term" placeholder="Search ID or Name..." value="<?php echo htmlspecialchars(trim($search_term)); ?>" style="width: 250px;">
            <button type="submit" class="btn btn-primary-pro">Search</button>
            <?php if(!empty($search_term) || !empty($dept_filter_array) || $status_filter !== 'all'): ?>
                <a href="<?= APP_BASE_PATH ?>index.php?route=exit_checklist&reset_filters=1" class="btn btn-outline-secondary">Clear</a>
            <?php endif; ?>
            <a href="<?= APP_BASE_PATH ?>index.php?route=add_edit_exit_checklist" class="btn btn-primary-pro d-flex align-items-center">
                <i data-feather="plus" style="width:14px;" class="me-1"></i> New
            </a>
        </form>
    </div>

    <div class="pro-card">
        <div class="table-responsive overflow-visible">
            <table class="table-custom table-hover">
                <thead>
                    <tr>
                        <th>Checklist No.</th>
                        <th style="text-align: left;">Employee Details</th>
                        
                        <!-- CHECKBOX DEPARTMENT FILTER -->
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
                                        <!-- Keep track of other active filters -->
                                        <input type="hidden" name="route" value="exit_checklist">
                                        <input type="hidden" name="status_filter" value="<?= htmlspecialchars($status_filter); ?>">
                                        <?php if (!empty($search_term)): ?><input type="hidden" name="search_term" value="<?= htmlspecialchars($search_term); ?>"><?php endif; ?>

                                        <!-- Checkbox Options -->
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

                        <th>Last Working Day</th>
                        <th>Asset Verified By</th>
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
                        <th style="width: 60px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(!empty($error)): ?>
                        <tr><td colspan="8" class="text-center text-danger py-5"><i data-feather="alert-triangle" class="mb-2" style="width:32px; height:32px; opacity:0.5;"></i><br><strong>A database error occurred:</strong> <?php echo htmlspecialchars($error); ?></td></tr>
                    <?php elseif (empty($checklists)) : ?>
                        <tr><td colspan="8" class="text-center text-muted py-5"><i data-feather="inbox" class="mb-2" style="width:32px; height:32px; opacity:0.3;"></i><br>No exit checklists found matching your criteria.</td></tr>
                    <?php else : ?>
                        <?php foreach ($checklists as $checklist) :
                            $full_name = htmlspecialchars(trim($checklist['first_name'] . ' ' . $checklist['last_name']));
                        ?>
                            <tr class="clickable-row" data-id="<?php echo $checklist['id']; ?>">
                                <td class="fw-bold text-primary">
                                    <a href="<?= APP_BASE_PATH ?>index.php?route=add_edit_exit_checklist&id=<?php echo $checklist['id']; ?>" class="text-decoration-none">
                                        #<?php echo $checklist['id']; ?>
                                    </a>
                                </td>
                                <td style="text-align: left;">
                                    <div class="fw-bold text-dark mb-1"><?php echo $full_name; ?></div>
                                    <div class="small-meta">ID: <?php echo htmlspecialchars($checklist['emp_id']); ?></div>
                                </td>
                                
                                <td><?php echo htmlspecialchars($checklist['department'] ?? 'N/A'); ?></td>
                                
                                <td>
                                    <div class="fw-medium text-dark">
                                        <?php echo !empty($checklist['last_working_day']) ? date('d M Y', strtotime($checklist['last_working_day'])) : 'N/A'; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    $asset_verified_by = $checklist['asset_verified_by'] ?? '';
                                    if (($checklist['overall_status'] ?? '') === 'Preparing' && empty($asset_verified_by)) {
                                        echo '<span class="text-muted fst-italic small">In-Progress</span>';
                                    } else {
                                        echo '<div class="fw-medium text-dark">' . htmlspecialchars($asset_verified_by ?: 'N/A') . '</div>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $approved_by = $checklist['approved_by'] ?? '';
                                    if (($checklist['overall_status'] ?? '') === 'Preparing' && empty($approved_by)) {
                                        echo '<span class="text-muted fst-italic small">In-Progress</span>';
                                    } else {
                                        echo '<div class="fw-medium text-dark">' . htmlspecialchars($approved_by ?: 'N/A') . '</div>';
                                    }
                                    ?>
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
                                    <div class="dropdown action-btn-container text-center">
                                        <button class="btn dropdown-toggle border" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i data-feather="more-horizontal" style="width:14px; height:14px;"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <?php if (can('download_exit_checklist_pdf')): ?>
                                                <?php 
                                                $is_completed = ($checklist['overall_status'] ?? '') === 'Completed';
                                                $download_href = $is_completed ? APP_BASE_PATH . 'index.php?route=generate_exit_checklist_pdf&id=' . $checklist['id'] : '#';
                                                $download_disabled_class = $is_completed ? '' : 'disabled text-muted';
                                                $download_tooltip = $is_completed ? '' : 'data-bs-toggle="tooltip" data-bs-placement="left" title="PDF available after completion."';
                                                ?>
                                                <li <?php echo $download_tooltip; ?>>
                                                    <a class="dropdown-item <?php echo $download_disabled_class; ?>" href="<?php echo $download_href; ?>" target="_blank">
                                                        <i data-feather="download" style="width:14px;" class="text-primary me-2"></i> Download PDF
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                            
                                            <?php if (can('delete_exit_checklist')): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <a class="dropdown-item text-danger" href="#" data-bs-toggle="modal" data-bs-target="#deleteConfirmModal" data-checklist-id="<?php echo $checklist['id']; ?>" data-employee-name="<?php echo $full_name; ?>">
                                                        <i data-feather="trash-2" style="width:14px;" class="me-2"></i> Delete
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
                <form id="deleteForm" method="POST" action="<?= APP_BASE_PATH ?>index.php?route=exit_checklist" class="w-100 d-flex justify-content-end gap-2 m-0">
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
    
    // Initialize tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Row click navigation
    document.querySelector('.table-custom > tbody').addEventListener('click', function(event) {
        const row = event.target.closest('tr.clickable-row');
        if (!row) return;

        // Ignore clicks on links or action buttons or filter elements
        if (event.target.closest('.action-btn-container, a, input, label, [data-bs-toggle="tooltip"]')) {
            return;
        }
        
        const checklistId = row.dataset.id;
        if (checklistId) {
            window.location.href = `<?= APP_BASE_PATH ?>index.php?route=add_edit_exit_checklist&id=${checklistId}`;
        }
    });

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

<?php include_once '../includes/footer.php'; ?>