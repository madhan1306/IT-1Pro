<?php
// FILE: shr/dashboard.php
date_default_timezone_set('Asia/Kolkata');
if (session_status() === PHP_SESSION_NONE) { session_start(); }

include_once '../includes/db_connect.php';
include_once '../includes/auth.php';

checkPermission('view_shr');

// =========================================================================
// --- HANDLE EXPORT REQUEST ---
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_action'])) {
    $start_date = $_POST['exp_start'] ?? date('Y-m-d');
    $end_date = $_POST['exp_end'] ?? date('Y-m-d');
    $eng_filter = $_POST['exp_engineer'] ?? 'all';
    $format = $_POST['exp_format'] ?? 'csv';

    $query = "SELECT * FROM shift_handovers WHERE DATE(shift_date) >= ? AND DATE(shift_date) <= ?";
    $params = [$start_date, $end_date];
    $types = "ss";

    if ($eng_filter !== 'all') {
        $query .= " AND created_by_username = ?";
        $params[] = $eng_filter;
        $types .= "s";
    }
    $query .= " ORDER BY shift_date ASC, daily_shift_no ASC"; 

    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $export_data = [];
    while ($row = $res->fetch_assoc()) {
        $json = json_decode($row['glpi_snapshot_json'], true);
        $m = $json['metrics'] ?? [];
        
        // Parse Pending Stats
        $pending_raw = $m['pending'] ?? 0;
        $pending_total = 0;
        if (is_string($pending_raw) && strpos($pending_raw, '|') !== false) {
            preg_match('/User:\s*(\d+)/i', $pending_raw, $m_user);
            preg_match('/Internal:\s*(\d+)/i', $pending_raw, $m_int);
            $pending_user = isset($m_user[1]) ? (int)$m_user[1] : 0;
            $pending_internal = isset($m_int[1]) ? (int)$m_int[1] : 0;
            $pending_total = $pending_user + $pending_internal;
        } elseif (is_numeric($pending_raw)) {
            $pending_total = (int)$pending_raw;
        } else {
            preg_match_all('/\d+/', $pending_raw, $matches);
            $pending_total = !empty($matches[0]) ? array_sum($matches[0]) : 0;
        }

        $export_data[] = [
            'SHR No' => $row['shr_no'],
            'Date' => $row['shift_date'],
            'Shift' => trim(preg_replace('/\(.*?\)/', '', $row['shift_type'])),
            'Engineer' => $row['created_by_name'],
            'Handed Over To' => $row['acknowledged_by_name'] ?? 'Pending Ack',
            'Received' => $m['received'] ?? 0,
            'Worked' => $m['worked'] ?? 0,
            'Closed' => $m['closed'] ?? 0,
            'Pending Total' => $pending_total
        ];
    }
    $stmt->close();

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="SHR_Export_' . $start_date . '_to_' . $end_date . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['SHR No', 'Date', 'Shift', 'Engineer', 'Handed Over To', 'Received', 'Worked', 'Closed', 'Pending Total']);
        foreach ($export_data as $row) { fputcsv($output, $row); }
        fclose($output);
        exit;
    } elseif ($format === 'excel') {
        header("Content-Type: application/vnd.ms-excel");
        header("Content-Disposition: attachment; filename=\"SHR_Export_{$start_date}_to_{$end_date}.xls\"");
        echo "<table border='1'>";
        echo "<tr><th style='background:#0f766e;color:white;'>SHR No</th><th style='background:#0f766e;color:white;'>Date</th><th style='background:#0f766e;color:white;'>Shift</th><th style='background:#0f766e;color:white;'>Engineer</th><th style='background:#0f766e;color:white;'>Handed Over To</th><th style='background:#0f766e;color:white;'>Received</th><th style='background:#0f766e;color:white;'>Worked</th><th style='background:#0f766e;color:white;'>Closed</th><th style='background:#0f766e;color:white;'>Pending Total</th></tr>";
        foreach ($export_data as $row) {
            echo "<tr>";
            foreach ($row as $val) echo "<td>" . htmlspecialchars($val) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        exit;
    }
}
// =========================================================================

