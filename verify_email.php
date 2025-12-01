<?php
session_start();
require 'mysql_connect.php';
require 'app_config.php';

$status = array(
    'verified' => false,
    'message' => '',
    'type' => 'error'
);

if (!isset($_GET['token']) || empty($_GET['token'])) {
    $status['message'] = "Invalid verification link.";
} else {
    $token = $_GET['token'];
    
    // Check for token expiration and validate user
    $stmt = $conn->prepare("
        SELECT id, first_name, email, created_at 
        FROM tbadmin 
        WHERE verification_token = ? AND is_verified = 0
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $created_timestamp = strtotime($user['created_at']);
        $current_timestamp = time();
        
        // Check if token is expired (24 hours)
        if (($current_timestamp - $created_timestamp) > 86400) {
            $status['message'] = "Verification link has expired. Please request a new one.";
            // Generate new token and send email
            $new_token = bin2hex(random_bytes(16));
            $update = $conn->prepare("UPDATE tbadmin SET verification_token = ? WHERE id = ?");
            $update->bind_param("si", $new_token, $user['id']);
            $update->execute();
            
            require_once 'send_verification_email.php';
            sendVerificationEmail($user['email'], $user['first_name'], $new_token);
        } else {
            // Mark account as verified
            $update = $conn->prepare("UPDATE tbadmin SET is_verified = 1, verification_token = NULL WHERE id = ?");
            $update->bind_param("i", $user['id']);
            
            if ($update->execute()) {
                $status['verified'] = true;
                $status['type'] = 'success';
                $status['message'] = "Your email has been successfully verified! You can now log in.";
                
                // Set session variables if user was waiting for verification
                if (isset($_SESSION['pending_email']) && $_SESSION['pending_email'] === $user['email']) {
                    unset($_SESSION['pending_email']);
                    unset($_SESSION['pending_username']);
                }
            } else {
                $status['message'] = "An error occurred while verifying your email. Please try again.";
            }
        }
    } else {
        $status['message'] = "Invalid or already used verification link.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Email Verified</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body {
      display: flex;
      justify-content: center;
      align-items: center;
      height: 100vh;
      background: #f7f7f7;
    }
    .message-box {
      text-align: center;
      background: white;
      padding: 30px;
      border-radius: 12px;
      box-shadow: 0 10px 20px rgba(0,0,0,0.1);
    }
    .btn-orange {
      background-color: #ff914d;
      border: none;
      color: white;
    }
  </style>
</head>
<body>
  <div class="message-box">
    <h4>
      <?= $verified ? "✅ Your email has been verified." : "⚠️ Invalid or expired link." ?>
    </h4>
    <p class="mb-4">You may now proceed to login.</p>
    <a href="LoginModule.php" class="btn btn-orange px-4">Go to Login</a>
  </div>
</body>
</html>
