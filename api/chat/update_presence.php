<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../../mysql_connect.php';
require_once __DIR__ . '/_auth.php';

try {
    [$user_id, $user_role] = current_user_id_and_role();
    if ($user_id === 0) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    // Create table if this is the first time the endpoint is hit
    $createSql = "
        CREATE TABLE IF NOT EXISTS chat_presence (
            user_id INT NOT NULL,
            role ENUM('tenant','owner','admin','unit_owner') NOT NULL,
            last_seen_at DATETIME NOT NULL,
            PRIMARY KEY (user_id, role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    $conn->query($createSql);

    $roleKey = $user_role === 'unit_owner' ? 'owner' : $user_role;

    $stmt = $conn->prepare("
        INSERT INTO chat_presence (user_id, role, last_seen_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE last_seen_at = NOW()
    ");
    $stmt->bind_param('is', $user_id, $roleKey);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('update_presence.php: ' . $e->getMessage());
    echo json_encode(['error' => 'Server error']);
}
