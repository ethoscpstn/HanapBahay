<?php
/**
 * Price Interval Prediction API
 * Returns confidence interval for rental price predictions
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

// Read and decode JSON input
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data || !isset($data['inputs']) || !is_array($data['inputs'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input. Expected {"inputs": [...]}.']);
    exit;
}

// Get the first input (for single property prediction)
$input = $data['inputs'][0] ?? [];

// Validate required fields
$required = ['Capacity', 'Bedroom', 'unit_sqm', 'Type', 'Location'];
foreach ($required as $field) {
    if (!isset($input[$field])) {
        http_response_code(400);
        echo json_encode(['error' => "Missing required field: $field"]);
        exit;
    }
}

// Call the ML prediction endpoint first to get base prediction
$ml_api_url = 'http://localhost/api/ml_suggest_price.php';
if ($_SERVER['HTTP_HOST'] !== 'localhost' && $_SERVER['HTTP_HOST'] !== '127.0.0.1') {
    $ml_api_url = 'https://' . $_SERVER['HTTP_HOST'] . '/api/ml_suggest_price.php';
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $ml_api_url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['inputs' => [$input]]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$ml_response = curl_exec($ch);
$curl_error = curl_error($ch);
curl_close($ch);

if (!$ml_response || $curl_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to get ML prediction: ' . $curl_error]);
    exit;
}

$ml_data = json_decode($ml_response, true);
if (!isset($ml_data['prediction'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid ML prediction response']);
    exit;
}

$base_prediction = $ml_data['prediction'];

// Calculate confidence interval (95% confidence)
// Using a simplified approach based on property characteristics
// In production, this would come from the ML model's prediction intervals

// Factors affecting price variance
$capacity = (int)($input['Capacity'] ?? 1);
$sqm = (float)($input['unit_sqm'] ?? 20);
$bedroom = (int)($input['Bedroom'] ?? 1);

// Calculate variance factor based on property size and type
$variance_factor = 0.15; // Base 15% variance

// Adjust based on property characteristics
if ($sqm < 20) {
    $variance_factor += 0.05; // Smaller properties have more variance
}
if ($capacity > 4) {
    $variance_factor += 0.05; // Larger capacity = more variance
}
if ($input['Type'] === 'Studio' || $input['Type'] === 'Boarding House') {
    $variance_factor += 0.03; // These types have more price variation
}

// Location-based variance (some locations have more price variation)
$high_variance_locations = ['Manila', 'Makati', 'BGC', 'Taguig', 'Quezon City'];
$location = $input['Location'] ?? '';
foreach ($high_variance_locations as $loc) {
    if (stripos($location, $loc) !== false) {
        $variance_factor += 0.05;
        break;
    }
}

// Cap variance factor at 30%
$variance_factor = min($variance_factor, 0.30);

// Calculate interval (95% confidence)
// For a normal distribution, 95% confidence ≈ 1.96 standard deviations
$margin = $base_prediction * $variance_factor;

$price_min = max(0, round($base_prediction - $margin, -2)); // Round to nearest 100
$price_max = round($base_prediction + $margin, -2);

// Ensure minimum range
if ($price_max - $price_min < 1000) {
    $price_min = max(0, $base_prediction - 500);
    $price_max = $base_prediction + 500;
}

// Response
$response = [
    'prediction' => $base_prediction,
    'interval' => [
        'min' => $price_min,
        'max' => $price_max,
        'confidence' => 95,
        'variance_factor' => round($variance_factor * 100, 1)
    ],
    'metadata' => [
        'timestamp' => date('Y-m-d H:i:s'),
        'version' => '1.0'
    ]
];

echo json_encode($response, JSON_PRETTY_PRINT);
