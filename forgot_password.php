<?php
session_start();
require 'mysql_connect.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);

    $stmt = $conn->prepare("SELECT id FROM tbadmin WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $code = strval(rand(100000, 999999));
        $expiry = date("Y-m-d H:i:s", strtotime("+15 minutes"));

        // Store code in DB
        $stmt = $conn->prepare("UPDATE tbadmin SET reset_code = ?, reset_expiry = ? WHERE id = ?");
        $stmt->bind_param("ssi", $code, $expiry, $user['id']);
        $stmt->execute();

        // Store email in session for next step
        $_SESSION['reset_email'] = $email;

        // Send email
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'ethos.cpstn@gmail.com';
            $mail->Password = 'ntwhcojthfgakjxr';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            $mail->setFrom('ethos.cpstn@gmail.com', 'HanapBahay');
            $mail->addAddress($email);
            $mail->Subject = 'HanapBahay Password Reset Code';
            $mail->Body = "Your password reset code is: $code\nThis code expires in 15 minutes.";

            $mail->send();
            header("Location: verify_reset_code.php");
            exit();
        } catch (Exception $e) {
            $error = "Failed to send email. Try again.";
        }
    } else {
        $error = "Email not found.";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Forgot Password | HanapBahay</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .forgot-box {
            max-width: 480px;
            margin: 80px auto;
            padding: 30px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
        }
        .toggle-icon {
            position: absolute;
            top: 50%;
            right: 15px;
            transform: translateY(-50%);
            width: 24px;
            height: auto;
            cursor: pointer;
            z-index: 5;
        }
    </style>
</head>
<body>
<div class="container forgot-box text-center">
    <h3 class="mb-4">🔑 Forgot Password</h3>
    <p>Enter your email and we'll send you a reset link.</p>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php elseif ($error): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="mb-3 text-start position-relative">
            <label for="email" class="form-label">Email address</label>
            <input type="email" class="form-control" name="email" id="email" required>
        </div>
        <div class="d-grid">
            <button type="submit" class="btn btn-primary" style="background-color:#ff914d; border:none;">Send Reset Link</button>
        </div>
    </form>

    <div class="mt-3">
        <a href="LoginModule.php" class="btn btn-link">← Back to Login</a>
    </div>
</div>

<script>
function togglePassword(id, el) {
    const input = document.getElementById(id);
    const isVisible = input.type === "text";
    input.type = isVisible ? "password" : "text";
    el.src = isVisible ? "Assets/hide.png" : "Assets/show.png";
}
</script>
</body>
</html>
