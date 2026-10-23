<?php
// FILE: shr/create.php

date_default_timezone_set('Asia/Kolkata');
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

include_once '../includes/db_connect.php';
include_once '../includes/auth.php';
include_once '../includes/api.php'; 
@include_once '../includes/email_config.php'; 

checkPermission('view_shr');

$logged_in_username = $_SESSION['username'];
$logged_in_user_fullname = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')) ?: $logged_in_username;

// --- EDIT MODE LOGIC ---
$edit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$edit_data = null;
if ($edit_id > 0) {
    $stmt_edit = $conn->prepare("SELECT * FROM shift_handovers WHERE id = ? AND created_by_username = ? AND status = 'Pending Ack'");
    $stmt_edit->bind_param("is", $edit_id, $logged_in_username);
    $stmt_edit->execute();
    $edit_data = $stmt_edit->get_result()->fetch_assoc();
    $stmt_edit->close();
    
    if (!$edit_data) {
        die("Invalid request. The handover report was not found, has already been acknowledged, or you do not have permission to edit it.");
    }
}

// --- FETCH SHIFT OWNERS FOR DROPDOWN ---
$shift_owners = [];
$so_stmt = $conn->prepare("SELECT DISTINCT u.username, u.firstname, u.lastname FROM users u JOIN roles r ON u.role_id = r.id JOIN role_permissions rp ON r.id = rp.role_id JOIN permissions p ON rp.permission_id = p.id WHERE p.action_key = 'shift_owner' ORDER BY u.firstname ASC");
if ($so_stmt) {
    $so_stmt->execute();
    $res_so = $so_stmt->get_result();
    while ($row = $res_so->fetch_assoc()) {
        $so_name = trim($row['firstname'] . ' ' . $row['lastname']);
        $shift_owners[$row['username']] = !empty($so_name) ? $so_name : $row['username'];
    }
    $so_stmt->close();
}

