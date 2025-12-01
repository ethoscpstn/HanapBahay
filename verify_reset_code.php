<?php
session_start();
require 'mysql_connect.php';

if (!isset($_SESSION['reset_email'])) {
    header("Location: forgot_password.php");
    exit();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_SESSION['reset_email'];
    $code = trim($_POST['code']);

    $stmt = $conn->prepare("SELECT id, reset_expiry FROM tbadmin WHERE email = ? AND reset_code = ?");
    $stmt->bind_param("ss", $email, $code);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();
        if (strtotime($user['reset_expiry']) > time()) {
            $_SESSION['reset_verified'] = true;
            header("Location: reset_password.php");
            exit();
        } else {
            $error = "Code expired. Please try again.";
        }
    } else {
        $error = "Invalid code.";
    }
}
?>

<!-- HTML -->
<!DOCTYPE html>
<html>
<head>
    <title>Verify Reset Code | HanapBahay</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5" style="max-width: 480px;">
    <h3 class="mb-4">📧 Enter the Code Sent to Your Email</h3>
    <?php if ($error) echo "<div class='alert alert-danger'>$error</div>"; ?>
    <form method="POST">
        <div class="mb-3">
            <label>6-digit Code</label>
            <input type="text" name="code" class="form-control" maxlength="6" required>
        </div>
        <div class="d-grid">
            <button class="btn btn-primary">Verify Code</button>
        </div>
    </form>
</div>
</body>
</html>