$filter_date = $_GET['date'] ?? date('Y-m-d');

// Fetch Engineers for Modal Dropdown
$eng_stmt = $conn->query("SELECT DISTINCT created_by_username, created_by_name FROM shift_handovers ORDER BY created_by_name ASC");
$all_engineers = [];
if($eng_stmt) {
    while($row = $eng_stmt->fetch_assoc()) {
        $all_engineers[$row['created_by_username']] = $row['created_by_name'];
    }
    $eng_stmt->close();
}

// Fetch Handovers for Dashboard View (Single Date)
$handovers = [];
$stmt = $conn->prepare("SELECT * FROM shift_handovers WHERE DATE(shift_date) = ? ORDER BY created_at DESC");
$stmt->bind_param("s", $filter_date);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $handovers[] = $row;
}
$stmt->close();

$total_closed = 0; $total_worked = 0;
$latest_pending_user = 0; $latest_pending_int = 0; $latest_pending_total = 0;
$unique_high = []; $unique_medium = [];
$engineer_stats = []; $is_first_row = true;

foreach ($handovers as $h) {
    $json = json_decode($h['glpi_snapshot_json'], true);
    $m = $json['metrics'] ?? ['received'=>0, 'worked'=>0, 'closed'=>0, 'pending'=>0];
    $tickets = $json['tickets'] ?? [];
    
    $total_closed += (int)$m['closed'];
    $total_worked += (int)$m['worked'];
    
    $pending_raw = $m['pending'] ?? 0;
    $pending_total = 0; $pending_user = 0; $pending_internal = 0; $has_split = false;
    
    if (is_string($pending_raw) && strpos($pending_raw, '|') !== false) {
        $has_split = true;
        preg_match('/User:\s*(\d+)/i', $pending_raw, $m_user);
        preg_match('/Internal:\s*(\d+)/i', $pending_raw, $m_int);
        $pending_user = isset($m_user[1]) ? (int)$m_user[1] : 0;
        $pending_internal = isset($m_int[1]) ? (int)$m_int[1] : 0;
        $pending_total = $pending_user + $pending_internal;
    } elseif (is_numeric($pending_raw)) {
        $pending_total = (int)$pending_raw;
        $pending_user = $pending_total; 
    } else {
        preg_match_all('/\d+/', $pending_raw, $matches);
        $pending_total = !empty($matches[0]) ? array_sum($matches[0]) : 0;
        $pending_user = $pending_total; 
    }
    
    if ($is_first_row) {
        $latest_pending_user = $pending_user;
        $latest_pending_int = $pending_internal;
        $latest_pending_total = $pending_total;
        $is_first_row = false;
    }

    foreach ($tickets as $t) {
        if (isset($t['priority'])) {
            if ((int)$t['priority'] >= 4) { $unique_high[$t['id']] = 1; } 
            elseif ((int)$t['priority'] == 3) { $unique_medium[$t['id']] = 1; }
        }
    }

    $engineer_stats[] = [
        'id' => $h['id'],
        'shr_no' => $h['shr_no'] ?? $h['id'],
        'daily_shift_no' => $h['daily_shift_no'] ?? 1,
        'engineer' => $h['created_by_name'],
        'handed_over_to' => $h['acknowledged_by_name'] ?? 'Pending Ack',
        'shift' => $h['shift_type'],
        'received' => $m['received'],
        'worked' => $m['worked'],
        'closed' => $m['closed'],
        'pending_total' => $pending_total,
        'pending_user' => $pending_user,
        'pending_internal' => $pending_internal,
        'has_split' => $has_split,
        'time' => date('h:i A', strtotime($h['created_at']))
    ];
}

$high_priority_count = count($unique_high);
$medium_priority_count = count($unique_medium);

