<?php
// pages/settings.php

include_once '../includes/db_connect.php';
include_once '../includes/auth.php';

// A user can access this page if they can view ANY of the admin tabs
if (!can('view_users_tab') && !can('view_roles_tab') && !can('view_permissions_tab') && !can('view_ldap_tab') && !can('view_group_mapping_tab') && !can('view_synced_groups_tab') && !can('view_admin_menu')) {
    checkPermission('view_admin_menu'); // Fallback to trigger the standard "no permission" redirect
}

// --- START: AJAX HANDLERS ---
if (isset($_GET['action']) && $_GET['action'] == 'get_role_users' && isset($_GET['role_id'])) {
    header('Content-Type: text/html');
    $role_id = (int)$_GET['role_id'];
    $users_in_role = [];
    $stmt = $conn->prepare("SELECT firstname, lastname, department, username FROM users WHERE role_id = ? ORDER BY lastname, firstname");
    $stmt->bind_param("i", $role_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $users_in_role[] = $row; }
    $stmt->close();

    if (empty($users_in_role)) {
        echo '<div class="text-center text-muted p-4"><i data-feather="users" class="mb-2" style="width:32px; height:32px; opacity:0.3;"></i><br>No users are currently assigned to this role.</div>';
    } else {
        echo '<ul class="list-group list-group-flush">';
        foreach ($users_in_role as $user) {
            echo '<li class="list-group-item d-flex justify-content-between align-items-center border-0 py-2 px-3">';
            echo '<div><div class="fw-medium text-dark">' . htmlspecialchars($user['firstname'] . ' ' . $user['lastname']) . '</div><div class="small text-muted">' . htmlspecialchars($user['username']) . '</div></div>';
            echo '<span class="badge bg-secondary-subtle text-secondary-emphasis rounded-pill">' . htmlspecialchars($user['department']) . '</span>';
            echo '</li>';
        }
        echo '</ul>';
    }
    exit();
}

if (isset($_POST['action']) && $_POST['action'] == 'test_ldap_connection') {
    header('Content-Type: application/json');
    if (!can('view_ldap_tab')) { echo json_encode(['success' => false, 'message' => 'Permission denied.']); exit(); }
    if (file_exists(__DIR__ . '/../includes/ldap_config.php')) { include_once __DIR__ . '/../includes/ldap_config.php'; } else { echo json_encode(['success' => false, 'message' => 'LDAP config file not found. Please save your settings first.']); exit(); }
    
    $test_user = trim($_POST['test_username'] ?? ''); $test_pass = $_POST['test_password'] ?? '';
    if (empty($test_user) || empty($test_pass)) { echo json_encode(['success' => false, 'message' => 'Username and password are required for the test.']); exit(); }

    ldap_set_option(null, LDAP_OPT_NETWORK_TIMEOUT, 5);
    $ldap_conn = @ldap_connect(defined('LDAP_HOST') ? LDAP_HOST : '', (int)(defined('LDAP_PORT') ? LDAP_PORT : 389));
    if (!$ldap_conn) { echo json_encode(['success' => false, 'message' => 'Connection Failed: Could not connect to LDAP host.']); exit(); }
    
    ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ldap_conn, LDAP_OPT_REFERRALS, 0);

    $bind_dn = defined('LDAP_BIND_DN') ? LDAP_BIND_DN : ''; $bind_pass = defined('LDAP_BIND_PASSWORD') ? LDAP_BIND_PASSWORD : '';
    $service_bind = (!empty($bind_dn) && !empty($bind_pass)) ? @ldap_bind($ldap_conn, $bind_dn, $bind_pass) : @ldap_bind($ldap_conn);
    if (!$service_bind) { echo json_encode(['success' => false, 'message' => 'Service Bind Failed.']); exit(); }
    
    $login_attribute = defined('LDAP_LOGIN_ATTRIBUTE') ? LDAP_LOGIN_ATTRIBUTE : 'sAMAccountName';
    $base_filter = defined('LDAP_USER_SEARCH_FILTER') ? LDAP_USER_SEARCH_FILTER : ''; 
    $user_filter = "(&($login_attribute=" . ldap_escape($test_user, "", LDAP_ESCAPE_FILTER) . ")$base_filter)";

    $search = @ldap_search($ldap_conn, defined('LDAP_BASE_DN') ? LDAP_BASE_DN : '', $user_filter, ['dn']);
    if (!$search) { echo json_encode(['success' => false, 'message' => 'User Search Failed.']); exit(); }
    
    $entries = ldap_get_entries($ldap_conn, $search);
    if ($entries['count'] == 0) { echo json_encode(['success' => false, 'message' => 'User Not Found in directory.']); exit(); }
    
    $user_dn = $entries[0]['dn'];
    $user_bind = @ldap_bind($ldap_conn, $user_dn, $test_pass);
    ldap_close($ldap_conn);
    
    if ($user_bind) { echo json_encode(['success' => true, 'message' => 'Success! Authentication successful.']); } 
    else { echo json_encode(['success' => false, 'message' => 'Authentication Failed: Incorrect password.']); }
    exit();
}
// --- END: AJAX HANDLERS ---

