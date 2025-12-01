<?php
// public_html/api/ml_suggest_price.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/ml_client.php';

// REMOVED: json_exit() function - it's already in ml_client.php

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(400);
  echo json_encode(['error' => 'missing_inputs', 'hint' => 'POST JSON: {"inputs":[{...}]}']);
  exit;
}

$raw  = file_get_contents('php://input');
if ($raw === false) {
  http_response_code(400);
  echo json_encode(['error'=>'read_body_failed']);
  exit;
}

$body = json_decode($raw, true);
if (!is_array($body)) {
  http_response_code(400);
  echo json_encode(['error'=>'invalid_json','raw'=>$raw]);
  exit;
}

$rows = $body['inputs'] ?? null;
if (!is_array($rows) || empty($rows)) {
  http_response_code(400);
  echo json_encode(['error'=>'missing_inputs']);
  exit;
}

// Point prediction
$pred = ml_predict($rows);
if (isset($pred['error'])) {
  http_response_code(502);
  echo json_encode(['error'=>'ml_unavailable','detail'=>$pred]);
  exit;
}

$y = $pred['predictions'][0] ?? null;

// Round up to nearest 100
if ($y !== null) {
  $y = ceil($y / 100) * 100;
}

// Interval
$interval = ml_price_interval($rows, 0.08);
$band = null;
if (is_array($interval) && empty($interval['error']) && !empty($interval['intervals'][0])) {
  $i0 = $interval['intervals'][0];
  $band = [
    'low'  => isset($i0['low'])  ? ceil($i0['low'] / 100) * 100 : null,
    'pred' => isset($i0['pred']) ? ceil($i0['pred'] / 100) * 100 : $y,
    'high' => isset($i0['high']) ? ceil($i0['high'] / 100) * 100 : null,
  ];
}

http_response_code(200);
echo json_encode([
  'version'    => $pred['version'] ?? null,
  'prediction' => $y,
  'interval'   => $band
]);