$pageTitle = "SHR Dashboard";
include_once '../includes/header.php';
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
    body { font-family: 'Inter', sans-serif; background-color: #f3f4f6 !important; }
    .modern-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border: 1px solid #e5e7eb; overflow: hidden; margin-bottom: 24px; }
    .summary-card { padding: 24px; border-radius: 12px; color: white; display: flex; align-items: center; justify-content: space-between; }
    
    /* Reduced Table Fonts & Padding */
    .table-custom th { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; font-weight: 600; padding: 10px 12px; background: #f8fafc; border-bottom: 1px solid #e5e7eb; }
    .table-custom td { padding: 10px 12px; vertical-align: middle; border-bottom: 1px solid #f1f5f9; color: #334155; font-size: 0.8rem; font-weight: 500; }
    .metric-link { display: inline-block; min-width: 28px; text-align: center; padding: 3px 8px; border-radius: 6px; background: #f1f5f9; color: #0f766e; text-decoration: none; font-weight: 700; font-size: 0.8rem; transition: 0.2s; border: 1px solid #e2e8f0; }
    .metric-link:hover { background: #0f766e; color: white; border-color: #0f766e; transform: translateY(-1px); }
    
    div.dataTables_wrapper div.dataTables_filter input { border-radius: 6px; border: 1px solid #cbd5e1; padding: 4px 12px; font-size: 0.85rem; }
</style>

<div class="container-xl py-4">
    
    <!-- Top Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold m-0 text-dark">SHR Dashboard</h4>
            <div class="text-muted small mt-1">Shift summary and engineer performance tracking</div>
        </div>
        <div class="d-flex align-items-center gap-3">
            <form method="GET" class="d-flex align-items-center gap-2 bg-white p-2 rounded-3 shadow-sm border m-0">
                <input type="hidden" name="route" value="dashboard_shr">
                <label for="date" class="fw-bold text-muted small ms-2 mb-0">DATE:</label>
                <input type="date" name="date" class="form-control form-control-sm border-0 fw-bold text-primary" value="<?= htmlspecialchars($filter_date) ?>" onchange="this.form.submit()" style="cursor: pointer; outline: none; box-shadow: none;">
            </form>
            <button class="btn btn-success fw-bold d-flex align-items-center gap-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#exportModal">
                <i data-feather="download" style="width: 18px;"></i> Export Report
            </button>
        </div>
    </div>

    <!-- Management Summary Cards -->
    <div class="row g-4 mb-4">
        <div class="col-lg-3 col-md-6">
            <div class="summary-card shadow-sm" style="background: linear-gradient(135deg, #0284c7, #0369a1);">
                <div>
                    <div class="small fw-bold text-uppercase opacity-75 mb-1">Total Worked Tickets</div>
                    <h2 class="fw-bold m-0"><?= $total_worked ?></h2>
                </div>
                <div style="opacity: 0.8;"><i data-feather="activity" style="width: 48px; height: 48px;"></i></div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="summary-card shadow-sm" style="background: linear-gradient(135deg, #10b981, #059669);">
                <div>
                    <div class="small fw-bold text-uppercase opacity-75 mb-1">Total Closed Today</div>
                    <h2 class="fw-bold m-0"><?= $total_closed ?></h2>
                </div>
                <div style="opacity: 0.8;"><i data-feather="check-circle" style="width: 48px; height: 48px;"></i></div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="summary-card shadow-sm" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                <div>
                    <div class="small fw-bold text-uppercase opacity-75 mb-1">Final Pending EOD</div>
                    <?php if ($latest_pending_user > 0 || $latest_pending_int > 0): ?>
                        <h3 class="fw-bold m-0">
                            <?= $latest_pending_user ?> <span class="fs-6 opacity-75 fw-normal">User</span> <span class="mx-1 opacity-50">|</span> <?= $latest_pending_int ?> <span class="fs-6 opacity-75 fw-normal">Int.</span>
                        </h3>
                    <?php else: ?>
                        <h2 class="fw-bold m-0"><?= $latest_pending_total ?></h2>
                    <?php endif; ?>
                </div>
                <div style="opacity: 0.8;"><i data-feather="clock" style="width: 48px; height: 48px;"></i></div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="summary-card shadow-sm" style="background: linear-gradient(135deg, #ef4444, #b91c1c);">
                <div>
                    <div class="small fw-bold text-uppercase opacity-75 mb-1">Priority Ticket Logs</div>
                    <h3 class="fw-bold m-0">
                        <?= $high_priority_count ?> <span class="fs-6 opacity-75 fw-normal">High</span> <span class="mx-1 opacity-50">|</span> <?= $medium_priority_count ?> <span class="fs-6 opacity-75 fw-normal">Med</span>
                    </h3>
                </div>
                <div style="opacity: 0.8;"><i data-feather="alert-triangle" style="width: 48px; height: 48px;"></i></div>
            </div>
        </div>
    </div>

    <!-- UI Data Table -->
    <div class="modern-card">
        <div class="modern-card-header text-dark p-3 bg-white border-bottom fw-bold d-flex align-items-center">
            <i data-feather="users" class="me-2 text-primary" style="width: 18px;"></i> Engineer-Wise Shift Breakdown
        </div>
        <div class="card-body p-4">
            <div class="table-responsive">
                <table id="engineerTable" class="table table-custom mb-0 text-center w-100">
                    <thead class="text-start">
                        <tr>
                            <th>SHR No.</th>
                            <th>Engineer / Shift Owner</th>
                            <th class="text-center">Shift No.</th>
                            <th>Shift Timing</th>
                            <th>Handed Over To</th>
                            <th class="text-center">Previous Pending (Received)</th>
                            <th class="text-center">Total Tickets Worked</th>
                            <th class="text-center">Tickets Closed</th>
                            <th class="text-center">Pending Handover</th>
                        </tr>
                    </thead>
                    <tbody class="text-start">
                        <?php foreach ($engineer_stats as $stat): 
                            $view_link = APP_BASE_PATH . "?route=view_shr&id=" . $stat['id'];
                            preg_match('/\((.*?)\)/', $stat['shift'], $matches);
                            $time_only = isset($matches[1]) ? trim($matches[1]) : htmlspecialchars($stat['shift']);
                        ?>
                        <tr>
                            <td>
                                <span class="badge bg-light text-dark border px-2 py-1" style="font-size: 0.75rem;">#<?= htmlspecialchars($stat['shr_no']) ?></span>
                            </td>
                            <td>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($stat['engineer']) ?></div>
                            </td>
                            <td class="text-center">
                                <!-- Shift No reduced badge font and padding -->
                                <span class="badge bg-secondary text-white border" style="font-size: 0.7rem; padding: 4px 8px;"><?= htmlspecialchars($stat['daily_shift_no']) ?></span>
                            </td>
                            <td>
                                <div class="fw-bold text-muted" style="font-size: 0.75rem;"><?= $time_only ?></div>
                                <div class="text-muted" style="font-size: 0.7rem;">Submitted: <?= $stat['time'] ?></div>
                            </td>
                            <td>
                                <div class="fw-bold text-primary"><?= htmlspecialchars($stat['handed_over_to']) ?></div>
                            </td>
                            <td class="text-center">
                                <a href="<?= $view_link ?>" class="metric-link" title="Click to view handover report" data-bs-toggle="tooltip"><?= $stat['received'] ?></a>
                            </td>
                            <td class="text-center">
                                <a href="<?= $view_link ?>" class="metric-link" title="Click to view handover report" data-bs-toggle="tooltip"><?= $stat['worked'] ?></a>
                            </td>
                            <td class="text-center">
                                <a href="<?= $view_link ?>" class="metric-link text-success border-success-subtle bg-success-subtle" title="Click to view handover report" data-bs-toggle="tooltip"><?= $stat['closed'] ?></a>
                            </td>
                            <td class="text-center">
                                <a href="<?= $view_link ?>" class="metric-link text-danger border-danger-subtle bg-danger-subtle px-2" title="Click to view handover report" data-bs-toggle="tooltip" style="min-width: 70px;">
                                    <?php if ($stat['has_split']): ?>
                                        <?= $stat['pending_user'] ?> <span style="font-size: 0.65rem; opacity: 0.85;">User</span> 
                                        <span class="mx-1" style="opacity: 0.4;">|</span> 
                                        <?= $stat['pending_internal'] ?> <span style="font-size: 0.65rem; opacity: 0.85;">Int.</span>
                                    <?php else: ?>
                                        <?= $stat['pending_total'] ?>
                                    <?php endif; ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================= -->
<!-- EXPORT MODAL POPUP -->
<!-- ========================================================= -->
<div class="modal fade" id="exportModal" tabindex="-1" aria-labelledby="exportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold" id="exportModalLabel"><i data-feather="download" class="me-2" style="width: 20px;"></i> Export SHR Data</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="export_action" value="1">
                <div class="modal-body p-4">
                    
                    <!-- Quick Select Buttons -->
                    <div class="mb-4 text-center">
                        <span class="text-muted small fw-bold text-uppercase d-block mb-2">Quick Date Select</span>
                        <div class="btn-group w-100 shadow-sm" role="group">
                            <?php 
                                $today_str = date('Y-m-d');
                                $week_start = date('Y-m-d', strtotime('monday this week'));
                                $month_start = date('Y-m-01');
                            ?>
                            <button type="button" class="btn btn-outline-secondary btn-sm fw-bold" onclick="setExportDates('<?= $today_str ?>', '<?= $today_str ?>')">Today</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm fw-bold" onclick="setExportDates('<?= $week_start ?>', '<?= $today_str ?>')">This Week</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm fw-bold" onclick="setExportDates('<?= $month_start ?>', '<?= $today_str ?>')">This Month</button>
                        </div>
                    </div>
                    
                    <hr class="text-muted mb-4">
                    
                    <!-- Date Range -->
                    <div class="row g-3 mb-4">
                        <div class="col-6">
                            <label class="form-label text-dark small fw-bold mb-1">Start Date</label>
                            <input type="date" name="exp_start" id="exp_start" class="form-control bg-light" value="<?= $today_str ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label text-dark small fw-bold mb-1">End Date</label>
                            <input type="date" name="exp_end" id="exp_end" class="form-control bg-light" value="<?= $today_str ?>" required>
                        </div>
                    </div>
                    
                    <!-- Engineer Filter -->
                    <div class="mb-4">
                        <label class="form-label text-dark small fw-bold mb-1">Filter by Engineer</label>
                        <select name="exp_engineer" class="form-select bg-light">
                            <option value="all">-- All Engineers --</option>
                            <?php foreach($all_engineers as $uname => $name): ?>
                                <option value="<?= htmlspecialchars($uname) ?>"><?= htmlspecialchars($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Format Selection -->
                    <div class="mb-2">
                        <label class="form-label text-dark small fw-bold mb-2">Export Format</label>
                        <div class="d-flex gap-4">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="exp_format" id="fmt_csv" value="csv" checked>
                                <label class="form-check-label fw-bold text-muted" for="fmt_csv">CSV (.csv)</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="exp_format" id="fmt_excel" value="excel">
                                <label class="form-check-label fw-bold text-muted" for="fmt_excel">Excel (.xls)</label>
                            </div>
                        </div>
                    </div>

                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-light border fw-bold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success fw-bold px-4 shadow-sm">Download File</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- DataTables Core Script -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
// Logic to pre-fill the modal date inputs
function setExportDates(start, end) {
    document.getElementById('exp_start').value = start;
    document.getElementById('exp_end').value = end;
}

window.addEventListener('load', function() {
    if(typeof feather !== 'undefined') feather.replace(); 
    
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) { return new bootstrap.Tooltip(tooltipTriggerEl) })

    // Initialize clean DataTable without export buttons clashing
    if ($.fn.DataTable.isDataTable('#engineerTable')) {
        $('#engineerTable').DataTable().destroy();
    }

    $('#engineerTable').DataTable({
        dom: '<"d-flex flex-wrap justify-content-between align-items-center mb-3"lf>rt<"d-flex flex-wrap justify-content-between align-items-center mt-3"ip>',
        order: [], 
        pageLength: 25,
        language: {
            search: "",
            searchPlaceholder: "Search records...",
            emptyTable: "No shift handovers recorded for this date."
        },
        drawCallback: function() {
            if(typeof feather !== 'undefined') feather.replace();
        }
    });
});
</script>
<?php include_once '../includes/footer.php'; ?>