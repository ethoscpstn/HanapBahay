<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/Exception.php';
require_once 'PHPMailer/src/SMTP.php';

/**
 * Send rental request notification email to property owner
 *
 * @param string $ownerEmail Owner's email address
 * @param string $ownerName Owner's name
 * @param string $tenantName Tenant's name
 * @param string $propertyTitle Property title
 * @param float $amountDue Amount to be paid
 * @param string $paymentMethod Payment method (half/full)
 * @param int $requestId Rental request ID
 * @return bool True on success, false on failure
 */
function sendRentalRequestNotification($ownerEmail, $ownerName, $tenantName, $propertyTitle, $amountDue, $paymentMethod, $requestId) {
    $mail = new PHPMailer(true);

    // Debug to file (disabled for production to prevent JSON corruption)
    $mail->SMTPDebug  = 0;
    $mail->Debugoutput = function($str, $level) {
        @file_put_contents(__DIR__ . '/mail_debug.log', '['.date('c')."] $str\n", FILE_APPEND);
    };

    try {
        // SMTP configuration
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'ethos.cpstn@gmail.com';
        $mail->Password   = 'ntwhcojthfgakjxr';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Sender
        $mail->setFrom('ethos.cpstn@gmail.com', 'HanapBahay');
        $mail->addReplyTo('eysie2@gmail.com', 'HanapBahay Support');

        // Recipient
        $mail->addAddress($ownerEmail, $ownerName);

        // Email content
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);

        $safeOwnerName = htmlspecialchars($ownerName ?? '', ENT_QUOTES, 'UTF-8');
        $safeTenantName = htmlspecialchars($tenantName ?? '', ENT_QUOTES, 'UTF-8');
        $safePropertyTitle = htmlspecialchars($propertyTitle ?? '', ENT_QUOTES, 'UTF-8');
        $paymentLabel = $paymentMethod === 'half' ? 'Half Payment (50%)' : 'Full Payment (100%)';

        $mail->Subject = 'New Rental Request for ' . $safePropertyTitle;
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <h2 style='color: #8B4513;'>New Rental Request</h2>
                <p>Hi <strong>{$safeOwnerName}</strong>,</p>
                <p>You have received a new rental request for your property:</p>

                <div style='background: #f7f7f7; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                    <table style='width: 100%; border-collapse: collapse;'>
                        <tr>
                            <td style='padding: 8px 0;'><strong>Property:</strong></td>
                            <td style='padding: 8px 0;'>{$safePropertyTitle}</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0;'><strong>Tenant:</strong></td>
                            <td style='padding: 8px 0;'>{$safeTenantName}</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0;'><strong>Payment Method:</strong></td>
                            <td style='padding: 8px 0;'>{$paymentLabel}</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0;'><strong>Amount Due:</strong></td>
                            <td style='padding: 8px 0; color: #28a745; font-size: 18px;'><strong>₱" . number_format($amountDue, 2) . "</strong></td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0;'><strong>Request ID:</strong></td>
                            <td style='padding: 8px 0;'>#" . $requestId . "</td>
                        </tr>
                    </table>
                </div>

                <p>Please log in to your dashboard to review and respond to this request:</p>
                <p style='text-align: center; margin: 25px 0;'>
                    <a href='https://hanapbahay.online/rental_requests_uo'
                       style='background: #8B4513; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;'>
                        View Rental Requests
                    </a>
                </p>

                <hr style='border: none; border-top: 1px solid #ddd; margin: 30px 0;'>
                <p style='color: #666; font-size: 12px;'>
                    This is an automated notification from HanapBahay. Please do not reply to this email.
                </p>
            </div>
        ";

        $mail->AltBody = "New rental request from {$tenantName} for {$propertyTitle}. Amount: ₱" . number_format($amountDue, 2) . ". Request ID: #{$requestId}. Login to your dashboard to view details.";

        if ($mail->send()) {
            return true;
        }

        return false;
    } catch (Exception $e) {
        @error_log("Rental Notification Error: " . $mail->ErrorInfo);
        @error_log("Exception: " . $e->getMessage());
        return false;
    }
}
