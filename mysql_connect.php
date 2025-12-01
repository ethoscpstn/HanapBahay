<?php
// Set timezone to Philippine time for consistency
date_default_timezone_set('Asia/Manila');

// Environment detection: Check if running locally (XAMPP) or production
$isLocal = (
    (isset($_SERVER['SERVER_NAME']) && $_SERVER['SERVER_NAME'] === 'localhost') ||
    (isset($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] === '127.0.0.1') ||
    (isset($_SERVER['SERVER_NAME']) && strpos($_SERVER['SERVER_NAME'], 'localhost') !== false) ||
    php_sapi_name() === 'cli' // Command line interface
);

if ($isLocal) {
    // Local XAMPP configuration
    $servername = "localhost";
    $username = "root";
    $password = "";
    $database = "u412552698_dbhanapbahay";
} else {
    // Production configuration
    $servername = "localhost";
    $username = "u412552698_dbhanapbahay";
    $password = "Obu8@20a6|";
    $database = "u412552698_dbhanapbahay";
}

// Error reporting (log to file, don't display)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Create connection
$conn = new mysqli($servername, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set MySQL timezone to match PHP timezone
$conn->query("SET time_zone = '+08:00'");
?>


