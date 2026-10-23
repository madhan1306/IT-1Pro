<?php
// pages/add_edit_shipment.php

include_once '../includes/error_handler.php';

// 1. Session & Security Headers
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

include_once '../includes/db_connect.php';
include_once '../includes/auth.php';

// --- AJAX HANDLER FOR GATEPASS CHECK ---
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'check_gatepass') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $recipient_name = trim($_POST['recipient_name']);
    $employee_id = !empty($_POST['employee_id']) ? (int)$_POST['employee_id'] : 0;
    
    // Find the latest gatepass. Prioritize Unshipped ones first.
    $sql = "SELECT gp.id, gp.gatepass_no, gp.status,
            (SELECT COUNT(id) FROM shipments s WHERE s.gatepass_id = gp.id) as has_shipment
            FROM gatepass_requests gp 
            LEFT JOIN gp_vendors v ON gp.vendor_id = v.id 
            WHERE (gp.employee_user_id = ? AND gp.employee_user_id > 0) 
               OR gp.employee_name = ? 
               OR v.vendor_name = ?
            ORDER BY 
               (SELECT COUNT(id) FROM shipments s WHERE s.gatepass_id = gp.id) ASC,
               gp.id DESC LIMIT 1";
               
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $employee_id, $recipient_name, $recipient_name);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($row = $res->fetch_assoc()) {
        if ($row['has_shipment'] > 0) {
            echo json_encode(['status' => 'already_shipped', 'gatepass_no' => $row['gatepass_no']]);
        } else if ($row['status'] !== 'Approved') {
            echo json_encode(['status' => 'not_approved', 'gatepass_no' => $row['gatepass_no'], 'gp_status' => $row['status']]);
        } else {
            echo json_encode(['status' => 'found', 'gatepass_id' => $row['id'], 'gatepass_no' => $row['gatepass_no']]);
        }
    } else {
        echo json_encode(['status' => 'not_found']);
    }
    
    $stmt->close();
    exit;
}

checkPermission('view_shipment');

function log_shipment_activity($conn, $gatepass_id, $description, $field_changed = null, $old_value = null, $new_value = null) {
    $performed_by = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')) ?: $_SESSION['username'];
    $action_type = 'Shipment';
    $employee_id = null;

    $stmt_find = $conn->prepare("SELECT employee_id FROM checklists WHERE gatepass_id = ? LIMIT 1");
    if ($stmt_find) {
        $stmt_find->bind_param("i", $gatepass_id);
        $stmt_find->execute();
        if ($row = $stmt_find->get_result()->fetch_assoc()) {
            $employee_id = $row['employee_id'];
        }
        $stmt_find->close();
    }

    if ($employee_id) {
        $sql = "INSERT INTO action_logs (joiner_id, template_id, performed_by, action_type, action_description, field_changed, old_value, new_value) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt_log = $conn->prepare($sql);
        if ($stmt_log) {
            $stmt_log->bind_param("iissssss", $employee_id, $gatepass_id, $performed_by, $action_type, $description, $field_changed, $old_value, $new_value);
            $stmt_log->execute();
            $stmt_log->close();
        }
    }
}

$is_editing = isset($_GET['id']) && is_numeric($_GET['id']);
$from_gatepass_id = isset($_GET['from_gatepass_id']) && is_numeric($_GET['from_gatepass_id']) ? (int)$_GET['from_gatepass_id'] : null;

