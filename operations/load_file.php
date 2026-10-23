<?php
$file = '/mnt/test/ITD-L4-29 NTP Sync Report.xlsx';

if (!file_exists($file)) {
    http_response_code(404);
    exit('File not found');
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
readfile($file);
exit;
?>