<?php

include_once '../includes/error_handler.php';
include_once '../includes/db_connect.php';
include_once '../includes/auth.php';

// A user can access this page if they can view ANY of the admin tabs
if (!can('view_users_tab') && !can('view_roles_tab') && !can('view_permissions_tab') && !can('view_ldap_tab') && !can('view_group_mapping_tab') && !can('view_synced_groups_tab')) {
    checkPermission('view_admin_menu'); // Fallback to trigger the standard "no permission" redirect
}

// --- START: AJAX HANDLERS ---
if (isset($_GET['action']) && $_GET['action'] == 'get_role_users' && isset($_GET['role_id'])) {
    header('Content-Type: text/html');
    $role_id = (int)$_GET['role_id'];
    $users_in_role = [];
    $stmt = $conn->prepare("SELECT firstname, lastname, department FROM users WHERE role_id = ? ORDER BY lastname, firstname");
    $stmt->bind_param("i", $role_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $users_in_role[] = $row; }
    $stmt->close();

    if (empty($users_in_role)) {
        echo '<p class="text-center text-muted p-3">No users are currently assigned to this role.</p>';
    } else {
        echo '<ul class="list-group list-group-flush">';
        foreach ($users_in_role as $user) {
            echo '<li class="list-group-item d-flex justify-content-between align-items-center">';
            echo htmlspecialchars($user['firstname'] . ' ' . $user['lastname']);
            echo '<span class="badge bg-secondary rounded-pill">' . htmlspecialchars($user['department']) . '</span>';
            echo '</li>';
        }
        echo '</ul>';
    }
    exit();
}
if (isset($_POST['action']) && $_POST['action'] == 'test_ldap_connection') {
    header('Content-Type: application/json');
    if (!can('view_ldap_tab')) {
        echo json_encode(['success' => false, 'message' => 'Permission denied.']);
        exit();
    }
    if (file_exists(__DIR__ . '/../includes/ldap_config.php')) {
        include_once __DIR__ . '/../includes/ldap_config.php';
    } else {
        echo json_encode(['success' => false, 'message' => 'LDAP config file not found. Please save your settings first.']);
        exit();
    }
    $test_user = trim($_POST['test_username'] ?? '');
    $test_pass = $_POST['test_password'] ?? '';
    if (empty($test_user) || empty($test_pass)) {
        echo json_encode(['success' => false, 'message' => 'Username and password are required for the test.']);
        exit();
    }

    ldap_set_option(null, LDAP_OPT_NETWORK_TIMEOUT, 5);

    $ldap_conn = ldap_connect(LDAP_HOST, (int)LDAP_PORT);
    if (!$ldap_conn) {
        echo json_encode(['success' => false, 'message' => 'Connection Failed: Could not connect to LDAP host. Check host, port, and firewall rules.']);
        exit();
    }
    ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    
    // --- START CORRECTION: Disable referrals for Active Directory ---
    // This is crucial for searching from the domain root (e.g., DC=example,DC=com)
    // and ensures the server searches its own catalog without referring to others.
    ldap_set_option($ldap_conn, LDAP_OPT_REFERRALS, 0);
    // --- END CORRECTION ---

    $bind_dn = defined('LDAP_BIND_DN') ? LDAP_BIND_DN : '';
    $bind_pass = defined('LDAP_BIND_PASSWORD') ? LDAP_BIND_PASSWORD : '';
    if (!empty($bind_dn) && !empty($bind_pass)) {
        $service_bind = @ldap_bind($ldap_conn, $bind_dn, $bind_pass);
    } else {
        $service_bind = @ldap_bind($ldap_conn);
    }
    if (!$service_bind) {
        echo json_encode(['success' => false, 'message' => 'Service Bind Failed: Could not bind to LDAP with service account credentials. Check Bind DN and Password.']);
        exit();
    }
    
    // START FIX: Use configured search parameters
    $login_attribute = defined('LDAP_LOGIN_ATTRIBUTE') ? LDAP_LOGIN_ATTRIBUTE : 'sAMAccountName';
    $base_filter = defined('LDAP_USER_SEARCH_FILTER') ? LDAP_USER_SEARCH_FILTER : '(&(objectClass=user)(objectCategory=person)(!(userAccountControl:1.2.840.113556.1.4.803:=2)))'; // Default AD filter components

    $username_search_term = "($login_attribute=" . ldap_escape($test_user, "", LDAP_ESCAPE_FILTER) . ")";
    $user_filter = "(&$username_search_term$base_filter)";
    // END FIX

    $search = @ldap_search($ldap_conn, LDAP_BASE_DN, $user_filter, ['dn']);
    if (!$search) {
        echo json_encode(['success' => false, 'message' => 'User Search Failed: Could not perform search. Check Base DN or User Search Filter.']);
        exit();
    }
    $entries = ldap_get_entries($ldap_conn, $search);
    if ($entries['count'] == 0) {
        echo json_encode(['success' => false, 'message' => 'User Not Found: The username could not be found in the directory.']);
        exit();
    }
    $user_dn = $entries[0]['dn'];
    $user_bind = @ldap_bind($ldap_conn, $user_dn, $test_pass);
    ldap_close($ldap_conn);
    if ($user_bind) {
        echo json_encode(['success' => true, 'message' => 'Success! Authentication for user "' . htmlspecialchars($test_user) . '" was successful.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Authentication Failed: The provided password for the user is incorrect.']);
    }
    exit();
}
// --- END: AJAX HANDLERS ---

