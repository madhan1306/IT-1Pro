<?php
// pages/shipment.php

include_once '../includes/error_handler.php';
include_once '../includes/db_connect.php';
include_once '../includes/auth.php'; // This file includes the log_system_event function

checkPermission('view_shipment');

$pageTitle = "Shipments";

// --- Handle Delete Action ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_shipment') {
    if (!can('delete_shipment')) {
        $_SESSION['page_message'] = ['type' => 'danger', 'text' => 'You do not have permission to delete shipments.'];
        header('Location: ' . APP_BASE_PATH . 'index.php?route=shipment');
        exit();
    }
    
    $shipment_id_to_delete = $_POST['shipment_id'] ?? null;
    if ($shipment_id_to_delete) {
        $conn->begin_transaction();
        try {
            $info_stmt = $conn->prepare("SELECT recipient_name FROM shipments WHERE id = ?");
            $info_stmt->bind_param("i", $shipment_id_to_delete);
            $info_stmt->execute();
            $recipient_name = $info_stmt->get_result()->fetch_assoc()['recipient_name'] ?? 'Unknown';
            $info_stmt->close();
            
            if (function_exists('log_system_event')) {
                log_system_event($conn, 'Shipment', "Deleted shipment #" . $shipment_id_to_delete . " for recipient " . $recipient_name);
            }

            $stmt_del = $conn->prepare("DELETE FROM shipments WHERE id = ?");
            $stmt_del->bind_param("i", $shipment_id_to_delete);
            if (!$stmt_del->execute()) throw new Exception("Failed to delete shipment.");
            $stmt_del->close();

            $conn->commit();
            $_SESSION['page_message'] = ['type' => 'success', 'text' => "Shipment #" . $shipment_id_to_delete . " was permanently deleted."];

        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['page_message'] = ['type' => 'danger', 'text' => "Error deleting shipment: " . $e->getMessage()];
        }
    } else {
        $_SESSION['page_message'] = ['type' => 'danger', 'text' => "Invalid shipment ID for deletion."];
    }
    header('Location: ' . APP_BASE_PATH . 'index.php?route=shipment');
    exit();
}

// --- Data fetching and filtering logic ---
$search_term = '';
$shipments = []; 
$status_filter = $_GET['status_filter'] ?? 'all';

$records_per_page = 15; 
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

$from_and_joins = "
    FROM shipments s
    LEFT JOIN gatepass_requests gp ON s.gatepass_id = gp.id
";

// Using the updated table structure which includes delivered_on and cancelled_on
$sql = "SELECT s.id, s.gatepass_id, s.shipment_status, s.courier_name, s.tracking_number, s.shipped_on, s.delivered_on, s.cancelled_on, s.recipient_name,
               gp.gatepass_no, gp.status as gatepass_status
        {$from_and_joins}";

$sql_where_clause = [];
$params = [];
$types = '';

if (isset($_GET['search_term']) && !empty(trim($_GET['search_term']))) {
    $search_term = trim($_GET['search_term']);
    $search_like = "%" . $search_term . "%";
    $sql_where_clause[] = "(s.recipient_name LIKE ? OR s.tracking_number LIKE ? OR gp.gatepass_no LIKE ? OR s.id LIKE ?)";
    $params = [$search_like, $search_like, $search_like, $search_like];
    $types = "ssss";
}

if ($status_filter !== 'all') {
    $sql_where_clause[] = "s.shipment_status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

$where_sql = !empty($sql_where_clause) ? " WHERE " . implode(" AND ", $sql_where_clause) : "";

$count_sql = "SELECT COUNT(s.id) " . $from_and_joins . $where_sql;
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_records = $count_stmt->get_result()->fetch_row()[0];
$count_stmt->close();

$total_pages = ceil($total_records / $records_per_page);

$sql .= $where_sql . " ORDER BY s.created_at DESC LIMIT ? OFFSET ?";
$params[] = $records_per_page;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
if (count($params) > 0) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $shipments[] = $row;
}
$stmt->close();

include_once '../includes/header.php';

function generatePageUrl($param_overrides = []) {
    $params = array_merge($_GET, $param_overrides);
    $params['route'] = 'shipment'; // Force routing explicitly
    if (isset($param_overrides['status_filter']) && !isset($param_overrides['page'])) {
        unset($params['page']);
    }
    return APP_BASE_PATH . 'index.php?' . http_build_query($params);
}