// --- START: FORM & GET HANDLERS ---
function updateLdapConfigFile($config) {
    $filePath = __DIR__ . '/../includes/ldap_config.php';
    if ((file_exists($filePath) && !is_writable($filePath)) || (!file_exists($filePath) && !is_writable(dirname($filePath)))) { return false; }
    $content = "<?php\n// LDAP Configuration - Last updated: " . date('Y-m-d H:i:s') . "\n";
    foreach ($config as $key => $value) {
        $formattedValue = is_bool($value) ? ($value ? 'true' : 'false') : "'" . addslashes($value) . "'";
        $content .= "define('" . $key . "', " . $formattedValue . ");\n";
    }
    if (file_put_contents($filePath, $content) !== false) {
        if (function_exists('opcache_invalidate')) { opcache_invalidate($filePath, true); } return true;
    }
    return false;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];

    // --- MAINTENANCE FLAG FILE LOGIC ---
    if ($action == 'save_maintenance_config' && (can('enable_maintenance_mode') || $_SESSION['user_id'] == 1)) {
        $maintenance_file = __DIR__ . '/../maintenance.enable';
        $is_enabled = isset($_POST['enable_maintenance']);
        $allowed_ips = trim($_POST['maintenance_allowed_ips']);
        
        if ($is_enabled) {
            $current_ip = $_SERVER['REMOTE_ADDR'];
            $ip_array = array_filter(array_map('trim', explode(',', $allowed_ips)));
            if (!in_array($current_ip, $ip_array)) {
                $ip_array[] = $current_ip;
            }
            $final_ips = implode(', ', $ip_array);
            
            if (file_put_contents($maintenance_file, $final_ips) !== false) {
                $_SESSION['form_success'] = 'Maintenance mode enabled successfully.';
                log_system_event($conn, 'System', 'Maintenance mode ENABLED. Allowed IPs: ' . $final_ips);
            } else { 
                $_SESSION['form_error'] = 'Failed to write maintenance file. Check server permissions.'; 
            }
        } else {
            if (file_exists($maintenance_file)) {
                unlink($maintenance_file);
            }
            $_SESSION['form_success'] = 'Maintenance mode disabled.';
            log_system_event($conn, 'System', 'Maintenance mode DISABLED.');
        }
        header('Location: ' . APP_BASE_PATH . 'index.php?route=settings&tab=maintenance');
        exit();
    }

    if ($action == 'save_ldap_config' && can('edit_ldap_config')) {
        if (file_exists(__DIR__ . '/../includes/ldap_config.php')) { include_once __DIR__ . '/../includes/ldap_config.php'; }
        $new_config = [
            'ENABLE_LDAP_AUTH' => isset($_POST['enable_ldap_auth']),
            'LDAP_HOST' => trim($_POST['ldap_host']),
            'LDAP_PORT' => trim($_POST['ldap_port']),
            'LDAP_BASE_DN' => trim($_POST['ldap_base_dn']),
            'LDAP_DOMAIN' => trim($_POST['ldap_domain']),
            'LDAP_LOGIN_ATTRIBUTE' => trim($_POST['ldap_login_attribute']),
            'LDAP_USER_SEARCH_FILTER' => trim($_POST['ldap_user_search_filter']),
            'LDAP_BIND_DN' => trim($_POST['ldap_bind_dn'])
        ];
        $new_password = trim($_POST['ldap_bind_password']);
        $new_config['LDAP_BIND_PASSWORD'] = !empty($new_password) ? $new_password : (defined('LDAP_BIND_PASSWORD') ? LDAP_BIND_PASSWORD : '');
        if (updateLdapConfigFile($new_config)) {
            $_SESSION['form_success'] = 'LDAP configuration saved successfully.';
            log_system_event($conn, 'System', 'LDAP configuration was updated.');
        } else { $_SESSION['form_error'] = 'Failed to write to includes/ldap_config.php. Please check file permissions.'; }
        header('Location: ' . APP_BASE_PATH . 'index.php?route=settings&tab=ldap');
        exit();
    }
    
    if ($action == 'search_ldap_groups' && can('edit_group_mapping')) {
        $keyword = trim($_POST['group_search_keyword'] ?? '');
        if (strlen($keyword) < 2) { $_SESSION['form_error'] = 'Please enter at least 2 characters to search for groups.'; header('Location: ' . APP_BASE_PATH . 'index.php?route=settings&tab=group_mapping'); exit(); }
        if (file_exists(__DIR__ . '/../includes/ldap_config.php')) { include_once __DIR__ . '/../includes/ldap_config.php'; }
        if (!defined('ENABLE_LDAP_AUTH') || !ENABLE_LDAP_AUTH || !defined('LDAP_HOST') || !defined('LDAP_BASE_DN')) {
            $_SESSION['form_error'] = 'LDAP is not enabled or configured. Please check LDAP settings.'; header('Location: ' . APP_BASE_PATH . 'index.php?route=settings&tab=group_mapping'); exit();
        }
        
        ldap_set_option(null, LDAP_OPT_NETWORK_TIMEOUT, 5);
        $ldap_conn = @ldap_connect(LDAP_HOST, (int)LDAP_PORT);
        if ($ldap_conn) {
            ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, 3); ldap_set_option($ldap_conn, LDAP_OPT_REFERRALS, 0);
            $bind_dn = defined('LDAP_BIND_DN') ? LDAP_BIND_DN : ''; $bind_pass = defined('LDAP_BIND_PASSWORD') ? LDAP_BIND_PASSWORD : '';
            $bind = (!empty($bind_dn) && !empty($bind_pass)) ? @ldap_bind($ldap_conn, $bind_dn, $bind_pass) : @ldap_bind($ldap_conn);
            if ($bind) {
                ldap_set_option($ldap_conn, LDAP_OPT_TIMELIMIT, 10);
                $filter = "(&(objectCategory=group)(cn=*".ldap_escape($keyword, "", LDAP_ESCAPE_FILTER)."*))";
                $search = @ldap_search($ldap_conn, LDAP_BASE_DN, $filter, ['dn', 'cn']);
                if ($search) {
                    $entries = ldap_get_entries($ldap_conn, $search);
                    if ($entries['count'] > 0) {
                        $local_groups_res = $conn->query("SELECT group_dn FROM ldap_groups");
                        $local_group_dns = []; while ($row = $local_groups_res->fetch_assoc()) { $local_group_dns[] = $row['group_dn']; }
                        $new_groups_added_count = 0;
                        $stmt = $conn->prepare("INSERT INTO ldap_groups (group_dn, group_name) VALUES (?, ?)");
                        for ($i = 0; $i < $entries['count']; $i++) {
                            if (isset($entries[$i]['dn']) && isset($entries[$i]['cn'][0])) {
                                $dn = $entries[$i]['dn'];
                                if (!in_array($dn, $local_group_dns)) {
                                    $cn = $entries[$i]['cn'][0];
                                    $stmt->bind_param("ss", $dn, $cn); $stmt->execute();
                                    $new_groups_added_count++;
                                }
                            }
                        }
                        $stmt->close();
                        if ($new_groups_added_count > 0) { $_SESSION['form_success'] = "Sync complete. Added " . $new_groups_added_count . " new groups."; log_system_event($conn, 'LDAP', 'Added new groups from AD.'); } 
                        else { $_SESSION['form_success'] = "Sync complete. No new groups were found."; }
                    } else { $_SESSION['form_error'] = 'No groups found in LDAP matching "' . htmlspecialchars($keyword) . '".'; }
                } else { $_SESSION['form_error'] = "The LDAP search timed out or failed."; }
            } else { $_SESSION['form_error'] = 'Could not bind to LDAP server.'; }
            ldap_close($ldap_conn);
        } else { $_SESSION['form_error'] = 'Could not connect to LDAP server.'; }
        header('Location: ' . APP_BASE_PATH . 'index.php?route=settings&tab=group_mapping');
        exit();
    }
    
    if ($action == 'save_group_mappings' && can('edit_group_mapping')) {
        $mappings = $_POST['mappings'] ?? [];
        $mapped_groups = [];
        foreach ($mappings as $role_id => $ldap_group_id) {
            if (!empty($ldap_group_id)) {
                if (in_array($ldap_group_id, $mapped_groups)) { $_SESSION['form_error'] = 'Each LDAP group can only be mapped to one role.'; header('Location: ' . APP_BASE_PATH . 'index.php?route=settings&tab=group_mapping'); exit(); }
                $mapped_groups[] = $ldap_group_id;
            }
        }
        $conn->begin_transaction();
        try {
            $conn->query("DELETE FROM role_ldap_group_mapping");
            if (!empty($mappings)) {
                $stmt = $conn->prepare("INSERT INTO role_ldap_group_mapping (role_id, ldap_group_id) VALUES (?, ?)");
                foreach ($mappings as $role_id => $ldap_group_id) {
                    if (!empty($ldap_group_id)) { $stmt->bind_param("ii", $role_id, $ldap_group_id); $stmt->execute(); }
                }
                $stmt->close();
            }
            $conn->commit();
            $_SESSION['form_success'] = 'LDAP group mappings saved successfully.';
            log_system_event($conn, 'LDAP Group', 'LDAP group to role mappings were updated.');
        } catch (Exception $e) { $conn->rollback(); $_SESSION['form_error'] = 'An error occurred while saving the mappings.'; }
        header('Location: ' . APP_BASE_PATH . 'index.php?route=settings&tab=group_mapping');
        exit();
    }
    
    if ($action == 'save_role_permissions' && can('edit_permissions')) {
        $role_id = $_POST['role_id'];
        if ($role_id == 1) { $_SESSION['form_error'] = 'Super Admin role permissions cannot be modified.'; header('Location: ' . APP_BASE_PATH . 'index.php?route=settings&tab=permissions&selected_role=' . $role_id); exit(); }
        $posted_permissions = $_POST['permissions'] ?? [];
        $conn->begin_transaction();
        try {
            $stmt_delete = $conn->prepare("DELETE FROM role_permissions WHERE role_id = ?");
            $stmt_delete->bind_param("i", $role_id); $stmt_delete->execute();
            if (!empty($posted_permissions)) {
                $stmt_insert = $conn->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                foreach ($posted_permissions as $perm_id => $value) { if ($value === 'on') { $stmt_insert->bind_param("ii", $role_id, $perm_id); $stmt_insert->execute(); } }
            }
            $conn->commit(); $_SESSION['form_success'] = 'Permissions updated successfully.';
        } catch (Exception $e) { $conn->rollback(); $_SESSION['form_error'] = 'Error updating permissions.'; }
        header('Location: ' . APP_BASE_PATH . 'index.php?route=settings&tab=permissions&selected_role=' . $role_id);
        exit();
    }
    
    if ($action == 'create_role' && can('create_role')) {
        $role_name = trim($_POST['role_name']); $description = trim($_POST['description']);
        if (!empty($role_name)) {
            $stmt = $conn->prepare("INSERT INTO roles (role_name, description) VALUES (?, ?)");
            $stmt->bind_param("ss", $role_name, $description);
            if ($stmt->execute()) { $_SESSION['form_success'] = 'Role "' . htmlspecialchars($role_name) . '" created successfully.'; } 
            else { $_SESSION['form_error'] = 'Error: Role name may already exist.'; }
        } else { $_SESSION['form_error'] = 'Role name cannot be empty.'; }
        header('Location: ' . APP_BASE_PATH . 'index.php?route=settings&tab=roles');
        exit();
    }
    
    if ($action == 'edit_role' && can('edit_role')) {
        $role_id = $_POST['role_id']; $role_name = trim($_POST['role_name']); $description = trim($_POST['description']);
        if (!empty($role_name) && !empty($role_id)) {
            if ($role_id == 1) { $_SESSION['form_error'] = 'The Super Admin role cannot be modified.'; }
            else {
                $stmt = $conn->prepare("UPDATE roles SET role_name = ?, description = ? WHERE id = ?");
                $stmt->bind_param("ssi", $role_name, $description, $role_id);
                if ($stmt->execute()) { $_SESSION['form_success'] = 'Role updated successfully.'; }
                else { $_SESSION['form_error'] = 'Error updating role.'; }
            }
        } else { $_SESSION['form_error'] = 'Role name and ID cannot be empty.'; }
        header('Location: ' . APP_BASE_PATH . 'index.php?route=settings&tab=roles');
        exit();
    }
    
    if ($action == 'edit_user' && can('edit_user_role')) {
        $user_id = $_POST['user_id']; $role_id = $_POST['role_id'];
        if ($user_id != 1) {
            $stmt = $conn->prepare("UPDATE users SET role_id = ? WHERE id = ?");
            $stmt->bind_param("ii", $role_id, $user_id);
            if ($stmt->execute()) { $_SESSION['form_success'] = "User's role updated successfully."; } 
            else { $_SESSION['form_error'] = "Error updating user's role."; }
        } else { $_SESSION['form_error'] = "The initial super admin's role cannot be changed."; }
        
        // Preserve filter parameters
        $query_params = ['route' => 'settings', 'tab' => 'users'];
        if (!empty($_POST['search'])) $query_params['search'] = $_POST['search'];
        if (!empty($_POST['dept_filter'])) $query_params['dept_filter'] = $_POST['dept_filter'];
        if (!empty($_POST['role_filter'])) $query_params['role_filter'] = $_POST['role_filter'];
        if (!empty($_POST['page'])) $query_params['page'] = $_POST['page'];
        
        header('Location: ' . APP_BASE_PATH . 'index.php?' . http_build_query($query_params));
        exit();
    }
}

if (isset($_GET['delete_role']) && is_numeric($_GET['delete_role']) && can('delete_role')) {
    $role_id_to_delete = $_GET['delete_role'];
    if ($role_id_to_delete == 1) { $_SESSION['form_error'] = 'The Super Admin role cannot be deleted.'; }
    else {
        $stmt_check = $conn->prepare("SELECT COUNT(*) FROM users WHERE role_id = ?");
        $stmt_check->bind_param("i", $role_id_to_delete); $stmt_check->execute();
        $user_count = $stmt_check->get_result()->fetch_row()[0];
        if ($user_count > 0) { $_SESSION['form_error'] = 'Cannot delete role: It is currently assigned to ' . $user_count . ' user(s).'; }
        else {
            $stmt = $conn->prepare("DELETE FROM roles WHERE id = ?");
            $stmt->bind_param("i", $role_id_to_delete);
            if ($stmt->execute()) { $_SESSION['form_success'] = 'Role deleted successfully.'; }
            else { $_SESSION['form_error'] = 'Error deleting role.'; }
        }
    }
    header('Location: ' . APP_BASE_PATH . 'index.php?route=settings&tab=roles');
    exit();
}

if (isset($_GET['delete_user']) && is_numeric($_GET['delete_user']) && can('delete_user')) {
    $user_id = $_GET['delete_user'];
    if ($user_id > 1 && $user_id != $_SESSION['user_id']) {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) { $_SESSION['form_success'] = 'User deleted successfully.'; }
        else { $_SESSION['form_error'] = 'Error deleting user.'; }
    } else { $_SESSION['form_error'] = 'This user cannot be deleted.'; }
    header('Location: ' . APP_BASE_PATH . 'index.php?route=settings&tab=users');
    exit();
}

