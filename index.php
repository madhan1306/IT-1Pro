<?php
ob_start();

// =======================================================================
// === START: INTEGRATED MAINTENANCE CHECK (READING IP FROM FILE) ===
// =======================================================================
$maintenance_file = __DIR__ . '/maintenance.enable';
$is_maintenance = false;

if (file_exists($maintenance_file)) {
    $file_content = file_get_contents($maintenance_file);
    $allowed_ips = array_filter(array_map('trim', explode(',', $file_content)));
    $user_ip = $_SERVER['REMOTE_ADDR'];
    if (!in_array($user_ip, $allowed_ips)) {
        $is_maintenance = true;
    }
}

// Only process the application logic if we are NOT in maintenance mode
if (!$is_maintenance) {
    
    // =======================================================================
    // === INSTALL & UPGRADE DETECTION (VERSION AWARE) ===
    // =======================================================================
    $lock_file = 'includes/installed.lock';
    $is_installed = file_exists($lock_file);
    $install_dir_exists = is_dir(__DIR__ . '/install') && file_exists(__DIR__ . '/install/install.php');

    if ($is_installed) {
        $version = trim(file_get_contents($lock_file));
        
        if ($version === 'v2.0') {
            // System is fully upgraded. If the install folder is still here, throw a hard block!
            if ($install_dir_exists) {
                die('
                <html><head><title>Action Required</title><style>body{font-family:"Segoe UI",sans-serif;background:#f4f7f6;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;} .alert-box{background:#fff;padding:40px;border-radius:10px;box-shadow:0 10px 25px rgba(0,0,0,0.1);text-align:center;max-width:550px; border-top: 5px solid #dc3545;} h2{color:#dc3545; margin-top:0;} code{background:#ffebee;padding:4px 8px;border-radius:4px; color:#c62828;} .btn{padding:12px 25px;background:#0C5D58;color:#fff;border:none;border-radius:6px;cursor:pointer;margin-top:20px;font-size:16px; font-weight:bold;}</style></head><body>
                <div class="alert-box">
                    <h2>?? Action Required</h2>
                    <p>The system upgrade was successful, but the installer directory is still active on your server.</p>
                    <p style="margin-bottom:25px;">For critical security reasons, you <b>must</b> manually delete or rename the <code>/install/</code> directory before you can access the application.</p>
                    <button class="btn" onclick="location.reload()">I have deleted it, reload page</button>
                </div>
                </body></html>
                ');
            }
        } else {
            // Old version detected, redirect to upgrade flow
            if ($install_dir_exists) {
                header('Location: install/install.php');
                exit;
            }
        }
    } else {
        // Fresh install scenario
        if ($install_dir_exists) {
            header('Location: install/install.php');
            exit;
        } else {
            die("System is not installed and the 'install' directory is missing.");
        }
    }

    include_once 'includes/db_connect.php';
    include_once 'includes/auth.php';

    // =======================================================================
    // === NEW: PUBLIC PORTAL BYPASS (Allow access without login) ===
    // =======================================================================
    $route = $_GET['route'] ?? '';
    if ($route === 'portals') {
        $target_dir = __DIR__ . '/pages';
        chdir($target_dir);
        require_once 'portals.php';
        exit; 
    }
    // =======================================================================

    // =======================================================================
    // === START: FRONT CONTROLLER / ROUTER LOGIC ===
    // =======================================================================
    if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
        
        if (!empty($route)) {
            $routes = [
                'dashboard' => 'dashboard.php',
                'add_edit_exit_checklist' => 'pages/add_edit_exit_checklist.php',
                'add_edit_gatepass' => 'pages/add_edit_gatepass.php',
                'add_edit_shipment' => 'pages/add_edit_shipment.php',
                'add_edit_template' => 'pages/add_edit_template.php',
                'ajax_search' => 'pages/ajax_search.php',
                'create_new_checklist' => 'pages/create_new_checklist.php',
                'edit_employee' => 'pages/edit_employee.php',
                'exit_checklist' => 'pages/exit_checklist.php',
                'gatepass' => 'pages/gatepass.php',
                'generate_acceptance_pdf' => 'pages/generate_acceptance_pdf.php',
                'generate_checklist_pdf' => 'pages/generate_checklist_pdf.php',
                'generate_exit_checklist_pdf' => 'pages/generate_exit_checklist_pdf.php',
                'generate_gatepass_pdf' => 'pages/generate_gatepass_pdf.php',
                'generate_report' => 'pages/generate_report.php',
                'generate_template_pdf' => 'pages/generate_template_pdf.php',
                'get_employee_details' => 'pages/get_employee_details.php',
                'manage_employees' => 'pages/manage_employees.php',
                'manage_forms' => 'pages/manage_forms.php',
                'manage_portals' => 'pages/manage_portals.php',
                'portal_card.partial' => 'pages/portal_card.partial.php',
                'portals' => 'pages/portals.php',
                'reports' => 'pages/reports.php',
                'settings' => 'pages/settings.php',
                'shipment' => 'pages/shipment.php',
                'system_audit_trail' => 'pages/system_audit_trail.php',
                'system_readiness_checklist' => 'pages/system_readiness_checklist.php',
                'system_readiness' => 'pages/system_readiness.php',
                'templates' => 'pages/templates.php',
                'user_acceptance' => 'pages/user_acceptance.php',
                'view_gatepass' => 'pages/view_gatepass.php',
                'manage_users' => 'pages/manage_users.php',
                'ntp' => 'operations/ntp.php',
                'shift_roster' => 'operations/shift_roster.php',
                'glpi_assets' => 'pages/view_glpi_assets.php',
                'index_shr' => 'shr/shr.php',
                'my_handovers_shr' => 'shr/my_handovers.php',
                'view_shr' => 'shr/view.php',
                'create_shr' => 'shr/create.php',
                'dashboard_shr' => 'shr/dashboard.php',
                'hp_tickets' => 'shr/hp_tickets.php',
                'kb_index' => 'kb/kb.php',
                'kb_create' => 'kb/kb_create.php',
                'kb_edit' => 'kb/kb_edit.php',
                'kb_categories' => 'kb/kb_categories.php'
            ];

            if (array_key_exists($route, $routes) && file_exists(__DIR__ . '/' . $routes[$route])) {
                $target_dir = dirname(__DIR__ . '/' . $routes[$route]);
                chdir($target_dir);
                require_once basename($routes[$route]);
                exit; 
            }
        }

        if (can('view_dashboard')) { $redirect_url = APP_BASE_PATH . "index.php?route=dashboard"; } 
        elseif (can('nav_my_profile_main')) { $redirect_url = APP_BASE_PATH . "index.php?route=edit_employee"; } 
        elseif (can('view_portals_menu')) { $redirect_url = APP_BASE_PATH . "index.php?route=portals"; } 
        else { $redirect_url = APP_BASE_PATH . "index.php?route=dashboard"; }
        
        header("location: " . $redirect_url);
        exit;
    }

    @include_once 'includes/ldap_config.php';

    $username = $password = "";
    $username_err = $password_err = $login_err = "";

    // Process form submission
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        if (empty(trim($_POST["username"]))) { $username_err = "Please enter username."; } else { $username = trim($_POST["username"]); }
        if (empty(trim($_POST["password"]))) { $password_err = "Please enter your password."; } else { $password = trim($_POST["password"]); }

        if (empty($username_err) && empty($password_err)) {
            $login_successful = false;
            $user_data_to_store = null;
            $role_id = null;
            $user_id = null;
            $is_new_user = false;

            $sql = "SELECT id, username, password, role_id, firstname, lastname, department FROM users WHERE LOWER(username) = LOWER(?)";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("s", $username);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($result->num_rows == 1) {
                        $user = $result->fetch_assoc();
                        if (password_verify($password, $user['password'])) {
                            $user_data_to_store = $user;
                            $user_data_to_store['is_ldap'] = false;
                            $role_id = $user['role_id'];
                            $user_id = $user['id'];
                            $login_successful = true;
                        }
                    }
                }
                $stmt->close();
            }

            if (!$login_successful && defined('ENABLE_LDAP_AUTH') && ENABLE_LDAP_AUTH) {
                $ldap_auth_result = authenticate_with_ldap($conn, $username, $password);
                if ($ldap_auth_result['success']) {
                    $user_data_to_store = $ldap_auth_result['user_data'];
                    $user_data_to_store['is_ldap'] = true;
                    if (!empty($user_data_to_store['groups'])) {
                        $role_id = assign_role_from_ldap_groups($conn, $user_data_to_store['groups']);
                    }
                    if (is_null($role_id)) {
                        $_SESSION['form_error'] = 'You do not have access. Please contact IT Support.';
                        header("Location: " .APP_BASE_PATH. "index.php");
                        exit();
                    }
                    $login_successful = true;
                } else {
                    $login_err = $ldap_auth_result['message'];
                }
            }
            
            if ($login_successful) {
                if ($user_data_to_store['is_ldap']) {
                    $local_user_data = null;
                    $stmt_find = $conn->prepare("SELECT id, firstname, lastname, department, role_id FROM users WHERE LOWER(username) = LOWER(?)");
                    $stmt_find->bind_param("s", $user_data_to_store['username']);
                    if($stmt_find->execute()){
                        $result = $stmt_find->get_result();
                        if($result->num_rows == 1){
                            $local_user_data = $result->fetch_assoc();
                            $user_id = $local_user_data['id'];
                        }
                    }
                    $stmt_find->close();

                    if (!$local_user_data) {
                        $is_new_user = true;
                        $hashed_password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                        $stmt_insert = $conn->prepare("INSERT INTO users (username, password, firstname, lastname, department, role_id) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt_insert->bind_param("sssssi", $user_data_to_store['username'], $hashed_password, $user_data_to_store['firstname'], $user_data_to_store['lastname'], $user_data_to_store['department'], $role_id);
                        $stmt_insert->execute();
                        $user_id = $conn->insert_id;
                        $stmt_insert->close();
                    } else {
                        $updates = []; $params = []; $types = '';
                        if ($local_user_data['firstname'] !== $user_data_to_store['firstname']) { $updates[] = "firstname = ?"; $params[] = $user_data_to_store['firstname']; $types .= 's'; }
                        if ($local_user_data['lastname'] !== $user_data_to_store['lastname']) { $updates[] = "lastname = ?"; $params[] = $user_data_to_store['lastname']; $types .= 's'; }
                        if ($local_user_data['department'] !== $user_data_to_store['department']) { $updates[] = "department = ?"; $params[] = $user_data_to_store['department']; $types .= 's'; }
                        if ($local_user_data['role_id'] != $role_id) { $updates[] = "role_id = ?"; $params[] = $role_id; $types .= 'i'; }

                        if (!empty($updates)) {
                            $sql_update = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
                            $params[] = $user_id;
                            $types .= 'i';
                            $stmt_update = $conn->prepare($sql_update);
                            $stmt_update->bind_param($types, ...$params);
                            $stmt_update->execute();
                            $stmt_update->close();
                        }
                    }
                }
                
                $_SESSION["loggedin"] = true;
                $_SESSION["user_id"] = $user_id;
                $_SESSION["username"] = $user_data_to_store['username'];
                $_SESSION["role_id"] = $role_id;
                $_SESSION["firstname"] = $user_data_to_store['firstname'];
                $_SESSION["lastname"] = $user_data_to_store['lastname'];
                $_SESSION["department"] = $user_data_to_store['department'] ?? '';
                $_SESSION['last_activity'] = time();

                $_SESSION['trigger_glpi_sync'] = true;

                load_user_permissions($role_id);
                log_system_event($conn, 'Authentication', 'User "' . $username . '" logged in successfully' . ($is_new_user ? ' (new user created).' : '.'));
                
                // ==========================================================
                // === FIXED REDIRECT LOGIC WITH APP_BASE_PATH ENFORCEMENT ===
                // ==========================================================
                $redirect_url = '';
                if (isset($_POST['next']) && !empty(trim($_POST['next']))) {
                    $next_url = trim($_POST['next']);
                    
                    // Parse the requested URL to safely rebuild it without absolute domains
                    $parsed = parse_url($next_url);
                    $path = $parsed['path'] ?? '';
                    $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
                    
                    // Ensure we have a valid base path ending in a slash
                    $base = defined('APP_BASE_PATH') ? APP_BASE_PATH : '/';
                    $base = rtrim($base, '/') . '/';
                    
                    // If the parsed path happens to already include the base path, strip it to prevent duplication
                    if (strpos($path, $base) === 0) {
                        $path = substr($path, strlen($base));
                    }
                    
                    // Rebuild the URL ensuring it strictly uses APP_BASE_PATH
                    $redirect_url = $base . ltrim($path, '/') . $query;
                }

                // Default Fallbacks
                if (empty($redirect_url)) {
                    if (can('view_dashboard')) { $redirect_url = APP_BASE_PATH . "index.php?route=dashboard"; } 
                    elseif (can('nav_my_profile_main')) { $redirect_url = APP_BASE_PATH . "index.php?route=edit_employee"; } 
                    elseif (can('view_templates_list')) { $redirect_url = APP_BASE_PATH . "index.php?route=templates"; } 
                    elseif (can('view_system_readiness')) { $redirect_url = APP_BASE_PATH . "index.php?route=system_readiness"; } 
                    elseif (can('view_gatepass')) { $redirect_url = APP_BASE_PATH . "index.php?route=gatepass"; } 
                    elseif (can('view_shipment')) { $redirect_url = APP_BASE_PATH . "index.php?route=shipment"; } 
                    elseif (can('view_employees')) { $redirect_url = APP_BASE_PATH . "index.php?route=manage_employees"; } 
                    elseif (can('view_admin_menu')) { $redirect_url = APP_BASE_PATH . "index.php?route=manage_users"; } 
                    elseif (can('view_portals_menu')) { $redirect_url = APP_BASE_PATH . "index.php?route=portals"; } 
                    else { $redirect_url = APP_BASE_PATH . "index.php?route=edit_employee"; }
                }

                if (empty($redirect_url)) {
                    renderAccessDeniedPage();
                } else {
                    header("location: " . $redirect_url);
                    exit();
                }

            } else if (empty($login_err)) {
                $login_err = "Invalid username or password.";
            }
        }
    }
} 

