<?php
// FILE: kb/kb.php

date_default_timezone_set('Asia/Kolkata');
if (session_status() === PHP_SESSION_NONE) { session_start(); }

include_once '../includes/db_connect.php';
include_once '../includes/auth.php';

$pageTitle = "Knowledge Base & Manuals";
$message = '';

// --- HANDLE DELETE ---
if (isset($_POST['delete_id'])) {
    $del_id = (int)$_POST['delete_id'];
    
    $stmt_file = $conn->prepare("SELECT pdf_filename FROM kb_articles WHERE id = ?");
    $stmt_file->bind_param("i", $del_id);
    $stmt_file->execute();
    $file_res = $stmt_file->get_result()->fetch_assoc();
    $stmt_file->close();

    if ($file_res && !empty($file_res['pdf_filename'])) {
        $file_path = '../uploads/kb/' . $file_res['pdf_filename'];
        if (file_exists($file_path)) unlink($file_path);
    }

    $stmt_del = $conn->prepare("DELETE FROM kb_articles WHERE id = ?");
    $stmt_del->bind_param("i", $del_id);
    if ($stmt_del->execute()) $message = "Article deleted successfully.";
    $stmt_del->close();
}

// --- HANDLE SERVER-SIDE SEARCH, FILTER & PAGINATION ---
$search_query = $_GET['q'] ?? '';
$cat_filter = !empty($_GET['cat']) ? (int)$_GET['cat'] : null;
$dept_filter = $_GET['user_department'] ?? '';

// Pagination variables
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Base Condition Setup
$where_clauses = ["k.status = 'Published'"];
$params = [];
$types = "";

if ($cat_filter) {
    $where_clauses[] = "k.category_id = ?";
    $params[] = $cat_filter;
    $types .= "i";
}

if (!empty($dept_filter)) {
    $where_clauses[] = "l.user_department = ?";
    $params[] = $dept_filter;
    $types .= "s";
}

if (!empty($search_query)) {
    $where_clauses[] = "(k.title LIKE ? OR k.description LIKE ? OR k.extracted_text LIKE ? OR l.ticket_id LIKE ?)";
    $term = "%" . $search_query . "%";
    array_push($params, $term, $term, $term, $term);
    $types .= "ssss";
}

$where_sql = implode(" AND ", $where_clauses);

// --- 1. Get Total Count for Pagination ---
$count_sql = "SELECT COUNT(DISTINCT k.id) as total 
              FROM kb_articles k 
              LEFT JOIN kb_categories c ON k.category_id = c.id 
              LEFT JOIN kb_ticket_links l ON k.id = l.kb_id 
              WHERE $where_sql";

$stmt_count = $conn->prepare($count_sql);
if (!empty($params)) $stmt_count->bind_param($types, ...$params);
$stmt_count->execute();
$total_rows = $stmt_count->get_result()->fetch_assoc()['total'];
$stmt_count->close();
$total_pages = $total_rows > 0 ? ceil($total_rows / $limit) : 1;

// --- 2. Fetch Paginated Records ---
// Modified GROUP_CONCAT to pull ticket_id AND user_department split by a pipe '|'
$sql = "
    SELECT 
        k.id, k.title, k.description, k.pdf_filename, c.name as category_name,
        GROUP_CONCAT(DISTINCT CONCAT(l.ticket_id, '|', COALESCE(l.user_department, '')) ORDER BY l.added_at DESC SEPARATOR ',') as linked_tickets
    FROM kb_articles k
    LEFT JOIN kb_categories c ON k.category_id = c.id
    LEFT JOIN kb_ticket_links l ON k.id = l.kb_id
    WHERE $where_sql
    GROUP BY k.id 
    ORDER BY k.created_at DESC
    LIMIT ? OFFSET ?
";

// Append pagination bind params
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$articles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch all categories for the filter dropdown
$cat_res = $conn->query("SELECT id, name FROM kb_categories ORDER BY name ASC");
$all_categories = $cat_res->fetch_all(MYSQLI_ASSOC);

// --- FETCH TICKET DETAILS FROM SHR SNAPSHOTS ---
$all_page_tickets = [];
foreach ($articles as $art) {
    if (!empty($art['linked_tickets'])) {
        foreach (explode(',', $art['linked_tickets']) as $data_str) {
            $parts = explode('|', $data_str);
            $t_id = trim($parts[0] ?? '');
            if ($t_id) $all_page_tickets[] = $t_id;
        }
    }
}
$all_page_tickets = array_unique($all_page_tickets);
$ticket_details_cache = [];

