<?php
session_start();
require_once 'mysql_connect.php';
require_once 'app_config.php';

// If user is not pending verification, redirect to login
if (!isset($_SESSION['verification_required'])) {
    header("Location: LoginModule.php");
    exit();
}

$message = '';
$message_type = '';

// Handle resend verification
if (isset($_POST['resend'])) {
    if (isset($_SESSION['pending_email'])) {
        $email = $_SESSION['pending_email'];
        
        // Generate new token
        $new_token = bin2hex(random_bytes(16));
        
        $stmt = $conn->prepare("UPDATE tbadmin SET verification_token = ? WHERE email = ?");
        $stmt->bind_param("ss", $new_token, $email);
        
        if ($stmt->execute()) {
            require_once 'send_verification_email.php';
            if (sendVerificationEmail($email, $_SESSION['pending_username'], $new_token)) {
                $message = "Verification email has been resent. Please check your inbox and spam folder.";
                $message_type = "success";
            } else {
                $message = "Failed to send verification email. Please try again.";
                $message_type = "danger";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification Required - <?php echo $APP_CONFIG['app_name']; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow">
                    <div class="card-body text-center p-5">
                        <h3 class="mb-4">Email Verification Required</h3>
                        <p class="mb-4">
                            To access this feature, please verify your email address. 
                            We sent a verification link to <strong><?php echo htmlspecialchars($_SESSION['pending_email']); ?></strong>
                        </p>
                        
                        <?php if ($message): ?>
                            <div class="alert alert-<?php echo $message_type; ?> mb-4">
                                <?php echo htmlspecialchars($message); ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="post" class="mb-4">
                            <button type="submit" name="resend" class="btn btn-primary">
                                Resend Verification Email
                            </button>
                        </form>
                        
                        <div class="small text-muted">
                            <p>Make sure to:</p>
                            <ul class="list-unstyled">
                                <li>Check your spam/junk folder</li>
                                <li>Add <?php echo htmlspecialchars($APP_CONFIG['app_email']); ?> to your contacts</li>
                                <li>Make sure your email address is correct</li>
                            </ul>
                        </div>
                        
                        <hr class="my-4">
                        
                        <a href="LoginModule.php" class="btn btn-outline-secondary">
                            Back to Login
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>