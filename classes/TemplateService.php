<?php
// FILE: classes/TemplateService.php

class TemplateService {
    private $db;

    public function __construct($conn) {
        $this->db = $conn;
    }

    public function getJoinerById($id) {
        $sql = "SELECT e.*, 
                       c.id as checklist_id, 
                       c.serial_number, c.hostname, c.system_model, c.processor_ram_hdd_ssd, c.operating_system, c.ms_office_version,
                       c.induction_it_remarks, c.induction_hr_remarks, c.induction_facility_remarks
                FROM employees e 
                LEFT JOIN checklists c ON e.id = c.employee_id AND c.checklist_type = 'system_readiness' 
                WHERE e.id = ? AND e.employee_type = 'new_joiner' LIMIT 1";
                
        $stmt = $this->db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $result;
        }
        return null;
    }

    public function logActivity($joinerId, $userFullname, $description, $field = null, $old = null, $new = null) {
        $action_type = 'Template Management';
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        
        $sql = "INSERT INTO action_logs (joiner_id, performed_by, action_type, action_description, field_changed, old_value, new_value, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("isssssss", $joinerId, $userFullname, $action_type, $description, $field, $old, $new, $ip);
            $stmt->execute();
            $stmt->close();
        }
    }

    public function isFieldTaken($field, $value, $excludeId = null) {
        $allowedFields = ['emp_id', 'domain_username', 'email_id'];
        if (!in_array($field, $allowedFields)) return false;

        $sql = "SELECT id FROM employees WHERE `$field` = ?";
        $params = [$value];
        $types = "s";

        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
            $types .= "i";
        }

        $stmt = $this->db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->store_result();
            $count = $stmt->num_rows;
            $stmt->close();
            return $count > 0;
        }
        return false;
    }

    public function saveTemplate($id, $postData, $userFullname, $action) {
        $isUpdate = !empty($id);
        $currentTimestamp = date('Y-m-d H:i:s');
        
        $oldData = [];
        if ($isUpdate) {
            $oldData = $this->getJoinerById($id);
        }

        // --- CRITICAL FIX: PRESERVE EXISTING STATUSES ---
        if ($action === 'approve') {
            $newStatus = 'Approved';
        } elseif (!$isUpdate) {
            $newStatus = 'New Request';
        } else {
            // Keep the database status! Do not overwrite it with 'New Request'
            $newStatus = $oldData['template_status'] ?? 'New Request';
        }
        $postData['template_status'] = $newStatus;

        if (!isset($postData['employee_status'])) {
            if (!$isUpdate) {
                $postData['employee_status'] = 'Yet to Join';
            } else {
                $postData['employee_status'] = $oldData['employee_status'] ?? 'Yet to Join';
            }
        }
        // ------------------------------------------------

        $allColumns = [
            'emp_id', 'first_name', 'last_name', 'date_of_joining', 'location', 'department', 'position', 'workstation',
            'asset_type', 'voice_service', 'o365_edition', 'email_preference', 'remarks', 
            'domain_username', 'domain_password', 'email_id', 'email_password', 'did', 'ms_teams', 'network_drives',
            'it_induction_conducted_by', 'it_induction_status', 'facility_induction_conducted_by', 'facility_induction_status', 'hr_induction_conducted_by', 'hr_induction_status',
            'template_status', 'employee_status'
        ];

        // FIX: Always capture requested_by/at if it is a new record, regardless of action
        if (!$isUpdate) {
            $allColumns[] = 'requested_by';
            $allColumns[] = 'requested_at';
            $postData['requested_by'] = $userFullname;
            $postData['requested_at'] = $currentTimestamp;
        }

        // Add created_by/at if the action is approve
        if ($action === 'approve') {
            $allColumns[] = 'created_by';
            $allColumns[] = 'created_at';
            $postData['created_by'] = $userFullname;
            $postData['created_at'] = $currentTimestamp;
        }

        try {
            if ($isUpdate) {
                $setParts = [];
                $bindParams = [];
                $bindTypes = "";
                $somethingChanged = false;

                if ($action === 'approve' && ($oldData['template_status'] ?? '') !== 'Approved') {
                    $this->logActivity($id, $userFullname, "Approved Template", "Status", $oldData['template_status'] ?? '', "Approved");
                    $somethingChanged = true;
                }

                foreach ($allColumns as $col) {
                    if (array_key_exists($col, $postData)) {
                        $newVal = trim((string)$postData[$col]);
                        $oldVal = trim((string)($oldData[$col] ?? ''));

                        if ($newVal !== $oldVal) {
                            $somethingChanged = true;
                            $isInternal = in_array($col, ['template_status', 'created_by', 'created_at', 'requested_by', 'requested_at']);
                            $shouldLog = !(strpos($col, 'password') !== false || ($action === 'approve' && $isInternal));

                            if ($shouldLog) {
                                $this->logActivity($id, $userFullname, "Updated " . ucwords(str_replace('_', ' ', $col)), $col, $oldVal, $newVal);
                            }
                        }
                        
                        $setParts[] = "`$col` = ?";
                        $bindParams[] = ($newVal === '' && in_array($col, ['email_id', 'domain_username'])) ? null : $newVal;
                        $bindTypes .= "s";
                    }
                }

                // Check checklist remarks for changes
                if (isset($postData['induction_hr_remarks']) && $postData['induction_hr_remarks'] != ($oldData['induction_hr_remarks'] ?? '')) $somethingChanged = true;
                if (isset($postData['induction_it_remarks']) && $postData['induction_it_remarks'] != ($oldData['induction_it_remarks'] ?? '')) $somethingChanged = true;
                if (isset($postData['induction_facility_remarks']) && $postData['induction_facility_remarks'] != ($oldData['induction_facility_remarks'] ?? '')) $somethingChanged = true;

                if (!$somethingChanged) {
                    return ['success' => true, 'id' => $id, 'message' => "No changes detected."];
                }

                $setParts[] = "`updated_at` = ?";
                $bindParams[] = $currentTimestamp; $bindTypes .= "s";
                $bindParams[] = $id; $bindTypes .= "i";

                $stmt = $this->db->prepare("UPDATE employees SET " . implode(", ", $setParts) . " WHERE id = ?");
                $stmt->bind_param($bindTypes, ...$bindParams);
                $stmt->execute();
                $stmt->close();
                
                // Update Checklist remarks safely
                if (isset($postData['induction_hr_remarks']) || isset($postData['induction_it_remarks']) || isset($postData['induction_facility_remarks'])) {
                    $rem_hr = $postData['induction_hr_remarks'] ?? $oldData['induction_hr_remarks'] ?? '';
                    $rem_it = $postData['induction_it_remarks'] ?? $oldData['induction_it_remarks'] ?? '';
                    $rem_fac = $postData['induction_facility_remarks'] ?? $oldData['induction_facility_remarks'] ?? '';
                    
                    $chk_stmt = $this->db->prepare("UPDATE checklists SET induction_hr_remarks=?, induction_it_remarks=?, induction_facility_remarks=? WHERE employee_id=? AND checklist_type='system_readiness'");
                    if ($chk_stmt) {
                        $chk_stmt->bind_param("sssi", $rem_hr, $rem_it, $rem_fac, $id);
                        $chk_stmt->execute();
                        $chk_stmt->close();
                    }
                }

                $finalId = $id;

            } else {
                // Insert Logic
                $placeholders = array_fill(0, count($allColumns), '?');
                $stmt = $this->db->prepare("INSERT INTO employees (employee_type, `" . implode("`, `", $allColumns) . "`) VALUES ('new_joiner', " . implode(", ", $placeholders) . ")");
                
                $bindTypes = ""; $bindParams = [];
                foreach ($allColumns as $col) { 
                    $val = $postData[$col] ?? null;
                    if ($val === '' && in_array($col, ['email_id', 'domain_username'])) $val = null;
                    $bindParams[] = $val; 
                    $bindTypes .= "s"; 
                }
                $stmt->bind_param($bindTypes, ...$bindParams);
                $stmt->execute();
                $finalId = $this->db->insert_id;
                $stmt->close();
                $this->logActivity($finalId, $userFullname, "Created Request");
            }

            // Automatically Trigger Checklist on Active Employee Status
            if (($postData['employee_status'] ?? '') === 'Active') {
                $chk_stmt = $this->db->prepare("SELECT id FROM checklists WHERE employee_id = ? AND checklist_type = 'system_readiness'");
                $chk_stmt->bind_param("i", $finalId);
                $chk_stmt->execute();
                $chk_res = $chk_stmt->get_result();
                
                if ($chk_res->num_rows == 0) {
                    $c_sql = "INSERT INTO checklists (employee_id, checklist_type, overall_status, created_by) VALUES (?, 'system_readiness', 'Not Started', ?)";
                    $c_stmt = $this->db->prepare($c_sql);
                    $c_stmt->bind_param("is", $finalId, $userFullname);
                    $c_stmt->execute();
                    $c_stmt->close();
                }
                $chk_stmt->close();
            }

            $msg = ($action === 'approve') ? "Approved Successfully!" : "Saved Successfully.";
            return ['success' => true, 'id' => $finalId, 'message' => $msg];

        } catch (Exception $e) {
            throw $e;
        }
    }
}