<?php
/**
 * Secure Configuration Manager
 * Centralized configuration with environment variable support
 */

require_once __DIR__ . '/env_loader.php';

class SecureConfig {
    
    /**
     * Get Google Maps API Key
     */
    public static function getGoogleMapsKey() {
        return EnvLoader::get('GOOGLE_MAPS_API_KEY', '');
    }
    
    /**
     * Get ML API Configuration
     */
    public static function getMLConfig() {
        return [
            'base_url' => EnvLoader::get('ML_BASE_URL', 'https://hanapbahay-ml.onrender.com'),
            'api_key' => EnvLoader::get('ML_API_KEY', 'hanapbahay_ml_prod_secure_2024'),
            'local_url' => EnvLoader::get('ML_BASE_URL_LOCAL', 'http://127.0.0.1:8000'),
            'local_key' => EnvLoader::get('ML_API_KEY_LOCAL', 'hanapbahay_ml_local_2024')
        ];
    }
    
    /**
     * Get Database Configuration
     */
    public static function getDBConfig() {
        return [
            'host' => EnvLoader::get('DB_HOST', 'localhost'),
            'user' => EnvLoader::get('DB_USER', 'root'),
            'pass' => EnvLoader::get('DB_PASS', ''),
            'name' => EnvLoader::get('DB_NAME', 'u412552698_dbhanapbahay')
        ];
    }
    
    /**
     * Get Email Configuration
     */
    public static function getEmailConfig() {
        return [
            'host' => EnvLoader::get('SMTP_HOST', 'smtp.gmail.com'),
            'port' => EnvLoader::get('SMTP_PORT', 587),
            'username' => EnvLoader::get('SMTP_USERNAME', ''),
            'password' => EnvLoader::get('SMTP_PASSWORD', ''),
            'from_email' => EnvLoader::get('SMTP_FROM_EMAIL', 'noreply@hanapbahay.com'),
            'from_name' => EnvLoader::get('SMTP_FROM_NAME', 'HanapBahay')
        ];
    }
    
    /**
     * Get Security Settings
     */
    public static function getSecurityConfig() {
        return [
            'csrf_token_length' => EnvLoader::get('CSRF_TOKEN_LENGTH', 32),
            'session_lifetime' => EnvLoader::get('SESSION_LIFETIME', 86400),
            'max_login_attempts' => EnvLoader::get('MAX_LOGIN_ATTEMPTS', 5),
            'password_min_length' => EnvLoader::get('PASSWORD_MIN_LENGTH', 8),
            'encryption_key' => EnvLoader::get('ENCRYPTION_KEY', '')
        ];
    }
    
    /**
     * Get File Upload Settings
     */
    public static function getUploadConfig() {
        return [
            'max_photo_size' => EnvLoader::get('MAX_PHOTO_SIZE', 5242880),
            'max_gov_id_size' => EnvLoader::get('MAX_GOV_ID_SIZE', 10485760),
            'allowed_photo_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
            'allowed_gov_id_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf']
        ];
    }
    
    /**
     * Get Rate Limiting Settings
     */
    public static function getRateLimitConfig() {
        return [
            'requests' => EnvLoader::get('RATE_LIMIT_REQUESTS', 100),
            'window' => EnvLoader::get('RATE_LIMIT_WINDOW', 3600)
        ];
    }
    
    /**
     * Check if running in production
     */
    public static function isProduction() {
        return EnvLoader::get('APP_ENV', 'development') === 'production';
    }
    
    /**
     * Check if debug mode is enabled
     */
    public static function isDebug() {
        return EnvLoader::get('APP_DEBUG', 'true') === 'true';
    }
}
?>
