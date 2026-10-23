<?php
// FILE: includes/auth.php - FINAL VERSION WITH URL REDIRECT FIX, IP LOGGING & PUBLIC BYPASS
date_default_timezone_set('Asia/Kolkata');

// =========================================================================
// === START: ROBUST & CENTRALIZED SESSION MANAGEMENT BLOCK ===
// =========================================================================

// Only configure and start a session if one is not already active.
if (session_status() === PHP_SESSION_NONE) {
    // Set a unique cookie path if it was defined during installation.
    if (defined('APP_BASE_PATH')) {
        session_set_cookie_params([
            'lifetime' => 0, // 0 = until browser is closed
            'path' => APP_BASE_PATH, // Set the cookie path to our application's root
            'secure' => isset($_SERVER['HTTPS']), // Only send cookie over HTTPS
            'httponly' => true, // Prevent client-side script access to cookie
            'samesite' => 'Lax' // CSRF protection
        ]);
    }
    
    // Now, it is safe to start the session.
    session_start();
}
// =========================================================================
// === END: SESSION MANAGEMENT BLOCK ===
// =========================================================================

// Check if the application has been installed and load the configuration.
if (file_exists(__DIR__ . '/db_connect.php')) {
    include_once __DIR__ . '/db_connect.php';
}

include_once __DIR__ . '/cron_trigger.php';

// *** START: FINAL, SELF-CONTAINED SESSION TIMEOUT FIX ***
$inactive_timeout = 1500; // 25 minutes (1500 seconds)

// Only process session activity and timeouts if a user is logged in.
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {

    // Check if the session has timed out due to inactivity.
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $inactive_timeout) {
        
        // 1. Capture the current page URL before destroying the session
        // We use urlencode to ensure the path handles special characters correctly.
        $redirect_after_login = urlencode($_SERVER['REQUEST_URI']);

        // --- Session has expired. Destroy it completely. ---
        $_SESSION = array();
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();

        // --- NEW PUBLIC BYPASS LOGIC ---
        if (defined('ALLOW_PUBLIC_ACCESS') && ALLOW_PUBLIC_ACCESS === true) {
            // If they are on a public page, restart as a guest and refresh quietly
            session_start();
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        }
        // -------------------------------

        // --- Now, force the browser to redirect correctly using your project folder path. ---
        // ADDED: &next= parameter to carry the original URL
        $login_url = (defined('APP_BASE_PATH') ? APP_BASE_PATH : '/') . 'index.php?message=session_expired&next=' . $redirect_after_login;

        $is_ajax_request = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
        $is_modal_request = (isset($_GET['view']) && $_GET['view'] === 'modal') || (isset($_POST['view']) && $_POST['view'] === 'modal');

        if ($is_ajax_request || $is_modal_request) {
            // For popups and background requests, send a silent JS command to redirect the main page.
            http_response_code(401); // Unauthorized
            echo '<script>window.top.location.href = "' . $login_url . '";</script>';
            exit();
        } else {
            // For regular pages, do a standard server-side redirect.
            header("Location: " . $login_url);
            exit();
        }

    } else {
        // Session is active. Update the last activity timestamp.
        $_SESSION['last_activity'] = time();
    }
}
// *** END: FINAL, SELF-CONTAINED SESSION TIMEOUT FIX ***


/**
 * Logs a system-wide event to the audit trail (NOW WITH IP ADDRESS CAPTURE).
 */
function log_system_event($conn, $action_type, $description) {
    $performed_by = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')) ?: ($_SESSION['username'] ?? 'System');
    
    // Safely capture the IP Address
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip_address = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Proxies can pass multiple IPs, grab the first one
        $ip_list = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip_address = trim($ip_list[0]);
    }
    
    // Updated SQL query to include ip_address
    $sql = "INSERT INTO system_logs (performed_by, action_type, description, ip_address) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ssss", $performed_by, $action_type, $description, $ip_address);
        $stmt->execute();
        $stmt->close();
    }
}


/**
 * Added the logic to logo redirection according to user.
 * ROUTER FIX: Removed .php extensions for clean URLs
 */
function getUserDefaultPage(): string
{
    // Safety fallback
    $base = defined('APP_BASE_PATH') ? APP_BASE_PATH : '/';

    // Super Admin (role_id = 1)
    if (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1) {
        return $base . 'dashboard';
    }

    // Dashboard access
    if (can('view_dashboard')) {
        return $base . 'dashboard';
    }

    // Portals access
    if (can('view_portals_menu')) {
        return $base . 'templates';
    }

    // Fallback – no permissions
    return $base . 'access_denied.php';
}

/**
 * Loads a user's permissions from the database into the session.
 */
function load_user_permissions($role_id) {
    global $conn;
    $_SESSION['permissions'] = [];

    if ($role_id) {
        $stmt = $conn->prepare("SELECT p.action_key FROM role_permissions rp JOIN permissions p ON rp.permission_id = p.id WHERE rp.role_id = ?");
        $stmt->bind_param("i", $role_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $_SESSION['permissions'][$row['action_key']] = true;
        }
        $stmt->close();
    }
}


// --- LDAP FUNCTIONS ---

/**
 * Helper function to get LDAP groups and assign roles.
 */
function assign_role_from_ldap_groups($conn, $ldap_groups) {
    if (empty($ldap_groups)) {
        return null;
    }

    $group_dns = array_map('strtolower', $ldap_groups);
    $placeholders = implode(',', array_fill(0, count($group_dns), '?'));

    $stmt = $conn->prepare("SELECT rlgm.role_id FROM role_ldap_group_mapping rlgm JOIN ldap_groups lg ON rlgm.ldap_group_id = lg.id WHERE LOWER(lg.group_dn) IN ($placeholders) LIMIT 1");

    if ($stmt === false) {
        error_log("Failed to prepare statement for group mapping: " . $conn->error);
        return null;
    }
    
    $types = str_repeat('s', count($group_dns));
    $stmt->bind_param($types, ...$group_dns);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc()['role_id'];
    }
    
    return null;
}

