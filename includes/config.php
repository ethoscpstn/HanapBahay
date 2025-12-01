<?php
// ML Service Configuration - Environment Detection
if (!defined('ML_BASE')) {
    // Detect environment and use appropriate ML service URL
    $is_localhost = (isset($_SERVER['HTTP_HOST']) &&
                     ($_SERVER['HTTP_HOST'] === 'localhost' || $_SERVER['HTTP_HOST'] === '127.0.0.1'));

    if ($is_localhost) {
        // Local development - use local ML service
        define('ML_BASE', 'http://127.0.0.1:8000');
        define('ML_KEY', 'hanapbahay_ml_local_2024');
    } else {
        // Production - use Render ML service
        define('ML_BASE', 'https://hanapbahay-ml.onrender.com');
        define('ML_KEY', 'hanapbahay_ml_prod_secure_2024');
    }
}
