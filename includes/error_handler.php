<?php
// config/error_handler.php

$logDir = __DIR__ . '/../logs';

if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$logFile = $logDir . '/php_error.log';

// Disable showing errors in browser
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

// Helper function to force write to OUR file, bypassing server overrides
function writeCustomLog($message) {
    global $logFile;
    // The '3' tells error_log to append to the specific file path
    error_log("[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL, 3, $logFile);
}

// 1. Capture Normal Errors & Warnings
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    writeCustomLog("PHP Error [$errno]: $errstr in $errfile on line $errline");
    return true; // Prevent default PHP error handler from running
});

// 2. Capture Uncaught Exceptions
set_exception_handler(function($exception) {
    writeCustomLog("Uncaught Exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine());
});

// 3. Capture Fatal Shutdown Errors
register_shutdown_function(function () {
    $error = error_get_last();
    // Catch fatal errors that bypass the normal error handler
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        writeCustomLog("FATAL ERROR: {$error['message']} in {$error['file']} on line {$error['line']}");
    }
});

// 4. Custom App Logger
function appLog($message, $type = "INFO") {
    writeCustomLog("[$type] $message");
}

// Omit closing PHP tag