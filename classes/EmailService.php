<?php
// FILE: classes/EmailService.php

// Import PHPMailer classes into the global namespace
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Manually load the PHPMailer files
require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

class EmailService {
    
    private $db;

    // ==========================================
    // ?? O365 DIRECT SEND / RELAY CONFIGURATION
    // ==========================================
    private $smtpHost = 'suntechnologies-com.mail.protection.outlook.com'; 
    private $smtpUser = 'no-reply@suntechnologies.com'; 
    private $smtpPort = 25; 
    private $fromName = 'IT Onboarding System';
    // ==========================================

    // ==========================================
    // ?? EMAIL GROUPS
    // ==========================================
    private $groups = [
        'NewRequests'       => '',      // Update with your real New Requests group email
        'ApprovedTemplates' => '' // Update with your real Approved group email
    ];

    public function __construct($db = null) {
        $this->db = $db;
    }

    /**
     * Resolves the actual email ID by querying the database.
     */
    private function resolveUserEmail($nameOrUsername) {
        $nameOrUsername = trim($nameOrUsername);
        if (empty($nameOrUsername) || !$this->db) {
            return null;
        }

        // 1. Check Employees Table
        $sql = "SELECT email_id FROM employees WHERE domain_username = ? OR first_name = ? OR CONCAT(first_name, ' ', last_name) = ? LIMIT 1";
        $stmt = $this->db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("sss", $nameOrUsername, $nameOrUsername, $nameOrUsername);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                if (!empty($row['email_id'])) {
                    $stmt->close();
                    return trim($row['email_id']);
                }
            }
            $stmt->close();
        }

        // 2. Fallback to Users table
        $sql_u = "SELECT email FROM users WHERE username = ? OR firstname = ? OR CONCAT(firstname, ' ', lastname) = ? LIMIT 1";
        $stmt_u = $this->db->prepare($sql_u);
        if ($stmt_u) {
            $stmt_u->bind_param("sss", $nameOrUsername, $nameOrUsername, $nameOrUsername);
            $stmt_u->execute();
            $result_u = $stmt_u->get_result();
            if ($row_u = $result_u->fetch_assoc()) {
                if (!empty($row_u['email'])) {
                    $stmt_u->close();
                    return trim($row_u['email']);
                }
            }
            $stmt_u->close();
        }

        return null;
    }

    // ==========================================
    // RULE 1: NEW REQUEST CREATED
    // ==========================================
    public function sendNewRequestEmail($data) {
        $requesterEmail  = $this->resolveUserEmail($data['requested_by'] ?? '');
        $newRequestGroup = $this->groups['NewRequests'];

        $to = [$newRequestGroup];
        $cc = [];
        if ($requesterEmail) {
            $cc[] = $requesterEmail;
        }

        $subject = "New Onboarding Request: " . ($data['first_name'] ?? '') . " " . ($data['last_name'] ?? '');
        
        // Pass 'false' at the end to indicate this is NOT an approval email
        $htmlBody = $this->generateHtmlTemplate("New Onboarding Request Submitted", $data, "A new onboarding request has been created and requires review.", false);
        
        return $this->sendMail($to, $cc, $subject, $htmlBody);
    }

    // ==========================================
    // RULE 2: REQUEST APPROVED & FINISHED
    // ==========================================
    public function sendApprovalEmail($data) {
        $approvedGroup  = $this->groups['ApprovedTemplates']; 
        $requesterEmail = $this->resolveUserEmail($data['requested_by'] ?? '');
        $approverEmail  = $this->resolveUserEmail($data['created_by'] ?? '');

        $to = [];
        if ($requesterEmail) {
            $to[] = $requesterEmail;
        }

        $cc = [$approvedGroup];
        if ($approverEmail && $approverEmail !== $requesterEmail) {
            $cc[] = $approverEmail;
        }

        $subject = "Approved: Onboarding for " . ($data['first_name'] ?? '') . " " . ($data['last_name'] ?? '');
        
        // Pass 'true' at the end to explicitly trigger the Approval rows
        $htmlBody = $this->generateHtmlTemplate("Onboarding Request Approved", $data, "The following onboarding request has been officially approved and is ready for system provisioning.", true);
        
        return $this->sendMail($to, $cc, $subject, $htmlBody);
    }

    // ==========================================
    // DYNAMIC HTML TEMPLATE GENERATOR
    // ==========================================
    private function generateHtmlTemplate($title, $data, $introMessage, $isApproval = false) {
        $fullName = htmlspecialchars(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));
        $department = htmlspecialchars($data['department'] ?? 'N/A');
        $position = htmlspecialchars($data['position'] ?? 'N/A');
        
        $doj = !empty($data['date_of_joining']) ? date('d M Y', strtotime($data['date_of_joining'])) : 'N/A';
        $requestedBy = htmlspecialchars($data['requested_by'] ?? 'System');
        $requestedAt = !empty($data['requested_at']) ? date('d M Y, h:i A', strtotime($data['requested_at'])) : date('d M Y, h:i A');

        // Dynamic rows based on approval status
        $approvalRows = "";

        if ($isApproval) {
            // Approver data is saved in 'created_by' / 'created_at' during the approval process
            $approvedBy = htmlspecialchars($data['created_by'] ?? 'System');
            $approvedAt = !empty($data['created_at']) ? date('d M Y, h:i A', strtotime($data['created_at'])) : date('d M Y, h:i A');
            
            // Generate the direct PDF link row using the database ID
            $recordId = htmlspecialchars($data['id'] ?? '');
            $pdfRow = "";
            if (!empty($recordId)) {
                $pdfUrl = "http://172.16.29.172/itone/index.php?route=generate_template_pdf&id={$recordId}";
                $pdfRow = "<tr><th>Template PDF</th><td><a href='{$pdfUrl}' style='color: #0f766e; text-decoration: underline; word-break: break-all;'>{$pdfUrl}</a></td></tr>";
            }
            
            // Append the approval rows and the PDF row to the table
            $approvalRows = "
                <tr><th>Approved By</th><td>{$approvedBy}</td></tr>
                <tr><th>Approved At</th><td>{$approvedAt}</td></tr>
                {$pdfRow}
            ";
        }

        return "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; background-color: #f3f4f6; padding: 20px; color: #333; }
                .email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; border: 1px solid #e5e7eb; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
                .header { background-color: #0f766e; color: #ffffff; padding: 20px; text-align: center; }
                .header h2 { margin: 0; font-size: 20px; }
                .content { padding: 24px; }
                .intro { margin-bottom: 20px; font-size: 14px; line-height: 1.6; color: #4b5563; }
                .data-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                .data-table th, .data-table td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #e5e7eb; font-size: 14px; }
                .data-table th { background-color: #f9fafb; font-weight: 600; color: #4b5563; width: 35%; }
                .data-table td { color: #1f2937; font-weight: 500; }
                .footer { background-color: #f9fafb; padding: 15px; text-align: center; font-size: 12px; color: #6b7280; border-top: 1px solid #e5e7eb; }
            </style>
        </head>
        <body>
            <div class='email-container'>
                <div class='header'>
                    <h2>{$title}</h2>
                </div>
                <div class='content'>
                    <p class='intro'>{$introMessage}</p>
                    <table class='data-table'>
                        <tr><th>Employee Name</th><td>{$fullName}</td></tr>
                        <tr><th>Department</th><td>{$department}</td></tr>
                        <tr><th>Position</th><td>{$position}</td></tr>
                        <tr><th>Date of Joining</th><td>{$doj}</td></tr>
                        <tr><th>Requested By</th><td>{$requestedBy}</td></tr>
                        <tr><th>Requested At</th><td>{$requestedAt}</td></tr>
                        {$approvalRows}
                    </table>
                </div>
                <div class='footer'>
                    This is an automated notification from the IT Onboarding System.<br>
                    Please do not reply directly to this email.
                </div>
            </div>
        </body>
        </html>
        ";
    }

    private function sendMail($toArray, $ccArray, $subject, $htmlBody) {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = $this->smtpHost;
            $mail->SMTPAuth   = false; 
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $this->smtpPort;

            $mail->setFrom($this->smtpUser, $this->fromName);

            $toArray = array_unique(array_filter($toArray));
            $ccArray = array_unique(array_filter($ccArray));

            foreach ($toArray as $email) {
                $mail->addAddress(trim($email));
            }

            foreach ($ccArray as $email) {
                $email = trim($email);
                if (!in_array($email, $toArray)) {
                    $mail->addCC($email);
                }
            }

            if (empty($toArray) && empty($ccArray)) {
                error_log("EmailService: Aborted sending email, no valid TO or CC recipients found.");
                return false; 
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags($htmlBody);

            $mail->send();
            return true;
            
        } catch (\PHPMailer\PHPMailer\Exception $e) { 
            error_log("PHPMailer Error: {$mail->ErrorInfo}");
            return false;
        }
    }
}