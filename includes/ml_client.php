<?php
// includes/ml_client.php
// Client functions to call the ML API

require_once __DIR__ . '/config.php';

/**
 * Call the ML prediction endpoint
 * @param array $rows Array of input data rows
 * @return array Response from ML API
 */
function ml_predict($rows) {
    $url = ML_BASE . '/predict';

    $payload = json_encode(['inputs' => $rows]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-API-Key: ' . ML_KEY
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['error' => 'curl_error', 'message' => $error];
    }

    curl_close($ch);

    if ($httpCode !== 200) {
        return ['error' => 'http_error', 'code' => $httpCode, 'response' => $response];
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        return ['error' => 'invalid_json', 'response' => $response];
    }

    return $data;
}

/**
 * Call the ML price interval endpoint
 * @param array $rows Array of input data rows
 * @param float $noise Noise parameter (default 0.08)
 * @return array Response from ML API
 */
function ml_price_interval($rows, $noise = 0.08) {
    $url = ML_BASE . '/price_interval';

    $payload = json_encode([
        'inputs' => $rows,
        'noise' => $noise
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-API-Key: ' . ML_KEY
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['error' => 'curl_error', 'message' => $error];
    }

    curl_close($ch);

    if ($httpCode !== 200) {
        return ['error' => 'http_error', 'code' => $httpCode, 'response' => $response];
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        return ['error' => 'invalid_json', 'response' => $response];
    }

    return $data;
}