// --- START: FORM & GET HANDLERS ---
function updateLdapConfigFile($config) {
    $filePath = __DIR__ . '/../includes/ldap_config.php';
    if ((file_exists($filePath) && !is_writable($filePath)) || (!file_exists($filePath) && !is_writable(dirname($filePath)))) {
        error_log("LDAP config file/directory is not writable: " . $filePath);
        return false;
    }
    $content = "<?php\n// LDAP Configuration - Last updated: " . date('Y-m-d H:i:s') . "\n";
    foreach ($config as $key => $value) {
        $formattedValue = is_bool($value) ? ($value ? 'true' : 'false') : "'" . addslashes($value) . "'";
        $content .= "define('" . $key . "', " . $formattedValue . ");\n";
    }

    if (file_put_contents($filePath, $content) !== false) {
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($filePath, true);
        }
        return true;
    }
    return false;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action == 'save_ldap_config' && can('edit_ldap_config')) {
        if (file_exists(__DIR__ . '/../includes/ldap_config.php')) { include_once __DIR__ . '/../includes/ldap_config.php'; }
        $new_config = [
            'ENABLE_LDAP_AUTH' => isset($_POST['enable_ldap_auth']),
            'LDAP_HOST' => trim($_POST['ldap_host']),
            'LDAP_PORT' => trim($_POST['ldap_port']),
            'LDAP_BASE_DN' => trim($_POST['ldap_base_dn']),
            'LDAP_DOMAIN' => trim($_POST['ldap_domain']),
            // NEW FIELDS
            'LDAP_LOGIN_ATTRIBUTE' => trim($_POST['ldap_login_attribute']),
            'LDAP_USER_SEARCH_FILTER' => trim($_POST['ldap_user_search_filter']),
            // END NEW FIELDS
            'LDAP_BIND_DN' => trim($_POST['ldap_bind_dn'])
        ];
        $new_password = trim($_POST['ldap_bind_password']);
        $new_config['LDAP_BIND_PASSWORD'] = !empty($new_password) ? $new_password : (defined('LDAP_BIND_PASSWORD') ? LDAP_BIND_PASSWORD : '');
        if (updateLdapConfigFile($new_config)) {
            $_SESSION['form_success'] = 'LDAP configuration saved successfully.';
            log_system_event($conn, 'System', 'LDAP configuration was updated.');
        } else { $_SESSION['form_error'] = 'Failed to write to includes/ldap_config.php. Please check file permissions.'; }
        header('Location: manage_users.php?tab=ldap');
        exit();
    }
    if ($action == 'search_ldap_groups' && can('edit_group_mapping')) {
        $keyword = trim($_POST['group_search_keyword'] ?? '');
        if (strlen($keyword) < 2) {
            $_SESSION['form_error'] = 'Please enter at least 2 characters to search for groups.';
            header('Location: manage_users.php?tab=group_mapping');
            exit();
        }
        if (file_exists(__DIR__ . '/../includes/ldap_config.php')) { include_once __DIR__ . '/../includes/ldap_config.php'; }
        if (!defined('ENABLE_LDAP_AUTH') || !ENABLE_LDAP_AUTH || !defined('LDAP_HOST') || !defined('LDAP_BASE_DN')) {
            $_SESSION['form_error'] = 'LDAP is not enabled or configured. Please check LDAP settings.';
            header('Location: manage_users.php?tab=group_mapping');
            exit();
        }
        
        ldap_set_option(null, LDAP_OPT_NETWORK_TIMEOUT, 5);

        $ldap_conn = ldap_connect(LDAP_HOST, (int)LDAP_PORT);
        if ($ldap_conn) {
            ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, 3);
            
            // --- START CORRECTION: Disable referrals for Active Directory ---
            // This is crucial for searching from the domain root (e.g., DC=example,DC=com)
            // and ensures the server searches its own catalog without referring to others.
            ldap_set_option($ldap_conn, LDAP_OPT_REFERRALS, 0);
            // --- END CORRECTION ---

            $bind_dn = defined('LDAP_BIND_DN') ? LDAP_BIND_DN : '';
            $bind_pass = defined('LDAP_BIND_PASSWORD') ? LDAP_BIND_PASSWORD : '';
            $bind = (!empty($bind_dn) && !empty($bind_pass)) ? @ldap_bind($ldap_conn, $bind_dn, $bind_pass) : @ldap_bind($ldap_conn);
            if ($bind) {
                ldap_set_option($ldap_conn, LDAP_OPT_TIMELIMIT, 10);
                
                $filter = "(&(objectCategory=group)(cn=*".ldap_escape($keyword, "", LDAP_ESCAPE_FILTER)."*))";

                $search = @ldap_search($ldap_conn, LDAP_BASE_DN, $filter, ['dn', 'cn']);
                if ($search) {
                    $entries = ldap_get_entries($ldap_conn, $search);
                    if ($entries['count'] > 0) {
                        $local_groups_res = $conn->query("SELECT group_dn FROM ldap_groups");
                        $local_group_dns = [];
                        while ($row = $local_groups_res->fetch_assoc()) { $local_group_dns[] = $row['group_dn']; }
                        $new_groups_added_count = 0;
                        $stmt = $conn->prepare("INSERT INTO ldap_groups (group_dn, group_name) VALUES (?, ?)");
                        for ($i = 0; $i < $entries['count']; $i++) {
                            if (isset($entries[$i]['dn']) && isset($entries[$i]['cn'][0])) {
                                $dn = $entries[$i]['dn'];
                                if (!in_array($dn, $local_group_dns)) {
                                    $cn = $entries[$i]['cn'][0];
                                    $stmt->bind_param("ss", $dn, $cn);
                                    $stmt->execute();
                                    $new_groups_added_count++;
                                }
                            }
                        }
                        $stmt->close();
                        if ($new_groups_added_count > 0) {
                            $_SESSION['form_success'] = "Sync complete. Added " . $new_groups_added_count . " new groups.";
                            log_system_event($conn, 'LDAP', 'Fetched and added ' . $new_groups_added_count . ' new groups from LDAP matching keyword: "' . $keyword . '".');
                        } else { $_SESSION['form_success'] = "Sync complete. No new groups were found."; }
                    } else { $_SESSION['form_error'] = 'No groups found in LDAP matching "' . htmlspecialchars($keyword) . '".'; }
                } else { $_SESSION['form_error'] = "The LDAP search timed out or failed. Please check the Base DN and try a more specific keyword."; }
            } else { $_SESSION['form_error'] = 'Could not bind to LDAP server. Check Bind DN and Password.'; }
            ldap_close($ldap_conn);
        } else { $_SESSION['form_error'] = 'Could not connect to LDAP server. Check host, port, and firewall rules.'; }
        header('Location: manage_users.php?tab=group_mapping');
        exit();
    }
    if ($action == 'save_group_mappings' && can('edit_group_mapping')) {
        $mappings = $_POST['mappings'] ?? [];
        $mapped_groups = [];
        foreach ($mappings as $role_id => $ldap_group_id) {
            if (!empty($ldap_group_id)) {
                if (in_array($ldap_group_id, $mapped_groups)) {
                    $_SESSION['form_error'] = 'Each LDAP group can only be mapped to one role. Please correct your selections.';
                    header('Location: manage_users.php?tab=group_mapping');
                    exit();
                }
                $mapped_groups[] = $ldap_group_id;
            }
        }
        $conn->begin_transaction();
        try {
            $conn->query("DELETE FROM role_ldap_group_mapping");
            if (!empty($mappings)) {
                $stmt = $conn->prepare("INSERT INTO role_ldap_group_mapping (role_id, ldap_group_id) VALUES (?, ?)");
                foreach ($mappings as $role_id => $ldap_group_id) {
                    if (!empty($ldap_group_id)) {
                        $stmt->bind_param("ii", $role_id, $ldap_group_id);
                        $stmt->execute();
                    }
                }
                $stmt->close();
            }
            $conn->commit();
            $_SESSION['form_success'] = 'LDAP group mappings saved successfully.';
            log_system_event($conn, 'LDAP Group', 'LDAP group to role mappings were updated.');
        } catch (Exception $e) { $conn->rollback(); $_SESSION['form_error'] = 'An error occurred while saving the mappings.'; }
        header('Location: manage_users.php?tab=group_mapping');
        exit();
    }
    if ($action == 'save_role_permissions' && can('edit_permissions')) {
        $role_id = $_POST['role_id'];
        $role_name_stmt = $conn->prepare("SELECT role_name FROM roles WHERE id = ?");
        $role_name_stmt->bind_param("i", $role_id);
        $role_name_stmt->execute();
        $role_name = $role_name_stmt->get_result()->fetch_assoc()['role_name'] ?? 'ID: ' . $role_id;
        $role_name_stmt->close();
        if ($role_id == 1) {
            $_SESSION['form_error'] = 'The Super Admin role permissions cannot be modified.';
            header('Location: manage_users.php?tab=permissions&selected_role=' . $role_id);
            exit();
        }
        $posted_permissions = $_POST['permissions'] ?? [];
        $conn->begin_transaction();
        try {
            $stmt_delete = $conn->prepare("DELETE FROM role_permissions WHERE role_id = ?");
            $stmt_delete->bind_param("i", $role_id); $stmt_delete->execute();
            if (!empty($posted_permissions)) {
                $stmt_insert = $conn->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                foreach ($posted_permissions as $perm_id => $value) { if ($value === 'on') { $stmt_insert->bind_param("ii", $role_id, $perm_id); $stmt_insert->execute(); } }
            }
            $conn->commit();
            $_SESSION['form_success'] = 'Permissions updated successfully.';
            log_system_event($conn, 'Permissions', 'Permissions updated for role: "' . $role_name . '".');
        } catch (Exception $e) { $conn->rollback(); $_SESSION['form_error'] = 'Error updating permissions.'; }
        header('Location: manage_users.php?tab=permissions&selected_role=' . $role_id);
        exit();
    }
    if ($action == 'add_role' && can('create_role')) {
        $role_name = trim($_POST['role_name']); $description = trim($_POST['description']);
        if (!empty($role_name)) {
            $stmt = $conn->prepare("INSERT INTO roles (role_name, description) VALUES (?, ?)");
            $stmt->bind_param("ss", $role_name, $description);
            if ($stmt->execute()) {
                $new_role_id = $stmt->insert_id;
                $_SESSION['form_success'] = 'Role "' . htmlspecialchars($role_name) . '" created successfully.';
                log_system_event($conn, 'Role Management', 'Created new role: "' . $role_name . '".');
                header('Location: manage_users.php?tab=roles&selected_role_id=' . $new_role_id);
            } else { $_SESSION['form_error'] = 'Error: Role name may already exist.'; header('Location: manage_users.php?tab=roles&action=new'); }
        } else { $_SESSION['form_error'] = 'Role name cannot be empty.'; header('Location: manage_users.php?tab=roles&action=new'); }
        exit();
    }
    if ($action == 'edit_role' && can('edit_role')) {
        $role_id = $_POST['role_id']; $role_name = trim($_POST['role_name']); $description = trim($_POST['description']);
        if (!empty($role_name) && !empty($role_id)) {
            if ($role_id == 1) { $_SESSION['form_error'] = 'The Super Admin role cannot be modified.'; }
            else {
                $stmt = $conn->prepare("UPDATE roles SET role_name = ?, description = ? WHERE id = ?");
                $stmt->bind_param("ssi", $role_name, $description, $role_id);
                if ($stmt->execute()) { $_SESSION['form_success'] = 'Role "' . htmlspecialchars($role_name) . '" updated successfully.'; log_system_event($conn, 'Role Management', 'Edited role: "' . $role_name . '".'); }
                else { $_SESSION['form_error'] = 'Error updating role.'; }
            }
        } else { $_SESSION['form_error'] = 'Role name and ID cannot be empty.'; }
        header('Location: manage_users.php?tab=roles&selected_role_id=' . $role_id);
        exit();
    }
    if ($action == 'edit_user' && can('edit_user_role')) {
        $user_id = $_POST['user_id']; $role_id = $_POST['role_id'];
        if ($user_id != 1) {
            $stmt = $conn->prepare("UPDATE users SET role_id = ? WHERE id = ?");
            $stmt->bind_param("ii", $role_id, $user_id);
            if ($stmt->execute()) {
                $user_info = $conn->query("SELECT username FROM users WHERE id = $user_id")->fetch_assoc();
                $role_info = $conn->query("SELECT role_name FROM roles WHERE id = $role_id")->fetch_assoc();
                $_SESSION['form_success'] = "User's role updated successfully.";
                log_system_event($conn, 'User Management', 'Assigned role "' . ($role_info['role_name'] ?? 'N/A') . '" to user "' . ($user_info['username'] ?? 'N/A') . '".');
            } else { $_SESSION['form_error'] = "Error updating user's role."; }
        } else { $_SESSION['form_error'] = "The initial super admin's role cannot be changed."; }
        header('Location: manage_users.php?tab=users&selected_user_id=' . $user_id);
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
            $role_info = $conn->query("SELECT role_name FROM roles WHERE id = $role_id_to_delete")->fetch_assoc();
            $stmt = $conn->prepare("DELETE FROM roles WHERE id = ?");
            $stmt->bind_param("i", $role_id_to_delete);
            if ($stmt->execute()) { $_SESSION['form_success'] = 'Role deleted successfully.'; log_system_event($conn, 'Role Management', 'Deleted role: "' . ($role_info['role_name'] ?? 'ID: '.$role_id_to_delete) . '".'); }
            else { $_SESSION['form_error'] = 'Error deleting role.'; }
        }
    }
    header('Location: manage_users.php?tab=roles');
    exit();
}
if (isset($_GET['delete_user']) && is_numeric($_GET['delete_user']) && can('delete_user')) {
    $user_id = $_GET['delete_user'];
    if ($user_id > 1 && $user_id != $_SESSION['user_id']) {
        $user_info = $conn->query("SELECT username FROM users WHERE id = $user_id")->fetch_assoc();
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) { $_SESSION['form_success'] = 'User deleted successfully.'; log_system_event($conn, 'User Management', 'Deleted user: "' . ($user_info['username'] ?? 'ID: '.$user_id) . '".'); }
        else { $_SESSION['form_error'] = 'Error deleting user.'; }
    } else { $_SESSION['form_error'] = 'This user cannot be deleted.'; }
    header('Location: manage_users.php?tab=users');
    exit();
}
if (isset($_GET['delete_ldap_group']) && is_numeric($_GET['delete_ldap_group']) && can('delete_synced_group')) {
    $group_id_to_delete = (int)$_GET['delete_ldap_group'];
    
    $stmt_check = $conn->prepare("SELECT COUNT(*) FROM role_ldap_group_mapping WHERE ldap_group_id = ?");
    $stmt_check->bind_param("i", $group_id_to_delete);
    $stmt_check->execute();
    $mapping_count = $stmt_check->get_result()->fetch_row()[0];
    $stmt_check->close();

    if ($mapping_count > 0) {
        $_SESSION['form_error'] = 'Cannot delete group: It is currently mapped to ' . $mapping_count . ' role(s). Please unmap it first.';
    } else {
        $group_info = $conn->query("SELECT group_name FROM ldap_groups WHERE id = $group_id_to_delete")->fetch_assoc();
        $stmt_delete = $conn->prepare("DELETE FROM ldap_groups WHERE id = ?");
        $stmt_delete->bind_param("i", $group_id_to_delete);
        if ($stmt_delete->execute()) {
            $_SESSION['form_success'] = 'Synced group deleted successfully.';
            log_system_event($conn, 'LDAP Group', 'Deleted synced group: "' . ($group_info['group_name'] ?? 'ID: ' . $group_id_to_delete) . '".');
        } else {
            $_SESSION['form_error'] = 'Error deleting synced group.';
        }
        $stmt_delete->close();
    }
    header('Location: manage_users.php?tab=synced_groups');
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

$active_tab = $_GET['tab'] ?? '';
if (empty($active_tab) || !in_array($active_tab, ['users', 'roles', 'permissions', 'ldap', 'group_mapping', 'synced_groups']) || !can('view_' . $active_tab . '_tab')) {
    if (can('view_users_tab')) { $active_tab = 'users'; }
    elseif (can('view_roles_tab')) { $active_tab = 'roles'; }
    elseif (can('view_permissions_tab')) { $active_tab = 'permissions'; }
    elseif (can('view_group_mapping_tab')) { $active_tab = 'group_mapping'; }
    elseif (can('view_synced_groups_tab')) { $active_tab = 'synced_groups'; }
    elseif (can('view_ldap_tab')) { $active_tab = 'ldap'; }
}

$role_action = $_GET['action'] ?? '';
$selected_user_id = 0;
if ($active_tab == 'users') {
    $selected_user_id = isset($_GET['selected_user_id']) ? (int)$_GET['selected_user_id'] : 0;
}
$selected_role_id = 0;
if ($active_tab == 'roles') { $selected_role_id = isset($_GET['selected_role_id']) ? (int)$_GET['selected_role_id'] : 0; }
if ($active_tab == 'permissions') { $selected_role_id = isset($_GET['selected_role']) ? (int)$_GET['selected_role'] : ($roles[0]['id'] ?? 0); }

$selected_role_data = null;
$assigned_users_to_role = [];
if ($active_tab == 'roles' && $selected_role_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM roles WHERE id = ?"); $stmt->bind_param("i", $selected_role_id); $stmt->execute();
    $selected_role_data = $stmt->get_result()->fetch_assoc(); $stmt->close();
    $stmt_users = $conn->prepare("SELECT firstname, lastname, department FROM users WHERE role_id = ? ORDER BY lastname, firstname");
    $stmt_users->bind_param("i", $selected_role_id); $stmt_users->execute();
    $result_users = $stmt_users->get_result();
    while ($row = $result_users->fetch_assoc()) { $assigned_users_to_role[] = $row; }
    $stmt_users->close();
}
$selected_user_data = null;
if ($active_tab == 'users' && $selected_user_id > 0) {
    $stmt = $conn->prepare("SELECT u.*, r.role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
    $stmt->bind_param("i", $selected_user_id);
    $stmt->execute();
    $selected_user_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$selected_role_name = '';
$all_permissions = [];
$all_permissions_result = $conn->query("SELECT id, action_key, action_name FROM permissions ORDER BY action_name");
while ($perm = $all_permissions_result->fetch_assoc()) { $all_permissions[$perm['action_key']] = ['id' => $perm['id'], 'name' => $perm['action_name']]; }
$saved_permissions = [];
if ($selected_role_id > 0) { $stmt = $conn->prepare("SELECT permission_id FROM role_permissions WHERE role_id = ?"); $stmt->bind_param("i", $selected_role_id); $stmt->execute(); $result = $stmt->get_result(); while ($row = $result->fetch_assoc()) { $saved_permissions[$row['permission_id']] = true; } $stmt->close(); }

$ldap_groups = [];
$group_mappings = [];
if ($active_tab == 'group_mapping') {
    $groups_result = $conn->query("SELECT id, group_name FROM ldap_groups ORDER BY group_name ASC");
    if ($groups_result) { while($g = $groups_result->fetch_assoc()) { $ldap_groups[] = $g; } }
    $mappings_result = $conn->query("SELECT role_id, ldap_group_id FROM role_ldap_group_mapping");
    if ($mappings_result) { while($m = $mappings_result->fetch_assoc()) { $group_mappings[$m['role_id']] = $m['ldap_group_id']; } }
}

$synced_ldap_groups = [];
if ($active_tab == 'synced_groups') {
    $groups_result = $conn->query("SELECT id, group_name FROM ldap_groups ORDER BY group_name ASC");
    if ($groups_result) { while($g = $groups_result->fetch_assoc()) { $synced_ldap_groups[] = $g; } }
}

if (file_exists(__DIR__ . '/../includes/ldap_config.php')) { include_once __DIR__ . '/../includes/ldap_config.php'; }
$ldap_config = [
    'ENABLE_LDAP_AUTH' => defined('ENABLE_LDAP_AUTH') ? ENABLE_LDAP_AUTH : false, 
    'LDAP_HOST' => defined('LDAP_HOST') ? LDAP_HOST : '',
    'LDAP_PORT' => defined('LDAP_PORT') ? LDAP_PORT : '389',
    'LDAP_BASE_DN' => defined('LDAP_BASE_DN') ? LDAP_BASE_DN : '',
    'LDAP_DOMAIN' => defined('LDAP_DOMAIN') ? LDAP_DOMAIN : '',
    // NEW FIELDS
    'LDAP_LOGIN_ATTRIBUTE' => defined('LDAP_LOGIN_ATTRIBUTE') ? LDAP_LOGIN_ATTRIBUTE : 'sAMAccountName',
    'LDAP_USER_SEARCH_FILTER' => defined('LDAP_USER_SEARCH_FILTER') ? LDAP_USER_SEARCH_FILTER : '(&(objectClass=user)(objectCategory=person)(!(userAccountControl:1.2.840.113556.1.4.803:=2)))',
    // END NEW FIELDS
    'LDAP_BIND_DN' => defined('LDAP_BIND_DN') ? LDAP_BIND_DN : '',
    'LDAP_BIND_PASSWORD' => defined('LDAP_BIND_PASSWORD') ? LDAP_BIND_PASSWORD : ''
];
// --- END: DATA FETCHING ---

$pageTitle = "Users, Roles, & Permissions";
include_once '../includes/header.php';
?>
<style>
    :root { --primary-deep-green: #0C5D58; }
    .action-buttons-container { display: flex; gap: 0.5rem; justify-content: center; align-items: center; }
    .action-buttons-container .btn { background: none !important; border: none !important; padding: 0.1rem 0.2rem !important; vertical-align: middle; box-shadow: none !important; color: #6c757d; }
    .action-buttons-container .btn:hover { color: var(--primary-deep-green); }
    .table thead.table-deep-green th { background-color: transparent !important; color: var(--primary-deep-green) !important; border-bottom: 2px solid var(--primary-deep-green) !important; font-weight: 600; }
    .roles-nav .list-group-item.active, .users-nav .list-group-item.active { border-left: 4px solid var(--primary-deep-green); background-color: #f1f5f9; color: var(--primary-deep-green); font-weight: 600; }
    .nav-tabs .nav-link { font-weight: 500; }
    .nav-tabs .nav-link.active { font-weight: 600; }
    .permission-section { margin-bottom: 2rem; }
    .permission-section-title { font-size: 1.1rem; font-weight: 600; margin-bottom: 1rem; color: #334155; }
    .permission-sub-group { border-left: 3px solid #e2e8f0; padding-left: 1.5rem; margin-top: 1.5rem; }
    .permission-sub-group-title { font-size: 0.95rem; font-weight: 600; color: #475569; margin-bottom: 0.75rem; }
    .btn-deep-green { background-color: var(--primary-deep-green); border-color: var(--primary-deep-green); color: #fff; }
    .btn-deep-green:hover { background-color: #0a4a45; border-color: #0a4a45; }
    .form-section-header { color: var(--primary-deep-green); font-weight: 600; }
</style>

<div class="container-xl">
    <div class="container-fluid pt-4">
        <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1100">
            <div id="formToast" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="toast-header"><strong class="me-auto" id="toastTitle"></strong><button type="button" class="btn-close" data-bs-dismiss="toast"></button></div>
                <div class="toast-body" id="toastBody"></div>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <ul class="nav nav-tabs">
                <?php if (can('view_users_tab')): ?><li class="nav-item"><a class="nav-link <?php if($active_tab == 'users') echo 'active'; ?>" href="?tab=users">Users</a></li><?php endif; ?>
                <?php if (can('view_roles_tab')): ?><li class="nav-item"><a class="nav-link <?php if($active_tab == 'roles') echo 'active'; ?>" href="?tab=roles">Roles</a></li><?php endif; ?>
                <?php if (can('view_permissions_tab')): ?><li class="nav-item"><a class="nav-link <?php if($active_tab == 'permissions') echo 'active'; ?>" href="?tab=permissions">Permissions</a></li><?php endif; ?>
                <?php if (can('view_group_mapping_tab')): ?><li class="nav-item"><a class="nav-link <?php if($active_tab == 'group_mapping') echo 'active'; ?>" href="?tab=group_mapping">Group Mapping</a></li><?php endif; ?>
                <?php if (can('view_synced_groups_tab')): ?><li class="nav-item"><a class="nav-link <?php if($active_tab == 'synced_groups') echo 'active'; ?>" href="?tab=synced_groups">Synced Groups</a></li><?php endif; ?>
                <?php if (can('view_ldap_tab')): ?><li class="nav-item"><a class="nav-link <?php if($active_tab == 'ldap') echo 'active'; ?>" href="?tab=ldap">LDAP</a></li><?php endif; ?>
            </ul>
        </div>

        <div class="tab-content">
            <?php if (can('view_users_tab')): ?>
            <div class="tab-pane fade <?php if($active_tab == 'users') echo 'show active'; ?>" id="users-pane">
                <div class="row">
                    <div class="col-md-5">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <form action="" method="get" class="mb-4">
                                    <input type="hidden" name="tab" value="users">
                                    <div class="mb-2"><input type="text" name="search" class="form-control" placeholder="Search by name or username..." value="<?php echo htmlspecialchars($search_filter); ?>"></div>
                                    <div class="mb-2"><select name="dept_filter" class="form-select"><option value="all">All Departments</option><?php foreach($departments as $dept):?><option value="<?php echo htmlspecialchars($dept); ?>" <?php if($dept_filter==$dept) echo 'selected';?>><?php echo htmlspecialchars($dept); ?></option><?php endforeach; ?></select></div>
                                    <div class="mb-2"><select name="role_filter" class="form-select"><option value="all">All Roles</option><?php foreach($roles as $role):?><option value="<?php echo $role['id']; ?>" <?php if($role_filter==$role['id']) echo 'selected';?>><?php echo htmlspecialchars($role['role_name']); ?></option><?php endforeach; ?></select></div>
                                    <button type="submit" class="btn btn-deep-green w-100">Filter Users</button>
                                </form>
                                <div class="list-group users-nav" style="max-height: 500px; overflow-y: auto;">
                                    <?php foreach($users as $user): ?>
                                    <a href="manage_users.php?tab=users&selected_user_id=<?php echo $user['id']; ?>" class="list-group-item list-group-item-action <?php if ($user['id'] == $selected_user_id) echo 'active'; ?>">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1"><?php echo htmlspecialchars(trim($user['firstname'].' '.$user['lastname'])); ?></h6>
                                            <small class="text-muted"><?php echo htmlspecialchars($user['username']); ?></small>
                                        </div>
                                        <small class="text-muted"><?php echo htmlspecialchars($user['department']); ?></small>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-7">
                        <div class="card shadow-sm">
                            <?php if ($selected_user_data): ?>
                                <div class="card-header"><h5 class="mb-0">Manage User: <?php echo htmlspecialchars(trim($selected_user_data['firstname'].' '.$selected_user_data['lastname'])); ?></h5></div>
                                <div class="card-body">
                                    <dl class="row">
                                        <dt class="col-sm-3">Username</dt><dd class="col-sm-9"><?php echo htmlspecialchars($selected_user_data['username']); ?></dd>
                                        <dt class="col-sm-3">Department</dt><dd class="col-sm-9"><?php echo htmlspecialchars($selected_user_data['department']); ?></dd>
                                    </dl>
                                    <hr>
                                    <form action="manage_users.php" method="POST">
                                        <input type="hidden" name="action" value="edit_user">
                                        <input type="hidden" name="user_id" value="<?php echo $selected_user_data['id']; ?>">
                                        <fieldset <?php if (!can('edit_user_role') || $selected_user_data['id'] == 1) echo 'disabled'; ?>>
                                            <div class="mb-3"><label for="userRole" class="form-label fw-bold">Assign Role</label><select class="form-select" id="userRole" name="role_id" required><option value="">Select a Role...</option><?php foreach($roles as $role): ?><option value="<?php echo $role['id']; ?>" <?php if ($selected_user_data['role_id'] == $role['id']) echo 'selected'; ?>><?php echo htmlspecialchars($role['role_name']); ?></option><?php endforeach; ?></select></div>
                                        </fieldset>
                                        
                                        <?php
                                            $can_delete_this_user = can('delete_user') && $selected_user_data['id'] > 1 && $selected_user_data['id'] != $_SESSION['user_id'];
                                            $can_edit_this_user_role = can('edit_user_role') && $selected_user_data['id'] != 1;
                                            
                                            $button_container_class = 'text-end mt-4'; // Default alignment
                                            if ($can_delete_this_user && $can_edit_this_user_role) {
                                                $button_container_class = 'd-flex justify-content-between mt-4'; // Align apart if both exist
                                            }
                                        ?>
                                        <div class="<?php echo $button_container_class; ?>">
                                            <?php if ($can_delete_this_user): ?>
                                                <a href="manage_users.php?delete_user=<?php echo $selected_user_data['id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this user?');">Delete User</a>
                                            <?php endif; ?>
                                            <?php if ($can_edit_this_user_role): ?>
                                                <button type="submit" class="btn btn-deep-green">Save Role</button>
                                            <?php endif; ?>
                                        </div>
                                    </form>
                                </div>
                            <?php else: ?>
                                <div class="card-body text-center text-muted d-flex align-items-center justify-content-center" style="min-height: 300px;">
                                    <p><i data-feather="info" class="me-2"></i>Select a user from the list to view their details.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (can('view_roles_tab')): ?>
            <div class="tab-pane fade <?php if($active_tab == 'roles') echo 'show active'; ?>" id="roles-pane">
                <div class="row">
                    <div class="col-md-4">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <?php if (can('create_role')): ?>
                                <a href="manage_users.php?tab=roles&action=new" class="btn btn-deep-green w-100 mb-3"><i data-feather="plus" class="me-1"></i> Add New Role</a>
                                <?php endif; ?>
                                <div class="list-group roles-nav">
                                    <?php foreach($roles as $role): ?>
                                    <a href="manage_users.php?tab=roles&selected_role_id=<?php echo $role['id']; ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?php if ($role['id'] == $selected_role_id) echo 'active'; ?>">
                                        <?php echo htmlspecialchars($role['role_name']); ?>
                                        <span class="badge bg-primary rounded-pill"><?php echo $role_user_counts[$role['id']] ?? 0; ?></span>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="card shadow-sm">
                            <?php if ($role_action === 'new' && can('create_role')): ?>
                                <div class="card-header"><h5 class="mb-0">Create New Role</h5></div>
                                <div class="card-body">
                                    <form action="manage_users.php" method="POST">
                                        <input type="hidden" name="action" value="add_role">
                                        <div class="mb-3"><label for="roleName" class="form-label">Role Name</label><input type="text" class="form-control" id="roleName" name="role_name" required></div>
                                        <div class="mb-3"><label for="roleDescription" class="form-label">Description</label><textarea class="form-control" id="roleDescription" name="description" rows="3"></textarea></div>
                                        <div class="text-end"><button type="submit" class="btn btn-deep-green">Save Role</button></div>
                                    </form>
                                </div>
                            <?php elseif ($selected_role_data): ?>
                                <div class="card-header">
                                    <ul class="nav nav-tabs card-header-tabs">
                                        <li class="nav-item"><button class="nav-link active" id="role-details-tab" data-bs-toggle="tab" data-bs-target="#role-details-pane" type="button">Details</button></li>
                                        <li class="nav-item"><button class="nav-link" id="role-users-tab" data-bs-toggle="tab" data-bs-target="#role-users-pane" type="button">Assigned Users</button></li>
                                    </ul>
                                </div>
                                <div class="card-body">
                                    <div class="tab-content">
                                        <div class="tab-pane fade show active" id="role-details-pane">
                                            <form action="manage_users.php" method="POST">
                                                <input type="hidden" name="action" value="edit_role">
                                                <input type="hidden" name="role_id" value="<?php echo $selected_role_data['id']; ?>">
                                                <fieldset <?php if (!can('edit_role') || $selected_role_data['id'] == 1) echo 'disabled'; ?>>
                                                    <div class="mb-3"><label for="roleNameEdit" class="form-label">Role Name</label><input type="text" class="form-control" id="roleNameEdit" name="role_name" value="<?php echo htmlspecialchars($selected_role_data['role_name']); ?>" required></div>
                                                    <div class="mb-3"><label for="roleDescriptionEdit" class="form-label">Description</label><textarea class="form-control" id="roleDescriptionEdit" name="description" rows="4"><?php echo htmlspecialchars($selected_role_data['description']); ?></textarea></div>
                                                </fieldset>
                                                
                                                <?php
                                                    $can_delete_this_role = can('delete_role') && $selected_role_data['id'] != 1 && ($role_user_counts[$selected_role_data['id']] ?? 0) == 0;
                                                    $can_edit_this_role = can('edit_role') && $selected_role_data['id'] != 1;
                                                    
                                                    $role_button_container_class = 'text-end mt-4'; // Default alignment
                                                    if ($can_delete_this_role && $can_edit_this_role) {
                                                        $role_button_container_class = 'd-flex justify-content-between mt-4'; // Align apart if both exist
                                                    }
                                                ?>
                                                <div class="<?php echo $role_button_container_class; ?>">
                                                    <?php if ($can_delete_this_role): ?>
                                                        <a href="manage_users.php?delete_role=<?php echo $selected_role_data['id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this role?');">Delete Role</a>
                                                    <?php endif; ?>
                                                    <?php if ($can_edit_this_role): ?>
                                                        <button type="submit" class="btn btn-deep-green">Save Changes</button>
                                                    <?php endif; ?>
                                                </div>
                                            </form>
                                        </div>
                                        <div class="tab-pane fade" id="role-users-pane">
                                            <?php if (empty($assigned_users_to_role)): ?>
                                                <p class="text-muted text-center">No users are currently assigned to this role.</p>
                                            <?php else: ?>
                                                <ul class="list-group list-group-flush">
                                                    <?php foreach ($assigned_users_to_role as $user): ?>
                                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                                            <?php echo htmlspecialchars($user['firstname'] . ' ' . $user['lastname']); ?>
                                                            <span class="badge bg-secondary rounded-pill"><?php echo htmlspecialchars($user['department']); ?></span>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="card-body text-center text-muted d-flex align-items-center justify-content-center" style="min-height: 300px;">
                                    <p><i data-feather="info" class="me-2"></i>Please select a role from the list to view its details, or create a new one.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (can('view_permissions_tab')): ?>
            <div class="tab-pane fade <?php if($active_tab == 'permissions') echo 'show active'; ?>" id="permissions-pane">
                <?php $can_edit_perms = can('edit_permissions'); $disabled_attr = !$can_edit_perms ? 'disabled' : ''; ?>
                <div class="card shadow-sm"><div class="row g-0">
                    <div class="col-md-3" style="border-right: 1px solid #dee2e6;">
                        <div class="p-3"><h5 class="form-section-header border-0 mb-0">Select a Role</h5></div>
                        <div class="list-group list-group-flush roles-nav">
                            <?php foreach ($roles as $role): if ($role['id'] == $selected_role_id) $selected_role_name = $role['role_name']; ?>
                                <a href="?tab=permissions&selected_role=<?php echo $role['id']; ?>" class="list-group-item list-group-item-action <?php if ($role['id'] == $selected_role_id) echo 'active'; ?>"><?php echo htmlspecialchars($role['role_name']); ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-md-9">
                        <form id="permissionsForm" action="manage_users.php?tab=permissions&selected_role=<?php echo $selected_role_id; ?>" method="POST">
                            <input type="hidden" name="action" value="save_role_permissions"><input type="hidden" name="role_id" value="<?php echo $selected_role_id; ?>">
                            <div class="p-3 d-flex justify-content-between align-items-center" style="background-color: #f8f9fa; border-bottom: 1px solid #dee2e6;">
                                <h5 class="form-section-header border-0 mb-0">Permissions for: <span class="text-primary"><?php echo htmlspecialchars($selected_role_name); ?></span></h5>
                                <?php if ($can_edit_perms): ?><button type="submit" id="savePermissionsBtn" class="btn btn-deep-green" <?php if ($selected_role_id == 0 || $selected_role_id == 1) echo 'disabled'; ?> disabled><i data-feather="save" class="me-1"></i> Save Permissions</button><?php endif; ?>
                            </div>
                            <div class="p-4"><div class="row"><div class="col-md-12">
                            <?php
                            $ui_structure = [
                                'Portals' => ['parent' => 'view_portals_menu', 'permissions' => ['manage_portals']],
                                'Dashboard' => ['parent' => 'view_dashboard', 'permissions' => []],
                                'Templates' => ['parent' => 'view_templates_list', 'permissions' => ['create_template', 'approve_template', 'delete_template', 'download_template_pdf', 'view_employee_details', 'view_domain_details', 'view_system_info', 'view_induction_details', 'sub_group' => ['Details & Induction' => ['edit_template_basic', 'edit_induction_it', 'edit_induction_hr', 'edit_induction_facility'], 'Tab Views' => ['view_template_readiness_tab', 'view_activity_timeline', 'view_timeline_template_events', 'view_timeline_readiness_events'], 'Column Visibility' => ['view_column_created_by', 'view_column_system_readiness', 'view_column_it_induction', 'view_column_hr_induction', 'view_column_facility_induction']]]],
                                'Checklist' => ['parent' => 'view_checklist_menu', 'permissions' => ['sub_group' => ['System Readiness' => ['view_system_readiness','manage_system_readiness', 'verify_system_readiness', 'edit_without_assign', 'download_checklist_pdf'], 'Exit Checklist' => ['view_exit_checklist', 'create_exit_checklist', 'manage_exit_checklist', 'approve_exit_checklist', 'delete_exit_checklist', 'download_exit_checklist_pdf', 'edit_exit_checklist_without_assign' ]]]],
                                'Gatepass' => ['parent' => 'view_gatepass', 'permissions' => ['create_gatepass', 'edit_gatepass_details', 'delete_gatepass', 'approve_gatepass', 'download_gatepass_pdf', 'edit_security_fields']],
                                'Shipment' => ['parent' => 'view_shipment', 'permissions' => ['create_shipment', 'edit_shipment', 'delete_shipment']],
                                'Reports' => ['parent' => 'view_reports', 'permissions' => []],
                                'Administration' => ['parent' => 'view_admin_menu', 'permissions' => ['sub_group' => [
                                    'Users & Roles' => ['view_users_tab', 'edit_user_role', 'delete_user', 'view_roles_tab', 'create_role', 'edit_role', 'delete_role', 'view_permissions_tab', 'edit_permissions'],
                                    'LDAP' => ['view_ldap_tab', 'edit_ldap_config'],
                                    'Group Mapping' => ['view_group_mapping_tab', 'edit_group_mapping', 'view_synced_groups_tab', 'delete_synced_group'],
                                    'Forms Management' => ['view_manage_forms_page', 'view_departments_tab', 'create_department', 'edit_department', 'delete_department','view_locations_tab','create_location','edit_location','delete_location'],
                                    'Manage Employees' => ['view_employees', 'edit_employees', 'manage_employees'],
                                    'System Audit Trail' => ['view_audit_trail']
                                ]]]
                            ];
                            foreach ($ui_structure as $section_title => $data):
                                $parent_key = $data['parent']; if (!isset($all_permissions[$parent_key])) continue;
                                $parent_perm_id = $all_permissions[$parent_key]['id'];
                            ?>
                                <div class="permission-section">
                                    <div class="form-check permission-section-title">
                                        <input class="form-check-input" type="checkbox" name="permissions[<?php echo $parent_perm_id; ?>]" id="perm-<?php echo $selected_role_id . '-' . $parent_perm_id; ?>" <?php echo isset($saved_permissions[$parent_perm_id]) ? 'checked' : ''; ?> data-parent-for="<?php echo $selected_role_id . '-' . $parent_key; ?>" <?php echo $disabled_attr; ?>>
                                        <label class="form-check-label" for="perm-<?php echo $selected_role_id . '-' . $parent_perm_id; ?>"><?php echo $section_title; ?></label>
                                    </div>
                                    <?php
                                    $permissions = $data['permissions']; $main_permissions = is_array($permissions) ? array_filter($permissions, 'is_string') : [$permissions];
                                    if (!empty($main_permissions)) { echo '<div class="permission-sub-group">'; foreach ($main_permissions as $perm_key) { if (!isset($all_permissions[$perm_key])) continue; $perm_id = $all_permissions[$perm_key]['id']; $perm_name = $all_permissions[$perm_key]['name']; echo '<div class="form-check"><input class="form-check-input" type="checkbox" data-child-of="'.$selected_role_id . '-' .$parent_key.'" name="permissions['.$perm_id.']" id="perm-'.$selected_role_id . '-'.$perm_id.'" '.(isset($saved_permissions[$perm_id]) ? "checked":"").' '.$disabled_attr.'><label class="form-check-label" for="perm-'.$selected_role_id . '-'.$perm_id.'">'.htmlspecialchars($perm_name).'</label></div>'; } echo '</div>'; }
                                    if (isset($permissions['sub_group'])) { foreach ($permissions['sub_group'] as $sub_group_title => $sub_permissions) { echo '<div class="permission-sub-group"><h6 class="permission-sub-group-title">'.$sub_group_title.'</h6>'; foreach ($sub_permissions as $perm_key) { if (!isset($all_permissions[$perm_key])) continue; $perm_id = $all_permissions[$perm_key]['id']; $perm_name = $all_permissions[$perm_key]['name']; echo '<div class="form-check"><input class="form-check-input" type="checkbox" data-child-of="'.$selected_role_id . '-' .$parent_key.'" name="permissions['.$perm_id.']" id="perm-'.$selected_role_id . '-'.$perm_id.'" '.(isset($saved_permissions[$perm_id]) ? "checked":"").' '.$disabled_attr.'><label class="form-check-label" for="perm-'.$selected_role_id . '-'.$perm_id.'">'.htmlspecialchars($perm_name).'</label></div>'; } echo '</div>'; } }
                                    ?>
                                </div>
                            <?php endforeach; ?>
                            </div></div></div>
                        </form>
                    </div>
                </div></div>
            </div>
            <?php endif; ?>

            <?php if (can('view_group_mapping_tab')): ?>
            <div class="tab-pane fade <?php if($active_tab == 'group_mapping') echo 'show active'; ?>" id="group-mapping-pane">
                <div class="card shadow-sm">
                    <div class="card-header"><h5 class="mb-0">LDAP Group to Application Role Mapping</h5></div>
                    <div class="card-body">
                        <p class="text-muted">This feature allows you to automatically assign roles to users when they log in, based on their LDAP group membership. First, sync LDAP groups, then map them to the roles below.</p>
                        <?php if (can('edit_group_mapping')): ?>
                        <div class="card bg-light mb-4">
                            <div class="card-body">
                                <h6 class="form-section-header">Sync LDAP Groups</h6>
                                <form action="manage_users.php?tab=group_mapping" method="POST" class="row g-3 align-items-end">
                                    <input type="hidden" name="action" value="search_ldap_groups">
                                    <div class="col-md-6"><label for="group_search_keyword" class="form-label">Group Name Keyword</label><input type="text" class="form-control" name="group_search_keyword" id="group_search_keyword" placeholder="e.g., AppAdmins" required minlength="2"></div>
                                    <div class="col-md-4"><button type="submit" class="btn btn-deep-green"><i data-feather="refresh-cw" class="me-1"></i> Sync Groups</button></div>
                                </form>
                            </div>
                        </div>
                        <?php endif; ?>
                        <h6 class="form-section-header">Assign Groups to Roles</h6>
                        <form id="groupMappingForm" action="manage_users.php?tab=group_mapping" method="POST">
                            <input type="hidden" name="action" value="save_group_mappings">
                            <fieldset <?php if (!can('edit_group_mapping')) echo 'disabled'; ?>>
                                <div class="table-responsive"><table class="table table-bordered"><thead class="table-deep-green"><tr><th style="width: 40%;">Application Role</th><th>Mapped LDAP Group</th></tr></thead>
                                <tbody>
                                <?php foreach($roles as $role): if ($role['id'] == 1) continue; ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($role['role_name']); ?></strong><div class="text-muted small"><?php echo htmlspecialchars($role['description']); ?></div></td>
                                        <td><select class="form-select" name="mappings[<?php echo $role['id']; ?>]"><option value="">-- Not Mapped --</option><?php foreach($ldap_groups as $group): ?><option value="<?php echo $group['id']; ?>" <?php if (isset($group_mappings[$role['id']]) && $group_mappings[$role['id']] == $group['id']) echo 'selected'; ?>><?php echo htmlspecialchars($group['group_name']); ?></option><?php endforeach; ?></select></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody></table></div>
                                <?php if (can('edit_group_mapping')): ?><div class="mt-3 text-end"><button type="submit" class="btn btn-deep-green"><i data-feather="save" class="me-1"></i> Save Mappings</button></div><?php endif; ?>
                            </fieldset>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (can('view_synced_groups_tab')): ?>
            <div class="tab-pane fade <?php if($active_tab == 'synced_groups') echo 'show active'; ?>" id="synced-groups-pane">
                <p class="text-muted">This table shows all LDAP groups that have been synchronized to the local database via the "Group Mapping" tab. You can remove a group from this local list if it is no longer needed. A group cannot be deleted if it is currently mapped to a role.</p>
                <div class="table-responsive">
                    <table class="table">
                        <thead class="table-deep-green">
                            <tr>
                                <th class="text-start">Group Name</th>
                                <th class="text-end" style="width: 120px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($synced_ldap_groups)): ?>
                                <tr>
                                    <td colspan="2" class="text-center text-muted py-4">No groups have been synced yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($synced_ldap_groups as $group): ?>
                                    <tr>
                                        <td class="text-start"><?php echo htmlspecialchars($group['group_name']); ?></td>
                                        <td class="text-end">
                                            <div class="action-buttons-container justify-content-end">
                                                <?php if (can('delete_synced_group')): ?>
                                                    <a href="manage_users.php?delete_ldap_group=<?php echo $group['id']; ?>" class="btn" onclick="return confirm('Are you sure you want to delete this synced group? This will not affect the group in LDAP.');" title="Delete Synced Group">
                                                        <i data-feather="trash-2"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            <?php if (can('view_ldap_tab')): ?>
            <div class="tab-pane fade <?php if($active_tab == 'ldap') echo 'show active'; ?>" id="ldap-pane">
                <div class="card shadow-sm">
                    <div class="card-header"><h5 class="mb-0">LDAP / Active Directory Settings</h5></div>
                    <div class="card-body">
                        <form action="manage_users.php?tab=ldap" method="POST">
                            <input type="hidden" name="action" value="save_ldap_config">
                            <fieldset <?php if (!can('edit_ldap_config')) echo 'disabled'; ?>>
                                <p class="form-section-header">Connection Settings</p>
                                <div class="form-check form-switch mb-4"><input class="form-check-input" type="checkbox" role="switch" id="enable_ldap_auth" name="enable_ldap_auth" <?php echo ($ldap_config['ENABLE_LDAP_AUTH'] ?? false) ? 'checked' : '';?>><label class="form-check-label" for="enable_ldap_auth">Enable LDAP Authentication</label></div>
                                <div class="row">
                                    <div class="col-md-8 mb-3"><label for="ldap_host" class="form-label">LDAP Host</label><input type="text" class="form-control" id="ldap_host" name="ldap_host" value="<?php echo htmlspecialchars($ldap_config['LDAP_HOST'] ?? '');?>" placeholder="e.g., ldap.example.com or 192.168.1.10"></div>
                                    <div class="col-md-4 mb-3"><label for="ldap_port" class="form-label">LDAP Port</label><input type="number" class="form-control" id="ldap_port" name="ldap_port" value="<?php echo htmlspecialchars($ldap_config['LDAP_PORT'] ?? '');?>" placeholder="e.g., 389"></div>
                                </div>
                                <div class="mb-3"><label for="ldap_base_dn" class="form-label">Base DN</label><input type="text" class="form-control" id="ldap_base_dn" name="ldap_base_dn" value="<?php echo htmlspecialchars($ldap_config['LDAP_BASE_DN'] ?? '');?>" placeholder="e.g., OU=Users,DC=example,DC=com"></div>
                                <div class="mb-3"><label for="ldap_domain" class="form-label">LDAP Domain</label><input type="text" class="form-control" id="ldap_domain" name="ldap_domain" value="<?php echo htmlspecialchars($ldap_config['LDAP_DOMAIN'] ?? '');?>" placeholder="e.g., example.com"></div>
                                
                                <hr class="my-4">
                                <p class="form-section-header">User & Group Search Filters</p>
                                <div class="mb-3">
                                    <label for="ldap_login_attribute" class="form-label">User Login Attribute (e.g., sAMAccountName, uid)</label>
                                    <input type="text" class="form-control" id="ldap_login_attribute" name="ldap_login_attribute" value="<?php echo htmlspecialchars($ldap_config['LDAP_LOGIN_ATTRIBUTE'] ?? 'sAMAccountName');?>" placeholder="e.g., sAMAccountName" required>
                                    <div class="form-text">This is the attribute used in LDAP to match the username entered by the user.</div>
                                </div>
                                <div class="mb-3">
                                    <label for="ldap_user_search_filter" class="form-label">Additional User Search Filter (LDAP Query)</label>
                                    <input type="text" class="form-control" id="ldap_user_search_filter" name="ldap_user_search_filter" value="<?php echo htmlspecialchars($ldap_config['LDAP_USER_SEARCH_FILTER'] ?? '(&(objectClass=user)(objectCategory=person)(!(userAccountControl:1.2.840.113556.1.4.803:=2)))');?>" placeholder="e.g., (&(objectClass=user)(objectCategory=person)(!(userAccountControl:1.2.840.113556.1.4.803:=2)))">
                                    <div class="form-text">This filter is combined with the Login Attribute. Leave the default for standard Active Directory users (excludes disabled accounts).</div>
                                </div>

                                <hr class="my-4">
                                <p class="form-section-header">Bind Credentials (Service Account)</p>
                                <div class="mb-3"><label for="ldap_bind_dn" class="form-label">Bind DN</label><input type="text" class="form-control" id="ldap_bind_dn" name="ldap_bind_dn" value="<?php echo htmlspecialchars($ldap_config['LDAP_BIND_DN'] ?? '');?>" placeholder="e.g., CN=ServiceUser,OU=Users,DC=example,DC=com"></div>
                                <div class="mb-3"><label for="ldap_bind_password" class="form-label">Bind Password</label><input type="password" class="form-control" id="ldap_bind_password" name="ldap_bind_password" value="" placeholder="Enter new password to update"></div>
                                <div class="mt-4 text-end"><button type="submit" class="btn btn-deep-green"><i data-feather="save" class="me-1"></i> Save Configuration</button></div>
                            </fieldset>
                        </form>
                    </div>
                </div>
                <div class="card shadow-sm mt-4">
                    <div class="card-header"><h5 class="mb-0">Test Configuration</h5></div>
                    <div class="card-body">
                        <form id="ldapTestForm">
                            <div id="testResult" class="mb-3"></div>
                            <div class="row">
                                <div class="col-md-5 mb-3">
                                    <label for="test_username" class="form-label">Username</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="test_username" name="test_username" required>
                                        <button class="btn btn-outline-secondary" type="button" id="clearLdapUsernameBtn" title="Clear Saved Username"><i data-feather="x"></i></button>
                                    </div>
                                </div>
                                <div class="col-md-5 mb-3"><label for="test_password" class="form-label">Password</label><input type="password" class="form-control" id="test_password" name="test_password" required></div>
                                <div class="col-md-2 d-flex align-items-end mb-3"><button type="submit" id="testLdapBtn" class="btn btn-secondary w-100">Test</button></div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal fade" id="unsavedChangesModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Unsaved Changes</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">You have unsaved changes. Are you sure you want to leave?</div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Stay</button><button type="button" class="btn btn-danger" id="discardChangesBtn">Discard & Leave</button></div>
</div></div></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof feather !== 'undefined') { feather.replace(); }

    const savedLdapUsername = localStorage.getItem('ldapTestUsername');
    if (savedLdapUsername) {
        const usernameInput = document.getElementById('test_username');
        if (usernameInput) {
            usernameInput.value = savedLdapUsername;
        }
    }

    function showToast(title, message, isError = false) {
        const toastEl = document.getElementById('formToast'); const toastHeader = toastEl.querySelector('.toast-header'); const toastBodyEl = toastEl.querySelector('.toast-body');
        toastEl.querySelector('#toastTitle').textContent = title; toastBodyEl.textContent = message;
        toastHeader.className = 'toast-header';
        if (isError) { toastHeader.classList.add('bg-danger', 'text-white'); toastBodyEl.classList.add('bg-danger-subtle'); }
        else { toastHeader.classList.add('bg-success', 'text-white'); toastBodyEl.classList.add('bg-success-subtle'); }
        bootstrap.Toast.getOrCreateInstance(toastEl).show();
    }
    const phpSuccess = <?php echo json_encode($_SESSION['form_success'] ?? null); unset($_SESSION['form_success']); ?>;
    const phpError = <?php echo json_encode($_SESSION['form_error'] ?? null); unset($_SESSION['form_error']); ?>;
    if (phpSuccess) { showToast('Success', phpSuccess); }
    if (phpError) { showToast('Error', phpError, true); }

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
            testBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Testing...';
            testResultEl.innerHTML = '';

            fetch('manage_users.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    const alertClass = data.success ? 'alert-success' : 'alert-danger';
                    testResultEl.innerHTML = `<div class="alert ${alertClass}">${data.message}</div>`;
                })
                .catch(error => {
                    testResultEl.innerHTML = `<div class="alert alert-danger">An unexpected error occurred. Check the browser console for details.</div>`;
                })
                .finally(() => {
                    testBtn.disabled = false;
                    testBtn.innerHTML = 'Test';
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
        if (link && hasUnsavedChanges) {
            const currentUrl = window.location.href.split('?')[0];
            const targetUrl = link.href.split('?')[0];
            if (currentUrl !== targetUrl || link.href !== window.location.href) {
                event.preventDefault();
                intendedUrl = link.href;
                unsavedModal.show();
            }
        }
    });
    
    unsavedModalEl.addEventListener('show.bs.modal', function(event) {
        if (event.relatedTarget && event.relatedTarget.tagName === 'A') {
            event.relatedTarget.onclick = function() { return false; };
        }
    });

    if (discardBtn) {
        discardBtn.addEventListener('click', function() {
            hasUnsavedChanges = false;
            if (intendedUrl) { window.location.href = intendedUrl; }
        });
    }
    
    window.addEventListener('beforeunload', (e) => {
        if (hasUnsavedChanges) {
            e.preventDefault();
            e.returnValue = '';
            return '';
        }
    });

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

<?php include_once '../includes/footer.php'; ?>