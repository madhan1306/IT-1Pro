<?php
// FILE: /pages/manage_portals.php - FINAL VERSION WITH SMART AUTO-SORTING & COLLISION HANDLING

include_once '../includes/error_handler.php';
include_once '../includes/db_connect.php';
include_once '../includes/auth.php';

checkPermission('manage_portals'); // Security check

$pageTitle = "Manage Portals & Categories";

// --- START: Session Tab Handling ---
$active_tab = 'portals';
if (isset($_SESSION['active_tab'])) {
    $active_tab = $_SESSION['active_tab'];
    unset($_SESSION['active_tab']);
}
// --- END: Session Tab Handling ---

// Helper function to handle POST-Redirect-Get (PRG)
function redirect_with_flash($msg, $type, $tab = 'portals') {
    if ($type === 'danger' || $type === 'error') {
        $_SESSION['form_error'] = $msg;
    } else {
        $_SESSION['form_success'] = $msg;
    }
    $_SESSION['active_tab'] = $tab;
    header("Location: " . APP_BASE_PATH . "index.php?route=manage_portals");
    exit;
}

// --- START: Handle Clock Settings POST Action ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['clock_action'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        redirect_with_flash('Security Validation Failed: Invalid CSRF Token.', 'danger', 'clocks');
    }

    $labels = $_POST['clock_label'] ?? [];
    $tzs = $_POST['clock_timezone'] ?? [];
    
    $clocks_to_save = [];
    for ($i = 0; $i < count($labels); $i++) {
        $label = trim($labels[$i] ?? '');
        $tz = trim($tzs[$i] ?? '');
        
        if (!empty($label) && !empty($tz)) {
            $clocks_to_save[] = [
                'label' => $label,
                'timezone' => $tz
            ];
        }
    }

    $settings_file = '../includes/clock_settings.json';
    if (file_put_contents($settings_file, json_encode($clocks_to_save, JSON_PRETTY_PRINT)) !== false) {
        redirect_with_flash('Clock settings updated successfully.', 'success', 'clocks');
    } else {
        redirect_with_flash('Failed to save clock settings. Check directory permissions.', 'danger', 'clocks');
    }
}
// --- END: Handle Clock Settings Action ---

