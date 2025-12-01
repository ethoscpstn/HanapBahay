<?php
/**
 * Rate Limiting Implementation for Login
 * Add this to your login_process.php file
 */

// Add rate limiting to login attempts
require_once 'includes/rate_limiter.php';

function applyLoginRateLimit() {
    $client_ip = getClientIP();
    $rate_limit_key = "login:$client_ip";
    
    // Allow 5 login attempts per 15 minutes
    if (!rateLimit($rate_limit_key, 5, 900)) {
        // Rate limit exceeded
        http_response_code(429);
        die(json_encode([
            'success' => false,
            'message' => 'Too many login attempts. Please try again in 15 minutes.',
            'retry_after' => 900
        ]));
    }
}

// Usage in login_process.php:
// 1. Add this at the top: require_once 'includes/login_rate_limit.php';
// 2. Add this before processing login: applyLoginRateLimit();

?>





