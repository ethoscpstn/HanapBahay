<?php
session_start();
require 'mysql_connect.php';
require 'send_verification_email.php';

if (!isset($_SESSION['pending_email']) || !isset($_SESSION['pending_username'])) {
    $_SESSION['login_error'] = "Session expired. Please register or log in again.";
    header("Location: LoginModule.php");
    exit();
}

$email = $_SESSION['pending_email'];
$username = $_SESSION['pending_username'];

// Fetch token and check if user is already verified
$stmt = $conn->prepare("SELECT verification_token, is_verified FROM tbadmin WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows === 1) {
    $user = $result->fetch_assoc();

    if ($user['is_verified']) {
        $_SESSION['login_error'] = "Your email is already verified.";
    } else {
        $token = $user['verification_token'];

        if (sendVerificationEmail($email, $username, $token)) {
            $_SESSION['login_error'] = "Verification email resent successfully. Please check your inbox (and Spam).";
        } else {
            $_SESSION['login_error'] = "We couldn't resend the verification email right now. Please try again later.";
        }
    }
} else {
    $_SESSION['login_error'] = "Could not resend email. Try registering again.";
}

header("Location: LoginModule.php");
exit();

