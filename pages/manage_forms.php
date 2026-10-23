<?php
// FILE: pages/manage_forms.php

include_once '../includes/error_handler.php';
include_once '../includes/db_connect.php';
include_once '../includes/auth.php';

// A user must have permission to view at least one of the tabs on this page.
if (!can('view_departments_tab') && !can('view_locations_tab')) {
    checkPermission('view_departments_tab'); // Fallback to trigger the standard "no permission" redirect
}

// 1. Session & Security Headers
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

// --- START: POST HANDLER (CREATE / UPDATE / DELETE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // CSRF Check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['form_error'] = 'Security Validation Failed: Invalid CSRF Token.';
        header("Location: " . APP_BASE_PATH . "index.php?route=manage_forms");
        exit();
    }

    $action = $_POST['action'];
    $type = $_POST['type'] ?? ''; // 'department' or 'location'
    $id = (int)($_POST['id'] ?? 0);
    
    $table_name = ($type === 'department') ? 'departments' : 'locations';
    $entity_name = ucfirst($type);
    $redirect_tab = ($type === 'department') ? 'departments' : 'locations';

    // --- DELETE LOGIC ---
    if ($action === 'delete') {
        if (!can("delete_{$type}")) {
            $_SESSION['form_error'] = 'You do not have permission to delete this item.';
        } else {
            // Get the name for logging before deleting
            $stmt_get = $conn->prepare("SELECT name FROM {$table_name} WHERE id = ?");
            $stmt_get->bind_param("i", $id);
            $stmt_get->execute();
            $deleted_name = $stmt_get->get_result()->fetch_assoc()['name'] ?? 'ID: ' . $id;
            $stmt_get->close();

            try {
                $stmt = $conn->prepare("DELETE FROM {$table_name} WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();
                $_SESSION['form_success'] = "{$entity_name} deleted successfully.";
                log_system_event($conn, 'Forms Management', "Deleted {$entity_name}: '{$deleted_name}'.");
            } catch (Exception $e) {
                error_log($e->getMessage());
                $_SESSION['form_error'] = "Error deleting " . strtolower($entity_name) . ". It may be in use by an employee or gatepass record.";
            }
        }
    } 
    // --- CREATE / UPDATE LOGIC ---
    elseif ($action === 'save') {
        $name = trim(strip_tags($_POST['name'] ?? ''));
        
        if (empty($name)) {
            $_SESSION['form_error'] = "{$entity_name} Name cannot be empty.";
        } else {
            $is_new = ($id === 0);
            $can_edit = can("edit_{$type}");
            $can_create = can("create_{$type}");

            try {
                if (!$is_new && $can_edit) {
                    $stmt_old = $conn->prepare("SELECT name FROM {$table_name} WHERE id = ?");
                    $stmt_old->bind_param("i", $id);
                    $stmt_old->execute();
                    $old_name = $stmt_old->get_result()->fetch_assoc()['name'] ?? 'N/A';
                    $stmt_old->close();

                    $stmt = $conn->prepare("UPDATE {$table_name} SET name = ? WHERE id = ?");
                    $stmt->bind_param("si", $name, $id);
                    $stmt->execute();
                    $_SESSION['form_success'] = "{$entity_name} updated successfully.";
                    log_system_event($conn, 'Forms Management', "Updated {$entity_name}: '{$old_name}' to '{$name}'.");
                } elseif ($is_new && $can_create) {
                    $stmt = $conn->prepare("INSERT INTO {$table_name} (name) VALUES (?)");
                    $stmt->bind_param("s", $name);
                    $stmt->execute();
                    $_SESSION['form_success'] = "{$entity_name} added successfully.";
                    log_system_event($conn, 'Forms Management', "Created new {$entity_name}: '{$name}'.");
                } else {
                    $_SESSION['form_error'] = 'You do not have permission to perform this action.';
                }
                if (isset($stmt)) $stmt->close();
            } catch (Exception $e) {
                $_SESSION['form_error'] = ($conn->errno == 1062) ? "A {$entity_name} with that name already exists." : 'A database error occurred.';
            }
        }
    }

    header("Location: " . APP_BASE_PATH . "index.php?route=manage_forms&tab={$redirect_tab}");
    exit();
}
// --- END: POST HANDLER ---