// --- START: Handle Portal POST Actions (Create, Update, Delete) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['portal_action'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        redirect_with_flash('Security Validation Failed: Invalid CSRF Token.', 'danger', 'portals');
    }

    $action = $_POST['portal_action'];
    $portal_id = $_POST['portal_id'] ?? null;
    $name = trim($_POST['portal_name'] ?? '');
    $url = trim($_POST['portal_url'] ?? '');
    $category_id = $_POST['category_id'] ?? null;
    $sort_order = (int)($_POST['sort_order'] ?? 1);
    
    $visibility_type = $_POST['visibility_type'] ?? 'all'; 
    $selected_departments = $_POST['departments'] ?? []; 

    if ($action === 'delete' && $portal_id) {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT icon_path FROM portals WHERE id = ?");
            $stmt->bind_param("i", $portal_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                if (!empty($row['icon_path']) && file_exists('../' . ltrim($row['icon_path'], '/'))) {
                    unlink('../' . ltrim($row['icon_path'], '/'));
                }
            }
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM portal_department_visibility WHERE portal_id = ?");
            $stmt->bind_param("i", $portal_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM portals WHERE id = ?");
            $stmt->bind_param("i", $portal_id);
            if (!$stmt->execute()) throw new Exception("Failed to delete portal.");
            $stmt->close();

            $conn->commit();
            redirect_with_flash('Portal deleted successfully.', 'success', 'portals');
        } catch (Exception $e) {
            $conn->rollback();
            redirect_with_flash($e->getMessage(), 'danger', 'portals');
        }
    } 
    elseif ($action === 'create' || $action === 'update') {
        if (empty($name) || empty($url) || empty($category_id)) {
            redirect_with_flash('Portal Name, URL, and Category are required.', 'danger', 'portals');
        }

        $icon_path = $_POST['existing_icon'] ?? null;
        if (isset($_FILES['portal_icon']) && $_FILES['portal_icon']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/portal_icons/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            
            $file_extension = strtolower(pathinfo($_FILES['portal_icon']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'];
            
            if (!in_array($file_extension, $allowed_extensions)) {
                redirect_with_flash('Invalid file type for icon. Allowed: JPG, PNG, GIF, SVG, WEBP.', 'danger', 'portals');
            }
            
            $new_filename = uniqid('icon_') . '.' . $file_extension;
            $destination = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['portal_icon']['tmp_name'], $destination)) {
                $icon_path = 'uploads/portal_icons/' . $new_filename;
                if ($action === 'update' && !empty($_POST['existing_icon']) && file_exists('../' . ltrim($_POST['existing_icon'], '/'))) {
                    unlink('../' . ltrim($_POST['existing_icon'], '/'));
                }
            } else {
                redirect_with_flash('Failed to upload icon.', 'danger', 'portals');
            }
        }

        $conn->begin_transaction();
        try {
            // --- SMART REARRANGING: Shift existing portals down if the target order is taken ---
            $check_id = $portal_id ? $portal_id : 0;
            $stmt_check = $conn->prepare("SELECT id FROM portals WHERE category_id = ? AND sort_order = ? AND id != ?");
            $stmt_check->bind_param("iii", $category_id, $sort_order, $check_id);
            $stmt_check->execute();
            $collision = $stmt_check->get_result()->num_rows > 0;
            $stmt_check->close();

            if ($collision) {
                // Shift all subsequent portals in this category down by 1 to make room
                $stmt_shift = $conn->prepare("UPDATE portals SET sort_order = sort_order + 1 WHERE category_id = ? AND sort_order >= ? AND id != ?");
                $stmt_shift->bind_param("iii", $category_id, $sort_order, $check_id);
                $stmt_shift->execute();
                $stmt_shift->close();
            }

            if ($action === 'create') {
                $stmt = $conn->prepare("INSERT INTO portals (name, url, icon_path, category_id, sort_order) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("sssii", $name, $url, $icon_path, $category_id, $sort_order);
                if (!$stmt->execute()) throw new Exception("Failed to create portal: " . $stmt->error);
                $portal_id = $conn->insert_id;
                $stmt->close();
            } else { 
                $stmt = $conn->prepare("UPDATE portals SET name = ?, url = ?, icon_path = ?, category_id = ?, sort_order = ? WHERE id = ?");
                $stmt->bind_param("sssiii", $name, $url, $icon_path, $category_id, $sort_order, $portal_id);
                if (!$stmt->execute()) throw new Exception("Failed to update portal: " . $stmt->error);
                $stmt->close();
            }

            $stmt_del_vis = $conn->prepare("DELETE FROM portal_department_visibility WHERE portal_id = ?");
            $stmt_del_vis->bind_param("i", $portal_id);
            $stmt_del_vis->execute();
            $stmt_del_vis->close();

            if ($visibility_type === 'restricted' && !empty($selected_departments)) {
                $stmt_ins_vis = $conn->prepare("INSERT INTO portal_department_visibility (portal_id, department_name) VALUES (?, ?)");
                foreach ($selected_departments as $dept) {
                    $stmt_ins_vis->bind_param("is", $portal_id, $dept);
                    $stmt_ins_vis->execute();
                }
                $stmt_ins_vis->close();
            }

            $conn->commit();
            $msg = $action === 'create' ? 'Portal created successfully.' : 'Portal updated successfully.';
            redirect_with_flash($msg, 'success', 'portals');
            
        } catch (Exception $e) {
            $conn->rollback();
            redirect_with_flash($e->getMessage(), 'danger', 'portals');
        }
    }
}
// --- END: Handle Portal Actions ---

// --- START: Handle Category POST Actions ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['category_action'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        redirect_with_flash('Security Validation Failed: Invalid CSRF Token.', 'danger', 'categories');
    }

    $action = $_POST['category_action'];
    $category_id = $_POST['category_id'] ?? null;
    $category_name = trim($_POST['category_name'] ?? '');
    $sort_order = (int)($_POST['category_sort_order'] ?? 1);

    if ($action === 'delete' && $category_id) {
        $stmt_check = $conn->prepare("SELECT COUNT(*) FROM portals WHERE category_id = ?");
        $stmt_check->bind_param("i", $category_id);
        $stmt_check->execute();
        $count = $stmt_check->get_result()->fetch_row()[0];
        $stmt_check->close();

        if ($count > 0) {
             redirect_with_flash("Cannot delete category. It is currently assigned to $count portal(s).", 'danger', 'categories');
        } else {
            $stmt = $conn->prepare("DELETE FROM portal_categories WHERE id = ?");
            $stmt->bind_param("i", $category_id);
            if ($stmt->execute()) {
                redirect_with_flash('Category deleted successfully.', 'success', 'categories');
            } else {
                redirect_with_flash('Failed to delete category.', 'danger', 'categories');
            }
            $stmt->close();
        }
    } 
    elseif ($action === 'create' || $action === 'update') {
        if (empty($category_name)) redirect_with_flash('Category Name is required.', 'danger', 'categories');

        // --- SMART REARRANGING: Shift existing categories down if order is taken ---
        $check_id = $category_id ? $category_id : 0;
        $stmt_check = $conn->prepare("SELECT id FROM portal_categories WHERE sort_order = ? AND id != ?");
        $stmt_check->bind_param("ii", $sort_order, $check_id);
        $stmt_check->execute();
        $collision = $stmt_check->get_result()->num_rows > 0;
        $stmt_check->close();

        if ($collision) {
            $stmt_shift = $conn->prepare("UPDATE portal_categories SET sort_order = sort_order + 1 WHERE sort_order >= ? AND id != ?");
            $stmt_shift->bind_param("ii", $sort_order, $check_id);
            $stmt_shift->execute();
            $stmt_shift->close();
        }

        if ($action === 'create') {
            $stmt = $conn->prepare("INSERT INTO portal_categories (category_name, sort_order) VALUES (?, ?)");
            $stmt->bind_param("si", $category_name, $sort_order);
            if ($stmt->execute()) redirect_with_flash('Category created successfully.', 'success', 'categories');
            else redirect_with_flash('Failed to create category.', 'danger', 'categories');
        } else {
            $stmt = $conn->prepare("UPDATE portal_categories SET category_name = ?, sort_order = ? WHERE id = ?");
            $stmt->bind_param("sii", $category_name, $sort_order, $category_id);
            if ($stmt->execute()) redirect_with_flash('Category updated successfully.', 'success', 'categories');
            else redirect_with_flash('Failed to update category.', 'danger', 'categories');
        }
    }
}
// --- END: Handle Category Actions ---

