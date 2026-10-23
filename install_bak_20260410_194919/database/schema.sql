SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;


--
-- Table structure for table `action_logs`
--

CREATE TABLE `action_logs` (
  `id` int(11) NOT NULL,
  `joiner_id` int(11) DEFAULT NULL,
  `template_id` int(11) DEFAULT NULL,
  `action_type` varchar(50) DEFAULT NULL,
  `action_description` text DEFAULT NULL,
  `performed_by` varchar(100) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `field_changed` varchar(100) DEFAULT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `checklists`
--

CREATE TABLE `checklists` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `checklist_type` varchar(50) NOT NULL COMMENT 'e.g., ''system_readiness'', ''exit''',
  `checklist_purpose` varchar(255) NOT NULL DEFAULT 'New Joiner',
  `purpose_remarks` text DEFAULT NULL,
  `overall_status` varchar(50) NOT NULL DEFAULT 'Not Started',
  `assigned_to` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`assigned_to`)),
  `assignment_history` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`assignment_history`)),
  `prepared_by` varchar(255) DEFAULT NULL,
  `prepared_at` datetime DEFAULT NULL,
  `approved_by` varchar(255) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_working_day` date DEFAULT NULL,
  `hostname` varchar(100) DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `system_model` varchar(255) DEFAULT NULL,
  `processor_ram_hdd_ssd` varchar(255) DEFAULT NULL,
  `operating_system` varchar(255) DEFAULT NULL,
  `ms_office_version` varchar(255) DEFAULT NULL,
  `gatepass_id` int(11) DEFAULT NULL,
  `asset_form_path` varchar(255) DEFAULT NULL,
  `asset_form_updated_by` varchar(100) DEFAULT NULL,
  `asset_form_updated_at` datetime DEFAULT NULL,
  `asset_verified_by` varchar(255) DEFAULT NULL,
  `backup_verified_by` varchar(255) DEFAULT NULL,
  `voip_verified_by` varchar(255) DEFAULT NULL,
  `email_sent_to_it_by` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `checklist_items`
--

CREATE TABLE `checklist_items` (
  `id` int(11) NOT NULL,
  `template_id` int(11) DEFAULT NULL,
  `checklist_category` varchar(255) DEFAULT NULL,
  `task_description` varchar(255) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `updated_by` varchar(100) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- NEW Table structure for table `checklist_templates`
--

CREATE TABLE `checklist_templates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `template_name` VARCHAR(255) NOT NULL,
  `template_type` ENUM('system_readiness', 'exit_checklist') NOT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- --------------------------------------------------------

--
-- NEW Table structure for table `checklist_template_categories`
--

CREATE TABLE `checklist_template_categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `template_id` INT NOT NULL,
  `category_name` VARCHAR(255) NOT NULL,
  `display_order` INT NOT NULL DEFAULT 0,
  FOREIGN KEY (`template_id`) REFERENCES `checklist_templates`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --------------------------------------------------------

--
-- NEW Table structure for table `checklist_template_items`
--

CREATE TABLE `checklist_template_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `category_id` INT NOT NULL,
  `task_description` TEXT NOT NULL,
  `is_critical` BOOLEAN NOT NULL DEFAULT FALSE,
  `display_order` INT NOT NULL DEFAULT 0,
  FOREIGN KEY (`category_id`) REFERENCES `checklist_template_categories`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `id` int(11) NOT NULL,
  `emp_id` varchar(50) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `email_id` varchar(100) DEFAULT NULL,
  `email_password` varchar(255) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `workstation` varchar(50) DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL,
  `date_of_joining` date DEFAULT NULL,
  `domain_username` varchar(100) DEFAULT NULL,
  `domain_password` varchar(255) DEFAULT NULL,
  `o365_edition` varchar(50) DEFAULT NULL,
  `network_drives` varchar(50) DEFAULT NULL,
  `ms_teams` varchar(50) DEFAULT NULL,
  `did` varchar(50) DEFAULT NULL,
  `employee_status` varchar(50) NOT NULL DEFAULT 'Active',
  `remarks` text DEFAULT NULL,
  `employee_type` varchar(50) NOT NULL DEFAULT 'existing_employee',
  `created_by` varchar(100) DEFAULT NULL,
  `it_induction_status` varchar(50) DEFAULT 'Pending',
  `it_induction_conducted_by` varchar(100) DEFAULT NULL,
  `hr_induction_status` varchar(50) DEFAULT 'Pending',
  `hr_induction_conducted_by` varchar(100) DEFAULT NULL,
  `facility_induction_status` varchar(50) DEFAULT 'Pending',
  `facility_induction_conducted_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `template_status` varchar(50) NOT NULL DEFAULT 'Pending Approval',
  `template_approved_by` varchar(100) DEFAULT NULL,
  `template_approved_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gatepass_items`
--

CREATE TABLE `gatepass_items` (
  `id` int(11) NOT NULL,
  `gatepass_id` int(11) NOT NULL,
  `description` text NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `remarks` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gatepass_requests`
