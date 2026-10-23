<?php
// FILE: shr/my_handovers.php
date_default_timezone_set('Asia/Kolkata');
if (session_status() === PHP_SESSION_NONE) { session_start(); }

include_once '../includes/db_connect.php';
include_once '../includes/auth.php';

checkPermission('view_shr');

$logged_in_username = $_SESSION['username'];
$pageTitle = "My Shift Handovers";
include_once '../includes/header.php';

$handovers = [];
$stmt = $conn->prepare("SELECT * FROM shift_handovers WHERE created_by_username = ? OR acknowledged_by_username = ? ORDER BY shift_date DESC, created_at DESC LIMIT 500");
$stmt->bind_param("ss", $logged_in_username, $logged_in_username);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) { $handovers[] = $row; }
$stmt->close();

$unique_dates = [];
$unique_shifts = [];
$unique_outgoing = [];
$unique_incoming = [];
$unique_statuses = [];
$grouped_handovers = [];

$today_date = date('Y-m-d');

foreach ($handovers as $h) {
    $date = $h['shift_date'];
    
    if (!isset($grouped_handovers[$date])) {
        $grouped_handovers[$date] = [];
    }
    $grouped_handovers[$date][] = $h;
    
    if (!empty($date)) $unique_dates[$date] = true;
    if (!empty($h['shift_type'])) $unique_shifts[$h['shift_type']] = true;
    if (!empty($h['created_by_name'])) $unique_outgoing[$h['created_by_name']] = true;
    if (!empty($h['acknowledged_by_name'])) $unique_incoming[$h['acknowledged_by_name']] = true;
    if (!empty($h['status'])) $unique_statuses[$h['status']] = true;
}

