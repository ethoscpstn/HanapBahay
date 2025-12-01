<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/Exception.php';
require_once 'PHPMailer/src/SMTP.php';

/**
 * Send verification result notification email to property owner
 *
 * @param string $ownerEmail Owner's email address
 * @param string $ownerName Owner's name
 * @param string $propertyTitle Property title
 * @param string $status 'approved' or 'rejected'
 * @param string $rejectionReason Reason for rejection (if rejected)
 * @param int $listingId Listing ID
 * @return bool True on success, false on failure
 */
function sendVerificationResultEmail($ownerEmail, $ownerName, $propertyTitle, $status, $rejectionReason = '', $listingId = 0) {
    $mail = new PHPMailer(true);

    // Debug to file (disabled for production)
    $mail->SMTPDebug  = 0;
    $mail->Debugoutput = function($str, $level) {
        @file_put_contents(__DIR__ . '/mail_debug.log', '['.date('c')."] $str\n", FILE_APPEND);
    };

    try {
        // SMTP configuration
        $mail->isSMTP();
        $mail->Timeout = 15;
        $mail->SMTPKeepAlive = false;
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
        $safePropertyTitle = htmlspecialchars($propertyTitle ?? '', ENT_QUOTES, 'UTF-8');

        if ($status === 'approved') {
            $mail->Subject = 'Property Listing Approved - ' . $safePropertyTitle;
            $statusColor = '#28a745';
            $statusIcon = '✓';
            $statusMessage = 'Your property listing has been <strong>approved</strong> and is now live!';
            $nextSteps = '
                <div style="background: #e7f5ec; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0;">
                    <h4 style="color: #28a745; margin-top: 0;">What happens now:</h4>
                    <ul style="margin: 10px 0; padding-left: 20px;">
                        <li>Your property is now visible to all tenants on HanapBahay</li>
                        <li>You will receive email notifications when tenants apply</li>
                        <li>Manage rental requests from your dashboard</li>
                    </ul>
                </div>
            ';
        } else {
            $mail->Subject = 'Property Listing Verification Update - ' . $safePropertyTitle;
            $statusColor = '#dc3545';
            $statusIcon = '✗';
            $statusMessage = 'Unfortunately, your property listing was <strong>not approved</strong>.';
            $safeRejectionReason = htmlspecialchars($rejectionReason ?? 'Not specified', ENT_QUOTES, 'UTF-8');
            $nextSteps = '
                <div style="background: #f8d7da; border-left: 4px solid #dc3545; padding: 15px; margin: 20px 0;">
                    <h4 style="color: #721c24; margin-top: 0;">Reason for rejection:</h4>
                    <p style="margin: 10px 0; color: #721c24;">' . $safeRejectionReason . '</p>
                    <h4 style="color: #721c24; margin-top: 15px;">Next steps:</h4>
                    <ul style="margin: 10px 0; padding-left: 20px; color: #721c24;">
                        <li>Review the rejection reason carefully</li>
                        <li>Correct the issues mentioned</li>
                        <li>Resubmit your listing from your dashboard</li>
                    </ul>
                </div>
            ';
        }

        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <div style='background: " . $statusColor . "; color: white; padding: 20px; text-align: center;'>
                    <h1 style='margin: 0; font-size: 2.5rem;'>" . $statusIcon . "</h1>
                    <h2 style='margin: 10px 0 0 0;'>Property Verification Update</h2>
                </div>

                <div style='padding: 30px; background: white;'>
                    <p>Hi <strong>{$safeOwnerName}</strong>,</p>
                    <p>" . $statusMessage . "</p>

                    <div style='background: #f7f7f7; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                        <table style='width: 100%; border-collapse: collapse;'>
                            <tr>
                                <td style='padding: 8px 0;'><strong>Property:</strong></td>
                                <td style='padding: 8px 0;'>{$safePropertyTitle}</td>
                            </tr>
                            <tr>
                                <td style='padding: 8px 0;'><strong>Status:</strong></td>
                                <td style='padding: 8px 0;'><span style='color: " . $statusColor . "; font-weight: bold;'>" . ucfirst($status) . "</span></td>
                            </tr>
                        </table>
                    </div>

                    " . $nextSteps . "

                    <p style='text-align: center; margin: 25px 0;'>
                        <a href='https://hanapbahay.online/DashboardUO'
                           style='background: #8B4513; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;'>
                            Go to Dashboard
                        </a>
                    </p>
                </div>

                <div style='background: #f7f7f7; padding: 20px; text-align: center;'>
                    <p style='color: #666; font-size: 12px; margin: 0;'>
                        This is an automated notification from HanapBahay.<br>
                        If you have questions, please contact support.
                    </p>
                </div>
            </div>
        ";

        $mail->AltBody = "Your property listing '{$propertyTitle}' has been " . ($status === 'approved' ? 'approved and is now live' : 'rejected. Reason: ' . $rejectionReason) . ". Login to your dashboard to view details.";

        if ($mail->send()) {
            return true;
        }

        return false;
    } catch (Exception $e) {
        @error_log("Verification Result Email Error: " . $mail->ErrorInfo);
        @error_log("Exception: " . $e->getMessage());
        return false;
    }
}
