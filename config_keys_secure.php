<?php
/**
 * Secure Configuration File - API Keys and Sensitive Data
 * UPDATED: Now uses environment variables for security
 *
 * IMPORTANT: Add this file to .gitignore to prevent committing to version control
 * DO NOT share these keys publicly
 */

// Prevent direct access
if (!defined('HANAPBAHAY_SECURE')) {
    die('Direct access not permitted');
}

// Load environment variables
require_once __DIR__ . '/includes/env_loader.php';

// Google Maps API Key - Now from environment
define('GOOGLE_MAPS_API_KEY', EnvLoader::get('GOOGLE_MAPS_API_KEY', ''));

// ML API Configuration - Now from environment
if (!defined('ML_BASE')) {
    define('ML_BASE', EnvLoader::get('ML_BASE_URL', 'https://hanapbahay-ml.onrender.com'));
}
if (!defined('ML_KEY')) {
    define('ML_KEY', EnvLoader::get('ML_API_KEY', 'hanapbahay_ml_prod_secure_2024'));
}

// Database Configuration - Now from environment
define('DB_HOST', EnvLoader::get('DB_HOST', 'localhost'));
define('DB_USER', EnvLoader::get('DB_USER', 'u412552698_dbhanapbahay'));
define('DB_PASS', EnvLoader::get('DB_PASS', ''));
define('DB_NAME', EnvLoader::get('DB_NAME', 'u412552698_dbhanapbahay'));

// Security Settings - Now from environment
define('CSRF_TOKEN_LENGTH', EnvLoader::get('CSRF_TOKEN_LENGTH', 32));
define('SESSION_LIFETIME', EnvLoader::get('SESSION_LIFETIME', 3600 * 24)); // 24 hours
define('MAX_LOGIN_ATTEMPTS', EnvLoader::get('MAX_LOGIN_ATTEMPTS', 5));

// File Upload Settings - Now from environment
define('MAX_PHOTO_SIZE', EnvLoader::get('MAX_PHOTO_SIZE', 5 * 1024 * 1024)); // 5MB
define('MAX_GOV_ID_SIZE', EnvLoader::get('MAX_GOV_ID_SIZE', 10 * 1024 * 1024)); // 10MB
define('ALLOWED_PHOTO_TYPES', ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
define('ALLOWED_GOV_ID_TYPES', ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf']);

// Email Configuration - Now from environment
define('SMTP_HOST', EnvLoader::get('SMTP_HOST', 'smtp.gmail.com'));
define('SMTP_PORT', EnvLoader::get('SMTP_PORT', 587));
define('SMTP_USERNAME', EnvLoader::get('SMTP_USERNAME', ''));
define('SMTP_PASSWORD', EnvLoader::get('SMTP_PASSWORD', ''));
define('SMTP_FROM_EMAIL', EnvLoader::get('SMTP_FROM_EMAIL', 'noreply@hanapbahay.com'));
define('SMTP_FROM_NAME', EnvLoader::get('SMTP_FROM_NAME', 'HanapBahay'));

?>





