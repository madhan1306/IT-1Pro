<?php
// FILE: pages/user_acceptance.php
// Interactive User Acceptance & Induction Form

include_once '../includes/error_handler.php';
include_once '../includes/db_connect.php';
include_once '../includes/auth.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) { die("Access Denied. Please log in."); }

$checklist_id = $_GET['checklist_id'] ?? null;
if (!$checklist_id || !is_numeric($checklist_id)) { 
    die("Invalid Checklist ID."); 
}

// 1. FETCH DATA (Joined with employees to get domain_username)
$sql = "SELECT 
            c.*, 
            e.emp_id, e.first_name, e.last_name, e.department, e.position, e.date_of_joining, e.domain_username 
        FROM checklists c 
        JOIN employees e ON c.employee_id = e.id 
        WHERE c.id = ?";

$stmt = $conn->prepare($sql);
if (!$stmt) { die("DB Error: " . $conn->error); }
$stmt->bind_param("i", $checklist_id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$data) die("Checklist record not found.");

// --- SECURITY & OWNERSHIP CHECKS ---
$logged_in_username = trim($_SESSION['username'] ?? '');
$target_domain_username = trim($data['domain_username'] ?? '');

// Match case-insensitively just in case AD passes different casing
$is_own_form = (!empty($logged_in_username) && !empty($target_domain_username) && strcasecmp($logged_in_username, $target_domain_username) === 0);

$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 1);
$is_it_admin = can('view_system_readiness') || can('manage_system_readiness');

// 1. View Authorization: Block people who have no business seeing this form
if (!$is_super_admin && !$is_it_admin && !$is_own_form) {
    if (function_exists('log_system_event')) {
        log_system_event($conn, 'Security', 'UNAUTHORIZED_ACCESS', "User {$logged_in_username} attempted to access UAC Form ID $checklist_id belonging to {$target_domain_username}.", $_SESSION['user_id']);
    }
    $_SESSION['form_error'] = "Access Denied: You are not authorized to view this form.";
    
    // Redirect basic users to their profile, others to dashboard
    if (can('user_view') && !can('view_dashboard')) {
        header("Location: " . APP_BASE_PATH . "index.php?route=edit_employee"); 
    } else {
        header("Location: " . APP_BASE_PATH . "index.php?route=dashboard");
    }
    exit;
}

// Determine if the form is already locked (resolved)
$is_resolved = ($data['user_acceptance_status'] !== 'Pending');

// Check checklist purpose to determine if Induction block is needed
$purposes_to_exclude_induction = ['Asset Replacement', 'Department Change', 'Other', 'Others'];
$is_new_joiner = !in_array($data['checklist_purpose'] ?? '', $purposes_to_exclude_induction);


