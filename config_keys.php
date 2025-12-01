<?php
/**
 * Secure Configuration File - API Keys and Sensitive Data
 *
 * IMPORTANT: Add this file to .gitignore to prevent committing to version control
 * DO NOT share these keys publicly
 */

// Prevent direct access
if (!defined('HANAPBAHAY_SECURE')) {
    die('Direct access not permitted');
}

// Google Maps API Key
define('GOOGLE_MAPS_API_KEY', 'AIzaSyCrKcnAX9KOdNp_TNHwWwzbLSQodgYqgnU');

// ML API Configuration (already in includes/config.php)
// Keeping it here for centralization
if (!defined('ML_BASE')) {
    define('ML_BASE', 'https://revolvingly-uncombining-genia.ngrok-free.dev');
}
if (!defined('ML_KEY')) {
    define('ML_KEY', 'hanapbahay_ml_secure_2024_permanent_key_v1');
}

// Database Configuration (for reference, actual connection in mysql_connect.php)
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'u412552698_dbhanapbahay');

// Security Settings
define('CSRF_TOKEN_LENGTH', 32);
define('SESSION_LIFETIME', 3600 * 24); // 24 hours
define('MAX_LOGIN_ATTEMPTS', 5);

// File Upload Settings
define('MAX_PHOTO_SIZE', 5 * 1024 * 1024); // 5MB
define('MAX_GOV_ID_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_PHOTO_TYPES', ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
define('ALLOWED_GOV_ID_TYPES', ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf']);

// Email Configuration
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your-email@gmail.com'); // Update this
define('SMTP_PASSWORD', 'your-app-password'); // Update this
define('SMTP_FROM_EMAIL', 'noreply@hanapbahay.com');
define('SMTP_FROM_NAME', 'HanapBahay');

?>
