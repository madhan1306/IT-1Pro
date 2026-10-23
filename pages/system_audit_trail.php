<?php

include_once '../includes/error_handler.php';
include_once '../includes/db_connect.php';
include_once '../includes/auth.php';

// Protect this page with the specific permission
checkPermission('view_audit_trail');

$pageTitle = "System Audit Trail";

// --- START: Tab and Filter Logic ---
$active_tab = $_GET['tab'] ?? 'all'; // 'all' for all other logs, 'auth' for authentication logs

$where_clause = "";
$params = [];
$types = "";

if ($active_tab == 'auth') {
    $where_clause = "WHERE action_type = ?";
    $params[] = 'Authentication';
    $types .= 's';
} else { 
    $active_tab = 'all'; // Ensure a valid default
    $where_clause = "WHERE action_type != ?";
    $params[] = 'Authentication';
    $types .= 's';
}
// --- END: Tab and Filter Logic ---


// Pagination Logic
$records_per_page = 20;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Get total number of records based on the filter
$count_stmt = $conn->prepare("SELECT COUNT(id) FROM system_logs " . $where_clause);
$count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_logs = $count_stmt->get_result()->fetch_row()[0];
$total_pages = ceil($total_logs / $records_per_page);
$count_stmt->close();

// Fetch logs for the current page based on the filter (ADDED ip_address)
$logs = [];
$fetch_sql = "SELECT timestamp, performed_by, action_type, description, ip_address FROM system_logs " . $where_clause . " ORDER BY timestamp DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($fetch_sql);

// Add pagination params to the existing filter params
$fetch_params = $params;
$fetch_params[] = $records_per_page;
$fetch_params[] = $offset;
$fetch_types = $types . 'ii';

$stmt->bind_param($fetch_types, ...$fetch_params);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $logs[] = $row;
}
$stmt->close();

