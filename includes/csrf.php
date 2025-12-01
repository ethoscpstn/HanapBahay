<?php
/**
 * CSRF Protection Utilities
 *
 * Usage:
 * 1. In forms: echo csrf_field();
 * 2. In form handlers: csrf_verify();
 */

/**
 * Generate CSRF token and store in session
 * @return string The generated token
 */
function csrf_token() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Generate HTML hidden input field with CSRF token
 * @return string HTML input field
 */
function csrf_field() {
    $token = csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}

/**
 * Verify CSRF token from POST request
 * @param bool $die Whether to die on failure (default: true)
 * @return bool True if valid, false/dies if invalid
 */
function csrf_verify($die = true) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $token = $_POST['csrf_token'] ?? '';
    $session_token = $_SESSION['csrf_token'] ?? '';

    if (empty($token) || empty($session_token) || !hash_equals($session_token, $token)) {
        if ($die) {
            http_response_code(403);
            die('CSRF token validation failed. Please refresh the page and try again.');
        }
        return false;
    }

    return true;
}

/**
 * Regenerate CSRF token (call after successful form submission)
 */
function csrf_regenerate() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

?>
