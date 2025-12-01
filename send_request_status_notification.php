<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/Exception.php';
require_once 'PHPMailer/src/SMTP.php';

/**
 * Send rental request status notification email to tenant
 *
 * @param string $tenantEmail Tenant's email address
 * @param string $tenantName Tenant's name
 * @param string $ownerName Owner's name
 * @param string $propertyTitle Property title
 * @param float $amountDue Amount to be paid
 * @param string $status Status: 'approved' or 'rejected'
 * @param int $requestId Rental request ID
 * @return bool True on success, false on failure
 */
function sendRequestStatusNotification($tenantEmail, $tenantName, $ownerName, $propertyTitle, $amountDue, $status, $requestId) {
    $mail = new PHPMailer(true);

    // Debug to file
    $mail->SMTPDebug  = 2;
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
        $mail->addAddress($tenantEmail, $tenantName);

        // Email content
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);

        $safeTenantName = htmlspecialchars($tenantName ?? '', ENT_QUOTES, 'UTF-8');
        $safeOwnerName = htmlspecialchars($ownerName ?? '', ENT_QUOTES, 'UTF-8');
        $safePropertyTitle = htmlspecialchars($propertyTitle ?? '', ENT_QUOTES, 'UTF-8');

        if ($status === 'approved') {
            $mail->Subject = 'Rental Request Approved - ' . $safePropertyTitle;
            $statusColor = '#28a745';
            $statusIcon = '✓';
            $statusMessage = 'Your rental request has been <strong>approved</strong>!';
            $nextSteps = '
                <div style="background: #e7f5ec; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0;">
                    <h4 style="color: #28a745; margin-top: 0;">Next Steps:</h4>
                    <ol style="margin: 10px 0; padding-left: 20px;">
                        <li>Contact the property owner to arrange viewing/move-in</li>
                        <li>Prepare payment: ₱' . number_format($amountDue, 2) . '</li>
                        <li>Review and sign the rental agreement</li>
                        <li>Complete payment to secure the property</li>
                    </ol>
                </div>
            ';
        } else {
            $mail->Subject = 'Rental Request Update - ' . $safePropertyTitle;
            $statusColor = '#dc3545';
            $statusIcon = '✗';
            $statusMessage = 'Unfortunately, your rental request has been <strong>declined</strong>.';
            $nextSteps = '
                <div style="background: #f8d7da; border-left: 4px solid #dc3545; padding: 15px; margin: 20px 0;">
                    <p style="margin: 0; color: #721c24;">
                        Don\'t worry! There are many other great properties available on HanapBahay.
                        <br>Continue browsing to find your perfect home.
                    </p>
                </div>
            ';
        }

        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <div style='background: " . $statusColor . "; color: white; padding: 20px; text-align: center;'>
                    <h1 style='margin: 0; font-size: 2.5rem;'>" . $statusIcon . "</h1>
                    <h2 style='margin: 10px 0 0 0;'>Rental Request Update</h2>
                </div>

                <div style='padding: 30px; background: white;'>
                    <p>Hi <strong>{$safeTenantName}</strong>,</p>
                    <p>" . $statusMessage . "</p>

                    <div style='background: #f7f7f7; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                        <table style='width: 100%; border-collapse: collapse;'>
                            <tr>
                                <td style='padding: 8px 0;'><strong>Property:</strong></td>
                                <td style='padding: 8px 0;'>{$safePropertyTitle}</td>
                            </tr>
                            <tr>
                                <td style='padding: 8px 0;'><strong>Property Owner:</strong></td>
                                <td style='padding: 8px 0;'>{$safeOwnerName}</td>
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

                    " . $nextSteps . "

                    <p style='text-align: center; margin: 25px 0;'>
                        <a href='https://hanapbahay.online/rental_request'
                           style='background: #8B4513; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;'>
                            View My Requests
                        </a>
                    </p>
                </div>

                <div style='background: #f7f7f7; padding: 20px; text-align: center;'>
                    <p style='color: #666; font-size: 12px; margin: 0;'>
                        This is an automated notification from HanapBahay.<br>
                        For questions, please contact the property owner directly.
                    </p>
                </div>
            </div>
        ";

        $mail->AltBody = "Your rental request for {$propertyTitle} has been " . ($status === 'approved' ? 'approved' : 'rejected') . ". Amount: ₱" . number_format($amountDue, 2) . ". Request ID: #{$requestId}. Login to your dashboard to view details.";

        if ($mail->send()) {
            return true;
        }

        return false;
    } catch (Exception $e) {
        @error_log("Request Status Notification Error: " . $mail->ErrorInfo);
        @error_log("Exception: " . $e->getMessage());
        return false;
    }
}