if (!empty($all_page_tickets)) {
    $stmt_shr = $conn->prepare("SELECT glpi_snapshot_json FROM shift_handovers ORDER BY id DESC LIMIT 300");
    $stmt_shr->execute();
    $res_shr = $stmt_shr->get_result();
    
    while ($row = $res_shr->fetch_assoc()) {
        $json = json_decode($row['glpi_snapshot_json'], true);
        if (isset($json['tickets'])) {
            foreach ($json['tickets'] as $t) {
                if (in_array($t['id'], $all_page_tickets) && !isset($ticket_details_cache[$t['id']])) {
                    $ticket_details_cache[$t['id']] = $t;
                }
            }
        }
    }
    $stmt_shr->close();
}

$prioMap = [
    1 => ['label' => 'Very Low', 'class' => 'bg-secondary'],
    2 => ['label' => 'Low', 'class' => 'bg-info text-dark'],
    3 => ['label' => 'Medium', 'class' => 'bg-primary'],
    4 => ['label' => 'High', 'class' => 'bg-warning text-dark'],
    5 => ['label' => 'Very High', 'class' => 'bg-danger'],
    6 => ['label' => 'Major', 'class' => 'bg-dark']
];

include_once '../includes/header.php';
?>

<style>
    .table-custom-header th { background-color: #f8fafc; font-size: 0.75rem; text-transform: uppercase; color: #64748b; letter-spacing: 0.05em; border-bottom: 2px solid #e2e8f0; padding: 12px 16px; }
    .doc-title { font-size: 0.95rem; font-weight: 600; color: #1e293b; }
    .doc-desc { font-size: 0.8rem; color: #64748b; margin-top: 4px; }
    .sub-table-container { background-color: #f1f5f9; padding: 16px 24px; border-bottom: 1px solid #e2e8f0; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02); }
    .sub-table th { font-size: 0.7rem; color: #475569; text-transform: uppercase; border-bottom: 1px solid #cbd5e1; }
    .sub-table td { font-size: 0.8rem; color: #334155; vertical-align: middle; border-bottom: 1px solid #e2e8f0; background: #fff; }
    .btn-expand { font-size: 0.8rem; font-weight: 500; padding: 4px 12px; border-radius: 6px; }
    tr.main-row:hover { background-color: #f8fafc; }
    .category-badge { font-size: 0.75rem; padding: 5px 12px; font-weight: 500; }

/* Prevents positioning jerks while allowing a smooth fade-in for the filter dropdown ONLY */
    #filterDropdown + .dropdown-menu {
        display: block !important;
        visibility: hidden;
        opacity: 0;
        transition: visibility 0s, opacity 0.15s ease-in-out !important;
    }
    
    #filterDropdown + .dropdown-menu.show {
        visibility: visible;
        opacity: 1;
    }
</style>

<div class="container-xl py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold m-0 text-dark">Knowledge Base</h3>
            <p class="text-muted small mb-0">Search documents and explore related GLPI tickets.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            
            <!-- Hanging Filter Dropdown Panel with data-bs-offset to push it below the button -->
            <div class="dropdown">
                <button class="btn btn-light bg-white border shadow-sm fw-medium dropdown-toggle text-dark" type="button" id="filterDropdown" data-bs-toggle="dropdown" aria-expanded="false" data-bs-auto-close="outside" data-bs-offset="0,10">
                    <i data-feather="filter" style="width:16px;" class="me-1 text-dark"></i> Filter Options
                </button>
                <div class="dropdown-menu dropdown-menu-end p-3 shadow-lg border-0" style="width: 320px;" aria-labelledby="filterDropdown">
                    <form method="GET" action="">
                        <input type="hidden" name="route" value="kb_index">
                        
                        <div class="mb-3">
                            <label class="form-label small text-muted fw-bold text-uppercase">Search Keyword</label>
                            <input type="text" name="q" class="form-control form-control-sm border-secondary" placeholder="Title, description, or ticket no..." value="<?= htmlspecialchars($search_query) ?>">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label small text-muted fw-bold text-uppercase">Category Filter</label>
                            <select name="cat" class="form-select form-select-sm border-secondary">
                                <option value="">All Categories</option>
                                <?php foreach ($all_categories as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= $cat_filter === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small text-muted fw-bold text-uppercase">User Department</label>
                            <select name="user_department" class="form-select form-select-sm border-secondary">
                                <option value="">All Departments</option>
                                <option value="Management" <?= $dept_filter === 'Management' ? 'selected' : '' ?>>Management</option>
                                <option value="IT" <?= $dept_filter === 'IT' ? 'selected' : '' ?>>IT</option>
                                <option value="HR" <?= $dept_filter === 'HR' ? 'selected' : '' ?>>HR</option>
                            </select>
                        </div>
                        
                        <div class="d-grid gap-2 mt-4 border-top pt-3">
                            <button type="submit" class="btn btn-dark btn-sm fw-medium">Apply Search & Filters</button>
                            <a href="?route=kb_index" class="btn btn-light btn-sm border">Clear All</a>
                        </div>
                    </form>
                </div>
            </div>

            <a href="<?php echo APP_BASE_PATH; ?>?route=kb_create" class="btn btn-primary shadow-sm fw-medium">
                <i data-feather="settings" style="width:16px;" class="me-1"></i> Manage KB
            </a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success d-flex align-items-center rounded-3 shadow-sm border-0 mb-4">
            <i data-feather="check-circle" class="me-2"></i> <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 mb-4 rounded-3 overflow-hidden">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-custom-header">
                    <tr>
                        <th style="width: 35%;">Document Title & Info</th>
                        <th style="width: 15%;">Category</th>
                        <th style="width: 15%;">Document</th>
                        <th style="width: 15%;" class="text-center">Resolved Cases</th>
                        <th style="width: 20%;" class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($articles)): ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted"><i data-feather="inbox" class="mb-3 opacity-50" style="width:32px; height:32px;"></i><br>No documents found matching the filter criteria.</td></tr>
                    <?php else: ?>
                        <?php foreach ($articles as $art): 
                            
                            // Parse linked tickets and segregate by Department
                            $management_tickets = [];
                            $other_tickets = [];
                            
                            $tickets_raw = !empty($art['linked_tickets']) ? array_unique(explode(',', $art['linked_tickets'])) : [];
                            foreach ($tickets_raw as $data_str) {
                                $parts = explode('|', $data_str);
                                $t_id = trim($parts[0] ?? '');
                                $dept = trim($parts[1] ?? '');
                                
                                if (!$t_id) continue;
                                
                                if ($dept === 'Management') {
                                    $management_tickets[$t_id] = $t_id;
                                } else {
                                    $other_tickets[$t_id] = $t_id;
                                }
                            }
                            
                            $ticket_count = count($management_tickets) + count($other_tickets);
                        ?>
                        <tr class="main-row border-bottom">
                            <td class="px-3 py-3">
                                <div class="doc-title"><?= htmlspecialchars($art['title']) ?></div>
                                <?php if (!empty($art['description'])): ?>
                                    <div class="doc-desc text-truncate" style="max-width: 350px;" title="<?= htmlspecialchars($art['description']) ?>">
                                        <?= htmlspecialchars($art['description']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <!-- Category Column -->
                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle category-badge">
                                    <?= htmlspecialchars($art['category_name'] ?? 'Uncategorized') ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($art['pdf_filename'])): ?>
                                    <a href="<?php echo APP_BASE_PATH; ?>/uploads/kb/<?= htmlspecialchars($art['pdf_filename']) ?>" target="_blank" class="badge bg-light text-primary border border-primary-subtle text-decoration-none py-2 px-3 rounded-pill">
                                        <i data-feather="file-text" style="width:12px;" class="me-1"></i> View PDF
                                    </a>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border py-2 px-3 rounded-pill"><i data-feather="file-minus" style="width:12px;" class="me-1"></i> No File</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($ticket_count > 0): ?>
                                    <button class="btn btn-light btn-sm border text-primary btn-expand shadow-sm d-inline-flex align-items-center" data-bs-toggle="collapse" data-bs-target="#tickets-<?= $art['id'] ?>">
                                        <i data-feather="layers" style="width:14px;" class="me-2"></i> <?= $ticket_count ?> <?= $ticket_count === 1 ? 'Ticket' : 'Tickets' ?>
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted small fst-italic">No tickets linked</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end px-3">
                                <a href="<?php echo APP_BASE_PATH; ?>?route=kb_edit&id=<?= $art['id'] ?>" class="btn btn-sm btn-light border text-secondary me-1" title="Edit">
                                    <i data-feather="edit-2" style="width:14px;"></i>
                                </a>
                                <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Permanently delete this manual?');">
                                    <input type="hidden" name="delete_id" value="<?= $art['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-light border text-danger" title="Delete">
                                        <i data-feather="trash-2" style="width:14px;"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        
                        <?php if ($ticket_count > 0): ?>
                        <tr>
                            <td colspan="5" class="p-0 border-0">
                                <div id="tickets-<?= $art['id'] ?>" class="collapse">
                                    <div class="sub-table-container">
                                        
                                        <!-- Management Tickets Block -->
                                        <?php if (count($management_tickets) > 0): ?>
                                            <div class="fw-bold mt-1 mb-2" style="font-size: 0.75rem; text-transform: uppercase; color: #0f766e;">
                                                <i data-feather="briefcase" style="width:14px; margin-top:-2px;" class="me-1"></i> Management Tickets
                                            </div>
                                            <div class="table-responsive border rounded bg-white mb-3">
                                                <table class="table table-sm sub-table mb-0">
                                                    <thead class="bg-light">
                                                        <tr>
                                                            <th class="ps-3" style="width: 15%;">Ticket No</th>
                                                            <th style="width: 40%;">Issue Title</th>
                                                            <th style="width: 20%;">Assigned To</th>
                                                            <th style="width: 15%;">Location</th>
                                                            <th style="width: 10%;">Priority</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($management_tickets as $t_id): 
                                                            $t_data = $ticket_details_cache[$t_id] ?? null;
                                                            $title = $t_data ? $t_data['title'] : 'Details unavailable (Archived)';
                                                            $assigned = $t_data ? $t_data['assigned_to'] : '-';
                                                            $location = $t_data ? ($t_data['location'] ?: 'N/A') : '-';
                                                            $prio_num = $t_data ? (int)$t_data['priority'] : 3;
                                                            $prio_info = $prioMap[$prio_num] ?? $prioMap[3];
                                                        ?>
                                                        <tr>
                                                            <td class="ps-3 fw-bold" style="color: #0f766e;">#<?= htmlspecialchars($t_id) ?></td>
                                                            <td class="fw-medium text-dark"><?= htmlspecialchars($title) ?></td>
                                                            <td><?= htmlspecialchars($assigned) ?></td>
                                                            <td><i data-feather="map-pin" class="text-muted me-1" style="width:10px;"></i><?= htmlspecialchars($location) ?></td>
                                                            <td><span class="badge <?= $prio_info['class'] ?> fw-medium" style="font-size:0.65rem; padding: 3px 6px;"><?= $prio_info['label'] ?></span></td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Other Tickets Block -->
                                        <?php if (count($other_tickets) > 0): ?>
                                            <div class="fw-bold text-dark mt-1 mb-2" style="font-size: 0.75rem; text-transform: uppercase;">
                                                <i data-feather="layers" style="width:14px; margin-top:-2px;" class="me-1"></i> Other Tickets
                                            </div>
                                            <div class="table-responsive border rounded bg-white mb-2">
                                                <table class="table table-sm sub-table mb-0">
                                                    <thead class="bg-light">
                                                        <tr>
                                                            <th class="ps-3" style="width: 15%;">Ticket No</th>
                                                            <th style="width: 40%;">Issue Title</th>
                                                            <th style="width: 20%;">Assigned To</th>
                                                            <th style="width: 15%;">Location</th>
                                                            <th style="width: 10%;">Priority</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($other_tickets as $t_id): 
                                                            $t_data = $ticket_details_cache[$t_id] ?? null;
                                                            $title = $t_data ? $t_data['title'] : 'Details unavailable (Archived)';
                                                            $assigned = $t_data ? $t_data['assigned_to'] : '-';
                                                            $location = $t_data ? ($t_data['location'] ?: 'N/A') : '-';
                                                            $prio_num = $t_data ? (int)$t_data['priority'] : 3;
                                                            $prio_info = $prioMap[$prio_num] ?? $prioMap[3];
                                                        ?>
                                                        <tr>
                                                            <td class="ps-3 fw-bold text-primary">#<?= htmlspecialchars($t_id) ?></td>
                                                            <td class="fw-medium text-dark"><?= htmlspecialchars($title) ?></td>
                                                            <td><?= htmlspecialchars($assigned) ?></td>
                                                            <td><i data-feather="map-pin" class="text-muted me-1" style="width:10px;"></i><?= htmlspecialchars($location) ?></td>
                                                            <td><span class="badge <?= $prio_info['class'] ?> fw-medium" style="font-size:0.65rem; padding: 3px 6px;"><?= $prio_info['label'] ?></span></td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                        
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                        
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination Links -->
        <?php if ($total_pages > 1): ?>
        <div class="card-footer bg-white border-top p-3 d-flex justify-content-between align-items-center">
            <span class="text-muted small">Showing page <?= $page ?> of <?= $total_pages ?></span>
            <ul class="pagination pagination-sm m-0 shadow-sm">
                <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                    <a class="page-link" href="?route=kb_index&page=<?= $page - 1 ?>&q=<?= urlencode($search_query) ?>&cat=<?= $cat_filter ?>&user_department=<?= urlencode($dept_filter) ?>">Prev</a>
                </li>
                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <li class="page-item <?= ($page == $i) ? 'active' : '' ?>">
                        <a class="page-link" href="?route=kb_index&page=<?= $i ?>&q=<?= urlencode($search_query) ?>&cat=<?= $cat_filter ?>&user_department=<?= urlencode($dept_filter) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                    <a class="page-link" href="?route=kb_index&page=<?= $page + 1 ?>&q=<?= urlencode($search_query) ?>&cat=<?= $cat_filter ?>&user_department=<?= urlencode($dept_filter) ?>">Next</a>
                </li>
            </ul>
        </div>
        <?php endif; ?>
        
    </div>
</div>

<script>document.addEventListener('DOMContentLoaded', () => { if(typeof feather !== 'undefined') feather.replace(); });</script>
<?php include_once '../includes/footer.php'; ?>