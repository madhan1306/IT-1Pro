<?php
// FILE: shr/hp_tickets.php

date_default_timezone_set('Asia/Kolkata');
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

include_once '../includes/db_connect.php';
include_once '../includes/auth.php';
include_once '../includes/api.php'; 

$logged_in_username = $_SESSION['username'];
$logged_in_user_fullname = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')) ?: $logged_in_username;

// --- AJAX ENDPOINTS ---
if (isset($_POST['ajax_action'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    if ($_POST['ajax_action'] === 'fetch_hp_tickets') {
        try {
            $token = initGLPISession();
            if (!$token) throw new Exception("GLPI API Authentication Failed. Check API credentials.");

            // --- 1. FETCH DICTIONARIES (Users, Categories, Locations) ---
            $url_users = "/search/User?forcedisplay[0]=1&forcedisplay[1]=2&range=0-9999";
            $res_users = callGLPI($url_users, 'GET', null, $token);
            $user_map = [];
            $users_data = $res_users['data']['data'] ?? $res_users['data'] ?? [];
            if (is_array($users_data)) {
                foreach ($users_data as $u) {
                    if (is_array($u) && isset($u['2']) && isset($u['1'])) { $user_map[$u['2']] = strip_tags($u['1']); }
                }
            }

            $url_cat = "/search/ITILCategory?forcedisplay[0]=1&forcedisplay[1]=2&range=0-9999";
            $res_cat = callGLPI($url_cat, 'GET', null, $token);
            $cat_map = [];
            $cat_data = $res_cat['data']['data'] ?? $res_cat['data'] ?? [];
            if (is_array($cat_data)) {
                foreach ($cat_data as $c) {
                    if (is_array($c) && isset($c['2']) && isset($c['1'])) { $cat_map[$c['2']] = strip_tags($c['1']); }
                }
            }

            $url_loc = "/search/Location?forcedisplay[0]=1&forcedisplay[1]=2&range=0-9999";
            $res_loc = callGLPI($url_loc, 'GET', null, $token);
            $loc_map = [];
            $loc_data = $res_loc['data']['data'] ?? $res_loc['data'] ?? [];
            if (is_array($loc_data)) {
                foreach ($loc_data as $l) {
                    if (is_array($l) && isset($l['2']) && isset($l['1'])) { $loc_map[$l['2']] = strip_tags($l['1']); }
                }
            }

            // --- 2. FETCH HIGH PRIORITY TICKETS ---
            $base_url = "/search/Ticket?forcedisplay[0]=1&forcedisplay[1]=2&forcedisplay[2]=5&forcedisplay[3]=3&forcedisplay[4]=12&forcedisplay[5]=4&forcedisplay[6]=7&forcedisplay[7]=15&forcedisplay[8]=16&forcedisplay[9]=83&range=0-300";
            
            $res_p4 = callGLPI($base_url . "&criteria[0][field]=3&criteria[0][searchtype]=equals&criteria[0][value]=4", 'GET', null, $token);
            $res_p5 = callGLPI($base_url . "&criteria[0][field]=3&criteria[0][searchtype]=equals&criteria[0][value]=5", 'GET', null, $token);
            $res_p6 = callGLPI($base_url . "&criteria[0][field]=3&criteria[0][searchtype]=equals&criteria[0][value]=6", 'GET', null, $token);
            
            killGLPISession($token);

            $get_tickets = function($res) {
                if (isset($res['data']['data']) && is_array($res['data']['data'])) return $res['data']['data'];
                if (isset($res['data']) && is_array($res['data'])) return array_filter($res['data'], 'is_array');
                return [];
            };

            $glpi_data = array_merge($get_tickets($res_p4), $get_tickets($res_p5), $get_tickets($res_p6));

            $tickets = [];
            $seen_ids = [];
            $status_map = [ 1 => 'New', 2 => 'Processing (Assigned)', 3 => 'Processing (Planned)', 4 => 'Pending', 5 => 'Solved', 6 => 'Closed' ];

            // --- 3. PARSE RESULTS ---
            if (!empty($glpi_data)) {
                foreach ($glpi_data as $t) {
                    if (!is_array($t)) continue; 
                    
                    $ticket_id = $t['2'] ?? null;
                    if (!$ticket_id || in_array($ticket_id, $seen_ids)) continue;
                    
                    $ticket_title = $t['1'] ?? 'No Subject';
                    
                    // Identify Category
                    $cat_val = $t['7'] ?? '';
                    $cat_id = is_array($cat_val) ? implode(',', $cat_val) : strip_tags($cat_val);
                    $category_name = $cat_map[$cat_id] ?? $cat_id;

                    // FILTER 1: Exclude if Category or Title contains 'IT Internal'
                    if (stripos($ticket_title, 'IT Internal') !== false || stripos($category_name, 'IT Internal') !== false) {
                        continue;
                    }

                    $seen_ids[] = $ticket_id;

                    $raw_status = isset($t['12']) ? trim(strip_tags($t['12'])) : '1';
                    $status_id = is_numeric($raw_status) ? (int)$raw_status : 1;
                    if (!is_numeric($raw_status)) {
                        $lowered = strtolower($raw_status);
                        if (strpos($lowered, 'assign') !== false || strpos($lowered, 'process') !== false) { $status_id = 2; }
                        elseif (strpos($lowered, 'plan') !== false) { $status_id = 3; }
                        elseif (strpos($lowered, 'pending') !== false || strpos($lowered, 'wait') !== false) { $status_id = 4; }
                        elseif (strpos($lowered, 'solve') !== false) { $status_id = 5; }
                        elseif (strpos($lowered, 'close') !== false) { $status_id = 6; }
                    }
                    $status_text = $status_map[$status_id] ?? 'Open';

                    // Parse Requester
                    $req_names = [];
                    if (!empty($t['4'])) {
                        $reqs = is_array($t['4']) ? $t['4'] : explode(',', $t['4']);
                        foreach ($reqs as $req_id) {
                            $clean_id = trim(strip_tags($req_id));
                            if ($clean_id) { $req_names[] = $user_map[$clean_id] ?? $clean_id; }
                        }
                    }
                    $requester = !empty($req_names) ? implode(', ', $req_names) : 'Unassigned';

                    // Parse Assigned To
                    $assigned_names = [];
                    if (!empty($t['5'])) {
                        $techs = is_array($t['5']) ? $t['5'] : explode(',', $t['5']);
                        foreach ($techs as $tech_id) {
                            $clean_id = trim(strip_tags($tech_id));
                            if ($clean_id) { $assigned_names[] = $user_map[$clean_id] ?? $clean_id; }
                        }
                    }
                    $assigned_to = !empty($assigned_names) ? implode(', ', $assigned_names) : 'Unassigned';

                    // Parse Location
                    $loc_val = $t['83'] ?? '';
                    $loc_id = is_array($loc_val) ? implode(',', $loc_val) : strip_tags($loc_val);
                    $location = $loc_map[$loc_id] ?? $loc_id;
                    if (empty($location)) { $location = 'Unknown Location'; }

                    // Parse Dates
                    $opening_date = isset($t['15']) ? trim(strip_tags($t['15'])) : '';
                    $closing_date = isset($t['16']) ? trim(strip_tags($t['16'])) : '';
                    if ($status_id !== 5 && $status_id !== 6) {
                        $closing_date = ''; // Show blank if not closed
                    }

                    $tickets[] = [
                        'id' => $ticket_id,
                        'title' => $ticket_title,
                        'requester' => $requester,
                        'location' => $location,
                        'opening_date' => $opening_date,
                        'closing_date' => $closing_date,
                        'priority' => (int)($t['3'] ?? 4), 
                        'assigned_to' => $assigned_to,
                        'status' => $status_text,
                        'status_id' => $status_id 
                    ];
                }
            }

            // --- 4. SORT MERGED RESULTS ---
            usort($tickets, function($a, $b) {
                if ($a['priority'] == $b['priority']) {
                    return $b['id'] <=> $a['id'];
                }
                return $b['priority'] <=> $a['priority'];
            });

            echo json_encode(['status' => 'success', 'data' => $tickets]);
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
}

$pageTitle = "High Priority Monitor";
include_once '../includes/header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Inter', sans-serif; background-color: #f3f4f6 !important; }
    .modern-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border: 1px solid #e5e7eb; overflow: hidden; margin-bottom: 24px; }
    .modern-card-header { padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid #e5e7eb; font-weight: 600; color: #0f766e; }
    .btn-primary-pro { background-color: #0f766e; color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; }
    .btn-primary-pro:hover { background-color: #0d9488; color: white; }
    .bg-major { background-color: #7f1d1d !important; color: white !important; }
    .column-filter { font-size: 0.85rem; padding: 0.4rem 0.5rem; }
</style>

<!-- CHANGED to container-fluid px-4 to maximize width -->
<div class="container-fluid px-4 py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold m-0 text-dark"><i data-feather="alert-triangle" class="text-danger me-2" style="width:28px; height:28px;"></i> High Priority Monitor</h3>
            <p class="text-muted small mb-0 mt-1">Real-time overview of all High, Very High, and Major priority tickets.</p>
        </div>
        
        <div class="d-flex gap-2">
            <!-- Standalone Status Select -->
            <select class="form-select border-secondary fw-medium shadow-sm" id="filterStatus" style="width: 220px;">
                <option value="open" selected>Open & Processing Only</option>
                <option value="all">All High Priority Tickets</option>
                <option value="closed">Solved & Closed Only</option>
            </select>

            <!-- Floating Dropdown Column Filters -->
            <div class="dropdown">
                <button class="btn btn-white border border-secondary shadow-sm d-flex align-items-center gap-2" type="button" id="filterDropdownBtn" data-bs-toggle="dropdown" aria-expanded="false" data-bs-auto-close="outside">
                    <i data-feather="filter" style="width:16px;"></i> Filters
                </button>
                <div class="dropdown-menu dropdown-menu-end p-3 shadow-lg border-0" style="width: 350px;">
                    <h6 class="dropdown-header px-0 text-dark fw-bold border-bottom mb-3 pb-2">Filter by Column</h6>
                    
                    <div class="mb-2">
                        <input type="text" id="fltTicket" class="form-control form-control-sm column-filter" placeholder="Ticket No.">
                    </div>
                    <div class="mb-2">
                        <input type="text" id="fltTitle" class="form-control form-control-sm column-filter" placeholder="Title">
                    </div>
                    <div class="mb-2">
                        <select id="fltRequester" class="form-select form-select-sm column-filter">
                            <option value="">All Requesters</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <select id="fltLocation" class="form-select form-select-sm column-filter">
                            <option value="">All Locations</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <select id="fltAssigned" class="form-select form-select-sm column-filter">
                            <option value="">All Assignees</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label text-muted small fw-bold mb-1">Opening Date Range</label>
                        <div class="d-flex gap-2">
                            <input type="date" id="fltDateFrom" class="form-control form-control-sm column-filter" title="From Date">
                            <input type="date" id="fltDateTo" class="form-control form-control-sm column-filter" title="To Date">
                        </div>
                    </div>
                    <div class="mb-2 mt-3">
                        <select id="fltPriority" class="form-select form-select-sm column-filter">
                            <option value="">All Priorities</option>
                            <option value="high">High</option>
                            <option value="very high">Very High</option>
                            <option value="major">Major</option>
                        </select>
                    </div>
                    
                    <button class="btn btn-sm btn-light w-100 border mt-3 text-secondary fw-medium" type="button" id="btnClearFilters">
                        Clear Column Filters
                    </button>
                </div>
            </div>

            <!-- Refresh Button -->
            <button type="button" id="btnRefresh" class="btn btn-primary-pro shadow-sm d-flex align-items-center gap-2">
                <i data-feather="refresh-cw" style="width:16px;"></i> Refresh
            </button>
        </div>
    </div>

    <div class="modern-card">
        <div class="modern-card-header text-secondary d-flex justify-content-between align-items-center">
            <span><i data-feather="list" style="width:18px;" class="me-2"></i> Ticket Queue</span>
            <span class="badge bg-secondary" id="ticketCountBadge">0 Tickets</span>
        </div>
        <div class="card-body p-0">
            <div id="loadingIndicator" class="text-center py-5 text-muted">
                <div class="spinner-border text-primary mb-3" role="status"></div>
                <div>Fetching High Priority Queue...</div>
            </div>
            
            <div id="errorContainer" class="text-center py-5 text-danger d-none fw-bold"></div>

            <div id="tableContainer" class="table-responsive bg-white d-none">
                <table class="table table-hover align-middle mb-0" style="font-size: 0.95rem;">
                    <thead class="table-light text-muted border-bottom">
                        <tr>
                            <th class="ps-4" style="width: 100px;">Ticket No.</th>
                            <th>Title</th>
                            <th>Requester</th>
                            <th>Location</th>
                            <th>Assigned To</th>
                            <th>Opening Date</th>
                            <th>Closing Date</th>
                            <th>Status</th>
                            <th class="pe-4">Priority</th>
                        </tr>
                    </thead>
                    <tbody id="ticketTableBody">
                        <!-- Populated by JS -->
                    </tbody>
                </table>
            </div>
            
            <div id="emptyContainer" class="text-center py-5 text-muted d-none">
                <i data-feather="check-circle" style="width:48px; height:48px; opacity:0.3;" class="mb-3"></i><br>
                <span class="fs-5">No tickets found for the current filter.</span>
            </div>
        </div>
    </div>
</div>

<script>
window.allHpTickets = [];

const prioMap = { 
    1: 'Very Low', 2: 'Low', 3: 'Medium', 
    4: 'High', 5: 'Very High', 6: 'Major' 
};

// Dynamically extracts unique names and locations to populate the select dropdowns
function populateDynamicFilters() {
    const reqSelect = document.getElementById('fltRequester');
    const locSelect = document.getElementById('fltLocation');
    const assSelect = document.getElementById('fltAssigned');
    
    // Reset to defaults
    reqSelect.innerHTML = '<option value="">All Requesters</option>';
    locSelect.innerHTML = '<option value="">All Locations</option>';
    assSelect.innerHTML = '<option value="">All Assignees</option>';
    
    const requesters = new Set();
    const locations = new Set();
    const assignees = new Set();
    
    window.allHpTickets.forEach(t => {
        // Split multiple requesters by comma if they exist
        if (t.requester && t.requester !== 'Unassigned') {
            t.requester.split(',').forEach(name => requesters.add(name.trim()));
        }
        
        // Add Location
        if (t.location) {
            locations.add(t.location.trim());
        }

        // Split multiple assignees by comma if they exist
        if (t.assigned_to && t.assigned_to !== 'Unassigned') {
            t.assigned_to.split(',').forEach(name => assignees.add(name.trim()));
        }
    });
    
    // Sort alphabetically and append to dropdowns
    Array.from(requesters).sort().forEach(r => { if(r) reqSelect.add(new Option(r, r)); });
    Array.from(locations).sort().forEach(l => { if(l) locSelect.add(new Option(l, l)); });
    Array.from(assignees).sort().forEach(a => { if(a) assSelect.add(new Option(a, a)); });
}

function renderTable() {
    const statusFilter = document.getElementById('filterStatus').value;
    
    // Header Inputs
    const fltTicket = document.getElementById('fltTicket').value.toLowerCase();
    const fltTitle = document.getElementById('fltTitle').value.toLowerCase();
    const fltRequester = document.getElementById('fltRequester').value.toLowerCase();
    const fltLocation = document.getElementById('fltLocation').value.toLowerCase();
    const fltAssigned = document.getElementById('fltAssigned').value.toLowerCase();
    const fltDateFrom = document.getElementById('fltDateFrom').value; 
    const fltDateTo = document.getElementById('fltDateTo').value; 
    const fltPriority = document.getElementById('fltPriority').value.toLowerCase();

    const tbody = document.getElementById('ticketTableBody');
    const emptyState = document.getElementById('emptyContainer');
    const tableContainer = document.getElementById('tableContainer');
    const countBadge = document.getElementById('ticketCountBadge');
    
    tbody.innerHTML = '';
    
    let filteredTickets = window.allHpTickets.filter(t => {
        const isClosed = (t.status_id === 5 || t.status_id === 6);
        
        // 1. Dropdown Status Filter
        if (statusFilter === 'open' && isClosed) return false;
        if (statusFilter === 'closed' && !isClosed) return false;
        
        // 2. Individual Column Filters
        if (fltTicket && !String(t.id).toLowerCase().includes(fltTicket)) return false;
        if (fltTitle && !String(t.title).toLowerCase().includes(fltTitle)) return false;
        
        // Use includes() for names since a single ticket might have multiple requesters/assignees
        if (fltRequester && !t.requester.toLowerCase().includes(fltRequester)) return false;
        if (fltAssigned && !t.assigned_to.toLowerCase().includes(fltAssigned)) return false;
        
        // Use exact match for location
        if (fltLocation && t.location.toLowerCase() !== fltLocation) return false;
        
        // Date Range Check (comparing ticket opening date)
        if (fltDateFrom || fltDateTo) {
            // Extract YYYY-MM-DD from the opening date (e.g. "2024-05-12 10:30:00")
            const ticketDateStr = t.opening_date ? t.opening_date.substring(0, 10) : '';
            if (!ticketDateStr) return false; // Hide tickets with no date if date filter is active
            
            if (fltDateFrom && ticketDateStr < fltDateFrom) return false;
            if (fltDateTo && ticketDateStr > fltDateTo) return false;
        }

        // Priority matching
        let prioLabel = prioMap[t.priority] || 'High';
        if (fltPriority && prioLabel.toLowerCase() !== fltPriority) return false;

        return true; 
    });
    
    countBadge.innerText = `${filteredTickets.length} Tickets`;

    if (filteredTickets.length === 0) {
        tableContainer.classList.add('d-none');
        emptyState.classList.remove('d-none');
        return;
    }

    tableContainer.classList.remove('d-none');
    emptyState.classList.add('d-none');

    let html = '';
    filteredTickets.forEach(t => {
        let isClosed = (t.status_id === 5 || t.status_id === 6);
        let trClass = isClosed ? 'table-light text-muted' : (t.priority >= 5 ? 'table-danger' : '');
        
        let prioLabel = prioMap[t.priority] || 'High';
        
        let prioBadgeClass = 'bg-secondary';
        if (t.priority == 4) prioBadgeClass = 'bg-warning text-dark';
        if (t.priority == 5) prioBadgeClass = 'bg-danger';
        if (t.priority == 6) prioBadgeClass = 'bg-major';

        let statusBadgeClass = isClosed ? 'bg-secondary text-white' : 'bg-light text-dark border';

        html += `
            <tr class="${trClass}">
                <td class="fw-bold text-primary ps-4">
                    <a href="javascript:void(0)" class="text-decoration-none">${t.id}</a>
                </td>
                <td class="fw-medium text-dark ${isClosed ? 'text-decoration-line-through opacity-75' : ''}">${t.title}</td>
                <td>${t.requester}</td>
                <td>${t.location}</td>
                <td>${t.assigned_to}</td>
                <td class="text-nowrap">${t.opening_date}</td>
                <td class="text-nowrap">${t.closing_date}</td>
                <td><span class="badge ${statusBadgeClass}">${t.status}</span></td>
                <td class="pe-4">
                    <span class="badge ${prioBadgeClass} shadow-sm">${prioLabel}</span>
                </td>
            </tr>
        `;
    });
    
    tbody.innerHTML = html;
}

function fetchHPTickets() {
    const btn = document.getElementById('btnRefresh');
    const loading = document.getElementById('loadingIndicator');
    const tableContainer = document.getElementById('tableContainer');
    const errorContainer = document.getElementById('errorContainer');
    const emptyContainer = document.getElementById('emptyContainer');

    btn.disabled = true;
    loading.classList.remove('d-none');
    tableContainer.classList.add('d-none');
    errorContainer.classList.add('d-none');
    emptyContainer.classList.add('d-none');

    const fd = new FormData();
    fd.append('ajax_action', 'fetch_hp_tickets');

    fetch('<?php echo APP_BASE_PATH; ?>?route=hp_tickets', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(res => {
            btn.disabled = false;
            loading.classList.add('d-none');
            
            if (res.status === 'success') {
                window.allHpTickets = res.data;
                populateDynamicFilters(); // Update the drop-down menus before rendering
                renderTable();
            } else {
                errorContainer.classList.remove('d-none');
                errorContainer.innerText = `Error: ${res.message}`;
            }
        })
        .catch(err => {
            btn.disabled = false;
            loading.classList.add('d-none');
            errorContainer.classList.remove('d-none');
            errorContainer.innerText = 'Network Error connecting to GLPI API. Check your logs.';
        });
}

document.addEventListener('DOMContentLoaded', () => {
    if(typeof feather !== 'undefined') feather.replace();
    
    // Initial fetch
    fetchHPTickets();
    
    // Event Listeners
    document.getElementById('btnRefresh').addEventListener('click', fetchHPTickets);
    document.getElementById('filterStatus').addEventListener('change', renderTable);
    
    // Attach input/change events to all column filter fields
    document.querySelectorAll('.column-filter').forEach(input => {
        input.addEventListener('input', renderTable);
        input.addEventListener('change', renderTable);
    });

    // Clear filters button logic
    document.getElementById('btnClearFilters').addEventListener('click', () => {
        document.querySelectorAll('.column-filter').forEach(input => input.value = '');
        renderTable();
    });
});
</script>
<?php include_once '../includes/footer.php'; ?>