// --- TAB STATE MANAGEMENT ---
$active_tab = $_GET['tab'] ?? '';
if (!in_array($active_tab, ['departments', 'locations']) || !can('view_' . $active_tab . '_tab')) {
    $active_tab = can('view_departments_tab') ? 'departments' : (can('view_locations_tab') ? 'locations' : '');
}

// --- FETCH DATA ---
$departments = [];
$locations = [];

if (can('view_departments_tab')) {
    $result_depts = $conn->query("SELECT id, name, created_at FROM departments ORDER BY name ASC");
    if ($result_depts) {
        while ($row = $result_depts->fetch_assoc()) { $departments[] = $row; }
    }
}
if (can('view_locations_tab')) {
    $result_locs = $conn->query("SELECT id, name, created_at FROM locations ORDER BY name ASC");
    if ($result_locs) {
        while ($row = $result_locs->fetch_assoc()) { $locations[] = $row; }
    }
}

$pageTitle = "Forms Management";
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
        background: var(--card-bg); 
        border-radius: var(--radius); 
        box-shadow: 0 2px 4px rgba(0,0,0,0.05); 
        border: 1px solid var(--border-color); 
        overflow: hidden; 
        margin-bottom: 24px; 
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

    /* Modern Tabs */
    .nav-tabs-modern {
        border-bottom: 1px solid var(--border-color);
        gap: 10px;
        margin-bottom: 20px;
    }
    .nav-tabs-modern .nav-link {
        color: #64748b;
        border: none;
        border-bottom: 2px solid transparent;
        font-weight: 500;
        padding: 10px 16px;
        transition: all 0.2s;
        border-radius: 0;
        background: transparent;
        font-size: 0.85rem;
    }
    .nav-tabs-modern .nav-link:hover { color: var(--primary-color); background: #f1f5f9; border-radius: 6px 6px 0 0; }
    .nav-tabs-modern .nav-link.active { color: var(--primary-color); border-bottom: 2px solid var(--primary-color); background: transparent; font-weight: 600; }

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
        padding: 10px 16px;
        vertical-align: middle;
    }
    .table-custom td {
        padding: 12px 16px; 
        vertical-align: middle; 
        font-size: 0.85rem; 
        color: #374151; 
        border-bottom: 1px solid #f3f4f6;
    }

    /* Action Dropdown */
    .action-btn-container .dropdown-toggle { background: none; border: none; color: #94a3b8; border-radius: 6px; padding: 2px 6px; }
    .action-btn-container .dropdown-toggle::after { display: none; }
    .action-btn-container .dropdown-toggle:hover { background-color: #f1f5f9; color: var(--primary-color);}
    .dropdown-menu { border: none; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05); border-radius: 8px; padding: 8px; }
    .dropdown-item { border-radius: 6px; display: flex; align-items: center; gap: 0.75rem; font-size: 0.8rem; font-weight: 500; padding: 6px 12px; color: #475569;}
    .dropdown-item:hover { background-color: #f1f5f9; color: var(--primary-color); }
    .dropdown-item.text-danger:hover { background-color: #fef2f2; color: #dc2626; }

    /* Form Controls & Search */
    .form-control { border-radius: 8px; padding: 10px 14px; font-size: 0.85rem; border: 1px solid #cbd5e1; transition: all 0.2s; }
    .form-control:focus { border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1); }
    .form-label { font-weight: 600; font-size: 0.75rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.02em; margin-bottom: 6px; }
    
    .form-control-search {
        height: 34px;
        padding: 6px 10px 6px 32px !important;
        font-size: 0.8rem;
        border-radius: 6px;
    }
</style>

<div class="main-content-wrapper container-fluid pt-4 px-4">

    <div class="row justify-content-center">
        <div class="col-xl-8 col-lg-10 col-md-12">
            
            <div class="d-flex justify-content-between align-items-end mb-4">
                <div>
                    <h4 class="fw-bold m-0 text-dark">Forms Management</h4>
                    <div class="text-muted mt-1" style="font-size: 0.8rem;">Configure global dropdown options for Forms, Checklists, and Gatepasses</div>
                </div>
            </div>

            <ul class="nav nav-tabs-modern" id="managementTabs" role="tablist">
                <?php if (can('view_departments_tab')): ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?php echo $active_tab === 'departments' ? 'active' : ''; ?>" id="departments-tab" data-bs-toggle="tab" data-bs-target="#departments-pane" type="button" role="tab"><i data-feather="briefcase" class="me-2" style="width:14px;"></i> Departments</button>
                    </li>
                <?php endif; ?>
                <?php if (can('view_locations_tab')): ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?php echo $active_tab === 'locations' ? 'active' : ''; ?>" id="locations-tab" data-bs-toggle="tab" data-bs-target="#locations-pane" type="button" role="tab"><i data-feather="map-pin" class="me-2" style="width:14px;"></i> Locations</button>
                    </li>
                <?php endif; ?>
            </ul>

            <div class="tab-content" id="managementTabsContent">
                
                <?php if (can('view_departments_tab')): ?>
                <div class="tab-pane fade <?php echo $active_tab === 'departments' ? 'show active' : ''; ?>" id="departments-pane" role="tabpanel">
                    <div class="pro-card">
                        <div class="d-flex justify-content-between align-items-center p-3 border-bottom bg-white flex-wrap gap-3">
                            <h6 class="fw-bold m-0 text-dark">Department Dictionary</h6>
                            <div class="d-flex align-items-center gap-2">
                                <div class="position-relative">
                                    <i data-feather="search" class="position-absolute text-muted" style="left: 10px; top: 50%; transform: translateY(-50%); width: 14px;"></i>
                                    <input type="text" class="form-control form-control-search table-search" data-target="departmentsTableBody" placeholder="Search departments..." style="width: 220px;">
                                </div>
                                <?php if (can('create_department')): ?>
                                <button type="button" class="btn btn-primary-pro d-flex align-items-center text-nowrap" onclick="openFormModal('department')">
                                    <i data-feather="plus" style="width:14px;" class="me-1"></i> Add Department
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table-custom table-hover">
                                <thead>
                                    <tr>
                                        <th style="width: 50%;">Department Name</th>
                                        <th>Date Added</th>
                                        <th class="text-center" style="width: 100px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="departmentsTableBody">
                                    <?php if (empty($departments)): ?>
                                        <tr class="empty-state-row"><td colspan="3" class="text-center text-muted py-5"><i data-feather="inbox" class="mb-2" style="width:32px; height:32px; opacity:0.3;"></i><br>No departments found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($departments as $dept): ?>
                                            <tr class="searchable-row">
                                                <td class="fw-bold text-dark searchable-text"><?php echo htmlspecialchars($dept['name']); ?></td>
                                                <td class="text-muted small"><?php echo !empty($dept['created_at']) ? date('M d, Y', strtotime($dept['created_at'])) : 'N/A'; ?></td>
                                                <td class="text-center">
                                                    <div class="dropdown action-btn-container text-center">
                                                        <button class="btn dropdown-toggle border" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                            <i data-feather="more-horizontal" style="width:14px; height:14px;"></i>
                                                        </button>
                                                        <ul class="dropdown-menu dropdown-menu-end">
                                                            <?php if (can('edit_department')): ?>
                                                                <li><a class="dropdown-item" href="#" onclick="openFormModal('department', <?php echo $dept['id']; ?>, '<?php echo addslashes(htmlspecialchars($dept['name'])); ?>')"><i data-feather="edit-2"></i> Edit</a></li>
                                                            <?php endif; ?>
                                                            <?php if (can('delete_department')): ?>
                                                                <li><hr class="dropdown-divider"></li>
                                                                <li><a class="dropdown-item text-danger" href="#" onclick="confirmDelete(<?php echo $dept['id']; ?>, 'department', '<?php echo addslashes(htmlspecialchars($dept['name'])); ?>')"><i data-feather="trash-2"></i> Delete</a></li>
                                                            <?php endif; ?>
                                                            <?php if (!can('edit_department') && !can('delete_department')): ?>
                                                                <li><span class="dropdown-item text-muted disabled">No Access</span></li>
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
                    </div>
                </div>
                <?php endif; ?>

                <?php if (can('view_locations_tab')): ?>
                <div class="tab-pane fade <?php echo $active_tab === 'locations' ? 'show active' : ''; ?>" id="locations-pane" role="tabpanel">
                    <div class="pro-card">
                        <div class="d-flex justify-content-between align-items-center p-3 border-bottom bg-white flex-wrap gap-3">
                            <h6 class="fw-bold m-0 text-dark">Office Locations</h6>
                            <div class="d-flex align-items-center gap-2">
                                <div class="position-relative">
                                    <i data-feather="search" class="position-absolute text-muted" style="left: 10px; top: 50%; transform: translateY(-50%); width: 14px;"></i>
                                    <input type="text" class="form-control form-control-search table-search" data-target="locationsTableBody" placeholder="Search locations..." style="width: 220px;">
                                </div>
                                <?php if (can('create_location')): ?>
                                <button type="button" class="btn btn-primary-pro d-flex align-items-center text-nowrap" onclick="openFormModal('location')">
                                    <i data-feather="plus" style="width:14px;" class="me-1"></i> Add Location
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table-custom table-hover">
                                <thead>
                                    <tr>
                                        <th style="width: 50%;">Location Name</th>
                                        <th>Date Added</th>
                                        <th class="text-center" style="width: 100px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="locationsTableBody">
                                    <?php if (empty($locations)): ?>
                                        <tr class="empty-state-row"><td colspan="3" class="text-center text-muted py-5"><i data-feather="map-pin" class="mb-2" style="width:32px; height:32px; opacity:0.3;"></i><br>No locations found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($locations as $loc): ?>
                                            <tr class="searchable-row">
                                                <td class="fw-bold text-dark searchable-text"><?php echo htmlspecialchars($loc['name']); ?></td>
                                                <td class="text-muted small"><?php echo !empty($loc['created_at']) ? date('M d, Y', strtotime($loc['created_at'])) : 'N/A'; ?></td>
                                                <td class="text-center">
                                                    <div class="dropdown action-btn-container text-center">
                                                        <button class="btn dropdown-toggle border" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                            <i data-feather="more-horizontal" style="width:14px; height:14px;"></i>
                                                        </button>
                                                        <ul class="dropdown-menu dropdown-menu-end">
                                                            <?php if (can('edit_location')): ?>
                                                                <li><a class="dropdown-item" href="#" onclick="openFormModal('location', <?php echo $loc['id']; ?>, '<?php echo addslashes(htmlspecialchars($loc['name'])); ?>')"><i data-feather="edit-2"></i> Edit</a></li>
                                                            <?php endif; ?>
                                                            <?php if (can('delete_location')): ?>
                                                                <li><hr class="dropdown-divider"></li>
                                                                <li><a class="dropdown-item text-danger" href="#" onclick="confirmDelete(<?php echo $loc['id']; ?>, 'location', '<?php echo addslashes(htmlspecialchars($loc['name'])); ?>')"><i data-feather="trash-2"></i> Delete</a></li>
                                                            <?php endif; ?>
                                                            <?php if (!can('edit_location') && !can('delete_location')): ?>
                                                                <li><span class="dropdown-item text-muted disabled">No Access</span></li>
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
                    </div>
                </div>
                <?php endif; ?>

            </div>

        </div>
    </div>
</div>

<div class="modal fade" id="formModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px;">
            <div class="modal-header bg-light border-bottom-0 pb-3">
                <h5 class="modal-title fw-bold text-dark d-flex align-items-center" id="formModalTitle">
                    <i data-feather="edit-2" class="me-2 text-primary" style="width:18px;"></i> Edit Item
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="dynamicForm" action="<?= APP_BASE_PATH ?>index.php?route=manage_forms" method="POST">
                <div class="modal-body pt-0">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="type" id="form_type" value="">
                    <input type="hidden" name="id" id="form_id" value="0">
                    
                    <div class="mb-2">
                        <label class="form-label" id="nameLabel">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control fw-medium text-dark" name="name" id="form_name" required autocomplete="off">
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-light border px-4 rounded-pill fw-medium" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-pro px-4 rounded-pill shadow-sm d-flex align-items-center">
                        <i data-feather="save" class="me-2" style="width:16px;"></i> Save Changes
                    </button>
                </div>
            </form>
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
                Are you sure you want to delete the <span id="deleteItemTypeTxt" class="fw-bold"></span> 
                "<strong id="deleteItemName" class="text-dark"></strong>"?<br><br>This action cannot be undone and may affect existing records.
            </div>
            <div class="modal-footer border-0">
                <form id="deleteForm" method="POST" action="<?= APP_BASE_PATH ?>index.php?route=manage_forms" class="w-100 d-flex justify-content-end gap-2 m-0">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="type" id="delete_type_input" value="">
                    <input type="hidden" name="id" id="delete_id_input" value="">
                    
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-danger rounded-pill px-4">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include_once '../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // UI Setup
    if (typeof feather !== 'undefined') feather.replace();

    const formModal = new bootstrap.Modal(document.getElementById('formModal'));
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));

    // Frontend Search Filtering Logic
    document.querySelectorAll('.table-search').forEach(input => {
        input.addEventListener('keyup', function() {
            const targetTableId = this.getAttribute('data-target');
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll(`#${targetTableId} tr.searchable-row`);
            const emptyStateRow = document.querySelector(`#${targetTableId} tr.empty-state-row`);
            
            let visibleCount = 0;
            
            rows.forEach(row => {
                const textCell = row.querySelector('.searchable-text');
                if (textCell) {
                    const text = textCell.textContent.toLowerCase();
                    if (text.includes(filter)) {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                }
            });

            // If an empty state row exists, handle showing it if 0 matches
            if (emptyStateRow) {
                // If the user deleted all data, visibleCount and rows.length are both 0. Don't touch it.
                if (rows.length > 0) {
                    if (visibleCount === 0) {
                        emptyStateRow.style.display = '';
                        emptyStateRow.innerHTML = `<td colspan="3" class="text-center text-muted py-5"><i data-feather="search" class="mb-2" style="width:32px; height:32px; opacity:0.3;"></i><br>No matches found for "${this.value}".</td>`;
                        if (typeof feather !== 'undefined') feather.replace();
                    } else {
                        emptyStateRow.style.display = 'none';
                    }
                }
            }
        });
    });

    // Form Modal Handler
    window.openFormModal = function(type, id = 0, name = '') {
        document.getElementById('form_type').value = type;
        document.getElementById('form_id').value = id;
        document.getElementById('form_name').value = name;
        
        const entityLabel = type === 'department' ? 'Department' : 'Location';
        document.getElementById('nameLabel').innerHTML = `${entityLabel} Name <span class="text-danger">*</span>`;
        
        const modalTitle = document.getElementById('formModalTitle');
        if (id > 0) {
            modalTitle.innerHTML = `<i data-feather="edit-2" class="me-2 text-primary" style="width:18px;"></i> Edit ${entityLabel}`;
        } else {
            modalTitle.innerHTML = `<i data-feather="plus-circle" class="me-2 text-primary" style="width:18px;"></i> Add ${entityLabel}`;
        }
        
        if (typeof feather !== 'undefined') feather.replace();
        formModal.show();
        
        // Auto-focus input after modal opens
        document.getElementById('formModal').addEventListener('shown.bs.modal', () => {
            document.getElementById('form_name').focus();
        }, { once: true });
    };

    // Delete Modal Handler
    window.confirmDelete = function(id, type, name) {
        document.getElementById('deleteItemTypeTxt').textContent = type;
        document.getElementById('deleteItemName').textContent = name;
        document.getElementById('delete_id_input').value = id;
        document.getElementById('delete_type_input').value = type;
        
        deleteModal.show();
    };

});
</script>