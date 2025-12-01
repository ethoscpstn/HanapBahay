<?php
/**
 * Robust Email Sending Function with Multiple Fallbacks
 *
 * This function tries multiple methods to send email:
 * 1. Gmail with primary app password
 * 2. Gmail with environment variable password
 * 3. Mailtrap (for development/testing)
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/Exception.php';
require_once 'PHPMailer/src/SMTP.php';

function sendRobustEmail($toEmail, $toName, $subject, $bodyHtml, $bodyText = '', $debug = false) {
    $configurations = [
        // Config 1: Gmail with hardcoded password (no spaces)
        [
            'name' => 'Gmail (Primary)',
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'encryption' => PHPMailer::ENCRYPTION_STARTTLS,
            'username' => 'ethos.cpston@gmail.com',
            'password' => 'ytcvclkzeflxytmw',
            'from_email' => 'ethos.cpston@gmail.com',
            'from_name' => 'HanapBahay'
        ],
        // Config 2: Gmail with environment variable
        [
            'name' => 'Gmail (Environment)',
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'encryption' => PHPMailer::ENCRYPTION_STARTTLS,
            'username' => 'ethos.cpston@gmail.com',
            'password' => getenv('GMAIL_APP_PASSWORD') ?: 'ytcvclkzeflxytmw',
            'from_email' => 'ethos.cpston@gmail.com',
            'from_name' => 'HanapBahay'
        ],
        // Config 3: Gmail with SSL (port 465)
        [
            'name' => 'Gmail (SSL)',
            'host' => 'smtp.gmail.com',
            'port' => 465,
            'encryption' => PHPMailer::ENCRYPTION_SMTPS,
            'username' => 'ethos.cpston@gmail.com',
            'password' => 'ytcvclkzeflxytmw',
            'from_email' => 'ethos.cpston@gmail.com',
            'from_name' => 'HanapBahay'
        ],
        // Config 4: Mailtrap (always works for testing)
        [
            'name' => 'Mailtrap',
            'host' => 'sandbox.smtp.mailtrap.io',
            'port' => 2525,
            'encryption' => PHPMailer::ENCRYPTION_STARTTLS,
            'username' => 'c1e13c8b555518',
            'password' => '643ad50f0f1141',
            'from_email' => 'noreply@hanapbahay.local',
            'from_name' => 'HanapBahay (Dev)'
        ]
    ];

    foreach ($configurations as $config) {
        if ($debug) {
            echo "Trying: {$config['name']}...\n";
        }

        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->SMTPDebug = $debug ? 2 : 0;
            if ($debug) {
                $mail->Debugoutput = 'html';
            } else {
                $mail->Debugoutput = function($str) {
                    @file_put_contents(__DIR__ . '/mail_debug.log', '['.date('c')."] $str\n", FILE_APPEND);
                };
            }

            $mail->isSMTP();
            $mail->Host       = $config['host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $config['username'];
            $mail->Password   = $config['password'];
            $mail->SMTPSecure = $config['encryption'];
            $mail->Port       = $config['port'];
            $mail->Timeout    = 10;

            // Recipients
            $mail->setFrom($config['from_email'], $config['from_name']);
            $mail->addAddress($toEmail, $toName);

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $bodyHtml;
            $mail->AltBody = $bodyText ?: strip_tags($bodyHtml);

            $mail->send();

            if ($debug) {
                echo "✅ SUCCESS with {$config['name']}!\n";
            }

            // Log successful config for future reference
            @file_put_contents(__DIR__ . '/last_working_config.log',
                date('c') . " - SUCCESS: {$config['name']}\n", FILE_APPEND);

            return [
                'success' => true,
                'method' => $config['name'],
                'message' => 'Email sent successfully'
            ];

        } catch (Exception $e) {
            if ($debug) {
                echo "❌ Failed with {$config['name']}: {$e->getMessage()}\n\n";
            }
            @file_put_contents(__DIR__ . '/mail_error.log',
                date('c') . " - FAILED {$config['name']}: {$e->getMessage()}\n", FILE_APPEND);
            continue;
        }
    }

    // All methods failed
    return [
        'success' => false,
        'method' => 'none',
        'message' => 'All email sending methods failed. Check logs.'
    ];
}

// Wrapper for verification emails
function sendVerificationEmailRobust($toEmail, $firstName, $verificationToken) {
    $verificationLink = "https://hanapbahay.online/verify_email.php?token=" . urlencode($verificationToken);

    $bodyHtml = "
        <p>Hi <strong>" . htmlspecialchars($firstName) . "</strong>,</p>
        <p>Thank you for registering with <strong>HanapBahay</strong>!</p>
        <p>Please verify your email address by clicking the link below:</p>
        <p><a href='{$verificationLink}'>{$verificationLink}</a></p>
        <br>
        <p>If you did not register, you can safely ignore this message.</p>
    ";

    $bodyText = "Verify your email: {$verificationLink}";

    return sendRobustEmail(
        $toEmail,
        $firstName,
        'HanapBahay Email Verification',
        $bodyHtml,
        $bodyText
    );
}

// Test function
if (isset($_GET['test'])) {
    $result = sendRobustEmail(
        'ethos.cpston@gmail.com',
        'Test User',
        'Test Email - ' . date('Y-m-d H:i:s'),
        '<h3>Test Email</h3><p>This email was sent using the robust email function.</p>',
        'Test Email',
        true  // Enable debug
    );

    echo "<hr>";
    echo "<h3>Result:</h3>";
    echo "<pre>";
    print_r($result);
    echo "</pre>";
}
?>
