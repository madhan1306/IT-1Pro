<?php
// FILE: dashboard.php

include_once 'includes/db_connect.php';
include_once 'includes/auth.php';

checkPermission('view_dashboard');

$pageTitle = "Dashboard";

// --- Year Filtering Logic ---
$available_years = [];
$year_query_sql = "(SELECT DISTINCT YEAR(created_at) as year FROM employees) UNION (SELECT DISTINCT YEAR(created_at) as year FROM checklists) UNION (SELECT DISTINCT YEAR(created_at) as year FROM gatepass_requests) UNION (SELECT DISTINCT YEAR(created_at) as year FROM shipments WHERE shipment_status = 'Delivered') ORDER BY year DESC";
$year_query = $conn->query($year_query_sql);
if ($year_query) {
    while ($row = $year_query->fetch_assoc()) {
        if (!is_null($row['year'])) { $available_years[] = $row['year']; }
    }
}
if (empty($available_years)) { $available_years[] = date('Y'); }

$selected_year = $_GET['year'] ?? 'all';

if (!in_array($selected_year, $available_years) && $selected_year !== 'all') {
    $selected_year = $available_years[0] ?? date('Y');
}

$year_filter_sql = '';
$year_params = [];
$year_type = '';
if ($selected_year !== 'all') {
    $year_filter_sql = ' AND YEAR(created_at) = ?';
    $year_params[] = $selected_year;
    $year_type = 'i';
}

// --- DEPARTMENT PREFERENCES LOGIC ---
$preferred_depts = $_SESSION['preferred_departments'] ?? [];
$dept_filter_sql = '';
$dept_filter_sql_c = ''; 

if (!empty($preferred_depts)) {
    $escaped_depts = array_map(function($d) use ($conn) { return "'" . $conn->real_escape_string($d) . "'"; }, $preferred_depts);
    $in_clause = implode(',', $escaped_depts);
    
    $dept_filter_sql = " AND department IN ({$in_clause})";           // Standard table (employees)
    $dept_filter_sql_c = " AND e.department IN ({$in_clause})";       // Checklists joined with employees 'e'
}


// --- DATA FOR CARDS ---
$total_new_joiners = 0;
$total_yet_to_join = 0;
$completed_readiness = 0;
$completed_exit_checklists = 0;
$total_gatepasses = 0;
$total_shipments = 0;

$readiness_statuses = ['Not Started' => 0, 'Preparing' => 0, 'Pending Verification' => 0];
$gatepass_types = ['Employee' => 0, 'Vendor' => 0, 'Site-to-Site' => 0];
$shipment_counts = ['New' => 0, 'Shipped' => 0, 'In Transit' => 0, 'Delivered' => 0, 'Cancelled' => 0];

// Updated query to separate joined employees from 'Yet to Join' AND apply department filter
$sql_joiners = "SELECT 
                    SUM(CASE WHEN employee_type = 'new_joiner' AND (employee_status != 'Yet to Join' OR employee_status IS NULL) THEN 1 ELSE 0 END) as count_new_joiner,
                    SUM(CASE WHEN employee_status = 'Yet to Join' THEN 1 ELSE 0 END) as count_yet_to_join
                FROM employees 
                WHERE (employee_type = 'new_joiner' OR employee_status = 'Yet to Join') 
                {$dept_filter_sql} " . str_replace('created_at', 'employees.created_at', $year_filter_sql);

$stmt_joiners = $conn->prepare($sql_joiners);
if ($stmt_joiners) {
    if ($selected_year !== 'all') { $stmt_joiners->bind_param($year_type, ...$year_params); }
    $stmt_joiners->execute();
    $row = $stmt_joiners->get_result()->fetch_assoc();
    $total_new_joiners = $row['count_new_joiner'] ?? 0;
    $total_yet_to_join = $row['count_yet_to_join'] ?? 0;
    $stmt_joiners->close();
}

// Joined with employees to apply department filter
$sql_readiness = "SELECT c.overall_status, COUNT(c.id) as count 
                  FROM checklists c 
                  JOIN employees e ON c.employee_id = e.id 
                  WHERE c.checklist_type = 'system_readiness' 
                  {$dept_filter_sql_c} " . str_replace('created_at', 'c.created_at', $year_filter_sql) . " 
                  GROUP BY c.overall_status";
