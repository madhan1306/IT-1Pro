<?php
// FILE: classes/SystemReadinessService.php

class SystemReadinessService {
    private $db;

    public function __construct($conn) {
        $this->db = $conn;
    }

    public function logActivity($employee_id, $checklist_id, $performed_by, $action_description) {
        if (empty($employee_id)) return;
        $sql = "INSERT INTO action_logs (joiner_id, template_id, performed_by, action_type, action_description) VALUES (?, ?, ?, 'System Readiness', ?)";
        $stmt = $this->db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("iiss", $employee_id, $checklist_id, $performed_by, $action_description);
            $stmt->execute();
            $stmt->close();
        }
    }

    public function updateConfig($checklist_id, $field, $value, $user_fullname) {
        $allowed_fields = ['hostname', 'serial_number', 'system_model', 'processor_ram_hdd_ssd', 'operating_system', 'ms_office_version'];
        if (!in_array($field, $allowed_fields)) throw new Exception("Invalid configuration field.");

        $stmt = $this->db->prepare("UPDATE checklists SET $field = ? WHERE id = ?");
        $stmt->bind_param("si", $value, $checklist_id);
        
        if (!$stmt->execute()) throw new Exception("Database update failed.");
        
        // Special case: Hostname syncs with checklist item
        if ($field === 'hostname') {
             $status = !empty($value) ? 'Completed' : 'Pending';
             $stmt_item = $this->db->prepare("UPDATE checklist_items SET remarks = ?, status = ?, updated_by = ?, updated_at = NOW() WHERE template_id = ? AND task_description = 'Provide appropriate Hostname for the PC'");
             $stmt_item->bind_param("sssi", $value, $status, $user_fullname, $checklist_id);
             $stmt_item->execute();
        }
        return true;
    }

    public function updateChecklistItem($item_id, $field, $value, $user_fullname) {
        if (!in_array($field, ['status', 'remarks'])) throw new Exception("Invalid item field.");

        $stmt_get = $this->db->prepare("SELECT id FROM checklist_items WHERE id = ?");
        $stmt_get->bind_param("i", $item_id);
        $stmt_get->execute();
        
        if ($stmt_get->get_result()->num_rows === 0) throw new Exception("Item not found.");

        $stmt_upd = $this->db->prepare("UPDATE checklist_items SET $field = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
        $stmt_upd->bind_param("ssi", $value, $user_fullname, $item_id);
        
        if (!$stmt_upd->execute()) throw new Exception("Item update failed.");
        
        return [
            'status' => 'success', 
            'message' => 'Saved',
            'updated_by' => $user_fullname,
            'updated_at' => date('M d, H:i')
        ];
    }

    public function insertInitialItems($items, $checklist_id, $creator_fullname, $employee, $category_stack = []) {
        foreach ($items as $task_description_key => $value) {
            if (is_array($value)) {
                $current_category = is_numeric($task_description_key) ? $value[0] : $task_description_key;
                $this->insertInitialItems($value, $checklist_id, $creator_fullname, $employee, array_merge($category_stack, [$current_category]));
            } else {
                $actual_task = is_numeric($task_description_key) ? $value : $task_description_key;
                $top_category = !empty($category_stack) ? $category_stack[0] : 'UNKNOWN_CATEGORY';
                $status = 'Pending'; $remarks = ''; 
                
                // Auto-populate logic
                if ($actual_task === 'Create AD Credentials' && !empty($employee['domain_username'])) { 
                    $status = 'Completed'; $remarks = $employee['domain_username']; 
                } elseif ($actual_task === 'Configure User Profile, IM & Outlook email configuration' && !empty($employee['email_id'])) { 
                    $status = 'Completed'; $remarks = $employee['email_id']; 
                } elseif ($actual_task === 'Email ID Creation in O365 with appropriate license' && !empty($employee['o365_edition'])) { 
                    $status = 'Completed'; $remarks = $employee['o365_edition']; 
                }
                
                $stmt = $this->db->prepare("INSERT INTO checklist_items (template_id, checklist_category, task_description, status, remarks, updated_by) VALUES (?, ?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param("isssss", $checklist_id, $top_category, $actual_task, $status, $remarks, $creator_fullname);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
    }
}