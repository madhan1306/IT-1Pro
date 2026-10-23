<?php
// FILE: includes/cron_trigger.php

// Path to a simple file that stores the timestamp of the last run
$last_run_file = __DIR__ . '/cron_last_run.txt';
// How often to run the script (in seconds). 86400 = 24 hours.
$run_interval = 86400; 

// Check if the last run time is stored
$last_run_time = 0;
if (file_exists($last_run_file)) {
    $last_run_time = (int)file_get_contents($last_run_file);
}

// Check if it's time to run the task again
if (time() - $last_run_time > $run_interval) {
    
    // Update the timestamp IMMEDIATELY to prevent multiple triggers
    file_put_contents($last_run_file, time());

    // Include and execute your daily deactivation script
    include_once __DIR__ . '/daily_deactivation_check.php';
}
?>