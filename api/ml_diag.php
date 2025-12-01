<?php
date_default_timezone_set('Asia/Manila');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../reports/ml_diag_error.log');

header('Content-Type: application/json');

require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/ml_client.php';

$out = [];
$out['timestamp'] = date('c');
$out['env'] = [
    'ML_BASE' => defined('ML_BASE') ? ML_BASE : null,
    'ML_KEY_len' => defined('ML_KEY') ? strlen(ML_KEY) : 0
];

try {
    if (function_exists('ml_version')) {
        $out['version'] = ml_version(ML_BASE);
    } else {
        $out['version'] = ['error' => 'ml_version_missing'];
    }

    if (function_exists('ml_health')) {
        $out['health'] = ml_health(ML_BASE, ML_KEY);
    } else {
        $out['health'] = ['error' => 'ml_health_missing'];
    }

    $sample = [[
        'Capacity' => 3,
        'Bedroom' => 1,
        'unit_sqm' => 24,
        'cap_per_bedroom' => 3,
        'Type' => 'Apartment',
        'Kitchen' => 'Yes',
        'Kitchen type' => 'Shared',
        'Gender specific' => 'Mixed',
        'Pets' => 'Allowed',
        'Location' => 'Makati'
    ]];

    if (function_exists('ml_predict')) {
        $out['predict'] = ml_predict($sample);
    } else {
        $out['predict'] = ['error' => 'ml_predict_missing'];
    }

    if (function_exists('ml_price_interval')) {
        $out['interval'] = ml_price_interval($sample, 0.08);
    } else {
        $out['interval'] = ['error' => 'ml_price_interval_missing'];
    }

    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    $out['fatal'] = [
        'type' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ];
    error_log('ml_diag fatal: ' . $e->getMessage());
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
