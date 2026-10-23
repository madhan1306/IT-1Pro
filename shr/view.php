<?php
// FILE: shr/view.php
date_default_timezone_set('Asia/Kolkata');
if (session_status() === PHP_SESSION_NONE) { session_start(); }

include_once '../includes/db_connect.php';
include_once '../includes/auth.php';
include_once '../includes/email_config.php'; 

checkPermission('view_shr');

$logged_in_username = $_SESSION['username'];
$logged_in_user_fullname = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')) ?: $logged_in_username;

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $conn->prepare("SELECT * FROM shift_handovers WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$shr = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$shr) die("Handover report not found.");

// --- GENERATE DYNAMIC HEADER TITLE ---
preg_match('/\(([^-\)]+)/i', $shr['shift_type'], $matches);
$shift_time = isset($matches[1]) ? trim($matches[1]) : '';
$formatted_date = date('d-M-Y', strtotime($shr['shift_date']));
$daily_no = $shr['daily_shift_no'] ?? 1;

$creator_name_parts = explode(' ', trim($shr['created_by_name']));
$first_name = $creator_name_parts[0];
$last_initial = (count($creator_name_parts) > 1) ? strtoupper(substr(end($creator_name_parts), 0, 1)) : '';
$tech_formatted = trim($first_name . ($last_initial ? " $last_initial" : ""));

$formatted_header = "IT-SHR - {$tech_formatted} | {$formatted_date} | {$shift_time} | {$daily_no}";


// --- EMAIL TRIGGER LOGIC UPON ACKNOWLEDGMENT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'acknowledge') {
    // 1. Prevent execution if already completed or unauthorized
    if ($shr['status'] !== 'Completed' && $logged_in_username === $shr['acknowledged_by_username']) {
        
        // 2. Atomic update: only update if status is currently NOT 'Completed'
        $update_stmt = $conn->prepare("UPDATE shift_handovers SET status = 'Completed', acknowledged_at = NOW() WHERE id = ? AND status != 'Completed'");
        $update_stmt->bind_param("i", $id);
        $update_stmt->execute();
        $was_updated = $update_stmt->affected_rows > 0;
        $update_stmt->close();

        // 3. Trigger email send ONLY if this specific HTTP request mutated the row
        if ($was_updated) {
            $parsed_data = json_decode($shr['glpi_snapshot_json'], true) ?: [];
            $sum = $parsed_data['summary'] ?? [];
            $met = $parsed_data['metrics'] ?? ['received'=>0, 'closed'=>0, 'pending'=>0, 'worked'=>0];
            $tickets = $parsed_data['tickets'] ?? [];

            $val = function($input) { return !empty(trim($input)) ? htmlspecialchars(trim($input)) : 'NA'; };

            $prioTickets = array_filter($tickets, fn($t) => isset($t['is_priority']) && $t['is_priority']);
            $prioHtml = "";
            if (!empty($prioTickets)) {
                $prioHtml = "<ol style='padding-left: 20px; margin: 0;'>";
                foreach ($prioTickets as $pt) {
                    // Fallback to history if the active comment is blank
                    $comment = !empty(trim($pt['priority_comment'])) ? htmlspecialchars(trim($pt['priority_comment'])) : '';
                    if (empty($comment) && !empty($pt['priority_history'])) {
                        $last_hist = end($pt['priority_history']);
                        $comment = htmlspecialchars($last_hist['comment']);
                    }
                    $comment = $comment ?: 'NA';
                    $prioHtml .= "<li><b>Ticket #{$pt['id']}</b>: {$comment}</li>";
                }
                $prioHtml .= "</ol>";
            } else {
                $prioHtml = "NA";
            }
            
            $ack_date_time = date('d-M-Y h:i A');
            
            $emailHTML = "
            <div style='font-family: Arial, sans-serif; color: #333; line-height: 1.6;'>
                <div style='background-color: #f8fafc; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0;'>
                    
                    <h3 style='color: #0f766e; margin-top: 0;'>Shift Handover Completed.</h3>
                    <p>
                        <strong>SHR Prepared By :</strong> " . htmlspecialchars($shr['created_by_name']) . "<br>
                        <strong>Handedover to :</strong> " . htmlspecialchars($shr['acknowledged_by_name']) . "<br>
                        <strong>Ackowledged :</strong> {$ack_date_time}
                    </p>
                    <hr style='border: 0; border-top: 1px solid #e2e8f0; margin: 20px 0;'>

                    <table border='1' cellpadding='8' cellspacing='0' style='border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; font-size: 14px; border-color: #cbd5e1; background-color: #ffffff;'>
                        <tr>
                            <th align='left' style='background-color: #f1f5f9; color: #0f766e; width: 25%;'>Shift Summary</th>
                            <td colspan='5' align='center'>
                                <span style='color: #d97706;'>Previous Pending: <b>{$met['received']}</b></span> &nbsp;|&nbsp; 
                                <span style='color: #dc2626;'>Pending Handover: <b>{$met['pending']}</b></span><br>
                                <span style='color: #0284c7;'>Total Tickets I Worked: <b>{$met['worked']}</b></span> &nbsp;|&nbsp; 
                                <span style='color: #16a34a;'>Closed: <b>{$met['closed']}</b></span>
                            </td>
                        </tr>
                        <tr>
                            <th align='left' style='background-color: #f1f5f9;'>Major Events</th>
                            <td align='center'><b>{$sum['major_events']['count']}</b></td>
                            <th style='background-color: #f1f5f9;'>BRIEF:</th>
                            <td>" . $val($sum['major_events']['brief']) . "</td>
                            <th style='background-color: #f1f5f9;'>STATUS:</th>
                            <td>" . $val($sum['major_events']['status']) . "</td>
                        </tr>
                        <tr>
                            <th align='left' style='background-color: #f1f5f9;'>High Priority Incidents</th>
                            <td align='center'><b>{$sum['high_priority']['count']}</b></td>
                            <th style='background-color: #f1f5f9;'>BRIEF:</th>
                            <td>" . $val($sum['high_priority']['brief']) . "</td>
                            <th style='background-color: #f1f5f9;'>STATUS:</th>
                            <td>" . $val($sum['high_priority']['status']) . "</td>
                        </tr>
                        <tr>
                            <th align='left' style='background-color: #f1f5f9;'>SLA Risks (Not Meeting 2Hrs)</th>
                            <td align='center' style='color: red;'><b>{$sum['sla_risks']['count']}</b></td>
                            <th style='background-color: #f1f5f9;'>BRIEF:</th>
                            <td>" . $val($sum['sla_risks']['brief']) . "</td>
                            <th style='background-color: #f1f5f9;'>STATUS:</th>
                            <td>" . $val($sum['sla_risks']['status']) . "</td>
                        </tr>
                        <tr>
                            <th align='left' style='background-color: #f1f5f9;'>Infrastructure Health</th>
                            <td align='center'><b>" . $val($sum['infra_health']['status']) . "</b></td>
                            <th style='background-color: #f1f5f9;'>COMMENTS:</th>
                            <td colspan='3'>" . $val($sum['infra_health']['comments']) . "</td>
                        </tr>
                        <tr style='background-color: #fef08a;'>
                            <th align='left'>Next Shift Priorities</th>
                            <td align='center'><b>" . count($prioTickets) . "</b></td>
                            <th style='background-color: #fef08a;'>COMMENTS:</th>
                            <td colspan='3'>$prioHtml</td>
                        </tr>
                        <tr style='background-color: #fef08a;'>
                            <th align='left'>Escalations</th>
                            <td align='center'><b>{$sum['escalations']['count']}</b></td>
                            <th>COMMENTS:</th>
                            <td colspan='3'>" . $val($sum['escalations']['comments']) . "</td>
                        </tr>
                        <tr>
                            <th align='left' style='background-color: #f1f5f9;'>Any Oral Updates</th>
                            <td colspan='5' style='white-space: pre-wrap;'>" . $val($sum['oral_updates']) . "</td>
                        </tr>
                        <tr>
                            <th align='left' style='background-color: #f1f5f9;'>Other Information</th>
                            <td colspan='5'>" . $val($sum['attachments']) . "</td>
                        </tr>
                    </table>

                    <div style='margin-top: 20px; color: #475569;'>
                        -<br>
                        <strong>IT Support</strong><br>
                        Sun Technologies<br>
                        <span style='font-size: 15px; color: #94a3b8;'>(This is a autogenerated Email Trigered from IT-One)</span>
                    </div>
                    
                </div>
            </div>";

            $creatorEmail = getUserEmail($conn, $shr['created_by_username']);
            $receiverEmail = getUserEmail($conn, $shr['acknowledged_by_username']);

            // Wrap email triggering in try-catch to prevent crash if config is missing
            try {
                sendSHREmail($receiverEmail, $shr['acknowledged_by_name'], $creatorEmail, $shr['created_by_name'], $emailHTML, $formatted_header);
            } catch (Throwable $e) {
                error_log("Acknowledgment email failed: " . $e->getMessage());
            }
        }

        $base_path = defined('APP_BASE_PATH') ? APP_BASE_PATH : '/';
        header("Location: " . $base_path . "?route=view_shr&id=$id&success=1");
        exit;
    }
}

