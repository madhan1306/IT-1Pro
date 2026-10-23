<?php
// Database Credentials
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', 'IT$upp0rt');
define('DB_NAME', 'itonedb');

// --- START: ROBUST DYNAMIC BASE PATH DEFINITION ---
// This calculates the web-accessible base path relative to the document root.
// This code runs every time db_connect.php is included.

// Get the physical file system path of this file's directory (e.g., /var/www/html/<projectdir>/includes)
$includes_dir = __DIR__;

// Get the physical file system path of the project's root directory (e.g., /var/www/html/<projectdir>)
$project_root_fs = dirname($includes_dir); 

// Get the server's document root (e.g., /var/www/html)
$document_root_fs = $_SERVER['DOCUMENT_ROOT']; 

// Normalize directory separators for consistency
$project_root_fs_norm = str_replace(DIRECTORY_SEPARATOR, '/', $project_root_fs);
$document_root_fs_norm = str_replace(DIRECTORY_SEPARATOR, '/', rtrim($document_root_fs, DIRECTORY_SEPARATOR));

// Calculate the URL path by removing the document root from the project's physical path.
if (strpos($project_root_fs_norm, $document_root_fs_norm) === 0) {
    // This will result in "/<projectdir>" or "" (if in root)
    $base_path_url = substr($project_root_fs_norm, strlen($document_root_fs_norm));
} else {
    // Fallback for unusual server configurations (e.g., symlinks outside doc root)
    $base_path_url = ''; 
}

// Ensure the base path always has a single trailing slash.
define('APP_BASE_PATH', rtrim($base_path_url, '/') . '/'); // e.g., "/<projectdir>/" or "/"

// --- END: ROBUST DYNAMIC BASE PATH DEFINITION ---

// Database Connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) { die('Connection Failed: ' . $conn->connect_error); }
?>