include_once '../includes/header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    :root {
        --primary-color: #0f766e;
        --bg-color: #f8fafc;
        --card-bg: #ffffff;
        --border-color: #e2e8f0;
        --text-main: #334155;
        --text-light: #64748b;
        --radius: 12px;
    }

    body { font-family: 'Inter', sans-serif; background-color: var(--bg-color) !important; color: var(--text-main); font-size: 0.875rem; }
    h1, h2, h3, h4, h5, h6 { font-weight: 600; color: #1e293b; }

    .modern-card { background: var(--card-bg); border-radius: var(--radius); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid var(--border-color); overflow: hidden; margin-bottom: 24px; }
    
    /* Modern Tabs */
    .nav-tabs-modern { border-bottom: 1px solid var(--border-color); padding: 0 20px; }
    .nav-tabs-modern .nav-link { color: var(--text-light); border: none; border-bottom: 3px solid transparent; font-weight: 600; padding: 14px 18px; transition: all 0.2s; border-radius: 0; background: transparent; font-size: 0.9rem; }
    .nav-tabs-modern .nav-link:hover { color: var(--primary-color); }
    .nav-tabs-modern .nav-link.active { color: var(--primary-color); border-bottom: 3px solid var(--primary-color); color: #1e293b;}

    /* Table Styling */
    .table-modern { margin-bottom: 0; }
    .table-modern thead th { 
        background-color: #f8fafc; 
        color: var(--text-light); 
        font-weight: 600; 
        font-size: 0.75rem; 
        text-transform: uppercase; 
        letter-spacing: 0.05em; 
        border-bottom: 1px solid var(--border-color);
        padding: 14px 20px;
    }
    .table-modern tbody td { 
        padding: 14px 20px; 
        vertical-align: middle; 
        border-bottom: 1px solid var(--border-color); 
        color: var(--text-main);
        font-size: 0.85rem;
    }
    .table-modern tbody tr:last-child td { border-bottom: none; }
    .table-modern tbody tr:hover { background-color: #f0fdfa; }

    /* Custom Badges */
    .badge-modern { padding: 6px 12px; border-radius: 20px; font-weight: 600; font-size: 0.75rem; letter-spacing: 0.02em; }
    .bg-auth { background-color: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; }
    .bg-insert { background-color: #dcfce7; color: #059669; border: 1px solid #bbf7d0; }
    .bg-update { background-color: #fef9c3; color: #ca8a04; border: 1px solid #fef08a; }
    .bg-delete { background-color: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }
    .bg-default { background-color: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }

    /* Pagination Styling */
    .pagination-modern .page-link { color: var(--text-main); border: none; margin: 0 4px; border-radius: 8px; font-weight: 500; font-size: 0.85rem; }
    .pagination-modern .page-link:hover { background-color: #f1f5f9; color: var(--primary-color); }
    .pagination-modern .page-item.active .page-link { background-color: var(--primary-color); color: #ffffff; box-shadow: 0 2px 4px rgba(15, 118, 110, 0.2); }
</style>

<div class="container-fluid px-4 py-4">
    
    <div class="d-flex align-items-center mb-4">
        <div class="bg-primary bg-opacity-10 text-primary p-2 rounded-3 me-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
            <i data-feather="shield" style="width: 24px; height: 24px; color: var(--primary-color);"></i>
        </div>
        <div>
            <h4 class="fw-bold m-0 text-dark">System Audit Trail</h4>
            <div class="text-muted small mt-1">Review system activities, security events, and user modifications.</div>
        </div>
    </div>

    <div class="modern-card">
        <ul class="nav nav-tabs-modern d-flex border-bottom" id="auditTabs">
            <li class="nav-item">
                <a class="nav-link d-flex align-items-center <?php if($active_tab == 'all') echo 'active'; ?>" href="<?= APP_BASE_PATH ?>index.php?route=system_audit_trail&tab=all">
                    <i data-feather="list" class="me-2" style="width:16px;"></i> System Events
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link d-flex align-items-center <?php if($active_tab == 'auth') echo 'active'; ?>" href="<?= APP_BASE_PATH ?>index.php?route=system_audit_trail&tab=auth">
                    <i data-feather="log-in" class="me-2" style="width:16px;"></i> Authentication Logs
                </a>
            </li>
        </ul>

        <div class="table-responsive">
            <table class="table table-modern table-hover mb-0">
                <thead>
                    <tr>
                        <th style="width: 20%;">Date & Time</th>
                        <th style="width: 18%;">Performed By</th>
                        <th style="width: 15%;">IP Address</th>
                        <th style="width: 15%;">Action Type</th>
                        <th>Action Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-5">
                                <i data-feather="search" style="width: 32px; height: 32px; opacity: 0.3;" class="mb-2"></i><br>
                                No audit records found for this category.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): 
                            // Determine Badge Colors dynamically
                            $type_lower = strtolower($log['action_type']);
                            $badge_class = 'bg-default';
                            
                            if (strpos($type_lower, 'auth') !== false || strpos($type_lower, 'login') !== false) $badge_class = 'bg-auth';
                            elseif (strpos($type_lower, 'create') !== false || strpos($type_lower, 'insert') !== false) $badge_class = 'bg-insert';
                            elseif (strpos($type_lower, 'update') !== false || strpos($type_lower, 'edit') !== false || strpos($type_lower, 'sign') !== false) $badge_class = 'bg-update';
                            elseif (strpos($type_lower, 'delete') !== false || strpos($type_lower, 'remove') !== false) $badge_class = 'bg-delete';
                        ?>
                            <tr>
                                <td>
                                    <div class="fw-medium text-dark"><?php echo date('d M Y', strtotime($log['timestamp'])); ?></div>
                                    <div class="text-muted" style="font-size: 0.75rem;"><?php echo date('h:i:s A', strtotime($log['timestamp'])); ?></div>
                                </td>
                                <td><span class="fw-medium"><?php echo htmlspecialchars($log['performed_by']); ?></span></td>
                                <td>
                                    <span class="font-monospace text-muted" style="font-size: 0.8rem;">
                                        <?php echo htmlspecialchars($log['ip_address'] ?? 'Unknown'); ?>
                                    </span>
                                </td>
                                <td><span class="badge badge-modern <?php echo $badge_class; ?>"><?php echo htmlspecialchars($log['action_type']); ?></span></td>
                                <td class="text-muted"><?php echo htmlspecialchars($log['description']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="p-3 border-top bg-light bg-opacity-50">
            <nav aria-label="Audit Trail Pagination">
                <ul class="pagination pagination-modern justify-content-center mb-0">
                    
                    <li class="page-item <?php echo ($current_page <= 1) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="<?= APP_BASE_PATH ?>index.php?route=system_audit_trail&tab=<?php echo $active_tab; ?>&page=<?php echo $current_page - 1; ?>"><i data-feather="chevron-left" style="width:16px;"></i></a>
                    </li>

                    <?php for ($i = max(1, $current_page - 2); $i <= min($total_pages, $current_page + 2); $i++): ?>
                        <li class="page-item <?php echo ($i == $current_page) ? 'active' : ''; ?>">
                            <a class="page-link" href="<?= APP_BASE_PATH ?>index.php?route=system_audit_trail&tab=<?php echo $active_tab; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>

                    <li class="page-item <?php echo ($current_page >= $total_pages) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="<?= APP_BASE_PATH ?>index.php?route=system_audit_trail&tab=<?php echo $active_tab; ?>&page=<?php echo $current_page + 1; ?>"><i data-feather="chevron-right" style="width:16px;"></i></a>
                    </li>

                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof feather !== 'undefined') {
            feather.replace();
        }
    });
</script>

<?php include_once '../includes/footer.php'; ?>