/**
 * Authenticates a user against LDAP and retrieves their details.
 */
function authenticate_with_ldap($conn, $username, $password) {
    // Return early if LDAP is not enabled
    if (!defined('ENABLE_LDAP_AUTH') || !ENABLE_LDAP_AUTH) {
        return ['success' => false, 'message' => 'LDAP authentication is not enabled.'];
    }

    // Load LDAP configuration
    if (!file_exists(__DIR__ . '/ldap_config.php')) {
        return ['success' => false, 'message' => 'LDAP configuration file not found.'];
    }
    include_once __DIR__ . '/ldap_config.php';

    $ldap_conn = @ldap_connect(LDAP_HOST, (int)LDAP_PORT);
    if (!$ldap_conn) {
        return ['success' => false, 'message' => 'LDAP Connection Failed. Please check the host and port settings.'];
    }

    ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ldap_conn, LDAP_OPT_REFERRALS, 0);

    // Attempt to bind with the service account to search for the user
    $bind_dn = defined('LDAP_BIND_DN') ? LDAP_BIND_DN : null;
    $bind_pass = defined('LDAP_BIND_PASSWORD') ? LDAP_BIND_PASSWORD : null;
    $service_bind_result = false;
    if ($bind_dn && $bind_pass) {
        $service_bind_result = @ldap_bind($ldap_conn, $bind_dn, $bind_pass);
    } else {
        $service_bind_result = @ldap_bind($ldap_conn); // Attempt anonymous bind
    }

    if (!$service_bind_result) {
        ldap_close($ldap_conn);
        return ['success' => false, 'message' => 'Service account bind failed. Check LDAP Bind DN and Password in settings.'];
    }

    // Search for the user's DN
    $user_filter = "(&(objectClass=user)(sAMAccountName=" . ldap_escape($username, '', LDAP_ESCAPE_FILTER) . "))";
    
    $attributes_to_fetch = ['dn', 'givenName', 'sn', 'mail', 'memberOf', 'department'];
    $search_result = @ldap_search($ldap_conn, LDAP_BASE_DN, $user_filter, $attributes_to_fetch);

    if (!$search_result) {
        ldap_close($ldap_conn);
        return ['success' => false, 'message' => 'User search failed. Check LDAP Base DN.'];
    }

    $entries = ldap_get_entries($ldap_conn, $search_result);
    if ($entries['count'] == 0) {
        ldap_close($ldap_conn);
        return ['success' => false, 'message' => 'Invalid username or password.'];
    }

    $user_dn = $entries[0]['dn'];

    // Now, attempt to authenticate (bind) as the user with the provided password
    $user_bind_result = @ldap_bind($ldap_conn, $user_dn, $password);
    ldap_close($ldap_conn);

    if (!$user_bind_result) {
        return ['success' => false, 'message' => 'Invalid username or password.'];
    }

    // User authenticated. Collect their data.
    $user_data = [
        'username' => strtolower($username),
        'firstname' => $entries[0]['givenname'][0] ?? 'N/A',
        'lastname' => $entries[0]['sn'][0] ?? 'N/A',
        'department' => $entries[0]['department'][0] ?? '',
        'groups' => []
    ];

    if (isset($entries[0]['memberof'])) {
        foreach ($entries[0]['memberof'] as $group_dn) {
            if (is_string($group_dn)) {
                $user_data['groups'][] = $group_dn;
            }
        }
    }
    
    return ['success' => true, 'message' => 'Login successful.', 'user_data' => $user_data];
}
// --- END OF LDAP FUNCTIONS ---

/**
 * Checks if a user is currently logged in.
 */
function isLoggedIn() {
    return isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true;
}

/**
 * Checks if the current user has a specific permission.
 */
function can(string $action): bool {
    // Super Admin (role_id: 1) bypasses all checks and has full access.
    if (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1) {
        return true;
    }
    return isset($_SESSION['permissions']) && isset($_SESSION['permissions'][$action]);
}

/**
 * Centralized function to display the "Access Denied" page and terminate the script.
 */
function renderAccessDeniedPage() {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();

    http_response_code(403);
    
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    $login_page_url = (defined('APP_BASE_PATH') ? APP_BASE_PATH : '/') . 'index.php';
    
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Access Denied</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { 
            background-color: #f8f9fa; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            height: 100vh;
            margin: 0;
        }
        .alert { 
            max-width: 500px;
            border: 1px solid #842029;
        }
    </style>
</head>
<body>
    <div class="alert alert-danger text-center" role="alert">
        <h4 class="alert-heading">Access Denied</h4>
        <p>You don't have permission to view this page. Please contact IT Support.</p>
        <hr>
        <a href="$login_page_url" class="btn btn-primary">Go to Login Page</a>
    </div>
</body>
</html>
HTML;
    exit();
}

/**
 * Redirects the user if they do not have the required permission.
 */
function checkPermission(string $action) {
    if (!defined('APP_BASE_PATH')) {
        // Fallback in case APP_BASE_PATH is not defined elsewhere
        $path = rtrim(str_replace(basename($_SERVER['PHP_SELF']), '', $_SERVER['PHP_SELF']), '/');
        define('APP_BASE_PATH', $path . '/');
    }

    if (!isLoggedIn()) {
        header('Location: ' . APP_BASE_PATH . 'index.php');
        exit();
    }
    
    if (!can($action)) {
        renderAccessDeniedPage();
    }
}