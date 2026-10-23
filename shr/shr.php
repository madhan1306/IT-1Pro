<?php
// FILE: shr/shr.php
date_default_timezone_set('Asia/Kolkata');
if (session_status() === PHP_SESSION_NONE) { session_start(); }

include_once '../includes/db_connect.php';
include_once '../includes/auth.php';
include_once '../includes/email_config.php'; // Required for resending emails

checkPermission('view_shr');

// Check if the current user has the resend permission or is a Super Admin
$can_resend = can('resend_email');

// --- AJAX ENDPOINT: RESEND EMAIL (REMINDER OR SUMMARY) ---
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'resend_email') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    try {
        // Backend Security Check
        if (!$can_resend) {
            throw new Exception("You do not have the required permission (resend_email) to perform this action.");
        }

        $id = (int)$_POST['id'];
        
        $stmt = $conn->prepare("SELECT * FROM shift_handovers WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $shr = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$shr) throw new Exception("Handover record not found.");
        if (!function_exists('sendSHREmail')) throw new Exception("Email configuration is missing on the server.");

        // Get Receiver Email (Incoming Tech)
        $receiverEmail = '';
        if (function_exists('getUserEmail')) {
            $receiverEmail = getUserEmail($conn, $shr['acknowledged_by_username']);
        }
        if (empty($receiverEmail)) {
            throw new Exception("Recipient (" . htmlspecialchars($shr['acknowledged_by_username']) . ") does not have a valid email address configured.");
        }

        // Get Creator Email (Outgoing Tech - for CC in Completed emails)
        $creatorEmail = '';
        if (function_exists('getUserEmail')) {
            $creatorEmail = getUserEmail($conn, $shr['created_by_username']);
        }

        // Reconstruct Email Details
        preg_match('/\(([^-\)]+)/i', $shr['shift_type'], $matches);
        $shift_time_email = isset($matches[1]) ? trim($matches[1]) : '';
        $formatted_date_email = date('d-M-Y', strtotime($shr['shift_date']));

        $creator_name_parts = explode(' ', trim($shr['created_by_name']));
        $first_name = $creator_name_parts[0];
        $last_initial = (count($creator_name_parts) > 1) ? strtoupper(substr(end($creator_name_parts), 0, 1)) : '';
        $tech_formatted = trim($first_name . ($last_initial ? " $last_initial" : ""));

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $domainName = $_SERVER['HTTP_HOST'];
        $base_path = defined('APP_BASE_PATH') ? APP_BASE_PATH : '/';
        $view_link = $protocol . $domainName . $base_path . "?route=view_shr&id=" . $id;

        // Generate different emails based on the current status
        if ($shr['status'] === 'Completed') {
            // Exact same payload parsing as view.php
            $parsed_data = json_decode($shr['glpi_snapshot_json'], true) ?: [];
            $sum = $parsed_data['summary'] ?? [];
            $met = $parsed_data['metrics'] ?? ['received'=>0, 'closed'=>0, 'pending'=>0, 'worked'=>0];
            $tickets = $parsed_data['tickets'] ?? [];
            $daily_no = $shr['daily_shift_no'] ?? 1;
            $val = function($input) { return !empty(trim($input)) ? htmlspecialchars(trim($input)) : 'NA'; };

            $prioTickets = array_filter($tickets, fn($t) => isset($t['is_priority']) && $t['is_priority']);
            $prioHtml = "";
            if (!empty($prioTickets)) {
                $prioHtml = "<ol style='padding-left: 20px; margin: 0;'>";
                foreach ($prioTickets as $pt) {
                    $comment = !empty(trim($pt['priority_comment'])) ? htmlspecialchars(trim($pt['priority_comment'])) : 'NA';
                    $prioHtml .= "<li><b>Ticket #{$pt['id']}</b>: {$comment}</li>";
                }
                $prioHtml .= "</ol>";
            } else {
                $prioHtml = "NA";
            }
            
            $ack_date_time = date('d-M-Y h:i A', strtotime($shr['acknowledged_at']));
            
            // Header exactly matching view.php
            $subject = "IT-SHR - {$tech_formatted} | {$formatted_date_email} | {$shift_time_email} | {$daily_no}";

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
        } else {
            // Keep the standard Reminder email if it's still pending
            $subject = "IT-SHR - {$tech_formatted} | {$formatted_date_email} | {$shift_time_email} | Awaiting Acknowledgement (Reminder)";
            $emailHTML = "
            <div style='font-family: Arial, sans-serif; color: #333; line-height: 1.6;'>
                <div style='background-color: #f8fafc; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0;'>
                    <p>Hi <strong>" . htmlspecialchars($shr['acknowledged_by_name']) . "</strong>,</p><br>
                    <p>This is a reminder that <strong>" . htmlspecialchars($shr['created_by_name']) . "</strong> has submitted the shift handover and it is currently awaiting your acknowledgement.</p>
                    <p>Kindly review the handover report and accept the queue to successfully take over the shift operations.</p>
                    
                    <div style='margin: 30px 0;'>
                        <a href='{$view_link}' style='background-color: #0f766e; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;'>View & Acknowledge Handover</a>
                    </div>
                    
                    -<br>
                    <strong>IT Support</strong><br>
                    Sun Technologies<br>
                    <span style='font-size: 15px; color: #94a3b8;'>(This is an autogenerated Email Triggered from IT-One)</span>
                </div>
            </div>";
        }

        // --- STRICT EMAIL ERROR CATCHING ---
        ob_start(); 
        $mail_result = false;
        
        try {
            // Note: If 'Completed', send CC to the Creator just like view.php
            if ($shr['status'] === 'Completed') {
                $mail_result = sendSHREmail($receiverEmail, $shr['acknowledged_by_name'], $creatorEmail, $shr['created_by_name'], $emailHTML, $subject);
            } else {
                $mail_result = sendSHREmail($receiverEmail, $shr['acknowledged_by_name'], '', '', $emailHTML, $subject, false);
            }
        } catch (Throwable $mail_ex) {
            ob_end_clean();
            throw new Exception("Mail Exception: " . $mail_ex->getMessage());
        }
        
        $hidden_errors = trim(ob_get_clean()); 

        if ($mail_result === false) {
            throw new Exception("Mail server rejected the request. " . strip_tags($hidden_errors));
        } elseif (is_string($mail_result) && (stripos($mail_result, 'error') !== false || stripos($mail_result, 'fail') !== false)) {
            throw new Exception("SMTP Error: " . strip_tags($mail_result));
        } elseif (!empty($hidden_errors)) {
            throw new Exception("Mail Script Warning: " . strip_tags($hidden_errors));
        }

        echo json_encode(['status' => 'success', 'message' => 'Notification email sent successfully.']);
    } catch (Throwable $e) {
        error_log("SHR Email Resend Failed: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}
// -----------------------------------

$pageTitle = "Shift Handover Reports";
include_once '../includes/header.php';

$handovers = [];
$stmt = $conn->prepare("SELECT * FROM shift_handovers ORDER BY shift_date DESC, created_at DESC LIMIT 500");
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

// Align the dashboard "Today" with the 10 AM cutoff
$current_hour = (int)date('H');
$today_date = ($current_hour < 10) ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');

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
    .status-badge { padding: 3px 8px; border-radius: 12px; font-size: 0.65rem; font-weight: 600; text-transform: uppercase; display: inline-block; }
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
    .resend-btn { transition: all 0.2s; }
    .resend-btn:hover { background-color: #e2e8f0; }
    
    /* Toast Styles */
    #custom-toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; }
    .custom-toast { background: #fff; border-left: 5px solid #0f766e; box-shadow: 0 4px 12px rgba(0,0,0,0.15); padding: 15px 20px; border-radius: 4px; margin-bottom: 10px; opacity: 0; transform: translateX(100%); transition: all 0.3s ease-in-out; display: flex; align-items: center; }
    .custom-toast.show { opacity: 1; transform: translateX(0); }
    .custom-toast.toast-error { border-left-color: #dc2626; }
</style>

<!-- Toast Container -->
<div id="custom-toast-container"></div>

<div class="container-xl py-3">
    
    <div class="modern-card p-3 mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h5 class="fw-bold m-0 text-dark">Shift Handover Reports</h5>
                <div class="text-muted" style="font-size: 0.75rem;">24/7 Support Desk Logs</div>
            </div>
            
            <div class="d-flex flex-wrap align-items-center gap-2" style="flex: 1; max-width: 650px;">
                <div class="position-relative flex-grow-1">
                    <i data-feather="search" class="position-absolute text-muted" style="width: 14px; top: 50%; left: 10px; transform: translateY(-50%); pointer-events: none;"></i>
                    <input type="text" id="globalSearch" class="form-control form-control-sm" placeholder="Search SHR No, Date, Names, Status..." style="border-radius: 6px; padding-left: 32px;">
                </div>
                <button class="btn btn-white btn-sm border shadow-sm text-dark fw-medium d-flex align-items-center" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                    <i data-feather="filter" style="width:14px;" class="me-1 text-primary"></i> Filters
                </button>
                <a href="<?php echo (defined('APP_BASE_PATH') ? APP_BASE_PATH : '/'); ?>?route=create_shr" class="btn btn-primary-pro btn-sm d-flex align-items-center shadow-sm">
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
                        <tr id="noDataRaw"><td colspan="9" class="text-center py-5 text-muted"><i data-feather="inbox" class="mb-2" style="width:24px;height:24px;opacity:0.5;"></i><br><span style="font-size: 0.8rem;">No handovers found.</span></td></tr>
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
                                onclick="window.location='<?php echo (defined('APP_BASE_PATH') ? APP_BASE_PATH : '/'); ?>?route=view_shr&id=<?= $shr['id'] ?>'">
                                
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
                                        <div class="d-flex align-items-center">
                                            <span class="status-badge bg-success-subtle text-success border border-success-subtle">Completed</span>
                                            <?php if ($can_resend): ?>
                                            <button type="button" class="btn btn-sm btn-light border ms-2 rounded p-1 resend-btn d-flex align-items-center justify-content-center" onclick="event.stopPropagation(); triggerResend(<?= $shr['id'] ?>, this);" title="Resend Summary Email">
                                                <i data-feather="mail" style="width:14px; height:14px; color:#0f766e;"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="d-flex align-items-center">
                                            <span class="status-badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Pending Ack</span>
                                            <?php if ($can_resend): ?>
                                            <button type="button" class="btn btn-sm btn-light border ms-2 rounded p-1 resend-btn d-flex align-items-center justify-content-center" onclick="event.stopPropagation(); triggerResend(<?= $shr['id'] ?>, this);" title="Resend Reminder Email">
                                                <i data-feather="mail" style="width:14px; height:14px; color:#b45309;"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
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
function showToast(message, type = 'success') {
    const container = document.getElementById('custom-toast-container');
    const toast = document.createElement('div');
    toast.className = `custom-toast toast-${type}`;
    const icon = type === 'success' ? 'check-circle' : 'alert-circle';
    toast.innerHTML = `<i data-feather="${icon}" class="me-2" style="width:18px;"></i> ${message}`;
    container.appendChild(toast);
    
    if(typeof feather !== 'undefined') feather.replace();

    setTimeout(() => { toast.classList.add('show'); }, 10);
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

// Function to handle the Resend button click
function triggerResend(shrId, btnElement) {
    const originalHtml = btnElement.innerHTML;
    btnElement.disabled = true;
    btnElement.innerHTML = '<span class="spinner-border spinner-border-sm" style="width: 14px; height: 14px; color:#0f766e;"></span>';

    const fd = new FormData();
    fd.append('ajax_action', 'resend_email');
    fd.append('id', shrId);

    fetch('<?php echo (defined("APP_BASE_PATH") ? APP_BASE_PATH : "/"); ?>?route=index_shr', {
        method: 'POST',
        body: fd
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            showToast(data.message, 'success');
        } else {
            showToast('Failed to resend: ' + data.message, 'error');
        }
    })
    .catch(err => {
        console.error(err);
        showToast('Network error while resending notification.', 'error');
    })
    .finally(() => {
        btnElement.disabled = false;
        btnElement.innerHTML = originalHtml;
        if(typeof feather !== 'undefined') feather.replace();
    });
}

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

    // Always auto-expand the most recent available date block
    let expandedDates = new Set();
    if (dateHeaders.length > 0) {
        expandedDates.add(dateHeaders[0].dataset.date);
    }

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
        
        // Reset and auto-expand the most recent date again
        expandedDates = new Set();
        if (dateHeaders.length > 0) expandedDates.add(dateHeaders[0].dataset.date);
        
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