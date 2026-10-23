<?php
// FILE: logout.php

// The session_start() call is needed to access and destroy the session.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include necessary files for the logging function.
include_once 'includes/db_connect.php';
include_once 'includes/auth.php';

// 1. Log the logout event before destroying session data (Your addition).
if (isset($_SESSION['username']) && isset($conn)) {
    log_system_event($conn, 'Authentication', 'User "' . $_SESSION['username'] . '" logged out.');
}

// 2. Unset all of the session variables.
$_SESSION = array();

// 3. CRITICAL STEP: Delete the session cookie from the user's browser.
// This forces the browser to forget the old session completely.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 4. Finally, destroy the session on the server.
session_destroy();

// 5. Redirect to the login page with the correct message.
$message = "You have been logged out successfully.";

// Check if the logout was triggered automatically by a session timeout.
if (isset($_GET['reason']) && $_GET['reason'] === 'expired') {
    $message = "session_expired";
}

header("Location: index.php?message=" . urlencode($message));
exit();
?>