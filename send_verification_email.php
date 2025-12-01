<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Load PHPMailer classes
require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/Exception.php';
require_once 'PHPMailer/src/SMTP.php';
require_once 'app_config.php';

/**
 * Send verification email with full SMTP debug to file and port fallback.
 * Returns true on success, false on failure.
 */
function sendVerificationEmail($toEmail, $first_name, $verification_token) {
    $mail = new PHPMailer(true);

    // --- Debug to file for troubleshooting ---
    $mail->SMTPDebug  = 2; // 0=off, 2=client+server
    $mail->Debugoutput = function($str, $level) {
        @file_put_contents(__DIR__ . '/mail_debug.log', '['.date('c')."] $str\n", FILE_APPEND);
    };

    try {
        global $APP_CONFIG;
        
        // SMTP server configuration from config
        $mail->isSMTP();
        $mail->Host = $APP_CONFIG['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $APP_CONFIG['smtp_username'];
        $mail->Password = $APP_CONFIG['smtp_password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Sender info
        $mail->setFrom('ethos.cpstn@gmail.com', 'HanapBahay');
        $mail->addReplyTo('eysie2@gmail.com', 'HanapBahay Support');
        $mail->addCustomHeader('X-Mailer', 'HanapBahay Verification Mailer');
        $mail->addCustomHeader('X-Priority', '3');

        // Recipient
        $mail->addAddress($toEmail, $first_name);

        // Verification link
        global $APP_CONFIG;
        $verificationLink = $APP_CONFIG['app_url'] . "/verify_email.php?token=" . urlencode($verification_token);

        // Email content
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);
        $safeFirst = htmlspecialchars($first_name ?? '', ENT_QUOTES, 'UTF-8');
        $mail->Subject = 'HanapBahay Email Verification';
        $mail->Body    = "
            <p>Hi <strong>{$safeFirst}</strong>,</p>
            <p>Thank you for registering with <strong>HanapBahay</strong>!</p>
            <p>Please verify your email address by clicking the link below:</p>
            <p><a href='{$verificationLink}'>{$verificationLink}</a></p>
            <br>
            <p>If you did not register, you can safely ignore this message.</p>
        ";
        $mail->AltBody = "Verify your email: {$verificationLink}";

        // Try send on 587 first
        try {
            if ($mail->send()) {
                return true;
            }
        } catch (Exception $e587) {
            // Fallback to SMTPS:465
            $mail->SMTPDebug  = 2;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            try {
                if ($mail->send()) {
                    return true;
                }
            } catch (Exception $e465) {}
            throw $e587;
        }

        return true;
    } catch (Exception $e) {
        @error_log("PHPMailer ErrorInfo: " . $mail->ErrorInfo);
        @error_log("PHPMailer Exception: " . $e->getMessage());
        return false;
    }
}