--

CREATE TABLE `gatepass_requests` (
  `id` int(11) NOT NULL,
  `gatepass_no` varchar(20) DEFAULT NULL,
  `gatepass_type` varchar(25) DEFAULT NULL,
  `ticket_no` varchar(255) DEFAULT NULL,
  `employee_name` varchar(255) DEFAULT NULL,
  `employee_user_id` int(11) DEFAULT NULL,
  `date_of_joining` date DEFAULT NULL,
  `position` varchar(255) DEFAULT NULL,
  `department` varchar(255) DEFAULT NULL,
  `purpose` varchar(100) NOT NULL DEFAULT 'Employee Asset',
  `pickup_person` varchar(255) DEFAULT NULL,
  `vendor_id` int(11) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `out_time` datetime DEFAULT NULL,
  `in_time` datetime DEFAULT NULL,
  `expected_return_date` date DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'Pending Approval',
  `approved_by` varchar(255) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `prepared_by` varchar(255) DEFAULT NULL,
  `security_out_time` time DEFAULT NULL,
  `security_out_date` date DEFAULT NULL,
  `security_person_name` varchar(255) DEFAULT NULL,
  `security_verified_by` varchar(255) DEFAULT NULL,
  `created_by` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `from_location` varchar(255) DEFAULT NULL,
  `to_location` varchar(255) DEFAULT NULL,
  `requested_by` varchar(255) DEFAULT NULL,
  `received_by` varchar(255) DEFAULT NULL,
  `security_in_time` time DEFAULT NULL,
  `security_in_date` date DEFAULT NULL,
  `security_in_person_name` varchar(255) DEFAULT NULL,
  `security_in_verified_by` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gp_vendors`
--

CREATE TABLE `gp_vendors` (
  `id` int(11) NOT NULL,
  `vendor_name` varchar(255) NOT NULL,
  `pickup_person` varchar(255) DEFAULT NULL,
  `contact_mobile` varchar(20) DEFAULT NULL,
  `contact_email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ldap_groups`
--

CREATE TABLE `ldap_groups` (
  `id` int(11) NOT NULL,
  `group_dn` text NOT NULL,
  `group_name` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `locations`
--

CREATE TABLE `locations` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` int(11) NOT NULL,
  `action_key` varchar(100) NOT NULL,
  `action_name` varchar(255) NOT NULL,
  `group_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `action_key`, `action_name`, `group_name`) VALUES

(1, 'view_admin_menu', 'View Administration Menu', 'Administration'),
(2, 'view_users_tab', 'View Users Tab', 'Administration'),
(3, 'edit_user_role', 'Edit User Role', 'Administration'),
(4, 'delete_user', 'Delete User', 'Administration'),
(5, 'view_roles_tab', 'View Roles Tab', 'Administration'),
(6, 'create_role', 'Create Role', 'Administration'),
(7, 'edit_role', 'Edit Role', 'Administration'),
(8, 'delete_role', 'Delete Role', 'Administration'),
(9, 'view_permissions_tab', 'View Permissions Tab', 'Administration'),
(10, 'edit_permissions', 'Edit Permissions', 'Administration'),
(11, 'view_ldap_tab', 'View LDAP Tab', 'Administration'),
(12, 'edit_ldap_config', 'Edit LDAP Config', 'Administration'),
(13, 'view_group_mapping_tab', 'View Group Mapping', 'Administration'),
(14, 'edit_group_mapping', 'Edit Group Mapping', 'Administration'),
(15, 'view_manage_forms_page', 'View Manage Form Data Page', 'Administration'),
(16, 'view_departments_tab', 'View Departments Tab', 'Administration'),
(17, 'create_department', 'Create Department', 'Administration'),
(18, 'edit_department', 'Edit Department', 'Administration'),
(19, 'delete_department', 'Delete Department', 'Administration'),
(20, 'view_locations_tab', 'View Locations Tab', 'Administration'),
(21, 'create_location', 'Create Location', 'Administration'),
(22, 'edit_location', 'Edit Location', 'Administration'),
(23, 'delete_location', 'Delete Location', 'Administration'),
(24, 'view_checklist_menu', 'View Checklist Menu', 'Checklist'),
(25, 'view_exit_checklist', 'View Exit Checklist List', 'Checklist'),
(26, 'create_exit_checklist', 'Create Exit Checklist', 'Checklist'),
(27, 'manage_exit_checklist', 'Manage Exit Checklist', 'Checklist'),
(28, 'approve_exit_checklist', 'Approve Exit Checklist', 'Checklist'),
(29, 'delete_exit_checklist', 'Delete Exit Checklist', 'Checklist'),
(30, 'edit_exit_checklist_without_assign', 'Edit Checklist without Assignment', 'Checklist'),
(31, 'download_exit_checklist_pdf', 'Download Exit Checklist PDF', 'Checklist'),
(32, 'view_dashboard', 'View Dashboard', 'Dashboard'),
(33, 'view_gatepass', 'View Gatepass', 'Gatepass'),
(34, 'create_gatepass', 'Create Gatepass', 'Gatepass'),
(35, 'edit_gatepass_details', 'Edit Gatepass Details', 'Gatepass'),
(36, 'delete_gatepass', 'Delete Gatepass', 'Gatepass'),
(37, 'approve_gatepass', 'Approve Gatepass', 'Gatepass'),
(38, 'download_gatepass_pdf', 'Download Gatepass PDF', 'Gatepass'),
(39, 'edit_security_fields', 'Edit Security Fields', 'Gatepass'),
(40, 'view_reports', 'View Reports', 'Reports'),
(41, 'view_system_readiness', 'View System Readiness', 'System Readiness'),
(42, 'manage_system_readiness', 'Manage System Readiness', 'System Readiness'),
(43, 'verify_system_readiness', 'Verify System Readiness', 'System Readiness'),
(44, 'edit_without_assign', 'Edit Checklist without Assignment', 'System Readiness'),
(45, 'download_checklist_pdf', 'Download Checklist PDF', 'System Readiness'),
(46, 'view_audit_trail', 'View System Audit Trail', 'System Logs'),
(47, 'view_templates_list', 'View Templates List', 'Templates'),
(48, 'create_template', 'Create Template', 'Templates'),
(49, 'approve_template', 'Approve Template', 'Templates'),
(50, 'delete_template', 'Delete Template', 'Templates'),
(51, 'download_template_pdf', 'Download Template PDF', 'Templates'),
(52, 'view_employee_details', 'View Employee Details', 'Templates'),
(53, 'view_domain_details', 'View Domain Details', 'Templates'),
(54, 'view_system_info', 'View System Information', 'Templates'),
(55, 'view_induction_details', 'View Induction Details', 'Templates'),
(56, 'edit_template_basic', 'Edit Template Basic Details', 'Templates'),
(57, 'edit_induction_it', 'Edit IT Induction', 'Templates'),
(58, 'edit_induction_hr', 'Edit HR Induction', 'Templates'),
(59, 'edit_induction_facility', 'Edit Facility Induction', 'Templates'),
(60, 'view_template_readiness_tab', 'View System Readiness Tab', 'Templates'),
(61, 'view_activity_timeline', 'View Activity Timeline', 'Templates'),
(62, 'view_timeline_template_events', 'View Timeline (Template Events)', 'Templates'),
(63, 'view_timeline_readiness_events', 'View Timeline (System Readiness Events)', 'Templates'),
(64, 'view_column_created_by', 'View \"Created By\" Column', 'Templates'),
(65, 'view_column_system_readiness', 'View \"System Readiness\" Column', 'Templates'),
(66, 'view_column_it_induction', 'View \"IT Induction\" Column', 'Templates'),
(67, 'view_column_hr_induction', 'View \"HR Induction\" Column', 'Templates'),
(68, 'view_column_facility_induction', 'View \"Facility Induction\" Column', 'Templates'),
(69, 'view_synced_groups_tab', 'View Groups Tab', 'Administration'),
(70, 'delete_synced_group', 'Delete Group', 'Administration'),
(71, 'view_shipment', 'View Shipment', 'Shipment'),
(72, 'create_shipment', 'Create Shipment', 'Shipment'),
(73, 'edit_shipment', 'Edit Shipment', 'Shipment'),
(74, 'delete_shipment', 'Delete Shipment', 'Shipment'),
(75, 'view_portals_menu', 'View Portals Page', 'Portals'),
(76, 'manage_portals', 'Manage Portals', 'Portals');

-- --------------------------------------------------------

--
-- Table structure for table `portals`
--
CREATE TABLE `portal_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_by` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_name` (`category_name`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE `portals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `url` varchar(512) NOT NULL,
  `icon_path` varchar(255) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `category_id` int(11) DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_portal_category` (`category_id`),
  CONSTRAINT `fk_portal_category` FOREIGN KEY (`category_id`) REFERENCES `portal_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `portal_department_visibility` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `portal_id` int(11) NOT NULL,
  `department_name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `portal_dept` (`portal_id`,`department_name`),
  KEY `portal_id` (`portal_id`),
  CONSTRAINT `fk_portal_id` FOREIGN KEY (`portal_id`) REFERENCES `portals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `role_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `roles` (`id`, `role_name`, `description`) VALUES
	(1, 'Super Admin', 'Full Access'),
        (2, 'IT Manager', 'IT Support Manager'),
	(3, 'Sr. Technician', 'Sr. IT Support Engineer'),
	(4, 'Technician', 'IT Support Engineer');

-- --------------------------------------------------------

--
-- Table structure for table `role_ldap_group_mapping`
--

CREATE TABLE `role_ldap_group_mapping` (
  `role_id` int(11) NOT NULL,
  `ldap_group_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(2, 1),
	(2, 2),
	(2, 3),
	(2, 4),
	(2, 5),
	(2, 6),
	(2, 7),
	(2, 8),
	(2, 9),
	(2, 10),
	(2, 11),
	(2, 12),
	(2, 13),
	(2, 14),
	(2, 15),
	(2, 16),
	(2, 17),
	(2, 18),
	(2, 19),
	(2, 20),
	(2, 21),
	(2, 22),
	(2, 23),
	(2, 24),
	(2, 25),
	(2, 26),
	(2, 27),
	(2, 28),
	(2, 29),
	(2, 30),
	(2, 31),
	(2, 32),
	(2, 33),
	(2, 34),
	(2, 35),
	(2, 36),
	(2, 37),
	(2, 38),
	(2, 39),
	(2, 40),
	(2, 41),
	(2, 42),
	(2, 43),
	(2, 44),
	(2, 45),
	(2, 46),
	(2, 47),
	(2, 48),
	(2, 49),
	(2, 50),
	(2, 51),
	(2, 52),
	(2, 53),
	(2, 54),
	(2, 55),
	(2, 56),
	(2, 57),
	(2, 58),
	(2, 59),
	(2, 60),
	(2, 61),
	(2, 62),
	(2, 63),
	(2, 64),
	(2, 65),
	(2, 66),
	(2, 67),
	(2, 68),
	(2, 69),
	(2, 70),
	(2, 71),
	(2, 72),
	(2, 73),
	(2, 74),
	(2, 75),
	(2, 76),
	(3, 1),
	(3, 2),
	(3, 3),
	(3, 5),
	(3, 9),
	(3, 11),
	(3, 13),
	(3, 14),
	(3, 15),
	(3, 16),
	(3, 17),
	(3, 18),
	(3, 20),
	(3, 21),
	(3, 22),
	(3, 24),
	(3, 25),
	(3, 26),
	(3, 27),
	(3, 28),
	(3, 31),
	(3, 32),
	(3, 33),
	(3, 34),
	(3, 35),
	(3, 37),
	(3, 38),
	(3, 39),
	(3, 40),
	(3, 41),
	(3, 42),
	(3, 43),
	(3, 45),
	(3, 47),
	(3, 48),
	(3, 49),
	(3, 51),
	(3, 52),
	(3, 53),
	(3, 54),
	(3, 55),
	(3, 56),
	(3, 57),
	(3, 58),
	(3, 59),
	(3, 60),
	(3, 61),
	(3, 62),
	(3, 63),
	(3, 64),
	(3, 65),
	(3, 66),
	(3, 69),
	(3, 71),
	(3, 72),
	(3, 73),
	(3, 75),
	(3, 76),
	(4, 1),
	(4, 2),
	(4, 5),
	(4, 9),
	(4, 11),
	(4, 13),
	(4, 16),
	(4, 20),
	(4, 24),
	(4, 25),
	(4, 26),
	(4, 27),
	(4, 31),
	(4, 32),
	(4, 33),
	(4, 34),
	(4, 35),
	(4, 38),
	(4, 39),
	(4, 40),
	(4, 41),
	(4, 42),
	(4, 45),
	(4, 47),
	(4, 48),
	(4, 51),
	(4, 52),
	(4, 53),
	(4, 54),
	(4, 55),
	(4, 57),
	(4, 58),
	(4, 59),
	(4, 60),
	(4, 61),
	(4, 62),
	(4, 63),
	(4, 64),
	(4, 65),
	(4, 66),
	(4, 69),
	(4, 71),
	(4, 72),
	(4, 75);

-- --------------------------------------------------------

--
-- NEW Table structure for table `shipments`
--

CREATE TABLE `shipments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `gatepass_id` INT NOT NULL UNIQUE,
  `shipment_status` ENUM('New', 'Shipped', 'In Transit', 'Delivered', 'Cancelled') NOT NULL DEFAULT 'New',
  `courier_name` VARCHAR(100) DEFAULT NULL,
  `tracking_number` VARCHAR(100) DEFAULT NULL,
  `shipped_on` DATE DEFAULT NULL,
  `recipient_name` VARCHAR(255) NOT NULL,
  `recipient_address` TEXT NOT NULL,
  `recipient_phone` VARCHAR(20) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` VARCHAR(255) NOT NULL,
  `updated_by` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- --------------------------------------------------------

--
-- Table structure for table `system_logs`
--

CREATE TABLE `system_logs` (
  `id` int(11) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `performed_by` varchar(255) DEFAULT NULL,
  `action_type` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `firstname` varchar(50) DEFAULT NULL,
  `lastname` varchar(50) DEFAULT NULL,
  `role_id` int(11) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

ALTER TABLE `action_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_template_id` (`template_id`);

ALTER TABLE `checklists`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_id_idx` (`employee_id`),
  ADD KEY `checklist_type_idx` (`checklist_type`);

ALTER TABLE `checklist_items`
  ADD PRIMARY KEY (`id`);

-- NEW Indexes for checklist_templates (only foreign key indexes here) --
ALTER TABLE `checklist_template_categories`
  ADD KEY `template_id` (`template_id`);
ALTER TABLE `checklist_template_items`
  ADD KEY `category_id` (`category_id`);

ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `emp_id` (`emp_id`),
  ADD UNIQUE KEY `email_id` (`email_id`);

ALTER TABLE `gatepass_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `gatepass_id` (`gatepass_id`);

ALTER TABLE `gatepass_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `gatepass_no` (`gatepass_no`),
  ADD KEY `fk_gatepass_gp_vendor` (`vendor_id`);

ALTER TABLE `gp_vendors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `vendor_name` (`vendor_name`);

ALTER TABLE `ldap_groups`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `locations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `action_key` (`action_key`);

ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_name` (`role_name`);

ALTER TABLE `role_ldap_group_mapping`
  ADD PRIMARY KEY (`role_id`,`ldap_group_id`),
  ADD KEY `fk_ldap_group_id` (`ldap_group_id`);

ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`role_id`,`permission_id`),
  ADD KEY `permission_id` (`permission_id`);

ALTER TABLE `system_logs`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `role_id` (`role_id`);

--
-- AUTO_INCREMENT for dumped tables
--

ALTER TABLE `action_logs` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
ALTER TABLE `checklists` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
ALTER TABLE `checklist_items` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
-- NEW AUTO_INCREMENT for checklist_templates --
ALTER TABLE `checklist_templates` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
ALTER TABLE `checklist_template_categories` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
ALTER TABLE `checklist_template_items` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
ALTER TABLE `departments` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
ALTER TABLE `employees` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
ALTER TABLE `gatepass_items` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
ALTER TABLE `gatepass_requests` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
ALTER TABLE `gp_vendors` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
ALTER TABLE `ldap_groups` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
ALTER TABLE `locations` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
ALTER TABLE `permissions` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=74;
ALTER TABLE `roles` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
-- NEW AUTO_INCREMENT for shipments --
ALTER TABLE `shipments` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
ALTER TABLE `system_logs` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
ALTER TABLE `users` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- Constraints for dumped tables
--

ALTER TABLE `gatepass_requests`
  ADD CONSTRAINT `fk_gatepass_gp_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `gp_vendors` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `role_ldap_group_mapping`
  ADD CONSTRAINT `fk_ldap_group_id` FOREIGN KEY (`ldap_group_id`) REFERENCES `ldap_groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_role_id_mapping` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `role_permissions`
  ADD CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE;

ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL;

--
-- Add constraint for shipments table
--
ALTER TABLE `shipments`
  ADD CONSTRAINT `fk_shipments_gatepass_id` FOREIGN KEY (`gatepass_id`) REFERENCES `gatepass_requests` (`id`) ON DELETE CASCADE;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;