if ($from_gatepass_id && !$is_editing) {
    $check_stmt = $conn->prepare("SELECT id FROM shipments WHERE gatepass_id = ?");
    if ($check_stmt) {
        $check_stmt->bind_param("i", $from_gatepass_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        if ($existing_shipment = $check_result->fetch_assoc()) {
            header('Location: ' . APP_BASE_PATH . 'index.php?route=add_edit_shipment&id=' . $existing_shipment['id']);
            exit();
        }
        $check_stmt->close();
    }
}

$shipment = []; 
$pageTitle = $is_editing ? "Edit Shipment" : "Create New Shipment";
$shipment_status_history = [];
$full_activity_log = [];
$errors = [];

if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['ajax_action'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Security Validation Failed: Invalid CSRF Token.");
    }

    // Backend Date Validation
    $status = $_POST['shipment_status'] ?? 'New';
    if (in_array($status, ['Shipped', 'In Transit', 'Delivered']) && empty($_POST['shipped_on'])) {
        $errors['shipped_on'] = "Date Shipped is required.";
    }
    if ($status === 'Delivered' && empty($_POST['delivered_on'])) {
        $errors['delivered_on'] = "Date Delivered is required.";
    }
    if ($status === 'Cancelled' && empty($_POST['cancelled_on'])) {
        $errors['cancelled_on'] = "Date Cancelled is required.";
    }

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            $shipment_id = !empty($_POST['id']) ? (int)$_POST['id'] : null;

            if ($shipment_id) { 
                // SECURITY: Prevent editing if already Delivered in DB
                $stmt_lock_check = $conn->prepare("SELECT shipment_status FROM shipments WHERE id = ?");
                $stmt_lock_check->bind_param("i", $shipment_id);
                $stmt_lock_check->execute();
                $lock_status = $stmt_lock_check->get_result()->fetch_assoc()['shipment_status'] ?? '';
                $stmt_lock_check->close();
                
                if ($lock_status === 'Delivered') {
                    throw new Exception("This shipment has been marked as Delivered and is locked from further changes.");
                }
                
                checkPermission('edit_shipment'); 
            } else { 
                checkPermission('create_shipment'); 
            }

            $gatepass_id = !empty($_POST['gatepass_id']) ? (int)$_POST['gatepass_id'] : null;
            $recipient_name = $_POST['recipient_name'] ?? '';
            $recipient_phone = $_POST['recipient_phone'] ?? null;
            $recipient_address = $_POST['recipient_address'] ?? '';
            $shipment_status = $status;
            $courier_name = !empty($_POST['courier_name']) ? $_POST['courier_name'] : null;
            $tracking_number = !empty($_POST['tracking_number']) ? $_POST['tracking_number'] : null;
            
            $shipped_on = !empty($_POST['shipped_on']) ? $_POST['shipped_on'] : null;
            $delivered_on = !empty($_POST['delivered_on']) ? $_POST['delivered_on'] : null;
            $cancelled_on = !empty($_POST['cancelled_on']) ? $_POST['cancelled_on'] : null;
            
            $updated_by = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')) ?: $_SESSION['username'];

            if ($shipment_id) {
                $stmt_old = $conn->prepare("SELECT * FROM shipments WHERE id = ?");
                $old_data = null;
                if($stmt_old) {
                    $stmt_old->bind_param("i", $shipment_id);
                    $stmt_old->execute();
                    $old_data = $stmt_old->get_result()->fetch_assoc();
                    $stmt_old->close();
                }
                
                $sql = "UPDATE shipments SET recipient_name=?, recipient_phone=?, recipient_address=?, shipment_status=?, courier_name=?, tracking_number=?, shipped_on=?, delivered_on=?, cancelled_on=?, updated_by=? WHERE id=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssssssssi", $recipient_name, $recipient_phone, $recipient_address, $shipment_status, $courier_name, $tracking_number, $shipped_on, $delivered_on, $cancelled_on, $updated_by, $shipment_id);
                if (!$stmt->execute()) throw new Exception("Database error on update: " . $stmt->error);
                $stmt->close();

                if($old_data) {
                    if($old_data['recipient_name'] !== $recipient_name) log_shipment_activity($conn, $gatepass_id, "Recipient Name updated", 'Recipient Name', $old_data['recipient_name'], $recipient_name);
                    if($old_data['recipient_phone'] !== $recipient_phone) log_shipment_activity($conn, $gatepass_id, "Recipient Phone updated", 'Recipient Phone', $old_data['recipient_phone'], $recipient_phone);
                    if($old_data['recipient_address'] !== $recipient_address) log_shipment_activity($conn, $gatepass_id, "Recipient Address updated", 'Recipient Address', $old_data['recipient_address'], $recipient_address);
                    if($old_data['shipment_status'] !== $shipment_status) log_shipment_activity($conn, $gatepass_id, "Status changed to '{$shipment_status}'", 'Shipment Status', $old_data['shipment_status'], $shipment_status);
                    if($old_data['tracking_number'] !== $tracking_number) log_shipment_activity($conn, $gatepass_id, "Tracking Number updated", 'Tracking Number', $old_data['tracking_number'], $tracking_number);
                    if($old_data['courier_name'] !== $courier_name) log_shipment_activity($conn, $gatepass_id, "Courier updated", 'Courier Name', $old_data['courier_name'], $courier_name);
                }
                
                $_SESSION['form_success'] = 'Shipment #' . $shipment_id . ' updated successfully!';
                $redirect_url = APP_BASE_PATH . 'index.php?route=add_edit_shipment&id=' . $shipment_id;

            } else {
                if (empty($gatepass_id)) throw new Exception("Cannot create a shipment without a linked Gatepass ID.");
                $created_by = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')) ?: $_SESSION['username'];
                $sql = "INSERT INTO shipments (gatepass_id, recipient_name, recipient_phone, recipient_address, shipment_status, courier_name, tracking_number, shipped_on, delivered_on, cancelled_on, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("issssssssss", $gatepass_id, $recipient_name, $recipient_phone, $recipient_address, $shipment_status, $courier_name, $tracking_number, $shipped_on, $delivered_on, $cancelled_on, $created_by);
                if (!$stmt->execute()) throw new Exception("Database error on insert: " . $stmt->error);
                $new_shipment_id = $conn->insert_id;
                $stmt->close();

                log_shipment_activity($conn, $gatepass_id, "Shipment #" . $new_shipment_id . " created", 'Shipment Status', null, 'New');
                $_SESSION['form_success'] = 'New Shipment #' . $new_shipment_id . ' created successfully!';
                $redirect_url = APP_BASE_PATH . 'index.php?route=add_edit_shipment&id=' . $new_shipment_id;
            }
            $conn->commit();
            header('Location: ' . $redirect_url);
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['form_error'] = "An error occurred: " . $e->getMessage();
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit();
        }
    }
}