// --- FETCH DATA FOR DISPLAY ---
// 1. Fetch Categories and calculate max order
$categories = [];
$max_category_order = 0;
$cat_result = $conn->query("SELECT * FROM portal_categories ORDER BY sort_order ASC, category_name ASC");
while ($row = $cat_result->fetch_assoc()) {
    $categories[] = $row;
    if ($row['sort_order'] > $max_category_order) {
        $max_category_order = $row['sort_order'];
    }
}

// 2. Fetch Departments for checkboxes
$departments = [];
$dept_result = $conn->query("SELECT name FROM departments ORDER BY name ASC");
while ($row = $dept_result->fetch_assoc()) {
    $departments[] = $row['name'];
}

// 3. Fetch Portals and calculate max orders per category
$portals = [];
$max_sort_orders = []; // Stores the highest sort order per category
foreach ($categories as $c) { $max_sort_orders[$c['id']] = 0; } // Initialize all known categories to 0

$portal_sql = "
    SELECT 
        p.*, 
        pc.category_name,
        GROUP_CONCAT(pdv.department_name SEPARATOR ', ') as restricted_departments
    FROM portals p
    LEFT JOIN portal_categories pc ON p.category_id = pc.id
    LEFT JOIN portal_department_visibility pdv ON p.id = pdv.portal_id
    GROUP BY p.id
    ORDER BY pc.sort_order ASC, pc.category_name ASC, p.sort_order ASC, p.name ASC
";
$portal_result = $conn->query($portal_sql);
while ($row = $portal_result->fetch_assoc()) {
    $row['is_unrestricted'] = empty($row['restricted_departments']);
    $portals[] = $row;

    // Track highest sort order per category
    $cat_id = $row['category_id'];
    if ($cat_id && isset($row['sort_order'])) {
        if (!isset($max_sort_orders[$cat_id]) || $row['sort_order'] > $max_sort_orders[$cat_id]) {
            $max_sort_orders[$cat_id] = $row['sort_order'];
        }
    }
}

// 4. Fetch Clock Settings
$clock_settings_file = '../includes/clock_settings.json';
$clock_settings = [];
if (file_exists($clock_settings_file)) {
    $clock_settings = json_decode(file_get_contents($clock_settings_file), true) ?? [];
}
// Default if empty
if (empty($clock_settings)) {
    $clock_settings = [
        ['label' => 'India (IST)', 'timezone' => 'Asia/Kolkata'],
        ['label' => 'New York (EST)', 'timezone' => 'America/New_York']
    ];
}

$available_timezones = [
    'Asia/Kolkata' => 'India (IST)',
    'America/New_York' => 'New York (EST/EDT)',
    'Europe/London' => 'London (GMT/BST)',
    'UTC' => 'Universal Time (UTC)',
    'Asia/Dubai' => 'Dubai (GST)',
    'Asia/Singapore' => 'Singapore (SGT)',
    'Australia/Sydney' => 'Sydney (AEST/AEDT)',
    'America/Los_Angeles' => 'Los Angeles (PST/PDT)',
    'Europe/Paris' => 'Paris (CET/CEST)',
    'Asia/Tokyo' => 'Tokyo (JST)'
];