$stmt_readiness = $conn->prepare($sql_readiness);
if ($stmt_readiness) {
    if ($selected_year !== 'all') { $stmt_readiness->bind_param($year_type, ...$year_params); }
    $stmt_readiness->execute();
    $result = $stmt_readiness->get_result();
    while($row = $result->fetch_assoc()) {
        if ($row['overall_status'] === 'Completed') {
            $completed_readiness = $row['count'];
        } else {
            $status_key = $row['overall_status'];
            if ($status_key === 'Verification Pending') { $status_key = 'Pending Verification'; }
            if (isset($readiness_statuses[$status_key])) { $readiness_statuses[$status_key] = $row['count']; }
        }
    }
    $stmt_readiness->close();
}
$total_readiness_for_percent = array_sum($readiness_statuses) + $completed_readiness;

// Joined with employees to apply department filter
$sql_exit = "SELECT COUNT(c.id) as count 
             FROM checklists c 
             JOIN employees e ON c.employee_id = e.id 
             WHERE c.checklist_type = 'exit_checklist' AND c.overall_status = 'Completed' 
             {$dept_filter_sql_c} " . str_replace('created_at', 'c.created_at', $year_filter_sql);
$stmt_exit = $conn->prepare($sql_exit);
if ($stmt_exit) {
    if ($selected_year !== 'all') { $stmt_exit->bind_param($year_type, ...$year_params); }
    $stmt_exit->execute();
    $completed_exit_checklists = $stmt_exit->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt_exit->close();
}

$sql_gatepass = "SELECT gatepass_type, COUNT(id) as count FROM gatepass_requests WHERE 1=1" . str_replace('created_at', 'gatepass_requests.created_at', $year_filter_sql) . " GROUP BY gatepass_type";
$stmt_gatepass = $conn->prepare($sql_gatepass);
if ($stmt_gatepass) {
    if ($selected_year !== 'all') { $stmt_gatepass->bind_param($year_type, ...$year_params); }
    $stmt_gatepass->execute();
    $result = $stmt_gatepass->get_result();
    while($row = $result->fetch_assoc()) {
        if (isset($gatepass_types[$row['gatepass_type']])) { $gatepass_types[$row['gatepass_type']] = $row['count']; }
    }
    $total_gatepasses = array_sum($gatepass_types);
    $stmt_gatepass->close();
}

$sql_shipments = "SELECT shipment_status, COUNT(id) as count FROM shipments WHERE 1=1" . str_replace('created_at', 'shipments.created_at', $year_filter_sql) . " GROUP BY shipment_status";
$stmt_shipments = $conn->prepare($sql_shipments);
if ($stmt_shipments) {
    if ($selected_year !== 'all') { $stmt_shipments->bind_param($year_type, ...$year_params); }
    $stmt_shipments->execute();
    $result = $stmt_shipments->get_result();
    while($row = $result->fetch_assoc()) {
        if (isset($shipment_counts[$row['shipment_status']])) { $shipment_counts[$row['shipment_status']] = $row['count']; }
    }
    $total_shipments = $shipment_counts['Delivered'] ?? 0; 
    $stmt_shipments->close();
}

// Chart data logic
$month_names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$joiners_created_monthly = array_fill_keys($month_names, 0);
$readiness_completed_monthly = array_fill_keys($month_names, 0);

$sql_created_monthly = "SELECT DATE_FORMAT(created_at, '%b') as month_name, COUNT(id) as count 
                        FROM employees 
                        WHERE employee_type IN ('new_joiner', 'Yet to Join') 
                        {$dept_filter_sql} {$year_filter_sql} 
                        GROUP BY month_name ORDER BY MONTH(created_at)";
$stmt_created_monthly = $conn->prepare($sql_created_monthly);
if ($stmt_created_monthly) {
    if ($selected_year !== 'all') { $stmt_created_monthly->bind_param("i", $selected_year); }
    $stmt_created_monthly->execute();
    $result = $stmt_created_monthly->get_result();
    while ($row = $result->fetch_assoc()) {
        if (isset($joiners_created_monthly[$row['month_name']])) $joiners_created_monthly[$row['month_name']] = (int)$row['count'];
    }
    $stmt_created_monthly->close();
}

