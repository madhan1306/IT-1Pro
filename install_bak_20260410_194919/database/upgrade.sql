-- --------------------------------------------------------
-- UPGRADE SCRIPT v6.0
-- Adds new tables, columns, roles, and permissions safely.
-- --------------------------------------------------------

-- 1. Create new `acceptance_attempts` table
CREATE TABLE IF NOT EXISTS `acceptance_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `checklist_id` int(11) NOT NULL,
  `status` enum('Accepted','Rejected') NOT NULL,
  `action_date` datetime NOT NULL,
  `action_by` varchar(100) DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `induction_it_remarks` text DEFAULT NULL,
  `induction_hr_remarks` text DEFAULT NULL,
  `induction_facility_remarks` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `checklist_id` (`checklist_id`),
  CONSTRAINT `acceptance_attempts_ibfk_1` FOREIGN KEY (`checklist_id`) REFERENCES `checklists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- 2. Add new columns to `checklists` (Supports MariaDB 10.0.2+ / MySQL 8.0.29+)
ALTER TABLE `checklists`
  ADD COLUMN IF NOT EXISTS `user_acceptance_status` enum('Pending','Accepted','Rejected') DEFAULT 'Pending',
  ADD COLUMN IF NOT EXISTS `user_acceptance_date` datetime DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `user_acceptance_ip` varchar(50) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `user_acceptance_by` varchar(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `user_acceptance_comments` text DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `induction_it_remarks` text DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `induction_hr_remarks` text DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `induction_facility_remarks` text DEFAULT NULL;

-- 3. Add new columns to `employees`
ALTER TABLE `employees`
  ADD COLUMN IF NOT EXISTS `asset_type` varchar(50) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `voice_service` varchar(50) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `email_preference` varchar(255) DEFAULT NULL,
  CHANGE created_by requested_by varchar(100) DEFAULT NULL,
  CHANGE created_at requested_at varchar(100) DEFAULT NULL,
  CHANGE template_approved_by created_by varchar(100) DEFAULT NULL,
  CHANGE template_approved_at created_at varchar(100) DEFAULT NULL;
  
-- 4. Insert new Roles (Facility, HR, General User)
INSERT IGNORE INTO `roles` (`id`, `role_name`, `description`) VALUES
(5, 'Facility User', 'Facility User'),
(6, 'HR User', 'Human Resources User'),
(7, 'User', 'This is General User Role');

-- 5. Insert new Permissions
INSERT IGNORE INTO `permissions` (`id`, `action_key`, `action_name`, `group_name`) VALUES
(77, 'view_employees', 'View Employees', 'Administration'),
(78, 'edit_employees', 'Edit Employees', 'Administration'),
(79, 'manage_employees', 'Manage Employees', 'Administration'),
(80, 'request_template', 'New Template Request ( HR )', 'Templates'),
(81, 'view_column_requested_by', 'View "Requested By" Column', 'Templates'),
(82, 'download_user_acceptance_pdf', 'Download User Acceptance', 'System Readiness'),
(83, 'view_employee_status', 'View Employee Status', 'Templates'),
(84, 'user_view', 'User View', 'User View'),
(85, 'nav_my_profile_dropdown', 'View Profile ( Only for Support Team )', 'User View'),
(86, 'nav_my_profile_main', 'View Profile ( Only for Users/Employees )', 'User View'),
(87, 'enable_maintenance_mode', 'Enable Maintenance Mode', 'Administration');

-- 6. Insert new Role-Permission Mappings
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(2, 77), (2, 78), (2, 79), (2, 81), (2, 82), (2, 83), (2, 84), (2, 85), (2, 87),
(3, 77), (3, 78), (3, 79), (3, 81), (3, 82), (3, 83), (3, 84), (3, 85),
(4, 77), (4, 81), (4, 82), (4, 83), (4, 84), (4, 85),
(5, 33), (5, 37), (5, 38), (5, 39), (5, 47), (5, 52), (5, 55), (5, 59), (5, 62), (5, 68), (5, 71), (5, 72), (5, 73), (5, 75), (5, 83), (5, 84), (5, 85),
(6, 1), (6, 24), (6, 33), (6, 47), (6, 51), (6, 52), (6, 54), (6, 55), (6, 56), (6, 57), (6, 58), (6, 59), (6, 66), (6, 67), (6, 68), (6, 71), (6, 75), (6, 77), (6, 78), (6, 79), (6, 80), (6, 81), (6, 82), (6, 83), (6, 84), (6, 85),
(7, 75), (7, 84), (7, 86);

-- 7. Insert new columns to shipments
ALTER TABLE shipments 
ADD COLUMN delivered_on DATE NULL AFTER shipped_on, 
ADD COLUMN cancelled_on DATE NULL AFTER delivered_on;

-- 8. Adding the new columns on Logs Tables
ALTER TABLE system_logs ADD COLUMN ip_address VARCHAR(45) DEFAULT NULL;
ALTER TABLE action_logs ADD COLUMN ip_address VARCHAR(45) DEFAULT NULL;