// 2. HANDLE FORM SUBMISSION (Write Authorization)
// Only process if Request is POST AND the form is currently Pending
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['decision']) && !$is_resolved) {
    
    // STRICT SECURITY: Only the actual assigned employee can sign their own form!
    if (!$is_own_form) {
        die("Unauthorized Action: Only the assigned employee ({$target_domain_username}) can digitally sign this form.");
    }

    $decision = $_POST['decision']; // 'accept' or 'reject'
    $comments = trim($_POST['comments'] ?? '');
    
    // Capture Feedback (Fallback to existing DB data if the fields were hidden)
    $it_remarks = isset($_POST['it_remarks']) ? trim($_POST['it_remarks']) : ($data['induction_it_remarks'] ?? '');
    $hr_remarks = isset($_POST['hr_remarks']) ? trim($_POST['hr_remarks']) : ($data['induction_hr_remarks'] ?? '');
    $facility_remarks = isset($_POST['facility_remarks']) ? trim($_POST['facility_remarks']) : ($data['induction_facility_remarks'] ?? '');
    
    $username = $_SESSION['username'] ?? 'Unknown';
    $fullname = isset($_SESSION['firstname']) ? trim($_SESSION['firstname'] . ' ' . $_SESSION['lastname']) : $username;
    
    // Capture IP
    $ip_address = $_SERVER['REMOTE_ADDR'];
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) { $ip_address = $_SERVER['HTTP_CLIENT_IP']; }
    elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) { $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR']; }

    $timestamp = date('Y-m-d H:i:s');
    $status = ($decision === 'accept') ? 'Accepted' : 'Rejected';

    $conn->begin_transaction();
    try {
        // Update the main Checklists table
        $stmt = $conn->prepare("UPDATE checklists SET user_acceptance_status = ?, user_acceptance_date = ?, user_acceptance_by = ?, user_acceptance_ip = ?, user_acceptance_comments = ?, induction_it_remarks = ?, induction_hr_remarks = ?, induction_facility_remarks = ? WHERE id = ?");
        $stmt->bind_param("ssssssssi", $status, $timestamp, $fullname, $ip_address, $comments, $it_remarks, $hr_remarks, $facility_remarks, $checklist_id);
        if (!$stmt->execute()) { throw new Exception("Failed to update checklist status."); }
        $stmt->close();

        // Insert into History Table
        $stmt_hist = $conn->prepare("INSERT INTO acceptance_attempts (checklist_id, status, action_date, action_by, comments, induction_it_remarks, induction_hr_remarks, induction_facility_remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt_hist->bind_param("isssssss", $checklist_id, $status, $timestamp, $fullname, $comments, $it_remarks, $hr_remarks, $facility_remarks);
        if (!$stmt_hist->execute()) { throw new Exception("Failed to log history."); }
        $stmt_hist->close();

        // Auto-complete status change in the main checklist timeline
        if ($status === 'Accepted') {
            $task_desc = 'Fill out the Asset Issuance Form';
            $item_status = 'Completed';
            $stmt_item = $conn->prepare("UPDATE checklist_items SET status = ?, updated_by = ?, remarks = 'Digitally Accepted by User' WHERE template_id = ? AND task_description = ?");
            $stmt_item->bind_param("ssis", $item_status, $fullname, $checklist_id, $task_desc);
            if (!$stmt_item->execute()) { throw new Exception("Failed to auto-complete checklist item."); }
            $stmt_item->close();
        }

        // Activity Timeline Logging
        $employee_id_log = $data['employee_id'] ?? 0;
        $action_type = 'System Readiness';
        $action_desc = ($status === 'Accepted') 
            ? "User {$fullname} ACCEPTED the system handover. IP: {$ip_address}" 
            : "User {$fullname} REJECTED the system handover. Reason: {$comments}";
        $field_changed = "User Acceptance Status";
        $new_value = $status;

        $stmt_log = $conn->prepare("INSERT INTO action_logs (joiner_id, template_id, performed_by, action_type, action_description, field_changed, new_value) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt_log->bind_param("iisssss", $employee_id_log, $checklist_id, $fullname, $action_type, $action_desc, $field_changed, $new_value);
        if (!$stmt_log->execute()) { throw new Exception("Failed to insert activity log."); }
        $stmt_log->close();

        $conn->commit();
        
        // POST-REDIRECT-GET (PRG) Pattern
        header("Location: " . APP_BASE_PATH . "index.php?route=user_acceptance&checklist_id=" . $checklist_id);
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = "Error processing request: " . $e->getMessage();
    }
}

$section_counter = 3; // Start dynamic counter for sections 3 onwards
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>User Acceptance Form</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #525659; padding-top: 2rem; padding-bottom: 2rem; font-family: 'Times New Roman', Times, serif; }
        .form-card { 
            max-width: 850px; 
            margin: 0 auto; 
            background: #fff; 
            box-shadow: 0 0 15px rgba(0,0,0,0.5); 
            min-height: 1100px;
            padding: 40px 50px;
            position: relative;
        }
        .company-header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 10px; }
        .company-name { font-size: 24px; font-weight: bold; text-decoration: underline; text-transform: uppercase; margin-bottom: 5px; color: #000; }
        .company-address { font-size: 12px; font-weight: bold; color: #000; }
        .doc-title { text-align: center; font-weight: bold; text-decoration: underline; font-size: 18px; margin-top: 20px; margin-bottom: 20px; text-transform: uppercase; }
        .section-header { background-color: #e6e6e6; font-weight: bold; font-size: 14px; padding: 5px 10px; border: 1px solid #000; margin-top: 20px; text-transform: uppercase; }
        .data-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .data-table td { border: 1px solid #000; padding: 6px 10px; vertical-align: top; }
        .data-label { font-weight: bold; width: 35%; background-color: #f9f9f9; }
        .acknowledgement-box { margin-top: 30px; border: 1px solid #000; padding: 15px; background-color: #fff; }
        .ack-text { font-style: italic; font-size: 15px; line-height: 1.6; text-align: justify; }
        .induction-box { border: 1px solid #ccc; padding: 10px; margin-bottom: 5px; background: #fdfdfd; font-family: sans-serif; }
        .digital-sig { border: 2px dashed #0C5D58; background-color: #eefbf9; padding: 15px; border-radius: 5px; margin-top: 20px; font-family: sans-serif; }
        .sig-label { font-size: 11px; text-transform: uppercase; color: #555; }
        .sig-val { font-weight: bold; color: #0C5D58; font-size: 14px; }
    </style>
</head>
<body>

<div class="container">
    
    <?php if (isset($error_msg)): ?>
        <div class="alert alert-danger text-center mb-4"><i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error_msg; ?></div>
    <?php endif; ?>

    <div class="form-card">
        <div class="company-header">
            <div style="position: absolute; left: 50px; top: 40px;">
                <img src="<?php echo APP_BASE_PATH; ?>assets/images/favicon.png" alt="Logo" style="width: 100px;">
            </div>
            <div class="company-name">Sun Technology Integrators Pvt. Ltd.</div>
            <div class="company-address">No.496, 1st stage, 4th Block, HBR Layout, Bangalore 560043.</div>
        </div>

        <div class="doc-title">User Acceptance & Induction Form</div>

        <form method="POST" action="<?php echo APP_BASE_PATH; ?>index.php?route=user_acceptance&checklist_id=<?php echo $checklist_id; ?>">
            
            <div class="section-header">1. Employee Details</div>
            <table class="data-table">
                <tr>
                    <td class="data-label">Full Name</td>
                    <td><?php echo htmlspecialchars($data['first_name'] . ' ' . $data['last_name']); ?></td>
                </tr>
                <tr>
                    <td class="data-label">Department</td>
                    <td><?php echo htmlspecialchars($data['department'] ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <td class="data-label">Position</td>
                    <td><?php echo htmlspecialchars($data['position'] ?? 'N/A'); ?></td>
                </tr>
            </table>

            <div class="section-header">2. System Information</div>
            <table class="data-table">
                <tr>
                    <td class="data-label">Hostname</td>
                    <td><?php echo htmlspecialchars($data['hostname'] ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <td class="data-label">Serial Number</td>
                    <td><?php echo htmlspecialchars($data['serial_number'] ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <td class="data-label">Model</td>
                    <td><?php echo htmlspecialchars($data['system_model'] ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <td class="data-label">Operating System</td>
                    <td><?php echo htmlspecialchars($data['operating_system'] ?? 'N/A'); ?></td>
                </tr>
            </table>

            <?php if ($is_new_joiner): ?>
                <div class="section-header"><?php echo $section_counter++; ?>. Induction Feedback</div>
                <div style="margin-top: 10px;">
                    <div class="induction-box">
                        <strong>IT Induction:</strong>
                        <input type="text" name="it_remarks" class="form-control form-control-sm border-0 bg-transparent" placeholder="Enter feedback..." value="<?php echo htmlspecialchars($data['induction_it_remarks'] ?? ''); ?>" <?php echo ($is_resolved || !$is_own_form) ? 'readonly' : ''; ?>>
                    </div>
                    <div class="induction-box">
                        <strong>HR Induction:</strong>
                        <input type="text" name="hr_remarks" class="form-control form-control-sm border-0 bg-transparent" placeholder="Enter feedback..." value="<?php echo htmlspecialchars($data['induction_hr_remarks'] ?? ''); ?>" <?php echo ($is_resolved || !$is_own_form) ? 'readonly' : ''; ?>>
                    </div>
                    <div class="induction-box">
                        <strong>Facility Induction:</strong>
                        <input type="text" name="facility_remarks" class="form-control form-control-sm border-0 bg-transparent" placeholder="Enter feedback..." value="<?php echo htmlspecialchars($data['induction_facility_remarks'] ?? ''); ?>" <?php echo ($is_resolved || !$is_own_form) ? 'readonly' : ''; ?>>
                    </div>
                </div>
            <?php endif; ?>

            <div class="section-header"><?php echo $section_counter; ?>. Acknowledgement</div>
            <div class="acknowledgement-box">
                <p class="ack-text mb-0">
                    I acknowledge that the above-mentioned asset has been handed over to me in good working condition. I agree to handle the asset with due care and accept full responsibility for any loss, theft, or damage while it is in my custody, in accordance with the policies of <b>Sun Technology Integrators Pvt. Ltd.</b>
                </p>
            </div>

            <?php if ($is_resolved): ?>
                
                <div class="digital-sig">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="mb-2">
                                <span class="sig-label">Status:</span> 
                                <span class="badge <?php echo ($data['user_acceptance_status'] == 'Accepted') ? 'bg-success' : 'bg-danger'; ?>">
                                    <?php echo strtoupper($data['user_acceptance_status']); ?>
                                </span>
                            </div>
                            <div class="mb-1"><span class="sig-label">Digitally Signed By:</span> <span class="sig-val"><?php echo htmlspecialchars($data['user_acceptance_by']); ?></span></div>
                            <div class="mb-1"><span class="sig-label">Date & Time:</span> <span class="sig-val"><?php echo date('d-M-Y H:i:s', strtotime($data['user_acceptance_date'])); ?></span></div>
                            <div class="mb-0"><span class="sig-label">IP Address:</span> <span class="sig-val"><?php echo htmlspecialchars($data['user_acceptance_ip'] ?? 'N/A'); ?></span></div>
                        </div>
                        <div class="col-md-4 text-end d-flex align-items-center justify-content-end">
                            <?php if ($data['user_acceptance_status'] == 'Accepted'): ?>
                                <i class="bi bi-patch-check-fill text-success" style="font-size: 3rem; opacity: 0.8;"></i>
                            <?php else: ?>
                                <i class="bi bi-x-octagon-fill text-danger" style="font-size: 3rem; opacity: 0.8;"></i>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($data['user_acceptance_status'] == 'Rejected'): ?>
                        <div class="mt-2 pt-2 border-top border-danger text-danger">
                            <strong>Rejection Reason:</strong> <?php echo htmlspecialchars($data['user_acceptance_comments']); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($data['user_acceptance_status'] == 'Accepted'): ?>
                    <div class="text-center mt-4 no-print">
                        <a href="<?php echo APP_BASE_PATH; ?>index.php?route=generate_acceptance_pdf&checklist_id=<?php echo $checklist_id; ?>" target="_blank" class="btn btn-primary btn-lg">
                            <i class="bi bi-file-earmark-pdf-fill me-2"></i>Download Signed PDF
                        </a>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                
                <?php if ($is_own_form): ?>
                    <div class="mt-5 text-center no-print">
                        <div class="mb-4" id="reject-comment-box" style="display:none; text-align: left;">
                            <label class="form-label text-danger fw-bold">Rejection Reason:</label>
                            <textarea name="comments" class="form-control" rows="3" placeholder="Please describe why you are rejecting the handover..."></textarea>
                        </div>
                        
                        <div class="d-flex justify-content-center gap-3">
                            <button type="submit" name="decision" value="accept" class="btn btn-success btn-lg px-5 shadow-sm">
                                <i class="bi bi-check-circle-fill me-2"></i> I ACCEPT & ACKNOWLEDGE
                            </button>
                            
                            <button type="button" id="btn-reject-toggle" class="btn btn-outline-danger btn-lg px-5">
                                REJECT
                            </button>
                        </div>
                        <button type="submit" name="decision" value="reject" id="btn-reject-submit" class="d-none"></button>
                    </div>
                <?php else: ?>
                    <div class="mt-5 text-center no-print p-4" style="background: #f8f9fa; border: 1px dashed #ccc; border-radius: 8px;">
                        <i class="bi bi-clock-history mb-2" style="font-size: 2rem; color: #6c757d;"></i>
                        <h5 class="text-muted fw-bold">Pending Employee Signature</h5>
                        <p class="text-muted mb-0 small">This form is currently waiting for <strong><?php echo htmlspecialchars($data['first_name'] . ' ' . $data['last_name']); ?></strong> to log in and digitally sign.</p>
                    </div>
                <?php endif; ?>

            <?php endif; ?>
        </form>
        
        <div class="text-center mt-4 text-muted small" style="border-top: 1px solid #eee; padding-top: 15px;">
            Generated by IT-One Portal | Sun Technology Integrators Pvt. Ltd.
        </div>
    </div>
</div>

<script>
    document.getElementById('btn-reject-toggle')?.addEventListener('click', function() {
        const box = document.getElementById('reject-comment-box');
        const txt = document.querySelector('textarea[name="comments"]');
        if (box.style.display === 'none') {
            box.style.display = 'block'; 
            txt.focus();
            this.innerHTML = "CONFIRM REJECTION"; 
            this.classList.replace('btn-outline-danger', 'btn-danger');
            this.classList.add('shadow-sm');
        } else {
            if(txt.value.trim() === "") { 
                alert("Please provide a reason for rejection."); 
            } else { 
                document.getElementById('btn-reject-submit').click(); 
            }
        }
    });
</script>
</body>
</html>