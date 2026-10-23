<?php
// FILE: includes/email_config.php

// Load your existing EmailService class
require_once __DIR__ . '/../classes/EmailService.php';

// Define your Team Distribution List
define('TEAM_DL_EMAIL', 'sit@suntechnologies.com');
define('SYSTEM_FROM_EMAIL', 'itone@suntechnologies.com');

/**
 * Sends the SHR Handover HTML Email using your existing EmailService
 * Added $cc_team flag to allow sending private alerts (e.g., Awaiting Acknowledgement)
 */
function sendSHREmail($to_email, $to_name, $creator_email, $creator_name, $htmlBody, $subject, $cc_team = true) {
    
    // Instantiate your existing class
    $emailService = new EmailService();
    
    // We will use PHPMailer via your EmailService class
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        // Direct Send Configuration (No Auth Required)
        $mail->isSMTP();
        $mail->Host       = 'suntechnologies-com.mail.protection.outlook.com';
        $mail->Port       = 25;
        $mail->SMTPAuth   = false; // Direct Send doesn't use authentication
        $mail->SMTPAutoTLS = false; // Usually not required for O365 Direct Send

        // Sender
        $mail->setFrom(SYSTEM_FROM_EMAIL, 'IT-One');
        
        if (!empty($creator_email)) {
            $mail->addReplyTo($creator_email, $creator_name);
        }

        // Recipients
        $mail->addAddress($to_email, $to_name);
        
        // ONLY add CCs if the flag is true
        // ONLY add CCs if the flag is true
	if ($cc_team) {
    	// Split the comma-separated string into an array
    	$dl_emails = explode(',', TEAM_DL_EMAIL);
    
    	// Loop through and add each email separately
    	foreach ($dl_emails as $cc_email) {
        	$mail->addCC(trim($cc_email)); // trim() removes any accidental spaces
    		}	
    
    	if (!empty($creator_email)) {
        	$mail->addCC($creator_email, $creator_name);
    		}
	}

        // Subject Line
        $mail->Subject = $subject;

        // Content
        $mail->isHTML(true);
        $mail->Body = $htmlBody;

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("SHR Email failed. Error: {$mail->ErrorInfo}");
        return false;
    }
}

/**
 * Fetches a user's email from the employees table
 */
function getUserEmail($conn, $username) {
    // Default fallback
    $email = $username . '@sti.com';
    
    $stmt = $conn->prepare("SELECT email_id FROM employees WHERE domain_username = ? LIMIT 1");
    
    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        
        if ($res && !empty($res['email_id'])) {
            $email = $res['email_id'];
        }
        $stmt->close();
    }
    
    return $email;
}
?>