if (isset($_GET['delete_ldap_group']) && is_numeric($_GET['delete_ldap_group']) && can('delete_synced_group')) {
    $group_id_to_delete = (int)$_GET['delete_ldap_group'];
    $stmt_check = $conn->prepare("SELECT COUNT(*) FROM role_ldap_group_mapping WHERE ldap_group_id = ?");
    $stmt_check->bind_param("i", $group_id_to_delete); $stmt_check->execute();
    $mapping_count = $stmt_check->get_result()->fetch_row()[0]; $stmt_check->close();

    if ($mapping_count > 0) { $_SESSION['form_error'] = 'Cannot delete group: mapped to ' . $mapping_count . ' role(s).'; } 
    else {
        $stmt_delete = $conn->prepare("DELETE FROM ldap_groups WHERE id = ?");
        $stmt_delete->bind_param("i", $group_id_to_delete);
        if ($stmt_delete->execute()) { $_SESSION['form_success'] = 'Synced group deleted successfully.'; } 
        else { $_SESSION['form_error'] = 'Error deleting synced group.'; }
        $stmt_delete->close();
    }
    header('Location: ' . APP_BASE_PATH . 'index.php?route=settings&tab=synced_groups');
    exit();
}
// --- END: FORM HANDLERS ---

// --- START: DATA FETCHING ---
$roles = [];
$roles_result = $conn->query("SELECT * FROM roles ORDER BY role_name ASC");
while ($role = $roles_result->fetch_assoc()) { $roles[] = $role; }

$departments = [];
$dept_result = $conn->query("SELECT name FROM departments ORDER BY name ASC");
while($dept_row = $dept_result->fetch_assoc()){ $departments[] = $dept_row['name']; }

$role_user_counts = [];
$count_result = $conn->query("SELECT role_id, COUNT(id) as user_count FROM users WHERE role_id IS NOT NULL GROUP BY role_id");
while($row = $count_result->fetch_assoc()) { $role_user_counts[$row['role_id']] = $row['user_count']; }

$active_tab = $_GET['tab'] ?? '';
if (empty($active_tab) || !in_array($active_tab, ['users', 'roles', 'permissions', 'ldap', 'group_mapping', 'synced_groups', 'maintenance'])) {
    if (can('view_users_tab')) { $active_tab = 'users'; }
    elseif (can('view_roles_tab')) { $active_tab = 'roles'; }
    elseif (can('view_permissions_tab')) { $active_tab = 'permissions'; }
    elseif (can('view_group_mapping_tab')) { $active_tab = 'group_mapping'; }
    elseif (can('view_synced_groups_tab')) { $active_tab = 'synced_groups'; }
    elseif (can('view_ldap_tab')) { $active_tab = 'ldap'; }
    elseif (can('view_admin_menu')) { $active_tab = 'maintenance'; }
}

// Users Tab Data
$search_filter = $_GET['search'] ?? ''; $dept_filter = $_GET['dept_filter'] ?? 'all'; $role_filter = $_GET['role_filter'] ?? 'all';
$records_per_page = 15; $current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;
$where_clauses = []; $params = []; $types = '';
if (!empty($search_filter)) { $where_clauses[] = "(u.username LIKE ? OR u.firstname LIKE ? OR u.lastname LIKE ?)"; $search_param = "%{$search_filter}%"; array_push($params, $search_param, $search_param, $search_param); $types .= 'sss'; }
if ($dept_filter !== 'all') { $where_clauses[] = "u.department = ?"; $params[] = $dept_filter; $types .= 's'; }
if ($role_filter !== 'all') { $where_clauses[] = "u.role_id = ?"; $params[] = (int)$role_filter; $types .= 'i'; }
$where_sql = empty($where_clauses) ? '' : 'WHERE ' . implode(' AND ', $where_clauses);
$count_query = "SELECT COUNT(u.id) FROM users u " . $where_sql;
$stmt_count = $conn->prepare($count_query);
if(!empty($params)) { $stmt_count->bind_param($types, ...$params); }
$stmt_count->execute(); $total_users = $stmt_count->get_result()->fetch_row()[0]; $total_pages = ceil($total_users / $records_per_page); $stmt_count->close();
$users = [];
$user_query = "SELECT u.id, u.username, u.firstname, u.lastname, u.department, u.role_id, r.role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id " . $where_sql . " ORDER BY u.firstname, u.lastname LIMIT ? OFFSET ?";
array_push($params, $records_per_page, $offset); $types .= 'ii';
$stmt_users = $conn->prepare($user_query);
if(!empty($params)) { $stmt_users->bind_param($types, ...$params); }
$stmt_users->execute(); $users_result = $stmt_users->get_result(); while ($user = $users_result->fetch_assoc()) { $users[] = $user; } $stmt_users->close();

function generatePageUrl($param_overrides = []) {
    $params = array_merge($_GET, $param_overrides);
    $params['route'] = 'settings'; // Explicit route
    return APP_BASE_PATH . 'index.php?' . http_build_query($params);
}

// Permissions Data
$selected_role_id = ($active_tab == 'permissions') ? (isset($_GET['selected_role']) ? (int)$_GET['selected_role'] : ($roles[0]['id'] ?? 0)) : 0;
$selected_role_name = '';
$all_permissions = [];
$all_permissions_result = $conn->query("SELECT id, action_key, action_name FROM permissions ORDER BY action_name");
while ($perm = $all_permissions_result->fetch_assoc()) { $all_permissions[$perm['action_key']] = ['id' => $perm['id'], 'name' => $perm['action_name']]; }
$saved_permissions = [];
if ($selected_role_id > 0) { $stmt = $conn->prepare("SELECT permission_id FROM role_permissions WHERE role_id = ?"); $stmt->bind_param("i", $selected_role_id); $stmt->execute(); $result = $stmt->get_result(); while ($row = $result->fetch_assoc()) { $saved_permissions[$row['permission_id']] = true; } $stmt->close(); }

// LDAP Data
$ldap_groups = []; $group_mappings = []; $synced_ldap_groups = [];
if ($active_tab == 'group_mapping' || $active_tab == 'synced_groups') {
    $groups_result = $conn->query("SELECT id, group_name FROM ldap_groups ORDER BY group_name ASC");
    if ($groups_result) { while($g = $groups_result->fetch_assoc()) { $ldap_groups[] = $g; $synced_ldap_groups[] = $g; } }
    $mappings_result = $conn->query("SELECT role_id, ldap_group_id FROM role_ldap_group_mapping");
    if ($mappings_result) { while($m = $mappings_result->fetch_assoc()) { $group_mappings[$m['role_id']] = $m['ldap_group_id']; } }
}

if (file_exists(__DIR__ . '/../includes/ldap_config.php')) { include_once __DIR__ . '/../includes/ldap_config.php'; }
$ldap_config = [
    'ENABLE_LDAP_AUTH' => defined('ENABLE_LDAP_AUTH') ? ENABLE_LDAP_AUTH : false, 
    'LDAP_HOST' => defined('LDAP_HOST') ? LDAP_HOST : '',
    'LDAP_PORT' => defined('LDAP_PORT') ? LDAP_PORT : '389',
    'LDAP_BASE_DN' => defined('LDAP_BASE_DN') ? LDAP_BASE_DN : '',
    'LDAP_DOMAIN' => defined('LDAP_DOMAIN') ? LDAP_DOMAIN : '',
    'LDAP_LOGIN_ATTRIBUTE' => defined('LDAP_LOGIN_ATTRIBUTE') ? LDAP_LOGIN_ATTRIBUTE : 'sAMAccountName',
    'LDAP_USER_SEARCH_FILTER' => defined('LDAP_USER_SEARCH_FILTER') ? LDAP_USER_SEARCH_FILTER : '(&(objectClass=user)(objectCategory=person)(!(userAccountControl:1.2.840.113556.1.4.803:=2)))',
    'LDAP_BIND_DN' => defined('LDAP_BIND_DN') ? LDAP_BIND_DN : ''
];

// --- MAINTENANCE DATA (Reading from file) ---
$maintenance_file_path = __DIR__ . '/../maintenance.enable';
$is_maintenance_active = file_exists($maintenance_file_path);
$saved_ips = $is_maintenance_active ? file_get_contents($maintenance_file_path) : '';

// --- END: DATA FETCHING ---