krsort($unique_dates);
ksort($unique_shifts);
ksort($unique_outgoing);
ksort($unique_incoming);
ksort($unique_statuses);
?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Inter', sans-serif; background-color: #f8fafc !important; }
    .modern-card { background: #fff; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; overflow: hidden; margin-bottom: 16px; }
    .table-custom th { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; font-weight: 600; padding: 10px 12px; background: #f1f5f9; border-bottom: 1px solid #e2e8f0; }
    .table-custom td { padding: 10px 12px; vertical-align: middle; border-bottom: 1px solid #f1f5f9; color: #334155; font-size: 0.8rem; }
    .status-badge { padding: 3px 8px; border-radius: 12px; font-size: 0.65rem; font-weight: 600; text-transform: uppercase; }
    .btn-primary-pro { background-color: #0f766e; color: white; border: none; padding: 6px 12px; border-radius: 6px; font-weight: 500; transition: all 0.2s; text-decoration: none; }
    .btn-primary-pro:hover { background-color: #0d9488; color: white; }
    .date-header { background-color: #f8fafc; transition: background-color 0.2s; }
    .date-header:hover { background-color: #f1f5f9 !important; }
    .cursor-pointer { cursor: pointer; }
    .clickable-row:hover { background-color: #f8fafc; cursor: pointer; }
    .pagination .page-link { color: #0f766e; border-color: #e2e8f0; box-shadow: none; font-size: 0.8rem; padding: 4px 10px; }
    .pagination .page-item.active .page-link { background-color: #0f766e; border-color: #0f766e; color: #fff; font-weight: bold; }
    .pagination .page-item.disabled .page-link { color: #94a3b8; background-color: #f8fafc; }
    .filter-panel { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; box-shadow: 0 2px 4px rgba(0,0,0,0.04); }
    .form-select-sm, .form-control-sm { border-radius: 6px; border-color: #cbd5e1; font-size: 0.8rem; }
    .form-select-sm:focus, .form-control-sm:focus { border-color: #0f766e; box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1); }
</style>

<div class="container-xl py-3">
    
    <div class="modern-card p-3 mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h5 class="fw-bold m-0 text-dark">My Shift Handovers</h5>
                <div class="text-muted" style="font-size: 0.75rem;">Handovers involving your profile</div>
            </div>
            
            <div class="d-flex flex-wrap align-items-center gap-2" style="flex: 1; max-width: 650px;">
                <div class="position-relative flex-grow-1">
                    <i data-feather="search" class="position-absolute text-muted" style="width: 14px; top: 50%; left: 10px; transform: translateY(-50%); pointer-events: none;"></i>
                    <input type="text" id="globalSearch" class="form-control form-control-sm" placeholder="Search SHR No, Date, Names, Status..." style="border-radius: 6px; padding-left: 32px;">
                </div>
                <button class="btn btn-white btn-sm border shadow-sm text-dark fw-medium d-flex align-items-center" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                    <i data-feather="filter" style="width:14px;" class="me-1 text-primary"></i> Filters
                </button>
                <a href="<?php echo APP_BASE_PATH; ?>?route=create_shr" class="btn btn-primary-pro btn-sm d-flex align-items-center shadow-sm">
                    <i data-feather="plus" style="width:14px;" class="me-1"></i> New Handover
                </a>
            </div>
        </div>
    </div>

    <div class="collapse mb-3" id="filterCollapse">
        <div class="filter-panel">
            <div class="row g-2">
                <div class="col-md-2">
                    <label class="form-label mb-1" style="font-size: 0.7rem; font-weight: 600; color: #64748b; text-transform: uppercase;">Date</label>
                    <select class="form-select form-select-sm filter-input" id="fltDate">
                        <option value="">All Dates</option>
                        <?php foreach(array_keys($unique_dates) as $d): ?>
                            <option value="<?= $d ?>"><?= date('M d, Y', strtotime($d)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1" style="font-size: 0.7rem; font-weight: 600; color: #64748b; text-transform: uppercase;">Shift No.</label>
                    <select class="form-select form-select-sm filter-input" id="fltShift">
                        <option value="">All Shifts</option>
                        <?php foreach(array_keys($unique_shifts) as $s): ?>
                            <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label mb-1" style="font-size: 0.7rem; font-weight: 600; color: #64748b; text-transform: uppercase;">Outgoing Engineer</label>
                    <select class="form-select form-select-sm filter-input" id="fltOut">
                        <option value="">Any Engineer</option>
                        <?php foreach(array_keys($unique_outgoing) as $o): ?>
                            <option value="<?= htmlspecialchars($o) ?>"><?= htmlspecialchars($o) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label mb-1" style="font-size: 0.7rem; font-weight: 600; color: #64748b; text-transform: uppercase;">Incoming Engineer</label>
                    <select class="form-select form-select-sm filter-input" id="fltIn">
                        <option value="">Any Engineer</option>
                        <?php foreach(array_keys($unique_incoming) as $i): ?>
                            <option value="<?= htmlspecialchars($i) ?>"><?= htmlspecialchars($i) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1" style="font-size: 0.7rem; font-weight: 600; color: #64748b; text-transform: uppercase;">Status</label>
                    <select class="form-select form-select-sm filter-input" id="fltStatus">
                        <option value="">All Statuses</option>
                        <?php foreach(array_keys($unique_statuses) as $st): ?>
                            <option value="<?= htmlspecialchars($st) ?>"><?= htmlspecialchars($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 text-end mt-2">
                    <button class="btn btn-sm btn-light border text-danger fw-bold" id="btnClearFilters" style="font-size: 0.75rem;">Clear All Filters</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modern-card">
        <div class="table-responsive">
            <table class="table table-custom mb-0" id="dataTable">
                <thead>
                    <tr>
                        <th>SHR No.</th>
                        <th>Date</th>
                        <th>Shift Timing</th>
                        <th class="text-center">Shift No.</th>
                        <th>Handover By (Outgoing)</th>
                        <th>Acknowledged By (Incoming)</th>
                        <th>High Priority</th>
                        <th>Next Shift Priorities</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <?php if (empty($grouped_handovers)): ?>
                        <tr id="noDataRaw"><td colspan="9" class="text-center py-5 text-muted"><i data-feather="inbox" class="mb-2" style="width:24px;height:24px;opacity:0.5;"></i><br><span style="font-size: 0.8rem;">No personal handovers found.</span></td></tr>
                    <?php else: ?>
                        <?php foreach ($grouped_handovers as $date => $date_handovers): 
                            $is_today = ($date === $today_date);
                            $display_date = $is_today ? "Today (" . date('M d, Y', strtotime($date)) . ")" : date('M d, Y', strtotime($date));
                        ?>
                            <tr class="date-header cursor-pointer border-bottom border-top" data-date="<?= $date ?>">
                                <td colspan="9" class="py-2">
                                    <div class="d-flex align-items-center fw-bold text-primary" style="font-size: 0.85rem;">
                                        <span class="icon-collapsed"><i data-feather="chevron-right" style="width:16px;"></i></span>
                                        <span class="icon-expanded d-none"><i data-feather="chevron-down" style="width:16px;"></i></span>
                                        <span class="ms-1"><?= $display_date ?></span>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill ms-2 count-badge" style="font-size: 0.7rem;">0 Handovers</span>
                                    </div>
                                </td>
                            </tr>
                            
                            <?php foreach ($date_handovers as $shr): 
                                $snapshot = json_decode($shr['glpi_snapshot_json'], true) ?: [];
                                $summary = $snapshot['summary'] ?? [];
                                $tickets = $snapshot['tickets'] ?? [];
                                
                                $hp_count = isset($summary['high_priority']['count']) ? (int)$summary['high_priority']['count'] : 0;
                                $nsp_count = count(array_filter($tickets, fn($t) => !empty($t['is_priority'])));

                                $daily_no = $shr['daily_shift_no'] ?? 1;
                                preg_match('/\((.*?)\)/', $shr['shift_type'], $matches);
                                $time_only = isset($matches[1]) ? trim($matches[1]) : htmlspecialchars($shr['shift_type']);
                            ?>
                            <tr class="clickable-row data-row" 
                                data-date="<?= $shr['shift_date'] ?>"
                                data-shift="<?= htmlspecialchars($shr['shift_type']) ?>"
                                data-outgoing="<?= htmlspecialchars($shr['created_by_name']) ?>"
                                data-incoming="<?= htmlspecialchars($shr['acknowledged_by_name']) ?>"
                                data-status="<?= htmlspecialchars($shr['status']) ?>"
                                onclick="window.location='<?php echo APP_BASE_PATH; ?>?route=view_shr&id=<?= $shr['id'] ?>'">
                                
                                <td class="ps-3">
                                    <span class="badge bg-light text-dark border px-2 py-1" style="font-size: 0.75rem;"><?= htmlspecialchars($shr['shr_no'] ?? $shr['id']) ?></span>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= date('M d, Y', strtotime($shr['shift_date'])) ?></div>
                                </td>
                                <td>
                                    <div class="text-muted fw-medium" style="font-size: 0.75rem;"><?= $time_only ?></div>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-secondary text-white border px-2 py-1 fs-6"><?= $daily_no ?></span>
                                </td>
                                <td>
                                    <div class="fw-medium"><?= htmlspecialchars($shr['created_by_name']) ?></div>
                                    <div class="text-muted" style="font-size: 0.75rem;"><?= date('h:i A', strtotime($shr['created_at'])) ?></div>
                                </td>
                                <td>
                                    <?php if ($shr['status'] === 'Completed'): ?>
                                        <div class="fw-medium text-success"><?= htmlspecialchars($shr['acknowledged_by_name']) ?></div>
                                        <div class="text-muted" style="font-size: 0.75rem;"><?= date('h:i A', strtotime($shr['acknowledged_at'])) ?></div>
                                    <?php else: ?>
                                        <span class="text-muted fst-italic" style="font-size: 0.75rem;">Ack. Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($hp_count > 0): ?>
                                        <span class="badge bg-danger rounded-pill" style="font-size: 0.7rem;"><?= $hp_count ?> High/Crit</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border" style="font-size: 0.7rem;">0 High/Crit</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($nsp_count > 0): ?>
                                        <span class="badge bg-warning text-dark border border-warning rounded-pill" style="font-size: 0.7rem;"><?= $nsp_count ?> Priority</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border" style="font-size: 0.7rem;">0 Priority</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($shr['status'] === 'Completed'): ?>
                                        <span class="status-badge bg-success-subtle text-success border border-success-subtle">Completed</span>
                                    <?php else: ?>
                                        <span class="status-badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Pending Ack</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <tr id="noDataRow" style="display: none;"><td colspan="9" class="text-center py-5 text-muted"><i data-feather="search" class="mb-2" style="width:24px;height:24px;opacity:0.5;"></i><br><span style="font-size: 0.8rem;">No matching records found.</span></td></tr>
                </tbody>
            </table>
        </div>
        
        <div class="card-footer bg-white border-top px-3 py-2 d-flex justify-content-between align-items-center">
            <div class="text-muted fw-medium" id="pageInfo" style="font-size: 0.75rem;">Showing 0 to 0 of 0 dates</div>
            <nav><ul class="pagination mb-0" id="pagination"></ul></nav>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    if(typeof feather !== 'undefined') feather.replace();

    const searchInput = document.getElementById('globalSearch');
    const filterInputs = document.querySelectorAll('.filter-input');
    const btnClearFilters = document.getElementById('btnClearFilters');
    
    const dateHeaders = Array.from(document.querySelectorAll('.date-header'));
    const rows = Array.from(document.querySelectorAll('.data-row'));
    const noDataRow = document.getElementById('noDataRow');
    
    const paginationUl = document.getElementById('pagination');
    const pageInfo = document.getElementById('pageInfo');

    const todayDate = "<?= $today_date ?>";
    let expandedDates = new Set([todayDate]); 
    let currentPage = 1;
    const datesPerPage = 10;

    let rowsByDate = {};
    rows.forEach(r => {
        let d = r.dataset.date;
        if(!rowsByDate[d]) rowsByDate[d] = [];
        rowsByDate[d].push(r);
    });

    function renderTable() {
        const searchTerm = searchInput.value.toLowerCase().trim();
        const filters = {
            date: document.getElementById('fltDate').value,
            shift: document.getElementById('fltShift').value,
            outgoing: document.getElementById('fltOut').value,
            incoming: document.getElementById('fltIn').value,
            status: document.getElementById('fltStatus').value
        };

        const isFiltering = searchTerm !== '' || Object.values(filters).some(v => v !== '');
        let visibleDates = [];

        rows.forEach(row => {
            row.style.display = 'none'; 
            let isMatch = true;

            if (searchTerm && !row.innerText.toLowerCase().includes(searchTerm)) isMatch = false;
            if (isMatch && filters.date && row.dataset.date !== filters.date) isMatch = false;
            if (isMatch && filters.shift && row.dataset.shift !== filters.shift) isMatch = false;
            if (isMatch && filters.outgoing && row.dataset.outgoing !== filters.outgoing) isMatch = false;
            if (isMatch && filters.incoming && row.dataset.incoming !== filters.incoming) isMatch = false;
            if (isMatch && filters.status && row.dataset.status !== filters.status) isMatch = false;

            if (isMatch) row.classList.add('is-match');
            else row.classList.remove('is-match');
        });

        dateHeaders.forEach(header => {
            let date = header.dataset.date;
            let matchingRows = (rowsByDate[date] || []).filter(r => r.classList.contains('is-match'));
            let count = matchingRows.length;

            if (count > 0) {
                header.querySelector('.count-badge').innerText = count + (count === 1 ? ' Handover' : ' Handovers');
                visibleDates.push({ date: date, header: header, rows: matchingRows });
            } else {
                header.style.display = 'none';
            }
        });

        let totalItems = visibleDates.length;
        let totalPages = Math.ceil(totalItems / datesPerPage) || 1;
        if (currentPage > totalPages) currentPage = totalPages;

        let startIdx = (currentPage - 1) * datesPerPage;
        let endIdx = startIdx + datesPerPage;

        dateHeaders.forEach(h => h.style.display = 'none');

        let currentSlice = visibleDates.slice(startIdx, endIdx);
        
        currentSlice.forEach(item => {
            item.header.style.display = '';
            
            let iconCollapsed = item.header.querySelector('.icon-collapsed');
            let iconExpanded = item.header.querySelector('.icon-expanded');

            let shouldExpand = expandedDates.has(item.date) || isFiltering;

            if (shouldExpand) {
                iconCollapsed.classList.add('d-none');
                iconExpanded.classList.remove('d-none');
                item.rows.forEach(r => r.style.display = '');
            } else {
                iconExpanded.classList.add('d-none');
                iconCollapsed.classList.remove('d-none');
            }
        });

        if(document.getElementById('noDataRaw')) document.getElementById('noDataRaw').style.display = 'none';
        noDataRow.style.display = totalItems === 0 ? '' : 'none';

        const dispStart = totalItems === 0 ? 0 : startIdx + 1;
        const dispEnd = Math.min(endIdx, totalItems);
        pageInfo.innerHTML = `Showing <b>${dispStart}</b> to <b>${dispEnd}</b> of <b>${totalItems}</b> dates`;

        buildPagination(totalPages);
    }

    function buildPagination(totalPages) {
        paginationUl.innerHTML = '';
        if (totalPages <= 1) return;

        paginationUl.innerHTML += `<li class="page-item ${currentPage === 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${currentPage - 1}">&laquo;</a></li>`;
        
        let startPage = Math.max(1, currentPage - 2);
        let endPage = Math.min(totalPages, startPage + 4);
        if (endPage - startPage < 4) startPage = Math.max(1, endPage - 4);

        for (let i = startPage; i <= endPage; i++) {
            paginationUl.innerHTML += `<li class="page-item ${currentPage === i ? 'active' : ''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
        }

        paginationUl.innerHTML += `<li class="page-item ${currentPage === totalPages ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${currentPage + 1}">&raquo;</a></li>`;
    }

    dateHeaders.forEach(header => {
        header.addEventListener('click', function() {
            let d = this.dataset.date;
            if (expandedDates.has(d)) {
                expandedDates.delete(d);
            } else {
                expandedDates.add(d);
            }
            renderTable();
        });
    });

    searchInput.addEventListener('input', () => { currentPage = 1; renderTable(); });
    filterInputs.forEach(input => input.addEventListener('change', () => { currentPage = 1; renderTable(); }));

    btnClearFilters.addEventListener('click', () => {
        searchInput.value = '';
        filterInputs.forEach(input => input.value = '');
        expandedDates = new Set([todayDate]); 
        currentPage = 1;
        renderTable();
    });

    paginationUl.addEventListener('click', (e) => {
        e.preventDefault();
        if (e.target.tagName === 'A') {
            const page = parseInt(e.target.dataset.page);
            if (!isNaN(page) && page !== currentPage) {
                currentPage = page;
                renderTable();
            }
        }
    });

    renderTable(); 
});
</script>

<?php include_once '../includes/footer.php'; ?>