if ($is_editing) {
    $shipment_id_get = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT s.*, gp.gatepass_no FROM shipments s LEFT JOIN gatepass_requests gp ON s.gatepass_id = gp.id WHERE s.id = ?");
    $stmt->bind_param("i", $shipment_id_get);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $shipment = $result->fetch_assoc();
        $from_gatepass_id = $shipment['gatepass_id'];
    } else {
        $_SESSION['page_message'] = ['type' => 'danger', 'text' => 'Shipment not found.', 'context' => 'shipment'];
        header('Location: ' . APP_BASE_PATH . 'index.php?route=shipment');
        exit();
    }
    $stmt->close();
    
    $log_stmt = $conn->prepare("SELECT new_value, performed_by, timestamp FROM action_logs WHERE action_type = 'Shipment' AND template_id = ? AND field_changed = 'Shipment Status' ORDER BY timestamp ASC");
    if($log_stmt) {
        $log_stmt->bind_param("i", $from_gatepass_id);
        $log_stmt->execute();
        $log_result = $log_stmt->get_result();
        while($log_row = $log_result->fetch_assoc()){
            $shipment_status_history[$log_row['new_value']] = $log_row;
        }
        $log_stmt->close();
    }

    $full_log_stmt = $conn->prepare("SELECT * FROM action_logs WHERE action_type = 'Shipment' AND template_id = ? ORDER BY timestamp DESC");
    if($full_log_stmt) {
        $full_log_stmt->bind_param("i", $from_gatepass_id);
        $full_log_stmt->execute();
        $full_log_result = $full_log_stmt->get_result();
        while($log_row = $full_log_result->fetch_assoc()) {
            $full_activity_log[] = $log_row;
        }
        $full_log_stmt->close();
    }
} elseif ($from_gatepass_id) {
    $stmt_gp = $conn->prepare("SELECT gatepass_type, gatepass_no, employee_name, employee_user_id, vendor_id FROM gatepass_requests WHERE id = ?");
    if ($stmt_gp) {
        $stmt_gp->bind_param("i", $from_gatepass_id);
        $stmt_gp->execute();
        $gp_info = $stmt_gp->get_result()->fetch_assoc();
        $stmt_gp->close();
        if ($gp_info) {
            $shipment['gatepass_id'] = $from_gatepass_id;
            $shipment['gatepass_no'] = $gp_info['gatepass_no'];
            
            if ($gp_info['gatepass_type'] === 'Employee' && !empty($gp_info['employee_user_id'])) {
                $stmt_emp = $conn->prepare("SELECT address, phone_number FROM employees WHERE id = ?");
                $stmt_emp->bind_param("i", $gp_info['employee_user_id']);
                $stmt_emp->execute();
                if ($emp_info = $stmt_emp->get_result()->fetch_assoc()) {
                    $shipment['recipient_name'] = $gp_info['employee_name'];
                    $shipment['recipient_address'] = $emp_info['address'];
                    $shipment['recipient_phone'] = $emp_info['phone_number'];
                }
                $stmt_emp->close();
            } elseif ($gp_info['gatepass_type'] === 'Vendor' && !empty($gp_info['vendor_id'])) {
                $stmt_ven = $conn->prepare("SELECT vendor_name, address, contact_mobile FROM gp_vendors WHERE id = ?");
                $stmt_ven->bind_param("i", $gp_info['vendor_id']);
                $stmt_ven->execute();
                if ($ven_info = $stmt_ven->get_result()->fetch_assoc()) {
                    $shipment['recipient_name'] = $ven_info['vendor_name'];
                    $shipment['recipient_address'] = $ven_info['address'];
                    $shipment['recipient_phone'] = $ven_info['contact_mobile'];
                }
                $stmt_ven->close();
            } else {
                $shipment['recipient_name'] = $gp_info['employee_name'] ?? 'N/A';
            }
        }
    }
}

$detailsTabActive = false;
if ($_SERVER["REQUEST_METHOD"] == "POST" && !empty($errors)) {
    $detailsTabActive = true;
} elseif (!$is_editing) {
    $detailsTabActive = true;
}

// --- PERMISSIONS & LOCKING LOGIC ---
$is_locked = ($is_editing && ($shipment['shipment_status'] ?? '') === 'Delivered');
$is_form_editable = $is_editing ? (can('edit_shipment') && !$is_locked) : can('create_shipment');
$disabled_attr = $is_form_editable ? '' : 'disabled';

include_once '../includes/header.php';
?>