$pageTitle = $is_maintenance ? "Under Maintenance" : "Login";
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo htmlspecialchars($pageTitle); ?> | Sun IT One Portal</title>
    <link rel="icon" href="assets/images/favicon.svg" type="image/x-icon" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <style>
        body, html { height: 100%; margin: 0; font-family: 'Poppins', sans-serif; background-color: #f4f7f6; }
        .login-container { display: flex; min-height: 100vh; }
        .login-branding { flex: 1; background: linear-gradient(135deg, #0C5D58, #15857e); color: white; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 3rem; text-align: center; }
        .login-branding img { max-width: 100px; margin-bottom: 1.5rem; }
        .login-branding h1 { font-weight: 700; font-size: 2.5rem; }
        .login-branding p { font-size: 1.1rem; opacity: 0.9; }
        .login-form-wrapper { flex: 1; display: flex; justify-content: center; align-items: center; padding: 2rem; }
        .login-form { width: 100%; max-width: 400px; }
        .login-form .form-control { height: 50px; padding-left: 2.75rem; }
        .login-form .input-group-text { background-color: transparent; border-right: 0; }
        .login-form .form-control:focus { border-color: #0C5D58; box-shadow: 0 0 0 0.25rem rgba(12, 93, 88, 0.25); }
        .btn-login { background-color: #0C5D58; border-color: #0C5D58; color: #fff; padding: 0.75rem; font-weight: 600; transition: background-color: 0.2s; }
        .btn-login:hover { background-color: #084c47; border-color: #084c47; }
        .is-invalid { border-color: #dc3545 !important; }
        .invalid-feedback { display: block; }
        @media (max-width: 768px) { .login-branding { display: none; } }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-branding">
            <img src="assets/images/favicon.png" alt="Logo"/>
            <h1>IT One</h1>
            <p>** Streamlining your IT operations - from onboarding to exit **</p>
        </div>
        <div class="login-form-wrapper">
            <div class="login-form">
                
                <?php if ($is_maintenance): ?>
                    <div class="text-center">
                        <div class="mb-4">
                            <i data-feather="tool" style="width: 64px; height: 64px; color: #0C5D58;"></i>
                        </div>
                        <h3 class="fw-bold">Scheduled Maintenance</h3>
                        <p class="text-muted mb-4">We are currently performing scheduled updates to the portal. We will be back online shortly.</p>
                        <hr>
                        <p class="small text-muted mt-4">Authorized administrators can bypass this screen by connecting via the secure network.</p>
                    </div>
                <?php else: ?>
                    <div class="text-center mb-5">
                        <h3 class="fw-bold">Welcome Back</h3>
                        <p class="text-muted">Please sign in to continue.</p>
                    </div>

                    <?php if (isset($_GET['msg'])): ?>
                        <?php if ($_GET['msg'] == 'upgrade_complete'): ?>
                            <div class="alert alert-success text-center p-2 mb-3">System Update successful! The installer has been secured.</div>
                        <?php elseif ($_GET['msg'] == 'install_complete'): ?>
                            <div class="alert alert-success text-center p-2 mb-3">Installation successful! The installer has been secured.</div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if (!empty($_SESSION['flash_message'])): ?>
                        <div class='alert alert-info text-center p-2 mb-3'>
                            <?php 
                                if ($_SESSION['flash_message'] === 'session_expired') {
                                    echo "Session expired. Please login again.";
                                } else {
                                    echo htmlspecialchars($_SESSION['flash_message']);
                                }
                            ?>
                        </div>
                        <?php unset($_SESSION['flash_message']); ?>
                    <?php endif; ?>

                    <?php if (!empty($login_err)): ?>
                        <div class="alert alert-danger text-center p-2 mb-3"><?php echo htmlspecialchars($login_err); ?></div>
                    <?php endif; ?>
                    
                    <?php if (isset($_GET['message']) && $_GET['message'] == 'session_expired'): ?>
                        <div class="alert alert-warning text-center p-2 mb-3">Your session has expired due to inactivity. Please log in again.</div>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['form_error'])): ?>
                        <div class="alert alert-danger text-center p-2 mb-3"><?php echo htmlspecialchars($_SESSION['form_error']); ?></div>
                        <?php unset($_SESSION['form_error']); ?>
                    <?php endif; ?>

                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                        <?php
                            $next_val = $_GET['next'] ?? ($_POST['next'] ?? '');
                            
                            if (empty($next_val) && !empty($_SERVER['QUERY_STRING'])) {
                                $base = defined('APP_BASE_PATH') ? APP_BASE_PATH : '/';
                                $base = rtrim($base, '/') . '/';
                                $next_val = $base . 'index.php?' . $_SERVER['QUERY_STRING'];
                            }
                        ?>
                        <input type="hidden" name="next" value="<?php echo htmlspecialchars($next_val); ?>">

                        <div class="mb-3">
                            <div class="input-group">
                                <span class="input-group-text"><i data-feather="user"></i></span>
                                <input type="text" name="username" placeholder="Username" class="form-control <?php echo (!empty($username_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($username ?? ''); ?>">
                            </div>
                            <?php if(!empty($username_err)): ?>
                                <div class="invalid-feedback mt-1"><?php echo htmlspecialchars($username_err); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="mb-4">
                            <div class="input-group">
                                <span class="input-group-text"><i data-feather="lock"></i></span>
                                <input type="password" name="password" placeholder="Password" class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>">
                            </div>
                                <?php if(!empty($password_err)): ?>
                                 <div class="invalid-feedback mt-1"><?php echo htmlspecialchars($password_err); ?></div>
                                <?php endif; ?>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-login">Sign In</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <script>feather.replace();</script>
</body>
</html>