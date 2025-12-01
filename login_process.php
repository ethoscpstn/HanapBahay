<?php
// login_process.php
ini_set('display_errors', 0); 
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__.'/php-error.log');

session_start();
require 'mysql_connect.php';

require_once 'includes/auth_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT id, email, password, role, first_name, last_name 
                            FROM tbadmin WHERE email=? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        $stored = $user['password'];
        $ok = false;

        // Check if password looks like a password_hash()
        $info = password_get_info($stored);
        if (!empty($info['algo'])) {
            // Modern hash
            $ok = password_verify($password, $stored);
        } else {
            // Legacy MD5
            if (hash_equals($stored, md5($password))) {
                $ok = true;
                // Auto-upgrade to password_hash()
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $up = $conn->prepare("UPDATE tbadmin SET password=? WHERE id=?");
                $up->bind_param("si", $newHash, $user['id']);
                $up->execute();
                $up->close();
            }
        }

        if ($ok) {
            // For local development, skip email verification
            $isLocal = (
                (isset($_SERVER['SERVER_NAME']) && $_SERVER['SERVER_NAME'] === 'localhost') ||
                (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] === 'localhost') ||
                (isset($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] === '127.0.0.1') ||
                (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false) ||
                (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false)
            );
            
            if ($isLocal) {
                // Direct login for local development
                $_SESSION['user_id']    = (int)$user['id'];
                $_SESSION['first_name'] = $user['first_name'] ?? '';
                $_SESSION['last_name']  = $user['last_name'] ?? '';
                $_SESSION['role']       = $user['role'] ?? 'tenant';

                if ($_SESSION['role'] === 'unit_owner') {
                    $_SESSION['owner_id'] = (int)$user['id'];
                } else {
                    unset($_SESSION['owner_id']);
                }

                // Redirect by role
                if ($_SESSION['role'] === 'admin') {
                    header("Location: admin_listings.php");
                } elseif ($_SESSION['role'] === 'unit_owner') {
                    header("Location: DashboardUO.php");
                } else {
                    header("Location: DashboardT.php");
                }
                exit();
            } else {
                // Production: Store pending info only (2FA not yet verified)
                $_SESSION['pending_user_id'] = $user['id'];
                $_SESSION['pending_role']    = $user['role'];
                $_SESSION['pending_email']   = $user['email'];

                $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                hb_send_login_code($conn, (int)$user['id'], $user['email'], $name);

                header("Location: verify_code.php");
                exit();
            }
        } else {
            $_SESSION['login_error'] = "Incorrect password.";
        }
    } else {
        $_SESSION['login_error'] = "Account not found.";
    }

    header("Location: LoginModule.php");
    exit();
} else {
    // If accessed directly (not POST), redirect to login
    header("Location: LoginModule.php");
    exit();
}