<link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

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
        --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
    }
    
    body { font-family: 'Inter', sans-serif; background-color: var(--bg-color) !important; color: var(--text-main); padding-bottom: 100px; font-size: 0.875rem; }
    h1, h2, h3, h4, h5, h6 { font-weight: 600; color: #1e293b; }
    
    .modern-card { background: var(--card-bg); border-radius: var(--radius); box-shadow: var(--card-shadow); border: 1px solid var(--border-color); overflow: hidden; margin-bottom: 24px; }
    .modern-card-header { padding: 16px 20px; background: #ffffff; border-bottom: 1px solid var(--border-color); font-weight: 600; color: var(--primary-color); display: flex; align-items: center; gap: 8px; font-size: 0.95rem; }
    .modern-card-body { padding: 20px; }
    
    .nav-tabs { border-bottom: 1px solid var(--border-color); gap: 10px; margin-bottom: 20px; }
    .nav-tabs .nav-link { color: var(--text-light); border: none; border-bottom: 2px solid transparent; font-weight: 500; padding: 10px 16px; transition: all 0.2s; border-radius: 0; background: transparent; }
    .nav-tabs .nav-link:hover { color: var(--primary-color); background: #f1f5f9; border-radius: 6px 6px 0 0; }
    .nav-tabs .nav-link.active { color: var(--primary-color); border-bottom: 2px solid var(--primary-color); background: transparent; font-weight: 600; }
    
    .form-label { font-weight: 600; font-size: 0.75rem; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.02em; margin-bottom: 6px; }
    .form-control, .form-select { border-radius: 8px; padding: 8px 12px; font-size: 0.875rem; border: 1px solid #cbd5e1; transition: all 0.2s; }
    .form-control:focus, .form-select:focus { border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1); }
    .form-control[readonly], .form-select:disabled, .form-control:disabled { background-color: #f1f5f9; color: #475569; border-color: #e2e8f0; cursor: not-allowed; }
    
    .btn-primary-modern { background-color: var(--primary-color); color: white; border: none; border-radius: 20px; padding: 6px 20px; font-weight: 600; font-size: 0.875rem; transition: all 0.2s; }
    .btn-primary-modern:hover { background-color: var(--primary-hover); transform: translateY(-1px); color: white; }
    
    .tracking-list { list-style: none; padding-left: 1rem; margin-top: 10px; }
    .tracking-item { position: relative; padding-left: 2.5rem; padding-bottom: 2.5rem; }
    .tracking-item:last-child { padding-bottom: 0; }
    .tracking-item::before { content: ''; position: absolute; left: 14px; top: 12px; width: 2px; background-color: #e2e8f0; height: 100%; }
    .tracking-item:last-child::before { display: none; }
    .tracking-item-icon { position: absolute; left: 0; top: 0; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; z-index: 1; transition: all 0.3s; }
    .tracking-item-icon.completed { background-color: var(--primary-color); color: white; box-shadow: 0 0 0 4px #ccfbf1; }
    .tracking-item-icon.pending { background-color: #f8fafc; border: 2px solid #cbd5e1; color: #94a3b8; }
    .tracking-item-content h6 { margin-bottom: 0.25rem; font-weight: 600; color: #1e293b; font-size: 0.95rem; }
    .tracking-item-content small { color: #64748b; font-size: 0.8rem; }
    .tracking-item.completed::before { background-color: var(--primary-color); }
    
    .timeline-compact { list-style-type: none; padding-left: 0; position: relative; }
    .timeline-compact:before { content: ' '; background: #e2e8f0; display: inline-block; position: absolute; left: 12px; width: 2px; height: 100%; z-index: 1; }
    .timeline-compact > li { margin: 20px 0; padding-left: 35px; position: relative; }
    .timeline-compact > li:before { content: ' '; background: white; display: inline-block; position: absolute; border-radius: 50%; border: 3px solid var(--primary-color); left: 4px; width: 18px; height: 18px; z-index: 2; top: 4px; }
    .timeline-compact .event-list { padding: 16px; background-color: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0; }
    .timeline-compact .event-item { font-size: 0.875rem; color: #334155; position: relative; padding-left: 20px; margin-bottom: 12px; }
    .timeline-compact .event-item:last-child { margin-bottom: 0; }
    .timeline-compact .event-item:before { content: '\2713'; position: absolute; left: 0; color: var(--primary-color); font-weight: bold; }
    .timeline-compact .event-details { font-size: 0.75rem; color: #64748b; display: block; margin-top: 4px; }
    .timeline-compact .change-detail { font-size: 0.8rem; color: #475569; background-color: #ffffff; padding: 8px 12px; border-radius: 6px; margin-top: 8px; border-left: 3px solid var(--primary-color); display: inline-flex; flex-wrap: wrap; align-items: baseline; gap: 0.4rem; box-shadow: 0 1px 2px rgba(0,0,0,0.02); border: 1px solid #e2e8f0; border-left-width: 3px; }
    .timeline-compact .change-detail em { color: var(--primary-color); font-style: normal; font-weight: 600; }
    
    .ui-autocomplete { box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05) !important; border-radius: 8px !important; border: 1px solid var(--border-color) !important; background: #ffffff !important; z-index: 1050 !important; padding: 4px 0 !important; max-height: 250px; overflow-y: auto; overflow-x: hidden; }
    .ui-menu-item-wrapper { padding: 10px 16px !important; font-family: 'Inter', sans-serif !important; font-size: 0.875rem !important; color: var(--text-main) !important; border: none !important; background: transparent !important; transition: background-color 0.15s; cursor: pointer !important; }
    .ui-menu-item-wrapper.ui-state-active { background-color: #f0fdfa !important; color: var(--primary-color) !important; margin: 0 !important; }
</style>

<div class="container-xl py-4">

    <div class="mb-4">
        <a href="<?= APP_BASE_PATH ?>index.php?route=shipment" class="text-decoration-none text-muted small fw-bold d-inline-flex align-items-center transition-colors hover-primary mb-2 check-unsaved">
            <i data-feather="arrow-left" style="width:14px;" class="me-1"></i> BACK TO LIST
        </a>
        <div class="d-flex justify-content-between align-items-end mt-2">
            <div>
                <h4 class="fw-bold m-0 text-dark"><?php echo $pageTitle; ?></h4>
                <div class="text-muted small mt-1"><?php echo $is_editing ? "ID: #" . $shipment['id'] : "Generate a new shipment record linked to a gatepass"; ?></div>
            </div>
            <?php if ($is_editing): ?>
                <div class="text-end">
                    <?php
                        $status_badge_class = match (strtolower($shipment['shipment_status'])) {
                            'delivered' => 'bg-success-subtle text-success-emphasis border border-success-subtle',
                            'shipped', 'in transit' => 'bg-info-subtle text-info-emphasis border border-info-subtle',
                            'new' => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
                            'cancelled' => 'bg-danger-subtle text-danger border border-danger-subtle',
                            default => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
                        };
                    ?>
                    <span class="badge rounded-pill p-2 <?php echo $status_badge_class; ?>" style="font-size: 0.75rem; letter-spacing: 0.02em; text-transform: uppercase;">
                        <?php echo htmlspecialchars($shipment['shipment_status']); ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <ul class="nav nav-tabs" id="mainTab" role="tablist">
        <?php if ($is_editing): ?>
            <li class="nav-item" role="presentation"><button class="nav-link <?php echo !$detailsTabActive ? 'active' : ''; ?>" id="tracking-tab" data-bs-toggle="tab" data-bs-target="#tracking-pane" type="button">Shipment Tracking</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link <?php echo $detailsTabActive ? 'active' : ''; ?>" id="details-tab" data-bs-toggle="tab" data-bs-target="#details-pane" type="button">Shipment Details</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" id="timeline-tab" data-bs-toggle="tab" data-bs-target="#timeline-pane" type="button">Activity Log</button></li>
        <?php else: ?>
             <li class="nav-item" role="presentation"><button class="nav-link active" id="details-tab" data-bs-toggle="tab" data-bs-target="#details-pane" type="button">Initialization Details</button></li>
        <?php endif; ?>
    </ul>

    <div class="tab-content" id="mainTabContent">
        <div class="tab-pane fade <?php if($is_editing) { echo $detailsTabActive ? 'show active' : ''; } else { echo 'show active'; } ?>" id="details-pane" role="tabpanel">
            
            <form id="shipmentForm" method="POST" action="<?= APP_BASE_PATH ?>index.php?route=add_edit_shipment<?php echo $is_editing ? '&id='.$_GET['id'] : ''; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="id" value="<?php echo htmlspecialchars($shipment['id'] ?? ''); ?>">
                <input type="hidden" id="gatepass_id" name="gatepass_id" value="<?php echo htmlspecialchars($from_gatepass_id ?? $shipment['gatepass_id'] ?? ''); ?>">
                
                <div class="modern-card">
                    <div class="modern-card-header bg-light">
                        <div class="d-flex align-items-center"><i data-feather="user" style="width:16px;" class="me-2 text-primary"></i> Recipient & Gatepass Link</div>
                    </div>
                    <div class="modern-card-body">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label">Recipient Name <span class="text-danger">*</span></label>
                                <div class="position-relative">
                                    <i data-feather="search" class="position-absolute text-muted" style="left: 12px; top: 50%; transform: translateY(-50%); width: 14px;"></i>
                                    <input type="text" id="recipient_name" name="recipient_name" class="form-control ps-5 fw-medium" placeholder="Search employee or enter vendor..." value="<?php echo htmlspecialchars($_POST['recipient_name'] ?? $shipment['recipient_name'] ?? ''); ?>" required autocomplete="off" <?php echo $disabled_attr; ?>>
                                </div>
                                <?php if(!$is_editing): ?>
                                    <div class="form-text text-muted small mt-1">Select an employee to auto-link their pending Gatepass.</div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Gatepass Reference <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-muted"><i data-feather="link" style="width:14px;"></i></span>
                                    <input type="text" id="gatepass_no" class="form-control fw-bold text-primary font-monospace bg-light" value="<?php echo htmlspecialchars($_POST['gatepass_no'] ?? $shipment['gatepass_no'] ?? 'Unlinked'); ?>" readonly required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Recipient Phone</label>
                                <input type="text" name="recipient_phone" class="form-control" value="<?php echo htmlspecialchars($_POST['recipient_phone'] ?? $shipment['recipient_phone'] ?? ''); ?>" <?php echo $disabled_attr; ?>>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Recipient Address <span class="text-danger">*</span></label>
                                <textarea name="recipient_address" class="form-control" rows="2" required <?php echo $disabled_attr; ?>><?php echo htmlspecialchars($_POST['recipient_address'] ?? $shipment['recipient_address'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modern-card mt-4">
                    <div class="modern-card-header bg-light">
                        <div class="d-flex align-items-center"><i data-feather="truck" style="width:16px;" class="me-2 text-primary"></i> Shipping Information</div>
                    </div>
                    <div class="modern-card-body">
                        <div class="row g-4">
                            <div class="col-md-4">
                                <label class="form-label">Shipment Status <span class="text-danger">*</span></label>
                                <select name="shipment_status" id="shipment_status" class="form-select fw-medium" <?php echo $disabled_attr; ?>>
                                    <?php $selected_status = $_POST['shipment_status'] ?? $shipment['shipment_status'] ?? 'New'; ?>
                                    <option value="New" <?php echo ($selected_status == 'New') ? 'selected' : ''; ?>>New</option>
                                    <option value="Shipped" <?php echo ($selected_status == 'Shipped') ? 'selected' : ''; ?>>Shipped</option>
                                    <option value="In Transit" <?php echo ($selected_status == 'In Transit') ? 'selected' : ''; ?>>In Transit</option>
                                    <option value="Delivered" <?php echo ($selected_status == 'Delivered') ? 'selected' : ''; ?>>Delivered</option>
                                    <option value="Cancelled" <?php echo ($selected_status == 'Cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Courier Name</label>
                                <input type="text" id="courier_name" name="courier_name" class="form-control" placeholder="e.g., FedEx, DHL" value="<?php echo htmlspecialchars($_POST['courier_name'] ?? $shipment['courier_name'] ?? ''); ?>" <?php echo $disabled_attr; ?>>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Tracking Number</label>
                                <input type="text" id="tracking_number" name="tracking_number" class="form-control font-monospace" placeholder="Enter tracking code..." value="<?php echo htmlspecialchars($_POST['tracking_number'] ?? $shipment['tracking_number'] ?? ''); ?>" <?php echo $disabled_attr; ?>>
                            </div>

                            <div class="col-md-4 date-field-wrapper" id="wrap_shipped_on" style="display: none;">
                                <label for="shipped_on" class="form-label">Date Shipped <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-muted"><i data-feather="calendar" style="width:16px;"></i></span>
                                    <input type="text" id="shipped_on" name="shipped_on" class="form-control <?php echo $is_locked ? 'bg-light' : 'bg-white'; ?> flatpickr-date" placeholder="YYYY-MM-DD" value="<?php echo htmlspecialchars($_POST['shipped_on'] ?? $shipment['shipped_on'] ?? ''); ?>" <?php echo $disabled_attr; ?>>
                                </div>
                            </div>
                            
                            <div class="col-md-4 date-field-wrapper" id="wrap_delivered_on" style="display: none;">
                                <label for="delivered_on" class="form-label">Date Delivered <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-success"><i data-feather="check-circle" style="width:16px;"></i></span>
                                    <input type="text" id="delivered_on" name="delivered_on" class="form-control <?php echo $is_locked ? 'bg-light' : 'bg-white'; ?> flatpickr-date" placeholder="YYYY-MM-DD" value="<?php echo htmlspecialchars($_POST['delivered_on'] ?? $shipment['delivered_on'] ?? ''); ?>" <?php echo $disabled_attr; ?>>
                                </div>
                            </div>
                            
                            <div class="col-md-4 date-field-wrapper" id="wrap_cancelled_on" style="display: none;">
                                <label for="cancelled_on" class="form-label">Date Cancelled <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-danger"><i data-feather="x-circle" style="width:16px;"></i></span>
                                    <input type="text" id="cancelled_on" name="cancelled_on" class="form-control <?php echo $is_locked ? 'bg-light' : 'bg-white'; ?> flatpickr-date" placeholder="YYYY-MM-DD" value="<?php echo htmlspecialchars($_POST['cancelled_on'] ?? $shipment['cancelled_on'] ?? ''); ?>" <?php echo $disabled_attr; ?>>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end align-items-center gap-2 mt-4 pt-3 border-top">
                    <a href="<?= APP_BASE_PATH ?>index.php?route=shipment" class="btn btn-light border rounded-pill px-4 fw-medium text-muted check-unsaved">Cancel</a>
                    <?php if ($is_locked): ?>
                        <button type="button" class="btn btn-secondary rounded-pill px-4 shadow-sm d-flex align-items-center" disabled>
                            <i data-feather="lock" class="me-2" style="width:16px;"></i> Locked (Delivered)
                        </button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-primary-modern d-flex align-items-center shadow-sm" <?php echo $disabled_attr; ?>>
                            <i data-feather="save" class="me-2" style="width:16px;"></i> Save Shipment
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        
        <?php if ($is_editing): ?>
        <div class="tab-pane fade <?php echo !$detailsTabActive ? 'show active' : ''; ?>" id="tracking-pane" role="tabpanel">
            <div class="modern-card">
                <div class="modern-card-header bg-white border-bottom">
                    <div class="d-flex align-items-center"><i data-feather="map" style="width:16px;" class="me-2 text-primary"></i> Lifecycle Tracking</div>
                </div>
                <div class="modern-card-body p-5">
                    <ul class="tracking-list">
                        <?php 
                            $tracking_stages = ['Shipment Requested' => 'New', 'Shipped' => 'Shipped', 'In Transit' => 'In Transit', 'Delivered' => 'Delivered'];
                            $stage_icons = ['Shipment Requested' => 'shopping-cart', 'Shipped' => 'truck', 'In Transit' => 'navigation', 'Delivered' => 'check'];
                            $current_status_index = array_search($shipment['shipment_status'], array_values($tracking_stages));
                            if ($current_status_index === false) $current_status_index = -1;
                            
                            foreach ($tracking_stages as $stage_name => $status_value):
                                $stage_index = array_search($status_value, array_values($tracking_stages));
                                $is_completed = $stage_index <= $current_status_index;
                        ?>
                            <li class="tracking-item <?php echo $is_completed ? 'completed' : ''; ?>">
                                <div class="tracking-item-icon <?php echo $is_completed ? 'completed' : 'pending'; ?>">
                                    <i data-feather="<?php echo $stage_icons[$stage_name]; ?>" style="width:14px;"></i>
                                </div>
                                <div class="tracking-item-content">
                                    <h6><?php echo $stage_name; ?></h6>
                                    <small>
                                        <?php 
                                        if ($stage_name === 'Shipment Requested') {
                                            echo "Processed by <strong class='text-dark'>" . htmlspecialchars($shipment['created_by']) . "</strong> on " . date('M d, Y', strtotime($shipment['created_at']));
                                        } elseif ($stage_name === 'Shipped' && !empty($shipment['shipped_on'])) {
                                            echo "Dispatched on <strong class='text-dark'>" . date('M d, Y', strtotime($shipment['shipped_on'])) . "</strong>";
                                        } elseif ($stage_name === 'Delivered' && !empty($shipment['delivered_on'])) {
                                            echo "Delivered on <strong class='text-dark'>" . date('M d, Y', strtotime($shipment['delivered_on'])) . "</strong>";
                                        } elseif ($is_completed && $shipment['shipment_status'] == $status_value) {
                                            $user = $shipment['updated_by'] ?: $shipment['created_by'];
                                            echo "Updated by <strong class='text-dark'>" . htmlspecialchars($user) . "</strong>";
                                        } else {
                                            echo "Pending action";
                                        }
                                        ?>
                                    </small>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <div class="tab-pane fade" id="timeline-pane" role="tabpanel">
            <div class="modern-card">
                <div class="modern-card-header bg-white border-bottom">
                    <div class="d-flex align-items-center"><i data-feather="clock" style="width:16px;" class="me-2 text-primary"></i> Detailed Audit Trail</div>
                </div>
                <div class="modern-card-body bg-light p-4">
                    <ul class="timeline-compact">
                        <?php if (empty($full_activity_log)): ?>
                            <li>
                                <div class="event-list bg-white"><div class="event-item text-muted">No activity has been recorded yet.</div></div>
                            </li>
                        <?php else: ?>
                            <li>
                                <div class="event-list bg-white shadow-sm">
                                    <?php foreach ($full_activity_log as $event): ?>
                                        <div class="event-item">
                                            <span class="fw-medium"><?php echo htmlspecialchars($event['action_description']); ?></span>
                                            <?php if ($event['field_changed'] !== null && $event['field_changed'] !== ''): ?>
                                                <div class="change-detail mt-2">
                                                    <span class="text-muted">Field <strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $event['field_changed']))); ?></strong> changed from</span>
                                                    <em>"<?php echo htmlspecialchars($event['old_value'] ?: 'empty'); ?>"</em>
                                                    <span class="text-muted">to</span>
                                                    <em>"<?php echo htmlspecialchars($event['new_value'] ?: 'empty'); ?>"</em>
                                                </div>
                                            <?php endif; ?>
                                            <span class="event-details mt-1">
                                                <i data-feather="calendar" style="width:10px; margin-right:4px;"></i><?php echo date('M d, Y h:i A', strtotime($event['timestamp'])); ?> 
                                                <span class="mx-1">&bull;</span>
                                                <i data-feather="user" style="width:10px; margin-right:4px;"></i><strong><?php echo htmlspecialchars($event['performed_by']); ?></strong>
                                            </span>
                                        </div>
                                        <hr class="my-3 border-light">
                                    <?php endforeach; ?>
                                </div>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="unsavedChangesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header bg-warning-subtle border-0 pb-0">
                <h5 class="modal-title fw-bold text-warning-emphasis d-flex align-items-center">
                    <i data-feather="alert-triangle" class="me-2"></i> Unsaved Changes
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-4">
                <p class="mb-0 text-dark">You have unsaved changes on this form. Are you sure you want to discard them and leave?</p>
            </div>
            <div class="modal-footer border-0 bg-light">
                <button type="button" class="btn btn-white border px-4 rounded-pill fw-medium" data-bs-dismiss="modal">Stay</button>
                <button type="button" class="btn btn-danger px-4 rounded-pill fw-medium shadow-sm" id="confirm-discard">Discard & Leave</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="alertModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; border-left: 4px solid var(--primary-color);">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-dark d-flex align-items-center" id="alertModalTitle">
                    <i data-feather="info" class="me-2 text-primary"></i> Notice
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-muted pt-3 pb-4" id="alertModalBody"></div>
        </div>
    </div>
</div>

<?php include_once '../includes/footer.php'; ?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Flatpickr initialization
        flatpickr(".flatpickr-date", { 
            dateFormat: "Y-m-d",
            allowInput: true 
        });
        
        const shipmentForm = document.getElementById('shipmentForm');
        const statusSelect = document.getElementById('shipment_status');
        const courierInput = document.getElementById('courier_name');
        const trackingInput = document.getElementById('tracking_number');

        // Setup generic warning Modal UI
        function showAlertModal(msg) {
            document.getElementById('alertModalBody').innerHTML = msg;
            new bootstrap.Modal(document.getElementById('alertModal')).show();
        }

        // --- Unsaved Changes Logic ---
        const unsavedModalElement = document.getElementById('unsavedChangesModal');
        const unsavedModal = new bootstrap.Modal(unsavedModalElement);
        let formIsDirty = false;
        let isSubmitting = false;
        let targetUrl = null;
        const getFormDataAsString = (form) => new URLSearchParams(new FormData(form)).toString();
        let initialFormData = getFormDataAsString(shipmentForm);
        
        shipmentForm.addEventListener('input', () => {
            let currentFormData = getFormDataAsString(shipmentForm);
            formIsDirty = (initialFormData !== currentFormData);
        });
        
        document.addEventListener('click', function(event) {
            if (formIsDirty && !isSubmitting) {
                const link = event.target.closest('a');
                if (link && !shipmentForm.contains(link) && !link.hasAttribute('data-bs-toggle') && link.getAttribute('target') !== '_blank') {
                    event.preventDefault();
                    targetUrl = link.href;
                    unsavedModal.show();
                }
            }
        });
        
        document.getElementById('confirm-discard').addEventListener('click', function() {
            formIsDirty = false; 
            if (targetUrl) { window.location.href = targetUrl; }
        });
        
        // --- Gatepass Check Logic ---
        function checkGatepass(recipientName, employeeId = null) {
            if(!recipientName) return;
            const fd = new FormData();
            fd.append('ajax_action', 'check_gatepass');
            fd.append('recipient_name', recipientName);
            if (employeeId) fd.append('employee_id', employeeId);

            fetch('<?= APP_BASE_PATH ?>index.php?route=add_edit_shipment', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                const gpIdField = document.getElementById('gatepass_id');
                const gpNoField = document.getElementById('gatepass_no');
                
                if(data.status === 'found') {
                    gpIdField.value = data.gatepass_id;
                    gpNoField.value = data.gatepass_no;
                    
                    gpNoField.classList.remove('text-danger', 'text-warning');
                    gpNoField.classList.add('text-primary');
                    
                    gpNoField.style.transition = 'background-color 0.3s ease';
                    gpNoField.style.backgroundColor = '#e0f2fe';
                    setTimeout(() => gpNoField.style.backgroundColor = '', 600);
                    
                } else if (data.status === 'not_approved') {
                    showAlertModal(`The Gatepass <strong>${data.gatepass_no}</strong> for <strong>${recipientName}</strong> is currently <strong>${data.gp_status}</strong>.<br><br>It must be <strong>Approved</strong> before a shipment can be created.`);
                    document.getElementById('recipient_name').value = '';
                    gpIdField.value = '';
                    gpNoField.value = 'Requires Approval';
                    
                    gpNoField.classList.remove('text-primary', 'text-danger');
                    gpNoField.classList.add('text-warning');
                    
                } else if (data.status === 'already_shipped') {
                    showAlertModal(`The Gatepass <strong>${data.gatepass_no}</strong> for <strong>${recipientName}</strong> has already been linked to an existing shipment.`);
                    document.getElementById('recipient_name').value = '';
                    gpIdField.value = '';
                    gpNoField.value = 'Already Shipped';
                    
                    gpNoField.classList.remove('text-primary', 'text-warning');
                    gpNoField.classList.add('text-danger');
                    
                } else {
                    showAlertModal(`No Gatepass found for <strong>${recipientName}</strong>.<br><br>Please <a href="<?= APP_BASE_PATH ?>index.php?route=add_edit_gatepass" class="fw-bold text-decoration-underline text-primary">create and approve a Gatepass</a> first.`);
                    document.getElementById('recipient_name').value = '';
                    gpIdField.value = '';
                    gpNoField.value = 'Unlinked';
                    
                    gpNoField.classList.remove('text-primary', 'text-warning', 'text-danger');
                }
                formIsDirty = true;
            });
        }

        <?php if(!$is_editing): ?>
        $("#recipient_name").autocomplete({
            source: function(request, response) {
                $.ajax({
                    url: "<?= APP_BASE_PATH ?>index.php?route=get_employee_details",
                    dataType: "json",
                    data: { term: request.term },
                    success: function(data) {
                        if (!data.length) {
                            response([{ label: 'No records found (Type manually to check vendors)', value: '' }]);
                        } else {
                            response(data);
                        }
                    }
                });
            },
            minLength: 2,
            select: function(event, ui) {
                if (ui.item && ui.item.id) {
                    event.preventDefault();
                    let empName = ui.item.full_name || ui.item.value || ui.item.label;
                    if (empName.includes('(')) { empName = empName.split('(')[0].trim(); }
                    
                    $("#recipient_name").val(empName);
                    checkGatepass(empName, ui.item.id);
                }
            }
        });

        document.getElementById('recipient_name').addEventListener('change', function() {
            if(!document.getElementById('gatepass_id').value && this.value.trim() !== '') {
                checkGatepass(this.value);
            }
        });
        <?php endif; ?>

        // --- Custom Manual Validation & Date Toggling Logic ---
        const wrapShipped = document.getElementById('wrap_shipped_on');
        const wrapDelivered = document.getElementById('wrap_delivered_on');
        const wrapCancelled = document.getElementById('wrap_cancelled_on');
        
        const inputShipped = document.getElementById('shipped_on');
        const inputDelivered = document.getElementById('delivered_on');
        const inputCancelled = document.getElementById('cancelled_on');

        function handleDateRequirement() {
            if (!statusSelect) return;
            const currentStatus = statusSelect.value;
            
            // Hide all by default
            wrapShipped.style.display = 'none';
            wrapDelivered.style.display = 'none';
            wrapCancelled.style.display = 'none';

            if (['Shipped', 'In Transit'].includes(currentStatus)) {
                wrapShipped.style.display = 'block';
            } else if (currentStatus === 'Delivered') {
                wrapShipped.style.display = 'block';
                wrapDelivered.style.display = 'block';
            } else if (currentStatus === 'Cancelled') {
                wrapCancelled.style.display = 'block';
                if (inputShipped.value.trim() !== '') {
                    wrapShipped.style.display = 'block'; // Keep visible if it was already shipped
                }
            }
        }

        function handleTrackingRequirement() {
            if (!courierInput || !trackingInput) return;
            if (courierInput.value.trim() !== '') {
                trackingInput.setAttribute('required', 'required');
            } else {
                trackingInput.removeAttribute('required');
            }
        }
        
        if(statusSelect) statusSelect.addEventListener('change', handleDateRequirement);
        if(courierInput) courierInput.addEventListener('input', handleTrackingRequirement);
        
        handleDateRequirement();
        handleTrackingRequirement();
        
        // Manual validation on submit to bypass Flatpickr breaking native tooltips
        shipmentForm.addEventListener('submit', function(event) {
            handleDateRequirement();
            handleTrackingRequirement();
            
            let missingFields = [];
            const currentStatus = statusSelect.value;
            
            if (['Shipped', 'In Transit', 'Delivered'].includes(currentStatus) && inputShipped.value.trim() === '') {
                missingFields.push('Date Shipped');
            }
            if (currentStatus === 'Delivered' && inputDelivered.value.trim() === '') {
                missingFields.push('Date Delivered');
            }
            if (currentStatus === 'Cancelled' && inputCancelled.value.trim() === '') {
                missingFields.push('Date Cancelled');
            }
            
            if (courierInput.value.trim() !== '' && trackingInput.value.trim() === '') {
                missingFields.push('Tracking Number');
            }
            
            if (missingFields.length > 0) {
                event.preventDefault();
                isSubmitting = false;
                showAlertModal(`Please fill in the following required fields:<br><br><ul class="mb-0 text-danger text-start"><li>${missingFields.join('</li><li>')}</li></ul>`);
                return;
            }

            if (!shipmentForm.checkValidity()) {
                event.preventDefault();
                isSubmitting = false;
                shipmentForm.reportValidity();
            } else {
                isSubmitting = true;
                formIsDirty = false;
            }
        });
    });
</script>

<?php include_once '../includes/footer.php'; ?>