<?php
$file = __DIR__ . '/08_Aug2026_IT-Shift-Roster.xlsx';

if (!file_exists($file)) {
    http_response_code(404);
    exit('File not found on server.');
}

// CRITICAL FIX: This wipes out any hidden blank spaces or PHP warnings 
// that might be corrupting the Excel file before Javascript reads it.
if (ob_get_length()) {
    ob_clean();
}

// Tell the browser and Javascript EXACTLY what kind of file this is
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="08_Aug2026_IT-Shift-Roster.xlsx"');
header('Content-Length: ' . filesize($file));
header('Cache-Control: max-age=0');

// Stream the file
readfile($file);
exit;
?>