include_once '../includes/header.php';
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
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
    
    /* DROPDOWN CLIPPING & FLICKERING FIXES */
    .pro-card, .table-responsive, .tab-content, .tab-pane { 
        overflow: visible !important; 
    }
    
    .pro-card { 
        background: var(--card-bg); 
        border-radius: var(--radius); 
        box-shadow: 0 2px 4px rgba(0,0,0,0.05); 
        border: 1px solid var(--border-color); 
        margin-bottom: 24px; 
    }
    
    .pro-card > .border-bottom:first-child {
        border-top-left-radius: calc(var(--radius) - 1px);
        border-top-right-radius: calc(var(--radius) - 1px);
    }
    
    /* Buttons */
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
    
    .btn-primary-modern { 
        background-color: var(--primary-color); 
        color: white; 
        border: none; 
        padding: 8px 20px; 
        font-weight: 600; 
        font-size: 0.85rem; 
        border-radius: 8px;
        transition: all 0.2s ease-in-out; 
    }
    .btn-primary-modern:hover, .btn-primary-modern:focus { 
        background-color: var(--primary-hover); 
        color: white; 
        transform: translateY(-1px);
        box-shadow: 0 4px 6px -1px rgba(15, 118, 110, 0.2);
    }
    
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
    }
    .table-custom td {
        padding: 8px 12px; 
        vertical-align: middle; 
        font-size: 0.8rem; 
        color: #374151; 
        border-bottom: 1px solid #f3f4f6;
    }
    
    /* Image Preview in Table */
    .table-icon-preview {
        width: 32px; height: 32px;
        object-fit: contain;
        background-color: #f8fafc;
        border-radius: 6px;
        border: 1px solid var(--border-color);
        padding: 2px;
    }

    /* Badges */
    .status-badge {
        padding: 3px 8px; 
        border-radius: 6px; 
        font-size: 0.7rem; 
        font-weight: 600;
        display: inline-block;
    }

    /* Action Dropdown */
    .action-btn-container { position: relative; }
    .action-btn-container .dropdown-toggle { background: none; border: none; color: #94a3b8; border-radius: 6px; padding: 2px 6px; }
    .action-btn-container .dropdown-toggle::after { display: none; }
    .action-btn-container .dropdown-toggle:hover { background-color: #f1f5f9; color: var(--primary-color);}
    .dropdown-menu { border: none; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05); border-radius: 8px; padding: 8px; margin-top: 0.25rem !important; }
    .dropdown-item { border-radius: 6px; display: flex; align-items: center; gap: 0.75rem; font-size: 0.8rem; font-weight: 500; padding: 6px 12px; color: #475569;}
    .dropdown-item:hover { background-color: #f1f5f9; color: var(--primary-color); }
    .dropdown-item.text-danger:hover { background-color: #fef2f2; color: #dc2626; }

    /* Modern Tabs */
    .nav-tabs-modern {
        border-bottom: 1px solid var(--border-color);
        gap: 10px;
        margin-bottom: 20px;
    }
    .nav-tabs-modern .nav-link {
        color: var(--text-light);
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

    /* Modal Form Controls */
    .form-control, .form-select { border-radius: 8px; padding: 8px 12px; font-size: 0.85rem; border: 1px solid #cbd5e1; transition: all 0.2s; }
    .form-control:focus, .form-select:focus { border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1); }
    .form-label { font-weight: 600; font-size: 0.75rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.02em; margin-bottom: 6px; }
</style>

<div class="main-content-wrapper container-fluid pt-4 px-4">

    <div class="d-flex justify-content-between align-items-end mb-4">
        <div>
            <h4 class="fw-bold m-0 text-dark">Manage Portals & Categories</h4>
            <div class="text-muted mt-1" style="font-size: 0.8rem;">Configure internal tools, links, and department visibility</div>
        </div>
        <a href="<?= APP_BASE_PATH ?>index.php?route=portals" class="btn btn-outline-secondary d-flex align-items-center bg-white shadow-sm">
            <i data-feather="external-link" class="me-2" style="width:14px;"></i> View Portal Hub
        </a>
    </div>

    <ul class="nav nav-tabs-modern" id="managementTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $active_tab === 'portals' ? 'active' : ''; ?>" id="portals-tab" data-bs-toggle="tab" data-bs-target="#portals-pane" type="button" role="tab">Portals Management</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $active_tab === 'categories' ? 'active' : ''; ?>" id="categories-tab" data-bs-toggle="tab" data-bs-target="#categories-pane" type="button" role="tab">Categories</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $active_tab === 'clocks' ? 'active' : ''; ?>" id="clocks-tab" data-bs-toggle="tab" data-bs-target="#clocks-pane" type="button" role="tab">Clock Settings</button>
        </li>
    </ul>

    <div class="tab-content" id="managementTabsContent">
        
        <div class="tab-pane fade <?php echo $active_tab === 'portals' ? 'show active' : ''; ?>" id="portals-pane" role="tabpanel">
            <div class="pro-card">
                <div class="d-flex justify-content-between align-items-center p-3 border-bottom bg-white">
                    <h6 class="fw-bold m-0 text-dark">All Portals</h6>
                    <button type="button" class="btn btn-primary-pro d-flex align-items-center" onclick="openPortalModal()">
                        <i data-feather="plus" style="width:14px;" class="me-1"></i> Add Portal
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table-custom table-hover">
                        <thead>
                            <tr>
                                <th style="padding-left: 20px;">Portal Name & URL</th>
                                <th style="width: 20%;">Category</th>
                                <th class="text-center" style="width: 120px;">Sort Order</th>
                                <th style="width: 25%;">Visibility</th>
                                <th class="text-center" style="width: 80px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($portals)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-5"><i data-feather="layout" class="mb-2" style="width:32px; height:32px; opacity:0.3;"></i><br>No portals configured.</td></tr>
                            <?php else: ?>
                                <?php foreach ($portals as $p): 
                                    $icon_src = !empty($p['icon_path']) ? APP_BASE_PATH . ltrim($p['icon_path'], '/') : APP_BASE_PATH . 'assets/images/default_portal.png';
                                    $p_json = htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8');
                                ?>
                                    <tr>
                                        <td style="padding-left: 20px;">
                                            <div class="d-flex align-items-center gap-3">
                                                <img src="<?php echo $icon_src; ?>" alt="icon" class="table-icon-preview shadow-sm">
                                                <div>
                                                    <div class="fw-bold text-dark"><?php echo htmlspecialchars($p['name']); ?></div>
                                                    <a href="<?php echo htmlspecialchars($p['url']); ?>" target="_blank" class="text-muted small text-decoration-none hover-primary"><?php echo htmlspecialchars($p['url']); ?></a>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($p['category_name'] ?? 'Uncategorized'); ?></td>
                                        <td class="text-center"><?php echo $p['sort_order']; ?></td>
                                        <td>
                                            <?php if ($p['is_unrestricted']): ?>
                                                <span class="status-badge bg-success-subtle text-success-emphasis border border-success-subtle">All Departments</span>
                                            <?php else: ?>
                                                <div class="d-flex flex-wrap gap-1" style="max-width: 200px;">
                                                    <?php foreach(explode(',', $p['restricted_departments']) as $dept): ?>
                                                        <span class="status-badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"><?php echo htmlspecialchars(trim($dept)); ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="dropdown action-btn-container">
                                                <button class="btn dropdown-toggle border" type="button" data-bs-toggle="dropdown" aria-expanded="false" data-bs-display="static">
                                                    <i data-feather="more-horizontal" style="width:14px; height:14px;"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                    <li><a class="dropdown-item" href="#" onclick="openPortalModal(<?php echo $p_json; ?>)"><i data-feather="edit-2"></i> Edit</a></li>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li><a class="dropdown-item text-danger" href="#" onclick="confirmDelete(<?php echo $p['id']; ?>, 'portal')"><i data-feather="trash-2"></i> Delete</a></li>
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

        <div class="tab-pane fade <?php echo $active_tab === 'categories' ? 'show active' : ''; ?>" id="categories-pane" role="tabpanel">
            <div class="row">
                <div class="col-xl-7 col-lg-9 col-md-12"> <div class="pro-card">
                        <div class="d-flex justify-content-between align-items-center p-3 border-bottom bg-white">
                            <h6 class="fw-bold m-0 text-dark">Portal Categories</h6>
                            <button type="button" class="btn btn-primary-pro d-flex align-items-center" onclick="openCategoryModal()">
                                <i data-feather="plus" style="width:14px;" class="me-1"></i> Add Category
                            </button>
                        </div>
                        <div class="table-responsive">
                            <table class="table-custom table-hover">
                                <thead>
                                    <tr>
                                        <th style="padding-left: 20px; width: 60%;">Category Name</th>
                                        <th class="text-center" style="width: 25%;">Sort Order</th>
                                        <th class="text-center" style="width: 15%;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($categories)): ?>
                                        <tr><td colspan="3" class="text-center text-muted py-5"><i data-feather="folder" class="mb-2" style="width:32px; height:32px; opacity:0.3;"></i><br>No categories configured.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($categories as $cat): 
                                            $c_json = htmlspecialchars(json_encode($cat), ENT_QUOTES, 'UTF-8');
                                        ?>
                                            <tr>
                                                <td class="fw-bold text-dark" style="padding-left: 20px;"><?php echo htmlspecialchars($cat['category_name']); ?></td>
                                                <td class="text-center"><?php echo $cat['sort_order']; ?></td>
                                                <td class="text-center">
                                                    <div class="dropdown action-btn-container text-center">
                                                        <button class="btn dropdown-toggle border" type="button" data-bs-toggle="dropdown" aria-expanded="false" data-bs-display="static">
                                                            <i data-feather="more-horizontal" style="width:14px; height:14px;"></i>
                                                        </button>
                                                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                            <li><a class="dropdown-item" href="#" onclick="openCategoryModal(<?php echo $c_json; ?>)"><i data-feather="edit-2"></i> Edit</a></li>
                                                            <li><hr class="dropdown-divider"></li>
                                                            <li><a class="dropdown-item text-danger" href="#" onclick="confirmDelete(<?php echo $cat['id']; ?>, 'category')"><i data-feather="trash-2"></i> Delete</a></li>
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
            </div>
        </div>

        <div class="tab-pane fade <?php echo $active_tab === 'clocks' ? 'show active' : ''; ?>" id="clocks-pane" role="tabpanel">
            <div class="row">
                <div class="col-xl-8 col-lg-10 col-md-12">
                    <div class="pro-card">
                        <div class="d-flex justify-content-between align-items-center p-3 border-bottom bg-white">
                            <h6 class="fw-bold m-0 text-dark">World Clocks Configuration</h6>
                        </div>
                        <div class="p-4 bg-white">
                            <form action="<?= APP_BASE_PATH ?>index.php?route=manage_portals" method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="clock_action" value="save">
                                
                                <p class="text-muted mb-4">Add, remove, or rearrange the clocks displayed on the Portals page.</p>

                                <div id="clockRowsContainer">
                                    <?php foreach ($clock_settings as $index => $clock): ?>
                                    <div class="row g-2 mb-3 clock-row align-items-center">
                                        <div class="col-md-5">
                                            <label class="form-label">Display Label</label>
                                            <input type="text" class="form-control" name="clock_label[]" value="<?php echo htmlspecialchars($clock['label']); ?>" placeholder="e.g., India (IST)" required>
                                        </div>
                                        <div class="col-md-5">
                                            <label class="form-label">Timezone</label>
                                            <select name="clock_timezone[]" class="form-select" required>
                                                <?php foreach($available_timezones as $tz_value => $tz_name): ?>
                                                    <option value="<?php echo $tz_value; ?>" <?php echo ($clock['timezone'] === $tz_value) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($tz_name); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2 mt-4 pt-1">
                                            <button type="button" class="btn btn-outline-danger btn-sm w-100 remove-clock-btn">Remove</button>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="mt-4 pt-3 border-top d-flex justify-content-between">
                                    <button type="button" id="addClockBtn" class="btn btn-outline-primary fw-medium rounded-pill px-4">
                                        <i data-feather="plus" class="me-1" style="width: 16px;"></i> Add Another Clock
                                    </button>
                                    <button type="submit" class="btn btn-primary-modern rounded-pill shadow-sm">
                                        <i data-feather="save" class="me-1" style="width: 16px;"></i> Save Configurations
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<div class="modal fade" id="portalModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px;">
            <div class="modal-header bg-light border-bottom-0 pb-3">
                <h5 class="modal-title fw-bold text-dark d-flex align-items-center" id="portalModalTitle">
                    <i data-feather="layout" class="me-2 text-primary" style="width:18px;"></i> Add Portal
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="portalForm" action="<?= APP_BASE_PATH ?>index.php?route=manage_portals" method="POST" enctype="multipart/form-data">
                <div class="modal-body pt-0">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="portal_action" id="portal_action" value="create">
                    <input type="hidden" name="portal_id" id="portal_id" value="">
                    <input type="hidden" name="existing_icon" id="existing_icon" value="">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Portal Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="portal_name" id="portal_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Category <span class="text-danger">*</span></label>
                            <select class="form-select" name="category_id" id="category_id" required>
                                <option value="">-- Select Category --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Portal URL <span class="text-danger">*</span></label>
                            <input type="url" class="form-control text-primary font-monospace" name="portal_url" id="portal_url" placeholder="https://..." required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Icon Image</label>
                            <input type="file" class="form-control" name="portal_icon" id="portal_icon" accept="image/*">
                            <div class="form-text small text-muted">Leave empty to keep existing. Recommeded: Square PNG/SVG.</div>
                            <div id="current_icon_preview" class="mt-2" style="display:none;">
                                <span class="badge bg-light text-dark border"><i data-feather="image" style="width:12px;" class="me-1"></i>Current Icon Exists</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Sort Order <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="sort_order" id="sort_order" value="1" min="1" required>
                            <div class="form-text small text-muted">Selecting an existing number shifts the others down.</div>
                        </div>
                        
                        <div class="col-12 mt-4">
                            <div class="p-3 bg-light rounded border">
                                <label class="form-label">Department Visibility</label>
                                <div class="d-flex gap-4 mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="visibility_type" id="vis_all" value="all" checked>
                                        <label class="form-check-label fw-medium" for="vis_all">All Departments</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="visibility_type" id="vis_restricted" value="restricted">
                                        <label class="form-check-label fw-medium" for="vis_restricted">Restricted (Select below)</label>
                                    </div>
                                </div>
                                <div id="departments_selection" style="display: none;">
                                    <hr class="my-2 border-light">
                                    <div class="row g-2 mt-2">
                                        <?php foreach ($departments as $dept): ?>
                                            <div class="col-md-6">
                                                <div class="form-check">
                                                    <input class="form-check-input dept-checkbox" type="checkbox" name="departments[]" value="<?php echo htmlspecialchars($dept); ?>" id="dept_<?php echo md5($dept); ?>">
                                                    <label class="form-check-label small" for="dept_<?php echo md5($dept); ?>">
                                                        <?php echo htmlspecialchars($dept); ?>
                                                    </label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-light border px-4 rounded-pill fw-medium" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-modern rounded-pill shadow-sm d-flex align-items-center">
                        <i data-feather="save" class="me-2" style="width:16px;"></i> Save Portal
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="categoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px;">
            <div class="modal-header bg-light border-bottom-0 pb-3">
                <h5 class="modal-title fw-bold text-dark d-flex align-items-center" id="categoryModalTitle">
                    <i data-feather="folder" class="me-2 text-primary" style="width:18px;"></i> Add Category
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="categoryForm" action="<?= APP_BASE_PATH ?>index.php?route=manage_portals" method="POST">
                <div class="modal-body pt-0">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="category_action" id="category_action" value="create">
                    <input type="hidden" name="category_id" id="category_id" value="">
                    
                    <div class="mb-3">
                        <label class="form-label">Category Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="category_name" id="category_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sort Order <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="category_sort_order" id="category_sort_order" value="1" min="1" required>
                        <div class="form-text small text-muted">Selecting an existing number shifts the others down.</div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-light border px-4 rounded-pill fw-medium" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-modern rounded-pill shadow-sm d-flex align-items-center">
                        <i data-feather="save" class="me-2" style="width:16px;"></i> Save Category
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
                Are you sure you want to delete this <span id="deleteItemTypeTxt">item</span>? This action cannot be undone.
            </div>
            <div class="modal-footer border-0">
                <form id="deleteForm" method="POST" action="<?= APP_BASE_PATH ?>index.php?route=manage_portals" class="w-100 d-flex justify-content-end gap-2 m-0">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="active_tab" id="delete_active_tab" value="">
                    <input type="hidden" name="" id="delete_action_input" value="delete">
                    <input type="hidden" name="" id="delete_id_input" value="">
                    
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
    
    // Configuration Data generated by PHP
    const maxSortOrders = <?php echo json_encode($max_sort_orders); ?>;
    const nextCategorySortOrder = <?php echo $max_category_order + 1; ?>;

    // --- Dynamic Sort Order Calculation (PORTALS) ---
    document.getElementById('category_id').addEventListener('change', function() {
        if (document.getElementById('portal_action').value === 'create') {
            const catId = this.value;
            if (catId) {
                // Find the max order for this category, and add 1
                const nextOrder = (parseInt(maxSortOrders[catId]) || 0) + 1;
                document.getElementById('sort_order').value = nextOrder;
            } else {
                document.getElementById('sort_order').value = '';
            }
        }
    });

    // --- Clock Settings Logic ---
    const addClockBtn = document.getElementById('addClockBtn');
    const clockRowsContainer = document.getElementById('clockRowsContainer');
    
    if (addClockBtn) {
        addClockBtn.addEventListener('click', function() {
            const firstRow = clockRowsContainer.querySelector('.clock-row');
            if (firstRow) {
                const newRow = firstRow.cloneNode(true);
                newRow.querySelector('input[type="text"]').value = '';
                clockRowsContainer.appendChild(newRow);
            }
        });
    }

    if (clockRowsContainer) {
        clockRowsContainer.addEventListener('click', function(e) {
            if (e.target.classList.contains('remove-clock-btn') || e.target.closest('.remove-clock-btn')) {
                const rows = clockRowsContainer.querySelectorAll('.clock-row');
                if (rows.length > 1) {
                    const rowToRemove = e.target.closest('.clock-row');
                    rowToRemove.remove();
                } else {
                    alert('You must keep at least one clock configured.');
                }
            }
        });
    }

    // --- Portal Form Visibility Toggle ---
    const visAll = document.getElementById('vis_all');
    const visRestricted = document.getElementById('vis_restricted');
    const deptSelection = document.getElementById('departments_selection');
    const deptCheckboxes = document.querySelectorAll('.dept-checkbox');

    function toggleDeptSelection() {
        if (visRestricted.checked) {
            deptSelection.style.display = 'block';
            deptCheckboxes.forEach(cb => cb.required = false);
        } else {
            deptSelection.style.display = 'none';
            deptCheckboxes.forEach(cb => cb.checked = false);
        }
    }

    if (visAll && visRestricted) {
        visAll.addEventListener('change', toggleDeptSelection);
        visRestricted.addEventListener('change', toggleDeptSelection);
    }

    // --- Portal Modal Logic ---
    const portalModal = new bootstrap.Modal(document.getElementById('portalModal'));
    window.openPortalModal = function(portalData = null) {
        document.getElementById('portalForm').reset();
        deptCheckboxes.forEach(cb => cb.checked = false);
        
        if (portalData) {
            document.getElementById('portalModalTitle').innerHTML = '<i data-feather="edit-2" class="me-2 text-primary" style="width:18px;"></i> Edit Portal';
            document.getElementById('portal_action').value = 'update';
            document.getElementById('portal_id').value = portalData.id;
            document.getElementById('portal_name').value = portalData.name;
            document.getElementById('portal_url').value = portalData.url;
            document.getElementById('category_id').value = portalData.category_id || '';
            document.getElementById('sort_order').value = portalData.sort_order;
            document.getElementById('existing_icon').value = portalData.icon_path || '';
            
            document.getElementById('current_icon_preview').style.display = portalData.icon_path ? 'block' : 'none';

            if (portalData.is_unrestricted) {
                visAll.checked = true;
            } else {
                visRestricted.checked = true;
                const restrictedDepts = portalData.restricted_departments ? portalData.restricted_departments.split(',').map(d => d.trim()) : [];
                deptCheckboxes.forEach(cb => {
                    if (restrictedDepts.includes(cb.value)) {
                        cb.checked = true;
                    }
                });
            }
        } else {
            document.getElementById('portalModalTitle').innerHTML = '<i data-feather="layout" class="me-2 text-primary" style="width:18px;"></i> Add Portal';
            document.getElementById('portal_action').value = 'create';
            document.getElementById('portal_id').value = '';
            document.getElementById('existing_icon').value = '';
            document.getElementById('current_icon_preview').style.display = 'none';
            document.getElementById('sort_order').value = ''; // Cleared so auto-populate can fill it when category is chosen
            visAll.checked = true;
        }
        
        toggleDeptSelection();
        if (typeof feather !== 'undefined') feather.replace();
        portalModal.show();
    };

    // --- Category Modal Logic ---
    const categoryModal = new bootstrap.Modal(document.getElementById('categoryModal'));
    window.openCategoryModal = function(catData = null) {
        document.getElementById('categoryForm').reset();
        
        if (catData) {
            document.getElementById('categoryModalTitle').innerHTML = '<i data-feather="edit-2" class="me-2 text-primary" style="width:18px;"></i> Edit Category';
            document.getElementById('category_action').value = 'update';
            document.getElementById('category_id').value = catData.id;
            document.getElementById('category_name').value = catData.category_name;
            document.getElementById('category_sort_order').value = catData.sort_order;
        } else {
            document.getElementById('categoryModalTitle').innerHTML = '<i data-feather="folder" class="me-2 text-primary" style="width:18px;"></i> Add Category';
            document.getElementById('category_action').value = 'create';
            document.getElementById('category_id').value = '';
            // Auto-populate the next sequential number for Categories
            document.getElementById('category_sort_order').value = nextCategorySortOrder;
        }
        
        if (typeof feather !== 'undefined') feather.replace();
        categoryModal.show();
    };

    // --- Delete Logic ---
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    window.confirmDelete = function(id, type) {
        document.getElementById('deleteItemTypeTxt').textContent = type;
        
        const actionInput = document.getElementById('delete_action_input');
        const idInput = document.getElementById('delete_id_input');
        const tabInput = document.getElementById('delete_active_tab');

        if (type === 'portal') {
            actionInput.name = 'portal_action';
            idInput.name = 'portal_id';
            tabInput.value = 'portals';
        } else {
            actionInput.name = 'category_action';
            idInput.name = 'category_id';
            tabInput.value = 'categories';
        }
        
        idInput.value = id;
        deleteModal.show();
    };

    if (typeof feather !== 'undefined') {
        feather.replace();
    }
});
</script>