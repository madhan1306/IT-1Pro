<?php
// FILE: includes/api.php

// 1. Pointing to the native REST API for GLPI 9.1.x
$GLPI_URL = "http://helpdesk.sti.com/apirest.php"; 

// 2. Ensure absolutely no accidental spaces exist around your token
$USER_TOKEN = trim("h3trx8j9yoo64r1edluh0a4hvp2ztajhur9x4twq"); 

function callGLPI($endpoint, $method = 'GET', $body = null, $token = null) {
    global $GLPI_URL, $USER_TOKEN;
    
    $url = $GLPI_URL . $endpoint;
    
    // Core Headers
    $headers = [
        "Content-Type: application/json",
        "Accept: application/json"
    ];

    if ($endpoint === "/initSession") {
        // Strict match to GLPI 9.1 documentation for user_token
        $headers[] = "Authorization: user_token " . $USER_TOKEN;
    } elseif ($token) { 
        $headers[] = "Session-Token: " . $token; 
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 
    curl_setopt($ch, CURLOPT_POSTREDIR, 3); 
    curl_setopt($ch, CURLOPT_UNRESTRICTED_AUTH, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    if ($body) { 
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); 
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    return [
        'code' => $httpCode, 
        'data' => json_decode($response, true),
        'raw_response' => $response,
        'curl_error' => $curlError
    ];
}

function initGLPISession() {
    $res = callGLPI("/initSession", "GET");
    
    // =========================================================
    // DEBUG MODE: CAPTURE THE EXACT GLPI REJECTION REASON
    // =========================================================
    if (!isset($res['data']['session_token'])) {
        $errorMsg = "HTTP Code: " . $res['code'] . " | ";
        $errorMsg .= "GLPI Response: " . ($res['raw_response'] ?: 'EMPTY (Server blocked request)') . " | ";
        $errorMsg .= "cURL Error: " . ($res['curl_error'] ?: 'None');
        
        // This throws the exact GLPI error straight to your UI screen
        throw new Exception($errorMsg);
    }
    
    return $res['data']['session_token'];
}

function killGLPISession($token) {
    if ($token) {
        callGLPI("/killSession", "GET", null, $token);
    }
}

/**
 * Validates if the given hostname is currently assigned to the target user strictly by Domain Name
 */
function verifyGLPIAssignment($hostname, $domain_username, $employee_name, $employee_email) {
    $token = initGLPISession();
    
    if (!$token) return ['assigned' => false, 'message' => 'GLPI API Auth Failed.'];

    $search_url = "/search/Computer?criteria[0][field]=1&criteria[0][searchtype]=contains&criteria[0][value]=" . rawurlencode(trim($hostname)) . "&forcedisplay[0]=1&forcedisplay[1]=2";
    $res = callGLPI($search_url, 'GET', null, $token);

    if (empty($res['data']['data'])) {
        killGLPISession($token);
        return ['assigned' => false, 'message' => 'Hostname not found in GLPI.'];
    }

    $comp_id = null;
    $target_hostname = strtolower(trim($hostname));
    foreach ($res['data']['data'] as $comp) {
        if (strtolower(trim($comp['1'] ?? '')) === $target_hostname) {
            $comp_id = $comp['2'];
            break;
        }
    }

    if (!$comp_id) {
        killGLPISession($token);
        return ['assigned' => false, 'message' => "Exact hostname '$hostname' not found in GLPI search results."];
    }
    
    $comp_res = callGLPI("/Computer/$comp_id?expand_dropdowns=false", 'GET', null, $token);
    if (empty($comp_res['data'])) {
        killGLPISession($token);
        return ['assigned' => false, 'message' => 'Failed to fetch computer details.'];
    }
    
    $users_id = $comp_res['data']['users_id'] ?? 0;
    
    if ($users_id === 0 || $users_id === '0') {
        killGLPISession($token);
        return ['assigned' => false, 'message' => 'Asset is currently In-Stock / Not Assigned to anyone in GLPI.'];
    }

    $user_res = callGLPI("/User/$users_id?expand_dropdowns=false", 'GET', null, $token);
    killGLPISession($token);

    $glpi_username = strtolower(trim($user_res['data']['name'] ?? '')); 
    $glpi_realname = strtolower(trim($user_res['data']['realname'] ?? '')); 
    $glpi_firstname = strtolower(trim($user_res['data']['firstname'] ?? ''));
    $target_login = strtolower(trim($domain_username));

    if (!empty($target_login) && $glpi_username === $target_login) {
        return ['assigned' => true, 'message' => 'Verified.'];
    } else {
        $assigned_display = trim(ucwords($glpi_firstname . ' ' . $glpi_realname)) . " ($glpi_username)";
        return ['assigned' => false, 'message' => "Asset is currently assigned to: <b>$assigned_display</b> in GLPI.<br>It must be assigned to the checklist user: <b>$domain_username</b>."];
    }
}
?>