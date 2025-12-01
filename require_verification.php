<?php
/**
 * Middleware to enforce email verification
 * Include this file at the top of any page that requires verified email
 */

session_start();

function requireVerifiedEmail() {
    global $conn;
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['login_error'] = "Please log in to access this page.";
        header("Location: LoginModule.php");
        exit();
    }

    // Check if user's email is verified
    $stmt = $conn->prepare("SELECT is_verified, email FROM tbadmin WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if (!$user['is_verified']) {
        $_SESSION['pending_email'] = $user['email'];
        $_SESSION['pending_username'] = $_SESSION['username'] ?? '';
        $_SESSION['verification_required'] = true;
        $_SESSION['return_url'] = $_SERVER['REQUEST_URI']; // Store current URL to return after verification
        
        header("Location: verify_required.php");
        exit();
    }
}

// Connect to database if not already connected
if (!isset($conn)) {
    require_once 'mysql_connect.php';
}

// Call the function
requireVerifiedEmail();