$year_filter_approved_sql = str_replace('created_at', 'c.approved_at', $year_filter_sql);
$sql_completed_monthly = "SELECT DATE_FORMAT(c.approved_at, '%b') as month_name, COUNT(c.id) as count 
                                FROM checklists c
                                JOIN employees e ON c.employee_id = e.id
                                WHERE c.checklist_type = 'system_readiness' 
                                  AND e.employee_type IN ('new_joiner', 'Yet to Join')
                                  AND c.overall_status = 'Completed' 
                                  AND c.approved_at IS NOT NULL 
                                  {$dept_filter_sql_c} {$year_filter_approved_sql} 
                                GROUP BY month_name 
                                ORDER BY MONTH(c.approved_at)";
$stmt_completed_monthly = $conn->prepare($sql_completed_monthly);
if ($stmt_completed_monthly) {
    if ($selected_year !== 'all') { $stmt_completed_monthly->bind_param("i", $selected_year); }
    $stmt_completed_monthly->execute();
    $result = $stmt_completed_monthly->get_result();
    while ($row = $result->fetch_assoc()) {
        if (isset($readiness_completed_monthly[$row['month_name']])) $readiness_completed_monthly[$row['month_name']] = (int)$row['count'];
    }
    $stmt_completed_monthly->close();
}
$js_line_labels = json_encode(array_keys($joiners_created_monthly));
$js_created_values = json_encode(array_values($joiners_created_monthly));
$js_completed_values = json_encode(array_values($readiness_completed_monthly));

$donut_chart_labels = [];
$donut_chart_values = [];
$sql_donut = "SELECT e.department, COUNT(c.id) as total_count 
              FROM checklists c 
              JOIN employees e ON c.employee_id = e.id 
              WHERE c.checklist_type = 'system_readiness' AND c.overall_status = 'Completed' 
              {$dept_filter_sql_c} " . str_replace('created_at', 'c.created_at', $year_filter_sql) . " 
              GROUP BY e.department HAVING total_count > 0 ORDER BY total_count DESC";
$stmt_donut = $conn->prepare($sql_donut);
if ($stmt_donut) {
    if ($selected_year !== 'all') { $stmt_donut->bind_param("i", $selected_year); }
    $stmt_donut->execute();
    $result = $stmt_donut->get_result();
    while ($row = $result->fetch_assoc()) {
        $donut_chart_labels[] = $row['department'];
        $donut_chart_values[] = (int)$row['total_count'];
    }
    $stmt_donut->close();
}
$js_donut_labels = json_encode($donut_chart_labels);
$js_donut_values = json_encode($donut_chart_values);