$snapshot_data = json_decode($shr['glpi_snapshot_json'], true) ?: [];
$metrics = $snapshot_data['metrics'] ?? ['received'=>0,'closed'=>0,'pending'=>0,'worked'=>0];
$summary = $snapshot_data['summary'] ?? [];
$tickets = $snapshot_data['tickets'] ?? $snapshot_data; 

$pageTitle = $formatted_header;
include_once '../includes/header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Inter', sans-serif; background-color: #f8fafc !important; }
    .modern-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; overflow: hidden; margin-bottom: 24px; }
    .modern-card-header { padding: 16px 20px; background: #ffffff; border-bottom: 1px solid #e2e8f0; font-weight: 600; color: #0f766e; display: flex; align-items: center; gap: 8px; font-size: 1rem; }
    .ticket-row { border-bottom: 1px solid #f1f5f9; padding: 12px 16px; background: #fff; border-radius: 8px; margin-bottom: 8px; border: 1px solid #e2e8f0; }
    .btn-success-pro { background-color: #10b981; color: white; border: none; padding: 12px 24px; border-radius: 8px; font-weight: 600; font-size: 1.1rem; transition: all 0.2s; width: 100%; box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.3); }
    .btn-success-pro:hover { background-color: #059669; transform: translateY(-1px); color: white;}
    
    .bg-solved { background-color: #f0fdf4 !important; border-left: 4px solid #10b981 !important; }
    .bg-priority { background-color: #fefce8 !important; border: 2px solid #eab308 !important; }
    .bg-critical { background-color: #fef2f2 !important; border-left: 4px solid #dc2626 !important; }

    .summary-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; background: #fff; }
    .summary-table th, .summary-table td { border: 1px solid #cbd5e1; padding: 8px 12px; vertical-align: middle; }
    .summary-table .text-center { text-align: center; }
    .bg-yellow { background-color: #fef08a; }
    .text-red { color: #dc2626; font-weight: bold; }
</style>

<div class="container-fluid py-4 px-lg-5"> <!-- Adjusted to container-fluid to give more space for wide cards -->
    
    <?php if(isset($_GET['success'])): ?>
    <div class="alert alert-success d-flex align-items-center mb-4 rounded-3 border-0 shadow-sm">
        <i data-feather="check-circle" class="me-2"></i> Handover safely acknowledged. You have the conn!
    </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <a href="<?php echo (defined('APP_BASE_PATH') ? APP_BASE_PATH : '/'); ?>?route=index_shr" class="text-decoration-none text-muted small fw-bold"><i data-feather="arrow-left" style="width:14px;"></i> GO BACK</a>
            
            <h5 class="fw-bold m-0 mt-1 text-dark"><?= htmlspecialchars($formatted_header) ?></h5>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <?php if ($shr['status'] === 'Completed'): ?>
                <span class="badge bg-success border px-3 py-2 fs-6"><i data-feather="check" style="width:14px;"></i> Acknowledged</span>
            <?php else: ?>
                <!-- Edit button for the creator (Top Right) -->
                <?php if ($logged_in_username === $shr['created_by_username']): ?>
                    <a href="<?php echo (defined('APP_BASE_PATH') ? APP_BASE_PATH : '/'); ?>?route=create_shr&id=<?= $id ?>" class="btn btn-sm btn-outline-primary fw-bold d-flex align-items-center shadow-sm bg-white">
                        <i data-feather="edit-2" style="width:14px;" class="me-1"></i> Edit
                    </a>
                <?php endif; ?>
                <span class="badge bg-warning text-dark border px-3 py-2 fs-6"><i data-feather="clock" style="width:14px;"></i> Pending Acknowledgement</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($shr['status'] !== 'Completed'): ?>
        <?php if ($logged_in_username === $shr['acknowledged_by_username']): ?>
            <form method="POST" class="mb-4" onsubmit="preventDoubleSubmit(this);">
                <input type="hidden" name="action" value="acknowledge">
                <button type="submit" id="ackSubmitBtn" class="btn-success-pro">
                    <i data-feather="check-square" class="me-2"></i> I have reviewed the handover and accept the queue
                </button>
            </form>

            <script>
            function preventDoubleSubmit(form) {
                const btn = form.querySelector('#ackSubmitBtn');
                btn.disabled = true;
                btn.style.opacity = '0.75';
                btn.style.cursor = 'not-allowed';
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Processing acknowledgment...';
            }
            </script>
        <?php else: ?>
            <div class="alert alert-warning text-center border-0 shadow-sm mb-4">
                Awaiting acknowledgment from intended recipient: <strong><?= htmlspecialchars($shr['acknowledged_by_name']) ?></strong>.
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Grid Layout exclusively for the Sidebar and Summary Table -->
    <div class="row g-4 mb-4">
        
        <div class="col-lg-3 order-lg-2">
            <div class="modern-card mb-4">
                <div class="modern-card-header text-primary"><i data-feather="log-out" style="width:18px;"></i> Outgoing Shift</div>
                <div class="card-body p-3 bg-light">
                    <div class="text-muted text-uppercase small fw-bold mb-1">SHR By</div>
                    <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($shr['created_by_name']) ?></div>
                    <div class="mt-3 text-muted text-uppercase small fw-bold mb-1">Shift Details</div>
                    <div class="text-dark fw-medium"><?= htmlspecialchars($shr['shift_type']) ?> <br> <?= date('M d, Y', strtotime($shr['shift_date'])) ?></div>
                    <div class="text-muted small mt-1">At <?= date('h:i A', strtotime($shr['created_at'])) ?></div>
                </div>
            </div>

            <div class="modern-card mb-0">
                <div class="modern-card-header text-success"><i data-feather="log-in" style="width:18px;"></i> Incoming Shift</div>
                <div class="card-body p-3 bg-light">
                    <div class="text-muted text-uppercase small fw-bold mb-1">Handed Over To</div>
                    <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($shr['acknowledged_by_name']) ?></div>
                    <div class="text-dark fw-medium small mb-3">Time: <?= date('M d, Y - h:i A', strtotime($shr['created_at'])) ?></div>

                    <div class="text-muted text-uppercase small fw-bold mb-1 border-top pt-3">Acknowledgment</div>
                    <?php if ($shr['status'] === 'Completed'): ?>
                        <div class="fw-bold text-success fs-6"><i data-feather="check-circle" style="width:14px;"></i> Acknowledged At</div>
                        <div class="text-dark fw-medium mt-1"><?= date('M d, Y - h:i A', strtotime($shr['acknowledged_at'])) ?></div>
                    <?php else: ?>
                        <div class="text-warning-emphasis fw-bold small"><i data-feather="clock" style="width:14px;"></i> Pending acknowledgment...</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-9 order-lg-1">
            
            <?php if (!empty($summary)): ?>
            <div class="modern-card mb-0">
                <div class="modern-card-header text-dark">
                    <i data-feather="grid" style="width:18px;" class="me-2"></i> SHR Summary
                </div>
                <div class="table-responsive" style="border-radius: 0; overflow: hidden;">
                    <table class="summary-table mb-0 border-0">
                        <tr>
                            <th class="text-start px-3 bg-light text-primary" style="width: 25%;">Shift Summary</th>
                            <td colspan="5" class="p-3 fs-6">
                                <div class="row text-start m-0 w-100">
                                    <div class="col-md-5 mb-2" style="color: #d97706;">
                                        Previous Pending: <span class="fw-bold"><?= htmlspecialchars($metrics['received'] ?? '0') ?></span>
                                    </div>
                                    <div class="col-md-7 mb-2" style="color: #dc2626;">
                                        Pending Handover: <span class="fw-bold"><?= htmlspecialchars($metrics['pending'] ?? '0') ?></span>
                                    </div>
                                    <div class="col-md-5 mb-0" style="color: #0284c7;">
                                        Total Tickets I Worked: <span class="fw-bold"><?= htmlspecialchars($metrics['worked'] ?? '0') ?></span>
                                    </div>
                                    <div class="col-md-7 mb-0" style="color: #16a34a;">
                                        Closed: <span class="fw-bold"><?= htmlspecialchars($metrics['closed'] ?? '0') ?></span>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th class="text-start px-3 bg-light">Major Events</th>
                            <td class="text-center fw-bold" style="width: 10%;"><?= htmlspecialchars($summary['major_events']['count']) ?></td>
                            <th class="text-center bg-light" style="width: 10%;">BRIEF:</th>
                            <td><?= htmlspecialchars($summary['major_events']['brief']) ?></td>
                            <th class="text-center bg-light" style="width: 10%;">STATUS:</th>
                            <td style="width: 15%;" class="fw-medium"><?= htmlspecialchars($summary['major_events']['status']) ?></td>
                        </tr>
                        <tr>
                            <th class="text-start px-3 bg-light">High Priority Incidents</th>
                            <td class="text-center fw-bold"><?= htmlspecialchars($summary['high_priority']['count']) ?></td>
                            <th class="text-center bg-light">BRIEF:</th>
                            <td><?= htmlspecialchars($summary['high_priority']['brief']) ?></td>
                            <th class="text-center bg-light">STATUS:</th>
                            <td class="fw-medium"><?= htmlspecialchars($summary['high_priority']['status']) ?></td>
                        </tr>
                        <tr>
                            <th class="text-start px-3 bg-light">SLA Risks (Not Meeting 2Hrs)</th>
                            <td class="text-center fw-bold text-danger"><?= htmlspecialchars($summary['sla_risks']['count']) ?></td>
                            <th class="text-center bg-light">BRIEF:</th>
                            <td><?= htmlspecialchars($summary['sla_risks']['brief']) ?></td>
                            <th class="text-center bg-light">STATUS:</th>
                            <td class="fw-medium text-danger"><?= htmlspecialchars($summary['sla_risks']['status']) ?></td>
                        </tr>
                        <tr>
                            <th class="text-start px-3 bg-light">Infrastructure Health</th>
                            <td class="text-center fw-bold <?= $summary['infra_health']['status'] === 'Needs Attention' ? 'text-danger' : 'text-success' ?>">
                                <?= htmlspecialchars($summary['infra_health']['status']) ?>
                            </td>
                            <th class="text-center bg-light">COMMENTS:</th>
                            <td colspan="3"><?= htmlspecialchars($summary['infra_health']['comments']) ?></td>
                        </tr>
                        
                        <?php 
                            $prioTickets = array_filter($tickets, fn($t) => isset($t['is_priority']) && $t['is_priority']);
                        ?>
                        <tr class="bg-yellow border-dark">
                            <th class="text-start px-3 border-dark text-dark">Next Shift Priorities</th>
                            <td class="text-center fw-bold fs-6 border-dark text-dark"><?= count($prioTickets) ?></td>
                            <th class="text-center border-dark text-dark">COMMENTS:</th>
                            <td colspan="3" class="fw-bold border-dark">
                                <?php if (empty($prioTickets)): ?>
                                    NA
                                <?php else: ?>
                                    <ol class="mb-0 ps-3" style="font-family: monospace; line-height: 1.6;">
                                    <?php foreach ($prioTickets as $pt): 
                                        $comment = !empty(trim($pt['priority_comment'])) ? htmlspecialchars(trim($pt['priority_comment'])) : '';
                                        if (empty($comment) && !empty($pt['priority_history'])) {
                                            $last_hist = end($pt['priority_history']);
                                            $comment = htmlspecialchars($last_hist['comment']);
                                        }
                                        $comment = $comment ?: 'NA';
                                    ?>
                                        <li><a href="#ticket-<?= $pt['id'] ?>" class="text-decoration-none" style="color: #854d0e;"><b><?= $pt['id'] ?></b></a>: <?= $comment ?></li>
                                    <?php endforeach; ?>
                                    </ol>
                                <?php endif; ?>
                            </td>
                        </tr>

                        <tr class="bg-yellow text-red border-dark">
                            <th class="text-start px-3 border-dark">Escalations</th>
                            <td class="text-center border-dark"><?= htmlspecialchars($summary['escalations']['count']) ?></td>
                            <th class="text-center border-dark">COMMENTS:</th>
                            <td colspan="3" class="border-dark"><?= htmlspecialchars($summary['escalations']['comments']) ?></td>
                        </tr>
                        <tr>
                            <th class="text-start px-3 bg-light">Any Oral Updates</th>
                            <td colspan="5" style="white-space: pre-wrap; font-family: monospace;"><?= htmlspecialchars($summary['oral_updates'] ?: 'NA') ?></td>
                        </tr>
                        <tr>
                            <th class="text-start px-3 bg-light">Other Information</th>
                            <td colspan="5"><?= htmlspecialchars($summary['attachments'] ?: 'NA') ?></td>
                        </tr>
                        <tr>
                            <th class="text-start px-3 bg-light">Oral Handover Given To</th>
                            <td colspan="5" class="fw-bold text-primary fs-6"><?= htmlspecialchars($summary['handover_to'] ?: 'NA') ?></td>
                        </tr>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div> <!-- Close Column/Grid Layout -->

    <!-- FULL WIDTH TICKET CARDS BELOW GRID -->
    <?php 
        $closed_tickets = array_filter($tickets, fn($t) => in_array($t['status'], ['Solved', 'Closed']));
        $pending_tickets = array_filter($tickets, fn($t) => !in_array($t['status'], ['Solved', 'Closed']) && empty($t['is_priority']));
    ?>

    <?php if(!empty($closed_tickets)): ?>
    <div class="modern-card mt-4 mb-4">
        <div class="modern-card-header text-success border-success-subtle bg-success-subtle">
            <span><i data-feather="check-circle" style="width:18px;" class="me-2"></i> Closed Tickets Today (By <?= htmlspecialchars($shr['created_by_name']) ?>)</span>
        </div>
        <div class="card-body p-4 bg-light">
            <?php foreach ($closed_tickets as $t): 
                $prioLabel = [1 => 'Very Low', 2 => 'Low', 3 => 'Medium', 4 => 'High', 5 => 'Very High', 6 => 'Major'][$t['priority']] ?? 'Medium';
                $isCrit = $t['priority'] >= 4;
            ?>
                <div class="ticket-row bg-solved d-flex justify-content-between align-items-center">
                    <div>
                        <div class="d-flex align-items-center mb-1">
                            <span class="fw-bold text-success me-2"><?= htmlspecialchars($t['id']) ?></span>
                            <span class="fw-medium text-dark"><?= htmlspecialchars($t['title']) ?></span>
                        </div>
                        <div class="text-muted small">
                            <i data-feather="map-pin" style="width:12px;" class="me-1"></i><?= htmlspecialchars($t['location'] ?? 'N/A') ?>
                        </div>
                    </div>
                    <div class="text-end">
                        <span class="badge bg-success border border-success d-block mb-1"><?= htmlspecialchars($t['status']) ?></span>
                        <span class="badge <?= $isCrit ? 'bg-danger' : 'bg-secondary' ?>"><?= htmlspecialchars($prioLabel) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if(!empty($prioTickets)): ?>
    <div class="modern-card mt-4 mb-4">
        <div class="modern-card-header text-warning-emphasis bg-warning-subtle border-warning-subtle">
            <span><i data-feather="alert-circle" style="width:18px;" class="me-2"></i> Next Shift Priorities</span>
        </div>
        <div class="card-body p-4 bg-light">
            <?php foreach ($prioTickets as $t): 
                $prioLabel = [1 => 'Very Low', 2 => 'Low', 3 => 'Medium', 4 => 'High', 5 => 'Very High', 6 => 'Major'][$t['priority']] ?? 'Medium';
                $isCrit = $t['priority'] >= 4;
            ?>
                <div id="ticket-<?= $t['id'] ?>" class="ticket-row bg-priority d-flex justify-content-between align-items-center" style="scroll-margin-top: 100px;">
                    <div class="w-100 me-3">
                        <div class="d-flex align-items-center mb-1">
                            <span class="fw-bold text-dark me-2 fs-6"><?= htmlspecialchars($t['id']) ?></span>
                            <span class="fw-bold text-dark fs-6"><?= htmlspecialchars($t['title']) ?></span>
                        </div>
                        <div class="text-muted small mb-2">
                            <i data-feather="map-pin" style="width:12px;" class="me-1"></i><?= htmlspecialchars($t['location'] ?? 'N/A') ?>
                        </div>
                        
                        <!-- Tracked History Render -->
                        <?php if (!empty($t['priority_history'])): ?>
                            <div class="mt-2 mb-2 p-2 bg-white border border-warning-subtle rounded shadow-sm" style="max-height: 150px; overflow-y: auto; font-size: 0.8rem;">
                                <?php foreach ($t['priority_history'] as $h): ?>
                                    <div class="mb-1 pb-1 border-bottom">
                                        <strong class="text-primary"><?= htmlspecialchars($h['user']) ?></strong> 
                                        <span class="text-muted" style="font-size:0.75rem;">(<?= htmlspecialchars($h['time']) ?>)</span><br>
                                        <span class="text-dark"><?= htmlspecialchars($h['comment']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Current Priority Comment appended if written -->
                        <?php if (!empty(trim($t['priority_comment']))): ?>
                            <div class="fw-bold mt-1" style="color: #a16207; font-family: monospace; font-size: 0.95rem;">> <?= htmlspecialchars($t['priority_comment']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="text-end flex-shrink-0">
                        <div class="mb-1">
                            <span class="badge bg-light text-dark border"><?= htmlspecialchars($t['status']) ?></span>
                        </div>
                        <div class="mb-1">
                            <span class="badge <?= $isCrit ? 'bg-danger' : 'bg-secondary' ?>"><?= htmlspecialchars($prioLabel) ?></span>
                        </div>
                        <div class="text-muted small mt-1">Assigned: <?= htmlspecialchars($t['assigned_to']) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="modern-card mb-4 mt-4">
        <div class="modern-card-header text-secondary">
            <span><i data-feather="inbox" style="width:18px;" class="me-2"></i> Handover Ticket Queue</span>
            <span class="badge bg-secondary ms-auto"><?= count(array_filter($tickets, fn($t) => !in_array($t['status'], ['Solved', 'Closed']))) ?> Tickets Total</span>
        </div>
        <div class="card-body p-4 bg-light">
            <?php if (empty($pending_tickets) && empty($prioTickets)): ?>
                <div class="text-center py-5 text-muted">No tickets found in the queue at the time of handover.</div>
            <?php elseif(empty($pending_tickets) && !empty($prioTickets)): ?>
                    <div class="text-muted small fst-italic">All pending tickets are marked as priorities. See the card above.</div>
            <?php else: ?>
                <div class="table-responsive border rounded bg-white">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.9rem;">
                        <thead class="table-light text-muted">
                            <tr>
                                <th>Ticket No.</th>
                                <th>Title</th>
                                <th>Location</th>
                                <th>Assigned To</th>
                                <th>Status</th>
                                <th>Priority</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_tickets as $t): 
                                $isCrit = $t['priority'] >= 4;
                                $prioLabel = [1 => 'Very Low', 2 => 'Low', 3 => 'Medium', 4 => 'High', 5 => 'Very High', 6 => 'Major'][$t['priority']] ?? 'Medium';
                            ?>
                                <tr class="<?= $isCrit ? 'table-danger' : '' ?>">
                                    <td class="fw-bold text-primary"><?= htmlspecialchars($t['id']) ?></td>
                                    <td class="fw-medium text-dark"><?= htmlspecialchars($t['title']) ?></td>
                                    <td><?= htmlspecialchars($t['location'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($t['assigned_to']) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($t['status']) ?></span></td>
                                    <td>
                                        <?php if ($isCrit): ?>
                                            <span class="badge bg-danger"><?= htmlspecialchars($prioLabel) ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><?= htmlspecialchars($prioLabel) ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>document.addEventListener('DOMContentLoaded', () => { if(typeof feather !== 'undefined') feather.replace(); });</script>
<?php include_once '../includes/footer.php'; ?>