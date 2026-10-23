<?php
include_once '../includes/error_handler.php';
include_once '../includes/db_connect.php';
include_once '../includes/auth.php';

// Wrapped in a function_exists check to prevent fatal errors
if (!function_exists('log_action')) {
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
}


checkPermission('view_gatepass');

// --- AJAX Delete Handling ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete_gatepass') {
    header('Content-Type: application/json');
    if (!can('delete_gatepass')) {
        echo json_encode(['success' => false, 'message' => 'You do not have permission to delete this gatepass.']);
        exit();
    }
    
    $delete_id = filter_input(INPUT_POST, 'delete_id', FILTER_VALIDATE_INT);
    if (!$delete_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid ID provided.']);
        exit();
    }

    $conn->begin_transaction();
    try {
        $gatepass_no = 'N/A';
        $stmt_get = $conn->prepare("SELECT gatepass_no FROM gatepass_requests WHERE id = ?");
        $stmt_get->bind_param("i", $delete_id);
        $stmt_get->execute();
        $result = $stmt_get->get_result();
        if($result->num_rows > 0) {
            $gatepass_no = $result->fetch_assoc()['gatepass_no'];
        }
        $stmt_get->close();

        $stmt_unlink = $conn->prepare("UPDATE checklists SET gatepass_id = NULL WHERE gatepass_id = ?");
        $stmt_unlink->bind_param("i", $delete_id);
        $stmt_unlink->execute();
        $stmt_unlink->close();

        $stmt_items = $conn->prepare("DELETE FROM gatepass_items WHERE gatepass_id = ?");
        $stmt_items->bind_param("i", $delete_id);
        $stmt_items->execute();
        $stmt_items->close();

        $stmt_delete = $conn->prepare("DELETE FROM gatepass_requests WHERE id = ?");
        $stmt_delete->bind_param("i", $delete_id);
        $stmt_delete->execute();
        
        if ($stmt_delete->affected_rows > 0) {
            log_action($conn, 'Gatepass', "Deleted Gatepass: " . $gatepass_no);
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Gatepass deleted successfully.']);
        } else {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Gatepass not found or already deleted.']);
        }
        $stmt_delete->close();

    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

$pageTitle = "Gatepass";
$search_filter = $_GET['search'] ?? '';
$status_filter = $_GET['status_filter'] ?? 'all';
$purpose_filter = $_GET['purpose_filter'] ?? 'all';
$type_filter = $_GET['type_filter'] ?? 'all';

$base_sql = "FROM gatepass_requests gp LEFT JOIN gp_vendors v ON gp.vendor_id = v.id";
$where_clauses = [];
$params = [];
$types = '';

if (!empty($search_filter)) {
    $where_clauses[] = "(gp.gatepass_no LIKE ? OR gp.employee_name LIKE ? OR v.vendor_name LIKE ? OR gp.created_by LIKE ?)";
    $search_param = "%{$search_filter}%";
    array_push($params, $search_param, $search_param, $search_param, $search_param);
    $types .= 'ssss';
}
if ($status_filter !== 'all') {
    $where_clauses[] = "gp.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}
if ($purpose_filter !== 'all') {
    $where_clauses[] = "gp.purpose = ?";
    $params[] = $purpose_filter;
    $types .= 's';
}
if ($type_filter !== 'all') {
    $where_clauses[] = "gp.gatepass_type = ?";
    $params[] = $type_filter;
    $types .= 's';
}

$where_sql = empty($where_clauses) ? '' : 'WHERE ' . implode(' AND ', $where_clauses);

$records_per_page = 15;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

$count_query = "SELECT COUNT(DISTINCT gp.id) " . $base_sql . " " . $where_sql;
$stmt_count = $conn->prepare($count_query);
if(!empty($params)) { $stmt_count->bind_param($types, ...$params); }
$stmt_count->execute();
$total_records = $stmt_count->get_result()->fetch_row()[0];
$total_pages = ceil($total_records / $records_per_page);
$stmt_count->close();

$gatepasses = [];
$query = "SELECT gp.id, gp.gatepass_no, gp.gatepass_type, gp.employee_name, v.vendor_name, gp.purpose, gp.status, gp.created_at, gp.created_by, gp.approved_by, gp.approved_at " . $base_sql . " " . $where_sql . " ORDER BY gp.id DESC LIMIT ? OFFSET ?";
array_push($params, $records_per_page, $offset);
$types .= 'ii';
$stmt = $conn->prepare($query);
if(!empty($params)) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $gatepasses[] = $row;
}
$stmt->close();

$distinct_purposes = [];
$purpose_result = $conn->query("SELECT DISTINCT purpose FROM gatepass_requests WHERE purpose IS NOT NULL AND purpose != '' ORDER BY purpose ASC");
while($row = $purpose_result->fetch_assoc()) {
    $distinct_purposes[] = $row['purpose'];
}

$status_states = ['all' => 'All Statuses', 'Pending Approval' => 'Pending Approval', 'Approved' => 'Approved', 'Returned' => 'Returned'];
$type_states = ['all' => 'All Types', 'Employee' => 'Employee', 'Vendor' => 'Vendor', 'Site-to-Site' => 'Site-to-Site'];

if (!function_exists('generateFilterUrl')) {
    function generateFilterUrl($param_to_change, $next_value) {
        $params = $_GET;
        $params['route'] = 'gatepass'; // Route included explicitly
        $params[$param_to_change] = $next_value;
        if($param_to_change !== 'page') { unset($params['page']); }
        return APP_BASE_PATH . 'index.php?' . http_build_query($params);
    }
}

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
    
    .table-responsive { flex: 1; }

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
</style>

<div class="main-content-wrapper container-fluid pt-4 px-4">

    <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1090;">
        <div id="formToast" class="toast align-items-center border-0 shadow" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body fw-medium" id="toastBody"></div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end mb-3 gap-3">
        <div>
            <h4 class="fw-bold m-0 text-dark">Manage Gatepass</h4>
            <div class="text-muted small-meta mt-1">Track and manage employee, vendor, and site-to-site gatepasses</div>
        </div>
        
        <form action="<?= APP_BASE_PATH ?>index.php" method="GET" class="d-flex align-items-center gap-2 pb-2">
            <input type="hidden" name="route" value="gatepass">
            <input type="hidden" name="purpose_filter" value="<?php echo htmlspecialchars($purpose_filter); ?>">
            <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter); ?>">
            <input type="hidden" name="type_filter" value="<?php echo htmlspecialchars($type_filter); ?>">
            <input type="text" class="form-control form-control-search" name="search" placeholder="Search ID or Name..." value="<?php echo htmlspecialchars($search_filter); ?>" style="width: 250px;">
            
            <button type="submit" class="btn btn-primary-pro">Search</button>
            <?php if(!empty($search_filter) || $purpose_filter !== 'all' || $status_filter !== 'all' || $type_filter !== 'all'): ?>
                <a href="<?= APP_BASE_PATH ?>index.php?route=gatepass" class="btn btn-outline-secondary">Clear</a>
            <?php endif; ?>
            
            <?php if (can('create_gatepass')): ?>
                <a href="<?= APP_BASE_PATH ?>index.php?route=add_edit_gatepass" class="btn btn-primary-pro d-flex align-items-center ms-1">
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
                        <th class="text-start" style="padding-left: 16px;">GP No.</th>
                        <th>
                            <div class="dropdown d-inline-block">
                                <a href="#" class="filter-link dropdown-toggle" data-bs-toggle="dropdown">Type <i data-feather="filter" style="width:12px; height:12px;"></i></a>
                                <ul class="dropdown-menu filter-dropdown">
                                    <?php foreach ($type_states as $key => $value): ?>
                                    <li><a class="dropdown-item <?php if($type_filter == $key) echo 'active'; ?>" href="<?php echo generateFilterUrl('type_filter', $key); ?>"><?php echo $value; ?></a></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </th>
                        <th>Name</th>
                        <th>
                            <div class="dropdown d-inline-block">
                                <a href="#" class="filter-link dropdown-toggle" data-bs-toggle="dropdown">Purpose <i data-feather="filter" style="width:12px; height:12px;"></i></a>
                                <ul class="dropdown-menu filter-dropdown">
                                    <li><a class="dropdown-item <?php if($purpose_filter == 'all') echo 'active'; ?>" href="<?php echo generateFilterUrl('purpose_filter', 'all'); ?>">All Purposes</a></li>
                                    <?php foreach ($distinct_purposes as $purpose): ?>
                                    <li><a class="dropdown-item <?php if($purpose_filter == $purpose) echo 'active'; ?>" href="<?php echo generateFilterUrl('purpose_filter', $purpose); ?>"><?php echo htmlspecialchars($purpose); ?></a></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </th>
                        <th>
                            <div class="dropdown d-inline-block">
                                 <a href="#" class="filter-link dropdown-toggle" data-bs-toggle="dropdown">Status <i data-feather="filter" style="width:12px; height:12px;"></i></a>
                                 <ul class="dropdown-menu filter-dropdown">
                                     <?php foreach ($status_states as $key => $value): ?>
                                     <li><a class="dropdown-item <?php if($status_filter == $key) echo 'active'; ?>" href="<?php echo generateFilterUrl('status_filter', $key); ?>"><?php echo $value; ?></a></li>
                                     <?php endforeach; ?>
                                 </ul>
                            </div>
                        </th>
                        <th>Created By</th>
                        <th>Approved By</th>
                        <th style="width: 60px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($gatepasses)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-5"><i data-feather="inbox" class="mb-2" style="width:32px; height:32px; opacity:0.3;"></i><br>No gatepass requests found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($gatepasses as $gp): ?>
                            <tr class="clickable-row" data-href="<?= APP_BASE_PATH ?>index.php?route=add_edit_gatepass&id=<?php echo $gp['id']; ?>">
                                <td class="fw-bold text-primary text-start" style="padding-left: 16px;">
                                    <?php echo htmlspecialchars($gp['gatepass_no']); ?>
                                </td>
                                <td>
                                    <?php
                                        $type_badge_class = match ($gp['gatepass_type']) {
                                            'Employee' => 'bg-info-subtle text-info-emphasis',
                                            'Site-to-Site' => 'bg-primary-subtle text-primary-emphasis',
                                            default => 'bg-secondary-subtle text-secondary-emphasis',
                                        };
                                    ?>
                                    <span class="status-badge <?php echo $type_badge_class; ?>"><?php echo htmlspecialchars($gp['gatepass_type']); ?></span>
                                </td>
                                <td class="fw-bold text-dark">
                                    <?php echo htmlspecialchars($gp['gatepass_type'] === 'Vendor' ? $gp['vendor_name'] : $gp['employee_name']); ?>
                                </td>
                                <td><?php echo htmlspecialchars($gp['purpose']); ?></td>
                                <td>
                                    <?php
                                        $status_badge_class = match ($gp['status']) {
                                            'Approved' => 'bg-success-subtle text-success-emphasis',
                                            'Pending Approval' => 'bg-warning-subtle text-warning-emphasis',
                                            'Returned' => 'bg-primary-subtle text-primary-emphasis',
                                            default => 'bg-secondary-subtle text-secondary-emphasis',
                                        };
                                    ?>
                                    <span class="status-badge <?php echo $status_badge_class; ?>"><?php echo htmlspecialchars($gp['status']); ?></span>
                                </td>
                                <td>
                                    <div class="fw-medium text-dark"><?php echo htmlspecialchars($gp['created_by']); ?></div>
                                    <div class="small-meta"><?php echo date('d M Y, H:i', strtotime($gp['created_at'])); ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($gp['approved_by']) && !empty($gp['approved_at'])): ?>
                                        <div class="fw-medium text-dark"><?php echo htmlspecialchars($gp['approved_by']); ?></div>
                                        <div class="small-meta"><?php echo date('d M Y, H:i', strtotime($gp['approved_at'])); ?></div>
                                    <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="dropdown action-btn-container">
                                        <button class="btn btn-sm dropdown-toggle border py-0 px-1" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i data-feather="more-horizontal" style="width:14px; height:14px;"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <?php
                                            $isApproved = $gp['status'] === 'Approved';
                                            $canDownload = can('download_gatepass_pdf');
                                            $isDownloadable = $isApproved && $canDownload;
                                            
                                            $li_attrs = '';
                                            if (!$isDownloadable) {
                                                $tooltip_text = !$canDownload ? 'Permission denied' : 'Available after approval';
                                                $li_attrs = 'data-bs-toggle="tooltip" data-bs-placement="left" title="' . $tooltip_text . '"';
                                            }
                                            ?>
                                            <li <?php echo $li_attrs; ?>>
                                                <a class="dropdown-item <?php if (!$isDownloadable) echo 'disabled text-muted'; ?>" href="<?php echo $isDownloadable ? APP_BASE_PATH . 'index.php?route=generate_gatepass_pdf&id=' . $gp['id'] : '#'; ?>" target="_blank">
                                                    <i data-feather="download" class="text-primary"></i> Gatepass PDF
                                                </a>
                                            </li>
                                            
                                            <?php if (can('delete_gatepass')): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li><a class="dropdown-item text-danger delete-gatepass-btn" href="#" data-gatepass-id="<?php echo $gp['id']; ?>"><i data-feather="trash-2"></i> Delete</a></li>
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
                    <li class="page-item <?php echo ($current_page <= 1) ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo generateFilterUrl('page', 1); ?>">&laquo;</a></li>
                    <li class="page-item <?php echo ($current_page <= 1) ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo generateFilterUrl('page', $current_page - 1); ?>">&lsaquo;</a></li>
                    <li class="page-item active"><span class="page-link"><?php echo $current_page; ?></span></li>
                    <li class="page-item <?php echo ($current_page >= $total_pages) ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo generateFilterUrl('page', $current_page + 1); ?>">&rsaquo;</a></li>
                    <li class="page-item <?php echo ($current_page >= $total_pages) ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo generateFilterUrl('page', $total_pages); ?>">&raquo;</a></li>
                </ul>
            </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal fade modal-elegant" id="deleteConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="border: none; border-radius: 12px; box-shadow: 0 15px 35px rgba(0,0,0,0.1); border-left: 3px solid #dc2626;">
            <div class="modal-header border-0 bg-white" style="border-radius: 12px 12px 0 0; padding: 15px 20px;">
                <h5 class="modal-title fw-bold text-danger" style="font-size: 1rem;"><i data-feather="alert-triangle" style="width:18px;" class="me-2"></i> Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-muted" style="padding: 0 20px 15px; font-size: 0.9rem;">
                Are you sure you want to delete this gatepass? This action cannot be undone.
            </div>
            <div class="modal-footer border-0" style="padding: 0 20px 20px;">
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

    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    function showToast(message, type) {
        const toastElement = document.getElementById('formToast');
        if (!toastElement) return;
        
        const toastBodyEl = document.getElementById('toastBody');
        toastBodyEl.innerHTML = message;
        
        // Reset classes
        toastElement.className = 'toast align-items-center border-0 shadow';
        
        // Add specific classes based on type
        if (type === 'success') {
            toastElement.classList.add('bg-success', 'text-white');
        } else if (type === 'danger' || type === 'error') {
            toastElement.classList.add('bg-danger', 'text-white');
        }
        
        const formToast = bootstrap.Toast.getOrCreateInstance(toastElement);
        formToast.show();
    }
    
    const pageMessage = <?php echo json_encode($_SESSION['page_message'] ?? null); unset($_SESSION['page_message']); ?>;
    if (pageMessage && pageMessage.context === 'gatepass') {
        showToast(pageMessage.text, pageMessage.type);
    }

    document.querySelectorAll('.clickable-row').forEach(row => {
        row.addEventListener('click', function(event) {
            if (event.target.closest('a, button, .dropdown')) {
                return; 
            }
            window.location.href = this.dataset.href;
        });
    });

    const deleteConfirmModalEl = document.getElementById('deleteConfirmModal');
    if(deleteConfirmModalEl) {
        const deleteConfirmModal = new bootstrap.Modal(deleteConfirmModalEl);
        const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
        let gatepassIdToDelete = null;
        let rowToDelete = null;

        document.querySelectorAll('.delete-gatepass-btn').forEach(button => {
            button.addEventListener('click', function(event) {
                event.preventDefault();
                event.stopPropagation();
                gatepassIdToDelete = this.dataset.gatepassId;
                rowToDelete = this.closest('tr');
                deleteConfirmModal.show();
            });
        });

        confirmDeleteBtn.addEventListener('click', () => {
            if (gatepassIdToDelete) {
                const formData = new FormData();
                formData.append('action', 'delete_gatepass');
                formData.append('delete_id', gatepassIdToDelete);

                fetch('<?= APP_BASE_PATH ?>index.php?route=gatepass', {
                    method: 'POST',
                    headers: {'X-Requested-With': 'XMLHttpRequest'},
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message, 'success');
                        if (rowToDelete) {
                            rowToDelete.remove();
                        }
                    } else {
                        showToast(data.message, 'danger');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showToast('An unexpected error occurred.', 'danger');
                })
                .finally(() => {
                    deleteConfirmModal.hide();
                    gatepassIdToDelete = null;
                    rowToDelete = null;
                });
            }
        });
    }
});
</script>