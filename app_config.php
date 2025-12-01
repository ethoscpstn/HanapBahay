<?php
/**
 * Global application configuration settings
 */

// Base URL configuration - change this based on environment
$APP_CONFIG = [
    'app_url' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' 
                 ? 'https://' 
                 : 'http://' . $_SERVER['HTTP_HOST'],
    'app_name' => 'HanapBahay',
    'app_email' => 'ethos.cpstn@gmail.com',
    'support_email' => 'eysie2@gmail.com',
    'smtp_host' => 'smtp.gmail.com',
    'smtp_username' => 'ethos.cpstn@gmail.com',
    'smtp_password' => 'ntwhcojthfgakjxr',
    'smtp_port' => 587
];