<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


session_start();
require 'mysql_connect.php';
require 'app_config.php';
require 'send_verification_email.php';
require 'smtp_email_verify.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'] ?? '';
    $role = $_POST['role'] ?? '';

    $errors = [];

    // === Name validation ===
    if (!preg_match("/^[a-zA-Z ]+$/", $first_name)) $errors[] = "First name must contain letters and spaces only.";
    if (!preg_match("/^[a-zA-Z ]+$/", $last_name)) $errors[] = "Last name must contain letters and spaces only.";

    // === Password validation ===
    if (strlen($password) < 8) $errors[] = "Password must be at least 8 characters.";
    if (!preg_match('/[A-Z]/', $password)) $errors[] = "Include at least 1 uppercase letter.";
    if (!preg_match('/[a-z]/', $password)) $errors[] = "Include at least 1 lowercase letter.";
    if (!preg_match('/\\d/', $password)) $errors[] = "Include at least 1 number.";
    if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) $errors[] = "Include at least 1 special character.";
    if ($password !== $confirm_password) $errors[] = "Passwords do not match.";

    // === Email & Role validation ===
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format.";
    if (!in_array($role, ['tenant', 'unit_owner'])) $errors[] = "Invalid role selected.";

    // === Duplicate email check ===
    $checkStmt = $conn->prepare("SELECT id FROM tbadmin WHERE email = ?");
    $checkStmt->bind_param("s", $email);
    $checkStmt->execute();
    $checkStmt->store_result();
    if ($checkStmt->num_rows > 0) $errors[] = "Email already registered.";
    $checkStmt->close();

    // === SMTP MX check (optional)
    if (empty($errors) && !smtpCheckEmail($email)) {
        $errors[] = "Email domain is unreachable or invalid.";
    }

    // === Return to form if errors ===
    if (!empty($errors)) {
        $_SESSION['reg_errors'] = $errors;
        header("Location: LoginModule.php");
        exit();
    }

    // === Final insert ===
    $verification_token = bin2hex(random_bytes(16));
    $hashed_password = password_hash($password, PASSWORD_BCRYPT);
    $is_verified = 0;

    $stmt = $conn->prepare("INSERT INTO tbadmin (first_name, last_name, email, password, role, verification_token, is_verified) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssi", $first_name, $last_name, $email, $hashed_password, $role, $verification_token, $is_verified);
    $stmt->execute();
    $stmt->close();

    if (sendVerificationEmail($email, $first_name, $verification_token)) {
    $_SESSION['success_flash'] = "Registration successful! Please check your email (and Spam) to verify your account.";
} else {
    $_SESSION['error_flash'] = "We couldn't send your verification email right now. Please try again in a few minutes or contact support.";
}
header("Location: LoginModule.php");
    exit();
} else {
    // If accessed directly (not POST), redirect to login
    header("Location: LoginModule.php");
    exit();
}