// --- FETCH KB ARTICLES WITH CATEGORIES ---
$kb_articles = [];
$kb_stmt = $conn->query("
    SELECT k.id, k.title, c.name as category_name 
    FROM kb_articles k 
    LEFT JOIN kb_categories c ON k.category_id = c.id 
    WHERE k.status = 'Published' 
    ORDER BY c.name ASC, k.title ASC
");
if ($kb_stmt) {
    while ($row = $kb_stmt->fetch_assoc()) {
        $kb_articles[] = $row;
    }
    $kb_stmt->close();
}

// --- AJAX ENDPOINTS ---
if (isset($_POST['ajax_action'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    if ($_POST['ajax_action'] === 'fetch_glpi_tickets') {
        try {
            $token = initGLPISession();
            if (!$token) throw new Exception("GLPI API Authentication Failed. Check API credentials.");

            $shift_type = $_POST['shift_type'] ?? '';

// 1. Extract times from the shift string (e.g., "6 AM - 3 PM" or "5:30 PM - 2:30 AM")
preg_match('/\((.*?)\s*-\s*(.*?)\)/', $shift_type, $times);
$start_str = $times[1] ?? '00:00:00';
$end_str = $times[2] ?? '23:59:59';

// 2. Build the shift window assuming it started Today
$base_date = date('Y-m-d');
$shift_start_ts = strtotime("$base_date $start_str");
$shift_end_ts = strtotime("$base_date $end_str");

if ($shift_end_ts < $shift_start_ts) {
    $shift_end_ts = strtotime('+1 day', $shift_end_ts); // Shift crosses midnight
}

// 3. SMART DATE DETECTION for all shifts
// If the current time is way before today's shift even starts, the tech is submitting a late handover for Yesterday.
if (time() < strtotime('-2 hours', $shift_start_ts)) {
    $base_date = date('Y-m-d', strtotime('-1 day'));
    $shift_start_ts = strtotime("$base_date $start_str");
    $shift_end_ts = strtotime("$base_date $end_str");
    if ($shift_end_ts < $shift_start_ts) {
        $shift_end_ts = strtotime('+1 day', $shift_end_ts);
    }
}

// 4. Add buffers: 1 hour for early logins, 4 hours for late SHR submissions
$window_start = strtotime('-1 hour', $shift_start_ts);
$window_end = strtotime('+4 hours', $shift_end_ts);

// 5. Evaluate GLPI ticket timestamps against this exact window
$is_valid_shift_time = function($date_string) use ($window_start, $window_end) {
    if (empty($date_string)) return false;
    $ticket_time = strtotime($date_string);
    return ($ticket_time >= $window_start && $ticket_time <= $window_end);
};

            $stmt_prev = $conn->prepare("SELECT glpi_snapshot_json FROM shift_handovers ORDER BY id DESC LIMIT 1");
            $received_count = 0;
            $prev_priority_tickets = []; // Array to track ongoing priorities
            
            if ($stmt_prev) {
                $stmt_prev->execute();
                $res_prev = $stmt_prev->get_result()->fetch_assoc();
                if ($res_prev && !empty($res_prev['glpi_snapshot_json'])) {
                    $prev_json = json_decode($res_prev['glpi_snapshot_json'], true);
                    $prev_tickets = $prev_json['tickets'] ?? []; 
                    if (is_array($prev_tickets)) {
                        foreach ($prev_tickets as $pt) {
                            if (isset($pt['status']) && !in_array($pt['status'], ['Solved', 'Closed'])) {
                                $received_count++;
                            }
                            // Capture previously marked priorities and their history
                            if (!empty($pt['is_priority']) && $pt['is_priority'] == true) {
                                $prev_priority_tickets[$pt['id']] = [
                                    'history' => $pt['priority_history'] ?? []
                                ];
                            }
                        }
                    }
                }
                $stmt_prev->close();
            }

            $url_users = "/search/User?forcedisplay[0]=1&forcedisplay[1]=2&range=0-9999";
            $res_users = callGLPI($url_users, 'GET', null, $token);
            $user_map = [];
            $users_data = $res_users['data']['data'] ?? $res_users['data'] ?? [];
            if (is_array($users_data)) {
                foreach ($users_data as $u) {
                    if (isset($u['2']) && isset($u['1'])) { $user_map[$u['2']] = strip_tags($u['1']); }
                }
            }

            $url_open = "/search/Ticket?criteria[0][field]=12&criteria[0][searchtype]=equals&criteria[0][value]=1"
                      . "&criteria[1][link]=OR&criteria[1][field]=12&criteria[1][searchtype]=equals&criteria[1][value]=2"
                      . "&criteria[2][link]=OR&criteria[2][field]=12&criteria[2][searchtype]=equals&criteria[2][value]=3"
                      . "&criteria[3][link]=OR&criteria[3][field]=12&criteria[3][searchtype]=equals&criteria[3][value]=4"
                      . "&forcedisplay[0]=1&forcedisplay[1]=2&forcedisplay[2]=5&forcedisplay[3]=3&forcedisplay[4]=12&forcedisplay[5]=64&forcedisplay[6]=19&forcedisplay[7]=83&range=0-1000";
            $res_open = callGLPI($url_open, 'GET', null, $token);

            $url_solved = "/search/Ticket?criteria[0][field]=12&criteria[0][searchtype]=equals&criteria[0][value]=5"
                        . "&criteria[1][link]=OR&criteria[1][field]=12&criteria[1][searchtype]=equals&criteria[1][value]=6"
                        . "&forcedisplay[0]=1&forcedisplay[1]=2&forcedisplay[2]=5&forcedisplay[3]=3&forcedisplay[4]=12"
                        . "&forcedisplay[5]=15&forcedisplay[6]=16&forcedisplay[7]=17&forcedisplay[8]=64&forcedisplay[9]=19&forcedisplay[10]=83&sort=15&order=DESC&range=0-500";
            $res_solved = callGLPI($url_solved, 'GET', null, $token);
            
            killGLPISession($token);

            $tickets = [];
            $seen_ids = [];
            $closed_count = 0;
            $pending_count = 0;
            $pending_internal = 0;
            $pending_user = 0;
            $worked_count = 0;

            $status_map = [ 1 => 'New', 2 => 'Processing (Assigned)', 3 => 'Processing (Planned)', 4 => 'Pending', 5 => 'Solved', 6 => 'Closed' ];

            $process_results = function($res_data, $is_solved_queue) use (&$tickets, &$seen_ids, &$closed_count, &$pending_count, &$pending_internal, &$pending_user, &$worked_count, $status_map, $user_map, $logged_in_username, $logged_in_user_fullname, $is_valid_shift_time, $prev_priority_tickets) {
                $glpi_data = $res_data['data']['data'] ?? $res_data['data'] ?? [];
                if (is_array($glpi_data) && !empty($glpi_data)) {
                    foreach ($glpi_data as $t) {
                        $ticket_id = $t['2'] ?? null;
                        if (!$ticket_id || in_array($ticket_id, $seen_ids)) continue;

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
                        $ticket_title = $t['1'] ?? 'No Subject';
                        $location = isset($t['83']) ? trim(strip_tags($t['83'])) : 'N/A';

                        $assigned_names = [];
                        if (!empty($t['5'])) {
                            $techs = is_array($t['5']) ? $t['5'] : explode(',', $t['5']);
                            foreach ($techs as $tech_id) {
                                $clean_id = trim(strip_tags($tech_id));
                                if ($clean_id) { $assigned_names[] = $user_map[$clean_id] ?? $clean_id; }
                            }
                        }
                        $assigned_to = !empty($assigned_names) ? implode(', ', $assigned_names) : 'Unassigned';

                        $updater_names = [];
                        if (!empty($t['64'])) {
                            $updaters = is_array($t['64']) ? $t['64'] : explode(',', $t['64']);
                            foreach ($updaters as $u_id) {
                                $clean_id = trim(strip_tags($u_id));
                                if ($clean_id) { $updater_names[] = $user_map[$clean_id] ?? $clean_id; }
                            }
                        }

                        $my_identifiers = array_filter([
                            strtolower(trim($logged_in_username)), 
                            strtolower(trim($logged_in_user_fullname))
                        ]);

                        $updated_by_me = false;
                        foreach ($updater_names as $name) {
                            $name_lower = strtolower(trim($name));
                            foreach ($my_identifiers as $my_id) {
                                if (strpos($name_lower, $my_id) !== false || strpos($my_id, $name_lower) !== false) {
                                    $updated_by_me = true;
                                    break 2;
                                }
                            }
                        }

                        $is_updated_valid = $is_valid_shift_time($t['19'] ?? '');

                        $assigned_to_me = false;
                        foreach ($assigned_names as $name) {
                            $name_lower = strtolower(trim($name));
                            foreach ($my_identifiers as $my_id) {
                                if (strpos($name_lower, $my_id) !== false || strpos($my_id, $name_lower) !== false) {
                                    $assigned_to_me = true;
                                    break 2;
                                }
                            }
                        }

                        $is_mine = $assigned_to_me || $updated_by_me;

                        if ($is_mine && $is_updated_valid) {
                            $worked_count++;
                        }

                        if ($is_solved_queue) {
                            if ($status_id < 5) continue; 
                            
                            $is_solve_valid = false;
                            if ($is_valid_shift_time($t['15'] ?? '')) $is_solve_valid = true; 
                            if ($is_valid_shift_time($t['16'] ?? '')) $is_solve_valid = true; 
                            if ($is_valid_shift_time($t['17'] ?? '')) $is_solve_valid = true; 
                            if ($is_valid_shift_time($t['19'] ?? '')) $is_solve_valid = true; 

                            if (!$is_solve_valid || !$is_mine) continue; 

                            $closed_count++;
                        } else {
                            if ($status_id >= 5) continue; 
                            $pending_count++;
                            
                            if (stripos($ticket_title, 'IT Internal') !== false) {
                                $pending_internal++;
                            } else {
                                $pending_user++;
                            }
                        }

                        $seen_ids[] = $ticket_id;
                        
                        // Auto-assign priority if carried over from previous shift and still open
                        $is_prio = false;
                        $prio_comment = '';
                        $prio_history = [];
                        
                        if (!$is_solved_queue && isset($prev_priority_tickets[$ticket_id])) {
                            $is_prio = true;
                            // Explicitly force a blank comment so the incoming tech MUST fill it
                            $prio_comment = ''; 
                            $prio_history = $prev_priority_tickets[$ticket_id]['history'];
                        }

                        $tickets[] = [
                            'id' => $ticket_id,
                            'title' => $ticket_title,
                            'location' => $location,
                            'priority' => (int)($t['3'] ?? 3), 
                            'assigned_to' => $assigned_to,
                            'status' => $status_text,
                            'is_priority' => $is_prio,
                            'priority_comment' => $prio_comment,
                            'priority_history' => $prio_history,
                            'linked_kb_id' => '',
                            'user_department' => ''
                        ];
                    }
                }
            };

            $process_results($res_open, false);
            $process_results($res_solved, true);
            
            usort($tickets, function($a, $b) {
                if ($a['priority'] == $b['priority']) return $b['id'] <=> $a['id'];
                return $b['priority'] <=> $a['priority'];
            });

            echo json_encode([
                'status' => 'success', 
                'metrics' => [
                    'received' => $received_count, 
                    'closed' => $closed_count,
                    'pending' => "User: $pending_user | Internal: $pending_internal",
                    'worked' => $worked_count
                ],
                'data' => $tickets
            ]);
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['ajax_action'] === 'save_handover') {
        try {
            $post_edit_id = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : 0;
            
            $pending_check_stmt = $conn->prepare("SELECT shr_no FROM shift_handovers WHERE status = 'Pending Ack' AND acknowledged_by_username = ?");
            $pending_check_stmt->bind_param("s", $logged_in_username);
            $pending_check_stmt->execute();
            if ($pending_check_stmt->get_result()->num_rows > 0) {
                throw new Exception("You have an outstanding incoming shift handover that you must acknowledge before submitting a new one.");
            }
            $pending_check_stmt->close();

            $shift_type = $_POST['shift_type'];
            $snapshot_json = $_POST['snapshot_json']; 
            $ho_username = $_POST['ho_username'];
            $ho_name = $_POST['ho_name'];
            
            if ((int)date('H') < 12) {
                $date = date('Y-m-d', strtotime('-1 day'));
            } else {
                $date = date('Y-m-d');
            }

            // --- Process Comment History Before Saving Snapshot ---
            $snapshot_data = json_decode($snapshot_json, true);
            $current_time = date('d-M-Y h:i A');
            if (isset($snapshot_data['tickets']) && is_array($snapshot_data['tickets'])) {
                foreach ($snapshot_data['tickets'] as &$kt) {
                    if (!empty($kt['is_priority'])) {
                        if (!isset($kt['priority_history'])) $kt['priority_history'] = [];
                        
                        $last_hist = end($kt['priority_history']);
                        $last_text = $last_hist ? $last_hist['comment'] : '';
                        $current_text = trim($kt['priority_comment'] ?? '');
                        
                        // Only append to history if the tech actually wrote something new/different
                        if ($current_text !== '' && $current_text !== $last_text) {
                            $kt['priority_history'][] = [
                                'user' => $logged_in_user_fullname,
                                'time' => $current_time,
                                'comment' => $current_text
                            ];
                        }
                    }
                }
            }
            // Re-encode snapshot with updated history array
            $snapshot_json = json_encode($snapshot_data);

            $insert_id = 0;

            if ($post_edit_id > 0) {
                $stmt = $conn->prepare("UPDATE shift_handovers SET shift_type = ?, glpi_snapshot_json = ?, acknowledged_by_username = ?, acknowledged_by_name = ? WHERE id = ? AND created_by_username = ? AND status = 'Pending Ack'");
                if (!$stmt) throw new Exception("Database preparation failure: " . $conn->error);
                
                $stmt->bind_param("ssssis", $shift_type, $snapshot_json, $ho_username, $ho_name, $post_edit_id, $logged_in_username);
                $stmt->execute();
                $stmt->close();
                $insert_id = $post_edit_id;

            } else {
                $stmt_shr = $conn->query("SELECT shr_no FROM shift_handovers WHERE shr_no LIKE 'SHR-%' ORDER BY CAST(SUBSTRING(shr_no, 5) AS UNSIGNED) DESC LIMIT 1");
                $next_shr_num = 1;
                if ($stmt_shr && $stmt_shr->num_rows > 0) {
                    $row_shr = $stmt_shr->fetch_assoc();
                    $last_num = (int)substr($row_shr['shr_no'], 4);
                    $next_shr_num = $last_num + 1;
                }
                $new_shr_no = 'SHR-' . str_pad($next_shr_num, 2, '0', STR_PAD_LEFT);

                $stmt_daily = $conn->prepare("SELECT MAX(daily_shift_no) as max_no FROM shift_handovers WHERE shift_date = ?");
                $stmt_daily->bind_param("s", $date);
                $stmt_daily->execute();
                $res_daily = $stmt_daily->get_result()->fetch_assoc();
                $next_daily_no = ($res_daily['max_no'] ?? 0) + 1;
                $stmt_daily->close();

                $stmt = $conn->prepare("INSERT INTO shift_handovers (shr_no, daily_shift_no, shift_type, shift_date, created_by_username, created_by_name, glpi_snapshot_json, status, acknowledged_by_username, acknowledged_by_name) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending Ack', ?, ?)");
                if (!$stmt) throw new Exception("Database preparation failure: " . $conn->error);
                
                $stmt->bind_param("sisssssss", $new_shr_no, $next_daily_no, $shift_type, $date, $logged_in_username, $logged_in_user_fullname, $snapshot_json, $ho_username, $ho_name);
                $stmt->execute();
                
                $insert_id = $conn->insert_id; 
                $stmt->close();
            }

            // --- PROCESS KB LINKS ---
            $stmt_kb_link = $conn->prepare("INSERT IGNORE INTO kb_ticket_links (kb_id, ticket_id, added_by_username, user_department) VALUES (?, ?, ?, ?)");
            foreach ($snapshot_data['tickets'] as $kt) {
                if (!empty($kt['linked_kb_id'])) {
                    $kb_id_to_link = (int)$kt['linked_kb_id'];
                    $t_id = trim($kt['id']);
                    $dept = isset($kt['user_department']) ? $kt['user_department'] : '';
                    $stmt_kb_link->bind_param("isss", $kb_id_to_link, $t_id, $logged_in_username, $dept);
                    $stmt_kb_link->execute();
                }
            }
            if ($stmt_kb_link) $stmt_kb_link->close();

            // --- EMAIL NOTIFICATION ---
            try {
                if (function_exists('sendSHREmail')) {
                    preg_match('/\(([^-\)]+)/i', $shift_type, $matches);
                    $shift_time_email = isset($matches[1]) ? trim($matches[1]) : '';
                    $formatted_date_email = date('d-M-Y', strtotime($date));

                    $creator_name_parts = explode(' ', trim($logged_in_user_fullname));
                    $first_name = $creator_name_parts[0];
                    $last_initial = (count($creator_name_parts) > 1) ? strtoupper(substr(end($creator_name_parts), 0, 1)) : '';
                    $tech_formatted = trim($first_name . ($last_initial ? " $last_initial" : ""));

                    $mode_text = ($post_edit_id > 0) ? "updated" : "submitted";
                    $subject = "IT-SHR - {$tech_formatted} | {$formatted_date_email} | {$shift_time_email} | Awaiting Acknowledgement";

                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
                    $domainName = $_SERVER['HTTP_HOST'];
                    $base_path = defined('APP_BASE_PATH') ? APP_BASE_PATH : '/';
                    $view_link = $protocol . $domainName . $base_path . "?route=view_shr&id=" . $insert_id;

                    $emailHTML = "
                    <div style='font-family: Arial, sans-serif; color: #333; line-height: 1.6;'>
                        <div style='background-color: #f8fafc; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0;'>
                            <p>Hi <strong>" . htmlspecialchars($ho_name) . "</strong>,</p><br>
                            <p><strong>" . htmlspecialchars($logged_in_user_fullname) . "</strong> has {$mode_text} the shift handover and it is currently awaiting your acknowledgement.</p>
                            <p>Kindly review the handover report and accept the queue to successfully take over the shift operations.</p>
                            <div style='margin: 30px 0;'>
                                <a href='{$view_link}' style='background-color: #0f766e; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;'>View & Acknowledge Handover</a>
                            </div>
                            -<br>
                            <strong>IT Support</strong><br>
                            Sun Technologies<br>
                        </div>
                    </div>";

                    if (function_exists('getUserEmail')) {
                        $receiverEmail = getUserEmail($conn, $ho_username);
                        sendSHREmail($receiverEmail, $ho_name, '', '', $emailHTML, $subject, false);
                    }
                }
            } catch (Throwable $mail_ex) {
                error_log("SHR Creation Email Failed: " . $mail_ex->getMessage());
            }

            echo json_encode(['status' => 'success', 'redirect' => (defined('APP_BASE_PATH') ? APP_BASE_PATH : '/') . "?route=index_shr&success=1"]);
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
}

$pageTitle = $edit_data ? "Edit Shift Handover" : "Create Shift Handover";
include_once '../includes/header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<!-- Tom Select CSS for easy search dropdowns -->
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">

<style>
    body { font-family: 'Inter', sans-serif; background-color: #f3f4f6 !important; }
    .modern-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border: 1px solid #e5e7eb; overflow: hidden; margin-bottom: 24px; }
    .modern-card-header { padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid #e5e7eb; font-weight: 600; color: #0f766e; }
    .form-label { font-weight: 600; font-size: 0.8rem; color: #475569; text-transform: uppercase; letter-spacing: 0.03em; }
    .form-control, .form-select { border-radius: 8px; font-size: 0.9rem; }
    .form-control:focus, .form-select:focus { border-color: #0f766e; box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1); }
    .btn-primary-pro { background-color: #0f766e; color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; transition: all 0.2s; }
    .btn-primary-pro:hover:not(:disabled) { background-color: #0d9488; color: white; }
    
    .ticket-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 10px; border-left: 4px solid #0f766e; }
    .ticket-box.critical { border-left-color: #dc2626; background: #fef2f2; }
    .ticket-box.solved { border-left-color: #10b981; background: #f0fdf4; }

    .summary-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    .summary-table th, .summary-table td { border: 1px solid #000; padding: 6px 10px; vertical-align: middle; }
    .bg-yellow { background-color: #fef08a; }
    .text-red { color: #dc2626; font-weight: bold; }
    .input-sm { border: 1px solid #ccc; border-radius: 4px; padding: 4px 6px; width: 100%; font-size: 0.85rem; }
    
    #custom-toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; }
    .custom-toast { background: #fff; border-left: 5px solid #dc2626; box-shadow: 0 4px 12px rgba(0,0,0,0.15); padding: 15px 20px; border-radius: 4px; margin-bottom: 10px; opacity: 0; transform: translateX(100%); transition: all 0.3s ease-in-out; display: flex; align-items: center; }
    .custom-toast.show { opacity: 1; transform: translateX(0); }
    .custom-toast.toast-warning { border-left-color: #f59e0b; }
    
    /* Tom Select Style Overrides */
    .ts-wrapper .ts-control { border-radius: 6px; font-size: 0.85rem; padding: 6px 12px; }
    .ts-dropdown .optgroup-header { font-weight: 700; color: #0f766e; font-size: 0.75rem; background-color: #f8fafc; text-transform: uppercase; }
</style>

<div id="custom-toast-container"></div>

<div class="container-fluid py-4 px-lg-5">
    <div class="mb-4">
        <a href="<?php echo (defined('APP_BASE_PATH') ? APP_BASE_PATH : '/'); ?>?route=index_shr" class="text-decoration-none text-muted small fw-bold"><i data-feather="arrow-left" style="width:14px;"></i> GO BACK</a>
        <h3 class="fw-bold m-0 mt-2 text-dark">
            <?= $edit_data ? "Edit Handover (" . htmlspecialchars($edit_data['shr_no']) . ")" : "Initiate Shift Handover" ?>
        </h3>
    </div>

    <?php
    $pending_check_stmt = $conn->prepare("SELECT shr_no FROM shift_handovers WHERE status = 'Pending Ack' AND acknowledged_by_username = ?");
    $pending_check_stmt->bind_param("s", $logged_in_username);
    $pending_check_stmt->execute();
    $pending_res = $pending_check_stmt->get_result();
    
    $has_pending_block = false;
    $pending_message = "";
    
    if ($pending_res->num_rows > 0) {
        $has_pending_block = true;
        $pending_row = $pending_res->fetch_assoc();
        $pending_message = "You have a pending incoming handover ({$pending_row['shr_no']}) that you must acknowledge before creating/editing a shift handover.";
    }
    $pending_check_stmt->close();
    ?>

    <?php if ($has_pending_block): ?>
        <div class="alert alert-danger d-flex align-items-center p-4 rounded-3 shadow-sm border-0 mt-4">
            <i data-feather="alert-triangle" class="me-3 text-danger" style="width:32px; height:32px;"></i>
            <div>
                <h5 class="fw-bold mb-1 text-danger">Action Required</h5>
                <p class="mb-0 fs-6"><?= htmlspecialchars($pending_message) ?></p>
            </div>
        </div>
    <?php else: ?>

    <form id="handoverForm">
        <input type="hidden" id="edit_id" value="<?= $edit_id ?>">
        
        <!-- Grid bounds exclusively for Summary and Details Sidebars -->
        <div class="row g-4 mb-4">
            
            <div class="col-lg-3 order-lg-2">
                <div class="modern-card mb-0">
                    <div class="modern-card-header text-dark">
                        <i data-feather="clock" class="me-2" style="width:16px;"></i> Shift Details
                    </div>
                    <div class="card-body p-3">
                        <div class="mb-3">
                            <label class="form-label text-muted">Date</label>
                            <div class="fw-bold text-dark fs-5"><?= date('M d, Y') ?></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted">Handover By</label>
                            <div class="fw-bold text-primary"><?= htmlspecialchars($logged_in_user_fullname) ?></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted">Handover To (Next Tech)</label>
                            <select class="form-select border-primary fw-bold" id="handover_to" required onchange="document.getElementById('sum_handover_to').innerText = this.options[this.selectedIndex].text || 'Select User';">
                                <option value="">-- Select Shift Owner --</option>
                                <?php foreach ($shift_owners as $uname => $fname): ?>
                                    <option value="<?= htmlspecialchars($uname) ?>" <?= ($edit_data && $edit_data['acknowledged_by_username'] === $uname) ? 'selected' : '' ?>><?= htmlspecialchars($fname) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted">Shift Ending</label>
                            <select class="form-select border-primary" id="shift_type" required>
                                <option value="">-- Select Shift --</option>
                                <?php 
                                $shifts = [
    				"Shift 1 (6 AM - 3 PM)",
    				"Shift 2 (9 AM - 6 PM)",
    				"Shift 3 (11 AM - 8 PM)",
    				"Shift 4 (12 PM - 9 PM)",
    				"Shift 5 (1 PM - 10 PM)",
    				"Shift 6 (2 PM - 11 PM)",
    				"Shift 7 (4 PM - 1 AM)",
    				"Shift 8 (5:30 PM - 2:30 AM)",
    				"Shift 9 (9 PM - 6 AM)",
    				"Shift 10 (10 PM - 7 AM)"	
				];                                
				foreach ($shifts as $s) {
                                    $sel = ($edit_data && $edit_data['shift_type'] === $s) ? 'selected' : '';
                                    echo "<option value=\"$s\" $sel>$s</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <button type="button" id="btnFetchTickets" class="btn btn-outline-primary w-100 fw-bold rounded-pill mt-3">
                            <i data-feather="refresh-cw" style="width:14px;"></i> <?= $edit_data ? 'Refresh Queue (Warning: Resets Notes)' : 'Auto-Pull GLPI Queue' ?>
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-lg-9 order-lg-1">
                <div class="modern-card mb-0">
                    <div class="modern-card-header d-flex justify-content-between align-items-center bg-white border-bottom">
                        <span class="text-dark"><i data-feather="grid" class="me-2" style="width:16px;"></i> Shift Summary Report</span>
                    </div>
                    <div class="card-body p-4 bg-light">
                        <div class="table-responsive bg-white shadow-sm mb-4">
                            <table class="summary-table">
                                <tr>
                                    <th class="text-start px-3 text-primary" style="width: 25%;">Shift Summary</th>
                                    <td colspan="5" id="table_metrics" class="p-3 fs-6">
                                        <div class="row text-start m-0 w-100">
                                            <div class="col-md-5 mb-2" style="color: #d97706;">
                                                Previous Pending: <span id="met-prev" class="fw-bold">0</span>
                                            </div>
                                            <div class="col-md-7 mb-2" style="color: #dc2626;">
                                                Pending Handover: <span id="met-pending" class="fw-bold">User: 0 | Internal: 0</span>
                                            </div>
                                            <div class="col-md-5 mb-0" style="color: #0284c7;">
                                                Total Tickets I Worked: <span id="met-worked" class="fw-bold">0</span>
                                            </div>
                                            <div class="col-md-7 mb-0" style="color: #16a34a;">
                                                Closed: <span id="met-closed" class="fw-bold">0</span>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th class="text-start px-3">Major Events <span class="text-danger">*</span></th>
                                    <td style="width: 10%;"><input type="number" class="input-sm text-center req-field" id="sum_major_cnt" value="0"></td>
                                    <th class="text-center" style="width: 10%;">BRIEF:</th>
                                    <td><input type="text" class="input-sm req-field" id="sum_major_brief" placeholder="Provide brief..."></td>
                                    <th class="text-center" style="width: 10%;">STATUS:</th>
                                    <td style="width: 15%;"><input type="text" class="input-sm text-center req-field" id="sum_major_status" placeholder="Status..."></td>
                                </tr>
                                <tr>
                                    <th class="text-start px-3">High Priority Incidents <span class="text-danger">*</span></th>
                                    <td><input type="number" class="input-sm text-center req-field" id="sum_hp_cnt" value="0" readonly></td>
                                    <th class="text-center">BRIEF:</th>
                                    <td><input type="text" class="input-sm req-field" id="sum_hp_brief" placeholder="Provide brief..."></td>
                                    <th class="text-center">STATUS:</th>
                                    <td><input type="text" class="input-sm text-center req-field" id="sum_hp_status" placeholder="Status..."></td>
                                </tr>
                                <tr>
                                    <th class="text-start px-3">SLA Risks (Not Meeting 2Hrs) <span class="text-danger">*</span></th>
                                    <td><input type="number" class="input-sm text-center req-field" id="sum_sla_cnt" value="0"></td>
                                    <th class="text-center">BRIEF:</th>
                                    <td><input type="text" class="input-sm req-field" id="sum_sla_brief" placeholder="Provide brief..."></td>
                                    <th class="text-center">STATUS:</th>
                                    <td><input type="text" class="input-sm text-center req-field" id="sum_sla_status" placeholder="Status..."></td>
                                </tr>
                                <tr>
                                    <th class="text-start px-3">Infrastructure Health <span class="text-danger">*</span></th>
                                    <td class="text-center">
                                        <select class="input-sm text-center req-field" id="sum_infra_status">
                                            <option>All Good</option>
                                            <option>Needs Attention</option>
                                        </select>
                                    </td>
                                    <th class="text-center">COMMENTS:</th>
                                    <td colspan="3"><input type="text" class="input-sm req-field" id="sum_infra_comments" placeholder="Comments..."></td>
                                </tr>
                                <tr class="bg-yellow">
                                    <th class="text-start px-3">Next Shift Priorities</th>
                                    <td class="text-center fw-bold fs-6" id="sum_nsp_cnt">0</td>
                                    <th class="text-center">COMMENTS:</th>
                                    <td colspan="3" id="sum_nsp_comments" class="fw-bold" style="white-space: pre-wrap; font-family: monospace;">Select tickets below to auto-populate...</td>
                                </tr>
                                <tr class="bg-yellow text-red">
                                    <th class="text-start px-3">Escalations <span class="text-danger">*</span></th>
                                    <td><input type="number" class="input-sm text-center text-red req-field" id="sum_esc_cnt" value="0"></td>
                                    <th class="text-center">COMMENTS:</th>
                                    <td colspan="3"><input type="text" class="input-sm text-red req-field" id="sum_esc_comments" placeholder="Comments..."></td>
                                </tr>
                                <tr>
                                    <th class="text-start px-3">Any Oral Updates <span class="text-danger">*</span></th>
                                    <td colspan="5"><textarea class="input-sm req-field" id="sum_oral_updates" rows="2" placeholder="01) ..."></textarea></td>
                                </tr>
                                <tr>
                                    <th class="text-start px-3">Other Information <span class="text-danger">*</span></th>
                                    <td colspan="5"><input type="text" class="input-sm req-field" id="sum_attachments" placeholder="Other Information..."></td>
                                </tr>
                                <tr>
                                    <th class="text-start px-3 bg-light">Oral Handover Given To</th>
                                    <td colspan="5" class="fw-bold text-primary fs-6" id="sum_handover_to">Select User</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div> 

        <!-- FULL WIDTH CARDS OUTSIDE THE GRID LAYOUT -->
        <div id="ticketsContainer" class="text-center text-muted py-5 modern-card mt-4">
            <i data-feather="inbox" style="width:32px; height:32px; opacity:0.5;" class="mb-2"></i><br>
            Click "Auto-Pull GLPI Queue" to fetch data.
        </div>

        <div id="cardClosed" class="modern-card mt-4 d-none">
            <div class="modern-card-header text-success">
                <i data-feather="check-circle" class="me-2" style="width:18px;"></i> Tickets Closed Today (By <?= htmlspecialchars($logged_in_user_fullname) ?>)
            </div>
            <div class="card-body p-4 bg-light" id="closedContainer"></div>
        </div>

        <div id="cardPending" class="modern-card mt-4 d-none">
            <div class="modern-card-header text-secondary">
                <i data-feather="inbox" class="me-2" style="width:18px;"></i> Handover Ticket Queue
            </div>
            <div class="card-body p-4 bg-light">
                <p class="text-muted small mb-3">Tick the checkbox to mark as <strong>Next Shift Priority</strong>. Use the text box to add your mandatory new comments to the ongoing history.</p>
                <div id="pendingContainer"></div>
            </div>
        </div>

        <input type="hidden" id="snapshot_json" name="snapshot_json" required>

        <div class="text-end mb-5 mt-4">
            <button type="button" id="btnSubmitHandover" class="btn btn-primary-pro shadow-sm px-5 fs-5 py-3">
                <?= $edit_data ? 'Update & Resend Digital Handover' : 'Submit Digital Handover' ?> <i data-feather="send" class="ms-2"></i>
            </button>
        </div>
        
    </form>
    <?php endif; ?>
</div>

<!-- Tom Select JS for Searchable Dropdowns -->
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>

<script>
window.metrics = {};
window.allTickets = [];
const kbArticlesList = <?php echo json_encode($kb_articles); ?>;

const isEditMode = <?= $edit_data ? 'true' : 'false' ?>;
const existingSnapshot = <?= $edit_data ? $edit_data['glpi_snapshot_json'] : 'null' ?>;

function showToast(message, type = 'danger') {
    const container = document.getElementById('custom-toast-container');
    const toast = document.createElement('div');
    toast.className = `custom-toast toast-${type}`;
    toast.innerHTML = `<i data-feather="alert-circle" class="me-2" style="width:18px;"></i> ${message}`;
    container.appendChild(toast);
    if(typeof feather !== 'undefined') feather.replace();
    setTimeout(() => { toast.classList.add('show'); }, 10);
    setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 300); }, 4000);
}

function togglePriority(id, isChecked) {
    const commentBox = document.getElementById(`priocomment-${id}`);
    const historyBox = document.getElementById(`history-${id}`);
    const ticketBox = document.getElementById(`ticketbox-${id}`);
    const ticket = window.allTickets.find(t => t.id == id);
    
    if (isChecked) {
        commentBox.classList.remove('d-none');
        commentBox.classList.add('req-field');
        if (historyBox && historyBox.innerHTML.trim() !== '') historyBox.classList.remove('d-none');
        ticketBox.classList.add('table-warning');
        ticket.is_priority = true;
        ticket.priority_comment = commentBox.value || '';
    } else {
        commentBox.classList.add('d-none');
        commentBox.classList.remove('req-field');
        commentBox.classList.remove('border-danger'); 
        if (historyBox) historyBox.classList.add('d-none');
        ticketBox.classList.remove('table-warning');
        ticket.is_priority = false;
        ticket.priority_comment = '';
    }
    updateSummaryTable();
}

function updatePriorityComment(id, val) {
    const ticket = window.allTickets.find(t => t.id == id);
    const commentBox = document.getElementById(`priocomment-${id}`);
    
    if (val.trim().toUpperCase() === 'NA') {
        showToast('"NA" is not allowed. Please provide a valid update.', 'warning');
        val = '';
        if (commentBox) {
            commentBox.value = '';
            commentBox.classList.add('border-danger');
        }
    } else {
        if (commentBox) commentBox.classList.remove('border-danger');
    }
    
    if (ticket) ticket.priority_comment = val;
    updateSummaryTable();
}

function updateTicketKB(id, kbId) {
    const ticket = window.allTickets.find(t => t.id == id);
    if (ticket) ticket.linked_kb_id = kbId;
    
    const deptSelect = document.getElementById(`dept-select-${id}`);
    if (deptSelect) {
        if (kbId && kbId !== '') {
            deptSelect.classList.remove('d-none');
        } else {
            deptSelect.classList.add('d-none');
            deptSelect.value = ''; 
            ticket.user_department = '';
        }
    }
}

function updateTicketDept(id, val) {
    const ticket = window.allTickets.find(t => t.id == id);
    if (ticket) ticket.user_department = val;
}

function updateSummaryTable() {
    const prioTickets = window.allTickets.filter(t => t.is_priority);
    document.getElementById('sum_nsp_cnt').innerText = prioTickets.length;
    let commentsHtml = '';
    if (prioTickets.length > 0) {
        prioTickets.forEach((t, index) => { 
            // Display active text. If blank, visually fall back to history in Summary
            let lastComment = (t.priority_comment || '').trim();
            if (!lastComment && t.priority_history && t.priority_history.length > 0) {
                lastComment = t.priority_history[t.priority_history.length - 1].comment;
            }
            if (!lastComment) lastComment = '(No note provided)';
            
            commentsHtml += `${index + 1}. Ticket #${t.id} - ${lastComment}\n`; 
        });
        document.getElementById('sum_nsp_comments').innerText = commentsHtml;
    } else { document.getElementById('sum_nsp_comments').innerText = 'None selected'; }
}

function renderTicketsUI(metrics, dataArray) {
    window.metrics = metrics;
    window.allTickets = dataArray;

    document.getElementById('met-prev').innerText = metrics.received;
    document.getElementById('met-worked').innerText = metrics.worked;
    document.getElementById('met-closed').innerText = metrics.closed;
    document.getElementById('met-pending').innerText = metrics.pending;
    
    const hpCount = window.allTickets.filter(t => t.priority >= 4 && t.status !== 'Solved' && t.status !== 'Closed').length;
    document.getElementById('sum_hp_cnt').value = hpCount;
    if(hpCount > 0 && !document.getElementById('sum_hp_brief').value) {
        document.getElementById('sum_hp_brief').value = `${hpCount} High Priority Incidents active.`;
    }

    let closedHtml = '';
    let pendingHtml = `
    <div class="table-responsive border rounded bg-white">
        <table class="table table-hover align-middle mb-0" style="font-size: 0.9rem;">
            <thead class="table-light text-muted">
                <tr>
                    <th class="text-center" style="width: 50px;">NSP</th>
                    <th style="width: 100px;">Ticket No.</th>
                    <th>Title & Priority Log</th>
                    <th style="width: 150px;">Location</th>
                    <th style="width: 150px;">Assigned To</th>
                    <th style="width: 120px;">Status</th>
                    <th style="width: 100px;">Priority</th>
                    <th style="width: 300px;">Link to KB & Dept</th>
                </tr>
            </thead>
            <tbody>
    `;
    let hasClosed = false, hasPending = false;
    const prioMap = {1: 'Very Low', 2: 'Low', 3: 'Medium', 4: 'High', 5: 'Very High', 6: 'Major'};

    window.allTickets.forEach(t => {
        let prioLabel = prioMap[t.priority] || 'Medium';
        let isCrit = t.priority >= 4;
        let prioBadgeClass = isCrit ? 'bg-danger' : 'bg-secondary';
        
        // Build KB Options
        let kbOptions = '<option value="">-- Search & Select Manual --</option>';
        let currentCat = null;
        
        kbArticlesList.forEach(kb => { 
            let cat = kb.category_name || 'Uncategorized';
            if (cat !== currentCat) {
                if (currentCat !== null) kbOptions += '</optgroup>';
                kbOptions += `<optgroup label="${cat}">`;
                currentCat = cat;
            }
            let selected = (t.linked_kb_id == kb.id) ? 'selected' : '';
            kbOptions += `<option value="${kb.id}" ${selected}>${kb.title}</option>`; 
        });
        if (currentCat !== null) kbOptions += '</optgroup>';

        let deptOptions = `
            <option value="">Select Dept</option>
            <option value="Management" ${t.user_department === 'Management' ? 'selected' : ''}>Management</option>
            <option value="IT" ${t.user_department === 'IT' ? 'selected' : ''}>IT</option>
            <option value="HR" ${t.user_department === 'HR' ? 'selected' : ''}>HR</option>
        `;
        let deptClass = t.linked_kb_id ? '' : 'd-none';
        
        if (t.status === 'Solved' || t.status === 'Closed') {
            hasClosed = true;
            closedHtml += `
                <div class="ticket-box solved d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-bold text-dark mb-1">${t.id}: ${t.title}</div>
                            <div class="small text-muted"><i data-feather="map-pin" style="width:12px;" class="me-1"></i>${t.location || 'N/A'}</div>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-success border border-success d-block mb-1">${t.status}</span>
                            <span class="badge ${prioBadgeClass}">${prioLabel}</span>
                        </div>
                    </div>
                    <div class="mt-2 pt-2 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="d-flex align-items-center flex-grow-1">
                            <span class="small text-muted fw-medium me-2 text-nowrap"><i data-feather="book-open" style="width:12px;" class="me-1"></i> Link KB:</span>
                            <select class="form-select kb-search-select" style="min-width: 250px;" data-ticket-id="${t.id}">
                                ${kbOptions}
                            </select>
                        </div>
                        <select class="form-select form-select-sm w-auto ${deptClass}" id="dept-select-${t.id}" onchange="updateTicketDept(${t.id}, this.value)">
                            ${deptOptions}
                        </select>
                    </div>
                </div>
            `;
        } else {
            hasPending = true;
            let isChecked = t.is_priority ? 'checked' : '';
            let commentClass = t.is_priority ? 'req-field' : 'd-none';
            let trClass = isCrit ? 'table-danger' : (t.is_priority ? 'table-warning' : '');
            
            // Build Comment History UI
            let historyHtml = '';
            if (t.priority_history && t.priority_history.length > 0) {
                historyHtml = `<div id="history-${t.id}" class="${t.is_priority ? '' : 'd-none'} mt-2 mb-1 p-2 bg-light border border-secondary rounded shadow-sm" style="max-height: 120px; overflow-y: auto; font-size: 0.75rem;">`;
                t.priority_history.forEach(h => {
                    historyHtml += `<div class="mb-1 pb-1 border-bottom"><strong class="text-primary">${h.user}</strong> <span class="text-muted" style="font-size:0.7rem;">(${h.time})</span><br><span class="text-dark">${h.comment}</span></div>`;
                });
                historyHtml += `</div>`;
            } else {
                historyHtml = `<div id="history-${t.id}" class="d-none"></div>`; 
            }
            
            pendingHtml += `
                <tr id="ticketbox-${t.id}" class="${trClass}">
                    <td class="text-center"><input class="form-check-input shadow-sm border-secondary" type="checkbox" onchange="togglePriority(${t.id}, this.checked)" style="width: 1.25rem; height: 1.25rem; cursor: pointer;" ${isChecked}></td>
                    <td class="fw-bold text-primary">${t.id}</td>
                    <td>
                        <div class="fw-bold text-dark">${t.title}</div>
                        ${historyHtml}
                        <input type="text" class="form-control form-control-sm border-warning ${commentClass} ${t.priority_history && t.priority_history.length > 0 ? 'mt-1' : 'mt-2'}" id="priocomment-${t.id}" placeholder="Add your new handover note here..." value="${t.priority_comment || ''}" onkeyup="updatePriorityComment(${t.id}, this.value)">
                    </td>
                    <td>${t.location || 'N/A'}</td>
                    <td>${t.assigned_to}</td>
                    <td><span class="badge bg-light text-dark border">${t.status}</span></td>
                    <td><span class="badge ${prioBadgeClass}">${prioLabel}</span></td>
                    <td>
                        <div class="d-flex flex-column gap-2">
                            <select class="form-select kb-search-select" data-ticket-id="${t.id}">
                                ${kbOptions}
                            </select>
                            <select class="form-select form-select-sm ${deptClass}" id="dept-select-${t.id}" onchange="updateTicketDept(${t.id}, this.value)">
                                ${deptOptions}
                            </select>
                        </div>
                    </td>
                </tr>
            `;
        }
    });

    pendingHtml += `</tbody></table></div>`;

    document.getElementById('ticketsContainer').classList.add('d-none');
    document.getElementById('cardClosed').classList.remove('d-none');
    document.getElementById('cardPending').classList.remove('d-none');

    document.getElementById('closedContainer').innerHTML = hasClosed ? closedHtml : '<div class="text-muted small fst-italic">No tickets closed by you today.</div>';
    document.getElementById('pendingContainer').innerHTML = hasPending ? pendingHtml : '<div class="text-muted small fst-italic">No pending tickets in queue.</div>';
    
    updateSummaryTable(); 
    if(typeof feather !== 'undefined') feather.replace();

    // Initialize Tom Select
    document.querySelectorAll('.kb-search-select').forEach(el => {
        new TomSelect(el, {
            create: false,
            placeholder: "Search KB Manual...",
            dropdownParent: 'body',
            onChange: function(value) {
                updateTicketKB(el.getAttribute('data-ticket-id'), value);
            }
        });
    });
}

function prefillEditSummary(summary) {
    if (!summary) return;
    const val = (obj, key1, key2) => obj && obj[key1] ? obj[key1][key2] : '';
    document.getElementById('sum_major_cnt').value = val(summary, 'major_events', 'count') || 0;
    document.getElementById('sum_major_brief').value = val(summary, 'major_events', 'brief');
    document.getElementById('sum_major_status').value = val(summary, 'major_events', 'status');
    document.getElementById('sum_hp_cnt').value = val(summary, 'high_priority', 'count') || 0;
    document.getElementById('sum_hp_brief').value = val(summary, 'high_priority', 'brief');
    document.getElementById('sum_hp_status').value = val(summary, 'high_priority', 'status');
    document.getElementById('sum_sla_cnt').value = val(summary, 'sla_risks', 'count') || 0;
    document.getElementById('sum_sla_brief').value = val(summary, 'sla_risks', 'brief');
    document.getElementById('sum_sla_status').value = val(summary, 'sla_risks', 'status');
    document.getElementById('sum_infra_status').value = val(summary, 'infra_health', 'status');
    document.getElementById('sum_infra_comments').value = val(summary, 'infra_health', 'comments');
    document.getElementById('sum_esc_cnt').value = val(summary, 'escalations', 'count') || 0;
    document.getElementById('sum_esc_comments').value = val(summary, 'escalations', 'comments');
    document.getElementById('sum_oral_updates').value = summary.oral_updates || '';
    document.getElementById('sum_attachments').value = summary.attachments || '';
    document.getElementById('sum_handover_to').innerText = summary.handover_to || 'Select User';
}

document.addEventListener('DOMContentLoaded', () => {
    if(typeof feather !== 'undefined') feather.replace();

    if (isEditMode && existingSnapshot) {
        const hoSelect = document.getElementById('handover_to');
        if(hoSelect.selectedIndex > 0) { document.getElementById('sum_handover_to').innerText = hoSelect.options[hoSelect.selectedIndex].text; }
        prefillEditSummary(existingSnapshot.summary);
        renderTicketsUI(existingSnapshot.metrics, existingSnapshot.tickets);
    }

    const btnFetch = document.getElementById('btnFetchTickets');
    if (btnFetch) {
        const inputJson = document.getElementById('snapshot_json');
        const handoverForm = document.getElementById('handoverForm');

        document.querySelectorAll('.req-field').forEach(field => {
            field.addEventListener('input', function(e) {
                if (this.value.trim().toUpperCase() === 'NA') {
                    showToast('"NA" is not allowed in Shift Summary fields. Please provide valid details.', 'warning');
                    this.value = '';
                    this.classList.add('border-danger');
                } else { this.classList.remove('border-danger'); }
            });
        });

        handoverForm.addEventListener('submit', (e) => { e.preventDefault(); });

        btnFetch.addEventListener('click', () => {
            const shiftType = document.getElementById('shift_type').value;
            if (!shiftType) return showToast('Please select your "Shift Ending" time before auto-pulling tickets.', 'warning');

            btnFetch.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Fetching...';
            btnFetch.disabled = true;

            const fd = new FormData();
            fd.append('ajax_action', 'fetch_glpi_tickets');
            fd.append('shift_type', shiftType);

            fetch('<?php echo (defined("APP_BASE_PATH") ? APP_BASE_PATH : "/"); ?>?route=create_shr', { method: 'POST', body: fd })
                .then(res => { if (!res.ok) throw new Error('API server returned error code ' + res.status); return res.json(); })
                .then(res => {
                    btnFetch.innerHTML = '<i data-feather="check" style="width:12px;"></i> Auto-Pull GLPI Queue';
                    if(typeof feather !== 'undefined') feather.replace();
                    
                    if (res.status === 'success') {
                        renderTicketsUI(res.metrics, res.data);
                        btnFetch.disabled = false;
                    } else {
                        document.getElementById('ticketsContainer').innerHTML = `<div class="text-danger">Error: ${res.message}</div>`;
                        btnFetch.disabled = false;
                    }
                }).catch(err => {
                    document.getElementById('ticketsContainer').innerHTML = `<div class="text-danger">Network Error connecting to GLPI API. Check logs.</div>`;
                    btnFetch.disabled = false;
                    btnFetch.innerText = 'Retry';
                });
        });

        const btnSubmit = document.getElementById('btnSubmitHandover');
        btnSubmit.addEventListener('click', (e) => {
            e.preventDefault(); 
            const hoSelect = document.getElementById('handover_to');
            if (!hoSelect.value) return showToast('Please select the incoming shift owner in the Shift Details pane.', 'warning');
            const shiftType = document.getElementById('shift_type').value;
            if (!shiftType) return showToast('Please select a shift type.', 'warning');
            if (window.allTickets.length === 0) return showToast('Please Auto-Pull the GLPI tickets first to create a snapshot.', 'warning');

            let fieldsValid = true;
            document.querySelectorAll('.req-field').forEach(field => {
                const val = field.value.trim().toUpperCase();
                if (val === '' || val === 'NA') { fieldsValid = false; field.classList.add('border-danger'); } 
                else { field.classList.remove('border-danger'); }
            });
            if (!fieldsValid) return showToast('All fields in the Shift Summary (including active Next Shift Priority notes) are mandatory and cannot be left blank or contain "NA".', 'danger');

            const originalBtnHtml = btnSubmit.innerHTML;
            btnSubmit.disabled = true;
            btnSubmit.innerHTML = 'Processing... <span class="spinner-border spinner-border-sm ms-2"></span>';

            const hoUsername = hoSelect.value;
            const hoName = hoSelect.options[hoSelect.selectedIndex].text;

            const summaryData = {
                major_events: { count: document.getElementById('sum_major_cnt').value, brief: document.getElementById('sum_major_brief').value, status: document.getElementById('sum_major_status').value },
                high_priority: { count: document.getElementById('sum_hp_cnt').value, brief: document.getElementById('sum_hp_brief').value, status: document.getElementById('sum_hp_status').value },
                sla_risks: { count: document.getElementById('sum_sla_cnt').value, brief: document.getElementById('sum_sla_brief').value, status: document.getElementById('sum_sla_status').value },
                infra_health: { status: document.getElementById('sum_infra_status').value, comments: document.getElementById('sum_infra_comments').value },
                escalations: { count: document.getElementById('sum_esc_cnt').value, comments: document.getElementById('sum_esc_comments').value },
                oral_updates: document.getElementById('sum_oral_updates').value,
                attachments: document.getElementById('sum_attachments').value,
                handover_to: hoName
            };

            inputJson.value = JSON.stringify({ metrics: window.metrics, summary: summaryData, tickets: window.allTickets });

            const fd = new FormData();
            fd.append('ajax_action', 'save_handover');
            fd.append('edit_id', document.getElementById('edit_id').value);
            fd.append('shift_type', shiftType);
            fd.append('ho_username', hoUsername);
            fd.append('ho_name', hoName);
            fd.append('snapshot_json', inputJson.value);

            fetch('<?php echo (defined("APP_BASE_PATH") ? APP_BASE_PATH : "/"); ?>?route=create_shr', { method: 'POST', body: fd })
                .then(res => { if (!res.ok) throw new Error('Database insertion rejected by server endpoint.'); return res.json(); })
                .then(res => {
                    if(res.status === 'success') { window.location.href = res.redirect; } 
                    else { btnSubmit.disabled = false; btnSubmit.innerHTML = originalBtnHtml; showToast('Error: ' + res.message, 'danger'); }
                })
                .catch(err => {
                    console.error(err);
                    btnSubmit.disabled = false;
                    btnSubmit.innerHTML = originalBtnHtml;
                    showToast('An unforeseen connection error occurred while saving. Please ensure fields are valid or review network log information.', 'danger');
                });
        });
    }
});
</script>
<?php include_once '../includes/footer.php'; ?>