include_once 'includes/header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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
    }

    body { font-family: 'Inter', sans-serif; background-color: var(--bg-color) !important; color: var(--text-main); font-size: 0.875rem; padding-bottom: 60px; }
    h1, h2, h3, h4, h5, h6 { font-weight: 600; color: #1e293b; }

    .modern-card { background: var(--card-bg); border-radius: var(--radius); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid var(--border-color); overflow: hidden; margin-bottom: 24px; transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .modern-card:hover { transform: translateY(-3px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.08); }
    
    .modern-card-header { padding: 16px 20px; background: #ffffff; border-bottom: 1px solid var(--border-color); font-weight: 600; color: #1e293b; display: flex; align-items: center; justify-content: space-between; font-size: 0.95rem; }
    .modern-card-body { padding: 20px; }

    /* Modern KPI Cards */
    .kpi-icon-wrap { width: 54px; height: 54px; border-radius: 12px; display: flex; align-items: center; justify-content: center; }
    .kpi-icon-wrap .feather { width: 24px; height: 24px; }
    .kpi-title { font-size: 0.75rem; font-weight: 600; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px; }
    .kpi-value { font-size: 1.75rem; font-weight: 700; color: #1e293b; line-height: 1; }

    /* Link Styles */
    .kpi-clickable-link { color: var(--primary-color); text-decoration: none; transition: color 0.2s; }
    .kpi-clickable-link:hover { color: var(--primary-hover); text-decoration: underline; }

    /* Summary Cards */
    .progress-group { margin-bottom: 1.25rem; }
    .progress-group:last-child { margin-bottom: 0; }
    .progress-label { font-size: 0.85rem; font-weight: 500; color: var(--text-main); display: flex; justify-content: space-between; margin-bottom: 6px; }
    .progress-value { font-weight: 700; color: #1e293b; }
    .progress.modern-progress { height: 8px; border-radius: 10px; background-color: #f1f5f9; overflow: hidden; }

    /* Stat Blocks */
    .stat-block { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 12px; border-radius: 8px; background: #f8fafc; transition: all 0.2s; text-decoration: none !important; }
    .stat-block:hover { background: #f0fdfa; }
    .stat-block .stat-value { font-size: 1.5rem; font-weight: 700; }
    .stat-block .stat-label { font-size: 0.75rem; color: var(--text-light); text-transform: uppercase; font-weight: 600; margin-top: 4px; }

    .form-select-modern { border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.85rem; font-weight: 500; color: #334155; padding: 6px 36px 6px 12px; }
    .form-select-modern:focus { border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1); }
</style>

<div class="container-fluid px-4 py-4 main-content-wrapper">
    
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
        <div>
            <h4 class="fw-bold m-0 text-dark">Dashboard</h4>
        </div>
        <form id="yearFilterForm" method="GET" action="<?= APP_BASE_PATH ?>index.php" class="d-flex align-items-center mt-3 mt-md-0">
            <input type="hidden" name="route" value="dashboard">
            <label for="yearFilter" class="me-2 text-muted fw-bold" style="font-size: 0.8rem; text-transform: uppercase;">Reporting Year:</label>
            <select name="year" id="yearFilter" class="form-select form-select-modern" style="width: 140px;">
                <option value="all" <?php echo ($selected_year == 'all') ? 'selected' : ''; ?>>All Time</option>
                <?php foreach ($available_years as $year): ?>
                    <option value="<?php echo $year; ?>" <?php echo ($year == $selected_year) ? 'selected' : ''; ?>><?php echo $year; ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    
    <div class="row g-4 mb-4">
        <div class="col-xl col-lg-4 col-md-6">
            <div class="modern-card h-100 mb-0">
                <div class="modern-card-body d-flex align-items-center gap-3 p-3">
                    <div class="kpi-icon-wrap bg-info-subtle text-info"><i data-feather="user-plus"></i></div>
                    <div>
                        <div class="kpi-title">New Joiners / Yet to Join</div>
                        <div class="kpi-value d-flex align-items-center">
                            <span><?php echo $total_new_joiners; ?></span>
                            <span class="mx-2 text-muted" style="font-size: 1.25rem; font-weight: 300;">/</span>
                            <a href="<?= APP_BASE_PATH ?>index.php?route=templates&employee_status=Yet+to+Join" 
                               class="kpi-clickable-link text-info" 
                               title="Click to view Yet to Join templates">
                                <?php echo $total_yet_to_join; ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl col-lg-4 col-md-6">
            <div class="modern-card h-100 mb-0">
                <div class="modern-card-body d-flex align-items-center gap-3 p-3">
                    <div class="kpi-icon-wrap bg-success-subtle text-success"><i data-feather="check-circle"></i></div>
                    <div>
                        <div class="kpi-title">Deployed Systems</div>
                        <div class="kpi-value"><?php echo $completed_readiness; ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl col-lg-4 col-md-6">
            <div class="modern-card h-100 mb-0">
                <div class="modern-card-body d-flex align-items-center gap-3 p-3">
                    <div class="kpi-icon-wrap bg-secondary-subtle text-secondary"><i data-feather="file-text"></i></div>
                    <div>
                        <div class="kpi-title">Gatepasses Issued</div>
                        <div class="kpi-value"><?php echo $total_gatepasses; ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl col-lg-6 col-md-6">
            <div class="modern-card h-100 mb-0">
                <div class="modern-card-body d-flex align-items-center gap-3 p-3">
                    <div class="kpi-icon-wrap" style="background-color: #e0e7ff; color: #4f46e5;"><i data-feather="truck"></i></div>
                    <div>
                        <div class="kpi-title">Delivered Shipments</div>
                        <div class="kpi-value"><?php echo $total_shipments; ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl col-lg-6 col-md-12">
            <div class="modern-card h-100 mb-0">
                <div class="modern-card-body d-flex align-items-center gap-3 p-3">
                    <div class="kpi-icon-wrap bg-danger-subtle text-danger"><i data-feather="user-minus"></i></div>
                    <div>
                        <div class="kpi-title">Completed Exits</div>
                        <div class="kpi-value"><?php echo $completed_exit_checklists; ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-4">
            <div class="modern-card h-100 mb-0">
                <div class="modern-card-header bg-transparent border-0 pb-0">
                    <span class="fw-bold d-flex align-items-center text-dark"><i data-feather="activity" class="me-2 text-primary" style="width:16px;"></i> Deployment Pipeline</span>
                </div>
                <div class="modern-card-body pt-3">
                    <div class="progress-group">
                        <div class="progress-label"><span>Not Started</span> <span class="progress-value"><?= $readiness_statuses['Not Started'] ?></span></div>
                        <div class="progress modern-progress">
                            <div class="progress-bar bg-secondary" role="progressbar" style="width: <?= $total_readiness_for_percent > 0 ? ($readiness_statuses['Not Started'] / $total_readiness_for_percent * 100) : 0 ?>%"></div>
                        </div>
                    </div>
                    <div class="progress-group">
                        <div class="progress-label"><span>Preparing</span> <span class="progress-value"><?= $readiness_statuses['Preparing'] ?></span></div>
                        <div class="progress modern-progress">
                            <div class="progress-bar text-bg-primary" style="width: <?= $total_readiness_for_percent > 0 ? ($readiness_statuses['Preparing'] / $total_readiness_for_percent * 100) : 0 ?>%"></div>
                        </div>
                    </div>
                    <div class="progress-group">
                        <div class="progress-label"><span>Pending Verification</span> <span class="progress-value"><?= $readiness_statuses['Pending Verification'] ?></span></div>
                        <div class="progress modern-progress">
                            <div class="progress-bar bg-warning" role="progressbar" style="width: <?= $total_readiness_for_percent > 0 ? ($readiness_statuses['Pending Verification'] / $total_readiness_for_percent * 100) : 0 ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="modern-card h-100 mb-0">
                <div class="modern-card-header bg-transparent border-0 pb-0">
                    <span class="fw-bold d-flex align-items-center text-dark"><i data-feather="file-text" class="me-2 text-primary" style="width:16px;"></i> Gatepass by Type</span>
                </div>
                <div class="modern-card-body d-flex flex-column justify-content-center">
                    <div class="row g-2 text-center">
                        <div class="col-4">
                            <div class="stat-block border">
                                <div class="stat-value text-dark"><?= $gatepass_types['Employee'] ?></div>
                                <div class="stat-label">Employee</div>
                            </div>
                        </div>
                        <div class="col-4">
                             <div class="stat-block border">
                                <div class="stat-value text-dark"><?= $gatepass_types['Vendor'] ?></div>
                                <div class="stat-label">Vendor</div>
                            </div>
                        </div>
                        <div class="col-4">
                             <div class="stat-block border">
                                <div class="stat-value text-dark"><?= $gatepass_types['Site-to-Site'] ?></div>
                                <div class="stat-label">Site-to-Site</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
             <div class="modern-card h-100 mb-0">
                <div class="modern-card-header bg-transparent border-0 pb-0">
                    <span class="fw-bold d-flex align-items-center text-dark"><i data-feather="truck" class="me-2 text-primary" style="width:16px;"></i> Logistics Summary</span>
                </div>
                <div class="modern-card-body">
                    <div class="row g-2 text-center">
                        <div class="col-6">
                            <a href="<?= APP_BASE_PATH ?>index.php?route=shipment&status_filter=New" class="stat-block h-100" style="border: 1px solid #bfdbfe;">
                                <div class="stat-value text-primary"><?= $shipment_counts['New'] ?></div>
                                <div class="stat-label">New</div>
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="<?= APP_BASE_PATH ?>index.php?route=shipment&status_filter=Shipped" class="stat-block h-100" style="border: 1px solid #cffafe;">
                                <div class="stat-value text-info"><?= $shipment_counts['Shipped'] ?></div>
                                <div class="stat-label">Shipped</div>
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="<?= APP_BASE_PATH ?>index.php?route=shipment&status_filter=In+Transit" class="stat-block h-100" style="border: 1px solid #fef08a;">
                                <div class="stat-value text-warning"><?= $shipment_counts['In Transit'] ?></div>
                                <div class="stat-label">In Transit</div>
                            </a>
                        </div>
                        <div class="col-6">
                             <a href="<?= APP_BASE_PATH ?>index.php?route=shipment&status_filter=Cancelled" class="stat-block h-100" style="border: 1px solid #fecaca;">
                                <div class="stat-value text-danger"><?= $shipment_counts['Cancelled'] ?></div>
                                <div class="stat-label">Cancelled</div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row g-4 mb-4">
        <div class="col-lg-7">
            <div class="modern-card h-100 mb-0">
                <div class="modern-card-header bg-transparent">
                    <span class="fw-bold d-flex align-items-center text-dark"><i data-feather="trending-up" class="me-2 text-primary" style="width:16px;"></i> Deployment Trend (<?php echo ($selected_year === 'all') ? 'All Time' : $selected_year; ?>)</span>
                </div>
                <div class="modern-card-body pt-0">
                    <div style="height: 350px; width: 100%;"><canvas id="readinessLineChart"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="modern-card h-100 mb-0">
                <div class="modern-card-header bg-transparent">
                    <span class="fw-bold d-flex align-items-center text-dark"><i class="me-2 text-primary" data-feather="pie-chart" style="width:16px;"></i> Completed by Department</span>
                </div>
                <div class="modern-card-body pt-0 d-flex align-items-center justify-content-center">
                    <div style="height: 350px; width: 100%;"><canvas id="departmentDonutChart"></canvas></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof feather !== 'undefined') {
            feather.replace();
        }
        
        const yearFilter = document.getElementById('yearFilter');
        if (yearFilter) {
            yearFilter.addEventListener('change', function() {
                document.getElementById('yearFilterForm').submit();
            });
        }

        // Global Chart Defaults
        Chart.defaults.font.family = "'Inter', sans-serif";
        Chart.defaults.color = '#64748b';
        Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(15, 23, 42, 0.9)';
        Chart.defaults.plugins.tooltip.padding = 10;
        Chart.defaults.plugins.tooltip.cornerRadius = 8;

        const lineCtx = document.getElementById('readinessLineChart').getContext('2d');
        
        // Gradient for the primary line
        let gradientPrimary = lineCtx.createLinearGradient(0, 0, 0, 350);
        gradientPrimary.addColorStop(0, 'rgba(15, 118, 110, 0.2)'); // Teal Light
        gradientPrimary.addColorStop(1, 'rgba(15, 118, 110, 0.0)');
        
        new Chart(lineCtx, {
            type: 'line',
            data: {
                labels: <?php echo $js_line_labels; ?>,
                datasets: [{
                    label: 'System Readiness Completed',
                    data: <?php echo $js_completed_values; ?>,
                    borderColor: '#0f766e', // Primary Teal
                    backgroundColor: gradientPrimary,
                    borderWidth: 2,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#0f766e',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    fill: true, 
                    tension: 0.4
                }, {
                    label: 'Joiner & Yet to Join Templates',
                    data: <?php echo $js_created_values; ?>,
                    borderColor: '#94a3b8', // Slate gray
                    borderDash: [5, 5],
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#94a3b8',
                    pointRadius: 3,
                    fill: false, 
                    tension: 0.4
                }]
            },
            options: { 
                responsive: true, 
                maintainAspectRatio: false, 
                plugins: { 
                    legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20, font: { weight: '500' } } } 
                }, 
                scales: { 
                    y: { 
                        beginAtZero: true, 
                        ticks: { precision: 0 },
                        grid: { color: '#f1f5f9', drawBorder: false }
                    },
                    x: {
                        grid: { display: false, drawBorder: false }
                    }
                },
                interaction: { mode: 'index', intersect: false }
            }
        });

        const donutCtx = document.getElementById('departmentDonutChart').getContext('2d');
        new Chart(donutCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo $js_donut_labels; ?>,
                datasets: [{
                    data: <?php echo $js_donut_values; ?>,
                    // Premium Executive Palette
                    backgroundColor: [
                        '#1F3A5F', '#2E5A88', '#4C6A92', '#5E4B8B', '#6B7280', 
                        '#3F6F6F', '#7C8A6D', '#8B6F47', '#A06A6A', '#374151', 
                        '#475569', '#2F4858', '#6C7A89', '#556B8D', '#4B5563', 
                        '#7A869A', '#5B6B7A', '#3E5C76', '#6D597A', '#2C3E50'
                    ],
                    borderWidth: 2, 
                    borderColor: '#ffffff',
                    hoverOffset: 6
                }]
            },
            options: { 
                responsive: true, 
                maintainAspectRatio: false, 
                cutout: '72%',
                plugins: { 
                    legend: { 
                        position: 'right', 
                        labels: { 
                            usePointStyle: true, 
                            padding: 18, 
                            font: { size: 11, family: "'Inter', sans-serif", weight: '500' },
                            color: '#475569'
                        } 
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.9)', 
                        titleFont: { size: 13, family: "'Inter', sans-serif" },
                        bodyFont: { size: 12, family: "'Inter', sans-serif" },
                        padding: 12,
                        cornerRadius: 8,
                        boxPadding: 6
                    }
                } 
            }
        });
});

</script>

<?php include_once 'includes/footer.php'; ?>