$status_states = ['all' => 'All Statuses', 'New' => 'New', 'Shipped' => 'Shipped', 'In Transit' => 'In Transit', 'Delivered' => 'Delivered', 'Cancelled' => 'Cancelled'];
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

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end mb-3 gap-3">
        <div>
            <h4 class="fw-bold m-0 text-dark">Manage Shipments</h4>
            <div class="text-muted small-meta mt-1">Track and update equipment packages and courier status</div>
        </div>
        
        <form action="<?= APP_BASE_PATH ?>index.php" method="GET" class="d-flex align-items-center gap-2 pb-2">
            <input type="hidden" name="route" value="shipment">
            <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter); ?>">
            <input type="text" class="form-control form-control-search" name="search_term" placeholder="Search Recipient, TN..." value="<?php echo htmlspecialchars($search_term); ?>" style="width: 250px;">
            <button type="submit" class="btn btn-primary-pro">Search</button>
            <?php if(!empty($search_term)): ?>
                <a href="<?= APP_BASE_PATH ?>index.php?route=shipment&status_filter=<?php echo htmlspecialchars($status_filter); ?>" class="btn btn-outline-secondary">Clear</a>
            <?php endif; ?>
            <?php if (can('create_shipment')): ?>
                <a href="<?= APP_BASE_PATH ?>index.php?route=add_edit_shipment" class="btn btn-primary-pro d-flex align-items-center">
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
                        <th>Shipment No.</th>
                        <th>Gatepass Ref.</th>
                        <th style="text-align: left;">Recipient Name</th>
                        <th>Courier details</th>
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
                        <th>Status Date</th>
                        <th style="width: 60px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($shipments)) : ?>
                        <tr><td colspan="7" class="text-center text-muted py-5"><i data-feather="inbox" class="mb-2" style="width:32px; height:32px; opacity:0.3;"></i><br>No shipments found matching your criteria.</td></tr>
                    <?php else : ?>
                        <?php foreach ($shipments as $shipment) : ?>
                            <tr class="clickable-row" data-id="<?php echo $shipment['id']; ?>">
                                <td class="fw-bold text-primary text-start" style="padding-left: 16px;">
                                    <a href="<?= APP_BASE_PATH ?>index.php?route=add_edit_shipment&id=<?php echo $shipment['id']; ?>" class="text-decoration-none">
                                        #<?php echo $shipment['id']; ?>
                                    </a>
                                </td>
                                <td>
                                    <?php if($shipment['gatepass_id']): ?>
                                        <a href="<?= APP_BASE_PATH ?>index.php?route=add_edit_gatepass&id=<?php echo $shipment['gatepass_id']; ?>" class="text-decoration-none fw-medium text-dark">
                                            <?php echo htmlspecialchars($shipment['gatepass_no']); ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: left;" class="fw-bold text-dark">
                                    <?php echo htmlspecialchars($shipment['recipient_name']); ?>
                                </td>
                                <td>
                                    <div class="fw-medium text-dark"><?php echo htmlspecialchars($shipment['courier_name'] ?? 'N/A'); ?></div>
                                    <?php if (!empty($shipment['tracking_number'])): ?>
                                        <div class="small-meta">TN: <?php echo htmlspecialchars($shipment['tracking_number']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                        $status = $shipment['shipment_status'];
                                        $badgeClass = match (strtolower($status)) {
                                            'delivered' => 'bg-success-subtle text-success-emphasis border border-success-subtle',
                                            'shipped', 'in transit' => 'bg-info-subtle text-info-emphasis border border-info-subtle',
                                            'new' => 'bg-primary-subtle text-primary-emphasis border border-primary-subtle',
                                            'cancelled' => 'bg-danger-subtle text-danger-emphasis border border-danger-subtle',
                                            default => 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle',
                                        };
                                        echo "<span class=\"status-badge {$badgeClass}\">" . htmlspecialchars($status) . "</span>";
                                    ?>
                                </td>
                                <td>
                                    <div class="fw-medium text-dark">
                                        <?php 
                                        $status_lower = strtolower($shipment['shipment_status']);
                                        $date_val = null;
                                        $meta_text = '';
                                        $meta_color = '';
                                        $meta_icon = '';

                                        if ($status_lower === 'delivered') {
                                            // Fallback to shipped_on just in case delivered_on isn't populated on legacy data
                                            $date_val = !empty($shipment['delivered_on']) ? $shipment['delivered_on'] : $shipment['shipped_on'];
                                            $meta_text = 'Delivered'; $meta_icon = 'check-circle'; $meta_color = 'text-success';
                                        } elseif ($status_lower === 'cancelled') {
                                            $date_val = !empty($shipment['cancelled_on']) ? $shipment['cancelled_on'] : null;
                                            $meta_text = 'Cancelled'; $meta_icon = 'x-circle'; $meta_color = 'text-danger';
                                        } elseif ($status_lower === 'in transit') {
                                            $date_val = !empty($shipment['shipped_on']) ? $shipment['shipped_on'] : null;
                                            $meta_text = 'In Transit'; $meta_icon = 'navigation'; $meta_color = 'text-info';
                                        } elseif ($status_lower === 'shipped') {
                                            $date_val = !empty($shipment['shipped_on']) ? $shipment['shipped_on'] : null;
                                            $meta_text = 'Shipped'; $meta_icon = 'truck'; $meta_color = 'text-info';
                                        }

                                        if (!empty($date_val)) {
                                            echo date('d M Y', strtotime($date_val));
                                            echo '<div class="small-meta mt-1 ' . $meta_color . '"><i data-feather="' . $meta_icon . '" style="width:10px; height:10px; margin-top:-2px;"></i> ' . $meta_text . '</div>';
                                        } else {
                                            echo '<span class="text-muted small">N/A</span>';
                                        }
                                        ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="dropdown action-btn-container text-center">
                                        <button class="btn dropdown-toggle border" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i data-feather="more-horizontal" style="width:14px; height:14px;"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <?php 
                                            $isGatepassApproved = ($shipment['gatepass_status'] ?? '') === 'Approved';
                                            $canDownloadGatepass = can('download_gatepass_pdf');
                                            $isDownloadable = $isGatepassApproved && $canDownloadGatepass && $shipment['gatepass_id'];
                                            
                                            $li_attrs_gp = '';
                                            if (!$isDownloadable) {
                                                $tooltip_text = 'Gatepass PDF unavailable';
                                                if (!$shipment['gatepass_id']) $tooltip_text = 'No Gatepass linked';
                                                elseif (!$isGatepassApproved) $tooltip_text = 'Gatepass not yet approved';
                                                elseif (!$canDownloadGatepass) $tooltip_text = 'Permission denied';
                                                
                                                $li_attrs_gp = 'data-bs-toggle="tooltip" data-bs-placement="left" title="' . $tooltip_text . '"';
                                            }
                                            ?>
                                            <li <?php echo $li_attrs_gp; ?>>
                                                <a class="dropdown-item <?php if (!$isDownloadable) echo 'disabled text-muted'; ?>" 
                                                   href="<?php echo $isDownloadable ? APP_BASE_PATH . 'index.php?route=generate_gatepass_pdf&id=' . $shipment['gatepass_id'] : '#'; ?>" 
                                                   target="_blank">
                                                    <i data-feather="file-text" style="width:14px;" class="text-primary"></i> Gatepass PDF
                                                </a>
                                            </li>

                                            <?php if (can('delete_shipment')): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" href="#" data-bs-toggle="modal" data-bs-target="#deleteConfirmModal" data-shipment-id="<?php echo $shipment['id']; ?>" data-recipient-name="<?php echo htmlspecialchars($shipment['recipient_name']); ?>">
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
                Are you sure you want to delete the shipment for <strong id="recipientNameInModal" class="text-dark"></strong>?<br><br>This action cannot be undone.
            </div>
            <div class="modal-footer border-0">
                <form id="deleteForm" method="POST" action="<?= APP_BASE_PATH ?>index.php?route=shipment" class="w-100 d-flex justify-content-end gap-2 m-0">
                    <input type="hidden" name="action" value="delete_shipment">
                    <input type="hidden" name="shipment_id" id="shipmentIdToDelete">
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-danger rounded-pill px-4">Delete</button>
                </form>
            </div>
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

    // Handle row click navigation
    document.querySelector('.table-custom > tbody').addEventListener('click', function(event) {
        const row = event.target.closest('tr.clickable-row');
        if (!row) return;

        // Ignore clicks on links or action buttons
        if (event.target.closest('.action-btn-container, a, [data-bs-toggle="tooltip"]')) {
            return;
        }
        
        const shipmentId = row.dataset.id;
        if (shipmentId) {
            window.location.href = `<?= APP_BASE_PATH ?>index.php?route=add_edit_shipment&id=${shipmentId}`;
        }
    });

    // Delete modal population
    const deleteModalEl = document.getElementById('deleteConfirmModal');
    if (deleteModalEl) {
        deleteModalEl.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            document.getElementById('shipmentIdToDelete').value = button.dataset.shipmentId;
            document.getElementById('recipientNameInModal').textContent = button.dataset.recipientName;
        });
    }
});
</script>

<?php include_once '../includes/footer.php'; ?>