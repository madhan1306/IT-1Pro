<?php
// FILE: reports.php
// PHASE 1: INITIALIZATION & AJAX HANDLING

include_once '../includes/error_handler.php';
include_once '../includes/db_connect.php';
include_once '../includes/auth.php';

// --- Reusable Functions ---
function getDepartments() {
    global $conn;
    $departments = [];
    $sql = "(SELECT DISTINCT `department` FROM `employees` WHERE `department` IS NOT NULL AND `department` != '') 
            UNION (SELECT DISTINCT `department` FROM `gatepass_requests` WHERE `department` IS NOT NULL AND `department` != '') 
            ORDER BY `department` ASC";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) { while ($row = $result->fetch_assoc()) { $departments[] = $row['department']; } }
    return $departments;
}

function getStatuses($report_type) {
    global $conn;
    $statuses = [];
    if (empty($report_type)) return $statuses;
    $status_col = 'status';
    switch ($report_type) {
        case 'template_details': 
            $sql = "SELECT DISTINCT `employee_status` FROM `employees` WHERE `employee_status` IS NOT NULL AND `employee_status` != '' ORDER BY `employee_status` ASC";
            $status_col = 'employee_status'; 
            break;
        case 'system_readiness': 
        case 'exit_checklist':
            $sql = "SELECT DISTINCT `overall_status` FROM `checklists` WHERE `overall_status` IS NOT NULL AND `overall_status` != '' ORDER BY `overall_status` ASC"; 
            $status_col = 'overall_status'; 
            break;
        case 'gatepass_report': 
            $sql = "SELECT DISTINCT `status` FROM `gatepass_requests` WHERE `status` IS NOT NULL AND `status` != '' ORDER BY `status` ASC"; 
            break;
        default: return $statuses;
    }
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) { while ($row = $result->fetch_assoc()) { if (!empty($row[$status_col])) { $statuses[] = $row[$status_col]; } } }
    return $statuses;
}

// --- AJAX Handler ---
if (isset($_GET['action']) && $_GET['action'] === 'get_statuses') {
    header('Content-Type: application/json');
    $report_type = $_GET['report_type'] ?? '';
    echo json_encode(getStatuses($report_type));
    exit();
}

// PHASE 2: DATA PROCESSING
$departments = getDepartments();
$filters = [
    'report_type' => $_GET['report_type'] ?? '', 
    'start_date' => $_GET['start_date'] ?? '', 
    'end_date' => $_GET['end_date'] ?? '', 
    'date_type' => $_GET['date_type'] ?? 'created_date',
    'department_filter' => $_GET['department_filter'] ?? '', 
    'status_filter' => $_GET['status_filter'] ?? ''
];

$results = []; 
$totalRecords = 0; 
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$recordsPerPage = 15; 
$offset = ($currentPage - 1) * $recordsPerPage;

