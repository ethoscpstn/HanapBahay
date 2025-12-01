<?php
// public/api.php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/ml_client.php';

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'version':
            echo json_encode(ml_version()); break;

        case 'health':
            echo json_encode(ml_health()); break;

        case 'predict': {
            // Expect JSON body: { "inputs": [ {..row..}, ... ] }
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            if (empty($body['inputs']) || !is_array($body['inputs'])) {
                http_response_code(400);
                echo json_encode(['error'=>'Missing or invalid "inputs"']); break;
            }
            echo json_encode(ml_predict($body['inputs']));
            break;
        }

        case 'price_interval': {
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            $rows = $body['inputs'] ?? [];
            $noise = isset($body['noise']) ? floatval($body['noise']) : 0.08;
            if (empty($rows)) { http_response_code(400); echo json_encode(['error'=>'Missing "inputs"']); break; }
            echo json_encode(ml_price_interval($rows, $noise));
            break;
        }

        case 'recommend': {
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            $listings  = $body['listings']  ?? [];
            $user_pref = $body['user_pref'] ?? [];
            $top_k     = isset($body['top_k']) ? intval($body['top_k']) : 10;
            if (empty($listings)) { http_response_code(400); echo json_encode(['error'=>'Missing "listings"']); break; }
            echo json_encode(ml_recommend($listings, $user_pref, $top_k));
            break;
        }

        case 'comps': {
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            $target   = $body['target']   ?? null;
            $listings = $body['listings'] ?? [];
            $k        = isset($body['k']) ? intval($body['k']) : 8;
            if (!$target || empty($listings)) { http_response_code(400); echo json_encode(['error'=>'Missing "target" or "listings"']); break; }
            echo json_encode(ml_comps($target, $listings, $k));
            break;
        }

        case 'loc_score': {
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            $rows = $body['inputs'] ?? [];
            if (empty($rows)) { http_response_code(400); echo json_encode(['error'=>'Missing "inputs"']); break; }
            echo json_encode(ml_loc_score($rows));
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['error'=>'Unknown action']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'detail' => $e->getMessage()]);
}