$pageTitle = "System Configuration & Access";
include_once '../includes/header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
    
    body { font-family: 'Inter', sans-serif; background-color: var(--bg-color) !important; color: var(--text-main); font-size: 0.875rem; padding-bottom: 60px; }
    h1, h2, h3, h4, h5, h6 { font-weight: 600; color: #1e293b; }

    /* Modern Tabs */
    .nav-tabs-modern { border-bottom: 1px solid var(--border-color); gap: 10px; margin-bottom: 20px; }
    .nav-tabs-modern .nav-link { color: var(--text-light); border: none; border-bottom: 2px solid transparent; font-weight: 500; padding: 10px 16px; transition: all 0.2s; border-radius: 0; background: transparent; font-size: 0.9rem; }
    .nav-tabs-modern .nav-link:hover { color: var(--primary-color); background: #f1f5f9; border-radius: 6px 6px 0 0; }
    .nav-tabs-modern .nav-link.active { color: var(--primary-color); border-bottom: 2px solid var(--primary-color); background: transparent; font-weight: 600; }

    /* Cards */
    .modern-card { background: var(--card-bg); border-radius: var(--radius); box-shadow: var(--card-shadow); border: 1px solid var(--border-color); overflow: hidden; margin-bottom: 24px; }
    .modern-card-header { padding: 16px 20px; background: #ffffff; border-bottom: 1px solid var(--border-color); font-weight: 600; color: #1e293b; display: flex; align-items: center; justify-content: space-between; font-size: 0.95rem; }
    .modern-card-body { padding: 20px; }

    /* Forms */
    .form-control, .form-select { border-radius: 8px; padding: 8px 12px; font-size: 0.875rem; border: 1px solid #cbd5e1; transition: all 0.2s; }
    .form-control:focus, .form-select:focus { border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1); }
    .form-control-search { height: 36px; padding: 6px 10px 6px 32px !important; }
    .form-select-sm { height: 36px; }

    /* Buttons */
    .btn-primary-modern { background-color: var(--primary-color); color: white; border: none; border-radius: 6px; padding: 6px 20px; font-weight: 600; font-size: 0.85rem; transition: all 0.2s; }
    .btn-primary-modern:hover { background-color: var(--primary-hover); color: white; }
    .btn-primary-modern:disabled { background-color: #94a3b8; cursor: not-allowed; transform: none; }

    /* Tables */
    .table-custom { width: 100%; border-collapse: separate; border-spacing: 0; margin-bottom: 0; }
    .table-custom th { font-weight: 600; color: #4b5563; border-bottom: 1px solid #e5e7eb; background: #f9fafb; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; padding: 12px 16px; vertical-align: middle; }
    .table-custom td { padding: 12px 16px; vertical-align: middle; font-size: 0.85rem; color: #374151; border-bottom: 1px solid #f1f5f9; }
    
    .status-badge { padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; display: inline-block; }

    /* Action Dropdown */
    .action-btn-container .dropdown-toggle { background: none; border: none; color: #94a3b8; border-radius: 6px; padding: 4px 8px; }
    .action-btn-container .dropdown-toggle::after { display: none; }
    .action-btn-container .dropdown-toggle:hover { background-color: #f1f5f9; color: var(--primary-color);}
    .dropdown-menu { border: none; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05); border-radius: 8px; padding: 8px; }
    .dropdown-item { border-radius: 6px; display: flex; align-items: center; gap: 0.75rem; font-size: 0.8rem; font-weight: 500; padding: 6px 12px; color: #475569;}
    .dropdown-item:hover { background-color: #f1f5f9; color: var(--primary-color); }
    .dropdown-item.text-danger:hover { background-color: #fef2f2; color: #dc2626; }

    /* Pagination */
    .pagination .page-link { color: #374151; border: 1px solid #e5e7eb; margin: 0 2px; border-radius: 6px !important; font-size: 0.8rem; padding: 6px 12px; }
    .pagination .page-item.active .page-link { background-color: var(--primary-color); border-color: var(--primary-color); color: white; }
    .pagination .page-item.disabled .page-link { background-color: #f8fafc; color: #cbd5e1; border-color: #e5e7eb; }

    /* Permission Hierarchy Styling */
    .permission-section { margin-bottom: 24px; padding: 16px; background-color: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0; }
    .permission-section-title { font-size: 1rem; font-weight: 700; margin-bottom: 12px; color: #1e293b; border-bottom: 1px solid #cbd5e1; padding-bottom: 8px; display: flex; align-items: center; }
    .permission-section-title .form-check-input { margin-top: 0; margin-right: 10px; }
    .permission-sub-group { border-left: 2px solid var(--primary-color); padding-left: 16px; margin-top: 16px; margin-left: 6px; }
    .permission-sub-group-title { font-size: 0.8rem; font-weight: 700; color: var(--text-light); text-transform: uppercase; margin-bottom: 10px; letter-spacing: 0.05em; }
    .form-check-label { cursor: pointer; color: #475569; font-weight: 500; font-size: 0.85rem; }
    .switch-lg { transform: scale(1.2); transform-origin: left center; }
    
    .nav-list-group .list-group-item { border: none; border-bottom: 1px solid #f1f5f9; padding: 12px 16px; font-size: 0.875rem; border-left: 3px solid transparent; }
    .nav-list-group .list-group-item:hover { background-color: #f8fafc; cursor: pointer; }
    .nav-list-group .list-group-item.active { background-color: #f0fdfa; color: var(--primary-color); border-left: 3px solid var(--primary-color); font-weight: 600; }
</style>

<div class="container-xl py-4">

    <div class="d-flex justify-content-between align-items-end mb-4">
        <div>
            <h4 class="fw-bold m-0 text-dark">System Administration</h4>
            <div class="text-muted small mt-1">Manage users, access controls, maintenance, and active directory settings</div>
        </div>
    </div>

    <ul class="nav nav-tabs-modern" id="adminTabs" role="tablist">
        <?php if (can('view_users_tab')): ?><li class="nav-item"><a class="nav-link <?php if($active_tab == 'users') echo 'active'; ?>" href="<?= APP_BASE_PATH ?>index.php?route=settings&tab=users"><i data-feather="users" style="width:16px;" class="me-1"></i>Users Directory</a></li><?php endif; ?>
        <?php if (can('view_roles_tab')): ?><li class="nav-item"><a class="nav-link <?php if($active_tab == 'roles') echo 'active'; ?>" href="<?= APP_BASE_PATH ?>index.php?route=settings&tab=roles"><i data-feather="shield" style="width:16px;" class="me-1"></i>Roles & Counts</a></li><?php endif; ?>
        <?php if (can('view_permissions_tab')): ?><li class="nav-item"><a class="nav-link <?php if($active_tab == 'permissions') echo 'active'; ?>" href="<?= APP_BASE_PATH ?>index.php?route=settings&tab=permissions"><i data-feather="key" style="width:16px;" class="me-1"></i>Permissions Matrix</a></li><?php endif; ?>
        <?php if (can('view_group_mapping_tab')): ?><li class="nav-item"><a class="nav-link <?php if($active_tab == 'group_mapping') echo 'active'; ?>" href="<?= APP_BASE_PATH ?>index.php?route=settings&tab=group_mapping"><i data-feather="git-merge" style="width:16px;" class="me-1"></i>LDAP Mapping</a></li><?php endif; ?>
        <?php if (can('view_synced_groups_tab')): ?><li class="nav-item"><a class="nav-link <?php if($active_tab == 'synced_groups') echo 'active'; ?>" href="<?= APP_BASE_PATH ?>index.php?route=settings&tab=synced_groups"><i data-feather="folder" style="width:16px;" class="me-1"></i>Synced Groups</a></li><?php endif; ?>
        <?php if (can('view_ldap_tab')): ?><li class="nav-item"><a class="nav-link <?php if($active_tab == 'ldap') echo 'active'; ?>" href="<?= APP_BASE_PATH ?>index.php?route=settings&tab=ldap"><i data-feather="server" style="width:16px;" class="me-1"></i>LDAP Config</a></li><?php endif; ?>
        <?php if (can('view_admin_menu')): ?><li class="nav-item"><a class="nav-link <?php if($active_tab == 'maintenance') echo 'active'; ?>" href="<?= APP_BASE_PATH ?>index.php?route=settings&tab=maintenance"><i data-feather="tool" style="width:16px;" class="me-1"></i>Maintenance</a></li><?php endif; ?>
    </ul>

    <div class="tab-content mt-3">
        
        <?php if (can('view_users_tab')): ?>
        <div class="tab-pane fade <?php if($active_tab == 'users') echo 'show active'; ?>" id="users-pane">
            <div class="modern-card">
                <div class="modern-card-header bg-white">
                    <form action="<?= APP_BASE_PATH ?>index.php" method="GET" class="d-flex flex-wrap gap-2 align-items-center w-100">
                        <input type="hidden" name="route" value="settings">
                        <input type="hidden" name="tab" value="users">
                        <div class="position-relative" style="flex: 1; min-width: 200px;">
                            <i data-feather="search" class="position-absolute text-muted" style="left: 10px; top: 10px; width: 16px;"></i>
                            <input type="text" name="search" class="form-control form-control-search" placeholder="Search name or username..." value="<?php echo htmlspecialchars($search_filter); ?>">
                        </div>
                        <select name="dept_filter" class="form-select form-select-sm" style="width: auto; min-width: 150px;">
                            <option value="all">All Departments</option>
                            <?php foreach($departments as $dept):?>
                                <option value="<?php echo htmlspecialchars($dept); ?>" <?php if($dept_filter==$dept) echo 'selected';?>><?php echo htmlspecialchars($dept); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="role_filter" class="form-select form-select-sm" style="width: auto; min-width: 150px;">
                            <option value="all">All Roles</option>
                            <?php foreach($roles as $role):?>
                                <option value="<?php echo $role['id']; ?>" <?php if($role_filter==$role['id']) echo 'selected';?>><?php echo htmlspecialchars($role['role_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-primary-modern">Filter</button>
                        <?php if (!empty($search_filter) || $dept_filter !== 'all' || $role_filter !== 'all'): ?>
                            <a href="<?= APP_BASE_PATH ?>index.php?route=settings&tab=users" class="btn btn-light border px-3 py-1" style="border-radius: 6px; font-size: 0.85rem;">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>
                
                <div class="table-responsive border-top">
                    <table class="table-custom table-hover">
                        <thead>
                            <tr>
                                <th style="padding-left: 20px;">Employee</th>
                                <th>Username</th>
                                <th>Department</th>
                                <th>Assigned Role</th>
                                <th class="text-center" style="width: 80px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr><td colspan="5" class="text-center py-5 text-muted"><i data-feather="users" style="width:32px; height:32px; opacity:0.3; margin-bottom:10px;"></i><br>No users found matching your criteria.</td></tr>
                            <?php else: ?>
                                <?php foreach($users as $user): 
                                    $u_json = htmlspecialchars(json_encode($user), ENT_QUOTES, 'UTF-8');
                                ?>
                                    <tr>
                                        <td style="padding-left: 20px;" class="fw-bold text-dark"><?php echo htmlspecialchars(trim($user['firstname'].' '.$user['lastname'])); ?></td>
                                        <td class="text-muted"><?php echo htmlspecialchars($user['username']); ?></td>
                                        <td><span class="status-badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"><?php echo htmlspecialchars($user['department']); ?></span></td>
                                        <td>
                                            <?php if ($user['role_name']): ?>
                                                <span class="status-badge bg-primary-subtle text-primary-emphasis border border-primary-subtle"><?php echo htmlspecialchars($user['role_name']); ?></span>
                                            <?php else: ?>
                                                <span class="status-badge bg-light text-muted border">No Role Assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="dropdown action-btn-container text-center">
                                                <button class="btn dropdown-toggle border" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                    <i data-feather="more-horizontal" style="width:14px; height:14px;"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <?php if (can('edit_user_role') && $user['id'] != 1): ?>
                                                        <li><a class="dropdown-item" href="#" onclick="openUserModal(<?php echo $u_json; ?>)"><i data-feather="shield"></i> Assign Role</a></li>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (can('delete_user') && $user['id'] > 1 && $user['id'] != $_SESSION['user_id']): ?>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li><a class="dropdown-item text-danger" href="<?= APP_BASE_PATH ?>index.php?route=settings&delete_user=<?php echo $user['id']; ?>" onclick="return confirm('Are you sure you want to delete this user?');"><i data-feather="trash-2"></i> Delete</a></li>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($user['id'] == 1 || (!can('edit_user_role') && !can('delete_user'))): ?>
                                                        <li><span class="dropdown-item text-muted disabled">No actions available</span></li>
                                                    <?php endif; ?>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="d-flex justify-content-between align-items-center p-3 bg-light border-top">
                    <small class="text-muted">Showing <?php echo ($total_users > 0) ? $offset + 1 : 0; ?> to <?php echo min($offset + $records_per_page, $total_users); ?> of <?php echo $total_users; ?> users</small>
                    <?php if ($total_pages > 1): ?>
                    <nav>
                        <ul class="pagination mb-0">
                            <li class="page-item <?php if($current_page <= 1) echo 'disabled'; ?>"><a class="page-link" href="<?php echo generatePageUrl(['page' => 1]); ?>">&laquo;</a></li>
                            <li class="page-item <?php if($current_page <= 1) echo 'disabled'; ?>"><a class="page-link" href="<?php echo generatePageUrl(['page' => $current_page - 1]); ?>">&lsaquo;</a></li>
                            <li class="page-item active"><span class="page-link"><?php echo $current_page; ?></span></li>
                            <li class="page-item <?php if($current_page >= $total_pages) echo 'disabled'; ?>"><a class="page-link" href="<?php echo generatePageUrl(['page' => $current_page + 1]); ?>">&rsaquo;</a></li>
                            <li class="page-item <?php if($current_page >= $total_pages) echo 'disabled'; ?>"><a class="page-link" href="<?php echo generatePageUrl(['page' => $total_pages]); ?>">&raquo;</a></li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (can('view_roles_tab')): ?>
        <div class="tab-pane fade <?php if($active_tab == 'roles') echo 'show active'; ?>" id="roles-pane">
            <div class="row">
                <div class="col-xl-9 col-lg-12"> <div class="modern-card">
                        <div class="modern-card-header bg-white d-flex justify-content-between align-items-center p-3">
                            <h6 class="m-0 fw-bold text-dark">System Roles</h6>
                            <?php if (can('create_role')): ?>
                                <button class="btn btn-primary-modern d-flex align-items-center" onclick="openRoleModal()">
                                    <i data-feather="plus" style="width:14px;" class="me-1"></i> New Role
                                </button>
                            <?php endif; ?>
                        </div>
                        <div class="table-responsive border-top">
                            <table class="table-custom table-hover">
                                <thead>
                                    <tr>
                                        <th style="padding-left: 20px; width: 30%;">Role Name</th>
                                        <th style="width: 40%;">Description</th>
                                        <th class="text-center">Assigned Users</th>
                                        <th class="text-center" style="width: 80px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($roles)): ?>
                                        <tr><td colspan="4" class="text-center text-muted py-5">No roles configured.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($roles as $role): 
                                            $r_count = $role_user_counts[$role['id']] ?? 0;
                                            $r_json = htmlspecialchars(json_encode($role), ENT_QUOTES, 'UTF-8');
                                        ?>
                                        <tr>
                                            <td style="padding-left: 20px;" class="fw-bold text-dark"><?php echo htmlspecialchars($role['role_name']); ?></td>
                                            <td class="text-muted small"><?php echo htmlspecialchars($role['description'] ?? 'No description provided.'); ?></td>
                                            <td class="text-center">
                                                <?php if ($r_count > 0): ?>
                                                    <button class="btn btn-sm btn-link text-decoration-none fw-bold" onclick="viewRoleUsers(<?php echo $role['id']; ?>, '<?php echo addslashes(htmlspecialchars($role['role_name'])); ?>')">
                                                        <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle rounded-pill px-3"><?php echo $r_count; ?> Users</span>
                                                    </button>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-muted border rounded-pill px-3">0 Users</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <div class="dropdown action-btn-container text-center">
                                                    <button class="btn dropdown-toggle border" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i data-feather="more-horizontal" style="width:14px; height:14px;"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        <?php if (can('edit_role') && $role['id'] != 1): ?>
                                                            <li><a class="dropdown-item" href="#" onclick="openRoleModal(<?php echo $r_json; ?>)"><i data-feather="edit-2"></i> Edit Details</a></li>
                                                        <?php endif; ?>
                                                        
                                                        <?php if (can('view_permissions_tab')): ?>
                                                            <li><a class="dropdown-item" href="<?= APP_BASE_PATH ?>index.php?route=settings&tab=permissions&selected_role=<?php echo $role['id']; ?>"><i data-feather="key"></i> Edit Permissions</a></li>
                                                        <?php endif; ?>
                                                        
                                                        <?php if (can('delete_role') && $role['id'] != 1 && $r_count == 0): ?>
                                                            <li><hr class="dropdown-divider"></li>
                                                            <li><a class="dropdown-item text-danger" href="<?= APP_BASE_PATH ?>index.php?route=settings&delete_role=<?php echo $role['id']; ?>" onclick="return confirm('Are you sure you want to delete this role?');"><i data-feather="trash-2"></i> Delete</a></li>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($role['id'] == 1): ?>
                                                            <li><span class="dropdown-item text-muted disabled">Super Admin is locked</span></li>
                                                        <?php endif; ?>
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
        <?php endif; ?>

        <?php if (can('view_permissions_tab')): ?>
        <div class="tab-pane fade <?php if($active_tab == 'permissions') echo 'show active'; ?>" id="permissions-pane">
            <?php $can_edit_perms = can('edit_permissions'); $disabled_attr = !$can_edit_perms ? 'disabled' : ''; ?>
            <div class="modern-card">
                <div class="row g-0">
                    <div class="col-md-3 bg-light border-end">
                        <div class="p-3 border-bottom"><h6 class="m-0 fw-bold text-dark">Select Role</h6></div>
                        <div class="list-group nav-list-group rounded-0 bg-transparent" style="max-height: 600px; overflow-y: auto;">
                            <?php foreach ($roles as $role): if ($role['id'] == $selected_role_id) $selected_role_name = $role['role_name']; ?>
                                <a href="<?= APP_BASE_PATH ?>index.php?route=settings&tab=permissions&selected_role=<?php echo $role['id']; ?>" class="list-group-item list-group-item-action bg-transparent <?php if ($role['id'] == $selected_role_id) echo 'active bg-white'; ?>"><?php echo htmlspecialchars($role['role_name']); ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-md-9 bg-white">
                        <form id="permissionsForm" action="<?= APP_BASE_PATH ?>index.php?route=settings&tab=permissions&selected_role=<?php echo $selected_role_id; ?>" method="POST">
                            <input type="hidden" name="action" value="save_role_permissions">
                            <input type="hidden" name="role_id" value="<?php echo $selected_role_id; ?>">
                            
                            <div class="p-3 border-bottom d-flex justify-content-between align-items-center sticky-top bg-white" style="z-index: 10;">
                                <div>
                                    <h5 class="m-0 fw-bold text-dark d-flex align-items-center">
                                        <i data-feather="key" class="me-2 text-primary" style="width: 18px;"></i> Access Matrix
                                    </h5>
                                    <div class="text-muted small mt-1">Configuring permissions for: <strong class="text-primary"><?php echo htmlspecialchars($selected_role_name); ?></strong></div>
                                </div>
                                <?php if ($can_edit_perms): ?>
                                    <button type="submit" id="savePermissionsBtn" class="btn btn-primary-modern shadow-sm" <?php if ($selected_role_id == 0 || $selected_role_id == 1) echo 'disabled'; ?> disabled>
                                        <i data-feather="save" style="width:14px;" class="me-1"></i> Save Rules
                                    </button>
                                <?php endif; ?>
                            </div>
                            
                            <div class="p-4 bg-white" style="max-height: 600px; overflow-y: auto;">
                                <?php
                                $ui_structure = [
                                    'Portals' => ['parent' => 'view_portals_menu', 'permissions' => ['manage_portals']],
                                    'Dashboard' => ['parent' => 'view_dashboard', 'permissions' => []],
                                    'Templates' => ['parent' => 'view_templates_list', 'permissions' => ['request_template', 'create_template', 'approve_template', 'delete_template', 'download_template_pdf', 'view_employee_details', 'view_domain_details', 'view_system_info', 'view_induction_details', 'sub_group' => ['Details & Induction' => ['edit_template_basic', 'edit_induction_it', 'edit_induction_hr', 'edit_induction_facility'], 'Tab Views' => ['view_template_readiness_tab', 'view_activity_timeline', 'view_timeline_template_events', 'view_timeline_readiness_events'], 'Column Visibility' => ['view_column_requested_by', 'view_column_created_by', 'view_column_system_readiness', 'view_column_it_induction', 'view_column_hr_induction', 'view_column_facility_induction', 'view_employee_status']]]],
                                    'Checklist' => ['parent' => 'view_checklist_menu', 'permissions' => ['sub_group' => ['System Readiness' => ['view_system_readiness','manage_system_readiness', 'verify_system_readiness', 'edit_without_assign', 'download_checklist_pdf', 'download_user_acceptance_pdf'], 'Exit Checklist' => ['view_exit_checklist', 'create_exit_checklist', 'manage_exit_checklist', 'approve_exit_checklist', 'delete_exit_checklist', 'download_exit_checklist_pdf', 'edit_exit_checklist_without_assign', 'view_timeline_exit_events']]]],
                                    'Gatepass' => ['parent' => 'view_gatepass', 'permissions' => ['create_gatepass', 'edit_gatepass_details', 'delete_gatepass', 'approve_gatepass', 'download_gatepass_pdf', 'edit_security_fields']],
                                    'Shipment' => ['parent' => 'view_shipment', 'permissions' => ['create_shipment', 'edit_shipment', 'delete_shipment']],
                                    'Reports' => ['parent' => 'view_reports', 'permissions' => []],
                                    'SHR' => ['parent' => 'view_shr', 'permissions' => ['view_shr', 'shift_owner', 'resend_email']],
                                    'Administration' => ['parent' => 'view_admin_menu', 'permissions' => ['sub_group' => [
                                        'Users & Roles' => ['view_users_tab', 'edit_user_role', 'delete_user', 'view_roles_tab', 'create_role', 'edit_role', 'delete_role', 'view_permissions_tab', 'edit_permissions'],
                                        'LDAP' => ['view_ldap_tab', 'edit_ldap_config'],
                                        'Group Mapping' => ['view_group_mapping_tab', 'edit_group_mapping', 'view_synced_groups_tab', 'delete_synced_group'],
                                        'Forms Management' => ['view_manage_forms_page', 'view_departments_tab', 'create_department', 'edit_department', 'delete_department','view_locations_tab','create_location','edit_location','delete_location'],
                                        'Manage Employees' => ['view_employees', 'edit_employees', 'manage_employees'],
                                        'Maintenance Mode' => ['enable_maintenance_mode'],
                                        'System Audit Trail' => ['view_audit_trail']
                                    ]]],
                                    'User View' => ['parent' => 'user_view', 'permissions' => ['nav_my_profile_dropdown', 'nav_my_profile_main', 'show_department_preference']]
                                ];
                                
                                echo '<div class="row row-cols-1 row-cols-lg-2 g-4">';
                                foreach ($ui_structure as $section_title => $data):
                                    $parent_key = $data['parent']; if (!isset($all_permissions[$parent_key])) continue;
                                    $parent_perm_id = $all_permissions[$parent_key]['id'];
                                ?>
                                    <div class="col">
                                        <div class="permission-section h-100">
                                            <div class="form-check permission-section-title">
                                                <input class="form-check-input switch-lg shadow-sm" type="checkbox" role="switch" name="permissions[<?php echo $parent_perm_id; ?>]" id="perm-<?php echo $selected_role_id . '-' . $parent_perm_id; ?>" <?php echo isset($saved_permissions[$parent_perm_id]) ? 'checked' : ''; ?> data-parent-for="<?php echo $selected_role_id . '-' . $parent_key; ?>" <?php echo $disabled_attr; ?>>
                                                <label class="form-check-label text-dark fw-bold ms-2 mt-1" for="perm-<?php echo $selected_role_id . '-' . $parent_perm_id; ?>"><?php echo $section_title; ?></label>
                                            </div>
                                            
                                            <?php
                                            $permissions = $data['permissions']; $main_permissions = is_array($permissions) ? array_filter($permissions, 'is_string') : [$permissions];
                                            if (!empty($main_permissions)) { 
                                                echo '<div class="permission-sub-group">'; 
                                                foreach ($main_permissions as $perm_key) { 
                                                    if (!isset($all_permissions[$perm_key])) continue; 
                                                    $perm_id = $all_permissions[$perm_key]['id']; 
                                                    $perm_name = $all_permissions[$perm_key]['name']; 
                                                    echo '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" data-child-of="'.$selected_role_id . '-' .$parent_key.'" name="permissions['.$perm_id.']" id="perm-'.$selected_role_id . '-'.$perm_id.'" '.(isset($saved_permissions[$perm_id]) ? "checked":"").' '.$disabled_attr.'><label class="form-check-label text-muted" for="perm-'.$selected_role_id . '-'.$perm_id.'">'.htmlspecialchars($perm_name).'</label></div>'; 
                                                } 
                                                echo '</div>'; 
                                            }
                                            if (isset($permissions['sub_group'])) { 
                                                foreach ($permissions['sub_group'] as $sub_group_title => $sub_permissions) { 
                                                    echo '<div class="permission-sub-group mt-3"><h6 class="permission-sub-group-title">'.$sub_group_title.'</h6>'; 
                                                    foreach ($sub_permissions as $perm_key) { 
                                                        if (!isset($all_permissions[$perm_key])) continue; 
                                                        $perm_id = $all_permissions[$perm_key]['id']; 
                                                        $perm_name = $all_permissions[$perm_key]['name']; 
                                                        echo '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" data-child-of="'.$selected_role_id . '-' .$parent_key.'" name="permissions['.$perm_id.']" id="perm-'.$selected_role_id . '-'.$perm_id.'" '.(isset($saved_permissions[$perm_id]) ? "checked":"").' '.$disabled_attr.'><label class="form-check-label text-muted" for="perm-'.$selected_role_id . '-'.$perm_id.'">'.htmlspecialchars($perm_name).'</label></div>'; 
                                                    } 
                                                    echo '</div>'; 
                                                } 
                                            }
                                            ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (can('view_group_mapping_tab')): ?>
        <div class="tab-pane fade <?php if($active_tab == 'group_mapping') echo 'show active'; ?>" id="group-mapping-pane">
            <div class="modern-card">
                <div class="modern-card-header bg-white border-bottom">
                    <h5 class="mb-0 fw-bold d-flex align-items-center"><i data-feather="git-merge" class="me-2 text-primary"></i> LDAP Group Role Mapping</h5>
                </div>
                <div class="modern-card-body">
                    <p class="text-muted small mb-4">Automatically assign application roles to users upon login based on their Active Directory group membership.</p>
                    
                    <?php if (can('edit_group_mapping')): ?>
                    <div class="bg-light p-4 rounded border mb-4">
                        <h6 class="fw-bold text-dark mb-3" style="font-size: 0.85rem; text-transform: uppercase;">1. Fetch Groups from Directory</h6>
                        <form action="<?= APP_BASE_PATH ?>index.php?route=settings&tab=group_mapping" method="POST" class="row g-3 align-items-end">
                            <input type="hidden" name="action" value="search_ldap_groups">
                            <div class="col-md-7">
                                <label for="group_search_keyword" class="form-label">Active Directory Group Keyword</label>
                                <input type="text" class="form-control bg-white" name="group_search_keyword" id="group_search_keyword" placeholder="e.g., Domain Admins" required minlength="2">
                            </div>
                            <div class="col-md-5">
                                <button type="submit" class="btn btn-primary-modern w-100 shadow-sm"><i data-feather="download-cloud" style="width:14px;" class="me-2"></i> Sync Groups to Portal</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                    
                    <h6 class="fw-bold text-dark mb-3" style="font-size: 0.85rem; text-transform: uppercase;">2. Assign Groups to Roles</h6>
                    <form id="groupMappingForm" action="<?= APP_BASE_PATH ?>index.php?route=settings&tab=group_mapping" method="POST">
                        <input type="hidden" name="action" value="save_group_mappings">
                        <fieldset <?php if (!can('edit_group_mapping')) echo 'disabled'; ?>>
                            <div class="table-responsive border rounded">
                                <table class="table-custom mb-0">
                                    <thead class="bg-light"><tr><th style="width: 40%;">System Role</th><th>Mapped Active Directory Group</th></tr></thead>
                                    <tbody>
                                    <?php foreach($roles as $role): if ($role['id'] == 1) continue; ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($role['role_name']); ?></div>
                                                <div class="text-muted small mt-1"><?php echo htmlspecialchars($role['description']); ?></div>
                                            </td>
                                            <td>
                                                <select class="form-select bg-light fw-medium" name="mappings[<?php echo $role['id']; ?>]">
                                                    <option value="">-- No Group Mapped --</option>
                                                    <?php foreach($ldap_groups as $group): ?>
                                                        <option value="<?php echo $group['id']; ?>" <?php if (isset($group_mappings[$role['id']]) && $group_mappings[$role['id']] == $group['id']) echo 'selected'; ?>><?php echo htmlspecialchars($group['group_name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if (can('edit_group_mapping')): ?>
                                <div class="mt-4 pt-3 text-end border-top">
                                    <button type="submit" class="btn btn-primary-modern shadow-sm"><i data-feather="save" style="width:14px;" class="me-2"></i> Save Configuration</button>
                                </div>
                            <?php endif; ?>
                        </fieldset>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (can('view_synced_groups_tab')): ?>
        <div class="tab-pane fade <?php if($active_tab == 'synced_groups') echo 'show active'; ?>" id="synced-groups-pane">
            <div class="modern-card">
                <div class="modern-card-header bg-white border-bottom">
                    <h5 class="mb-0 fw-bold d-flex align-items-center"><i data-feather="folder" class="me-2 text-primary"></i> Local Synced Groups</h5>
                </div>
                <div class="modern-card-body p-0">
                    <div class="p-3 bg-light border-bottom text-muted small">
                        This table shows all Active Directory groups successfully fetched and cached in the local database. Unmapped groups can be deleted to clean up the dropdown menu.
                    </div>
                    <div class="table-responsive">
                        <table class="table-custom mb-0">
                            <thead>
                                <tr>
                                    <th class="ps-4">Group Name (CN)</th>
                                    <th class="text-center" style="width: 120px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($synced_ldap_groups)): ?>
                                    <tr><td colspan="2" class="text-center text-muted py-5"><i data-feather="inbox" style="width:32px; height:32px; opacity:0.3; margin-bottom:10px;"></i><br>No groups have been synced yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($synced_ldap_groups as $group): ?>
                                        <tr>
                                            <td class="ps-4 fw-medium text-dark"><?php echo htmlspecialchars($group['group_name']); ?></td>
                                            <td class="text-center">
                                                <?php if (can('delete_synced_group')): ?>
                                                    <a href="<?= APP_BASE_PATH ?>index.php?route=settings&delete_ldap_group=<?php echo $group['id']; ?>" class="btn btn-link text-danger p-0" onclick="return confirm('Are you sure you want to delete this synced group?');" title="Delete Synced Group">
                                                        <i data-feather="trash-2" style="width:16px;"></i>
                                                    </a>
                                                <?php endif; ?>
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
        <?php endif; ?>
        
        <?php if (can('view_ldap_tab')): ?>
        <div class="tab-pane fade <?php if($active_tab == 'ldap') echo 'show active'; ?>" id="ldap-pane">
            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="modern-card">
                        <div class="modern-card-header bg-white border-bottom">
                            <h5 class="mb-0 fw-bold d-flex align-items-center"><i data-feather="server" class="me-2 text-primary"></i> Active Directory Integration</h5>
                        </div>
                        <div class="modern-card-body">
                            <form action="<?= APP_BASE_PATH ?>index.php?route=settings&tab=ldap" method="POST">
                                <input type="hidden" name="action" value="save_ldap_config">
                                <fieldset <?php if (!can('edit_ldap_config')) echo 'disabled'; ?>>
                                    <div class="d-flex align-items-center mb-4 p-3 bg-light rounded border">
                                        <div class="form-check form-switch switch-lg m-0 ms-2">
                                            <input class="form-check-input" type="checkbox" role="switch" id="enable_ldap_auth" name="enable_ldap_auth" <?php echo ($ldap_config['ENABLE_LDAP_AUTH'] ?? false) ? 'checked' : '';?>>
                                        </div>
                                        <label class="form-check-label fw-bold text-dark ms-3" for="enable_ldap_auth" style="font-size: 0.9rem;">Enable LDAP Authentication for the Portal</label>
                                    </div>
                                    <h6 class="fw-bold text-muted text-uppercase mb-3" style="font-size:0.75rem; letter-spacing:0.05em;">Server & Path</h6>
                                    <div class="row g-3 mb-4">
                                        <div class="col-md-9"><label for="ldap_host" class="form-label">LDAP Host (IP or FQDN)</label><input type="text" class="form-control font-monospace text-primary" id="ldap_host" name="ldap_host" value="<?php echo htmlspecialchars($ldap_config['LDAP_HOST'] ?? '');?>" placeholder="e.g., ldap.example.com"></div>
                                        <div class="col-md-3"><label for="ldap_port" class="form-label">Port</label><input type="number" class="form-control font-monospace" id="ldap_port" name="ldap_port" value="<?php echo htmlspecialchars($ldap_config['LDAP_PORT'] ?? '');?>" placeholder="e.g., 389"></div>
                                        <div class="col-md-12"><label for="ldap_base_dn" class="form-label">Base DN (Search Root)</label><input type="text" class="form-control font-monospace" id="ldap_base_dn" name="ldap_base_dn" value="<?php echo htmlspecialchars($ldap_config['LDAP_BASE_DN'] ?? '');?>" placeholder="e.g., DC=example,DC=com"></div>
                                        <div class="col-md-12"><label for="ldap_domain" class="form-label">Domain Name Suffix</label><input type="text" class="form-control font-monospace" id="ldap_domain" name="ldap_domain" value="<?php echo htmlspecialchars($ldap_config['LDAP_DOMAIN'] ?? '');?>" placeholder="e.g., example.com"></div>
                                    </div>
                                    <hr class="border-light my-4">
                                    <h6 class="fw-bold text-muted text-uppercase mb-3" style="font-size:0.75rem; letter-spacing:0.05em;">Query Configuration</h6>
                                    <div class="row g-3 mb-4">
                                        <div class="col-md-12">
                                            <label for="ldap_login_attribute" class="form-label">Login Identifier Attribute</label>
                                            <input type="text" class="form-control font-monospace" id="ldap_login_attribute" name="ldap_login_attribute" value="<?php echo htmlspecialchars($ldap_config['LDAP_LOGIN_ATTRIBUTE'] ?? 'sAMAccountName');?>" placeholder="e.g., sAMAccountName" required>
                                            <div class="form-text mt-1">Attribute mapped to the portal username. Usually <strong>sAMAccountName</strong> or <strong>uid</strong>.</div>
                                        </div>
                                        <div class="col-md-12">
                                            <label for="ldap_user_search_filter" class="form-label">Additional Filter Rules</label>
                                            <input type="text" class="form-control font-monospace" id="ldap_user_search_filter" name="ldap_user_search_filter" value="<?php echo htmlspecialchars($ldap_config['LDAP_USER_SEARCH_FILTER'] ?? '(&(objectClass=user)(objectCategory=person)(!(userAccountControl:1.2.840.113556.1.4.803:=2)))');?>">
                                            <div class="form-text mt-1">Leave default to restrict disabled AD accounts.</div>
                                        </div>
                                    </div>
                                    <hr class="border-light my-4">
                                    <h6 class="fw-bold text-muted text-uppercase mb-3" style="font-size:0.75rem; letter-spacing:0.05em;">Service Account (Bind Credentials)</h6>
                                    <div class="row g-3">
                                        <div class="col-md-12"><label for="ldap_bind_dn" class="form-label">Service Account DN</label><input type="text" class="form-control font-monospace" id="ldap_bind_dn" name="ldap_bind_dn" value="<?php echo htmlspecialchars($ldap_config['LDAP_BIND_DN'] ?? '');?>" placeholder="e.g., CN=LDAPReader,OU=ServiceAccounts,DC=example,DC=com"></div>
                                        <div class="col-md-12"><label for="ldap_bind_password" class="form-label">Account Password</label><input type="password" class="form-control" id="ldap_bind_password" name="ldap_bind_password" value="" placeholder="Type here to override existing password..."></div>
                                    </div>
                                    <div class="mt-4 pt-3 border-top text-end">
                                        <button type="submit" class="btn btn-primary-modern shadow-sm"><i data-feather="save" style="width:14px;" class="me-2"></i> Save Network Config</button>
                                    </div>
                                </fieldset>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="modern-card border-primary" style="border-width: 2px !important;">
                        <div class="modern-card-header bg-primary-subtle text-primary-emphasis border-bottom-0 pb-0">
                            <h6 class="mb-0 fw-bold d-flex align-items-center"><i data-feather="activity" class="me-2 text-primary"></i> Diagnostics</h6>
                        </div>
                        <div class="modern-card-body bg-primary-subtle pt-3">
                            <p class="small text-primary-emphasis mb-3">Verify the network connection, service account bind, and query rules against a real user.</p>
                            <form id="ldapTestForm">
                                <div id="testResult" class="mb-3"></div>
                                <div class="mb-3">
                                    <label for="test_username" class="form-label text-primary-emphasis">Target Username</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-end-0"><i data-feather="user" style="width:14px;" class="text-muted"></i></span>
                                        <input type="text" class="form-control bg-white border-start-0" id="test_username" name="test_username" placeholder="e.g., jsmith" required>
                                        <button class="btn btn-white border border-start-0 text-muted" type="button" id="clearLdapUsernameBtn" title="Clear Field"><i data-feather="x" style="width:14px;"></i></button>
                                    </div>
                                </div>
                                <div class="mb-4">
                                    <label for="test_password" class="form-label text-primary-emphasis">User Password</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-end-0"><i data-feather="lock" style="width:14px;" class="text-muted"></i></span>
                                        <input type="password" class="form-control bg-white border-start-0" id="test_password" name="test_password" placeholder="Test authentication..." required>
                                    </div>
                                </div>
                                <button type="submit" id="testLdapBtn" class="btn btn-primary w-100 fw-bold shadow-sm d-flex align-items-center justify-content-center">
                                    <i data-feather="play-circle" style="width:16px;" class="me-2"></i> Run Diagnostics
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (can('view_admin_menu')): ?>
        <div class="tab-pane fade <?php if($active_tab == 'maintenance') echo 'show active'; ?>" id="maintenance-pane">
            <div class="modern-card">
                <div class="modern-card-header bg-white border-bottom">
                    <h5 class="mb-0 fw-bold d-flex align-items-center"><i data-feather="tool" class="me-2 text-warning"></i> System Maintenance Mode</h5>
                </div>
                <div class="modern-card-body">
                    <?php 
                        // Determine if user has the strict permission to enable/disable maintenance
                        $can_toggle_maintenance = can('enable_maintenance_mode') || (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 1); 
                    ?>
                    <form action="<?= APP_BASE_PATH ?>index.php?route=settings&tab=maintenance" method="POST">
                        <input type="hidden" name="action" value="save_maintenance_config">
                        <fieldset <?php if (!$can_toggle_maintenance) echo 'disabled'; ?>>
                            <div class="d-flex align-items-center mb-4 p-3 bg-warning-subtle rounded border border-warning-subtle">
                                <div class="form-check form-switch switch-lg m-0 ms-2">
                                    <input class="form-check-input" type="checkbox" role="switch" id="enable_maintenance" name="enable_maintenance" <?php echo $is_maintenance_active ? 'checked' : '';?>>
                                </div>
                                <label class="form-check-label fw-bold text-dark ms-3" for="enable_maintenance" style="font-size: 0.95rem;">Enable Maintenance Mode</label>
                                
                                <span class="ms-auto badge bg-white text-dark border shadow-sm px-3 py-2 fw-medium" style="font-size: 0.85rem;">
                                    Your Current IP: <span class="text-primary fw-bold"><?php echo $_SERVER['REMOTE_ADDR']; ?></span>
                                </span>
                            </div>
                            
                            <div class="mb-4">
                                <label for="maintenance_allowed_ips" class="form-label fw-bold text-dark">Admin IPs to Bypass (Comma Separated)</label>
                                <textarea class="form-control font-monospace" id="maintenance_allowed_ips" name="maintenance_allowed_ips" rows="3" placeholder="e.g., 192.168.1.50, 203.0.113.1"><?php echo htmlspecialchars($saved_ips); ?></textarea>
                                <div class="form-text mt-2 text-muted">
                                    <i data-feather="info" style="width: 14px; height: 14px;" class="me-1 text-primary"></i> 
                                    Enter IP addresses that should still have access to the portal while maintenance mode is active. Separate multiple IPs with commas. <strong class="text-danger">Ensure your current IP is in this list before enabling!</strong>
                                </div>
                            </div>

                            <?php if ($can_toggle_maintenance): ?>
                                <div class="mt-4 pt-3 border-top text-end">
                                    <button type="submit" class="btn btn-primary-modern shadow-sm"><i data-feather="save" style="width:14px;" class="me-2"></i> Save Maintenance Settings</button>
                                </div>
                            <?php else: ?>
                                <div class="mt-4 pt-3 border-top text-muted small">
                                    <i data-feather="lock" style="width:14px;" class="me-1"></i> You do not have permission to alter maintenance settings.
                                </div>
                            <?php endif; ?>
                        </fieldset>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<div class="modal fade" id="userEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px;">
            <div class="modal-header bg-light border-bottom-0 pb-3">
                <h5 class="modal-title fw-bold text-dark d-flex align-items-center">
                    <i data-feather="user" class="me-2 text-primary" style="width:18px;"></i> Assign User Role
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="<?= APP_BASE_PATH ?>index.php?route=settings" method="POST">
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search_filter); ?>">
                <input type="hidden" name="dept_filter" value="<?php echo htmlspecialchars($dept_filter); ?>">
                <input type="hidden" name="role_filter" value="<?php echo htmlspecialchars($role_filter); ?>">
                <input type="hidden" name="page" value="<?php echo $current_page; ?>">

                <div class="modal-body pt-0">
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" name="user_id" id="edit_user_id" value="">
                    
                    <div class="mb-3">
                        <label class="form-label">Employee Name</label>
                        <input type="text" class="form-control bg-light text-muted" id="edit_user_name" readonly>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Application Role <span class="text-danger">*</span></label>
                        <select class="form-select fw-medium border-primary shadow-sm" name="role_id" id="edit_user_role" required>
                            <option value="">Select a Role...</option>
                            <?php foreach($roles as $role): ?>
                                <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['role_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-light border px-4 rounded-pill fw-medium" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-modern px-4 rounded-pill shadow-sm d-flex align-items-center">
                        <i data-feather="save" class="me-2" style="width:16px;"></i> Save Assignment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="roleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px;">
            <div class="modal-header bg-light border-bottom-0 pb-3">
                <h5 class="modal-title fw-bold text-dark d-flex align-items-center" id="roleModalTitle">
                    <i data-feather="shield" class="me-2 text-primary" style="width:18px;"></i> Role Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="<?= APP_BASE_PATH ?>index.php?route=settings" method="POST">
                <div class="modal-body pt-0">
                    <input type="hidden" name="action" id="role_action" value="create_role">
                    <input type="hidden" name="role_id" id="role_id" value="">
                    
                    <div class="mb-3">
                        <label class="form-label">Role Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="role_name" id="role_name" required placeholder="e.g., HR Manager">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" id="role_description" rows="3" placeholder="Describe the purpose of this role..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-light border px-4 rounded-pill fw-medium" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-modern px-4 rounded-pill shadow-sm d-flex align-items-center">
                        <i data-feather="save" class="me-2" style="width:16px;"></i> Save Role
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="roleUsersModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px;">
            <div class="modal-header bg-light border-bottom">
                <h5 class="modal-title fw-bold text-dark d-flex align-items-center">
                    <i data-feather="users" class="me-2 text-primary" style="width:18px;"></i> Users in Role
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="p-3 bg-white border-bottom">
                <h6 class="m-0 fw-bold text-primary" id="roleUsersModalName">Role Name</h6>
            </div>
            <div class="modal-body p-0" id="roleUsersModalBody">
                <div class="text-center p-5"><span class="spinner-border text-primary" role="status"></span></div>
            </div>
            <div class="modal-footer border-0 bg-light">
                <button type="button" class="btn btn-light border px-4 rounded-pill fw-medium" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
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
                <button type="button" class="btn btn-danger px-4 rounded-pill fw-medium shadow-sm" id="discardChangesBtn">Discard & Leave</button>
            </div>
        </div>
    </div>
</div>

<?php include_once '../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof feather !== 'undefined') { feather.replace(); }

    // --- Modal Logic ---
    const userModal = new bootstrap.Modal(document.getElementById('userEditModal'));
    window.openUserModal = function(userData) {
        document.getElementById('edit_user_id').value = userData.id;
        document.getElementById('edit_user_name').value = userData.firstname + ' ' + userData.lastname + ' (' + userData.username + ')';
        document.getElementById('edit_user_role').value = userData.role_id || '';
        userModal.show();
    };

    const roleModal = new bootstrap.Modal(document.getElementById('roleModal'));
    window.openRoleModal = function(roleData = null) {
        if (roleData) {
            document.getElementById('roleModalTitle').innerHTML = '<i data-feather="edit-2" class="me-2 text-primary" style="width:18px;"></i> Edit Role';
            document.getElementById('role_action').value = 'edit_role';
            document.getElementById('role_id').value = roleData.id;
            document.getElementById('role_name').value = roleData.role_name;
            document.getElementById('role_description').value = roleData.description;
        } else {
            document.getElementById('roleModalTitle').innerHTML = '<i data-feather="shield" class="me-2 text-primary" style="width:18px;"></i> Add Role';
            document.getElementById('role_action').value = 'create_role';
            document.getElementById('role_id').value = '';
            document.getElementById('role_name').value = '';
            document.getElementById('role_description').value = '';
        }
        if (typeof feather !== 'undefined') feather.replace();
        roleModal.show();
    };

    const roleUsersModal = new bootstrap.Modal(document.getElementById('roleUsersModal'));
    window.viewRoleUsers = function(roleId, roleName) {
        document.getElementById('roleUsersModalName').textContent = roleName;
        const body = document.getElementById('roleUsersModalBody');
        body.innerHTML = '<div class="text-center p-5"><span class="spinner-border text-primary" role="status"></span></div>';
        roleUsersModal.show();

        fetch(`<?= APP_BASE_PATH ?>index.php?route=settings&action=get_role_users&role_id=${roleId}`)
            .then(r => r.text())
            .then(html => {
                body.innerHTML = html;
            })
            .catch(() => {
                body.innerHTML = '<div class="text-center text-danger p-4"><i data-feather="alert-triangle" class="mb-2"></i><br>Error loading users.</div>';
                if (typeof feather !== 'undefined') feather.replace();
            });
    };

    // --- AJAX LDAP Diagnostics ---
    const ldapTestForm = document.getElementById('ldapTestForm');
    if (ldapTestForm) {
        ldapTestForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const testBtn = document.getElementById('testLdapBtn');
            const testResultEl = document.getElementById('testResult');
            const formData = new FormData(ldapTestForm);
            formData.append('action', 'test_ldap_connection');

            const testUsername = document.getElementById('test_username').value;
            localStorage.setItem('ldapTestUsername', testUsername);

            testBtn.disabled = true;
            testBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Diagnosing...';
            testResultEl.innerHTML = '';

            fetch('<?= APP_BASE_PATH ?>index.php?route=settings', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    const alertClass = data.success ? 'alert-success border-success' : 'alert-danger border-danger';
                    const icon = data.success ? 'check-circle' : 'x-circle';
                    testResultEl.innerHTML = `<div class="alert ${alertClass} d-flex shadow-sm py-2 px-3 small fw-medium"><i data-feather="${icon}" class="me-2 mt-1 flex-shrink-0" style="width:16px; height:16px;"></i> <div>${data.message}</div></div>`;
                    if (typeof feather !== 'undefined') feather.replace();
                })
                .catch(error => {
                    testResultEl.innerHTML = `<div class="alert alert-danger border-danger d-flex shadow-sm py-2 px-3 small fw-medium"><i data-feather="alert-triangle" class="me-2 mt-1 flex-shrink-0" style="width:16px; height:16px;"></i> <div>An unexpected error occurred. Check network logs.</div></div>`;
                    if (typeof feather !== 'undefined') feather.replace();
                })
                .finally(() => {
                    testBtn.disabled = false;
                    testBtn.innerHTML = '<i data-feather="play-circle" style="width:16px;" class="me-2"></i> Run Diagnostics';
                    if (typeof feather !== 'undefined') feather.replace();
                });
        });
    }

    const clearLdapUsernameBtn = document.getElementById('clearLdapUsernameBtn');
    if (clearLdapUsernameBtn) {
        clearLdapUsernameBtn.addEventListener('click', function() {
            localStorage.removeItem('ldapTestUsername');
            document.getElementById('test_username').value = '';
        });
    }

    // --- Form Dirtiness & Unsaved Warnings ---
    const permissionsForm = document.getElementById('permissionsForm');
    const groupMappingForm = document.getElementById('groupMappingForm');
    const unsavedModalEl = document.getElementById('unsavedChangesModal');
    const unsavedModal = new bootstrap.Modal(unsavedModalEl);
    const discardBtn = document.getElementById('discardChangesBtn');
    let hasUnsavedChanges = false;
    let intendedUrl = null;

    if (permissionsForm) {
        const savePermissionsBtn = document.getElementById('savePermissionsBtn');
        permissionsForm.addEventListener('change', (event) => {
            if (event.target.type === 'checkbox') {
                hasUnsavedChanges = true;
                if (savePermissionsBtn) savePermissionsBtn.disabled = false;
            }
        });
        permissionsForm.addEventListener('submit', () => { hasUnsavedChanges = false; });
    }

    if (groupMappingForm) {
        groupMappingForm.addEventListener('change', () => { hasUnsavedChanges = true; });
        groupMappingForm.addEventListener('submit', () => { hasUnsavedChanges = false; });
    }

    document.addEventListener('click', function(event) {
        const link = event.target.closest('a');
        if (link && hasUnsavedChanges && !link.hasAttribute('data-bs-toggle') && link.getAttribute('target') !== '_blank') {
            const currentUrl = window.location.href.split('?')[0];
            const targetUrl = link.href.split('?')[0];
            if (currentUrl !== targetUrl || link.href !== window.location.href) {
                event.preventDefault();
                intendedUrl = link.href;
                unsavedModal.show();
            }
        }
    });

    if (discardBtn) {
        discardBtn.addEventListener('click', function() {
            hasUnsavedChanges = false;
            if (intendedUrl) { window.location.href = intendedUrl; }
        });
    }
    
    window.addEventListener('beforeunload', (e) => {
        if (hasUnsavedChanges) { e.preventDefault(); e.returnValue = ''; return ''; }
    });

    // --- Complex Checkbox Hierarchy Logic ---
    document.querySelectorAll('input[data-parent-for]').forEach(parentCheckbox => {
        const parentKey = parentCheckbox.dataset.parentFor;
        const childCheckboxes = document.querySelectorAll(`input[data-child-of="${parentKey}"]`);
        function updateChildren() {
            const shouldBeDisabled = !parentCheckbox.checked || parentCheckbox.disabled;
            childCheckboxes.forEach(child => { child.disabled = shouldBeDisabled; if (!parentCheckbox.checked) { child.checked = false; } });
        }
        parentCheckbox.addEventListener('change', updateChildren);
        childCheckboxes.forEach(child => { child.addEventListener('change', function() { if (this.checked) { parentCheckbox.checked = true; } }); });
        updateChildren();
    });
});
</script>