if (isset($_GET['filter_applied']) && !empty($filters['report_type'])) {
    $baseSql = ''; $whereClauses = []; $params = []; $types = ""; $orderBy = ""; $dataSql = "";

    if ($filters['report_type'] === 'template_details') {
        // ENHANCED: Included requested_by, requested_at, approved_by (created_by), and approved_at (created_at)
        $dataSql = "SELECT emp_id, first_name, last_name, department, date_of_joining, employee_status as status, 
                           requested_by, requested_at, created_by as approved_by, created_at as approved_at ";
        $baseSql = "FROM employees";
        $orderBy = "ORDER BY employees.created_at DESC";
        $date_column = ($filters['date_type'] === 'joining_date') ? 'date_of_joining' : 'created_at';
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) { $whereClauses[] = "$date_column BETWEEN ? AND ?"; $types .= "ss"; array_push($params, $filters['start_date'], $filters['end_date'] . ' 23:59:59'); }
        if (!empty($filters['department_filter'])) { $whereClauses[] = "department = ?"; $types .= "s"; $params[] = $filters['department_filter']; }
        if (!empty($filters['status_filter'])) { $whereClauses[] = "employee_status = ?"; $types .= "s"; $params[] = $filters['status_filter']; }
    
    } elseif ($filters['report_type'] === 'system_readiness') {
        $dataSql = "SELECT e.emp_id, CONCAT(e.first_name, ' ', e.last_name) as full_name, e.department, e.date_of_joining, c.created_at, c.overall_status, c.assigned_to ";
        $baseSql = "FROM checklists c JOIN employees e ON c.employee_id = e.id";
        $orderBy = "ORDER BY c.created_at DESC";
        $whereClauses[] = "c.checklist_type = 'system_readiness'";
        $date_column = ($filters['date_type'] === 'joining_date') ? 'e.date_of_joining' : 'c.created_at';
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) { $whereClauses[] = "$date_column BETWEEN ? AND ?"; $types .= "ss"; array_push($params, $filters['start_date'], $filters['end_date'] . ' 23:59:59'); }
        if (!empty($filters['department_filter'])) { $whereClauses[] = "e.department = ?"; $types .= "s"; $params[] = $filters['department_filter']; }
        if (!empty($filters['status_filter'])) { $whereClauses[] = "c.overall_status = ?"; $types .= "s"; $params[] = $filters['status_filter']; }

    } elseif ($filters['report_type'] === 'exit_checklist') {
        $dataSql = "SELECT e.emp_id, CONCAT(e.first_name, ' ', e.last_name) as full_name, e.department, c.last_working_day, c.created_at, c.overall_status, c.asset_verified_by, c.approved_by ";
        $baseSql = "FROM checklists c JOIN employees e ON c.employee_id = e.id";
        $orderBy = "ORDER BY c.created_at DESC";
        $whereClauses[] = "c.checklist_type = 'exit_checklist'";
        $date_column = ($filters['date_type'] === 'lwd_date') ? 'c.last_working_day' : 'c.created_at';
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) { $whereClauses[] = "$date_column BETWEEN ? AND ?"; $types .= "ss"; array_push($params, $filters['start_date'], $filters['end_date'] . ' 23:59:59'); }
        if (!empty($filters['department_filter'])) { $whereClauses[] = "e.department = ?"; $types .= "s"; $params[] = $filters['department_filter']; }
        if (!empty($filters['status_filter'])) { $whereClauses[] = "c.overall_status = ?"; $types .= "s"; $params[] = $filters['status_filter']; }

    } elseif ($filters['report_type'] === 'gatepass_report') {
        $dataSql = "SELECT gatepass_no, employee_name, department, gatepass_type, status, security_out_date as out_time, created_at ";
        $baseSql = "FROM gatepass_requests";
        $orderBy = "ORDER BY created_at DESC";
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) { $whereClauses[] = "created_at BETWEEN ? AND ?"; $types .= "ss"; array_push($params, $filters['start_date'], $filters['end_date'] . ' 23:59:59'); }
        if (!empty($filters['department_filter'])) { $whereClauses[] = "department = ?"; $types .= "s"; $params[] = $filters['department_filter']; }
        if (!empty($filters['status_filter'])) { $whereClauses[] = "`status` = ?"; $types .= "s"; $params[] = $filters['status_filter']; }
    }

    if (!empty($baseSql)) {
        $whereSql = !empty($whereClauses) ? " WHERE " . implode(" AND ", $whereClauses) : "";
        $countStmt = $conn->prepare("SELECT COUNT(*) as total " . $baseSql . $whereSql);
        if ($countStmt) {
            if (!empty($types)) { $countStmt->bind_param($types, ...$params); }
            $countStmt->execute();
            $totalRecords = $countStmt->get_result()->fetch_assoc()['total'];
            $totalPages = ceil($totalRecords / $recordsPerPage);
            $countStmt->close();
        }
        
        if ($totalRecords > 0) {
            $dataStmt = $conn->prepare($dataSql . $baseSql . $whereSql . " " . $orderBy . " LIMIT ? OFFSET ?");
            if ($dataStmt) {
                $allParams = $params; $allTypes = $types . "ii"; array_push($allParams, $recordsPerPage, $offset);
                $dataStmt->bind_param($allTypes, ...$allParams);
                $dataStmt->execute();
                $result = $dataStmt->get_result();
                while($row = $result->fetch_assoc()) { $results[] = $row; }
                $dataStmt->close();
            }
        }
    }
}

