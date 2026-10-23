<?php
// --- Secure, Multi-Step Installation & Upgrade Script v2.0 ---

// Set paths relative to the project root.
$ROOT_DIR = dirname(__DIR__);
$lock_file = $ROOT_DIR . '/includes/installed.lock';
$db_connect_file = $ROOT_DIR . '/includes/db_connect.php';
$includes_dir = $ROOT_DIR . '/includes';
$uploads_dir = $ROOT_DIR . '/uploads'; 
$schema_file = __DIR__ . '/database/schema.sql';
$upgrade_file = __DIR__ . '/database/upgrade.sql'; 
$htaccess_file = $ROOT_DIR . '/.htaccess';
$ldap_config_file = $ROOT_DIR . '/includes/ldap_config.php';

session_start();
error_reporting(0); // Suppress errors for a cleaner UI.

// --- DETECT INSTALLATION STATE ---
$is_installed = file_exists($lock_file) && file_exists($db_connect_file);

// --- UPGRADE ROUTINE ---
if ($is_installed) {
    if (isset($_POST['do_upgrade'])) {
        require_once $db_connect_file;
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            // 1. Run Database Upgrade
            if (file_exists($upgrade_file)) {
                $sql_upgrade = file_get_contents($upgrade_file);
                if ($sql_upgrade !== false && !empty(trim($sql_upgrade))) {
                    if ($conn->multi_query($sql_upgrade)) {
                        do { if ($result = $conn->store_result()) { $result->free(); } } while ($conn->more_results() && $conn->next_result());
                    }
                }
            }

            // 2. Update .htaccess File
            $project_root_fs_norm = str_replace(DIRECTORY_SEPARATOR, '/', $ROOT_DIR);
            $document_root_fs_norm = str_replace(DIRECTORY_SEPARATOR, '/', rtrim($_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR));
            $base_path_url = (strpos($project_root_fs_norm, $document_root_fs_norm) === 0) ? substr($project_root_fs_norm, strlen($document_root_fs_norm)) : '';
            $rewrite_base = rtrim($base_path_url, '/') . '/';

            $htaccess_content = "# Prevent Directory Listing\nOptions -Indexes\n\nRewriteEngine On\nRewriteBase {$rewrite_base}\n\n# Deny access to sensitive folders\nRewriteRule ^includes/ - [F,L]\nRewriteRule ^install/ - [F,L]\n\n# Clean URL Routing\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule ^([^/]+)/?$ index.php?route=$1 [QSA,L]\n";
            
            if (!file_put_contents($htaccess_file, $htaccess_content)) {
                throw new Exception("Database upgraded successfully, but could not write the new .htaccess file. Please check file permissions.");
            }

            // 3. Mark Version in Lock File
            file_put_contents($lock_file, 'v2.0');

            // 4. Attempt Auto-Rename of install folder
            $install_dir = $ROOT_DIR . '/install';
            $bak_dir = $ROOT_DIR . '/install_bak_' . date('Ymd_His');
            
            if (@rename($install_dir, $bak_dir)) {
                // Rename successful, browser redirect to index
                header("Location: ../index.php?msg=upgrade_complete");
                exit;
            } else {
                // Rename failed, show manual delete warning
                header("Location: install.php?upgrade_success=1");
                exit;
            }

        } catch (Exception $e) {
            $upgrade_error = "Upgrade failed: " . $e->getMessage();
        }
        mysqli_report(MYSQLI_REPORT_OFF);
    }
    
    if (isset($_GET['upgrade_success'])) {
        $show_upgrade_success = true;
    } else {
        // If they visit the page and it's already v2.0, bounce them back.
        $current_version = trim(file_get_contents($lock_file));
        if ($current_version === 'v2.0') {
            header("Location: ../index.php");
            exit;
        }
        $show_upgrade_screen = true;
    }
} else {
    // --- FRESH INSTALL ROUTINE ---
    $step = 1;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $step = (int)($_POST['step'] ?? 1);
    } elseif (isset($_GET['step'])) {
        $step = (int)$_GET['step'];
    }

    $error = '';
    $checks = [];
    $total_steps = 3;

    $db_config = $_SESSION['db_config'] ?? ['db_host' => 'localhost', 'db_user' => '', 'db_pass' => '', 'db_name' => ''];
    $admin_config = $_SESSION['admin_config'] ?? ['username' => ''];

    if ($step == 2) {
        if (!is_dir($includes_dir)) { @mkdir($includes_dir, 0755, true); }
        if (!is_dir($uploads_dir)) { @mkdir($uploads_dir, 0755, true); } 
        $checks['php_version'] = version_compare(PHP_VERSION, '8.0.0', '>=');
        $checks['mysqli'] = extension_loaded('mysqli');
        $checks['ldap'] = extension_loaded('ldap');
        $checks['mysqlnd'] = function_exists('mysqli_get_client_stats');
        $checks['includes_writable'] = is_writable($includes_dir);
        $checks['uploads_writable'] = is_writable($uploads_dir);
        $checks['root_writable'] = is_writable($ROOT_DIR);
        $checks['schema_readable'] = is_readable($schema_file);
        $_SESSION['checks_passed'] = !in_array(false, $checks, true);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($step == 3 && isset($_POST['db_host'])) {
            $_SESSION['db_config'] = ['db_host' => $_POST['db_host'], 'db_user' => $_POST['db_user'], 'db_pass' => $_POST['db_pass'], 'db_name' => $_POST['db_name']];
            $db_config = $_SESSION['db_config'];
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            try {
                $conn = new mysqli($db_config['db_host'], $db_config['db_user'], $db_config['db_pass']);
                $db_name = $conn->real_escape_string($db_config['db_name']);
                if (!$conn->select_db($db_name)) {
                    if (!$conn->query("CREATE DATABASE `{$db_name}`")) throw new Exception("Error creating database: " . $conn->error);
                    $conn->select_db($db_name);
                }
                $sql_schema = file_get_contents($schema_file);
                if ($sql_schema === false) throw new Exception("Could not read schema file.");
                if (!$conn->multi_query($sql_schema)) throw new Exception("Error importing database schema: " . $conn->error);
                do { if ($result = $conn->store_result()) { $result->free(); } } while ($conn->more_results() && $conn->next_result());
                
                if (file_exists($upgrade_file)) {
                    $sql_upgrade = file_get_contents($upgrade_file);
                    if ($sql_upgrade !== false && !empty(trim($sql_upgrade))) {
                        if (!$conn->multi_query($sql_upgrade)) throw new Exception("Error importing upgrade schema: " . $conn->error);
                        do { if ($result = $conn->store_result()) { $result->free(); } } while ($conn->more_results() && $conn->next_result());
                    }
                }
                $conn->close();
                $_SESSION['db_setup_complete'] = true;
                $step = 4;
            } catch (Exception $e) { $error = "Database setup failed: " . $e->getMessage(); $step = 3; }
            mysqli_report(MYSQLI_REPORT_OFF);
        }
        elseif ($step == 4 && isset($_POST['username'])) {
            $_SESSION['admin_config'] = ['username' => trim($_POST['username']), 'password' => $_POST['password'], 'confirm_password' => $_POST['confirm_password']];
            $admin_config = $_SESSION['admin_config'];
            $db_config = $_SESSION['db_config'];
            if (empty($admin_config['username']) || empty($admin_config['password'])) { $error = "Username and Password are required."; $step = 4; } 
            elseif ($admin_config['password'] !== $admin_config['confirm_password']) { $error = "Passwords do not match."; $step = 4; } 
            else {
                try {
                    $conn = new mysqli($db_config['db_host'], $db_config['db_user'], $db_config['db_pass'], $db_config['db_name']);
                    $role_query = "INSERT INTO roles (id, role_name, Description) VALUES (1, 'Super Admin', 'Full Access') ON DUPLICATE KEY UPDATE role_name = 'Super Admin', Description = 'Full Access'";
                    if (!$conn->query($role_query)) throw new Exception("Error creating default admin role: " . $conn->error); 
                    
                    $hashed_password = password_hash($admin_config['password'], PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("INSERT INTO users (username, password, firstname, lastname, role_id) VALUES (?, ?, 'Super', 'Admin', 1)");
                    $stmt->bind_param("ss", $admin_config['username'], $hashed_password);
                    if (!$stmt->execute()) throw new Exception("Error creating admin user: " . $stmt->error);
                    
                    $app_base_path_logic = <<<'EOT'

// --- START: ROBUST DYNAMIC BASE PATH DEFINITION ---
$includes_dir = __DIR__;
$project_root_fs = dirname($includes_dir); 
$document_root_fs = $_SERVER['DOCUMENT_ROOT']; 
$project_root_fs_norm = str_replace(DIRECTORY_SEPARATOR, '/', $project_root_fs);
$document_root_fs_norm = str_replace(DIRECTORY_SEPARATOR, '/', rtrim($document_root_fs, DIRECTORY_SEPARATOR));
if (strpos($project_root_fs_norm, $document_root_fs_norm) === 0) { $base_path_url = substr($project_root_fs_norm, strlen($document_root_fs_norm)); } else { $base_path_url = ''; }
define('APP_BASE_PATH', rtrim($base_path_url, '/') . '/'); 
// --- END: ROBUST DYNAMIC BASE PATH DEFINITION ---

EOT;
                    $config_content = "<?php\n// Database Credentials\n";
                    $config_content .= "define('DB_HOST', '" . addslashes($db_config['db_host']) . "');\n";
                    $config_content .= "define('DB_USER', '" . addslashes($db_config['db_user']) . "');\n";
                    $config_content .= "define('DB_PASS', '" . addslashes($db_config['db_pass']) . "');\n";
                    $config_content .= "define('DB_NAME', '" . addslashes($db_config['db_name']) . "');\n";
                    $config_content .= $app_base_path_logic; 
                    $config_content .= "\n// Database Connection\n\$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);\nif (\$conn->connect_error) { die('Connection Failed: ' . \$conn->connect_error); }\n?>";

                    if (!file_put_contents($db_connect_file, $config_content)) throw new Exception("Could not write to db_connect.php."); 
                    
                    $ldap_content = "<?php\ndefine('ENABLE_LDAP_AUTH', false);\ndefine('LDAP_HOST', '');\ndefine('LDAP_PORT', '389');\ndefine('LDAP_BASE_DN', '');\ndefine('LDAP_DOMAIN', '');\ndefine('LDAP_DEFAULT_USER_ROLE', 'user');\n?>";
                    if (!file_put_contents($ldap_config_file, $ldap_content)) throw new Exception("Could not write to ldap_config.php.");

                    $project_root_fs_norm = str_replace(DIRECTORY_SEPARATOR, '/', $ROOT_DIR);
                    $document_root_fs_norm = str_replace(DIRECTORY_SEPARATOR, '/', rtrim($_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR));
                    $base_path_url = (strpos($project_root_fs_norm, $document_root_fs_norm) === 0) ? substr($project_root_fs_norm, strlen($document_root_fs_norm)) : '';
                    $rewrite_base = rtrim($base_path_url, '/') . '/';

                    $htaccess_content = "# Prevent Directory Listing\nOptions -Indexes\n\nRewriteEngine On\nRewriteBase {$rewrite_base}\n\n# Deny access to sensitive folders\nRewriteRule ^includes/ - [F,L]\nRewriteRule ^install/ - [F,L]\n\n# Clean URL Routing\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule ^([^/]+)/?$ index.php?route=$1 [QSA,L]\n";
                    if (!file_put_contents($htaccess_file, $htaccess_content)) throw new Exception("Could not create .htaccess file.");

                    // WRITE VERSION LOCK
                    if (!file_put_contents($lock_file, 'v2.0')) throw new Exception("Could not create lock file.");

                    session_destroy();
                    
                    // Attempt Auto-Rename
                    $install_dir = $ROOT_DIR . '/install';
                    $bak_dir = $ROOT_DIR . '/install_bak_' . date('Ymd_His');
                    if (@rename($install_dir, $bak_dir)) {
                        header("Location: ../index.php?msg=install_complete");
                        exit;
                    } else {
                        $step = 5; 
                    }
                } catch (Exception $e) { $error = "Finalization failed: " . $e->getMessage(); $step = 4; }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Application Installer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <style>
        html, body { height: 100%; }
        body { background-color: #f0f2f5 !important; display: flex; align-items: center; }
        .card { max-width: 700px; border: none; border-radius: 0.75rem; }
        .card-header { background-color: #0C5D58; color: white; border-top-left-radius: 0.75rem; border-top-right-radius: 0.75rem; }
        .progress-bar { background-color: #0C5D58; }
        .list-group-item .feather { width: 18px; height: 18px; }
        .btn-primary { background-color: #0C5D58; border-color: #0C5D58; }
        .btn-primary:hover { background-color: #084c47; border-color: #084c47; }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-9">
                <div class="card shadow-lg">
                    <div class="card-header p-4 d-flex align-items-center">
                        <img src="../assets/images/favicon.png" alt="Logo" style="height: 40px; margin-right: 15px;">
                        <h2 class="mb-0 h4 flex-grow-1">Templates Portal Configuration</h2>
                    </div>
                    <div class="card-body p-4 p-md-5">

                        <?php if (isset($show_upgrade_screen)): ?>
                            <h4 class="text-center mb-3">System Update Detected</h4>
                            <?php if (!empty($upgrade_error)) echo "<div class='alert alert-danger'>$upgrade_error</div>"; ?>
                            <p class="text-muted text-center">Your application files have been updated, but your database needs to be synchronized with the latest version. Doing this will not erase your current data.</p>
                            <form method="post" action="install.php" class="text-center mt-4">
                                <button type="submit" name="do_upgrade" class="btn btn-primary px-5 py-2">Run System Upgrade <i data-feather="database" class="ms-1" style="width:16px;"></i></button>
                            </form>

                        <?php elseif (isset($show_upgrade_success)): ?>
                            <h4 class="text-center text-success mb-3">Upgrade Complete!</h4>
                            <div class="alert alert-success text-center mb-3">
                                Your database and configuration files have been successfully updated to the latest version.
                            </div>
                            <div class="alert alert-danger text-center shadow-sm">
                                <h5 class="alert-heading"><i data-feather="alert-triangle" class="me-2"></i>Action Required</h5>
                                <p class="mb-0">Your server permissions prevented the automatic removal of this tool. You <b>MUST</b> manually delete or rename the <code>/install/</code> directory from your server before you can access the application.</p>
                            </div>
                            <div class="d-grid mt-4"><a href="../index.php" class="btn btn-primary">I have removed the folder. Continue to Login</a></div>

                        <?php else: ?>
                            <?php if($step > 1 && $step < 5): ?>
                            <div class="progress mb-4" style="height: 25px;"><div class="progress-bar fw-bold" role="progressbar" style="width: <?php echo (($step - 1) / $total_steps) * 100; ?>%;">Step <?php echo $step - 1; ?> of <?php echo $total_steps; ?></div></div>
                            <?php endif; ?>

                            <?php if ($step == 1): ?>
                                <h4 class="text-center mb-3">Welcome to the Installer</h4><p class="text-muted text-center">This wizard will guide you through the necessary steps to get the application up and running.</p><form method="post" action="install.php" class="text-end mt-4"><input type="hidden" name="step" value="2"><button type="submit" class="btn btn-primary">Begin Installation <i data-feather="arrow-right" class="ms-1" style="width:16px;"></i></button></form>
                            <?php elseif ($step == 2): ?>
                                <h4 class="text-center mb-4">Step 1: Pre-installation Checks</h4><ul class="list-group mb-4"><li class="list-group-item d-flex justify-content-between align-items-center">PHP Version >= 8.0.0 <span class="badge bg-<?php echo $checks['php_version'] ? 'success' : 'danger'; ?>"><i data-feather="<?php echo $checks['php_version'] ? 'check' : 'x'; ?>"></i></span></li><li class="list-group-item d-flex justify-content-between align-items-center">MySQLi Extension Enabled <span class="badge bg-<?php echo $checks['mysqli'] ? 'success' : 'danger'; ?>"><i data-feather="<?php echo $checks['mysqli'] ? 'check' : 'x'; ?>"></i></span></li><li class="list-group-item d-flex justify-content-between align-items-center">LDAP Extension Enabled <span class="badge bg-<?php echo $checks['ldap'] ? 'success' : 'danger'; ?>"><i data-feather="<?php echo $checks['ldap'] ? 'check' : 'x'; ?>"></i></span></li><li class="list-group-item d-flex justify-content-between align-items-center">MySQL Native Driver (mysqlnd) <span class="badge bg-<?php echo $checks['mysqlnd'] ? 'success' : 'danger'; ?>"><i data-feather="<?php echo $checks['mysqlnd'] ? 'check' : 'x'; ?>"></i></span></li><li class="list-group-item d-flex justify-content-between align-items-center"><code>/includes/</code> Directory Writable <span class="badge bg-<?php echo $checks['includes_writable'] ? 'success' : 'danger'; ?>"><i data-feather="<?php echo $checks['includes_writable'] ? 'check' : 'x'; ?>"></i></span></li><li class="list-group-item d-flex justify-content-between align-items-center"><code>/uploads/</code> Directory Writable <span class="badge bg-<?php echo $checks['uploads_writable'] ? 'success' : 'danger'; ?>"><i data-feather="<?php echo $checks['uploads_writable'] ? 'check' : 'x'; ?>"></i></span></li><li class="list-group-item d-flex justify-content-between align-items-center">Project Root Writable (for .htaccess) <span class="badge bg-<?php echo $checks['root_writable'] ? 'success' : 'danger'; ?>"><i data-feather="<?php echo $checks['root_writable'] ? 'check' : 'x'; ?>"></i></span></li><li class="list-group-item d-flex justify-content-between align-items-center"><code>/install/database/schema.sql</code> Readable <span class="badge bg-<?php echo $checks['schema_readable'] ? 'success' : 'danger'; ?>"><i data-feather="<?php echo $checks['schema_readable'] ? 'check' : 'x'; ?>"></i></span></li></ul><div class="d-flex justify-content-between"><a href="install.php" class="btn btn-link text-secondary ps-0">Back to Welcome</a><div><?php if (!$_SESSION['checks_passed']): ?><a href="install.php?step=2" class="btn btn-secondary">Re-check</a><?php endif; ?><form method="post" action="install.php" class="d-inline"><input type="hidden" name="step" value="3"><button type="submit" class="btn btn-primary" <?php if (!$_SESSION['checks_passed']) echo 'disabled'; ?>>Continue</button></form></div></div>
                            <?php elseif ($step == 3): ?>
                                <h4 class="text-center mb-4">Step 2: Database Setup</h4><?php if ($error) echo "<div class='alert alert-danger'>$error</div>"; ?><p class="text-muted text-center">Enter your database credentials. The installer will attempt to connect, create the database, and import the table structure.</p><form method="post" action="install.php"><input type="hidden" name="step" value="3"><div class="mb-3"><label class="form-label">Database Host</label><input type="text" name="db_host" class="form-control" value="<?php echo htmlspecialchars($db_config['db_host']); ?>" required></div><div class="mb-3"><label class="form-label">Database Name</label><input type="text" name="db_name" class="form-control" value="<?php echo htmlspecialchars($db_config['db_name']); ?>" required></div><div class="mb-3"><label class="form-label">Database User</label><input type="text" name="db_user" class="form-control" value="<?php echo htmlspecialchars($db_config['db_user']); ?>" required></div><div class="mb-3"><label class="form-label">Database Password</label><input type="password" name="db_pass" class="form-control" value="<?php echo htmlspecialchars($db_config['db_pass']); ?>"></div><div class="d-flex justify-content-between"><a href="install.php?step=2" class="btn btn-link text-secondary ps-0">Back</a><button type="submit" class="btn btn-primary">Setup Database & Continue</button></div></form>
                            <?php elseif ($step == 4): ?>
                                <h4 class="text-center mb-4">Step 3: Create Super Admin</h4><?php if ($error) echo "<div class='alert alert-danger'>$error</div>"; ?><p class="text-center">Database setup was successful! Now, create your Super Admin account to finalize the installation.</p><form method="post" action="install.php"><input type="hidden" name="step" value="4"><div class="mb-3"><label class="form-label">Username</label><input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($admin_config['username']); ?>" required></div><div class="row"><div class="col-md-6 mb-3"><label class="form-label">Password</label><input type="password" name="password" class="form-control" required></div><div class="col-md-6 mb-3"><label class="form-label">Confirm Password</label><input type="password" name="confirm_password" class="form-control" required></div></div><div class="d-flex justify-content-between"><a href="install.php?step=3" class="btn btn-link text-secondary ps-0">Back</a><button type="submit" class="btn btn-primary">Complete Installation</button></div></form>
                            <?php elseif ($step == 5): ?>
                                <h4 class="text-center text-success">Installation Complete!</h4><div class="alert alert-success mt-4 text-center"><p>The application has been installed successfully.</p></div><div class="alert alert-danger text-center shadow-sm"><h5 class="alert-heading"><i data-feather="alert-triangle" class="me-2"></i>Action Required</h5><p class="mb-0">Your server permissions prevented the automatic removal of this tool. You <b>MUST</b> manually delete or rename the <code>/install/</code> directory from your server before you can log in.</p></div><div class="d-grid mt-4"><a href="../index.php" class="btn btn-primary">I have removed the folder. Continue to Login</a></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>feather.replace();</script>
</body>
</html>