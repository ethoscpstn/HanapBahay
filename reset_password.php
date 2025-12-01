<?php
session_start();
require 'mysql_connect.php';

if (!isset($_SESSION['reset_email']) || !isset($_SESSION['reset_verified'])) {
    header("Location: forgot_password.php");
    exit();
}

$error = '';
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_SESSION['reset_email'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // Password validation - same as registration
    if (strlen($new_password) < 8) {
        $error = "Password must be at least 8 characters.";
    } elseif (!preg_match('/[A-Z]/', $new_password)) {
        $error = "Password must include at least 1 uppercase letter.";
    } elseif (!preg_match('/[a-z]/', $new_password)) {
        $error = "Password must include at least 1 lowercase letter.";
    } elseif (!preg_match('/\\d/', $new_password)) {
        $error = "Password must include at least 1 number.";
    } elseif (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $new_password)) {
        $error = "Password must include at least 1 special character (!@#$%^&* etc).";
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        $hashed = password_hash($new_password, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("UPDATE tbadmin SET password = ?, reset_code = NULL, reset_expiry = NULL WHERE email = ?");
        $stmt->bind_param("ss", $hashed, $email);
        if ($stmt->execute()) {
            $success = true;
            session_unset();
            session_destroy();
        } else {
            $error = "Failed to update password.";
        }
    }
}
?>

<!-- HTML -->
<!DOCTYPE html>
<html>
<head>
    <title>Reset Password | HanapBahay</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5" style="max-width: 480px;">
    <h3 class="text-center mb-4">🔐 Reset Your Password</h3>
    <?php if ($success): ?>
        <div class="alert alert-success text-center">
            Password changed successfully. <a href="LoginModule.php">Login</a>
        </div>
    <?php elseif ($error): ?>
        <div class="alert alert-danger text-center"><?= $error ?></div>
    <?php endif; ?>

    <?php if (!$success): ?>
    <form method="POST">
        <div class="mb-3">
            <label>New Password</label>
            <input type="password" name="new_password" class="form-control" required>
            <small class="text-muted">
                Password must be at least 8 characters with 1 uppercase, 1 lowercase, 1 number, and 1 special character.
            </small>
        </div>
        <div class="mb-3">
            <label>Confirm New Password</label>
            <input type="password" name="confirm_password" class="form-control" required>
        </div>
        <div class="d-grid">
            <button class="btn btn-warning">Reset Password</button>
        </div>
    </form>
    <?php endif; ?>
</div>
</body>
</html>