checkPermission('view_reports');
$pageTitle = "Reports Center";
include_once '../includes/header.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<style>
    :root { --primary-color: #0f766e; --bg-color: #f3f4f6; --card-bg: #ffffff; --border-color: #e5e7eb; --radius: 12px; }
    body { font-family: 'Inter', sans-serif; background-color: var(--bg-color) !important; color: #1f2937; }
    
    .pro-card { background: var(--card-bg); border-radius: var(--radius); box-shadow: 0 2px 4px rgba(0,0,0,0.05); border: 1px solid var(--border-color); margin-bottom: 24px; overflow: hidden; }
    .pro-card-header { background: #fff; padding: 18px 24px; border-bottom: 1px solid var(--border-color); font-weight: 600; color: var(--primary-color); display: flex; align-items: center; gap: 10px; }
    .pro-card-body { padding: 24px; }
    
    .form-label { font-weight: 500; font-size: 0.85rem; color: #4b5563; margin-bottom: 6px; }
    .form-control, .form-select { border-radius: 8px; padding: 10px; font-size: 0.9rem; border: 1px solid #d1d5db; }
    .form-control:focus, .form-select:focus { border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1); }
    
    .btn-pro { padding: 10px 20px; border-radius: 8px; font-weight: 500; font-size: 0.9rem; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; }
    .btn-pro-primary { background: var(--primary-color); color: #fff; border: none; }
    .btn-pro-primary:hover { background: #0d9488; color: #fff; }
    .btn-pro-secondary { background: #fff; color: #4b5563; border: 1px solid #d1d5db; }
    .btn-pro-success { background: #10b981; color: #fff; border: none; }

    .table-pro { width: 100%; font-size: 0.85rem; }
    .table-pro th { background: #f9fafb; color: #4b5563; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; padding: 12px 16px; border-bottom: 2px solid #e5e7eb; }
    .table-pro td { padding: 12px 16px; vertical-align: middle; border-bottom: 1px solid #f3f4f6; }
    
    .status-badge { padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; }
    .badge-gray { background: #f3f4f6; color: #4b5563; }
    .small-meta { font-size: 0.75rem; color: #6b7280; display: block; }
</style>

<div class="container-xl py-5">
    <div class="pro-card">
        <div class="pro-card-header"><i data-feather="bar-chart-2"></i> Report Configuration</div>
        <div class="pro-card-body">
            <form id="reportForm" action="index.php" method="GET">
                <input type="hidden" name="route" value="reports">
                <input type="hidden" name="filter_applied" value="1">
                
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Report Category</label>
                        <select class="form-select" id="report_type" name="report_type" required>
                            <option value="" disabled <?= empty($filters['report_type']) ? 'selected' : '' ?>>Select Report...</option>
                            <option value="template_details" <?= $filters['report_type'] === 'template_details' ? 'selected' : '' ?>>Onboarding Template Details</option>
                            <option value="system_readiness" <?= $filters['report_type'] === 'system_readiness' ? 'selected' : '' ?>>System Readiness Status</option>
                            <option value="exit_checklist" <?= $filters['report_type'] === 'exit_checklist' ? 'selected' : '' ?>>Exit Clearance Report</option>
                            <option value="gatepass_report" <?= $filters['report_type'] === 'gatepass_report' ? 'selected' : '' ?>>Gatepass Movements</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Department</label>
                        <select class="form-select" name="department_filter">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= htmlspecialchars($dept) ?>" <?= $filters['department_filter'] === $dept ? 'selected' : '' ?>><?= htmlspecialchars($dept) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Specific Status</label>
                        <select class="form-select" id="status_filter" name="status_filter" <?= empty($filters['report_type']) ? 'disabled' : '' ?>>
                            <option value="">All Statuses</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date Pivot</label>
                        <select class="form-select" id="date_type" name="date_type">
                            <option value="created_date" <?= $filters['date_type'] === 'created_date' ? 'selected' : '' ?>>System Entry Date</option>
                            <option value="joining_date" id="doj_option" <?= $filters['date_type'] === 'joining_date' ? 'selected' : '' ?>>Joining Date</option>
                            <option value="lwd_date" id="lwd_option" <?= $filters['date_type'] === 'lwd_date' ? 'selected' : '' ?>>Last Working Day</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Timeline Range</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0"><i data-feather="calendar" style="width:14px;"></i></span>
                            <input type="text" class="form-control date-picker border-start-0" name="start_date" placeholder="From Date" value="<?= htmlspecialchars($filters['start_date']) ?>">
                            <input type="text" class="form-control date-picker" name="end_date" placeholder="To Date" value="<?= htmlspecialchars($filters['end_date']) ?>">
                        </div>
                    </div>
                    <div class="col-md-6 d-flex align-items-end justify-content-end gap-2">
                        <a href="index.php?route=reports" class="btn btn-pro btn-pro-secondary">Reset</a>
                        <button type="submit" class="btn btn-pro btn-pro-primary"><i data-feather="filter"></i> Generate Report</button>
                        <button type="button" id="exportCsvBtn" class="btn btn-pro btn-pro-success"><i data-feather="download"></i> Export CSV</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if (isset($_GET['filter_applied'])): ?>
    <div class="pro-card">
        <div class="pro-card-header d-flex justify-content-between">
            <span><i data-feather="list"></i> Data Results</span>
            <span class="text-muted fw-normal small">Showing <?= count($results) ?> of <?= $totalRecords ?> total records</span>
        </div>
        <div class="table-responsive">
            <table class="table-pro table-hover">
                <thead>
                    <?php if ($filters['report_type'] === 'template_details'): ?>
                        <tr>
                            <th>Employee</th>
                            <th>Department</th>
                            <th>Dates</th>
                            <th>Audit (Request)</th>
                            <th>Audit (Approval)</th>
                            <th>Status</th>
                        </tr>
                    <?php elseif ($filters['report_type'] === 'system_readiness'): ?>
                        <tr><th>Emp ID</th><th>Full Name</th><th>Department</th><th>Assigned To</th><th>Readiness Status</th><th>Created</th></tr>
                    <?php elseif ($filters['report_type'] === 'exit_checklist'): ?>
                        <tr><th>Emp ID</th><th>Full Name</th><th>LWD</th><th>IT Assets</th><th>HR Approval</th><th>Final Status</th></tr>
                    <?php elseif ($filters['report_type'] === 'gatepass_report'): ?>
                        <tr><th>Gatepass #</th><th>Requestor</th><th>Type</th><th>Movement Date</th><th>Current Status</th></tr>
                    <?php endif; ?>
                </thead>
                <tbody>
                    <?php if (empty($results)): ?>
                        <tr><td colspan="10" class="text-center py-5 text-muted">No matching records found for the selected filters.</td></tr>
                    <?php else: ?>
                        <?php foreach($results as $row): ?>
                        <tr>
                            <?php if ($filters['report_type'] === 'template_details'): ?>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))) ?></div>
                                    <div class="small-meta"><?= htmlspecialchars($row['emp_id'] ?? '') ?></div>
                                </td>
                                <td><?= htmlspecialchars($row['department'] ?? '') ?></td>
                                <td>
                                    <div class="small-meta">DOJ: <?= !empty($row['date_of_joining']) ? date('d-m-Y', strtotime($row['date_of_joining'])) : 'N/A' ?></div>
                                </td>
                                <td>
                                    <div class="fw-medium"><?= htmlspecialchars($row['requested_by'] ?: 'N/A') ?></div>
                                    <div class="small-meta"><?= !empty($row['requested_at']) ? date('d-m-y | H:i', strtotime($row['requested_at'])) : '' ?></div>
                                </td>
                                <td>
                                    <div class="fw-medium"><?= htmlspecialchars($row['approved_by'] ?: 'N/A') ?></div>
                                    <div class="small-meta"><?= !empty($row['approved_at']) ? date('d-m-y | H:i', strtotime($row['approved_at'])) : '' ?></div>
                                </td>
                                <td><span class="status-badge badge-gray"><?= htmlspecialchars($row['status'] ?? '') ?></span></td>

                            <?php elseif ($filters['report_type'] === 'system_readiness'): ?>
                                <td><?= htmlspecialchars($row['emp_id'] ?? 'N/A') ?></td>
                                <td class="fw-bold"><?= htmlspecialchars($row['full_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($row['department'] ?? 'N/A') ?></td>
                                <td><?= is_array($assigned = json_decode($row['assigned_to'] ?? '[]', true)) ? implode(', ', $assigned) : 'N/A' ?></td>
                                <td><span class="status-badge badge-gray"><?= htmlspecialchars($row['overall_status'] ?? 'N/A') ?></span></td>
                                <td><?= !empty($row['created_at']) ? date('d-m-Y', strtotime($row['created_at'])) : '' ?></td>

                            <?php elseif ($filters['report_type'] === 'exit_checklist'): ?>
                                <td><?= htmlspecialchars($row['emp_id'] ?? '') ?></td>
                                <td class="fw-bold"><?= htmlspecialchars($row['full_name'] ?? '') ?></td>
                                <td><?= !empty($row['last_working_day']) ? date('d-m-Y', strtotime($row['last_working_day'])) : '' ?></td>
                                <td><div class="small-meta">Verified By: <?= htmlspecialchars($row['asset_verified_by'] ?? 'N/A') ?></div></td>
                                <td><div class="small-meta">Approved By: <?= htmlspecialchars($row['approved_by'] ?? 'N/A') ?></div></td>
                                <td><span class="status-badge badge-gray"><?= htmlspecialchars($row['overall_status'] ?? '') ?></span></td>

                            <?php elseif ($filters['report_type'] === 'gatepass_report'): ?>
                                <td class="fw-bold text-primary">#<?= htmlspecialchars($row['gatepass_no'] ?? '') ?></td>
                                <td><?= htmlspecialchars($row['employee_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($row['gatepass_type'] ?? '') ?></td>
                                <td><?= !empty($row['out_time']) ? date('d-m-Y', strtotime($row['out_time'])) : 'Pending' ?></td>
                                <td><span class="status-badge badge-gray"><?= htmlspecialchars($row['status'] ?? '') ?></span></td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($totalPages > 1): ?>
        <div class="p-3 border-top bg-light d-flex justify-content-center">
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($i = 1; $i <= $totalPages; $i++): 
                        $page_params = $_GET; $page_params['page'] = $i; $queryParams = http_build_query($page_params); ?>
                        <li class="page-item <?= $i === $currentPage ? 'active' : '' ?>"><a class="page-link" href="?<?= $queryParams ?>"><?= $i ?></a></li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    flatpickr('.date-picker', { dateFormat: "Y-m-d", allowInput: true });
    if (typeof feather !== 'undefined') feather.replace();

    const reportForm = document.getElementById('reportForm');
    const reportTypeSelect = document.getElementById('report_type');
    const statusFilterSelect = document.getElementById('status_filter');
    const dateTypeSelect = document.getElementById('date_type');
    const dojOption = document.getElementById('doj_option');
    const lwdOption = document.getElementById('lwd_option');
    const exportCsvBtn = document.getElementById('exportCsvBtn');
    const currentStatus = "<?= htmlspecialchars($filters['status_filter']) ?>";

    // --- Export CSV Logic Interceptor ---
    if (exportCsvBtn) {
        exportCsvBtn.addEventListener('click', function() {
            if (!reportTypeSelect.value) {
                reportTypeSelect.reportValidity();
                return;
            }

            const routeInput = reportForm.querySelector('input[name="route"]');
            
            // Temporarily switch destination parameters for the export
            routeInput.value = 'generate_report';
            reportForm.target = '_blank';
            
            // Trigger the submission
            reportForm.submit();
            
            // Revert immediately so regular filtering still works on the main tab
            routeInput.value = 'reports';
            reportForm.target = '';
        });
    }

    async function updateStatusFilter() {
        const selectedType = reportTypeSelect.value;
        statusFilterSelect.innerHTML = '<option value="">All Statuses</option>';
        if (!selectedType) { statusFilterSelect.disabled = true; return; }
        try {
            const response = await fetch(`index.php?route=reports&action=get_statuses&report_type=${selectedType}`);
            const statuses = await response.json();
            statuses.forEach(status => {
                const option = document.createElement('option');
                option.value = status;
                option.textContent = status;
                if (status === currentStatus) option.selected = true;
                statusFilterSelect.appendChild(option);
            });
            statusFilterSelect.disabled = false;
        } catch (error) { statusFilterSelect.disabled = true; }
    }

    function toggleDateTypeOption() {
        const selectedType = reportTypeSelect.value;
        dojOption.hidden = !(selectedType === 'template_details' || selectedType === 'system_readiness');
        lwdOption.hidden = (selectedType !== 'exit_checklist');
        if (dateTypeSelect.options[dateTypeSelect.selectedIndex].hidden) dateTypeSelect.value = 'created_date';
    }

    reportTypeSelect.addEventListener('change', () => { toggleDateTypeOption(); updateStatusFilter(); });
    toggleDateTypeOption(); updateStatusFilter();
});
</script>

<?php include_once '../includes/footer.php'; ?>