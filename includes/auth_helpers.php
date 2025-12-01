<?php
/**
 * Authentication helper utilities.
 *
 * Currently provides reusable helpers for login verification codes.
 */

require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/src/SMTP.php';
require_once __DIR__ . '/../PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Send email notification to property owner when tenant sends a message
 *
 * @param mysqli  $conn
 * @param int     $thread_id
 * @param int     $tenant_id
 * @param int     $owner_id
 * @param string  $message_content
 * @param int     $listing_id
 * @return bool   True if email sent successfully, false otherwise
 */
function hb_send_inquiry_notification(mysqli $conn, int $thread_id, int $tenant_id, int $owner_id, string $message_content, int $listing_id): bool
{
    // Get owner details and notification preferences
    $stmt = $conn->prepare("SELECT first_name, last_name, email, email_notifications, instant_notifications FROM tbadmin WHERE id = ?");
    $stmt->bind_param("i", $owner_id);
    $stmt->execute();
    $owner_result = $stmt->get_result();
    $owner = $owner_result->fetch_assoc();
    $stmt->close();

    if (!$owner || empty($owner['email'])) {
        error_log("hb_send_inquiry_notification: Owner not found or no email for owner_id: $owner_id");
        return false;
    }

    // Check if owner has email notifications enabled
    if (!$owner['email_notifications'] || !$owner['instant_notifications']) {
        error_log("hb_send_inquiry_notification: Email notifications disabled for owner_id: $owner_id");
        return false;
    }

    // Get tenant details
    $stmt = $conn->prepare("SELECT first_name, last_name FROM tbadmin WHERE id = ?");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $tenant_result = $stmt->get_result();
    $tenant = $tenant_result->fetch_assoc();
    $stmt->close();

    if (!$tenant) {
        error_log("hb_send_inquiry_notification: Tenant not found for tenant_id: $tenant_id");
        return false;
    }

    // Get listing details
    $stmt = $conn->prepare("SELECT title, address, price FROM tblistings WHERE id = ?");
    $stmt->bind_param("i", $listing_id);
    $stmt->execute();
    $listing_result = $stmt->get_result();
    $listing = $listing_result->fetch_assoc();
    $stmt->close();

    if (!$listing) {
        error_log("hb_send_inquiry_notification: Listing not found for listing_id: $listing_id");
        return false;
    }

    $owner_name = trim($owner['first_name'] . ' ' . $owner['last_name']);
    $tenant_name = trim($tenant['first_name'] . ' ' . $tenant['last_name']);
    $property_title = $listing['title'];
    $property_address = $listing['address'];
    $property_price = number_format($listing['price']);

    // Create email content
    $subject = "New Property Inquiry - " . $property_title;
    
    $html_body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #8B4513; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 8px 8px; }
            .property-info { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #8B4513; }
            .message-box { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; border: 1px solid #ddd; }
            .cta-button { display: inline-block; background: #8B4513; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; margin: 20px 0; }
            .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🏠 New Property Inquiry</h1>
                <p>Someone is interested in your property!</p>
            </div>
            
            <div class='content'>
                <h2>Hello " . htmlspecialchars($owner_name) . ",</h2>
                
                <p><strong>" . htmlspecialchars($tenant_name) . "</strong> has sent you a message about your property:</p>
                
                <div class='property-info'>
                    <h3>📋 Property Details</h3>
                    <p><strong>Title:</strong> " . htmlspecialchars($property_title) . "</p>
                    <p><strong>Address:</strong> " . htmlspecialchars($property_address) . "</p>
                    <p><strong>Price:</strong> ₱" . htmlspecialchars($property_price) . "</p>
                </div>
                
                <div class='message-box'>
                    <h3>💬 Tenant's Message</h3>
                    <p><em>\"" . htmlspecialchars($message_content) . "\"</em></p>
                </div>
                
                <p>Please respond to this inquiry as soon as possible to maintain good tenant relations.</p>
                
                <a href='https://yourdomain.com/DashboardUO.php?thread_id=" . $thread_id . "' class='cta-button'>
                    📱 Reply to Tenant
                </a>
                
                <p><small>This is an automated notification from HanapBahay. Please do not reply to this email.</small></p>
            </div>
            
            <div class='footer'>
                <p>HanapBahay - Your Property Management Partner</p>
                <p>© " . date('Y') . " HanapBahay. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ";

    $text_body = "
New Property Inquiry - " . $property_title . "

Hello " . $owner_name . ",

" . $tenant_name . " has sent you a message about your property:

Property Details:
- Title: " . $property_title . "
- Address: " . $property_address . "
- Price: ₱" . $property_price . "

Tenant's Message:
\"" . $message_content . "\"

Please respond to this inquiry as soon as possible.

Reply to tenant: https://yourdomain.com/DashboardUO.php?thread_id=" . $thread_id . "

This is an automated notification from HanapBahay.
    ";

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'ethos.cpstn@gmail.com';
        $mail->Password   = 'ntwhcojthfgakjxr';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('ethos.cpstn@gmail.com', 'HanapBahay');
        $mail->addAddress($owner['email'], $owner_name);
        $mail->addReplyTo('noreply@hanapbahay.com', 'HanapBahay');

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_body;
        $mail->AltBody = $text_body;

        $mail->send();
        
        // Log successful notification
        error_log("Property inquiry notification sent to owner: " . $owner['email'] . " for listing: " . $listing_id);
        return true;
        
    } catch (Exception $e) {
        error_log("Failed to send property inquiry notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Send email notification to property owner when tenant starts a new conversation
 *
 * @param mysqli  $conn
 * @param int     $thread_id
 * @param int     $tenant_id
 * @param int     $owner_id
 * @param int     $listing_id
 * @return bool   True if email sent successfully, false otherwise
 */
function hb_send_new_conversation_notification(mysqli $conn, int $thread_id, int $tenant_id, int $owner_id, int $listing_id): bool
{
    // Get owner details and notification preferences
    $stmt = $conn->prepare("SELECT first_name, last_name, email, email_notifications, instant_notifications FROM tbadmin WHERE id = ?");
    $stmt->bind_param("i", $owner_id);
    $stmt->execute();
    $owner_result = $stmt->get_result();
    $owner = $owner_result->fetch_assoc();
    $stmt->close();

    if (!$owner || empty($owner['email'])) {
        error_log("hb_send_new_conversation_notification: Owner not found or no email for owner_id: $owner_id");
        return false;
    }

    // Check if owner has email notifications enabled
    if (!$owner['email_notifications'] || !$owner['instant_notifications']) {
        error_log("hb_send_new_conversation_notification: Email notifications disabled for owner_id: $owner_id");
        return false;
    }

    // Get tenant details
    $stmt = $conn->prepare("SELECT first_name, last_name FROM tbadmin WHERE id = ?");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $tenant_result = $stmt->get_result();
    $tenant = $tenant_result->fetch_assoc();
    $stmt->close();

    if (!$tenant) {
        error_log("hb_send_new_conversation_notification: Tenant not found for tenant_id: $tenant_id");
        return false;
    }

    // Get listing details
    $stmt = $conn->prepare("SELECT title, address, price FROM tblistings WHERE id = ?");
    $stmt->bind_param("i", $listing_id);
    $stmt->execute();
    $listing_result = $stmt->get_result();
    $listing = $listing_result->fetch_assoc();
    $stmt->close();

    if (!$listing) {
        error_log("hb_send_new_conversation_notification: Listing not found for listing_id: $listing_id");
        return false;
    }

    $owner_name = trim($owner['first_name'] . ' ' . $owner['last_name']);
    $tenant_name = trim($tenant['first_name'] . ' ' . $tenant['last_name']);
    $property_title = $listing['title'];
    $property_address = $listing['address'];
    $property_price = number_format($listing['price']);

    // Create email content
    $subject = "New Property Inquiry Started - " . $property_title;
    
    $html_body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #8B4513; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 8px 8px; }
            .property-info { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #8B4513; }
            .cta-button { display: inline-block; background: #8B4513; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; margin: 20px 0; }
            .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🏠 New Property Inquiry</h1>
                <p>A potential tenant is interested in your property!</p>
            </div>
            
            <div class='content'>
                <h2>Hello " . htmlspecialchars($owner_name) . ",</h2>
                
                <p><strong>" . htmlspecialchars($tenant_name) . "</strong> has started a conversation about your property:</p>
                
                <div class='property-info'>
                    <h3>📋 Property Details</h3>
                    <p><strong>Title:</strong> " . htmlspecialchars($property_title) . "</p>
                    <p><strong>Address:</strong> " . htmlspecialchars($property_address) . "</p>
                    <p><strong>Price:</strong> ₱" . htmlspecialchars($property_price) . "</p>
                </div>
                
                <p>This tenant is interested in your property and wants to know more details. Please respond promptly to convert this inquiry into a potential rental.</p>
                
                <a href='https://yourdomain.com/DashboardUO.php?thread_id=" . $thread_id . "' class='cta-button'>
                    💬 Start Conversation
                </a>
                
                <p><small>This is an automated notification from HanapBahay. Please do not reply to this email.</small></p>
            </div>
            
            <div class='footer'>
                <p>HanapBahay - Your Property Management Partner</p>
                <p>© " . date('Y') . " HanapBahay. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ";

    $text_body = "
New Property Inquiry Started - " . $property_title . "

Hello " . $owner_name . ",

" . $tenant_name . " has started a conversation about your property:

Property Details:
- Title: " . $property_title . "
- Address: " . $property_address . "
- Price: ₱" . $property_price . "

This tenant is interested in your property and wants to know more details. Please respond promptly to convert this inquiry into a potential rental.

Start conversation: https://yourdomain.com/DashboardUO.php?thread_id=" . $thread_id . "

This is an automated notification from HanapBahay.
    ";

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'ethos.cpstn@gmail.com';
        $mail->Password   = 'ntwhcojthfgakjxr';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('ethos.cpstn@gmail.com', 'HanapBahay');
        $mail->addAddress($owner['email'], $owner_name);
        $mail->addReplyTo('noreply@hanapbahay.com', 'HanapBahay');

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_body;
        $mail->AltBody = $text_body;

        $mail->send();
        
        // Log successful notification
        error_log("New conversation notification sent to owner: " . $owner['email'] . " for listing: " . $listing_id);
        return true;
        
    } catch (Exception $e) {
        error_log("Failed to send new conversation notification: " . $e->getMessage());
        return false;
    }
}
function hb_send_login_code(mysqli $conn, int $userId, string $email, string $name = ''): bool
{
    $code   = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    $stmt = $conn->prepare("
        UPDATE tbadmin
           SET verification_code = ?,
               code_expiry       = ?,
               login_attempts    = 0,
               lock_until        = NULL
         WHERE id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        error_log('hb_send_login_code: failed to prepare update statement.');
        return false;
    }
    $stmt->bind_param('ssi', $code, $expiry, $userId);
    $stmt->execute();
    $stmt->close();

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'ethos.cpstn@gmail.com';
        $mail->Password   = 'ntwhcojthfgakjxr';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('ethos.cpstn@gmail.com', 'HanapBahay');
        $mail->addAddress($email, $name);
        $mail->Subject = 'Your HanapBahay Verification Code';
        $mail->Body    = "Your code is: {$code}\nThis will expire in 10 minutes.";

        $mail->send();
        return true;
    } catch (\Throwable $e) {
        error_log('hb_send_login_code mail error: ' . $e->getMessage());
        return false;
    }
}
